<?php
/**
 * Neighbourhoods engine: reads a pasted or imported street sheet, matches its
 * roads to the site's street list, and works out what saving it would change.
 * Pure PHP with no WordPress calls, so every rule can be tested on its own
 * (tests/neighbourhoods/). Storage lives in inc/neighbourhoods.php.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ATK_NB_TESTING' ) ) {
	exit;
}

/**
 * Turns pasted text or spreadsheet rows into clean street records.
 * Pure PHP: no WordPress functions, so it can be tested on its own.
 */
class ATK_Nb_Parser {

	const POSTCODE_RE = '/\b([A-Z]{1,2}\d[A-Z\d]?)\s?(\d[A-Z]{2})\b/i';
	const OUTCODE_RE  = '/^[A-Z]{1,2}\d[A-Z\d]?$/';

	/** Upper-case, drop apostrophes, turn other punctuation into single spaces. */
	public static function normalise_name( $name ) {
		$name = mb_strtoupper( (string) $name, 'UTF-8' );
		$name = str_replace( array( "'", "\u{2018}", "\u{2019}", '`' ), '', $name );
		$name = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name );
		return trim( preg_replace( '/\s+/', ' ', $name ) );
	}

	/** "A or B" becomes [A, B]. Slashes inside a ward name are left alone. */
	public static function split_wards( $text ) {
		$parts = preg_split( '/\s+or\s+|\s*;\s*|\r?\n/i', (string) $text );
		$out   = array();
		$seen  = array();
		foreach ( $parts as $part ) {
			$part = trim( preg_replace( '/\s+/', ' ', $part ) );
			if ( '' === $part ) {
				continue;
			}
			$key = mb_strtolower( $part, 'UTF-8' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $part;
		}
		return $out;
	}

	/** Finds every full UK postcode in free text and formats it as "AB25 1AB". */
	public static function extract_postcodes( $text ) {
		$out = array();
		if ( preg_match_all( self::POSTCODE_RE, (string) $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $hit ) {
				$pc         = strtoupper( $hit[1] ) . ' ' . strtoupper( $hit[2] );
				$out[ $pc ] = $pc;
			}
		}
		return array_values( $out );
	}

	public static function outcode_of( $postcode ) {
		$parts = explode( ' ', trim( (string) $postcode ) );
		return strtoupper( $parts[0] );
	}

	/** Accepts "AB25", or a full postcode (its outcode is used). Anything else gives an empty string. */
	public static function parse_outcode( $text ) {
		$text = strtoupper( trim( (string) $text ) );
		if ( preg_match( self::OUTCODE_RE, $text ) ) {
			return $text;
		}
		$full = self::extract_postcodes( $text );
		return $full ? self::outcode_of( $full[0] ) : '';
	}

	/** Reads a header row. Returns column positions, or null when it is not a header. */
	public static function map_header( array $row ) {
		$map       = array();
		$addresses = array();
		foreach ( $row as $i => $cell ) {
			$key = strtolower( preg_replace( '/[^a-z]/i', '', (string) $cell ) );
			if ( in_array( $key, array( 'road', 'roadname', 'street', 'streetname' ), true ) ) {
				$map['road'] = $i;
			} elseif ( in_array( $key, array( 'postcode', 'outcode', 'district' ), true ) ) {
				$map['outcode'] = $i;
			} elseif ( in_array( $key, array( 'address', 'postcodes', 'fullpostcode', 'fullpostcodes' ), true ) ) {
				$addresses[] = $i;
			} elseif ( in_array( $key, array( 'ward', 'wards', 'neighbourhood', 'neighbourhoods' ), true ) ) {
				$map['ward'] = $i;
			} elseif ( in_array( $key, array( 'note', 'notes' ), true ) ) {
				$map['note'] = $i;
			}
		}
		if ( ! isset( $map['road'] ) ) {
			return null;
		}
		// Older sheets have two columns both called "Address": outcode first, full postcodes second.
		if ( count( $addresses ) >= 2 && ! isset( $map['outcode'] ) ) {
			$map['outcode']   = $addresses[0];
			$map['postcodes'] = $addresses[1];
		} elseif ( $addresses ) {
			$map['postcodes'] = end( $addresses );
		}
		return $map;
	}

	private static function cell( array $row, $map, $key ) {
		if ( ! isset( $map[ $key ] ) || ! isset( $row[ $map[ $key ] ] ) ) {
			return '';
		}
		$value = $row[ $map[ $key ] ];
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function row_is_empty( array $row ) {
		foreach ( $row as $cell ) {
			if ( is_scalar( $cell ) && '' !== trim( (string) $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/** Parses a table of rows, with or without a header row. */
	public static function parse_rows( array $rows ) {
		$rows = array_values( $rows );
		$map  = null;
		$from = 0;
		foreach ( $rows as $i => $row ) {
			if ( self::row_is_empty( $row ) ) {
				continue;
			}
			$map = self::map_header( $row );
			if ( $map ) {
				$from = $i + 1;
			}
			break;
		}
		if ( ! $map ) {
			$map = array( 'road' => 0, 'outcode' => 1, 'postcodes' => 2, 'ward' => 3, 'note' => 4 );
		}

		$out = array();
		for ( $i = $from; $i < count( $rows ); $i++ ) {
			$row = $rows[ $i ];
			if ( self::row_is_empty( $row ) ) {
				continue;
			}
			$road          = trim( preg_replace( '/\s+/', ' ', self::cell( $row, $map, 'road' ) ) );
			$outcode_text  = self::cell( $row, $map, 'outcode' );
			$postcode_text = self::cell( $row, $map, 'postcodes' );
			$postcodes     = self::extract_postcodes( $postcode_text );
			$outcode       = self::parse_outcode( $outcode_text );
			if ( '' === $outcode && $postcodes ) {
				$outcode = self::outcode_of( $postcodes[0] );
			}
			$issues = array();
			if ( '' === $road ) {
				$issues[] = 'This row has no road name.';
			}
			if ( '' !== $postcode_text && ! $postcodes ) {
				$issues[] = 'No full postcode found in "' . $postcode_text . '".';
			}
			if ( '' !== $outcode ) {
				foreach ( $postcodes as $pc ) {
					if ( self::outcode_of( $pc ) !== $outcode ) {
						$issues[] = $pc . ' is outside ' . $outcode . '.';
					}
				}
			}
			$out[] = array(
				'line'      => $i + 1,
				'road'      => $road,
				'outcode'   => $outcode,
				'postcodes' => $postcodes,
				'wards'     => self::split_wards( self::cell( $row, $map, 'ward' ) ),
				'note'      => self::cell( $row, $map, 'note' ),
				'issues'    => $issues,
			);
		}
		return $out;
	}

	/** Plain street names, one per line. */
	public static function names_to_rows( array $lines ) {
		$out = array();
		foreach ( array_values( $lines ) as $i => $line ) {
			$road = trim( preg_replace( '/\s+/', ' ', trim( $line, " \t,;-*\xE2\x80\xA2" ) ) );
			if ( '' === $road ) {
				continue;
			}
			$out[] = array(
				'line'      => $i + 1,
				'road'      => $road,
				'outcode'   => '',
				'postcodes' => array(),
				'wards'     => array(),
				'note'      => '',
				'issues'    => array(),
			);
		}
		return $out;
	}

	/**
	 * Reads pasted text. Tab-separated text (copied from a spreadsheet) is a table,
	 * a comma header such as "Road, Post Code, ..." is csv, anything else is a list of names.
	 *
	 * @return array{mode:string, rows:array}
	 */
	public static function parse_paste( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text  = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$lines = array_values(
			array_filter(
				explode( "\n", $text ),
				function ( $line ) {
					return '' !== trim( $line );
				}
			)
		);
		if ( ! $lines ) {
			return array( 'mode' => 'names', 'rows' => array() );
		}

		foreach ( $lines as $line ) {
			if ( false !== strpos( $line, "\t" ) ) {
				$table = array();
				foreach ( $lines as $l ) {
					$table[] = explode( "\t", $l );
				}
				return array( 'mode' => 'table', 'rows' => self::parse_rows( $table ) );
			}
		}

		if ( preg_match( '/^\s*(road|street)[a-z ]*,/i', $lines[0] ) ) {
			$table = array();
			foreach ( $lines as $l ) {
				$table[] = str_getcsv( $l, ',', '"', '\\' );
			}
			return array( 'mode' => 'table', 'rows' => self::parse_rows( $table ) );
		}

		return array( 'mode' => 'names', 'rows' => self::names_to_rows( $lines ) );
	}
}

/**
 * Matches a road name from a sheet to an existing street.
 * Pure PHP: takes a plain id => title array, so it can be tested on its own.
 */
class ATK_Nb_Matcher {

	/** @var array<int,string> */
	private $titles = array();

	/** @var array<string,int[]> normalised name => street ids */
	private $index = array();

	public function __construct( array $id_to_title ) {
		foreach ( $id_to_title as $id => $title ) {
			$key = ATK_Nb_Parser::normalise_name( $title );
			if ( '' === $key ) {
				continue;
			}
			$this->titles[ (int) $id ] = $title;
			$this->index[ $key ][]     = (int) $id;
		}
	}

	public function title( $id ) {
		return isset( $this->titles[ (int) $id ] ) ? $this->titles[ (int) $id ] : '';
	}

	public function has( $id ) {
		return isset( $this->titles[ (int) $id ] );
	}

	/**
	 * @return array{status:string,id:?int,ids:int[],suggestion:?array}
	 */
	public function match( $road ) {
		$key = ATK_Nb_Parser::normalise_name( $road );
		if ( '' === $key ) {
			return array( 'status' => 'invalid', 'id' => null, 'ids' => array(), 'suggestion' => null );
		}
		if ( isset( $this->index[ $key ] ) ) {
			$ids = $this->index[ $key ];
			if ( 1 === count( $ids ) ) {
				return array( 'status' => 'matched', 'id' => $ids[0], 'ids' => $ids, 'suggestion' => null );
			}
			return array( 'status' => 'ambiguous', 'id' => null, 'ids' => $ids, 'suggestion' => null );
		}
		return array( 'status' => 'unmatched', 'id' => null, 'ids' => array(), 'suggestion' => $this->nearest( $key ) );
	}

	/** Closest existing name, offered as a suggestion only. */
	private function nearest( $key ) {
		if ( strlen( $key ) < 5 ) {
			return null;
		}
		$limit = strlen( $key ) >= 12 ? 3 : 2;
		$best  = null;
		$bestd = PHP_INT_MAX;
		foreach ( $this->index as $candidate => $ids ) {
			if ( abs( strlen( $candidate ) - strlen( $key ) ) > $limit || count( $ids ) !== 1 ) {
				continue;
			}
			$distance = levenshtein( $candidate, $key );
			if ( $distance < $bestd ) {
				$bestd = $distance;
				$best  = $ids[0];
			}
		}
		if ( null === $best || $bestd > $limit ) {
			return null;
		}
		return array( 'id' => $best, 'title' => $this->titles[ $best ], 'distance' => $bestd );
	}
}

/**
 * Works out what an import would change, without touching the database.
 * Pure PHP: the store hands it plain arrays, so every rule here can be tested.
 */
class ATK_Nb_Planner {

	/**
	 * @param array $rows Output of ATK_Nb_Parser.
	 * @param array $ctx  titles (id => title), terms (term id => [id, name]), street_terms (street id => term ids),
	 *                    meta (street id => [outcode, postcodes, note]), target (term id or 0),
	 *                    choices (line => street id), hue (callable name => int).
	 * @return array{rows:array,summary:array}
	 */
	public static function plan( array $rows, array $ctx ) {
		$matcher   = new ATK_Nb_Matcher( $ctx['titles'] );
		$hue       = ( isset( $ctx['hue'] ) && is_callable( $ctx['hue'] ) ) ? $ctx['hue'] : function () {
			return 0;
		};
		$choices   = isset( $ctx['choices'] ) ? $ctx['choices'] : array();
		$canonical = array();
		foreach ( $ctx['terms'] as $term ) {
			$canonical[ self::lower( $term['name'] ) ] = $term['name'];
		}
		$target_id = isset( $ctx['target'] ) ? (int) $ctx['target'] : 0;
		$target    = ( $target_id && isset( $ctx['terms'][ $target_id ] ) ) ? $ctx['terms'][ $target_id ]['name'] : '';

		$summary = array(
			'total'              => 0,
			'ready'              => 0,
			'unchanged'          => 0,
			'shared'             => 0,
			'attention'          => 0,
			'warnings'           => 0,
			'new_neighbourhoods' => array(),
		);
		$out     = array();

		foreach ( $rows as $row ) {
			$summary['total']++;
			$match     = $matcher->match( $row['road'] );
			$status    = $match['status'];
			$street_id = $match['id'];

			if ( 'unmatched' === $status && isset( $choices[ $row['line'] ] ) && $matcher->has( (int) $choices[ $row['line'] ] ) ) {
				$street_id = (int) $choices[ $row['line'] ];
				$status    = 'matched';
			}

			$has_own = ! empty( $row['wards'] );
			$wards   = $has_own ? $row['wards'] : ( '' !== $target ? array( $target ) : array() );
			foreach ( $wards as $i => $ward ) {
				$key = self::lower( $ward );
				if ( isset( $canonical[ $key ] ) ) {
					$wards[ $i ] = $canonical[ $key ];
				}
			}

			$result = array(
				'line'         => $row['line'],
				'road'         => $row['road'],
				'status'       => $status,
				'street_id'    => $street_id,
				'street_title' => $street_id ? $matcher->title( $street_id ) : '',
				'suggestion'   => $match['suggestion'],
				'ids'          => $match['ids'],
				'issues'       => $row['issues'],
				'note'         => $row['note'],
				'wards'        => array(),
				'ward_chips'   => array(),
				'added'        => array(),
				'removed'      => array(),
				'changes'      => array(),
				'updates'      => array(),
				'shared'       => false,
			);

			if ( 'matched' === $status ) {
				$current = array();
				$tids    = isset( $ctx['street_terms'][ $street_id ] ) ? $ctx['street_terms'][ $street_id ] : array();
				foreach ( $tids as $tid ) {
					if ( isset( $ctx['terms'][ $tid ] ) ) {
						$current[] = $ctx['terms'][ $tid ]['name'];
					}
				}

				$final = $has_own ? $wards : self::union( $current, $wards );
				if ( ! $final ) {
					$result['status'] = 'no_ward';
				} else {
					$result['wards']   = $final;
					$result['added']   = self::diff( $final, $current );
					$result['removed'] = $has_own ? self::diff( $current, $final ) : array();
					foreach ( $final as $name ) {
						$result['ward_chips'][] = array(
							'name'   => $name,
							'hue'    => (int) call_user_func( $hue, $name ),
							'is_new' => ! isset( $canonical[ self::lower( $name ) ] ),
						);
					}

					$meta = array( 'outcode' => '', 'postcodes' => array(), 'note' => '' );
					if ( isset( $ctx['meta'][ $street_id ] ) ) {
						$meta = array_merge( $meta, $ctx['meta'][ $street_id ] );
					}
					self::meta_changes( $row, $meta, $result );

					$changed          = $result['added'] || $result['removed'] || $result['changes'];
					$result['status'] = $changed ? 'ready' : 'unchanged';
					$result['shared'] = count( $final ) > 1;
				}
			}

			if ( 'ready' === $result['status'] ) {
				$summary['ready']++;
				foreach ( $result['ward_chips'] as $chip ) {
					if ( $chip['is_new'] ) {
						$summary['new_neighbourhoods'][ self::lower( $chip['name'] ) ] = $chip['name'];
					}
				}
			} elseif ( 'unchanged' === $result['status'] ) {
				$summary['unchanged']++;
			} else {
				$summary['attention']++;
			}
			if ( $result['shared'] ) {
				$summary['shared']++;
			}
			$out[] = $result;
		}

		// The same street twice in one file: both rows would be saved in order, so the later one wins. Say so.
		$saved_states = array( 'ready', 'unchanged' );
		$first_line   = array();
		foreach ( $out as $i => $result ) {
			if ( ! $result['street_id'] || ! in_array( $result['status'], $saved_states, true ) ) {
				continue;
			}
			$id = $result['street_id'];
			if ( isset( $first_line[ $id ] ) ) {
				$out[ $i ]['issues'][] = 'This street is also on row ' . $first_line[ $id ] . '. The later row is saved last, so it wins.';
			} else {
				$first_line[ $id ] = $result['line'];
			}
		}
		foreach ( $out as $result ) {
			if ( $result['issues'] && in_array( $result['status'], $saved_states, true ) ) {
				$summary['warnings']++;
			}
		}

		$summary['new_neighbourhoods'] = array_values( $summary['new_neighbourhoods'] );
		return array( 'rows' => $out, 'summary' => $summary );
	}

	/** Only fields the sheet actually provides are compared, so an empty cell never wipes saved data. */
	private static function meta_changes( array $row, array $meta, array &$result ) {
		if ( $row['postcodes'] ) {
			$old = (array) $meta['postcodes'];
			$new = $row['postcodes'];
			$a   = $old;
			$b   = $new;
			sort( $a );
			sort( $b );
			if ( $a !== $b ) {
				$result['changes'][]              = array(
					'field' => 'Postcodes',
					'from'  => $old ? implode( ', ', $old ) : 'none',
					'to'    => implode( ', ', $new ),
				);
				$result['updates']['postcodes'] = $new;
			}
		}
		if ( '' !== $row['outcode'] && $row['outcode'] !== $meta['outcode'] ) {
			$result['changes'][]            = array(
				'field' => 'Post code area',
				'from'  => '' !== $meta['outcode'] ? $meta['outcode'] : 'none',
				'to'    => $row['outcode'],
			);
			$result['updates']['outcode'] = $row['outcode'];
		}
		if ( '' !== $row['note'] && $row['note'] !== $meta['note'] ) {
			$result['changes'][]          = array(
				'field' => 'Instructor note',
				'from'  => '' !== $meta['note'] ? $meta['note'] : 'none',
				'to'    => $row['note'],
			);
			$result['updates']['note'] = $row['note'];
		}
	}

	private static function lower( $text ) {
		return mb_strtolower( (string) $text, 'UTF-8' );
	}

	private static function union( array $a, array $b ) {
		$out = array();
		foreach ( array_merge( $a, $b ) as $item ) {
			$out[ self::lower( $item ) ] = isset( $out[ self::lower( $item ) ] ) ? $out[ self::lower( $item ) ] : $item;
		}
		return array_values( $out );
	}

	/** Items of $a that are not in $b, ignoring case. */
	private static function diff( array $a, array $b ) {
		$skip = array();
		foreach ( $b as $item ) {
			$skip[ self::lower( $item ) ] = true;
		}
		$out = array();
		foreach ( $a as $item ) {
			if ( ! isset( $skip[ self::lower( $item ) ] ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}
}
