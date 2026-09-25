# Sikora Block Author Enumeration (Security)

A lightweight WordPress plugin that stops **author enumeration** attacks by redirecting any front-end request containing an `?author=` parameter to the homepage.

- **Version:** 1.0.1
- **Requires WordPress:** 5.0+
- **Requires PHP:** 7.0+
- **License:** GPL-2.0-or-later
- **Author:** [Sikora Collective](https://sikoracollective.com/)

## The problem

By default, WordPress redirects `https://example.com/?author=1` to `https://example.com/author/<username>/`. By incrementing the number, bots can collect every valid username on a site, then use them in brute-force or credential-stuffing attacks against `wp-login.php`.

## What this plugin does

On every front-end page load, if the request contains an `author` parameter (in the query string or POST body), the plugin sends a `301` redirect to the site homepage. No username is revealed.

It blocks the parameter **whatever its value**, because WordPress converts the value to an integer. That means variants like these also resolve to user IDs, and a plain numeric check would miss them:

| Request            | Blocked |
| ------------------ | ------- |
| `/?author=1`       | ✅      |
| `/?author=1a`      | ✅      |
| `/?author=1,2`     | ✅      |
| `/?author[]=1`     | ✅      |
| `/author/jane/`    | ❌ — normal author archives keep working |

### What it does *not* affect

The check runs on the `template_redirect` hook (priority 1, before WordPress's own canonical redirect). That hook only fires for front-end page loads, so these are untouched:

- The WordPress admin dashboard
- `admin-ajax.php` and WP-Cron
- The REST API. The block editor calls endpoints such as `/wp-json/wp/v2/posts?author=1` and needs them to work.

## Installation

### Upload through the dashboard
1. Download `sikora-block-author-enumeration.zip`.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the zip, click **Install Now**, then **Activate**.

### Manual (FTP/SFTP)
1. Copy the `sikora-block-author-enumeration` folder into `wp-content/plugins/`.
2. Activate it under **Plugins** in the dashboard.

There are no settings. The plugin works as soon as it is activated.

## Verifying it works

With the plugin active, run:

```bash
curl -sI "https://example.com/?author=1"
```

You should see `HTTP/... 301` with `Location: https://example.com/`, **not** a `/author/<username>/` URL.

## Other enumeration vectors (not covered)

This plugin blocks only the `?author=` vector. Usernames or user slugs can also be exposed through:

- **REST API:** `/wp-json/wp/v2/users`
- **Core sitemaps:** `/wp-sitemap-users-1.xml` (WordPress 5.5+)
- **oEmbed responses** and **author archive links/classes** in themes

For full coverage, combine this plugin with a firewall or security plugin that restricts those endpoints. Also make sure each user's **Display name** differs from their login username.

## Security notes

- Exits right away if the file is accessed directly (outside WordPress).
- Never reads, outputs or stores the `author` value. It only checks whether the parameter is present.
- Uses `wp_safe_redirect()`, which only redirects to the site's own host.
- Includes an `index.php` file to stop directory listing of the plugin folder.

## Changelog

### 1.0.1
- Fixed bypasses (`?author=1a`, `?author=1,2`, `?author[]=1`) by blocking every request with an `author` parameter.
- Moved from `init` to `template_redirect` so REST API requests, and with them the block editor, are no longer redirected.
- Switched to `wp_safe_redirect()`.
- Added standard plugin headers (license, requirements, text domain).

### 1.0.0
- Initial release.
