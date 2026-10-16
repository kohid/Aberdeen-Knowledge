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
		return implode( ' ', $words );
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
			$index[ ATK_Poi_Parser::ward_key( $ward ) ] = $i;
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
				$key = ATK_Poi_Parser::ward_key( $ward );
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
