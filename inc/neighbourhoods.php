<?php
/**
 * Neighbourhoods: instructors group streets into ward-based neighbourhoods
 * (a street can sit in two wards, e.g. ALBURY LANE), then pick those
 * neighbourhoods in the Course Builder.
 *
 * - Tab: "Neighbourhoods" on the Instructor Dashboard (rendered by
 *   atk_render_instructor_neighbourhoods_tab(), driven by
 *   assets/js/instructor-neighbourhoods.js over the atk/v1 REST routes below).
 * - Input: pasted street names, or a pasted/imported sheet with the columns
 *   Road, Post Code, Address, Ward, Note. Every import is reviewed before it
 *   is saved. Parsing, matching and change rules are in
 *   inc/neighbourhoods-engine.php (pure PHP, tested in tests/neighbourhoods/).
 * - Storage: inc/db-schema.php tables atk_nb_neighbourhoods, atk_nb_streets
 *   (a registry of the site's known street names plus instructor-only
 *   postcodes and notes) and atk_nb_links (street to neighbourhood).
 * - Course Builder: a week still stores its neighbourhoods as plain names
 *   (course_weeks.neighbourhoods), so nothing that reads weeks changes. The
 *   names are looked up here to count a week's streets (a street in two of
 *   its neighbourhoods counts once) and to narrow the question street pickers.
 */

defined( 'ABSPATH' ) || exit;

require_once ATK_PLUGIN_DIR . '/inc/neighbourhoods-engine.php';

/* -------------------------------------------------------------------- */
/* Storage                                                              */
/* -------------------------------------------------------------------- */

class ATK_Nb_Store {

	const MAX_NAME = 191;

