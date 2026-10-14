<?php
/**
 * Elementor helper. The plugin works with or without Elementor; this is the
 * one piece that needs to know about it.
 *
 * Header, footer and single-page templates are the active theme's and
 * Elementor Theme Builder's job, so the plugin registers no locations and
 * does not touch Elementor's Site Settings.
 */

defined( 'ABSPATH' ) || exit;

/**
 * True while Elementor is actively editing or live-previewing the current
 * page (its editor, or the preview iframe it loads), as opposed to a real
 * front-end visit.
 *
 * Several shortcodes (atk_course_test, atk_course_hub, ...) gate their real
 * content behind login, enrollment or valid-week checks that redirect and
 * exit() when they fail. That is right for a real visitor, but it silently
 * breaks Elementor's editor: the preview iframe never carries the original
 * page's query string, and the admin editing is essentially never "enrolled",
 * so the gate would fire and exit before Elementor's own scripts load
 * ("Sorry, the content area was not found in your page"). Every such gate
 * must check this first and show a plain placeholder instead.
 */
function atk_is_elementor_editing() {
	return class_exists( '\Elementor\Plugin' )
		&& ( \Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode() );
}
