// Run with: node tests/streets/pair-tests.mjs
// Checks that the browser's From/To marking (assets/js/route-pairs.js) agrees with the PHP rules
// (ATK_Route_Pairs in inc/streets-engine.php) on the same inputs, so a reply is never marked
// differently on the page than it is by the server.
import { createRequire } from "node:module";
import { execFileSync } from "node:child_process";
const require = createRequire( import.meta.url );
const pairs = require( "../../assets/js/route-pairs.js" );

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

const php = JSON.parse( execFileSync( "php", [ new URL( "./pair-keys.php", import.meta.url ).pathname ], { encoding: "utf8" } ) );

php.keys.forEach( function ( row ) {
	check( "key of " + JSON.stringify( row[ 0 ] ), pairs.key( row[ 0 ] ), row[ 1 ] );
} );
php.matches.forEach( function ( row ) {
	check( "reply " + JSON.stringify( row[ 0 ] ) + " to " + JSON.stringify( row[ 1 ] ), pairs.matches( row[ 0 ], row[ 1 ], row[ 2 ] ), row[ 3 ] );
} );

php.ends.forEach( function ( row, i ) {
	check( "ends of set " + i, pairs.ends( row[ 0 ] ), row[ 1 ] );
} );
php.grades.forEach( function ( row, i ) {
	check( "grade " + i, pairs.grade( row[ 0 ], row[ 1 ], row[ 2 ] ), row[ 3 ] );
} );
php.sheets.forEach( function ( row, i ) {
	check( "answer sheet " + i, pairs.answerSheet( row[ 0 ] ), row[ 1 ] );
} );

check( "the label lists every pair", pairs.label( [ [ "A Road", "B Street" ], [ "C", "D" ] ] ), "A Road to B Street / C to D" );
check( "malformed pairs are skipped", pairs.matches( "A", "B", [ null, [ "A" ], "x", [ "A", "B" ] ] ), true );

console.log( "\n" + checks + " checks, " + failures + " failed" );
process.exit( failures ? 1 : 0 );
