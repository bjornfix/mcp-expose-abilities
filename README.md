# MCP Expose Abilities

Give an AI assistant WordPress tools it can use to finish real site work: inspect pages, correct selected content, organise media, update menus and check installed plugins.

[![Release 3.0.93](https://img.shields.io/badge/release-3.0.93-blue.svg)](https://downloads.devenia.com/mcp-expose-abilities.zip)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://www.php.net/)

**Stable tag:** 3.0.93<br>
**Tested up to:** 7.1<br>
**License:** GPL-2.0-or-later<br>
**Tags:** mcp, ai, automation, content, rest-api

## What It Does

MCP Expose Abilities supplies 79 WordPress operations through the WordPress Abilities API. With WordPress MCP Adapter and a compatible authenticated client, your assistant can read the site's actual records and make the changes you request.

For example, ask it to find a named set of articles, inspect their contact details, and turn the selected phone numbers into clickable links. The assistant can read each result back and report which pages changed. The plugin supplies the operations; your client handles the conversation, task selection and review.

## The Real Workflow

1. Identify the exact site, pages and intended change.
2. Let the assistant read the current records and propose the affected items.
3. Review the scope and the effect of the proposed write.
4. Run the relevant WordPress operation and inspect the returned result.
5. Read the saved record and check the rendered page when appearance matters.

A useful first task is a single page correction. Once that works, the client can repeat the same narrow task across your selected pages. There is no fixed completion time or automatic quality guarantee.

## Why This Feels Different

The assistant can act on WordPress records as well as explain what to do. You can stay with one task from finding the problem through a saved change, instead of transferring every instruction into the admin screen yourself.

Each operation has a name, an input definition and a permission check. Content, menus and media remain native WordPress records, so the ordinary admin interface remains available for review and further editing.

## Before vs After

| Task | With the connected assistant |
|---|---|
| Find an outdated detail across selected pages | Search, inspect matches and apply a specified correction |
| Improve media descriptions | Read attachment details and update selected titles, captions or alternative text |
| Shorten menu labels | Change the label while preserving the existing target and stored position |
| Understand a site's plugin state | List installed plugins and available updates before choosing an action |
| Check a content change | Read the stored result and use the native revision tools when appropriate |

## Who It Is For

- Agencies maintaining several WordPress sites.
- Editorial teams with repeated content corrections.
- Site owners who want help with media, menus and routine maintenance.
- Developers building MCP workflows around native WordPress data.

## Modular Architecture

| Plugin | Abilities | Role |
|---|---|---|
| **MCP Expose Abilities** (core) | 79 | Supplies the WordPress content, media, menu and administration operations listed below |

WordPress MCP Adapter carries requests from the client to WordPress. The Abilities API registers and executes the operations. This plugin provides the core WordPress tools. Add-ons supply operations for specific builders or plugins; their own documentation defines their current coverage.

## Requirements

- WordPress 6.9 or later, which includes the WordPress Abilities API.
- PHP 8.0 or later.
- The standalone [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter/).
- An MCP-compatible client with an authenticated connection to your WordPress site.
- Gutenberg block-content writes require [MCP Abilities Block Editor](https://devenia.com/plugins/mcp-abilities-block-editor/) for syntax checks. These checks do not replace validation in the native Gutenberg editor.

MCP transport access defaults to the WordPress `manage_options` capability. Each requested operation also checks its own permissions. Changing the transport capability with `mcp_expose_mcp_transport_capability` does not grant the underlying WordPress permissions.

## Documentation

- [Plugin overview and connection guide](https://devenia.com/plugins/mcp-expose-abilities/)
- [Stable ZIP download](https://downloads.devenia.com/mcp-expose-abilities.zip)
- [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)
- [WordPress MCP Adapter documentation](https://github.com/WordPress/mcp-adapter/)

## Start Here

1. Confirm that WordPress and PHP meet the requirements.
2. Install and activate WordPress MCP Adapter and MCP Expose Abilities.
3. Configure your client's authenticated connection using the adapter's instructions.
4. Discover the available operations and read one named page with `content/get-page`.
5. For block-content editing, install the Block Editor add-on. Add builder-specific tools when the site uses that builder.
6. Review a small change, execute it and read the saved result before expanding the task.

If discovery fails, check authentication and the adapter connection first. If one operation fails, inspect its required input, permissions and dependencies. A working read proves that connection for that operation; it does not prove that every write or add-on is available.

## Core Plugin Abilities (79)

### Content Management (27)

| Ability | Description |
|---------|-------------|
| `content/list-posts` | List posts with filtering by status, category, author, search |
| `content/get-post` | Get single post by ID or slug |
| `content/get-next-post` | Find the next existing post after an ID, even when IDs have gaps |
| `content/create-post` | Create new post, including `featured_image_id` |
| `content/update-post` | Update an existing post, including guarded or explicit full-rebuild content replacement and `featured_image_id` |
| `content/delete-post` | Delete post (trash or permanent) |
| `content/restore-post` | Restore a post, page, or custom post type from trash |
| `content/patch-post` | Find/replace in post content |
| `content/list-pages` | List pages with filtering |
| `content/get-page` | Get single page by ID or slug |
| `content/create-page` | Create new page, including `featured_image_id` |
| `content/update-page` | Update an existing page, including guarded or explicit full-rebuild content replacement and `featured_image_id` |
| `content/update-discussion-status` | Open or close comments and pings for posts/pages |
| `content/delete-page` | Delete page |
| `content/patch-page` | Find/replace in page content |
| `content/list-categories` | List all categories |
| `content/create-category` | Create new category |
| `content/update-category` | Update existing category |
| `content/list-tags` | List all tags |
| `content/create-tag` | Create new tag |
| `content/list-media` | List media items |
| `content/list-users` | List users |
| `content/search` | Search across posts, pages, media |
| `content/list-revisions` | List revisions for a post/page |
| `content/get-revision` | Get specific revision details |
| `content/restore-revision` | Restore one exact post or page revision |
| `content/update-tag` | Update an existing tag name, slug, or description |

### Menu Management (8)

| Ability | Description |
|---------|-------------|
| `menus/list` | List all menus and theme locations |
| `menus/get-items` | Get items from a menu |
| `menus/create` | Create new menu |
| `menus/add-item` | Add item to menu |
| `menus/update-item` | Update menu item |
| `menus/upsert-item` | Create or update an item by object identity or custom URL |
| `menus/delete-item` | Delete menu item |
| `menus/assign-location` | Assign menu to theme location |

### User Management (7)

| Ability | Description |
|---------|-------------|
| `users/list` | List users with roles |
| `users/get` | Get user by ID, login, or email |
| `users/create` | Create new user |
| `users/update` | Update user |
| `users/delete` | Delete user (can reassign content) |
| `users/create-restricted-application-password` | Create one sealed Application Password after explicit confirmation for an exact editable user without generic WordPress Core write authority |
| `users/revoke-current-application-password` | Revoke only the Application Password authenticating the current request after explicit confirmation and deletion readback |

### Media Library (5)

| Ability | Description |
|---------|-------------|
| `media/upload` | Upload media from URL |
| `media/upload-base64` | Upload JPEG, PNG, WebP, GIF, PDF or MP4 media from base64-encoded bytes |
| `media/get` | Get media item details and sizes |
| `media/update` | Update title, alt, caption |
| `media/delete` | Delete media item |

### Post Meta (3)

| Ability | Description |
|---------|-------------|
| `meta/get-post-meta` | Read one exact post meta key |
| `meta/update-post-meta` | Update one permitted post meta key |
| `meta/delete-post-meta` | Delete one permitted post meta key |

### Widget Management (3)

| Ability | Description |
|---------|-------------|
| `widgets/list-sidebars` | List all widget areas |
| `widgets/get-sidebar` | Get widgets in a sidebar |
| `widgets/list-available` | List available widget types |

### Plugin Management (11)

| Ability | Description |
|---------|-------------|
| `plugins/upload` | Upload plugin from URL |
| `plugins/upload-base64` | Upload plugin from local file (base64 or zip path) |
| `plugins/search-directory` | Search the official WordPress.org plugin directory |
| `plugins/install-directory` | Install plugin from the official WordPress.org plugin directory by slug |
| `plugins/list` | List installed plugins |
| `plugins/list-updates` | List available plugin updates |
| `plugins/update` | Update an installed plugin |
| `plugins/activate` | Activate installed plugin |
| `plugins/deactivate` | Deactivate active plugin |
| `plugins/switch` | Activate one plugin and deactivate one or more others |
| `plugins/delete` | Delete inactive plugin |

### Comments (7)

| Ability | Description |
|---------|-------------|
| `comments/list` | List comments with filtering |
| `comments/get` | Get single comment details |
| `comments/update-author-url` | Update or clear one comment author URL |
| `comments/create` | Create top-level comment |
| `comments/reply` | Reply to existing comment |
| `comments/update-status` | Update comment status (approve, spam, trash) |
| `comments/delete` | Delete comment |

### Options (3)

| Ability | Description |
|---------|-------------|
| `options/get` | Get option value |
| `options/update` | Update option (protected options blocked) |
| `options/list` | List all options |

### System (4)

| Ability | Description |
|---------|-------------|
| `system/get-transient` | Get transient value |
| `system/ability-timings` | Read recent slow or failed ability timings |
| `system/debug-log` | Read debug.log file |
| `system/toggle-debug` | Toggle WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY |

### Taxonomy Utilities (1)

| Ability | Description |
|---------|-------------|
| `taxonomy/associate-with-post-type` | Associate a taxonomy with a post type and persist the mapping |

## Add-on Plugin Abilities

Choose add-ons for the work and software on your site. Each one has its own requirements and operation inventory:

- [Block Editor](https://devenia.com/plugins/mcp-abilities-block-editor/) for Gutenberg parsing, validation and editing.
- [GeneratePress and GenerateBlocks](https://devenia.com/plugins/mcp-abilities-generatepress/) for the native theme and block design tools.
- [Elementor](https://devenia.com/plugins/mcp-abilities-elementor/) for Elementor documents.
- [WooCommerce](https://devenia.com/plugins/mcp-abilities-for-woocommerce/) for shop products, orders, stock and settings.
- [All public plugin pages](https://devenia.com/plugins/) for other integrations.

## Usage Examples

### Read one page

```json
{
  "ability": "content/get-page",
  "input": { "id": 123 }
}
```

### Update an attachment's alternative text

```json
{
  "ability": "media/update",
  "input": {
    "id": 456,
    "alt_text": "A blue ceramic mug beside its shipping box"
  }
}
```

Choose the real attachment ID from a prior read and describe what the image actually shows. Then read the attachment again to verify the saved text.

### Change a menu label

```json
{
  "ability": "menus/update-item",
  "input": {
    "menu_id": 12,
    "item_id": 34,
    "title": "Our services"
  }
}
```

Use `menus/list` to find the active theme location, then `menus/get-items` to identify the exact item. Omitting `position` preserves the stored order. Supplying a position requests a move in the menu's flat order; parent relationships remain separate.

## Safety and Ownership Boundaries

WordPress remains the authority for authentication, records and permissions. The plugin does not include an AI model or decide whether a requested editorial change is good. The client and operator must review the task and result.

- Some operations require an exact `confirm_dangerous_action` value. Read the operation's input definition; not every write has this field.
- Gutenberg writes use the Block Editor validator. Explicit `full_rebuild` means intentional replacement, not permission to skip validation.
- Protected builder metadata must use its dedicated builder tools. Generic post metadata writes enforce their key policy and WordPress permissions.
- Plugin upload and deletion require the server-side `MCP_EXPOSE_ENABLE_PLUGIN_CODE_WRITES` opt-in. Confirmed WordPress.org directory installs use the native directory installer; plugin updates require the code-write opt-in or an approved update-policy adapter.
- Restricted Application Password creation accepts only an eligible user and returns the secret encrypted for the supplied recipient key. Revocation affects only the Application Password authenticating the current request.
- Site-owned content policies may reject a write or keep content as a draft. Inspect the actual returned status and saved record.

## Installation


For update notifications in WordPress, install [Devenia MCP Updater](https://downloads.devenia.com/devenia-mcp-updater.zip). The updater is optional. You choose which plugins update automatically through WordPress.

Download the stable ZIP. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, select the file and activate it. Complete the requirements and connection steps above.

With WP-CLI, after downloading the ZIP:

```bash
wp plugin install mcp-expose-abilities.zip --activate
```

## Recent Changes


### 3.0.93

Upload MP4 videos through `media/upload-base64`, with native WordPress file validation and attachment metadata.

### 3.0.92

Add one dismissible Plugins-screen reminder when Devenia MCP Updater is missing or inactive, with persistent install or activate links. Automatic updates remain your choice in WordPress.

### 3.0.91

- Preserve exact WordPress option names, including dots and letter case, when reading or updating settings.
- Declare the supported option value types for WordPress schema validation.
- Keep protected settings and credential names blocked across punctuation-separated names.

### 3.0.89

- Use the Block Editor syntax checks without requiring a separate validation process.
- Preserve escaped block attributes and reject malformed block syntax before storage.

### 3.0.87

- Added syntax checks to generic block-content writes and preserved escaped block attributes through WordPress storage.
- Preserved menu targets and stored positions during label-only updates, including upserts.
- Made explicit menu-position changes move the surrounding items and corrected custom-link readback checks.
- Allowed MCP requests to use WordPress's admin memory allowance.
- Updated setup guidance, requirements and practical examples.

### 3.0.86

- Read back saved post and page status instead of reporting publication success when site policy retained a draft.

### 3.0.85

- Required page edit permission for ordinary page updates.

### 3.0.84

- Loaded the native Screen API for plugin updates initiated outside wp-admin.

## Contributing

Provide a reproducible case, the affected operation and the expected result. Keep changes within native WordPress behaviour and include a focused test for the corrected public operation.

## License

[GNU General Public License v2.0 or later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).

## Author

[basicus](https://profiles.wordpress.org/basicus/)

## Links

- [Plugin page](https://devenia.com/plugins/mcp-expose-abilities/)
- [Stable download](https://downloads.devenia.com/mcp-expose-abilities.zip)
- [Source mirror](https://github.com/bjornfix/mcp-expose-abilities)
