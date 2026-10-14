<?php
/**
 * Custom sign in / sign up / password reset handling.
 *
 * Forms on the auth page templates post to admin-post.php, which is
 * processed here, so drivers never see wp-login.php.
 */

defined( 'ABSPATH' ) || exit;

const ATK_AUTH_NONCE_ACTION   = 'atk_auth';
const ATK_AVATAR_NONCE_ACTION = 'atk_upload_avatar';
const ATK_AVATAR_MAX_BYTES    = 2097152; // 2 MB

/**
 * Human-readable text for the ?atk_notice= codes the handlers below
 * redirect back with. Shared between the error and success cases.
 */
function atk_auth_notice_text( $code ) {
	$messages = array(
		'invalid_login'        => 'That email and password combination doesn\'t match an account. Please try again.',
		'missing_fields'       => 'Please fill in all required fields.',
		'invalid_email'        => 'Please enter a valid email address.',
		'email_exists'         => 'An account already exists for that email address. Try signing in instead.',
		'password_too_short'   => 'Your password must be at least 8 characters long.',
		'password_mismatch'    => 'Those passwords don\'t match. Please try again.',
		'toc_required'         => 'Please agree to the terms to create an account.',
		'registered'           => 'Your account has been created.',
		'reset_email_sent'     => 'If an account exists for that email, we\'ve sent a link to reset your password.',
		'reset_link_invalid'   => 'That password reset link is invalid or has expired. Please request a new one.',
		'reset_success'        => 'Your password has been reset. You\'re now signed in.',
		'avatar_updated'       => 'Your profile photo has been updated.',
		'avatar_missing_file'  => 'Please choose a photo to upload.',
		'avatar_too_large'     => 'That photo is too large — please choose one under 2 MB.',
		'avatar_upload_failed' => 'We couldn\'t upload that photo. Please try a JPG, PNG or WebP image.',
	);

	return isset( $messages[ $code ] ) ? $messages[ $code ] : '';
}

/**
 * Where to send someone after they successfully sign in or register.
 * Honours a same-site ?redirect_to= if present, otherwise the profile page.
 */
function atk_auth_redirect_target() {
	if ( ! empty( $_REQUEST['redirect_to'] ) ) {
		$target = wp_validate_redirect( wp_unslash( $_REQUEST['redirect_to'] ), '' );
		if ( $target ) {
			return $target;
		}
	}

	return atk_get_page_url( 'profile' );
}

/**
 * The current user's role-specific "Dashboard" link for the header's user
 * menu — the one thing that actually differs per role. Returns '' when a
 * role has no distinct dashboard of its own (e.g. drivers, for whom "My
 * Profile" already is their dashboard — showing both would just point two
 * menu items at the same page).
 */
function atk_current_user_dashboard_url() {
	if ( atk_is_instructor() ) {
		return atk_get_page_url( 'instructor-dashboard' );
	}
	return '';
}

/**
 * Already-logged-in visitors don't need the sign in / sign up pages.
 */
function atk_redirect_if_logged_in() {
	if ( is_user_logged_in() ) {
		wp_safe_redirect( atk_get_page_url( 'profile' ) );
		exit;
	}
}

/**
 * Pages that require a driver to be logged in — sends them back to
 * whichever page they actually requested after signing in (e.g. a specific
 * course test's ?week=&test= query string), not always the profile page.
 */
function atk_require_login() {
	if ( ! is_user_logged_in() ) {
		$current_url = home_url( add_query_arg( null, null ) );
		wp_safe_redirect( add_query_arg( 'redirect_to', urlencode( $current_url ), atk_get_page_url( 'signin' ) ) );
		exit;
	}
}

/* -------------------------------------------------------------------- */
/* Sign in                                                               */
/* -------------------------------------------------------------------- */

