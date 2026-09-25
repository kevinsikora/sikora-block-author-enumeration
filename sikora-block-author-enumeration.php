<?php
/**
 * Plugin Name: Sikora Block Author Enumeration (Security)
 * Description: Stops bots from discovering usernames through ?author= URLs, the REST API, and oEmbed, while keeping admin author links working.
 * Version: 1.3.0
 * Author: <a href="https://SikoraCollective.com/" target="_blank" rel="noopener noreferrer">Sikora Collective</a>
 */

// Exit if accessed directly — prevents direct file execution outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks author enumeration attempts via the ?author= query parameter.
 *
 * WordPress exposes usernames through URLs like /?author=1, /?author=2, etc.
 * Attackers exploit this to enumerate valid user accounts before attempting
 * brute-force login attacks. This function intercepts those requests on the
 * front end and issues a 301 redirect to the site homepage, revealing nothing
 * about existing users.
 *
 * Any request carrying an `author` parameter is redirected, regardless of its
 * value. WordPress casts the value with intval(), so variants such as
 * ?author=1a, ?author=1,2 and ?author[]=1 all resolve to a user ID; checking
 * only for purely numeric values would let those through. Legitimate author
 * archives use pretty permalinks (/author/slug/) and are unaffected.
 *
 * Hooked to 'template_redirect' at priority 1 so it runs before WordPress's
 * own redirect_canonical() (priority 10), which is what performs the
 * username-revealing redirect. This hook only fires for front-end page loads,
 * so the admin area, admin-ajax, cron and REST API requests (which the block
 * editor uses with ?author= to filter posts) are unaffected.
 *
 * @return void
 */
function sikora_block_author_enumeration() {
	// Only presence matters; the value is never read or output.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	if ( isset( $_GET['author'] ) || isset( $_POST['author'] ) ) {
		// Redirect to the homepage with a permanent (301) status code.
		// wp_safe_redirect() restricts the target to the site's own host.
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'sikora_block_author_enumeration', 1 );

/**
 * Hides the REST API user endpoints from visitors who are not logged in.
 *
 * By default, /wp-json/wp/v2/users lists every user who has published posts,
 * including their login-based slug, and /wp-json/wp/v2/users/<id> returns a
 * single user. Both work for anonymous visitors (also via ?rest_route=), which
 * makes them an easy username enumeration vector.
 *
 * Removing the routes for logged-out requests makes them return a 404
 * (rest_no_route). Logged-in users are unaffected, so the block editor and
 * other admin screens that rely on these endpoints keep working.
 *
 * @param array $endpoints Registered REST API routes.
 * @return array Routes, minus the user endpoints for logged-out visitors.
 */
function sikora_block_rest_user_enumeration( $endpoints ) {
	if ( is_user_logged_in() ) {
		return $endpoints;
	}

	unset(
		$endpoints['/wp/v2/users'],
		$endpoints['/wp/v2/users/(?P<id>[\d]+)']
	);

	return $endpoints;
}
add_filter( 'rest_endpoints', 'sikora_block_rest_user_enumeration' );

/**
 * Removes the author archive link from oEmbed responses.
 *
 * WordPress includes an `author_url` field in every oEmbed response (the data
 * other sites and apps fetch to build link previews). That URL is the author
 * archive, /author/<slug>/, and the slug is normally derived from the login
 * username, so it leaks the same information the other protections hide.
 *
 * Only `author_url` is removed. It is optional in the oEmbed spec, and the
 * rest of the response, including the display name in `author_name`, the
 * title, thumbnail and embed HTML, is left intact so embeds keep working.
 * This filter covers both the JSON and XML oEmbed formats.
 *
 * @param array $data The oEmbed response data.
 * @return array Response data without the author archive link.
 */
function sikora_block_oembed_author_url( $data ) {
	unset( $data['author_url'] );

	return $data;
}
add_filter( 'oembed_response_data', 'sikora_block_oembed_author_url' );

/**
 * Rewrites admin "filter by author" links to use the author's slug.
 *
 * In the admin, WordPress links each name in the Author column to a filtered
 * list such as edit.php?post_type=post&author=7. The "Mine" view link and the
 * Media Library's Author column (upload.php?author=7) use the same format.
 * Some host firewalls, including SiteGround's, block every URL that contains
 * author= followed by a number, and they do it before WordPress loads, so
 * those links return a 403 even for logged-in administrators. The front-end
 * protections in this plugin can't prevent that, because the request never
 * reaches WordPress.
 *
 * This filter changes those links to the equivalent
 * edit.php?post_type=post&author_name=<slug>, which WordPress filters the same
 * way and which those firewall rules don't match. Every other parameter in the
 * link is kept.
 *
 * WordPress builds these links without a dedicated hook, but it always passes
 * them through esc_url(), which applies the 'clean_url' filter. The function
 * only changes a URL when all of the following are true, and returns every
 * other URL untouched:
 * - The request is in the admin area (is_admin()), so front-end pages, feeds
 *   and the REST API never output an author slug.
 * - The link points to edit.php or upload.php.
 * - Its `author` parameter is a single whole number that matches an existing
 *   user.
 *
 * The slug appears only in links on admin screens, which are only shown to
 * logged-in users, so this doesn't reopen the enumeration hole the rest of
 * the plugin closes.
 *
 * Known side effect: after you click the "Mine" view, WordPress no longer
 * highlights it as the current view, because it looks for `author` in the
 * address to decide that. The list is still filtered correctly.
 *
 * @param string $url          The URL after esc_url() has cleaned it.
 * @param string $original_url The URL before cleaning.
 * @param string $context      'display' for esc_url() or 'db' for esc_url_raw().
 * @return string The rewritten, escaped URL, or $url unchanged.
 */
function sikora_admin_author_links( $url, $original_url, $context ) {
	// This filter runs for every escaped URL, so rule most of them out with a
	// cheap check before parsing anything. Note that "author_name=" does not
	// contain "author=", so rewritten URLs also exit here.
	if ( ! is_admin() || false === strpos( $original_url, 'author=' ) ) {
		return $url;
	}

	// Only touch links to the post and media list screens.
	$parts = wp_parse_url( $original_url );
	if ( empty( $parts['path'] ) || empty( $parts['query'] )
		|| ! in_array( basename( $parts['path'] ), array( 'edit.php', 'upload.php' ), true ) ) {
		return $url;
	}

	// Only rewrite a single numeric author ID, the form the firewall blocks.
	// Anything else, such as a comma-separated list or an array, is left alone.
	wp_parse_str( $parts['query'], $args );
	if ( ! isset( $args['author'] ) || ! is_string( $args['author'] ) || ! preg_match( '/^\d+$/', $args['author'] ) ) {
		return $url;
	}

	$user = get_userdata( (int) $args['author'] );
	if ( ! $user ) {
		return $url;
	}

	// Swap author=<id> for author_name=<slug>, keeping the rest of the query.
	$rewritten = add_query_arg(
		'author_name',
		$user->user_nicename,
		remove_query_arg( 'author', $original_url )
	);

	// Escape the new URL the same way the original call would have. The new URL
	// has no "author=", so this nested call returns through the early exit above.
	return esc_url( $rewritten, null, $context );
}
add_filter( 'clean_url', 'sikora_admin_author_links', 10, 3 );
