<?php
/**
 * Plugin Name: Sikora Block Author Enumeration
 * Description: Stops bots from discovering usernames through ?author= URLs, the REST API, and oEmbed, while keeping admin author links working.
 * Version: 2.1.0
 * Author: Sikora Collective
 * Author URI: https://SikoraCollective.com/
 */

// Exit if accessed directly — prevents direct file execution outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current request carries an author enumeration parameter.
 *
 * Checks $_GET/$_POST for presence only (values are never read or output), then
 * the raw QUERY_STRING so encoded forms such as author%5B%5D=1 are caught even
 * if PHP's query parser differs.
 *
 * @return bool True when an author or author_name parameter is present.
 */
function sikora_request_has_author_param() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	if ( isset( $_GET['author'] ) || isset( $_POST['author'] )
		|| isset( $_GET['author_name'] ) || isset( $_POST['author_name'] ) ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$qs = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : '';
	if ( ! is_string( $qs ) || '' === $qs ) {
		return false;
	}

	// author=, author_name=, author[]=, author%5B%5D= (and author_name equivalents).
	return (bool) preg_match( '/(?:^|&)author(_name)?(?:%5B[^&]*%5D|\[[^&]*\])?=/i', $qs );
}

/**
 * Blocks author enumeration via ?author= and ?author_name= query parameters.
 *
 * WordPress exposes usernames through URLs like /?author=1, /?author=2, etc.
 * Attackers exploit this to enumerate valid user accounts before attempting
 * brute-force login attacks. The same leak exists for ?author_name=<slug>,
 * which resolves a nicename and can redirect to /author/<slug>/. This function
 * intercepts those front-end requests and issues a 301 redirect to the site
 * homepage, revealing nothing about existing users.
 *
 * Any request carrying an `author` or `author_name` parameter is redirected,
 * regardless of its value. WordPress casts `author` with intval(), so variants
 * such as ?author=1a, ?author=1,2 and ?author[]=1 all resolve to a user ID;
 * checking only for purely numeric values would let those through. The raw
 * QUERY_STRING is also inspected so encoded forms are not missed. Legitimate
 * author archives use pretty permalinks (/author/slug/) and are unaffected.
 *
 * Hooked to 'template_redirect' at priority 1 so it runs before WordPress's
 * own redirect_canonical() (priority 10), which is what performs the
 * username-revealing redirect. This hook only fires for front-end page loads,
 * so the admin area, admin-ajax, cron and REST API requests (which the block
 * editor uses with ?author= to filter posts) are unaffected. Admin author
 * filter links that use author_name= are also unaffected for the same reason.
 *
 * @return void
 */
function sikora_block_author_enumeration() {
	if ( ! sikora_request_has_author_param() ) {
		return;
	}

	// Redirect to the homepage with a permanent (301) status code.
	// wp_safe_redirect() restricts the target to the site's own host.
	wp_safe_redirect( home_url( '/' ), 301 );
	exit;
}
add_action( 'template_redirect', 'sikora_block_author_enumeration', 1 );

/**
 * Hides the REST API user endpoints from users who cannot edit posts.
 *
 * By default, /wp-json/wp/v2/users lists every user who has published posts,
 * including their login-based slug, and /wp-json/wp/v2/users/<id> returns a
 * single user. Both work for anonymous visitors and low-privilege accounts
 * such as subscribers (also via ?rest_route=), which makes them an easy
 * username enumeration vector.
 *
 * Removing the routes for anyone without the edit_posts capability makes them
 * return a 404 (rest_no_route). Authors, editors and admins keep the routes,
 * so the block editor author selector and related admin screens still work.
 * Responses for users without list_users are further reduced by
 * sikora_rest_prepare_user() and sikora_rest_user_query().
 *
 * @param array $endpoints Registered REST API routes.
 * @return array Routes, minus the user endpoints when the requester lacks edit_posts.
 */
