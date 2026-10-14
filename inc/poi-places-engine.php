<?php
/**
 * Places of Interest engine: reads the official places sheet, works out each
 * place's street answer from its address, and plans what saving an import
 * would change. Pure PHP with no WordPress calls, so every rule can be tested
 * on its own (tests/places/). Storage lives in inc/poi-places.php.
 *
 * Reuses ATK_Nb_Parser and ATK_Nb_Matcher from inc/neighbourhoods-engine.php.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ATK_NB_TESTING' ) ) {
	exit;
}

/**
 * Turns spreadsheet rows (an array of arrays of cells) into clean place records.
 */
class ATK_Poi_Parser {

	/** How far down the sheet the header row may sit. */
	const HEADER_SCAN_ROWS = 5;

	/** Header text (lower case) that stands for each field. */
	const HEADER_ALIASES = array(
		'name'     => array( 'place of interest', 'place', 'name', 'venue' ),
		'address'  => array( 'street', 'address' ),
		'postcode' => array( 'postcode', 'post code' ),
		'ward'     => array( 'ward' ),
		'category' => array( 'category' ),
		'status'   => array( 'status' ),
		'note'     => array( 'note', 'notes' ),
	);

	/** Words in an address that are never the street. */
	const FILLER_PARTS = array( 'aberdeen', 'scotland', 'united kingdom' );

	private static function clean_cell( $cell ) {
		$cell = is_scalar( $cell ) ? (string) $cell : '';
		$cell = preg_replace( '/^\x{FEFF}/u', '', $cell );
		return trim( preg_replace( '/\s+/u', ' ', $cell ) );
	}

	/** @return array<string,int> field => column index, for the fields found in this header row. */
	public static function map_header( array $row ) {
		$map = array();
		foreach ( array_values( $row ) as $index => $cell ) {
			$text = mb_strtolower( self::clean_cell( $cell ), 'UTF-8' );
			if ( '' === $text ) {
				continue;
			}
			foreach ( self::HEADER_ALIASES as $field => $aliases ) {
				if ( ! isset( $map[ $field ] ) && in_array( $text, $aliases, true ) ) {
					$map[ $field ] = $index;
					break;
				}
			}
		}
		return $map;
	}

	/**
	 * @param array[] $table Sheet rows; the header is the first of the first few rows with a place column.
	 * @return array{rows: array[], error: ?string}
	 */
	public static function parse_rows( array $table ) {
		$table  = array_values( $table );
		$header = -1;
		$map    = array();
		foreach ( array_slice( $table, 0, self::HEADER_SCAN_ROWS ) as $index => $row ) {
			$found = is_array( $row ) ? self::map_header( $row ) : array();
			if ( isset( $found['name'] ) ) {
				$header = $index;
				$map    = $found;
				break;
			}
		}
		if ( $header < 0 ) {
			return array( 'rows' => array(), 'error' => 'Could not find a "Place of interest" column.' );
		}

		$cell = function ( array $row, $field ) use ( $map ) {
			if ( ! isset( $map[ $field ] ) ) {
				return '';
			}
			$cells = array_values( $row );
			return isset( $cells[ $map[ $field ] ] ) ? self::clean_cell( $cells[ $map[ $field ] ] ) : '';
		};

		$rows = array();
		foreach ( array_slice( $table, $header + 1, null, true ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = $cell( $row, 'name' );
			if ( '' === $name ) {
				continue;
			}
			$address  = $cell( $row, 'address' );
			$postcode = self::tidy_postcode( $cell( $row, 'postcode' ) );
			if ( '' === $postcode ) {
				$postcode = self::tidy_postcode( $address );
			}
			$rows[] = array(
				'line'     => $index + 1,
				'name'     => $name,
				'address'  => $address,
				'postcode' => $postcode,
				'ward'     => $cell( $row, 'ward' ),
				'category' => $cell( $row, 'category' ),
				'status'   => $cell( $row, 'status' ),
				'note'     => $cell( $row, 'note' ),
			);
		}
		return array( 'rows' => $rows, 'error' => null );
	}

	/** The first UK postcode in the text as "AB25 1GL", or an empty string. */
	public static function tidy_postcode( $text ) {
		$found = ATK_Nb_Parser::extract_postcodes( preg_replace( '/\s+/', ' ', (string) $text ) );
		return $found ? $found[0] : '';
	}

	/** Lower case, with no spaces around slashes: "Midstocket / Rosemount" equals "Midstocket/Rosemount". */
	public static function ward_key( $ward ) {
		$ward = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $ward ) ), 'UTF-8' );
		return preg_replace( '/\s*\/\s*/u', '/', $ward );
	}

	/**
	 * The parts of an address that could be the street, right-most first.
	 *
	 * @return string[]
	 */
	public static function street_candidates( $address ) {
		$text  = preg_replace( ATK_Nb_Parser::POSTCODE_RE, '', (string) $address );
		$parts = array();
		foreach ( explode( ',', $text ) as $part ) {
			$part = trim( preg_replace( '/\s+/u', ' ', $part ) );
			$part = trim( preg_replace( '/[\s.;]+$/u', '', $part ) );
			$part = preg_replace( '/^\d+[A-Za-z]?(-\d+[A-Za-z]?)?\s+/u', '', $part );
			if ( '' === $part || in_array( mb_strtolower( $part, 'UTF-8' ), self::FILLER_PARTS, true ) ) {
				continue;
			}
			$parts[] = $part;
		}
		return array_reverse( $parts );
	}
}

