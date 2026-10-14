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
		foreach ( self::plan_rows( $parsed_rows ) as $row ) {
			if ( 'skip' === $row['action'] ) {
				$result['skipped']++;
				continue;
			}
			$ward_id = $row['ward_id'];
			if ( null === $ward_id ) {
				$ward_id = 0;
				if ( in_array( $row['line'], array_map( 'intval', $create_wards ), true ) ) {
					$made = ATK_Nb_Store::ensure_neighbourhood( $row['ward'], $cache );
					if ( ! is_wp_error( $made ) ) {
						$ward_id = (int) $made[0];
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
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$key            = mb_strtolower( $row->name, 'UTF-8' );
			$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
		}
		$venues = array();
		foreach ( (array) $rows as $row ) {
			$repeat   = $counts[ mb_strtolower( $row->name, 'UTF-8' ) ] > 1;
			$venues[] = array(
				'name'     => $row->name . ( $repeat && '' !== $row->postcode ? ' (' . $row->postcode . ')' : '' ),
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

	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
