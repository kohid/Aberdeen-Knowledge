# Study Tools and Places of Interest Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the dashboard's Major Streets tab into Study Tools with a Places of Interest sub-tab, where places are added by hand or imported from the official sheet, shown by ward in teaching order, pinned on the public map, and used as the POI question bank.

**Architecture:** A pure PHP engine (parser, street matcher, planner, ward ordering) holds every import rule and is tested without WordPress, like the Neighbourhoods engine. A new `atk_poi_places` table and `ATK_Poi_Store` class hold the data and reuse Neighbourhoods for wards. REST routes under `atk/v1` serve the dashboard (instructors only) and the public map. Pin lookup runs in the instructor's browser against Nominatim, and the server re-checks every coordinate.

**Tech Stack:** PHP 8, WordPress REST API, `dbDelta`, Bootstrap 5.3 plus `assets/css/akn.css`, Leaflet, bundled SheetJS, plain JS (no build step), Select2 where the dashboard already uses it.

**Spec:** `docs/superpowers/specs/2026-10-14-study-tools-places-of-interest-design.md`

## Global Constraints

- Internal names keep the `atk_` / `ATK_` prefix (functions, classes, tables, REST). Do not rename existing ones.
- Plugin `Version` header and `ATK_VERSION` become `1.1.0`; `ATK_DB_VERSION` in `inc/db-schema.php` goes from 4 to 5.
- Tables are created only through `dbDelta` in `atk_maybe_install_db()`. No existing database row is deleted or changed, and the `quiz_poi_questions` and `course_poi_questions` tables are left alone.
- Wards come from `atk_nb_neighbourhoods`. A slash is part of a ward's name, never a separator. A place has exactly one ward, or none.
- The server rebuilds the import plan at save time and never trusts the browser's copy.
- Never invent coordinates, categories or street names. Anything not found stays empty and is flagged.
- Nominatim: at most one request per second, only for new or changed places.
- Aberdeen area box constants: latitude 57.00 to 57.30, longitude -2.40 to -1.95.
- The category list is fixed at eleven: Restaurants and Cafes, Hotels, Museums Arts & Culture, Sports Facilities, Schools Libraries & Learning, Community Centres, Religious Buildings & Cemeteries, Clubs & Members Clubs, Other Amenities, Living Spaces, Transport Hubs. Default is Other Amenities.
- Design: Metronic8 demo2 is the visual reference, recreated in `assets/css/akn.css`. Never copy Metronic files. Every class used in markup exists in Bootstrap 5.3 or `akn.css`. Brand colour comes only from the `--akn-brand*` variables, never a hex.
- Copy: sentence case, no em dashes, no company names in body copy.
- Every task that changes UI collaborates with the `frontend-design:frontend-design` skill and is checked with real screenshots, as `CLAUDE.md` requires.
- Commit to `main` with the trailer lines `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` and `Claude-Session: https://claude.ai/code/session_01WoyUxoa4zNF31KLFBeaJDV`. Jay pushes release tags himself.
- Leave the pending decision about the 827 unnamed road segments untouched.

## Review Focus

- The same place twice in one file: the later row is skipped, never saved twice (Task 2).
- A header row that is not the first row, or a file with blank rows above it, still imports; a sheet with no place column gives a plain error (Task 1).
- A place name containing `<script>` or a quote shows as text on the dashboard and the public map, never as markup (Tasks 7 and 10).
- A ward deleted from Neighbourhoods after places were linked to it: those places fall into "No ward yet" instead of vanishing (Task 4).
- A pin lookup interrupted halfway (tab closed): the unfinished places stay "No pin yet" and a "Look up missing pins" button resumes only those, not the ones already tried and not found (Task 9).

---

### Task 1: Engine, part one: parser and street matcher

**Files:**
- Create: `inc/poi-places-engine.php` (guard like `neighbourhoods-engine.php`: stop unless `ABSPATH` or `ATK_NB_TESTING` is defined)
- Create: `tests/places/run-tests.php` (same `check()` harness as `tests/neighbourhoods/run-tests.php`; defines `ATK_NB_TESTING`, then requires `inc/neighbourhoods-engine.php` and `inc/poi-places-engine.php`; `planner-tests.php` does the same)

