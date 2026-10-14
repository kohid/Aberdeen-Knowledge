<?php
/**
 * Sample question banks for the three mockup knowledge tests.
 *
 * This content is representative sample data for the mockup, modelled on
 * real Aberdeen streets and landmarks, and is NOT the verified official
 * Aberdeen Street Knowledge Test answer key.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Original static content — used only once, as the seed data for the
 * database tables the Instructor Dashboard now manages (inc/db-schema.php).
 * Live reads go through the DB-backed atk_street_test_questions() etc.
 * defined further down this file.
 *
 * "Name a connecting road between two roads."
 */
function atk_street_test_questions_seed() {
	return array(
		array( 'a' => 'Union Street', 'b' => 'Guild Street', 'accepted' => array( 'Bridge Street', 'Market Street' ) ),
		array( 'a' => 'Union Street', 'b' => 'Union Square', 'accepted' => array( 'Market Street', 'Guild Street' ) ),
		array( 'a' => 'Union Street', 'b' => 'Marischal College', 'accepted' => array( 'Broad Street' ) ),
		array( 'a' => 'Union Street', 'b' => 'Aberdeen Art Gallery', 'accepted' => array( 'Schoolhill' ) ),
		array( 'a' => 'Union Street', 'b' => "His Majesty's Theatre", 'accepted' => array( 'Union Terrace' ) ),
		array( 'a' => 'Union Street', 'b' => 'Aberdeen Central Library', 'accepted' => array( 'Rosemount Viaduct' ) ),
		array( 'a' => 'Schoolhill', 'b' => 'Holburn Street', 'accepted' => array( 'Skene Street' ) ),
		array( 'a' => 'Union Street', 'b' => 'Queens Road', 'accepted' => array( 'Albyn Place', 'Alford Place' ) ),
		array( 'a' => 'Holburn Street', 'b' => 'Alford Place', 'accepted' => array( 'Union Street' ) ),
		array( 'a' => 'Queens Road', 'b' => 'Great Western Road', 'accepted' => array( 'Anderson Drive' ) ),
		array( 'a' => 'Bridge Street', 'b' => 'Market Street', 'accepted' => array( 'Guild Street' ) ),
		array( 'a' => 'Union Street', 'b' => 'Trinity Quay', 'accepted' => array( 'Market Street' ) ),
		array( 'a' => 'Castlegate', 'b' => 'Beach Esplanade', 'accepted' => array( 'Beach Boulevard' ) ),
		array( 'a' => 'Union Street', 'b' => 'King Street', 'accepted' => array( 'Castlegate' ) ),
		array( 'a' => 'Anderson Drive', 'b' => 'North Deeside Road', 'accepted' => array( 'Garthdee Road' ) ),
		array( 'a' => 'Justice Street', 'b' => 'Beach Boulevard', 'accepted' => array( 'Park Street' ) ),
		array( 'a' => 'King Street', 'b' => 'Pittodrie Stadium', 'accepted' => array( 'Pittodrie Street' ) ),
		array( 'a' => "King's College", 'b' => 'High Street', 'accepted' => array( 'College Bounds' ) ),
		array( 'a' => 'Union Street', 'b' => 'Belmont Street', 'accepted' => array( 'Union Street', 'Belmont Street' ) ),
		array( 'a' => 'Broad Street', 'b' => 'Gallowgate', 'accepted' => array( 'John Street', 'Upperkirkgate' ) ),
	);
}

/**
 * "Name the street where you'll find these places of interest."
 */
