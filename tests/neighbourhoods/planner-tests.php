<?php
/**
 * Run with: php tests/neighbourhoods/planner-tests.php
 * Covers what an import would change, including streets that sit in two wards.
 */
define( 'ATK_NB_TESTING', true );
require __DIR__ . '/../../inc/neighbourhoods-engine.php';

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

$titles = array(
	1 => 'ALBURY LANE',
	2 => 'ALTENS ROUNDABOUT',
	3 => 'ABBEY PLACE',
	4 => 'ALBURY ROAD',
);
$terms = array(
	100 => array( 'id' => 100, 'name' => 'Torry/Ferryhill' ),
	101 => array( 'id' => 101, 'name' => 'Midstocket/Rosemount' ),
);
$base = array(
	'titles'       => $titles,
	'terms'        => $terms,
	'street_terms' => array( 3 => array( 100 ), 4 => array( 100 ) ),
	'meta'         => array( 3 => array( 'outcode' => 'AB11', 'postcodes' => array( 'AB11 9QH' ), 'note' => '' ) ),
	'target'       => 0,
	'choices'      => array(),
	'hue'          => function ( $name ) {
		return strlen( $name );
	},
);

// Import: ALBURY LANE is in two wards, one of them new to the site.
$sheet = ATK_Nb_Parser::parse_paste(
	"Road\tPost Code\tAddress\tWard\tNote\n"
	. "ALBURY LANE\tAB25\tAB25 1AB and AB25 1SY\tHazlehead/Queens Cross/Countesswells or Midstocket/Rosemount\tCheck quadrant\n"
	. "ALTENS ROUNDABOUT\tAB12\tAB12 3LF and AB12 3JG\tKincorth/Nigg/Cove\t\n"
	. "ABBEY PLACE\tAB11\tAB11 9QH\tTorry/Ferryhill\t\n"
	. "ALTENS ROUNDABUOT\tAB12\t\tKincorth/Nigg/Cove\t\n"
);
$plan = ATK_Nb_Planner::plan( $sheet['rows'], $base );
$rows = $plan['rows'];

check( 'two-ward street is ready', $rows[0]['status'], 'ready' );
check( 'two-ward street keeps both wards', $rows[0]['wards'], array( 'Hazlehead/Queens Cross/Countesswells', 'Midstocket/Rosemount' ) );
check( 'two-ward street flagged as shared', $rows[0]['shared'], true );
check( 'existing ward reuses stored name', $rows[0]['ward_chips'][1]['is_new'], false );
check( 'unknown ward marked new', $rows[0]['ward_chips'][0]['is_new'], true );
check( 'postcodes will be saved', $rows[0]['updates']['postcodes'], array( 'AB25 1AB', 'AB25 1SY' ) );
check( 'note will be saved', $rows[0]['updates']['note'], 'Check quadrant' );
check( 'single-ward street is not shared', $rows[1]['shared'], false );
check( 'no changes when sheet matches site', $rows[2]['status'], 'unchanged' );
check( 'typo row needs attention', $rows[3]['status'], 'unmatched' );
check( 'typo row offers the right suggestion', $rows[3]['suggestion']['id'], 2 );
check( 'summary: ready', $plan['summary']['ready'], 2 );
check( 'summary: unchanged', $plan['summary']['unchanged'], 1 );
check( 'summary: attention', $plan['summary']['attention'], 1 );
check( 'summary: shared', $plan['summary']['shared'], 1 );
check(
	'summary: new neighbourhoods listed once',
	$plan['summary']['new_neighbourhoods'],
	array( 'Hazlehead/Queens Cross/Countesswells', 'Kincorth/Nigg/Cove' )
);

// The instructor accepts the suggestion for row 5.
$accepted = ATK_Nb_Planner::plan( $sheet['rows'], array_merge( $base, array( 'choices' => array( 5 => 2 ) ) ) );
check( 'accepted suggestion becomes a match', $accepted['rows'][3]['status'], 'ready' );
check( 'accepted suggestion targets the chosen street', $accepted['rows'][3]['street_id'], 2 );
check( 'nothing needs attention after accepting', $accepted['summary']['attention'], 0 );
$bogus = ATK_Nb_Planner::plan( $sheet['rows'], array_merge( $base, array( 'choices' => array( 5 => 999 ) ) ) );
check( 'a choice for a street that does not exist is ignored', $bogus['rows'][3]['status'], 'unmatched' );

