# Study Tools tab and Places of Interest question bank

Status: draft for review. No code has been written.

## What Jay asked for

The Instructor Dashboard tab "Major Streets" becomes "Study Tools". Major Streets moves inside it as a sub-tab, and a new "Places of Interest" sub-tab sits beside it. Instructors add places by hand or import a spreadsheet (`.xlsx` or `.csv`) with the columns Place of interest, Street, Postcode and Ward, in ward order.

The imported places are the only places of interest the site knows about. They replace the 87 built-in venues on `https://aberdeenknowledge.taxi/places-of-interest/`, and they become the question bank for the POI tests. Jay holds the official copy of the list and will import it himself.

Decisions taken during brainstorming:

1. The Official course's POI test picks its places from the saved list in the Course Builder. The mockup POI test and the public map's Take Test draw straight from it.
2. The street answer is worked out by matching the Street column against the site's real road names, then a review step. Unmatched rows are never guessed.
3. Map pins come from an OpenStreetMap lookup with a confidence check. Places that fail it are still saved and listed, and can be pinned by hand.
4. Category is an optional column. Rows without one are filed under Other Amenities and can be changed from a dropdown.
5. The POI questions already typed into the database stay there, unused and hidden, until Jay asks for them to be deleted.

## Out of scope

Deleting any existing database rows. Changing the Street, Route or Neighbourhoods tests. Editing the Major Streets screen beyond moving it under a sub-tab. Inventing coordinates, categories or street names: anything not found is left empty and flagged.

## Data

A new table, `atk_poi_places`, created through `db-schema.php` with `dbDelta` (the same way every other table is made, so nothing runs destructively).

| Column | Meaning |
| --- | --- |
| `id` | Primary key |
| `name` | Place name, as written |
| `address` | The Street cell as written, kept in full |
| `postcode` | Postcode cell, tidied (upper case, one space) |
| `street_answer` | The matched real road name, or empty |
| `neighbourhood_id` | The ward, a link to `atk_nb_neighbourhoods`, or 0 |
| `category` | One of the fixed list below |
| `status` | `current` or `closed` |
| `notes` | Free text, instructor only |
| `lat`, `lng` | Pin position, or null |
| `geo_state` | `looked_up`, `manual` or `none` |
| `sort_order` | Keeps the sheet's order within a ward |
| `created_at`, `updated_at` | Timestamps |

Categories are the existing eleven (Restaurants and Cafes, Hotels, Museums Arts & Culture, Sports Facilities, Schools Libraries & Learning, Community Centres, Religious Buildings & Cemeteries, Clubs & Members Clubs, Other Amenities, Living Spaces, Transport Hubs). The map legend already builds itself from the data, so nothing in the map script depends on this list.

Wards reuse Neighbourhoods, so a ward is never typed twice and the Course Builder can match places to a week's wards with the existing `ATK_Nb_Store::ids_in_text()`.

## The import engine

A pure PHP file, `inc/poi-places-engine.php`, with no WordPress calls, tested like `neighbourhoods-engine.php`.

**Input.** A pasted sheet or a read `.xlsx` or `.csv` (read in the browser with the bundled SheetJS). Columns are found by header name, ignoring case and spacing: Place of interest, Street, Postcode, Ward, and optionally Category, Status and Note. A row with no place name is skipped.

**Ward match.** A ward is compared after lower-casing and removing spaces around slashes, so "Midstocket / Rosemount" equals "Midstocket/Rosemount". No match means the row is marked "new ward" and the review offers "Create neighbourhood" per row. It is never created silently.

**Street match.** From the Street cell the engine drops the postcode, drops house numbers and ranges such as "43-45", splits on commas and full stops, then tries each part from the right against the real road names, ignoring case and treating Rd/Road and St/Street alike. From the real sheet: "Rosemount viaduct" matches, "Old stoneywood church, Bankhead road, AB21 9HQ" matches Bankhead Road, and "Forresterhill" has no match and is marked "needs a street". A close lookalike is shown as a suggestion only.

**Duplicates.** A place is the same place when its lower-cased name and its postcode agree. A file import updates the existing place (ward, address, street answer, category, status), blank cells never erase saved data, and a hand-placed pin is never overwritten.

**Review.** Every row gets one status badge: ready, needs a street, new ward, or duplicate (will update). The server rebuilds the plan at save time from the posted rows and never trusts the browser's copy.

## Pins

