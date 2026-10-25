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
		$selected_plugins = (array) get_site_option( 'auto_update_plugins', array() );
		$selected_themes = (array) get_site_option( 'auto_update_themes', array() );
		$automatic_disabled = ( new WP_Automatic_Updater() )->is_disabled();
		$items = array();
		foreach ( $plugins as $file => $plugin ) {
			$offer = $plugin_known ? ( $plugin_cache->response[ $file ] ?? null ) : null;
			$selected = in_array( $file, $selected_plugins, true );
			$base = $offer && ( ! empty( $offer->autoupdate ) || ( wp_is_auto_update_enabled_for_type( 'plugin' ) && $selected ) );
			$items[] = array(
				'plugin' => $file, 'current_version' => (string) $plugin['Version'],
				'new_version' => $offer ? (string) ( $offer->new_version ?? '' ) : null,
				'update_available' => $plugin_known ? null !== $offer : null,
				'auto_update_selected' => $selected,
				'offer_auto_update_allowed' => $offer ? ! $automatic_disabled && (bool) apply_filters( 'auto_update_plugin', (bool) $base, $offer ) : null,
				'mcp_update_allowed_by_policy' => mcp_expose_plugin_code_writes_enabled( 'plugins/update' ) || mcp_expose_is_policy_allowed_plugin_update( $file ),
			);
		}
		$theme_items = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$offer = $theme_known ? ( $theme_cache->response[ $stylesheet ] ?? null ) : null;
			$selected = in_array( $stylesheet, $selected_themes, true );
			$native_offer = $offer ? (object) $offer : null;
			$base = $offer && ( ! empty( $native_offer->autoupdate ) || ( wp_is_auto_update_enabled_for_type( 'theme' ) && $selected ) );
			$theme_items[] = array(
				'theme' => $stylesheet, 'current_version' => (string) $theme->get( 'Version' ),
				'new_version' => $offer ? (string) ( $native_offer->new_version ?? '' ) : null,
				'update_available' => $theme_known ? null !== $offer : null,
				'auto_update_selected' => $selected,
				'offer_auto_update_allowed' => $offer ? ! $automatic_disabled && (bool) apply_filters( 'auto_update_theme', (bool) $base, $native_offer ) : null,
			);
		}
		$core_offers = array();
		foreach ( $core_known ? $core_cache->updates : array() as $offer ) {
			$version = (string) ( $offer->current ?? '' );
			$core_offers[] = array(
				'version' => $version,
				'response' => in_array( $offer->response ?? '', array( 'upgrade', 'latest', 'development' ), true ) ? $offer->response : 'unknown',
				'offer_auto_update_allowed' => ! $automatic_disabled && (bool) apply_filters( 'auto_update_core', Core_Upgrader::should_update_to_version( $version ), $offer ),
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
			'plugins' => array( 'inventory_known' => $plugin_known, 'last_checked' => $plugin_known ? (int) ( $plugin_cache->last_checked ?? 0 ) : null, 'items' => $items ),
			'themes' => array( 'inventory_known' => $theme_known, 'last_checked' => $theme_known ? (int) ( $theme_cache->last_checked ?? 0 ) : null, 'items' => $theme_items ),
			'automatic_updater_disabled' => $automatic_disabled,
			'note' => 'Cached offers and saved choices are distinct. Policy eligibility does not prove filesystem, cron or runtime success. No software or policy was changed.',
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
