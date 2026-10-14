<?php
/**
 * GitHub release updater.
 *
 * Makes Plugins show "A new version is available" when this plugin's GitHub
 * repository has a newer release than the installed Version, and lets
 * "Update now" install it. It uses the standard "Update URI" mechanism
 * (the plugin header points at github.com), so it keeps working whichever
 * theme is active.
 *
 * A public repository needs nothing else. For a private repository, define a
 * read-only token in wp-config.php:
 *
 *     define( 'AKN_GITHUB_TOKEN', '...' );
 *
 * (fine-grained token, this repository only, "Contents: read-only"). A token
 * made for a different repository, such as the old theme's, cannot read this
 * one.
 *
 * Release flow: bump Version in aberdeen-knowledge.php, push a `vX.Y.Z` tag,
 * and .github/workflows/release.yml publishes the release.
 */

defined( 'ABSPATH' ) || exit;

const AKN_GITHUB_REPO       = 'kohid/Aberdeen-Knowledge';
const AKN_RELEASE_TRANSIENT = 'akn_github_latest_release';

function akn_github_token() {
	return defined( 'AKN_GITHUB_TOKEN' ) ? (string) AKN_GITHUB_TOKEN : '';
}

function akn_github_headers( $accept = 'application/vnd.github+json' ) {
	$headers = array(
		'Accept'     => $accept,
		'User-Agent' => 'aberdeen-knowledge-updater',
	);
	if ( akn_github_token() ) {
		$headers['Authorization'] = 'Bearer ' . akn_github_token();
	}
	return $headers;
}

/**
 * Latest release as array( version, url, package, body ), or false. Cached
 * for 6 hours; a failed lookup is cached for 1 hour so a bad token does not
 * hit GitHub on every request.
 */
function akn_get_latest_release( $force = false ) {
	$cached = $force ? false : get_site_transient( AKN_RELEASE_TRANSIENT );
	if ( false !== $cached ) {
		return $cached ?: false;
	}

	$release = false;
	$res     = wp_remote_get(
		'https://api.github.com/repos/' . AKN_GITHUB_REPO . '/releases/latest',
		array( 'timeout' => 10, 'headers' => akn_github_headers() )
	);
	if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! empty( $body['tag_name'] ) && ! empty( $body['zipball_url'] ) ) {
			$release = array(
				'version' => ltrim( $body['tag_name'], 'vV' ),
				'url'     => $body['html_url'] ?? 'https://github.com/' . AKN_GITHUB_REPO . '/releases',
				'package' => $body['zipball_url'],
				'body'    => isset( $body['body'] ) ? (string) $body['body'] : '',
			);
		}
	}

	set_site_transient( AKN_RELEASE_TRANSIENT, $release ?: 0, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $release;
}

/**
 * WordPress asks this when it checks for plugin updates, for every plugin
 * whose Update URI is on github.com. "Check again" on Dashboard, Updates
 * bypasses the cache.
 */
add_filter( 'update_plugins_github.com', function ( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( ATK_PLUGIN_FILE ) !== $plugin_file ) {
		return $update;
	}
	$release = akn_get_latest_release( ! empty( $_GET['force-check'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $release ) {
		return $update;
	}
	return array(
		'id'           => 'github.com/' . AKN_GITHUB_REPO,
		'slug'         => dirname( $plugin_file ),
		'plugin'       => $plugin_file,
		'version'      => $release['version'],
		'url'          => $release['url'],
		'package'      => $release['package'],
		'requires'     => '6.0',
		'requires_php' => '7.4',
		'autoupdate'   => false,
		'translations' => array(),
	);
}, 10, 3 );

/** The "View details" box on the Plugins screen. */
add_filter( 'plugins_api', function ( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( ATK_PLUGIN_FILE ) ) !== $args->slug ) {
		return $result;
	}
	$release = akn_get_latest_release();
	$info    = get_plugin_data( ATK_PLUGIN_FILE, false, false );
	return (object) array(
		'name'          => $info['Name'],
		'slug'          => $args->slug,
		'version'       => $release ? $release['version'] : $info['Version'],
		'author'        => $info['Author'],
		'homepage'      => $release ? $release['url'] : 'https://github.com/' . AKN_GITHUB_REPO,
		'requires'      => '6.0',
		'requires_php'  => '7.4',
		'download_link' => $release ? $release['package'] : '',
		'sections'      => array(
			'description' => wp_kses_post( wpautop( $info['Description'] ) ),
			'changelog'   => $release && '' !== $release['body'] ? wp_kses_post( wpautop( $release['body'] ) ) : 'See the releases page on GitHub.',
		),
	);
}, 10, 3 );

/**
 * Private repository downloads: the zipball address needs the token, and it
 * redirects to a signed storage address that must be fetched WITHOUT the
 * Authorization header.
 */
add_filter( 'upgrader_pre_download', function ( $reply, $package ) {
	if ( 0 !== strpos( (string) $package, 'https://api.github.com/repos/' . AKN_GITHUB_REPO . '/' ) ) {
		return $reply;
	}
	$first = wp_remote_get( $package, array(
		'timeout'     => 30,
		'redirection' => 0,
		'headers'     => akn_github_headers(),
	) );
	if ( is_wp_error( $first ) ) {
		return $first;
	}
	$location = wp_remote_retrieve_header( $first, 'location' );
	if ( ! $location ) {
		return new WP_Error( 'akn_download_failed', 'GitHub did not return a download location. For a private repository, check AKN_GITHUB_TOKEN in wp-config.php.' );
	}
	return download_url( $location, 300 );
}, 10, 2 );

/**
 * GitHub zips extract to "<owner>-<repo>-<sha>/"; WordPress needs the folder
 * to match the installed plugin folder or it installs a second copy.
 */
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra ) {
	if ( empty( $hook_extra['plugin'] ) || plugin_basename( ATK_PLUGIN_FILE ) !== $hook_extra['plugin'] ) {
		return $source;
	}
	$target = trailingslashit( $remote_source ) . dirname( $hook_extra['plugin'] ) . '/';
	if ( trailingslashit( $source ) === $target ) {
		return $source;
	}
	global $wp_filesystem;
	if ( $wp_filesystem && $wp_filesystem->move( $source, $target, true ) ) {
		return $target;
	}
	return new WP_Error( 'akn_rename_failed', 'Could not prepare the downloaded plugin folder.' );
}, 10, 4 );

add_action( 'upgrader_process_complete', function () {
	delete_site_transient( AKN_RELEASE_TRANSIENT );
} );
