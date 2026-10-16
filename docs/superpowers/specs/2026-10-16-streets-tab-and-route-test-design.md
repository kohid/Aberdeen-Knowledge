# Streets tab and the Official Route test

Date: 2026-10-16. Status: approved in conversation (Jay).

## Why

The client supplied the list of streets for the Official Route test (2,280 rows, `List_of_Streets.xlsx`). In the Route test each street is a question. The student types where the route **From** and **To** the street begins and ends. Many streets have more than one correct answer. Ten streets are highlighted red in the sheet and need the client's clarification, so they stay out of the questions until cleared.

## What Jay decided

1. Answers (From and To) are typed into a new Streets tab on the Instructor Dashboard. An optional import reads the same columns later.
2. A reply is correct when it matches any listed pair, in either order.
3. Only the Official Route test changes. The mockup Route Test stays multiple choice.
4. No 20-question cap: the Official test is divided so every ready street is asked.

## Streets tab (`?tab=streets`)

Two views of one list.

- **Alphabetical** (editable): search, filter (All, Ready, Needs answer, Needs clarification), A to Z jump, Add, Edit, Delete. A street has a name, one or more wards, postcodes, a note, a "needs clarification" flag and any number of From/To pairs.
- **By ward** (read only): the same streets grouped under the 13 official wards in order, then any other wards, then "No ward". A street in two wards appears under both. It is computed from the same data, so every change in Alphabetical shows here with no second save.

A street is on the list when it is flagged as listed or has a ward link. Deleting from the list unlinks its wards and clears its flag, pairs and listed state. The registry row stays (it may be a real OpenStreetMap street).

Status of a street: **Needs clarification** (flag set), else **Needs answer** (no pair), else **Ready**.

## Data

- `atk_nb_streets` gains `listed` (tinyint), `flagged` (tinyint), `routes` (JSON text, `[["From","To"],...]`). Ward links stay in `atk_nb_links`, so the Neighbourhoods tab and the Streets tab always agree.
- `course_route_questions` gains `street` (varchar) and `pairs` (JSON text). A row with a `street` is a pair question. Old multiple-choice rows are kept untouched and unused by the Official test, as was done for the POI migration.
- `ATK_DB_VERSION` goes to 7 (dbDelta adds the columns).

## Matching

`ATK_Route_Pairs` (pure PHP, `inc/streets-engine.php`): text is compared after the same normalising the street registry uses (case, apostrophes, punctuation and hyphens). A reply of `(from, to)` is correct when it equals some listed pair as `(from, to)` or `(to, from)`. Both boxes must be filled. Street, Road, Avenue, Drive and Boulevard match St, Rd, Ave, Dr and Blvd (as in the Street test); nothing else is guessed.

## Official Route test

- One week per official ward, 13 weeks in official order (`ATK_Poi_Planner::OFFICIAL_WARDS`), as the POI test does.
- A ward's ready streets (listed, not flagged, at least one pair) are sorted A to Z and divided evenly into the week's 5 tests, so each is asked once. The first `n mod 5` tests get one extra. A street in two wards is asked in both weeks.
- Ward names are matched ignoring case, spaces around slashes and St = Street ("George St/Harbour" is the official "George Street/Harbour"). Streets with no official ward are reported as left out, never guessed. Flagged streets and streets without a pair are listed in the preview.
- A test's time is `max(20, number of questions)` minutes, and the pass mark stays 75 percent.
- The Course Builder Route tab gets a card, "Fill the Official Route test from your streets", with a preview and a "rewrite" tick. Weeks that already hold questions are untouched unless "rewrite" is ticked. Nothing else is deleted.
- The quiz shows two boxes (From, To) for pair questions. The review shows every accepted pair.
- The limits that assume 20 questions (`atk_handle_course_complete`, `atk_handle_record_review`, catalog totals) become real counts for the route track.

## Import (optional)

Paste or open an `.xlsx`/`.csv` with the columns Road, Ward, Post Code, Address, Note, Clarify, From, To. Rows repeating a street add pairs. Blank cells never erase saved data. A street not on the site is shown in the review and added only when the review is confirmed. A ward not on the site is reported and skipped (wards are created in the Neighbourhoods tab). The server rebuilds the plan when saving.

## Deliverables

Engine and tests, schema, Streets tab, quiz and Course Builder changes, CLAUDE.md update, release 1.4.0, and the client's sheet returned with a Clarify column (the ten red rows marked) and empty From and To columns.

## Out of scope

The mockup Route Test, route maps, the 827 unnamed road segments decision (still waiting on Jay).
