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
			'also_answers'  => self::split_streets( isset( $row->also_answers ) ? $row->also_answers : '' ),
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

	const MAX_ALSO = 6;

	/**
	 * Extra accepted streets: one per line (or an array). Tidied, deduped without
	 * regard to case or spacing, and the place's own street answer is dropped.
	 *
	 * @return string[]
	 */
	public static function split_streets( $value, $main = '' ) {
		$lines = is_array( $value ) ? $value : preg_split( '/\R/u', (string) $value );
		$seen  = array();
		if ( '' !== trim( (string) $main ) ) {
			$seen[ ATK_Nb_Parser::normalise_name( $main ) ] = true;
		}
		$out = array();
		foreach ( (array) $lines as $line ) {
			$line = self::tidy_text( $line, 255 );
			$key  = ATK_Nb_Parser::normalise_name( $line );
			if ( '' === $line || '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $line;
			if ( count( $out ) >= self::MAX_ALSO ) {
				break;
			}
		}
		return $out;
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
		if ( array_key_exists( 'also_answers', $fields ) || array_key_exists( 'street_answer', $fields ) ) {
			$main_street = array_key_exists( 'street_answer', $fields ) ? $data['street_answer'] : ( $current ? $current['street_answer'] : '' );
			$source      = array_key_exists( 'also_answers', $fields ) ? $fields['also_answers'] : ( $current ? $current['also_answers'] : '' );
			$data['also_answers'] = implode( "\n", self::split_streets( $source, $main_street ) );
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

	/* ---------------------------------------------------------------- */
	/* Official POI test: weeks 1 to 13 filled from the saved places     */
	/* ---------------------------------------------------------------- */

	/** @return array<string,array> research_key => array( street, also, source, note ), from assets/data/poi-research.json. */
	public static function course_research() {
		$file = ATK_PLUGIN_DIR . '/assets/data/poi-research.json';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		$out  = array();
		foreach ( is_array( $data ) && ! empty( $data['places'] ) ? $data['places'] : array() as $row ) {
			if ( empty( $row['name'] ) || empty( $row['street'] ) ) {
				continue;
			}
			$out[ ATK_Poi_Planner::research_key( $row['name'], isset( $row['postcode'] ) ? $row['postcode'] : '' ) ] = array(
				'street' => (string) $row['street'],
				'also'   => isset( $row['also'] ) ? (array) $row['also'] : array(),
				'only'   => ! empty( $row['only'] ),
				'source' => isset( $row['source'] ) ? (string) $row['source'] : '',
				'note'   => isset( $row['note'] ) ? (string) $row['note'] : '',
			);
		}
		return $out;
	}

	/** POI course weeks that already exist: ward key => array( id, week_number, neighbourhoods, questions ). Read straight from the database. */
	private static function existing_course_weeks() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, week_number, neighbourhoods FROM " . atk_table( 'course_weeks' ) . " WHERE course_type = 'poi' ORDER BY sort_order ASC, id ASC" );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . atk_table( 'course_poi_questions' ) . ' WHERE week_id = %d', $row->id ) );
			$key   = ATK_Poi_Parser::ward_key( $row->neighbourhoods );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'id' => (int) $row->id, 'week_number' => (int) $row->week_number, 'neighbourhoods' => $row->neighbourhoods, 'questions' => $count );
			}
		}
		return $out;
	}

	/**
	 * What filling the Official POI test would do, week by week. Writes nothing.
	 *
	 * @return array{weeks: array[], outside: array[]}
	 */
	public static function course_preview() {
		$wards  = self::wards();
		$places = array();
		foreach ( self::labelled() as $place ) {
			$places[] = array(
				'name' => $place['name'], 'label' => $place['label'], 'postcode' => $place['postcode'],
				'ward' => isset( $wards[ $place['ward_id'] ] ) ? $wards[ $place['ward_id'] ] : '',
				'street_answer' => $place['street_answer'], 'status' => $place['status'],
			);
		}
		$plan     = ATK_Poi_Planner::course_plan( $places, self::course_research() );
		$existing = self::existing_course_weeks();
		foreach ( $plan['weeks'] as $i => $week ) {
			$key = ATK_Poi_Parser::ward_key( $week['ward'] );
			$have = isset( $existing[ $key ] ) ? $existing[ $key ] : null;
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
	 * Creates the missing POI weeks and fills the empty ones. A week that already has
	 * questions is left alone unless $replace is true. Nothing else is ever deleted.
	 *
	 * @return array{weeks_created:int,weeks_filled:int,weeks_kept:int,weeks_replaced:int,questions:int}
	 */
	public static function course_apply( $replace ) {
		global $wpdb;
		$result = array( 'weeks_created' => 0, 'weeks_filled' => 0, 'weeks_kept' => 0, 'weeks_replaced' => 0, 'questions' => 0 );
		$wards  = self::wards();
		$names  = array();
		foreach ( $wards as $name ) {
			$names[ ATK_Poi_Parser::ward_key( $name ) ] = $name;
		}
		$plan  = self::course_preview();
		$order = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM " . atk_table( 'course_weeks' ) . " WHERE course_type = 'poi'" );
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
				$key   = ATK_Poi_Parser::ward_key( $week['ward'] );
				$order++;
				$wpdb->insert( atk_table( 'course_weeks' ), array(
					'course_type'    => 'poi',
					'week_number'    => $week['number'],
					'neighbourhoods' => isset( $names[ $key ] ) ? $names[ $key ] : $week['ward'],
					'sort_order'     => $order,
				) );
				$week_id = (int) $wpdb->insert_id;
				$result['weeks_created']++;
			} elseif ( 'filled' === $week['state'] ) {
				$wpdb->delete( atk_table( 'course_poi_questions' ), array( 'week_id' => $week_id ) );
				$result['weeks_replaced']++;
			} else {
				$result['weeks_filled']++;
			}
			foreach ( $week['tests'] as $t => $test ) {
				foreach ( $test as $n => $question ) {
					$wpdb->insert( atk_table( 'course_poi_questions' ), array(
						'week_id'     => $week_id,
						'test_number' => $t + 1,
						'poi'         => $question['poi'],
						'accepted'    => wp_json_encode( array_values( $question['accepted'] ) ),
						'sort_order'  => $n + 1,
					) );
					$result['questions']++;
				}
			}
		}
		return $result;
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
		'researched' => atk_poi_researched_for( $places ),
		'counts'     => array( 'total' => count( $places ), 'pinned' => $pinned, 'no_pin' => count( $places ) - $pinned ),
	);
}

