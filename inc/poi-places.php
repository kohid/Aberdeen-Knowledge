<?php
/**
 * Places of Interest: the instructor-managed list behind the public map and the
 * POI tests. Rules for reading a sheet live in inc/poi-places-engine.php (pure
 * PHP, tested without WordPress); this file stores places, serves them over REST
 * and draws the dashboard screen.
 *
 * A place sits in one ward (a Neighbourhoods record) or none. Its map pin comes
 * from an OpenStreetMap lookup the instructor's browser runs, or is placed by
 * hand, and is always checked against the Aberdeen area box here before it is
 * stored. Nothing is ever deleted from the older POI question tables.
 */

defined( 'ABSPATH' ) || exit;

class ATK_Poi_Store {

	const CACHE_KEY  = 'atk_poi_venues';
	const MAX_NAME   = 255;
	const MAX_TEXT   = 5000;
	const PIN_STATES = array( 'looked_up', 'manual', 'not_found' );

	private static function table() {
		return atk_table( 'poi_places' );
	}

	private static function to_place( $row ) {
		return array(
			'id'            => (int) $row->id,
			'name'          => (string) $row->name,
			'address'       => (string) $row->address,
			'postcode'      => (string) $row->postcode,
			'street_answer' => (string) $row->street_answer,
			'ward_id'       => (int) $row->neighbourhood_id,
			'category'      => (string) $row->category,
			'status'        => (string) $row->status,
			'notes'         => (string) $row->notes,
			'lat'           => null === $row->lat ? null : (float) $row->lat,
			'lng'           => null === $row->lng ? null : (float) $row->lng,
			'geo_state'     => (string) $row->geo_state,
		);
	}

