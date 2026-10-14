# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with this repository.

## What this is

**Aberdeen Knowledge** is a WordPress plugin that helps new taxi drivers prepare for the Aberdeen Street
Knowledge Test (Major Streets, Neighbourhoods, and mockup Street/POI/Route tests), plus a paid Official Test
course with Stripe/PayPal enrollment. Auth, roles, payments, quiz content and an Instructor Dashboard are all
implemented in this plugin. It started as the theme `kohid/Aberdeen-Knowledge-Theme` and was moved into a
plugin so it works on top of any base theme. It is written to be usable by other sites, so nothing in it may
depend on one site's setup.

The site runs a plain base theme (the Elementor starter theme) with Elementor Pro's Theme Builder supplying the
header and footer. Ordinary pages (home, About, ...) are built in Elementor and pull in this plugin's content
through shortcodes. Full-screen app pages (auth pages, the map tools) are complete HTML documents served by this
plugin and stay outside Elementor.

**Git**: remote `kohid/Aberdeen-Knowledge`. Push straight to `main`. The session's git proxy refuses tag pushes
(HTTP 403); Jay pushes release tags himself (see Releases).

## Working agreement

Always work in collaboration with `/superpowers:brainstorming`, `/feature-dev:feature-dev` and
`/frontend-design:frontend-design`, whether or not the request names them: brainstorming to settle intent and
design before building, feature-dev for the workflow (explore, clarify, design, implement, review),
frontend-design for every visual or UI decision (inside the design rules below).

## Design rules (hard rules, not preferences)

- **Metronic8 demo2 is the visual reference for ALL designs**:
  https://github.com/kohid/Metronic8/tree/main/html/theme/demo2/ . New screens, components and states should look
  like they belong in demo2 (soft grey canvas, white cards, dashed table rules, soft tinted badges, calm spacing).
  The repo is cloned for reference only.
- **Never ship Metronic files.** Metronic is a paid product whose license generally does not allow handing its
  files to others. Do not copy its CSS, JS, fonts, SVG icon paths or images into this plugin. Re-create the look in
  `assets/css/akn.css`, which is our own code. Open source libraries (Bootstrap, Select2, Leaflet, Bootstrap
  Icons, SheetJS, Poppins) are bundled in `assets/vendor/` and need no CDN.
- **Maps use Leaflet with OpenStreetMap.** All map data comes from real, verified OpenStreetMap data (Overpass API
  or Nominatim), never invented coordinates or names. Be transparent when real data cannot be found rather than
  guessing. Keep the OpenStreetMap attribution on every map. The public tile and Nominatim servers are for light
  use; move to a dedicated provider before heavy traffic.
- **Brand colour is a setting, never a hard-coded hex.** Use the CSS variables from `inc/brand.php`
  (`--akn-brand`, `--akn-brand-hover`, `--akn-brand-soft`, `--akn-brand-on`, `--akn-brand-ink`, ...). No literal
  `#F69216` in CSS, PHP or JS except as the default value in `AKN_Brand`.
- Classes in markup must exist in Bootstrap 5.3 or be defined in `assets/css/akn.css`. Check before using one.
- The Course catalog cards (`atk_render_course_catalog()` in `inc/course.php`) intentionally use pastel
  per-track backgrounds, by explicit user request. Do not extend pastel or custom colours elsewhere without the
  same kind of explicit ask.
- Modal conventions: use `modal-dialog-scrollable` only when content can actually grow long.

## Stylesheet notes (`assets/css/akn.css`)

