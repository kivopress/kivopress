# Kivopress

Kivopress is a lightweight PHP publishing system with a native admin panel, clean content model, REST API, themes, plugins, media, users, roles, menus, SEO tooling, and extensible custom fields.

It is built to keep the runtime small and understandable while still giving developers the core pieces expected from a modern content platform.

## Project Status

Kivopress is early-stage software. The current runtime is usable for local development, prototypes, small content sites, and plugin/theme experimentation. Public APIs may still evolve before a stable `1.0` release.

Current core version: `0.2.0`

## Highlights

- Lightweight PHP runtime with no required framework dependency.
- Native admin dashboard for content, media, users, themes, plugins, menus, settings, REST routes, and diagnostics.
- Unified `content` table for posts, pages, and custom content types.
- Native `content_meta` and `user_meta` tables for custom fields and ACF-style plugin data.
- Custom taxonomies with hierarchical and flat term support.
- First-class REST API under `/api`.
- API token authentication with hashed tokens, expiration, revocation, and rotation.
- Theme system with template hierarchy, page templates, static front pages, hook points, and admin bar injection.
- Plugin system with manifests, activation state, admin menus, hooks, REST routes, custom fields, and custom tables.
- Media library with upload, metadata editing, public media serving, and image dimensions.
- Roles and capabilities for admin, editor, author, and subscriber-style access control.
- SEO plugin included with meta fields, social tags, robots rules, XML sitemaps, image sitemaps, and sitemap refresh hooks.
- Error handler with event IDs and admin diagnostics.
- File storage mode for simple local installs and MySQL mode for production-style deployments.
- PHP-first configuration through `kp-config.php` and local overrides in `kp-content/config.php`.

## Requirements

- PHP 8.2 or newer
- PDO
- MySQL with `pdo_mysql` for MySQL installs, or file storage for lightweight local installs
- Apache, Nginx, or PHP's built-in server for local development

Recommended production setup:

- PHP 8.2+
- MySQL 8+ or MariaDB 10.6+
- HTTPS
- Apache/Nginx rules that keep private runtime files unreachable from the web

## Quick Start

Clone the repository and point your local web server at the project root:

```sh
git clone https://github.com/kivopress/kivopress.git
cd kivopress
```

Open the site in your browser. When `kp-content/config.php` does not exist, Kivopress shows the installer.

The installer writes local runtime settings to:

```text
kp-content/config.php
```

That file is intentionally ignored by Git because it can contain local database settings.

## Configuration

Default configuration lives in:

```text
kp-config.php
```

Local install configuration lives in:

```text
kp-content/config.php
```

Kivopress does not require `.env` loading. Use PHP constants or the local config file.

Common constants:

```php
define('KIVOPRESS_DEBUG', false);
define('KIVOPRESS_TIMEZONE', 'UTC');
define('DB_CONNECTION', 'mysql');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'kivopress');
define('DB_USER', 'kivopress');
define('DB_PASSWORD', '');
define('KIVOPRESS_THEME', 'default');
```

The default table prefix is:

```php
$table_prefix = 'kp_';
```

## Repository Layout

```text
.
├── index.php                  Front controller
├── kp-load.php                Runtime bootstrap
├── kp-config.php              Default configuration
├── kp-admin/                  Admin UI, controllers, templates, assets
├── kp-includes/               Core runtime classes and helper APIs
└── kp-content/
    ├── themes/default/        Bundled default theme
    └── plugins/seo/           Bundled SEO plugin
```

Ignored local/runtime paths include config overrides, logs, cache, uploads, generated data, database dumps, private management files, and dependency/build outputs.

## Core Features

### Content Management

Kivopress ships with posts and pages out of the box. Plugins can register custom content types using the same runtime content system.

Content supports:

- title, slug, body, excerpt, status, author, publish time
- draft and published status
- unique slugs
- custom fields
- featured images
- taxonomy terms
- pagination, search, status filters, and ordering
- static front page selection
- permalink settings

### Custom Fields

Kivopress stores custom fields in `content_meta`, not in separate post-specific tables.

Use update helpers for one current value:

```php
kp_update_content_meta($contentId, 'subtitle', 'Fast by default');
$subtitle = kp_get_content_meta($contentId, 'subtitle', true);
```

