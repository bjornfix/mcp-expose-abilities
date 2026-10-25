<?php
/**
 * Selected native maintenance status. No package URLs, configuration files or debug dumps.
 *
 * @package MCP_Expose_Abilities
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Return only the supported native core-update policy enum. */
function mcp_expose_core_auto_update_constant() {
	if ( ! defined( 'WP_AUTO_UPDATE_CORE' ) ) {
		return null;
	}
	$value = WP_AUTO_UPDATE_CORE;
	return in_array( $value, array( true, false, 'minor', 'beta', 'rc', 'development', 'branch-development' ), true ) ? $value : 'unrecognized';
}

/** Read update offers and saved choices; refresh only on explicit request. */
function mcp_expose_get_maintenance_status( $input = array() ): array {
	global $wp_version;
	$stage = 'load';
	try {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$refreshed = true === ( $input['refresh'] ?? false );
		if ( $refreshed ) {
			$stage = 'refresh';
			wp_clean_update_cache();
			wp_version_check();
			wp_update_plugins();
			wp_update_themes();
		}
		$stage = 'inventory';
		$plugins = get_plugins();
		$themes  = wp_get_themes();
		$plugin_cache = get_site_transient( 'update_plugins' );
		$theme_cache = get_site_transient( 'update_themes' );
		$core_cache = get_site_transient( 'update_core' );
		$plugin_known = is_object( $plugin_cache ) && isset( $plugin_cache->response ) && is_array( $plugin_cache->response );
		$theme_known = is_object( $theme_cache ) && isset( $theme_cache->response ) && is_array( $theme_cache->response );
		$core_known = is_object( $core_cache ) && isset( $core_cache->updates ) && is_array( $core_cache->updates );
		$automatic_disabled = ( new WP_Automatic_Updater() )->is_disabled();
		$items = array();
		foreach ( $plugins as $file => $plugin ) {
			$offer = $plugin_known ? ( $plugin_cache->response[ $file ] ?? null ) : null;
			$forced = $offer ? wp_is_auto_update_forced_for_item( 'plugin', null, $offer ) : null;
			$items[] = array(
				'plugin' => $file, 'current_version' => (string) $plugin['Version'],
				'new_version' => $offer ? (string) ( $offer->new_version ?? '' ) : null,
				'update_available' => $plugin_known ? null !== $offer : null,
				'offer_auto_update_forced' => null === $forced ? null : (bool) $forced,
				'provider_auto_update' => $offer ? ! empty( $offer->autoupdate ) : null,
				'mcp_update_allowed_by_policy' => mcp_expose_plugin_code_writes_enabled( 'plugins/update' ) || mcp_expose_is_policy_allowed_plugin_update( $file ),
			);
		}
		$theme_items = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$offer = $theme_known ? ( $theme_cache->response[ $stylesheet ] ?? null ) : null;
			$native_offer = $offer ? (object) $offer : null;
			$forced = $offer ? wp_is_auto_update_forced_for_item( 'theme', null, $native_offer ) : null;
			$theme_items[] = array(
				'theme' => $stylesheet, 'current_version' => (string) $theme->get( 'Version' ),
				'new_version' => $offer ? (string) ( $native_offer->new_version ?? '' ) : null,
				'update_available' => $theme_known ? null !== $offer : null,
				'offer_auto_update_forced' => null === $forced ? null : (bool) $forced,
				'provider_auto_update' => $offer ? ! empty( $native_offer->autoupdate ) : null,
			);
		}
		$core_offers = array();
		foreach ( $core_known ? $core_cache->updates : array() as $offer ) {
			$version = (string) ( $offer->current ?? '' );
			$core_offers[] = array(
				'version' => $version,
				'response' => in_array( $offer->response ?? '', array( 'upgrade', 'latest', 'development' ), true ) ? $offer->response : 'unknown',
				'offer_auto_update_allowed' => ! $automatic_disabled && (bool) wp_is_auto_update_forced_for_item( 'core', Core_Upgrader::should_update_to_version( $version ), $offer ),
			);
		}
		$core_options = array();
		foreach ( array( 'dev', 'minor', 'major' ) as $kind ) {
			$value = get_site_option( 'auto_update_core_' . $kind, 'unset' );
			$core_options[ $kind ] = in_array( $value, array( 'enabled', 'disabled', 'unset' ), true ) ? $value : 'unrecognized';
		}
		return array(
			'success' => $plugin_known && $theme_known && $core_known, 'refresh_requested' => $refreshed,
			'core' => array( 'current_version' => (string) $wp_version, 'inventory_known' => $core_known, 'offers' => $core_offers,
				'last_checked' => $core_known ? (int) ( $core_cache->last_checked ?? 0 ) : null,
				'auto_update_constant' => mcp_expose_core_auto_update_constant(), 'saved_auto_update_options' => $core_options,
				'constant_change_supported' => true ),
			'plugins' => array( 'auto_update_ui_enabled' => wp_is_auto_update_enabled_for_type( 'plugin' ), 'inventory_known' => $plugin_known, 'last_checked' => $plugin_known ? (int) ( $plugin_cache->last_checked ?? 0 ) : null, 'items' => $items ),
			'themes' => array( 'auto_update_ui_enabled' => wp_is_auto_update_enabled_for_type( 'theme' ), 'inventory_known' => $theme_known, 'last_checked' => $theme_known ? (int) ( $theme_cache->last_checked ?? 0 ) : null, 'items' => $theme_items ),
			'automatic_updater_disabled' => $automatic_disabled, 'saved_plugin_theme_choices_included' => false,
			'saved_choices_reader' => 'options/get',
			'note' => 'Read saved plugin/theme choices through the existing options/get Interface. Forced native offer policy, provider defaults and native UI availability are separate from those choices. No software or policy was changed.',
		);
	} catch ( Throwable $error ) {
		return array( 'success' => false, 'failed_stage' => $stage, 'inventory_known' => false, 'software_changed' => false, 'policy_changed' => false );
	}
}