**Interfaces:**
- Produces `ATK_Poi_Parser::map_header( array $row ): array<string,int>` with keys among `name, address, postcode, ward, category, status, note`.
- Produces `ATK_Poi_Parser::parse_rows( array $table ): array{rows: array[], error: ?string}`. Each row: `line` (1-based sheet row number), `name`, `address`, `postcode` (tidied), `ward`, `category`, `status`, `note`, all strings. The header is the first of the first five rows that has a name column.
- Produces `ATK_Poi_Parser::tidy_postcode( string $text ): string` (first UK postcode found, as `AB25 1GL`, else empty).
- Produces `ATK_Poi_Parser::ward_key( string $ward ): string`.
- Produces `ATK_Poi_Parser::street_candidates( string $address ): string[]`.
- Produces `new ATK_Poi_Street_Matcher( string[] $road_names )` and `->match( string $address ): array{status: 'matched'|'unmatched', road: ?string, suggestion: ?string}`.
- Consumes `ATK_Nb_Parser::normalise_name`, `ATK_Nb_Parser::extract_postcodes`, `ATK_Nb_Matcher` (already in `inc/neighbourhoods-engine.php`).

- [ ] **Step 1: Write the failing tests** in `tests/places/run-tests.php`, with the sheet's real values:
  - `map_header( ['Place of interest','Street','Postcode','Ward'] )` equals `['name'=>0,'address'=>1,'postcode'=>2,'ward'=>3]`; `['place of interest','ADDRESS','Post code','Ward','Category']` gives `address` 1, `postcode` 2, `category` 4.
  - `parse_rows` with two blank rows above the header returns the row for `22 CLUB` with `line` 4 and `postcode` `AB10 1UB`; a table whose header is `['Foo','Bar']` returns `error` `Could not find a "Place of interest" column.` and no rows; a row with an empty name is dropped; a leading UTF-8 BOM on the first header cell is ignored.
  - `tidy_postcode( 'ab25  1gl' )` is `AB25 1GL`; `tidy_postcode( 'AB101UB' )` is `AB10 1UB`; an empty Postcode cell falls back to the postcode inside the address.
  - `ward_key( 'Midstocket / Rosemount' ) === ward_key( 'Midstocket/Rosemount' )`, and `ward_key( 'Airyhall/Broomhill/Garthdee' )` is `airyhall/broomhill/garthdee`.
  - `street_candidates` for: `Old stoneywood church, Bankhead road, AB21 9HQ` gives `['Bankhead road','Old stoneywood church']`; `Schoolhill. AB10 1FQ` gives `['Schoolhill']`; `52-60 Langstane Place, Aberdeen, Scotland, United Kingdom` gives `['Langstane Place']`; `43-45 Market street, AB11 5EL` gives `['Market street']`; `164 Spital, AB24 3JD` gives `['Spital']`; `His Majesty's Theatre, Rosemount viaduct, AB25 1GL` gives `['Rosemount viaduct',"His Majesty's Theatre"]`; `St. Machar Drive, AB24 3UU` gives `['St. Machar Drive']` (interior full stops stay).
  - With roads `['ROSEMOUNT VIADUCT','BANKHEAD ROAD','KING STREET','ST MACHAR DRIVE','SPITAL','SCHOOLHILL','BACK WYND']`: matching the Rosemount address gives `matched` / `ROSEMOUNT VIADUCT`; `33 King st, AB24 5AA` gives `KING STREET`; `St Machar Drive, AB24 3UU` gives `ST MACHAR DRIVE`; `Forresterhill, AB25 2ZW` gives `unmatched`, road null.
- [ ] **Step 2: Run** `php tests/places/run-tests.php`. Expected: fatal error, class `ATK_Poi_Parser` not found.
- [ ] **Step 3: Implement the parser** in `inc/poi-places-engine.php`. Header aliases, matched case-insensitively after trimming: name = `place of interest`, `place`, `name`, `venue`; address = `street`, `address`; postcode = `postcode`, `post code`; ward = `ward`; category = `category`; status = `status`; note = `note`, `notes`. `street_candidates` order of work: remove postcodes, trim trailing punctuation and spaces, split on commas only, drop parts that equal `Aberdeen`, `Scotland` or `United Kingdom` (case-insensitive), strip a leading house number or range (`/^\d+[A-Za-z]?(-\d+[A-Za-z]?)?\s+/`), drop empty parts, return right to left.
- [ ] **Step 4: Implement `ATK_Poi_Street_Matcher`** by wrapping `ATK_Nb_Matcher` (ids are list positions). For each candidate try it as written, then with its final word expanded (`Rd`→`Road`, `St`→`Street`, `Ave`→`Avenue`, `Dr`→`Drive`, `Ln`→`Lane`, `Pl`→`Place`, `Ct`→`Court`); only the final word is expanded so `St Machar Drive` stays intact. The first `matched` wins and returns the road in the list's own spelling; otherwise `suggestion` is the first candidate's `ATK_Nb_Matcher` suggestion title, or null.
- [ ] **Step 5: Run** `php tests/places/run-tests.php`. Expected: every line `ok`, exit 0.
- [ ] **Step 6: Commit** `git add inc/poi-places-engine.php tests/places && git commit -m "Places engine: sheet parser and street matcher"`.