add_action( 'admin_post_nopriv_atk_login', 'atk_handle_login' );
function atk_handle_login() {
	check_admin_referer( ATK_AUTH_NONCE_ACTION, 'atk_nonce' );

	$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$password = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';

	if ( '' === $email || '' === $password ) {
		atk_redirect_back( 'signin', 'missing_fields', array( 'email' => $email ) );
	}

	$user = wp_signon( array(
		'user_login'    => $email,
		'user_password' => $password,
		'remember'      => true,
	), is_ssl() );

	if ( is_wp_error( $user ) ) {
		atk_redirect_back( 'signin', 'invalid_login', array( 'email' => $email ) );
	}

	wp_safe_redirect( atk_auth_redirect_target() );
	exit;
}

/* -------------------------------------------------------------------- */
/* Sign up                                                               */
/* -------------------------------------------------------------------- */

add_action( 'admin_post_nopriv_atk_register', 'atk_handle_register' );
function atk_handle_register() {
	check_admin_referer( ATK_AUTH_NONCE_ACTION, 'atk_nonce' );

	$first_name       = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
	$last_name        = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
	$email            = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$password         = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
	$confirm_password = isset( $_POST['confirm_password'] ) ? (string) $_POST['confirm_password'] : '';
	$toc              = ! empty( $_POST['toc'] );

	$sticky = array(
		'first_name' => $first_name,
		'last_name'  => $last_name,
		'email'      => $email,
	);

	if ( '' === $first_name || '' === $last_name || '' === $email || '' === $password || '' === $confirm_password ) {
		atk_redirect_back( 'signup', 'missing_fields', $sticky );
	}

	if ( ! is_email( $email ) ) {
		atk_redirect_back( 'signup', 'invalid_email', $sticky );
	}

	if ( email_exists( $email ) ) {
		atk_redirect_back( 'signup', 'email_exists', $sticky );
	}

	if ( strlen( $password ) < 8 ) {
		atk_redirect_back( 'signup', 'password_too_short', $sticky );
	}

	if ( $password !== $confirm_password ) {
		atk_redirect_back( 'signup', 'password_mismatch', $sticky );
	}

	if ( ! $toc ) {
		atk_redirect_back( 'signup', 'toc_required', $sticky );
	}

	$username = atk_unique_username_from_email( $email );

	$user_id = wp_insert_user( array(
		'user_login'   => $username,
		'user_email'   => $email,
		'user_pass'    => $password,
		'first_name'   => $first_name,
		'last_name'    => $last_name,
		'display_name' => trim( $first_name . ' ' . $last_name ),
		'role'         => 'driver',
	) );

	if ( is_wp_error( $user_id ) ) {
		atk_redirect_back( 'signup', 'missing_fields', $sticky );
	}

	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true, is_ssl() );

	wp_safe_redirect( add_query_arg( 'atk_notice', 'registered', atk_auth_redirect_target() ) );
	exit;
}

/**
 * Turn an email into a free username, appending a number if it's taken.
 */
function atk_unique_username_from_email( $email ) {
	$base     = sanitize_user( current( explode( '@', $email ) ), true );
	$base     = $base ? $base : 'driver';
	$username = $base;
	$suffix   = 1;

	while ( username_exists( $username ) ) {
		$suffix++;
		$username = $base . $suffix;
	}

	return $username;
}

/* -------------------------------------------------------------------- */
/* Forgot password                                                      */
/* -------------------------------------------------------------------- */

add_action( 'admin_post_nopriv_atk_forgot_password', 'atk_handle_forgot_password' );
function atk_handle_forgot_password() {
	check_admin_referer( ATK_AUTH_NONCE_ACTION, 'atk_nonce' );

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$user  = $email ? get_user_by( 'email', $email ) : false;

	if ( $user ) {
		$key = get_password_reset_key( $user );

		if ( ! is_wp_error( $key ) ) {
			$reset_url = add_query_arg( array(
				'login' => rawurlencode( $user->user_login ),
				'key'   => $key,
			), atk_get_page_url( 'reset' ) );

			$message  = "Someone has requested a password reset for the following account:\r\n\r\n";
			$message .= network_home_url( '/' ) . "\r\n\r\n";
			$message .= "If this wasn't you, you can ignore this email.\r\n\r\n";
			$message .= "To reset your password, visit the following address:\r\n\r\n" . $reset_url . "\r\n";

			wp_mail( $user->user_email, '[' . get_bloginfo( 'name' ) . '] Password Reset', $message );
		}
	}

	// Always the same response, whether or not the account exists, so we don't leak which emails are registered.
	atk_redirect_back( 'forgot', 'reset_email_sent', array(), true );
}

