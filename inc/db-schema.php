<?php
/**
 * Database-backed storage for course + mockup question content, replacing
 * the static PHP arrays that used to live in course-data.php / quiz-data.php.
 * The Instructor Dashboard (inc/instructor.php) is the only thing that
 * writes to these tables; everything that reads course/question content
 * (inc/course.php, inc/quiz-data.php) queries them through the small
 * accessor functions below, keeping the same function names/shapes the
 * rest of the theme already relies on.
 *
 * A one-time migration seeds these tables from the original static data
 * the first time this runs, so nothing already built this session is lost.
 */

defined( 'ABSPATH' ) || exit;

const ATK_DB_VERSION = 6;

function atk_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'atk_' . $name;
}

add_action( 'init', 'atk_maybe_install_db', 5 );
function atk_maybe_install_db() {
	if ( (int) get_option( 'atk_db_version', 0 ) >= ATK_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = array();

	$sql[] = "CREATE TABLE " . atk_table( 'course_weeks' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		course_type VARCHAR(20) NOT NULL DEFAULT 'street',
		week_number INT UNSIGNED NOT NULL,
		neighbourhoods TEXT NOT NULL,
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY course_type_sort (course_type, sort_order)
	) $charset_collate;";

	$sql[] = "CREATE TABLE " . atk_table( 'course_questions' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		week_id BIGINT UNSIGNED NOT NULL,
		test_number TINYINT UNSIGNED NOT NULL,
		road_a VARCHAR(255) NOT NULL,
		road_b VARCHAR(255) NOT NULL,
		accepted TEXT NOT NULL,
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY week_test (week_id, test_number)
	) $charset_collate;";

	$sql[] = "CREATE TABLE " . atk_table( 'course_poi_questions' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		week_id BIGINT UNSIGNED NOT NULL,
		test_number TINYINT UNSIGNED NOT NULL,
		poi VARCHAR(255) NOT NULL,
		accepted TEXT NOT NULL,
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY week_test (week_id, test_number)
	) $charset_collate;";

	$sql[] = "CREATE TABLE " . atk_table( 'course_route_questions' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		week_id BIGINT UNSIGNED NOT NULL,
		test_number TINYINT UNSIGNED NOT NULL,
		from_place VARCHAR(255) NOT NULL,
		to_place VARCHAR(255) NOT NULL,
		options TEXT NOT NULL,
		correct_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY week_test (week_id, test_number)
	) $charset_collate;";

	$sql[] = "CREATE TABLE " . atk_table( 'quiz_street_questions' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		road_a VARCHAR(255) NOT NULL,
		road_b VARCHAR(255) NOT NULL,
		accepted TEXT NOT NULL,
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id)
	) $charset_collate;";

	$sql[] = "CREATE TABLE " . atk_table( 'quiz_poi_questions' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		poi VARCHAR(255) NOT NULL,
		accepted TEXT NOT NULL,
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id)
	) $charset_collate;";

	$sql[] = "CREATE TABLE " . atk_table( 'quiz_route_questions' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		from_place VARCHAR(255) NOT NULL,
		to_place VARCHAR(255) NOT NULL,
		options TEXT NOT NULL,
		correct_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id)
	) $charset_collate;";

	$sql[] = "CREATE TABLE " . atk_table( 'road_overrides' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		original_name VARCHAR(255) NOT NULL,
		new_name VARCHAR(255) NULL,
		findable TINYINT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY original_name (original_name)
	) $charset_collate;";

	$sql[] = "CREATE TABLE " . atk_table( 'road_additions' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(255) NOT NULL,
		points LONGTEXT NOT NULL,
		findable TINYINT UNSIGNED NOT NULL DEFAULT 1,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	// Neighbourhoods tab: wards, a registry of the streets they hold (with
	// instructor-only postcodes and notes), and the many-to-many links, since
	// one street can sit in two wards. See inc/neighbourhoods.php.
	$sql[] = "CREATE TABLE " . atk_table( 'nb_neighbourhoods' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(191) NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY name (name)
	) $charset_collate;";

	$sql[] = "CREATE TABLE " . atk_table( 'nb_streets' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(191) NOT NULL,
		outcode VARCHAR(16) NOT NULL DEFAULT '',
		postcodes TEXT NULL,
		note TEXT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY name (name)
	) $charset_collate;";

	$sql[] = "CREATE TABLE " . atk_table( 'nb_links' ) . " (
		neighbourhood_id BIGINT UNSIGNED NOT NULL,
		street_id BIGINT UNSIGNED NOT NULL,
		PRIMARY KEY  (neighbourhood_id, street_id),
		KEY street_id (street_id)
	) $charset_collate;";

	// Places of Interest: the instructor-managed list that feeds the public map
	// and the POI tests. A place sits in one ward (a neighbourhood id, 0 for none).
	// See inc/poi-places.php.
	$sql[] = "CREATE TABLE " . atk_table( 'poi_places' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(255) NOT NULL,
		address TEXT NULL,
		postcode VARCHAR(12) NOT NULL DEFAULT '',
		street_answer VARCHAR(255) NOT NULL DEFAULT '',
		also_answers TEXT NULL,
		neighbourhood_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		category VARCHAR(60) NOT NULL DEFAULT 'Other Amenities',
		status VARCHAR(10) NOT NULL DEFAULT 'current',
		notes TEXT NULL,
		lat DECIMAL(9,6) NULL,
		lng DECIMAL(9,6) NULL,
		geo_state VARCHAR(12) NOT NULL DEFAULT 'none',
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY neighbourhood_id (neighbourhood_id),
		KEY name_postcode (name(100), postcode)
	) $charset_collate;";

	foreach ( $sql as $statement ) {
		dbDelta( $statement );
	}

	atk_migrate_static_content_to_db();

	update_option( 'atk_db_version', ATK_DB_VERSION, false );
}

