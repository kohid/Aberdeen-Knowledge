<?php
/**
 * Instructor role: a front-end-only dashboard (no wp-admin access, same
 * pattern as the Driver role) for reviewing drivers' test results and
 * managing course + mockup question content — the database tables this
 * writes to are defined in inc/db-schema.php.
 */

defined( 'ABSPATH' ) || exit;

const ATK_INSTRUCTOR_NONCE_ACTION = 'atk_instructor_action';

/**
 * True for the 'instructor' role, and also for administrators — admins
 * manage the whole site already, so they get the dashboard too rather
 * than needing a separate role assigned to see it.
 */
function atk_is_instructor( $user = null ) {
	$user  = $user ? $user : wp_get_current_user();
	$roles = (array) $user->roles;
	return in_array( 'instructor', $roles, true ) || in_array( 'administrator', $roles, true );
}

/**
 * Gate for the dashboard page itself: logged in + instructor role, or sent
 * home. Mirrors atk_require_login()'s redirect-back-to-here behaviour.
 */
function atk_require_instructor() {
	atk_require_login();
	if ( ! atk_is_instructor() ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}

/**
 * Gate for the admin-ajax.php handlers below: dies rather than redirecting,
 * since these are form-submission targets, not pages a person browses to.
 */
function atk_instructor_verify_request() {
	check_admin_referer( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' );
	if ( ! is_user_logged_in() || ! atk_is_instructor() ) {
		wp_die( 'Not permitted.', 'Forbidden', array( 'response' => 403 ) );
	}
}

function atk_instructor_redirect_back( $args = array() ) {
	wp_safe_redirect( add_query_arg( $args, atk_get_page_url( 'instructor-dashboard' ) ) );
	exit;
}

/**
 * A "tag select" field's submitted value into a clean list of strings,
 * dropping empties — used for "accepted answers"/"neighbourhoods"
 * everywhere they're user-entered. Select2 (multi-select, name="x[]")
 * submits an array; accepts a comma-separated string too, just in case.
 */
function atk_instructor_parse_list( $raw ) {
	$parts = is_array( $raw ) ? array_map( 'sanitize_text_field', $raw ) : explode( ',', (string) $raw );
	$parts = array_map( 'trim', $parts );
	return array_values( array_filter( $parts, function ( $p ) { return '' !== $p; } ) );
}

/**
 * A route option entered as "Road A -> Road B -> Road C" into a list of
 * road names, dropping empties.
 */
function atk_instructor_parse_route_option( $raw ) {
	$parts = array_map( 'trim', preg_split( '/->|\x{2192}/u', (string) $raw ) );
	return array_values( array_filter( $parts, function ( $p ) { return '' !== $p; } ) );
}

/**
 * Every neighbourhood name already used across any Course week (any
 * track), the neighbourhoods built on the Neighbourhoods tab, plus the full
 * master list of Aberdeen's real 30 neighbourhoods
 * (inc/road-names-data.php) — so the "Neighbourhoods" field's suggestions
 * cover every real area even for a track that has no weeks yet, not just
 * whatever's already been typed in.
 */
function atk_instructor_known_neighbourhoods() {
	global $wpdb;
	$rows = $wpdb->get_col( 'SELECT neighbourhoods FROM ' . atk_table( 'course_weeks' ) );

	$seen = array();
	foreach ( atk_master_neighbourhoods() as $name ) {
		$seen[ $name ] = true;
	}
	// Neighbourhoods built on the Neighbourhoods tab (inc/neighbourhoods.php).
	foreach ( ATK_Nb_Store::neighbourhood_names() as $name ) {
		$seen[ $name ] = true;
	}
	foreach ( $rows as $row ) {
		foreach ( explode( ',', $row ) as $name ) {
			$name = trim( $name );
			if ( '' !== $name ) {
				$seen[ $name ] = true;
			}
		}
	}
	$list = array_keys( $seen );
	sort( $list, SORT_STRING | SORT_FLAG_CASE );
	return $list;
}

/**
 * Every road name already used anywhere as an endpoint or accepted answer
 * across the Street/POI Course and mockup question banks, plus the full
 * master list of ~3,400 real Aberdeen street names from OpenStreetMap
 * (inc/road-names-data.php) — so "Accepted Answers" suggestions cover
 * real Aberdeen streets generally, not just ones already used somewhere.
 * Both tracks share one pool since they draw from the same real streets.
 */
function atk_instructor_known_road_names() {
	global $wpdb;
	$seen = array();
	foreach ( atk_master_road_names() as $name ) {
		$seen[ $name ] = true;
	}
	// Streets added from the Neighbourhoods tab (inc/neighbourhoods.php).
	foreach ( ATK_Nb_Store::registry_names() as $name ) {
		$seen[ $name ] = true;
	}

	$add_accepted = function ( $json ) use ( &$seen ) {
		foreach ( (array) json_decode( $json, true ) as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				$seen[ $name ] = true;
			}
		}
	};

	foreach ( array( atk_table( 'course_questions' ), atk_table( 'quiz_street_questions' ) ) as $table ) {
		foreach ( $wpdb->get_results( "SELECT road_a, road_b, accepted FROM $table" ) as $row ) {
			$seen[ $row->road_a ] = true;
			$seen[ $row->road_b ] = true;
			$add_accepted( $row->accepted );
		}
	}

	foreach ( array( atk_table( 'course_poi_questions' ), atk_table( 'quiz_poi_questions' ) ) as $table ) {
		foreach ( $wpdb->get_col( "SELECT accepted FROM $table" ) as $json ) {
			$add_accepted( $json );
		}
	}

	unset( $seen[''] );
	$list = array_keys( $seen );
	sort( $list, SORT_STRING | SORT_FLAG_CASE );
	return $list;
}

/**
 * Every POI name already used across the Course and mockup POI question
 * banks. Unlike roads/neighbourhoods there's no independent master list of
 * Aberdeen POIs to seed from, so suggestions grow organically from whatever
 * instructors have already entered.
 */
function atk_instructor_known_poi_names() {
	global $wpdb;
	$seen = array();
	foreach ( array( atk_table( 'course_poi_questions' ), atk_table( 'quiz_poi_questions' ) ) as $table ) {
		foreach ( $wpdb->get_col( "SELECT poi FROM $table" ) as $poi ) {
			$poi = trim( (string) $poi );
			if ( '' !== $poi ) {
				$seen[ $poi ] = true;
			}
		}
	}
	$list = array_keys( $seen );
	sort( $list, SORT_STRING | SORT_FLAG_CASE );
	return $list;
}

/**
 * A multi-value <select> enhanced client-side into Select2 (assets/js/
 * instructor-dashboard.js), used for every "Accepted Answers"/
 * "Neighbourhoods" field instead of a raw comma-separated text input.
 * Select2's `tags: true` still allows entering a value that isn't in
 * $whitelist (e.g. a genuinely new road name), it's a suggestion list, not
 * a hard restriction. Submits as $name[] — atk_instructor_parse_list()
 * accepts that array form directly.
 */
function atk_render_tag_select( $id, $name, $whitelist, $selected = array() ) {
	$options = '';
	foreach ( array_unique( array_merge( $selected, $whitelist ) ) as $value ) {
		$options .= sprintf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $value ),
			in_array( $value, $selected, true ) ? ' selected' : '',
			esc_html( $value )
		);
	}
	return sprintf(
		'<select name="%1$s[]" id="%2$s" class="form-select atk-tag-select" multiple="multiple">%3$s</select>',
		esc_attr( $name ),
		esc_attr( $id ),
		$options
	);
}

/**
 * A single-value <select> enhanced client-side into Select2 (same tags:true
 * autocomplete as atk_render_tag_select() above), used for one-value fields
 * like Road A/B, Point of Interest, and From/To instead of a raw text
 * input — still lets an instructor type a value that isn't in $whitelist.
 */
function atk_render_single_select( $id, $name, $whitelist, $selected = '' ) {
	$values = $whitelist;
	if ( '' !== $selected && ! in_array( $selected, $values, true ) ) {
		$values[] = $selected;
	}
	$options = '<option value=""></option>';
	foreach ( array_unique( $values ) as $value ) {
		$options .= sprintf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $value, $selected, false ),
			esc_html( $value )
		);
	}
	return sprintf(
		'<select name="%1$s" id="%2$s" class="form-select atk-single-select" required>%3$s</select>',
		esc_attr( $name ),
		esc_attr( $id ),
		$options
	);
}

/* -------------------------------------------------------------------- */
/* Course Builder: weeks                                                */
/* -------------------------------------------------------------------- */

