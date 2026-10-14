<?php
/**
 * WordPress-dependent checks for the "Check for updates" link and its handler.
 *
 *     ATK_WP_LOAD=/path/to/wp-load.php php tests/updater/integration.php
 *
 * GitHub is stubbed, so no network is used.
 */
$load = getenv( 'ATK_WP_LOAD' );
if ( ! $load || ! file_exists( $load ) ) {
	fwrite( STDERR, "Set ATK_WP_LOAD to the path of wp-load.php.\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']      = getenv( 'ATK_WP_HOST' ) ? getenv( 'ATK_WP_HOST' ) : '127.0.0.1:8099';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $load;

$fail = 0;
$n    = 0;
function t( $label, $actual, $expected ) {
	global $fail, $n;
	$n++;
	if ( $actual === $expected ) {
		echo "ok    $label\n";
		return;
	}
	$fail++;
	echo "FAIL  $label\n      expected: " . json_encode( $expected ) . "\n      actual:   " . json_encode( $actual ) . "\n";
}

$tag = 'v99.0.0';
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$tag ) {
	if ( false !== strpos( $url, 'api.github.com/repos/' ) ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'tag_name' => $tag, 'zipball_url' => 'https://api.github.com/zip', 'html_url' => 'https://github.com/x/releases', 'body' => '' ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	}
	return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
}, 10, 3 );

// The wording of the three outcomes.
$status = akn_update_status( array( 'version' => '99.0.0' ), '1.1.0' );
t( 'a newer release is reported as available', $status['state'], 'available' );
t( 'the available message names the version', strpos( $status['message'], '99.0.0' ) !== false, true );
t( 'the same version is reported as current', akn_update_status( array( 'version' => '1.1.0' ), '1.1.0' )['state'], 'current' );
t( 'an older release is reported as current', akn_update_status( array( 'version' => '1.0.0' ), '1.1.0' )['state'], 'current' );
t( 'no release found is reported as unknown', akn_update_status( false, '1.1.0' )['state'], 'unknown' );

// The link on the Plugins row.
$admin = wp_insert_user( array( 'user_login' => 'zz_it_updater', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
wp_set_current_user( $admin );
$links = apply_filters( 'plugin_action_links_' . plugin_basename( ATK_PLUGIN_FILE ), array( 'deactivate' => '<a>Deactivate</a>' ) );
$joined = implode( ' ', $links );
t( 'an administrator gets a Check for updates link', strpos( $joined, 'Check for updates' ) !== false, true );
t( 'the link goes through admin-post with a nonce', strpos( $joined, 'action=akn_check_updates' ) !== false && strpos( $joined, '_wpnonce=' ) !== false, true );
t( 'the existing Deactivate link is kept', strpos( $joined, 'Deactivate' ) !== false, true );
wp_set_current_user( 0 );
t( 'a logged-out visitor gets no link', implode( ' ', apply_filters( 'plugin_action_links_' . plugin_basename( ATK_PLUGIN_FILE ), array() ) ), '' );
wp_set_current_user( $admin );

// The check itself bypasses the 6 hour cache.
set_site_transient( AKN_RELEASE_TRANSIENT, array( 'version' => '1.0.0', 'url' => '', 'package' => '', 'body' => '' ), 6 * HOUR_IN_SECONDS );
$result = akn_run_update_check();
t( 'the check ignores a cached older answer', $result['state'], 'available' );
t( 'the cache now holds the fresh release', get_site_transient( AKN_RELEASE_TRANSIENT )['version'], '99.0.0' );
$tag = 'v' . ATK_VERSION;
t( 'when GitHub has only the installed version the site is current', akn_run_update_check()['state'], 'current' );

// The notice after a check.
global $pagenow;
$pagenow = 'plugins.php';
$_GET['akn_checked'] = 'available';
$_GET['akn_version'] = '99.0.0';
ob_start();
do_action( 'admin_notices' );
$notice = ob_get_clean();
t( 'the Plugins screen shows the available version once', strpos( $notice, 'Aberdeen Knowledge 99.0.0 is available' ) !== false, true );
$_GET['akn_version'] = '<script>x</script>';
ob_start();
do_action( 'admin_notices' );
t( 'a tampered version is not printed as markup', strpos( ob_get_clean(), '<script>' ), false );
$_GET['akn_checked'] = 'current';
$_GET['akn_version'] = ATK_VERSION;
ob_start();
do_action( 'admin_notices' );
t( 'an up to date site gets the success notice', strpos( ob_get_clean(), 'notice-success' ) !== false, true );

delete_site_transient( AKN_RELEASE_TRANSIENT );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $admin );
echo "\n$n checks, $fail failed\n";
exit( $fail ? 1 : 0 );
