#!/usr/bin/env python3
"""
Build assets/data/route-streets.json: for every street on the instructor's
street-list spreadsheet, the real roads at each END of the street (the
"From" and "To" answers of the Route test), derived from OpenStreetMap.

Nothing here invents geography. A street's ends are found from real OSM way
geometry; the answers are the real named roads that meet it there (through
roundabouts and slip roads too). Where OSM has nothing, or the result is
ambiguous, the street gets low/none confidence and no answers are guessed.

Inputs (not committed, fetched by the commands below):
  osm.json       Overpass dump of every highway way in the Aberdeen area:
                 curl --data-urlencode 'data=[out:json][timeout:420];
                   way["highway"]["highway"!~"^(footway|path|steps|cycleway|bridleway|track|corridor|proposed|construction|elevator|platform|raceway|bus_guideway)$"]
                   (57.04,-2.36,57.28,-2.00);out body;>;out skel qt;'
                   https://maps.mail.ru/osm/tools/overpass/api/interpreter
  streets.xlsx   the instructor's list (sheet STREETS: Road, Post Code,
                 Address, Ward, Note, Clarify, ...)
  postcodes are geocoded once via api.postcodes.io and cached next to the output.

Usage (run with an isolated interpreter):
  python3 -I build_route_streets.py osm.json streets.xlsx out.json [postcode-cache.json]
"""
import collections
import json
import math
import re
import sys
import time
import urllib.request

import openpyxl

OSM_PATH, XLSX_PATH, OUT_PATH = sys.argv[1], sys.argv[2], sys.argv[3]
PC_CACHE_PATH = sys.argv[4] if len(sys.argv) > 4 else OUT_PATH + '.postcodes.json'

CANONICAL_WARDS = [
    'Airyhall/Broomhill/Garthdee', 'Bridge of Don', 'Dyce/Bucksburn/Danestone',
    'George St/Harbour', 'Hazlehead/Queens Cross/Countesswells', 'Hilton/Woodside/Stockethill',
    'Kincorth/Nigg/Cove', 'Kingswells/Sheddocksley/Summerhill', 'Lower Deeside',
    'Midstocket/Rosemount', 'Northfield/Mastrick North', 'Torry/Ferryhill',
    'Tillydrone/Seaton/Old Aberdeen',
]
CONNECTOR_REACH_M = 90      # how far to walk through roundabouts / slip roads from a street end
SNAP_M = 20                 # fallback: a named road this close to an unconnected street end
FAR_M = 2500                # a street whose OSM geometry is further than this from its postcode


def norm(s):
    s = re.sub(r"[’'`]", '', (s or '').upper())
    s = re.sub(r'[^A-Z0-9 ]', ' ', s)
    return re.sub(r'\s+', ' ', s).strip()


def hav(a, b):
    la1, lo1, la2, lo2 = map(math.radians, (a[0], a[1], b[0], b[1]))
    h = math.sin((la2 - la1) / 2) ** 2 + math.cos(la1) * math.cos(la2) * math.sin((lo2 - lo1) / 2) ** 2
    return 6371000 * 2 * math.asin(math.sqrt(h))


def title_case(s):
    out = []
    for w in s.strip().split(' '):
        lw = w.lower()
        if lw in ('of', 'the', 'and', 'to', 'at') and out:
            out.append(lw)
        elif lw.startswith('mc') and len(lw) > 2:
            out.append('Mc' + lw[2:].capitalize())
        elif "'" in lw:
            parts = lw.split("'")
            # O'Brien keeps its capital; a possessive stays "Clark's", never "Clark'S"
            out.append("'".join(p if (i > 0 and p == 's') else p.capitalize() for i, p in enumerate(parts)))
        else:
            out.append(lw.capitalize())
    return ' '.join(out)


# ---------------------------------------------------------------- OSM index
data = json.load(open(OSM_PATH))
OSM_TS = data['osm3s']['timestamp_osm_base']
nodes, ways = {}, {}
for e in data['elements']:
    if e['type'] == 'node':
        nodes[e['id']] = (e['lat'], e['lon'])
    elif e['type'] == 'way':
        ways[e['id']] = {'nodes': e['nodes'], 'tags': e.get('tags', {})}

