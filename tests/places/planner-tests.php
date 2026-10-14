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

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
