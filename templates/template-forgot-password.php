<?php
/**
 * Template Name: Forgot Password
 */

defined( 'ABSPATH' ) || exit;

atk_redirect_if_logged_in();

akn_shell( 'auth', 'header' );
?>

<form class="form w-100" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
	<input type="hidden" name="action" value="atk_forgot_password" />
	<?php wp_nonce_field( ATK_AUTH_NONCE_ACTION, 'atk_nonce' ); ?>

	<?php atk_render_auth_notice(); ?>

	<div class="text-center mb-10">
		<h1 class="text-dark mb-3">Forgot Password?</h1>
		<div class="text-gray-500 fw-bold fs-4">Enter your email to reset your password.</div>
	</div>

	<div class="fv-row mb-8">
		<label class="form-label fw-bolder text-dark fs-6">Email</label>
		<input class="form-control form-control-lg form-control-solid" type="email" name="email" autocomplete="username" required />
	</div>

	<div class="d-flex flex-wrap justify-content-center">
		<button type="submit" class="btn btn-lg btn-primary fw-bolder me-4">Submit</button>
		<a href="<?php echo esc_url( atk_get_page_url( 'signin' ) ); ?>" class="btn btn-lg btn-light-primary fw-bolder">Cancel</a>
	</div>
</form>

<?php akn_shell( 'auth', 'footer' ); ?>
