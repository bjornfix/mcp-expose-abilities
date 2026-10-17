<?php
/** Verify sibling restoration using the real WordPress database; validation site only. */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'mcp_expose_capture_translation_sibling_state' ) ) {
	throw new RuntimeException( 'WordPress and MCP Expose Abilities are required.' );
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) { throw new RuntimeException( 'Validation administrator required.' ); }
wp_set_current_user( (int) $admins[0] );
$ids = array();
$check = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$read = static function ( int $id, array $fields ): array {
	clean_post_cache( $id );
	$post = get_post( $id );
	$result = array();
	foreach ( $fields as $field ) { $result[ $field ] = $post->{$field}; }
	return $result;
};
$cleanup = static function () use ( &$ids ): void {
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
	$ids = array();
};
try {
	foreach ( array( 'Source', 'Translation' ) as $label ) {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Sibling field validation ' . wp_generate_uuid4() . ' ' . $label, 'post_content' => '<p>Original</p>', 'post_date' => '2021-02-03 04:05:06', 'post_date_gmt' => '2021-02-03 04:05:06' ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$ids[] = (int) $id;
	}
	list( $source, $translation ) = $ids;
	$provider = static function ( array $siblings, int $id ) use ( $source, $translation ): array {
		return $id === $source ? array( $translation ) : ( $id === $translation ? array( $source ) : $siblings );
	};
	add_filter( 'mcp_expose_translation_sibling_post_ids', $provider, 999, 2 );
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish', 'menu_order' => -7 ), array( 'ID' => $translation ) );
	clean_post_cache( $translation );
	$snapshot = mcp_expose_capture_translation_sibling_state( $source );
	$expected = $snapshot['posts'][ $translation ];
	$fields = array_keys( $expected );
	$unchanged = mcp_expose_restore_translation_sibling_state( $snapshot );
	$check( empty( $unchanged['restored_post_ids'] ) && $read( $translation, $fields ) === $expected, 'Unchanged sibling was written or altered.' );
	$wpdb->update( $wpdb->posts, array( 'post_title' => 'Incidental source title', 'post_content' => '<p>Incidental source body</p>' ), array( 'ID' => $translation ) );
	$restored = mcp_expose_restore_translation_sibling_state( $snapshot );
	$actual = $read( $translation, $fields );
	$check( $actual === $expected, 'Full sibling restore changed fields: ' . implode( ', ', array_keys( array_diff_assoc( $expected, $actual ) ) ) );
	$check( in_array( $translation, $restored['restored_post_ids'], true ), 'Changed sibling was not restored.' );
	// A pending explicit target uses a subset in caller order, not snapshot order.
	$sparse_fields = array( 'menu_order', 'post_parent', 'post_author', 'post_title', 'post_content', 'post_status', 'post_date_gmt' );
	$sparse = array();
	foreach ( $sparse_fields as $field ) { $sparse[ $field ] = $expected[ $field ]; }
	$wpdb->update( $wpdb->posts, array( 'post_title' => 'Incidental reordered title' ), array( 'ID' => $translation ) );
	mcp_expose_restore_translation_sibling_state( array( 'sibling_ids' => array( $translation ), 'posts' => array( $translation => $sparse ), 'meta' => array() ) );
	$check( $read( $translation, $fields ) === $expected, 'Sparse reordered restore changed publication, date or native integer fields.' );
	// Exercise a real Content caller and the same deferred restore used by builders.
	$mutate = static function ( int $id ) use ( $source, $translation, $wpdb ): void {
		if ( $id === $source ) { $wpdb->update( $wpdb->posts, array( 'post_content' => '<p>Incidental synchronous copy</p>' ), array( 'ID' => $translation ) ); }
	};
	add_action( 'wp_after_insert_post', $mutate, 999, 1 );
	register_shutdown_function( static function () use ( $translation, $wpdb ): void {
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'Incidental late copy' ), array( 'ID' => $translation ) );
	} );
	$result = wp_get_ability( 'content/update-post' )->execute( array( 'id' => $source, 'title' => 'Explicit source title' ) );
	remove_action( 'wp_after_insert_post', $mutate, 999 );
	$check( ! is_wp_error( $result ) && ! empty( $result['success'] ), 'Registered Content writer failed.' );
	$check( $read( $translation, $fields ) === $expected, 'Registered Content writer corrupted the sibling before returning.' );
	mcp_expose_schedule_translation_sibling_state_restore( array(), $translation, $sparse_fields );
	register_shutdown_function( static function () use ( $translation, $fields, $expected, $read, $check, $cleanup, $provider ): void {
		try {
			$check( $read( $translation, $fields ) === $expected, 'Deferred sibling/explicit-target restore corrupted native fields.' );
			echo "PASS: unchanged, full, sparse/reordered, registered Content and deferred restoration preserve publication status, both dates, native integer fields and text in real WordPress storage.\n";
		} finally {
			remove_filter( 'mcp_expose_translation_sibling_post_ids', $provider, 999 );
			$cleanup();
			echo "Sibling validation fixtures removed.\n";
		}
	} );
} catch ( Throwable $error ) {
	$cleanup();
	throw $error;
}
