<?php
/**
 * Streets: the list of streets behind the Official Route test.
 *
 * - Tab: "Streets" on the Instructor Dashboard (assets/js/instructor-streets.js over the
 *   atk/v1 REST routes below). Alphabetical is editable; By ward is the same list grouped,
 *   read only, so every change shows in both.
 * - Storage: the Neighbourhoods registry (atk_nb_streets) gains `listed`, `flagged` and
 *   `routes` (the From/To answers as JSON). Wards stay in atk_nb_links, so the
 *   Neighbourhoods tab and this tab always agree. A street is on the list when it is
 *   marked listed or has a ward.
 * - Official Route test: one week per official ward, each ward's ready streets divided
 *   across the week's five tests (course_preview / course_apply). Rules live in
 *   inc/streets-engine.php (pure PHP, tested in tests/streets/).
 */

defined( 'ABSPATH' ) || exit;

require_once ATK_PLUGIN_DIR . '/inc/streets-engine.php';

/* -------------------------------------------------------------------- */
/* Storage                                                              */
/* -------------------------------------------------------------------- */

class ATK_Street_Store {

	const MAX_NOTE = 2000;

	private static function table() {
		return atk_table( 'nb_streets' );
	}

	private static function decode_list( $json ) {
		$list = json_decode( (string) $json, true );
		return is_array( $list ) ? $list : array();
	}

	/** One street as the screen and the planner use it. */
	private static function entry( $row, array $ward_ids ) {
		sort( $ward_ids );
		$pairs  = ATK_Route_Pairs::clean( self::decode_list( $row->routes ) );
		$entry  = array(
			'id'        => (int) $row->id,
			'name'      => (string) $row->name,
			'wards'     => array_values( $ward_ids ),
			'outcode'   => (string) $row->outcode,
			'postcodes' => array_values( array_map( 'strval', self::decode_list( $row->postcodes ) ) ),
			'note'      => (string) $row->note,
			'flagged'   => (bool) (int) $row->flagged,
			'pairs'     => $pairs,
			'listed'    => (bool) (int) $row->listed,
		);
		$entry['status'] = ATK_Route_Planner::status( $entry );
		return $entry;
	}