	/** @return array[] Every place, in the order they were added or imported. */
	public static function all() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY sort_order ASC, id ASC' );
		return array_map( array( __CLASS__, 'to_place' ), (array) $rows );
	}

	/** @return array[] Every place, each with a `label`: its name, plus the postcode when another place shares the name. */
	public static function labelled() {
		$places = self::all();
		$labels = ATK_Poi_Planner::display_names( $places );
		foreach ( $places as $i => $place ) {
			$places[ $i ]['label'] = $labels[ $i ];
		}
		return $places;
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
		return $row ? self::to_place( $row ) : null;
	}

	/** @return array<int,string> ward id => name */
	public static function wards() {
		$out = array();
		foreach ( ATK_Nb_Store::neighbourhoods() as $id => $item ) {
			$out[ (int) $id ] = $item['name'];
		}
		return $out;
	}

	/**
	 * The places split into one section per ward, in the order the wards are taught
	 * (the Course Builder's weeks). Places with no ward, or a ward that no longer
	 * exists, go in a last section.
	 *
	 * @return array[] each array( 'ward_id' => int, 'ward' => string, 'places' => array[] )
	 */
	public static function sections() {
		$wards = self::wards();
		$texts = array();
		if ( function_exists( 'atk_course_weeks' ) ) {
			foreach ( array( 'poi', 'street' ) as $type ) {
				foreach ( atk_course_weeks( $type ) as $week ) {
					$texts[] = implode( ', ', $week['neighbourhoods'] );
				}
			}
		}
		$grouped = array();
		foreach ( self::all() as $place ) {
			$ward_id               = isset( $wards[ $place['ward_id'] ] ) ? $place['ward_id'] : 0;
			$grouped[ $ward_id ][] = $place;
		}
		$sections = array();
		foreach ( ATK_Poi_Planner::order_wards( $wards, $texts ) as $ward_id ) {
			if ( ! empty( $grouped[ $ward_id ] ) ) {
				$sections[] = array( 'ward_id' => $ward_id, 'ward' => $wards[ $ward_id ], 'places' => $grouped[ $ward_id ] );
			}
		}
		if ( ! empty( $grouped[0] ) ) {
			$sections[] = array( 'ward_id' => 0, 'ward' => 'No ward yet', 'places' => $grouped[0] );
		}
		return $sections;
	}

	private static function tidy_text( $value, $max ) {
		return mb_substr( trim( preg_replace( '/[ \t]+/u', ' ', (string) $value ) ), 0, $max, 'UTF-8' );
	}

	/**
	 * Creates a place (id 0) or updates the fields given. Only keys present in
	 * $fields change. Returns the saved place, or a WP_Error.
	 *
	 * @return array|WP_Error
	 */
	public static function save( array $fields, $id = 0 ) {
		global $wpdb;
		$id      = (int) $id;
		$current = $id ? self::get( $id ) : null;
		if ( $id && ! $current ) {
			return new WP_Error( 'atk_poi_missing', 'That place no longer exists.', array( 'status' => 404 ) );
		}

		$data = array();
		if ( array_key_exists( 'name', $fields ) || ! $id ) {
			$name = self::tidy_text( isset( $fields['name'] ) ? $fields['name'] : '', self::MAX_NAME );
			if ( '' === $name ) {
				return new WP_Error( 'atk_poi_name', 'A place needs a name.', array( 'status' => 400 ) );
			}
			$data['name'] = $name;
		}
		if ( array_key_exists( 'address', $fields ) ) {
			$data['address'] = self::tidy_text( $fields['address'], self::MAX_TEXT );
		}
		if ( array_key_exists( 'postcode', $fields ) ) {
			$raw              = trim( (string) $fields['postcode'] );
			$tidy             = ATK_Poi_Parser::tidy_postcode( $raw );
			$data['postcode'] = '' !== $tidy ? $tidy : mb_substr( mb_strtoupper( $raw, 'UTF-8' ), 0, 12, 'UTF-8' );
		}
		if ( array_key_exists( 'street_answer', $fields ) ) {
			$data['street_answer'] = self::tidy_text( $fields['street_answer'], 255 );
		}
		if ( array_key_exists( 'ward_id', $fields ) ) {
			$ward_id = (int) $fields['ward_id'];
			if ( $ward_id < 0 || ( $ward_id > 0 && ! isset( self::wards()[ $ward_id ] ) ) ) {
				return new WP_Error( 'atk_poi_ward', 'That ward does not exist.', array( 'status' => 400 ) );
			}
			$data['neighbourhood_id'] = $ward_id;
		}
		if ( array_key_exists( 'category', $fields ) || ! $id ) {
			$category         = isset( $fields['category'] ) ? $fields['category'] : '';
			$data['category'] = in_array( $category, ATK_Poi_Planner::CATEGORIES, true ) ? $category : ATK_Poi_Planner::DEFAULT_CATEGORY;
		}
		if ( array_key_exists( 'status', $fields ) || ! $id ) {
			$data['status'] = ( isset( $fields['status'] ) && 'closed' === $fields['status'] ) ? 'closed' : 'current';
		}
		if ( array_key_exists( 'notes', $fields ) ) {
			$data['notes'] = self::tidy_text( $fields['notes'], self::MAX_TEXT );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $current ) {
			$moved = ( isset( $data['address'] ) && $data['address'] !== $current['address'] )
				|| ( isset( $data['postcode'] ) && $data['postcode'] !== $current['postcode'] );
			if ( $moved && 'manual' !== $current['geo_state'] ) {
				$data['lat']       = null;
				$data['lng']       = null;
				$data['geo_state'] = 'none';
			}
			$data['updated_at'] = $now;
			$wpdb->update( self::table(), $data, array( 'id' => $id ) );
		} else {
			$data['sort_order'] = 1 + (int) $wpdb->get_var( 'SELECT MAX(sort_order) FROM ' . self::table() );
			$data['created_at'] = $now;
			$data['updated_at'] = $now;
			if ( ! $wpdb->insert( self::table(), $data ) ) {
				return new WP_Error( 'atk_poi_save', 'That place could not be saved.', array( 'status' => 500 ) );
			}
			$id = (int) $wpdb->insert_id;
		}
		self::flush_cache();
		return self::get( $id );
	}

	public static function delete( $id ) {
		global $wpdb;
		$deleted = (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		self::flush_cache();
		return $deleted;
	}

	/**
	 * Stores a pin. A hand-placed pin is never replaced by a lookup. Coordinates
	 * outside the Aberdeen area box are refused.
	 *
	 * @return array|WP_Error
	 */
	public static function set_pin( $id, $lat, $lng, $state ) {
		global $wpdb;
		$place = self::get( $id );
		if ( ! $place ) {
			return new WP_Error( 'atk_poi_missing', 'That place no longer exists.', array( 'status' => 404 ) );
		}
		if ( ! in_array( $state, self::PIN_STATES, true ) ) {
			return new WP_Error( 'atk_poi_state', 'Unknown pin state.', array( 'status' => 400 ) );
		}
		if ( 'not_found' === $state ) {
			if ( 'manual' === $place['geo_state'] ) {
				return $place;
			}
			$data = array( 'lat' => null, 'lng' => null, 'geo_state' => 'not_found' );
		} else {
			if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) || ! ATK_Poi_Geo::in_box( (float) $lat, (float) $lng ) ) {
				return new WP_Error( 'atk_poi_box', 'That position is outside the Aberdeen area.', array( 'status' => 400 ) );
			}
			if ( 'looked_up' === $state && 'manual' === $place['geo_state'] ) {
				return $place;
			}
			$data = array( 'lat' => round( (float) $lat, 6 ), 'lng' => round( (float) $lng, 6 ), 'geo_state' => $state );
		}
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		self::flush_cache();
		return self::get( $id );
	}

	/** Plans an import against what is saved. @param array[] $parsed_rows ATK_Poi_Parser rows. */
	public static function plan_rows( array $parsed_rows ) {
		$existing = array();
		foreach ( self::all() as $place ) {
			$existing[] = array( 'id' => $place['id'], 'name' => $place['name'], 'postcode' => $place['postcode'], 'street_answer' => $place['street_answer'] );
		}
		return ATK_Poi_Planner::plan( $parsed_rows, array(
			'wards'    => self::wards(),
			'existing' => $existing,
			'roads'    => ATK_Nb_Store::known_street_names(),
		) );
	}

	/**
	 * Saves an import. The plan is rebuilt here from the parsed rows; nothing the
	 * browser worked out is trusted. Blank sheet cells never erase saved values,
	 * and a ward that is not on the site yet is created only for the lines asked for.
	 *
	 * @param array[]           $parsed_rows
	 * @param int[]             $create_wards     Sheet lines whose new ward should be created.
	 * @param array<int,string> $street_overrides Sheet line => street answer typed in the review.
	 * @return array{added:int,updated:int,skipped:int,wards_created:int,lookups:array[]}
	 */
	public static function commit( array $parsed_rows, array $create_wards, array $street_overrides ) {
		$result = array( 'added' => 0, 'updated' => 0, 'skipped' => 0, 'wards_created' => 0, 'lookups' => array() );
		$cache  = null;
		$plan   = self::plan_rows( $parsed_rows );
		$asked  = array_map( 'intval', $create_wards );
		// Ticking one line of a new ward creates that ward for every line that names it.
		$approved = array();
		$made_for = array(); // ward key => id, so "A / B" and "A/B" share one new ward.
		foreach ( $plan as $row ) {
			if ( null === $row['ward_id'] && in_array( $row['line'], $asked, true ) ) {
				$approved[ ATK_Poi_Parser::ward_key( $row['ward'] ) ] = true;
			}
		}
		foreach ( $plan as $row ) {
			if ( 'skip' === $row['action'] ) {
				$result['skipped']++;
				continue;
			}
			$ward_id = $row['ward_id'];
			if ( null === $ward_id ) {
				$ward_id = 0;
				$ward_key = ATK_Poi_Parser::ward_key( $row['ward'] );
				if ( isset( $made_for[ $ward_key ] ) ) {
					$ward_id = $made_for[ $ward_key ];
				} elseif ( isset( $approved[ $ward_key ] ) ) {
					$made = ATK_Nb_Store::ensure_neighbourhood( $row['ward'], $cache );
					if ( ! is_wp_error( $made ) ) {
						$ward_id = (int) $made[0];
						$made_for[ $ward_key ] = $ward_id;
						if ( $made[1] ) {
							$result['wards_created']++;
						}
					}
				}
			}
			$street   = $row['street_answer'];
			$override = isset( $street_overrides[ $row['line'] ] ) ? trim( (string) $street_overrides[ $row['line'] ] ) : '';
			if ( '' !== $override ) {
				$street = $override;
			} elseif ( 'update' === $row['action'] ) {
				// A street already saved (possibly typed in by an instructor) is not
				// replaced by the sheet's automatic match.
				$saved_place = self::get( $row['existing_id'] );
				if ( $saved_place && '' !== $saved_place['street_answer'] ) {
					$street = '';
				}
			}

			if ( 'add' === $row['action'] ) {
				$saved = self::save( array(
					'name' => $row['name'], 'address' => $row['address'], 'postcode' => $row['postcode'], 'street_answer' => $street,
					'ward_id' => $ward_id, 'category' => $row['category'], 'status' => $row['status'], 'notes' => $row['note'],
				) );
				$result['added']++;
			} else {
				$fields = array();
				foreach ( array( 'address' => $row['address'], 'postcode' => $row['postcode'], 'notes' => $row['note'], 'street_answer' => $street ) as $key => $value ) {
					if ( '' !== trim( (string) $value ) ) {
						$fields[ $key ] = $value;
					}
				}
				if ( $ward_id > 0 ) {
					$fields['ward_id'] = $ward_id;
				}
				if ( $row['category_given'] ) {
					$fields['category'] = $row['category'];
				}
				if ( $row['status_given'] ) {
					$fields['status'] = $row['status'];
				}
				$saved = self::save( $fields, $row['existing_id'] );
				$result['updated']++;
			}
			if ( is_array( $saved ) && 'none' === $saved['geo_state'] && null === $saved['lat'] ) {
				$result['lookups'][] = array( 'id' => $saved['id'], 'name' => $saved['name'], 'address' => $saved['address'], 'postcode' => $saved['postcode'] );
			}
		}
		return $result;
	}

	/**
	 * The pinned places for the public map. A name used by more than one place gets
	 * its postcode added, because the map tells places apart by name.
	 *
	 * @return array[] each array( name, category, status, address, lat, lng )
	 */
	public static function venues() {
		global $wpdb;
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$rows   = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE lat IS NOT NULL AND lng IS NOT NULL ORDER BY sort_order ASC, id ASC' );
		$rows   = (array) $rows;
		$names  = ATK_Poi_Planner::display_names( array_map( function ( $row ) {
			return array( 'name' => $row->name, 'postcode' => (string) $row->postcode );
		}, $rows ) );
		$venues = array();
		foreach ( $rows as $i => $row ) {
			$venues[] = array(
				'name'     => $names[ $i ],
				'category' => $row->category,
				'status'   => $row->status,
				'address'  => (string) $row->address,
				'lat'      => (float) $row->lat,
				'lng'      => (float) $row->lng,
			);
		}
		set_transient( self::CACHE_KEY, $venues, 12 * HOUR_IN_SECONDS );
		return $venues;
	}

	/**
	 * For the Course Builder: the places in each week's wards, by week id, so the
	 * POI question form can offer them. Only weeks whose wards hold places appear.
	 *
	 * @param array[] $weeks Output of atk_course_weeks().
	 * @return array<int,array<int,array{name:string,street:string}>>
	 */
	public static function week_places( array $weeks ) {
		$by_ward = array();
		foreach ( self::labelled() as $place ) {
			$by_ward[ $place['ward_id'] ][] = array( 'name' => $place['label'], 'street' => $place['street_answer'] );
		}
		$out = array();
		foreach ( $weeks as $week ) {
			$list = array();
			foreach ( ATK_Nb_Store::ids_in_text( implode( ', ', $week['neighbourhoods'] ) ) as $ward_id ) {
				if ( ! empty( $by_ward[ $ward_id ] ) ) {
					$list = array_merge( $list, $by_ward[ $ward_id ] );
				}
			}
			if ( $list ) {
				$out[ (int) $week['id'] ] = $list;
			}
		}
		return $out;
	}

	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
		// LiteSpeed Cache keeps REST answers on its own clock (7 days by default).
		// Does nothing on a site without it.
		do_action( 'litespeed_purge_url', rest_url( 'atk/v1/poi-venues' ) );
	}
}

