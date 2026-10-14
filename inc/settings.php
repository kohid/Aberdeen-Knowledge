<?php
/**
 * Site Settings: Stripe payment credentials (used by the Course enrollment
 * checkout, inc/course.php) and per-track Course pricing storage —
 * instructor-only, reachable from the header user menu, entered through a
 * real settings page rather than wp-config.php constants so an instructor
 * can manage them without server access.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------- */
/* Stripe credentials                                                    */
/* -------------------------------------------------------------------- */

function atk_stripe_publishable_key() {
	return get_option( 'atk_stripe_publishable_key', '' );
}

function atk_stripe_secret_key() {
	return get_option( 'atk_stripe_secret_key', '' );
}

function atk_stripe_webhook_secret() {
	return get_option( 'atk_stripe_webhook_secret', '' );
}

function atk_stripe_is_configured() {
	return (bool) ( atk_stripe_publishable_key() && atk_stripe_secret_key() && atk_stripe_webhook_secret() );
}

/* -------------------------------------------------------------------- */
/* PayPal credentials                                                    */
/* -------------------------------------------------------------------- */

function atk_paypal_client_id() {
	return get_option( 'atk_paypal_client_id', '' );
}

function atk_paypal_client_secret() {
	return get_option( 'atk_paypal_client_secret', '' );
}

function atk_paypal_is_configured() {
	return (bool) ( atk_paypal_client_id() && atk_paypal_client_secret() );
}

/* -------------------------------------------------------------------- */
/* Test mode                                                             */
/* -------------------------------------------------------------------- */

/**
 * A single instructor-facing kill switch for BOTH gateways at once, for
 * trying out the enrollment wizard/course flow without real credentials or
 * being charged. Distinct from "is configured" (has credentials saved) —
 * a gateway can be fully configured and still disabled here.
 */
function atk_payments_test_mode() {
	return (bool) get_option( 'atk_payments_test_mode', false );
}

/**
 * Whether a gateway should actually be offered at checkout: configured AND
 * not held back by test mode. Everything that decides checkout behaviour
 * (inc/course.php: atk_handle_course_enroll(), the enrollment wizard) reads
 * these instead of atk_*_is_configured() directly.
 */
function atk_stripe_enabled() {
	return atk_stripe_is_configured() && ! atk_payments_test_mode();
}

function atk_paypal_enabled() {
	return atk_paypal_is_configured() && ! atk_payments_test_mode();
}