function atk_instructor_course_question_table( $type ) {
	$types = atk_course_types();
	$type  = isset( $types[ $type ] ) ? $type : 'street';
	return atk_table( $types[ $type ]['question_table'] );
}

add_action( 'wp_ajax_atk_instructor_save_week', 'atk_handle_instructor_save_week' );
function atk_handle_instructor_save_week() {
	atk_instructor_verify_request();

	global $wpdb;
	$week_id        = isset( $_POST['week_id'] ) ? (int) $_POST['week_id'] : 0;
	$course_type    = isset( $_POST['course_type'] ) ? atk_course_type_or_default( sanitize_key( $_POST['course_type'] ) ) : 'street';
	$week_number    = isset( $_POST['week_number'] ) ? (int) $_POST['week_number'] : 0;
	$neighbourhoods = implode( ', ', atk_instructor_parse_list( isset( $_POST['neighbourhoods'] ) ? wp_unslash( $_POST['neighbourhoods'] ) : '' ) );

	if ( $week_number < 1 || '' === $neighbourhoods ) {
		atk_instructor_redirect_back( array( 'tab' => 'course', 'type' => $course_type, 'notice' => 'missing_fields' ) );
	}

	$fields = array( 'week_number' => $week_number, 'neighbourhoods' => $neighbourhoods );

	if ( $week_id ) {
		$wpdb->update( atk_table( 'course_weeks' ), $fields, array( 'id' => $week_id ) );
	} else {
		$fields['course_type'] = $course_type;
		$max_order = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT MAX(sort_order) FROM ' . atk_table( 'course_weeks' ) . ' WHERE course_type = %s',
			$course_type
		) );
		$fields['sort_order'] = $max_order + 1;
		$wpdb->insert( atk_table( 'course_weeks' ), $fields );
	}

	atk_instructor_redirect_back( array( 'tab' => 'course', 'type' => $course_type, 'notice' => 'saved' ) );
}

add_action( 'wp_ajax_atk_instructor_delete_week', 'atk_handle_instructor_delete_week' );
function atk_handle_instructor_delete_week() {
	atk_instructor_verify_request();

	global $wpdb;
	$week_id     = isset( $_POST['week_id'] ) ? (int) $_POST['week_id'] : 0;
	$course_type = isset( $_POST['course_type'] ) ? atk_course_type_or_default( sanitize_key( $_POST['course_type'] ) ) : 'street';
	if ( $week_id ) {
		$wpdb->delete( atk_instructor_course_question_table( $course_type ), array( 'week_id' => $week_id ) );
		$wpdb->delete( atk_table( 'course_weeks' ), array( 'id' => $week_id ) );
	}

	atk_instructor_redirect_back( array( 'tab' => 'course', 'type' => $course_type, 'notice' => 'deleted' ) );
}

/* -------------------------------------------------------------------- */
/* Course Builder: questions                                            */
/* -------------------------------------------------------------------- */

add_action( 'wp_ajax_atk_instructor_save_course_question', 'atk_handle_instructor_save_course_question' );
function atk_handle_instructor_save_course_question() {
	atk_instructor_verify_request();

	global $wpdb;
	$course_type = isset( $_POST['course_type'] ) ? atk_course_type_or_default( sanitize_key( $_POST['course_type'] ) ) : 'street';
	$table       = atk_instructor_course_question_table( $course_type );
	$question_id = isset( $_POST['question_id'] ) ? (int) $_POST['question_id'] : 0;
	$week_id     = isset( $_POST['week_id'] ) ? (int) $_POST['week_id'] : 0;
	$test_number = isset( $_POST['test_number'] ) ? (int) $_POST['test_number'] : 0;

	$redirect_args = array( 'tab' => 'course', 'type' => $course_type, 'week' => $week_id );

	if ( ! $week_id || $test_number < 1 || $test_number > ATK_COURSE_TESTS_PER_WEEK ) {
		atk_instructor_redirect_back( array_merge( $redirect_args, array( 'notice' => 'missing_fields' ) ) );
	}

	if ( 'street' === $course_type ) {
		$road_a   = isset( $_POST['road_a'] ) ? sanitize_text_field( wp_unslash( $_POST['road_a'] ) ) : '';
		$road_b   = isset( $_POST['road_b'] ) ? sanitize_text_field( wp_unslash( $_POST['road_b'] ) ) : '';
		$accepted = atk_instructor_parse_list( isset( $_POST['accepted'] ) ? wp_unslash( $_POST['accepted'] ) : '' );
		if ( '' === $road_a || '' === $road_b || empty( $accepted ) ) {
			atk_instructor_redirect_back( array_merge( $redirect_args, array( 'notice' => 'missing_fields' ) ) );
		}
		$fields = array( 'road_a' => $road_a, 'road_b' => $road_b, 'accepted' => wp_json_encode( $accepted ) );
	} elseif ( 'poi' === $course_type ) {
		$poi      = isset( $_POST['poi'] ) ? sanitize_text_field( wp_unslash( $_POST['poi'] ) ) : '';
		$accepted = atk_instructor_parse_list( isset( $_POST['accepted'] ) ? wp_unslash( $_POST['accepted'] ) : '' );
		if ( '' === $poi || empty( $accepted ) ) {
			atk_instructor_redirect_back( array_merge( $redirect_args, array( 'notice' => 'missing_fields' ) ) );
		}
		$fields = array( 'poi' => $poi, 'accepted' => wp_json_encode( $accepted ) );
	} else { // route
		$from_place = isset( $_POST['from_place'] ) ? sanitize_text_field( wp_unslash( $_POST['from_place'] ) ) : '';
		$to_place   = isset( $_POST['to_place'] ) ? sanitize_text_field( wp_unslash( $_POST['to_place'] ) ) : '';
		$options    = array();
		for ( $i = 0; $i < 4; $i++ ) {
			// Each option is now an ordered multi-select (name="option_N[]") —
			// the roads it submits, in pick order, ARE the route sequence, so
			// this is just sanitize+dedupe-empties, not the old "->"-string
			// parser (atk_instructor_parse_route_option(), still used by the
			// Question Bank's free-text version of this same field).
			$raw = isset( $_POST[ 'option_' . $i ] ) ? wp_unslash( $_POST[ 'option_' . $i ] ) : array();
			$opt = atk_instructor_parse_list( $raw );
			if ( ! empty( $opt ) ) {
				$options[] = $opt;
			}
		}
		$correct_index = isset( $_POST['correct_index'] ) ? (int) $_POST['correct_index'] : 0;

		if ( '' === $from_place || '' === $to_place || count( $options ) < 2 || $correct_index >= count( $options ) ) {
			atk_instructor_redirect_back( array_merge( $redirect_args, array( 'notice' => 'missing_fields' ) ) );
		}
		$fields = array(
			'from_place'    => $from_place,
			'to_place'      => $to_place,
			'options'       => wp_json_encode( $options ),
			'correct_index' => $correct_index,
		);
	}

	$fields['week_id']     = $week_id;
	$fields['test_number'] = $test_number;

	if ( $question_id ) {
		$wpdb->update( $table, $fields, array( 'id' => $question_id ) );
	} else {
		$max_order = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(sort_order) FROM $table WHERE week_id = %d AND test_number = %d",
			$week_id, $test_number
		) );
		$fields['sort_order'] = $max_order + 1;
		$wpdb->insert( $table, $fields );
	}

	atk_instructor_redirect_back( array_merge( $redirect_args, array( 'notice' => 'saved' ) ) );
}

add_action( 'wp_ajax_atk_instructor_delete_course_question', 'atk_handle_instructor_delete_course_question' );
function atk_handle_instructor_delete_course_question() {
	atk_instructor_verify_request();

	global $wpdb;
	$course_type = isset( $_POST['course_type'] ) ? atk_course_type_or_default( sanitize_key( $_POST['course_type'] ) ) : 'street';
	$question_id = isset( $_POST['question_id'] ) ? (int) $_POST['question_id'] : 0;
	$week_id     = isset( $_POST['week_id'] ) ? (int) $_POST['week_id'] : 0;
	if ( $question_id ) {
		$wpdb->delete( atk_instructor_course_question_table( $course_type ), array( 'id' => $question_id ) );
	}

	atk_instructor_redirect_back( array( 'tab' => 'course', 'type' => $course_type, 'week' => $week_id, 'notice' => 'deleted' ) );
}

/* -------------------------------------------------------------------- */
/* Question Bank: mockup Street / POI / Route questions                 */
/* -------------------------------------------------------------------- */

function atk_instructor_quiz_table( $qtype ) {
	$tables = array(
		'street' => atk_table( 'quiz_street_questions' ),
		'poi'    => atk_table( 'quiz_poi_questions' ),
		'route'  => atk_table( 'quiz_route_questions' ),
	);
	return isset( $tables[ $qtype ] ) ? $tables[ $qtype ] : null;
}