### Task 2: Engine, part two: planner and ward order

**Files:**
- Modify: `inc/poi-places-engine.php`
- Create: `tests/places/planner-tests.php`

**Interfaces:**
- Consumes Task 1's parser and matcher.
- Produces `ATK_Poi_Planner::CATEGORIES` (the eleven names, array).
- Produces `ATK_Poi_Planner::plan( array $rows, array $ctx ): array[]`. `$ctx` is `['wards' => array<int,string>, 'existing' => array<int,array{id:int,name:string,postcode:string}>, 'roads' => string[]]`. Each plan row has: `line`, `name`, `address`, `postcode`, `ward` (text), `ward_id` (int, 0 when the ward cell is empty, null when it names an unknown ward), `street_answer` (string), `suggestion` (?string), `category` (one of the eleven), `status` (`current` or `closed`), `note`, `action` (`add`, `update` or `skip`), `existing_id` (int, 0 when none), `issues` (subset of `['needs_street','new_ward']`).
- Produces `ATK_Poi_Planner::order_wards( array $wards, array $week_texts ): int[]` where `$wards` is id to name and `$week_texts` is one comma-joined neighbourhood string per week, in week order.

- [ ] **Step 1: Write the failing tests** in `tests/places/planner-tests.php` (same harness): with wards `[1=>'Midstocket/Rosemount', 2=>'George St/Harbour']`, existing `[['id'=>7,'name'=>'22 CLUB','postcode'=>'AB10 1UB']]` and a roads list containing `ROSE STREET`:
  - `22 CLUB` / `55 Rose street, AB10 1UB` / `Midstocket/Rosemount` plans as `update`, `existing_id` 7, `ward_id` 1, `street_answer` `ROSE STREET`, no issues.
  - `Babylon` with ward `Midstocket / Rosemount` gets `ward_id` 1; a ward `Hazlehead/Queens Cross/Countesswells` gets `ward_id` null and issue `new_ward`; an empty ward gets `ward_id` 0 and no issue.
  - `Aberdeen Blood Donor Centre` / `Foresterhill, AB25 2ZW` gets issue `needs_street` and an empty `street_answer`.
  - A second row with the same name and postcode as an earlier one in the file has `action` `skip`.
  - Category `hotels` becomes `Hotels`; `Nonsense` and an empty cell become `Other Amenities`. Status `Closed` becomes `closed`; anything else `current`.
  - Blank fields stay blank in an `update` row (the store, not the planner, decides not to erase).
  - `order_wards( [1=>'A',2=>'B',3=>'C',4=>'D'], ['B, A','C'] )` is `[2,1,3,4]`; `order_wards( [1=>'A',2=>'B',3=>'C'], ['A','B, A'] )` is `[1,2,3]` (first week wins, rest alphabetical); a ward name that contains a comma, such as `Torry, Ferryhill`, is found whole before shorter names.
- [ ] **Step 2: Run** `php tests/places/planner-tests.php`. Expected: fatal error, class `ATK_Poi_Planner` not found.
- [ ] **Step 3: Implement `ATK_Poi_Planner`.** Duplicate key is `lower(name) . '|' . postcode`. Ward lookup uses `ATK_Poi_Parser::ward_key` against the context wards. `order_wards` finds each ward's position in each week text by case-insensitive search, longest names first, removing a found name from the text so shorter names cannot match inside it, then orders by week, then position; wards in no week follow, alphabetically.
- [ ] **Step 4: Run** both test files. Expected: all `ok`.
- [ ] **Step 5: Commit** `git commit -am "Places engine: planner and ward order"` (add the new test file too).

### Task 3: Pin rule (JavaScript) and area box (PHP)

**Files:**
- Create: `assets/js/poi-geo.js` (plain script that sets `window.ATK_POI_GEO` and also `module.exports` when `module` exists)
- Create: `tests/places/geo-tests.mjs` (run with `node`)
- Modify: `inc/poi-places-engine.php` (add `ATK_Poi_Geo`), `tests/places/run-tests.php`

**Interfaces:**
- Produces `ATK_POI_GEO.BOX = {latMin:57.00, latMax:57.30, lngMin:-2.40, lngMax:-1.95}`, `inBox( lat, lng ): boolean`, `acceptResult( result ): boolean`, `pick( results ): ?{lat:number,lng:number}`, `buildQueries( {name,address,postcode} ): string[]`, `searchUrl( query ): string`.
- Produces `ATK_Poi_Geo::in_box( float $lat, float $lng ): bool` and the same box as class constants.