// Sheet replaces: ALBURY ROAD moves from Torry/Ferryhill to Midstocket/Rosemount.
$move = ATK_Nb_Planner::plan( ATK_Nb_Parser::parse_paste( "Road\tWard\nALBURY ROAD\tMidstocket/Rosemount\n" )['rows'], $base );
check( 'import replaces wards', $move['rows'][0]['wards'], array( 'Midstocket/Rosemount' ) );
check( 'old ward shown as removed', $move['rows'][0]['removed'], array( 'Torry/Ferryhill' ) );
check( 'new ward shown as added', $move['rows'][0]['added'], array( 'Midstocket/Rosemount' ) );

// Pasting names into a neighbourhood adds, never removes.
$add = ATK_Nb_Planner::plan( ATK_Nb_Parser::parse_paste( "ALBURY ROAD\nALBURY LANE\n" )['rows'], array_merge( $base, array( 'target' => 101 ) ) );
check( 'names mode keeps existing ward', $add['rows'][0]['wards'], array( 'Torry/Ferryhill', 'Midstocket/Rosemount' ) );
check( 'names mode removes nothing', $add['rows'][0]['removed'], array() );
check( 'names mode makes a street shared', $add['rows'][0]['shared'], true );
check( 'names mode adds a street with no wards yet', $add['rows'][1]['wards'], array( 'Midstocket/Rosemount' ) );

// Names with no neighbourhood chosen cannot be placed.
$orphan = ATK_Nb_Planner::plan( ATK_Nb_Parser::parse_paste( "ALBURY LANE\n" )['rows'], $base );
check( 'names without a target need attention', $orphan['rows'][0]['status'], 'no_ward' );

// An empty cell never wipes saved data.
$keep = ATK_Nb_Planner::plan( ATK_Nb_Parser::parse_paste( "Road\tPost Code\tAddress\tWard\tNote\nABBEY PLACE\t\t\tTorry/Ferryhill\t\n" )['rows'], $base );
check( 'blank postcode cells leave saved postcodes alone', $keep['rows'][0]['updates'], array() );
check( 'and the row reads as unchanged', $keep['rows'][0]['status'], 'unchanged' );

// Case in ward names does not create a second neighbourhood.
$case = ATK_Nb_Planner::plan( ATK_Nb_Parser::parse_paste( "Road\tWard\nALBURY LANE\ttorry/ferryhill\n" )['rows'], $base );
check( 'ward case matches the existing neighbourhood', $case['rows'][0]['wards'], array( 'Torry/Ferryhill' ) );
check( 'so no new neighbourhood is created', $case['summary']['new_neighbourhoods'], array() );

// Same street listed twice with different wards: later row sees the same starting state, both flagged.
check( 'warnings count rows with parse issues on matched streets', ATK_Nb_Planner::plan(
	ATK_Nb_Parser::parse_rows( array( array( 'Road', 'Post Code', 'Address', 'Ward' ), array( 'ALBURY LANE', 'AB10', 'AB12 3LF', 'Torry/Ferryhill' ) ) ),
	$base
)['summary']['warnings'], 1 );

// The same street twice in one file is flagged on the later row.
$twice = ATK_Nb_Planner::plan(
	ATK_Nb_Parser::parse_rows( array(
		array( 'Road', 'Ward' ),
		array( 'ALTENS ROUNDABOUT', 'Kincorth/Nigg/Cove' ),
		array( 'ALTENS ROUNDABOUT', 'Torry/Ferryhill' ),
	) ),
	$base
);
check( 'first occurrence has no warning', $twice['rows'][0]['issues'], array() );
check( 'later occurrence is flagged', $twice['rows'][1]['issues'], array( 'This street is also on row 2. The later row is saved last, so it wins.' ) );
check( 'duplicate counted as one warning', $twice['summary']['warnings'], 1 );

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