/* -------------------------------------------------------------------- */
/* REST (atk/v1): instructors manage places, anyone reads the pinned ones */
/* -------------------------------------------------------------------- */

add_action( 'rest_api_init', 'atk_poi_register_routes' );
function atk_poi_register_routes() {
	$can = 'atk_nb_can_manage';

	register_rest_route( 'atk/v1', '/poi-places', array(
		array( 'methods' => 'GET', 'callback' => 'atk_poi_rest_list', 'permission_callback' => $can ),
		array( 'methods' => 'POST', 'callback' => 'atk_poi_rest_save', 'permission_callback' => $can ),
	) );
	register_rest_route( 'atk/v1', '/poi-places/preview', array(
		'methods' => 'POST', 'callback' => 'atk_poi_rest_preview', 'permission_callback' => $can,
	) );
	register_rest_route( 'atk/v1', '/poi-places/commit', array(
		'methods' => 'POST', 'callback' => 'atk_poi_rest_commit', 'permission_callback' => $can,
	) );
	register_rest_route( 'atk/v1', '/poi-places/(?P<id>\d+)', array(
		array( 'methods' => 'POST', 'callback' => 'atk_poi_rest_save', 'permission_callback' => $can ),
		array( 'methods' => 'DELETE', 'callback' => 'atk_poi_rest_delete', 'permission_callback' => $can ),
	) );
	register_rest_route( 'atk/v1', '/poi-places/(?P<id>\d+)/pin', array(
		'methods' => 'POST', 'callback' => 'atk_poi_rest_pin', 'permission_callback' => $can,
	) );
	register_rest_route( 'atk/v1', '/poi-venues', array(
		'methods' => 'GET', 'callback' => 'atk_poi_rest_venues', 'permission_callback' => '__return_true',
	) );
}