add_action( 'wp_ajax_atk_instructor_save_quiz_question', 'atk_handle_instructor_save_quiz_question' );
function atk_handle_instructor_save_quiz_question() {
	atk_instructor_verify_request();

	global $wpdb;
	$qtype       = isset( $_POST['qtype'] ) ? sanitize_key( $_POST['qtype'] ) : '';
	$table       = atk_instructor_quiz_table( $qtype );
	$question_id = isset( $_POST['question_id'] ) ? (int) $_POST['question_id'] : 0;

	if ( ! $table ) {
		atk_instructor_redirect_back( array( 'tab' => 'questions', 'notice' => 'missing_fields' ) );
	}

	if ( 'street' === $qtype ) {
		$road_a   = isset( $_POST['road_a'] ) ? sanitize_text_field( wp_unslash( $_POST['road_a'] ) ) : '';
		$road_b   = isset( $_POST['road_b'] ) ? sanitize_text_field( wp_unslash( $_POST['road_b'] ) ) : '';
		$accepted = atk_instructor_parse_list( isset( $_POST['accepted'] ) ? wp_unslash( $_POST['accepted'] ) : '' );
		if ( '' === $road_a || '' === $road_b || empty( $accepted ) ) {
			atk_instructor_redirect_back( array( 'tab' => 'questions', 'type' => $qtype, 'notice' => 'missing_fields' ) );
		}
		$fields = array( 'road_a' => $road_a, 'road_b' => $road_b, 'accepted' => wp_json_encode( $accepted ) );
	} elseif ( 'poi' === $qtype ) {
		$poi      = isset( $_POST['poi'] ) ? sanitize_text_field( wp_unslash( $_POST['poi'] ) ) : '';
		$accepted = atk_instructor_parse_list( isset( $_POST['accepted'] ) ? wp_unslash( $_POST['accepted'] ) : '' );
		if ( '' === $poi || empty( $accepted ) ) {
			atk_instructor_redirect_back( array( 'tab' => 'questions', 'type' => $qtype, 'notice' => 'missing_fields' ) );
		}
		$fields = array( 'poi' => $poi, 'accepted' => wp_json_encode( $accepted ) );
	} else { // route
		$from_place = isset( $_POST['from_place'] ) ? sanitize_text_field( wp_unslash( $_POST['from_place'] ) ) : '';
		$to_place   = isset( $_POST['to_place'] ) ? sanitize_text_field( wp_unslash( $_POST['to_place'] ) ) : '';
		$options    = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$raw = isset( $_POST[ 'option_' . $i ] ) ? wp_unslash( $_POST[ 'option_' . $i ] ) : '';
			$opt = atk_instructor_parse_route_option( $raw );
			if ( ! empty( $opt ) ) {
				$options[] = $opt;
			}
		}
		$correct_index = isset( $_POST['correct_index'] ) ? (int) $_POST['correct_index'] : 0;

		if ( '' === $from_place || '' === $to_place || count( $options ) < 2 || $correct_index >= count( $options ) ) {
			atk_instructor_redirect_back( array( 'tab' => 'questions', 'type' => $qtype, 'notice' => 'missing_fields' ) );
		}
		$fields = array(
			'from_place'    => $from_place,
			'to_place'      => $to_place,
			'options'       => wp_json_encode( $options ),
			'correct_index' => $correct_index,
		);
	}

	if ( $question_id ) {
		$wpdb->update( $table, $fields, array( 'id' => $question_id ) );
	} else {
		$max_order            = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM $table" );
		$fields['sort_order'] = $max_order + 1;
		$wpdb->insert( $table, $fields );
	}

	atk_instructor_redirect_back( array( 'tab' => 'questions', 'type' => $qtype, 'notice' => 'saved' ) );
}

/**
 * Bulk edit of the mockup POI questions from a textarea: one line per
 * question, "POI name | answer 1, answer 2". Synced by POI name
 * (case-insensitive): existing names are updated in place (keeping their id),
 * new names inserted, names no longer listed deleted, and line order becomes
 * sort_order. Any malformed line rejects the whole save so a typo can never
 * silently drop questions.
 */
add_action( 'wp_ajax_atk_instructor_bulk_save_poi_questions', 'atk_handle_instructor_bulk_save_poi_questions' );
function atk_handle_instructor_bulk_save_poi_questions() {
	atk_instructor_verify_request();

	global $wpdb;
	$table = atk_table( 'quiz_poi_questions' );
	$raw   = isset( $_POST['poi_list'] ) ? (string) wp_unslash( $_POST['poi_list'] ) : '';
	$fail  = array( 'tab' => 'questions', 'type' => 'poi', 'notice' => 'missing_fields' );

	$entries = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		$parts    = explode( '|', $line, 2 );
		$poi      = sanitize_text_field( $parts[0] );
		$accepted = atk_instructor_parse_list( isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '' );
		if ( '' === $poi || empty( $accepted ) ) {
			atk_instructor_redirect_back( $fail );
		}
		$key = strtolower( $poi );
		if ( ! isset( $entries[ $key ] ) ) { // duplicate names: first line wins
			$entries[ $key ] = array( 'poi' => $poi, 'accepted' => $accepted );
		}
	}
	if ( empty( $entries ) ) { // an empty box is far more likely a mistake than "delete everything"
		atk_instructor_redirect_back( $fail );
	}

	$existing = array();
	foreach ( $wpdb->get_results( "SELECT id, poi FROM $table", ARRAY_A ) as $row ) {
		$existing[ strtolower( trim( $row['poi'] ) ) ] = (int) $row['id'];
	}

	$order = 0;
	foreach ( $entries as $key => $entry ) {
		$order++;
		$fields = array(
			'poi'        => $entry['poi'],
			'accepted'   => wp_json_encode( $entry['accepted'] ),
			'sort_order' => $order,
		);
		if ( isset( $existing[ $key ] ) ) {
			$wpdb->update( $table, $fields, array( 'id' => $existing[ $key ] ) );
			unset( $existing[ $key ] );
		} else {
			$wpdb->insert( $table, $fields );
		}
	}
	foreach ( $existing as $id ) { // listed nowhere in the textarea any more
		$wpdb->delete( $table, array( 'id' => $id ) );
	}

	atk_instructor_redirect_back( array( 'tab' => 'questions', 'type' => 'poi', 'notice' => 'saved' ) );
}

add_action( 'wp_ajax_atk_instructor_delete_quiz_question', 'atk_handle_instructor_delete_quiz_question' );
function atk_handle_instructor_delete_quiz_question() {
	atk_instructor_verify_request();

	global $wpdb;
	$qtype       = isset( $_POST['qtype'] ) ? sanitize_key( $_POST['qtype'] ) : '';
	$table       = atk_instructor_quiz_table( $qtype );
	$question_id = isset( $_POST['question_id'] ) ? (int) $_POST['question_id'] : 0;

	if ( $table && $question_id ) {
		$wpdb->delete( $table, array( 'id' => $question_id ) );
	}

	atk_instructor_redirect_back( array( 'tab' => 'questions', 'type' => $qtype, 'notice' => 'deleted' ) );
}

/* -------------------------------------------------------------------- */
/* Rendering                                                             */
/* -------------------------------------------------------------------- */