node_ways = collections.defaultdict(list)
for wid, w in ways.items():
    for n in w['nodes']:
        node_ways[n].append(wid)


def way_label(w):
    t = w['tags']
    if t.get('name'):
        return t['name']
    # Unnamed main roads are signed by number (A956, A90...) and are used that way in the test.
    if t.get('ref') and t.get('highway') in ('motorway', 'trunk', 'primary', 'secondary', 'tertiary'):
        return t['ref'].split(';')[0].strip()
    return None


def answer_ok(w):
    return w['tags'].get('highway') != 'service'


def is_connector(w):
    """Unnamed way that is part of a junction rather than a road in its own right."""
    t = w['tags']
    if t.get('name'):
        return False
    if t.get('junction') in ('roundabout', 'circular'):
        return True
    hw = t.get('highway', '')
    return hw.endswith('_link') or hw in ('primary', 'secondary', 'tertiary', 'trunk', 'unclassified', 'residential')


by_name = collections.defaultdict(list)
for wid, w in ways.items():
    if w['tags'].get('name'):
        by_name[norm(w['tags']['name'])].append(wid)

# spatial grid of named, non-service way nodes, for the "unconnected end" fallback
GRID = 0.0003
grid = collections.defaultdict(list)
for wid, w in ways.items():
    lab = way_label(w)
    if lab and answer_ok(w):
        for n in w['nodes']:
            la, lo = nodes[n]
            grid[(int(la / GRID), int(lo / GRID))].append((n, lab))


def labels_at(node, self_norm):
    """Real named roads meeting at `node`, walking through unnamed roundabouts/slip roads."""
    found = collections.Counter()
    visited = {node}
    stack = [(node, 0.0)]
    while stack:
        n, d = stack.pop()
        for wid in node_ways[n]:
            w = ways[wid]
            lab = way_label(w)
            ring = w['tags'].get('junction') in ('roundabout', 'circular')
            if lab:
                if norm(lab) != self_norm and answer_ok(w):
                    found[lab] += 1
                if not ring:
                    continue        # a named roundabout is a junction: keep walking round it to its arms
            elif not is_connector(w):
                continue
            seq = w['nodes']
            idxs = [i for i, x in enumerate(seq) if x == n]
            for i0 in idxs:
                for step in (1, -1):
                    i, dist, prev = i0, d, n
                    while True:
                        i += step
                        if i < 0 or i >= len(seq):
                            if w['tags'].get('junction') in ('roundabout', 'circular') or seq[0] == seq[-1]:
                                i %= len(seq)
                            else:
                                break
                        nxt = seq[i]
                        dist += hav(nodes[prev], nodes[nxt])
                        if dist > CONNECTOR_REACH_M or nxt == n:
                            break
                        if nxt not in visited:
                            visited.add(nxt)
                            stack.append((nxt, dist))
                        prev = nxt
    return found


def snap_labels(node, self_norm):
    la, lo = nodes[node]
    found = collections.Counter()
    gx, gy = int(la / GRID), int(lo / GRID)
    for dx in (-1, 0, 1):
        for dy in (-1, 0, 1):
            for n, lab in grid.get((gx + dx, gy + dy), ()):
                if norm(lab) != self_norm and hav((la, lo), nodes[n]) <= SNAP_M:
                    found[lab] += 1
    return found


# ---------------------------------------------------------------- postcodes
try:
    pc_cache = json.load(open(PC_CACHE_PATH))
except (OSError, ValueError):
    pc_cache = {}
PC_RE = re.compile(r'\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b')


def postcodes_of(text):
    return [a + ' ' + b for a, b in PC_RE.findall((text or '').upper())]


wb = openpyxl.load_workbook(XLSX_PATH)
ws = wb['STREETS']
sheet = []
for r in ws.iter_rows(min_row=2):
    if not r[0].value or not str(r[0].value).strip():
        continue
    red = any(c.fill and c.fill.fill_type == 'solid' and c.fill.fgColor.type == 'rgb' and c.fill.fgColor.rgb == 'FFFF0000' for c in r[:8])
    sheet.append({
        'raw': str(r[0].value).strip(), 'district': (r[1].value or '').strip() if r[1].value else '',
        'address': str(r[2].value).strip() if r[2].value else '', 'ward_raw': str(r[3].value).strip() if r[3].value else '',
        'note': str(r[4].value).strip() if r[4].value else '', 'clarify': str(r[5].value or '').strip().lower() == 'yes' or red,
    })

