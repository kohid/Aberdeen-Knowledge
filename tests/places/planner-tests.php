<?php
/**
 * Run with: php tests/places/planner-tests.php
 * Covers the Places of Interest planner: what an import would add, update or skip,
 * and the order the ward sections appear in.
 */
define( 'ATK_NB_TESTING', true );
require __DIR__ . '/../../inc/neighbourhoods-engine.php';
require __DIR__ . '/../../inc/poi-places-engine.php';

$failures = 0;
$checks   = 0;
function check( $label, $actual, $expected ) {
	global $failures, $checks;
	$checks++;
	if ( $actual === $expected ) {
		echo "ok    $label\n";
		return;
	}
	$failures++;
	echo "FAIL  $label\n      expected: " . json_encode( $expected ) . "\n      actual:   " . json_encode( $actual ) . "\n";
}

$ctx = array(
	'wards'    => array( 1 => 'Midstocket/Rosemount', 2 => 'George St/Harbour' ),
	'existing' => array(
		array( 'id' => 7, 'name' => '22 CLUB', 'postcode' => 'AB10 1UB', 'street_answer' => '' ),
		array( 'id' => 8, 'name' => 'Babylon', 'postcode' => 'AB10 1YD', 'street_answer' => 'ALFORD PLACE' ),
	),
	'roads'    => array( 'ROSE STREET', 'ALFORD PLACE', 'ROSEMOUNT VIADUCT' ),
);
function row( $line, $name, $address, $postcode, $ward, $category = '', $status = '' ) {
	return array( 'line' => $line, 'name' => $name, 'address' => $address, 'postcode' => $postcode, 'ward' => $ward, 'category' => $category, 'status' => $status, 'note' => '' );
}

$plan = ATK_Poi_Planner::plan( array(
	row( 2, '22 CLUB', '55 Rose street, AB10 1UB', 'AB10 1UB', 'Midstocket/Rosemount' ),
	row( 3, 'Babylon', '9 Alford Place, Aberdeen', 'AB10 1YD', 'Midstocket / Rosemount' ),
	row( 4, 'Unknown Place', '1 Rose street, AB10 1AA', 'AB10 1AA', 'Hazlehead/Queens Cross/Countesswells' ),
	row( 5, 'No Ward Place', '2 Rose street, AB10 1AB', 'AB10 1AB', '' ),
	row( 6, 'Aberdeen Blood Donor Centre', 'Foresterhill, AB25 2ZW', 'AB25 2ZW', 'Midstocket/Rosemount' ),
	row( 7, '22 club', '55 Rose street, AB10 1UB', 'AB10 1UB', 'Midstocket/Rosemount' ),
	row( 8, 'Grand Hotel', '3 Rose street, AB10 1AC', 'AB10 1AC', 'George St/Harbour', 'hotels', 'Closed' ),
	row( 9, 'Odd Place', '4 Rose street, AB10 1AD', 'AB10 1AD', 'George St/Harbour', 'Nonsense', 'open' ),
	row( 10, 'Plain Place', '5 Rose street, AB10 1AE', 'AB10 1AE', 'George St/Harbour', '', '' ),
), $ctx );

check( 'one plan row per sheet row', count( $plan ), 9 );
check( 'existing place is an update', $plan[0]['action'], 'update' );
check( 'update keeps the existing id', $plan[0]['existing_id'], 7 );
check( 'ward found by key', $plan[0]['ward_id'], 1 );
check( 'street answer from the road list', $plan[0]['street_answer'], 'ROSE STREET' );
check( 'a ready row has no issues', $plan[0]['issues'], array() );
check( 'spaces around the slash still find the ward', $plan[1]['ward_id'], 1 );
check( 'update with a saved street answer and a match has no issues', $plan[1]['issues'], array() );
check( 'new place is an add', $plan[2]['action'], 'add' );
check( 'unknown ward has a null ward id', $plan[2]['ward_id'], null );
check( 'unknown ward is flagged', $plan[2]['issues'], array( 'new_ward' ) );
check( 'empty ward is ward 0', $plan[3]['ward_id'], 0 );
check( 'empty ward is not an issue', $plan[3]['issues'], array() );
check( 'a bare area needs a street', $plan[4]['issues'], array( 'needs_street' ) );
check( 'a bare area has an empty street answer', $plan[4]['street_answer'], '' );
check( 'the same name and postcode again is skipped', $plan[5]['action'], 'skip' );
check( 'category matched ignoring case', $plan[6]['category'], 'Hotels' );
check( 'status Closed becomes closed', $plan[6]['status'], 'closed' );
check( 'unknown category becomes Other Amenities', $plan[7]['category'], 'Other Amenities' );
check( 'any other status is current', $plan[7]['status'], 'current' );
check( 'empty category becomes Other Amenities', $plan[8]['category'], 'Other Amenities' );

