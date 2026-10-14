<?php
/**
 * Setup: roles, the managed-page registry, the account menu, the page toolbar,
 * and the front-end-only lockout for drivers and instructors.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The account area: signed-in avatar with a dropdown (dashboard, profile,
 * settings, dark mode, log out), or the dark-mode switch with Sign In and
 * Sign Up links when logged out. One implementation shared by the
 * [atk_user_menu] shortcode and any direct call, so an Elementor-built header
 * can drop it in without a second copy of the markup. It is a plain Bootstrap
 * dropdown, so it needs only the plugin's own assets.
 */
function atk_render_user_menu() {
	akn_enqueue_app_assets();
	ob_start();
	if ( is_user_logged_in() ) :
		$current_user  = wp_get_current_user();
		$dashboard_url = atk_current_user_dashboard_url();
		?>
		<div class="dropdown d-flex align-items-center justify-content-end">
			<button type="button" class="btn btn-icon btn-circle btn-active-light-primary w-40px h-40px p-0" data-bs-toggle="dropdown" data-bs-offset="0,8" aria-expanded="false" aria-label="Account menu">
				<?php echo atk_get_avatar_html( $current_user->ID, 40 ); ?>
			</button>
			<ul class="dropdown-menu dropdown-menu-end akn-user-menu py-4 fs-6">
				<li>
					<div class="d-flex align-items-center px-5 py-2">
						<div class="me-4"><?php echo atk_get_avatar_html( $current_user->ID, 50 ); ?></div>
						<div class="d-flex flex-column min-w-0">
							<div class="fw-bolder fs-5 text-gray-900 text-truncate"><?php echo esc_html( $current_user->display_name ); ?></div>
							<div class="fw-bold text-muted fs-7 text-truncate"><?php echo esc_html( $current_user->user_email ); ?></div>
						</div>
					</div>
				</li>
				<li><hr class="dropdown-divider my-2" /></li>
				<?php if ( $dashboard_url ) : ?>
					<li><a class="dropdown-item px-5" href="<?php echo esc_url( $dashboard_url ); ?>">Dashboard</a></li>
				<?php endif; ?>
				<li><a class="dropdown-item px-5" href="<?php echo esc_url( atk_get_page_url( 'profile' ) ); ?>">My Profile</a></li>
				<?php if ( atk_is_instructor( $current_user ) ) : ?>
					<li><a class="dropdown-item px-5" href="<?php echo esc_url( atk_get_page_url( 'settings' ) ); ?>">Settings</a></li>
				<?php endif; ?>
				<li><hr class="dropdown-divider my-2" /></li>
				<li class="px-5 py-1"><?php atk_render_theme_mode_toggle(); ?></li>
				<li><hr class="dropdown-divider my-2" /></li>
				<li><a class="dropdown-item px-5" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Log Out</a></li>
			</ul>
		</div>
	<?php else : ?>
		<div class="dropdown d-flex align-items-center justify-content-end">
			<button type="button" class="btn btn-icon btn-circle btn-active-light-primary w-40px h-40px p-0" data-bs-toggle="dropdown" data-bs-offset="0,8" aria-expanded="false" aria-label="Account menu">
				<i class="bi bi-person-circle fs-1 text-gray-500" aria-hidden="true"></i>
			</button>
			<ul class="dropdown-menu dropdown-menu-end akn-user-menu py-4 fs-6">
				<li>
					<div class="d-flex align-items-center px-5 py-2">
						<div class="me-4"><i class="bi bi-person-circle text-gray-500" style="font-size:3rem;line-height:1" aria-hidden="true"></i></div>
						<div class="d-flex flex-column">
							<div class="fw-bolder fs-5 text-gray-900">Guest</div>
							<div class="fw-bold text-muted fs-7">Not signed in</div>
						</div>
					</div>
				</li>
				<li><hr class="dropdown-divider my-2" /></li>
				<li><a class="dropdown-item px-5" href="<?php echo esc_url( atk_get_page_url( 'signin' ) ); ?>">Sign In</a></li>
				<li><a class="dropdown-item px-5" href="<?php echo esc_url( atk_get_page_url( 'signup' ) ); ?>">Sign Up</a></li>
				<li><hr class="dropdown-divider my-2" /></li>
				<li class="px-5 py-1"><?php atk_render_theme_mode_toggle(); ?></li>
			</ul>
		</div>
	<?php endif;
	return ob_get_clean();
}
add_shortcode( 'atk_user_menu', 'atk_render_user_menu' );