want = sorted({p for s in sheet for p in postcodes_of(s['address'])} - set(pc_cache))
for i in range(0, len(want), 100):
    chunk = want[i:i + 100]
    req = urllib.request.Request('https://api.postcodes.io/postcodes', data=json.dumps({'postcodes': chunk}).encode(),
                                 headers={'Content-Type': 'application/json'})
    for item in json.load(urllib.request.urlopen(req, timeout=60))['result']:
        res = item['result']
        pc_cache[item['query']] = [res['latitude'], res['longitude']] if res and res.get('latitude') else None
    time.sleep(0.3)
json.dump(pc_cache, open(PC_CACHE_PATH, 'w'))


def pc_points(s):
    return [tuple(pc_cache[p]) for p in postcodes_of(s['address']) if pc_cache.get(p)]


# ---------------------------------------------------------------- clusters
def clusters_for(name_norm):
    """Connected groups of same-named ways (a 'street' as OSM draws it)."""
    wids = by_name.get(name_norm, [])
    parent = {w: w for w in wids}

    def find(x):
        while parent[x] != x:
            parent[x] = parent[parent[x]]
            x = parent[x]
        return x
    owner = {}
    for wid in wids:
        for n in ways[wid]['nodes']:
            if n in owner:
                parent[find(wid)] = find(owner[n])
            else:
                owner[n] = wid
    groups = collections.defaultdict(list)
    for wid in wids:
        groups[find(wid)].append(wid)
    return list(groups.values())


def cluster_nodes(wids):
    return {n for wid in wids for n in ways[wid]['nodes']}


def cluster_dist(wids, points):
    """Min distance (m) from any given point to the cluster's geometry."""
    if not points:
        return None
    best = 1e12
    for n in cluster_nodes(wids):
        for p in points:
            d = hav(nodes[n], p)
            if d < best:
                best = d
    return best


NET_ADJ = None


def net_path_len(a, b, limit):
    """Shortest road-network distance (m) from node a to node b, or None if over `limit`."""
    global NET_ADJ
    if NET_ADJ is None:
        NET_ADJ = collections.defaultdict(set)
        for w in ways.values():
            seq = w['nodes']
            for x, y in zip(seq, seq[1:]):
                NET_ADJ[x].add(y)
                NET_ADJ[y].add(x)
    import heapq
    dist, heap = {a: 0.0}, [(0.0, a)]
    while heap:
        d, n = heapq.heappop(heap)
        if n == b:
            return d
        if d > dist.get(n, 1e12) or d > limit:
            continue
        for m in NET_ADJ[n]:
            nd = d + hav(nodes[n], nodes[m])
            if nd < dist.get(m, 1e12) and nd <= limit:
                dist[m] = nd
                heapq.heappush(heap, (nd, m))
    return None


GAP_M = 100          # OSM often splits a street's name at a bridge/junction: join fragments this close
BRIDGE_M = 350       # ...or this close when a fairly direct road (e.g. a named bridge) links the two ends
SPUR_M = 60          # a stub of the same name this close to the main line is not a real branch


def end_nodes(wids):
    adj = collections.defaultdict(set)
    for wid in wids:
        seq = ways[wid]['nodes']
        for a, b in zip(seq, seq[1:]):
            if a != b:
                adj[a].add(b)
                adj[b].add(a)
    return adj, [n for n, s in adj.items() if len(s) == 1]


