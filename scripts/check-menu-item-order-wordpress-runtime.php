<?php
/**
 * Native menu update contract. Run with wp --user=<administrator> eval-file.
 * Creates an unassigned temporary menu and removes every fixture in finally.
 */

if ( ! defined( 'ABSPATH' ) || ! current_user_can( 'edit_theme_options' ) ) {
	throw new RuntimeException( 'Run through WordPress with an authorized menu editor.' );
}

$update = wp_get_ability( 'menus/update-item' );
$upsert = wp_get_ability( 'menus/upsert-item' );
if ( ! $update || ! $upsert ) {
	throw new RuntimeException( 'Native menu abilities are unavailable.' );
}

$check = static function ( $result, string $operation ): array {
	if ( is_wp_error( $result ) || ! is_array( $result ) || true !== ( $result['success'] ?? false ) ) {
		throw new RuntimeException( $operation . ' failed: ' . wp_json_encode( $result ) );
	}
	return $result;
};
$ids = array();
$menu = wp_create_nav_menu( 'MCP order contract ' . wp_generate_uuid4() );
if ( is_wp_error( $menu ) ) {
	throw new RuntimeException( $menu->get_error_message() );
}

try {
	foreach ( range( 1, 6 ) as $index ) {
		$id = wp_update_nav_menu_item( $menu, 0, array(
			'menu-item-title' => 'Fixture ' . $index,
			'menu-item-url' => home_url( '/mcp-order-fixture-' . $index . '/' ),
			'menu-item-type' => 'custom',
			'menu-item-object' => 'custom',
			'menu-item-status' => 'publish',
			'menu-item-position' => $index,
		) );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$ids[] = (int) $id;
	}
	$orders = array( 1, 4, 8, 8, 12, 17 );
	foreach ( $ids as $index => $id ) {
		$result = wp_update_post( array( 'ID' => $id, 'menu_order' => $orders[ $index ] ), true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
	}
	update_post_meta( $ids[3], '_menu_item_menu_item_parent', $ids[0] );
	$snapshot = static function () use ( &$ids ): array {
		$result = array();
		foreach ( $ids as $id ) {
			$item = wp_setup_nav_menu_item( get_post( $id ) );
			$result[ $id ] = array( (int) $item->menu_order, (int) $item->menu_item_parent, (string) $item->url );
		}
		return $result;
	};
	$before = $snapshot();
	$check( $update->execute( array( 'menu_id' => $menu, 'item_id' => $ids[3], 'title' => 'Short label' ) ), 'Title-only update' );
	if ( $before !== $snapshot() || 'Short label' !== get_post( $ids[3] )->post_title ) {
		throw new RuntimeException( 'Title-only update changed stored orders, parents, or URLs, or lost its title.' );
	}
	$check( $upsert->execute( array( 'menu_id' => $menu, 'title' => 'Upsert label', 'object' => 'custom', 'url' => home_url( '/mcp-order-fixture-3/' ) ) ), 'Title-only upsert' );
	if ( $before !== $snapshot() || 'Upsert label' !== get_post( $ids[2] )->post_title ) {
		throw new RuntimeException( 'Title-only upsert changed stored orders, parents, or URLs, or lost its title.' );
	}

	// Explicit movement uses the public flat ordinal position, in both directions.
	foreach ( $ids as $index => $id ) {
		wp_update_post( array( 'ID' => $id, 'menu_order' => $index + 1 ) );
	}
	$sequence = static function () use ( $menu ): array {
		return array_map( 'intval', wp_list_pluck( wp_get_nav_menu_items( $menu ), 'ID' ) );
	};
	$check( $update->execute( array( 'menu_id' => $menu, 'item_id' => $ids[4], 'position' => 2 ) ), 'Move earlier' );
	if ( array( $ids[0], $ids[4], $ids[1], $ids[2], $ids[3], $ids[5] ) !== $sequence() ) {
		throw new RuntimeException( 'Explicit earlier move produced the wrong item sequence.' );
	}
	$check( $update->execute( array( 'menu_id' => $menu, 'item_id' => $ids[4], 'position' => 5 ) ), 'Move later' );
	if ( $ids !== $sequence() ) {
		throw new RuntimeException( 'Explicit later move produced the wrong item sequence.' );
	}
	echo "Menu title-only update/upsert preserve sparse orders; explicit moves pass.\n";
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	wp_delete_nav_menu( $menu );
}