/** The list the dashboard screen draws: sections by ward plus what the filters and forms need. */
function atk_poi_screen_data() {
	$places = ATK_Poi_Store::all();
	$pinned = 0;
	foreach ( $places as $place ) {
		if ( null !== $place['lat'] ) {
			$pinned++;
		}
	}
	$wards = array();
	foreach ( ATK_Poi_Store::wards() as $id => $name ) {
		$wards[] = array( 'id' => $id, 'name' => $name );
	}
	return array(
		'sections'   => ATK_Poi_Store::sections(),
		'wards'      => $wards,
		'categories' => ATK_Poi_Planner::CATEGORIES,
		'counts'     => array( 'total' => count( $places ), 'pinned' => $pinned, 'no_pin' => count( $places ) - $pinned ),
	);
}

function atk_poi_rest_list() {
	return rest_ensure_response( atk_poi_screen_data() );
}

/** Creates (no id) or updates (id in the route). Only the fields sent change. */
function atk_poi_rest_save( WP_REST_Request $request ) {
	$fields = array();
	foreach ( array( 'name', 'postcode', 'street_answer', 'category', 'status' ) as $key ) {
		if ( null !== $request->get_param( $key ) ) {
			$fields[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) );
		}
	}
	foreach ( array( 'address', 'notes' ) as $key ) {
		if ( null !== $request->get_param( $key ) ) {
			$fields[ $key ] = sanitize_textarea_field( (string) $request->get_param( $key ) );
		}
	}
	if ( null !== $request->get_param( 'ward_id' ) ) {
		$fields['ward_id'] = (int) $request->get_param( 'ward_id' );
	}
	$saved = ATK_Poi_Store::save( $fields, (int) $request['id'] );
	return is_wp_error( $saved ) ? $saved : rest_ensure_response( $saved );
}

