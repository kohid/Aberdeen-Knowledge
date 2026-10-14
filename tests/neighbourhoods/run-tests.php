<?php
/**
 * Run with: php tests/neighbourhoods/run-tests.php
 * Covers the parser and matcher, which hold all the import rules.
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

// The two sample rows from the client sheet, tab-separated as copied from Google Sheets.
$sheet = "Road\tPost Code\tAddress\tWard\tNote\n"
	. "ALBURY LANE\tAB25\tAB25 1AB and AB25 1SY\tHazlehead/Queens Cross/Countesswells or Midstocket/Rosemount\t(Note: \"Albury Lane\" in Western Australia or Albury Road in Ferryhill, Aberdeen are separate locations)\n"
	. "ALTENS ROUNDABOUT\tAB12\tAB12 3LF and AB12 3JG\tKincorth/Nigg/Cove\t(depending on the exact quadrant of the junction)\n";
$parsed = ATK_Nb_Parser::parse_paste( $sheet );
check( 'tab paste is a table', $parsed['mode'], 'table' );
check( 'two rows parsed', count( $parsed['rows'] ), 2 );
$albury = $parsed['rows'][0];
check( 'road', $albury['road'], 'ALBURY LANE' );
check( 'outcode', $albury['outcode'], 'AB25' );
check( 'two postcodes split on "and"', $albury['postcodes'], array( 'AB25 1AB', 'AB25 1SY' ) );
check( 'two wards split on "or"', $albury['wards'], array( 'Hazlehead/Queens Cross/Countesswells', 'Midstocket/Rosemount' ) );
check( 'slash inside a ward is kept', $parsed['rows'][1]['wards'], array( 'Kincorth/Nigg/Cove' ) );
check( 'note kept whole, commas and quotes included', strpos( $albury['note'], 'Western Australia or Albury Road in Ferryhill, Aberdeen' ) !== false, true );
check( 'no issues on clean rows', $albury['issues'], array() );
check( 'line number counts the header', $albury['line'], 2 );

// Comma header typed or pasted as csv.
$csv = ATK_Nb_Parser::parse_paste( "Road, Post Code, Address, Ward, Note\nABBEY PLACE, AB11, AB11 9QH, Torry/Ferryhill, \n" );
check( 'csv header detected', $csv['mode'], 'table' );
check( 'csv ward', $csv['rows'][0]['wards'], array( 'Torry/Ferryhill' ) );

// Older workbook: two columns both called Address.
$old = ATK_Nb_Parser::parse_rows( array(
	array( 'Road ', 'Address', 'Address', 'Ward' ),
	array( 'ABBEY ROAD', 'AB11', 'AB11 9PE', 'Torry/Ferryhill' ),
) );
check( 'two Address columns: outcode', $old[0]['outcode'], 'AB11' );
check( 'two Address columns: postcode', $old[0]['postcodes'], array( 'AB11 9PE' ) );

// Plain names.
$names = ATK_Nb_Parser::parse_paste( "ALBURY PLACE\n  ALBURY ROAD,\n\n- ALBYN GROVE\n" );
check( 'plain lines are names', $names['mode'], 'names' );
check( 'names cleaned', array_column( $names['rows'], 'road' ), array( 'ALBURY PLACE', 'ALBURY ROAD', 'ALBYN GROVE' ) );

// Problems are flagged, not hidden.
$bad = ATK_Nb_Parser::parse_rows( array(
	array( 'Road', 'Post Code', 'Address', 'Ward', 'Note' ),
	array( 'TEST STREET', 'AB10', 'AB12 3LF', 'Torry/Ferryhill', '' ),
	array( 'OTHER STREET', 'AB10', 'see client', 'Torry/Ferryhill', '' ),
	array( '', 'AB10', 'AB10 1AA', 'Torry/Ferryhill', '' ),
) );
check( 'postcode outside outcode flagged', $bad[0]['issues'], array( 'AB12 3LF is outside AB10.' ) );
check( 'unreadable postcode flagged', $bad[1]['issues'], array( 'No full postcode found in "see client".' ) );
check( 'missing road flagged', $bad[2]['issues'], array( 'This row has no road name.' ) );

// Postcode formats.
check( 'postcode without space is spaced', ATK_Nb_Parser::extract_postcodes( 'ab251ab,AB25 1SY' ), array( 'AB25 1AB', 'AB25 1SY' ) );
check( 'duplicates removed', ATK_Nb_Parser::extract_postcodes( 'AB25 1AB and AB25 1AB' ), array( 'AB25 1AB' ) );
check( 'ward list de-duplicated', ATK_Nb_Parser::split_wards( 'Torry/Ferryhill or torry/ferryhill' ), array( 'Torry/Ferryhill' ) );

// Matching.
$matcher = new ATK_Nb_Matcher( array(
	10 => 'ALBURY LANE',
	11 => "ADVOCATES' ROAD",
	12 => 'ASH-HILL DRIVE',
	13 => 'ALTENS ROUNDABOUT',
	14 => 'DUPLICATE ROAD',
	15 => 'Duplicate Road',
) );
check( 'exact match ignores case and spacing', $matcher->match( ' albury  lane ' )['id'], 10 );
check( 'apostrophes ignored', $matcher->match( 'ADVOCATES ROAD' )['id'], 11 );
check( 'hyphen equals space', $matcher->match( 'ASH HILL DRIVE' )['id'], 12 );
check( 'duplicate titles are ambiguous', $matcher->match( 'DUPLICATE ROAD' )['status'], 'ambiguous' );
$near = $matcher->match( 'ALTENS ROUNDABUOT' );
check( 'typo is unmatched', $near['status'], 'unmatched' );
check( 'typo gets a suggestion', $near['suggestion']['id'], 13 );
check( 'suggestion is never auto-applied', $near['id'], null );
check( 'far-off name has no suggestion', $matcher->match( 'ZZZZZ STREET' )['suggestion'], null );
check( 'empty name is invalid', $matcher->match( '  ' )['status'], 'invalid' );

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
