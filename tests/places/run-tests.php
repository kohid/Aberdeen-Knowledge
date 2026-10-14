<?php
/**
 * Run with: php tests/places/run-tests.php
 * Covers the Places of Interest sheet parser, street matcher and area box.
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

// Header mapping.
check( 'header map finds the sheet columns', ATK_Poi_Parser::map_header( array( 'Place of interest', 'Street', 'Postcode', 'Ward' ) ), array( 'name' => 0, 'address' => 1, 'postcode' => 2, 'ward' => 3 ) );
$mapped = ATK_Poi_Parser::map_header( array( 'place of interest', 'ADDRESS', 'Post code', 'Ward', 'Category' ) );
check( 'Address header reads as address', $mapped['address'], 1 );
check( 'Post code header reads as postcode', $mapped['postcode'], 2 );
check( 'Category column is found', $mapped['category'], 4 );

// Row parsing.
$table  = array(
	array( '' ),
	array(),
	array( 'Place of interest', 'Street', 'Postcode', 'Ward' ),
	array( '22 CLUB', '55 Rose street, AB10 1UB', 'AB10 1UB', 'Midstocket/Rosemount' ),
	array( '', 'Nowhere', '', '' ),
);
$parsed = ATK_Poi_Parser::parse_rows( $table );
check( 'header on the third row is found', $parsed['error'], null );
check( 'one row survives (empty name dropped)', count( $parsed['rows'] ), 1 );
check( 'row name', $parsed['rows'][0]['name'], '22 CLUB' );
check( 'row keeps the sheet line number', $parsed['rows'][0]['line'], 4 );
check( 'row postcode', $parsed['rows'][0]['postcode'], 'AB10 1UB' );
check( 'row ward', $parsed['rows'][0]['ward'], 'Midstocket/Rosemount' );
$none = ATK_Poi_Parser::parse_rows( array( array( 'Foo', 'Bar' ) ) );
check( 'no name column is a plain error', $none['error'], 'Could not find a "Place of interest" column.' );
check( 'no name column gives no rows', $none['rows'], array() );
$bom = ATK_Poi_Parser::parse_rows( array( array( "\xEF\xBB\xBFPlace of interest", 'Street' ), array( 'Babylon', '9 Alford Place, Aberdeen' ) ) );
check( 'a BOM on the first header cell is ignored', $bom['rows'][0]['name'], 'Babylon' );
$fallback = ATK_Poi_Parser::parse_rows( array( array( 'Place of interest', 'Street', 'Postcode' ), array( 'Babylon', '9 Alford Place, AB10 1YD', '' ) ) );
check( 'empty Postcode cell falls back to the address', $fallback['rows'][0]['postcode'], 'AB10 1YD' );

// Postcodes.
check( 'tidy_postcode upper-cases and spaces', ATK_Poi_Parser::tidy_postcode( 'ab25  1gl' ), 'AB25 1GL' );
check( 'tidy_postcode inserts the space', ATK_Poi_Parser::tidy_postcode( 'AB101UB' ), 'AB10 1UB' );
check( 'tidy_postcode of nothing is empty', ATK_Poi_Parser::tidy_postcode( 'Aberdeen' ), '' );

// Ward keys.
check( 'ward key ignores spaces around slashes', ATK_Poi_Parser::ward_key( 'Midstocket / Rosemount' ), ATK_Poi_Parser::ward_key( 'Midstocket/Rosemount' ) );
check( 'ward key keeps a three-part ward whole', ATK_Poi_Parser::ward_key( 'Airyhall/Broomhill/Garthdee' ), 'airyhall/broomhill/garthdee' );

// Street candidates, using the real sheet's addresses.
check( 'candidates: place then road then postcode', ATK_Poi_Parser::street_candidates( 'Old stoneywood church, Bankhead road, AB21 9HQ' ), array( 'Bankhead road', 'Old stoneywood church' ) );
check( 'candidates: trailing full stop before postcode', ATK_Poi_Parser::street_candidates( 'Schoolhill. AB10 1FQ' ), array( 'Schoolhill' ) );
check( 'candidates: filler parts dropped', ATK_Poi_Parser::street_candidates( '52-60 Langstane Place, Aberdeen, Scotland, United Kingdom' ), array( 'Langstane Place' ) );
check( 'candidates: house-number range dropped', ATK_Poi_Parser::street_candidates( '43-45 Market street, AB11 5EL' ), array( 'Market street' ) );
check( 'candidates: bare street name', ATK_Poi_Parser::street_candidates( '164 Spital, AB24 3JD' ), array( 'Spital' ) );
check( 'candidates: apostrophes kept', ATK_Poi_Parser::street_candidates( "His Majesty's Theatre, Rosemount viaduct, AB25 1GL" ), array( 'Rosemount viaduct', "His Majesty's Theatre" ) );
check( 'candidates: interior full stops stay', ATK_Poi_Parser::street_candidates( 'St. Machar Drive, AB24 3UU' ), array( 'St. Machar Drive' ) );

// Street matcher.
$roads   = array( 'ROSEMOUNT VIADUCT', 'BANKHEAD ROAD', 'KING STREET', 'ST MACHAR DRIVE', 'SPITAL', 'SCHOOLHILL', 'BACK WYND' );
$matcher = new ATK_Poi_Street_Matcher( $roads );
$hit     = $matcher->match( "His Majesty's Theatre, Rosemount viaduct, AB25 1GL" );
check( 'matches the road in the list spelling', $hit['road'], 'ROSEMOUNT VIADUCT' );
check( 'matched status', $hit['status'], 'matched' );
check( 'matches the right-most road', $matcher->match( 'Old stoneywood church, Bankhead road, AB21 9HQ' )['road'], 'BANKHEAD ROAD' );
check( 'expands a final St to Street', $matcher->match( '33 King st, AB24 5AA' )['road'], 'KING STREET' );
check( 'leaves a leading St alone', $matcher->match( 'St Machar Drive, AB24 3UU' )['road'], 'ST MACHAR DRIVE' );
check( 'a bare area name is unmatched', $matcher->match( 'Foresterhill, AB25 2ZW' )['status'], 'unmatched' );
check( 'unmatched has no road', $matcher->match( 'Foresterhill, AB25 2ZW' )['road'], null );

// Area box.
check( 'in_box accepts Aberdeen', ATK_Poi_Geo::in_box( 57.1497, -2.0943 ), true );
check( 'in_box rejects London', ATK_Poi_Geo::in_box( 51.5, -0.12 ), false );
check( 'in_box rejects the box edge outside', ATK_Poi_Geo::in_box( 57.31, -2.0 ), false );

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
