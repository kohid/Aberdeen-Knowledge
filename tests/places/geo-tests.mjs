// Run with: node tests/places/geo-tests.mjs
// Covers the pin accept rule, the lookup queries and the Nominatim address.
import { createRequire } from "node:module";
const require = createRequire( import.meta.url );
const geo = require( "../../assets/js/poi-geo.js" );

let failures = 0;
let checks = 0;
function check( label, actual, expected ) {
	checks++;
	if ( JSON.stringify( actual ) === JSON.stringify( expected ) ) {
		console.log( "ok    " + label );
		return;
	}
	failures++;
	console.log( "FAIL  " + label + "\n      expected: " + JSON.stringify( expected ) + "\n      actual:   " + JSON.stringify( actual ) );
}

const theatre = { category: "amenity", type: "theatre", name: "His Majesty's Theatre", lat: "57.1488", lon: "-2.1019" };
check( "a named amenity inside the box is accepted", geo.acceptResult( theatre ), true );
check( "the class key is read too", geo.acceptResult( { class: "tourism", type: "museum", name: "Museum", lat: "57.15", lon: "-2.09" } ), true );
check( "a postcode boundary is rejected", geo.acceptResult( { class: "boundary", type: "postcode", name: "AB25 1GL", lat: "57.15", lon: "-2.1" } ), false );
check( "a suburb centre is rejected", geo.acceptResult( { class: "place", type: "suburb", name: "Rosemount", lat: "57.15", lon: "-2.1" } ), false );
check( "a road is rejected", geo.acceptResult( { class: "highway", type: "residential", name: "Rosemount Viaduct", lat: "57.15", lon: "-2.1" } ), false );
check( "an unnamed building is rejected", geo.acceptResult( { class: "building", type: "yes", lat: "57.15", lon: "-2.1" } ), false );
check( "an amenity outside the box is rejected", geo.acceptResult( { class: "amenity", type: "pub", name: "Pub", lat: "51.5", lon: "-0.12" } ), false );
check( "pick returns the first accepted result as numbers", geo.pick( [ { class: "place", type: "suburb", name: "x", lat: "57.15", lon: "-2.1" }, theatre ] ), { lat: 57.1488, lng: -2.1019 } );
check( "pick returns null when nothing passes", geo.pick( [ { class: "place", type: "suburb", name: "x", lat: "57.15", lon: "-2.1" } ] ), null );
check( "pick of nothing is null", geo.pick( [] ), null );
check( "inBox accepts Aberdeen", geo.inBox( 57.1497, -2.0943 ), true );
check( "inBox rejects London", geo.inBox( 51.5, -0.12 ), false );

check( "queries: name with postcode, then name with address", geo.buildQueries( { name: "22 CLUB", address: "55 Rose street, AB10 1UB", postcode: "AB10 1UB" } ), [ "22 CLUB, AB10 1UB", "22 CLUB, 55 Rose street, AB10 1UB" ] );
check( "queries: empty parts are skipped", geo.buildQueries( { name: "Babylon", address: "", postcode: "" } ), [] );
check( "queries: duplicates are dropped", geo.buildQueries( { name: "Babylon", address: "AB10 1YD", postcode: "AB10 1YD" } ), [ "Babylon, AB10 1YD" ] );

const url = geo.searchUrl( "22 CLUB, AB10 1UB" );
check( "the search is bounded to the box", url.indexOf( "bounded=1" ) > -1 && url.indexOf( "viewbox=-2.4,57.3,-1.95,57" ) > -1, true );
check( "the search is limited to Great Britain, jsonv2", url.indexOf( "countrycodes=gb" ) > -1 && url.indexOf( "format=jsonv2" ) > -1, true );
check( "the query is encoded", url.indexOf( "q=22%20CLUB%2C%20AB10%201UB" ) > -1, true );

console.log( "\n" + checks + " checks, " + failures + " failed" );
process.exit( failures ? 1 : 0 );
