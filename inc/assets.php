<?php
/**
 * Front-end assets. Everything the plugin needs ships inside the plugin
 * (assets/vendor), so nothing depends on a CDN or on files elsewhere in
 * wp-content:
 *
 *   Bootstrap 5.3 (MIT) ........ layout, components, colour modes
 *   Bootstrap Icons (MIT) ...... icons
 *   Select2 4.0 (MIT) .......... searchable pickers in the Course Builder (skinned in akn.css)
 *   Leaflet 1.9 (BSD-2) ........ maps, with OpenStreetMap tiles
 *   SheetJS 0.18 (Apache-2.0) .. reading .xlsx / .csv in the browser
 *   Poppins (OFL) .............. type, declared in akn.css
 *
 * assets/css/akn.css is the plugin's own stylesheet. No Metronic file is
 * shipped; see CLAUDE.md.
 *
 * Handles. The ported modules still depend on three handles from the theme's
 * Metronic days ('atk-plugins-bundle', 'atk-scripts-bundle', 'leaflet'). They
 * are kept as virtual handles that pull in the right bundled libraries, so
 * no module needed to change.
 */

defined( 'ABSPATH' ) || exit;

const AKN_BOOTSTRAP_VERSION = '5.3.3';
const AKN_SELECT2_VERSION   = '4.0.13';
const AKN_SHEETJS_VERSION   = '0.18.5';

/** Version string that changes whenever the file does, so a CDN in front of the site fetches a fresh copy after an update. */
function akn_asset_ver( $relative_path ) {
	$file = ATK_PLUGIN_DIR . $relative_path;
	return file_exists( $file ) ? (string) filemtime( $file ) : ATK_VERSION;
}

function akn_register_assets() {
	$uri = ATK_PLUGIN_URI;

	// Styles.
	wp_register_style( 'akn-bootstrap', $uri . '/assets/vendor/bootstrap/bootstrap.min.css', array(), AKN_BOOTSTRAP_VERSION );
	wp_register_style( 'akn-bootstrap-icons', $uri . '/assets/vendor/bootstrap-icons/bootstrap-icons.min.css', array(), '1.11.3' );
	wp_register_style( 'akn', $uri . '/assets/css/akn.css', array( 'akn-bootstrap', 'akn-bootstrap-icons' ), akn_asset_ver( '/assets/css/akn.css' ) );
	wp_add_inline_style( 'akn', akn_brand_inline_css() );

	wp_register_style( 'akn-select2', $uri . '/assets/vendor/select2/select2.min.css', array( 'akn' ), AKN_SELECT2_VERSION );

	wp_register_style( 'leaflet', $uri . '/assets/vendor/leaflet/leaflet.css', array(), ATK_LEAFLET_VERSION );

	// Scripts.
	wp_register_script( 'akn-bootstrap-js', $uri . '/assets/vendor/bootstrap/bootstrap.bundle.min.js', array(), AKN_BOOTSTRAP_VERSION, true );
	wp_register_script( 'akn-select2', $uri . '/assets/vendor/select2/select2.min.js', array( 'jquery' ), AKN_SELECT2_VERSION, true );
	wp_register_script( 'leaflet', $uri . '/assets/vendor/leaflet/leaflet.js', array(), ATK_LEAFLET_VERSION, true );
	wp_register_script( 'atk-nb-xlsx', $uri . '/assets/vendor/sheetjs/xlsx.full.min.js', array(), AKN_SHEETJS_VERSION, true );

	// Virtual handles kept for the ported modules (no file of their own).
	wp_register_script( 'atk-plugins-bundle', false, array( 'jquery', 'akn-bootstrap-js', 'akn-select2' ), ATK_VERSION, true );
	wp_register_script( 'atk-scripts-bundle', false, array( 'atk-plugins-bundle' ), ATK_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'akn_register_assets', 1 );

/**
 * Loads the stylesheet and Bootstrap's scripts. Safe to call more than once
 * and safe to call late (a shortcode running in the page body): styles
 * enqueued after <head> are printed in the footer.
 */
function akn_enqueue_app_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	if ( ! wp_style_is( 'akn', 'registered' ) ) {
		akn_register_assets();
	}
	$done = true;
	wp_enqueue_style( 'akn' );
	wp_enqueue_script( 'akn-bootstrap-js' );
}

/** True when this request's page is one the plugin manages or one that holds a plugin shortcode. */
function akn_page_needs_assets() {
	if ( ! is_singular() ) {
		return false;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return false;
	}
	$slugs = array();
	foreach ( atk_managed_pages() as $page ) {
		$slugs[] = $page['slug'];
	}
	if ( in_array( $post->post_name, $slugs, true ) ) {
		return true;
	}
	if ( false !== strpos( (string) $post->post_content, '[atk_' ) ) {
		return true;
	}
	$elementor = get_post_meta( $post->ID, '_elementor_data', true );
	return is_string( $elementor ) && false !== strpos( $elementor, '[atk_' );
}

/*
 * Priority 20, after the base theme (which enqueues at 10), so our stylesheet
 * prints after the theme's and wins a tie between equal selectors.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( akn_load_everywhere() || akn_page_needs_assets() ) {
		akn_enqueue_app_assets();
	}
}, 20 );

/** The plugin's own pages get the grey page canvas; the rest of the site keeps its background. */
add_filter( 'body_class', function ( $classes ) {
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			foreach ( atk_managed_pages() as $page ) {
				if ( $page['slug'] === $post->post_name ) {
					$classes[] = 'akn-app';
					break;
				}
			}
		}
	}
	return $classes;
} );

/** Any plugin shortcode that runs pulls the assets in, wherever it was placed (page, Elementor widget, header template). */
add_filter( 'do_shortcode_tag', function ( $output, $tag ) {
	if ( 0 === strpos( $tag, 'atk_' ) ) {
		akn_enqueue_app_assets();
	}
	return $output;
}, 10, 2 );

/** True when $target is queued, directly or as a dependency of anything queued. */
function akn_script_is_needed( $target ) {
	$scripts = wp_scripts();
	$seen    = array();
	$stack   = $scripts->queue;
	while ( $stack ) {
		$handle = array_pop( $stack );
		if ( $handle === $target ) {
			return true;
		}
		if ( isset( $seen[ $handle ] ) || empty( $scripts->registered[ $handle ] ) ) {
			continue;
		}
		$seen[ $handle ] = true;
		foreach ( (array) $scripts->registered[ $handle ]->deps as $dep ) {
			$stack[] = $dep;
		}
	}
	return false;
}

/** Select2's styles ride along whenever Select2's script is going to print. */
function akn_maybe_enqueue_select2_styles() {
	if ( akn_script_is_needed( 'akn-select2' ) ) {
		wp_enqueue_style( 'akn-select2' );
	}
}
add_action( 'wp_enqueue_scripts', 'akn_maybe_enqueue_select2_styles', 99 );
add_action( 'wp_footer', 'akn_maybe_enqueue_select2_styles', 5 );