- [ ] **Step 1: Write the failing tests.** Node: a result with `category:'amenity', type:'theatre', name:"His Majesty's Theatre", lat:'57.1488', lon:'-2.1019'` is accepted; `class:'boundary', type:'postcode'` is rejected; `class:'place', type:'suburb'` is rejected; `class:'highway', type:'residential', name:'Rosemount Viaduct'` is rejected; a `building` with no name is rejected; an amenity at `lat '51.5'` is rejected; both `class` and `category` keys are read; `pick` returns the first accepted result as numbers, or null. `buildQueries( {name:'22 CLUB',address:'55 Rose street, AB10 1UB',postcode:'AB10 1UB'} )` equals `['22 CLUB, AB10 1UB','22 CLUB, 55 Rose street, AB10 1UB']`, and empty parts are skipped. `searchUrl( 'x' )` contains `bounded=1`, `countrycodes=gb`, `format=jsonv2` and the box as `viewbox=-2.4,57.3,-1.95,57`. PHP: `in_box( 57.1497, -2.0943 )` true, `in_box( 51.5, -0.12 )` false.
- [ ] **Step 2: Run** `node tests/places/geo-tests.mjs` and `php tests/places/run-tests.php`. Expected: fail, module and class missing.
- [ ] **Step 3: Implement.** Allowed classes: `amenity, tourism, leisure, shop, office, historic, building` (schools, libraries and clubs are `amenity` in OpenStreetMap, which covers the spec's "education"). A result needs a non-empty `name`. Query one is `name, postcode`; query two is `name, address`.
- [ ] **Step 4: Run** both. Expected: all pass. Also run `node --check assets/js/poi-geo.js`.
- [ ] **Step 5: Commit** `git add assets/js/poi-geo.js inc/poi-places-engine.php tests/places && git commit -m "Places: pin accept rule and Aberdeen area box"`.

### Task 4: Table and store

**Files:**
- Modify: `inc/db-schema.php` (bump `ATK_DB_VERSION` to 5, add the table SQL to the `$sql` list)
- Create: `inc/poi-places.php` (`ATK_Poi_Store`)
- Modify: `aberdeen-knowledge.php` (require `inc/poi-places-engine.php`, then `inc/poi-places.php`, directly after `inc/neighbourhoods.php`; the engine only uses `ATK_Nb_Parser` and `ATK_Nb_Matcher` when called, so load order beyond that does not matter)
- Create: `tests/places/integration.php` (WordPress-dependent; run as `ATK_WP_LOAD=/path/to/wp-load.php php tests/places/integration.php`; creates its own instructor user and rows and removes them at the end)

**Interfaces:**
- Table `atk_poi_places`: `id`, `name` VARCHAR(255), `address` TEXT, `postcode` VARCHAR(12), `street_answer` VARCHAR(255), `neighbourhood_id` BIGINT UNSIGNED default 0, `category` VARCHAR(60), `status` VARCHAR(10) default `current`, `notes` TEXT, `lat` DECIMAL(9,6) NULL, `lng` DECIMAL(9,6) NULL, `geo_state` VARCHAR(12) default `none` (one of `none`, `looked_up`, `manual`, `not_found`), `sort_order` INT, `created_at`, `updated_at`; keys on `neighbourhood_id` and on `(name(100), postcode)`.
- `ATK_Poi_Store::all(): array[]` (Place arrays: `id,name,address,postcode,street_answer,ward_id,category,status,notes,lat,lng,geo_state`, ordered by `sort_order, id`).
- `ATK_Poi_Store::sections(): array[]` (`['ward_id'=>int,'ward'=>string,'places'=>Place[]]`, teaching order from `ATK_Poi_Planner::order_wards` fed with `atk_course_weeks('poi')` then `atk_course_weeks('street')` week texts; unknown or deleted ward ids and ward 0 go in a last section named `No ward yet`, omitted when empty).
- `ATK_Poi_Store::save( array $fields, int $id = 0 ): array|WP_Error`; `::delete( int $id ): bool`; `::plan_rows( array $parsed_rows ): array[]` (builds the planner context from the database, using `ATK_Nb_Store::neighbourhoods()` and `ATK_Nb_Store::known_street_names()`); `::commit( array $parsed_rows, array $create_wards, array $street_overrides ): array{added:int,updated:int,skipped:int,wards_created:int,lookups:array[]}` (`$create_wards` is a list of line numbers; `$street_overrides` maps line to street text; `lookups` lists `{id,name,address,postcode}` for every added or address-changed place with no hand-placed pin); `::set_pin( int $id, $lat, $lng, string $state ): array|WP_Error`; `::venues(): array[]` (pinned places only, `name, category, status, address, notes, lat, lng`, with the postcode appended to the name as `Name (AB10 1AB)` for every place whose name repeats); `::flush_cache(): void` (deletes the `atk_poi_venues` transient; every write calls it).
- Consumes `ATK_Nb_Store::ensure_neighbourhood( $name, &$cache )`, which returns `[id, created]`.

- [ ] **Step 1: Write the failing integration checks** in `tests/places/integration.php`: `save` creates a place and rejects an empty name; `commit` of two rows adds both, a second `commit` of the same rows reports `updated` 2 and `added` 0; blank cells in a re-import leave saved address and street answer unchanged; a hand-placed pin (`set_pin( id, 57.1488, -2.1019, 'manual' )`) survives a re-import that changes the address; `set_pin` with `lat` 51.5 returns a `WP_Error`; with `create_wards` containing a line, the new ward exists once in Neighbourhoods and is linked; deleting a ward with `ATK_Nb_Store::delete_neighbourhood` moves its places into the `No ward yet` section; `venues()` leaves out unpinned places and renames a repeated name with its postcode.
- [ ] **Step 2: Run** it against the throwaway test site (`bash <scratchpad>/reset-plugin.sh` starts it, then `ATK_WP_LOAD=<scratchpad>/wp/core/wp-load.php php tests/places/integration.php`). Expected: fails, class `ATK_Poi_Store` not found.
- [ ] **Step 3: Implement** the table and `ATK_Poi_Store`. Updates never overwrite a non-empty saved value with an empty plan value, never replace a `manual` pin, and reset `geo_state` to `none` only when the address or postcode changes and the pin is not `manual`. Validate in `save`: name 1 to 255 characters, category in `ATK_Poi_Planner::CATEGORIES` (else Other Amenities), status `current` or `closed`.
- [ ] **Step 4: Run** the integration script again. Expected: every line `ok`, and a second run also passes (it cleaned up). Run `php -l` on each changed file.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Places: table, store and integration checks"`.

### Task 5: REST routes

**Files:**
- Modify: `inc/poi-places.php`
- Modify: `tests/places/integration.php`

**Interfaces:**
- All instructor routes use `atk_nb_can_manage` as the permission callback. Produces under `atk/v1`:
  - `GET poi-places` returns `{sections, wards:[{id,name}], categories, counts:{total,pinned,no_pin}}`.
  - `POST poi-places` creates; `POST poi-places/(?P<id>\d+)` updates; `DELETE poi-places/(?P<id>\d+)`; each returns the Place (delete returns `{deleted:true}`).
  - `POST poi-places/preview` takes `rows` (array of arrays, at most 5000 rows by 12 cells of 500 characters, as in `atk_nb_read_input`) or `text` (pasted tab-separated text, parsed with `str_getcsv` per line on tabs), returns `{error, rows: planRows, counts:{add,update,skip,needs_street,new_ward}}`.
  - `POST poi-places/commit` takes the same input plus `create_wards` and `street_overrides`, returns `commit()`'s result.
  - `POST poi-places/(?P<id>\d+)/pin` takes `lat`, `lng`, `state` (`looked_up`, `manual`, `not_found`), returns the Place.
  - Public `GET poi-venues` (`permission_callback` `__return_true`) returns `{venues: ATK_Poi_Store::venues()}` with header `Cache-Control: public, max-age=60`.
- `atk_poi_venues()` in `inc/poi-map.php` is changed in Task 10 to return `ATK_Poi_Store::venues()`.

- [ ] **Step 1: Write the failing checks** in `integration.php` using `rest_do_request`: a logged-out request to `poi-places` gets 401 or 403; as an instructor, create, update and delete round-trip; `preview` on the three real sheet rows `22 CLUB`, `Babylon`, `Aberdeen Blood Donor Centre` returns one `needs_street` and counts that add up; `commit` with a `street_overrides` entry stores that street; the public `poi-venues` route answers without login and carries the one-minute header; a sheet with no place column returns `error` and no rows.
- [ ] **Step 2: Run** the script. Expected: route not found failures.
- [ ] **Step 3: Implement** the routes in `inc/poi-places.php`, reusing the input-reading shape of `atk_nb_read_input` (cap and truncate the same way) and `ATK_Poi_Parser::parse_rows`.
- [ ] **Step 4: Run** the script. Expected: all `ok`. `php -l inc/poi-places.php`.
- [ ] **Step 5: Commit** `git commit -am "Places: REST routes for the dashboard and the public map"`.

### Task 6: Study Tools tab and sub-tabs

**Files:**
- Modify: `inc/instructor.php` (tab list around lines 543 to 594)
- Modify: `inc/instructor-roads.php` (`atk_instructor_roads_redirect_back` and `atk_render_instructor_roads_pagination`)
- Modify: `assets/js/instructor-roads.js` only if it builds a `tab=roads` address (search it first)

**Interfaces:**
- Produces `atk_render_instructor_study_tools_tab(): void`, which reads `$_GET['tool']` (`roads` default, or `poi`), prints a line-tab sub-navigation (`nav nav-tabs nav-line-tabs fs-7 mb-6`), then calls `atk_render_instructor_roads_tab()` or `atk_render_instructor_poi_places_tab()` (Task 7). Until Task 7 exists, the poi branch prints a short "Coming soon" card; replace it there.
- Study Tools address: `?tab=study-tools&tool=roads|poi`. The old `?tab=roads` is read as `tab=study-tools&tool=roads`.

- [ ] **Step 1: Write the failing check** in `integration.php`: render the dashboard shortcode as an instructor with `$_GET['tab']='roads'` and again with `'study-tools'` plus `tool=poi`; assert the output contains `Study Tools`, does not contain a nav link labelled `Major Streets` at the top level, and for `tab=roads` contains the roads table markup and the sub-tab link `tool=poi`.
- [ ] **Step 2: Run** it. Expected: fails.
- [ ] **Step 3: Implement.** Allowed tabs become `results, course, questions, study-tools, neighbourhoods, demo`; normalise `roads` to `study-tools` with tool `roads` before rendering and for the active state. The nav label is `Study Tools`. Change both roads redirect and pagination builders to `array( 'tab' => 'study-tools', 'tool' => 'roads', ... )` and keep `roads_search` and `roads_page`.
- [ ] **Step 4: Run** the check; `php -l` the two PHP files; load `?tab=roads` and `?tab=study-tools&tool=roads` on the test site and confirm the Major Streets editor and its pagination still work.
- [ ] **Step 5: Commit** `git commit -am "Dashboard: Study Tools tab with Major Streets sub-tab"`.

### Task 7: Places of Interest screen: list by ward, add and edit

**Files:**
- Modify: `inc/poi-places.php` (add `atk_render_instructor_poi_places_tab()` and `atk_poi_script_config(): string` returning `var ATK_POI_ADMIN = {restUrl, nonce, categories}`)
- Create: `assets/js/instructor-poi-places.js`
- Modify: `assets/css/akn.css` (ward section heading, pin badge tones; only what Bootstrap lacks)
- Modify: `inc/instructor.php` (replace the Task 6 placeholder with a call to `atk_render_instructor_poi_places_tab()`)

**Interfaces:**
- Consumes `GET poi-places` and the create, update and delete routes from Task 5.
- Produces DOM ids the next tasks use: `#atk-poi-admin-root` (whole screen), `#atk-poi-toolbar` (toolbar), `#atk-poi-import-btn` (the Import button), `#atk-poi-list` (the ward sections), and the global `window.ATK_POI_ADMIN_UI = { reload(): Promise, openPinPicker( place ): void, escapeHtml( s ): string }` (the last two are filled in by Tasks 8 and 9).

- [ ] **Step 1: Invoke the `frontend-design:frontend-design` skill** and settle the layout inside the demo2 look: one card, toolbar (search, ward filter, Add place, Import spreadsheet), one section per ward with a heading, a soft count badge and a dashed-rule table (Place of interest, Address, Postcode, street answer, category badge, pin badge, edit and delete buttons), a "Show as one list" switch that adds a Ward column, and an empty state that points at Import. Write the markup choices down in the commit message.
- [ ] **Step 2: Write the failing check** in `integration.php`: the poi tab output contains `atk-poi-admin-root`, the `ATK_POI_ADMIN` config with a nonce, and enqueues `instructor-poi-places.js`.
- [ ] **Step 3: Implement** the PHP renderer and the script. Render every dynamic string through `escapeHtml` (the Review Focus XSS case); the add and edit modal has place name, address, postcode, street answer (a plain text input), ward dropdown from `wards`, category dropdown, status, notes. Delete asks for confirmation in a Bootstrap modal, not `confirm()`.
- [ ] **Step 4: Verify.** `node --check assets/js/instructor-poi-places.js`; add three places by hand on the test site (one with `<script>alert(1)</script>` in the name) and confirm each appears under its ward, the script text shows as plain text, edit and delete work, and the ward filter and flat switch work. Take screenshots at 1280, 768 and 375 px, light and dark, and fix any overflow.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Places of Interest screen: ward sections, add, edit, delete"`.

### Task 8: Import flow

**Files:**
- Modify: `assets/js/instructor-poi-places.js`, `inc/poi-places.php` (enqueue `atk-nb-xlsx` for the poi tab), `assets/css/akn.css` if needed

**Interfaces:**
- Consumes `POST poi-places/preview` and `POST poi-places/commit`, `window.XLSX` (bundled SheetJS), `ATK_POI_ADMIN_UI.reload()`.
- Produces a global `ATK_POI_ADMIN_UI.afterCommit( result )` hook that Task 9 sets; this task calls it with the commit result if present.

- [ ] **Step 1: Invoke `frontend-design:frontend-design`** for the import modal: a drop zone and paste box, then a review table with one status badge per row (ready, needs a street, new ward, will update, skipped), an editable street answer on rows that need one, a "Create neighbourhood" checkbox on new-ward rows, a count summary above, Save and Cancel below. Follow the layout of `assets/js/instructor-neighbourhoods.js` so the two screens read as one family.
- [ ] **Step 2: Write the failing check** in `integration.php`: a commit request that mirrors what the browser sends (the sheet rows as arrays, `create_wards` of one line, `street_overrides` of one line) adds the rows and creates one ward; run through the preview first.
- [ ] **Step 3: Implement** reading `.xlsx` and `.csv` with SheetJS (first sheet, `header: 1`, all values as strings), pasted tab-separated text, a size guard of 5000 rows with a clear message, preview, the review table, and commit. After commit, show a summary and call `afterCommit`.
- [ ] **Step 4: Verify** with a real file: build a small `.xlsx` and a `.csv` from the sheet's first ten rows (copy the values from the spec's sheet examples), import each on the test site, and confirm statuses, the ward-creation choice, the street override, duplicates on a second import, and that an import with a wrong header shows the plain error. Screenshots at 1280 and 375 px.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Places of Interest: spreadsheet and paste import with a review step"`.