/** What the researched-answers file already accepts for each saved place: id => array( street, also, only, source ). */
function atk_poi_researched_for( array $places ) {
	$research = ATK_Poi_Store::course_research();
	$out      = array();
	foreach ( $places as $place ) {
		$key = ATK_Poi_Planner::research_key( $place['name'], $place['postcode'] );
		if ( isset( $research[ $key ] ) ) {
			$out[ $place['id'] ] = array(
				'street' => $research[ $key ]['street'],
				'also'   => $research[ $key ]['also'],
				'only'   => $research[ $key ]['only'],
				'source' => $research[ $key ]['source'],
			);
		}
	}
	return (object) $out;
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
	if ( null !== $request->get_param( 'also_answers' ) ) {
		$raw = $request->get_param( 'also_answers' );
		$fields['also_answers'] = is_array( $raw ) ? array_map( 'sanitize_text_field', $raw ) : implode( "\n", array_map( 'sanitize_text_field', preg_split( '/\R/u', (string) $raw ) ) );
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


/* -------------------------------------------------------------------- */
/* Course Builder: fill the Official POI test (weeks 1 to 13) from the places */
/* -------------------------------------------------------------------- */

/** "all in each of 5 tests" when every place is in every test, otherwise "20 in each of 5 tests". */
function atk_poi_course_per_test_text( $asked, $per ) {
	return (int) $asked === (int) $per ? 'all in each of 5 tests' : sprintf( '%d in each of 5 tests', $per );
}

/**
 * The card at the top of the Course Builder's POI Test track: a preview of the 13
 * weeks (one per Aberdeen City Council ward, in ward order) and the button that
 * fills them. Weeks that already hold questions are kept unless "replace" is ticked.
 */
function atk_render_instructor_poi_course_card() {
	$plan     = ATK_Poi_Store::course_preview();
	$research = ATK_Poi_Store::course_research();
	$places   = 0;
	foreach ( $plan['weeks'] as $week ) {
		$places += count( $week['questions'] );
	}
	$badges = array(
		'new'     => array( 'badge-light-primary', 'New week' ),
		'empty'   => array( 'badge-light-warning', 'Empty week, will be filled' ),
		'nothing' => array( 'badge-light-secondary', 'No places yet' ),
	);
	?>
	<div class="card mb-6" id="atk-poi-course-card">
		<div class="card-header">
			<h2 class="card-title fs-4">Fill the Official POI test from your places</h2>
		</div>
		<div class="card-body">
			<p class="text-gray-600 mb-5">Week 1 is ward 1 of Aberdeen City Council, week 2 is ward 2, and so on up to week 13. Each week gets five tests built from that ward's current places that have a street answer. The street researched for a place comes first and the sheet's street is also accepted.</p>

			<?php if ( isset( $_GET['poi_fill'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
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

			<?php if ( 0 === $places ) : ?>
				<div class="alert alert-warning d-flex align-items-center p-5 mb-6"><span>No places have a ward and a street answer yet. Import the Places of Interest sheet under Study Tools first.</span></div>
			<?php endif; ?>

			<div class="table-responsive d-none d-md-block">
				<table class="table table-row-dashed align-middle mb-0">
					<thead>
						<tr class="text-gray-600 fw-bolder fs-7">
							<th>Week</th>
							<th>Ward</th>
							<th>Places asked</th>
							<th>Status</th>
							<th>Left out</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $plan['weeks'] as $week ) :
							$asked = count( $week['questions'] );
							$per   = isset( $week['tests'][0] ) ? count( $week['tests'][0] ) : 0;
							if ( 'filled' === $week['state'] ) {
								$badge = array( 'badge-light-success', sprintf( 'Has %d questions, kept', $week['existing_questions'] ) );
							} else {
								$badge = $badges[ $week['state'] ];
							}
							$left = array_merge( $week['no_answer'], $week['left_out'] );
							?>
							<tr>
								<td class="fw-bolder"><?php echo (int) $week['number']; ?></td>
								<td><?php echo esc_html( $week['ward'] ); ?></td>
								<td>
									<?php echo (int) $asked; ?>
									<?php if ( $asked ) : ?><span class="text-gray-600 fs-7">&middot; <?php echo esc_html( atk_poi_course_per_test_text( $asked, $per ) ); ?></span><?php endif; ?>
								</td>
								<td><span class="badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span></td>
								<td class="text-gray-600 fs-7">
									<?php
									if ( $left ) {
										$shown = array_slice( $left, 0, 3 );
										echo esc_html( count( $left ) . ': ' . implode( ', ', $shown ) . ( count( $left ) > 3 ? ' and more' : '' ) );
									} else {
										echo '&ndash;';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="d-md-none" id="atk-poi-course-stack">
				<?php foreach ( $plan['weeks'] as $week ) :
					$asked = count( $week['questions'] );
					$per   = isset( $week['tests'][0] ) ? count( $week['tests'][0] ) : 0;
					$badge = 'filled' === $week['state'] ? array( 'badge-light-success', sprintf( 'Has %d questions, kept', $week['existing_questions'] ) ) : $badges[ $week['state'] ];
					$left  = array_merge( $week['no_answer'], $week['left_out'] );
					?>
					<div class="py-3 border-bottom">
						<div><span class="fw-bolder me-2"><?php echo (int) $week['number']; ?></span><?php echo esc_html( $week['ward'] ); ?></div>
						<div class="d-flex flex-wrap align-items-center gap-2 mt-2">
							<span class="badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
							<?php if ( $asked ) : ?><span class="text-gray-600 fs-7"><?php echo (int) $asked; ?> places &middot; <?php echo esc_html( atk_poi_course_per_test_text( $asked, $per ) ); ?></span><?php endif; ?>
						</div>
						<?php if ( $left ) : ?><div class="text-gray-600 fs-7 mt-1">Left out: <?php echo esc_html( implode( ', ', array_slice( $left, 0, 3 ) ) . ( count( $left ) > 3 ? ' and more' : '' ) ); ?></div><?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $plan['outside'] ) : ?>
				<p class="text-gray-600 fs-7 mt-4 mb-0"><?php echo esc_html( count( $plan['outside'] ) ); ?> places are not in one of the 13 wards and are not used, for example <?php echo esc_html( implode( ', ', array_slice( array_column( $plan['outside'], 'name' ), 0, 3 ) ) ); ?>. Fix their ward under Study Tools if they belong in a week.</p>
			<?php endif; ?>

			<?php if ( $research ) : ?>
				<details class="mt-4">
					<summary class="text-gray-600 fs-7"><?php echo esc_html( count( $research ) ); ?> places have a researched street</summary>
					<ul class="fs-7 text-gray-600 mt-3 mb-0">
						<?php foreach ( $research as $item ) : ?>
							<li class="mb-2"><?php echo esc_html( $item['note'] ); ?><?php if ( $item['source'] ) : ?> <a href="<?php echo esc_url( $item['source'] ); ?>" target="_blank" rel="noopener">Source</a><?php endif; ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" class="d-flex flex-wrap align-items-center gap-5 mt-6" id="atk-poi-fill-form">
				<input type="hidden" name="action" value="atk_instructor_fill_poi_course" />
				<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
				<button type="submit" class="btn btn-primary">Fill weeks 1 to 13</button>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="replace" value="1" id="atk-poi-fill-replace" />
					<label class="form-check-label" for="atk-poi-fill-replace">Also rewrite weeks that already have questions</label>
				</div>
			</form>
		</div>
	</div>
	<script>
		document.getElementById( 'atk-poi-fill-form' ).addEventListener( 'submit', function ( e ) {
			if ( document.getElementById( 'atk-poi-fill-replace' ).checked && ! window.confirm( 'This deletes the questions now in those weeks and replaces them. Continue?' ) ) {
				e.preventDefault();
			}
		} );
	</script>
	<?php
}

add_action( 'wp_ajax_atk_instructor_fill_poi_course', 'atk_handle_instructor_fill_poi_course' );
function atk_handle_instructor_fill_poi_course() {
	atk_instructor_verify_request();
	$result = ATK_Poi_Store::course_apply( ! empty( $_POST['replace'] ) );
	atk_instructor_redirect_back( array(
		'tab' => 'course', 'type' => 'poi', 'poi_fill' => 1,
		'fill_created' => $result['weeks_created'], 'fill_filled' => $result['weeks_filled'],
		'fill_replaced' => $result['weeks_replaced'], 'fill_kept' => $result['weeks_kept'], 'fill_q' => $result['questions'],
	) );
}
