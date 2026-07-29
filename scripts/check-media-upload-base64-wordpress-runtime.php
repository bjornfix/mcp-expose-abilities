<?php
/**
 * Dev WordPress runtime check for the owned base64 media upload Ability.
 */

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
	throw new RuntimeException( 'No administrator is available for the dev upload check.' );
}
wp_set_current_user( (int) $administrators[0] );

$svg = mcp_expose_upload_base64_media(
	array(
		'filename'  => 'unsupported.svg',
		'mime_type' => 'image/svg+xml',
		'base64'    => base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"/>' ),
	)
);
if ( false !== ( $svg['success'] ?? null ) ) {
	throw new RuntimeException( 'Unsupported SVG content reached the WordPress write seam.' );
}

$filename = 'mcp-expose-media-runtime-' . wp_generate_uuid4() . '.png';
$result   = mcp_expose_upload_base64_media(
	array(
		'filename'  => $filename,
		'mime_type' => 'image/png',
		'base64'    => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
		'title'     => 'MCP media upload runtime check',
		'alt_text'  => 'One transparent test pixel',
	)
);

$attachment_id = (int) ( $result['id'] ?? 0 );
try {
	if ( true !== ( $result['success'] ?? null ) || $attachment_id <= 0 ) {
		throw new RuntimeException( 'The valid PNG upload did not create an attachment: ' . wp_json_encode( $result ) );
	}
	if ( 'image/png' !== get_post_mime_type( $attachment_id ) ) {
		throw new RuntimeException( 'The attachment MIME type does not match the uploaded PNG.' );
	}
	$file = get_attached_file( $attachment_id );
	if ( ! is_string( $file ) || ! is_file( $file ) ) {
		throw new RuntimeException( 'The attachment file is missing after a successful upload.' );
	}
	if ( 'One transparent test pixel' !== get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
		throw new RuntimeException( 'The uploaded attachment lost its alt text.' );
	}
} finally {
	if ( $attachment_id > 0 ) {
		wp_delete_attachment( $attachment_id, true );
	}
}

fwrite(
	STDOUT,
	wp_json_encode(
		array(
			'success'         => true,
			'attachment_id'   => $attachment_id,
			'cleanup_verified' => null === get_post( $attachment_id ),
		)
	) . PHP_EOL
);