function atk_poi_test_questions_seed() {
	return array(
		array( 'poi' => "His Majesty's Theatre", 'accepted' => array( 'Rosemount Viaduct' ) ),
		array( 'poi' => 'Aberdeen Art Gallery', 'accepted' => array( 'Schoolhill' ) ),
		array( 'poi' => 'Marischal College', 'accepted' => array( 'Broad Street' ) ),
		array( 'poi' => 'St Nicholas Kirk', 'accepted' => array( 'Union Street', 'Back Wynd' ) ),
		array( 'poi' => 'Aberdeen Central Library', 'accepted' => array( 'Rosemount Viaduct' ) ),
		array( 'poi' => 'Union Square Shopping Centre', 'accepted' => array( 'Guild Street' ) ),
		array( 'poi' => 'Aberdeen Railway Station', 'accepted' => array( 'Guild Street' ) ),
		array( 'poi' => 'Bon Accord Shopping Centre', 'accepted' => array( 'George Street' ) ),
		array( 'poi' => 'The Tolbooth Museum', 'accepted' => array( 'Castle Street', 'Castlegate' ) ),
		array( 'poi' => "Codona's Amusement Park", 'accepted' => array( 'Beach Esplanade', 'Esplanade' ) ),
		array( 'poi' => 'Duthie Park', 'accepted' => array( 'Polmuir Road', 'Riverside Drive' ) ),
		array( 'poi' => "Provost Skene's House", 'accepted' => array( 'Broad Street', 'Guestrow' ) ),
		array( 'poi' => 'Aberdeen Maritime Museum', 'accepted' => array( 'Shiprow' ) ),
		array( 'poi' => 'Beach Ballroom', 'accepted' => array( 'Beach Esplanade', 'Esplanade' ) ),
		array( 'poi' => "St Machar's Cathedral", 'accepted' => array( 'The Chanonry', 'Chanonry' ) ),
		array( 'poi' => "King's College Chapel", 'accepted' => array( 'College Bounds' ) ),
		array( 'poi' => 'Robert Gordon University (Garthdee)', 'accepted' => array( 'Garthdee Road' ) ),
		array( 'poi' => 'Aberdeen Sports Village', 'accepted' => array( 'Linksfield Road' ) ),
		array( 'poi' => 'P&J Live', 'accepted' => array( 'Exhibition Avenue' ) ),
		array( 'poi' => 'Pittodrie Stadium', 'accepted' => array( 'Pittodrie Street' ) ),
	);
}

/**
 * "List road names to build a sequence of roads which forms a route."
 * Each question shows 4 full route options; one is correct.
 */
