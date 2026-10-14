<?php
/**
 * Page templates and page shells.
 *
 * The plugin's managed pages store a template file name in _wp_page_template
 * (atk_managed_pages() in inc/setup.php). Those names are kept exactly as the
 * old theme wrote them, so existing pages need no change: template_include
 * serves the matching file from templates/ whatever theme is active.
 *
 * Pages with no template of their own (profile, course, dashboard, ...) use
 * the active theme's ordinary page template and hold a shortcode; the plugin
 * only adds assets for them (inc/assets.php).
 */

defined( 'ABSPATH' ) || exit;

/** Template file => label, for the files in templates/ that are page templates. */
function akn_page_templates() {
	return array(
		'template-signin.php'                  => 'Sign In',
		'template-signup.php'                  => 'Sign Up',
		'template-forgot-password.php'         => 'Forgot Password',
		'template-reset-password.php'          => 'Reset Password',
		'template-study-major-streets.php'     => 'Study - Major Streets',
		'template-study-neighbourhoods.php'    => 'Study - Neighbourhoods',
		'template-study-places-of-interest.php' => 'Study - Places of Interest',
	);
}

/** Loads one half of a full-document shell ('auth' or 'map', 'header' or 'footer'). */
function akn_shell( $name, $part ) {
	$file = ATK_PLUGIN_DIR . '/templates/shell-' . $name . '-' . $part . '.php';
	if ( file_exists( $file ) ) {
		require $file;
	}
}

/**
 * Lists the templates in the page editor's Template box, so saving a managed
 * page does not reset its template just because the active theme has no such file.
 */
add_filter( 'theme_page_templates', function ( $templates ) {
	return array_merge( $templates, akn_page_templates() );
} );

/** Serves the plugin's file for a page that has one of the plugin's templates. */
add_filter( 'template_include', function ( $template ) {
	if ( ! is_page() ) {
		return $template;
	}
	$slug  = get_post_meta( get_queried_object_id(), '_wp_page_template', true );
	$known = akn_page_templates();
	if ( is_string( $slug ) && isset( $known[ $slug ] ) ) {
		$file = ATK_PLUGIN_DIR . '/templates/' . $slug;
		if ( file_exists( $file ) ) {
			return $file;
		}
	}
	return $template;
}, 99 );

/**
 * Buffers the page so a shortcode's own login / enrollment / valid-week gate
 * can still send a Location header after wp_head() has already printed
 * (atk_course_test, atk_course_hub, atk_require_login, ...). Without this
 * the redirect fails with "headers already sent". WordPress flushes the
 * buffer itself at shutdown.
 */
add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() || is_trackback() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	ob_start();
}, 0 );