/* -------------------------------------------------------------------- */
/* Reset password                                                       */
/* -------------------------------------------------------------------- */

add_action( 'admin_post_nopriv_atk_reset_password', 'atk_handle_reset_password' );
function atk_handle_reset_password() {
	check_admin_referer( ATK_AUTH_NONCE_ACTION, 'atk_nonce' );

	$login = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ), true ) : '';
	$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

	$user = check_password_reset_key( $key, $login );

	if ( is_wp_error( $user ) ) {
		atk_redirect_back( 'forgot', 'reset_link_invalid' );
	}

	$password         = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
	$confirm_password = isset( $_POST['confirm_password'] ) ? (string) $_POST['confirm_password'] : '';
	$reset_url_args   = array( 'login' => rawurlencode( $login ), 'key' => $key );

	if ( strlen( $password ) < 8 ) {
		atk_redirect_back_with_args( 'reset', 'password_too_short', $reset_url_args );
	}

	if ( $password !== $confirm_password ) {
		atk_redirect_back_with_args( 'reset', 'password_mismatch', $reset_url_args );
	}

	wp_set_password( $password, $user->ID );

	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true, is_ssl() );

	wp_safe_redirect( add_query_arg( 'atk_notice', 'reset_success', atk_get_page_url( 'profile' ) ) );
	exit;
}

/**
 * Render the Bootstrap alert for the current ?atk_notice=/&atk_type= pair,
 * if any. Used at the top of every auth form.
 */
function atk_render_auth_notice() {
	if ( empty( $_GET['atk_notice'] ) ) {
		return;
	}

	$code = sanitize_key( wp_unslash( $_GET['atk_notice'] ) );
	$text = atk_auth_notice_text( $code );

	if ( ! $text ) {
		return;
	}

	$is_success = isset( $_GET['atk_type'] ) && 'success' === $_GET['atk_type'];
	$class      = $is_success ? 'alert-success' : 'alert-danger';

	printf(
		'<div class="alert %1$s d-flex align-items-center p-5 mb-10"><span>%2$s</span></div>',
		esc_attr( $class ),
		esc_html( $text )
	);
}

/**
 * Sticky value helper: re-populate a field from the redirect query args
 * after a failed submission.
 */
function atk_render_signin_form() {
	?>
	<form class="form w-100" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
		<input type="hidden" name="action" value="atk_login" />
		<?php wp_nonce_field( ATK_AUTH_NONCE_ACTION, 'atk_nonce' ); ?>
		<?php if ( ! empty( $_GET['redirect_to'] ) ) : ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( wp_unslash( $_GET['redirect_to'] ) ); ?>" />
		<?php endif; ?>

		<?php atk_render_auth_notice(); ?>

		<div class="text-center mb-10">
			<h1 class="text-dark mb-3">Sign In</h1>
			<div class="text-gray-500 fw-bold fs-4">
				New here?
				<a href="<?php echo esc_url( atk_get_page_url( 'signup' ) ); ?>" class="link-primary fw-bolder">Create an account</a>
			</div>
		</div>

		<div class="fv-row mb-8">
			<label class="form-label fs-6 fw-bolder text-dark">Email</label>
			<input class="form-control form-control-lg form-control-solid" type="email" name="email" value="<?php echo atk_sticky_value( 'email' ); ?>" autocomplete="username" required />
		</div>

		<div class="fv-row mb-8">
			<div class="d-flex flex-stack mb-2">
				<label class="form-label fw-bolder text-dark fs-6 mb-0">Password</label>
				<a href="<?php echo esc_url( atk_get_page_url( 'forgot' ) ); ?>" class="link-primary fs-6 fw-bolder">Forgot Password?</a>
			</div>
			<input class="form-control form-control-lg form-control-solid" type="password" name="password" autocomplete="current-password" required />
		</div>

		<div class="text-center">
			<button type="submit" class="btn btn-lg btn-primary w-100">Sign In</button>
		</div>
	</form>
	<?php
}
add_shortcode( 'atk_signin_form', function () {
	ob_start();
	atk_render_signin_form();
	return ob_get_clean();
} );