check( 'a known category is marked as given', $plan[6]['category_given'], true );
check( 'an unknown category is not marked as given', $plan[7]['category_given'], false );
check( 'an empty category is not marked as given', $plan[8]['category_given'], false );
check( 'a status cell is marked as given', array( $plan[6]['status_given'], $plan[7]['status_given'] ), array( true, true ) );
check( 'an empty status cell is not marked as given', $plan[8]['status_given'], false );

$blank = ATK_Poi_Planner::plan( array( row( 2, '22 CLUB', '', '', '' ) ), $ctx );
check( 'blank cells stay blank in the plan', array( $blank[0]['address'], $blank[0]['postcode'], $blank[0]['ward'] ), array( '', '', '' ) );

check( 'eleven categories', count( ATK_Poi_Planner::CATEGORIES ), 11 );

// Ward order.
check( 'wards follow the weeks, then position in the week', ATK_Poi_Planner::order_wards( array( 1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D' ), array( 'B, A', 'C' ) ), array( 2, 1, 3, 4 ) );
check( 'a ward in two weeks sits at its first week', ATK_Poi_Planner::order_wards( array( 1 => 'A', 2 => 'B', 3 => 'C' ), array( 'A', 'B, A' ) ), array( 1, 2, 3 ) );
check( 'wards in no week come last, alphabetically', ATK_Poi_Planner::order_wards( array( 1 => 'Zeta', 2 => 'Alpha', 3 => 'Mid' ), array( 'Mid' ) ), array( 3, 2, 1 ) );
check( 'a ward name with a comma is found whole', ATK_Poi_Planner::order_wards( array( 1 => 'Torry, Ferryhill', 2 => 'Torry', 3 => 'Ferryhill' ), array( 'Torry, Ferryhill' ) ), array( 1, 3, 2 ) );
check( 'no weeks gives alphabetical order', ATK_Poi_Planner::order_wards( array( 5 => 'B', 6 => 'A' ), array() ), array( 6, 5 ) );

// ---- Official POI course: 13 wards, 5 tests a week --------------------------------------------
check( 'there are 13 official wards', count( ATK_Poi_Planner::OFFICIAL_WARDS ), 13 );
check( 'ward 1 is Dyce/Bucksburn/Danestone', ATK_Poi_Planner::OFFICIAL_WARDS[0], 'Dyce/Bucksburn/Danestone' );
check( 'ward 7 is Midstocket/Rosemount', ATK_Poi_Planner::OFFICIAL_WARDS[6], 'Midstocket/Rosemount' );
check( 'ward 10 is Hazlehead/Queens Cross/Countesswells', ATK_Poi_Planner::OFFICIAL_WARDS[9], 'Hazlehead/Queens Cross/Countesswells' );
check( 'ward 13 is Kincorth/Nigg/Cove', ATK_Poi_Planner::OFFICIAL_WARDS[12], 'Kincorth/Nigg/Cove' );

function it_items( $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = array( 'poi' => "Place $i", 'accepted' => array( "Street $i" ) );
	}
	return $out;
}
$small = ATK_Poi_Planner::course_tests( it_items( 4 ) );
check( 'five tests are always made', count( $small['tests'] ), 5 );
check( 'a small ward puts every place in every test', array_map( 'count', $small['tests'] ), array( 4, 4, 4, 4, 4 ) );
$mid = ATK_Poi_Planner::course_tests( it_items( 31 ) );
check( 'a 31 place ward fills every test to 20', array_map( 'count', $mid['tests'] ), array( 20, 20, 20, 20, 20 ) );
$dupe_free = true;
$seen_any  = array();
foreach ( $mid['tests'] as $test ) {
	$names = array_column( $test, 'poi' );
	if ( count( $names ) !== count( array_unique( $names ) ) ) {
		$dupe_free = false;
	}
	$seen_any = array_merge( $seen_any, $names );
}
check( 'no place repeats inside one test', $dupe_free, true );
check( 'every place appears in at least one test', count( array_unique( $seen_any ) ), 31 );
check( 'the split is repeatable', ATK_Poi_Planner::course_tests( it_items( 31 ) ), $mid );
$exact = ATK_Poi_Planner::course_tests( it_items( 100 ) );
check( 'a 100 place ward uses each place once', count( array_unique( array_merge( ...array_map( function ( $t ) { return array_column( $t, 'poi' ); }, $exact['tests'] ) ) ) ), 100 );
$big = ATK_Poi_Planner::course_tests( it_items( 130 ) );
check( 'more than 100 places are capped at 100 questions', array_sum( array_map( 'count', $big['tests'] ) ), 100 );
check( 'the places that did not fit are reported', count( $big['left_out'] ), 30 );
check( 'an empty ward makes five empty tests', array_map( 'count', ATK_Poi_Planner::course_tests( array() )['tests'] ), array( 0, 0, 0, 0, 0 ) );

$places = array(
	array( 'name' => 'Old Blackfriars', 'label' => 'Old Blackfriars', 'postcode' => 'AB11 5BB', 'ward' => 'George Street/Harbour', 'street_answer' => 'CASTLEGATE', 'status' => 'current' ),
	array( 'name' => '22 CLUB', 'label' => '22 CLUB', 'postcode' => 'AB10 1UB', 'ward' => 'Midstocket / Rosemount', 'street_answer' => 'ROSE STREET', 'status' => 'current' ),
	array( 'name' => 'Seaview Caravan Park', 'label' => 'Seaview Caravan Park', 'postcode' => 'AB23 8BR', 'ward' => 'Bridge of Don', 'street_answer' => '', 'status' => 'current' ),
	array( 'name' => 'Old Cinema', 'label' => 'Old Cinema', 'postcode' => 'AB10 1AA', 'ward' => 'Bridge of Don', 'street_answer' => 'KING STREET', 'status' => 'closed' ),
	array( 'name' => 'Trinity Court', 'label' => 'Trinity Court', 'postcode' => 'AB32 6LS', 'ward' => 'Westhill and District', 'street_answer' => 'TRINITY COURT', 'status' => 'current' ),
	array( 'name' => 'No Ward Place', 'label' => 'No Ward Place', 'postcode' => 'AB10 1AB', 'ward' => '', 'street_answer' => 'KING STREET', 'status' => 'current' ),
);
$research = array(
	ATK_Poi_Planner::research_key( 'Old Blackfriars', 'AB11 5BB' ) => array( 'street' => 'Castle Street', 'also' => array( 'Castlegate' ) ),
);
$plan = ATK_Poi_Planner::course_plan( $places, $research );
check( 'the plan has 13 weeks', count( $plan['weeks'] ), 13 );
check( 'week numbers run 1 to 13', array_column( $plan['weeks'], 'number' ), range( 1, 13 ) );
check( 'week 8 is George Street/Harbour', $plan['weeks'][7]['ward'], 'George Street/Harbour' );
check( 'researched street comes first, saved and extra after', $plan['weeks'][7]['questions'][0]['accepted'], array( 'Castle Street', 'Castlegate' ) );
$only_plan = ATK_Poi_Planner::course_plan( $places, array(
	ATK_Poi_Planner::research_key( 'Old Blackfriars', 'AB11 5BB' ) => array( 'street' => 'Castle Street', 'only' => true, 'also' => array() ),
) );
check( 'an only-research street is the single accepted answer', $only_plan['weeks'][7]['questions'][0]['accepted'], array( 'Castle Street' ) );
check( 'a ward spelled with spaces still lands in its week', $plan['weeks'][6]['questions'][0]['poi'], '22 CLUB' );
check( 'an unresearched answer is just the saved street', $plan['weeks'][6]['questions'][0]['accepted'], array( 'ROSE STREET' ) );
check( 'a place with no street answer is left out and named', $plan['weeks'][1]['no_answer'], array( 'Seaview Caravan Park' ) );
check( 'a closed place is not asked', count( $plan['weeks'][1]['questions'] ), 0 );
check( 'places outside the 13 wards are reported', array_column( $plan['outside'], 'name' ), array( 'Trinity Court', 'No Ward Place' ) );
check( 'each week carries five tests', count( $plan['weeks'][7]['tests'] ), 5 );

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
