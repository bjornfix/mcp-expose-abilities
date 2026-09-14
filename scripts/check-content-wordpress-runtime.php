<?php
/** Run through WP-CLI with an authorised editor. All draft fixtures are removed. */
if ( ! defined( 'ABSPATH' ) || ! current_user_can( 'edit_pages' ) || ! current_user_can( 'edit_posts' ) ) {
	throw new RuntimeException( 'Run with a WordPress editor on the authorised validation site.' );
}
if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'generateblocks/text' ) ) {
	throw new RuntimeException( 'GenerateBlocks must be active for the native validation fixture.' );
}
$ids = array();
$prefix = 'MCP content contract ' . wp_generate_uuid4();
add_action( 'wp_insert_post', static function ( $id, $post ) use ( &$ids, $prefix ) {
	if ( str_starts_with( $post->post_title, $prefix ) ) { $ids[] = (int) $id; }
}, 10, 2 );
$paragraph = static function ( string $label, string $text ): string {
	return '<!-- wp:paragraph ' . wp_json_encode( array( 'metadata' => array( 'name' => $label ) ) ) . ' --><p>' . $text . '</p><!-- /wp:paragraph -->';
};
$check_success = static function ( $result, string $operation ): array {
	if ( is_wp_error( $result ) || ! is_array( $result ) || true !== ( $result['success'] ?? false ) ) {
		throw new RuntimeException( $operation . ' failed: ' . wp_json_encode( $result ) );
	}
	return $result;
};
try {
	foreach ( array( 'post', 'page' ) as $type ) {
		$content = $paragraph( 'Quoted "label"', 'A useful paragraph.' );
		$r = $check_success( wp_get_ability( 'content/create-' . $type )->execute( array( 'title' => $prefix . ' ' . $type, 'status' => 'draft', 'content' => $content ) ), 'Create ' . $type );
		$id = (int) $r['id'];
		if ( $content !== get_post( $id )->post_content ) { throw new RuntimeException( 'Create lost escaped block attributes: ' . $type ); }
		$changed = $paragraph( 'Updated "label"', 'A corrected paragraph.' );
		$check_success( wp_get_ability( 'content/update-' . $type )->execute( array( 'id' => $id, 'content' => $changed ) ), 'Update ' . $type );
		if ( $changed !== get_post( $id )->post_content ) { throw new RuntimeException( 'Update lost escaped block attributes: ' . $type ); }
		$invalid = '<!-- wp:generateblocks/text {"uniqueId":"testnative","tagName":"h2"} --><p class="gb-text">Wrong saved tag</p><!-- /wp:generateblocks/text -->';
		$r = wp_get_ability( 'content/update-' . $type )->execute( array( 'id' => $id, 'content' => $invalid, 'content_write_mode' => 'full_rebuild' ) );
		if ( ! is_wp_error( $r ) && true === ( $r['success'] ?? false ) ) { throw new RuntimeException( 'Invalid Gutenberg content was accepted: ' . $type ); }
		if ( $changed !== get_post( $id )->post_content ) { throw new RuntimeException( 'Rejected content changed stored bytes: ' . $type ); }
		$check_success( wp_get_ability( 'content/patch-' . $type )->execute( array( 'id' => $id, 'find' => 'corrected', 'replace' => 'verified' ) ), 'Patch ' . $type );
		if ( str_replace( 'corrected', 'verified', $changed ) !== get_post( $id )->post_content ) { throw new RuntimeException( 'Patch lost escaped block attributes: ' . $type ); }
	}
	echo "PASS: post/page create, update and patch preserve escaped block attributes; invalid blocks are rejected without mutation.\n";
} finally {
	foreach ( array_unique( $ids ) as $id ) { wp_delete_post( $id, true ); }
	echo "Draft content fixtures removed.\n";
}
