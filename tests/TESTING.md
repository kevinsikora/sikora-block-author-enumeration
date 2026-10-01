# Testing: Sikora Block Author Enumeration

How to confirm the plugin works after you install it.

Replace `example.com` with your domain in every command. Run the tests from a terminal with `curl` rather than a browser, because browsers remember 301 redirects and can show you an old result. If you use a caching plugin or a CDN such as Cloudflare, clear its cache after activating the plugin.

## Automated runner

From the plugin root (or `tests/`), run:

```bash
./tests/run-tests.sh https://example.com
```

The site URL is required. The author slug is **optional**; if you omit it, the archive check uses `your-author-slug`. Pass a real slug when you want to exercise a specific author URL:

```bash
./tests/run-tests.sh https://example.com your-author-slug
```

That executes the curl checks below, marks each as pass/fail/skip, and writes `tests/test-report.html`. Add `--optional` only when the plugin is deactivated and you want the §1 leak check.

Prefer the site’s canonical URL (for example `https://www.example.com`). Non-www and www hosts can redirect or behave differently.

### Accepted “safe” status codes

The runner treats **enumeration blocked** as success, not only this plugin’s own `301`. That way host firewalls and SEO plugins do not fail the suite when they still prevent username leaks:

| Check | Pass when |
| --- | --- |
| `?author=` / bypasses / `author_name` / POST `author` | `301`/`302` to the homepage, **or** host **`403`**; never a Location of `/author/<user>/` |
| REST `/wp/v2/users` (logged out) | **`404`**, **`401`**, or **`403`** (not a public user list) |
| Core users sitemap URL | **`404`**, **`403`**, or **`3xx`** away from `/wp-sitemap-users-1.xml` |
| Author archive URL | **`200`**, **`404`**, **`403`**, or **`3xx`** (archives disabled/redirected) |
| REST `posts?author=1` | **`200`**, or host **`403`** |
| oEmbed | no `author_url` |
| Normal `?s=test` | **`200`** |

## Hosting and other plugins

Other layers often do related blocking and change status codes:

- **Host firewalls / CDNs** (for example SiteGround) may return **`403`** for URLs that contain `author=` followed by a number, before WordPress loads. The automated tests count that as a pass.
- **SEO plugins** (for example Yoast) may disable or redirect author archives, or remap sitemap URLs (for example `/wp-sitemap-users-1.xml` → `/author-sitemap.xml`). The suite accepts those safe outcomes for the archive and core sitemap checks.
- **Non-www vs www URLs** can redirect or behave differently. Use the canonical host when you run the tests.

## 1. See the leak first (optional)

With the plugin **deactivated** (and with any host rule that returns `403` for `author=` temporarily aside), run:

```bash
curl -sI "https://example.com/?author=1" | grep -i -E "^HTTP|^location"
```

You'll see a `301` with `location: https://example.com/author/<username>/`. That username is what the plugin hides. Now activate the plugin.

## 2. The main test

```bash
curl -sI "https://example.com/?author=1" | grep -i -E "^HTTP|^location"
```

**Pass:** `301`/`302` to the homepage with no `/author/` in `Location`, **or** host `403`.

## 3. Tricks attackers use to get around simple checks

This runs six variants in one go (`-g` lets curl send the `[]` characters as-is):

```bash
for q in "author=1" "author=1a" "author=1,2" "author=%201" "author[]=1" "p=1&author=1"; do printf "%-14s " "$q"; curl -gsI "https://example.com/?$q" | grep -i -E "^HTTP|^location"; done
```

**Pass:** each response is a homepage redirect or `403`. None should show `/author/<username>/`.

Also block the slug-based bypass:

```bash
curl -sI "https://example.com/?author_name=admin" | grep -i -E "^HTTP|^location"
```

**Pass:** homepage redirect or `403`, not `/author/admin/`.

Also try the value sent as form data instead of in the URL:

```bash
curl -si -X POST -d "author=1" "https://example.com/" | grep -i -E "^HTTP|^location"
```

**Pass:** homepage redirect or `403`.

## 4. REST, sitemap, and oEmbed

Logged out, users REST should not list users:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "https://example.com/wp-json/wp/v2/users"
```

**Pass:** `404`, `401`, or `403`.

Users sitemap at the core URL should not serve a public user list:

```bash
curl -sI "https://example.com/wp-sitemap-users-1.xml" | grep -i -E "^HTTP|^location"
```

**Pass:** `404`, `403`, or a `3xx` away from that URL.

oEmbed should not include `author_url`:

```bash
curl -s "https://example.com/wp-json/oembed/1.0/embed?url=https://example.com/" | grep -E '"author_url"|"author_name"' || echo "NO AUTHOR FIELDS"
```

**Pass:** no `author_url`. `author_name` only appears when the display name differs from the login/nicename.

## 5. Make sure nothing else broke

**Author archive URL.** Use an author slug from your site:

```bash
curl -sI "https://example.com/author/your-author-slug/" | grep -i -E "^HTTP|^location"
```

**Pass:** `200` (archive works), `404` (no posts), `403`, or `3xx` (for example Yoast disables author archives).

**REST posts filter.** The block editor relies on this; some hosts block `author=` entirely:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "https://example.com/wp-json/wp/v2/posts?author=1"
```

**Pass:** `200`, or host `403` (not a redirect that reveals `/author/<username>/`).

**Normal pages aren't affected:**

```bash
curl -sI "https://example.com/?s=test" | grep -i "^HTTP"
```

**Pass:** `200`.

## 6. Check the dashboard by hand

- [ ] **Posts → All Posts:** click an author's name in the Author column. The address should use `author_name=` (or still filter correctly) and the list should filter normally.
- [ ] **Block editor:** open a post and change the author in the sidebar. If you use a Query Loop block, filter it by author and check that the preview updates.
- [ ] **Users** page loads normally.

## 7. Check for errors

If `WP_DEBUG_LOG` is turned on, look in `wp-content/debug.log` for any messages that mention `sikora-block-author-enumeration`. There shouldn't be any.

## What this plugin doesn't hide

Public author archives may still work when nothing else disables them:

```bash
curl -sI "https://example.com/author/your-author-slug/" | grep -i -E "^HTTP|^location"
```

Themes may still print author nicenames in body classes or bylines. Login forms may still reveal whether a username exists. SEO plugins may still publish their own author sitemaps under a different URL.
