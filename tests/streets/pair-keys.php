<?php
/** Prints the PHP side of the pair matching rules as JSON, for tests/streets/pair-tests.mjs to compare against the browser version. */
define( 'ATK_NB_TESTING', true );
require __DIR__ . '/../../inc/neighbourhoods-engine.php';
require __DIR__ . '/../../inc/poi-places-engine.php';
require __DIR__ . '/../../inc/streets-engine.php';

$texts = array(
	'Union Street', 'union st', 'UNION  STREET.', "King Robert's Way", 'King Roberts Way', "Queen\u{2019}s Road", 'Queens Rd',
	'Great Western Road', 'Great Western Rd', 'Bon Accord Street', 'Bon-Accord St', 'Mill of Birsack Road', 'Rosemount Place',
	'Springbank Terrace', 'Dee Street (south)', 'Albury Rd', 'Albury Road', '  spaced   out   avenue  ', 'Boulevard Drive', 'Ave', '',
	"Caf\u{00e9} Lane", 'North Deeside Road', 'N. Deeside Rd', 'A96', 'St Swithin Street', 'Street Road',
	'Dead end', 'dead-end', 'DEADEND', 'Cul-de-sac', 'cul de sac', 'CULDESAC',
);
$pairs = array(
	array( array( 'Wellington Road', 'Victoria Road' ), array( 'Victoria Road', 'Market Street' ) ),
	array( array( 'Union St', 'King Street' ) ),
	array(),
);
$replies = array(
	array( 'Wellington Rd', 'Victoria Rd', 0 ),
	array( 'victoria road', 'wellington road', 0 ),
	array( 'Market St', 'Victoria Road', 0 ),
	array( 'Market St', 'Wellington Road', 0 ),
	array( 'Wellington Road', 'Wellington Rd', 0 ),
	array( '', 'Victoria Road', 0 ),
	array( 'Wellington Road', '', 0 ),
	array( 'Union Street', 'King St', 1 ),
	array( 'King St', 'Union Street', 1 ),
	array( 'Union Street', 'King St', 2 ),
);
$sets = array(
	array( array( 'Alford Place', 'Castle Street' ), array( 'Holburn Street', 'Castle Street' ) ),
	array( array( 'A', 'C' ), array( 'B', 'D' ) ),
	array( array( 'Union St', 'Castle Street' ), array( 'Union Street', 'Castle St' ) ),
	array( array( 'Hall Road', 'Dead end' ) ),
	array(),
);
$graded = array(
	array( 0, array( 'Alford Place', 'Holburn St' ), array( 'Castle St' ) ),
	array( 0, array( 'Holburn Street', '' ), array( 'Castle Street' ) ),
	array( 0, array( 'Castle Street' ), array( 'Alford Place', 'Holburn Street' ) ),
	array( 0, array( 'Alford Place', 'alford place' ), array( 'Castle Street' ) ),
	array( 0, array( '' ), array( '  ' ) ),
	array( 1, array( 'a' ), array( 'd', 'c' ) ),
	array( 2, array( 'Union Street' ), array( 'Castle Rd' ) ),
	array( 3, array( 'hall rd' ), array( 'Cul-de-sac' ) ),
	array( 3, array( 'Dead End' ), array( 'Hall Road' ) ),
	array( 4, array( 'x' ), array( 'y' ) ),
);
$out = array( 'keys' => array(), 'matches' => array(), 'ends' => array(), 'grades' => array(), 'sheets' => array() );
foreach ( $sets as $set ) {
	$out['ends'][]   = array( $set, ATK_Route_Pairs::ends( $set ) );
	$out['sheets'][] = array( $set, ATK_Route_Pairs::answer_sheet( $set ) );
}
foreach ( $graded as $g ) {
	$out['grades'][] = array( $sets[ $g[0] ], $g[1], $g[2], ATK_Route_Pairs::grade( $sets[ $g[0] ], $g[1], $g[2] ) );
}
foreach ( $texts as $text ) {
	$out['keys'][] = array( $text, ATK_Route_Pairs::key( $text ) );
}
foreach ( $replies as $r ) {
	$out['matches'][] = array( $r[0], $r[1], $pairs[ $r[2] ], ATK_Route_Pairs::matches( $r[0], $r[1], $pairs[ $r[2] ] ) );
}
echo json_encode( $out );