def merge_gaps(groups):
    """Join same-name fragments split by a bridge/junction; returns [(wids, virtual_edges, joined_far)]."""
    info = [{'w': list(g), 'ends': end_nodes(g)[1], 'virt': [], 'far': False} for g in groups]
    merged = True
    while merged and len(info) > 1:
        merged = False
        for i in range(len(info)):
            for j in range(i + 1, len(info)):
                best = None
                for a in info[i]['ends']:
                    for b in info[j]['ends']:
                        d = hav(nodes[a], nodes[b])
                        if d <= GAP_M:
                            if best is None or d < best[0]:
                                best = (d, a, b, False)
                        elif d <= BRIDGE_M and (best is None or best[3]):
                            np_ = net_path_len(a, b, max(150, 2.0 * d + 100))
                            if np_ is not None and (best is None or d < best[0]):
                                best = (d, a, b, True)
                if best:
                    _, a, b, far = best
                    info[i]['w'] += info[j]['w']
                    info[i]['virt'] += info[j]['virt'] + [(a, b)]
                    info[i]['far'] = info[i]['far'] or info[j]['far'] or far
                    info[i]['ends'] = [n for n in info[i]['ends'] + info[j]['ends'] if n not in (a, b)]
                    del info[j]
                    merged = True
                    break
            if merged:
                break
    return [(x['w'], x['virt'], x['far']) for x in info]


def path_nodes(adj, src, dst):
    prev, q = {src: None}, collections.deque([src])
    while q:
        n = q.popleft()
        if n == dst:
            break
        for m in adj[n]:
            if m not in prev:
                prev[m] = n
                q.append(m)
    out, n = [], dst
    while n is not None and n in prev:
        out.append(n)
        n = prev[n]
    return out


def cluster_len(wids):
    return sum(hav(nodes[a], nodes[b]) for w in wids for a, b in zip(ways[w]['nodes'], ways[w]['nodes'][1:]))


def analyse(wids, virt, self_norm):
    adj, ends = end_nodes(wids)
    for a, b in virt:
        adj[a].add(b)
        adj[b].add(a)
    ends = [n for n in adj if len(adj[n]) == 1]
    flags = []
    if len(ends) > 2:
        lab_of = {n: labels_at(n, self_norm) for n in ends}
        junction_ends = [n for n in ends if lab_of[n]]
        pool = junction_ends if len(junction_ends) >= 2 else ends
        best, pair = -1, None
        for i in range(len(pool)):
            for j in range(i + 1, len(pool)):
                d = hav(nodes[pool[i]], nodes[pool[j]])
                if d > best:
                    best, pair = d, (pool[i], pool[j])
        if len(junction_ends) == 1:      # one real junction end: pair it with the farthest other end (a dead end)
            far = max((n for n in ends if n != junction_ends[0]), key=lambda n: hav(nodes[n], nodes[junction_ends[0]]))
            pair = (junction_ends[0], far)
        main = [nodes[n] for n in path_nodes(adj, pair[0], pair[1])]
        for e in ends:
            if e in pair:
                continue
            if not main or min(hav(nodes[e], m) for m in main) > SPUR_M:
                flags.append('branched')
                break
        ends = list(pair)
    elif len(ends) < 2:
        # closed loop / lollipop: use the two most distant junction nodes with other named roads
        flags.append('loop')
        cand = [n for n in adj if any(way_label(ways[x]) and norm(way_label(ways[x])) != self_norm for x in node_ways[n])
                or len(adj[n]) != 2]
        cand = cand or list(adj)
        best, pair = -1, None
        for i in range(len(cand)):
            for j in range(i + 1, len(cand)):
                d = hav(nodes[cand[i]], nodes[cand[j]])
                if d > best:
                    best, pair = d, (cand[i], cand[j])
        ends = list(pair) if pair else ends
    sides = []
    for n in ends:
        lab = labels_at(n, self_norm)
        snapped = False
        if not lab:
            lab = snap_labels(n, self_norm)
            snapped = bool(lab)
        sides.append((n, lab, snapped))
    return sides, flags


# ---------------------------------------------------------------- per street
def ward_of(raw):
    if not raw:
        return '', ''
    cleaned = re.sub(r'\s*/\s*', '/', raw.replace('George Street', 'George St'))
    for w in CANONICAL_WARDS:
        if cleaned == w:
            return w, ''
    parts = [p.strip() for p in re.split(r'\s+or\s+', cleaned)]
    for w in CANONICAL_WARDS:
        if parts[0] == w:
            return w, raw
    if 'Lower Deeside' in cleaned:
        return 'Lower Deeside', raw
    return '', raw


