<?php
/*
 * Umbra by Kharim Mchatta, https://github.com/KharimMchatta/Umbra
 * Copyright (C) 2026 Kharim Mchatta
 *
 * This file is part of Umbra. Umbra is free software: you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any later
 * version, with the additional terms under section 7 of that License set out in the
 * NOTICE file (keep this attribution, do not misrepresent the origin, mark modified
 * versions). Umbra comes WITHOUT ANY WARRANTY. See the LICENSE file for the License.
 */

/**
 * Umbra custom login URL.
 *
 * Moves the login to a secret slug and makes wp-login.php and the logged-out
 * /wp-admin return a real 404. Design goals, proven by the test matrix:
 *
 *  - The login is served ONLY at the slug. Direct wp-login.php (any action,
 *    including ?action=postpass) returns a themed 404, never the login form,
 *    so the postpass bypass (CVE-2024-2473) cannot reveal the slug.
 *  - Logged-out /wp-admin (including options.php) returns a 404, NOT a redirect
 *    to the login. Nothing echoes the slug to an unauthenticated request, so a
 *    crafted Referer to options.php (CVE-2021-24917) cannot leak it either.
 *  - admin-ajax.php, admin-post.php, the REST API and WP-Cron keep working for
 *    logged-out requests (they are not /wp-admin page loads).
 *  - Login form submit, logout, password reset and lost-password all keep
 *    working, because every generated wp-login.php URL is rewritten to the slug.
 *  - Deactivating the plugin restores the stock login instantly (recovery).
 *
 * No rewrite rule is added (nothing to flush): the slug is matched directly
 * against the request path, so recovery needs no cleanup.
 */

defined('ABSPATH') || exit;

/**
 * Wire the custom-login hooks. Called from the feature registrar only when the
 * feature is on and the slug is non-empty.
 */
function umbra_custom_login_boot($slug) {
    $GLOBALS['umbra_cl_slug']      = $slug;
    $GLOBALS['umbra_cl_wp_login']  = false;
    $GLOBALS['umbra_cl_is_slug']   = false;

    add_action('plugins_loaded', 'umbra_cl_plugins_loaded', 1);
    add_action('wp_loaded', 'umbra_cl_wp_loaded');

    // Rewrite every wp-login.php URL WordPress generates to the slug.
    add_filter('site_url', 'umbra_cl_filter_login_url', 10, 2);
    add_filter('network_site_url', 'umbra_cl_filter_login_url', 10, 2);
    add_filter('wp_redirect', 'umbra_cl_filter_login_url', 10, 1);
    add_filter('login_url', 'umbra_cl_login_url_passthrough', 10, 1);

    // Keep the slug's address free: a post, page or attachment created or
    // published later must not take it, or the login URL would appear in its links,
    // feed and sitemap. Core's own bad-slug filters make it add the usual -2 suffix.
    add_filter('wp_unique_post_slug_is_bad_flat_slug', 'umbra_cl_bad_flat_slug', 10, 3);
    add_filter('wp_unique_post_slug_is_bad_hierarchical_slug', 'umbra_cl_bad_hierarchical_slug', 10, 4);
    add_filter('wp_unique_post_slug_is_bad_attachment_slug', 'umbra_cl_bad_attachment_slug', 10, 2);
    // wp_publish_post() sets the status without the slug check (a draft that kept the
    // slug, or a restored trashed post), so check again as it goes public.
    add_action('transition_post_status', 'umbra_cl_guard_published_slug', 10, 3);
    // set_post_type() changes the type with a direct update and no slug check either, so
    // an item of another type (a reusable block, say) named after the slug could become a
    // published page or post at the login address; check again when its cache is cleared.
    add_action('clean_post_cache', 'umbra_cl_guard_retyped_slug', 10, 2);
    // Attachments never pass transition_post_status, so their guid is checked here.
    add_action('add_attachment', 'umbra_cl_guard_attachment_guid');
    add_action('edit_attachment', 'umbra_cl_guard_attachment_guid');
}

