<?php
/**
 * Light and dark mode, using Bootstrap 5.3's colour modes: the mode is the
 * data-bs-theme attribute on <html>, and the stylesheet answers to it. There
 * are no separate dark stylesheets to swap.
 *
 * Page HTML for logged-out visitors is cached by URL alone on typical hosts
 * (a server page cache, often with a CDN in front that ignores custom
 * cookies), so no server-side choice can reach a logged-out visitor reliably.
 *
 * - Logged-out: the server always renders light (one cacheable page for
 *   everyone), and a tiny script in <head> sets the attribute from the
 *   visitor's cookie before first paint.
 * - Logged-in: those caches are bypassed, so the account preference is
 *   rendered on <html> by the server, and the script only keeps the cookie
 *   in step so the choice survives logging out.
 */

defined( 'ABSPATH' ) || exit;

const ATK_THEME_MODE_NONCE_ACTION = 'atk_set_theme_mode';
const ATK_THEME_MODE_COOKIE       = 'atk_theme_mode';

/**
 * The mode the server renders: the account preference when logged in
 * (falling back to the cookie if they have never set one), always 'light'
 * for logged-out visitors.
 */
function atk_theme_mode() {
	if ( ! is_user_logged_in() ) {
		return 'light';
	}
	$mode = get_user_meta( get_current_user_id(), 'atk_theme_mode', true );
	if ( '' === $mode && isset( $_COOKIE[ ATK_THEME_MODE_COOKIE ] ) ) {
		$mode = sanitize_key( wp_unslash( $_COOKIE[ ATK_THEME_MODE_COOKIE ] ) );
	}
	return 'dark' === $mode ? 'dark' : 'light';
}

/** Kept so the shells and any caller keep working; the mode itself lives on <html>, not on <body>. */
function atk_body_classes( $base_classes ) {
	return (array) $base_classes;
}

/** Puts the server-rendered mode on <html>, wherever the page's <html> tag uses language_attributes(). */
add_filter( 'language_attributes', function ( $output ) {
	if ( is_admin() ) {
		return $output;
	}
	return $output . ' data-bs-theme="' . esc_attr( atk_theme_mode() ) . '"';
} );

/**
 * Runs first in <head>: works out the wanted mode, keeps the cookie in step
 * with a logged-in account, applies the mode to <html> before first paint,
 * and exposes the switch handler. Identical output for every logged-out
 * visitor, so it caches safely.
 */
add_action( 'wp_head', 'atk_print_theme_mode_head_script', 1 );
function atk_print_theme_mode_head_script() {
	?>
	<script>
	(function (d, w) {
		var cookie = <?php echo wp_json_encode( ATK_THEME_MODE_COOKIE ); ?>;
		var serverMode = <?php echo wp_json_encode( atk_theme_mode() ); ?>;
		var loggedIn = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;
		var match = d.cookie.match(new RegExp('(?:^|;\\s*)' + cookie + '=(dark|light)'));
		var setCookie = function (mode) {
			d.cookie = cookie + '=' + mode + '; path=/; max-age=31536000; SameSite=Lax';
		};
		var wanted = loggedIn ? serverMode : (match ? match[1] : 'light');
		if (loggedIn && (!match || match[1] !== serverMode)) {
			setCookie(serverMode);
		}
		w.atkThemeMode = wanted;
		d.documentElement.setAttribute('data-bs-theme', wanted);

		w.atkSetThemeMode = function (input) {
			var next = input.checked ? 'dark' : 'light';
			if (!loggedIn) {
				setCookie(next);
				d.documentElement.setAttribute('data-bs-theme', next);
				w.atkThemeMode = next;
				d.querySelectorAll('.atk-theme-mode-input').forEach(function (other) {
					other.checked = input.checked;
				});
				return;
			}
			input.form.querySelector('input[name="mode"]').value = next;
			input.form.submit();
		};

		d.addEventListener('DOMContentLoaded', function () {
			d.querySelectorAll('.atk-theme-mode-input').forEach(function (input) {
				input.checked = (w.atkThemeMode === 'dark');
			});
		});
	})(document, window);
	</script>
	<?php
}

/**
 * Saves a logged-in user's choice to their account (and the cookie, so it
 * survives logging out). For a logged-out visitor with JavaScript the switch
 * only sets a cookie in the browser and never reaches this; without
 * JavaScript this still works as a plain POST. The nonce is only checked for
 * logged-in users: a cached page's nonce expires within a day while the page
 * stays cached for a week, and for a logged-out visitor this action only sets
 * a cosmetic cookie on their own browser.
 */
add_action( 'admin_post_atk_set_theme_mode', 'atk_handle_set_theme_mode' );
add_action( 'admin_post_nopriv_atk_set_theme_mode', 'atk_handle_set_theme_mode' );
function atk_handle_set_theme_mode() {
	if ( is_user_logged_in() ) {
		check_admin_referer( ATK_THEME_MODE_NONCE_ACTION, 'atk_nonce' );
	}

	$mode = ( isset( $_POST['mode'] ) && 'dark' === $_POST['mode'] ) ? 'dark' : 'light';

	if ( is_user_logged_in() ) {
		update_user_meta( get_current_user_id(), 'atk_theme_mode', $mode );
	}
	setcookie( ATK_THEME_MODE_COOKIE, $mode, time() + YEAR_IN_SECONDS, '/' );

	$redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/' );
	wp_safe_redirect( $redirect_to );
	exit;
}

/**
 * A switch; flipping it IS the action (no separate save step). Rendered
 * off or on from the server mode, then synced in the browser for logged-out
 * dark visitors. Still a real form, so it degrades to a plain POST without
 * JavaScript. Layout-agnostic: the caller places it in a menu or leaves it
 * bare (header, auth pages).
 */
function atk_render_theme_mode_toggle() {
	$is_dark     = 'dark' === atk_theme_mode();
	$current_url = home_url( add_query_arg( null, null ) );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="d-inline-block">
		<input type="hidden" name="action" value="atk_set_theme_mode" />
		<input type="hidden" name="mode" value="<?php echo esc_attr( $is_dark ? 'light' : 'dark' ); ?>" />
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $current_url ); ?>" />
		<?php wp_nonce_field( ATK_THEME_MODE_NONCE_ACTION, 'atk_nonce' ); ?>
		<label class="form-check form-switch form-check-custom form-check-solid pulse pulse-success mb-0">
			<input class="form-check-input w-30px h-20px atk-theme-mode-input" type="checkbox"<?php checked( $is_dark ); ?> onchange="atkSetThemeMode(this)" />
			<span class="pulse-ring ms-n1"></span>
			<span class="form-check-label text-gray-600 fs-7">Dark Mode</span>
		</label>
	</form>
	<?php
}