	/** Positive, unique integers only. */
	public static function clean_ids( $values ) {
		$ids = array();
		foreach ( (array) $values as $value ) {
			$id = is_numeric( $value ) ? (int) $value : 0;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		return array_values( $ids );
	}

	/** A stable colour for each neighbourhood, used everywhere it appears. */
	public static function hue( $name ) {
		return abs( crc32( mb_strtolower( (string) $name, 'UTF-8' ) ) ) % 360;
	}

	private static function lower( $text ) {
		return mb_strtolower( (string) $text, 'UTF-8' );
	}

	/**
	 * Every street the site already knows: the real Aberdeen master list,
	 * instructor-added and renamed roads from Major Streets, and any road
	 * already used as a question endpoint. Accepted-answer spellings are left
	 * out on purpose, so "Union St" never becomes its own street.
	 *
	 * @return string[]
	 */
	public static function known_street_names() {
		global $wpdb;
		$seen = array();
		foreach ( atk_master_road_names() as $name ) {
			$seen[ $name ] = true;
		}
		$queries = array(
			'SELECT name FROM ' . atk_table( 'road_additions' ),
			'SELECT new_name FROM ' . atk_table( 'road_overrides' ) . " WHERE new_name IS NOT NULL AND new_name <> ''",
			'SELECT road_a FROM ' . atk_table( 'course_questions' ),
			'SELECT road_b FROM ' . atk_table( 'course_questions' ),
			'SELECT road_a FROM ' . atk_table( 'quiz_street_questions' ),
			'SELECT road_b FROM ' . atk_table( 'quiz_street_questions' ),
		);
		foreach ( $queries as $sql ) {
			foreach ( (array) $wpdb->get_col( $sql ) as $name ) {
				$name = trim( (string) $name );
				if ( '' !== $name ) {
					$seen[ $name ] = true;
				}
			}
		}
		foreach ( array_keys( $seen ) as $name ) {
			if ( mb_strlen( $name, 'UTF-8' ) > self::MAX_NAME ) {
				unset( $seen[ $name ] );
			}
		}
		return array_keys( $seen );
	}

	/** Makes sure every known street has a registry row. Cheap when nothing changed. */
	public static function sync_streets() {
		global $wpdb;
		$names = self::known_street_names();
		$sig   = count( $names ) . ':' . md5( implode( "\n", $names ) );
		if ( get_option( 'atk_nb_streets_sig' ) === $sig ) {
			return;
		}
		$table = atk_table( 'nb_streets' );
		foreach ( array_chunk( $names, 400 ) as $chunk ) {
			$marks = implode( ',', array_fill( 0, count( $chunk ), '(%s)' ) );
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (name) VALUES $marks", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		update_option( 'atk_nb_streets_sig', $sig, false );
	}

	/** Every name in the registry, including streets instructors added from the Neighbourhoods tab. */
	public static function registry_names() {
		global $wpdb;
		$table = atk_table( 'nb_streets' );
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT name FROM $table" ) );
	}

	/**
	 * The name a new street is saved under. A sheet in capitals ("ALBURY LANE")
	 * becomes "Albury Lane", matching how the rest of the site writes street names.
	 */
	public static function new_street_name( $road ) {
		$name = trim( preg_replace( '/\s+/', ' ', (string) $road ) );
		if ( '' === $name || mb_strlen( $name, 'UTF-8' ) > self::MAX_NAME ) {
			return '';
		}
		if ( $name === mb_strtoupper( $name, 'UTF-8' ) && $name !== mb_strtolower( $name, 'UTF-8' ) ) {
			$name = mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' );
		}
		return $name;
	}

	/** Finds a street by name or adds it to the site's street list. Returns its id, or 0. */
	public static function ensure_street( $name ) {
		global $wpdb;
		$name  = self::new_street_name( $name );
		$table = atk_table( 'nb_streets' );
		if ( '' === $name ) {
			return 0;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (name) VALUES (%s)", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @return array<int,array{id:int,name:string,hue:int}> keyed by id */
	public static function neighbourhoods() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . atk_table( 'nb_neighbourhoods' ) . ' ORDER BY name ASC' ) as $row ) {
			$out[ (int) $row->id ] = array( 'id' => (int) $row->id, 'name' => $row->name, 'hue' => self::hue( $row->name ) );
		}
		return $out;
	}

	/** @return string[] neighbourhood names, for the Course Builder selectors */
	public static function neighbourhood_names() {
		return array_values( array_map( function ( $n ) {
			return $n['name'];
		}, self::neighbourhoods() ) );
	}

	/** @return array<int,string> street id => name */
	public static function street_titles() {
		global $wpdb;
		self::sync_streets();
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . atk_table( 'nb_streets' ) ) as $row ) {
			$out[ (int) $row->id ] = $row->name;
		}
		return $out;
	}

	/** @return array<int,int[]> street id => neighbourhood ids (only streets that have one) */
	public static function street_link_map() {
		global $wpdb;
		$map = array();
		foreach ( (array) $wpdb->get_results( 'SELECT street_id, neighbourhood_id FROM ' . atk_table( 'nb_links' ) ) as $row ) {
			$map[ (int) $row->street_id ][] = (int) $row->neighbourhood_id;
		}
		return $map;
	}

	private static function decode_postcodes( $json ) {
		$list = json_decode( (string) $json, true );
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
	}

	/** @return array<int,array{outcode:string,postcodes:string[],note:string}> only streets that have any */
	public static function meta_all() {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results(
			'SELECT id, outcode, postcodes, note FROM ' . atk_table( 'nb_streets' ) .
			" WHERE outcode <> '' OR postcodes IS NOT NULL OR note IS NOT NULL"
		);
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->id ] = array(
				'outcode'   => (string) $row->outcode,
				'postcodes' => self::decode_postcodes( $row->postcodes ),
				'note'      => (string) $row->note,
			);
		}
		return $out;
	}

	/** Neighbourhoods with how many streets each holds and how many of those are also in another one. */
	public static function list_neighbourhoods() {
		global $wpdb;
		$links  = atk_table( 'nb_links' );
		$count  = array();
		$shared = array();
		foreach ( (array) $wpdb->get_results( "SELECT neighbourhood_id, COUNT(*) AS c FROM $links GROUP BY neighbourhood_id" ) as $row ) {
			$count[ (int) $row->neighbourhood_id ] = (int) $row->c;
		}
		$rows = $wpdb->get_results(
			"SELECT l.neighbourhood_id, COUNT(*) AS c FROM $links l
			INNER JOIN ( SELECT street_id FROM $links GROUP BY street_id HAVING COUNT(*) > 1 ) s ON s.street_id = l.street_id
			GROUP BY l.neighbourhood_id"
		);
		foreach ( (array) $rows as $row ) {
			$shared[ (int) $row->neighbourhood_id ] = (int) $row->c;
		}
		$out = array();
		foreach ( self::neighbourhoods() as $id => $item ) {
			$item['count']  = isset( $count[ $id ] ) ? $count[ $id ] : 0;
			$item['shared'] = isset( $shared[ $id ] ) ? $shared[ $id ] : 0;
			$out[]          = $item;
		}
		return $out;
	}

	public static function streets_in_neighbourhood( $neighbourhood_id ) {
		global $wpdb;
		$neighbourhood_id = (int) $neighbourhood_id;
		$all              = self::neighbourhoods();
		$ids              = array();
		$links            = atk_table( 'nb_links' );
		$street_ids       = $wpdb->get_col( $wpdb->prepare( "SELECT street_id FROM $links WHERE neighbourhood_id = %d", $neighbourhood_id ) );
		foreach ( (array) $street_ids as $id ) {
			$ids[] = (int) $id;
		}
		if ( ! $ids ) {
			return array();
		}
		$titles = self::street_titles();
		$map    = self::street_link_map();
		$meta   = self::meta_all();
		$out    = array();
		foreach ( $ids as $street_id ) {
			if ( ! isset( $titles[ $street_id ] ) ) {
				continue;
			}
			$also = array();
			$linked = isset( $map[ $street_id ] ) ? $map[ $street_id ] : array();
			foreach ( $linked as $other ) {
				if ( $other !== $neighbourhood_id && isset( $all[ $other ] ) ) {
					$also[] = $all[ $other ];
				}
			}
			$m     = isset( $meta[ $street_id ] ) ? $meta[ $street_id ] : array( 'outcode' => '', 'postcodes' => array(), 'note' => '' );
			$out[] = array(
				'id'        => $street_id,
				'title'     => $titles[ $street_id ],
				'outcode'   => $m['outcode'],
				'postcodes' => $m['postcodes'],
				'note'      => $m['note'],
				'also'      => $also,
			);
		}
		usort( $out, function ( $a, $b ) {
			return strnatcasecmp( $a['title'], $b['title'] );
		} );
		return $out;
	}

	/** Finds a neighbourhood by name, ignoring case, or creates it. Returns [id, created] or WP_Error. */
	public static function ensure_neighbourhood( $name, &$cache = null ) {
		global $wpdb;
		$name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'atk_nb_empty', 'A neighbourhood needs a name.' );
		}
		if ( mb_strlen( $name, 'UTF-8' ) > self::MAX_NAME ) {
			return new WP_Error( 'atk_nb_long', 'That neighbourhood name is too long.' );
		}
		if ( null === $cache ) {
			$cache = array();
		}
		if ( ! $cache ) {
			foreach ( self::neighbourhoods() as $item ) {
				$cache[ self::lower( $item['name'] ) ] = $item['id'];
			}
		}
		$key = self::lower( $name );
		if ( isset( $cache[ $key ] ) ) {
			return array( $cache[ $key ], false );
		}
		$ok = $wpdb->insert( atk_table( 'nb_neighbourhoods' ), array( 'name' => $name ) );
		if ( ! $ok ) {
			return new WP_Error( 'atk_nb_create', 'That neighbourhood could not be created.' );
		}
		$cache[ $key ] = (int) $wpdb->insert_id;
		return array( $cache[ $key ], true );
	}

	public static function remove_street( $neighbourhood_id, $street_id ) {
		global $wpdb;
		return false !== $wpdb->delete( atk_table( 'nb_links' ), array( 'neighbourhood_id' => (int) $neighbourhood_id, 'street_id' => (int) $street_id ), array( '%d', '%d' ) );
	}

	/** Deletes a neighbourhood and its links. The streets themselves stay. */
	public static function delete_neighbourhood( $neighbourhood_id ) {
		global $wpdb;
		$neighbourhood_id = (int) $neighbourhood_id;
		$wpdb->delete( atk_table( 'nb_links' ), array( 'neighbourhood_id' => $neighbourhood_id ), array( '%d' ) );
		return (bool) $wpdb->delete( atk_table( 'nb_neighbourhoods' ), array( 'id' => $neighbourhood_id ), array( '%d' ) );
	}

	/** What would change if these rows were saved. Nothing is written. */
	public static function preview( array $rows, $target = 0, array $choices = array(), array $additions = array() ) {
		$titles = self::street_titles();

		// Rows the instructor chose to add as new streets get a temporary
		// negative id here; commit() turns it into a real street.
		$new_count = 0;
		if ( $additions ) {
			$wanted  = array_flip( array_map( 'intval', $additions ) );
			$matcher = new ATK_Nb_Matcher( $titles );
			$temp    = 0;
			foreach ( $rows as $row ) {
				if ( ! isset( $wanted[ $row['line'] ] ) || 'unmatched' !== $matcher->match( $row['road'] )['status'] ) {
					continue;
				}
				$name = self::new_street_name( $row['road'] );
				if ( '' !== $name && ! in_array( $name, $titles, true ) ) {
					$titles[ --$temp ] = $name;
					$new_count++;
				}
			}
		}

		$plan = ATK_Nb_Planner::plan( $rows, array(
			'titles'       => $titles,
			'terms'        => self::neighbourhoods(),
			'street_terms' => self::street_link_map(),
			'meta'         => self::meta_all(),
			'target'       => (int) $target,
			'choices'      => $choices,
			'hue'          => array( __CLASS__, 'hue' ),
		) );

		foreach ( $plan['rows'] as $i => $row ) {
			$plan['rows'][ $i ]['new_street'] = $row['street_id'] < 0;
		}
		$plan['summary']['new_streets'] = $new_count;
		return $plan;
	}

	/** Saves every row the preview marks as ready. The plan is rebuilt here, never trusted from the browser. */
	public static function commit( array $rows, $target = 0, array $choices = array(), array $additions = array() ) {
		global $wpdb;
		$plan = self::preview( $rows, $target, $choices, $additions );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$links_table   = atk_table( 'nb_links' );
		$streets_table = atk_table( 'nb_streets' );
		$cache         = array();
		$saved         = 0;
		$failed        = 0;
		$created       = array();

		foreach ( $plan['rows'] as $row ) {
			if ( 'ready' !== $row['status'] ) {
				continue;
			}
			$ids = array();
			foreach ( $row['wards'] as $name ) {
				$result = self::ensure_neighbourhood( $name, $cache );
				if ( is_wp_error( $result ) ) {
					$failed++;
					continue 2;
				}
				if ( $result[1] ) {
					$created[ $name ] = true;
				}
				$ids[] = (int) $result[0];
			}
			$ids       = array_values( array_unique( $ids ) );
			$street_id = (int) $row['street_id'];
			if ( $street_id < 0 ) {
				$street_id = self::ensure_street( $row['street_title'] );
				if ( ! $street_id ) {
					$failed++;
					continue;
				}
			}

			$wpdb->query( 'START TRANSACTION' );
			$ok = true;
			if ( $ids ) {
				$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$ok    = false !== $wpdb->query( $wpdb->prepare( "DELETE FROM $links_table WHERE street_id = %d AND neighbourhood_id NOT IN ($marks)", array_merge( array( $street_id ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			foreach ( $ids as $neighbourhood_id ) {
				if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $links_table (neighbourhood_id, street_id) VALUES (%d, %d)", $neighbourhood_id, $street_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
					$ok = false;
				}
			}
			$fields = array();
			if ( isset( $row['updates']['outcode'] ) ) {
				$fields['outcode'] = (string) $row['updates']['outcode'];
			}
			if ( isset( $row['updates']['postcodes'] ) ) {
				$fields['postcodes'] = wp_json_encode( array_values( $row['updates']['postcodes'] ) );
			}
			if ( isset( $row['updates']['note'] ) ) {
				$fields['note'] = (string) $row['updates']['note'];
			}
			if ( $fields && false === $wpdb->update( $streets_table, $fields, array( 'id' => $street_id ) ) ) {
				$ok = false;
			}
			if ( $ok ) {
				$wpdb->query( 'COMMIT' );
				$saved++;
			} else {
				$wpdb->query( 'ROLLBACK' );
				$failed++;
			}
		}

		return array(
			'saved'   => $saved,
			'failed'  => $failed,
			'created' => array_keys( $created ),
			'skipped' => $plan['summary']['total'] - $saved - $failed,
			'summary' => $plan['summary'],
		);
	}

	/** How many distinct streets these neighbourhoods hold, and how many sit in more than one of them. */
	public static function summary( array $neighbourhood_ids ) {
		global $wpdb;
		$ids = self::clean_ids( $neighbourhood_ids );
		if ( ! $ids ) {
			return array( 'streets' => 0, 'shared' => 0 );
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT street_id, COUNT(*) AS c FROM ' . atk_table( 'nb_links' ) . " WHERE neighbourhood_id IN ($marks) GROUP BY street_id", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$streets = 0;
		$shared  = 0;
		foreach ( (array) $rows as $row ) {
			$streets++;
			if ( (int) $row->c > 1 ) {
				$shared++;
			}
		}
		return array( 'streets' => $streets, 'shared' => $shared );
	}

	public static function summary_text( array $summary ) {
		if ( ! $summary['streets'] ) {
			return 'No streets yet for these neighbourhoods.';
		}
		$text = $summary['streets'] . ( 1 === $summary['streets'] ? ' street' : ' streets' );
		if ( $summary['shared'] ) {
			$text .= ', ' . $summary['shared'] . ' in more than one chosen neighbourhood and counted once';
		}
		return $text;
	}

	/**
	 * Which saved neighbourhoods a week's text names. A week stores its
	 * neighbourhoods as one comma-joined string, and some real names contain a
	 * comma themselves ("Cults, Bieldside and Milltimber"), so the text is
	 * matched against the saved names, longest first, instead of split.
	 *
	 * @return int[] neighbourhood ids
	 */
	public static function ids_in_text( $text ) {
		$all = self::neighbourhoods();
		if ( ! $all ) {
			return array();
		}
		$names = array();
		foreach ( $all as $item ) {
			$names[ $item['id'] ] = self::lower( $item['name'] );
		}
		uasort( $names, function ( $a, $b ) {
			return mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' );
		} );
		$rest  = ', ' . self::lower( $text ) . ',';
		$found = array();
		foreach ( $names as $id => $name ) {
			$needle = ', ' . $name . ',';
			if ( false !== strpos( $rest, $needle ) ) {
				$found[] = $id;
				$rest    = str_replace( $needle, ',', $rest );
			}
		}
		return $found;
	}

	/** Names of the distinct streets in these neighbourhoods, sorted. */
	public static function street_names_for( array $neighbourhood_ids ) {
		global $wpdb;
		$ids = self::clean_ids( $neighbourhood_ids );
		if ( ! $ids ) {
			return array();
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$names = $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT s.name FROM ' . atk_table( 'nb_streets' ) . ' s INNER JOIN ' . atk_table( 'nb_links' ) . " l ON l.street_id = s.id WHERE l.neighbourhood_id IN ($marks)", // phpcs:ignore WordPress.DB.PreparedSQL
			$ids
		) );
		natcasesort( $names );
		return array_values( $names );
	}
}

/* -------------------------------------------------------------------- */
/* REST (atk/v1), instructors and administrators only                    */
/* -------------------------------------------------------------------- */

add_action( 'rest_api_init', 'atk_nb_register_routes' );
function atk_nb_register_routes() {
	$can = 'atk_nb_can_manage';

	register_rest_route( 'atk/v1', '/neighbourhoods', array(
		array( 'methods' => 'GET', 'callback' => 'atk_nb_rest_list', 'permission_callback' => $can ),
		array( 'methods' => 'POST', 'callback' => 'atk_nb_rest_create', 'permission_callback' => $can ),
	) );
	register_rest_route( 'atk/v1', '/neighbourhoods/summary', array(
		'methods' => 'POST', 'callback' => 'atk_nb_rest_summary', 'permission_callback' => $can,
	) );
	register_rest_route( 'atk/v1', '/neighbourhoods/(?P<id>\d+)', array(
		'methods' => 'DELETE', 'callback' => 'atk_nb_rest_delete', 'permission_callback' => $can,
	) );
	register_rest_route( 'atk/v1', '/neighbourhoods/(?P<id>\d+)/streets', array(
		'methods' => 'GET', 'callback' => 'atk_nb_rest_streets', 'permission_callback' => $can,
	) );
	register_rest_route( 'atk/v1', '/neighbourhoods/(?P<id>\d+)/streets/(?P<street>\d+)', array(
		'methods' => 'DELETE', 'callback' => 'atk_nb_rest_remove_street', 'permission_callback' => $can,
	) );
	register_rest_route( 'atk/v1', '/neighbourhood-import/preview', array(
		'methods' => 'POST', 'callback' => 'atk_nb_rest_preview', 'permission_callback' => $can,
	) );
	register_rest_route( 'atk/v1', '/neighbourhood-import/commit', array(
		'methods' => 'POST', 'callback' => 'atk_nb_rest_commit', 'permission_callback' => $can,
	) );
}

function atk_nb_can_manage() {
	return is_user_logged_in() && atk_is_instructor();
}

function atk_nb_rest_list() {
	return rest_ensure_response( ATK_Nb_Store::list_neighbourhoods() );
}

function atk_nb_rest_create( WP_REST_Request $request ) {
	$result = ATK_Nb_Store::ensure_neighbourhood( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'atk_nb_create_failed', $result->get_error_message(), array( 'status' => 400 ) );
	}
	return rest_ensure_response( array( 'id' => $result[0], 'created' => $result[1] ) );
}

function atk_nb_rest_delete( WP_REST_Request $request ) {
	if ( ! ATK_Nb_Store::delete_neighbourhood( (int) $request['id'] ) ) {
		return new WP_Error( 'atk_nb_delete_failed', 'That neighbourhood could not be deleted.', array( 'status' => 400 ) );
	}
	return rest_ensure_response( array( 'deleted' => true ) );
}

function atk_nb_rest_streets( WP_REST_Request $request ) {
	return rest_ensure_response( ATK_Nb_Store::streets_in_neighbourhood( (int) $request['id'] ) );
}

function atk_nb_rest_remove_street( WP_REST_Request $request ) {
	ATK_Nb_Store::remove_street( (int) $request['id'], (int) $request['street'] );
	return rest_ensure_response( array( 'removed' => true ) );
}

/** Streets for a set of neighbourhood names (the Course Builder's Neighbourhoods field holds names). */
function atk_nb_rest_summary( WP_REST_Request $request ) {
	$names = array_map( 'sanitize_text_field', array_map( 'strval', (array) $request->get_param( 'names' ) ) );
	$ids   = ATK_Nb_Store::ids_in_text( implode( ', ', $names ) );
	$sum   = ATK_Nb_Store::summary( $ids );
	$sum['text'] = ATK_Nb_Store::summary_text( $sum );
	return rest_ensure_response( $sum );
}

function atk_nb_rest_preview( WP_REST_Request $request ) {
	$input = atk_nb_read_input( $request );
	return rest_ensure_response( array_merge(
		ATK_Nb_Store::preview( $input['rows'], $input['target'], $input['choices'], $input['additions'] ),
		array( 'mode' => $input['mode'] )
	) );
}

function atk_nb_rest_commit( WP_REST_Request $request ) {
	$input  = atk_nb_read_input( $request );
	return rest_ensure_response( ATK_Nb_Store::commit( $input['rows'], $input['target'], $input['choices'], $input['additions'] ) );
}

/** Turns the request into parsed rows. Sheet rows arrive as arrays, pasted text as a string. */
function atk_nb_read_input( WP_REST_Request $request ) {
	$raw  = $request->get_param( 'rows' );
	$mode = 'table';
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
		$rows = ATK_Nb_Parser::parse_rows( $table );
	} else {
		$parsed = ATK_Nb_Parser::parse_paste( mb_substr( (string) $request->get_param( 'text' ), 0, 400000 ) );
		$rows   = array_slice( $parsed['rows'], 0, 5000 );
		$mode   = $parsed['mode'];
	}

	$choices = array();
	$given   = $request->get_param( 'choices' );
	if ( is_array( $given ) ) {
		foreach ( $given as $line => $street_id ) {
			$choices[ (int) $line ] = (int) $street_id;
		}
	}

	$additions = array();
	foreach ( array_slice( (array) $request->get_param( 'additions' ), 0, 5000 ) as $line ) {
		$additions[] = (int) $line;
	}

	return array(
		'rows'      => $rows,
		'mode'      => $mode,
		'target'    => (int) $request->get_param( 'target' ),
		'choices'   => $choices,
		'additions' => $additions,
	);
}

/* -------------------------------------------------------------------- */
/* Dashboard tab                                                        */
/* -------------------------------------------------------------------- */

/** The REST address and nonce the tab's script needs. SheetJS (bundled) reads .xlsx and .csv in the browser. */
function atk_nb_script_config() {
	return 'var ATK_NB = ' . wp_json_encode( array(
		'restUrl' => esc_url_raw( rest_url( 'atk/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
	) ) . ';';
}

function atk_render_instructor_neighbourhoods_tab() {
	$path = '/assets/js/instructor-neighbourhoods.js';
	wp_enqueue_script( 'atk-nb-xlsx' ); // SheetJS, bundled (registered in inc/assets.php).
	wp_enqueue_script( 'atk-instructor-neighbourhoods', ATK_PLUGIN_URI . $path, array( 'atk-scripts-bundle', 'atk-nb-xlsx' ), filemtime( ATK_PLUGIN_DIR . $path ), true );
	wp_add_inline_script( 'atk-instructor-neighbourhoods', atk_nb_script_config(), 'before' );
	?>
	<div id="atk-nb-root"><p class="text-gray-600">Loading neighbourhoods&hellip;</p></div>
	<?php
}

/* -------------------------------------------------------------------- */
/* Course Builder                                                       */
/* -------------------------------------------------------------------- */

/**
 * For the Course Builder: each week's streets (by week id), so the question
 * forms can offer the streets of the neighbourhoods that week covers. Only
 * weeks whose neighbourhoods have streets are included.
 *
 * @param array[] $weeks Output of atk_course_weeks().
 * @return array<int,string[]>
 */
function atk_nb_week_street_map( array $weeks ) {
	$out = array();
	foreach ( $weeks as $week ) {
		$names = ATK_Nb_Store::street_names_for( ATK_Nb_Store::ids_in_text( implode( ', ', $week['neighbourhoods'] ) ) );
		if ( $names ) {
			$out[ (int) $week['id'] ] = $names;
		}
	}
	return $out;
}

/** Loads the Course Builder helper script once, with the REST address and each week's streets. */
function atk_nb_enqueue_course_builder( array $week_streets, array $week_places = array(), array $places = array() ) {
	$path = '/assets/js/instructor-course-neighbourhoods.js';
	wp_enqueue_script( 'atk-instructor-course-neighbourhoods', ATK_PLUGIN_URI . $path, array( 'atk-scripts-bundle', 'atk-instructor-dashboard' ), filemtime( ATK_PLUGIN_DIR . $path ), true );
	wp_add_inline_script(
		'atk-instructor-course-neighbourhoods',
		'var ATK_NB_COURSE = ' . wp_json_encode( array(
			'restUrl'     => esc_url_raw( rest_url( 'atk/v1/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'weekStreets' => (object) $week_streets,
			'weekPlaces'  => (object) $week_places,
			'places'      => array_values( $places ),
		) ) . ';',
		'before'
	);
}
