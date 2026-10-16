<?php
/**
 * Run with: php tests/streets/run-tests.php
 * Covers the Streets engine: answer pairs and their matching, the sheet reader
 * (Clarify / From / To columns), and the Official Route test planner.
 */
define( 'ATK_NB_TESTING', true );
require __DIR__ . '/../../inc/neighbourhoods-engine.php';
require __DIR__ . '/../../inc/poi-places-engine.php';
require __DIR__ . '/../../inc/streets-engine.php';

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

/* ---- Pairs: cleaning --------------------------------------------------- */

check( 'clean trims and drops half-filled pairs',
	ATK_Route_Pairs::clean( array( array( ' Bridge of Don ', 'City Centre' ), array( 'Dyce', '' ), array( '', '' ), 'junk' ) ),
	array( array( 'Bridge of Don', 'City Centre' ) ) );
check( 'clean drops a repeat ignoring case and punctuation',
	ATK_Route_Pairs::clean( array( array( "St Nicholas", 'Dyce' ), array( 'ST. NICHOLAS', 'dyce' ) ) ),
	array( array( 'St Nicholas', 'Dyce' ) ) );
check( 'clean drops the same pair written the other way round',
	ATK_Route_Pairs::clean( array( array( 'Dyce', 'Torry' ), array( 'Torry', 'Dyce' ) ) ),
	array( array( 'Dyce', 'Torry' ) ) );
check( 'clean keeps different pairs in order',
	ATK_Route_Pairs::clean( array( array( 'A', 'B' ), array( 'A', 'C' ) ) ),
	array( array( 'A', 'B' ), array( 'A', 'C' ) ) );
check( 'clean stops at the limit', count( ATK_Route_Pairs::clean( array_map( function ( $i ) { return array( "From $i", "To $i" ); }, range( 1, 40 ) ) ) ), ATK_Route_Pairs::MAX_PAIRS );
check( 'clean of nothing', ATK_Route_Pairs::clean( null ), array() );

/* ---- Pairs: matching --------------------------------------------------- */

$pairs = array( array( 'Bridge of Don', 'City Centre' ), array( 'Dyce', 'Torry' ) );
check( 'exact pair is right', ATK_Route_Pairs::matches( 'Bridge of Don', 'City Centre', $pairs ), true );
check( 'either order is right', ATK_Route_Pairs::matches( 'City Centre', 'Bridge of Don', $pairs ), true );
check( 'case and punctuation are ignored', ATK_Route_Pairs::matches( 'bridge-of-don', "CITY  CENTRE.", $pairs ), true );
check( 'any listed pair counts', ATK_Route_Pairs::matches( 'Torry', 'Dyce', $pairs ), true );
check( 'one right and one wrong is wrong', ATK_Route_Pairs::matches( 'Dyce', 'City Centre', $pairs ), false );
check( 'an empty box is wrong', ATK_Route_Pairs::matches( 'Dyce', '', $pairs ), false );
check( 'both empty is wrong even with no pairs', ATK_Route_Pairs::matches( '', '', array() ), false );
check( 'no pairs means nothing is right', ATK_Route_Pairs::matches( 'Dyce', 'Torry', array() ), false );
check( 'same place twice is not a pair of different places', ATK_Route_Pairs::matches( 'Dyce', 'Dyce', $pairs ), false );

$streets_pairs = array( array( 'Union Street', 'Skene Road' ) );
check( 'St is the same as Street', ATK_Route_Pairs::matches( 'union st', 'SKENE ROAD', $streets_pairs ), true );
check( 'Rd is the same as Road', ATK_Route_Pairs::matches( 'Union Street', 'Skene Rd.', $streets_pairs ), true );
check( 'St and Street count as one pair when cleaning', ATK_Route_Pairs::clean( array( array( 'Union Street', 'Skene Road' ), array( 'Union St', 'Skene Rd' ) ) ), array( array( 'Union Street', 'Skene Road' ) ) );
check( 'Ave and Drive fold too', ATK_Route_Pairs::matches( 'Forest Av', 'Dee Dr', array( array( 'Forest Avenue', 'Dee Drive' ) ) ), false );
check( 'Avenue folds to Ave', ATK_Route_Pairs::matches( 'Forest Ave', 'Dee Dr', array( array( 'Forest Avenue', 'Dee Drive' ) ) ), true );

check( 'pairs read as text', ATK_Route_Pairs::label( array( 'Dyce', 'Torry' ) ), 'Dyce to Torry' );

/* ---- Sheet reader ------------------------------------------------------ */