- Spacing is N x 0.25rem for N 0 to 20 (replaces Bootstrap's 0 to 5 steps); `fw-bold` is 500, `fw-bolder` 600,
  `fw-boldest` 700; `fs-1` to `fs-9` is our own scale (24px down to 11px). Markup relies on these, so do not "fix" them to
  Bootstrap's values.
- Tones: `.badge-light-*`, `.btn-light-*`, `.bg-light-*` read one set of `--akn-t-*` variables set by the tone class.
  Text colours for brand and status use the "ink" shade (readable on the surface in both modes), fills use the real colour.
- Checkboxes and radios are the browser's own control with `accent-color`, so the tick stays readable on any brand colour.
- Select2 is skinned in akn.css (`html .select2-...` selectors so they win whatever the load order); no Select2 theme file.
- Base-theme guard: Hello Elementor (the site's base theme) restyles every `button`, `[type=button]`, text input, table
  and link inside `.page-content` with selectors that beat Bootstrap's single classes. akn.css restates the essentials
  with `button.btn`, `input.form-control`, `table.table > ...` and `a.btn` selectors, and our stylesheet is enqueued at
  priority 20 so it prints after the theme's. When adding a component, check it on Hello Elementor (compile its
  `dev/scss` from github.com/elementor/hello-theme to test) before trusting a plain Bootstrap class.
- `body.akn-app` (managed pages) and `body.akn-auth` carry the grey page canvas; other site pages keep their background.

## Commands

There is no build step or package manager in this repository. Verify changes with:

- PHP: `php -l path/to/file.php`
- JS: `node --check path/to/file.js`
- Plain-PHP tests (no WordPress needed): `php tests/neighbourhoods/run-tests.php`,
  `php tests/neighbourhoods/planner-tests.php`, `php tests/brand/run-tests.php`,
  `php tests/places/run-tests.php`, `php tests/places/planner-tests.php`, `node tests/places/geo-tests.mjs`;
  `bash tests/run-all.sh` runs all of them
- Updater check (`Check for updates` link on the Plugins row, GitHub stubbed): `ATK_WP_LOAD=/path/to/wp-load.php php tests/updater/integration.php`
- Places of Interest checks that need WordPress: `ATK_WP_LOAD=/path/to/wp-load.php php tests/places/integration.php`
  against a throwaway site with the plugin active. It creates its own rows (all named `ZZ-IT ...`) and removes them.
- UI changes: take a real screenshot (headless Chromium against a throwaway or the live site) instead of assuming
  a CSS or layout fix worked.

For one-off admin or data tasks there is no WP-CLI: drop a temporary PHP script in the site's public root that
starts with `require __DIR__ . '/wp-load.php';`, fetch it once, then delete it immediately. To test an
authenticated form submission call `wp_set_current_user( $id )` before `wp_create_nonce()`, then
`wp_set_auth_cookie( $id )`, replay the cookies with curl, and use the nonce tied to the action you test (every
form's field is named `atk_nonce`, but forms use different actions). Clean up test rows, sessions and the script.

## Architecture

**Bootstrap**: `aberdeen-knowledge.php` defines `ATK_PLUGIN_FILE`, `ATK_PLUGIN_DIR`, `ATK_PLUGIN_URI`,
`ATK_VERSION`, stays dormant if the old theme is active, then requires every `inc/*.php`. Order matters:
`theme-mode.php`, `icons.php` and `brand.php` first (other modules render mode-aware, icon and brand markup),
`db-schema.php` before anything that reads course or quiz content, `course.php` before `demo.php`.

**Internal names are kept from the theme** (`atk_` functions, tables, options, user meta, the `driver` and
`instructor` roles, shortcodes, `atk/v1`). They are part of stored data and of page content, so do not rename them.
New code added in the plugin era uses the `akn_` / `AKN_` prefix (brand, assets, templates, updater).

**`inc/` modules**, one concern each:
- `setup.php`: roles, managed-page registry, user menu, admin lockout for drivers and instructors
- `assets.php`: registers and enqueues bundled libraries and `akn.css`, only where needed
- `brand.php`: brand colour option, derived palette, CSS variables, admin Settings screen
- `templates.php`: page templates for managed pages, auth and map shells, output buffering
- `auth.php`: sign in/up/reset, posts to `admin-post.php` so `wp-login.php` is never shown to drivers
- `db-schema.php`: DB-backed course and quiz tables (`atk_` prefix), with a one-time migration from old static arrays
- `course-data.php`, `quiz-data.php`, `road-names-data.php`: static reference data, git-committed, not user-editable
- `course.php`: the Official Test course (Street/POI/Route tracks), Stripe and PayPal enrollment
- `quiz.php`, `attempts.php`: mockup knowledge tests and attempt tracking
- `maps.php`, `arteries-map.php`, `neighbourhoods-map.php`, `poi-map.php`: full-screen Leaflet study tools
- `study-tools.php`: the Study Tools grid
- `instructor.php`, `instructor-roads.php`: the front-end Instructor Dashboard
- `neighbourhoods.php`, `neighbourhoods-engine.php`: the dashboard's Neighbourhoods tab (see below)
- `poi-places.php`, `poi-places-engine.php`: Study Tools > Places of Interest (see below)
- `settings.php`: payment gateway config and the shortcode reference list, instructor-only
- `theme-mode.php`, `icons.php`: dark/light mode and icon rendering
- `demo.php`: generates and clears sample driver accounts for the dashboard's Demo tab
- `elementor.php`: `atk_is_elementor_editing()` and nothing else
- `updater.php`: GitHub-release updater

**Roles and access**: `driver` and `instructor` (both just `read`) are front-end-only roles with no wp-admin and no
admin bar. An `admin_init` hook redirects them to their dashboard. `admin-post.php` fires `admin_init` too but is
also what every front-end form posts to, so it is exempted alongside `wp_doing_ajax()`. Without that every form
submission silently bounces to the dashboard.

**Managed pages**: `atk_managed_pages()` in `inc/setup.php` is the single source of truth for every page the plugin
controls (slug, title, template). Created on activation. Always resolve URLs with `atk_get_page_url( $key )`, never
hardcode a path. A page's template file name is stored in `_wp_page_template` and served by `inc/templates.php`.

**Form handling**: front-end forms `POST` to `admin-post.php?action=atk_{thing}`, handled by
`add_action( 'admin_post_atk_{thing}', ... )` (plus a `_nopriv_` variant when logged-out users can reach it). Every
form includes `wp_nonce_field( SOME_ACTION_CONST, 'atk_nonce' )`.

**Page caching**: logged-out page HTML is cached by URL alone on typical hosts (server cache and a CDN in front).
Never render logged-out HTML differently per visitor from a cookie; do it client-side, as `inc/theme-mode.php`
does. A nonce printed into a logged-out page goes stale long before the cached page does, so logged-out form actions
either need no nonce (only for harmless actions) or a fresh one fetched at request time. Every enqueued asset uses
`filemtime()` as its version because CDNs cache static files for days. After deploying, purge the page cache.

**Shortcodes**: `atk_registered_shortcodes()` in `inc/settings.php` is the definitive list. The render function
(`atk_render_user_menu()` etc.) is the single implementation shared by the shortcode and any direct call; never fork
them. Register a new shortcode together with an entry in that list. Gates that redirect or `exit()` must check
`atk_is_elementor_editing()` first, or Elementor's editor breaks.

**REST namespace**: `atk/v1` (Stripe webhook, Major Roads merge endpoint, `neighbourhoods*` and
`neighbourhood-import/*`, instructors and administrators only).

**Major Streets road data** (`inc/instructor-roads.php`): `assets/data/major-roads.json` (real OSM data, about 9,700
segments) is never written to. Instructor renames, hides and additions live in `atk_road_overrides` and
`atk_road_additions`; the REST route `atk/v1/major-roads` merges static data, overrides and additions at request time,
cached in a transient that every save invalidates. The student map reads only that endpoint. New ways of producing
road geometry are client-side concerns that each produce a plain `[[lat,lng],...]` array.

## Neighbourhoods

Instructors group streets into neighbourhoods on the dashboard's Neighbourhoods tab, then pick them in the Course
Builder.

- **Storage**: `atk_nb_neighbourhoods`, `atk_nb_streets`, `atk_nb_links`. `atk_nb_streets` is a registry of street
  names filled from `atk_master_road_names()` plus Major Streets additions and question endpoints (not accepted-answer
  spellings), carrying instructor-only postcodes and notes. `atk_nb_links` is many to many, which is how one street
  sits in two wards (ALBURY LANE).
- **Input**: pasted street names, or a pasted or imported sheet with the columns `Road, Post Code, Address, Ward, Note`
  (`.xlsx` and `.csv` read in the browser with the bundled SheetJS). A Ward cell with "A or B" (or `;`) puts the street
  in both wards. A file import replaces a street's wards, pasted names only add. Blank cells never erase saved data.
  Every import goes through a review step; the server rebuilds the plan at save time and never trusts the browser.
- **Streets not on the site yet** (the master list is OpenStreetMap, so some of the client's streets are missing) are
  never created silently: the review offers "Add as new street" per row. A close lookalike is only a suggestion.
- **Course Builder**: a week still stores its neighbourhoods as one comma-joined string in `course_weeks.neighbourhoods`.
  `ATK_Nb_Store::ids_in_text()` matches that string against saved names (longest first) because some real names contain
  a comma. `assets/js/instructor-course-neighbourhoods.js` shows a live street count under each field (a street in two
  chosen neighbourhoods counts once) and a switch in the Street question form that narrows Road A and B.
- **Tests**: `inc/neighbourhoods-engine.php` is pure PHP with tests in `tests/neighbourhoods/`.

## Places of Interest

The dashboard's **Study Tools** tab holds two sub-tabs, Major Streets and Places of Interest (`?tab=study-tools&tool=roads|poi`;
the old `?tab=roads` still lands on Major Streets). Places of Interest is the one list behind the public
`/places-of-interest/` map and the POI tests. The 87 built-in venues (`assets/data/poi.json`) were removed: the list starts
empty and the instructor imports the official sheet.

- **Storage**: table `atk_poi_places` (`ATK_Poi_Store`): name, address, postcode, `street_answer`, one ward
  (`neighbourhood_id`, a Neighbourhoods record, 0 for none), category, status (`current`/`closed`), notes, `lat`/`lng`,
  `also_answers` (extra accepted streets, one per line in the column, up to 6, edited in the Edit place form) and `geo_state` (`none`, `looked_up`, `manual`, `not_found`). A slash is part of a ward name ("Airyhall/Broomhill/Garthdee"
  is one ward). The older typed POI questions (`quiz_poi_questions`, `course_poi_questions`) stay in the database untouched
  but the mockup test no longer reads `quiz_poi_questions`.
- **Import** (`inc/poi-places-engine.php`, pure PHP): columns Place of interest, Street/Address, Postcode, Ward, optional
  Category, Status, Note. Same name and postcode is the same place (update, never a duplicate). Blank cells never erase
  saved values; a saved street answer is never replaced by the sheet's automatic match. The street answer is matched
  against the real road names from the address (right to left), never guessed; a close lookalike is only a suggestion.
  A ward not on the site is created only when the review box is ticked (ticking one row creates it for every row naming it).
  The server rebuilds the plan at save time.
- **Categories**: the official sheet has no Category column, so a place with none given is sorted from its name
  (`ATK_Poi_Planner::guess_category`): first `assets/data/poi-category-hints.json` (`categories` = the hand-sorted names of the
  87 original venues, `suggested` = unchecked suggestions from general knowledge, curated wins), then keyword rules, then
  Other Amenities. A guess is only used for a new place; an existing place's category changes only when the sheet gives one.
  On update, `atk_poi_sort_categories_once` moves places still on Other Amenities once (option `atk_poi_categories_sorted`).
  The category drives the public map's pin colour and icon (`CATEGORY_META` in `assets/js/poi-map.js`), so a map of
  all red pins means every place is on Other Amenities.
- **Pins**: the instructor's browser looks each new or changed place up through Nominatim (`assets/js/poi-geo.js` holds the
  accept rules, one request a second, Aberdeen area box, named result, allowed OSM classes) and the server checks the box
  again. The "Look up missing pins" button is always shown (disabled while a lookup runs or when nothing is missing); it covers
  places never looked up and places that were not found. Places without a pin can be placed by hand (`manual`, never replaced by
  a lookup): the "Place the pin" box has a search field (prefilled with the place name, results limited to the Aberdeen box, no
  class filter so streets and postcodes work, one request a second shared with the lookup) and the map click. The public
  Nominatim server is for light use.
- **Dashboard order**: one section per ward, ordered by the Course Builder's weeks (poi-track weeks, then street-track weeks).
- **Official POI test**: the Course Builder's POI Test track has a card, "Fill the Official POI test from your places"
  (`atk_render_instructor_poi_course_card()`), that makes 13 weeks, one per Aberdeen City Council ward in official order
  (`ATK_Poi_Planner::OFFICIAL_WARDS`: 1 Dyce/Bucksburn/Danestone, 2 Bridge of Don, 3 Kingswells/Sheddocksley/Summerhill,
  4 Northfield/Mastrick North, 5 Hilton/Woodside/Stockethill, 6 Tillydrone/Seaton/Old Aberdeen, 7 Midstocket/Rosemount,
  8 George Street/Harbour, 9 Lower Deeside, 10 Hazlehead/Queens Cross/Countesswells, 11 Airyhall/Broomhill/Garthdee,
  12 Torry/Ferryhill, 13 Kincorth/Nigg/Cove). A ward's current places with a street answer become five tests: a ward with
  20 places or fewer asks every place in every test, a bigger one fills each test to 20 by walking the list round, so no
  place repeats inside a test (100 questions at most). Existing weeks are never touched unless "rewrite" is ticked. The
  preview and the fill share one plan (`ATK_Poi_Planner::course_plan`, `ATK_Poi_Store::course_preview` / `course_apply`).
- **Researched answers**: `assets/data/poi-research.json` holds streets checked against a real source (name, postcode,
  street, extra accepted spellings, source link, note). The researched street is asked first and the sheet's street is
  also accepted, unless the entry has `only: true`, which makes the researched street the single answer (Jay's ruling for
  Old Blackfriars: Castle Street, and Linx Ice Arena: Beach Esplanade). A place's own `also_answers` (typed in the Edit place form) are always accepted too, even for an `only` entry. The accepted list is: researched street, research `also`, the saved street (unless `only`), then the place's `also_answers`, without repeats. About sixty larger and corner places were checked for a second street; only Aberdeen Art Gallery (Blackfriars Street) and Tolbooth Museum (Castlegate) were backed by a source, the rest confirmed the sheet's single street. Weeks already made keep their copy of the answers, so after editing a place run the Official POI card again with "rewrite" ticked. Places with no street answer and no research (Seaview Caravan Park) are left out, never guessed.
- **Public**: `GET atk/v1/poi-venues` (cached one minute, `Cache-Control: public, max-age=60`) returns the pinned places; the map
  page reads only that route. The POI test and the Course Builder POI picker read the saved places that are current and have
  a street answer.

## Releases

1. Bump `Version` in `aberdeen-knowledge.php` (and `ATK_VERSION`), merge to `main`.
2. Jay pushes the tag `vX.Y.Z` (it must equal the plugin `Version`). `.github/workflows/release.yml` checks that and
   publishes the release.
3. Sites see the update under Plugins. A private repo needs `AKN_GITHUB_TOKEN` in `wp-config.php` (fine-grained,
   this repo only, Contents read-only); a public repo needs none.
4. After updating, purge the page cache.

## Pending decision: 827 unnamed road segments in Major Streets / Neighbourhoods

`assets/data/major-roads.json` draws the full Aberdeen road network (9,699 segments). Of these 8,872 have a real label
(OSM `name`, or a `ref` such as "A96" used as fallback) and are quiz targets. 827 have no name and no ref anywhere in
OSM (confirmed from full tag sets): 34 tagged `noname=yes`, 5 with a contributor `fixme` asking for the name, 20 with
only a `highway_authority_ref`, 768 with nothing. They are drawn in purple, hoverable, with a "Nearest named road: X"
hint (the precomputed `near` field), but are not quiz targets (`findable: false`).

Two options were presented and the decision deferred: (1) merge each into its nearest named road, a simplification that
attributes segments to roads OSM does not; (2) leave as is, the most transparent option. **Do not implement either
without asking the user first.**