/**
 * Finds the real road named in an address, from the site's road list.
 */
class ATK_Poi_Street_Matcher {

	/** Final-word abbreviations that mean the same as the long form. */
	const ABBREVIATIONS = array(
		'rd'  => 'Road',
		'st'  => 'Street',
		'ave' => 'Avenue',
		'dr'  => 'Drive',
		'ln'  => 'Lane',
		'pl'  => 'Place',
		'ct'  => 'Court',
	);

	/** @var ATK_Nb_Matcher */
	private $matcher;

	/** @param string[] $road_names */
	public function __construct( array $road_names ) {
		// The same street can be listed twice in different case or punctuation
		// ("St. Nicholas Street" and "St Nicholas Street"); the matcher would call
		// that ambiguous and never match it, so keep the first spelling only.
		$seen = array();
		$kept = array();
		foreach ( $road_names as $name ) {
			$key = ATK_Nb_Parser::normalise_name( $name );
			if ( '' !== $key && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$kept[]       = $name;
			}
		}
		$this->matcher = new ATK_Nb_Matcher( $kept );
	}

	/** Expands an abbreviated final word only ("King st" becomes "King Street"; "St Machar Drive" is untouched). */
	private static function expand_final_word( $candidate ) {
		if ( ! preg_match( '/^(.+\s)(\S+)$/u', $candidate, $m ) ) {
			return $candidate;
		}
		$word = mb_strtolower( rtrim( $m[2], '.' ), 'UTF-8' );
		return isset( self::ABBREVIATIONS[ $word ] ) ? $m[1] . self::ABBREVIATIONS[ $word ] : $candidate;
	}

	/**
	 * @return array{status: string, road: ?string, suggestion: ?string}
	 */
	public function match( $address ) {
		$suggestion = null;
		foreach ( ATK_Poi_Parser::street_candidates( $address ) as $candidate ) {
			foreach ( array_unique( array( $candidate, self::expand_final_word( $candidate ) ) ) as $variant ) {
				$result = $this->matcher->match( $variant );
				if ( 'matched' === $result['status'] ) {
					return array( 'status' => 'matched', 'road' => $this->matcher->title( $result['id'] ), 'suggestion' => null );
				}
				if ( null === $suggestion && 'unmatched' === $result['status'] && ! empty( $result['suggestion'] ) ) {
					$suggestion = $result['suggestion']['title'];
				}
			}
		}
		return array( 'status' => 'unmatched', 'road' => null, 'suggestion' => $suggestion );
	}
}

/**
 * Works out what an import would change, without touching the database.
 * Pure PHP: the store hands it plain arrays, so every rule here can be tested.
 */
class ATK_Poi_Planner {

