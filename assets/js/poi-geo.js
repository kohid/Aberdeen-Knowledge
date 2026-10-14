"use strict";

/**
 * Pin rules for Places of Interest. A place gets a map pin from an OpenStreetMap
 * lookup only when the result is a named, real feature inside the Aberdeen area,
 * never a postcode area, boundary or suburb centre, and never a road.
 *
 * Plain functions with no page dependencies, so they run in the browser
 * (window.ATK_POI_GEO) and under Node (tests/places/geo-tests.mjs). The server
 * checks the same box again before it stores a coordinate (ATK_Poi_Geo).
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module !== "undefined" && module.exports ) {
		module.exports = api;
	} else {
		root.ATK_POI_GEO = api;
	}
}( typeof window !== "undefined" ? window : this, function () {
	var BOX = { latMin: 57.00, latMax: 57.30, lngMin: -2.40, lngMax: -1.95 };

	// OpenStreetMap classes that mean a real place. Schools, libraries, clubs and
	// places of worship are all "amenity".
	var ALLOWED_CLASSES = [ "amenity", "tourism", "leisure", "shop", "office", "historic", "building" ];

	function inBox( lat, lng ) {
		return lat >= BOX.latMin && lat <= BOX.latMax && lng >= BOX.lngMin && lng <= BOX.lngMax;
	}

	function acceptResult( result ) {
		if ( ! result ) {
			return false;
		}
		var cls  = result.class || result.category || "";
		var name = typeof result.name === "string" ? result.name.trim() : "";
		var lat  = parseFloat( result.lat );
		var lng  = parseFloat( result.lon );
		if ( "" === name || isNaN( lat ) || isNaN( lng ) ) {
			return false;
		}
		return ALLOWED_CLASSES.indexOf( cls ) !== -1 && inBox( lat, lng );
	}

	function pick( results ) {
		for ( var i = 0; i < ( results || [] ).length; i++ ) {
			if ( acceptResult( results[ i ] ) ) {
				return { lat: parseFloat( results[ i ].lat ), lng: parseFloat( results[ i ].lon ) };
			}
		}
		return null;
	}

	/** The searches to try, in order: name with postcode, then name with address. */
	function buildQueries( place ) {
		var name  = ( place.name || "" ).trim();
		var found = [];
		if ( "" === name ) {
			return found;
		}
		[ place.postcode, place.address ].forEach( function ( part ) {
			part = ( part || "" ).trim();
			var query = name + ", " + part;
			if ( "" !== part && found.indexOf( query ) === -1 ) {
				found.push( query );
			}
		} );
		return found;
	}

	function searchUrl( query ) {
		return "https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&countrycodes=gb&bounded=1"
			+ "&viewbox=" + [ BOX.lngMin, BOX.latMax, BOX.lngMax, BOX.latMin ].join( "," )
			+ "&q=" + encodeURIComponent( query );
	}

	return { BOX: BOX, inBox: inBox, acceptResult: acceptResult, pick: pick, buildQueries: buildQueries, searchUrl: searchUrl };
} ) );
