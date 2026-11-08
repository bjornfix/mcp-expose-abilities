<?php
/** Exercise native update batching without invoking any sender or updater. */
if ( 'dev.devenia.com' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'Authorized dev fixture only.' );
}
require_once $args[0] . '/includes/update-notifications.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

/** Replace only the terminal sender; use WordPress' actual batching decisions. */
class MCP_Notification_Fixture_Updater extends WP_Automatic_Updater {
	public $notifications = array();
	public function inspect_batch( array $results ): array {
		$this->notifications = array();
		$this->after_plugin_theme_update( $results );
		return $this->notifications;
	}
	protected function send_plugin_theme_email( $type, $successful_updates, $failed_updates ) {
		$this->notifications[] = $type;
	}
}

$fixture = 'mcp_notify_fixture_' . str_replace( '-', '', wp_generate_uuid4() );
$checks = array();
$check = static function ( $ok, string $label ) use ( &$checks ): void {
	$checks[] = array( 'passed' => (bool) $ok, 'check' => $label );
	if ( ! $ok ) { throw new RuntimeException( $label ); }
};
$settings = array( 'auto_update_plugins', 'auto_update_themes', 'auto_update_core_dev', 'auto_update_core_minor', 'auto_update_core_major' );
$snapshot = static function () use ( $settings ): array {
	return array_map( static function ( $name ) { return get_site_option( $name ); }, $settings );
};
$before = $snapshot();
$policy = static function () use ( $fixture ) { return get_option( $fixture, '0' ); };
$no_mail = static function () { throw new RuntimeException( 'The fixture must never invoke wp_mail.' ); };
$enabled = static function ( $value ) { return false; };
$success = (object) array( 'result' => true );
$error = (object) array( 'result' => new WP_Error( 'fixture_failure', 'Generated fixture only.' ) );
$offer = (object) array( 'current' => '7.1.4', 'response' => 'upgrade' );
$updater = new MCP_Notification_Fixture_Updater();
$saved_hooks = array();
foreach ( array( 'auto_plugin_update_send_email', 'auto_theme_update_send_email', 'auto_core_update_send_email' ) as $hook ) {
	$saved_hooks[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) ? clone $GLOBALS['wp_filter'][ $hook ] : null;
	remove_all_filters( $hook );
}
add_filter( 'auto_plugin_update_send_email', 'mcp_expose_update_batch_notification', 10, 2 );
add_filter( 'auto_theme_update_send_email', 'mcp_expose_update_batch_notification', 10, 2 );
add_filter( 'auto_core_update_send_email', 'mcp_expose_core_update_notification', 10, 4 );
try {
	$check( add_option( $fixture, '0', '', false ), 'Create only the generated fixture option' );
	add_filter( 'pre_option_mcp_expose_suppress_update_success_notifications', $policy );
	add_filter( 'pre_wp_mail', $no_mail );
	$check( array( 'success' ) === $updater->inspect_batch( array( 'plugin' => array( $success ) ) ), 'Default disabled policy preserves success notification' );
	mcp_expose_update_notification_settings();
	$registered = get_registered_settings();
	$setting = $registered['mcp_expose_suppress_update_success_notifications'] ?? array();
	$check( 'general' === ( $setting['group'] ?? null ) && false === ( $setting['default'] ?? null ), 'Native General Settings owns the optional boolean' );
	$check( true === sanitize_option( 'mcp_expose_suppress_update_success_notifications', '1' ) && false === sanitize_option( 'mcp_expose_suppress_update_success_notifications', 'unknown' ), 'Native setting accepts checkbox value and rejects unknown value' );
	update_option( $fixture, sanitize_option( 'mcp_expose_suppress_update_success_notifications', '1' ) );
	$check( mcp_expose_suppress_update_success_enabled(), 'Native saved fixture value enables the shared policy' );
	foreach ( array( 'plugin', 'theme' ) as $kind ) {
		$check( array() === $updater->inspect_batch( array( $kind => array( $success ) ) ), $kind . ' pure success is suppressed through native batching' );
		$check( array( 'fail' ) === $updater->inspect_batch( array( $kind => array( $error ) ) ), $kind . ' WP_Error retains failure notification' );
		$check( array( 'mixed' ) === $updater->inspect_batch( array( $kind => array( $success, $error ) ) ), $kind . ' mixed batch retains its notification' );
		foreach ( array( false, null, 'unknown', 1 ) as $unknown ) {
			$check( true === apply_filters( 'auto_' . $kind . '_update_send_email', true, array( (object) array( 'result' => $unknown ) ) ), $kind . ' uncertain result retains native decision: ' . var_export( $unknown, true ) );
		}
		foreach ( array( array(), null, array( new stdClass() ) ) as $unknown ) {
			$check( true === apply_filters( 'auto_' . $kind . '_update_send_email', true, $unknown ), $kind . ' empty or malformed batch retains native decision' );
		}
		add_filter( 'auto_' . $kind . '_update_send_email', $enabled, 5 );
		$check( false === apply_filters( 'auto_' . $kind . '_update_send_email', true, array( $success ) ), $kind . ' incoming refusal is preserved' );
		remove_filter( 'auto_' . $kind . '_update_send_email', $enabled, 5 );
	}
	$check( array( 'fail' ) === $updater->inspect_batch( array( 'plugin' => array( $error ), 'theme' => array( $success ) ) ), 'Theme success suppression cannot remove a plugin failure' );
	$check( array( 'fail' ) === $updater->inspect_batch( array( 'plugin' => array( $success ), 'theme' => array( $error ) ) ), 'Plugin success suppression cannot remove a theme failure' );
	$check( false === apply_filters( 'auto_core_update_send_email', true, 'success', $offer, null ), 'Core explicit success uses its documented default null result' );
	$check( false === apply_filters( 'auto_core_update_send_email', true, 'success', $offer, true ), 'Core explicit true success is suppressed' );
	foreach ( array( 'fail', 'critical', 'manual', '', 'unknown' ) as $type ) {
		$check( true === apply_filters( 'auto_core_update_send_email', true, $type, $offer, null ), 'Core non-success decision is preserved: ' . $type );
	}
	foreach ( array( null, new stdClass(), (object) array( 'current' => '' ) ) as $unknown ) {
		$check( true === apply_filters( 'auto_core_update_send_email', true, 'success', $unknown, null ), 'Core empty offer cannot establish success' );
	}
	foreach ( array( false, new WP_Error( 'fixture_failure' ), 'unknown', 1 ) as $unknown ) {
		$check( true === apply_filters( 'auto_core_update_send_email', true, 'success', $offer, $unknown ), 'Core uncertain or failed result retains native decision' );
	}
	$check( false === apply_filters( 'auto_core_update_send_email', false, 'fail', $offer, $error->result ), 'Core incoming refusal is preserved' );
	update_option( $fixture, 'unrecognized' );
	$check( array( 'success' ) === $updater->inspect_batch( array( 'plugin' => array( $success ) ) ), 'Unknown stored policy cannot suppress mail' );
	update_option( $fixture, '1' );
	ob_start(); do_settings_fields( 'general', 'default' ); $html = ob_get_clean();
	$check( str_contains( $html, 'id="mcp_expose_update_success_notifications"' ) && str_contains( $html, 'checked=' ), 'Native settings render the saved labelled checkbox' );
	$check( $before === $snapshot(), 'Saved automatic update choices are unchanged' );
	$check( ! has_filter( 'auto_update_plugin', 'mcp_expose_update_batch_notification' ) && ! has_filter( 'auto_update_theme', 'mcp_expose_update_batch_notification' ), 'Notification policy does not register an update execution filter' );
	$check( ! has_filter( 'wp_mail', 'mcp_expose_update_batch_notification' ) && ! has_filter( 'pre_wp_mail', 'mcp_expose_update_batch_notification' ), 'Other mail and security delivery hooks are unchanged' );
	echo wp_json_encode( array( 'passed' => count( $checks ), 'total' => count( $checks ), 'checks' => $checks, 'mail_invoked' => false, 'fixture_option' => $fixture ) ), PHP_EOL;
} finally {
	remove_filter( 'pre_option_mcp_expose_suppress_update_success_notifications', $policy );
	remove_filter( 'pre_wp_mail', $no_mail );
	delete_option( $fixture );
	foreach ( $saved_hooks as $hook => $saved ) {
		if ( null === $saved ) { unset( $GLOBALS['wp_filter'][ $hook ] ); }
		else { $GLOBALS['wp_filter'][ $hook ] = $saved; }
	}
}