	/** Aberdeen City Council's 13 wards in their official order (ward 1 first). Week N of the Official POI test is ward N. */
	const OFFICIAL_WARDS = array(
		'Dyce/Bucksburn/Danestone',
		'Bridge of Don',
		'Kingswells/Sheddocksley/Summerhill',
		'Northfield/Mastrick North',
		'Hilton/Woodside/Stockethill',
		'Tillydrone/Seaton/Old Aberdeen',
		'Midstocket/Rosemount',
		'George Street/Harbour',
		'Lower Deeside',
		'Hazlehead/Queens Cross/Countesswells',
		'Airyhall/Broomhill/Garthdee',
		'Torry/Ferryhill',
		'Kincorth/Nigg/Cove',
	);

	const COURSE_TESTS_PER_WEEK     = 5;
	const COURSE_QUESTIONS_PER_TEST = 20;

	/** Key for the researched answers file: place name plus postcode, ignoring case and spacing. */
	public static function research_key( $name, $postcode ) {
		return mb_strtolower( trim( (string) $name ), 'UTF-8' ) . '|' . strtoupper( preg_replace( '/\s+/', '', (string) $postcode ) );
	}

	/**
	 * Splits one ward's questions into the week's five tests. A ward with 20 places or fewer
	 * puts every place in every test; a bigger ward fills each test to 20 by walking the list
	 * round (so no place repeats inside a test and every place is asked at least once). At most
	 * 100 questions are used; the rest are returned in `left_out`.
	 *
	 * @param array[] $questions Each array( 'poi' => string, 'accepted' => string[] ).
	 * @return array{tests: array[], left_out: string[]}
	 */
	public static function course_tests( array $questions ) {
		$questions = array_values( $questions );
		$cap       = self::COURSE_TESTS_PER_WEEK * self::COURSE_QUESTIONS_PER_TEST;
		$used      = array_slice( $questions, 0, $cap );
		$left_out  = array_column( array_slice( $questions, $cap ), 'poi' );
		$n         = count( $used );
		$tests     = array();
		for ( $t = 0; $t < self::COURSE_TESTS_PER_WEEK; $t++ ) {
			if ( $n <= self::COURSE_QUESTIONS_PER_TEST ) {
				$tests[] = $used;
				continue;
			}
			$test = array();
			for ( $j = 0; $j < self::COURSE_QUESTIONS_PER_TEST; $j++ ) {
				$test[] = $used[ ( $t * self::COURSE_QUESTIONS_PER_TEST + $j ) % $n ];
			}
			$tests[] = $test;
		}
		return array( 'tests' => $tests, 'left_out' => $left_out );
	}

	/**
	 * What the Official POI test would hold, week by week.
	 *
	 * @param array[] $places   Each with name, label, postcode, ward (name), street_answer, status.
	 * @param array   $research research_key() => array( 'street' => string, 'also' => string[] ).
	 * @return array{weeks: array[], outside: array[]}
	 */
	public static function course_plan( array $places, array $research ) {
		$index = array();
		foreach ( self::OFFICIAL_WARDS as $i => $ward ) {
			$index[ ATK_Poi_Parser::ward_key( $ward ) ] = $i;
		}
		$weeks = array();
		foreach ( self::OFFICIAL_WARDS as $i => $ward ) {
			$weeks[ $i ] = array( 'number' => $i + 1, 'ward' => $ward, 'questions' => array(), 'no_answer' => array(), 'tests' => array(), 'left_out' => array() );
		}
		$outside = array();
		foreach ( $places as $place ) {
			$key = ATK_Poi_Parser::ward_key( (string) $place['ward'] );
			if ( ! isset( $index[ $key ] ) ) {
				$outside[] = array( 'name' => $place['name'], 'ward' => (string) $place['ward'] );
				continue;
			}
			if ( 'current' !== $place['status'] ) {
				continue;
			}
			$i     = $index[ $key ];
			$found = isset( $research[ self::research_key( $place['name'], $place['postcode'] ) ] ) ? $research[ self::research_key( $place['name'], $place['postcode'] ) ] : array();
			$saved = trim( (string) $place['street_answer'] );
			$first = ! empty( $found['street'] ) ? $found['street'] : $saved;
			if ( '' === $first ) {
				$weeks[ $i ]['no_answer'][] = $place['name'];
				continue;
			}
			$accepted = array();
			$seen     = array();
			$only    = ! empty( $found['street'] ) && ! empty( $found['only'] );
			foreach ( array_merge( array( $first ), isset( $found['also'] ) ? (array) $found['also'] : array(), $only ? array() : array( $saved ) ) as $answer ) {
				$norm = ATK_Nb_Parser::normalise_name( $answer );
				if ( '' !== $norm && ! isset( $seen[ $norm ] ) ) {
					$seen[ $norm ] = true;
					$accepted[]    = $answer;
				}
			}
			$weeks[ $i ]['questions'][] = array( 'poi' => $place['label'], 'accepted' => $accepted );
		}
		foreach ( $weeks as $i => $week ) {
			$split                  = self::course_tests( $week['questions'] );
			$weeks[ $i ]['tests']    = $split['tests'];
			$weeks[ $i ]['left_out'] = $split['left_out'];
		}
		return array( 'weeks' => $weeks, 'outside' => $outside );
	}