### Task 9: Pin lookup, resume and hand placement

**Files:**
- Modify: `assets/js/instructor-poi-places.js`, `inc/poi-places.php` (enqueue Leaflet via `atk_enqueue_leaflet()` and `poi-geo.js` for the poi tab)

**Interfaces:**
- Consumes `ATK_POI_GEO` (Task 3), `POST poi-places/{id}/pin`, `commit().lookups`, `ATK_POI_ADMIN_UI.afterCommit`.
- Produces `ATK_POI_ADMIN_UI.lookupPins( places ): Promise` (runs the queue, one request per second, progress bar, cancellable, marks `looked_up` or `not_found` through the pin route), a "Look up missing pins" button that runs it for every place whose `geo_state` is `none`, and `ATK_POI_ADMIN_UI.openPinPicker( place )` (a modal with a Leaflet map, click to set, saved as `manual`).

- [ ] **Step 1: Write the failing check** in `integration.php`: the pin route stores `looked_up` and `not_found`, rejects an outside-box coordinate, and a `not_found` place is not in the list of places with state `none`.
- [ ] **Step 2: Implement** the queue: for each place try `ATK_POI_GEO.buildQueries` in order against `ATK_POI_GEO.searchUrl`, take the first `pick`, post the result; if none pass, post `not_found`. Wait 1000 ms between requests. The OpenStreetMap attribution is on the picker map. The "Look up missing pins" button shows the count and is hidden at zero.
- [ ] **Step 3: Verify.** `node --check`. On the test site stub Nominatim with a local response (a small mock server on another port, `mock.py` in the scratchpad is a starting point) and confirm the progress bar, that closing the tab halfway leaves the rest `none`, that the resume button then finishes only those, and that hand placement turns the badge to "Placed by hand". Screenshot the progress state and the picker.
- [ ] **Step 4: Commit** `git add -A && git commit -m "Places of Interest: pin lookup, resume and hand placement"`.