/**
 * One-time seed: copy the original static PHP content (the functions this
 * file's counterparts used to define directly) into the new tables, but
 * only if those tables are still empty — never overwrites instructor edits.
 */
function atk_migrate_static_content_to_db() {
	global $wpdb;

	$weeks_table     = atk_table( 'course_weeks' );
	$questions_table = atk_table( 'course_questions' );
	if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $weeks_table" ) && function_exists( 'atk_course_weeks_seed' ) ) {
		foreach ( atk_course_weeks_seed() as $i => $week ) {
			$wpdb->insert( $weeks_table, array(
				'week_number'    => $i + 1,
				'neighbourhoods' => implode( ', ', $week['neighbourhoods'] ),
				'sort_order'     => $i,
			) );
			$week_id = $wpdb->insert_id;

			foreach ( $week['tests'] as $ti => $test ) {
				foreach ( $test as $qi => $q ) {
					$wpdb->insert( $questions_table, array(
						'week_id'     => $week_id,
						'test_number' => $ti + 1,
						'road_a'      => $q['a'],
						'road_b'      => $q['b'],
						'accepted'    => wp_json_encode( $q['accepted'] ),
						'sort_order'  => $qi,
					) );
				}
			}
		}
	}

	$street_table = atk_table( 'quiz_street_questions' );
	if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $street_table" ) && function_exists( 'atk_street_test_questions_seed' ) ) {
		foreach ( atk_street_test_questions_seed() as $i => $q ) {
			$wpdb->insert( $street_table, array(
				'road_a'     => $q['a'],
				'road_b'     => $q['b'],
				'accepted'   => wp_json_encode( $q['accepted'] ),
				'sort_order' => $i,
			) );
		}
	}

	$poi_table = atk_table( 'quiz_poi_questions' );
	if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $poi_table" ) && function_exists( 'atk_poi_test_questions_seed' ) ) {
		foreach ( atk_poi_test_questions_seed() as $i => $q ) {
			$wpdb->insert( $poi_table, array(
				'poi'        => $q['poi'],
				'accepted'   => wp_json_encode( $q['accepted'] ),
				'sort_order' => $i,
			) );
		}
	}

	$route_table = atk_table( 'quiz_route_questions' );
	if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $route_table" ) && function_exists( 'atk_route_test_questions_seed' ) ) {
		foreach ( atk_route_test_questions_seed() as $i => $q ) {
			$wpdb->insert( $route_table, array(
				'from_place'    => $q['from'],
				'to_place'      => $q['to'],
				'options'       => wp_json_encode( $q['options'] ),
				'correct_index' => (int) $q['correct'],
				'sort_order'    => $i,
			) );
		}
	}
}