add_action( 'admin_post_atk_save_settings', 'atk_handle_save_settings' );
function atk_handle_save_settings() {
	atk_instructor_verify_request();

	$publishable = isset( $_POST['stripe_publishable_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_publishable_key'] ) ) : '';
	update_option( 'atk_stripe_publishable_key', $publishable, false );

	// Secret fields render blank on purpose (atk_render_settings_page()) so
	// their real value never round-trips into page HTML — leave a saved
	// value alone unless the instructor actually typed a replacement.
	$secret = isset( $_POST['stripe_secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['stripe_secret_key'] ) ) ) : '';
	if ( '' !== $secret ) {
		update_option( 'atk_stripe_secret_key', $secret, false );
	}

	$webhook = isset( $_POST['stripe_webhook_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['stripe_webhook_secret'] ) ) ) : '';
	if ( '' !== $webhook ) {
		update_option( 'atk_stripe_webhook_secret', $webhook, false );
	}

	$paypal_client_id = isset( $_POST['paypal_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['paypal_client_id'] ) ) : '';
	update_option( 'atk_paypal_client_id', $paypal_client_id, false );

	$paypal_secret = isset( $_POST['paypal_client_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['paypal_client_secret'] ) ) ) : '';
	if ( '' !== $paypal_secret ) {
		update_option( 'atk_paypal_client_secret', $paypal_secret, false );
	}

	// Checkboxes only appear in $_POST when checked.
	update_option( 'atk_payments_test_mode', isset( $_POST['payments_test_mode'] ) ? 1 : 0, false );

	wp_safe_redirect( add_query_arg( 'notice', 'settings_saved', atk_get_page_url( 'settings' ) ) );
	exit;
}

/* -------------------------------------------------------------------- */
/* Course pricing                                                        */
/* -------------------------------------------------------------------- */

/**
 * A track's price, stored/edited in whole currency units (e.g. 49.99) —
 * converted to the smallest currency unit only right before creating a
 * Stripe Checkout Session (atk_stripe_create_checkout_session()).
 */
function atk_course_get_price( $type ) {
	$type = atk_course_type_or_default( $type );
	return array(
		'regular' => (float) get_option( 'atk_course_price_regular_' . $type, 0 ),
		'sale'    => get_option( 'atk_course_price_sale_' . $type, '' ),
	);
}

/**
 * The price a driver actually pays: the sale price if one's set and it's
 * genuinely lower than the regular price, otherwise the regular price.
 * Zero means "free" — atk_handle_course_enroll() skips checkout entirely
 * for a free track.
 */
function atk_course_effective_price( $type ) {
	$price = atk_course_get_price( $type );
	$sale  = '' !== $price['sale'] ? (float) $price['sale'] : null;
	if ( null !== $sale && $sale > 0 && $sale < $price['regular'] ) {
		return $sale;
	}
	return $price['regular'];
}

add_action( 'wp_ajax_atk_instructor_save_course_price', 'atk_handle_instructor_save_course_price' );
function atk_handle_instructor_save_course_price() {
	atk_instructor_verify_request();

	$type    = isset( $_POST['course_type'] ) ? atk_course_type_or_default( sanitize_key( $_POST['course_type'] ) ) : 'street';
	$regular = isset( $_POST['price_regular'] ) ? max( 0, (float) $_POST['price_regular'] ) : 0;
	$sale_raw = isset( $_POST['price_sale'] ) ? trim( wp_unslash( $_POST['price_sale'] ) ) : '';
	$sale    = '' !== $sale_raw ? max( 0, (float) $sale_raw ) : '';

	update_option( 'atk_course_price_regular_' . $type, $regular, false );
	update_option( 'atk_course_price_sale_' . $type, $sale, false );

	wp_safe_redirect( add_query_arg( array( 'tab' => 'course', 'type' => $type, 'notice' => 'price_saved' ), atk_get_page_url( 'instructor-dashboard' ) ) );
	exit;
}

/* -------------------------------------------------------------------- */
/* Settings page                                                         */
/* -------------------------------------------------------------------- */

function atk_render_settings_page() {
	atk_require_instructor();

	$publishable = atk_stripe_publishable_key();
	$has_secret  = (bool) atk_stripe_secret_key();
	$has_webhook = (bool) atk_stripe_webhook_secret();
	$webhook_url = rest_url( 'atk/v1/stripe-webhook' );

	$paypal_client_id  = atk_paypal_client_id();
	$has_paypal_secret = (bool) atk_paypal_client_secret();
	$test_mode         = atk_payments_test_mode();
	?>
	<div class="container py-10 py-lg-15" style="max-width: 800px;">
		<?php if ( isset( $_GET['notice'] ) && 'settings_saved' === $_GET['notice'] ) : ?>
			<div class="alert alert-success d-flex align-items-center p-5 mb-8"><span>Settings saved.</span></div>
		<?php endif; ?>

		<header class="mb-8">
			<p class="fs-5 text-gray-600 mb-0">Stripe and PayPal payment credentials for Course enrollment. A driver enrolling in a paid track can pay with either one, whichever is configured. Track prices are set per track on the Course Builder tab.</p>
		</header>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="atk_save_settings" />
			<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>

			<div class="card mb-6">
				<div class="card-header">
					<h2 class="card-title fs-4">Payment Gateways</h2>
				</div>
				<div class="card-body">
					<div class="form-check form-check-solid form-switch mb-6">
						<input class="form-check-input" type="checkbox" role="switch" name="payments_test_mode" value="1" id="atk-payments-test-mode"<?php checked( $test_mode ); ?> />
						<label class="form-check-label fw-bold" for="atk-payments-test-mode">Test mode &mdash; disable real payments</label>
					</div>
					<?php if ( $test_mode ) : ?>
						<div class="alert alert-warning d-flex align-items-center p-5 mb-6"><span>Test mode is ON &mdash; neither gateway below is offered during enrollment, even if fully configured. Every track enrolls free and instantly, regardless of price, so you can test the enrollment wizard and course flow without moving real money.</span></div>
					<?php endif; ?>

					<ul class="nav nav-tabs nav-line-tabs nav-line-tabs-2x mb-6 fs-6">
						<li class="nav-item">
							<button class="nav-link active" data-bs-toggle="tab" data-bs-target="#atk-settings-tab-stripe" type="button">
								Stripe
								<?php if ( ! atk_stripe_is_configured() ) : ?><span class="badge badge-light-warning ms-2">Not configured</span><?php endif; ?>
							</button>
						</li>
						<li class="nav-item">
							<button class="nav-link" data-bs-toggle="tab" data-bs-target="#atk-settings-tab-paypal" type="button">
								PayPal
								<?php if ( ! atk_paypal_is_configured() ) : ?><span class="badge badge-light-warning ms-2">Not configured</span><?php endif; ?>
							</button>
						</li>
					</ul>

					<div class="tab-content">
						<div class="tab-pane fade show active" id="atk-settings-tab-stripe">
							<?php if ( ! atk_stripe_is_configured() ) : ?>
								<div class="alert alert-warning d-flex align-items-center p-5 mb-6"><span>Not fully configured yet — until all three fields are saved, Stripe won't be offered as a payment option during enrollment.</span></div>
							<?php endif; ?>

							<div class="mb-6">
								<label class="form-label">Publishable Key</label>
								<input type="text" name="stripe_publishable_key" class="form-control" value="<?php echo esc_attr( $publishable ); ?>" placeholder="pk_test_..." />
							</div>
							<div class="mb-6">
								<label class="form-label">Secret Key</label>
								<input type="password" name="stripe_secret_key" class="form-control" placeholder="<?php echo esc_attr( $has_secret ? '•••••••••••••••• (saved — leave blank to keep)' : 'sk_test_...' ); ?>" autocomplete="off" />
							</div>
							<div class="mb-6">
								<label class="form-label">Webhook Signing Secret</label>
								<input type="password" name="stripe_webhook_secret" class="form-control" placeholder="<?php echo esc_attr( $has_webhook ? '•••••••••••••••• (saved — leave blank to keep)' : 'whsec_...' ); ?>" autocomplete="off" />
								<div class="form-text mt-2">
									In your <a href="https://dashboard.stripe.com/test/webhooks" target="_blank" rel="noopener">Stripe Dashboard &rarr; Webhooks</a>, add an endpoint listening for <code>checkout.session.completed</code>, pointing to:
									<br /><code><?php echo esc_html( $webhook_url ); ?></code>
									<br />then paste its signing secret here.
								</div>
							</div>
						</div>

						<div class="tab-pane fade" id="atk-settings-tab-paypal">
							<?php if ( ! atk_paypal_is_configured() ) : ?>
								<div class="alert alert-warning d-flex align-items-center p-5 mb-6"><span>Not fully configured yet — until both fields are saved, PayPal won't be offered as a payment option during enrollment.</span></div>
							<?php endif; ?>

							<div class="mb-6">
								<label class="form-label">Client ID</label>
								<input type="text" name="paypal_client_id" class="form-control" value="<?php echo esc_attr( $paypal_client_id ); ?>" placeholder="Sandbox or live Client ID" />
							</div>
							<div class="mb-6">
								<label class="form-label">Client Secret</label>
								<input type="password" name="paypal_client_secret" class="form-control" placeholder="<?php echo esc_attr( $has_paypal_secret ? '•••••••••••••••• (saved — leave blank to keep)' : 'Client Secret' ); ?>" autocomplete="off" />
								<div class="form-text mt-2">
									From your <a href="https://developer.paypal.com/dashboard/applications" target="_blank" rel="noopener">PayPal Developer Dashboard &rarr; Apps &amp; Credentials</a>. Use a Sandbox app's credentials to test without moving real money.
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<button type="submit" class="btn btn-primary">Save Settings</button>
		</form>

		<div class="card mt-6">
			<div class="card-header">
				<h2 class="card-title fs-4">Shortcodes</h2>
			</div>
			<div class="card-body">
				<p class="text-gray-600 mb-6">Drop these into a page built with a page builder (e.g. Elementor's Shortcode widget) or any other shortcode-aware area — each one renders using the plugin's real markup and styling, wired to the signed-in visitor exactly as it is everywhere else on the site.</p>
				<?php foreach ( atk_registered_shortcodes() as $shortcode ) : ?>
					<div class="d-flex align-items-center justify-content-between py-4 border-bottom">
						<div>
							<?php
							/**
							 * Double-bracketed — this is WordPress's own shortcode escape
							 * syntax ([[tag]] outputs literal [tag] text without running
							 * it). Necessary here specifically: this whole page is an
							 * Elementor Shortcode widget's output, which passes back
							 * through WordPress's normal the_content filter chain (a
							 * second do_shortcode() pass) — a real single-bracketed
							 * "[atk_course_test]" written here as plain documentation
							 * text would get executed for real on that second pass,
							 * hijacking the whole page load with its redirect. Confirmed
							 * live, not theoretical — this is exactly what was happening.
							 */
							?>
							<code class="fs-6"><?php echo esc_html( '[[' . $shortcode['tag'] . ']]' ); ?></code>
							<div class="fs-7 text-gray-600 mt-1"><?php echo esc_html( $shortcode['description'] ); ?></div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
}
add_shortcode( 'atk_settings', function () {
	ob_start();
	atk_render_settings_page();
	return ob_get_clean();
} );

/**
 * The plugin's shortcode reference shown on this page — add an entry here
 * alongside every add_shortcode() call so the list can't drift out of sync
 * with what's actually registered.
 */
function atk_registered_shortcodes() {
	return array(
		array(
			'tag'         => 'atk_user_menu',
			'description' => 'The account area from the header: signed-in avatar + dropdown (dashboard, profile, settings, dark mode, log out), or the dark-mode toggle + Sign In/Sign Up when logged out.',
		),
		array(
			'tag'         => 'atk_study_tools',
			'description' => 'The Study Tool hub\'s card grid (Major Streets, Neighbourhoods, Places of Interest, ...), with real Live/Coming Soon status per card.',
		),
		array(
			'tag'         => 'atk_mockup_tests',
			'description' => 'The three mockup Knowledge Test cards (Street/POI/Route), including each driver\'s Previous Attempts history.',
		),
		array(
			'tag'         => 'atk_course_test',
			'description' => 'A single Course test question set, driven by the ?type=/&week=/&test= query args on whatever page this is placed on (used by the Mock Test page).',
		),
		array(
			'tag'         => 'atk_course_hub',
			'description' => 'The Official Test Course hub — week/track progress, unlock status, and test links — driven by the ?type= query arg (used by the Official Test Course page).',
		),
		array(
			'tag'         => 'atk_quiz',
			'description' => 'One of the three mockup knowledge tests, e.g. [atk_quiz type="street"] (type is street, poi, or route — used by the Street/POI/Route Test pages).',
		),
		array(
			'tag'         => 'atk_course_catalog',
			'description' => 'The Course catalog — track lineup, pricing, an enrolled driver\'s progress, and the enrollment wizard modal (used by the Course page).',
		),
		array(
			'tag'         => 'atk_course_test_type',
			'description' => 'A heading naming the current ?type= track — "Street Official Test", "Place of Interest Official Test", or "Route Official Test" — for the Course Test page. Use Elementor\'s Shortcode widget, not the Heading widget (Heading doesn\'t run shortcodes). Optional atts: tag="h2" class="fs-2x fw-bolder text-gray-900".',
		),
		array(
			'tag'         => 'atk_profile',
			'description' => 'The My Profile page: avatar + stats, Official Test Course tracks, and mockup test results (used by the My Profile page).',
		),
		array(
			'tag'         => 'atk_settings',
			'description' => 'The instructor Settings page: Stripe/PayPal credentials, test mode, and this shortcode reference (used by the Settings page).',
		),
		array(
			'tag'         => 'atk_instructor_dashboard',
			'description' => 'The full Instructor Dashboard: Driver Results, Course Builder, Question Bank, and Major Streets tabs (used by the Instructor Dashboard page).',
		),
		array(
			'tag'         => 'atk_signin_form',
			'description' => 'The Sign In form (used by the Sign In page, inside its own minimal header-auth.php/footer-auth.php shell).',
		),
		array(
			'tag'         => 'atk_signup_form',
			'description' => 'The Sign Up form (used by the Sign Up page, inside its own minimal header-auth.php/footer-auth.php shell).',
		),
	);
}