### Task 10: Public page

**Files:**
- Modify: `inc/poi-map.php` (`atk_poi_venues()`, `atk_render_poi_map()`, the header comment)
- Modify: `assets/js/poi-map.js` (`loadVenues` and the empty state)
- Delete: `assets/data/poi.json`, `assets/data/poi-source.csv`
- Modify: `inc/study-tools.php` only if its count line needs nothing more than the new `atk_poi_venues()` (it should not)

**Interfaces:**
- `atk_poi_venues(): array[]` now returns `ATK_Poi_Store::venues()`, cached for the request.
- The map script reads `ATK_POI.dataUrl`, now `rest_url( 'atk/v1/poi-venues' )`, and treats `{venues: []}` as the empty state.

- [ ] **Step 1: Write the failing check** in `integration.php`: with no pinned places, `atk_poi_venues()` is empty and the rendered map markup contains the empty-state card text `Places will appear here once an instructor imports them.`; with two pinned places it returns both and the intro modal text counts only the current ones.
- [ ] **Step 2: Implement.** Replace the file read with the store call, change `dataUrl`, add the empty-state card (demo2 card, same position and styling as the status card) and keep Take Test disabled with no current pins. Update the file's header comment. Remove the two data files with `git rm`.
- [ ] **Step 3: Verify.** `node --check assets/js/poi-map.js`; load `/places-of-interest/` on the test site empty (empty state, no console errors), then pin three imported places and load it again (pins, legend, hover text, Take Test works, a name containing `<b>` shows as text). Screenshots at 1280 and 375 px.
- [ ] **Step 4: Commit** `git add -A && git commit -m "Public Places of Interest page reads the imported places"`.

