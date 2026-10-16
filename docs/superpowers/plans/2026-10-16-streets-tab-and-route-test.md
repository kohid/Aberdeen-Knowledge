# Streets tab and Official Route test: build plan

Spec: `docs/superpowers/specs/2026-10-16-streets-tab-and-route-test-design.md`. Executed inline, tests first.

1. **Engine** (`inc/streets-engine.php`, `tests/streets/run-tests.php`): `ATK_Route_Pairs` (normalise, clean, matches either order, display), `ATK_Street_Parser` (header map and rows with Clarify/From/To, merging repeated streets), `ATK_Route_Planner` (status, ward weeks in official order, even split over 5 tests, minutes). Add the suite to `tests/run-all.sh`.
2. **Schema and store** (`inc/db-schema.php`, `inc/streets.php`): version 7 columns; `ATK_Street_Store` (list, save, delete, import preview/commit, course preview/apply).
3. **Streets tab** (`inc/streets.php` REST, `inc/instructor.php` tab, `assets/js/instructor-streets.js`, `assets/css/akn.css`).
4. **Route test** (`inc/course.php`, `inc/attempts.php`, `assets/js/quiz.js`): pair payload and grading, time, clamps, review, catalog totals; Course Builder card.
5. **Verify and ship**: all suites, WordPress integration run, screenshots, CLAUDE.md, version 1.4.0, commit and push, return the client's sheet.
