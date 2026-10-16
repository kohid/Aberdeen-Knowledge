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
		} ).join( " " );
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

	/** "A to B / C to D", for the review. */
	function label( pairs ) {
		return ( pairs || [] ).map( function ( pair ) { return pair[ 0 ] + " to " + pair[ 1 ]; } ).join( " / " );
	}

	return { key: key, matches: matches, label: label };
} ) );