### Task 11: POI tests use the imported places

**Files:**
- Modify: `inc/quiz-data.php` (`atk_poi_test_questions()`)
- Modify: `inc/instructor.php` (`atk_render_instructor_poi_questions()`, `atk_instructor_known_poi_names()`, the Course Builder POI question modal around line 1028)
- Modify: `inc/poi-places.php` (`ATK_Poi_Store::week_places()`)
- Modify: `inc/neighbourhoods.php` (`atk_nb_enqueue_course_builder` passes `weekPlaces`)
- Modify: `assets/js/instructor-course-neighbourhoods.js`

**Interfaces:**
- `atk_poi_test_questions()` returns `array( 'poi' => name, 'accepted' => array( street_answer ) )` for every current place with a non-empty street answer, in `sort_order, id` order (same shape as today).
- `ATK_Poi_Store::week_places( array $weeks ): array<int,array<int,array{name:string,street:string}>>` maps a week id to the places in that week's wards (using `ATK_Nb_Store::ids_in_text` on the week's joined neighbourhoods), only for weeks that have any.
- `atk_instructor_known_poi_names(): string[]` returns the saved place names.
- Course Builder JS receives `ATK_NB_COURSE.weekPlaces` and `ATK_NB_COURSE.places` (all places as `{name, street}`).