$rows = array(
	array( 'Road', 'Post Code', 'Address', 'Ward', 'Note', 'Clarify', 'From', 'To' ),
	array( 'ABBEY PLACE', 'AB11', 'AB11 9QH', 'Torry/Ferryhill', '', '', 'Wellington Road', 'Victoria Road' ),
	array( 'ABBEY PLACE', '', '', '', '', '', 'Torry', 'City Centre' ),
	array( 'CASKIEBEN ROAD', 'AB21', '', 'Dyce/Bucksburn/Danestone', 'Which end?', 'Yes', '', '' ),
	array( 'DESSWOOD LANE', '', '', '', '', 'no', '', '' ),
	array( '', '', '', '', '', '', 'x', 'y' ),
);
$parsed = ATK_Street_Parser::parse_rows( $rows );
check( 'repeated street becomes one row', count( $parsed ), 3 );
check( 'pairs from repeated rows are merged', $parsed[0]['pairs'], array( array( 'Wellington Road', 'Victoria Road' ), array( 'Torry', 'City Centre' ) ) );
check( 'ward and postcode come through', array( $parsed[0]['wards'], $parsed[0]['outcode'], $parsed[0]['postcodes'] ), array( array( 'Torry/Ferryhill' ), 'AB11', array( 'AB11 9QH' ) ) );
check( 'a filled Clarify cell sets the flag', $parsed[1]['clarify'], true );
check( 'a blank Clarify cell leaves the flag alone', $parsed[0]['clarify'], null );
check( 'Clarify "no" clears the flag', $parsed[2]['clarify'], false );
check( 'a row with no road is dropped', array_column( $parsed, 'road' ), array( 'ABBEY PLACE', 'CASKIEBEN ROAD', 'DESSWOOD LANE' ) );
check( 'note is read', $parsed[1]['note'], 'Which end?' );
check( 'a sheet without the new columns still reads', ATK_Street_Parser::parse_rows( array( array( 'Road', 'Ward' ), array( 'ALPHA ROAD', 'Bridge of Don' ) ) )[0]['pairs'], array() );
check( 'a header-less table is not a sheet', ATK_Street_Parser::parse_rows( array( array( 'ALPHA ROAD', 'x' ) ) ), array() );
check( 'a half-filled pair is reported', ATK_Street_Parser::parse_rows( array( array( 'Road', 'From', 'To' ), array( 'ALPHA ROAD', 'Dyce', '' ) ) )[0]['issues'], array( 'Only one of From and To is filled in, so that pair was ignored.' ) );

/* ---- Pasted text ------------------------------------------------------- */

$paste = ATK_Street_Parser::parse_paste( "Road\tWard\tClarify\tFrom\tTo\nALPHA ROAD\tBridge of Don\t\tUnion Street\tSkene Road\nBETA LANE\t\tx\t\t\n" );
check( 'tab-separated paste with a header', array_column( $paste, 'road' ), array( 'ALPHA ROAD', 'BETA LANE' ) );
check( 'paste pairs and flags', array( $paste[0]['pairs'], $paste[1]['clarify'] ), array( array( array( 'Union Street', 'Skene Road' ) ), true ) );
$csv = ATK_Street_Parser::parse_paste( "Road, Ward, From, To\n\"ALPHA ROAD\", Bridge of Don, \"Union Street\", \"Skene Road\"\n" );
check( 'csv paste with quotes', array( $csv[0]['road'], $csv[0]['pairs'] ), array( 'ALPHA ROAD', array( array( 'Union Street', 'Skene Road' ) ) ) );
$names = ATK_Street_Parser::parse_paste( "Alpha Road\nBeta Lane\n\nGamma Street\n" );
check( 'a plain list of names has no header and no answers', array( array_column( $names, 'road' ), $names[0]['pairs'] ), array( array( 'Alpha Road', 'Beta Lane', 'Gamma Street' ), array() ) );
check( 'empty paste', ATK_Street_Parser::parse_paste( "  \n" ), array() );

/* ---- Status ------------------------------------------------------------ */

check( 'status: clarify wins', ATK_Route_Planner::status( array( 'flagged' => true, 'pairs' => array( array( 'a', 'b' ) ) ) ), 'clarify' );
check( 'status: needs answer', ATK_Route_Planner::status( array( 'flagged' => false, 'pairs' => array() ) ), 'needs_answer' );
check( 'status: ready', ATK_Route_Planner::status( array( 'flagged' => false, 'pairs' => array( array( 'a', 'b' ) ) ) ), 'ready' );

/* ---- Splitting into tests ---------------------------------------------- */