/** The configured slug. */
function umbra_cl_slug() {
    return isset($GLOBALS['umbra_cl_slug']) ? (string) $GLOBALS['umbra_cl_slug'] : '';
}

/**
 * The relative path of the current request (no query string), decoded. The query is
 * cut at the first literal "?" before decoding, so an encoded %3F stays part of the
 * path: /<slug>%3F/../wp-signup.php is not the slug (Apache runs wp-signup.php).
 */
function umbra_cl_request_path() {
    if (empty($_SERVER['REQUEST_URI'])) {
        return '';
    }
    $uri_parts = explode('?', (string) $_SERVER['REQUEST_URI'], 2);
    $path      = $uri_parts[0];
    // Only an absolute-form target (http://host/path) needs parse_url; a normal path is
    // used as it arrived, because parse_url reads a leading // as a host name while
    // Apache merges the slashes and runs the file after them.
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
        $path = parse_url($path, PHP_URL_PATH);
    }
    return is_string($path) ? rawurldecode($path) : '';
}

/** The absolute login URL at the slug (or at a given slug), preserving the install's directory. */
function umbra_cl_login_url($slug = null) {
    $slug = ($slug === null) ? umbra_cl_slug() : (string) $slug;
    if (get_option('permalink_structure')) {
        return user_trailingslashit(home_url('/' . $slug));
    }
    // Plain permalinks: /?<slug>
    return home_url('/') . '?' . $slug;
}

/** The slug's own relative path, for matching the request. */
function umbra_cl_slug_path() {
    $path = parse_url(home_url('/' . umbra_cl_slug()), PHP_URL_PATH);
    return is_string($path) ? untrailingslashit($path) : '';
}

/**
 * Early routing. If the request is the slug, mark it as the login page. If it is
 * a direct hit on wp-login.php, flag it so wp_loaded can 404 it.
 */
