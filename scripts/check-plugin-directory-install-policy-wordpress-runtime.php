<?php
/**
 * WordPress runtime contract for the official directory install policy.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through wp eval-file.\n" );
	exit( 1 );
}

$administrators = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ids',
	)
);
if ( empty( $administrators[0] ) ) {
	throw new RuntimeException( 'No administrator is available for the plugin install policy check.' );
}
wp_set_current_user( (int) $administrators[0] );

$directory_install = wp_get_ability( 'plugins/install-directory' );
$url_upload        = wp_get_ability( 'plugins/upload' );
$base64_upload     = wp_get_ability( 'plugins/upload-base64' );
if ( ! $directory_install || ! $url_upload || ! $base64_upload ) {
	throw new RuntimeException( 'Required plugin abilities are not registered.' );
}

$missing_slug = 'mcp-directory-install-policy-contract-missing';
$unconfirmed  = $directory_install->execute(
	array(
		'slug'     => $missing_slug,
		'activate' => false,
	)
);
if ( is_wp_error( $unconfirmed ) ) {
	throw new RuntimeException( 'Unconfirmed directory call returned an unexpected WordPress error: ' . $unconfirmed->get_error_message() );
}
if (
	false !== ( $unconfirmed['success'] ?? null ) ||
	'mcp_dangerous_action_confirmation_required' !== ( $unconfirmed['code'] ?? '' )
) {
	throw new RuntimeException( 'WordPress.org installation did not require exact per-call confirmation.' );
}

$confirmed = $directory_install->execute(
	array(
		'slug'                     => $missing_slug,
		'activate'                 => false,
		'confirm_dangerous_action' => 'plugins/install-directory',
	)
);
if ( is_wp_error( $confirmed ) ) {
	throw new RuntimeException( 'Confirmed directory call returned an unexpected WordPress error: ' . $confirmed->get_error_message() );
}
if ( false !== ( $confirmed['success'] ?? null ) ) {
	throw new RuntimeException( 'The deliberately missing WordPress.org slug unexpectedly installed.' );
}
if ( 'mcp_plugin_code_writes_disabled' === ( $confirmed['code'] ?? '' ) ) {
	throw new RuntimeException( 'The arbitrary-code gate still blocks the official WordPress.org directory Interface.' );
}

$blocked_url_upload = $url_upload->execute(
	array(
		'url'                      => 'https://example.com/plugin.zip',
		'activate'                 => false,
		'overwrite'                => false,
		'confirm_dangerous_action' => 'plugins/upload',
	)
);
$blocked_base64_upload = $base64_upload->execute(
	array(
		'content_base64'           => 'eA==',
		'filename'                 => 'mcp-guard-contract-test.zip',
		'activate'                 => false,
		'overwrite'                => false,
		'confirm_dangerous_action' => 'plugins/upload-base64',
	)
);
foreach ( array( $blocked_url_upload, $blocked_base64_upload ) as $blocked_upload ) {
	if ( is_wp_error( $blocked_upload ) ) {
		throw new RuntimeException( 'Blocked upload returned an unexpected WordPress error: ' . $blocked_upload->get_error_message() );
	}
	if (
		false !== ( $blocked_upload['success'] ?? null ) ||
		'mcp_plugin_code_writes_disabled' !== ( $blocked_upload['code'] ?? '' )
	) {
		throw new RuntimeException( 'An arbitrary plugin upload bypassed the server-side code-write gate.' );
	}
}

fwrite(
	STDOUT,
	wp_json_encode(
		array(
			'success'                       => true,
			'directory_confirmation'        => true,
			'directory_reached_wordpress_org' => true,
			'arbitrary_uploads_blocked'      => true,
		)
	) . PHP_EOL
);