$names = array_map( function ( $i ) { return array( 'street' => "S$i" ); }, range( 1, 12 ) );
$tests = ATK_Route_Planner::split_tests( $names );
check( '12 streets: sizes 3,3,2,2,2', array_map( 'count', $tests ), array( 3, 3, 2, 2, 2 ) );
check( '12 streets: all asked once', count( array_unique( array_merge( ...array_map( function ( $t ) { return array_column( $t, 'street' ); }, $tests ) ) ) ), 12 );
check( '3 streets: three single tests and two empty', array_map( 'count', ATK_Route_Planner::split_tests( array_slice( $names, 0, 3 ) ) ), array( 1, 1, 1, 0, 0 ) );
check( 'no streets: five empty tests', array_map( 'count', ATK_Route_Planner::split_tests( array() ) ), array( 0, 0, 0, 0, 0 ) );
$big = array_map( function ( $i ) { return array( 'street' => "S$i" ); }, range( 1, 203 ) );
check( '203 streets: no cap, sizes 41,41,41,40,40', array_map( 'count', ATK_Route_Planner::split_tests( $big ) ), array( 41, 41, 41, 40, 40 ) );

check( 'time is 20 minutes for a short test', ATK_Route_Planner::minutes( 12 ), 20 );
check( 'time grows with a long test', ATK_Route_Planner::minutes( 41 ), 41 );

/* ---- Course plan ------------------------------------------------------- */

$official = array( 'Dyce/Bucksburn/Danestone', 'Bridge of Don', 'George Street/Harbour' );
$p        = array( array( 'Dyce', 'Torry' ) );
$streets  = array(
	array( 'id' => 1, 'name' => 'Zeta Road', 'wards' => array( 'Bridge of Don' ), 'pairs' => $p, 'flagged' => false ),
	array( 'id' => 2, 'name' => 'alpha Road', 'wards' => array( 'Bridge of Don' ), 'pairs' => $p, 'flagged' => false ),
	array( 'id' => 3, 'name' => 'Both Lane', 'wards' => array( 'Dyce/Bucksburn/Danestone', 'Bridge of Don' ), 'pairs' => $p, 'flagged' => false ),
	array( 'id' => 4, 'name' => 'Red Road', 'wards' => array( 'Bridge of Don' ), 'pairs' => $p, 'flagged' => true ),
	array( 'id' => 5, 'name' => 'Blank Road', 'wards' => array( 'Dyce/Bucksburn/Danestone' ), 'pairs' => array(), 'flagged' => false ),
	array( 'id' => 6, 'name' => 'Lost Road', 'wards' => array(), 'pairs' => $p, 'flagged' => false ),
	array( 'id' => 7, 'name' => 'Odd Road', 'wards' => array( 'Somewhere Else' ), 'pairs' => $p, 'flagged' => false ),
	array( 'id' => 8, 'name' => 'Harbour Road', 'wards' => array( 'George St/Harbour' ), 'pairs' => $p, 'flagged' => false ),
);
$plan = ATK_Route_Planner::course_plan( $streets, $official );
check( 'one week per official ward, in order', array_column( $plan['weeks'], 'ward' ), $official );
check( 'week numbers follow the official order', array_column( $plan['weeks'], 'number' ), array( 1, 2, 3 ) );
check( 'a ready street is asked in each of its wards', array( array_column( $plan['weeks'][0]['questions'], 'street' ), array_column( $plan['weeks'][1]['questions'], 'street' ) ), array( array( 'Both Lane' ), array( 'alpha Road', 'Both Lane', 'Zeta Road' ) ) );
check( 'questions carry the accepted pairs', $plan['weeks'][0]['questions'][0]['pairs'], $p );
check( 'flagged street is listed, not asked', $plan['weeks'][1]['flagged'], array( 'Red Road' ) );
check( 'street without a pair is listed, not asked', $plan['weeks'][0]['no_answer'], array( 'Blank Road' ) );
check( 'street with no ward is reported', $plan['no_ward'], array( 'Lost Road' ) );
check( 'streets in a ward that is not an official one are reported with that ward', $plan['outside'], array( array( 'name' => 'Odd Road', 'ward' => 'Somewhere Else' ), array( 'name' => 'Harbour Road', 'ward' => 'George St/Harbour' ) ) );
check( 'ward name spelling George St is not guessed', count( $plan['weeks'][2]['questions'] ), 0 );
check( 'week 2 splits its 3 streets over the 5 tests', array_map( 'count', $plan['weeks'][1]['tests'] ), array( 1, 1, 1, 0, 0 ) );
check( 'week 2 test 1 starts with the first street A to Z', $plan['weeks'][1]['tests'][0][0]['street'], 'alpha Road' );
check( 'week minutes', $plan['weeks'][1]['minutes'], 20 );

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
