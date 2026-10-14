// Checks that assets/js/akn-brand.js produces exactly the palette inc/brand-engine.php does.
// Run: node tests/brand/parity.mjs
import { createRequire } from "module";
import { execFileSync } from "child_process";
import { fileURLToPath } from "url";
import path from "path";

const here = path.dirname( fileURLToPath( import.meta.url ) );
const root = path.resolve( here, "../.." );
const Brand = createRequire( import.meta.url )( path.join( root, "assets/js/akn-brand.js" ) );

const colors = [ "#F69216", "#FFFF00", "#FFFFFF", "#000000", "#0D6EFD", "#00AA55", "#7239EA", "#F1416C", "#808080", "#123456", "#FFC700", "#A0522D", "#E91E63" ];
let fail = 0;
for ( const c of colors ) {
	const php = JSON.parse( execFileSync( "php", [ "-r", `define("AKN_TESTING",true); require "${ root }/inc/brand-engine.php"; echo json_encode(AKN_Brand::palette("${ c }"));` ] ).toString() );
	const js = Brand.palette( c );
	if ( JSON.stringify( php ) !== JSON.stringify( js ) ) {
		fail++;
		console.log( "MISMATCH", c, "\n php", JSON.stringify( php ), "\n js ", JSON.stringify( js ) );
	}
}
console.log( fail ? `${ fail } mismatches` : `PHP and JS palettes match for ${ colors.length } colours` );
process.exit( fail ? 1 : 0 );