results, seen = [], set()
for s in sheet:
    key = (norm(s['raw']), s['district'], s['address'])
    if key in seen:
        continue          # exact duplicate row on the sheet
    seen.add(key)
    sn = norm(s['raw'])
    ward, ward_note = ward_of(s['ward_raw'])
    out = {
        'name': title_case(s['raw']) if s['raw'].isupper() else s['raw'],
        'postcode': s['district'], 'address': s['address'], 'ward': ward,
        'clarify': s['clarify'], 'from': [], 'to': [], 'confidence': 'none', 'flags': [], 'note': s['note'],
    }
    if ward_note:
        out['ward_note'] = ward_note
    cl = merge_gaps(clusters_for(sn))
    if not cl:
        out['flags'].append('no_osm_match')
        results.append(out)
        continue
    pts = pc_points(s)
    scored = sorted(((cluster_dist(c[0], pts) if pts else None, c) for c in cl), key=lambda t: (t[0] is None, t[0] if t[0] is not None else 0))
    if pts:
        d0, chosen = scored[0]
        if len(scored) > 1 and scored[1][0] is not None and scored[1][0] < 1000 and d0 < 1000 \
                and cluster_len(scored[1][1][0]) >= 0.25 * cluster_len(chosen[0]):
            out['flags'].append('ambiguous_segments')       # (a short stub next to the real street is not ambiguity)
        if d0 > FAR_M:
            out['flags'].append('far_from_postcode')
    else:
        chosen = max(cl, key=lambda c: sum(len(ways[w]['nodes']) for w in c[0]))
        if len(cl) > 1:
            out['flags'].append('ambiguous_segments')
    # a street whose OSM name is drawn properly: use OSM's spelling/case for display
    out['name'] = ways[chosen[0][0]]['tags']['name']
    if chosen[2]:
        out['flags'].append('joined_gap')
    sides, fl = analyse(chosen[0], chosen[1], sn)
    out['flags'] += fl
    if len(sides) < 2:
        out['flags'].append('single_end')
    labels = []
    for n, lab, snapped in sides:
        labels.append(sorted(lab, key=lambda x: (-lab[x], x)))
        if snapped:
            out['flags'].append('snapped_end')
    la = [(nodes[n][1], nodes[n][0]) for n, _, _ in sides]
    order = sorted(range(len(sides)), key=lambda i: la[i])      # west end first, purely for display
    labels = [labels[i] for i in order]
    if len(labels) == 2:
        out['from'], out['to'] = labels
        if not out['from']:
            out['flags'].append('dead_end_from')
        if not out['to']:
            out['flags'].append('dead_end_to')
    elif len(labels) == 1:
        out['from'] = labels[0]
        out['flags'].append('dead_end_to')

    # confidence
    f = set(out['flags'])
    if f & {'ambiguous_segments', 'far_from_postcode', 'branched', 'loop', 'single_end', 'snapped_end'} or \
            ('dead_end_from' in f and 'dead_end_to' in f):
        conf = 'low'
    elif f & {'dead_end_from', 'dead_end_to', 'joined_gap'}:
        conf = 'medium'
    elif max(len(out['from']), len(out['to'])) > 4:
        conf = 'medium'
    else:
        conf = 'high'
    if not out['from'] and not out['to']:
        conf = 'none'
    out['confidence'] = conf
    results.append(out)

results.sort(key=lambda r: r['name'].lower())
json.dump({
    'generated': time.strftime('%Y-%m-%d'),
    'osm_timestamp': OSM_TS,
    'source': 'Street ends derived from OpenStreetMap (c) OpenStreetMap contributors, ODbL; postcodes via postcodes.io',
    'streets': results,
}, open(OUT_PATH, 'w'), ensure_ascii=False, indent=1)

c = collections.Counter(r['confidence'] for r in results)
print('streets', len(results), dict(c))
print('flags', collections.Counter(f for r in results for f in r['flags']).most_common())
print('clarify', sum(1 for r in results if r['clarify']))
