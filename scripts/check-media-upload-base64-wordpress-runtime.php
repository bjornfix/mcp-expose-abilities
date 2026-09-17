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

$video_ability = wp_get_ability( 'media/upload-base64' );
if ( ! $video_ability ) {
	throw new RuntimeException( 'The registered media upload Ability is unavailable.' );
}
$video = $video_ability->execute(
	array(
		'filename'  => 'mcp-video-runtime-' . wp_generate_uuid4() . '.mp4',
		'mime_type' => 'video/mp4',
		'base64'    => 'AAAAIGZ0eXBpc29tAAACAGlzb21pc28yYXZjMW1wNDEAAAODbW9vdgAAAGxtdmhkAAAAAAAAAAAAAAAAAAAD6AAAAMgAAQAAAQAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAAq10cmFrAAAAXHRraGQAAAADAAAAAAAAAAAAAAABAAAAAAAAAMgAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAKAAAABaAAAAAAAkZWR0cwAAABxlbHN0AAAAAAAAAAEAAADIAAAEAAABAAAAAAIlbWRpYQAAACBtZGhkAAAAAAAAAAAAAAAAAAA8AAAADABVxAAAAAAALWhkbHIAAAAAAAAAAHZpZGUAAAAAAAAAAAAAAABWaWRlb0hhbmRsZXIAAAAB0G1pbmYAAAAUdm1oZAAAAAEAAAAAAAAAAAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAAZBzdGJsAAAAwHN0c2QAAAAAAAAAAQAAALBhdmMxAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAKAAWgBIAAAASAAAAAAAAAABFUxhdmM2MC4zMS4xMDIgbGlieDI2NAAAAAAAAAAAAAAAGP//AAAANmF2Y0MBZAAL/+EAGWdkAAus2UKN+TARAAADAAEAAAMAPA8UKZYBAAZo6+PLIsD9+PgAAAAAEHBhc3AAAAABAAAAAQAAABRidHJ0AAAAAAAAf6gAAH+oAAAAGHN0dHMAAAAAAAAAAQAAAAYAAAIAAAAAFHN0c3MAAAAAAAAAAQAAAAEAAABAY3R0cwAAAAAAAAAGAAAAAQAABAAAAAABAAAKAAAAAAEAAAQAAAAAAQAAAAAAAAABAAACAAAAAAEAAAQAAAAAHHN0c2MAAAAAAAAAAQAAAAEAAAAGAAAAAQAAACxzdHN6AAAAAAAAAAAAAAAGAAAC6QAAAA8AAAAMAAAADAAAAAwAAAAVAAAAFHN0Y28AAAAAAAAAAQAAA7MAAABidWR0YQAAAFptZXRhAAAAAAAAACFoZGxyAAAAAAAAAABtZGlyYXBwbAAAAAAAAAAAAAAAAC1pbHN0AAAAJal0b28AAAAdZGF0YQAAAAEAAAAATGF2ZjYwLjE2LjEwMAAAAAhmcmVlAAADOW1kYXQAAAKuBgX//6rcRem95tlIt5Ys2CDZI+7veDI2NCAtIGNvcmUgMTY0IHIzMTA4IDMxZTE5ZjkgLSBILjI2NC9NUEVHLTQgQVZDIGNvZGVjIC0gQ29weWxlZnQgMjAwMy0yMDIzIC0gaHR0cDovL3d3dy52aWRlb2xhbi5vcmcveDI2NC5odG1sIC0gb3B0aW9uczogY2FiYWM9MSByZWY9MyBkZWJsb2NrPTE6MDowIGFuYWx5c2U9MHgzOjB4MTEzIG1lPWhleCBzdWJtZT03IHBzeT0xIHBzeV9yZD0xLjAwOjAuMDAgbWl4ZWRfcmVmPTEgbWVfcmFuZ2U9MTYgY2hyb21hX21lPTEgdHJlbGxpcz0xIDh4OGRjdD0xIGNxbT0wIGRlYWR6b25lPTIxLDExIGZhc3RfcHNraXA9MSBjaHJvbWFfcXBfb2Zmc2V0PS0yIHRocmVhZHM9MyBsb29rYWhlYWRfdGhyZWFkcz0xIHNsaWNlZF90aHJlYWRzPTAgbnI9MCBkZWNpbWF0ZT0xIGludGVybGFjZWQ9MCBibHVyYXlfY29tcGF0PTAgY29uc3RyYWluZWRfaW50cmE9MCBiZnJhbWVzPTMgYl9weXJhbWlkPTIgYl9hZGFwdD0xIGJfYmlhcz0wIGRpcmVjdD0xIHdlaWdodGI9MSBvcGVuX2dvcD0wIHdlaWdodHA9MiBrZXlpbnQ9MjUwIGtleWludF9taW49MjUgc2NlbmVjdXQ9NDAgaW50cmFfcmVmcmVzaD0wIHJjX2xvb2thaGVhZD00MCByYz1jcmYgbWJ0cmVlPTEgY3JmPTIzLjAgcWNvbXA9MC42MCBxcG1pbj0wIHFwbWF4PTY5IHFwc3RlcD00IGlwX3JhdGlvPTEuNDAgYXE9MToxLjAwAIAAAAAzZYiEADP//t8y+BTWhQ50QjNkdLzCXHY3F3KsUvzWH+PthhyzDAY5VSLqwgDlAAcQL7rhAAAAC0GaJGxCv/44QBowAAAACEGeQniFfwFTAAAACAGeYXRCfwGxAAAACAGeY2pCfwGxAAAAEUGaZUmoQWiZTAhP//3xAD/B',
		'title'     => 'Media upload runtime test video',
	)
);
$video_id = is_array( $video ) ? (int) ( $video['id'] ?? 0 ) : 0;
try {
	if ( ! is_array( $video ) || true !== ( $video['success'] ?? null ) || $video_id <= 0 ) {
		throw new RuntimeException( 'The native MP4 upload failed: ' . wp_json_encode( $video ) );
	}
	$video_metadata = wp_get_attachment_metadata( $video_id );
	if ( 'video/mp4' !== get_post_mime_type( $video_id ) || 160 !== (int) ( $video_metadata['width'] ?? 0 ) || 90 !== (int) ( $video_metadata['height'] ?? 0 ) ) {
		throw new RuntimeException( 'MP4 MIME type and dimensions did not survive native attachment creation.' );
	}
} finally {
	if ( $video_id > 0 ) {
		wp_delete_attachment( $video_id, true );
	}
}

fwrite(
	STDOUT,
	wp_json_encode(
		array(
			'success'         => true,
			'attachment_id'   => $attachment_id,
			'cleanup_verified' => null === get_post( $attachment_id ) && null === get_post( $video_id ),
			'video_validated' => true,
		)
	) . PHP_EOL
);
