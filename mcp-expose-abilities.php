<?php
/**
 * Plugin Name: MCP Expose Abilities
 * Plugin URI: https://devenia.com
 * Description: Exposes WordPress abilities via MCP and registers content management abilities for posts, pages, and media.
 * Version: 2.5.4
 * Author: Devenia
 * Author URI: https://devenia.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package MCP_Expose_Abilities
 */

declare( strict_types=1 );

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add MCP exposure metadata to all registered abilities.
 *
 * @param array  $args         The arguments used to instantiate the ability.
 * @param string $ability_name The name of the ability being registered.
 *
 * @return array Modified ability arguments with MCP exposure enabled.
 */
function mcp_expose_all_abilities( array $args, string $ability_name ): array {
	if ( ! isset( $args['meta'] ) ) {
		$args['meta'] = array();
	}
	if ( ! isset( $args['meta']['mcp'] ) ) {
		$args['meta']['mcp'] = array();
	}
	if ( ! isset( $args['meta']['mcp']['public'] ) ) {
		$args['meta']['mcp']['public'] = true;
	}
	if ( ! isset( $args['meta']['mcp']['type'] ) ) {
		$args['meta']['mcp']['type'] = 'tool';
	}
	return $args;
}
add_filter( 'wp_register_ability_args', 'mcp_expose_all_abilities', 10, 2 );

/**
 * Register content management abilities.
 */