function umbra_cl_plugins_loaded() {
    global $pagenow;

    $path = umbra_cl_request_path();
    $slug = umbra_cl_slug();

    $is_slug = false;
    $is_case = false;
    if (get_option('permalink_structure')) {
        // Trailing slashes only: untrailingslashit() also strips backslashes, so /<slug>\ served
        // the login at an address a server rule guarding /<slug>/ does not match.
        $request = ($path !== '') ? rtrim($path, '/') : '';
        $is_slug = ($request !== '' && $request === umbra_cl_slug_path());
        $is_case = (!$is_slug && $request !== '' && umbra_cl_ascii_lower($request) === umbra_cl_ascii_lower(umbra_cl_slug_path()));
    } else {
        // Plain permalinks: /?<slug> with no value.
        foreach ($_GET as $key => $value) {
            if ($value === '' && umbra_cl_ascii_lower($key) === umbra_cl_ascii_lower($slug)) {
                $is_slug = ((string) $key === $slug);
                $is_case = !$is_slug;
                break;
            }
        }
    }

    // Any other capitalisation of the slug leads to the login: the slug is stored in lower case,
    // and /Vaultgate/ answering "page not found" looks exactly like a broken login. It is redirected
    // to the slug as stored, not served, so a server rule guarding the slug's own path (an IP
    // allow-list, basic auth) and the password-reset cookie path still apply.
    if ($is_case) {
        $GLOBALS['umbra_cl_case_redirect'] = true;
        return;
    }

    if ($is_slug) {
        // Serve the login at the slug. A dedicated flag (not $pagenow alone)
        // distinguishes this from a direct wp-login.php hit, which also carries
        // $pagenow === 'wp-login.php' but must 404.
        $GLOBALS['umbra_cl_is_slug'] = true;
        $pagenow = 'wp-login.php';
        return;
    }

    // Direct hit on the real login file, or on wp-signup.php / wp-activate.php,
    // which otherwise run normally and whose registration redirect gets rewritten
    // to the slug, leaking it, or on the legacy wp-register.php, which core 301s to
    // the registration (login) page. All are 404'd the same way (defense in depth
    // alongside the login-URL filter guard).
    // Path only, so a search or link that merely names an endpoint is not a hit. Split
    // on the first literal "?" before decoding, so an encoded %3F cannot end the path
    // early, and match by substring, not parse_url (which reads //wp-login.php as a host).
    $uri_parts = explode('?', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', 2);
    $uri       = rawurldecode($uri_parts[0]);
    foreach (array('wp-login.php', 'wp-signup.php', 'wp-activate.php', 'wp-register.php') as $hidden_endpoint) {
        if (strpos($uri, $hidden_endpoint) !== false) {
            $GLOBALS['umbra_cl_wp_login'] = true;
            break;
        }
    }
}

/**
 * Serve the login at the slug, and 404 the hidden endpoints. Runs at wp_loaded,
 * the last hook before the main query, so we can short-circuit cleanly.
 */
function umbra_cl_wp_loaded() {
    global $pagenow;

    // 1. Logged-out /wp-admin page loads -> 404. admin-ajax.php, admin-post.php,
    //    REST and WP-Cron are excluded because they are not admin PAGE loads and
    //    legitimately serve logged-out requests.
    if (is_admin()
        && !is_user_logged_in()
        && !wp_doing_ajax()
        && $pagenow !== 'admin-post.php') {
        umbra_cl_render_404();
    }

    // 2. Direct wp-login.php hit (any action, including postpass) -> 404.
    //    Checked before serving, so a raw wp-login.php request never renders the
    //    login and never reveals the slug (defeats the postpass bypass).
    if (!empty($GLOBALS['umbra_cl_wp_login']) && empty($GLOBALS['umbra_cl_is_slug'])) {
        umbra_cl_render_404();
    }

    // 3. Another capitalisation of the slug: redirect to the slug as stored.
    if (!empty($GLOBALS['umbra_cl_case_redirect'])) {
        umbra_cl_case_redirect();
    }

    // 4. The slug (and only the slug): serve wp-login.php.
    if (!empty($GLOBALS['umbra_cl_is_slug'])) {
        // wp-login.php reads these as globals.
        global $error, $interim_login, $action, $user_login;
        // Default action so the first visit renders the login form.
        if (!isset($_REQUEST['action'])) {
            $action = 'login';
        }
        umbra_cl_no_cache();
        @require_once ABSPATH . 'wp-login.php';
        die;
    }
}

/** Lower-case A-Z only, the same on every PHP version (strtolower follows the locale before 8.2). */
function umbra_cl_ascii_lower($text) {
    return strtr((string) $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
}

/**
 * Redirect a capitalised slug (/Vaultgate/, /?Vaultgate) to the login URL, keeping the rest of
 * the query as sent (action=lostpassword, a reset key). The requester typed the slug already, so
 * the Location discloses nothing; it is temporary and marked do-not-cache, so it never outlives
 * a slug change.
 */
function umbra_cl_case_redirect() {
    $uri_parts = explode('?', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', 2);
    $query     = isset($uri_parts[1]) ? $uri_parts[1] : '';
    $login     = umbra_cl_login_url();
    if (get_option('permalink_structure')) {
        $target = ($query !== '') ? $login . '?' . $query : $login;
    } else {
        // The login URL carries the slug key already; drop every capitalised copy from the query
        // (all of them, so the target can never redirect again).
        $rest = array();
        foreach (explode('&', $query) as $pair) {
            $kv  = explode('=', $pair, 2);
            $key = urldecode($kv[0]);
            if ((!isset($kv[1]) || $kv[1] === '') && $key !== umbra_cl_slug() && umbra_cl_ascii_lower($key) === umbra_cl_ascii_lower(umbra_cl_slug())) {
                continue;
            }
            if ($pair !== '') {
                $rest[] = $pair;
            }
        }
        $target = $rest ? $login . '&' . implode('&', $rest) : $login;
    }
    nocache_headers();
    umbra_cl_no_cache();
    wp_redirect($target, 302);
    exit;
}

/**
 * Send a real 404 and stop. Used for both the hidden wp-admin and the direct
 * wp-login.php hit, so neither reveals that a login exists and neither returns a
 * redirect that could carry the slug.
 *
 * It uses wp_die() with a 404 response rather than rendering the front-end theme:
 * these requests can arrive deep inside the wp-admin bootstrap (admin.php ->
 * wp-load), where WP_ADMIN is defined and is_admin() stays true, and running a
 * front-end template from there fatals in the admin bar (get_current_screen()).
 * wp_die() is context-safe, emits a genuine 404 status, and carries only a
 * generic "page not found" message, so nothing about the login is disclosed.
 */
function umbra_cl_render_404() {
    status_header(404);
    nocache_headers();
    umbra_cl_no_cache();
    wp_die(
        '<h1>' . esc_html__('Page not found.') . '</h1><p>' . esc_html__('The page you were looking for could not be found.') . '</p>',
        esc_html__('Page not found'),
        array('response' => 404)
    );
}

/**
 * Tell page caches not to store this response: the login at the slug, and the 404s above (a
 * stored 404 of wp-login.php would outlive turning the custom login off). Caches skip
 * wp-login.php by its file name, but the login at the slug looks like an ordinary page to them,
 * and most ignore the no-store header wp-login.php sends, so a stored copy kept answering at the
 * old slug after the slug changed. Each line is a signal a cache reads itself (checked in each
 * plugin's own source) and does nothing when that cache is absent.
 */
function umbra_cl_no_cache() {
    // LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Rocket, Cache Enabler, Breeze,
    // WP-Optimize, Hummingbird and the SiteGround file cache read this constant before storing.
    // (WP Fastest Cache and Super Page Cache ignore it but skip any request whose $pagenow is
    // wp-login.php, which umbra_cl_plugins_loaded sets for the slug, and do not store 404s.)
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!headers_sent()) {
        // LiteSpeed server cache (with or without its plugin), nginx FastCGI and proxy caches set
        // to ignore Cache-Control, and Cloudflare APO (replaces the official plugin's
        // "cf-edge-cache: cache" header).
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('X-Accel-Expires: 0');
        header('cf-edge-cache: no-cache');
    }
}

/**
 * Rewrite any wp-login.php URL to the slug, preserving its query string. This is
 * what keeps logout, password reset and the login form action working through
 * the slug. Applied to site_url, network_site_url and wp_redirect.
 */
function umbra_cl_filter_login_url($url, $unused = null) {
    if (!is_string($url) || strpos($url, 'wp-login.php') === false) {
        return $url;
    }

    // Never emit the slug to an unauthenticated client on a non-slug request.
    // Only rewrite when a user is logged in (logout / lost-password / admin flows)
    // or the current request IS the slug login page itself (its form, reset and
    // confirmation URLs). For an anonymous non-slug request the wp-login.php URL is
    // left literal, so a stray login link stays literal and never leaks the slug.
    $logged_in = function_exists('is_user_logged_in') && is_user_logged_in();
    if (!$logged_in && empty($GLOBALS['umbra_cl_is_slug'])) {
        return $url;
    }

    $query = '';
    $parts = explode('?', $url, 2);
    if (isset($parts[1]) && $parts[1] !== '') {
        $query = $parts[1];
    }

    $login = umbra_cl_login_url();

    if ($query !== '') {
        parse_str($query, $args);
        // Drop an empty slug arg that would duplicate the plain-permalink marker.
        unset($args[umbra_cl_slug()]);
        $login = add_query_arg($args, $login);
    }

    return $login;
}

/**
 * True when an item with this slug could be served at the login address /<slug>/:
 * any post (with /%postname%/ permalinks, or after a switch to them), page or
 * attachment. Child pages and attached files are included: deleting the parent moves
 * them to the top level with a direct database update that runs no slug check.
 */
function umbra_cl_is_reserved_post_slug($slug, $post_type, $post_parent = 0) {
    $login = umbra_cl_slug();
    if ($login === '' || (string) $slug !== $login) {
        return false;
    }
    return in_array($post_type, array('post', 'page', 'attachment'), true);
}

function umbra_cl_bad_flat_slug($bad, $slug, $post_type) {
    return $bad || umbra_cl_is_reserved_post_slug($slug, $post_type);
}

function umbra_cl_bad_hierarchical_slug($bad, $slug, $post_type, $post_parent) {
    return $bad || umbra_cl_is_reserved_post_slug($slug, $post_type, $post_parent);
}

function umbra_cl_bad_attachment_slug($bad, $slug) {
    return $bad || umbra_cl_is_reserved_post_slug($slug, 'attachment');
}

/**
 * Runs on every save as published. Backstop for wp_publish_post(), which updates the
 * status directly and skips wp_unique_post_slug(): if an item goes public on the login
 * address, move it off; and on any route, drop a guid that is the login URL. A private
 * item is not served at the login address, but its guid is checked on every save too.
 * Statuses are judged by their flags, not their names, so a public or private status
 * another plugin registers is treated like publish or private.
 */
function umbra_cl_guard_published_slug($new_status, $old_status, $post) {
    if (!($post instanceof WP_Post)) {
        return;
    }
    if (umbra_cl_is_public_status($new_status)) {
        umbra_cl_move_off_login_slug($post);
    } elseif ('private' === $new_status || umbra_cl_status_flag($new_status, 'private')) {
        umbra_cl_move_off_login_slug($post, true);
    }
}

/** True for publish and any other status registered as public. */
function umbra_cl_is_public_status($status) {
    return 'publish' === $status || umbra_cl_status_flag($status, 'public');
}

/** A registered post status's flag ('public' or 'private'); false when the status is unknown. */
function umbra_cl_status_flag($status, $flag) {
    $object = get_post_status_object($status);
    return $object && !empty($object->$flag);
}

/** add_attachment and edit_attachment: drop an attachment guid that is the login URL. */
function umbra_cl_guard_attachment_guid($post_id) {
    $post = get_post($post_id);
    if ($post instanceof WP_Post) {
        umbra_cl_move_off_login_slug($post, true);
    }
}

/**
 * Backstop for set_post_type(). Runs on every post cache clear, so it leaves at once
 * unless the item is named after the slug; the row is then read again (the cache was
 * just cleared), because the object passed in can still carry the old type.
 */
function umbra_cl_guard_retyped_slug($post_id, $post = null) {
    if (!($post instanceof WP_Post) || (string) $post->post_name !== umbra_cl_slug()) {
        return;
    }
    $fresh = WP_Post::get_instance((int) $post_id);
    if ($fresh && umbra_cl_is_public_status($fresh->post_status)) {
        umbra_cl_move_off_login_slug($fresh);
    }
}

/**
 * Move a public item off the login address to the next free slug (core's -2 suffix,
 * via the bad-slug filters above), and replace a guid that is the login URL, or feeds
 * and the REST API would still carry it. The guid is checked even when the slug has
 * already moved: core's own slug check (wp_update_post, a REST save) renames the item
 * but keeps the guid it was created with. $guid_only skips the slug move (private items
 * and attachments).
 */
function umbra_cl_move_off_login_slug($post, $guid_only = false) {
    static $busy = false;
    if ($busy) {
        return;
    }
    $data = array();
    if (!$guid_only && umbra_cl_is_reserved_post_slug($post->post_name, $post->post_type, $post->post_parent)) {
        $free = wp_unique_post_slug($post->post_name, $post->ID, 'publish', $post->post_type, $post->post_parent);
        if ($free !== $post->post_name) {
            $data['post_name'] = $free;
        }
    }
    if (umbra_cl_is_login_url($post->guid)) {
        $data['guid'] = umbra_cl_replacement_guid($post->ID, $post->post_type);
    }
    if (!$data) {
        return;
    }
    global $wpdb;
    $busy = true;
    $wpdb->update($wpdb->posts, $data, array('ID' => $post->ID));
    clean_post_cache($post->ID);
    $busy = false;
    foreach ($data as $field => $value) {
        $post->$field = $value;
    }
}

/**
 * A URL reduced to what decides whether it is the login address: host in lower case
 * without a leading www., port, path without a trailing slash, query and fragment. The
 * scheme is left out, so http and https copies of the same address compare equal.
 */
function umbra_cl_url_key($url) {
    $parts = parse_url(trim((string) $url));
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $host = strtolower($parts['host']);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return $host
        . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
        . (isset($parts['path']) ? untrailingslashit($parts['path']) : '')
        . (isset($parts['query']) ? '?' . $parts['query'] : '')
        . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}

/**
 * True when a stored URL (a guid) is the login URL, in any scheme, with or without www, in any
 * capitalisation (every capitalisation of the slug leads to the login).
 */
function umbra_cl_is_login_url($url, $login = null) {
    $key = umbra_cl_ascii_lower(umbra_cl_url_key($url));
    return $key !== '' && $key === umbra_cl_ascii_lower(umbra_cl_url_key($login === null ? umbra_cl_login_url() : $login));
}

/** What a login-URL guid is replaced with: the item's plain link, which names no slug. */
function umbra_cl_replacement_guid($post_id, $post_type) {
    return add_query_arg('attachment' === $post_type ? 'attachment_id' : 'p', (int) $post_id, home_url('/'));
}

/**
 * Replace every stored guid that is the login URL, in any post type or status. Runs on
 * every settings save and when Umbra is activated, with the custom login on, so items
 * stored before the login was turned on, before the slug changed or while Umbra was
 * inactive do not keep the login URL until their next save. The settings are passed in
 * because a settings save runs before the new slug is booted.
 */
function umbra_cl_sweep_login_guids($settings) {
    if (!is_array($settings) || empty($settings['custom_login'])) {
        return;
    }
    $slug = isset($settings['custom_login_slug']) ? (string) $settings['custom_login_slug'] : '';
    if ($slug === '') {
        return;
    }
    global $wpdb;
    $login = umbra_cl_login_url($slug);
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_type, guid FROM {$wpdb->posts} WHERE guid LIKE %s",
        '%' . $wpdb->esc_like($slug) . '%'
    ));
    foreach ((array) $rows as $row) {
        if (umbra_cl_is_login_url($row->guid, $login)) {
            $wpdb->update($wpdb->posts, array('guid' => umbra_cl_replacement_guid($row->ID, $row->post_type)), array('ID' => $row->ID));
            clean_post_cache((int) $row->ID);
        }
    }
}

/**
 * pre_update_option_umbra_settings: sweep with the settings being saved, and return them
 * untouched. A filter, not the update_option action, because WordPress skips that action
 * when the value is unchanged, and a Save with nothing changed must sweep too.
 */
function umbra_cl_sweep_on_settings_save($value) {
    umbra_cl_sweep_login_guids($value);
    return $value;
}

/** add_option_umbra_settings: the first save on a site without stored settings. */
function umbra_cl_sweep_on_settings_add($option, $value) {
    umbra_cl_sweep_login_guids($value);
}

/** update_option_permalink_structure: the login URL changed form (/<slug>/ or /?<slug>), so sweep for the new one. */
function umbra_cl_sweep_on_permalink_change() {
    umbra_cl_sweep_login_guids(umbra_get_settings());
}

/** login_url() is built from site_url already; keep it consistent as a safety net. */
function umbra_cl_login_url_passthrough($url) {
    return umbra_cl_filter_login_url($url);
}