function atk_route_test_questions_seed() {
	return array(
		array(
			'from'    => 'Castlegate',
			'to'      => 'Union Square',
			'options' => array(
				array( 'Union Street', 'Bridge Street', 'Guild Street' ),
				array( 'Union Street', 'Holburn Street', 'Great Western Road' ),
				array( 'King Street', 'Justice Street', 'Beach Boulevard' ),
				array( 'Broad Street', 'Schoolhill', 'Skene Street' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Marischal College',
			'to'      => 'Aberdeen Art Gallery',
			'options' => array(
				array( 'Broad Street', 'King Street', 'Beach Boulevard' ),
				array( 'Broad Street', 'Union Street', 'Schoolhill' ),
				array( 'Broad Street', 'Guild Street', 'Market Street' ),
				array( 'Broad Street', 'Belmont Street', 'Rosemount Viaduct' ),
			),
			'correct' => 1,
		),
		array(
			'from'    => "His Majesty's Theatre",
			'to'      => 'Holburn Street',
			'options' => array(
				array( 'Union Terrace', 'Bridge Street', 'Guild Street' ),
				array( 'Schoolhill', 'Broad Street', 'King Street' ),
				array( 'Rosemount Viaduct', 'Skene Street', 'Holburn Street' ),
				array( 'Belmont Street', 'Schoolhill', 'Market Street' ),
			),
			'correct' => 2,
		),
		array(
			'from'    => 'Aberdeen Railway Station',
			'to'      => 'Marischal College',
			'options' => array(
				array( 'Guild Street', 'Market Street', 'Holburn Street', 'Queens Road' ),
				array( 'Guild Street', 'Union Square', 'Beach Boulevard', 'King Street' ),
				array( 'Market Street', 'Schoolhill', 'Rosemount Viaduct', 'Skene Street' ),
				array( 'Guild Street', 'Bridge Street', 'Union Street', 'Broad Street' ),
			),
			'correct' => 3,
		),
		array(
			'from'    => 'Queens Road',
			'to'      => 'Union Square',
			'options' => array(
				array( 'Albyn Place', 'Union Street', 'Bridge Street' ),
				array( 'Anderson Drive', 'Great Western Road', 'Holburn Street' ),
				array( 'Great Western Road', 'Skene Street', 'Schoolhill' ),
				array( 'Albyn Place', 'Holburn Street', 'Great Southern Road' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Anderson Drive (Great Western Road junction)',
			'to'      => 'Holburn Street',
			'options' => array(
				array( 'Queens Road', 'Albyn Place', 'Alford Place' ),
				array( 'Great Western Road', 'Union Street', 'Holburn Street' ),
				array( 'Garthdee Road', 'North Deeside Road', 'Union Street' ),
				array( 'Anderson Drive', 'King Street', 'Castlegate' ),
			),
			'correct' => 1,
		),
		array(
			'from'    => 'Robert Gordon University (Garthdee)',
			'to'      => 'Union Street',
			'options' => array(
				array( 'Garthdee Road', 'Anderson Drive', 'Beach Boulevard' ),
				array( 'Garthdee Road', 'Holburn Street', 'Union Street' ),
				array( 'Garthdee Road', 'King Street', 'Broad Street' ),
				array( 'Garthdee Road', 'Schoolhill', 'Rosemount Viaduct' ),
			),
			'correct' => 1,
		),
		array(
			'from'    => 'Pittodrie Stadium',
			'to'      => 'Castlegate',
			'options' => array(
				array( 'Pittodrie Street', 'King Street', 'Castlegate' ),
				array( 'Pittodrie Street', 'Beach Boulevard', 'Justice Street' ),
				array( 'Pittodrie Street', 'Union Street', 'Broad Street' ),
				array( 'Pittodrie Street', 'Great Western Road', 'Holburn Street' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Beach Esplanade',
			'to'      => 'Castlegate',
			'options' => array(
				array( 'Beach Boulevard', 'King Street', 'Union Street' ),
				array( 'Beach Boulevard', 'Justice Street', 'Castlegate' ),
				array( 'Beach Boulevard', 'Anderson Drive', 'Great Western Road' ),
				array( 'Beach Boulevard', 'Schoolhill', 'Broad Street' ),
			),
			'correct' => 1,
		),
		array(
			'from'    => "King's College (Old Aberdeen)",
			'to'      => 'Union Street',
			'options' => array(
				array( 'College Bounds', 'High Street', 'King Street', 'Union Street' ),
				array( 'College Bounds', 'The Chanonry', 'Great Western Road' ),
				array( 'High Street', 'Linksfield Road', 'Beach Boulevard' ),
				array( 'College Bounds', 'Garthdee Road', 'Holburn Street' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Union Terrace',
			'to'      => 'Belmont Street',
			'options' => array(
				array( 'Union Terrace', 'Schoolhill', 'Belmont Street' ),
				array( 'Union Terrace', 'Bridge Street', 'Guild Street' ),
				array( 'Union Terrace', 'King Street', 'Castlegate' ),
				array( 'Union Terrace', 'Holburn Street', 'Alford Place' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Bridge Street',
			'to'      => 'Aberdeen Art Gallery',
			'options' => array(
				array( 'Bridge Street', 'Union Street', 'Schoolhill' ),
				array( 'Bridge Street', 'Guild Street', 'Market Street' ),
				array( 'Bridge Street', 'Holburn Street', 'Skene Street' ),
				array( 'Bridge Street', 'Beach Boulevard', 'Park Street' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Castlegate',
			'to'      => 'Aberdeen Art Gallery',
			'options' => array(
				array( 'King Street', 'Beach Boulevard', 'Justice Street' ),
				array( 'Union Street', 'Broad Street', 'Guild Street' ),
				array( 'Union Street', 'Schoolhill' ),
				array( 'Union Street', 'Holburn Street', 'Queens Road' ),
			),
			'correct' => 2,
		),
		array(
			'from'    => 'Market Street',
			'to'      => 'Union Terrace',
			'options' => array(
				array( 'Market Street', 'Union Street', 'Union Terrace' ),
				array( 'Market Street', 'Guild Street', 'Bridge Street' ),
				array( 'Market Street', 'Schoolhill', 'Belmont Street' ),
				array( 'Market Street', 'King Street', 'Castlegate' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Holburn Street',
			'to'      => 'Marischal College',
			'options' => array(
				array( 'Holburn Street', 'Union Street', 'Broad Street' ),
				array( 'Holburn Street', 'Great Western Road', 'Anderson Drive' ),
				array( 'Holburn Street', 'Skene Street', 'Schoolhill' ),
				array( 'Holburn Street', 'Queens Road', 'Albyn Place' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Guild Street',
			'to'      => 'Aberdeen Central Library',
			'options' => array(
				array( 'Guild Street', 'Bridge Street', 'Union Street', 'Rosemount Viaduct' ),
				array( 'Guild Street', 'Market Street', 'Schoolhill' ),
				array( 'Guild Street', 'Union Square', 'Beach Boulevard' ),
				array( 'Guild Street', 'Trinity Quay', 'King Street' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Belmont Street',
			'to'      => 'Union Square',
			'options' => array(
				array( 'Belmont Street', 'Union Street', 'Bridge Street', 'Guild Street' ),
				array( 'Belmont Street', 'Schoolhill', 'Rosemount Viaduct' ),
				array( 'Belmont Street', 'Broad Street', 'King Street' ),
				array( 'Belmont Street', 'Holburn Street', 'Great Southern Road' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Great Western Road',
			'to'      => 'Castlegate',
			'options' => array(
				array( 'Great Western Road', 'Union Street', 'Castlegate' ),
				array( 'Great Western Road', 'Anderson Drive', 'Garthdee Road' ),
				array( 'Great Western Road', 'Queens Road', 'Albyn Place' ),
				array( 'Great Western Road', 'Holburn Street', 'Skene Street' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'Beach Ballroom',
			'to'      => 'Union Street',
			'options' => array(
				array( 'Beach Esplanade', 'Beach Boulevard', 'King Street', 'Union Street' ),
				array( 'Beach Esplanade', 'Beach Boulevard', 'Anderson Drive' ),
				array( 'Beach Esplanade', 'Linksfield Road', 'Pittodrie Street' ),
				array( 'Beach Esplanade', 'Justice Street', 'Great Western Road' ),
			),
			'correct' => 0,
		),
		array(
			'from'    => 'St Nicholas Kirk',
			'to'      => 'Bon Accord Shopping Centre',
			'options' => array(
				array( 'Union Street', 'St Nicholas Street', 'George Street' ),
				array( 'Union Street', 'Bridge Street', 'Guild Street' ),
				array( 'Union Street', 'Schoolhill', 'Rosemount Viaduct' ),
				array( 'Union Street', 'Holburn Street', 'Alford Place' ),
			),
			'correct' => 0,
		),
	);
}


/* -------------------------------------------------------------------- */
/* Live, database-backed versions — Instructor Dashboard writes here     */
/* -------------------------------------------------------------------- */

function atk_street_test_questions() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT road_a, road_b, accepted FROM ' . atk_table( 'quiz_street_questions' ) . ' ORDER BY sort_order ASC, id ASC' );
	return array_map( function ( $row ) {
		return array( 'a' => $row->road_a, 'b' => $row->road_b, 'accepted' => json_decode( $row->accepted, true ) );
	}, $rows );
}

function atk_poi_test_questions() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT poi, accepted FROM ' . atk_table( 'quiz_poi_questions' ) . ' ORDER BY sort_order ASC, id ASC' );
	return array_map( function ( $row ) {
		return array( 'poi' => $row->poi, 'accepted' => json_decode( $row->accepted, true ) );
	}, $rows );
}

function atk_route_test_questions() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT from_place, to_place, options, correct_index FROM ' . atk_table( 'quiz_route_questions' ) . ' ORDER BY sort_order ASC, id ASC' );
	return array_map( function ( $row ) {
		return array(
			'from'    => $row->from_place,
			'to'      => $row->to_place,
			'options' => json_decode( $row->options, true ),
			'correct' => (int) $row->correct_index,
		);
	}, $rows );
}