Use add helpers for repeatable fields:

```php
kp_add_content_meta($contentId, 'gallery_image', $imageId);
$gallery = kp_get_content_meta($contentId, 'gallery_image');
```

Plugins can also register fields so they appear in admin forms and REST payloads:

```php
register_fields('product', [
    'price' => ['type' => 'number', 'label' => 'Price'],
    'gallery_image' => ['type' => 'media', 'label' => 'Gallery Image'],
]);
```

### Taxonomies

Taxonomies can be hierarchical or flat. The default runtime registers:

- `category` for posts
- `post_tag` for posts

Plugins can register additional taxonomies for any content type.

### Media Library

The media system supports:

- file uploads
- media metadata
- image dimensions
- alt text
- captions
- public media URLs
- admin media browser
- media field integration for content

### Menus

Kivopress includes native menu locations and a menu editor.

Default locations:

- `primary`
- `footer`

Themes can render menus, and plugins can register or modify admin menu items.

### Users, Roles, And Capabilities

The admin includes user management, role assignment, and capability checks.

Built-in capabilities cover:

- content editing and publishing
- page management
- media upload and management
- user management
- settings management
- theme and plugin management
- API token access

Passwords are hashed with PHP's `password_hash()`.

### REST API

Kivopress exposes native REST routes under `/api`.

Common routes:

```text
GET    /api
GET    /api/kp/v1
GET    /api/kp/v1/posts
POST   /api/kp/v1/posts
GET    /api/kp/v1/posts/{id-or-slug}
PATCH  /api/kp/v1/posts/{id}
DELETE /api/kp/v1/posts/{id}
GET    /api/kp/v1/pages
GET    /api/kp/v1/media
POST   /api/kp/v1/media
GET    /api/kp/v1/taxonomies
GET    /api/kp/v1/taxonomies/{taxonomy}
```

Mutating routes require an API token and the relevant capability:

```http
Authorization: Bearer TOKEN
```

or:

```http
X-Kivopress-Token: TOKEN
```

API tokens are created from the admin settings screen. Tokens are shown once, hashed before storage, and can expire, rotate, or be revoked.

### Themes

Themes live in:

```text
kp-content/themes/{theme-slug}/
```

The bundled default theme includes:

- home/front page templates
- post and page templates
- archive/search/taxonomy templates
- 404 template
- page templates for landing and canvas pages
- partials for header, footer, pagination, and post cards
- responsive CSS

Theme manifests use `theme.json`:

```json
{
  "name": "Kivopress Default",
  "version": "1.1.0",
  "author": "Kivopress"
}
```

### Plugins

Plugins live in:

```text
kp-content/plugins/{plugin-slug}/
```

Plugins can:

- register metadata
- add admin pages
- register fields and content types
- add REST routes
- add middleware
- add hooks and filters
- create custom tables
- enqueue admin assets
- add theme output

Minimal plugin example:

```php
<?php

plugin([
    'name' => 'Example Plugin',
    'version' => '1.0.0',
    'author' => 'Your Name',
]);

add_action('admin_menu', function () {
    register_admin_menu([
        'href' => '/admin/example',
        'label' => 'Example',
        'capability' => 'manage_settings',
        'icon' => 'extension',
    ]);
});
```

### Bundled SEO Plugin

Kivopress includes an SEO plugin with:

- SEO title and meta description fields
- canonical URL field
- noindex field
- sitemap image field
- social meta tags
- robots.txt
- XML sitemap index
- post and page sitemaps
- image sitemap entries
- admin SEO settings
- sitemap refresh on content and settings changes

## Database Model

The runtime schema is intentionally compact:

```text
options
content
content_meta
taxonomies
terms
term_relationships
users
user_meta
api_tokens
media
```

Plugin tables should use the configured table prefix:

```php
$table = db_table('orders');

db_delta('orders', [
    'id' => ['type' => 'id'],
    'content_id' => ['type' => 'integer'],
    'status' => ['type' => 'string', 'length' => 50],
    'created_at' => ['type' => 'datetime'],
]);
```

## Security Features

