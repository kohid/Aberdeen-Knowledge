#!/usr/bin/env python3
"""
Turns the output of build_route_streets.py into assets/data/route-research.json, the file the
Streets tab's "Fill answers from research" button reads (ATK_Route_Research in
inc/streets-engine.php).

A dead end is a real answer ("Dead end"), but only next to a real road: a street with no answer
at either end is left out. Streets awaiting clarification are left out too.

Usage: python3 -I to_research_json.py route-streets.json route-research.json
"""
import json
import sys

src = json.load(open(sys.argv[1]))
out = []
for s in src['streets']:
    if s.get('clarify'):
        continue
    flags = s.get('flags', [])
    frm, to = list(s['from']), list(s['to'])
    if not frm and to and 'dead_end_from' in flags:
        frm = ['Dead end']
    if not to and frm and 'dead_end_to' in flags and frm != ['Dead end']:
        to = ['Dead end']
    if not frm or not to:
        continue
    out.append({'name': s['name'], 'from': frm, 'to': to, 'confidence': s['confidence'], 'flags': flags})

json.dump({
    'source': src['source'],
    'osm_timestamp': src['osm_timestamp'],
    'generated': src['generated'],
    'note': 'From/To answers are derived from OpenStreetMap geometry and must be checked by an instructor; confidence says how sure the derivation is.',
    'streets': out,
}, open(sys.argv[2], 'w'), ensure_ascii=False, indent=0, separators=(',', ':'))
print(len(out), 'streets')
