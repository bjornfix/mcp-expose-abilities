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

/** Fixed native message catalog. Raw native labels/descriptions never become output. */
function mcp_expose_health_catalog(): array {
	return array(
		'wordpress_version' => array('labels' => array(
			'WordPress version %s' => 'wordpress_version',
			'WordPress update available (%s)' => 'wordpress_update_available',
			'Your version of WordPress (%s) is up to date' => 'your_version_of_wordpress_is_up_to_date',
		), 'details' => array(
		) ),
		'plugin_version' => array('labels' => array(
			'Your plugins are all up to date' => 'your_plugins_are_all_up_to_date',
			'You have plugins waiting to be updated' => 'you_have_plugins_waiting_to_be_updated',
			'You should remove inactive plugins' => 'you_should_remove_inactive_plugins',
		), 'details' => array(
		) ),
		'theme_version' => array('labels' => array(
			'Your themes are all up to date' => 'your_themes_are_all_up_to_date',
			'You have themes waiting to be updated' => 'you_have_themes_waiting_to_be_updated',
			'You should remove inactive themes' => 'you_should_remove_inactive_themes',
			'Have a default theme available' => 'have_a_default_theme_available',
		), 'details' => array(
		) ),
		'php_version' => array('labels' => array(
			'Your site is running PHP %s' => 'your_site_is_running_php',
			'Unable to determine the status of the current PHP version (%s)' => 'unable_to_determine_the_status_of_the_current_php_version',
			'Your site is running a recommended version of PHP (%s)' => 'your_site_is_running_a_recommended_version_of_php',
			'Your site is running on an older version of PHP (%s)' => 'your_site_is_running_on_an_older_version_of_php',
			'Your site is running on an outdated version of PHP (%s), which soon will not be supported by WordPress.' => 'your_site_is_running_on_an_outdated_version_of_php_which_soon_will_not_be_supported_by_wordpress',
			'Your site is running on an older version of PHP (%s), which should be updated' => 'your_site_is_running_on_an_older_version_of_php_which_should_be_updated',
			'Your site is running on an outdated version of PHP (%s), which does not receive security updates and soon will not be supported by WordPress.' => 'your_site_is_running_on_an_outdated_version_of_php_which_does_not_receive_security_updates_and_soon_will_not_be_supported_by_wordpress',
			'Your site is running on an outdated version of PHP (%s), which does not receive security updates. It should be updated.' => 'your_site_is_running_on_an_outdated_version_of_php_which_does_not_receive_security_updates_it_should_be_updated',
		), 'details' => array(
		) ),
		'php_extensions' => array('labels' => array(
			'Required and recommended modules are installed' => 'required_and_recommended_modules_are_installed',
			'One or more recommended modules are missing' => 'one_or_more_recommended_modules_are_missing',
			'One or more required modules are missing' => 'one_or_more_required_modules_are_missing',
		), 'details' => array(
		) ),
		'sql_server' => array('labels' => array(
			'SQL server is up to date' => 'sql_server_is_up_to_date',
			'Outdated SQL server' => 'outdated_sql_server',
			'Severely outdated SQL server' => 'severely_outdated_sql_server',
		), 'details' => array(
		) ),
		'https_status' => array('labels' => array(
			'Your website is using an active HTTPS connection' => 'your_website_is_using_an_active_https_connection',
			'Your website does not use HTTPS' => 'your_website_does_not_use_https',
		), 'details' => array(
		) ),
		'scheduled_events' => array('labels' => array(
			'Scheduled events are running' => 'scheduled_events_are_running',
			'It was not possible to check your scheduled events' => 'it_was_not_possible_to_check_your_scheduled_events',
			'A scheduled event has failed' => 'a_scheduled_event_has_failed',
			'A scheduled event is late' => 'a_scheduled_event_is_late',
		), 'details' => array(
		) ),
		'background_updates' => array('labels' => array(
			'Background updates are working' => 'background_updates_are_working',
			'Background updates are not working as expected' => 'background_updates_are_not_working_as_expected',
			'Background updates may not be working properly' => 'background_updates_may_not_be_working_properly',
		), 'details' => array(
		) ),
		'loopback_requests' => array('labels' => array(
			'Your site can perform loopback requests' => 'your_site_can_perform_loopback_requests',
			'Your site could not complete a loopback request' => 'your_site_could_not_complete_a_loopback_request',
		), 'details' => array(
		) ),
		'rest_availability' => array('labels' => array(
			'The REST API is available' => 'the_rest_api_is_available',
			'The REST API encountered an error' => 'the_rest_api_encountered_an_error',
			'The REST API encountered an unexpected result' => 'the_rest_api_encountered_an_unexpected_result',
			'The REST API did not behave correctly' => 'the_rest_api_did_not_behave_correctly',
		), 'details' => array(
		) ),
		'php_default_timezone' => array('labels' => array(
			'PHP default timezone is valid' => 'php_default_timezone_is_valid',
			'PHP default timezone is invalid' => 'php_default_timezone_is_invalid',
		), 'details' => array(
		) ),
		'php_sessions' => array('labels' => array(
			'No PHP sessions detected' => 'no_php_sessions_detected',
			'An active PHP session was detected' => 'an_active_php_session_was_detected',
		), 'details' => array(
		) ),
		'ssl_support' => array('labels' => array(
			'Your site can communicate securely with other services' => 'your_site_can_communicate_securely_with_other_services',
			'Your site is unable to communicate securely with other services' => 'your_site_is_unable_to_communicate_securely_with_other_services',
		), 'details' => array(
		) ),
		'http_requests' => array('labels' => array(
			'HTTP requests seem to be working as expected' => 'http_requests_seem_to_be_working_as_expected',
			'HTTP requests are blocked' => 'http_requests_are_blocked',
			'HTTP requests are partially blocked' => 'http_requests_are_partially_blocked',
		), 'details' => array(
		) ),
		'is_in_debug_mode' => array('labels' => array(
			'Your site is not set to output debug information' => 'your_site_is_not_set_to_output_debug_information',
			'Your site is set to log errors to a potentially public file' => 'your_site_is_set_to_log_errors_to_a_potentially_public_file',
			'Your site is set to display errors to site visitors' => 'your_site_is_set_to_display_errors_to_site_visitors',
		), 'details' => array(
		) ),
		'file_uploads' => array('labels' => array(
			'Files can be uploaded' => 'files_can_be_uploaded',
			'The "%1$s" value is smaller than "%2$s"' => 'the_value_is_smaller_than',
		), 'details' => array(
			array( 'code' => 'file_uploads_disabled', 'source' => '%1$s is set to %2$s. You won\'t be able to upload files on your site.', 'status' => 'critical' ),
		) ),
		'plugin_theme_auto_updates' => array('labels' => array(
			'Plugin and theme auto-updates appear to be configured correctly' => 'plugin_and_theme_auto_updates_appear_to_be_configured_correctly',
			'Your site may have problems auto-updating plugins and themes' => 'your_site_may_have_problems_auto_updating_plugins_and_themes',
		), 'details' => array(
			array( 'code' => 'auto_update_ui_policy_mismatch', 'source' => 'Auto-updates for plugins and/or themes appear to be disabled, but settings are still set to be displayed. This could cause auto-updates to not work as expected.', 'status' => 'critical' ),
			array( 'code' => 'plugin_theme_updates_disabled', 'source' => 'Auto-updates for plugins and themes appear to be disabled. This will prevent your site from receiving new versions automatically when available.', 'status' => 'recommended' ),
			array( 'code' => 'plugin_updates_disabled', 'source' => 'Auto-updates for plugins appear to be disabled. This will prevent your site from receiving new versions automatically when available.', 'status' => 'recommended' ),
			array( 'code' => 'theme_updates_disabled', 'source' => 'Auto-updates for themes appear to be disabled. This will prevent your site from receiving new versions automatically when available.', 'status' => 'recommended' ),
		) ),
		'update_temp_backup_writable' => array('labels' => array(
			'Plugin and theme temporary backup directory is writable' => 'plugin_and_theme_temporary_backup_directory_is_writable',
			'Could not access filesystem' => 'could_not_access_filesystem',
			'Unable to locate WordPress content directory' => 'unable_to_locate_wordpress_content_directory',
			'Plugin and theme temporary backup directories exist but are not writable' => 'plugin_and_theme_temporary_backup_directories_exist_but_are_not_writable',
			'Plugin temporary backup directory exists but is not writable' => 'plugin_temporary_backup_directory_exists_but_is_not_writable',
			'Theme temporary backup directory exists but is not writable' => 'theme_temporary_backup_directory_exists_but_is_not_writable',
			'The temporary backup directory exists but is not writable' => 'the_temporary_backup_directory_exists_but_is_not_writable',
			'The upgrade directory exists but is not writable' => 'the_upgrade_directory_exists_but_is_not_writable',
			'The upgrade directory cannot be created' => 'the_upgrade_directory_cannot_be_created',
		), 'details' => array(
		) ),
		'available_updates_disk_space' => array('labels' => array(
			'Disk space available to safely perform updates' => 'disk_space_available_to_safely_perform_updates',
		), 'details' => array(
			array( 'code' => 'disk_space_unknown', 'source' => 'Could not determine available disk space for updates.', 'status' => 'recommended' ),
			array( 'code' => 'disk_space_critical', 'source' => 'Available disk space is critically low, less than %s available. Proceed with caution, updates may fail.', 'status' => 'critical' ),
			array( 'code' => 'disk_space_low', 'source' => 'Available disk space is low, less than %s available.', 'status' => 'recommended' ),
		) ),
		'autoloaded_options' => array('labels' => array(
			'Autoloaded options are acceptable' => 'autoloaded_options_are_acceptable',
			'Autoloaded options could affect performance' => 'autoloaded_options_could_affect_performance',
		), 'details' => array(
		) ),
		'insecure_registration' => array('labels' => array(
			'Open Registration with privileged default role' => 'open_registration_with_privileged_default_role',
		), 'details' => array(
			array( 'code' => 'open_registration_privileged_default', 'source' => 'Registration is open to anyone, and the default role is set to a privileged role.', 'status' => 'critical' ),
		) ),
		'search_engine_visibility' => array('labels' => array(
			'Search engine indexing is enabled.' => 'search_engine_indexing_is_enabled',
			'Search engines are discouraged from indexing this site.' => 'search_engines_are_discouraged_from_indexing_this_site',
		), 'details' => array(
		) ),
		'opcode_cache' => array('labels' => array(
			'Opcode cache is enabled' => 'opcode_cache_is_enabled',
			'Opcode cache is not enabled' => 'opcode_cache_is_not_enabled',
		), 'details' => array(
		) ),
		'dotorg_communication' => array('labels' => array(
			'Can communicate with WordPress.org' => 'can_communicate_with_wordpress_org',
			'Could not reach WordPress.org' => 'could_not_reach_wordpress_org',
		), 'details' => array(
		) ),
		'page_cache' => array('labels' => array(
			'Unable to detect the presence of page cache' => 'unable_to_detect_the_presence_of_page_cache',
			'Page cache is not detected but the server response time is OK' => 'page_cache_is_not_detected_but_the_server_response_time_is_ok',
			'Page cache is detected and the server response time is good' => 'page_cache_is_detected_and_the_server_response_time_is_good',
			'Page cache is not detected and the server response time is slow' => 'page_cache_is_not_detected_and_the_server_response_time_is_slow',
			'Page cache is detected but the server response time is still slow' => 'page_cache_is_detected_but_the_server_response_time_is_still_slow',
		), 'details' => array(
		) ),
		'persistent_object_cache' => array('labels' => array(
			'A persistent object cache is being used' => 'a_persistent_object_cache_is_being_used',
			'A persistent object cache is not required' => 'a_persistent_object_cache_is_not_required',
			'You should use a persistent object cache' => 'you_should_use_a_persistent_object_cache',
		), 'details' => array(
		) ),
		'authorization_header' => array('labels' => array(
			'The Authorization header is working as expected' => 'the_authorization_header_is_working_as_expected',
			'The authorization header is missing' => 'the_authorization_header_is_missing',
			'The authorization header is invalid' => 'the_authorization_header_is_invalid',
		), 'details' => array(
		) ),
	);
}
/** Match known native translation templates without returning their variable data. */
function mcp_expose_health_message_matches( string $template, string $value, bool $within = false ): bool {
	if ( strlen( $template ) > 8192 || strlen( $value ) > 65536 ) { return false; }
	$normalize = static fn( $text ) => html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$parts = preg_split( '/(%(?:[0-9]+\$)?[sdif])/', $normalize( $template ), -1, PREG_SPLIT_DELIM_CAPTURE );
	$pattern = '';
	foreach ( $parts as $part ) { $pattern .= preg_match( '/^%(?:[0-9]+\$)?[sdif]$/D', $part ) ? '.{0,2048}?' : preg_quote( $part, '~' ); }
	return 1 === preg_match( '~' . ( $within ? '' : '^' ) . $pattern . ( $within ? '' : '$' ) . '~suD', $normalize( $value ) );
}

