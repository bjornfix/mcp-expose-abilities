<?php
/**
 * Optional success-only notification policy at WordPress' native update hooks.
 *
 * @package MCP_Expose_Abilities
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Unknown stored values leave the native notification decision intact. */
function mcp_expose_suppress_update_success_enabled(): bool {
	return in_array( get_option( 'mcp_expose_suppress_update_success_notifications', false ), array( true, 1, '1' ), true );
}

/** Suppress only a nonempty batch whose native results are all exactly true. */
function mcp_expose_update_batch_notification( $enabled, $results ) {
	if ( true !== $enabled || ! mcp_expose_suppress_update_success_enabled() || ! is_array( $results ) || ! $results ) {
		return $enabled;
	}
	foreach ( $results as $update ) {
		if ( ! is_object( $update ) || ! isset( $update->result ) || true !== $update->result ) {
			return $enabled;
		}
	}
	return false;
}

/** Core's explicit success branch calls send_email with its default null result. */
function mcp_expose_core_update_notification( $enabled, $type, $update, $result ) {
	if ( true !== $enabled || ! mcp_expose_suppress_update_success_enabled() || 'success' !== $type
		|| ! is_object( $update ) || ! isset( $update->current ) || ! is_string( $update->current ) || '' === trim( $update->current )
		|| ( null !== $result && true !== $result ) ) {
		return $enabled;
	}
	return false;
}

/** Use the existing General Settings form, capability and nonce handling. */
function mcp_expose_update_notification_settings(): void {
	register_setting( 'general', 'mcp_expose_suppress_update_success_notifications', array(
		'type' => 'boolean', 'default' => false,
		'sanitize_callback' => static function ( $value ): bool { return in_array( $value, array( true, 1, '1' ), true ); },
	) );
	add_settings_field( 'mcp_expose_update_success_notifications', __( 'Update confirmation emails', 'mcp-expose-abilities' ), static function (): void {
		echo '<input type="hidden" name="mcp_expose_suppress_update_success_notifications" value="0">';
		echo '<label><input id="mcp_expose_update_success_notifications" name="mcp_expose_suppress_update_success_notifications" type="checkbox" value="1" ';
		checked( mcp_expose_suppress_update_success_enabled() );
		echo '> ' . esc_html__( 'Skip confirmation emails for successful automatic updates', 'mcp-expose-abilities' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Applies to plugins, themes and WordPress core. Failure, mixed and unknown results keep their existing notification decision. Automatic updates and security alerts are unchanged.', 'mcp-expose-abilities' ) . '</p>';
	}, 'general', 'default', array( 'label_for' => 'mcp_expose_update_success_notifications' ) );
}

add_filter( 'auto_plugin_update_send_email', 'mcp_expose_update_batch_notification', 10, 2 );
add_filter( 'auto_theme_update_send_email', 'mcp_expose_update_batch_notification', 10, 2 );
add_filter( 'auto_core_update_send_email', 'mcp_expose_core_update_notification', 10, 4 );
add_action( 'admin_init', 'mcp_expose_update_notification_settings' );