	/** Every street on the list, A to Z. */
	public static function all() {
		global $wpdb;
		ATK_Nb_Store::sync_streets();
		$links = ATK_Nb_Store::street_link_map();
		$rows  = $wpdb->get_results( 'SELECT id, name, outcode, postcodes, note, listed, flagged, routes FROM ' . self::table() . ' WHERE listed = 1 OR id IN (SELECT DISTINCT street_id FROM ' . atk_table( 'nb_links' ) . ')' );
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::entry( $row, isset( $links[ (int) $row->id ] ) ? $links[ (int) $row->id ] : array() );
		}
		usort( $out, function ( $a, $b ) {
			return strnatcasecmp( $a['name'], $b['name'] );
		} );
		return $out;
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, outcode, postcodes, note, listed, flagged, routes FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
		if ( ! $row ) {
			return null;
		}
		$ward_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT neighbourhood_id FROM ' . atk_table( 'nb_links' ) . ' WHERE street_id = %d', (int) $id ) ) );
		return self::entry( $row, $ward_ids );
	}

	/** Wards in the official order first, then any others A to Z. */
	public static function wards() {
		$official = array();
		foreach ( ATK_Poi_Planner::OFFICIAL_WARDS as $i => $name ) {
			$official[ ATK_Route_Planner::ward_match_key( $name ) ] = $i + 1;
		}
		$out = array();
		foreach ( ATK_Nb_Store::neighbourhoods() as $id => $item ) {
			$key   = ATK_Route_Planner::ward_match_key( $item['name'] );
			$out[] = array( 'id' => (int) $id, 'name' => $item['name'], 'official' => isset( $official[ $key ] ) ? $official[ $key ] : 0 );
		}
		usort( $out, function ( $a, $b ) {
			if ( $a['official'] && $b['official'] ) {
				return $a['official'] - $b['official'];
			}
			if ( $a['official'] || $b['official'] ) {
				return $a['official'] ? -1 : 1;
			}
			return strnatcasecmp( $a['name'], $b['name'] );
		} );
		return $out;
	}

	/** What the screen needs in one go. */
	public static function screen() {
		$streets = self::all();
		$counts  = array( 'total' => count( $streets ), 'ready' => 0, 'needs_answer' => 0, 'clarify' => 0, 'no_ward' => 0 );
		foreach ( $streets as $street ) {
			$counts[ $street['status'] ]++;
			if ( ! $street['wards'] ) {
				$counts['no_ward']++;
			}
		}
		return array( 'streets' => $streets, 'wards' => self::wards(), 'counts' => $counts );
	}

	private static function valid_ward_ids( $values ) {
		$known = ATK_Nb_Store::neighbourhoods();
		return array_values( array_filter( ATK_Nb_Store::clean_ids( $values ), function ( $id ) use ( $known ) {
			return isset( $known[ $id ] );
		} ) );
	}

	private static function replace_links( $street_id, array $ward_ids ) {
		global $wpdb;
		$links = atk_table( 'nb_links' );
		$ok    = false !== $wpdb->delete( $links, array( 'street_id' => (int) $street_id ), array( '%d' ) );
		foreach ( $ward_ids as $ward_id ) {
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $links (neighbourhood_id, street_id) VALUES (%d, %d)", $ward_id, $street_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Adds a street to the list or changes one. $input keys: name, wards (ids), postcodes
	 * (text or list), note, flagged, pairs. Returns the street, or WP_Error.
	 */
	public static function save( array $input, $id = 0 ) {
		global $wpdb;
		$id   = (int) $id;
		$name = isset( $input['name'] ) ? ATK_Nb_Store::new_street_name( $input['name'] ) : '';
		$row  = null;
		if ( $id ) {
			$row = self::get( $id );
			if ( ! $row ) {
				return new WP_Error( 'atk_street_missing', 'That street no longer exists.' );
			}
			if ( '' === $name ) {
				$name = $row['name'];
			}
		} elseif ( '' === $name ) {
			return new WP_Error( 'atk_street_name', 'A street needs a name (up to ' . ATK_Nb_Store::MAX_NAME . ' characters).' );
		}

		$same = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE name = %s AND id <> %d', $name, $id ) );
		if ( $same ) {
			$other = self::get( $same );
			if ( ! $id && $other && ! $other['listed'] && ! $other['wards'] ) {
				$id  = $same; // On the site's street list but not on this list yet: add it rather than duplicate it.
				$row = $other;
			} else {
				return new WP_Error( 'atk_street_exists', '"' . $name . '" is already on the list.' );
			}
		}

		$postcode_text = isset( $input['postcodes'] ) ? ( is_array( $input['postcodes'] ) ? implode( ' ', array_map( 'strval', $input['postcodes'] ) ) : (string) $input['postcodes'] ) : null;
		$fields         = array( 'name' => $name, 'listed' => 1 );
		if ( null !== $postcode_text ) {
			$postcodes          = ATK_Nb_Parser::extract_postcodes( preg_replace( '/\s+/', ' ', $postcode_text ) );
			$fields['postcodes'] = $postcodes ? wp_json_encode( $postcodes ) : null;
			$fields['outcode']   = $postcodes ? ATK_Nb_Parser::outcode_of( $postcodes[0] ) : '';
		}
		if ( isset( $input['note'] ) ) {
			$note            = trim( mb_substr( sanitize_textarea_field( (string) $input['note'] ), 0, self::MAX_NOTE, 'UTF-8' ) );
			$fields['note']  = '' === $note ? null : $note;
		}
		if ( isset( $input['flagged'] ) ) {
			$fields['flagged'] = $input['flagged'] ? 1 : 0;
		}
		if ( isset( $input['pairs'] ) ) {
			$pairs            = ATK_Route_Pairs::clean( $input['pairs'] );
			$fields['routes'] = $pairs ? wp_json_encode( $pairs ) : null;
		}

		$wpdb->query( 'START TRANSACTION' );
		if ( $id ) {
			$ok = false !== $wpdb->update( self::table(), $fields, array( 'id' => $id ) );
		} else {
			$ok = (bool) $wpdb->insert( self::table(), $fields );
			$id = (int) $wpdb->insert_id;
		}
		if ( $ok && isset( $input['wards'] ) ) {
			$ok = self::replace_links( $id, self::valid_ward_ids( $input['wards'] ) );
		}
		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'atk_street_save', 'That street could not be saved.' );
		}
		$wpdb->query( 'COMMIT' );
		return self::get( $id );
	}

	/** Takes a street off the list: its wards, flag and answers are cleared. The street stays in the site's registry. */
	public static function remove( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$wpdb->delete( atk_table( 'nb_links' ), array( 'street_id' => $id ), array( '%d' ) );
		$wpdb->update( self::table(), array( 'listed' => 0, 'flagged' => 0, 'routes' => null ), array( 'id' => $id ) );
		return true;
	}

	/* ---------------------------------------------------------------- */
	/* Import                                                            */
	/* ---------------------------------------------------------------- */

	/**
	 * What saving these parsed rows would change. Nothing is written.
	 *
	 * @return array{rows:array[], summary:array, new_names:string[]}
	 */
	public static function import_plan( array $parsed ) {
		global $wpdb;
		ATK_Nb_Store::sync_streets();
		$by_key = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . self::table() . ' ORDER BY id ASC' ) as $row ) {
			$by_key[ ATK_Nb_Parser::normalise_name( $row->name ) ][] = array( (int) $row->id, (string) $row->name );
		}
		$current = array();
		foreach ( self::all() as $street ) {
			$current[ $street['id'] ] = $street;
		}
		$ward_ids = array();
		foreach ( ATK_Nb_Store::neighbourhoods() as $id => $item ) {
			$ward_ids[ ATK_Poi_Parser::ward_key( $item['name'] ) ] = (int) $id;
		}

		$summary = array( 'rows' => count( $parsed ), 'new' => 0, 'changed' => 0, 'same' => 0, 'pairs_added' => 0, 'flagged' => 0, 'cleared' => 0, 'wards_added' => 0, 'unknown_wards' => array() );
		$rows    = array();
		$names   = array();
		foreach ( $parsed as $item ) {
			// The street already on the site: the one spelled the same, else one already on the list, else the first.
			$id    = 0;
			$name  = ATK_Nb_Store::new_street_name( $item['road'] );
			$found = $by_key[ ATK_Nb_Parser::normalise_name( $item['road'] ) ] ?? array();
			foreach ( $found as $candidate ) {
				if ( $candidate[1] === $name ) {
					$id = $candidate[0];
					break;
				}
			}
			if ( ! $id ) {
				foreach ( $found as $candidate ) {
					if ( isset( $current[ $candidate[0] ] ) ) {
						$id = $candidate[0];
						break;
					}
				}
			}
			if ( ! $id && $found ) {
				$id = $found[0][0];
			}
			$old = isset( $current[ $id ] ) ? $current[ $id ] : null;
			if ( $id && ! $old ) {
				$old = self::get( $id ); // On the site's street list, not on this list yet.
			}

			$wards   = array();
			$issues  = $item['issues'];
			foreach ( $item['wards'] as $ward ) {
				$ward_key = ATK_Poi_Parser::ward_key( $ward );
				if ( isset( $ward_ids[ $ward_key ] ) ) {
					$wards[] = $ward_ids[ $ward_key ];
				} else {
					$issues[] = 'Ward "' . $ward . '" is not on the site, so it was skipped. Add it on the Neighbourhoods tab first.';
					$summary['unknown_wards'][ $ward ] = true;
				}
			}
			$old_wards = $old ? $old['wards'] : array();
			$new_wards = array_values( array_unique( array_merge( $old_wards, $wards ) ) );
			sort( $new_wards );
			$old_pairs = $old ? $old['pairs'] : array();
			$new_pairs = ATK_Route_Pairs::clean( array_merge( $old_pairs, $item['pairs'] ) );
			$flag_old  = $old ? $old['flagged'] : false;
			$flag_new  = null === $item['clarify'] ? $flag_old : (bool) $item['clarify'];
			$postcodes = array_values( array_unique( array_merge( $old ? $old['postcodes'] : array(), $item['postcodes'] ) ) );
			$note      = '' !== $item['note'] ? $item['note'] : ( $old ? $old['note'] : '' );

			$changes = array();
			if ( ! $old ) {
				$changes[] = 'new';
			} elseif ( ! $old['listed'] && ! $old['wards'] ) {
				$changes[] = 'added to the list';
			}
			$existing_name = $old ? $old['name'] : '';
			if ( count( $new_wards ) > count( $old_wards ) ) {
				$changes[] = ( count( $new_wards ) - count( $old_wards ) ) . ' ward' . ( 1 === count( $new_wards ) - count( $old_wards ) ? '' : 's' );
				$summary['wards_added'] += count( $new_wards ) - count( $old_wards );
			}
			if ( count( $new_pairs ) > count( $old_pairs ) ) {
				$added = count( $new_pairs ) - count( $old_pairs );
				$changes[] = $added . ' answer' . ( 1 === $added ? '' : 's' );
				$summary['pairs_added'] += $added;
			}
			if ( $flag_new !== $flag_old ) {
				$changes[] = $flag_new ? 'needs clarification' : 'clarification cleared';
				$summary[ $flag_new ? 'flagged' : 'cleared' ]++;
			}
			if ( $note !== ( $old ? $old['note'] : '' ) || $postcodes !== ( $old ? $old['postcodes'] : array() ) ) {
				$changes[] = 'details';
			}

			if ( ! $old ) {
				$summary['new']++;
				$names[] = ATK_Nb_Store::new_street_name( $item['road'] );
			} elseif ( $changes ) {
				$summary['changed']++;
			} else {
				$summary['same']++;
			}
			$rows[] = array(
				'line'      => $item['line'],
				'road'      => $item['road'],
				'name'      => $existing_name ? $existing_name : $name,
				'street_id' => $id,
				'changes'   => $changes,
				'issues'    => $issues,
				'apply'     => array(
					'wards'     => $new_wards,
					'pairs'     => $new_pairs,
					'flagged'   => $flag_new,
					'postcodes' => $postcodes,
					'note'      => $note,
				),
			);
		}
		$summary['unknown_wards'] = array_keys( $summary['unknown_wards'] );
		return array( 'rows' => $rows, 'summary' => $summary, 'new_names' => $names );
	}

	/** Same plan as the preview, but only the rows worth showing (changed, new or with a problem). */
	public static function import_preview( array $parsed ) {
		$plan = self::import_plan( $parsed );
		$show = array();
		foreach ( $plan['rows'] as $row ) {
			if ( $row['changes'] || $row['issues'] ) {
				$show[] = array_diff_key( $row, array( 'apply' => 1 ) );
			}
		}
		$plan['rows'] = array_slice( $show, 0, 400 );
		return $plan;
	}

	/** Saves the plan. New streets are only created when $add_new is true. */
	public static function import_commit( array $parsed, $add_new ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$plan   = self::import_plan( $parsed );
		$saved  = 0;
		$failed = 0;
		$left   = 0;
		foreach ( $plan['rows'] as $row ) {
			if ( ! $row['changes'] ) {
				continue;
			}
			if ( ! $row['street_id'] && ! $add_new ) {
				$left++;
				continue;
			}
			$result = self::save(
				array_merge( array( 'name' => $row['name'] ), $row['apply'] ),
				$row['street_id']
			);
			if ( is_wp_error( $result ) ) {
				$failed++;
			} else {
				$saved++;
			}
		}
		return array( 'saved' => $saved, 'failed' => $failed, 'left_out' => $left, 'summary' => $plan['summary'] );
	}

	/* ---------------------------------------------------------------- */
	/* Official Route test                                               */
	/* ---------------------------------------------------------------- */

	/** The streets as the planner wants them: ward names instead of ids. */
	private static function course_streets() {
		$names = array();
		foreach ( ATK_Nb_Store::neighbourhoods() as $id => $item ) {
			$names[ $id ] = $item['name'];
		}
		$out = array();
		foreach ( self::all() as $street ) {
			$out[] = array(
				'id'      => $street['id'],
				'name'    => $street['name'],
				'wards'   => array_values( array_filter( array_map( function ( $id ) use ( $names ) {
					return isset( $names[ $id ] ) ? $names[ $id ] : '';
				}, $street['wards'] ), 'strlen' ) ),
				'pairs'   => $street['pairs'],
				'flagged' => $street['flagged'],
			);
		}
		return $out;
	}

	/** Route weeks that already exist: ward key => array( id, week_number, neighbourhoods, questions ). Counts only the street-answer questions. */
	private static function existing_course_weeks() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, week_number, neighbourhoods FROM " . atk_table( 'course_weeks' ) . " WHERE course_type = 'route' ORDER BY sort_order ASC, id ASC" );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . atk_table( 'course_route_questions' ) . " WHERE week_id = %d AND street <> ''", $row->id ) );
			$key   = ATK_Route_Planner::ward_match_key( $row->neighbourhoods );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'id' => (int) $row->id, 'week_number' => (int) $row->week_number, 'neighbourhoods' => $row->neighbourhoods, 'questions' => $count );
			}
		}
		return $out;
	}

	/** What filling the Official Route test would do, week by week. Writes nothing. */
	public static function course_preview() {
		$plan     = ATK_Route_Planner::course_plan( self::course_streets(), ATK_Poi_Planner::OFFICIAL_WARDS );
		$existing = self::existing_course_weeks();
		foreach ( $plan['weeks'] as $i => $week ) {
			$have = isset( $existing[ ATK_Route_Planner::ward_match_key( $week['ward'] ) ] ) ? $existing[ ATK_Route_Planner::ward_match_key( $week['ward'] ) ] : null;
			if ( ! $week['questions'] ) {
				$state = 'nothing';
			} elseif ( ! $have ) {
				$state = 'new';
			} elseif ( 0 === $have['questions'] ) {
				$state = 'empty';
			} else {
				$state = 'filled';
			}
			$plan['weeks'][ $i ]['state']              = $state;
			$plan['weeks'][ $i ]['existing_week_id']   = $have ? $have['id'] : 0;
			$plan['weeks'][ $i ]['existing_questions'] = $have ? $have['questions'] : 0;
		}
		return $plan;
	}

	/**
	 * Creates the missing Route weeks and fills the empty ones. A week that already has
	 * street questions is left alone unless $replace is true; then only its street
	 * questions are replaced. Nothing else is ever deleted.
	 *
	 * @return array{weeks_created:int,weeks_filled:int,weeks_kept:int,weeks_replaced:int,questions:int}
	 */
	public static function course_apply( $replace ) {
		global $wpdb;
		$result = array( 'weeks_created' => 0, 'weeks_filled' => 0, 'weeks_kept' => 0, 'weeks_replaced' => 0, 'questions' => 0 );
		$names  = array();
		foreach ( ATK_Nb_Store::neighbourhoods() as $item ) {
			$names[ ATK_Route_Planner::ward_match_key( $item['name'] ) ] = $item['name'];
		}
		$plan  = self::course_preview();
		$order = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM " . atk_table( 'course_weeks' ) . " WHERE course_type = 'route'" );
		foreach ( $plan['weeks'] as $week ) {
			if ( 'nothing' === $week['state'] ) {
				continue;
			}
			if ( 'filled' === $week['state'] && ! $replace ) {
				$result['weeks_kept']++;
				continue;
			}
			$week_id = $week['existing_week_id'];
			if ( 'new' === $week['state'] ) {
				$key = ATK_Route_Planner::ward_match_key( $week['ward'] );
				$order++;
				$wpdb->insert( atk_table( 'course_weeks' ), array(
					'course_type'    => 'route',
					'week_number'    => $week['number'],
					'neighbourhoods' => isset( $names[ $key ] ) ? $names[ $key ] : $week['ward'],
					'sort_order'     => $order,
				) );
				$week_id = (int) $wpdb->insert_id;
				$result['weeks_created']++;
			} elseif ( 'filled' === $week['state'] ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM " . atk_table( 'course_route_questions' ) . " WHERE week_id = %d AND street <> ''", $week_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				$result['weeks_replaced']++;
			} else {
				$result['weeks_filled']++;
			}
			foreach ( $week['tests'] as $t => $test ) {
				foreach ( $test as $n => $question ) {
					$wpdb->insert( atk_table( 'course_route_questions' ), array(
						'week_id'       => $week_id,
						'test_number'   => $t + 1,
						'from_place'    => '',
						'to_place'      => '',
						'options'       => '[]',
						'correct_index' => 0,
						'street'        => $question['street'],
						'pairs'         => wp_json_encode( array_values( $question['pairs'] ) ),
						'sort_order'    => $n + 1,
					) );
					$result['questions']++;
				}
			}
		}
		return $result;
	}
}

