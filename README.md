# MCP Expose Abilities

Let AI assistants edit your WordPress site via MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-expose-abilities)](https://github.com/bjornfix/mcp-expose-abilities/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 6.9
**Stable tag:** 3.0.17
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

This plugin exposes WordPress functionality through MCP (Model Context Protocol), enabling AI assistants to directly interact with your WordPress site. No more copy-pasting between chat and admin.

**Example:** "Fix the phone numbers in these 25 articles to be clickable tel: links." - Done in 30 seconds, all 25 articles.

## Modular Architecture

Version 3.0 introduced a modular architecture. The core plugin provides WordPress-native abilities, while vendor-specific features are available as separate add-on plugins:

| Plugin | Abilities | Description |
|--------|-----------|-------------|
| **MCP Expose Abilities** (core) | 51 | WordPress core: content, menus, users, media, widgets, plugins, options, system |
| [MCP Abilities - Filesystem](https://github.com/bjornfix/mcp-abilities-filesystem) | 10 | File operations with security hardening |
| [MCP Abilities - Elementor](https://github.com/bjornfix/mcp-abilities-elementor) | 36 | Elementor page builder integration |
| [MCP Abilities - GeneratePress](https://github.com/bjornfix/mcp-abilities-generatepress) | 25 | GeneratePress theme + GenerateBlocks |
| [MCP Abilities - Cloudflare](https://github.com/bjornfix/mcp-abilities-cloudflare) | 1 | Cloudflare cache management |
| [MCP Abilities - Google Workspace](https://github.com/bjornfix/mcp-abilities-workspace) | 8 | Gmail API via Workspace service account |
| [MCP Abilities - Rank Math](https://github.com/bjornfix/mcp-abilities-rankmath) | 3 | Rank Math SEO metadata access |
| [MCP Abilities - Wordfence](https://github.com/bjornfix/mcp-abilities-wordfence) | 8 | Wordfence security status + blocks |
| [MCP Abilities - Brevo](https://github.com/bjornfix/mcp-abilities-brevo) | 12 | Brevo contacts, lists, campaigns |
| [MCP Abilities - Advanced Ads](https://github.com/bjornfix/mcp-abilities-advads) | 9 | Advanced Ads management |

**Total ecosystem: 163 abilities**

Install only what you need. Running GeneratePress? Install that add-on. Don't use Elementor? Skip it.

## Requirements

- WordPress 6.9+
- PHP 8.0+
- [Abilities API](https://github.com/WordPress/abilities-api) plugin (WordPress core team)
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin (WordPress core team)

## Installation

1. Install and activate the required plugins (Abilities API and MCP Adapter)
2. Download the latest release from [Releases](https://github.com/bjornfix/mcp-expose-abilities/releases)
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin
5. (Optional) Install add-on plugins for vendor-specific features

## Core Plugin Abilities (51)

### Content Management (21)

| Ability | Description |
|---------|-------------|
| `content/list-posts` | List posts with filtering by status, category, author, search |
| `content/get-post` | Get single post by ID or slug |
| `content/create-post` | Create new post |
| `content/update-post` | Update existing post |
| `content/delete-post` | Delete post (trash or permanent) |
| `content/patch-post` | Find/replace in post content |
| `content/list-pages` | List pages with filtering |
| `content/get-page` | Get single page by ID or slug |
| `content/create-page` | Create new page |
| `content/update-page` | Update existing page |
| `content/delete-page` | Delete page |
| `content/patch-page` | Find/replace in page content |
| `content/list-categories` | List all categories |
| `content/create-category` | Create new category |
| `content/list-tags` | List all tags |
| `content/create-tag` | Create new tag |
| `content/list-media` | List media items |
| `content/list-users` | List users |
| `content/search` | Search across posts, pages, media |
| `content/list-revisions` | List revisions for a post/page |
| `content/get-revision` | Get specific revision details |

### Menu Management (7)

| Ability | Description |
|---------|-------------|
| `menus/list` | List all menus and theme locations |
| `menus/get-items` | Get items from a menu |
| `menus/create` | Create new menu |
| `menus/add-item` | Add item to menu |
| `menus/update-item` | Update menu item |
| `menus/delete-item` | Delete menu item |
| `menus/assign-location` | Assign menu to theme location |

### User Management (5)

| Ability | Description |
|---------|-------------|
| `users/list` | List users with roles |
| `users/get` | Get user by ID, login, or email |
| `users/create` | Create new user |
| `users/update` | Update user |
| `users/delete` | Delete user (can reassign content) |

### Media Library (4)

| Ability | Description |
|---------|-------------|
| `media/upload` | Upload media from URL |
| `media/get` | Get media item details and sizes |
| `media/update` | Update title, alt, caption |
| `media/delete` | Delete media item |

### Widget Management (3)

| Ability | Description |
|---------|-------------|
| `widgets/list-sidebars` | List all widget areas |
| `widgets/get-sidebar` | Get widgets in a sidebar |
| `widgets/list-available` | List available widget types |

### Plugin Management (6)

| Ability | Description |
|---------|-------------|
| `plugins/upload` | Upload plugin from URL |
| `plugins/upload-base64` | Upload plugin from local file (base64 or zip path) |
| `plugins/list` | List installed plugins |
| `plugins/activate` | Activate installed plugin |
| `plugins/deactivate` | Deactivate active plugin |
| `plugins/delete` | Delete inactive plugin |

### Comments (6)

| Ability | Description |
|---------|-------------|
| `comments/list` | List comments with filtering |
| `comments/get` | Get single comment details |
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

### System (3)

| Ability | Description |
|---------|-------------|
| `system/get-transient` | Get transient value |
| `system/debug-log` | Read debug.log file |
| `system/toggle-debug` | Toggle WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY |

## Add-on Plugin Abilities

### Filesystem (mcp-abilities-filesystem) - 10 abilities

| Ability | Description |
|---------|-------------|
| `filesystem/get-changelog` | Get plugin/theme changelog |
| `filesystem/read-file` | Read file contents (security hardened) |
| `filesystem/write-file` | Write file (PHP code blocked) |
| `filesystem/append-file` | Append to file |
| `filesystem/list-directory` | List directory contents |
| `filesystem/delete-file` | Delete file (creates backup) |
| `filesystem/file-info` | Get file metadata |
| `filesystem/create-directory` | Create directory |
| `filesystem/copy-file` | Copy file |
| `filesystem/move-file` | Move/rename file |

### Elementor (mcp-abilities-elementor) - 36 abilities

See the add-on readme for the full list. Common abilities:

| Ability | Description |
|---------|-------------|
| `elementor/get-data` | Get Elementor JSON for a page |
| `elementor/update-data` | Replace Elementor JSON |
| `elementor/patch-data` | Find/replace in Elementor JSON |
| `elementor/update-element` | Update specific element by ID |
| `elementor/list-templates` | List saved templates |
| `elementor/clear-cache` | Clear CSS cache |

### GeneratePress (mcp-abilities-generatepress) - 25 abilities

See the add-on readme for the full list. Common abilities:

| Ability | Description |
|---------|-------------|
| `generatepress/get-settings` | Get theme settings |
| `generatepress/update-settings` | Update theme settings |
| `generatepress/get-typography` | Get typography rules and font manager |
| `generatepress/list-elements` | List GeneratePress Elements |
| `generatepress/list-modules` | List module statuses |
| `generateblocks/get-global-styles` | Get global styles |
| `generateblocks/update-global-styles` | Update global styles |
| `generateblocks/clear-cache` | Clear CSS cache |

### Cloudflare (mcp-abilities-cloudflare) - 1 ability

| Ability | Description |
|---------|-------------|
| `cloudflare/clear-cache` | Clear Cloudflare cache (entire site or specific URLs) |

### Google Workspace (mcp-abilities-workspace) - 8 abilities

| Ability | Description |
|---------|-------------|
| `gmail/configure` | Set up Gmail API service account credentials |
| `gmail/status` | Check API connection status and configuration |
| `gmail/list` | List inbox messages with filtering |
| `gmail/get` | Get full email content by ID |
| `gmail/send` | Send email with HTML, attachments, CC, BCC |
| `gmail/modify` | Modify labels (archive, mark read/unread, etc.) |
| `gmail/reply` | Reply to an existing email thread |
| `email/send` | Send email via WordPress wp_mail (non-Gmail fallback) |

## Usage with MCP Clients

### 1. Create Application Password

WordPress Admin → Users → Your Profile → Application Passwords

### 2. Add MCP Server

Configure your MCP client to connect to:

`https://yoursite.com/wp-json/mcp/mcp-adapter-default-server`

Use HTTP transport with a Basic Auth header generated from your WordPress username and application password.

### 3. Start Using

Your MCP client can now edit your WordPress site through conversation.

## Examples

### Create a new page

```json
{
  "ability_name": "content/create-page",
  "parameters": {
    "title": "About Us",
    "content": "<!-- wp:paragraph --><p>Hello world!</p><!-- /wp:paragraph -->",
    "status": "publish"
  }
}
```

### Add menu item

```json
{
  "ability_name": "menus/add-item",
  "parameters": {
    "menu_id": 5,
    "title": "Contact",
    "url": "/contact/"
  }
}
```

### Upload media from URL

```json
{
  "ability_name": "media/upload",
  "parameters": {
    "url": "https://example.com/image.jpg",
    "title": "Hero Image",
    "alt_text": "Beautiful sunset"
  }
}
```

### Batch find/replace

```json
{
  "ability_name": "content/patch-post",
  "parameters": {
    "id": 123,
    "find": "+44 203 3181 832",
    "replace": "<a href=\"tel:+442033181832\">+44 203 3181 832</a>"
  }
}
```

## Security

- **Authentication required** - Uses WordPress application passwords
- **Permission checks** - Every ability verifies user capabilities
- **Your server** - AI connects to your site, you control access
- **Protected options** - Critical settings blocked from modification
- **Filesystem hardening** - PHP code detection, path traversal protection (in add-on)

## Architecture

Three-plugin stack plus optional add-ons:

1. **[Abilities API](https://github.com/WordPress/abilities-api)** - Framework for registering abilities (WordPress core team)
2. **[MCP Adapter](https://github.com/WordPress/mcp-adapter)** - MCP protocol layer (WordPress core team)
3. **MCP Expose Abilities** (this plugin) - Core WordPress abilities
4. **Add-on plugins** (optional) - Vendor-specific abilities

## Changelog

### 3.0.17
- Fixed: Use literal text domain in translation calls
- Fixed: Add translators comments for placeholder strings

### 3.0.16
- Added: `include_totals` flag plus `has_more`/`returned` output for list-posts/list-pages/list-media to avoid expensive counts by default

### 3.0.15
- Added: plugins/upload-base64 now accepts `zip_path` for server-local zip installs
- Fixed: no-params abilities accept null input (menus/list, widgets/list-sidebars, widgets/list-available)

### 3.0.14
- Fixed: plugins/delete now loads core file helpers before deletion

### 3.0.13
- Added: Shared pagination normalization for core list abilities

### 3.0.12
- Fixed: plugins/upload now loads WordPress download helpers in non-admin contexts

### 3.0.11
* Added: plugins/upload-base64 ability for local file uploads

### 3.0.10
- Added: `content/create-category` ability

### 3.0.9
- Security: Added per-item capability checks for content, media, users, and comments

### 3.0.8
- Added: `plugins/activate` ability to activate installed plugins
- Added: `plugins/deactivate` ability to deactivate active plugins

### 3.0.7
- Improved: All 47 ability descriptions now include parameter hints

### 3.0.6
- Added: `comments/create` ability for top-level comments

### 3.0.5
- Added: `plugins/delete` ability to remove inactive plugins

### 3.0.4
- Fixed: Use WP_Filesystem API instead of native PHP functions
- Fixed: Replaced wp_get_sidebars_widgets with direct option call

### 3.0.3
- Added: Revisions abilities (`content/list-revisions`, `content/get-revision`)
- Added: Comments abilities (list, get, create, reply, update-status, delete)
- Added: `author_id` parameter for content creation

### 3.0.0
- **Breaking:** Modular architecture - vendor-specific abilities moved to add-on plugins
- Core plugin now contains only WordPress-native abilities
- Add-on plugins: Filesystem (10), Elementor (6), GeneratePress (5), Cloudflare (1), Google Workspace (8)
- Cleaner installation - install only what you need

### 2.2.12
- Security: Added protected options blocklist (active_plugins, siteurl, admin_email, etc.)
- Security: Prevents accidental site breakage via options/update

### 2.2.11
- Security: Added UTF-7 and UTF-16 encoding bypass detection
- Security: Blocks encoded PHP injection attempts

### 2.2.10
- Security: Major filesystem security hardening
- Security: PHP code detection in file writes
- Security: Path traversal protection
- Security: Restricted to wp-content directory

### 2.1.0
- Added: Filesystem abilities
- Added: Options abilities
- Added: System abilities
- Added: Cloudflare cache clear ability
- Added: `elementor/update-element` for targeted element updates

### 2.0.0
- Added: Menu, User, Media, Widget, Page abilities

### 1.0.0
- Initial release

## Contributing

PRs welcome! For vendor-specific abilities, consider creating an add-on plugin.

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
- [Abilities API](https://github.com/WordPress/abilities-api) (WordPress core team)
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) (WordPress core team)
