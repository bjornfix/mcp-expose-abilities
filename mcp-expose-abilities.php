<?php
/**
 * Plugin Name: MCP Expose Abilities
 * Plugin URI: https://devenia.com
 * Description: Exposes WordPress abilities via MCP and registers content management abilities for posts, pages, and media.
 * Version: 1.4.2
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
					$wp_filesystem->delete( $temp_dir, true );
					return array( 'success' => false, 'message' => 'Invalid plugin zip - no plugin folder found' );
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
}
add_action( 'wp_abilities_api_init', 'mcp_register_content_abilities' );
