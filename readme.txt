=== Sikora Block Author Enumeration ===
Contributors: sikoracollective
Tags: security, author enumeration, rest-api, xml-rpc, privacy
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Blocks author enumeration via ?author= URLs, REST users, oEmbed, sitemaps, and XML-RPC, while keeping admin author filters working.

== Description ==

Sikora Block Author Enumeration stops bots from discovering WordPress usernames through common enumeration vectors, without breaking the block editor or normal author archives.

= The problem =

By default, WordPress redirects `https://example.com/?author=1` to `https://example.com/author/<username>/`. By incrementing the number, bots can collect every valid username on a site, then use them in brute-force or credential-stuffing attacks against `wp-login.php`.

The REST API, oEmbed, the users sitemap, and XML-RPC author methods give away the same information through other doors.

= What this plugin does =

* **Blocks `?author=` and `?author_name=`** on the front end with a `301` redirect to the homepage. Values are never read or output; presence is enough. Encoded and array forms such as `?author=1a`, `?author=1,2`, and `?author[]=1` are blocked too. Pretty permalink author archives (`/author/jane/`) keep working.
* **Hides REST `/wp/v2/users` routes** from anyone without the `edit_posts` capability (anonymous visitors and subscribers get `404`). Authors, editors, and administrators keep the routes for the block editor. Users without `list_users` only see authors with REST-visible published posts, and `slug` / `link` are stripped from responses.
* **Removes leaking oEmbed fields.** `author_url` is always removed. `author_name` is removed when it matches the user's login or nicename (case-insensitive). Distinct display names are left in place.
* **Disables the core users sitemap** so `/wp-sitemap-users-1.xml` is not generated.
* **Removes XML-RPC listing methods** `wp.getUsers` and `wp.getAuthors`. Other XML-RPC features (including `wp.getUsersBlogs` for mobile apps) stay available.
* **Rewrites admin author filter links** from `author=<id>` to `author_name=<slug>` on `edit.php` and `upload.php`, so host firewalls that block `?author=` no longer return `403` for administrators.

= What it does not affect =

The front-end block runs on `template_redirect` (priority 1), so these stay untouched:

* The WordPress admin dashboard
* `admin-ajax.php` and WP-Cron
* The REST API (for example `/wp-json/wp/v2/posts?author=1` used by the block editor)

= Other enumeration vectors (not covered) =

This plugin does not disable public author archives. Usernames or slugs can also be exposed through:

* Author archive URLs such as `/author/<slug>/` (left working on purpose)
* Author archive links or body classes in themes
* Login error messages that distinguish bad usernames from bad passwords

For full coverage, combine this plugin with a firewall or security plugin where needed. Also make sure each user's **Display name** differs from their login username.

= Security notes =

* Exits immediately if the file is accessed directly (outside WordPress).
* Never reads, outputs, or stores the `author` or `author_name` value; it only checks whether the parameter is present (including via the raw query string).
* Uses `wp_safe_redirect()`, which only redirects to the site's own host.
* Admin author-link rewrites require `edit_posts` or `upload_files`, and only apply to same-site `wp-admin` `edit.php` / `upload.php` URLs. Rewritten links are escaped with `esc_url()`.
* Includes an `index.php` file to discourage directory listing of the plugin folder.

There are no settings. The plugin works as soon as it is activated.

== Installation ==

= Upload through the dashboard =

1. Download the plugin zip.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the zip, click **Install Now**, then **Activate**.

= Manual (FTP/SFTP) =

1. Copy the `sikora-block-author-enumeration` folder into `wp-content/plugins/`.
2. Activate it under **Plugins** in the dashboard.

== Frequently Asked Questions ==

= Will this break the block editor? =

No. REST requests such as `/wp-json/wp/v2/posts?author=1` are not redirected. Users who can edit posts still have access to the users routes the author selector needs.

= Do author archive pages still work? =

Yes. Pretty permalinks like `/author/jane/` are left alone on purpose.

= Why do my admin "filter by author" links use author_name? =

Some hosts (including SiteGround) block URLs that contain `author=` followed by a number before WordPress loads. The plugin rewrites those admin links to `author_name=<slug>` so the list still filters correctly.

= What if `?author=1` returns 403 instead of 301? =

Your host's firewall is blocking the request before WordPress runs. That still protects you. Try `?author=1a` to reach the plugin.

= How can I verify it works? =

Use the automated test runner in the `tests` directory. From the plugin root:

`./tests/run-tests.sh https://example.com`

The site URL is required. An author slug is optional. The script runs the checks documented in `tests/TESTING.md`, prints pass/fail results, and writes `tests/test-report.html`. Prefer your site's canonical URL (for example `https://www.example.com`). See `tests/TESTING.md` for accepted status codes, optional flags, and notes about host firewalls or SEO plugins.

== Changelog ==

= 2.2.0 =
* Adds `tests/run-tests.sh` and `tests/TESTING.md` for automated verification with an HTML report.
* FAQ verification steps now point to the test runner instead of duplicating curl commands.
* Plugin display name is Sikora Block Author Enumeration (without the Security suffix).

= 2.1.0 =
* Disables the core users sitemap provider.
* Removes XML-RPC `wp.getUsers` and `wp.getAuthors` methods.
* Strips oEmbed `author_name` when it matches the login or nicename.
* Hardens REST user responses: without `list_users`, limit queries to published authors and remove `slug` / `link`.
* Detects author parameters in the raw `QUERY_STRING` (encoded / `[]` forms).
* Admin author-link rewrites require `edit_posts` or `upload_files`.
* Uses plain-text plugin Author plus Author URI instead of HTML in the header.

= 2.0.0 =
* Blocks front-end `?author_name=` requests the same way as `?author=`, closing a slug-based enumeration bypass.
* Restricts REST `/wp/v2/users` routes to users with `edit_posts`, so subscribers can no longer enumerate usernames.
* Admin author-link rewrites only apply to same-site `wp-admin` `edit.php` / `upload.php` URLs.

= 1.3.0 =
* Rewrites admin author filter links (`edit.php` and `upload.php`) from `author=<id>` to `author_name=<slug>`, so host firewalls that block `?author=` no longer return a 403.

= 1.2.0 =
* Removes `author_url` from oEmbed responses.

= 1.1.0 =
* Hides the `/wp/v2/users` REST API routes from visitors who aren't logged in.

= 1.0.1 =
* Fixed bypasses (`?author=1a`, `?author=1,2`, `?author[]=1`) by blocking every request with an `author` parameter.
* Moved from `init` to `template_redirect` so REST API requests, and with them the block editor, are no longer redirected.
* Switched to `wp_safe_redirect()`.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.2.0 =
Adds an automated test runner under tests/. Verification docs point there; no settings changes.

= 2.1.0 =
Hardens REST, oEmbed, sitemaps, and XML-RPC author listing. No settings to configure; activate and go.