/* -------------------------------------------------------------------- */
/* REST (atk/v1), instructors and administrators only                    */
/* -------------------------------------------------------------------- */

add_action( 'rest_api_init', 'atk_streets_register_routes' );
function atk_streets_register_routes() {
	$can = 'atk_nb_can_manage';

	register_rest_route( 'atk/v1', '/streets', array(
		array( 'methods' => 'GET', 'callback' => 'atk_streets_rest_list', 'permission_callback' => $can ),
		array( 'methods' => 'POST', 'callback' => 'atk_streets_rest_save', 'permission_callback' => $can ),
	) );
	register_rest_route( 'atk/v1', '/streets/import/preview', array(
		'methods' => 'POST', 'callback' => 'atk_streets_rest_import_preview', 'permission_callback' => $can,
	) );
	register_rest_route( 'atk/v1', '/streets/import/commit', array(
		'methods' => 'POST', 'callback' => 'atk_streets_rest_import_commit', 'permission_callback' => $can,
	) );
	register_rest_route( 'atk/v1', '/streets/(?P<id>\d+)', array(
		array( 'methods' => 'POST', 'callback' => 'atk_streets_rest_save', 'permission_callback' => $can ),
		array( 'methods' => 'DELETE', 'callback' => 'atk_streets_rest_delete', 'permission_callback' => $can ),
	) );
}