function atk_render_signup_form() {
	?>
	<form class="form w-100" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
		<input type="hidden" name="action" value="atk_register" />
		<?php wp_nonce_field( ATK_AUTH_NONCE_ACTION, 'atk_nonce' ); ?>

		<?php atk_render_auth_notice(); ?>

		<div class="mb-10 text-center">
			<h1 class="text-dark mb-3">Create an Account</h1>
			<div class="text-gray-500 fw-bold fs-4">
				Already have an account?
				<a href="<?php echo esc_url( atk_get_page_url( 'signin' ) ); ?>" class="link-primary fw-bolder">Sign in here</a>
			</div>
		</div>

		<div class="row fv-row mb-7">
			<div class="col-md-6">
				<label class="form-label fw-bolder text-dark fs-6">First Name</label>
				<input class="form-control form-control-lg form-control-solid" type="text" name="first_name" value="<?php echo atk_sticky_value( 'first_name' ); ?>" autocomplete="given-name" required />
			</div>
			<div class="col-md-6">
				<label class="form-label fw-bolder text-dark fs-6">Last Name</label>
				<input class="form-control form-control-lg form-control-solid" type="text" name="last_name" value="<?php echo atk_sticky_value( 'last_name' ); ?>" autocomplete="family-name" required />
			</div>
		</div>

		<div class="fv-row mb-7">
			<label class="form-label fw-bolder text-dark fs-6">Email</label>
			<input class="form-control form-control-lg form-control-solid" type="email" name="email" value="<?php echo atk_sticky_value( 'email' ); ?>" autocomplete="email" required />
		</div>

		<div class="fv-row mb-7">
			<label class="form-label fw-bolder text-dark fs-6">Password</label>
			<input class="form-control form-control-lg form-control-solid" type="password" name="password" autocomplete="new-password" required minlength="8" />
			<div class="text-muted fs-7 mt-1">Use 8 or more characters with a mix of letters, numbers &amp; symbols.</div>
		</div>

		<div class="fv-row mb-7">
			<label class="form-label fw-bolder text-dark fs-6">Confirm Password</label>
			<input class="form-control form-control-lg form-control-solid" type="password" name="confirm_password" autocomplete="new-password" required minlength="8" />
		</div>

		<div class="fv-row mb-10">
			<label class="form-check form-check-custom form-check-solid form-check-inline">
				<input class="form-check-input" type="checkbox" name="toc" value="1" required />
				<span class="form-check-label fw-bold text-gray-700 fs-6">I agree to the terms and conditions</span>
			</label>
		</div>

		<div class="text-center">
			<button type="submit" class="btn btn-lg btn-primary w-100">Create Account</button>
		</div>
	</form>
	<?php
}
add_shortcode( 'atk_signup_form', function () {
	ob_start();
	atk_render_signup_form();
	return ob_get_clean();
} );

function atk_sticky_value( $field ) {
	return isset( $_GET[ $field ] ) ? esc_attr( wp_unslash( $_GET[ $field ] ) ) : '';
}

/* -------------------------------------------------------------------- */
/* Redirect helpers                                                     */
/* -------------------------------------------------------------------- */

/**
 * Redirect back to one of the managed pages with a notice code and any
 * sticky field values to repopulate the form, then stop execution.
 */
function atk_redirect_back( $page_key, $notice, $sticky = array(), $is_success = false ) {
	atk_redirect_back_with_args( $page_key, $notice, array(), $sticky, $is_success );
}

function atk_redirect_back_with_args( $page_key, $notice, $extra_args = array(), $sticky = array(), $is_success = false ) {
	$args = array_merge( $extra_args, $sticky, array(
		'atk_notice' => $notice,
		'atk_type'   => $is_success ? 'success' : 'error',
	) );

	wp_safe_redirect( add_query_arg( $args, atk_get_page_url( $page_key ) ) );
	exit;
}

/* -------------------------------------------------------------------- */
/* Avatar upload                                                        */
/* -------------------------------------------------------------------- */

