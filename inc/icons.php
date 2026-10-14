<?php
/**
 * Icons. One open source icon set (Bootstrap Icons, MIT, bundled in
 * assets/vendor) is used everywhere. The study tools, course tracks and
 * sign-in cards ask for an icon by a short key; this maps each key to its
 * Bootstrap Icons name, so the markup that calls it never has to change.
 *
 * (The keys are the names the old theme used for its duotone map icons.
 * They are kept because course settings and callers store and pass them.)
 */

defined( 'ABSPATH' ) || exit;

/** Short key => Bootstrap Icons name (without the "bi-" prefix). */
function atk_icon_map() {
	return array(
		'map001' => 'flag-fill',               // Street Test
		'map003' => 'sign-turn-right-fill',    // Route Practice
		'map004' => 'globe-europe-africa',     // Quays to the City
		'map006' => 'compass-fill',            // Route Test
		'map008' => 'geo-alt-fill',            // Places of Interest, POI Test
		'map009' => 'signpost-split-fill',     // Major Streets
		'map010' => 'pin-angle-fill',          // POI Pindrop
		'gen001' => 'house-door-fill',         // Neighbourhoods
		'gen008' => 'grid-fill',               // Multiple Choice POI
	);
}

/**
 * Renders one icon in a span. $classes carries size and colour, the same
 * convention as before: "svg-icon svg-icon-2tx svg-icon-primary". The
 * stylesheet turns those into font size and colour.
 * Returns '' for an unknown key so a bad key fails quietly.
 */
function atk_render_icon( $name, $classes = 'svg-icon' ) {
	$map = atk_icon_map();
	if ( ! isset( $map[ $name ] ) ) {
		return '';
	}
	return sprintf(
		'<span class="%1$s"><i class="bi bi-%2$s" aria-hidden="true"></i></span>',
		esc_attr( $classes ),
		esc_attr( $map[ $name ] )
	);
}

/** Old name, kept so every existing caller keeps working. */
function atk_render_duotone_icon( $name, $classes = 'svg-icon' ) {
	return atk_render_icon( $name, $classes );
}
