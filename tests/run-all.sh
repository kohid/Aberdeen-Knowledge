#!/bin/bash
# Runs every plain test in the plugin (no WordPress needed). Usage: bash tests/run-all.sh
cd "$(dirname "$0")/.." || exit 1
status=0
for file in tests/neighbourhoods/run-tests.php tests/neighbourhoods/planner-tests.php tests/brand/run-tests.php tests/places/run-tests.php tests/places/planner-tests.php tests/streets/run-tests.php; do
	[ -f "$file" ] || continue
	echo "== php $file"
	php "$file" | tail -1 || status=1
	[ "${PIPESTATUS[0]}" -eq 0 ] || status=1
done
for file in tests/places/geo-tests.mjs; do
	[ -f "$file" ] || continue
	echo "== node $file"
	node "$file" | tail -1 || status=1
	[ "${PIPESTATUS[0]}" -eq 0 ] || status=1
done
exit $status