function sikora_block_rest_user_enumeration( $endpoints ) {
	// edit_posts: block subscribers; keep roles that use the editor.
	if ( current_user_can( 'edit_posts' ) ) {
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
 * Strips login-derived fields from REST user responses without list_users.
 *
 * Users with edit_posts (but not list_users) still need the users routes for
 * the block editor author selector. Removing slug and link stops those
 * responses from revealing /author/<nicename>/ while keeping id and name.
 *
 * @param WP_REST_Response $response The response object.
 * @param WP_User          $user     User object used to create response.
 * @param WP_REST_Request  $request  Request object.
 * @return WP_REST_Response Filtered response.
 */
function sikora_rest_prepare_user( $response, $user, $request ) {
	if ( current_user_can( 'list_users' ) || ! ( $response instanceof WP_REST_Response ) ) {
		return $response;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return $response;
	}

	unset( $data['slug'], $data['link'] );
	$response->set_data( $data );

	return $response;
}
add_filter( 'rest_prepare_user', 'sikora_rest_prepare_user', 10, 3 );

/**
 * Limits REST user queries to published authors when the requester lacks list_users.
 *
 * @param array           $prepared_args WP_User_Query arguments.
 * @param WP_REST_Request $request       REST request.
 * @return array Filtered query arguments.
 */
function sikora_rest_user_query( $prepared_args, $request ) {
	if ( current_user_can( 'list_users' ) ) {
		return $prepared_args;
	}

	// Same constraint WordPress applies for who=authors.
	$prepared_args['has_published_posts'] = get_post_types( array( 'show_in_rest' => true ), 'names' );

	return $prepared_args;
}
add_filter( 'rest_user_query', 'sikora_rest_user_query', 10, 2 );

/**
 * Removes author archive URL from oEmbed, and author_name when it matches login/slug.
 *
 * WordPress includes an `author_url` field in every oEmbed response (the data
 * other sites and apps fetch to build link previews). That URL is the author
 * archive, /author/<slug>/, and the slug is normally derived from the login
 * username, so it leaks the same information the other protections hide.
 *
 * `author_name` is the display name. It is removed only when it equals the
 * user's login or nicename (case-insensitive), so previews still show a
 * distinct public display name when one is set.
 *
 * @param array          $data The oEmbed response data.
 * @param WP_Post|null   $post The post being embedded, when available.
 * @return array Response data without leaking author URLs or login-like names.
 */
function sikora_block_oembed_author_url( $data, $post = null ) {
	unset( $data['author_url'] );

	if ( empty( $data['author_name'] ) || ! $post instanceof WP_Post ) {
		return $data;
	}

	$user = get_userdata( (int) $post->post_author );
	if ( ! $user ) {
		return $data;
	}

	$name = $data['author_name'];
	if ( 0 === strcasecmp( $name, $user->user_login )
		|| 0 === strcasecmp( $name, $user->user_nicename ) ) {
		unset( $data['author_name'] );
	}

	return $data;
}
add_filter( 'oembed_response_data', 'sikora_block_oembed_author_url', 10, 2 );

/**
 * Removes the core users sitemap provider so slugs are not listed publicly.
 *
 * @param WP_Sitemaps_Provider $provider Instance of a WP_Sitemaps_Provider.
 * @param string               $name     Name of the sitemap provider.
 * @return WP_Sitemaps_Provider|false Provider, or false to disable users.
 */
function sikora_block_users_sitemap( $provider, $name ) {
	if ( 'users' === $name ) {
		return false;
	}

	return $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'sikora_block_users_sitemap', 10, 2 );

/**
 * Removes XML-RPC methods that expose author/user lists.
 *
 * Leaves the rest of XML-RPC intact (pingbacks, remote publishing, Jetpack,
 * mobile apps via wp.getUsersBlogs, etc.). Only the listing methods used for
 * username enumeration are stripped.
 *
 * @param array $methods Associative array of XML-RPC methods.
 * @return array Methods without author/user listing callbacks.
 */
function sikora_block_xmlrpc_user_methods( $methods ) {
	unset(
		$methods['wp.getUsers'],
		$methods['wp.getAuthors']
	);

	return $methods;
}
add_filter( 'xmlrpc_methods', 'sikora_block_xmlrpc_user_methods' );

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
 * - The current user can edit_posts or upload_files.
 * - The link points to this site's wp-admin edit.php or upload.php (same host
 *   and admin path). External or non-admin URLs are left alone.
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

	// Only users who can use the post or media list screens.
	if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'upload_files' ) ) {
		return $url;
	}

	$parts = wp_parse_url( $original_url );
	if ( empty( $parts['path'] ) || empty( $parts['query'] ) ) {
		return $url;
	}

	$basename = basename( $parts['path'] );
	if ( ! in_array( $basename, array( 'edit.php', 'upload.php' ), true ) ) {
		return $url;
	}

	// Only rewrite same-site wp-admin links. Relative "edit.php" is fine;
	// absolute URLs must match the admin host and path (blocks evil.com/edit.php).
	$admin = wp_parse_url( admin_url() );
	if ( ! empty( $parts['host'] ) ) {
		$admin_host = isset( $admin['host'] ) ? strtolower( $admin['host'] ) : '';
		if ( strtolower( $parts['host'] ) !== $admin_host ) {
			return $url;
		}
	}

	$dir       = untrailingslashit( dirname( $parts['path'] ) );
	$admin_dir = isset( $admin['path'] ) ? untrailingslashit( $admin['path'] ) : '/wp-admin';
	if ( '.' !== $dir && $dir !== $admin_dir ) {
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
