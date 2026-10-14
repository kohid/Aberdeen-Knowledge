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
		$this->matcher = new ATK_Nb_Matcher( array_values( $road_names ) );
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
