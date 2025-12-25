<?php
/**
 * Plugin Name: MCP Expose Abilities
 * Plugin URI: https://devenia.com
 * Description: Core WordPress abilities for MCP. Content, menus, users, media, widgets, plugins, options, and system management. Add-on plugins available for Elementor, GeneratePress, Cloudflare, and filesystem operations.
 * Version: 3.0.3
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
					'author_id'    => array(
						'type'        => 'integer',
						'description' => 'Author user ID. Defaults to current user.',
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
				if ( ! empty( $input['author_id'] ) ) {
					$post_data['post_author'] = intval( $input['author_id'] );
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
					'author_id'    => array(
						'type'        => 'integer',
						'description' => 'New author user ID.',
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
				if ( isset( $input['author_id'] ) ) {
					$post_data['post_author'] = intval( $input['author_id'] );
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
	// REVISIONS - List
	// =========================================================================
	wp_register_ability(
		'content/list-revisions',
		array(
			'label'               => 'List Revisions',
			'description'         => 'Lists revisions for a post or page. Returns revision IDs, dates, and authors.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to get revisions for.',
					),
					'per_page' => array(
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
					'success'   => array( 'type' => 'boolean' ),
					'revisions' => array( 'type' => 'array' ),
					'total'     => array( 'type' => 'integer' ),
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

				$per_page  = $input['per_page'] ?? 10;
				$revisions = wp_get_post_revisions( $input['id'], array( 'posts_per_page' => $per_page ) );

				$result = array();
				foreach ( $revisions as $revision ) {
					$author = get_user_by( 'id', $revision->post_author );
					$result[] = array(
						'id'       => $revision->ID,
						'date'     => $revision->post_date,
						'modified' => $revision->post_modified,
						'author'   => $author ? $author->display_name : 'Unknown',
						'title'    => $revision->post_title,
					);
				}

				return array(
					'success'   => true,
					'revisions' => $result,
					'total'     => count( $result ),
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
	// REVISIONS - Get
	// =========================================================================
	wp_register_ability(
		'content/get-revision',
		array(
			'label'               => 'Get Revision',
			'description'         => 'Retrieves a specific revision with full content. Useful for comparing or restoring.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Revision ID to retrieve.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'id'        => array( 'type' => 'integer' ),
					'parent_id' => array( 'type' => 'integer' ),
					'date'      => array( 'type' => 'string' ),
					'author'    => array( 'type' => 'string' ),
					'title'     => array( 'type' => 'string' ),
					'content'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => 'Revision ID is required' );
				}

				$revision = get_post( $input['id'] );
				if ( ! $revision || 'revision' !== $revision->post_type ) {
					return array( 'success' => false, 'message' => 'Revision not found' );
				}

				$author = get_user_by( 'id', $revision->post_author );

				return array(
					'success'   => true,
					'id'        => $revision->ID,
					'parent_id' => $revision->post_parent,
					'date'      => $revision->post_date,
					'modified'  => $revision->post_modified,
					'author'    => $author ? $author->display_name : 'Unknown',
					'title'     => $revision->post_title,
					'content'   => $revision->post_content,
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
				// Define stub for get_current_screen() if not available (REST API context).
				if ( ! function_exists( 'get_current_screen' ) ) {
					function get_current_screen() {
						return null;
					}
				}
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

				$name = sanitize_key( $input['name'] );

				// Protected options that cannot be modified via MCP for security.
				$protected_options = array(
					'active_plugins',           // Can disable security plugins.
					'siteurl',                  // Can break site access.
					'home',                     // Can break site access.
					'users_can_register',       // Security: user registration.
					'default_role',             // Security: new user privileges.
					'admin_email',              // Security: site recovery email.
					'cron',                     // Can inject malicious scheduled tasks.
					'auto_updater.lock',        // Can block security updates.
					'rewrite_rules',            // Can break permalinks.
					'recently_activated',       // Plugin state tracking.
					'uninstall_plugins',        // Plugin cleanup callbacks.
					'wp_user_roles',            // Security: role definitions.
				);

				if ( in_array( $name, $protected_options, true ) ) {
					return array(
						'success' => false,
						'name'    => $name,
						'message' => "Option '{$name}' is protected and cannot be modified via MCP for security reasons.",
					);
				}
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
	// COMMENTS - List
	// =========================================================================
	wp_register_ability(
		'comments/list',
		array(
			'label'               => 'List Comments',
			'description'         => 'Retrieves comments with optional filtering by status, post, or author.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'  => array(
						'type'        => 'string',
						'enum'        => array( 'approve', 'hold', 'spam', 'trash', 'all' ),
						'default'     => 'all',
						'description' => 'Filter by comment status. "approve" = approved, "hold" = pending moderation.',
					),
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Filter by post ID.',
					),
					'per_page' => array(
						'type'        => 'integer',
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Number of comments to return.',
					),
					'orderby' => array(
						'type'        => 'string',
						'enum'        => array( 'comment_date', 'comment_ID' ),
						'default'     => 'comment_date',
						'description' => 'Field to order by.',
					),
					'order'   => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'default'     => 'DESC',
						'description' => 'Sort order.',
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => function ( array $params ): array {
				$args = array(
					'number'  => $params['per_page'] ?? 20,
					'orderby' => $params['orderby'] ?? 'comment_date',
					'order'   => $params['order'] ?? 'DESC',
				);

				if ( ! empty( $params['status'] ) && 'all' !== $params['status'] ) {
					$args['status'] = $params['status'];
				}

				if ( ! empty( $params['post_id'] ) ) {
					$args['post_id'] = $params['post_id'];
				}

				$comments = get_comments( $args );
				$data     = array();

				foreach ( $comments as $comment ) {
					$data[] = array(
						'id'           => (int) $comment->comment_ID,
						'post_id'      => (int) $comment->comment_post_ID,
						'post_title'   => get_the_title( $comment->comment_post_ID ),
						'author'       => $comment->comment_author,
						'author_email' => $comment->comment_author_email,
						'content'      => $comment->comment_content,
						'status'       => wp_get_comment_status( $comment ),
						'date'         => $comment->comment_date,
						'parent'       => (int) $comment->comment_parent,
					);
				}

				return array(
					'success'  => true,
					'comments' => $data,
					'total'    => count( $data ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
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
	// COMMENTS - Get
	// =========================================================================
	wp_register_ability(
		'comments/get',
		array(
			'label'               => 'Get Comment',
			'description'         => 'Retrieves a single comment by ID with full details.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'The comment ID.',
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'execute_callback'            => function ( array $params ): array {
				$comment = get_comment( $params['id'] );

				if ( ! $comment ) {
					return array(
						'success' => false,
						'error'   => 'Comment not found.',
					);
				}

				return array(
					'success' => true,
					'comment' => array(
						'id'           => (int) $comment->comment_ID,
						'post_id'      => (int) $comment->comment_post_ID,
						'post_title'   => get_the_title( $comment->comment_post_ID ),
						'author'       => $comment->comment_author,
						'author_email' => $comment->comment_author_email,
						'author_url'   => $comment->comment_author_url,
						'author_ip'    => $comment->comment_author_IP,
						'content'      => $comment->comment_content,
						'status'       => wp_get_comment_status( $comment ),
						'date'         => $comment->comment_date,
						'parent'       => (int) $comment->comment_parent,
						'user_id'      => (int) $comment->user_id,
					),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
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
	// COMMENTS - Approve/Update Status
	// =========================================================================
	wp_register_ability(
		'comments/update-status',
		array(
			'label'               => 'Update Comment Status',
			'description'         => 'Approves, holds, spams, or trashes a comment.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => 'The comment ID.',
					),
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'approve', 'hold', 'spam', 'trash' ),
						'description' => 'New status: approve (publish), hold (pending), spam, or trash.',
					),
				),
				'required'             => array( 'id', 'status' ),
				'additionalProperties' => false,
			),
			'execute_callback'            => function ( array $params ): array {
				$comment = get_comment( $params['id'] );

				if ( ! $comment ) {
					return array(
						'success' => false,
						'error'   => 'Comment not found.',
					);
				}

				// Map status to WordPress values.
				$status_map = array(
					'approve' => 1,
					'hold'    => 0,
					'spam'    => 'spam',
					'trash'   => 'trash',
				);

				$result = wp_set_comment_status( $params['id'], $status_map[ $params['status'] ] );

				if ( ! $result ) {
					return array(
						'success' => false,
						'error'   => 'Failed to update comment status.',
					);
				}

				return array(
					'success'    => true,
					'comment_id' => $params['id'],
					'new_status' => $params['status'],
					'message'    => 'Comment status updated.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
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
	// COMMENTS - Reply
	// =========================================================================
	wp_register_ability(
		'comments/reply',
		array(
			'label'               => 'Reply to Comment',
			'description'         => 'Posts a reply to an existing comment.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'parent_id' => array(
						'type'        => 'integer',
						'description' => 'The parent comment ID to reply to.',
					),
					'content'   => array(
						'type'        => 'string',
						'description' => 'The reply content.',
					),
					'author'    => array(
						'type'        => 'string',
						'description' => 'Author name for the reply.',
					),
					'email'     => array(
						'type'        => 'string',
						'description' => 'Author email for the reply.',
					),
				),
				'required'             => array( 'parent_id', 'content' ),
				'additionalProperties' => false,
			),
			'execute_callback'            => function ( array $params ): array {
				$parent = get_comment( $params['parent_id'] );

				if ( ! $parent ) {
					return array(
						'success' => false,
						'error'   => 'Parent comment not found.',
					);
				}

				$user = wp_get_current_user();

				$comment_data = array(
					'comment_post_ID'      => $parent->comment_post_ID,
					'comment_content'      => $params['content'],
					'comment_parent'       => $params['parent_id'],
					'comment_author'       => $params['author'] ?? $user->display_name,
					'comment_author_email' => $params['email'] ?? $user->user_email,
					'user_id'              => $user->ID,
					'comment_approved'     => 1,
				);

				$comment_id = wp_insert_comment( $comment_data );

				if ( ! $comment_id ) {
					return array(
						'success' => false,
						'error'   => 'Failed to create reply.',
					);
				}

				return array(
					'success'    => true,
					'comment_id' => $comment_id,
					'message'    => 'Reply posted successfully.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
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
	// COMMENTS - Delete
	// =========================================================================
	wp_register_ability(
		'comments/delete',
		array(
			'label'               => 'Delete Comment',
			'description'         => 'Permanently deletes a comment.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'The comment ID to delete.',
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, permanently delete. If false, move to trash.',
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'execute_callback'            => function ( array $params ): array {
				$comment = get_comment( $params['id'] );

				if ( ! $comment ) {
					return array(
						'success' => false,
						'error'   => 'Comment not found.',
					);
				}

				$force  = $params['force'] ?? false;
				$result = wp_delete_comment( $params['id'], $force );

				if ( ! $result ) {
					return array(
						'success' => false,
						'error'   => 'Failed to delete comment.',
					);
				}

				return array(
					'success' => true,
					'message' => $force ? 'Comment permanently deleted.' : 'Comment moved to trash.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
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


}
add_action( 'wp_abilities_api_init', 'mcp_register_content_abilities' );