function atk_render_instructor_dashboard() {
	atk_require_instructor();

	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'results';
	if ( 'roads' === $tab ) {
		// Major Streets moved inside Study Tools; old bookmarks still land there.
		$tab = 'study-tools';
	}
	if ( ! in_array( $tab, array( 'results', 'course', 'questions', 'study-tools', 'neighbourhoods', 'demo' ), true ) ) {
		$tab = 'results';
	}
	?>
	<div class="container py-10 py-lg-15">
		<header class="mb-10">
			<p class="fs-5 text-gray-600 mb-0">Review driver results, build the Official Test course, and manage the mockup question banks.</p>
		</header>

		<ul class="nav nav-tabs nav-line-tabs nav-line-tabs-2x mb-8 fs-6">
			<li class="nav-item">
				<a class="nav-link<?php echo 'results' === $tab ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'results', atk_get_page_url( 'instructor-dashboard' ) ) ); ?>">Driver Results</a>
			</li>
			<li class="nav-item">
				<a class="nav-link<?php echo 'course' === $tab ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'course', atk_get_page_url( 'instructor-dashboard' ) ) ); ?>">Course Builder</a>
			</li>
			<li class="nav-item">
				<a class="nav-link<?php echo 'questions' === $tab ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'questions', atk_get_page_url( 'instructor-dashboard' ) ) ); ?>">Question Bank</a>
			</li>
			<li class="nav-item">
				<a class="nav-link<?php echo 'study-tools' === $tab ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'study-tools', atk_get_page_url( 'instructor-dashboard' ) ) ); ?>">Study Tools</a>
			</li>
			<li class="nav-item">
				<a class="nav-link<?php echo 'neighbourhoods' === $tab ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'neighbourhoods', atk_get_page_url( 'instructor-dashboard' ) ) ); ?>">Neighbourhoods</a>
			</li>
			<li class="nav-item">
				<a class="nav-link<?php echo 'demo' === $tab ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'demo', atk_get_page_url( 'instructor-dashboard' ) ) ); ?>">Demo</a>
			</li>
		</ul>

		<?php
		if ( 'results' === $tab ) {
			atk_render_instructor_results_tab();
		} elseif ( 'course' === $tab ) {
			atk_render_instructor_course_tab();
		} elseif ( 'study-tools' === $tab ) {
			atk_render_instructor_study_tools_tab();
		} elseif ( 'neighbourhoods' === $tab ) {
			atk_render_instructor_neighbourhoods_tab();
		} elseif ( 'demo' === $tab ) {
			atk_render_instructor_demo_tab();
		} else {
			atk_render_instructor_questions_tab();
		}
		?>
	</div>
	<?php
}
add_shortcode( 'atk_instructor_dashboard', function () {
	wp_enqueue_script( 'atk-instructor-dashboard', ATK_PLUGIN_URI . '/assets/js/instructor-dashboard.js', array( 'atk-scripts-bundle' ), filemtime( ATK_PLUGIN_DIR . '/assets/js/instructor-dashboard.js' ), true );
	wp_add_inline_script( 'atk-instructor-dashboard', 'var ATK_INSTRUCTOR = ' . wp_json_encode( array(
		'neighbourhoods' => atk_instructor_known_neighbourhoods(),
		'roadNames'      => atk_instructor_known_road_names(),
	) ) . ';', 'before' );
	ob_start();
	atk_render_instructor_dashboard();
	return ob_get_clean();
} );

/**
 * Study Tools tab: the instructor-managed content behind the map tools. A line-tab
 * bar switches between Major Streets and Places of Interest (?tool=roads|poi).
 */
function atk_render_instructor_study_tools_tab() {
	$tools = array( 'roads' => 'Major Streets', 'poi' => 'Places of Interest' );
	$tool  = isset( $_GET['tool'] ) ? sanitize_key( $_GET['tool'] ) : 'roads';
	if ( ! isset( $tools[ $tool ] ) ) {
		$tool = 'roads';
	}
	?>
	<ul class="nav nav-tabs nav-line-tabs fs-7 mb-6">
		<?php foreach ( $tools as $key => $label ) : ?>
			<li class="nav-item">
				<a class="nav-link<?php echo $key === $tool ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'study-tools', 'tool' => $key ), atk_get_page_url( 'instructor-dashboard' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
	if ( 'poi' === $tool ) {
		atk_render_instructor_poi_places_tab();
		return;
	}
	atk_render_instructor_roads_tab();
}

const ATK_RESULTS_PER_PAGE = 10;

/**
 * Pagination nav for the Driver Results leaderboard tables — the same real
 * Bootstrap pagination component the "Previous Attempts" modal uses
 * (inc/attempts.php), reused here for a query-string-paginated table
 * instead of a JS-toggled one.
 */
function atk_render_results_pagination_nav( $view, $current_page, $total_pages ) {
	if ( $total_pages <= 1 ) {
		return;
	}
	?>
	<nav aria-label="Driver results pages" class="mt-4 d-flex justify-content-center">
		<ul class="pagination pagination-sm mb-0">
			<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
				<li class="page-item<?php echo $p === $current_page ? ' active' : ''; ?>">
					<a class="page-link" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'results', 'results_view' => $view, 'page' => $p ), atk_get_page_url( 'instructor-dashboard' ) ) ); ?>"><?php echo esc_html( $p ); ?></a>
				</li>
			<?php endfor; ?>
		</ul>
	</nav>
	<?php
}

/**
 * Driver Results tab: a Mockup/Street/POI/Route sub-tab bar (query-string
 * driven, same pattern as the Course Builder's Street/POI/Route sub-tabs)
 * showing every driver's result for the three mockup tests, either as an
 * all-tests overview or a per-test leaderboard — each paginated at
 * ATK_RESULTS_PER_PAGE drivers per page.
 */
function atk_render_instructor_results_tab() {
	$drivers       = get_users( array( 'role' => 'driver', 'orderby' => 'display_name', 'order' => 'ASC' ) );
	$mockup_labels = array( 'street' => 'Street Test', 'poi' => 'POI Test', 'route' => 'Route Test' );

	$view = isset( $_GET['results_view'] ) ? sanitize_key( $_GET['results_view'] ) : 'mockup';
	if ( 'mockup' !== $view && ! isset( $mockup_labels[ $view ] ) ) {
		$view = 'mockup';
	}
	$requested_page = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;

	$modals = '';
	?>
	<ul class="nav nav-pills mb-6">
		<li class="nav-item">
			<a class="nav-link<?php echo 'mockup' === $view ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'results', 'results_view' => 'mockup' ), atk_get_page_url( 'instructor-dashboard' ) ) ); ?>">Mockup</a>
		</li>
		<?php foreach ( $mockup_labels as $mkey => $mlabel ) : ?>
			<li class="nav-item">
				<a class="nav-link<?php echo $mkey === $view ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'results', 'results_view' => $mkey ), atk_get_page_url( 'instructor-dashboard' ) ) ); ?>"><?php echo esc_html( $mlabel ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( empty( $drivers ) ) : ?>
		<div class="card mb-6"><div class="card-body"><p class="text-gray-600 mb-0">No drivers have signed up yet.</p></div></div>
	<?php elseif ( 'mockup' === $view ) :
		$filtered_drivers = array_values( array_filter( $drivers, function ( $driver ) use ( $mockup_labels ) {
			$reviews = atk_attempt_reviews( $driver->ID );
			foreach ( $mockup_labels as $mkey => $mlabel ) {
				if ( ! empty( $reviews[ $mkey ] ) ) {
					return true;
				}
			}
			return false;
		} ) );
		$total_pages  = max( 1, (int) ceil( count( $filtered_drivers ) / ATK_RESULTS_PER_PAGE ) );
		$current_page = min( $requested_page, $total_pages );
		$page_drivers = array_slice( $filtered_drivers, ( $current_page - 1 ) * ATK_RESULTS_PER_PAGE, ATK_RESULTS_PER_PAGE );
		?>
		<div class="card mb-6">
			<div class="card-body">
				<div class="table-responsive">
					<table class="table table-row-dashed align-middle mb-0">
						<thead>
							<tr class="text-gray-500 fs-7 text-uppercase">
								<th>Driver</th>
								<?php foreach ( $mockup_labels as $mlabel ) : ?>
									<th><?php echo esc_html( $mlabel ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $page_drivers ) ) : ?>
								<tr><td colspan="<?php echo esc_attr( 1 + count( $mockup_labels ) ); ?>" class="text-gray-500">No mockup tests attempted yet.</td></tr>
							<?php endif; ?>
							<?php foreach ( $page_drivers as $driver ) :
								$reviews = atk_attempt_reviews( $driver->ID );
								?>
								<tr>
									<td>
										<div class="d-flex align-items-center gap-3">
											<?php echo atk_get_avatar_html( $driver->ID, 35 ); ?>
											<div>
												<div class="fw-bold text-gray-900"><?php echo esc_html( $driver->display_name ); ?><?php echo atk_is_demo_driver( $driver->ID ) ? ' <span class="badge badge-light fs-9">Demo</span>' : ''; ?></div>
												<div class="text-gray-500 fs-8"><?php echo esc_html( $driver->user_email ); ?></div>
											</div>
										</div>
									</td>
									<?php foreach ( $mockup_labels as $mkey => $mlabel ) :
										if ( empty( $reviews[ $mkey ] ) ) :
											?>
											<td class="text-gray-400">&mdash;</td>
										<?php else :
											$latest  = $reviews[ $mkey ][0];
											$passed  = $latest['total'] > 0 && ( $latest['score'] / $latest['total'] ) >= ATK_QUIZ_PASS_THRESHOLD;
											$trigger = atk_render_previous_attempts_trigger( $mkey, 'btn btn-sm btn-icon btn-light-primary', true, $driver->ID );
											$modals .= atk_render_previous_attempts_modal( $mkey, $mlabel, $driver->ID );
											?>
											<td>
												<span class="badge <?php echo $passed ? 'badge-light-success' : 'badge-light-danger'; ?> me-2"><?php echo esc_html( $latest['score'] . '/' . $latest['total'] ); ?></span>
												<?php echo $trigger; ?>
											</td>
										<?php endif;
									endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php atk_render_results_pagination_nav( 'mockup', $current_page, $total_pages ); ?>
			</div>
		</div>
	<?php else :
		$mlabel           = $mockup_labels[ $view ];
		$filtered_drivers = array_values( array_filter( $drivers, function ( $driver ) use ( $view ) {
			$reviews = atk_attempt_reviews( $driver->ID );
			return ! empty( $reviews[ $view ] );
		} ) );
		$total_pages  = max( 1, (int) ceil( count( $filtered_drivers ) / ATK_RESULTS_PER_PAGE ) );
		$current_page = min( $requested_page, $total_pages );
		$page_drivers = array_slice( $filtered_drivers, ( $current_page - 1 ) * ATK_RESULTS_PER_PAGE, ATK_RESULTS_PER_PAGE );
		?>
		<div class="card mb-6">
			<div class="card-body">
				<div class="table-responsive">
					<table class="table table-row-dashed align-middle mb-0">
						<thead>
							<tr class="text-gray-500 fs-7 text-uppercase">
								<th>Driver</th>
								<th>Latest Score</th>
								<th>Result</th>
								<th>Attempts</th>
								<th>Last Taken</th>
								<th class="text-end">Review</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $page_drivers ) ) : ?>
								<tr><td colspan="6" class="text-gray-500">No attempts yet for <?php echo esc_html( $mlabel ); ?>.</td></tr>
							<?php endif; ?>
							<?php foreach ( $page_drivers as $driver ) :
								$reviews       = atk_attempt_reviews( $driver->ID );
								$attempts_list = $reviews[ $view ];
								$latest        = $attempts_list[0];
								$passed        = $latest['total'] > 0 && ( $latest['score'] / $latest['total'] ) >= ATK_QUIZ_PASS_THRESHOLD;
								$trigger       = atk_render_previous_attempts_trigger( $view, 'btn btn-sm btn-light-primary', false, $driver->ID );
								$modals       .= atk_render_previous_attempts_modal( $view, $mlabel, $driver->ID );
								?>
								<tr>
									<td>
										<div class="d-flex align-items-center gap-3">
											<?php echo atk_get_avatar_html( $driver->ID, 35 ); ?>
											<div>
												<div class="fw-bold text-gray-900"><?php echo esc_html( $driver->display_name ); ?><?php echo atk_is_demo_driver( $driver->ID ) ? ' <span class="badge badge-light fs-9">Demo</span>' : ''; ?></div>
												<div class="text-gray-500 fs-8"><?php echo esc_html( $driver->user_email ); ?></div>
											</div>
										</div>
									</td>
									<td><?php echo esc_html( $latest['score'] . ' / ' . $latest['total'] ); ?></td>
									<td><span class="badge <?php echo $passed ? 'badge-light-success' : 'badge-light-danger'; ?>"><?php echo $passed ? 'Pass' : 'Fail'; ?></span></td>
									<td><?php echo esc_html( count( $attempts_list ) ); ?></td>
									<td class="text-gray-600"><?php echo esc_html( date_i18n( 'j M Y, g:i a', $latest['timestamp'] ) ); ?></td>
									<td class="text-end"><?php echo $trigger; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php atk_render_results_pagination_nav( $view, $current_page, $total_pages ); ?>
			</div>
		</div>
	<?php endif; ?>

	<?php echo $modals; ?>
	<?php
}