/** Return a static catalog message with all native substitutions omitted. */
function mcp_expose_health_safe_label( string $source ): string {
	return (string) preg_replace( '/%(?:[0-9]+\$)?[sdif]/', '[value omitted]', $source );
}

/** Fixed native page-cache messages. Only rounded, bounded millisecond values can leave this seam. */
function mcp_expose_health_cache_messages(): array {
	return array(
		'Median server response time was %1$s milliseconds. This is less than the recommended %2$s milliseconds threshold.',
		'Median server response time was %1$s milliseconds. It should be less than the recommended %2$s milliseconds threshold.',
		'No client caching response headers were detected.',
		'A page cache plugin was detected.',
		'A page cache plugin was not detected.',
	);
}

function mcp_expose_health_cache_measurement(array $result, array $templates): array {
	$description = html_entity_decode(wp_strip_all_tags((string) ($result['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$metric = array('median_response_time_ms'=>null, 'recommended_threshold_ms'=>null, 'native_client_cache_headers_detected'=>null, 'native_page_cache_plugin_detected'=>null,
		'wp_cache_enabled'=>defined('WP_CACHE') && (bool) WP_CACHE,
		'advanced_cache_dropin_present'=>defined('WP_CONTENT_DIR') && file_exists(WP_CONTENT_DIR . '/advanced-cache.php'),
		'external_object_cache_in_use'=>function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : null,
		'varnish_purge_api_available'=>is_callable(array('VarnishPurger','purge_url')),
		'native_loopback_basic_auth_available'=>isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']),
		'measurement_scope'=>'Native median of three homepage loopback GET requests, rounded by WordPress; may forward initiating Basic authentication. Integration flags do not prove an external server cache is enabled.');
	$messages = mcp_expose_health_cache_messages();
	foreach (array_slice($messages,0,2) as $source) {
		$template = $templates[$source] ?? $source; $ordinals = array(); $pattern = '';
		$parts = preg_split('/(%[12]\\$s)/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($parts as $part) {
			if (preg_match('/^%([12])\\$s$/D', $part, $match)) { $ordinals[] = (int) $match[1]; $pattern .= '([0-9][0-9.,\\s\\x{00a0}\\x{202f}]*)'; }
			else { $pattern .= preg_quote($part,'~'); }
		}
		if (2 !== count($ordinals) || !preg_match('~'.$pattern.'~u', $description, $matches)) { continue; }
		$values = array();
		foreach ($ordinals as $index=>$ordinal) {
			$digits = preg_replace('/[^0-9]/','',$matches[$index+1]);
			if (strlen($digits)>8 || (int)$digits>86400000) { $values=array(); break; }
			$values[$ordinal] = (int)$digits;
		}
		if (isset($values[1],$values[2])) { $metric['median_response_time_ms']=$values[1]; $metric['recommended_threshold_ms']=$values[2]; break; }
	}
	if (str_contains($description, $templates[$messages[2]] ?? $messages[2])) { $metric['native_client_cache_headers_detected']=false; }
	if (str_contains($description, $templates[$messages[3]] ?? $messages[3])) { $metric['native_page_cache_plugin_detected']=true; }
	elseif (str_contains($description, $templates[$messages[4]] ?? $messages[4])) { $metric['native_page_cache_plugin_detected']=false; }
	return $metric;
}

/** Run only fixed native health methods; never execute callbacks supplied by the registry. */
function mcp_expose_get_site_health( $input = array() ): array {
	$catalog = mcp_expose_health_catalog();
	$initial_buffer = ob_get_level(); ob_start();
	try {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		$health = WP_Site_Health::get_instance();
		$registry = WP_Site_Health::get_tests();
		if ( ! is_array( $registry['direct'] ?? null ) || ! is_array( $registry['async'] ?? null ) ) { throw new RuntimeException( 'Invalid native registry.' ); }
		$registered = array_merge( $registry['direct'], $registry['async'] );
	} catch ( Throwable $error ) {
		return array( 'success' => false, 'complete' => false, 'execution_complete' => false, 'diagnostics_complete' => false, 'tests' => array(), 'reason_code' => 'native_health_bootstrap_failed', 'debug_dump_returned' => false );
	} finally {
		while ( ob_get_level() > $initial_buffer ) { ob_end_clean(); }
	}
	$tests = array(); $execution_complete = true; $diagnostics_complete = true;
	$counts = array( 'allowlisted' => count( $catalog ), 'supported' => 0, 'executed' => 0, 'passed' => 0, 'recommended' => 0, 'failed' => 0, 'execution_error' => 0, 'not_run' => 0 );
	$registry_ids = array_map( static fn( $name ) => 'is_in_debug_mode' === $name ? 'debug_enabled' : $name, array_keys( $catalog ) );
	$unlisted_count = count( array_diff( array_keys( $registered ), $registry_ids ) );
	foreach ( $catalog as $name => $messages ) {
		$method = 'get_test_' . $name;
		$registry_id = 'is_in_debug_mode' === $name ? 'debug_enabled' : $name;
		$available = is_callable( array( $health, $method ) );
		$context_supported = 'authorization_header' !== $name;
		$context_reason = 'requires_native_browser_authorization_probe';
		if ( 'update_temp_backup_writable' === $name && array_key_exists( $registry_id, $registered ) ) {
			$probe_buffer = ob_get_level(); ob_start();
			try { $context_supported = function_exists( 'get_filesystem_method' ) && 'direct' === get_filesystem_method(); } catch ( Throwable $error ) { $context_supported = false; } finally { while ( ob_get_level() > $probe_buffer ) { ob_end_clean(); } }
			$context_reason = 'requires_direct_native_filesystem';
		}
		$supported = $available && $context_supported;
		$counts['supported'] += (int) $supported;
		$row = array( 'test' => $name, 'supported' => $supported, 'executed' => false, 'outcome' => 'not_run', 'status' => 'unavailable',
			'label' => 'Native test was not run', 'reason_code' => 'native_method_unavailable', 'native_label_recognized' => false );
		if ( ! array_key_exists( $registry_id, $registered ) ) {
			$row['reason_code'] = 'not_registered_for_this_site';
		} elseif ( ! $context_supported ) {
			// The Authorization test needs its native browser probe. Filesystem checks require direct native access.
			// Do not confuse MCP authentication with dummy probe credentials or create another login.
			$row['reason_code'] = $context_reason;
			$row['label'] = 'authorization_header' === $name ? 'Authorization header test requires its native browser probe' : 'Temporary backup permission test requires direct native filesystem access';
		} elseif ( $supported ) {
			$row['executed'] = true; ++$counts['executed'];
			$templates = array();
			foreach ( $messages['labels'] as $source => $code ) { $templates[ $source ] = $source; }
			foreach ( $messages['details'] as $detail ) { $templates[ $detail['source'] ] = $detail['source']; }
			if ('page_cache' === $name) { foreach (mcp_expose_health_cache_messages() as $source) { $templates[$source]=$source; } }
			// Observe native translations only to recognize fixed messages in any site locale.
			// Neither translated text nor raw result values are returned.
			$observe = static function ( $translation, $source, $domain ) use ( &$templates ) {
				if ( 'default' === $domain && isset( $templates[ $source ] ) && is_string( $translation ) ) { $templates[ $source ] = $translation; }
				return $translation;
			};
			add_filter( 'gettext', $observe, PHP_INT_MAX, 3 );
			$buffer = ob_get_level(); ob_start();
			try {
				$result = $health->{$method}();
				if ( ! is_array( $result ) || ! in_array( $result['status'] ?? null, array( 'good', 'recommended', 'critical' ), true ) || ! is_string( $result['label'] ?? null ) ) { throw new RuntimeException( 'Invalid native result.' ); }
				$row['status'] = $result['status'];
				$row['outcome'] = array( 'good' => 'passed', 'recommended' => 'recommended', 'critical' => 'failed' )[ $result['status'] ];
				$row['label'] = 'Native result has an unrecognized diagnostic';
				$row['reason_code'] = 'unrecognized_native_diagnostic';
				foreach ( $messages['labels'] as $source => $code ) {
					if ( mcp_expose_health_message_matches( $templates[ $source ], $result['label'] ) ) {
						$row['label'] = mcp_expose_health_safe_label( $source ); $row['reason_code'] = $code; $row['native_label_recognized'] = true; break;
					}
				}
				foreach ( $messages['details'] as $detail ) {
					if ( $detail['status'] === $row['status'] && is_string( $result['description'] ?? null ) && mcp_expose_health_message_matches( $templates[ $detail['source'] ], $result['description'], true ) ) {
						$row['label'] = mcp_expose_health_safe_label( $detail['source'] ); $row['reason_code'] = $detail['code']; break;
					}
				}
				if ('page_cache' === $name) { $row['measurement']=mcp_expose_health_cache_measurement($result,$templates); }
				$diagnostics_complete = $diagnostics_complete && $row['native_label_recognized'];
			} catch ( Throwable $error ) {
				$row['outcome'] = 'execution_error'; $row['status'] = 'unavailable'; $row['label'] = 'Native health test could not return a valid result'; $row['reason_code'] = 'native_test_execution_failed';
				$execution_complete = false;
			} finally {
				remove_filter( 'gettext', $observe, PHP_INT_MAX );
				while ( ob_get_level() > $buffer ) { ob_end_clean(); }
			}
		}
		++$counts[ $row['outcome'] ]; $tests[] = $row;
	}
	return array( 'success' => $execution_complete && $diagnostics_complete, 'complete' => $execution_complete && $diagnostics_complete && 0 === $counts['not_run'] && 0 === $unlisted_count,
		'execution_complete' => $execution_complete, 'diagnostics_complete' => $diagnostics_complete, 'tests' => $tests, 'counts' => $counts,
		'additional_registered_tests_not_run' => $unlisted_count, 'scope' => 'fixed_native_allowlist', 'debug_dump_returned' => false, 'native_variable_data_returned' => true, 'raw_native_variable_data_returned'=>false, 'safe_numeric_page_cache_measurement_returned'=>true,
		'note' => 'Only fixed native methods run. Canonical catalog labels and reason codes omit substitutions. Only bounded page-cache millisecond measurements and fixed cache/auth-context flags are separately projected. Native descriptions, errors, debug data, paths, credentials and registry callbacks are never returned. Authorization-header browser probe and additional registered tests are not executed. Native tests can refresh caches, schedule health checks and make loopback requests.' );
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