function atk_streets_rest_list() {
	return rest_ensure_response( ATK_Street_Store::screen() );
}

function atk_streets_rest_save( WP_REST_Request $request ) {
	$id     = (int) $request['id'];
	$params = $request->get_json_params();
	$params = is_array( $params ) ? $params : $request->get_params();
	$input  = array();
	foreach ( array( 'name', 'wards', 'postcodes', 'note', 'flagged', 'pairs' ) as $key ) {
		if ( array_key_exists( $key, $params ) ) {
			$input[ $key ] = $params[ $key ];
		}
	}
	if ( isset( $input['name'] ) ) {
		$input['name'] = sanitize_text_field( (string) $input['name'] );
	}
	$result = ATK_Street_Store::save( $input, $id );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
	}
	return rest_ensure_response( $result );
}

function atk_streets_rest_delete( WP_REST_Request $request ) {
	if ( ! ATK_Street_Store::remove( (int) $request['id'] ) ) {
		return new WP_Error( 'atk_street_missing', 'That street no longer exists.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'removed' => true ) );
}

/** Sheet rows arrive as arrays of cells, pasted text as a string. */
function atk_streets_read_input( WP_REST_Request $request ) {
	$raw = $request->get_param( 'rows' );
	if ( is_array( $raw ) ) {
		$table = array();
		foreach ( array_slice( $raw, 0, 6000 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cells = array();
			foreach ( array_slice( $row, 0, 14 ) as $cell ) {
				$cells[] = mb_substr( is_scalar( $cell ) ? (string) $cell : '', 0, 600 );
			}
			$table[] = $cells;
		}
		return ATK_Street_Parser::parse_rows( $table );
	}
	return array_slice( ATK_Street_Parser::parse_paste( mb_substr( (string) $request->get_param( 'text' ), 0, 600000 ) ), 0, 6000 );
}

function atk_streets_rest_import_preview( WP_REST_Request $request ) {
	return rest_ensure_response( ATK_Street_Store::import_preview( atk_streets_read_input( $request ) ) );
}

function atk_streets_rest_import_commit( WP_REST_Request $request ) {
	return rest_ensure_response( ATK_Street_Store::import_commit( atk_streets_read_input( $request ), (bool) $request->get_param( 'add_new' ) ) );
}

/* -------------------------------------------------------------------- */
/* Dashboard tab                                                        */
/* -------------------------------------------------------------------- */

function atk_render_instructor_streets_tab() {
	$path = '/assets/js/instructor-streets.js';
	wp_enqueue_script( 'atk-nb-xlsx' ); // SheetJS, bundled (registered in inc/assets.php).
	wp_enqueue_script( 'atk-instructor-streets', ATK_PLUGIN_URI . $path, array( 'atk-scripts-bundle', 'atk-nb-xlsx' ), filemtime( ATK_PLUGIN_DIR . $path ), true );
	wp_add_inline_script( 'atk-instructor-streets', 'var ATK_STREETS = ' . wp_json_encode( array(
		'restUrl'  => esc_url_raw( rest_url( 'atk/v1/' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'maxPairs' => ATK_Route_Pairs::MAX_PAIRS,
	) ) . ';', 'before' );
	?>
	<div id="atk-streets-root"><p class="text-gray-600">Loading streets&hellip;</p></div>
	<?php
}

/* -------------------------------------------------------------------- */
/* Course Builder: Fill the Official Route test                         */
/* -------------------------------------------------------------------- */

/** "40 streets, 8 in each of 5 tests" for the preview. */
function atk_route_course_per_test_text( $asked, array $tests ) {
	$sizes = array_map( 'count', $tests );
	if ( ! $sizes ) {
		return '';
	}
	$min = min( $sizes );
	$max = max( $sizes );
	return $min === $max ? sprintf( '%d in each of 5 tests', $max ) : sprintf( '%d to %d in each of 5 tests', $min, $max );
}

/**
 * The card at the top of the Course Builder's Route Test track: a preview of the 13 weeks
 * (one per Aberdeen City Council ward, in ward order) and the button that fills them.
 * Weeks that already hold questions are kept unless "rewrite" is ticked.
 */
function atk_render_instructor_route_course_card() {
	$plan    = ATK_Street_Store::course_preview();
	$streets = 0;
	foreach ( $plan['weeks'] as $week ) {
		$streets += count( $week['questions'] );
	}
	$badges = array(
		'new'     => array( 'badge-light-primary', 'New week' ),
		'empty'   => array( 'badge-light-warning', 'Empty week, will be filled' ),
		'nothing' => array( 'badge-light-secondary', 'No streets ready' ),
	);
	$streets_tab = add_query_arg( 'tab', 'streets', atk_get_page_url( 'instructor-dashboard' ) );
	?>
	<div class="card mb-6" id="atk-route-course-card">
		<div class="card-header">
			<h2 class="card-title fs-4">Fill the Official Route test from your streets</h2>
		</div>
		<div class="card-body">
			<p class="text-gray-600 mb-3">Each street is one question: the student types where it runs From and To. There is one week for each of the 13 wards of Aberdeen City Council, in the council's order; a ward with no ready street gets no week until it has one. A ward's ready streets are divided over its five tests, so every ready street is asked once and the tests are never cut to 20. A test lasts 20 minutes, or one minute a question when it has more than 20.</p>
			<p class="text-gray-600 mb-5">A street is ready when it has at least one From and To pair and is not marked <em>Needs clarification</em>. Type the pairs on the <a href="<?php echo esc_url( $streets_tab ); ?>">Streets tab</a>. The mockup Route Test is not changed.</p>

			<?php if ( isset( $_GET['route_fill'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="alert alert-success d-flex align-items-center p-5 mb-6"><span>
					<?php
					printf(
						'Done. %1$d weeks created, %2$d empty weeks filled, %3$d weeks rewritten, %4$d weeks kept as they were. %5$d questions saved.',
						isset( $_GET['fill_created'] ) ? (int) $_GET['fill_created'] : 0, // phpcs:ignore WordPress.Security.NonceVerification
						isset( $_GET['fill_filled'] ) ? (int) $_GET['fill_filled'] : 0, // phpcs:ignore WordPress.Security.NonceVerification
						isset( $_GET['fill_replaced'] ) ? (int) $_GET['fill_replaced'] : 0, // phpcs:ignore WordPress.Security.NonceVerification
						isset( $_GET['fill_kept'] ) ? (int) $_GET['fill_kept'] : 0, // phpcs:ignore WordPress.Security.NonceVerification
						isset( $_GET['fill_q'] ) ? (int) $_GET['fill_q'] : 0 // phpcs:ignore WordPress.Security.NonceVerification
					);
					?>
				</span></div>
			<?php endif; ?>

			<?php if ( 0 === $streets ) : ?>
				<div class="alert alert-warning d-flex align-items-center p-5 mb-6"><span>No street is ready yet. Type From and To pairs for the streets on the <a class="ms-1" href="<?php echo esc_url( $streets_tab ); ?>">Streets tab</a>, or import them there.</span></div>
			<?php endif; ?>

			<div class="table-responsive d-none d-md-block">
				<table class="table table-row-dashed align-middle mb-0">
					<thead>
						<tr class="text-gray-600 fw-bolder fs-7">
							<th>Week</th>
							<th>Ward</th>
							<th>Streets asked</th>
							<th>Test length</th>
							<th>Status</th>
							<th>Left out</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $plan['weeks'] as $week ) :
							$asked = count( $week['questions'] );
							$badge = 'filled' === $week['state'] ? array( 'badge-light-success', sprintf( 1 === (int) $week['existing_questions'] ? 'Has %d question, kept' : 'Has %d questions, kept', $week['existing_questions'] ) ) : $badges[ $week['state'] ];
							$left  = array();
							if ( $week['flagged'] ) {
								$left[] = sprintf( '%d need clarification', count( $week['flagged'] ) );
							}
							if ( $week['no_answer'] ) {
								$left[] = sprintf( '%d have no pair yet', count( $week['no_answer'] ) );
							}
							?>
							<tr>
								<td class="fw-bolder"><?php echo (int) $week['number']; ?></td>
								<td><?php echo esc_html( $week['ward'] ); ?></td>
								<td>
									<?php echo (int) $asked; ?>
									<?php if ( $asked ) : ?><span class="text-gray-600 fs-7">&middot; <?php echo esc_html( atk_route_course_per_test_text( $asked, $week['tests'] ) ); ?></span><?php endif; ?>
								</td>
								<td class="text-gray-700"><?php echo $asked ? esc_html( $week['minutes'] . ' minutes' ) : '&ndash;'; ?></td>
								<td><span class="badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span></td>
								<td class="text-gray-600 fs-7"><?php echo $left ? esc_html( implode( ', ', $left ) ) : '&ndash;'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="d-md-none" id="atk-route-course-stack">
				<?php foreach ( $plan['weeks'] as $week ) :
					$asked = count( $week['questions'] );
					$badge = 'filled' === $week['state'] ? array( 'badge-light-success', sprintf( 1 === (int) $week['existing_questions'] ? 'Has %d question, kept' : 'Has %d questions, kept', $week['existing_questions'] ) ) : $badges[ $week['state'] ];
					$left  = array();
					if ( $week['flagged'] ) {
						$left[] = sprintf( '%d need clarification', count( $week['flagged'] ) );
					}
					if ( $week['no_answer'] ) {
						$left[] = sprintf( '%d have no pair yet', count( $week['no_answer'] ) );
					}
					?>
					<div class="py-3 border-bottom">
						<div><span class="fw-bolder me-2"><?php echo (int) $week['number']; ?></span><?php echo esc_html( $week['ward'] ); ?></div>
						<div class="d-flex flex-wrap align-items-center gap-2 mt-2">
							<span class="badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
							<?php if ( $asked ) : ?><span class="text-gray-600 fs-7"><?php echo (int) $asked; ?> streets &middot; <?php echo esc_html( atk_route_course_per_test_text( $asked, $week['tests'] ) ); ?> &middot; <?php echo esc_html( $week['minutes'] ); ?> min</span><?php endif; ?>
						</div>
						<?php if ( $left ) : ?><div class="text-gray-600 fs-7 mt-1">Left out: <?php echo esc_html( implode( ', ', $left ) ); ?></div><?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $plan['outside'] ) : ?>
				<p class="text-gray-600 fs-7 mt-4 mb-0"><?php echo esc_html( count( $plan['outside'] ) ); ?> streets are in a ward whose name is not one of the 13 official wards, so they are not used, for example <?php echo esc_html( implode( ', ', array_slice( array_column( $plan['outside'], 'name' ), 0, 3 ) ) ); ?> (<?php echo esc_html( $plan['outside'][0]['ward'] ); ?>). Fix the ward name under Neighbourhoods if they belong in a week.</p>
			<?php endif; ?>
			<?php if ( $plan['no_ward'] ) : ?>
				<p class="text-gray-600 fs-7 mt-3 mb-0"><?php echo esc_html( count( $plan['no_ward'] ) ); ?> streets have no ward and are not used, for example <?php echo esc_html( implode( ', ', array_slice( $plan['no_ward'], 0, 3 ) ) ); ?>.</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" class="d-flex flex-wrap align-items-center gap-5 mt-6" id="atk-route-fill-form">
				<input type="hidden" name="action" value="atk_instructor_fill_route_course" />
				<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
				<button type="submit" class="btn btn-primary">Fill weeks 1 to 13</button>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="replace" value="1" id="atk-route-fill-replace" />
					<label class="form-check-label" for="atk-route-fill-replace">Also rewrite weeks that already have questions</label>
				</div>
			</form>
		</div>
	</div>
	<script>
		document.getElementById( 'atk-route-fill-form' ).addEventListener( 'submit', function ( e ) {
			if ( document.getElementById( 'atk-route-fill-replace' ).checked && ! window.confirm( 'This deletes the questions now in those weeks and replaces them. Continue?' ) ) {
				e.preventDefault();
			}
		} );
	</script>
	<?php
}

add_action( 'wp_ajax_atk_instructor_fill_route_course', 'atk_handle_instructor_fill_route_course' );
function atk_handle_instructor_fill_route_course() {
	atk_instructor_verify_request();
	$result = ATK_Street_Store::course_apply( ! empty( $_POST['replace'] ) );
	atk_instructor_redirect_back( array(
		'tab' => 'course', 'type' => 'route', 'route_fill' => 1,
		'fill_created' => $result['weeks_created'], 'fill_filled' => $result['weeks_filled'],
		'fill_replaced' => $result['weeks_replaced'], 'fill_kept' => $result['weeks_kept'], 'fill_q' => $result['questions'],
	) );
}
