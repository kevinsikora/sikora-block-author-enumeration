# Sikora Block Author Enumeration (Security)

A lightweight WordPress plugin that stops **author enumeration** attacks. It redirects any front-end request containing an `?author=` parameter to the homepage, hides the REST API user list from visitors who aren't logged in, and removes the author link from oEmbed responses. It also keeps the admin "filter by author" links working on hosts whose firewall blocks `?author=`.

- **Version:** 1.3.0
- **Requires WordPress:** 5.0+
- **Requires PHP:** 7.0+
- **License:** GPL-2.0-or-later
- **Author:** [Sikora Collective](https://sikoracollective.com/)

## The problem

By default, WordPress redirects `https://example.com/?author=1` to `https://example.com/author/<username>/`. By incrementing the number, bots can collect every valid username on a site, then use them in brute-force or credential-stuffing attacks against `wp-login.php`.

The REST API gives away the same information: `/wp-json/wp/v2/users` lists users and their slugs to anyone who asks. So does oEmbed, the data other sites fetch to build link previews, through its `author_url` field.

## What this plugin does

### Blocks `?author=` requests

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

### Hides the REST API user list

For visitors who aren't logged in, the plugin removes these two REST API routes, so they return a `404` (`rest_no_route`):

- `/wp-json/wp/v2/users` (the list of users)
- `/wp-json/wp/v2/users/<id>` (a single user)

The same applies to the `?rest_route=/wp/v2/users` form. Logged-in users still get both routes, so the block editor's author selector and other admin screens keep working.

### Removes the author link from oEmbed

WordPress's oEmbed responses include `author_url`, which points to `/author/<slug>/`. The plugin removes that one field from both the JSON and XML formats. Everything else stays, including the author's display name, title, thumbnail and embed code, so embeds of your posts on other sites keep working. The only visible difference is that apps that show the author in a preview show the name without a link.

Other sites store embed data when a link is first pasted, so previews created before you installed the plugin may keep the old link until they refresh.

### Keeps admin author links working behind host firewalls

In the admin, clicking an author's name in the **Author** column normally opens a link such as `edit.php?post_type=post&author=7`. The **Mine** view and the Media Library's list view use the same format. Some hosts, including SiteGround, block every address that contains `author=` followed by a number. They do this at the server, before WordPress loads, so those links return a `403 Forbidden` even for administrators.

On admin screens, the plugin rewrites those links to the equivalent `author_name` form, for example `edit.php?post_type=post&author_name=jane`. WordPress filters the list the same way, and the host's firewall lets it through. This applies to posts, pages, custom post types and the Media Library.

It only changes a link when all of these are true:

- The page is an admin screen. Front-end pages, feeds and the REST API are never affected, so no author slug is exposed publicly.
- The link points to `edit.php` or `upload.php`.
- Its `author` value is a single number that matches an existing user.

One cosmetic side effect: after you click **Mine**, WordPress doesn't highlight it as the current view, because it looks for `author` in the address to decide that. The list is still filtered correctly.

The plugin can't fix author filtering inside the block editor, such as a Query Loop block's author filter. The editor sends those requests to the REST API as `?author=7`, and a firewall like this blocks them before WordPress runs.

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

Then, logged out, run:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "https://example.com/wp-json/wp/v2/users"
```

You should see `404`.

Finally, check the oEmbed data for your homepage:

```bash
curl -s "https://example.com/wp-json/oembed/1.0/embed?url=https://example.com/"
```

The response should have `author_name` but no `author_url`.

If you get a `403` instead of a `301` on the first test, your host's firewall is blocking the request before WordPress runs. That still protects you. Try `?author=1a` to reach the plugin.

## Other enumeration vectors (not covered)

This plugin blocks the `?author=` parameter, the REST API user routes and the oEmbed author link. Usernames or user slugs can also be exposed through:

- **Core sitemaps:** `/wp-sitemap-users-1.xml` (WordPress 5.5+)
- **Author archive links/classes** in themes

For full coverage, combine this plugin with a firewall or security plugin that restricts those endpoints. Also make sure each user's **Display name** differs from their login username.

## Security notes

- Exits right away if the file is accessed directly (outside WordPress).
- Never reads, outputs or stores the `author` value. It only checks whether the parameter is present.
- Uses `wp_safe_redirect()`, which only redirects to the site's own host.
- Hides the REST user routes only from logged-out visitors. It doesn't change any user permissions.
- Rewrites author links only on admin screens, which only logged-in users can see. Rewritten links are escaped with `esc_url()`, like the originals.
- Includes an `index.php` file to stop directory listing of the plugin folder.

## Changelog

### 1.3.0
- Rewrites admin author filter links (`edit.php` and `upload.php`) from `author=<id>` to `author_name=<slug>`, so host firewalls that block `?author=` no longer return a 403.

### 1.2.0
- Removes `author_url` from oEmbed responses.

### 1.1.0
- Hides the `/wp/v2/users` REST API routes from visitors who aren't logged in.

### 1.0.1
- Fixed bypasses (`?author=1a`, `?author=1,2`, `?author[]=1`) by blocking every request with an `author` parameter.
- Moved from `init` to `template_redirect` so REST API requests, and with them the block editor, are no longer redirected.
- Switched to `wp_safe_redirect()`.

### 1.0.0
- Initial release.
