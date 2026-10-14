<?php
/**
 * Template Name: Reset Password
 */

defined( 'ABSPATH' ) || exit;

atk_redirect_if_logged_in();

$login = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ), true ) : '';
$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
$user  = ( $login && $key ) ? check_password_reset_key( $key, $login ) : new WP_Error( 'missing', 'Missing reset details.' );
$valid = ! is_wp_error( $user );

akn_shell( 'auth', 'header' );
?>

<?php if ( ! $valid ) : ?>

	<div class="text-center mb-10">
		<h1 class="text-dark mb-3">Link Expired</h1>
		<div class="text-gray-500 fw-bold fs-4">This password reset link is invalid or has expired.</div>
	</div>
	<div class="text-center">
		<a href="<?php echo esc_url( atk_get_page_url( 'forgot' ) ); ?>" class="btn btn-lg btn-primary">Request a New Link</a>
	</div>

<?php else : ?>

	<form class="form w-100" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
		<input type="hidden" name="action" value="atk_reset_password" />
		<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>" />
		<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
		<?php wp_nonce_field( ATK_AUTH_NONCE_ACTION, 'atk_nonce' ); ?>

		<?php atk_render_auth_notice(); ?>

		<div class="text-center mb-10">
			<h1 class="text-dark mb-3">Set a New Password</h1>
		</div>

		<div class="fv-row mb-7">
			<label class="form-label fw-bolder text-dark fs-6">New Password</label>
			<input class="form-control form-control-lg form-control-solid" type="password" name="password" autocomplete="new-password" required minlength="8" />
			<div class="text-muted fs-7 mt-1">Use 8 or more characters with a mix of letters, numbers &amp; symbols.</div>
		</div>

		<div class="fv-row mb-10">
			<label class="form-label fw-bolder text-dark fs-6">Confirm New Password</label>
			<input class="form-control form-control-lg form-control-solid" type="password" name="confirm_password" autocomplete="new-password" required minlength="8" />
		</div>

		<div class="text-center">
			<button type="submit" class="btn btn-lg btn-primary w-100">Reset Password</button>
		</div>
	</form>

<?php endif; ?>

<?php akn_shell( 'auth', 'footer' ); ?>