- Admin authentication with session-based login.
- CSRF validation for admin forms.
- Password hashing with `password_hash()`.
- API token hashing with indexed lookup hashes.
- Token expiration, revocation, and rotation.
- Capability checks around admin screens and API mutations.
- Optional REST rate limiting.
- CORS headers for API routes.
- Output escaping helpers for templates.
- Runtime error capture with event IDs.
- Private directories protected by shipped `.htaccess` files on Apache.
- Local config, logs, cache, data, uploads, dumps, and backups ignored by Git.

## Performance And Benchmarks

Kivopress is designed around a small runtime, compact schema, direct routing, lightweight hooks, batched content hydration, and indexed lookup paths.

### Runtime Microbenchmark

The following benchmark was run locally against the shipped runtime using PHP CLI, file storage, 100 seeded published posts, warm in-process requests, and no web server or network overhead.

Environment:

- Date: 2026-06-05
- PHP: 8.2.27
- SAPI: CLI
- Storage: file mode
- Seed data: 100 posts
- Boot time: 33.70 ms

| Scenario | Iterations | Response | Median | p95 | Average |
| --- | ---: | ---: | ---: | ---: | ---: |
| Homepage theme render | 300 | 12.6 KB | 1.25 ms | 1.43 ms | 1.28 ms |
| REST posts collection | 500 | 12.4 KB | 0.31 ms | 0.35 ms | 0.32 ms |
| REST single post by slug | 500 | 1.0 KB | 0.09 ms | 0.09 ms | 0.09 ms |
| REST API index | 500 | 12.9 KB | 0.04 ms | 0.05 ms | 0.04 ms |

These are development-machine measurements, not a production guarantee. Real HTTP benchmarks depend on CPU, PHP SAPI, OPcache, web server, filesystem, MySQL latency, TLS, plugins, theme complexity, and cache strategy.

### Local WordPress HTTP Comparison

The following comparison was run over local HTTP virtual hosts against Kivopress and a local WordPress install on the same machine.

Method:

- 200 measured requests per target and scenario
- 10 concurrent requests
- 20 warmup requests
- 30 second request timeout
- HTTP only, no TLS
- Kivopress routes use native `/api`
- WordPress REST routes use `?rest_route=/wp/v2/...`

| Scenario | Kivopress Median | WordPress Median | Kivopress p95 | WordPress p95 | Median Speedup |
| --- | ---: | ---: | ---: | ---: | ---: |
| Homepage | 41.84 ms | 349.56 ms | 53.82 ms | 387.99 ms | 8.4x |
| REST posts collection | 35.29 ms | 304.56 ms | 54.88 ms | 325.16 ms | 8.6x |
| REST pages collection | 35.68 ms | 321.25 ms | 53.21 ms | 349.69 ms | 9.0x |
| API discovery | 35.87 ms | 298.84 ms | 45.52 ms | 316.93 ms | 8.3x |

Average response sizes in the same run:

| Scenario | Kivopress | WordPress |
| --- | ---: | ---: |
| Homepage | 4.0 KB | 66.7 KB |
| REST posts collection | 1.5 KB | 2.0 KB |
| REST pages collection | 1.2 KB | 3.1 KB |
| API discovery | 12.9 KB | 187.8 KB |

This is a local benchmark, not a universal claim about every WordPress site. WordPress performance varies heavily by theme, plugins, object cache, page cache, OPcache, database, server configuration, and REST payload shape. The result is useful as a development baseline for Kivopress' lightweight runtime.

To benchmark a deployed site, use an HTTP tool such as ApacheBench, `wrk`, or `autocannon`:

```sh
ab -n 1000 -c 20 https://example.com/api/kp/v1/posts?per_page=10
```

## Deployment Notes

Before deploying:

- Set `KIVOPRESS_DEBUG` to `false`.
- Use HTTPS.
- Keep `kp-content/config.php` private.
- Ensure uploads, cache, logs, and data directories are writable by PHP.
- Use MySQL for production-style multi-user deployments.
- Confirm API rate limiting if the site exposes public API traffic.
- Confirm `.htaccess` or equivalent Nginx rules protect private PHP/runtime files.
- Keep database dumps and backups outside the repository.

## What Should Not Be Committed

The repository is configured to ignore:

- local config files
- logs
- cache
- uploads
- local data stores
- database dumps
- backups
- private management workspace files
- generated reports
- dependency folders
- build outputs
- editor and OS files
- environment files

## License

Kivopress is open source software licensed under the MIT license.
