# MCP Expose Abilities

**Let AI assistants edit your WordPress site.** 54 abilities for content, menus, users, media, Elementor, and more.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-expose-abilities)](https://github.com/bjornfix/mcp-expose-abilities/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

## What It Does

This plugin exposes WordPress functionality through MCP (Model Context Protocol), enabling AI assistants like Claude to directly interact with your WordPress site. No more copy-pasting between chat and admin.

**Example:** "Fix the phone numbers in these 25 articles to be clickable tel: links." - Done in 30 seconds, all 25 articles.

## Requirements

- WordPress 6.9+
- PHP 8.0+
- [Abilities API](https://wordpress.org/plugins/abilities-api/) plugin (from WordPress.org)
- [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin (from WordPress.org)

## Installation

1. Install and activate the required plugins (Abilities API and MCP Adapter)
2. Download the latest release from [Releases](https://github.com/bjornfix/mcp-expose-abilities/releases)
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin

## All 54 Abilities

### Content Management (14)

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
| `content/list-tags` | List all tags |
| `content/search` | Search across posts, pages, media |

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

### Plugin Management (2)

| Ability | Description |
|---------|-------------|
| `plugins/upload` | Upload plugin from URL |
| `plugins/list` | List installed plugins |

### Elementor (5)

| Ability | Description |
|---------|-------------|
| `elementor/get-data` | Get Elementor JSON for a page |
| `elementor/update-data` | Replace Elementor JSON |
| `elementor/patch-data` | Find/replace in Elementor JSON |
| `elementor/list-templates` | List saved templates |
| `elementor/clear-cache` | Clear CSS cache |

### GeneratePress (2)

| Ability | Description |
|---------|-------------|
| `generatepress/get-settings` | Get theme settings |
| `generatepress/update-settings` | Update theme settings |

### GenerateBlocks (3)

| Ability | Description |
|---------|-------------|
| `generateblocks/get-global-styles` | Get global styles |
| `generateblocks/update-global-styles` | Update global styles |
| `generateblocks/clear-cache` | Clear CSS cache |

### Core (3)

| Ability | Description |
|---------|-------------|
| `core/get-site-info` | Site information |
| `core/get-user-info` | Current user profile |
| `core/get-environment-info` | PHP/DB/runtime info |

## Usage with Claude Code

### 1. Create Application Password

WordPress Admin → Users → Your Profile → Application Passwords

### 2. Add MCP Server

```bash
claude mcp add wordpress-mysite "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
  -t http \
  -H "Authorization: Basic $(echo -n 'username:application-password' | base64)"
```

### 3. Start Using

Now Claude can directly edit your WordPress site through conversation.

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

## Architecture

Three-plugin stack:

1. **[Abilities API](https://wordpress.org/plugins/abilities-api/)** - Framework for registering abilities
2. **[MCP Adapter](https://wordpress.org/plugins/mcp-adapter/)** - MCP protocol layer
3. **MCP Expose Abilities** (this plugin) - The actual abilities

## Contributing

PRs welcome! If you add useful abilities, share them.

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
- [Blog Post](https://devenia.com/we-built-a-wordpress-plugin-that-lets-ai-edit-your-site/)
- [Abilities API](https://wordpress.org/plugins/abilities-api/)
- [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/)