/**
 * A user's avatar as the symbol-circle component from akn.css — their
 * uploaded photo (atk_avatar_id user meta, set below) if they have one,
 * else a coloured circle with their first initial, the same placeholder
 * style the profile page used before uploads existed. Used both on the
 * driver's own profile page and in the Instructor Dashboard's Driver
 * Results tables.
 */
function atk_get_avatar_html( $user_id, $size = 50 ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return '';
	}

	$font_class = $size >= 60 ? 'fs-1' : ( $size >= 45 ? 'fs-4' : 'fs-7' );
	$avatar_id  = (int) get_user_meta( $user_id, 'atk_avatar_id', true );
	$image      = $avatar_id ? wp_get_attachment_image( $avatar_id, array( $size, $size ), false, array(
		'class' => 'w-100 h-100',
		'style' => 'object-fit: cover;',
		'alt'   => esc_attr( $user->display_name ),
	) ) : '';

	if ( $image ) {
		return sprintf( '<div class="symbol symbol-%1$dpx symbol-circle"><div class="symbol-label p-0">%2$s</div></div>', (int) $size, $image );
	}

	$initial = mb_strtoupper( mb_substr( $user->first_name ?: $user->display_name, 0, 1 ) );
	return sprintf(
		'<div class="symbol symbol-%1$dpx symbol-circle"><div class="symbol-label bg-light-primary text-primary fw-bolder %2$s">%3$s</div></div>',
		(int) $size,
		esc_attr( $font_class ),
		esc_html( $initial )
	);
}

add_action( 'admin_post_atk_upload_avatar', 'atk_handle_upload_avatar' );
function atk_handle_upload_avatar() {
	check_admin_referer( ATK_AVATAR_NONCE_ACTION, 'atk_nonce' );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( atk_get_page_url( 'signin' ) );
		exit;
	}

	if ( empty( $_FILES['avatar']['name'] ) ) {
		atk_redirect_back( 'profile', 'avatar_missing_file' );
	}

	if ( ! empty( $_FILES['avatar']['size'] ) && $_FILES['avatar']['size'] > ATK_AVATAR_MAX_BYTES ) {
		atk_redirect_back( 'profile', 'avatar_too_large' );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$uploaded = wp_handle_upload( $_FILES['avatar'], array(
		'test_form' => false,
		'mimes'     => array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
		),
	) );

	if ( ! empty( $uploaded['error'] ) ) {
		atk_redirect_back( 'profile', 'avatar_upload_failed' );
	}

	$user_id       = get_current_user_id();
	$attachment_id = wp_insert_attachment( array(
		'post_mime_type' => $uploaded['type'],
		'post_title'     => sanitize_file_name( basename( $uploaded['file'] ) ),
		'post_status'    => 'inherit',
		'post_author'    => $user_id,
	), $uploaded['file'] );

	if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
		atk_redirect_back( 'profile', 'avatar_upload_failed' );
	}

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );

	// Replace, don't accumulate — an old photo left in the Media Library
	// after every re-upload would just be orphaned clutter.
	$old_avatar_id = (int) get_user_meta( $user_id, 'atk_avatar_id', true );
	if ( $old_avatar_id ) {
		wp_delete_attachment( $old_avatar_id, true );
	}

	update_user_meta( $user_id, 'atk_avatar_id', $attachment_id );

	atk_redirect_back( 'profile', 'avatar_updated', array(), true );
}

/**
 * The My Profile page's body: who you are, a few real engagement stats,
 * your Official Test Course tracks (enrolled or not), and your three
 * mockup tests — every number here comes from data the site already
 * tracks, nothing invented. Registered as the [atk_profile] shortcode so
 * the page can be built in Elementor (no page title/breadcrumb baked in
 * here — that's chrome, left for the page itself to add if wanted).
 */
