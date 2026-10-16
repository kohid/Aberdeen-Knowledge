"use strict";

/**
 * Marking for the Official Route test: the student types where a street runs From and To,
 * and the reply is right when it is any listed pair, in either order. Both boxes must be
 * filled, and the same place in both boxes is wrong.
 *
 * Plain functions with no page dependencies, so they run in the browser
 * (window.ATK_ROUTE_PAIRS) and under Node (tests/streets/pair-tests.mjs). They repeat the
 * rules of ATK_Route_Pairs in inc/streets-engine.php, and the Node test checks the two agree.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module !== "undefined" && module.exports ) {
		module.exports = api;
	} else {
		root.ATK_ROUTE_PAIRS = api;
	}
}( typeof window !== "undefined" ? window : this, function () {
	var FOLD = { STREET: "ST", ROAD: "RD", AVENUE: "AVE", DRIVE: "DR", BOULEVARD: "BLVD" };

	/** Case, apostrophes and punctuation are ignored; Street = St, Road = Rd, Avenue = Ave, Drive = Dr, Boulevard = Blvd. */
	function key( text ) {
		var name = String( text === null || text === undefined ? "" : text ).toUpperCase()
			.replace( /['‘’`]/g, "" )
			.replace( /[^\p{L}\p{N}]+/gu, " " )
			.replace( /\s+/g, " " )
			.trim();
		if ( name === "" ) {
			return "";
		}
		return name.split( " " ).map( function ( word ) {
			return Object.prototype.hasOwnProperty.call( FOLD, word ) ? FOLD[ word ] : word;
		} ).join( " " ).replace( /\b(?:CUL DE SAC|CULDESAC|DEAD END|DEADEND)\b/g, "DEAD END" ); // a dead end is an answer, in any spelling
	}

	/** True when from/to is any listed pair (array of [from, to]) in either order. */
	function matches( from, to, pairs ) {
		var a = key( from );
		var b = key( to );
		if ( a === "" || b === "" || a === b ) {
			return false;
		}
		return ( pairs || [] ).some( function ( pair ) {
			if ( ! Array.isArray( pair ) || pair.length < 2 ) {
				return false;
			}
			var x = key( pair[ 0 ] );
			var y = key( pair[ 1 ] );
			return ( x === a && y === b ) || ( x === b && y === a );
		} );
	}

	/** The distinct From and To answers of a street, each once (by key), in order. One field and one point each. */
	function ends( pairs ) {
		var out = { from: [], to: [] };
		var seen = { from: {}, to: {} };
		( pairs || [] ).forEach( function ( pair ) {
			if ( ! Array.isArray( pair ) || pair.length < 2 ) {
				return;
			}
			[ [ "from", pair[ 0 ] ], [ "to", pair[ 1 ] ] ].forEach( function ( side ) {
				var k = key( side[ 1 ] );
				if ( k !== "" && ! seen[ side[ 0 ] ][ k ] ) {
					seen[ side[ 0 ] ][ k ] = true;
					out[ side[ 0 ] ].push( String( side[ 1 ] ) );
				}
			} );
		} );
		return out;
	}

	/** How many distinct answers the typed entries hit (blank entries never count). */
	function hits( entries, answers ) {
		var typed = {};
		( entries || [] ).forEach( function ( entry ) {
			var k = key( entry );
			if ( k !== "" ) {
				typed[ k ] = true;
			}
		} );
		var found = {};
		var n = 0;
		( answers || [] ).forEach( function ( answer ) {
			var k = key( answer );
			if ( k !== "" && typed[ k ] && ! found[ k ] ) {
				found[ k ] = true;
				n++;
			}
		} );
		return n;
	}

	/**
	 * Marks a reply by points: one per distinct answer matched, From and To either way round
	 * (the better of the two), blanks allowed, the same place twice counted once.
	 */
	function grade( pairs, givenFrom, givenTo ) {
		var e = ends( pairs );
		var straight = hits( givenFrom, e.from ) + hits( givenTo, e.to );
		var swapped = hits( givenFrom, e.to ) + hits( givenTo, e.from );
		return { points: Math.max( straight, swapped ), max: e.from.length + e.to.length };
	}

	/** Every answer for the review: "From: A / B — To: C". */
	function answerSheet( pairs ) {
		var e = ends( pairs );
		return "From: " + e.from.join( " / " ) + " \u2014 To: " + e.to.join( " / " );
	}

	/** "A to B / C to D", for the review. */
	function label( pairs ) {
		return ( pairs || [] ).map( function ( pair ) { return pair[ 0 ] + " to " + pair[ 1 ]; } ).join( " / " );
	}

	return { key: key, matches: matches, label: label, ends: ends, grade: grade, answerSheet: answerSheet };
} ) );
