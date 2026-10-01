# Testing: Sikora Block Author Enumeration

How to confirm the plugin works after you install it.

Replace `example.com` with your domain in every command. Run the tests from a terminal with `curl` rather than a browser, because browsers remember 301 redirects and can show you an old result. If you use a caching plugin or a CDN such as Cloudflare, clear its cache after activating the plugin.

## 1. See the leak first (optional)

With the plugin **deactivated**, run:

```bash
curl -sI "https://example.com/?author=1" | grep -i -E "^HTTP|^location"
```

You'll see a `301` with `location: https://example.com/author/<username>/`. That username is what the plugin hides. Now activate the plugin.

## 2. The main test

```bash
curl -sI "https://example.com/?author=1" | grep -i -E "^HTTP|^location"
```

**Pass:** `HTTP/2 301` and `location: https://example.com/`, with no username anywhere.

## 3. Tricks attackers use to get around simple checks

This runs six variants in one go (`-g` lets curl send the `[]` characters as-is):

```bash
for q in "author=1" "author=1a" "author=1,2" "author=%201" "author[]=1" "p=1&author=1"; do printf "%-14s " "$q"; curl -gsI "https://example.com/?$q" | grep -i "^location" || echo "NO REDIRECT"; done
```

**Pass:** every line shows `location: https://example.com/`. None should show `/author/...` or `NO REDIRECT`.

Also block the slug-based bypass:

```bash
curl -sI "https://example.com/?author_name=admin" | grep -i -E "^HTTP|^location"
```

**Pass:** `301` to the homepage, not `/author/admin/`.

Also try the value sent as form data instead of in the URL:

```bash
curl -si -X POST -d "author=1" "https://example.com/" | grep -i -E "^HTTP|^location"
```

**Pass:** `301` to the homepage.

## 4. REST, sitemap, and oEmbed

Logged out, users REST should be gone:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "https://example.com/wp-json/wp/v2/users"
```

**Pass:** `404`.

Users sitemap should be gone:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "https://example.com/wp-sitemap-users-1.xml"
```

**Pass:** `404`.

oEmbed should not include `author_url`:

```bash
curl -s "https://example.com/wp-json/oembed/1.0/embed?url=https://example.com/" | grep -E '"author_url"|"author_name"' || echo "NO AUTHOR FIELDS"
```

**Pass:** no `author_url`. `author_name` only appears when the display name differs from the login/nicename.

## 5. Make sure nothing else broke

**Author archive pages still load.** Use an author slug from your site:

```bash
curl -sI "https://example.com/author/your-author-slug/" | grep -i "^HTTP"
```

**Pass:** `200`. You'll get a `404` if that author has no published posts; that's normal WordPress behavior and not caused by the plugin.

**The REST API still answers.** This is what the block editor relies on:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "https://example.com/wp-json/wp/v2/posts?author=1"
```

**Pass:** `200`, not `301`.

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

Public author archives still work on purpose:

```bash
curl -sI "https://example.com/author/your-author-slug/" | grep -i "^HTTP"
```

Themes may still print author nicenames in body classes or bylines. Login forms may still reveal whether a username exists.
