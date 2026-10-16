<?php
/**
 * Streets engine: the Official Route test's rules, as pure PHP with no
 * WordPress calls so every rule can be tested (tests/streets/).
 *
 * - ATK_Route_Pairs: the From/To answers of a street, and how a reply is marked.
 * - ATK_Street_Parser: reads a sheet with Road, Ward, Post Code, Address, Note,
 *   Clarify, From and To columns.
 * - ATK_Route_Planner: a street's status and how the Official Route test is
 *   divided into weeks (one per official ward) and five tests a week.
 *
 * Reuses ATK_Nb_Parser from inc/neighbourhoods-engine.php and
 * ATK_Poi_Parser::ward_key from inc/poi-places-engine.php.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ATK_NB_TESTING' ) ) {
	exit;
}

class ATK_Route_Pairs {

	/** The most answers one street may hold. */
	const MAX_PAIRS = 20;

	const MAX_LENGTH = 120;

	/**
	 * Case, apostrophes and punctuation are ignored, and the common road words are
	 * folded the same way the quiz page does (Street = St, Road = Rd, Avenue = Ave,
	 * Drive = Dr, Boulevard = Blvd). assets/js/quiz.js repeats this for the pair mode.
	 */
	public static function key( $text ) {
		$words = explode( ' ', ATK_Nb_Parser::normalise_name( $text ) );
		$fold  = array( 'STREET' => 'ST', 'ROAD' => 'RD', 'AVENUE' => 'AVE', 'DRIVE' => 'DR', 'BOULEVARD' => 'BLVD' );
		foreach ( $words as $i => $word ) {
			if ( isset( $fold[ $word ] ) ) {
				$words[ $i ] = $fold[ $word ];
			}
		}
		// A dead end is an answer, in any spelling.
		return preg_replace( '/\b(?:CUL DE SAC|CULDESAC|DEAD END|DEADEND)\b/u', 'DEAD END', implode( ' ', $words ) );
	}

	/**
	 * Trims the pairs, drops half-filled ones and repeats (the same pair, either way round).
	 *
	 * @param mixed $pairs array of array( from, to ).
	 * @return array[] array( array( from, to ), ... )
	 */
	public static function clean( $pairs ) {
		$out  = array();
		$seen = array();
		foreach ( is_array( $pairs ) ? $pairs : array() as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$pair = array_values( $pair );
			$from = isset( $pair[0] ) && is_scalar( $pair[0] ) ? trim( preg_replace( '/\s+/u', ' ', (string) $pair[0] ) ) : '';
			$to   = isset( $pair[1] ) && is_scalar( $pair[1] ) ? trim( preg_replace( '/\s+/u', ' ', (string) $pair[1] ) ) : '';
			if ( '' === $from || '' === $to ) {
				continue;
			}
			$from = mb_substr( $from, 0, self::MAX_LENGTH, 'UTF-8' );
			$to   = mb_substr( $to, 0, self::MAX_LENGTH, 'UTF-8' );
			$a    = self::key( $from );
			$b    = self::key( $to );
			if ( '' === $a || '' === $b ) {
				continue;
			}
			$forward = $a . '|' . $b;
			$reverse = $b . '|' . $a;
			if ( isset( $seen[ $forward ] ) || isset( $seen[ $reverse ] ) ) {
				continue;
			}
			$seen[ $forward ] = true;
			$out[]            = array( $from, $to );
			if ( count( $out ) >= self::MAX_PAIRS ) {
				break;
			}
		}
		return $out;
	}

	/** True when the reply is any listed pair, in either order. Both boxes must be filled. */
	public static function matches( $from, $to, array $pairs ) {
		$a = self::key( $from );
		$b = self::key( $to );
		if ( '' === $a || '' === $b || $a === $b ) {
			return false;
		}
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || count( $pair ) < 2 ) {
				continue;
			}
			$pair = array_values( $pair );
			$x    = self::key( $pair[0] );
			$y    = self::key( $pair[1] );
			if ( ( $a === $x && $b === $y ) || ( $a === $y && $b === $x ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The distinct From answers and To answers of a street, each once (by key), in order.
	 * The student gets one field per answer, and each correct answer is a point.
	 *
	 * @return array{from: string[], to: string[]}
	 */
	public static function ends( $pairs ) {
		$out  = array( 'from' => array(), 'to' => array() );
		$seen = array( 'from' => array(), 'to' => array() );
		foreach ( is_array( $pairs ) ? $pairs : array() as $pair ) {
			if ( ! is_array( $pair ) || count( $pair ) < 2 ) {
				continue;
			}
			$pair = array_values( $pair );
			foreach ( array( 'from' => $pair[0], 'to' => $pair[1] ) as $end => $text ) {
				$key = self::key( $text );
				if ( '' !== $key && ! isset( $seen[ $end ][ $key ] ) ) {
					$seen[ $end ][ $key ] = true;
					$out[ $end ][]        = (string) $text;
				}
			}
		}
		return $out;
	}

	/** The points a street is worth: one for every distinct answer at either end. */
	public static function points( $pairs ) {
		$ends = self::ends( $pairs );
		return count( $ends['from'] ) + count( $ends['to'] );
	}

	/**
	 * The fewest pairs that hold every From and every To answer: the longer list sets the
	 * length and the shorter one repeats. Used for researched answers, so a street with
	 * six roads at one end is six pairs, not thirty-six.
	 */
	public static function cover( array $from, array $to ) {
		$from = array_values( $from );
		$to   = array_values( $to );
		if ( ! $from || ! $to ) {
			return array();
		}
		$pairs = array();
		$count = min( self::MAX_PAIRS, max( count( $from ), count( $to ) ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$pairs[] = array( $from[ $i % count( $from ) ], $to[ $i % count( $to ) ] );
		}
		return $pairs;
	}

	/** How many distinct answers the typed entries hit (blank entries never count). */
	private static function hits( array $entries, array $answers ) {
		$typed = array();
		foreach ( $entries as $entry ) {
			$key = self::key( $entry );
			if ( '' !== $key ) {
				$typed[ $key ] = true;
			}
		}
		$found = array();
		foreach ( $answers as $answer ) {
			$key = self::key( $answer );
			if ( '' !== $key && isset( $typed[ $key ] ) ) {
				$found[ $key ] = true;
			}
		}
		return count( $found );
	}

	/**
	 * Marks a reply by points: one for each distinct answer matched, From and To either
	 * way round (the better of the two), blanks allowed, the same place twice counted once.
	 *
	 * @return array{points: int, max: int}
	 */
	public static function grade( $pairs, array $given_from, array $given_to ) {
		$ends     = self::ends( $pairs );
		$straight = self::hits( $given_from, $ends['from'] ) + self::hits( $given_to, $ends['to'] );
		$swapped  = self::hits( $given_from, $ends['to'] ) + self::hits( $given_to, $ends['from'] );
		return array( 'points' => max( $straight, $swapped ), 'max' => count( $ends['from'] ) + count( $ends['to'] ) );
	}

	/** Every answer for the review: "From: A / B — To: C". */
	public static function answer_sheet( $pairs ) {
		$ends = self::ends( $pairs );
		return 'From: ' . implode( ' / ', $ends['from'] ) . ' — To: ' . implode( ' / ', $ends['to'] );
	}

	/**
	 * The `points` and `max` a stored review answer may carry. Anything without a `max`
	 * carries neither. A street holds at most two answers per pair, so `max` is capped there.
	 */
	public static function clean_result( array $raw ) {
		if ( ! isset( $raw['max'] ) ) {
			return array();
		}
		$max    = max( 0, min( 2 * self::MAX_PAIRS, (int) $raw['max'] ) );
		$points = max( 0, min( $max, isset( $raw['points'] ) ? (int) $raw['points'] : 0 ) );
		return array( 'points' => $points, 'max' => $max );
	}

	/** Every pair, for the Course Builder and the review: "A to B / C to D". */
	public static function label_all( array $pairs ) {
		return implode( ' / ', array_map( array( __CLASS__, 'label' ), array_values( array_filter( $pairs, 'is_array' ) ) ) );
	}

	public static function label( array $pair ) {
		$pair = array_values( $pair );
		return ( isset( $pair[0] ) ? $pair[0] : '' ) . ' to ' . ( isset( $pair[1] ) ? $pair[1] : '' );
	}
}

/**
 * Reads a table of rows into streets. A repeated street adds its pairs to the
 * first row; blank cells never mean "erase" (Clarify is null when blank).
 */
class ATK_Street_Parser {

	private static function words( $cell ) {
		return strtolower( preg_replace( '/[^a-z]/i', '', (string) $cell ) );
	}

	/** Column positions, or null when the first filled row is not a header with a Road column. */
	public static function map_header( array $row ) {
		$map = ATK_Nb_Parser::map_header( $row );
		if ( ! $map ) {
			return null;
		}
		foreach ( $row as $i => $cell ) {
			$key = self::words( $cell );
			if ( in_array( $key, array( 'clarify', 'clarification', 'needsclarification' ), true ) ) {
				$map['clarify'] = $i;
			} elseif ( 'from' === $key ) {
				$map['from'] = $i;
			} elseif ( 'to' === $key ) {
				$map['to'] = $i;
			}
		}
		return $map;
	}

	private static function cell( array $row, $map, $key ) {
		if ( ! isset( $map[ $key ] ) || ! isset( $row[ $map[ $key ] ] ) || ! is_scalar( $row[ $map[ $key ] ] ) ) {
			return '';
		}
		return trim( (string) $row[ $map[ $key ] ] );
	}

	private static function row_is_empty( array $row ) {
		foreach ( $row as $cell ) {
			if ( is_scalar( $cell ) && '' !== trim( (string) $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/** Blank leaves the flag alone (null); no / n / 0 / false / clear / cleared / ok clears it; anything else sets it. */
	private static function clarify_value( $text ) {
		$text = strtolower( trim( (string) $text ) );
		if ( '' === $text ) {
			return null;
		}
		return ! in_array( $text, array( 'no', 'n', '0', 'false', 'clear', 'cleared', 'ok', 'done', 'resolved' ), true );
	}

	/**
	 * @return array[] each: line, road, outcode, postcodes, wards, note, clarify (?bool), pairs, issues
	 */
	public static function parse_rows( array $rows ) {
		$rows = array_values( $rows );
		$map  = null;
		$from = 0;
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || self::row_is_empty( $row ) ) {
				continue;
			}
			$map = self::map_header( $row );
			if ( $map ) {
				$from = $i + 1;
			}
			break;
		}
		if ( ! $map ) {
			return array();
		}

		$out   = array();
		$index = array();
		for ( $i = $from; $i < count( $rows ); $i++ ) {
			$row = $rows[ $i ];
			if ( ! is_array( $row ) || self::row_is_empty( $row ) ) {
				continue;
			}
			$road = trim( preg_replace( '/\s+/', ' ', self::cell( $row, $map, 'road' ) ) );
			if ( '' === $road ) {
				continue;
			}
			$postcode_text = self::cell( $row, $map, 'postcodes' );
			$postcodes     = ATK_Nb_Parser::extract_postcodes( $postcode_text );
			$outcode       = ATK_Nb_Parser::parse_outcode( self::cell( $row, $map, 'outcode' ) );
			if ( '' === $outcode && $postcodes ) {
				$outcode = ATK_Nb_Parser::outcode_of( $postcodes[0] );
			}
			$issues = array();
			$a      = self::cell( $row, $map, 'from' );
			$b      = self::cell( $row, $map, 'to' );
			$pairs  = array();
			if ( '' !== $a && '' !== $b ) {
				$pairs = ATK_Route_Pairs::clean( array( array( $a, $b ) ) );
			} elseif ( '' !== $a || '' !== $b ) {
				$issues[] = 'Only one of From and To is filled in, so that pair was ignored.';
			}
			$entry = array(
				'line'      => $i + 1,
				'road'      => $road,
				'outcode'   => $outcode,
				'postcodes' => $postcodes,
				'wards'     => ATK_Nb_Parser::split_wards( self::cell( $row, $map, 'ward' ) ),
				'note'      => self::cell( $row, $map, 'note' ),
				'clarify'   => self::clarify_value( self::cell( $row, $map, 'clarify' ) ),
				'pairs'     => $pairs,
				'issues'    => $issues,
			);

			$key = ATK_Nb_Parser::normalise_name( $road );
			if ( ! isset( $index[ $key ] ) ) {
				$index[ $key ] = count( $out );
				$out[]         = $entry;
				continue;
			}
			// Same street again: add what is new, keep what is already there.
			$first = &$out[ $index[ $key ] ];
			$first['pairs']     = ATK_Route_Pairs::clean( array_merge( $first['pairs'], $entry['pairs'] ) );
			$first['postcodes'] = array_values( array_unique( array_merge( $first['postcodes'], $entry['postcodes'] ) ) );
			$first['wards']     = array_values( array_unique( array_merge( $first['wards'], $entry['wards'] ) ) );
			$first['issues']    = array_merge( $first['issues'], $entry['issues'] );
			if ( '' === $first['outcode'] ) {
				$first['outcode'] = $entry['outcode'];
			}
			if ( '' === $first['note'] ) {
				$first['note'] = $entry['note'];
			}
			if ( null === $first['clarify'] || false === $first['clarify'] ) {
				$first['clarify'] = null === $entry['clarify'] ? $first['clarify'] : $entry['clarify'];
			}
			unset( $first );
		}
		return $out;
	}

	/**
	 * Reads pasted text: tab-separated (copied from a spreadsheet), comma-separated
	 * with a header, or a plain list of street names, one per line.
	 */
	public static function parse_paste( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text  = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$lines = array_values( array_filter( explode( "\n", $text ), function ( $line ) {
			return '' !== trim( $line );
		} ) );
		if ( ! $lines ) {
			return array();
		}
		$tabbed = false !== strpos( $text, "\t" );
		$table  = array();
		foreach ( $lines as $line ) {
			$table[] = $tabbed ? explode( "\t", $line ) : str_getcsv( $line, ',', '"', '' );
		}
		$rows = self::parse_rows( $table );
		if ( $rows ) {
			return $rows;
		}
		// No header row: a list of names, one per line.
		$names = array( array( 'Road' ) );
		foreach ( $lines as $line ) {
			$names[] = array( trim( $line, " \t,;-*\xE2\x80\xA2" ) );
		}
		return self::parse_rows( $names );
	}
}

/**
 * Researched answers: From/To for each street, worked out from real OpenStreetMap data
 * (assets/data/route-research.json). Filling only ever adds answers to a street that has
 * none; an instructor's own answers are never replaced, and a street awaiting clarification
 * is left alone.
 */
class ATK_Route_Research {

	/** Flags a research entry may carry, so the file cannot put anything else on screen. */
	const FLAGS = array( 'joined_gap', 'branched', 'loop', 'dead_end_from', 'dead_end_to', 'ambiguous_segments', 'snapped_end', 'far_from_postcode', 'single_end' );

	/**
	 * Reads the research file's JSON into clean entries: name, from[], to[], confidence, flags[].
	 * An entry needs a name and at least one answer at each end.
	 *
	 * @return array[]
	 */
	public static function parse( $json ) {
		$data = json_decode( (string) $json, true );
		$out  = array();
		if ( ! is_array( $data ) || empty( $data['streets'] ) || ! is_array( $data['streets'] ) ) {
			return $out;
		}
		$list = function ( $value ) {
			$items = array();
			foreach ( is_array( $value ) ? $value : array() as $item ) {
				$item = is_scalar( $item ) ? trim( preg_replace( '/\s+/u', ' ', (string) $item ) ) : '';
				if ( '' !== $item ) {
					$items[] = mb_substr( $item, 0, ATK_Route_Pairs::MAX_LENGTH, 'UTF-8' );
				}
			}
			return $items;
		};
		foreach ( $data['streets'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$name = isset( $entry['name'] ) && is_scalar( $entry['name'] ) ? trim( (string) $entry['name'] ) : '';
			$from = $list( isset( $entry['from'] ) ? $entry['from'] : array() );
			$to   = $list( isset( $entry['to'] ) ? $entry['to'] : array() );
			if ( '' === $name || ! $from || ! $to ) {
				continue;
			}
			$confidence = isset( $entry['confidence'] ) && in_array( $entry['confidence'], array( 'high', 'medium', 'low' ), true ) ? $entry['confidence'] : 'medium';
			$flags      = array_values( array_intersect( self::FLAGS, isset( $entry['flags'] ) && is_array( $entry['flags'] ) ? $entry['flags'] : array() ) );
			$out[]      = array( 'name' => $name, 'from' => $from, 'to' => $to, 'confidence' => $confidence, 'flags' => $flags );
		}
		return $out;
	}

	/**
	 * What filling would do. $streets: id, name, pairs, flagged. A street is matched to its
	 * research by name, ignoring case, spacing and punctuation.
	 *
	 * @return array{fill: array[], kept: int, clarify: int, no_research: string[], unused: int, counts: array}
	 */
	public static function plan( array $streets, array $research ) {
		$index = array();
		foreach ( $research as $entry ) {
			$index[ ATK_Nb_Parser::normalise_name( $entry['name'] ) ] = $entry;
		}
		$used   = array();
		$fill   = array();
		$kept   = 0;
		$clarify = 0;
		$none   = array();
		$review = 0;
		foreach ( $streets as $street ) {
			$key   = ATK_Nb_Parser::normalise_name( $street['name'] );
			$entry = isset( $index[ $key ] ) ? $index[ $key ] : null;
			if ( ! empty( $street['flagged'] ) ) {
				if ( $entry ) {
					$used[ $key ] = true;
					$clarify++;
				}
				continue;
			}
			if ( ! $entry ) {
				$none[] = $street['name'];
				continue;
			}
			$used[ $key ] = true;
			if ( ! empty( $street['pairs'] ) ) {
				$kept++;
				continue;
			}
			$pairs = ATK_Route_Pairs::clean( ATK_Route_Pairs::cover( $entry['from'], $entry['to'] ) );
			if ( ! $pairs ) {
				continue;
			}
			$fill[] = array( 'id' => (int) $street['id'], 'name' => $street['name'], 'pairs' => $pairs, 'src' => array( 'c' => $entry['confidence'], 'f' => $entry['flags'] ) );
			if ( 'low' === $entry['confidence'] ) {
				$review++;
			}
		}
		return array(
			'fill'        => $fill,
			'kept'        => $kept,
			'clarify'     => $clarify,
			'no_research' => $none,
			'unused'      => max( 0, count( $index ) - count( $used ) ),
			'counts'      => array( 'fill' => count( $fill ), 'review' => $review ),
		);
	}
}

class ATK_Route_Planner {

	const TESTS_PER_WEEK = 5;
	const MIN_MINUTES    = 20;

	/** clarify (flag set), needs_answer (no pair yet) or ready. */
	public static function status( array $street ) {
		if ( ! empty( $street['flagged'] ) ) {
			return 'clarify';
		}
		if ( empty( $street['pairs'] ) ) {
			return 'needs_answer';
		}
		return 'ready';
	}

	/**
	 * How a ward name is compared with the official list: case, spaces around slashes and
	 * St = Street are ignored ("George St/Harbour" is the official "George Street/Harbour").
	 * Only for matching; the name that is stored and shown is never changed.
	 */
	public static function ward_match_key( $ward ) {
		return preg_replace( '/\bstreet\b/u', 'st', ATK_Poi_Parser::ward_key( $ward ) );
	}

	/** What a test is worth: every distinct answer of every street asked in it is one point. */
	public static function test_points( array $questions ) {
		$total = 0;
		foreach ( $questions as $question ) {
			$total += ATK_Route_Pairs::points( isset( $question['pairs'] ) ? $question['pairs'] : array() );
		}
		return $total;
	}

	/** A test lasts 20 minutes, or one minute a question when it is longer than that. */
	public static function minutes( $count ) {
		return max( self::MIN_MINUTES, (int) $count );
	}

	/**
	 * Divides the list evenly over the week's tests, keeping the order: with
	 * n streets, the first (n mod 5) tests get one extra. Nothing is dropped.
	 *
	 * @param array[] $items
	 * @return array[] self::TESTS_PER_WEEK lists
	 */
	public static function split_tests( array $items ) {
		$items = array_values( $items );
		$n     = count( $items );
		$base  = intdiv( $n, self::TESTS_PER_WEEK );
		$extra = $n % self::TESTS_PER_WEEK;
		$tests = array();
		$at    = 0;
		for ( $t = 0; $t < self::TESTS_PER_WEEK; $t++ ) {
			$size    = $base + ( $t < $extra ? 1 : 0 );
			$tests[] = array_slice( $items, $at, $size );
			$at     += $size;
		}
		return $tests;
	}

	/**
	 * What the Official Route test would hold, week by week.
	 *
	 * @param array[]  $streets  Each: id, name, wards (names), pairs, flagged.
	 * @param string[] $official The official ward names in order; week N is ward N.
	 * @return array{weeks: array[], no_ward: string[], outside: array[]}
	 */
	public static function course_plan( array $streets, array $official ) {
		$index = array();
		$weeks = array();
		foreach ( array_values( $official ) as $i => $ward ) {
			$index[ self::ward_match_key( $ward ) ] = $i;
			$weeks[ $i ] = array( 'number' => $i + 1, 'ward' => $ward, 'questions' => array(), 'flagged' => array(), 'no_answer' => array(), 'tests' => array(), 'minutes' => self::MIN_MINUTES );
		}
		$no_ward = array();
		$outside = array();
		foreach ( $streets as $street ) {
			$wards = array_values( array_filter( (array) $street['wards'], 'strlen' ) );
			if ( ! $wards ) {
				$no_ward[] = $street['name'];
				continue;
			}
			$hit = array();
			$odd = array();
			foreach ( $wards as $ward ) {
				$key = self::ward_match_key( $ward );
				if ( isset( $index[ $key ] ) ) {
					$hit[ $index[ $key ] ] = true;
				} else {
					$odd[] = $ward;
				}
			}
			if ( ! $hit ) {
				$outside[] = array( 'name' => $street['name'], 'ward' => implode( ', ', $odd ) );
				continue;
			}
			$status = self::status( $street );
			foreach ( array_keys( $hit ) as $i ) {
				if ( 'clarify' === $status ) {
					$weeks[ $i ]['flagged'][] = $street['name'];
				} elseif ( 'needs_answer' === $status ) {
					$weeks[ $i ]['no_answer'][] = $street['name'];
				} else {
					$weeks[ $i ]['questions'][] = array( 'street' => $street['name'], 'pairs' => array_values( $street['pairs'] ) );
				}
			}
		}
		foreach ( $weeks as $i => $week ) {
			usort( $weeks[ $i ]['questions'], function ( $a, $b ) {
				return strnatcasecmp( $a['street'], $b['street'] );
			} );
			natcasesort( $weeks[ $i ]['flagged'] );
			natcasesort( $weeks[ $i ]['no_answer'] );
			$weeks[ $i ]['flagged']   = array_values( $weeks[ $i ]['flagged'] );
			$weeks[ $i ]['no_answer'] = array_values( $weeks[ $i ]['no_answer'] );
			$weeks[ $i ]['tests']     = self::split_tests( $weeks[ $i ]['questions'] );
			$longest                  = 0;
			foreach ( $weeks[ $i ]['tests'] as $test ) {
				$longest = max( $longest, count( $test ) );
			}
			$weeks[ $i ]['minutes'] = self::minutes( $longest );
		}
		return array( 'weeks' => array_values( $weeks ), 'no_ward' => $no_ward, 'outside' => $outside );
	}
}