function atk_render_profile_page() {
	atk_require_login();

	$current_user  = wp_get_current_user();
	$is_instructor = atk_is_instructor( $current_user );
	$course_types  = atk_course_types();
	$quiz_tests    = atk_quiz_tests();
	$reviews       = atk_attempt_reviews( $current_user->ID );

	$enrolled_count = 0;
	$tests_passed   = 0;
	foreach ( $course_types as $type_key => $type_info ) {
		if ( atk_course_is_enrolled( $current_user->ID, $type_key ) ) {
			$enrolled_count++;
			$progress      = atk_course_progress( $current_user->ID, $type_key );
			$tests_passed += count( array_filter( $progress, function ( $r ) { return ! empty( $r['passed'] ); } ) );
		}
	}
	$mockup_tried_count = count( array_filter( $quiz_tests, function ( $test, $key ) use ( $reviews ) {
		return ! empty( $reviews[ $key ] );
	}, ARRAY_FILTER_USE_BOTH ) );

	$review_modals = '';
	?>

	<div class="container py-10 py-lg-15">
		<?php atk_render_auth_notice(); ?>
		<div class="row g-6">
			<div class="col-lg-4">
				<div class="card">
					<div class="card-body text-center">
						<div class="mb-5 d-flex justify-content-center"><?php echo atk_get_avatar_html( $current_user->ID, 75 ); ?></div>
						<div class="fs-3 fw-bolder text-gray-900"><?php echo esc_html( $current_user->display_name ); ?></div>
						<div class="fs-6 text-gray-500 mb-3"><?php echo esc_html( $current_user->user_email ); ?></div>
						<span class="badge badge-light-primary mb-3"><?php echo $is_instructor ? 'Instructor' : 'Driver'; ?></span>
						<div class="fs-7 text-gray-500 mb-6">Member since <?php echo esc_html( date_i18n( 'j M Y', strtotime( $current_user->user_registered ) ) ); ?></div>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="text-start">
							<input type="hidden" name="action" value="atk_upload_avatar" />
							<?php wp_nonce_field( ATK_AVATAR_NONCE_ACTION, 'atk_nonce' ); ?>
							<label class="form-label fs-7 text-gray-600">Profile photo</label>
							<input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm mb-3" required />
							<button type="submit" class="btn btn-sm btn-light-primary w-100">Upload Photo</button>
						</form>
					</div>
				</div>
			</div>

			<div class="col-lg-8">
				<div class="row g-6 mb-6">
					<div class="col-4">
						<div class="card h-100">
							<div class="card-body text-center py-6">
								<div class="fs-2x fw-bolder text-gray-900"><?php echo esc_html( $enrolled_count ); ?>/<?php echo esc_html( count( $course_types ) ); ?></div>
								<div class="fs-7 text-gray-500">Courses Enrolled</div>
							</div>
						</div>
					</div>
					<div class="col-4">
						<div class="card h-100">
							<div class="card-body text-center py-6">
								<div class="fs-2x fw-bolder text-gray-900"><?php echo esc_html( $tests_passed ); ?></div>
								<div class="fs-7 text-gray-500">Course Tests Passed</div>
							</div>
						</div>
					</div>
					<div class="col-4">
						<div class="card h-100">
							<div class="card-body text-center py-6">
								<div class="fs-2x fw-bolder text-gray-900"><?php echo esc_html( $mockup_tried_count ); ?>/<?php echo esc_html( count( $quiz_tests ) ); ?></div>
								<div class="fs-7 text-gray-500">Mockup Tests Tried</div>
							</div>
						</div>
					</div>
				</div>

				<div class="card mb-6">
					<div class="card-header">
						<h2 class="card-title">My Courses</h2>
					</div>
					<div class="card-body py-0">
						<?php
						$course_keys = array_keys( $course_types );
						foreach ( $course_types as $type_key => $type_info ) :
							$enrolled = atk_course_is_enrolled( $current_user->ID, $type_key );
							$is_last  = $type_key === end( $course_keys );
							?>
							<div class="d-flex align-items-center justify-content-between py-4<?php echo $is_last ? '' : ' border-bottom'; ?>">
								<div class="d-flex align-items-center">
									<div class="symbol symbol-40px me-4">
										<div class="symbol-label bg-light-primary"><?php echo atk_render_duotone_icon( atk_course_type_icon( $type_key ), 'svg-icon svg-icon-1 svg-icon-primary' ); ?></div>
									</div>
									<div>
										<div class="fw-bolder text-gray-900"><?php echo esc_html( $type_info['label'] ); ?></div>
										<?php if ( $enrolled ) :
											$progress       = atk_course_progress( $current_user->ID, $type_key );
											$passed         = count( array_filter( $progress, function ( $r ) { return ! empty( $r['passed'] ); } ) );
											$total_tests    = atk_course_total_weeks( $type_key ) * ATK_COURSE_TESTS_PER_WEEK;
											$track_attempts = atk_course_track_attempts( $current_user->ID, $type_key );
											$track_trigger  = atk_render_previous_attempts_trigger( 'course-track-' . $type_key, 'btn btn-sm btn-icon btn-light', true, null, $track_attempts );
											$review_modals .= atk_render_previous_attempts_modal( 'course-track-' . $type_key, $type_info['label'], null, $track_attempts );
											?>
											<div class="fs-7 text-gray-500"><?php echo esc_html( $passed ); ?>/<?php echo esc_html( $total_tests ); ?> tests passed<?php echo $track_attempts ? ' &middot; ' . esc_html( count( $track_attempts ) ) . ' attempt' . ( 1 === count( $track_attempts ) ? '' : 's' ) . ' on record' : ''; ?></div>
										<?php else : ?>
											<div class="fs-7 text-gray-500">Not enrolled</div>
										<?php endif; ?>
									</div>
								</div>
								<div class="d-flex align-items-center gap-2">
									<?php if ( $enrolled && $track_trigger ) : ?>
										<?php echo $track_trigger; ?>
									<?php endif; ?>
									<?php if ( $enrolled ) : ?>
										<a href="<?php echo esc_url( add_query_arg( 'type', $type_key, atk_get_page_url( 'official-test-course' ) ) ); ?>" class="btn btn-sm btn-primary">Continue</a>
									<?php else : ?>
										<a href="<?php echo esc_url( atk_get_page_url( 'course' ) ); ?>" class="btn btn-sm btn-light-primary">Enroll</a>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="card">
					<div class="card-header">
						<h2 class="card-title">Mockup Tests</h2>
					</div>
					<div class="card-body py-0">
						<?php
						$quiz_keys = array_keys( $quiz_tests );
						foreach ( $quiz_tests as $key => $test ) :
							$latest           = ! empty( $reviews[ $key ][0] ) ? $reviews[ $key ][0] : null;
							$previous_trigger = atk_render_previous_attempts_trigger( $key, 'btn btn-sm btn-icon btn-light' );
							$review_modals   .= atk_render_previous_attempts_modal( $key, $test['title'] );
							$is_last          = $key === end( $quiz_keys );
							?>
							<div class="d-flex align-items-center justify-content-between py-4<?php echo $is_last ? '' : ' border-bottom'; ?>">
								<div class="d-flex align-items-center">
									<div class="symbol symbol-40px me-4">
										<div class="symbol-label bg-light-warning"><?php echo atk_render_duotone_icon( atk_course_type_icon( $key ), 'svg-icon svg-icon-1 svg-icon-warning' ); ?></div>
									</div>
									<div>
										<div class="fw-bolder text-gray-900"><?php echo esc_html( $test['title'] ); ?></div>
										<?php if ( $latest ) : ?>
											<div class="fs-7 text-gray-500">Latest score: <?php echo esc_html( $latest['score'] . '/' . $latest['total'] ); ?></div>
										<?php else : ?>
											<div class="fs-7 text-gray-500">Not attempted yet</div>
										<?php endif; ?>
									</div>
								</div>
								<div class="d-flex align-items-center gap-2">
									<?php if ( $previous_trigger ) : ?>
										<?php echo $previous_trigger; ?>
									<?php endif; ?>
									<a href="<?php echo esc_url( atk_get_page_url( $test['page_key'] ) ); ?>" class="btn btn-sm btn-light-primary"><?php echo $latest ? 'Retry' : 'Start'; ?></a>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php echo $review_modals; ?>
	<?php
}
add_shortcode( 'atk_profile', function () {
	ob_start();
	atk_render_profile_page();
	return ob_get_clean();
} );