function mcp_register_content_abilities(): void {
	// =========================================================================
	// POSTS - List
	// =========================================================================
	wp_register_ability(
		'content/list-posts',
		array(
			'label'               => 'List Posts',
			'description'         => 'Retrieves a list of posts with optional filtering by status, category, author, and search term. Returns post ID, title, slug, status, date, modified date, and excerpt.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'      => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ),
						'default'     => 'publish',
						'description' => 'Filter by post status.',
					),
					'per_page'    => array(
						'type'        => 'integer',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Number of posts to return.',
					),
					'page'        => array(
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
						'description' => 'Page number for pagination.',
					),
					'orderby'     => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'modified', 'title', 'ID' ),
						'default'     => 'date',
						'description' => 'Field to order by.',
					),
					'order'       => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'default'     => 'DESC',
						'description' => 'Sort order.',
					),
					'search'      => array(
						'type'        => 'string',
						'description' => 'Search term to filter posts.',
					),
					'category_id' => array(
						'type'        => 'integer',
						'description' => 'Filter by category ID.',
					),
					'author_id'   => array(
						'type'        => 'integer',
						'description' => 'Filter by author ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'posts'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'title'    => array( 'type' => 'string' ),
								'slug'     => array( 'type' => 'string' ),
								'status'   => array( 'type' => 'string' ),
								'date'     => array( 'type' => 'string' ),
								'modified' => array( 'type' => 'string' ),
								'excerpt'  => array( 'type' => 'string' ),
								'link'     => array( 'type' => 'string' ),
							),
						),
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'post_type'      => 'post',
					'post_status'    => $input['status'] ?? 'publish',
					'posts_per_page' => $input['per_page'] ?? 10,
					'paged'          => $input['page'] ?? 1,
					'orderby'        => $input['orderby'] ?? 'date',
					'order'          => $input['order'] ?? 'DESC',
				);

				if ( 'any' === $args['post_status'] ) {
					$args['post_status'] = array( 'publish', 'draft', 'pending', 'private', 'future' );
				}

				if ( ! empty( $input['search'] ) ) {
					$args['s'] = $input['search'];
				}
				if ( ! empty( $input['category_id'] ) ) {
					$args['cat'] = $input['category_id'];
				}
				if ( ! empty( $input['author_id'] ) ) {
					$args['author'] = $input['author_id'];
				}

				$query = new WP_Query( $args );
				$posts = array();

				foreach ( $query->posts as $post ) {
					$posts[] = array(
						'id'       => $post->ID,
						'title'    => $post->post_title,
						'slug'     => $post->post_name,
						'status'   => $post->post_status,
						'date'     => $post->post_date,
						'modified' => $post->post_modified,
						'excerpt'  => wp_trim_words( $post->post_excerpt ?: $post->post_content, 30 ),
						'link'     => get_permalink( $post->ID ),
					);
				}

				return array(
					'posts'       => $posts,
					'total'       => (int) $query->found_posts,
					'total_pages' => (int) $query->max_num_pages,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Get Single
	// =========================================================================
	wp_register_ability(
		'content/get-post',
		array(
			'label'               => 'Get Post',
			'description'         => 'Retrieves a single post by ID or slug, including full content, categories, tags, featured image, and meta.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => 'Post ID to retrieve.',
					),
					'slug' => array(
						'type'        => 'string',
						'description' => 'Post slug to retrieve (used if ID not provided).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'             => array( 'type' => 'integer' ),
					'title'          => array( 'type' => 'string' ),
					'slug'           => array( 'type' => 'string' ),
					'status'         => array( 'type' => 'string' ),
					'content'        => array( 'type' => 'string' ),
					'excerpt'        => array( 'type' => 'string' ),
					'date'           => array( 'type' => 'string' ),
					'modified'       => array( 'type' => 'string' ),
					'author_id'      => array( 'type' => 'integer' ),
					'author_name'    => array( 'type' => 'string' ),
					'categories'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'tags'           => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'featured_image' => array( 'type' => 'string' ),
					'link'           => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$post  = null;

				if ( ! empty( $input['id'] ) ) {
					$post = get_post( $input['id'] );
				} elseif ( ! empty( $input['slug'] ) ) {
					$posts = get_posts( array(
						'name'        => $input['slug'],
						'post_type'   => 'post',
						'post_status' => 'any',
						'numberposts' => 1,
					) );
					$post = $posts[0] ?? null;
				}

				if ( ! $post ) {
					return array( 'error' => 'Post not found' );
				}

				$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );
				$tags       = wp_get_post_tags( $post->ID );
				$author     = get_user_by( 'id', $post->post_author );
				$thumbnail  = get_the_post_thumbnail_url( $post->ID, 'full' );

				return array(
					'id'             => $post->ID,
					'title'          => $post->post_title,
					'slug'           => $post->post_name,
					'status'         => $post->post_status,
					'content'        => $post->post_content,
					'excerpt'        => $post->post_excerpt,
					'date'           => $post->post_date,
					'modified'       => $post->post_modified,
					'author_id'      => (int) $post->post_author,
					'author_name'    => $author ? $author->display_name : '',
					'categories'     => array_map( function ( $cat ) {
						return array( 'id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug );
					}, $categories ),
					'tags'           => array_map( function ( $tag ) {
						return array( 'id' => $tag->term_id, 'name' => $tag->name, 'slug' => $tag->slug );
					}, $tags ),
					'featured_image' => $thumbnail ?: '',
					'link'           => get_permalink( $post->ID ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Create
	// =========================================================================
	wp_register_ability(
		'content/create-post',
		array(
			'label'               => 'Create Post',
			'description'         => 'Creates a new post with specified title, content, status, categories, and tags.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title' ),
				'properties'           => array(
					'title'        => array(
						'type'        => 'string',
						'description' => 'Post title.',
					),
					'content'      => array(
						'type'        => 'string',
						'description' => 'Post content (supports Gutenberg blocks).',
					),
					'excerpt'      => array(
						'type'        => 'string',
						'description' => 'Post excerpt.',
					),
					'status'       => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'default'     => 'draft',
						'description' => 'Post status.',
					),
					'slug'         => array(
						'type'        => 'string',
						'description' => 'Post slug (auto-generated from title if not provided).',
					),
					'category_ids' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Array of category IDs.',
					),
					'tag_ids'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Array of tag IDs.',
					),
					'date'         => array(
						'type'        => 'string',
						'description' => 'Post date (Y-m-d H:i:s format). For scheduled posts.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'link'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['title'] ) ) {
					return array( 'success' => false, 'message' => 'Title is required' );
				}

				$post_data = array(
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_content' => $input['content'] ?? '',
					'post_excerpt' => $input['excerpt'] ?? '',
					'post_status'  => $input['status'] ?? 'draft',
					'post_type'    => 'post',
				);

				if ( ! empty( $input['slug'] ) ) {
					$post_data['post_name'] = sanitize_title( $input['slug'] );
				}
				if ( ! empty( $input['date'] ) ) {
					$post_data['post_date'] = $input['date'];
				}

				$post_id = wp_insert_post( $post_data, true );

				if ( is_wp_error( $post_id ) ) {
					return array( 'success' => false, 'message' => $post_id->get_error_message() );
				}

				if ( ! empty( $input['category_ids'] ) ) {
					wp_set_post_categories( $post_id, array_map( 'intval', $input['category_ids'] ) );
				}
				if ( ! empty( $input['tag_ids'] ) ) {
					wp_set_post_tags( $post_id, array_map( 'intval', $input['tag_ids'] ) );
				}

				return array(
					'success' => true,
					'id'      => $post_id,
					'link'    => get_permalink( $post_id ),
					'message' => 'Post created successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'publish_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Update
	// =========================================================================
	wp_register_ability(
		'content/update-post',
		array(
			'label'               => 'Update Post',
			'description'         => 'Updates an existing post. Only provided fields will be updated.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => 'Post ID to update.',
					),
					'title'        => array(
						'type'        => 'string',
						'description' => 'New post title.',
					),
					'content'      => array(
						'type'        => 'string',
						'description' => 'New post content.',
					),
					'excerpt'      => array(
						'type'        => 'string',
						'description' => 'New post excerpt.',
					),
					'status'       => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'description' => 'New post status.',
					),
					'slug'         => array(
						'type'        => 'string',
						'description' => 'New post slug.',
					),
					'category_ids' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'New category IDs (replaces existing).',
					),
					'tag_ids'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'New tag IDs (replaces existing).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'link'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				$post_data = array( 'ID' => $input['id'] );

				if ( isset( $input['title'] ) ) {
					$post_data['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['content'] ) ) {
					$post_data['post_content'] = $input['content'];
				}
				if ( isset( $input['excerpt'] ) ) {
					$post_data['post_excerpt'] = $input['excerpt'];
				}
				if ( isset( $input['status'] ) ) {
					$post_data['post_status'] = $input['status'];
				}
				if ( isset( $input['slug'] ) ) {
					$post_data['post_name'] = sanitize_title( $input['slug'] );
				}

				$result = wp_update_post( $post_data, true );

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}

				if ( isset( $input['category_ids'] ) ) {
					wp_set_post_categories( $input['id'], array_map( 'intval', $input['category_ids'] ) );
				}
				if ( isset( $input['tag_ids'] ) ) {
					wp_set_post_tags( $input['id'], array_map( 'intval', $input['tag_ids'] ) );
				}

				return array(
					'success' => true,
					'id'      => $input['id'],
					'link'    => get_permalink( $input['id'] ),
					'message' => 'Post updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Delete
	// =========================================================================
	wp_register_ability(
		'content/delete-post',
		array(
			'label'               => 'Delete Post',
			'description'         => 'Deletes a post by ID. Can move to trash or permanently delete.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Post ID to delete.',
					),
					'force'      => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, permanently deletes. If false, moves to trash.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				$force  = ! empty( $input['force'] );
				$result = wp_delete_post( $input['id'], $force );

				if ( ! $result ) {
					return array( 'success' => false, 'message' => 'Failed to delete post' );
				}

				return array(
					'success' => true,
					'message' => $force ? 'Post permanently deleted' : 'Post moved to trash',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - List
	// =========================================================================
	wp_register_ability(
		'content/list-pages',
		array(
			'label'               => 'List Pages',
			'description'         => 'Retrieves a list of pages with optional filtering.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'   => array(
						'type'    => 'string',
						'enum'    => array( 'publish', 'draft', 'pending', 'private', 'any' ),
						'default' => 'publish',
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'parent'   => array(
						'type'        => 'integer',
						'description' => 'Filter by parent page ID. Use 0 for top-level pages.',
					),
					'orderby'  => array(
						'type'    => 'string',
						'enum'    => array( 'title', 'date', 'modified', 'menu_order', 'ID' ),
						'default' => 'menu_order',
					),
					'order'    => array(
						'type'    => 'string',
						'enum'    => array( 'ASC', 'DESC' ),
						'default' => 'ASC',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'pages'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'post_type'      => 'page',
					'post_status'    => $input['status'] ?? 'publish',
					'posts_per_page' => $input['per_page'] ?? 20,
					'paged'          => $input['page'] ?? 1,
					'orderby'        => $input['orderby'] ?? 'menu_order',
					'order'          => $input['order'] ?? 'ASC',
				);

				if ( 'any' === $args['post_status'] ) {
					$args['post_status'] = array( 'publish', 'draft', 'pending', 'private' );
				}

				if ( isset( $input['parent'] ) ) {
					$args['post_parent'] = $input['parent'];
				}

				$query = new WP_Query( $args );
				$pages = array();

				foreach ( $query->posts as $page ) {
					$pages[] = array(
						'id'         => $page->ID,
						'title'      => $page->post_title,
						'slug'       => $page->post_name,
						'status'     => $page->post_status,
						'parent_id'  => $page->post_parent,
						'menu_order' => $page->menu_order,
						'date'       => $page->post_date,
						'modified'   => $page->post_modified,
						'link'       => get_permalink( $page->ID ),
					);
				}

				return array(
					'pages'       => $pages,
					'total'       => (int) $query->found_posts,
					'total_pages' => (int) $query->max_num_pages,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - Get
	// =========================================================================
	wp_register_ability(
		'content/get-page',
		array(
			'label'               => 'Get Page',
			'description'         => 'Retrieves a single page by ID or slug, including full content and meta.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => 'Page ID to retrieve.',
					),
					'slug' => array(
						'type'        => 'string',
						'description' => 'Page slug to retrieve (used if ID not provided).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'id'             => array( 'type' => 'integer' ),
					'title'          => array( 'type' => 'string' ),
					'slug'           => array( 'type' => 'string' ),
					'status'         => array( 'type' => 'string' ),
					'content'        => array( 'type' => 'string' ),
					'excerpt'        => array( 'type' => 'string' ),
					'parent_id'      => array( 'type' => 'integer' ),
					'menu_order'     => array( 'type' => 'integer' ),
					'template'       => array( 'type' => 'string' ),
					'date'           => array( 'type' => 'string' ),
					'modified'       => array( 'type' => 'string' ),
					'author_id'      => array( 'type' => 'integer' ),
					'author_name'    => array( 'type' => 'string' ),
					'featured_image' => array( 'type' => 'string' ),
					'link'           => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$page  = null;

				if ( ! empty( $input['id'] ) ) {
					$page = get_post( $input['id'] );
					if ( $page && 'page' !== $page->post_type ) {
						$page = null;
					}
				} elseif ( ! empty( $input['slug'] ) ) {
					$page = get_page_by_path( $input['slug'] );
				}

				if ( ! $page ) {
					return array( 'success' => false, 'message' => 'Page not found' );
				}

				$author    = get_user_by( 'id', $page->post_author );
				$thumbnail = get_the_post_thumbnail_url( $page->ID, 'full' );
				$template  = get_page_template_slug( $page->ID );

				return array(
					'success'        => true,
					'id'             => $page->ID,
					'title'          => $page->post_title,
					'slug'           => $page->post_name,
					'status'         => $page->post_status,
					'content'        => $page->post_content,
					'excerpt'        => $page->post_excerpt,
					'parent_id'      => (int) $page->post_parent,
					'menu_order'     => (int) $page->menu_order,
					'template'       => $template ?: 'default',
					'date'           => $page->post_date,
					'modified'       => $page->post_modified,
					'author_id'      => (int) $page->post_author,
					'author_name'    => $author ? $author->display_name : '',
					'featured_image' => $thumbnail ?: '',
					'link'           => get_permalink( $page->ID ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - Create
	// =========================================================================
	wp_register_ability(
		'content/create-page',
		array(
			'label'               => 'Create Page',
			'description'         => 'Creates a new page with specified title, content, status, and parent.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title' ),
				'properties'           => array(
					'title'      => array(
						'type'        => 'string',
						'description' => 'Page title.',
					),
					'content'    => array(
						'type'        => 'string',
						'description' => 'Page content (supports Gutenberg blocks).',
					),
					'excerpt'    => array(
						'type'        => 'string',
						'description' => 'Page excerpt.',
					),
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
						'default'     => 'draft',
						'description' => 'Page status.',
					),
					'slug'       => array(
						'type'        => 'string',
						'description' => 'Page slug (auto-generated from title if not provided).',
					),
					'parent'     => array(
						'type'        => 'integer',
						'description' => 'Parent page ID. Use 0 for top-level page.',
					),
					'menu_order' => array(
						'type'        => 'integer',
						'description' => 'Menu order for page sorting.',
					),
					'template'   => array(
						'type'        => 'string',
						'description' => 'Page template slug.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'link'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['title'] ) ) {
					return array( 'success' => false, 'message' => 'Title is required' );
				}

				$page_data = array(
					'post_type'    => 'page',
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_content' => $input['content'] ?? '',
					'post_excerpt' => $input['excerpt'] ?? '',
					'post_status'  => $input['status'] ?? 'draft',
				);

				if ( ! empty( $input['slug'] ) ) {
					$page_data['post_name'] = sanitize_title( $input['slug'] );
				}

				if ( isset( $input['parent'] ) ) {
					$page_data['post_parent'] = (int) $input['parent'];
				}

				if ( isset( $input['menu_order'] ) ) {
					$page_data['menu_order'] = (int) $input['menu_order'];
				}

				$page_id = wp_insert_post( $page_data, true );

				if ( is_wp_error( $page_id ) ) {
					return array( 'success' => false, 'message' => $page_id->get_error_message() );
				}

				if ( ! empty( $input['template'] ) ) {
					update_post_meta( $page_id, '_wp_page_template', $input['template'] );
				}

				return array(
					'success' => true,
					'id'      => $page_id,
					'link'    => get_permalink( $page_id ),
					'message' => 'Page created successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'publish_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - Update
	// =========================================================================
	wp_register_ability(
		'content/update-page',
		array(
			'label'               => 'Update Page',
			'description'         => 'Updates an existing page. Only provided fields will be updated.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Page ID to update.',
					),
					'title'      => array(
						'type'        => 'string',
						'description' => 'New page title.',
					),
					'content'    => array(
						'type'        => 'string',
						'description' => 'New page content.',
					),
					'excerpt'    => array(
						'type'        => 'string',
						'description' => 'New page excerpt.',
					),
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
						'description' => 'New page status.',
					),
					'slug'       => array(
						'type'        => 'string',
						'description' => 'New page slug.',
					),
					'parent'     => array(
						'type'        => 'integer',
						'description' => 'New parent page ID.',
					),
					'menu_order' => array(
						'type'        => 'integer',
						'description' => 'New menu order.',
					),
					'template'   => array(
						'type'        => 'string',
						'description' => 'New page template slug.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'link'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Page ID is required' );
				}

				$page = get_post( $input['id'] );
				if ( ! $page || 'page' !== $page->post_type ) {
					return array( 'success' => false, 'message' => 'Page not found' );
				}

				$page_data = array( 'ID' => $input['id'] );

				if ( isset( $input['title'] ) ) {
					$page_data['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['content'] ) ) {
					$page_data['post_content'] = $input['content'];
				}
				if ( isset( $input['excerpt'] ) ) {
					$page_data['post_excerpt'] = $input['excerpt'];
				}
				if ( isset( $input['status'] ) ) {
					$page_data['post_status'] = $input['status'];
				}
				if ( isset( $input['slug'] ) ) {
					$page_data['post_name'] = sanitize_title( $input['slug'] );
				}
				if ( isset( $input['parent'] ) ) {
					$page_data['post_parent'] = (int) $input['parent'];
				}
				if ( isset( $input['menu_order'] ) ) {
					$page_data['menu_order'] = (int) $input['menu_order'];
				}

				$result = wp_update_post( $page_data, true );

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}

				if ( isset( $input['template'] ) ) {
					update_post_meta( $input['id'], '_wp_page_template', $input['template'] );
				}

				return array(
					'success' => true,
					'id'      => $input['id'],
					'link'    => get_permalink( $input['id'] ),
					'message' => 'Page updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - Delete
	// =========================================================================
	wp_register_ability(
		'content/delete-page',
		array(
			'label'               => 'Delete Page',
			'description'         => 'Deletes a page by ID. Can move to trash or permanently delete.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'Page ID to delete.',
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, permanently deletes. If false, moves to trash.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Page ID is required' );
				}

				$page = get_post( $input['id'] );
				if ( ! $page || 'page' !== $page->post_type ) {
					return array( 'success' => false, 'message' => 'Page not found' );
				}

				$force  = ! empty( $input['force'] );
				$result = wp_delete_post( $input['id'], $force );

				if ( ! $result ) {
					return array( 'success' => false, 'message' => 'Failed to delete page' );
				}

				$message = $force ? 'Page permanently deleted' : 'Page moved to trash';
				return array( 'success' => true, 'message' => $message );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - Patch
	// =========================================================================
	wp_register_ability(
		'content/patch-page',
		array(
			'label'               => 'Patch Page Content',
			'description'         => 'Performs find-and-replace operations on page content. Supports plain text or regex patterns.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'find', 'replace' ),
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'Page ID to patch.',
					),
					'find'    => array(
						'type'        => 'string',
						'description' => 'String or regex pattern to find.',
					),
					'replace' => array(
						'type'        => 'string',
						'description' => 'Replacement string. Supports backreferences ($1, $2, etc.) when using regex.',
					),
					'regex'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, treat "find" as a regex pattern.',
					),
					'limit'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum replacements (-1 for all). Only applies to non-regex mode.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'id'           => array( 'type' => 'integer' ),
					'replacements' => array( 'type' => 'integer' ),
					'message'      => array( 'type' => 'string' ),
					'link'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Page ID is required' );
				}
				if ( ! isset( $input['find'] ) || '' === $input['find'] ) {
					return array( 'success' => false, 'message' => 'Find string is required' );
				}
				if ( ! isset( $input['replace'] ) ) {
					return array( 'success' => false, 'message' => 'Replace string is required' );
				}

				$page = get_post( $input['id'] );
				if ( ! $page || 'page' !== $page->post_type ) {
					return array( 'success' => false, 'message' => 'Page not found' );
				}

				$content   = $page->post_content;
				$find      = $input['find'];
				$replace   = $input['replace'];
				$use_regex = ! empty( $input['regex'] );
				$limit     = isset( $input['limit'] ) ? (int) $input['limit'] : -1;
				$count     = 0;

				if ( $use_regex ) {
					$new_content = preg_replace( $find, $replace, $content, -1, $count );
					if ( null === $new_content ) {
						return array( 'success' => false, 'message' => 'Invalid regex pattern' );
					}
				} else {
					if ( -1 === $limit ) {
						$new_content = str_replace( $find, $replace, $content, $count );
					} else {
						$new_content = preg_replace( '/' . preg_quote( $find, '/' ) . '/', $replace, $content, $limit, $count );
					}
				}

				if ( 0 === $count ) {
					return array(
						'success'      => true,
						'id'           => $input['id'],
						'replacements' => 0,
						'message'      => 'No matches found - content unchanged',
						'link'         => get_permalink( $input['id'] ),
					);
				}

				$result = wp_update_post( array(
					'ID'           => $input['id'],
					'post_content' => $new_content,
				), true );

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}

				return array(
					'success'      => true,
					'id'           => $input['id'],
					'replacements' => $count,
					'message'      => "Successfully replaced {$count} occurrence(s)",
					'link'         => get_permalink( $input['id'] ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CATEGORIES - List
	// =========================================================================
	wp_register_ability(
		'content/list-categories',
		array(
			'label'               => 'List Categories',
			'description'         => 'Retrieves all post categories with their IDs, names, slugs, and post counts.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'hide_empty' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Hide categories with no posts.',
					),
					'parent'     => array(
						'type'        => 'integer',
						'description' => 'Filter by parent category ID. Use 0 for top-level.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'categories' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'hide_empty' => $input['hide_empty'] ?? false,
				);

				if ( isset( $input['parent'] ) ) {
					$args['parent'] = $input['parent'];
				}

				$categories = get_categories( $args );

				return array(
					'categories' => array_map( function ( $cat ) {
						return array(
							'id'          => $cat->term_id,
							'name'        => $cat->name,
							'slug'        => $cat->slug,
							'description' => $cat->description,
							'parent_id'   => $cat->parent,
							'count'       => $cat->count,
						);
					}, $categories ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// TAGS - List
	// =========================================================================
	wp_register_ability(
		'content/list-tags',
		array(
			'label'               => 'List Tags',
			'description'         => 'Retrieves all post tags with their IDs, names, slugs, and post counts.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'hide_empty' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'search'     => array(
						'type'        => 'string',
						'description' => 'Search tags by name.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'tags' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'hide_empty' => $input['hide_empty'] ?? false,
				);

				if ( ! empty( $input['search'] ) ) {
					$args['search'] = $input['search'];
				}

				$tags = get_tags( $args );

				return array(
					'tags' => array_map( function ( $tag ) {
						return array(
							'id'    => $tag->term_id,
							'name'  => $tag->name,
							'slug'  => $tag->slug,
							'count' => $tag->count,
						);
					}, $tags ?: array() ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// TAGS - Create
	// =========================================================================
	wp_register_ability(
		'content/create-tag',
		array(
			'label'               => 'Create Tag',
			'description'         => 'Creates a new post tag. Returns the tag ID, name, and slug.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => 'The tag name.',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'The tag slug (optional, auto-generated from name if not provided).',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'The tag description (optional).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'name'    => array( 'type' => 'string' ),
					'slug'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input ): array {
				$args = array();

				if ( ! empty( $input['slug'] ) ) {
					$args['slug'] = $input['slug'];
				}

				if ( ! empty( $input['description'] ) ) {
					$args['description'] = $input['description'];
				}

				$result = wp_insert_term( $input['name'], 'post_tag', $args );

				if ( is_wp_error( $result ) ) {
					// Check if tag already exists
					if ( $result->get_error_code() === 'term_exists' ) {
						$existing_term = get_term( $result->get_error_data(), 'post_tag' );
						return array(
							'success' => true,
							'id'      => $existing_term->term_id,
							'name'    => $existing_term->name,
							'slug'    => $existing_term->slug,
							'message' => 'Tag already exists.',
						);
					}
					return array(
						'success' => false,
						'message' => $result->get_error_message(),
					);
				}

				$term = get_term( $result['term_id'], 'post_tag' );

				return array(
					'success' => true,
					'id'      => $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'message' => 'Tag created successfully.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_categories' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - List
	// =========================================================================
	wp_register_ability(
		'content/list-media',
		array(
			'label'               => 'List Media',
			'description'         => 'Retrieves media library items with optional filtering by type.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'mime_type' => array(
						'type'        => 'string',
						'description' => 'Filter by MIME type (e.g., "image", "image/jpeg", "application/pdf").',
					),
					'search'    => array(
						'type'        => 'string',
						'description' => 'Search media by filename or title.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'media'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => $input['per_page'] ?? 20,
					'paged'          => $input['page'] ?? 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				);

				if ( ! empty( $input['mime_type'] ) ) {
					$args['post_mime_type'] = $input['mime_type'];
				}
				if ( ! empty( $input['search'] ) ) {
					$args['s'] = $input['search'];
				}

				$query = new WP_Query( $args );
				$media = array();

				foreach ( $query->posts as $item ) {
					$media[] = array(
						'id'        => $item->ID,
						'title'     => $item->post_title,
						'filename'  => basename( get_attached_file( $item->ID ) ),
						'mime_type' => $item->post_mime_type,
						'url'       => wp_get_attachment_url( $item->ID ),
						'date'      => $item->post_date,
						'alt_text'  => get_post_meta( $item->ID, '_wp_attachment_image_alt', true ),
					);
				}

				return array(
					'media'       => $media,
					'total'       => (int) $query->found_posts,
					'total_pages' => (int) $query->max_num_pages,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// USERS - List
	// =========================================================================
	wp_register_ability(
		'content/list-users',
		array(
			'label'               => 'List Users',
			'description'         => 'Retrieves a list of site users with their roles.',
			'category'            => 'user',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'role'     => array(
						'type'        => 'string',
						'description' => 'Filter by role (e.g., "administrator", "editor", "author").',
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'search'   => array(
						'type'        => 'string',
						'description' => 'Search users by name or email.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'users' => array( 'type' => 'array' ),
					'total' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'number' => $input['per_page'] ?? 20,
					'paged'  => $input['page'] ?? 1,
				);

				if ( ! empty( $input['role'] ) ) {
					$args['role'] = $input['role'];
				}
				if ( ! empty( $input['search'] ) ) {
					$args['search'] = '*' . $input['search'] . '*';
				}

				$user_query = new WP_User_Query( $args );
				$users      = array();

				foreach ( $user_query->get_results() as $user ) {
					$users[] = array(
						'id'           => $user->ID,
						'username'     => $user->user_login,
						'email'        => $user->user_email,
						'display_name' => $user->display_name,
						'roles'        => $user->roles,
						'registered'   => $user->user_registered,
					);
				}

				return array(
					'users' => $users,
					'total' => (int) $user_query->get_total(),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'list_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Patch (Find & Replace)
	// =========================================================================
	wp_register_ability(
		'content/patch-post',
		array(
			'label'               => 'Patch Post Content',
			'description'         => 'Performs find-and-replace operations on post content. Supports plain text or regex patterns. More efficient than full content updates for small changes.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'find', 'replace' ),
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'Post ID to patch.',
					),
					'find'    => array(
						'type'        => 'string',
						'description' => 'String or regex pattern to find.',
					),
					'replace' => array(
						'type'        => 'string',
						'description' => 'Replacement string. For regex, supports backreferences ($1, $2, etc.).',
					),
					'regex'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, treat "find" as a regex pattern.',
					),
					'limit'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Max replacements (-1 for all). Only applies to non-regex mode.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'id'           => array( 'type' => 'integer' ),
					'replacements' => array( 'type' => 'integer' ),
					'message'      => array( 'type' => 'string' ),
					'link'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post ID is required' );
				}
				if ( ! isset( $input['find'] ) || '' === $input['find'] ) {
					return array( 'success' => false, 'message' => 'Find string is required' );
				}
				if ( ! isset( $input['replace'] ) ) {
					return array( 'success' => false, 'message' => 'Replace string is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				$content     = $post->post_content;
				$find        = $input['find'];
				$replace     = $input['replace'];
				$use_regex   = ! empty( $input['regex'] );
				$limit       = $input['limit'] ?? -1;
				$count       = 0;

				if ( $use_regex ) {
					// Regex mode
					$new_content = preg_replace( $find, $replace, $content, -1, $count );
					if ( null === $new_content ) {
						return array( 'success' => false, 'message' => 'Invalid regex pattern' );
					}
				} else {
					// Plain text mode with optional limit
					if ( $limit === -1 ) {
						$new_content = str_replace( $find, $replace, $content, $count );
					} else {
						// Manual limited replacement
						$new_content = $content;
						$count       = 0;
						$pos         = 0;
						while ( $count < $limit && ( $pos = strpos( $new_content, $find, $pos ) ) !== false ) {
							$new_content = substr_replace( $new_content, $replace, $pos, strlen( $find ) );
							$pos        += strlen( $replace );
							$count++;
						}
					}
				}

				if ( $count === 0 ) {
					return array(
						'success'      => true,
						'id'           => $post->ID,
						'replacements' => 0,
						'message'      => 'No matches found - content unchanged',
						'link'         => get_permalink( $post->ID ),
					);
				}

				$result = wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $new_content,
					),
					true
				);

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}

				return array(
					'success'      => true,
					'id'           => $post->ID,
					'replacements' => $count,
					'message'      => "Successfully replaced {$count} occurrence(s)",
					'link'         => get_permalink( $post->ID ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// SEARCH - Global Search
	// =========================================================================
	wp_register_ability(
		'content/search',
		array(
			'label'               => 'Search Content',
			'description'         => 'Searches across posts, pages, and media for the given term.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'query' ),
				'properties'           => array(
					'query'      => array(
						'type'        => 'string',
						'description' => 'Search query.',
					),
					'post_types' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'default'     => array( 'post', 'page' ),
						'description' => 'Post types to search (e.g., ["post", "page", "attachment"]).',
					),
					'per_page'   => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'results' => array( 'type' => 'array' ),
					'total'   => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['query'] ) ) {
					return array( 'results' => array(), 'total' => 0 );
				}

				$query = new WP_Query( array(
					's'              => $input['query'],
					'post_type'      => $input['post_types'] ?? array( 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => $input['per_page'] ?? 10,
				) );

				$results = array();
				foreach ( $query->posts as $post ) {
					$results[] = array(
						'id'        => $post->ID,
						'title'     => $post->post_title,
						'type'      => $post->post_type,
						'excerpt'   => wp_trim_words( $post->post_excerpt ?: $post->post_content, 20 ),
						'link'      => get_permalink( $post->ID ),
						'date'      => $post->post_date,
					);
				}

				return array(
					'results' => $results,
					'total'   => (int) $query->found_posts,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'read' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
	// =========================================================================
	// PLUGINS - Upload & Install
	// =========================================================================
	wp_register_ability(
		'plugins/upload',
		array(
			'label'               => 'Upload Plugin',
			'description'         => 'Uploads and installs a plugin from a URL (zip file). Can optionally activate after install and overwrite existing plugin.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'url' ),
				'properties'           => array(
					'url'       => array(
						'type'        => 'string',
						'description' => 'URL to the plugin zip file.',
					),
					'activate'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Activate the plugin after installation.',
					),
					'overwrite' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Overwrite existing plugin if it exists.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'message'   => array( 'type' => 'string' ),
					'plugin'    => array( 'type' => 'string' ),
					'activated' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['url'] ) ) {
					return array( 'success' => false, 'message' => 'Plugin URL is required' );
				}

				// Include required WordPress files for plugin installation.
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				require_once ABSPATH . 'wp-admin/includes/plugin.php';

				// Download the zip file.
				$download_file = download_url( $input['url'] );
				if ( is_wp_error( $download_file ) ) {
					return array( 'success' => false, 'message' => 'Download failed: ' . $download_file->get_error_message() );
				}

				// Prepare for unzipping.
				WP_Filesystem();
				global $wp_filesystem;

				$plugins_dir = WP_PLUGIN_DIR;
				$temp_dir    = $plugins_dir . '/mcp-temp-' . uniqid();

				// Unzip to temp directory first to inspect contents.
				$unzip_result = unzip_file( $download_file, $temp_dir );
				@unlink( $download_file );

				if ( is_wp_error( $unzip_result ) ) {
					$wp_filesystem->delete( $temp_dir, true );
					return array( 'success' => false, 'message' => 'Unzip failed: ' . $unzip_result->get_error_message() );
				}

				// Find the plugin folder (first directory in the zip).
				$files = $wp_filesystem->dirlist( $temp_dir );
				if ( empty( $files ) ) {
					$wp_filesystem->delete( $temp_dir, true );
					return array( 'success' => false, 'message' => 'Invalid plugin zip - no files found' );
				}

				$plugin_folder = '';
				foreach ( $files as $file => $info ) {
					if ( 'd' === $info['type'] ) {
						$plugin_folder = $file;
						break;
					}
				}

				if ( empty( $plugin_folder ) ) {
					// Debug: list what was found
					$found_items = array();
					foreach ( $files as $file => $info ) {
						$found_items[] = $file . ' (type: ' . $info['type'] . ')';
					}
					$wp_filesystem->delete( $temp_dir, true );
					return array( 'success' => false, 'message' => 'Invalid plugin zip - no plugin folder found. Found: ' . implode( ', ', $found_items ) );
				}

				$target_dir  = $plugins_dir . '/' . $plugin_folder;
				$source_dir  = $temp_dir . '/' . $plugin_folder;
				$plugin_file = '';

				// Check if plugin already exists.
				if ( is_dir( $target_dir ) ) {
					if ( empty( $input['overwrite'] ) && false === $input['overwrite'] ) {
						$wp_filesystem->delete( $temp_dir, true );
						return array( 'success' => false, 'message' => 'Plugin already exists and overwrite is disabled' );
					}
					// Deactivate if active before overwriting.
					$all_plugins = get_plugins();
					foreach ( $all_plugins as $file => $data ) {
						if ( strpos( $file, $plugin_folder . '/' ) === 0 ) {
							$plugin_file = $file;
							if ( is_plugin_active( $file ) ) {
								deactivate_plugins( $file );
							}
							break;
						}
					}
					// Remove old plugin.
					$wp_filesystem->delete( $target_dir, true );
				}

				// Move plugin to plugins directory.
				$move_result = $wp_filesystem->move( $source_dir, $target_dir );
				$wp_filesystem->delete( $temp_dir, true );

				if ( ! $move_result ) {
					return array( 'success' => false, 'message' => 'Failed to move plugin to plugins directory' );
				}

				// Find the main plugin file if not already known.
				if ( empty( $plugin_file ) ) {
					$all_plugins = get_plugins();
					foreach ( $all_plugins as $file => $data ) {
						if ( strpos( $file, $plugin_folder . '/' ) === 0 ) {
							$plugin_file = $file;
							break;
						}
					}
				}

				if ( empty( $plugin_file ) ) {
					return array( 'success' => false, 'message' => 'Plugin installed but main file not found' );
				}

				// Activate if requested.
				$activated = false;
				if ( ! empty( $input['activate'] ) || ! isset( $input['activate'] ) ) {
					$activate_result = activate_plugin( $plugin_file );
					if ( is_wp_error( $activate_result ) ) {
						return array(
							'success'   => true,
							'message'   => 'Plugin installed but activation failed: ' . $activate_result->get_error_message(),
							'plugin'    => $plugin_file,
							'activated' => false,
						);
					}
					$activated = true;
				}

				return array(
					'success'   => true,
					'message'   => 'Plugin installed successfully' . ( $activated ? ' and activated' : '' ),
					'plugin'    => $plugin_file,
					'activated' => $activated,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'install_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PLUGINS - List
	// =========================================================================
	wp_register_ability(
		'plugins/list',
		array(
			'label'               => 'List Plugins',
			'description'         => 'Lists all installed plugins with their status, version, and other details.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'active', 'inactive' ),
						'default'     => 'all',
						'description' => 'Filter by plugin status.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugins' => array( 'type' => 'array' ),
					'total'   => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				require_once ABSPATH . 'wp-admin/includes/plugin.php';

				$all_plugins    = get_plugins();
				$active_plugins = get_option( 'active_plugins', array() );
				$status_filter  = $input['status'] ?? 'all';

				$plugins = array();
				foreach ( $all_plugins as $file => $data ) {
					$is_active = in_array( $file, $active_plugins, true );

					if ( 'active' === $status_filter && ! $is_active ) {
						continue;
					}
					if ( 'inactive' === $status_filter && $is_active ) {
						continue;
					}

					$plugins[] = array(
						'file'        => $file,
						'name'        => $data['Name'],
						'version'     => $data['Version'],
						'author'      => $data['Author'],
						'description' => $data['Description'],
						'active'      => $is_active,
					);
				}

				return array(
					'plugins' => $plugins,
					'total'   => count( $plugins ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'activate_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
	// =========================================================================
	// ELEMENTOR - Get Data
	// =========================================================================
	wp_register_ability(
		'elementor/get-data',
		array(
			'label'               => 'Get Elementor Data',
			'description'         => 'Retrieves the Elementor JSON data for a page or post. Returns the raw Elementor structure including containers, widgets, and settings.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to get Elementor data from.',
					),
					'format' => array(
						'type'        => 'string',
						'enum'        => array( 'array', 'json' ),
						'default'     => 'array',
						'description' => 'Return format: "array" for parsed PHP array, "json" for raw JSON string.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'id'            => array( 'type' => 'integer' ),
					'title'         => array( 'type' => 'string' ),
					'edit_mode'     => array( 'type' => 'string' ),
					'data'          => array( 'type' => 'array' ),
					'page_settings' => array( 'type' => 'object' ),
					'message'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );
				$edit_mode      = get_post_meta( $input['id'], '_elementor_edit_mode', true );
				$page_settings  = get_post_meta( $input['id'], '_elementor_page_settings', true );

				if ( empty( $elementor_data ) ) {
					return array(
						'success' => false,
						'id'      => $input['id'],
						'title'   => $post->post_title,
						'message' => 'No Elementor data found for this post',
					);
				}

				$format = $input['format'] ?? 'array';
				$data   = ( 'json' === $format ) ? $elementor_data : json_decode( $elementor_data, true );

				return array(
					'success'       => true,
					'id'            => $input['id'],
					'title'         => $post->post_title,
					'edit_mode'     => $edit_mode ?: 'not set',
					'data'          => $data,
					'page_settings' => $page_settings ?: array(),
					'message'       => 'Elementor data retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Data
	// =========================================================================
	wp_register_ability(
		'elementor/update-data',
		array(
			'label'               => 'Update Elementor Data',
			'description'         => 'Updates the Elementor JSON data for a page or post. Automatically clears Elementor CSS cache. Use with caution - invalid JSON will break the page.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'data' ),
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to update.',
					),
					'data' => array(
						'type'        => 'array',
						'description' => 'Elementor data array (will be JSON encoded).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
					'link'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( ! isset( $input['data'] ) || ! is_array( $input['data'] ) ) {
					return array( 'success' => false, 'message' => 'Elementor data array is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				// Encode data to JSON.
				$json_data = wp_json_encode( $input['data'] );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode data to JSON' );
				}

				// Update Elementor data.
				update_post_meta( $input['id'], '_elementor_data', wp_slash( $json_data ) );

				// Ensure edit mode is set to builder.
				update_post_meta( $input['id'], '_elementor_edit_mode', 'builder' );

				// Clear Elementor CSS cache for this post.
				delete_post_meta( $input['id'], '_elementor_css' );

				// Update post modified time to trigger regeneration.
				wp_update_post( array(
					'ID'            => $input['id'],
					'post_modified' => current_time( 'mysql' ),
				) );

				return array(
					'success' => true,
					'id'      => $input['id'],
					'message' => 'Elementor data updated successfully',
					'link'    => get_permalink( $input['id'] ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Patch Data (Find & Replace in JSON)
	// =========================================================================
	wp_register_ability(
		'elementor/patch-data',
		array(
			'label'               => 'Patch Elementor Data',
			'description'         => 'Performs find-and-replace operations within Elementor JSON data. Works on the raw JSON string, so you can replace text, URLs, settings values, etc.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'find', 'replace' ),
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to patch.',
					),
					'find'    => array(
						'type'        => 'string',
						'description' => 'String to find in the Elementor JSON.',
					),
					'replace' => array(
						'type'        => 'string',
						'description' => 'Replacement string.',
					),
					'regex'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, treat "find" as a regex pattern.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'id'           => array( 'type' => 'integer' ),
					'replacements' => array( 'type' => 'integer' ),
					'message'      => array( 'type' => 'string' ),
					'link'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( ! isset( $input['find'] ) || '' === $input['find'] ) {
					return array( 'success' => false, 'message' => 'Find string is required' );
				}
				if ( ! isset( $input['replace'] ) ) {
					return array( 'success' => false, 'message' => 'Replace string is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$find      = $input['find'];
				$replace   = $input['replace'];
				$use_regex = ! empty( $input['regex'] );
				$count     = 0;

				if ( $use_regex ) {
					$new_data = preg_replace( $find, $replace, $elementor_data, -1, $count );
					if ( null === $new_data ) {
						return array( 'success' => false, 'message' => 'Invalid regex pattern' );
					}
				} else {
					$new_data = str_replace( $find, $replace, $elementor_data, $count );
				}

				if ( 0 === $count ) {
					return array(
						'success'      => true,
						'id'           => $input['id'],
						'replacements' => 0,
						'message'      => 'No matches found - Elementor data unchanged',
						'link'         => get_permalink( $input['id'] ),
					);
				}

				// Validate that result is still valid JSON.
				$test_decode = json_decode( $new_data, true );
				if ( null === $test_decode && json_last_error() !== JSON_ERROR_NONE ) {
					return array( 'success' => false, 'message' => 'Replacement would result in invalid JSON - aborted' );
				}

				// Update Elementor data.
				update_post_meta( $input['id'], '_elementor_data', wp_slash( $new_data ) );

				// Clear Elementor CSS cache.
				delete_post_meta( $input['id'], '_elementor_css' );

				// Update post modified time.
				wp_update_post( array(
					'ID'            => $input['id'],
					'post_modified' => current_time( 'mysql' ),
				) );

				return array(
					'success'      => true,
					'id'           => $input['id'],
					'replacements' => $count,
					'message'      => "Successfully replaced {$count} occurrence(s) in Elementor data",
					'link'         => get_permalink( $input['id'] ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Update Element (targeted container/widget replacement)
	// =========================================================================
	wp_register_ability(
		'elementor/update-element',
		array(
			'label'               => 'Update Elementor Element',
			'description'         => 'Replaces a specific element (container or widget) by ID within the Elementor page structure. Useful for targeted updates without re-uploading the entire page.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'element_id', 'element_data' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID containing the element.',
					),
					'element_id'   => array(
						'type'        => 'string',
						'description' => 'The ID of the element to replace (e.g., "col1", "hero_section").',
					),
					'element_data' => array(
						'type'        => 'object',
						'description' => 'The new element data to replace it with. Must include "id", "elType", and other required Elementor fields.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'message'    => array( 'type' => 'string' ),
					'link'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Post/Page ID is required' );
				}
				if ( empty( $input['element_id'] ) ) {
					return array( 'success' => false, 'message' => 'Element ID is required' );
				}
				if ( ! isset( $input['element_data'] ) || ! is_array( $input['element_data'] ) ) {
					return array( 'success' => false, 'message' => 'Element data object is required' );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found' );
				}

				$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );
				if ( empty( $elementor_data ) ) {
					return array( 'success' => false, 'message' => 'No Elementor data found for this post' );
				}

				$data = json_decode( $elementor_data, true );
				if ( null === $data ) {
					return array( 'success' => false, 'message' => 'Failed to parse existing Elementor data' );
				}

				// Recursive function to find and replace element by ID.
				$found = false;
				$replace_element = function ( &$elements, $target_id, $new_element ) use ( &$replace_element, &$found ) {
					foreach ( $elements as $index => &$element ) {
						if ( isset( $element['id'] ) && $element['id'] === $target_id ) {
							$elements[ $index ] = $new_element;
							$found = true;
							return true;
						}
						if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
							if ( $replace_element( $element['elements'], $target_id, $new_element ) ) {
								return true;
							}
						}
					}
					return false;
				};

				$replace_element( $data, $input['element_id'], $input['element_data'] );

				if ( ! $found ) {
					return array(
						'success'    => false,
						'id'         => $input['id'],
						'element_id' => $input['element_id'],
						'message'    => 'Element with ID "' . $input['element_id'] . '" not found in page structure',
					);
				}

				// Encode and save.
				$json_data = wp_json_encode( $data );
				if ( false === $json_data ) {
					return array( 'success' => false, 'message' => 'Failed to encode updated data to JSON' );
				}

				update_post_meta( $input['id'], '_elementor_data', wp_slash( $json_data ) );

				// Clear Elementor CSS cache.
				delete_post_meta( $input['id'], '_elementor_css' );

				// Update post modified time.
				wp_update_post( array(
					'ID'            => $input['id'],
					'post_modified' => current_time( 'mysql' ),
				) );

				return array(
					'success'    => true,
					'id'         => $input['id'],
					'element_id' => $input['element_id'],
					'message'    => 'Element "' . $input['element_id'] . '" updated successfully',
					'link'       => get_permalink( $input['id'] ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - List Templates
	// =========================================================================
	wp_register_ability(
		'elementor/list-templates',
		array(
			'label'               => 'List Elementor Templates',
			'description'         => 'Lists all saved Elementor templates (sections, pages, containers, etc.).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'page', 'section', 'container', 'loop-item', 'header', 'footer', 'single', 'archive', 'popup' ),
						'default'     => 'all',
						'description' => 'Filter by template type.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'templates' => array( 'type' => 'array' ),
					'total'     => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'post_type'      => 'elementor_library',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
				);

				$type_filter = $input['type'] ?? 'all';
				if ( 'all' !== $type_filter ) {
					$args['meta_query'] = array(
						array(
							'key'   => '_elementor_template_type',
							'value' => $type_filter,
						),
					);
				}

				$query     = new WP_Query( $args );
				$templates = array();

				foreach ( $query->posts as $template ) {
					$template_type = get_post_meta( $template->ID, '_elementor_template_type', true );
					$templates[]   = array(
						'id'       => $template->ID,
						'title'    => $template->post_title,
						'type'     => $template_type ?: 'unknown',
						'date'     => $template->post_date,
						'modified' => $template->post_modified,
					);
				}

				return array(
					'templates' => $templates,
					'total'     => count( $templates ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// ELEMENTOR - Clear Cache
	// =========================================================================
	wp_register_ability(
		'elementor/clear-cache',
		array(
			'label'               => 'Clear Elementor Cache',
			'description'         => 'Clears Elementor CSS cache for a specific post or the entire site.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'  => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to clear cache for. If omitted, clears all Elementor cache.',
					),
					'all' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, clears all Elementor cache site-wide.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( ! empty( $input['all'] ) ) {
					// Clear all Elementor CSS files.
					if ( class_exists( '\Elementor\Plugin' ) ) {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
						return array( 'success' => true, 'message' => 'All Elementor cache cleared' );
					} else {
						// Manual fallback - delete all _elementor_css meta.
						global $wpdb;
						$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_elementor_css' ) );
						return array( 'success' => true, 'message' => 'Elementor CSS meta cleared (Elementor not loaded)' );
					}
				}

				if ( ! empty( $input['id'] ) ) {
					$post = get_post( $input['id'] );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => 'Post not found' );
					}

					delete_post_meta( $input['id'], '_elementor_css' );
					return array( 'success' => true, 'message' => "Cache cleared for post {$input['id']}" );
				}

				return array( 'success' => false, 'message' => 'Provide either "id" or set "all" to true' );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// GENERATEPRESS - Get Settings
	// =========================================================================
	wp_register_ability(
		'generatepress/get-settings',
		array(
			'label'               => 'Get GeneratePress Settings',
			'description'         => 'Retrieves GeneratePress theme settings including colors, typography, layout, and global styles.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'section' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'colors', 'typography', 'layout', 'buttons', 'site_identity' ),
						'default'     => 'all',
						'description' => 'Which settings section to retrieve.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'settings' => array( 'type' => 'object' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$settings       = get_option( 'generate_settings', array() );
				$global_colors  = get_option( 'generate_global_colors', array() );

				if ( empty( $settings ) && empty( $global_colors ) ) {
					return array(
						'success' => false,
						'message' => 'GeneratePress settings not found - is the theme active?',
					);
				}

				$section = $input['section'] ?? 'all';

				// Color-related settings keys.
				$color_keys = array(
					'global_colors', 'background_color', 'text_color', 'link_color', 'link_color_hover',
					'header_background_color', 'header_text_color', 'header_link_color',
					'navigation_background_color', 'navigation_text_color', 'navigation_background_hover',
					'sidebar_widget_title_color', 'sidebar_widget_text_color',
					'footer_background_color', 'footer_text_color', 'footer_link_color',
					'entry_meta_link_color', 'entry_meta_link_color_hover',
				);

				// Typography keys.
				$typo_keys = array(
					'font_body', 'body_font_weight', 'body_font_size', 'body_line_height',
					'font_heading_1', 'heading_1_weight', 'heading_1_font_size',
					'font_heading_2', 'heading_2_weight', 'heading_2_font_size',
					'font_heading_3', 'heading_3_weight', 'heading_3_font_size',
					'font_buttons', 'buttons_font_weight', 'buttons_font_size',
				);

				// Layout keys.
				$layout_keys = array(
					'container_width', 'content_layout_setting', 'content_width',
					'sidebar_width', 'sidebar_layout', 'header_layout_setting',
					'footer_widget_setting', 'back_to_top',
				);

				// Button keys.
				$button_keys = array(
					'form_button_background_color', 'form_button_background_color_hover',
					'form_button_text_color', 'form_button_text_color_hover',
					'form_button_border_radius',
				);

				$result = array();

				if ( 'all' === $section || 'colors' === $section ) {
					$result['global_colors'] = $global_colors;
					foreach ( $color_keys as $key ) {
						if ( isset( $settings[ $key ] ) ) {
							$result['colors'][ $key ] = $settings[ $key ];
						}
					}
				}

				if ( 'all' === $section || 'typography' === $section ) {
					foreach ( $typo_keys as $key ) {
						if ( isset( $settings[ $key ] ) ) {
							$result['typography'][ $key ] = $settings[ $key ];
						}
					}
				}

				if ( 'all' === $section || 'layout' === $section ) {
					foreach ( $layout_keys as $key ) {
						if ( isset( $settings[ $key ] ) ) {
							$result['layout'][ $key ] = $settings[ $key ];
						}
					}
				}

				if ( 'all' === $section || 'buttons' === $section ) {
					foreach ( $button_keys as $key ) {
						if ( isset( $settings[ $key ] ) ) {
							$result['buttons'][ $key ] = $settings[ $key ];
						}
					}
				}

				if ( 'all' === $section ) {
					$result['all_settings'] = $settings;
				}

				return array(
					'success'  => true,
					'settings' => $result,
					'message'  => 'GeneratePress settings retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// GENERATEPRESS - Update Settings
	// =========================================================================
	wp_register_ability(
		'generatepress/update-settings',
		array(
			'label'               => 'Update GeneratePress Settings',
			'description'         => 'Updates GeneratePress theme settings. Merges with existing settings - only provided keys are updated.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'settings' ),
				'properties'           => array(
					'settings' => array(
						'type'        => 'object',
						'description' => 'Settings to update (merged with existing).',
					),
					'global_colors' => array(
						'type'        => 'array',
						'description' => 'Global colors array to update.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['settings'] ) && empty( $input['global_colors'] ) ) {
					return array( 'success' => false, 'message' => 'No settings provided to update' );
				}

				if ( ! empty( $input['settings'] ) ) {
					$current = get_option( 'generate_settings', array() );
					$updated = array_merge( $current, $input['settings'] );
					update_option( 'generate_settings', $updated );
				}

				if ( ! empty( $input['global_colors'] ) ) {
					update_option( 'generate_global_colors', $input['global_colors'] );
				}

				return array(
					'success' => true,
					'message' => 'GeneratePress settings updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// GENERATEBLOCKS - Get Global Styles
	// =========================================================================
	wp_register_ability(
		'generateblocks/get-global-styles',
		array(
			'label'               => 'Get GenerateBlocks Global Styles',
			'description'         => 'Retrieves GenerateBlocks global styles and default settings.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'include_defaults' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Include default settings in response.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'global_styles' => array( 'type' => 'array' ),
					'defaults'      => array( 'type' => 'object' ),
					'message'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$global_styles = get_option( 'generateblocks_global_styles', array() );
				$defaults      = get_option( 'generateblocks_defaults', array() );
				$settings      = get_option( 'generateblocks', array() );

				return array(
					'success'       => true,
					'global_styles' => $global_styles,
					'defaults'      => $defaults,
					'settings'      => $settings,
					'message'       => 'GenerateBlocks settings retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// GENERATEBLOCKS - Update Global Styles
	// =========================================================================
	wp_register_ability(
		'generateblocks/update-global-styles',
		array(
			'label'               => 'Update GenerateBlocks Global Styles',
			'description'         => 'Updates GenerateBlocks global styles. Replaces entire global styles array.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'global_styles' => array(
						'type'        => 'array',
						'description' => 'Complete global styles array to save.',
					),
					'defaults' => array(
						'type'        => 'object',
						'description' => 'Default settings object to save.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['global_styles'] ) && empty( $input['defaults'] ) ) {
					return array( 'success' => false, 'message' => 'No styles or defaults provided to update' );
				}

				if ( isset( $input['global_styles'] ) ) {
					update_option( 'generateblocks_global_styles', $input['global_styles'] );
				}

				if ( isset( $input['defaults'] ) ) {
					update_option( 'generateblocks_defaults', $input['defaults'] );
				}

				return array(
					'success' => true,
					'message' => 'GenerateBlocks settings updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// GENERATEBLOCKS - Clear CSS Cache
	// =========================================================================
	wp_register_ability(
		'generateblocks/clear-cache',
		array(
			'label'               => 'Clear GenerateBlocks Cache',
			'description'         => 'Clears GenerateBlocks CSS cache by deleting generated CSS files.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'confirm' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Confirm cache clear operation.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'deleted' => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$upload_dir = wp_upload_dir();
				$css_dir    = $upload_dir['basedir'] . '/generateblocks/';
				$deleted    = 0;

				if ( is_dir( $css_dir ) ) {
					$files = glob( $css_dir . '*.css' );
					if ( $files ) {
						foreach ( $files as $file ) {
							if ( wp_delete_file( $file ) ) {
								$deleted++;
							}
						}
					}
				}

				// Also delete the CSS version option to force regeneration.
				delete_option( 'generateblocks_css_version' );

				return array(
					'success' => true,
					'deleted' => $deleted,
					'message' => "Cleared {$deleted} GenerateBlocks CSS file(s)",
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - List
	// =========================================================================
	wp_register_ability(
		'menus/list',
		array(
			'label'               => 'List Menus',
			'description'         => 'Lists all registered navigation menus and their locations.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'menus'     => array( 'type' => 'array' ),
					'locations' => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$menus     = wp_get_nav_menus();
				$locations = get_nav_menu_locations();
				$registered_locations = get_registered_nav_menus();

				$menu_list = array();
				foreach ( $menus as $menu ) {
					$menu_list[] = array(
						'id'          => $menu->term_id,
						'name'        => $menu->name,
						'slug'        => $menu->slug,
						'description' => $menu->description,
						'count'       => $menu->count,
					);
				}

				$location_list = array();
				foreach ( $registered_locations as $location => $description ) {
					$location_list[ $location ] = array(
						'description' => $description,
						'menu_id'     => $locations[ $location ] ?? 0,
					);
				}

				return array(
					'success'   => true,
					'menus'     => $menu_list,
					'locations' => $location_list,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Get Menu Items
	// =========================================================================
	wp_register_ability(
		'menus/get-items',
		array(
			'label'               => 'Get Menu Items',
			'description'         => 'Retrieves all items from a specific menu by ID or location.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => 'Menu ID.',
					),
					'location' => array(
						'type'        => 'string',
						'description' => 'Menu location slug (used if ID not provided).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'menu'    => array( 'type' => 'object' ),
					'items'   => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input   = is_array( $input ) ? $input : array();
				$menu_id = 0;

				if ( ! empty( $input['id'] ) ) {
					$menu_id = (int) $input['id'];
				} elseif ( ! empty( $input['location'] ) ) {
					$locations = get_nav_menu_locations();
					$menu_id   = $locations[ $input['location'] ] ?? 0;
				}

				if ( ! $menu_id ) {
					return array( 'success' => false, 'message' => 'Menu ID or location required' );
				}

				$menu = wp_get_nav_menu_object( $menu_id );
				if ( ! $menu ) {
					return array( 'success' => false, 'message' => 'Menu not found' );
				}

				$items      = wp_get_nav_menu_items( $menu_id );
				$item_list  = array();

				if ( $items ) {
					foreach ( $items as $item ) {
						$item_list[] = array(
							'id'          => $item->ID,
							'title'       => $item->title,
							'url'         => $item->url,
							'target'      => $item->target,
							'attr_title'  => $item->attr_title,
							'description' => $item->description,
							'classes'     => $item->classes,
							'xfn'         => $item->xfn,
							'parent'      => (int) $item->menu_item_parent,
							'order'       => (int) $item->menu_order,
							'object'      => $item->object,
							'object_id'   => (int) $item->object_id,
							'type'        => $item->type,
						);
					}
				}

				return array(
					'success' => true,
					'menu'    => array(
						'id'    => $menu->term_id,
						'name'  => $menu->name,
						'slug'  => $menu->slug,
						'count' => $menu->count,
					),
					'items'   => $item_list,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Create Menu
	// =========================================================================
	wp_register_ability(
		'menus/create',
		array(
			'label'               => 'Create Menu',
			'description'         => 'Creates a new navigation menu.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'Menu name.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['name'] ) ) {
					return array( 'success' => false, 'message' => 'Menu name is required' );
				}

				$menu_id = wp_create_nav_menu( sanitize_text_field( $input['name'] ) );

				if ( is_wp_error( $menu_id ) ) {
					return array( 'success' => false, 'message' => $menu_id->get_error_message() );
				}

				return array(
					'success' => true,
					'id'      => $menu_id,
					'message' => 'Menu created successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Add Menu Item
	// =========================================================================
	wp_register_ability(
		'menus/add-item',
		array(
			'label'               => 'Add Menu Item',
			'description'         => 'Adds a new item to a navigation menu.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'menu_id', 'title' ),
				'properties'           => array(
					'menu_id'   => array(
						'type'        => 'integer',
						'description' => 'Menu ID to add item to.',
					),
					'title'     => array(
						'type'        => 'string',
						'description' => 'Menu item title.',
					),
					'url'       => array(
						'type'        => 'string',
						'description' => 'URL for custom links.',
					),
					'object'    => array(
						'type'        => 'string',
						'description' => 'Object type (page, post, category, custom).',
						'default'     => 'custom',
					),
					'object_id' => array(
						'type'        => 'integer',
						'description' => 'Object ID (for pages/posts/categories).',
					),
					'parent'    => array(
						'type'        => 'integer',
						'description' => 'Parent menu item ID (for submenus).',
						'default'     => 0,
					),
					'position'  => array(
						'type'        => 'integer',
						'description' => 'Menu position/order.',
					),
					'target'    => array(
						'type'        => 'string',
						'enum'        => array( '', '_blank' ),
						'description' => 'Link target (_blank for new window).',
					),
					'classes'   => array(
						'type'        => 'string',
						'description' => 'CSS classes (space-separated).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['menu_id'] ) ) {
					return array( 'success' => false, 'message' => 'Menu ID is required' );
				}
				if ( empty( $input['title'] ) ) {
					return array( 'success' => false, 'message' => 'Title is required' );
				}

				$menu = wp_get_nav_menu_object( $input['menu_id'] );
				if ( ! $menu ) {
					return array( 'success' => false, 'message' => 'Menu not found' );
				}

				$object    = $input['object'] ?? 'custom';
				$object_id = $input['object_id'] ?? 0;
				$type      = 'custom';

				if ( 'page' === $object ) {
					$type = 'post_type';
				} elseif ( 'post' === $object ) {
					$type = 'post_type';
				} elseif ( 'category' === $object ) {
					$type      = 'taxonomy';
					$object    = 'category';
				}

				$item_data = array(
					'menu-item-title'     => sanitize_text_field( $input['title'] ),
					'menu-item-url'       => $input['url'] ?? '',
					'menu-item-object'    => $object,
					'menu-item-object-id' => $object_id,
					'menu-item-type'      => $type,
					'menu-item-parent-id' => $input['parent'] ?? 0,
					'menu-item-position'  => $input['position'] ?? 0,
					'menu-item-target'    => $input['target'] ?? '',
					'menu-item-classes'   => $input['classes'] ?? '',
					'menu-item-status'    => 'publish',
				);

				$item_id = wp_update_nav_menu_item( $input['menu_id'], 0, $item_data );

				if ( is_wp_error( $item_id ) ) {
					return array( 'success' => false, 'message' => $item_id->get_error_message() );
				}

				return array(
					'success' => true,
					'id'      => $item_id,
					'message' => 'Menu item added successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Update Menu Item
	// =========================================================================
	wp_register_ability(
		'menus/update-item',
		array(
			'label'               => 'Update Menu Item',
			'description'         => 'Updates an existing menu item.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'menu_id', 'item_id' ),
				'properties'           => array(
					'menu_id'  => array(
						'type'        => 'integer',
						'description' => 'Menu ID.',
					),
					'item_id'  => array(
						'type'        => 'integer',
						'description' => 'Menu item ID to update.',
					),
					'title'    => array(
						'type'        => 'string',
						'description' => 'New title.',
					),
					'url'      => array(
						'type'        => 'string',
						'description' => 'New URL.',
					),
					'parent'   => array(
						'type'        => 'integer',
						'description' => 'New parent menu item ID.',
					),
					'position' => array(
						'type'        => 'integer',
						'description' => 'New position/order.',
					),
					'target'   => array(
						'type'        => 'string',
						'description' => 'Link target.',
					),
					'classes'  => array(
						'type'        => 'string',
						'description' => 'CSS classes.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['menu_id'] ) || empty( $input['item_id'] ) ) {
					return array( 'success' => false, 'message' => 'Menu ID and item ID are required' );
				}

				$item = get_post( $input['item_id'] );
				if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
					return array( 'success' => false, 'message' => 'Menu item not found' );
				}

				$item_data = array(
					'menu-item-status' => 'publish',
				);

				if ( isset( $input['title'] ) ) {
					$item_data['menu-item-title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['url'] ) ) {
					$item_data['menu-item-url'] = esc_url_raw( $input['url'] );
				}
				if ( isset( $input['parent'] ) ) {
					$item_data['menu-item-parent-id'] = (int) $input['parent'];
				}
				if ( isset( $input['position'] ) ) {
					$item_data['menu-item-position'] = (int) $input['position'];
				}
				if ( isset( $input['target'] ) ) {
					$item_data['menu-item-target'] = $input['target'];
				}
				if ( isset( $input['classes'] ) ) {
					$item_data['menu-item-classes'] = $input['classes'];
				}

				$result = wp_update_nav_menu_item( $input['menu_id'], $input['item_id'], $item_data );

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}

				return array(
					'success' => true,
					'message' => 'Menu item updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Delete Menu Item
	// =========================================================================
	wp_register_ability(
		'menus/delete-item',
		array(
			'label'               => 'Delete Menu Item',
			'description'         => 'Deletes a menu item from a navigation menu.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'item_id' ),
				'properties'           => array(
					'item_id' => array(
						'type'        => 'integer',
						'description' => 'Menu item ID to delete.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['item_id'] ) ) {
					return array( 'success' => false, 'message' => 'Item ID is required' );
				}

				$item = get_post( $input['item_id'] );
				if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
					return array( 'success' => false, 'message' => 'Menu item not found' );
				}

				$result = wp_delete_post( $input['item_id'], true );

				if ( ! $result ) {
					return array( 'success' => false, 'message' => 'Failed to delete menu item' );
				}

				return array(
					'success' => true,
					'message' => 'Menu item deleted successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Assign to Location
	// =========================================================================
	wp_register_ability(
		'menus/assign-location',
		array(
			'label'               => 'Assign Menu to Location',
			'description'         => 'Assigns a menu to a theme location.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'menu_id', 'location' ),
				'properties'           => array(
					'menu_id'  => array(
						'type'        => 'integer',
						'description' => 'Menu ID to assign (use 0 to unassign).',
					),
					'location' => array(
						'type'        => 'string',
						'description' => 'Theme location slug.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( ! isset( $input['menu_id'] ) || empty( $input['location'] ) ) {
					return array( 'success' => false, 'message' => 'Menu ID and location are required' );
				}

				$registered = get_registered_nav_menus();
				if ( ! isset( $registered[ $input['location'] ] ) ) {
					return array( 'success' => false, 'message' => 'Invalid menu location' );
				}

				$locations = get_nav_menu_locations();
				$locations[ $input['location'] ] = (int) $input['menu_id'];
				set_theme_mod( 'nav_menu_locations', $locations );

				$action = $input['menu_id'] > 0 ? 'assigned' : 'unassigned';
				return array(
					'success' => true,
					'message' => "Menu {$action} to location successfully",
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// WIDGETS - List Sidebars
	// =========================================================================
	wp_register_ability(
		'widgets/list-sidebars',
		array(
			'label'               => 'List Widget Sidebars',
			'description'         => 'Lists all registered widget sidebars/areas.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'sidebars' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wp_registered_sidebars;

				$sidebars = array();
				foreach ( $wp_registered_sidebars as $id => $sidebar ) {
					$sidebars[] = array(
						'id'          => $id,
						'name'        => $sidebar['name'],
						'description' => $sidebar['description'] ?? '',
					);
				}

				return array(
					'success'  => true,
					'sidebars' => $sidebars,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// WIDGETS - Get Sidebar Widgets
	// =========================================================================
	wp_register_ability(
		'widgets/get-sidebar',
		array(
			'label'               => 'Get Sidebar Widgets',
			'description'         => 'Gets all widgets in a specific sidebar.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'sidebar_id' ),
				'properties'           => array(
					'sidebar_id' => array(
						'type'        => 'string',
						'description' => 'Sidebar ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'sidebar' => array( 'type' => 'object' ),
					'widgets' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wp_registered_sidebars, $wp_registered_widgets;
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['sidebar_id'] ) ) {
					return array( 'success' => false, 'message' => 'Sidebar ID is required' );
				}

				$sidebar_id = $input['sidebar_id'];
				if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
					return array( 'success' => false, 'message' => 'Sidebar not found' );
				}

				$sidebars_widgets = wp_get_sidebars_widgets();
				$widget_ids       = $sidebars_widgets[ $sidebar_id ] ?? array();
				$widgets          = array();

				foreach ( $widget_ids as $widget_id ) {
					if ( isset( $wp_registered_widgets[ $widget_id ] ) ) {
						$widget = $wp_registered_widgets[ $widget_id ];
						$widgets[] = array(
							'id'   => $widget_id,
							'name' => $widget['name'],
						);
					}
				}

				return array(
					'success' => true,
					'sidebar' => array(
						'id'   => $sidebar_id,
						'name' => $wp_registered_sidebars[ $sidebar_id ]['name'],
					),
					'widgets' => $widgets,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// WIDGETS - List Available Widgets
	// =========================================================================
	wp_register_ability(
		'widgets/list-available',
		array(
			'label'               => 'List Available Widgets',
			'description'         => 'Lists all widget types available for use.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'widgets' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wp_widget_factory;

				$widgets = array();
				foreach ( $wp_widget_factory->widgets as $class => $widget ) {
					$widgets[] = array(
						'id_base'     => $widget->id_base,
						'name'        => $widget->name,
						'description' => $widget->widget_options['description'] ?? '',
					);
				}

				return array(
					'success' => true,
					'widgets' => $widgets,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// USERS - List
	// =========================================================================
	wp_register_ability(
		'users/list',
		array(
			'label'               => 'List Users (Extended)',
			'description'         => 'Lists all users with detailed information including roles and capabilities.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'role'     => array(
						'type'        => 'string',
						'description' => 'Filter by role (administrator, editor, author, contributor, subscriber).',
					),
					'per_page' => array(
						'type'        => 'integer',
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
					),
					'page'     => array(
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
					),
					'orderby'  => array(
						'type'    => 'string',
						'enum'    => array( 'ID', 'login', 'nicename', 'email', 'registered', 'display_name' ),
						'default' => 'display_name',
					),
					'order'    => array(
						'type'    => 'string',
						'enum'    => array( 'ASC', 'DESC' ),
						'default' => 'ASC',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'users'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'number'  => $input['per_page'] ?? 20,
					'paged'   => $input['page'] ?? 1,
					'orderby' => $input['orderby'] ?? 'display_name',
					'order'   => $input['order'] ?? 'ASC',
				);

				if ( ! empty( $input['role'] ) ) {
					$args['role'] = $input['role'];
				}

				$query = new WP_User_Query( $args );
				$users = array();

				foreach ( $query->get_results() as $user ) {
					$users[] = array(
						'id'           => $user->ID,
						'login'        => $user->user_login,
						'email'        => $user->user_email,
						'display_name' => $user->display_name,
						'nicename'     => $user->user_nicename,
						'url'          => $user->user_url,
						'registered'   => $user->user_registered,
						'roles'        => $user->roles,
					);
				}

				$total = $query->get_total();
				$per_page = $input['per_page'] ?? 20;

				return array(
					'success'     => true,
					'users'       => $users,
					'total'       => $total,
					'total_pages' => (int) ceil( $total / $per_page ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'list_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// USERS - Get
	// =========================================================================
	wp_register_ability(
		'users/get',
		array(
			'label'               => 'Get User',
			'description'         => 'Gets detailed information about a specific user.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'User ID.',
					),
					'login' => array(
						'type'        => 'string',
						'description' => 'Username (used if ID not provided).',
					),
					'email' => array(
						'type'        => 'string',
						'description' => 'Email address (used if ID and login not provided).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'user'    => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$user  = null;

				if ( ! empty( $input['id'] ) ) {
					$user = get_user_by( 'id', $input['id'] );
				} elseif ( ! empty( $input['login'] ) ) {
					$user = get_user_by( 'login', $input['login'] );
				} elseif ( ! empty( $input['email'] ) ) {
					$user = get_user_by( 'email', $input['email'] );
				}

				if ( ! $user ) {
					return array( 'success' => false, 'message' => 'User not found' );
				}

				return array(
					'success' => true,
					'user'    => array(
						'id'           => $user->ID,
						'login'        => $user->user_login,
						'email'        => $user->user_email,
						'display_name' => $user->display_name,
						'first_name'   => $user->first_name,
						'last_name'    => $user->last_name,
						'nickname'     => $user->nickname,
						'nicename'     => $user->user_nicename,
						'url'          => $user->user_url,
						'description'  => $user->description,
						'registered'   => $user->user_registered,
						'roles'        => $user->roles,
						'caps'         => array_keys( array_filter( $user->allcaps ) ),
					),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'list_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// USERS - Create
	// =========================================================================
	wp_register_ability(
		'users/create',
		array(
			'label'               => 'Create User',
			'description'         => 'Creates a new user account.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'username', 'email' ),
				'properties'           => array(
					'username'     => array(
						'type'        => 'string',
						'description' => 'Username (login name).',
					),
					'email'        => array(
						'type'        => 'string',
						'description' => 'Email address.',
					),
					'password'     => array(
						'type'        => 'string',
						'description' => 'Password (auto-generated if not provided).',
					),
					'first_name'   => array(
						'type'        => 'string',
						'description' => 'First name.',
					),
					'last_name'    => array(
						'type'        => 'string',
						'description' => 'Last name.',
					),
					'display_name' => array(
						'type'        => 'string',
						'description' => 'Display name.',
					),
					'role'         => array(
						'type'        => 'string',
						'description' => 'User role.',
						'default'     => 'subscriber',
					),
					'url'          => array(
						'type'        => 'string',
						'description' => 'User website URL.',
					),
					'description'  => array(
						'type'        => 'string',
						'description' => 'User bio/description.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'id'       => array( 'type' => 'integer' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['username'] ) ) {
					return array( 'success' => false, 'message' => 'Username is required' );
				}
				if ( empty( $input['email'] ) ) {
					return array( 'success' => false, 'message' => 'Email is required' );
				}

				$userdata = array(
					'user_login' => sanitize_user( $input['username'] ),
					'user_email' => sanitize_email( $input['email'] ),
					'user_pass'  => $input['password'] ?? wp_generate_password(),
					'role'       => $input['role'] ?? 'subscriber',
				);

				if ( ! empty( $input['first_name'] ) ) {
					$userdata['first_name'] = sanitize_text_field( $input['first_name'] );
				}
				if ( ! empty( $input['last_name'] ) ) {
					$userdata['last_name'] = sanitize_text_field( $input['last_name'] );
				}
				if ( ! empty( $input['display_name'] ) ) {
					$userdata['display_name'] = sanitize_text_field( $input['display_name'] );
				}
				if ( ! empty( $input['url'] ) ) {
					$userdata['user_url'] = esc_url_raw( $input['url'] );
				}
				if ( ! empty( $input['description'] ) ) {
					$userdata['description'] = sanitize_textarea_field( $input['description'] );
				}

				$user_id = wp_insert_user( $userdata );

				if ( is_wp_error( $user_id ) ) {
					return array( 'success' => false, 'message' => $user_id->get_error_message() );
				}

				return array(
					'success' => true,
					'id'      => $user_id,
					'message' => 'User created successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'create_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// USERS - Update
	// =========================================================================
	wp_register_ability(
		'users/update',
		array(
			'label'               => 'Update User',
			'description'         => 'Updates an existing user. Only provided fields will be updated.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => 'User ID to update.',
					),
					'email'        => array(
						'type'        => 'string',
						'description' => 'New email address.',
					),
					'password'     => array(
						'type'        => 'string',
						'description' => 'New password.',
					),
					'first_name'   => array(
						'type'        => 'string',
						'description' => 'New first name.',
					),
					'last_name'    => array(
						'type'        => 'string',
						'description' => 'New last name.',
					),
					'display_name' => array(
						'type'        => 'string',
						'description' => 'New display name.',
					),
					'nickname'     => array(
						'type'        => 'string',
						'description' => 'New nickname.',
					),
					'role'         => array(
						'type'        => 'string',
						'description' => 'New role.',
					),
					'url'          => array(
						'type'        => 'string',
						'description' => 'New website URL.',
					),
					'description'  => array(
						'type'        => 'string',
						'description' => 'New bio/description.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'User ID is required' );
				}

				$user = get_user_by( 'id', $input['id'] );
				if ( ! $user ) {
					return array( 'success' => false, 'message' => 'User not found' );
				}

				$userdata = array( 'ID' => $input['id'] );

				if ( isset( $input['email'] ) ) {
					$userdata['user_email'] = sanitize_email( $input['email'] );
				}
				if ( isset( $input['password'] ) ) {
					$userdata['user_pass'] = $input['password'];
				}
				if ( isset( $input['first_name'] ) ) {
					$userdata['first_name'] = sanitize_text_field( $input['first_name'] );
				}
				if ( isset( $input['last_name'] ) ) {
					$userdata['last_name'] = sanitize_text_field( $input['last_name'] );
				}
				if ( isset( $input['display_name'] ) ) {
					$userdata['display_name'] = sanitize_text_field( $input['display_name'] );
				}
				if ( isset( $input['nickname'] ) ) {
					$userdata['nickname'] = sanitize_text_field( $input['nickname'] );
				}
				if ( isset( $input['role'] ) ) {
					$userdata['role'] = $input['role'];
				}
				if ( isset( $input['url'] ) ) {
					$userdata['user_url'] = esc_url_raw( $input['url'] );
				}
				if ( isset( $input['description'] ) ) {
					$userdata['description'] = sanitize_textarea_field( $input['description'] );
				}

				$result = wp_update_user( $userdata );

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}

				return array(
					'success' => true,
					'message' => 'User updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// USERS - Delete
	// =========================================================================
	wp_register_ability(
		'users/delete',
		array(
			'label'               => 'Delete User',
			'description'         => 'Deletes a user account. Can reassign content to another user.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => 'User ID to delete.',
					),
					'reassign_to' => array(
						'type'        => 'integer',
						'description' => 'User ID to reassign content to. If not provided, content will be deleted.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'User ID is required' );
				}

				$user = get_user_by( 'id', $input['id'] );
				if ( ! $user ) {
					return array( 'success' => false, 'message' => 'User not found' );
				}

				// Don't allow deleting yourself.
				if ( $input['id'] === get_current_user_id() ) {
					return array( 'success' => false, 'message' => 'Cannot delete your own account' );
				}

				require_once ABSPATH . 'wp-admin/includes/user.php';

				$reassign = ! empty( $input['reassign_to'] ) ? (int) $input['reassign_to'] : null;
				$result   = wp_delete_user( $input['id'], $reassign );

				if ( ! $result ) {
					return array( 'success' => false, 'message' => 'Failed to delete user' );
				}

				return array(
					'success' => true,
					'message' => 'User deleted successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - Upload
	// =========================================================================
	wp_register_ability(
		'media/upload',
		array(
			'label'               => 'Upload Media',
			'description'         => 'Uploads a file to the media library from a URL.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'url' ),
				'properties'           => array(
					'url'         => array(
						'type'        => 'string',
						'description' => 'URL of the file to upload.',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'Title for the media item.',
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => 'Caption for the media item.',
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => 'Alt text for images.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Description for the media item.',
					),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => 'Post ID to attach the media to.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'url'     => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['url'] ) ) {
					return array( 'success' => false, 'message' => 'URL is required' );
				}

				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';

				$post_id = $input['post_id'] ?? 0;

				// Download file to temp location.
				$tmp = download_url( $input['url'] );
				if ( is_wp_error( $tmp ) ) {
					return array( 'success' => false, 'message' => $tmp->get_error_message() );
				}

				// Get filename from URL.
				$filename = basename( wp_parse_url( $input['url'], PHP_URL_PATH ) );
				if ( empty( $filename ) ) {
					$filename = 'uploaded-file';
				}

				$file_array = array(
					'name'     => $filename,
					'tmp_name' => $tmp,
				);

				// Upload to media library.
				$attachment_id = media_handle_sideload( $file_array, $post_id );

				// Clean up temp file.
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}

				if ( is_wp_error( $attachment_id ) ) {
					return array( 'success' => false, 'message' => $attachment_id->get_error_message() );
				}

				// Update attachment metadata.
				if ( ! empty( $input['title'] ) ) {
					wp_update_post( array(
						'ID'         => $attachment_id,
						'post_title' => sanitize_text_field( $input['title'] ),
					) );
				}
				if ( ! empty( $input['caption'] ) ) {
					wp_update_post( array(
						'ID'           => $attachment_id,
						'post_excerpt' => sanitize_text_field( $input['caption'] ),
					) );
				}
				if ( ! empty( $input['description'] ) ) {
					wp_update_post( array(
						'ID'           => $attachment_id,
						'post_content' => sanitize_textarea_field( $input['description'] ),
					) );
				}
				if ( ! empty( $input['alt_text'] ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
				}

				return array(
					'success' => true,
					'id'      => $attachment_id,
					'url'     => wp_get_attachment_url( $attachment_id ),
					'message' => 'Media uploaded successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - Get
	// =========================================================================
	wp_register_ability(
		'media/get',
		array(
			'label'               => 'Get Media Item',
			'description'         => 'Gets detailed information about a media item.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Media attachment ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'media'   => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Media ID is required' );
				}

				$attachment = get_post( $input['id'] );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return array( 'success' => false, 'message' => 'Media not found' );
				}

				$metadata = wp_get_attachment_metadata( $input['id'] );
				$sizes    = array();

				if ( ! empty( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size => $data ) {
						$sizes[ $size ] = array(
							'url'    => wp_get_attachment_image_url( $input['id'], $size ),
							'width'  => $data['width'],
							'height' => $data['height'],
						);
					}
				}

				return array(
					'success' => true,
					'media'   => array(
						'id'          => $attachment->ID,
						'title'       => $attachment->post_title,
						'caption'     => $attachment->post_excerpt,
						'description' => $attachment->post_content,
						'alt_text'    => get_post_meta( $input['id'], '_wp_attachment_image_alt', true ),
						'mime_type'   => $attachment->post_mime_type,
						'url'         => wp_get_attachment_url( $input['id'] ),
						'date'        => $attachment->post_date,
						'modified'    => $attachment->post_modified,
						'author_id'   => (int) $attachment->post_author,
						'parent_id'   => (int) $attachment->post_parent,
						'width'       => $metadata['width'] ?? null,
						'height'      => $metadata['height'] ?? null,
						'file'        => $metadata['file'] ?? null,
						'sizes'       => $sizes,
					),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - Update
	// =========================================================================
	wp_register_ability(
		'media/update',
		array(
			'label'               => 'Update Media Item',
			'description'         => 'Updates media metadata (title, caption, alt text, description).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => 'Media attachment ID.',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'New title.',
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => 'New caption.',
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => 'New alt text.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'New description.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Media ID is required' );
				}

				$attachment = get_post( $input['id'] );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return array( 'success' => false, 'message' => 'Media not found' );
				}

				$post_data = array( 'ID' => $input['id'] );

				if ( isset( $input['title'] ) ) {
					$post_data['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['caption'] ) ) {
					$post_data['post_excerpt'] = sanitize_text_field( $input['caption'] );
				}
				if ( isset( $input['description'] ) ) {
					$post_data['post_content'] = sanitize_textarea_field( $input['description'] );
				}

				$result = wp_update_post( $post_data, true );

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}

				if ( isset( $input['alt_text'] ) ) {
					update_post_meta( $input['id'], '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
				}

				return array(
					'success' => true,
					'message' => 'Media updated successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - Delete
	// =========================================================================
	wp_register_ability(
		'media/delete',
		array(
			'label'               => 'Delete Media Item',
			'description'         => 'Permanently deletes a media item and its files.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'Media attachment ID.',
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Force permanent deletion (default true for media).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Media ID is required' );
				}

				$attachment = get_post( $input['id'] );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return array( 'success' => false, 'message' => 'Media not found' );
				}

				$force  = $input['force'] ?? true;
				$result = wp_delete_attachment( $input['id'], $force );

				if ( ! $result ) {
					return array( 'success' => false, 'message' => 'Failed to delete media' );
				}

				return array(
					'success' => true,
					'message' => 'Media deleted successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// SYSTEM - Get Transient
	// =========================================================================
	wp_register_ability(
		'system/get-transient',
		array(
			'label'               => 'Get Transient',
			'description'         => 'Retrieves a WordPress transient value by name.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'The transient name to retrieve.',
					),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'value'   => array(),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['name'] ) ) {
					return array( 'success' => false, 'message' => 'Transient name is required', 'value' => null );
				}

				$value = get_transient( $input['name'] );

				if ( false === $value ) {
					return array( 'success' => false, 'message' => 'Transient not found or expired', 'value' => null );
				}

				return array(
					'success' => true,
					'value'   => $value,
					'message' => 'Transient retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// SYSTEM - Debug Log
	// =========================================================================
	wp_register_ability(
		'system/debug-log',
		array(
			'label'               => 'Read Debug Log',
			'description'         => 'Reads the WordPress debug.log file. Returns the last N lines, optionally filtered by a search pattern.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'lines'  => array(
						'type'        => 'integer',
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 500,
						'description' => 'Number of lines to return from the end of the log.',
					),
					'filter' => array(
						'type'        => 'string',
						'description' => 'Optional filter string. Only lines containing this text will be returned.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'lines'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$log_file = WP_CONTENT_DIR . '/debug.log';

				if ( ! file_exists( $log_file ) ) {
					return array( 'success' => false, 'message' => 'Debug log file not found', 'lines' => array() );
				}

				if ( ! is_readable( $log_file ) ) {
					return array( 'success' => false, 'message' => 'Debug log file not readable', 'lines' => array() );
				}

				$num_lines = isset( $input['lines'] ) ? min( max( 1, (int) $input['lines'] ), 500 ) : 50;
				$filter    = isset( $input['filter'] ) ? $input['filter'] : '';

				// Read file from end
				$file_content = file_get_contents( $log_file );
				$all_lines    = explode( "\n", $file_content );
				$all_lines    = array_filter( $all_lines, function( $line ) { return trim( $line ) !== ''; } );

				// Apply filter if specified
				if ( ! empty( $filter ) ) {
					$all_lines = array_filter( $all_lines, function( $line ) use ( $filter ) {
						return stripos( $line, $filter ) !== false;
					} );
				}

				// Get last N lines
				$result_lines = array_slice( $all_lines, -$num_lines );

				return array(
					'success' => true,
					'lines'   => array_values( $result_lines ),
					'message' => sprintf( 'Returned %d lines', count( $result_lines ) ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// SYSTEM - Toggle Debug Mode
	// =========================================================================
	wp_register_ability(
		'system/toggle-debug',
		array(
			'label'               => 'Toggle Debug Mode',
			'description'         => 'Toggles WP_DEBUG on or off in wp-config.php. Can also set WP_DEBUG_LOG and WP_DEBUG_DISPLAY.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'debug'         => array(
						'type'        => 'boolean',
						'description' => 'Set WP_DEBUG to true or false.',
					),
					'debug_log'     => array(
						'type'        => 'boolean',
						'description' => 'Set WP_DEBUG_LOG to true or false. Optional.',
					),
					'debug_display' => array(
						'type'        => 'boolean',
						'description' => 'Set WP_DEBUG_DISPLAY to true or false. Optional.',
					),
				),
				'required'             => array( 'debug' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
					'changes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( ! isset( $input['debug'] ) ) {
					return array( 'success' => false, 'message' => 'Missing required parameter: debug', 'changes' => array() );
				}

				$wp_config_path = ABSPATH . 'wp-config.php';

				if ( ! file_exists( $wp_config_path ) ) {
					return array( 'success' => false, 'message' => 'wp-config.php not found', 'changes' => array() );
				}

				if ( ! is_writable( $wp_config_path ) ) {
					return array( 'success' => false, 'message' => 'wp-config.php is not writable', 'changes' => array() );
				}

				$content = file_get_contents( $wp_config_path );
				if ( false === $content ) {
					return array( 'success' => false, 'message' => 'Failed to read wp-config.php', 'changes' => array() );
				}

				$changes   = array();
				$debug_val = $input['debug'] ? 'true' : 'false';

				// Update or add WP_DEBUG
				if ( preg_match( "/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|false)\s*\)/i", $content ) ) {
					$content   = preg_replace(
						"/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|false)\s*\)/i",
						"define( 'WP_DEBUG', {$debug_val} )",
						$content
					);
					$changes[] = "WP_DEBUG set to {$debug_val}";
				} else {
					// Add before "That's all" comment or at end
					$insert = "define( 'WP_DEBUG', {$debug_val} );\n";
					if ( strpos( $content, "/* That's all" ) !== false ) {
						$content = str_replace( "/* That's all", $insert . "/* That's all", $content );
					} else {
						$content .= "\n" . $insert;
					}
					$changes[] = "WP_DEBUG added and set to {$debug_val}";
				}

				// Handle WP_DEBUG_LOG if specified
				if ( isset( $input['debug_log'] ) ) {
					$log_val = $input['debug_log'] ? 'true' : 'false';
					if ( preg_match( "/define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*(true|false)\s*\)/i", $content ) ) {
						$content   = preg_replace(
							"/define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*(true|false)\s*\)/i",
							"define( 'WP_DEBUG_LOG', {$log_val} )",
							$content
						);
						$changes[] = "WP_DEBUG_LOG set to {$log_val}";
					} elseif ( $input['debug_log'] ) {
						// Only add if setting to true
						$insert = "define( 'WP_DEBUG_LOG', true );\n";
						$content = preg_replace(
							"/(define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|false)\s*\)\s*;)/i",
							"$1\n" . $insert,
							$content
						);
						$changes[] = "WP_DEBUG_LOG added and set to true";
					}
				}

				// Handle WP_DEBUG_DISPLAY if specified
				if ( isset( $input['debug_display'] ) ) {
					$display_val = $input['debug_display'] ? 'true' : 'false';
					if ( preg_match( "/define\s*\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*(true|false)\s*\)/i", $content ) ) {
						$content   = preg_replace(
							"/define\s*\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*(true|false)\s*\)/i",
							"define( 'WP_DEBUG_DISPLAY', {$display_val} )",
							$content
						);
						$changes[] = "WP_DEBUG_DISPLAY set to {$display_val}";
					} elseif ( ! $input['debug_display'] ) {
						// Only add if setting to false (to hide errors)
						$insert = "define( 'WP_DEBUG_DISPLAY', false );\n";
						$content = preg_replace(
							"/(define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|false)\s*\)\s*;)/i",
							"$1\n" . $insert,
							$content
						);
						$changes[] = "WP_DEBUG_DISPLAY added and set to false";
					}
				}

				// Write changes
				$result = file_put_contents( $wp_config_path, $content );
				if ( false === $result ) {
					return array( 'success' => false, 'message' => 'Failed to write wp-config.php', 'changes' => array() );
				}

				return array(
					'success' => true,
					'message' => 'wp-config.php updated successfully',
					'changes' => $changes,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// OPTIONS - Get Option
	// =========================================================================
	wp_register_ability(
		'options/get',
		array(
			'label'               => 'Get Option',
			'description'         => 'Retrieves a WordPress option value by name. Supports both simple values and serialized arrays/objects.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'The option name to retrieve (e.g., "blogname", "rank_math_options_titles").',
					),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'name'    => array( 'type' => 'string' ),
					'value'   => array( 'description' => 'The option value (type varies)' ),
					'type'    => array( 'type' => 'string', 'description' => 'PHP type of the value' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['name'] ) ) {
					return array( 'success' => false, 'name' => '', 'value' => null, 'type' => 'null' );
				}

				$name  = sanitize_key( $input['name'] );
				$value = get_option( $name, null );

				if ( null === $value ) {
					return array(
						'success' => false,
						'name'    => $name,
						'value'   => null,
						'type'    => 'null',
						'message' => 'Option not found',
					);
				}

				return array(
					'success' => true,
					'name'    => $name,
					'value'   => $value,
					'type'    => gettype( $value ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// OPTIONS - Update Option
	// =========================================================================
	wp_register_ability(
		'options/update',
		array(
			'label'               => 'Update Option',
			'description'         => 'Updates a WordPress option value. Can update entire option or a specific key within a serialized array.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'description' => 'The option name to update.',
					),
					'value' => array(
						'description' => 'The new value (can be string, number, boolean, array, or object).',
					),
					'key'   => array(
						'type'        => 'string',
						'description' => 'Optional: If the option is an array, update only this specific key within it.',
					),
				),
				'required'             => array( 'name', 'value' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'name'      => array( 'type' => 'string' ),
					'message'   => array( 'type' => 'string' ),
					'old_value' => array( 'description' => 'Previous value (for verification)' ),
					'new_value' => array( 'description' => 'New value after update' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['name'] ) ) {
					return array( 'success' => false, 'name' => '', 'message' => 'Missing required parameter: name' );
				}

				$name      = sanitize_key( $input['name'] );
				$new_value = $input['value'];
				$key       = isset( $input['key'] ) ? $input['key'] : null;
				$old_value = get_option( $name );

				// If updating a specific key within an array option
				if ( null !== $key && is_array( $old_value ) ) {
					$updated_value       = $old_value;
					$old_key_value       = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
					$updated_value[ $key ] = $new_value;

					$result = update_option( $name, $updated_value );

					return array(
						'success'   => $result,
						'name'      => $name,
						'key'       => $key,
						'message'   => $result ? "Updated key '{$key}' in option '{$name}'" : 'Update failed or value unchanged',
						'old_value' => $old_key_value,
						'new_value' => $new_value,
					);
				}

				// Update entire option
				$result = update_option( $name, $new_value );

				return array(
					'success'   => $result,
					'name'      => $name,
					'message'   => $result ? "Option '{$name}' updated successfully" : 'Update failed or value unchanged',
					'old_value' => $old_value,
					'new_value' => $new_value,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// OPTIONS - List Options (search)
	// =========================================================================
	wp_register_ability(
		'options/list',
		array(
			'label'               => 'List Options',
			'description'         => 'Lists WordPress options matching a search pattern. Useful for discovering option names.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => 'Search pattern (SQL LIKE pattern, e.g., "rank_math%" or "%seo%").',
					),
					'per_page' => array(
						'type'        => 'integer',
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 200,
						'description' => 'Number of options to return.',
					),
				),
				'required'             => array( 'search' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'options' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name' => array( 'type' => 'string' ),
								'type' => array( 'type' => 'string' ),
								'size' => array( 'type' => 'integer', 'description' => 'Approximate size in bytes' ),
							),
						),
					),
					'total'   => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wpdb;

				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['search'] ) ) {
					return array( 'success' => false, 'options' => array(), 'total' => 0, 'message' => 'Missing search pattern' );
				}

				$search   = $input['search'];
				$per_page = isset( $input['per_page'] ) ? min( (int) $input['per_page'], 200 ) : 50;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
						$search,
						$per_page
					),
					ARRAY_A
				);

				$options = array();
				foreach ( $results as $row ) {
					$value     = maybe_unserialize( $row['option_value'] );
					$options[] = array(
						'name' => $row['option_name'],
						'type' => gettype( $value ),
						'size' => strlen( $row['option_value'] ),
					);
				}

				return array(
					'success' => true,
					'options' => $options,
					'total'   => count( $options ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CLOUDFLARE - Clear Cache
	// =========================================================================
	wp_register_ability(
		'cloudflare/clear-cache',
		array(
			'label'               => 'Clear Cloudflare Cache',
			'description'         => 'Purges the Cloudflare cache for the site. Requires Cloudflare plugin to be configured with API credentials.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'purge_everything' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Purge all cached files (default: true).',
					),
					'files'            => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional: Specific URLs to purge instead of everything.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				// Get Cloudflare credentials from plugin options.
				$api_email = get_option( 'cloudflare_api_email', '' );
				$api_key   = get_option( 'cloudflare_api_key', '' );
				$domain    = get_option( 'cloudflare_cached_domain_name', '' );

				if ( empty( $api_email ) || empty( $api_key ) ) {
					return array(
						'success' => false,
						'message' => 'Cloudflare API credentials not configured. Install and configure the Cloudflare plugin first.',
					);
				}

				if ( empty( $domain ) ) {
					$domain = wp_parse_url( home_url(), PHP_URL_HOST );
				}

				// Step 1: Get zone ID for the domain.
				$zones_response = wp_remote_get(
					'https://api.cloudflare.com/client/v4/zones?name=' . rawurlencode( $domain ),
					array(
						'headers' => array(
							'X-Auth-Email' => $api_email,
							'X-Auth-Key'   => $api_key,
							'Content-Type' => 'application/json',
						),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $zones_response ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to connect to Cloudflare API: ' . $zones_response->get_error_message(),
					);
				}

				$zones_body = json_decode( wp_remote_retrieve_body( $zones_response ), true );

				if ( empty( $zones_body['success'] ) || empty( $zones_body['result'][0]['id'] ) ) {
					$error_msg = isset( $zones_body['errors'][0]['message'] ) ? $zones_body['errors'][0]['message'] : 'Zone not found';
					return array(
						'success' => false,
						'message' => 'Cloudflare API error: ' . $error_msg,
					);
				}

				$zone_id = $zones_body['result'][0]['id'];

				// Step 2: Purge cache.
				$purge_everything = isset( $input['purge_everything'] ) ? (bool) $input['purge_everything'] : true;
				$files            = isset( $input['files'] ) && is_array( $input['files'] ) ? $input['files'] : array();

				if ( ! empty( $files ) ) {
					$purge_data = array( 'files' => $files );
				} else {
					$purge_data = array( 'purge_everything' => $purge_everything );
				}

				$purge_response = wp_remote_post(
					'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache',
					array(
						'headers' => array(
							'X-Auth-Email' => $api_email,
							'X-Auth-Key'   => $api_key,
							'Content-Type' => 'application/json',
						),
						'body'    => wp_json_encode( $purge_data ),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $purge_response ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to purge cache: ' . $purge_response->get_error_message(),
					);
				}

				$purge_body = json_decode( wp_remote_retrieve_body( $purge_response ), true );

				if ( empty( $purge_body['success'] ) ) {
					$error_msg = isset( $purge_body['errors'][0]['message'] ) ? $purge_body['errors'][0]['message'] : 'Unknown error';
					return array(
						'success' => false,
						'message' => 'Cache purge failed: ' . $error_msg,
					);
				}

				$message = ! empty( $files )
					? 'Purged ' . count( $files ) . ' specific URL(s) from Cloudflare cache.'
					: 'Purged entire Cloudflare cache for ' . $domain . '.';

				return array(
					'success' => true,
					'message' => $message,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM ABILITIES
	// =========================================================================
	// These abilities provide FTP-like file operations via MCP.
	// All operations are restricted to the WordPress installation directory.
	// Core files (wp-includes/, wp-admin/) cannot be modified.
	// All destructive operations are logged to wp-content/mcp-filesystem.log
	// =========================================================================

	/**
	 * Get the MCP backup directory path and ensure it exists.
	 *
	 * @return string The backup directory path.
	 */
	$mcp_get_backup_dir = function (): string {
		$backup_dir = WP_CONTENT_DIR . '/mcp-backups/' . gmdate( 'Y-m-d' );
		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}
		return $backup_dir;
	};

	/**
	 * Create a backup of a file in the centralized backup directory.
	 *
	 * @param string $source_path The file to backup.
	 * @return string|false The backup path on success, false on failure.
	 */
	$mcp_create_backup = function ( string $source_path ) use ( $mcp_get_backup_dir ): string|false {
		if ( ! file_exists( $source_path ) ) {
			return false;
		}

		$backup_dir  = $mcp_get_backup_dir();
		$filename    = basename( $source_path );
		$backup_name = $filename . '.bak.' . gmdate( 'His' );
		$backup_path = $backup_dir . '/' . $backup_name;

		// Handle duplicate names within same second.
		$counter = 1;
		while ( file_exists( $backup_path ) ) {
			$backup_path = $backup_dir . '/' . $filename . '.bak.' . gmdate( 'His' ) . '.' . $counter;
			$counter++;
		}

		if ( copy( $source_path, $backup_path ) ) {
			return $backup_path;
		}

		return false;
	};

	/**
	 * Clean up old backup folders (older than 7 days).
	 */
	$mcp_cleanup_old_backups = function (): void {
		$backup_base = WP_CONTENT_DIR . '/mcp-backups';
		if ( ! is_dir( $backup_base ) ) {
			return;
		}

		$cutoff = strtotime( '-7 days' );
		$dirs   = glob( $backup_base . '/20*-*-*', GLOB_ONLYDIR );

		foreach ( $dirs as $dir ) {
			$date_str = basename( $dir );
			$date_ts  = strtotime( $date_str );
			if ( $date_ts && $date_ts < $cutoff ) {
				// Delete all files in the directory.
				$files = glob( $dir . '/*' );
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						unlink( $file );
					}
				}
				rmdir( $dir );
			}
		}
	};

	/**
	 * Log filesystem operations to wp-content/mcp-filesystem.log
	 * This log survives context compaction and enables recovery.
	 * Includes security audit information (user, IP).
	 *
	 * @param string $operation The operation type (WRITE, DELETE, MOVE, COPY, APPEND).
	 * @param string $path      The file path being operated on.
	 * @param array  $details   Additional details (backup path, size, context, etc.).
	 */
	$mcp_log_filesystem_operation = function ( string $operation, string $path, array $details = array() ) use ( $mcp_cleanup_old_backups ): void {
		$log_file  = WP_CONTENT_DIR . '/mcp-filesystem.log';
		$timestamp = gmdate( 'Y-m-d H:i:s' );

		// Security audit info.
		$user    = wp_get_current_user();
		$user_id = $user->ID ?? 0;
		$email   = $user->user_email ?? 'unknown';
		$ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

		$entry = "[{$timestamp}] {$operation}\n";
		$entry .= "  File: {$path}\n";
		$entry .= "  User: {$email} (ID:{$user_id}) IP:{$ip}\n";

		if ( ! empty( $details['backup'] ) ) {
			$entry .= "  Backup: {$details['backup']}\n";
		}
		if ( ! empty( $details['size_before'] ) || ! empty( $details['size_after'] ) ) {
			$before = $details['size_before'] ?? '?';
			$after  = $details['size_after'] ?? '?';
			$entry .= "  Size: {$before} -> {$after} bytes\n";
		}
		if ( ! empty( $details['destination'] ) ) {
			$entry .= "  Destination: {$details['destination']}\n";
		}
		if ( ! empty( $details['context'] ) ) {
			$entry .= "  Context: {$details['context']}\n";
		}
		$entry .= "\n";

		// Append to log file (create if doesn't exist).
		file_put_contents( $log_file, $entry, FILE_APPEND | LOCK_EX );

		// Cleanup old backups occasionally (1 in 10 chance to avoid overhead).
		if ( wp_rand( 1, 10 ) === 1 ) {
			$mcp_cleanup_old_backups();
		}
	};

	/**
	 * Check if a file write should be blocked for security reasons.
	 * Uses WordPress security functions where applicable.
	 * Based on Wordfence recommendations: https://www.wordfence.com/learn/how-to-prevent-file-upload-vulnerabilities/
	 *
	 * @param string $path    The file path to check.
	 * @param string $content Optional content to scan for malicious patterns.
	 * @param int    $size    Optional content size in bytes.
	 * @return string|false Error message if blocked, false if allowed.
	 */
	$mcp_check_write_security = function ( string $path, string $content = '', int $size = 0 ): string|false {
		// Respect WordPress DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS constants.
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return 'File modifications are disabled by DISALLOW_FILE_MODS constant.';
		}

		// For PHP files, check DISALLOW_FILE_EDIT.
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'php' === $extension && defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return 'PHP file editing is disabled by DISALLOW_FILE_EDIT constant.';
		}

		// File size limit: 10MB max to prevent DoS.
		$max_size = 10 * 1024 * 1024; // 10MB
		if ( $size > $max_size ) {
			return 'File size exceeds 10MB limit.';
		}
		$filename  = basename( $path );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$dir       = dirname( $path );

		// Allow specific dotfiles that are legitimate config files.
		$allowed_dotfiles   = array( '.htaccess', '.htpasswd', '.user.ini' );
		$is_allowed_dotfile = in_array( $filename, $allowed_dotfiles, true );

		// Use WordPress sanitize_file_name to check for suspicious characters.
		// Skip this check for allowed dotfiles since sanitize_file_name strips leading dots.
		if ( ! $is_allowed_dotfile ) {
			$sanitized = sanitize_file_name( $filename );
			if ( $sanitized !== $filename ) {
				// File has characters that WordPress would sanitize out.
				return 'Filename contains invalid characters. WordPress sanitized version: ' . $sanitized;
			}
		}

		// Dangerous extensions that should never be written.
		$dangerous_anywhere = array( 'phar', 'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'cgi', 'pl', 'py' );
		if ( in_array( $extension, $dangerous_anywhere, true ) ) {
			return "Cannot write files with .{$extension} extension.";
		}

		// PHP files in uploads directory = web shell risk.
		if ( 'php' === $extension && strpos( $path, '/uploads/' ) !== false ) {
			return 'Cannot write PHP files in uploads directory (security risk).';
		}

		// Use WordPress to validate the file type for upload-like operations.
		if ( ! empty( $extension ) ) {
			$allowed_mimes = get_allowed_mime_types();
			$filetype      = wp_check_filetype( $filename, $allowed_mimes );

			// For known extensions, check if WordPress allows them.
			// But allow .htaccess, .php, .txt, .log, .json, .xml, .css, .js (config/code files).
			$always_allowed = array( 'htaccess', 'php', 'txt', 'log', 'json', 'xml', 'css', 'js', 'md', 'html', 'htm' );
			if ( ! in_array( $extension, $always_allowed, true ) && empty( $filetype['type'] ) ) {
				return "File type .{$extension} is not allowed by WordPress.";
			}
		}

		// Block files that look like web shells.
		$shell_patterns = array( 'c99', 'r57', 'wso', 'b374k', 'weevely', 'shell', 'alfa', 'bypass', 'backdoor' );
		$lower_filename = strtolower( $filename );
		foreach ( $shell_patterns as $pattern ) {
			if ( strpos( $lower_filename, $pattern ) !== false ) {
				return "Filename contains blocked pattern: {$pattern}";
			}
		}

		// Block double extensions that could be used to bypass filters.
		if ( preg_match( '/\.(php|phtml|phar)\.[^.]+$/i', $filename ) ) {
			return 'Double extensions with PHP are not allowed (e.g., file.php.jpg).';
		}

		// Content scanning for PHP files - detect common malicious patterns.
		if ( ! empty( $content ) && 'php' === $extension ) {
			// Dangerous function patterns commonly found in web shells.
			$dangerous_patterns = array(
				'/\b(eval|assert|create_function)\s*\(/i',                    // Code execution.
				'/\b(base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i', // Obfuscation.
				'/\b(shell_exec|exec|system|passthru|popen|proc_open)\s*\(/i', // Command execution.
				'/\$_(GET|POST|REQUEST|COOKIE)\s*\[.*\]\s*\(/i',              // Direct superglobal execution.
				'/\bpreg_replace\s*\(\s*[\'"].*\/e[\'"]/i',                    // preg_replace with /e modifier.
				'/\\x[0-9a-fA-F]{2}/',                                        // Hex-encoded content.
				'/\$[a-zA-Z_]\w*\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',      // Variable function with superglobal.
				'/[\'"][a-z]{2,5}[\'"]\s*\.\s*[\'"][a-z]{2,5}[\'"]/i',        // Concatenated short strings (obfuscation).
				'/\$\w+\s*=\s*[\'"][a-z_]+[\'"]\s*;\s*\$\w+\s*\(/i',          // Variable assignment then call.
			);

			foreach ( $dangerous_patterns as $pattern ) {
				if ( preg_match( $pattern, $content ) ) {
					return 'PHP content contains potentially malicious code pattern.';
				}
			}
		}

		return false;
	};

	// =========================================================================
	// FILESYSTEM - Get Changelog
	// =========================================================================
	wp_register_ability(
		'filesystem/get-changelog',
		array(
			'label'               => 'Get Filesystem Changelog',
			'description'         => '[FILESYSTEM] Returns recent filesystem operations log. Use this after context loss to understand what was changed.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'lines' => array(
						'type'        => 'integer',
						'description' => 'Number of lines to return (default 100, max 500).',
					),
				),
				'required'             => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'log'     => array( 'type' => 'string' ),
					'path'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				$log_file = WP_CONTENT_DIR . '/mcp-filesystem.log';
				$lines    = min( max( (int) ( $input['lines'] ?? 100 ), 1 ), 500 );

				if ( ! file_exists( $log_file ) ) {
					return array(
						'success' => true,
						'log'     => '',
						'path'    => $log_file,
						'message' => 'No filesystem operations have been logged yet.',
					);
				}

				// Read last N lines efficiently.
				$content = file_get_contents( $log_file );
				if ( false === $content ) {
					return array(
						'success' => false,
						'message' => 'Failed to read changelog.',
					);
				}

				$all_lines   = explode( "\n", $content );
				$total_lines = count( $all_lines );
				$start       = max( 0, $total_lines - $lines );
				$last_lines  = array_slice( $all_lines, $start );

				return array(
					'success' => true,
					'log'     => implode( "\n", $last_lines ),
					'path'    => $log_file,
					'message' => "Showing last {$lines} lines of {$total_lines} total.",
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// =========================================================================
	// FILESYSTEM - Read File
	// =========================================================================
	wp_register_ability(
		'filesystem/read-file',
		array(
			'label'               => 'Read File',
			'description'         => '[FILESYSTEM] Reads file contents. Restricted to WordPress directory. Explain to user what file and why before using.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path' => array(
						'type'        => 'string',
						'description' => 'File path (absolute or relative to WordPress root). Examples: ".htaccess", "wp-config.php", "wp-content/themes/mytheme/style.css"',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'content'  => array( 'type' => 'string' ),
					'path'     => array( 'type' => 'string' ),
					'size'     => array( 'type' => 'integer' ),
					'modified' => array( 'type' => 'string' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				$path = $input['path'] ?? '';

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				// Resolve path relative to ABSPATH if not absolute.
				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$full_path = realpath( $full_path );

				if ( false === $full_path ) {
					return array(
						'success' => false,
						'message' => 'File not found: ' . $input['path'],
					);
				}

				// Security: ensure file is within WordPress directory or its parent.
				$allowed_base = dirname( ABSPATH );
				if ( strpos( $full_path, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Access denied. File must be within the WordPress installation directory.',
					);
				}

				if ( ! is_file( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is not a file: ' . $input['path'],
					);
				}

				if ( ! is_readable( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'File is not readable: ' . $input['path'],
					);
				}

				$content = file_get_contents( $full_path );

				if ( false === $content ) {
					return array(
						'success' => false,
						'message' => 'Failed to read file: ' . $input['path'],
					);
				}

				return array(
					'success'  => true,
					'content'  => $content,
					'path'     => $full_path,
					'size'     => filesize( $full_path ),
					'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $full_path ) ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Write File
	// =========================================================================
	wp_register_ability(
		'filesystem/write-file',
		array(
			'label'               => 'Write File',
			'description'         => '[FILESYSTEM] Creates/overwrites file. DESTRUCTIVE - explain what file and content before using. Cannot modify core files.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'    => array(
						'type'        => 'string',
						'description' => 'File path (absolute or relative to WordPress root).',
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'Content to write to the file.',
					),
					'backup'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Create a backup before overwriting (default: true).',
					),
					'context' => array(
						'type'        => 'string',
						'description' => 'Brief description of why this change is being made (logged for recovery).',
					),
				),
				'required'             => array( 'path', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'message'     => array( 'type' => 'string' ),
					'path'        => array( 'type' => 'string' ),
					'backup_path' => array( 'type' => 'string' ),
					'bytes'       => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_check_write_security ): array {
				$path    = $input['path'] ?? '';
				$content = $input['content'] ?? '';
				$backup  = $input['backup'] ?? true;
				$context = $input['context'] ?? '';

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				// Resolve path relative to ABSPATH if not absolute.
				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				// For new files, check the directory exists.
				$dir = dirname( $full_path );
				if ( ! is_dir( $dir ) ) {
					return array(
						'success' => false,
						'message' => 'Directory does not exist: ' . $dir,
					);
				}

				$real_dir = realpath( $dir );

				// Security: ensure within WordPress directory.
				$allowed_base = dirname( ABSPATH );
				if ( strpos( $real_dir, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Access denied. File must be within the WordPress installation directory.',
					);
				}

				// Security: block core file modifications.
				$full_path_normalized = $real_dir . '/' . basename( $full_path );
				if ( strpos( $full_path_normalized, ABSPATH . 'wp-includes/' ) === 0 ||
					strpos( $full_path_normalized, ABSPATH . 'wp-admin/' ) === 0 ) {
					return array(
						'success' => false,
						'message' => 'Cannot modify WordPress core files in wp-includes or wp-admin.',
					);
				}

				// Security: check for dangerous file patterns and content.
				$security_error = $mcp_check_write_security( $full_path_normalized, $content, strlen( $content ) );
				if ( $security_error ) {
					return array(
						'success' => false,
						'message' => $security_error,
					);
				}

				$backup_path  = null;
				$size_before  = file_exists( $full_path_normalized ) ? filesize( $full_path_normalized ) : 0;

				// Create backup if file exists (using centralized backup).
				if ( $backup && file_exists( $full_path_normalized ) ) {
					$backup_path = $mcp_create_backup( $full_path_normalized );
					if ( false === $backup_path ) {
						return array(
							'success' => false,
							'message' => 'Failed to create backup.',
						);
					}
				}

				$bytes = file_put_contents( $full_path_normalized, $content );

				if ( false === $bytes ) {
					return array(
						'success' => false,
						'message' => 'Failed to write file: ' . $path,
					);
				}

				// Log the operation.
				$mcp_log_filesystem_operation( 'WRITE', $full_path_normalized, array(
					'backup'      => $backup_path,
					'size_before' => $size_before,
					'size_after'  => $bytes,
					'context'     => $context,
				) );

				$result = array(
					'success' => true,
					'message' => 'File written successfully.',
					'path'    => $full_path_normalized,
					'bytes'   => $bytes,
				);

				if ( $backup_path ) {
					$result['backup_path'] = $backup_path;
				}

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Append to File
	// =========================================================================
	wp_register_ability(
		'filesystem/append-file',
		array(
			'label'               => 'Append to File',
			'description'         => '[FILESYSTEM] Appends to file (e.g., .htaccess rules). Explain what you are adding before using.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'    => array(
						'type'        => 'string',
						'description' => 'File path (absolute or relative to WordPress root).',
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'Content to append to the file.',
					),
					'prepend' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, add content to the beginning instead of the end.',
					),
					'backup'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Create a backup before modifying (default: true).',
					),
					'context' => array(
						'type'        => 'string',
						'description' => 'Brief description of why this change is being made (logged for recovery).',
					),
				),
				'required'             => array( 'path', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'message'     => array( 'type' => 'string' ),
					'path'        => array( 'type' => 'string' ),
					'backup_path' => array( 'type' => 'string' ),
					'bytes'       => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_check_write_security ): array {
				$path    = $input['path'] ?? '';
				$content = $input['content'] ?? '';
				$prepend = $input['prepend'] ?? false;
				$backup  = $input['backup'] ?? true;
				$context = $input['context'] ?? '';

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				// Resolve path.
				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$full_path = realpath( $full_path );

				if ( false === $full_path || ! file_exists( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'File not found: ' . $input['path'],
					);
				}

				// Security check - path.
				$allowed_base = dirname( ABSPATH );
				if ( strpos( $full_path, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Access denied.',
					);
				}

				// Block core files.
				if ( strpos( $full_path, ABSPATH . 'wp-includes/' ) === 0 ||
					strpos( $full_path, ABSPATH . 'wp-admin/' ) === 0 ) {
					return array(
						'success' => false,
						'message' => 'Cannot modify WordPress core files.',
					);
				}

				// Security: check for dangerous content patterns.
				$security_error = $mcp_check_write_security( $full_path, $content, strlen( $content ) );
				if ( $security_error ) {
					return array(
						'success' => false,
						'message' => $security_error,
					);
				}

				$backup_path = null;
				$size_before = filesize( $full_path );

				// Create backup before modifying.
				if ( $backup ) {
					$backup_path = $mcp_create_backup( $full_path );
					if ( false === $backup_path ) {
						return array(
							'success' => false,
							'message' => 'Failed to create backup.',
						);
					}
				}

				if ( $prepend ) {
					$existing = file_get_contents( $full_path );
					$bytes    = file_put_contents( $full_path, $content . $existing );
				} else {
					$bytes = file_put_contents( $full_path, $content, FILE_APPEND );
				}

				if ( false === $bytes ) {
					return array(
						'success' => false,
						'message' => 'Failed to append to file.',
					);
				}

				$size_after = filesize( $full_path );

				// Log the operation.
				$mcp_log_filesystem_operation( 'APPEND', $full_path, array(
					'backup'      => $backup_path,
					'size_before' => $size_before,
					'size_after'  => $size_after,
					'context'     => $context . ( $prepend ? ' (prepend)' : '' ),
				) );

				$result = array(
					'success' => true,
					'message' => 'Content appended successfully.',
					'path'    => $full_path,
					'bytes'   => $bytes,
				);

				if ( $backup_path ) {
					$result['backup_path'] = $backup_path;
				}

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - List Directory
	// =========================================================================
	wp_register_ability(
		'filesystem/list-directory',
		array(
			'label'               => 'List Directory',
			'description'         => '[FILESYSTEM] Lists directory contents. Safe read-only operation.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'      => array(
						'type'        => 'string',
						'default'     => '.',
						'description' => 'Directory path (absolute or relative to WordPress root). Default is WordPress root.',
					),
					'recursive' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Include subdirectories recursively (max 2 levels deep).',
					),
					'pattern'   => array(
						'type'        => 'string',
						'description' => 'Filter files by pattern (e.g., "*.php", "*.js").',
					),
				),
				'required'             => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'path'    => array( 'type' => 'string' ),
					'items'   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'     => array( 'type' => 'string' ),
								'type'     => array( 'type' => 'string' ),
								'size'     => array( 'type' => 'integer' ),
								'modified' => array( 'type' => 'string' ),
								'path'     => array( 'type' => 'string' ),
							),
						),
					),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				$path      = $input['path'] ?? '.';
				$recursive = $input['recursive'] ?? false;
				$pattern   = $input['pattern'] ?? null;

				// Resolve path.
				if ( '.' === $path || empty( $path ) ) {
					$full_path = ABSPATH;
				} elseif ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$full_path = realpath( $full_path );

				if ( false === $full_path ) {
					return array(
						'success' => false,
						'message' => 'Directory not found: ' . $input['path'],
					);
				}

				// Security check.
				$allowed_base = dirname( ABSPATH );
				if ( strpos( $full_path, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Access denied.',
					);
				}

				if ( ! is_dir( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is not a directory.',
					);
				}

				$items = array();

				$list_dir = function ( $dir, $depth = 0 ) use ( &$list_dir, &$items, $recursive, $pattern ) {
					if ( $depth > 2 ) {
						return;
					}

					$files = scandir( $dir );
					foreach ( $files as $file ) {
						if ( '.' === $file || '..' === $file ) {
							continue;
						}

						$file_path = $dir . '/' . $file;

						// Pattern matching.
						if ( $pattern && ! fnmatch( $pattern, $file ) && is_file( $file_path ) ) {
							continue;
						}

						$is_dir = is_dir( $file_path );
						$items[] = array(
							'name'     => $file,
							'type'     => $is_dir ? 'directory' : 'file',
							'size'     => $is_dir ? 0 : filesize( $file_path ),
							'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $file_path ) ),
							'path'     => $file_path,
						);

						if ( $recursive && $is_dir ) {
							$list_dir( $file_path, $depth + 1 );
						}
					}
				};

				$list_dir( $full_path );

				return array(
					'success' => true,
					'path'    => $full_path,
					'items'   => $items,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Delete File
	// =========================================================================
	wp_register_ability(
		'filesystem/delete-file',
		array(
			'label'               => 'Delete File',
			'description'         => '[FILESYSTEM] Deletes file. DESTRUCTIVE - explain what and why before using. Creates backup by default.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'    => array(
						'type'        => 'string',
						'description' => 'File path to delete.',
					),
					'backup'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Create a backup before deleting (default: true).',
					),
					'context' => array(
						'type'        => 'string',
						'description' => 'Brief description of why this file is being deleted (logged for recovery).',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'message'     => array( 'type' => 'string' ),
					'backup_path' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_create_backup, $mcp_log_filesystem_operation ): array {
				$path    = $input['path'] ?? '';
				$backup  = $input['backup'] ?? true;
				$context = $input['context'] ?? '';

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				// Resolve path.
				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$full_path = realpath( $full_path );

				if ( false === $full_path || ! file_exists( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'File not found.',
					);
				}

				// Security check.
				$allowed_base = dirname( ABSPATH );
				if ( strpos( $full_path, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Access denied.',
					);
				}

				// Block directories.
				if ( is_dir( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Cannot delete directories. Use filesystem/delete-directory instead.',
					);
				}

				// Block core files.
				if ( strpos( $full_path, ABSPATH . 'wp-includes/' ) === 0 ||
					strpos( $full_path, ABSPATH . 'wp-admin/' ) === 0 ) {
					return array(
						'success' => false,
						'message' => 'Cannot delete WordPress core files.',
					);
				}

				// Block critical files.
				$critical = array( 'wp-config.php', '.htaccess', 'index.php' );
				if ( in_array( basename( $full_path ), $critical, true ) && dirname( $full_path ) === rtrim( ABSPATH, '/' ) ) {
					return array(
						'success' => false,
						'message' => 'Cannot delete critical WordPress files. Use write-file to modify instead.',
					);
				}

				$file_size   = filesize( $full_path );
				$backup_path = null;

				if ( $backup ) {
					$backup_path = $mcp_create_backup( $full_path );
					if ( false === $backup_path ) {
						return array(
							'success' => false,
							'message' => 'Failed to create backup.',
						);
					}
				}

				if ( ! unlink( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to delete file.',
					);
				}

				// Log the operation.
				$mcp_log_filesystem_operation( 'DELETE', $full_path, array(
					'backup'      => $backup_path,
					'size_before' => $file_size,
					'context'     => $context,
				) );

				$result = array(
					'success' => true,
					'message' => 'File deleted successfully.',
				);

				if ( $backup_path ) {
					$result['backup_path'] = $backup_path;
				}

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - File Info
	// =========================================================================
	wp_register_ability(
		'filesystem/file-info',
		array(
			'label'               => 'Get File Info',
			'description'         => '[FILESYSTEM] Gets file/directory metadata. Safe read-only operation.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path' => array(
						'type'        => 'string',
						'description' => 'File or directory path.',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'path'        => array( 'type' => 'string' ),
					'type'        => array( 'type' => 'string' ),
					'size'        => array( 'type' => 'integer' ),
					'permissions' => array( 'type' => 'string' ),
					'owner'       => array( 'type' => 'string' ),
					'group'       => array( 'type' => 'string' ),
					'created'     => array( 'type' => 'string' ),
					'modified'    => array( 'type' => 'string' ),
					'accessed'    => array( 'type' => 'string' ),
					'readable'    => array( 'type' => 'boolean' ),
					'writable'    => array( 'type' => 'boolean' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				$path = $input['path'] ?? '';

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				// Resolve path.
				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$full_path = realpath( $full_path );

				if ( false === $full_path ) {
					return array(
						'success' => false,
						'message' => 'Path not found.',
					);
				}

				// Security check.
				$allowed_base = dirname( ABSPATH );
				if ( strpos( $full_path, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Access denied.',
					);
				}

				$stat = stat( $full_path );

				return array(
					'success'     => true,
					'path'        => $full_path,
					'type'        => is_dir( $full_path ) ? 'directory' : 'file',
					'size'        => $stat['size'],
					'permissions' => substr( sprintf( '%o', fileperms( $full_path ) ), -4 ),
					'owner'       => function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $stat['uid'] )['name'] ?? $stat['uid'] : $stat['uid'],
					'group'       => function_exists( 'posix_getgrgid' ) ? posix_getgrgid( $stat['gid'] )['name'] ?? $stat['gid'] : $stat['gid'],
					'created'     => gmdate( 'Y-m-d H:i:s', $stat['ctime'] ),
					'modified'    => gmdate( 'Y-m-d H:i:s', $stat['mtime'] ),
					'accessed'    => gmdate( 'Y-m-d H:i:s', $stat['atime'] ),
					'readable'    => is_readable( $full_path ),
					'writable'    => is_writable( $full_path ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Create Directory
	// =========================================================================
	wp_register_ability(
		'filesystem/create-directory',
		array(
			'label'               => 'Create Directory',
			'description'         => '[FILESYSTEM] Creates directory. Explain purpose before using.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'        => array(
						'type'        => 'string',
						'description' => 'Directory path to create.',
					),
					'permissions' => array(
						'type'        => 'string',
						'default'     => '0755',
						'description' => 'Permissions for the new directory (default: 0755).',
					),
					'recursive'   => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Create parent directories if they do not exist.',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
					'path'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				$path        = $input['path'] ?? '';
				$permissions = octdec( $input['permissions'] ?? '0755' );
				$recursive   = $input['recursive'] ?? true;

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				// Resolve path.
				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				// Check parent directory.
				$parent = dirname( $full_path );
				$real_parent = realpath( $parent );

				// If parent doesn't exist and not recursive, fail.
				if ( false === $real_parent && ! $recursive ) {
					return array(
						'success' => false,
						'message' => 'Parent directory does not exist.',
					);
				}

				// Security: check parent is within allowed base.
				$allowed_base = dirname( ABSPATH );
				if ( $real_parent && strpos( $real_parent, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Access denied.',
					);
				}

				if ( file_exists( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Path already exists.',
					);
				}

				if ( ! mkdir( $full_path, $permissions, $recursive ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to create directory.',
					);
				}

				return array(
					'success' => true,
					'message' => 'Directory created successfully.',
					'path'    => realpath( $full_path ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Copy File
	// =========================================================================
	wp_register_ability(
		'filesystem/copy-file',
		array(
			'label'               => 'Copy File',
			'description'         => '[FILESYSTEM] Copies file. Explain source, destination, and purpose before using.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'source'    => array(
						'type'        => 'string',
						'description' => 'Source file path.',
					),
					'dest'      => array(
						'type'        => 'string',
						'description' => 'Destination file path.',
					),
					'overwrite' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Overwrite destination if it exists.',
					),
					'context'   => array(
						'type'        => 'string',
						'description' => 'Brief description of why this copy is being made (logged for recovery).',
					),
				),
				'required'             => array( 'source', 'dest' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'message'     => array( 'type' => 'string' ),
					'source'      => array( 'type' => 'string' ),
					'dest'        => array( 'type' => 'string' ),
					'backup_path' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_check_write_security ): array {
				$source    = $input['source'] ?? '';
				$dest      = $input['dest'] ?? '';
				$overwrite = $input['overwrite'] ?? false;
				$context   = $input['context'] ?? '';

				if ( empty( $source ) || empty( $dest ) ) {
					return array(
						'success' => false,
						'message' => 'Source and destination are required.',
					);
				}

				// Resolve paths.
				$source_path = strpos( $source, '/' ) !== 0 ? ABSPATH . $source : $source;
				$dest_path   = strpos( $dest, '/' ) !== 0 ? ABSPATH . $dest : $dest;

				$source_path = realpath( $source_path );

				if ( false === $source_path ) {
					return array(
						'success' => false,
						'message' => 'Source file not found.',
					);
				}

				// Security checks.
				$allowed_base = dirname( ABSPATH );
				if ( strpos( $source_path, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Source access denied.',
					);
				}

				$dest_dir = realpath( dirname( $dest_path ) );
				if ( false === $dest_dir || strpos( $dest_dir, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Destination access denied.',
					);
				}

				$final_dest = $dest_dir . '/' . basename( $dest_path );

				// Security: check destination for dangerous patterns.
				$security_error = $mcp_check_write_security( $final_dest );
				if ( $security_error ) {
					return array(
						'success' => false,
						'message' => $security_error,
					);
				}

				$backup_path = null;

				if ( file_exists( $final_dest ) ) {
					if ( ! $overwrite ) {
						return array(
							'success' => false,
							'message' => 'Destination already exists. Use overwrite=true to replace.',
						);
					}
					// Backup destination before overwriting.
					$backup_path = $mcp_create_backup( $final_dest );
				}

				if ( ! copy( $source_path, $final_dest ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to copy file.',
					);
				}

				// Log the operation.
				$mcp_log_filesystem_operation( 'COPY', $source_path, array(
					'destination' => $final_dest,
					'backup'      => $backup_path,
					'context'     => $context,
				) );

				$result = array(
					'success' => true,
					'message' => 'File copied successfully.',
					'source'  => $source_path,
					'dest'    => $final_dest,
				);

				if ( $backup_path ) {
					$result['backup_path'] = $backup_path;
				}

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Move/Rename File
	// =========================================================================
	wp_register_ability(
		'filesystem/move-file',
		array(
			'label'               => 'Move/Rename File',
			'description'         => '[FILESYSTEM] Moves/renames file. DESTRUCTIVE - explain source, destination, and purpose before using.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'source'    => array(
						'type'        => 'string',
						'description' => 'Source file path.',
					),
					'dest'      => array(
						'type'        => 'string',
						'description' => 'Destination file path.',
					),
					'overwrite' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Overwrite destination if it exists.',
					),
					'context'   => array(
						'type'        => 'string',
						'description' => 'Brief description of why this move is being made (logged for recovery).',
					),
				),
				'required'             => array( 'source', 'dest' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'            => array( 'type' => 'boolean' ),
					'message'            => array( 'type' => 'string' ),
					'source'             => array( 'type' => 'string' ),
					'dest'               => array( 'type' => 'string' ),
					'source_backup_path' => array( 'type' => 'string' ),
					'dest_backup_path'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_check_write_security ): array {
				$source    = $input['source'] ?? '';
				$dest      = $input['dest'] ?? '';
				$overwrite = $input['overwrite'] ?? false;
				$context   = $input['context'] ?? '';

				if ( empty( $source ) || empty( $dest ) ) {
					return array(
						'success' => false,
						'message' => 'Source and destination are required.',
					);
				}

				// Resolve paths.
				$source_path = strpos( $source, '/' ) !== 0 ? ABSPATH . $source : $source;
				$dest_path   = strpos( $dest, '/' ) !== 0 ? ABSPATH . $dest : $dest;

				$source_path = realpath( $source_path );

				if ( false === $source_path ) {
					return array(
						'success' => false,
						'message' => 'Source file not found.',
					);
				}

				// Security checks.
				$allowed_base = dirname( ABSPATH );
				if ( strpos( $source_path, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Source access denied.',
					);
				}

				// Block moving core files.
				if ( strpos( $source_path, ABSPATH . 'wp-includes/' ) === 0 ||
					strpos( $source_path, ABSPATH . 'wp-admin/' ) === 0 ) {
					return array(
						'success' => false,
						'message' => 'Cannot move WordPress core files.',
					);
				}

				$dest_dir = realpath( dirname( $dest_path ) );
				if ( false === $dest_dir || strpos( $dest_dir, $allowed_base ) !== 0 ) {
					return array(
						'success' => false,
						'message' => 'Destination access denied.',
					);
				}

				$final_dest = $dest_dir . '/' . basename( $dest_path );

				// Security: check destination for dangerous patterns.
				$security_error = $mcp_check_write_security( $final_dest );
				if ( $security_error ) {
					return array(
						'success' => false,
						'message' => $security_error,
					);
				}

				$source_backup_path = null;
				$dest_backup_path   = null;

				// Always backup source before moving (it will be gone after).
				$source_backup_path = $mcp_create_backup( $source_path );
				if ( false === $source_backup_path ) {
					return array(
						'success' => false,
						'message' => 'Failed to create source backup.',
					);
				}

				if ( file_exists( $final_dest ) ) {
					if ( ! $overwrite ) {
						return array(
							'success' => false,
							'message' => 'Destination already exists. Use overwrite=true to replace.',
						);
					}
					// Backup destination before overwriting.
					$dest_backup_path = $mcp_create_backup( $final_dest );
				}

				if ( ! rename( $source_path, $final_dest ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to move file.',
					);
				}

				// Log the operation.
				$mcp_log_filesystem_operation( 'MOVE', $source_path, array(
					'destination' => $final_dest,
					'backup'      => $source_backup_path,
					'context'     => $context,
				) );

				$result = array(
					'success'            => true,
					'message'            => 'File moved successfully.',
					'source'             => $source_path,
					'dest'               => $final_dest,
					'source_backup_path' => $source_backup_path,
				);

				if ( $dest_backup_path ) {
					$result['dest_backup_path'] = $dest_backup_path;
				}

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

}
add_action( 'wp_abilities_api_init', 'mcp_register_content_abilities' );