After the import is saved, the instructor's browser looks each new or changed place up in OpenStreetMap through Nominatim, one place per second, with a progress bar. It tries the place name with its postcode first, then the address. A result is accepted only when all of these hold: it is a named feature, not a postcode, boundary or place-area centre; its type matches an amenity, tourism, leisure, shop, office, historic, education or building class; and it lies inside the Aberdeen area box (latitude 57.00 to 57.30, longitude -2.40 to -1.95, a named constant). The browser sends the result to the server, which checks the box again before storing it.

A place with no accepted result keeps `geo_state` = `none`, appears on the dashboard as "No pin yet", and stays in the question bank and on the public list. Clicking "Place pin" in its edit window opens a small Leaflet map where the instructor clicks the spot, which stores `manual`. The public Nominatim server is for light use, so lookups run only for new or changed places, never for the whole list again.

## Instructor screen

`?tab=study-tools&tool=roads` or `&tool=poi`. The sub-navigation uses the same line-tab style as the dashboard tabs. The old `?tab=roads` address, including `roads_search` and `roads_page`, keeps working and lands on the Major Streets sub-tab, and the two redirects in `inc/instructor-roads.php` are updated to the new address.

Places of Interest is one card in the demo2 look: a toolbar with a search box, a ward filter, "Add place" and "Import spreadsheet", then a table grouped by ward in the sheet's order. A ward heading shows its name and place count as a soft badge. Rows show place, address, postcode, street answer, a category badge and a pin badge (Pinned, Placed by hand, No pin yet), with edit and delete buttons. Add and edit share one modal with the ward as a dropdown of existing neighbourhoods, the category dropdown, status, notes and the pin picker. Import opens a drop zone and paste box, then the review table, then Save. The layout and badges copy the Neighbourhoods import so the two screens read as one family.

REST routes under `atk/v1`, instructors and administrators only: `poi-places` (list, create, update, delete), `poi-places/preview` and `poi-places/commit` (import), and `poi-places/pin` (store a looked-up or hand-placed position).

## Public page

`atk_poi_venues()` is the single function every part of the page reads. It stops reading `assets/data/poi.json` and returns the saved places that have a pin. The map script reads a new public route, `atk/v1/poi-venues`, instead of the file. The response is cached in a transient that every save clears, and it is sent with a one-minute `Cache-Control` so the CDN cannot hold it for days. The map script is otherwise unchanged: categories, closed places, the Study Mode, Take Test and Review all behave as now. The map matches places by name, so when two places share a name the response adds the postcode to the displayed name of each, for example "Tesco (AB10 1AB)".

With no places saved, the page shows the map with a card saying places will appear once an instructor imports them, and Take Test is disabled. The intro modal count, the Study Tools hub card figure and the Take Test pool all come from the same function, so they always agree.

`assets/data/poi.json` and `assets/data/poi-source.csv` are deleted from the plugin. They stay in git history.

## POI tests

**Mockup POI test.** `atk_poi_test_questions()` reads the saved places that have a street answer and returns the same shape it returns today (the place name and a list of one accepted answer). The test page and its scoring are unchanged. With none saved, it shows the same "No questions yet" message the other tests use when empty.

**Question Bank, POI sub-tab.** The typed list and its bulk editor are replaced by a short card with the place count and a button to Study Tools, Places of Interest. The old rows in the `quiz_poi_questions` table stay in the database untouched.

**Official course.** The Course Builder's POI question form gets its place picker from the saved places, and choosing one fills the accepted street from its street answer. A switch narrows the picker to the places in the week's wards. A saved question still stores the place name and accepted answers, so nothing that reads weeks or tests changes, and the existing course POI questions keep working. `atk_instructor_known_poi_names()` reads from the saved places.

## Rollout

Bump the plugin to 1.1.0 and tag it. The new table is created on update. After deploying, purge the page cache and check on the live site that `atk/v1/poi-venues` is not served stale by Hostinger's CDN. Jay then imports his official sheet, watches the pin lookup finish, and fixes the "needs a street" and "No pin yet" rows from the dashboard.

## Testing

Plain PHP tests in `tests/places/` for the engine: header detection, ward matching with the real "Midstocket / Rosemount" variant, street matching on the sheet's real examples (including "Forresterhill" returning no match), duplicate handling, blank cells never erasing data, and the pin accept rule against saved sample responses. `php -l` and `node --check` on every changed file. Real screenshots of the Study Tools sub-tabs, the import review, the empty and filled public page, and the POI test on a Hello Elementor base, in light and dark mode and at phone width.

## Assumptions to confirm

The Aberdeen area box is wide enough for outer wards such as Bridge of Don and Dyce, and will be checked against the real list during the build. Category and Status are optional columns, and Status defaults to current.