function atk_poi_rest_delete( WP_REST_Request $request ) {
	if ( ! ATK_Poi_Store::delete( (int) $request['id'] ) ) {
		return new WP_Error( 'atk_poi_missing', 'That place no longer exists.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'deleted' => true ) );
}

function atk_poi_rest_pin( WP_REST_Request $request ) {
	$saved = ATK_Poi_Store::set_pin(
		(int) $request['id'],
		$request->get_param( 'lat' ),
		$request->get_param( 'lng' ),
		(string) $request->get_param( 'state' )
	);
	return is_wp_error( $saved ) ? $saved : rest_ensure_response( $saved );
}

function atk_poi_rest_preview( WP_REST_Request $request ) {
	$input  = atk_poi_read_input( $request );
	$rows   = $input['error'] ? array() : ATK_Poi_Store::plan_rows( $input['rows'] );
	$counts = array( 'add' => 0, 'update' => 0, 'skip' => 0, 'needs_street' => 0, 'new_ward' => 0 );
	foreach ( $rows as $row ) {
		$counts[ $row['action'] ]++;
		foreach ( array( 'needs_street', 'new_ward' ) as $issue ) {
			if ( in_array( $issue, $row['issues'], true ) ) {
				$counts[ $issue ]++;
			}
		}
	}
	return rest_ensure_response( array( 'error' => $input['error'], 'rows' => $rows, 'counts' => $counts ) );
}

function atk_poi_rest_commit( WP_REST_Request $request ) {
	$input = atk_poi_read_input( $request );
	if ( $input['error'] ) {
		return new WP_Error( 'atk_poi_sheet', $input['error'], array( 'status' => 400 ) );
	}
	$create = array();
	foreach ( array_slice( (array) $request->get_param( 'create_wards' ), 0, 5000 ) as $line ) {
		$create[] = (int) $line;
	}
	$overrides = array();
	$given     = $request->get_param( 'street_overrides' );
	if ( is_array( $given ) ) {
		foreach ( array_slice( $given, 0, 5000, true ) as $line => $street ) {
			$overrides[ (int) $line ] = mb_substr( sanitize_text_field( is_scalar( $street ) ? (string) $street : '' ), 0, 255 );
		}
	}
	return rest_ensure_response( ATK_Poi_Store::commit( $input['rows'], $create, $overrides ) );
}

/** Public: the pinned places for the map. Short cache so an import shows within a minute. */
function atk_poi_rest_venues() {
	$response = rest_ensure_response( array( 'venues' => ATK_Poi_Store::venues() ) );
	$response->header( 'Cache-Control', 'public, max-age=60' );
	// LiteSpeed Cache reads this instead of its 7-day REST default.
	$response->header( 'X-LiteSpeed-Cache-Control', 'public,max-age=60' );
	return $response;
}

/**
 * Turns the request into parsed place rows. Sheet rows arrive as arrays (capped at
 * 5000 rows by 12 cells of 500 characters), pasted text as tab-separated lines.
 *
 * @return array{rows:array[],error:?string}
 */
function atk_poi_read_input( WP_REST_Request $request ) {
	$raw = $request->get_param( 'rows' );
	if ( is_array( $raw ) ) {
		$table = array();
		foreach ( array_slice( $raw, 0, 5000 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cells = array();
			foreach ( array_slice( $row, 0, 12 ) as $cell ) {
				$cells[] = mb_substr( is_scalar( $cell ) ? (string) $cell : '', 0, 500 );
			}
			$table[] = $cells;
		}
	} else {
		$table = array();
		$text  = mb_substr( (string) $request->get_param( 'text' ), 0, 400000 );
		foreach ( array_slice( preg_split( '/\r\n|\r|\n/', $text ), 0, 5000 ) as $line ) {
			$cells = array();
			foreach ( array_slice( str_getcsv( $line, "\t", '"', '' ), 0, 12 ) as $cell ) {
				$cells[] = mb_substr( (string) $cell, 0, 500 );
			}
			$table[] = $cells;
		}
	}
	return ATK_Poi_Parser::parse_rows( $table );
}

/* -------------------------------------------------------------------- */
/* Dashboard screen (Study Tools > Places of Interest)                   */
/* -------------------------------------------------------------------- */

/** The REST address, nonce and category list the screen's script needs. */
function atk_poi_script_config() {
	return 'var ATK_POI_ADMIN = ' . wp_json_encode( array(
		'restUrl'    => esc_url_raw( rest_url( 'atk/v1/' ) ),
		'nonce'      => wp_create_nonce( 'wp_rest' ),
		'categories' => ATK_Poi_Planner::CATEGORIES,
	) ) . ';';
}

function atk_render_instructor_poi_places_tab() {
	$path = '/assets/js/instructor-poi-places.js';
	wp_enqueue_script( 'atk-nb-xlsx' ); // SheetJS, bundled (registered in inc/assets.php).
	atk_enqueue_leaflet();
	wp_enqueue_script( 'atk-poi-geo', ATK_PLUGIN_URI . '/assets/js/poi-geo.js', array(), filemtime( ATK_PLUGIN_DIR . '/assets/js/poi-geo.js' ), true );
	wp_enqueue_script( 'atk-instructor-poi-places', ATK_PLUGIN_URI . $path, array( 'atk-scripts-bundle', 'atk-nb-xlsx', 'leaflet', 'atk-poi-geo' ), filemtime( ATK_PLUGIN_DIR . $path ), true );
	wp_add_inline_script( 'atk-instructor-poi-places', atk_poi_script_config(), 'before' );
	?>
	<div id="atk-poi-admin-root"><p class="text-gray-600">Loading places of interest&hellip;</p></div>
	<?php
}