- [ ] **Step 1: Write the failing checks** in `integration.php`: `atk_poi_test_questions()` returns only places with a street answer and status current, with the accepted array holding exactly the street answer; `atk_instructor_known_poi_names()` equals the saved names; `week_places` for a week listing one ward returns only that ward's places.
- [ ] **Step 2: Implement** the functions. In the Question Bank, `atk_render_instructor_poi_questions()` becomes a card with the place count and a button to `?tab=study-tools&tool=poi`; the old typed list, bulk editor and modal markup for the mockup bank are removed from the page (handlers stay), and `assets/js/instructor-dashboard.js` is searched for the removed ids (`atk-poi-modal`, `atk-poi-bulk-form`, `atk-poi-bulk-text`, `atk-poi-bulk-count`) and any reference is guarded so no console error appears. In the Course Builder POI modal the place picker offers the saved places; picking one fills Accepted Answers with its street, and a switch "Only places in this week's wards" (on by default when the week has any) narrows the list, mirroring the Street form's switch in `instructor-course-neighbourhoods.js`.
- [ ] **Step 3: Verify.** `node --check`; on the test site take the mockup POI test with three imported places and confirm the prompt `Which street would you find <place> on?` and that the street answer is accepted; with no places confirm the empty message; create a Course Builder POI question from the picker and confirm it saves as before. Screenshots of the Question Bank card and the modal.
- [ ] **Step 4: Commit** `git add -A && git commit -m "POI tests draw from the imported places"`.

### Task 12: Docs, version and full check

**Files:**
- Modify: `CLAUDE.md`, `aberdeen-knowledge.php` (Version and `ATK_VERSION` to `1.1.0`), `README.md` if it lists features, `docs/superpowers/specs/2026-10-14-study-tools-places-of-interest-design.md`

- [ ] **Step 1: Update the spec** to match what was built: add `not_found` to the `geo_state` values, say interior full stops in an address are kept and only trailing ones trimmed, and name the accepted OpenStreetMap classes.
- [ ] **Step 2: Update `CLAUDE.md`:** add `inc/poi-places-engine.php` and `inc/poi-places.php` to the module list, a short "Places of Interest" section (storage, import rules, pins, ward order, public route), the new test commands (`php tests/places/run-tests.php`, `php tests/places/planner-tests.php`, `node tests/places/geo-tests.mjs`, the integration script), and that the 87 built-in venues were removed. Note the public Nominatim limit under Maps.
- [ ] **Step 3: Bump the version** and run the full check: `php -l` on every changed PHP file, `node --check` on every changed JS file, all test files, the integration script, then one last screenshot pass of Study Tools (both sub-tabs), the import review, the public page empty and filled, and the POI test, in light and dark at 1280 and 375 px.
- [ ] **Step 4: Commit and push** `git add -A && git commit -m "Release 1.1.0: Study Tools and Places of Interest question bank" && git push origin main`. Tell Jay the tag to push is `v1.1.0`, and the live checks to make after updating: purge the page cache, confirm `/wp-json/atk/v1/poi-venues` is not served stale by the CDN, then import the official sheet.