	/**
	 * The name each place is shown and asked under. A name used by more than one
	 * place gets its postcode added, so two branches of one chain stay two places.
	 *
	 * @param array[] $items Each with `name` and `postcode`.
	 * @return string[] One display name per item, in the same order.
	 */
	public static function display_names( array $items ) {
		$counts = array();
		foreach ( $items as $item ) {
			$key            = mb_strtolower( $item['name'], 'UTF-8' );
			$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
		}
		$out = array();
		foreach ( $items as $item ) {
			$repeat = $counts[ mb_strtolower( $item['name'], 'UTF-8' ) ] > 1;
			$out[]  = $item['name'] . ( $repeat && '' !== trim( (string) $item['postcode'] ) ? ' (' . $item['postcode'] . ')' : '' );
		}
		return $out;
	}

	const CATEGORIES = array(
		'Restaurants and Cafes',
		'Hotels',
		'Museums Arts & Culture',
		'Sports Facilities',
		'Schools Libraries & Learning',
		'Community Centres',
		'Religious Buildings & Cemeteries',
		'Clubs & Members Clubs',
		'Other Amenities',
		'Living Spaces',
		'Transport Hubs',
	);

	const DEFAULT_CATEGORY = 'Other Amenities';

	/** The same place is the same name (any case) at the same postcode. */
	public static function place_key( $name, $postcode ) {
		return mb_strtolower( trim( (string) $name ), 'UTF-8' ) . '|' . ATK_Poi_Parser::tidy_postcode( $postcode );
	}

	/** The category named by a cell, or null when it is empty or not one of the eleven. */
	private static function known_category( $text ) {
		$text = mb_strtolower( trim( (string) $text ), 'UTF-8' );
		foreach ( self::CATEGORIES as $category ) {
			if ( mb_strtolower( $category, 'UTF-8' ) === $text ) {
				return $category;
			}
		}
		return null;
	}

