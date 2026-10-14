<?php
/**
 * Plain-PHP tests for the brand colour maths. Run: php tests/brand/run-tests.php
 */
define( 'AKN_TESTING', true );
require __DIR__ . '/../../inc/brand-engine.php';

$pass = 0;
$fail = 0;
function check( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: $label $detail\n";
	}
}

// sanitize
check( 'sanitize full', AKN_Brand::sanitize( '#f69216' ) === '#F69216' );
check( 'sanitize no hash', AKN_Brand::sanitize( 'f69216' ) === '#F69216' );
check( 'sanitize short', AKN_Brand::sanitize( '#0af' ) === '#00AAFF' );
check( 'sanitize junk', AKN_Brand::sanitize( 'red' ) === AKN_Brand::DEFAULT_COLOR );
check( 'sanitize injection', AKN_Brand::sanitize( '#fff;}body{display:none' ) === AKN_Brand::DEFAULT_COLOR );
check( 'sanitize array', AKN_Brand::sanitize( array( 'x' ) ) === AKN_Brand::DEFAULT_COLOR );
check( 'sanitize empty', AKN_Brand::sanitize( '' ) === AKN_Brand::DEFAULT_COLOR );

// round trip hex -> hsl -> hex stays within 1 per channel
foreach ( array( '#F69216', '#0D6EFD', '#00AA55', '#FFFF00', '#123456', '#808080' ) as $hex ) {
	$back = AKN_Brand::rgb_to_hex( AKN_Brand::hsl_to_rgb( AKN_Brand::rgb_to_hsl( AKN_Brand::hex_to_rgb( $hex ) ) ) );
	$a    = AKN_Brand::hex_to_rgb( $hex );
	$b    = AKN_Brand::hex_to_rgb( $back );
	check( "round trip $hex", max( abs( $a[0] - $b[0] ), abs( $a[1] - $b[1] ), abs( $a[2] - $b[2] ) ) <= 1, "$hex -> $back" );
}

// contrast reference values
check( 'contrast black/white is 21', abs( AKN_Brand::contrast( '#000000', '#FFFFFF' ) - 21 ) < 0.01 );
check( 'contrast same is 1', abs( AKN_Brand::contrast( '#F69216', '#F69216' ) - 1 ) < 0.001 );

// Every colour gives readable ink and the better text colour.
$samples = array( '#F69216', '#FFFF00', '#FFFFFF', '#000000', '#0D6EFD', '#00AA55', '#7239EA', '#F1416C', '#808080', '#123456', '#FFC700' );
foreach ( $samples as $hex ) {
	$p = AKN_Brand::palette( $hex );
	check( "$hex ink on light >= 4.5", AKN_Brand::contrast( $p['light']['--akn-brand-ink'], AKN_Brand::SURFACE_LIGHT ) >= 4.5, $p['light']['--akn-brand-ink'] );
	check( "$hex ink on dark >= 4.5", AKN_Brand::contrast( $p['dark']['--akn-brand-ink'], AKN_Brand::SURFACE_DARK ) >= 4.5, $p['dark']['--akn-brand-ink'] );
	$on    = $p['light']['--akn-brand-on'];
	$other = '#FFFFFF' === $on ? AKN_Brand::INK_DARK : '#FFFFFF';
	check( "$hex on-colour is the better one or passes", AKN_Brand::contrast( $hex, $on ) >= AKN_Brand::contrast( $hex, $other ) || AKN_Brand::contrast( $hex, '#FFFFFF' ) >= 4.5 );
	check( "$hex hover is not equal to base unless extreme", $p['light']['--akn-brand-hover'] !== $hex || in_array( $hex, array( '#000000' ), true ) );
	check( "$hex rgb triple", 3 === count( explode( ',', $p['light']['--akn-brand-rgb'] ) ) );
}

// The default orange: white on orange fails 4.5, so dark ink is chosen; links use a darker orange.
$d = AKN_Brand::palette( AKN_Brand::DEFAULT_COLOR );
check( 'default on-colour is dark ink', $d['light']['--akn-brand-on'] === AKN_Brand::INK_DARK );
check( 'default ink is darker than brand', AKN_Brand::rgb_to_hsl( AKN_Brand::hex_to_rgb( $d['light']['--akn-brand-ink'] ) )[2] < AKN_Brand::rgb_to_hsl( AKN_Brand::hex_to_rgb( AKN_Brand::DEFAULT_COLOR ) )[2] );
check( 'default dark ink stays the brand colour', $d['dark']['--akn-brand-ink'] === AKN_Brand::DEFAULT_COLOR );

// css output is a closed, injection-safe string
$css = AKN_Brand::css( '#fff;}body{display:none' );
check( 'css falls back to default', false !== strpos( $css, '--akn-brand:#F69216;' ) && false === strpos( $css, 'display:none' ) );
check( 'css has dark block', false !== strpos( $css, '[data-bs-theme=dark]{' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