/**
 * Course Builder tab: Street/POI/Route sub-tabs (independent tracks — each
 * has its own week numbering and its own weeks/tests), weeks shown as an
 * accordion with their 5 fixed test slots and questions. Add/Edit uses one
 * shared modal per type (fields filled by assets/js/instructor-dashboard.js
 * from the clicked row's data-* attributes), rather than one modal per
 * question — some tests have up to 20+ rows.
 */
function atk_render_instructor_course_tab() {
	$types = atk_course_types();
	$type  = isset( $_GET['type'] ) ? atk_course_type_or_default( sanitize_key( $_GET['type'] ) ) : 'street';
	$weeks = atk_course_weeks( $type );
	$price = atk_course_get_price( $type );
	atk_nb_enqueue_course_builder( 'street' === $type ? atk_nb_week_street_map( $weeks ) : array() );
	?>
	<ul class="nav nav-pills mb-6">
		<?php foreach ( $types as $tkey => $tinfo ) : ?>
			<li class="nav-item">
				<a class="nav-link<?php echo $type === $tkey ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'course', 'type' => $tkey ), atk_get_page_url( 'instructor-dashboard' ) ) ); ?>"><?php echo esc_html( $tinfo['label'] ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( isset( $_GET['notice'] ) && 'price_saved' === $_GET['notice'] ) : ?>
		<div class="alert alert-success d-flex align-items-center p-5 mb-6"><span>Price saved.</span></div>
	<?php endif; ?>

	<div class="card mb-6">
		<div class="card-header">
			<h2 class="card-title fs-4">Price &mdash; <?php echo esc_html( $types[ $type ]['label'] ); ?></h2>
		</div>
		<div class="card-body">
			<?php if ( ! atk_stripe_is_configured() ) : ?>
				<div class="alert alert-warning d-flex align-items-center p-5 mb-6"><span>Stripe isn't fully configured in <a href="<?php echo esc_url( atk_get_page_url( 'settings' ) ); ?>">Settings</a> yet, so enrollment stays free and instant regardless of the price set here.</span></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" class="row g-4 align-items-end">
				<input type="hidden" name="action" value="atk_instructor_save_course_price" />
				<input type="hidden" name="course_type" value="<?php echo esc_attr( $type ); ?>" />
				<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
				<div class="col-md-4">
					<label class="form-label">Regular Price (&pound;)</label>
					<input type="number" min="0" step="0.01" name="price_regular" class="form-control" value="<?php echo esc_attr( $price['regular'] ); ?>" placeholder="0.00" />
				</div>
				<div class="col-md-4">
					<label class="form-label">Sale / Promo Price (&pound;)</label>
					<input type="number" min="0" step="0.01" name="price_sale" class="form-control" value="<?php echo esc_attr( $price['sale'] ); ?>" placeholder="Optional" />
				</div>
				<div class="col-md-4">
					<button type="submit" class="btn btn-primary w-100">Save Price</button>
				</div>
			</form>
		</div>
	</div>

	<div class="card mb-6">
		<div class="card-header">
			<h2 class="card-title fs-4">Add Week &mdash; <?php echo esc_html( $types[ $type ]['label'] ); ?></h2>
		</div>
		<div class="card-body">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" class="row g-4 align-items-end">
				<input type="hidden" name="action" value="atk_instructor_save_week" />
				<input type="hidden" name="course_type" value="<?php echo esc_attr( $type ); ?>" />
				<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
				<div class="col-md-3">
					<label class="form-label">Week Number</label>
					<input type="number" min="1" name="week_number" class="form-control" required value="<?php echo esc_attr( count( $weeks ) + 1 ); ?>" />
				</div>
				<div class="col-md-7">
					<label class="form-label">Neighbourhoods</label>
					<?php echo atk_render_tag_select( 'atk-new-week-neighbourhoods', 'neighbourhoods', atk_instructor_known_neighbourhoods() ); ?>
					<div class="form-text text-gray-600 mt-2" data-atk-nb-summary="atk-new-week-neighbourhoods" aria-live="polite"></div>
				</div>
				<div class="col-md-2">
					<button type="submit" class="btn btn-primary w-100">Add Week</button>
				</div>
			</form>
		</div>
	</div>

	<div class="accordion" id="atk-course-builder-accordion">
		<?php foreach ( $weeks as $wi => $week ) :
			$week_num = $wi + 1;
			$item_id  = 'atk-week-' . $week['id'];
			?>
			<div class="accordion-item mb-4">
				<h2 class="accordion-header d-flex align-items-center">
					<button class="accordion-button collapsed flex-grow-1" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo esc_attr( $item_id ); ?>">
						Week <?php echo esc_html( $week_num ); ?>: <?php echo esc_html( implode( ', ', $week['neighbourhoods'] ) ); ?>
					</button>
					<button type="button" class="btn btn-icon btn-light-danger me-4" title="Delete week" onclick="if(confirm('Delete this entire week and all its questions?')) document.getElementById('atk-delete-week-<?php echo esc_attr( $week['id'] ); ?>').submit();">
						<i class="bi bi-trash"></i>
					</button>
					<form id="atk-delete-week-<?php echo esc_attr( $week['id'] ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" class="d-none">
						<input type="hidden" name="action" value="atk_instructor_delete_week" />
						<input type="hidden" name="week_id" value="<?php echo esc_attr( $week['id'] ); ?>" />
						<input type="hidden" name="course_type" value="<?php echo esc_attr( $type ); ?>" />
						<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
					</form>
				</h2>
				<div id="<?php echo esc_attr( $item_id ); ?>" class="accordion-collapse collapse" data-bs-parent="#atk-course-builder-accordion">
					<div class="accordion-body">
						<?php for ( $t = 1; $t <= ATK_COURSE_TESTS_PER_WEEK; $t++ ) :
							$questions = isset( $week['tests'][ $t - 1 ] ) ? $week['tests'][ $t - 1 ] : array();
							?>
							<h4 class="fs-6 fw-bold mt-4 mb-2">Test <?php echo esc_html( $t ); ?> (<?php echo count( $questions ); ?> questions)</h4>
							<div class="table-responsive mb-2">
								<table class="table table-row-dashed align-middle">
									<thead>
										<tr class="text-gray-500 fs-7 text-uppercase">
											<?php if ( 'street' === $type ) : ?>
												<th>Road A</th><th>Road B</th><th>Accepted Answers</th>
											<?php elseif ( 'poi' === $type ) : ?>
												<th>Point of Interest</th><th>Accepted Answers</th>
											<?php else : ?>
												<th>From</th><th>To</th><th>Options</th>
											<?php endif; ?>
											<th class="text-end">Actions</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $questions as $qi => $q ) : ?>
											<tr>
												<?php if ( 'street' === $type ) : ?>
													<td><?php echo esc_html( $q['a'] ); ?></td>
													<td><?php echo esc_html( $q['b'] ); ?></td>
													<td><?php echo esc_html( implode( ', ', $q['accepted'] ) ); ?></td>
												<?php elseif ( 'poi' === $type ) : ?>
													<td><?php echo esc_html( $q['poi'] ); ?></td>
													<td><?php echo esc_html( implode( ', ', $q['accepted'] ) ); ?></td>
												<?php else : ?>
													<td><?php echo esc_html( $q['from'] ); ?></td>
													<td><?php echo esc_html( $q['to'] ); ?></td>
													<td class="fs-7">
														<?php foreach ( $q['options'] as $oi => $opt ) : ?>
															<div class="<?php echo $oi === $q['correct'] ? 'text-success fw-bold' : ''; ?>"><?php echo esc_html( implode( ' → ', $opt ) ); ?><?php echo $oi === $q['correct'] ? ' ✓' : ''; ?></div>
														<?php endforeach; ?>
													</td>
												<?php endif; ?>
												<td class="text-end">
													<?php if ( 'street' === $type ) : ?>
														<button type="button" class="btn btn-sm btn-icon btn-light-primary atk-edit-course-street-question"
															data-week-id="<?php echo esc_attr( $week['id'] ); ?>" data-test-number="<?php echo esc_attr( $t ); ?>" data-id="<?php echo esc_attr( $q['id'] ); ?>"
															data-road-a="<?php echo esc_attr( $q['a'] ); ?>" data-road-b="<?php echo esc_attr( $q['b'] ); ?>" data-accepted="<?php echo esc_attr( implode( ', ', $q['accepted'] ) ); ?>"
															title="Edit"><i class="bi bi-pencil"></i></button>
													<?php elseif ( 'poi' === $type ) : ?>
														<button type="button" class="btn btn-sm btn-icon btn-light-primary atk-edit-course-poi-question"
															data-week-id="<?php echo esc_attr( $week['id'] ); ?>" data-test-number="<?php echo esc_attr( $t ); ?>" data-id="<?php echo esc_attr( $q['id'] ); ?>"
															data-poi="<?php echo esc_attr( $q['poi'] ); ?>" data-accepted="<?php echo esc_attr( implode( ', ', $q['accepted'] ) ); ?>"
															title="Edit"><i class="bi bi-pencil"></i></button>
													<?php else : ?>
														<button type="button" class="btn btn-sm btn-icon btn-light-primary atk-edit-course-route-question"
															data-week-id="<?php echo esc_attr( $week['id'] ); ?>" data-test-number="<?php echo esc_attr( $t ); ?>" data-id="<?php echo esc_attr( $q['id'] ); ?>"
															data-from="<?php echo esc_attr( $q['from'] ); ?>" data-to="<?php echo esc_attr( $q['to'] ); ?>"
															data-options="<?php echo esc_attr( wp_json_encode( array_map( function ( $o ) { return implode( ' -> ', $o ); }, $q['options'] ) ) ); ?>"
															data-correct="<?php echo esc_attr( $q['correct'] ); ?>"
															title="Edit"><i class="bi bi-pencil"></i></button>
													<?php endif; ?>
													<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" class="d-inline">
														<input type="hidden" name="action" value="atk_instructor_delete_course_question" />
														<input type="hidden" name="question_id" value="<?php echo esc_attr( $q['id'] ); ?>" />
														<input type="hidden" name="week_id" value="<?php echo esc_attr( $week['id'] ); ?>" />
														<input type="hidden" name="course_type" value="<?php echo esc_attr( $type ); ?>" />
														<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
														<button type="submit" class="btn btn-sm btn-icon btn-light-danger" title="Delete" onclick="return confirm('Delete this question?');"><i class="bi bi-trash"></i></button>
													</form>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<button type="button" class="btn btn-sm btn-light-primary mb-6 atk-add-course-question" data-course-type="<?php echo esc_attr( $type ); ?>" data-week-id="<?php echo esc_attr( $week['id'] ); ?>" data-test-number="<?php echo esc_attr( $t ); ?>">
								<i class="bi bi-plus-lg me-1"></i>Add Question to Test <?php echo esc_html( $t ); ?>
							</button>
						<?php endfor; ?>

						<div class="pt-2 border-top">
							<button type="button" class="btn btn-sm btn-light atk-edit-week" data-id="<?php echo esc_attr( $week['id'] ); ?>" data-week-number="<?php echo esc_attr( $week_num ); ?>" data-neighbourhoods="<?php echo esc_attr( implode( ', ', $week['neighbourhoods'] ) ); ?>">
								<i class="bi bi-pencil me-1"></i>Edit Week Details
							</button>
						</div>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<?php atk_render_instructor_course_modals( $type ); ?>
	<?php
}