	/**
	 * @param array[] $rows Output of ATK_Poi_Parser::parse_rows()['rows'].
	 * @param array   $ctx  wards (id => name), existing (id, name, postcode, street_answer), roads (names).
	 * @return array[] One plan row per input row.
	 */
	public static function plan( array $rows, array $ctx ) {
		$wards = array();
		foreach ( $ctx['wards'] as $id => $name ) {
			$wards[ ATK_Poi_Parser::ward_key( $name ) ] = (int) $id;
		}
		$existing = array();
		foreach ( $ctx['existing'] as $place ) {
			$existing[ self::place_key( $place['name'], $place['postcode'] ) ] = $place;
		}
		$matcher = new ATK_Poi_Street_Matcher( $ctx['roads'] );
		$seen    = array();
		$out     = array();

		foreach ( $rows as $row ) {
			$key    = self::place_key( $row['name'], $row['postcode'] );
			$street = $matcher->match( $row['address'] );
			$known  = isset( $existing[ $key ] ) ? $existing[ $key ] : null;

			$action = 'add';
			if ( isset( $seen[ $key ] ) ) {
				$action = 'skip';
			} elseif ( $known ) {
				$action = 'update';
			}
			$seen[ $key ] = true;

			$issues = array();
			if ( '' === trim( $row['ward'] ) ) {
				$ward_id = 0;
			} elseif ( isset( $wards[ ATK_Poi_Parser::ward_key( $row['ward'] ) ] ) ) {
				$ward_id = $wards[ ATK_Poi_Parser::ward_key( $row['ward'] ) ];
			} else {
				$ward_id  = null;
				$issues[] = 'new_ward';
			}

			$category      = self::known_category( $row['category'] );
			$street_answer = null === $street['road'] ? '' : $street['road'];
			$saved_street  = $known && ! empty( $known['street_answer'] );
			if ( '' === $street_answer && ! $saved_street ) {
				$issues[] = 'needs_street';
			}

			$out[] = array(
				'line'          => $row['line'],
				'name'          => $row['name'],
				'address'       => $row['address'],
				'postcode'      => $row['postcode'],
				'ward'          => $row['ward'],
				'ward_id'       => $ward_id,
				'street_answer' => $street_answer,
				'suggestion'    => $street['suggestion'],
				'category'      => $category ? $category : self::DEFAULT_CATEGORY,
				'category_given' => null !== $category,
				'status'        => 'closed' === mb_strtolower( trim( $row['status'] ), 'UTF-8' ) ? 'closed' : 'current',
				'status_given'  => '' !== trim( $row['status'] ),
				'note'          => $row['note'],
				'action'        => $action,
				'existing_id'   => $known ? (int) $known['id'] : 0,
				'issues'        => 'skip' === $action ? array() : $issues,
			);
		}
		return $out;
	}

	/**
	 * The order ward sections appear in: wards of the first course week first, then the next week's,
	 * and so on (within a week, in the order written); wards in no week follow, alphabetically.
	 *
	 * @param array<int,string> $wards      id => name
	 * @param string[]          $week_texts One comma-joined neighbourhood string per week, in week order.
	 * @return int[] ward ids
	 */
	public static function order_wards( array $wards, array $week_texts ) {
		$lengths = array();
		$lower   = array();
		foreach ( $wards as $id => $name ) {
			$lower[ $id ]   = mb_strtolower( trim( $name ), 'UTF-8' );
			$lengths[ $id ] = mb_strlen( $lower[ $id ], 'UTF-8' );
		}
		$by_length = array_keys( $lengths );
		usort( $by_length, function ( $a, $b ) use ( $lengths ) {
			return $lengths[ $b ] <=> $lengths[ $a ];
		} );

		$ordered = array();
		foreach ( $week_texts as $text ) {
			$rest  = ', ' . mb_strtolower( (string) $text, 'UTF-8' ) . ',';
			$found = array();
			foreach ( $by_length as $id ) {
				if ( '' === $lower[ $id ] ) {
					continue;
				}
				$needle = ', ' . $lower[ $id ] . ',';
				$pos    = strpos( $rest, $needle );
				if ( false !== $pos ) {
					$found[ $id ] = $pos;
					// Blank the name but keep both commas and the length, so a neighbour still matches and positions stay right.
					$rest = substr_replace( $rest, ',' . str_repeat( '#', strlen( $needle ) - 2 ) . ',', $pos, strlen( $needle ) );
				}
			}
			asort( $found );
			foreach ( array_keys( $found ) as $id ) {
				if ( ! isset( $ordered[ $id ] ) ) {
					$ordered[ $id ] = true;
				}
			}
		}

		$rest_ids = array();
		foreach ( $wards as $id => $name ) {
			if ( ! isset( $ordered[ $id ] ) ) {
				$rest_ids[ $id ] = $lower[ $id ];
			}
		}
		asort( $rest_ids, SORT_STRING );
		return array_merge( array_keys( $ordered ), array_keys( $rest_ids ) );
	}
}

/**
 * The Aberdeen area a map pin must fall inside. The same box is applied in the
 * browser (assets/js/poi-geo.js) and again here before a coordinate is stored.
 */
class ATK_Poi_Geo {

	const LAT_MIN = 57.00;
	const LAT_MAX = 57.30;
	const LNG_MIN = -2.40;
	const LNG_MAX = -1.95;

	public static function in_box( $lat, $lng ) {
		return $lat >= self::LAT_MIN && $lat <= self::LAT_MAX && $lng >= self::LNG_MIN && $lng <= self::LNG_MAX;
	}
}
