<?php
/**
 * Plugin Name:       Aberdeen Knowledge
 * Plugin URI:        https://github.com/kohid/Aberdeen-Knowledge
 * Description:       Taxi knowledge test preparation: Major Streets, Neighbourhoods and Places of Interest map tools, mockup tests, an Official Test course, driver and instructor accounts, and an Instructor Dashboard. Maps use Leaflet with OpenStreetMap. The brand colour is a setting.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Jay Miralles
 * Text Domain:       aberdeen-knowledge
 * Update URI:        https://github.com/kohid/Aberdeen-Knowledge
 */

defined( 'ABSPATH' ) || exit;

define( 'ATK_VERSION', '1.1.0' );
define( 'ATK_PLUGIN_FILE', __FILE__ );
define( 'ATK_PLUGIN_DIR', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'ATK_PLUGIN_URI', untrailingslashit( plugin_dir_url( __FILE__ ) ) );

/**
 * This plugin was a theme first, and the old theme defines the same
 * functions, so the two cannot run together. While the old theme is the
 * active one the plugin stays dormant and says so, instead of causing a
 * "cannot redeclare function" fatal error. Switch to another theme and the
 * plugin starts on the next request.
 */
function akn_old_theme_is_active() {
	$template = get_template();
	if ( ! $template ) {
		return false;
	}
	return 'Aberdeen Taxi Knowledge' === wp_get_theme( $template )->get( 'Name' );
}

if ( akn_old_theme_is_active() ) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>Aberdeen Knowledge is installed but waiting.</strong> The old Aberdeen Taxi Knowledge theme is still active and does the same job. <a href="%s">Switch to another theme</a> and the plugin takes over, with all your pages, drivers and courses unchanged.</p></div>',
			esc_url( admin_url( 'themes.php' ) )
		);
	} );
	return;
}

// Order matters: mode, icons and brand first (other modules render mode-aware,
// icon and brand markup), db-schema before anything that reads course or quiz
// content, course before demo.
require ATK_PLUGIN_DIR . '/inc/theme-mode.php';
require ATK_PLUGIN_DIR . '/inc/icons.php';
require ATK_PLUGIN_DIR . '/inc/brand.php';
require ATK_PLUGIN_DIR . '/inc/assets.php';
require ATK_PLUGIN_DIR . '/inc/setup.php';
require ATK_PLUGIN_DIR . '/inc/templates.php';
require ATK_PLUGIN_DIR . '/inc/auth.php';
require ATK_PLUGIN_DIR . '/inc/db-schema.php';
require ATK_PLUGIN_DIR . '/inc/quiz-data.php';
require ATK_PLUGIN_DIR . '/inc/maps.php';
require ATK_PLUGIN_DIR . '/inc/quiz.php';
require ATK_PLUGIN_DIR . '/inc/attempts.php';
require ATK_PLUGIN_DIR . '/inc/study-tools.php';
require ATK_PLUGIN_DIR . '/inc/arteries-map.php';
require ATK_PLUGIN_DIR . '/inc/neighbourhoods-map.php';
require ATK_PLUGIN_DIR . '/inc/poi-map.php';
require ATK_PLUGIN_DIR . '/inc/course-data.php';
require ATK_PLUGIN_DIR . '/inc/road-names-data.php';
require ATK_PLUGIN_DIR . '/inc/instructor.php';
require ATK_PLUGIN_DIR . '/inc/instructor-roads.php';
require ATK_PLUGIN_DIR . '/inc/neighbourhoods.php';
require ATK_PLUGIN_DIR . '/inc/poi-places-engine.php';
require ATK_PLUGIN_DIR . '/inc/poi-places.php';
require ATK_PLUGIN_DIR . '/inc/settings.php';
require ATK_PLUGIN_DIR . '/inc/course.php';
require ATK_PLUGIN_DIR . '/inc/demo.php';
require ATK_PLUGIN_DIR . '/inc/elementor.php';
require ATK_PLUGIN_DIR . '/inc/updater.php';