/**
 * Every page the plugin manages, keyed by a short name used throughout the
 * code, mapped to the page slug, title and template file (see
 * inc/templates.php for the template files).
 */
function atk_managed_pages() {
	return array(
		'signin'   => array(
			'slug'     => 'sign-in',
			'title'    => 'Sign In',
			'template' => 'template-signin.php',
		),
		'signup'   => array(
			'slug'     => 'sign-up',
			'title'    => 'Sign Up',
			'template' => 'template-signup.php',
		),
		'forgot'   => array(
			'slug'     => 'forgot-password',
			'title'    => 'Forgot Password',
			'template' => 'template-forgot-password.php',
		),
		'reset'    => array(
			'slug'     => 'reset-password',
			'title'    => 'Reset Password',
			'template' => 'template-reset-password.php',
		),
		'profile'  => array(
			'slug'     => 'profile',
			'title'    => 'My Profile',
			'template' => '',
		),
		'street-test' => array(
			'slug'     => 'street-test',
			'title'    => 'Street Test',
			'template' => '',
		),
		'poi-test' => array(
			'slug'     => 'poi-test',
			'title'    => 'POI Test',
			'template' => '',
		),
		'route-test' => array(
			'slug'     => 'route-test',
			'title'    => 'Route Test',
			'template' => '',
		),
		'study-tools' => array(
			'slug'     => 'study-tool',
			'title'    => 'Study Tool',
			'template' => '',
		),
		'major-streets' => array(
			'slug'     => 'major-streets',
			'title'    => 'Major Streets',
			'template' => 'template-study-major-streets.php',
		),
		'neighbourhoods' => array(
			'slug'     => 'neighbourhoods',
			'title'    => 'Neighbourhoods',
			'template' => 'template-study-neighbourhoods.php',
		),
		'places-of-interest' => array(
			'slug'     => 'places-of-interest',
			'title'    => 'Places of Interest',
			'template' => 'template-study-places-of-interest.php',
		),
		'course' => array(
			'slug'     => 'course',
			'title'    => 'Course',
			'template' => '',
		),
		'official-test-course' => array(
			'slug'     => 'official-test-course',
			'title'    => 'Official Test Course',
			'template' => '',
		),
		'course-test' => array(
			'slug'     => 'course-test',
			'title'    => 'Course Test',
			'template' => '',
		),
		'instructor-dashboard' => array(
			'slug'     => 'instructor-dashboard',
			'title'    => 'Instructor Dashboard',
			'template' => '',
		),
		'settings' => array(
			'slug'     => 'settings',
			'title'    => 'Settings',
			'template' => '',
		),
	);
}

/**
 * The two roles. add_role() does nothing once a role exists, so this is
 * cheap enough to run on every request and survives a database restore.
 */
add_action( 'init', function () {
	add_role( 'driver', 'Driver', array( 'read' => true ) );
	add_role( 'instructor', 'Instructor', array( 'read' => true ) );
} );

/**
 * First run on a site: create any managed page that does not exist yet, so
 * the plugin works without manual wp-admin setup. Pages that already exist
 * (from the old theme, or made by hand) are left exactly as they are, and a
 * page deleted on purpose later is not brought back.
 */
function atk_install_pages() {
	// The shortcode each page shows. The forgot/reset password pages and the
	// map tools draw themselves from their plugin templates and need no content.
	$content = array(
		'signin'               => '[atk_signin_form]',
		'signup'               => '[atk_signup_form]',
		'profile'              => '[atk_profile]',
		'street-test'          => '[atk_quiz type="street"]',
		'poi-test'             => '[atk_quiz type="poi"]',
		'route-test'           => '[atk_quiz type="route"]',
		'study-tools'          => '[atk_study_tools]',
		'course'               => '[atk_course_catalog]',
		'official-test-course' => '[atk_course_hub]',
		'course-test'          => '[atk_course_test_type][atk_course_test]',
		'instructor-dashboard' => '[atk_instructor_dashboard]',
		'settings'             => '[atk_settings]',
	);
	foreach ( atk_managed_pages() as $key => $page ) {
		if ( get_page_by_path( $page['slug'] ) ) {
			continue;
		}
		$page_id = wp_insert_post( array(
			'post_title'   => $page['title'],
			'post_name'    => $page['slug'],
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => isset( $content[ $key ] ) ? $content[ $key ] : '',
		) );
		if ( $page_id && ! is_wp_error( $page_id ) && $page['template'] ) {
			update_post_meta( $page_id, '_wp_page_template', $page['template'] );
		}
	}
}