/**
 * Modals for the Course Builder tab: one question modal per type (Street/
 * POI/Route each have different fields, same as the Question Bank modals)
 * and one shared "edit week" modal — only the current tab's type modal is
 * rendered, since week_id/question_id context doesn't cross between types.
 */
function atk_render_instructor_course_modals( $type ) {
	?>
	<?php if ( 'street' === $type ) : ?>
		<div class="modal fade" id="atk-course-question-modal" tabindex="-1" aria-hidden="true">
			<div class="modal-dialog modal-dialog-centered">
				<div class="modal-content">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
						<input type="hidden" name="action" value="atk_instructor_save_course_question" />
						<input type="hidden" name="course_type" value="street" />
						<input type="hidden" name="question_id" id="atk-cq-id" value="" />
						<input type="hidden" name="week_id" id="atk-cq-week-id" value="" />
						<input type="hidden" name="test_number" id="atk-cq-test-number" value="" />
						<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
						<div class="modal-header">
							<h3 class="modal-title" id="atk-cq-title">Street Test Question</h3>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
						</div>
						<div class="modal-body">
							<div class="d-none mb-5" id="atk-cq-nb-wrap">
								<div class="form-check form-switch form-check-custom form-check-solid">
									<input class="form-check-input" type="checkbox" id="atk-cq-nb-only" />
									<label class="form-check-label fw-semibold" for="atk-cq-nb-only">Only streets in this week's neighbourhoods <span class="text-gray-600" id="atk-cq-nb-count"></span></label>
								</div>
							</div>
							<div class="mb-4"><label class="form-label">Road A</label><?php echo atk_render_single_select( 'atk-cq-road-a', 'road_a', atk_instructor_known_road_names() ); ?></div>
							<div class="mb-4"><label class="form-label">Road B</label><?php echo atk_render_single_select( 'atk-cq-road-b', 'road_b', atk_instructor_known_road_names() ); ?></div>
							<div class="mb-2"><label class="form-label">Accepted Answers</label><?php echo atk_render_tag_select( 'atk-cq-accepted', 'accepted', atk_instructor_known_road_names() ); ?></div>
						</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
							<button type="submit" class="btn btn-primary">Save</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	<?php elseif ( 'poi' === $type ) : ?>
		<div class="modal fade" id="atk-course-question-modal" tabindex="-1" aria-hidden="true">
			<div class="modal-dialog modal-dialog-centered">
				<div class="modal-content">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
						<input type="hidden" name="action" value="atk_instructor_save_course_question" />
						<input type="hidden" name="course_type" value="poi" />
						<input type="hidden" name="question_id" id="atk-cq-poi-id" value="" />
						<input type="hidden" name="week_id" id="atk-cq-poi-week-id" value="" />
						<input type="hidden" name="test_number" id="atk-cq-poi-test-number" value="" />
						<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
						<div class="modal-header">
							<h3 class="modal-title" id="atk-cq-poi-title">POI Test Question</h3>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
						</div>
						<div class="modal-body">
							<div class="mb-4"><label class="form-label">Point of Interest</label><?php echo atk_render_single_select( 'atk-cq-poi-poi', 'poi', atk_instructor_known_poi_names() ); ?></div>
							<div class="mb-2"><label class="form-label">Accepted Answers</label><?php echo atk_render_tag_select( 'atk-cq-poi-accepted', 'accepted', atk_instructor_known_road_names() ); ?></div>
						</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
							<button type="submit" class="btn btn-primary">Save</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	<?php else : ?>
		<div class="modal fade" id="atk-course-question-modal" tabindex="-1" aria-hidden="true">
			<div class="modal-dialog modal-dialog-centered modal-lg">
				<div class="modal-content">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
						<input type="hidden" name="action" value="atk_instructor_save_course_question" />
						<input type="hidden" name="course_type" value="route" />
						<input type="hidden" name="question_id" id="atk-cq-route-id" value="" />
						<input type="hidden" name="week_id" id="atk-cq-route-week-id" value="" />
						<input type="hidden" name="test_number" id="atk-cq-route-test-number" value="" />
						<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
						<div class="modal-header">
							<h3 class="modal-title" id="atk-cq-route-title">Route Test Question</h3>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
						</div>
						<div class="modal-body">
							<div class="row g-4 mb-4">
								<div class="col-md-6"><label class="form-label">From</label><?php echo atk_render_single_select( 'atk-cq-route-from', 'from_place', atk_instructor_known_road_names() ); ?></div>
								<div class="col-md-6"><label class="form-label">To</label><?php echo atk_render_single_select( 'atk-cq-route-to', 'to_place', atk_instructor_known_road_names() ); ?></div>
							</div>
							<p class="fs-7 text-gray-500">Pick each option's roads in the order the route passes through them. Mark the correct one below.</p>
							<?php for ( $i = 0; $i < 4; $i++ ) : ?>
								<div class="mb-3 d-flex align-items-start gap-3">
									<div class="form-check mt-2">
										<input class="form-check-input" type="radio" name="correct_index" value="<?php echo esc_attr( $i ); ?>" id="atk-cq-route-correct-<?php echo esc_attr( $i ); ?>" <?php checked( 0 === $i ); ?> />
									</div>
									<div class="flex-grow-1">
										<?php if ( $i >= 2 ) : ?>
											<div class="fs-8 text-gray-500 mb-1">Option <?php echo esc_attr( $i + 1 ); ?> (optional)</div>
										<?php endif; ?>
										<?php echo atk_render_tag_select( 'atk-cq-route-option-' . $i, 'option_' . $i, atk_instructor_known_road_names() ); ?>
									</div>
								</div>
							<?php endfor; ?>
						</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
							<button type="submit" class="btn btn-primary">Save</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<div class="modal fade" id="atk-edit-week-modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<input type="hidden" name="action" value="atk_instructor_save_week" />
					<input type="hidden" name="course_type" value="<?php echo esc_attr( $type ); ?>" />
					<input type="hidden" name="week_id" id="atk-ew-id" value="" />
					<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
					<div class="modal-header">
						<h3 class="modal-title">Edit Week</h3>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<div class="mb-4">
							<label class="form-label">Week Number</label>
							<input type="number" min="1" name="week_number" id="atk-ew-week-number" class="form-control" required />
						</div>
						<div class="mb-2">
							<label class="form-label">Neighbourhoods</label>
							<?php echo atk_render_tag_select( 'atk-ew-neighbourhoods', 'neighbourhoods', atk_instructor_known_neighbourhoods() ); ?>
							<div class="form-text text-gray-600 mt-2" data-atk-nb-summary="atk-ew-neighbourhoods" aria-live="polite"></div>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
						<button type="submit" class="btn btn-primary">Save</button>
					</div>
				</form>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Question Bank tab: Street / POI / Route sub-tabs, each a simple table +
 * add/edit modal (one modal per type, reused for every row via JS).
 */
function atk_render_instructor_questions_tab() {
	$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'street';
	if ( ! in_array( $type, array( 'street', 'poi', 'route' ), true ) ) {
		$type = 'street';
	}
	$labels = array( 'street' => 'Street Test', 'poi' => 'POI Test', 'route' => 'Route Test' );
	?>
	<ul class="nav nav-pills mb-6">
		<?php foreach ( $labels as $key => $label ) : ?>
			<li class="nav-item">
				<a class="nav-link<?php echo $type === $key ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'questions', 'type' => $key ), atk_get_page_url( 'instructor-dashboard' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php
	if ( 'street' === $type ) {
		atk_render_instructor_street_questions();
	} elseif ( 'poi' === $type ) {
		atk_render_instructor_poi_questions();
	} else {
		atk_render_instructor_route_questions();
	}
	?>
	<?php
}

function atk_render_instructor_street_questions() {
	$questions = atk_street_test_questions_with_ids();
	?>
	<div class="card">
		<div class="card-body">
			<button type="button" class="btn btn-primary mb-6" data-bs-toggle="modal" data-bs-target="#atk-street-modal" onclick="atkResetQuizModal('street')"><i class="bi bi-plus-lg me-1"></i>Add Street Question</button>
			<div class="table-responsive">
				<table class="table table-row-dashed align-middle">
					<thead><tr class="text-gray-500 fs-7 text-uppercase"><th>Road A</th><th>Road B</th><th>Accepted</th><th class="text-end">Actions</th></tr></thead>
					<tbody>
						<?php foreach ( $questions as $q ) : ?>
							<tr>
								<td><?php echo esc_html( $q['a'] ); ?></td>
								<td><?php echo esc_html( $q['b'] ); ?></td>
								<td><?php echo esc_html( implode( ', ', $q['accepted'] ) ); ?></td>
								<td class="text-end">
									<button type="button" class="btn btn-sm btn-icon btn-light-primary atk-edit-street-question"
										data-id="<?php echo esc_attr( $q['id'] ); ?>" data-road-a="<?php echo esc_attr( $q['a'] ); ?>" data-road-b="<?php echo esc_attr( $q['b'] ); ?>" data-accepted="<?php echo esc_attr( implode( ', ', $q['accepted'] ) ); ?>"><i class="bi bi-pencil"></i></button>
									<?php echo atk_instructor_delete_quiz_form( 'street', $q['id'] ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="modal fade" id="atk-street-modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<input type="hidden" name="action" value="atk_instructor_save_quiz_question" />
					<input type="hidden" name="qtype" value="street" />
					<input type="hidden" name="question_id" id="atk-street-id" value="" />
					<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
					<div class="modal-header"><h3 class="modal-title">Street Test Question</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
					<div class="modal-body">
						<div class="mb-4"><label class="form-label">Road A</label><input type="text" name="road_a" id="atk-street-road-a" class="form-control" required /></div>
						<div class="mb-4"><label class="form-label">Road B</label><input type="text" name="road_b" id="atk-street-road-b" class="form-control" required /></div>
						<div class="mb-2"><label class="form-label">Accepted Answers</label><?php echo atk_render_tag_select( 'atk-street-accepted', 'accepted', atk_instructor_known_road_names() ); ?></div>
					</div>
					<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
				</form>
			</div>
		</div>
	</div>
	<?php
}

function atk_render_instructor_poi_questions() {
	$questions = atk_poi_test_questions_with_ids();
	?>

		<?php
		$poi_lines = array();
		foreach ( $questions as $q ) {
			$poi_lines[] = $q['poi'] . ' | ' . implode( ', ', $q['accepted'] );
		}
		?>
		<div class="card mb-6">
			<div class="card-header">
				<h3 class="card-title">Edit as list</h3>
				<div class="card-toolbar"><span class="badge badge-light-primary" id="atk-poi-bulk-count"><?php echo esc_html( count( $poi_lines ) ); ?> questions</span></div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" id="atk-poi-bulk-form" data-existing="<?php echo esc_attr( count( $poi_lines ) ); ?>">
				<input type="hidden" name="action" value="atk_instructor_bulk_save_poi_questions" />
				<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
				<div class="card-body">
					<textarea name="poi_list" id="atk-poi-bulk-text" class="form-control form-control-solid font-monospace fs-7" rows="12" spellcheck="false" wrap="off"><?php echo esc_textarea( implode( "\n", $poi_lines ) ); ?></textarea>
					<div class="form-text mt-3">One question per line: <code>Point of Interest | accepted answer 1, accepted answer 2</code>. Saving updates existing places (matched by name), adds new ones, and <strong>removes any place no longer listed</strong>. Line order is the question order.</div>
				</div>
				<div class="card-footer d-flex justify-content-end">
					<button type="submit" class="btn btn-primary">Save list</button>
				</div>
			</form>
		</div>
	<div class="card">
		<div class="card-body">
			<button type="button" class="btn btn-primary mb-6" data-bs-toggle="modal" data-bs-target="#atk-poi-modal" onclick="atkResetQuizModal('poi')"><i class="bi bi-plus-lg me-1"></i>Add POI Question</button>
			<div class="table-responsive">
				<table class="table table-row-dashed align-middle">
					<thead><tr class="text-gray-500 fs-7 text-uppercase"><th>Point of Interest</th><th>Accepted</th><th class="text-end">Actions</th></tr></thead>
					<tbody>
						<?php foreach ( $questions as $q ) : ?>
							<tr>
								<td><?php echo esc_html( $q['poi'] ); ?></td>
								<td><?php echo esc_html( implode( ', ', $q['accepted'] ) ); ?></td>
								<td class="text-end">
									<button type="button" class="btn btn-sm btn-icon btn-light-primary atk-edit-poi-question"
										data-id="<?php echo esc_attr( $q['id'] ); ?>" data-poi="<?php echo esc_attr( $q['poi'] ); ?>" data-accepted="<?php echo esc_attr( implode( ', ', $q['accepted'] ) ); ?>"><i class="bi bi-pencil"></i></button>
									<?php echo atk_instructor_delete_quiz_form( 'poi', $q['id'] ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="modal fade" id="atk-poi-modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<input type="hidden" name="action" value="atk_instructor_save_quiz_question" />
					<input type="hidden" name="qtype" value="poi" />
					<input type="hidden" name="question_id" id="atk-poi-id" value="" />
					<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
					<div class="modal-header"><h3 class="modal-title">POI Test Question</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
					<div class="modal-body">
						<div class="mb-4"><label class="form-label">Point of Interest</label><input type="text" name="poi" id="atk-poi-poi" class="form-control" required /></div>
						<div class="mb-2"><label class="form-label">Accepted Answers</label><?php echo atk_render_tag_select( 'atk-poi-accepted', 'accepted', atk_instructor_known_road_names() ); ?></div>
					</div>
					<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
				</form>
			</div>
		</div>
	</div>
	<?php
}

function atk_render_instructor_route_questions() {
	$questions = atk_route_test_questions_with_ids();
	?>
	<div class="card">
		<div class="card-body">
			<button type="button" class="btn btn-primary mb-6" data-bs-toggle="modal" data-bs-target="#atk-route-modal" onclick="atkResetQuizModal('route')"><i class="bi bi-plus-lg me-1"></i>Add Route Question</button>
			<div class="table-responsive">
				<table class="table table-row-dashed align-middle">
					<thead><tr class="text-gray-500 fs-7 text-uppercase"><th>From</th><th>To</th><th>Options</th><th class="text-end">Actions</th></tr></thead>
					<tbody>
						<?php foreach ( $questions as $q ) : ?>
							<tr>
								<td><?php echo esc_html( $q['from'] ); ?></td>
								<td><?php echo esc_html( $q['to'] ); ?></td>
								<td class="fs-7">
									<?php foreach ( $q['options'] as $oi => $opt ) : ?>
										<div class="<?php echo $oi === $q['correct'] ? 'text-success fw-bold' : ''; ?>"><?php echo esc_html( implode( ' → ', $opt ) ); ?><?php echo $oi === $q['correct'] ? ' ✓' : ''; ?></div>
									<?php endforeach; ?>
								</td>
								<td class="text-end">
									<button type="button" class="btn btn-sm btn-icon btn-light-primary atk-edit-route-question"
										data-id="<?php echo esc_attr( $q['id'] ); ?>"
										data-from="<?php echo esc_attr( $q['from'] ); ?>"
										data-to="<?php echo esc_attr( $q['to'] ); ?>"
										data-options="<?php echo esc_attr( wp_json_encode( array_map( function ( $o ) { return implode( ' -> ', $o ); }, $q['options'] ) ) ); ?>"
										data-correct="<?php echo esc_attr( $q['correct'] ); ?>"><i class="bi bi-pencil"></i></button>
									<?php echo atk_instructor_delete_quiz_form( 'route', $q['id'] ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="modal fade" id="atk-route-modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg">
			<div class="modal-content">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<input type="hidden" name="action" value="atk_instructor_save_quiz_question" />
					<input type="hidden" name="qtype" value="route" />
					<input type="hidden" name="question_id" id="atk-route-id" value="" />
					<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
					<div class="modal-header"><h3 class="modal-title">Route Test Question</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
					<div class="modal-body">
						<div class="row g-4 mb-4">
							<div class="col-md-6"><label class="form-label">From</label><input type="text" name="from_place" id="atk-route-from" class="form-control" required /></div>
							<div class="col-md-6"><label class="form-label">To</label><input type="text" name="to_place" id="atk-route-to" class="form-control" required /></div>
						</div>
						<p class="fs-7 text-gray-500">Enter each route option as road names separated by <code>-&gt;</code>, e.g. <code>Union Street -&gt; Bridge Street -&gt; Guild Street</code>. Mark the correct one below.</p>
						<?php for ( $i = 0; $i < 4; $i++ ) : ?>
							<div class="mb-3 d-flex align-items-center gap-3">
								<div class="form-check">
									<input class="form-check-input" type="radio" name="correct_index" value="<?php echo esc_attr( $i ); ?>" id="atk-route-correct-<?php echo esc_attr( $i ); ?>" <?php checked( 0 === $i ); ?> />
								</div>
								<input type="text" name="option_<?php echo esc_attr( $i ); ?>" id="atk-route-option-<?php echo esc_attr( $i ); ?>" class="form-control" placeholder="Option <?php echo esc_attr( $i + 1 ); ?><?php echo $i < 2 ? ' (required)' : ' (optional)'; ?>" />
							</div>
						<?php endfor; ?>
					</div>
					<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
				</form>
			</div>
		</div>
	</div>
	<?php
}

function atk_instructor_delete_quiz_form( $qtype, $id ) {
	ob_start();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" class="d-inline">
		<input type="hidden" name="action" value="atk_instructor_delete_quiz_question" />
		<input type="hidden" name="qtype" value="<?php echo esc_attr( $qtype ); ?>" />
		<input type="hidden" name="question_id" value="<?php echo esc_attr( $id ); ?>" />
		<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
		<button type="submit" class="btn btn-sm btn-icon btn-light-danger" title="Delete" onclick="return confirm('Delete this question?');"><i class="bi bi-trash"></i></button>
	</form>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------- */
/* Read helpers that include the row id (the public atk_*_test_questions()  */
/* functions in quiz-data.php intentionally don't, to keep their payload    */
/* shape unchanged for the quiz engine) — dashboard-only, for edit/delete.  */
/* -------------------------------------------------------------------- */

function atk_street_test_questions_with_ids() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT id, road_a AS a, road_b AS b, accepted FROM ' . atk_table( 'quiz_street_questions' ) . ' ORDER BY sort_order ASC, id ASC', ARRAY_A );
	return array_map( function ( $row ) {
		$row['accepted'] = json_decode( $row['accepted'], true );
		return $row;
	}, $rows );
}

function atk_poi_test_questions_with_ids() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT id, poi, accepted FROM ' . atk_table( 'quiz_poi_questions' ) . ' ORDER BY sort_order ASC, id ASC', ARRAY_A );
	return array_map( function ( $row ) {
		$row['accepted'] = json_decode( $row['accepted'], true );
		return $row;
	}, $rows );
}

function atk_route_test_questions_with_ids() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT id, from_place AS `from`, to_place AS `to`, options, correct_index AS `correct` FROM ' . atk_table( 'quiz_route_questions' ) . ' ORDER BY sort_order ASC, id ASC', ARRAY_A );
	return array_map( function ( $row ) {
		$row['options'] = json_decode( $row['options'], true );
		$row['correct'] = (int) $row['correct'];
		return $row;
	}, $rows );
}