/** Run a fixed set of native tests and return only enumerated status values. */
function mcp_expose_get_site_health( $input = array() ): array {
	try {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		$health = WP_Site_Health::get_instance();
	} catch ( Throwable $error ) {
		return array( 'success' => false, 'complete' => false, 'tests' => array(), 'debug_dump_returned' => false );
	}
	$tests = array();
	$complete = true;
	foreach ( array( 'wordpress_version', 'plugin_version', 'theme_version', 'php_version', 'php_extensions', 'sql_server', 'https_status', 'scheduled_events', 'background_updates', 'loopback_requests', 'rest_availability' ) as $name ) {
		$status = 'unavailable';
		try {
			$result = $health->{'get_test_' . $name}();
			if ( is_array( $result ) && in_array( $result['status'] ?? null, array( 'good', 'recommended', 'critical' ), true ) ) {
				$status = $result['status'];
			}
		} catch ( Throwable $error ) {
			$status = 'unavailable';
		}
		$complete = $complete && 'unavailable' !== $status;
		$tests[] = array( 'test' => $name, 'status' => $status );
	}
	return array( 'success' => $complete, 'complete' => $complete, 'tests' => $tests, 'debug_dump_returned' => false,
		'scope' => 'selected_native_tests', 'note' => 'Native tests can refresh caches and schedule the native health check. Descriptions, debug information, paths, URLs and raw errors are excluded.' );
}

/** Verify only files listed in one installed plugin's existing signed release identity. */
function mcp_expose_verify_managed_installation( $input = array() ): array {
	$plugin = (string) ( $input['plugin'] ?? '' );
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) { return array( 'success' => false, 'code' => 'permission_denied' ); }
	if ( ! preg_match( '~^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*\.php$~D', $plugin ) ) { return array( 'success' => false, 'code' => 'invalid_plugin_identifier' ); }
	if ( ! function_exists( 'devenia_mcp_updater_manifest_plugins' ) ) { return array( 'success' => false, 'code' => 'native_signed_updater_unavailable' ); }
	try {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed = get_plugins();
		$entries = devenia_mcp_updater_manifest_plugins();
		$entry = $entries[ $plugin ] ?? null;
		if ( ! isset( $installed[ $plugin ] ) || ! is_array( $entry ) ) { return array( 'success' => false, 'code' => 'installed_signed_identity_unavailable' ); }
		$identity = $entry['releaseIdentity'] ?? array();
		$members = $identity['members'] ?? array();
		$slug = (string) ( $entry['slug'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) || dirname( $plugin ) !== $slug
			|| ( $identity['mainFile'] ?? '' ) !== $plugin || ! is_array( $members ) || !$members || count( $members ) > 2000 ) { return array( 'success' => false, 'code' => 'unsupported_signed_identity' ); }
		$plugins_root = realpath( WP_PLUGIN_DIR );
		$root = realpath( WP_PLUGIN_DIR . '/' . $slug );
		if ( false === $root || false === $plugins_root || ! str_starts_with( $root, $plugins_root . DIRECTORY_SEPARATOR ) ) { return array( 'success' => false, 'code' => 'plugin_root_unavailable' ); }
		$results = array(); $matched = 0;
		foreach ( $members as $member ) {
			$member_path = (string) ( $member['path'] ?? '' );
			$expected = (string) ( $member['sha256'] ?? '' );
			if ( ! str_starts_with( $member_path, $slug . '/' ) || str_contains( $member_path, '\\' ) || str_contains( $member_path, "\0" )
				|| in_array( '..', explode( '/', $member_path ), true ) || in_array( '.', explode( '/', $member_path ), true )
				|| ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) { return array( 'success' => false, 'code' => 'invalid_signed_member_path' ); }
			$relative = substr( $member_path, strlen( $slug ) + 1 );
			$path = realpath( $root . '/' . $relative );
			$status = 'missing_or_outside_plugin'; $actual = null;
			if ( false !== $path && str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) && is_file( $path ) ) {
				$actual = hash_file( 'sha256', $path );
				$status = is_string( $actual ) && hash_equals( $expected, $actual ) && (int) $member['size'] === wp_filesize( $path ) ? 'matched' : 'mismatch';
			}
			if ( 'matched' === $status ) { ++$matched; }
			$results[] = array( 'member' => $member_path, 'status' => $status, 'expected_sha256' => $expected, 'actual_sha256' => is_string( $actual ) ? $actual : null );
		}
		$version = (string) $installed[ $plugin ]['Version'];
		return array( 'success' => count( $members ) === $matched && $version === (string) $entry['version'], 'plugin' => $plugin,
			'installed_version' => $version, 'manifest_version' => (string) $entry['version'], 'package_sha256' => (string) $entry['sha256'],
			'source_commit' => $identity['source']['commit'] ?? null, 'checked_files' => count( $results ), 'matched_files' => $matched,
			'files' => $results, 'scope' => 'signed_release_members_only', 'additional_files_checked' => false, 'file_contents_returned' => false );
	} catch ( Throwable $error ) {
		return array( 'success' => false, 'code' => 'native_installation_verification_failed', 'file_contents_returned' => false );
	}
}