add_action( 'init', function () {
	if ( get_option( 'atk_plugin_installed' ) ) {
		return;
	}
	atk_install_pages();
	update_option( 'atk_plugin_installed', ATK_VERSION, false );
}, 20 );

/** The URL of one of the managed pages by its short key (signin, signup, forgot, reset, profile, ...). */
function atk_get_page_url( $key ) {
	static $cache = array();

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$pages = atk_managed_pages();
	if ( ! isset( $pages[ $key ] ) ) {
		return home_url( '/' );
	}

	$page = get_page_by_path( $pages[ $key ]['slug'] );
	$cache[ $key ] = $page ? get_permalink( $page ) : home_url( '/' );

	return $cache[ $key ];
}

/**
 * The page-title and breadcrumb bar shown at the top of the logged-in app
 * pages (Profile, Course, Official Test Course, Instructor Dashboard,
 * Settings, Study Tools). Not used on the auth pages or the full-screen map
 * tools. $trail is a list of [label, url] pairs for everything before the
 * current page; url may be '' for a crumb that is not a link. "Home" is added
 * automatically.
 */
function atk_render_page_toolbar( $title, $trail = array() ) {
	?>
	<div class="toolbar py-5 py-lg-10" id="akn_toolbar">
		<div class="container-xxl d-flex flex-stack flex-wrap">
			<div class="page-title d-flex flex-column me-3">
				<h1 class="d-flex text-gray-900 fw-bolder my-1 fs-3"><?php echo esc_html( $title ); ?></h1>
				<ul class="breadcrumb breadcrumb-separatorless fw-bold fs-7 my-1">
					<li class="breadcrumb-item text-gray-500">
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="text-gray-500 text-hover-primary">Home</a>
					</li>
					<?php foreach ( $trail as $crumb ) :
						list( $label, $url ) = $crumb;
						?>
						<li class="breadcrumb-item"><span class="bullet bg-secondary w-5px h-2px"></span></li>
						<li class="breadcrumb-item text-gray-500">
							<?php if ( $url ) : ?>
								<a href="<?php echo esc_url( $url ); ?>" class="text-gray-500 text-hover-primary"><?php echo esc_html( $label ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $label ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
					<li class="breadcrumb-item"><span class="bullet bg-secondary w-5px h-2px"></span></li>
					<li class="breadcrumb-item text-gray-900"><?php echo esc_html( $title ); ?></li>
				</ul>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Drivers and Instructors only ever use the front end. Keep them out of
 * wp-admin and hide the admin bar for them; instructors get their own
 * front-end dashboard (inc/instructor.php) instead.
 *
 * admin-post.php is exempted alongside admin-ajax.php: WordPress runs
 * 'admin_init' for that request too (it is a real wp-admin bootstrap), but it
 * is also where every front-end form posts (course enrollment, avatar upload,
 * settings, password reset, ...). Without the exemption each of those forms
 * would bounce a driver or instructor to their dashboard instead of ever
 * reaching its admin_post_{action} handler.
 */
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || ( isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'] ) ) {
		return;
	}

	$user  = wp_get_current_user();
	$roles = (array) $user->roles;
	if ( in_array( 'driver', $roles, true ) ) {
		wp_safe_redirect( atk_get_page_url( 'profile' ) );
		exit;
	}
	if ( in_array( 'instructor', $roles, true ) ) {
		wp_safe_redirect( atk_get_page_url( 'instructor-dashboard' ) );
		exit;
	}
} );

add_filter( 'show_admin_bar', function ( $show ) {
	if ( is_user_logged_in() ) {
		$roles = (array) wp_get_current_user()->roles;
		if ( in_array( 'driver', $roles, true ) || in_array( 'instructor', $roles, true ) ) {
			return false;
		}
	}
	return $show;
} );
