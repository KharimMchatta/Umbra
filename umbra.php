<?php
/**
 * Plugin Name: Umbra
 * Description: Hardens WordPress by removing the fingerprints that identify the CMS and its version, blocking user and author enumeration (REST users, ?author and its encoded/array bypasses, users sitemap, oEmbed author, REST _embed author, author body classes, feed author names, and the author sitemaps and schema of Yoast, Rank Math and All in One SEO), answering the registration and lost-password forms the same whether or not an account exists, optionally disabling XML-RPC, and optionally hiding wp-admin behind a custom login URL. Every feature is a toggle on its own settings page. Keeps the REST API, the block editor and wp-admin fully working, and leaves RSS feeds on by default.
 * Plugin URI: https://github.com/KharimMchatta/Umbra
 * Version: 2.8.5
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: Kharim Mchatta
 * Author URI: https://github.com/KharimMchatta
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI: https://github.com/KharimMchatta/Umbra
 */

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

/*
 * Why the Update URI header: WordPress sends every plugin's headers to the wordpress.org
 * update check, and wordpress.org returns no update for a plugin whose Update URI is not on
 * wordpress.org (WordPress 5.8 and later). Umbra is published only on GitHub, so without this
 * header a wordpress.org plugin that took the slug "umbra" would be offered to every Umbra
 * site as an update.
 */

defined('ABSPATH') || exit;

define('UMBRA_VERSION', '2.8.5');
define('UMBRA_FILE', __FILE__);
define('UMBRA_OPTION', 'umbra_settings');
/* Schema version for one-time settings migrations (e.g. strip_bylines -> hide_author). */
define('UMBRA_SCHEMA_VERSION', '2.2.1');
define('UMBRA_SCHEMA_OPTION', 'umbra_schema_version');

/* -------------------------------------------------------------------------
 * Settings: one option array, with the v1.1.0 behaviour as the defaults so a
 * fresh install with default settings behaves exactly as v1.1.0 did.
 * ---------------------------------------------------------------------- */

/**
 * Default settings. Each boolean is a feature toggle; custom_login_slug is the
 * path the login is moved to when custom_login is on.
 */
function umbra_default_settings() {
    return array(
        'generator'               => 1, // remove generator meta + version from feeds
        'strip_ver'               => 1, // strip ?ver= from front-end CSS/JS
        'head_links'              => 1, // remove RSD/WLW/shortlink/adjacent/oEmbed-discovery/REST head links
        'emoji'                   => 1, // remove emoji detection scripts
        'login_error'             => 1, // generic login error message
        'neutral_forms'           => 1, // registration + lost-password answer the same whether or not the account exists
        'rest_users'              => 1, // hide /wp/v2/users from unauthenticated REST
        'author_enum'             => 1, // block ?author=N enumeration + author leaks (sitemap, oEmbed, _embed, body class, author links, Yoast/Rank Math/AIOSEO sitemaps + head/schema)
        'hide_author'             => 0, // replace the real author name with a generic label in bylines + author pages (off: real name shown)
        'hide_author_label'       => get_bloginfo('name'), // the generic label shown when hide_author is on (default: site name)
        'x_pingback'              => 1, // remove the X-Pingback response header
        'xmlrpc'                  => 1, // disable XML-RPC and its pingback methods
        'strip_feed_authors'      => 1, // strip <dc:creator> author name from RSS/Atom feeds
        'disable_feeds'           => 0, // replace feeds with a notice (off: feeds stay on)
        'disable_author_archives' => 0, // 301 /author/<slug>/ archives home (off by default)
        'custom_login'            => 0, // hide wp-admin behind a custom login slug (off by default)
        'custom_login_slug'       => '', // the slug used when custom_login is on
    );
}

/** The list of boolean feature keys (everything except the slug string). */
function umbra_bool_keys() {
    return array(
        'generator', 'strip_ver', 'head_links', 'emoji', 'login_error', 'neutral_forms', 'rest_users',
        'author_enum', 'hide_author', 'x_pingback', 'xmlrpc', 'strip_feed_authors', 'disable_feeds',
        'disable_author_archives', 'custom_login',
    );
}

/** Saved settings merged over the defaults, so a missing key never breaks a feature check. */
function umbra_get_settings() {
    $saved = get_option(UMBRA_OPTION, array());
    if (!is_array($saved)) {
        $saved = array();
    }
    return array_merge(umbra_default_settings(), $saved);
}

/** True when the named feature is enabled. */
function umbra_on($key) {
    $settings = umbra_get_settings();
    return !empty($settings[$key]);
}

/** The sanitized custom-login slug (empty string when unset). */
function umbra_login_slug() {
    $settings = umbra_get_settings();
    return isset($settings['custom_login_slug']) ? (string) $settings['custom_login_slug'] : '';
}

/** The generic label shown in place of the author name; falls back to the site name. */
function umbra_hide_author_label() {
    $settings = umbra_get_settings();
    $label    = isset($settings['hide_author_label']) ? (string) $settings['hide_author_label'] : '';
    $label    = sanitize_text_field($label);
    if ($label === '') {
        $label = get_bloginfo('name');
    }
    return $label;
}

/* -------------------------------------------------------------------------
 * Activation: seed the defaults once, without clobbering an existing config.
 * ---------------------------------------------------------------------- */

function umbra_activate() {
    if (get_option(UMBRA_OPTION, null) === null) {
        add_option(UMBRA_OPTION, umbra_default_settings());
    }
    // Migrate any legacy option shape (e.g. strip_bylines) on activation too.
    umbra_maybe_upgrade();
    // SEO plugins may have cached author sitemaps while Umbra was inactive.
    umbra_seo_reset_cache_purge();
    // Items may have been stored with the login URL as their guid while Umbra was inactive.
    umbra_cl_sweep_login_guids(umbra_get_settings());
    // Page caches hold pages built without Umbra.
    umbra_purge_page_caches();
}
register_activation_hook(UMBRA_FILE, 'umbra_activate');

/* Deactivation: page caches hold pages built with Umbra on (a login copy at the slug, say).
 * Renaming the plugin folder for lockout recovery runs no hook; the login and Umbra's 404s
 * are never stored, so that path needs none. */
function umbra_deactivate() {
    umbra_purge_page_caches();
}
register_deactivation_hook(UMBRA_FILE, 'umbra_deactivate');

/* -------------------------------------------------------------------------
 * One-time settings migration. Runs when the stored schema version differs
 * from the plugin's, so an upgrade from 2.1.1 carries the old strip_bylines
 * behaviour forward into hide_author without the admin losing it.
 * ---------------------------------------------------------------------- */

function umbra_maybe_upgrade() {
    if (get_option(UMBRA_SCHEMA_OPTION, null) === UMBRA_SCHEMA_VERSION) {
        return;
    }
    umbra_migrate_settings();
    update_option(UMBRA_SCHEMA_OPTION, UMBRA_SCHEMA_VERSION);
}

/**
 * Fold the retired strip_bylines toggle into hide_author and drop it, then make
 * sure the new keys exist. A fresh install (no saved option yet) is left for the
 * activation seed. Idempotent: only writes when something actually changes.
 */
function umbra_migrate_settings() {
    $saved = get_option(UMBRA_OPTION, null);
    if (!is_array($saved)) {
        return;
    }

    $changed = false;

    // strip_bylines (v2.1.1) -> hide_author (v2.2.1). If it was on, carry the
    // behaviour forward so nobody loses it; then remove the retired key entirely.
    if (array_key_exists('strip_bylines', $saved)) {
        if (!empty($saved['strip_bylines']) && empty($saved['hide_author'])) {
            $saved['hide_author'] = 1;
        }
        unset($saved['strip_bylines']);
        $changed = true;
    }

    if (!array_key_exists('hide_author', $saved)) {
        $saved['hide_author'] = 0;
        $changed = true;
    }
    if (!array_key_exists('hide_author_label', $saved) || (string) $saved['hide_author_label'] === '') {
        $saved['hide_author_label'] = get_bloginfo('name');
        $changed = true;
    }

    if ($changed) {
        update_option(UMBRA_OPTION, $saved);
    }
}

/* Includes: the settings page UI, the custom-login engine, the protection
 * status panel (runtime health check, dependency alert, self-test), and the
 * Info tab (author, official source, legal notices). */
require_once __DIR__ . '/includes/settings-page.php';
require_once __DIR__ . '/includes/custom-login.php';
require_once __DIR__ . '/includes/status-panel.php';
require_once __DIR__ . '/includes/info-tab.php';

/* -------------------------------------------------------------------------
 * Feature registration. Each block registers its hooks only when its toggle
 * is on, so turning a feature off on the settings page truly removes it.
 * ---------------------------------------------------------------------- */

/*
 * Register at include time (not on the plugins_loaded action). The custom-login
 * engine needs to hook plugins_loaded at an early priority, and a hook added from
 * inside a later plugins_loaded callback would be registered too late to fire.
 * get_option and the hook API are both available when a plugin file is included.
 */
function umbra_register_features() {

    /*
     * Last-word priority. The filters below that REMOVE or EMPTY data run at
     * PHP_INT_MAX so a plugin that registers later and re-adds what we stripped
     * (a generator string, a REST users endpoint, an author name, an X-Pingback
     * header) cannot win over us: our filter runs last and has the final say.
     * This is the update-resilience guarantee for v2.3.0. Hooks that are early
     * by design (the author-enumeration template_redirect at priority 0, before
     * core canonical) and the asset-version strippers (9999+, their proven
     * working priority) are deliberately NOT moved.
     */

    /* Any settings change re-arms the one-time SEO author-cache purge, so turning
     * "Block author enumeration" back on clears what was cached while it was off. */
    add_action('update_option_' . UMBRA_OPTION, 'umbra_seo_reset_cache_purge');

    /* Every settings save with the custom login on, changed or not, replaces stored guids
     * that are the login URL. */
    add_filter('pre_update_option_' . UMBRA_OPTION, 'umbra_cl_sweep_on_settings_save', 10, 1);
    add_action('add_option_' . UMBRA_OPTION, 'umbra_cl_sweep_on_settings_add', 10, 2);
    /* A permalink switch changes the login URL's form, so it sweeps too. */
    add_action('update_option_permalink_structure', 'umbra_cl_sweep_on_permalink_change');

    /* Every settings save, changed or not, clears the page caches (after the write when it
     * changed), so no cache keeps serving pages built with the old settings. */
    add_action('update_option_' . UMBRA_OPTION, 'umbra_purge_page_caches', 10, 0);
    add_action('add_option_' . UMBRA_OPTION, 'umbra_purge_page_caches', 10, 0);
    add_filter('pre_update_option_' . UMBRA_OPTION, 'umbra_purge_page_caches_on_unchanged_save', 10, 2);

    /* 1. Version / generator fingerprint removal */
    if (umbra_on('generator')) {
        add_filter('the_generator', '__return_empty_string', PHP_INT_MAX);
        remove_action('wp_head', 'wp_generator');
        // SEO plugins that print their own version in the page head: Yoast's
        // "optimized with the Yoast SEO plugin vX" comment, and All in One SEO's
        // version in its generator tag and comment. No-ops when the plugin is absent.
        add_filter('wpseo_debug_markers', '__return_false', PHP_INT_MAX);
        add_filter('aioseo_hide_version_number', '__return_true', PHP_INT_MAX);
        // Rank Math's "Search Engine Optimization by Rank Math" head comments.
        add_filter('rank_math/frontend/remove_credit_notice', '__return_true', PHP_INT_MAX);
        // All in One SEO prints its own <meta name="generator"> and its credit comments
        // unconditionally (no filter exists), inside its head block at wp_head priority
        // 1. A buffer opened at priority 0 and closed at priority 2 removes exactly those.
        add_action('wp_head', 'umbra_aioseo_generator_buffer_start', 0);
        add_action('wp_head', 'umbra_aioseo_generator_buffer_end', 2);
    }

    /* 1b. Strip ?ver= from front-end CSS/JS (admin/editor untouched) */
    if (umbra_on('strip_ver')) {
        add_filter('style_loader_src', 'umbra_remove_asset_version', 9999);
        add_filter('script_loader_src', 'umbra_remove_asset_version', 9999);
    }

    /* 2. Head clutter that aids fingerprinting */
    if (umbra_on('head_links')) {
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head');
        remove_action('wp_head', 'adjacent_posts_rel_link');
        remove_action('wp_head', 'start_post_rel_link');
        remove_action('wp_head', 'index_rel_link');
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'rest_output_link_wp_head');
        remove_action('template_redirect', 'rest_output_link_header', 11);
        remove_action('xmlrpc_rsd_apis', 'rest_output_rsd');
    }

    /* 2b. X-Pingback response header */
    if (umbra_on('x_pingback')) {
        add_filter('wp_headers', 'umbra_remove_pingback_header', PHP_INT_MAX);
    }

    /* 3. Emoji detection scripts (front end and admin) */
    if (umbra_on('emoji')) {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('wp_resource_hints', 'umbra_remove_emoji_dns_prefetch', 10, 2);
    }

    /* 4. Generic login error message */
    if (umbra_on('login_error')) {
        add_filter('login_errors', 'umbra_generic_login_error');
    }

    /* 4b. Neutral registration and lost-password responses: an existing and an
     * unknown username or email get the same answer (status, redirect, message),
     * so neither form confirms that an account exists. Last word (PHP_INT_MAX), so
     * errors other plugins add (a captcha, say) are already in place. */
    if (umbra_on('neutral_forms')) {
        add_filter('registration_errors', 'umbra_neutral_registration_errors', PHP_INT_MAX, 1);
        add_filter('lostpassword_errors', 'umbra_neutral_lostpassword_errors', PHP_INT_MAX, 2);
    }

    /* 5. REST user enumeration (unauthenticated only) */
    if (umbra_on('rest_users')) {
        add_filter('rest_endpoints', 'umbra_block_rest_user_enum', PHP_INT_MAX);
    }

    /* 6. Author enumeration + the related author-identity leaks */
    if (umbra_on('author_enum')) {
        // Parsed-query redirect (defeats ?author=1, ?author[]=1, %61uthor=1, trailing junk, mixed case).
        add_action('template_redirect', 'umbra_block_author_enum', 0);
        add_filter('redirect_canonical', 'umbra_block_author_canonical', 10, 2);
        // Users sitemap (core, WP 5.5+) off: /wp-sitemap-users-1.xml and its index
        // entry. Guarded by the core sitemaps API being present so an older or
        // sitemap-less install degrades to a no-op instead of fataling; late
        // priority so our removal is the last word on the provider list.
        if (function_exists('wp_sitemaps_get_server')) {
            add_filter('wp_sitemaps_add_provider', 'umbra_remove_users_sitemap', PHP_INT_MAX, 2);
        }
        // oEmbed author strip (json and xml): runs last so nothing re-adds the name.
        add_filter('oembed_response_data', 'umbra_strip_oembed_author', PHP_INT_MAX);
        // REST _embed author strip for unauthenticated requests (numeric id stays, name does not).
        add_action('rest_api_init', 'umbra_strip_rest_embed_author', 20);
        // author-<slug> / author-<id> body classes.
        add_filter('body_class', 'umbra_strip_author_body_class', PHP_INT_MAX);
        // Author archive links point home: kills the nicename in the theme byline
        // href AND in any author-archive URL Yoast builds from get_author_posts_url.
        add_filter('author_link', 'umbra_neutralize_author_link', PHP_INT_MAX);
        // SEO plugin (Yoast) author sitemap, guarded by existence.
        add_action('init', 'umbra_disable_seo_author_sitemap');
        // SEO plugin (Yoast) author exposure in head/schema (REST yoast_head*),
        // guarded by existence at init so Yoast's constant is defined.
        add_action('init', 'umbra_disable_seo_author_head');
        // Rank Math and All in One SEO: author sitemap, schema, author meta and
        // "Written by" data, each guarded by its own plugin being active.
        add_action('init', 'umbra_rankmath_author_scrub');
        add_action('init', 'umbra_aioseo_author_scrub');
        // Clear any author sitemap data those plugins cached before this
        // protection was on. Once per change, not per request.
        add_action('init', 'umbra_seo_purge_author_caches', 20);
    }

    /* 6b. Hide the post author: replace the real display name with a generic label
     * on the front end (not admin, not feeds). Classic themes read the_author/
     * get_the_author; block themes (core post-author / post-author-name blocks) read
     * get_the_author_meta('display_name'), which fires get_the_author_display_name.
     * Cover all three, and the author-archive page title. Scope is post bylines +
     * author pages only: comment author names are deliberately not filtered. */
    if (umbra_on('hide_author')) {
        add_filter('the_author', 'umbra_hide_author_name', PHP_INT_MAX);
        add_filter('get_the_author', 'umbra_hide_author_name', PHP_INT_MAX);
        add_filter('get_the_author_display_name', 'umbra_hide_author_name', PHP_INT_MAX);
        add_filter('get_the_archive_title', 'umbra_hide_author_archive_title', PHP_INT_MAX);
    }

    /* 7. XML-RPC */
    if (umbra_on('xmlrpc')) {
        add_filter('xmlrpc_enabled', '__return_false', PHP_INT_MAX);
        add_filter('xmlrpc_methods', 'umbra_remove_xmlrpc_pingback', PHP_INT_MAX);
        remove_action('wp_head', 'rsd_link');
        add_filter('wp_headers', 'umbra_remove_pingback_header', PHP_INT_MAX);
    }

    /* 8. Feeds disabled (replace with a notice) */
    if (umbra_on('disable_feeds')) {
        add_action('do_feed_rdf', 'umbra_disable_feed', 1);
        add_action('do_feed_rss', 'umbra_disable_feed', 1);
        add_action('do_feed_rss2', 'umbra_disable_feed', 1);
        add_action('do_feed_atom', 'umbra_disable_feed', 1);
        add_action('do_feed_rss2_comments', 'umbra_disable_feed', 1);
        add_action('do_feed_atom_comments', 'umbra_disable_feed', 1);
        remove_action('wp_head', 'feed_links', 2);
        remove_action('wp_head', 'feed_links_extra', 3);
    }

    /* 12. Feed author names stripped */
    if (umbra_on('strip_feed_authors')) {
        add_filter('the_author', 'umbra_strip_feed_author', PHP_INT_MAX);
    }

    /* 13. Author archives disabled */
    if (umbra_on('disable_author_archives')) {
        add_action('template_redirect', 'umbra_disable_author_archives');
    }

    /* Custom login URL: only when on with a non-empty slug. */
    if (umbra_on('custom_login') && umbra_login_slug() !== '') {
        umbra_custom_login_boot(umbra_login_slug());
    }
}

/* -------------------------------------------------------------------------
 * Feature callbacks (unchanged behaviour from v1.1.0, now option-gated).
 * ---------------------------------------------------------------------- */

function umbra_remove_asset_version($src) {
    if (is_admin()) {
        return $src;
    }
    if (is_string($src) && strpos($src, 'ver=') !== false) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}

/** Open the head buffer that removes All in One SEO's generator tag (front end, AIOSEO active only). */
function umbra_aioseo_generator_buffer_start() {
    if (!defined('AIOSEO_VERSION') || is_admin() || is_feed()) {
        return;
    }
    if (ob_start('umbra_strip_aioseo_generator')) {
        $GLOBALS['umbra_aio_ob_level'] = ob_get_level();
    }
}

/**
 * Close the buffer, but only if it is still the innermost one. If another plugin
 * left a buffer open in between, ours is left for PHP to flush at shutdown, which
 * runs the same callback, so the page is never cut short or emptied.
 */
function umbra_aioseo_generator_buffer_end() {
    if (empty($GLOBALS['umbra_aio_ob_level'])) {
        return;
    }
    if (ob_get_level() === $GLOBALS['umbra_aio_ob_level']) {
        ob_end_flush();
    }
    $GLOBALS['umbra_aio_ob_level'] = 0;
}

/**
 * Remove All in One SEO's generator meta tag (with or without a version) and its
 * "All in One SEO" credit comments from the buffered head. Nothing else is touched.
 */
function umbra_strip_aioseo_generator($html) {
    $out = preg_replace(
        array(
            '#[ \t]*<meta\s+name=(["\'])generator\1\s+content=(["\'])All in One SEO[^"\']*\2\s*/?>[ \t]*\R?#i',
            '#[ \t]*<!--\s*All in One SEO\b[^>]*?-->[ \t]*\R?#i',
        ),
        '',
        (string) $html
    );
    return is_string($out) ? $out : $html;
}

function umbra_remove_pingback_header($headers) {
    if (isset($headers['X-Pingback'])) {
        unset($headers['X-Pingback']);
    }
    return $headers;
}

function umbra_remove_emoji_dns_prefetch($hints, $relation_type) {
    if ('dns-prefetch' === $relation_type) {
        $emoji_svg = apply_filters('emoji_svg_url', 'https://s.w.org/images/core/emoji/');
        $hints = array_diff($hints, array($emoji_svg));
    }
    return $hints;
}

/**
 * Generic message for the login form only. wp-login.php also prints the errors of
 * its other screens (register, lost password, reset link) through login_errors;
 * those keep their real text, because replacing them with "Username or password is
 * incorrect" misleads the visitor and hides nothing (their account-existence leak
 * is closed by the neutral-forms feature instead). wp-login.php resolves $action
 * to 'login' for the login form and any unknown action.
 */
function umbra_generic_login_error($error) {
    $action = (isset($GLOBALS['action']) && is_string($GLOBALS['action'])) ? $GLOBALS['action'] : 'login';
    if ($action !== 'login') {
        return $error;
    }
    return 'Username or password is incorrect.';
}

/** True on a POST to one of the given wp-login.php actions (direct or at the custom-login slug). */
function umbra_is_login_form_post($actions) {
    if (!isset($GLOBALS['pagenow']) || $GLOBALS['pagenow'] !== 'wp-login.php') {
        return false;
    }
    if (!isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
        return false;
    }
    $action = (isset($_REQUEST['action']) && is_string($_REQUEST['action'])) ? $_REQUEST['action'] : 'login';
    return in_array($action, $actions, true);
}

/**
 * Registration: drop the "username already registered" and "email already
 * registered" errors. If nothing else is wrong, answer exactly as core does after
 * a successful registration (the same redirect wp-login.php sends) without
 * creating an account, so an existing and a new username/email are
 * indistinguishable. Other errors (empty field, invalid characters, a captcha)
 * are still shown; they say nothing about whether an account exists. Only acts on
 * the wp-login.php register form, never on a programmatic register_new_user().
 */
function umbra_neutral_registration_errors($errors) {
    if (!is_wp_error($errors) || !umbra_is_login_form_post(array('register'))) {
        return $errors;
    }
    $exists = array_intersect($errors->get_error_codes(), array('username_exists', 'email_exists'));
    if (empty($exists)) {
        return $errors;
    }
    foreach ($exists as $code) {
        $errors->remove($code);
    }
    if ($errors->has_errors()) {
        return $errors;
    }
    // Same as wp-login.php's success branch for action=register.
    $redirect_to = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : 'wp-login.php?checkemail=registered';
    wp_safe_redirect($redirect_to);
    exit;
}

/**
 * Lost password: for an unknown username or email, answer exactly as core does
 * after a successful request (the same "check your email" redirect) and send
 * nothing, so the form cannot confirm that an account exists. A real account is
 * left to core. Other errors (empty field, a captcha) are still shown. Only acts
 * on the wp-login.php lost-password form, never on a programmatic call.
 */
function umbra_neutral_lostpassword_errors($errors, $user_data = false) {
    if (!is_wp_error($errors) || $user_data || !umbra_is_login_form_post(array('lostpassword', 'retrievepassword'))) {
        return $errors;
    }
    // Core flags an unknown email as invalid_email here; an unknown username has
    // no error yet (core adds invalidcombo after this filter).
    $errors->remove('invalid_email');
    if ($errors->has_errors()) {
        return $errors;
    }
    // Same as wp-login.php's success branch for action=lostpassword.
    $redirect_to = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : 'wp-login.php?checkemail=confirm';
    wp_safe_redirect($redirect_to);
    exit;
}

function umbra_block_rest_user_enum($endpoints) {
    if (is_user_logged_in()) {
        return $endpoints;
    }
    if (isset($endpoints['/wp/v2/users'])) {
        unset($endpoints['/wp/v2/users']);
    }
    if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
        unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    }
    return $endpoints;
}

/** A request we leave alone: admin, REST, JSON, or a logged-in user. */
function umbra_is_exempt_request() {
    if (is_admin()) {
        return true;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }
    if (function_exists('wp_is_json_request') && wp_is_json_request()) {
        return true;
    }
    if (is_user_logged_in()) {
        return true;
    }
    return false;
}

/**
 * Parsed-query author-enumeration block. Runs at template_redirect priority 0,
 * before core's canonical redirect to /author/<slug>/. Instead of reading the
 * raw query string (which URL-encoding and array params slip past), it uses the
 * values WordPress has already parsed and normalised, so ?author=1, ?author[]=1,
 * %61uthor=1 (decoded by PHP into author), trailing junk and mixed case all
 * resolve to the same parsed author query and are caught.
 */
function umbra_block_author_enum() {
    if (umbra_is_exempt_request()) {
        return;
    }

    $hit = false;

    // 1. Resolved author archive query (/author/<slug>/ or ?author=N both set this).
    if (is_author()) {
        $hit = true;
    }

    // 2. Numeric author query var (?author=1, ?author[]=1 -> array, %61uthor=1 -> author).
    if (!$hit) {
        $author = get_query_var('author');
        if (is_array($author)) {
            foreach ($author as $value) {
                if ((int) $value > 0) {
                    $hit = true;
                    break;
                }
            }
        } elseif ($author !== '' && (int) $author > 0) {
            $hit = true;
        }
    }

    // 3. Raw request scan, independent of how WordPress parsed the query. PHP has
    //    already URL-decoded $_GET, so %61uthor=1 arrives as the key "author"; a
    //    case-insensitive key match also catches AuThOr=1, and an array value
    //    (?author[]=1) is reduced to its first scalar. This closes the encoding,
    //    array and mixed-case bypasses the version-string regex used to miss.
    if (!$hit && !empty($_GET)) {
        foreach ($_GET as $key => $value) {
            if (!is_string($key) || strtolower($key) !== 'author') {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            if ((int) $value > 0) {
                $hit = true;
                break;
            }
        }
    }

    // 4. Author name query var (?author_name=admin).
    if (!$hit) {
        $author_name = get_query_var('author_name');
        if (is_string($author_name) && $author_name !== '') {
            $hit = true;
        }
    }

    if ($hit) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}

/** Second net: catch the author query through the canonical-redirect filter too. */
function umbra_block_author_canonical($redirect, $request) {
    if (!umbra_is_exempt_request() && preg_match('/\?author=([0-9]+)/i', (string) $request)) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
    return $redirect;
}

function umbra_remove_xmlrpc_pingback($methods) {
    unset($methods['pingback.ping']);
    unset($methods['pingback.extensions.getPingbacks']);
    return $methods;
}

function umbra_disable_feed() {
    wp_die(
        sprintf(
            /* translators: 1: opening link tag, 2: closing link tag. */
            esc_html__('No feed available, please visit the %1$shomepage%2$s.'),
            '<a href="' . esc_url(home_url('/')) . '">',
            '</a>'
        )
    );
}

function umbra_remove_users_sitemap($provider, $name) {
    return ('users' === $name) ? false : $provider;
}

function umbra_strip_oembed_author($data) {
    if (is_array($data)) {
        unset($data['author_name'], $data['author_url']);
    }
    return $data;
}

/**
 * Strip the author from REST responses for UNAUTHENTICATED requests, so
 * /wp-json/wp/v2/posts?_embed cannot resolve an author display name. The numeric
 * `author` field stays in the post object; only the embeddable author link (which
 * points at the already-blocked /wp/v2/users endpoint) is removed, so nothing
 * legitimate for a logged-out consumer is lost.
 */
function umbra_strip_rest_embed_author() {
    if (is_user_logged_in()) {
        return;
    }
    foreach (get_post_types(array('show_in_rest' => true), 'names') as $post_type) {
        add_filter("rest_prepare_{$post_type}", 'umbra_remove_rest_author_link', 10, 1);
    }
}

function umbra_remove_rest_author_link($response) {
    if (is_object($response) && method_exists($response, 'remove_link')) {
        $response->remove_link('author');
    }
    return $response;
}

function umbra_strip_author_body_class($classes) {
    if (!is_array($classes)) {
        return $classes;
    }
    $filtered = array();
    foreach ($classes as $class) {
        if (is_string($class) && strpos($class, 'author-') === 0) {
            continue;
        }
        $filtered[] = $class;
    }
    return array_values($filtered);
}

/**
 * Disable the Yoast SEO author sitemap when Yoast is active. Guarded by Yoast's
 * own constant/filter so it is a no-op when Yoast is absent. Plugin-specific: it
 * targets Yoast's author sitemap only, not the core sitemap (handled separately).
 */
function umbra_disable_seo_author_sitemap() {
    if (!defined('WPSEO_VERSION')) {
        return;
    }
    // Yoast builds its author sitemap from the users this filter returns. Returning
    // an empty set excludes every author, so the author-sitemap.xml entry is dropped
    // from the index and the sitemap itself is empty. (Yoast >= 14 filter.)
    add_filter('wpseo_sitemap_exclude_author', 'umbra_yoast_exclude_all_authors', PHP_INT_MAX);
}

function umbra_yoast_exclude_all_authors($users) {
    return array();
}

/**
 * Point every author-archive link at the home page. Applied to the core
 * `author_link` filter (used by get_author_posts_url), so the author nicename
 * never appears in a byline href on the front end, nor in any author-archive URL
 * Yoast derives from get_author_posts_url. The author archives themselves still
 * 301 home via the author-enumeration block; this only rewrites the printed href.
 */
function umbra_neutralize_author_link($link) {
    return home_url('/');
}

/**
 * Remove Yoast SEO's author exposure from the page head and REST yoast_head*
 * output: the author/Person Schema graph piece (which carries the display name
 * and author-archive URL) and Yoast's author meta. Guarded by Yoast being active
 * so it is a no-op when Yoast is absent. Runs at init where WPSEO_VERSION is set.
 */
function umbra_disable_seo_author_head() {
    if (!defined('WPSEO_VERSION')) {
        return;
    }
    // Scrub the assembled Yoast Schema graph: drop the Author/Person node and the
    // `author` reference on every remaining node (Article, WebPage), so neither
    // yoast_head nor yoast_head_json carries an author name, nicename or archive URL.
    add_filter('wpseo_schema_graph', 'umbra_yoast_scrub_schema_author', PHP_INT_MAX, 1);
    // Drop Yoast's Author presenter, which emits <meta name="author"> in
    // yoast_head and the "author" key in yoast_head_json (the display name).
    add_filter('wpseo_frontend_presenters', 'umbra_yoast_remove_author_presenter', PHP_INT_MAX, 1);
    // Drop the author ("Written by") from Yoast's Slack/Twitter enhanced-data card
    // (twitter:label1/data1 and twitter_misc), keeping the reading-time entry.
    add_filter('wpseo_enhanced_slack_data', 'umbra_yoast_strip_slack_author', PHP_INT_MAX, 2);
}

/**
 * Remove author identity from the final Yoast Schema graph. Drops any Person node
 * (which carries the display name and author-archive URL) and strips the `author`
 * key from every other node (Article/WebPage embed the name inline). Operating on
 * the assembled graph (wpseo_schema_graph) catches both the standalone node and
 * the inline references in one pass, for yoast_head and yoast_head_json alike.
 */
function umbra_yoast_scrub_schema_author($graph) {
    if (!is_array($graph)) {
        return $graph;
    }
    $out = array();
    foreach ($graph as $node) {
        if (!is_array($node)) {
            $out[] = $node;
            continue;
        }
        $type      = isset($node['@type']) ? $node['@type'] : '';
        $is_person = ($type === 'Person') || (is_array($type) && in_array('Person', $type, true));
        if ($is_person) {
            continue;
        }
        unset($node['author']);
        $out[] = $node;
    }
    return array_values($out);
}

/**
 * Remove Yoast's Author presenter from the front-end presenter list, which drops
 * both the <meta name="author"> tag from yoast_head and the "author" key from
 * yoast_head_json. Matches on the presenter class suffix so it survives namespace
 * changes across Yoast versions (Yoast 28.6: ...\Presenters\Meta_Author_Presenter).
 */
function umbra_yoast_remove_author_presenter($presenters) {
    if (!is_array($presenters)) {
        return $presenters;
    }
    $kept = array();
    foreach ($presenters as $presenter) {
        $class = is_object($presenter) ? get_class($presenter) : '';
        if ($class !== '' && preg_match('/\\\\Meta_Author_Presenter$/', $class)) {
            continue;
        }
        $kept[] = $presenter;
    }
    return $kept;
}

/**
 * Remove the author ("Written by") entry from Yoast's Slack/Twitter enhanced-data
 * card, identified by its value matching the post author's display name, so the
 * reading-time entry is preserved. Closes the twitter:label1/data1 and
 * twitter_misc author leak in yoast_head and yoast_head_json.
 */
function umbra_yoast_strip_slack_author($data, $presentation = null) {
    if (!is_array($data)) {
        return $data;
    }
    $author_id = 0;
    if (is_object($presentation) && isset($presentation->source)
        && is_object($presentation->source) && isset($presentation->source->post_author)) {
        $author_id = (int) $presentation->source->post_author;
    }
    if ($author_id > 0) {
        $name = get_the_author_meta('display_name', $author_id);
        foreach ($data as $key => $value) {
            if (is_string($value) && $value === $name) {
                unset($data[$key]);
            }
        }
    }
    return $data;
}

/* -------------------------------------------------------------------------
 * Rank Math and All in One SEO author exposure (v2.5.2). Same protection as the
 * Yoast block above: no author sitemap, no author Person node or author reference
 * in the schema, no author meta, no "Written by" entry. Each block is a no-op when
 * its plugin is absent. Hook names and data shapes confirmed against Rank Math
 * 1.0.280 and All in One SEO 5.0.2.1 source.
 * ---------------------------------------------------------------------- */

/** Option holding the last SEO author-cache purge: its signature and whether it worked. */
define('UMBRA_SEO_PURGE_OPTION', 'umbra_seo_cache_purge');

function umbra_rankmath_author_scrub() {
    if (!defined('RANK_MATH_VERSION')) {
        return;
    }
    // Author sitemap: drop its index entry and every author URL; the query filter
    // also empties the HTML sitemap's author list.
    add_filter('rank_math/sitemap/index/entry', 'umbra_rankmath_drop_author_index_entry', PHP_INT_MAX, 2);
    add_filter('rank_math/sitemap/entry', 'umbra_rankmath_drop_author_entry', PHP_INT_MAX, 2);
    add_filter('rank_math/sitemap/author/query', 'umbra_rankmath_no_authors_query', PHP_INT_MAX, 1);
    // Schema: the final schema data.
    add_filter('rank_math/schema/validated_data', 'umbra_rankmath_scrub_schema_author', PHP_INT_MAX, 1);
    // Slack/Twitter enhanced data (twitter:label1/data1 "Written by").
    add_filter('rank_math/opengraph/slack_enhanced_data', 'umbra_rankmath_strip_slack_author', PHP_INT_MAX, 1);
    // Rank Math's headless head data: no author data for logged-out requests.
    add_filter('rest_pre_dispatch', 'umbra_rankmath_flag_gethead', 10, 3);
    add_action('parse_query', 'umbra_rankmath_gethead_no_author_archive', PHP_INT_MAX);
}

/** Mark the current REST request as Rank Math's getHead route. */
function umbra_rankmath_flag_gethead($result, $server = null, $request = null) {
    if (is_object($request) && method_exists($request, 'get_route')
        && 0 === strcasecmp(rtrim((string) $request->get_route(), '/'), '/rankmath/v1/getHead')) {
        $GLOBALS['umbra_rm_gethead'] = true;
    }
    return $result;
}

/** Logged-out clients get no author archive here. */
function umbra_rankmath_gethead_no_author_archive($query) {
    if (empty($GLOBALS['umbra_rm_gethead']) || is_user_logged_in()) {
        return;
    }
    if (is_object($query) && method_exists($query, 'is_main_query') && $query->is_main_query() && $query->is_author()) {
        $query->set_404();
    }
}

function umbra_rankmath_drop_author_index_entry($item, $type = '') {
    return ('author' === $type) ? false : $item;
}

function umbra_rankmath_drop_author_entry($url, $type = '') {
    return ('user' === $type) ? false : $url;
}

/** A user query that matches nobody (no user has ID 0). */
function umbra_rankmath_no_authors_query($args) {
    if (!is_array($args)) {
        $args = array();
    }
    $args['include'] = array(0);
    return $args;
}

/**
 * Remove author identity from Rank Math's schema: the `author` reference on every
 * node (Article/BlogPosting, WebPage, custom schemas, review), and the author Person
 * node itself (keyed 'ProfilePage'). The publisher Person (the site's own identity, when the site represents a
 * person) is not an author reference and is kept.
 */
function umbra_rankmath_scrub_schema_author($data) {
    if (!is_array($data)) {
        return $data;
    }
    $author_ids = array();
    foreach ($data as $key => $node) {
        if (!is_array($node)) {
            continue;
        }
        if (isset($node['author'])) {
            if (is_array($node['author']) && isset($node['author']['@id']) && is_string($node['author']['@id'])) {
                $author_ids[] = $node['author']['@id'];
            }
            unset($data[$key]['author']);
        }
        if (isset($node['review']) && is_array($node['review'])) {
            unset($data[$key]['review']['author']);
        }
    }
    foreach ($data as $key => $node) {
        if (!is_array($node)) {
            continue;
        }
        $type      = isset($node['@type']) ? $node['@type'] : '';
        $is_person = ($type === 'Person') || (is_array($type) && in_array('Person', $type, true));
        if (!$is_person) {
            continue;
        }
        $id = (isset($node['@id']) && is_string($node['@id'])) ? $node['@id'] : '';
        if ('ProfilePage' === $key || ($id !== '' && in_array($id, $author_ids, true))) {
            unset($data[$key]);
        }
    }
    return $data;
}

/**
 * Drop Rank Math's "Written by" entry (keyed by its translated label) and any other
 * author-identifying entries; keep the rest.
 */
function umbra_rankmath_strip_slack_author($data) {
    if (!is_array($data)) {
        return $data;
    }
    unset($data[__('Written by', 'seo-by-rank-math')]);
    if (function_exists('is_author') && is_author()) {
        unset($data[__('Name', 'seo-by-rank-math')], $data[__('Posts', 'seo-by-rank-math')]);
    }
    return $data;
}

function umbra_aioseo_author_scrub() {
    if (!defined('AIOSEO_VERSION')) {
        return;
    }
    // Author sitemap: no author entries, and no author entry in the sitemap index.
    add_filter('aioseo_sitemap_author_archives', 'umbra_aioseo_no_author_entries', PHP_INT_MAX, 1);
    add_filter('aioseo_sitemap_indexes', 'umbra_aioseo_drop_author_index', PHP_INT_MAX, 1);
    // Schema graph, wherever AIOSEO outputs it.
    add_filter('aioseo_schema_output', 'umbra_aioseo_scrub_schema_author', PHP_INT_MAX, 1);
    // <meta name="author"> (the display name).
    add_filter('aioseo_author_meta', '__return_false', PHP_INT_MAX);
    // Twitter additional data (twitter:label1/data1 "Written by").
    add_filter('aioseo_social_twitter_additional_data', 'umbra_aioseo_strip_twitter_author', PHP_INT_MAX, 1);
    // Clear AIOSEO's cached author sitemap data before it can be served.
    add_action('parse_request', 'umbra_aioseo_clear_author_cache_on_xsl', 1);
}

/** Clear AIOSEO's cached author sitemap data first. */
function umbra_aioseo_clear_author_cache_on_xsl() {
    $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) parse_url($uri, PHP_URL_PATH);
    if (!preg_match('#sitemap[^/]*\.xsl$#i', $path) || !function_exists('aioseo')) {
        return;
    }
    $core = isset(aioseo()->core) ? aioseo()->core : null;
    if (is_object($core) && isset($core->cache) && is_object($core->cache) && method_exists($core->cache, 'clearPrefix')) {
        $core->cache->clearPrefix('aioseo_sitemap_author');
    }
}

function umbra_aioseo_no_author_entries($entries) {
    return array();
}

/** Remove the author-*.xml entries from AIOSEO's sitemap index. */
function umbra_aioseo_drop_author_index($indexes) {
    if (!is_array($indexes)) {
        return $indexes;
    }
    foreach ($indexes as $i => $index) {
        $loc  = (is_array($index) && isset($index['loc'])) ? (string) $index['loc'] : '';
        $path = (string) parse_url($loc, PHP_URL_PATH);
        if (preg_match('#(^|/)author-[^/]*\.xml(\.gz)?$#i', $path)) {
            unset($indexes[$i]);
        }
    }
    return $indexes;
}

/**
 * Remove author identity from AIOSEO's schema graph: the author Person node (its
 * @id ends in #author) and the `author`/`creator` references on every other node. The site's own Person
 * (#person, when the site represents a person) is kept.
 */
function umbra_aioseo_scrub_schema_author($graph) {
    if (!is_array($graph)) {
        return $graph;
    }
    $out = array();
    foreach ($graph as $node) {
        if (!is_array($node)) {
            $out[] = $node;
            continue;
        }
        $type      = isset($node['@type']) ? $node['@type'] : '';
        $is_person = ($type === 'Person') || (is_array($type) && in_array('Person', $type, true));
        $id        = (isset($node['@id']) && is_string($node['@id'])) ? $node['@id'] : '';
        if ($is_person && substr($id, -7) === '#author') {
            continue;
        }
        unset($node['author'], $node['creator']);
        $out[] = $node;
    }
    return $out;
}

/** Drop AIOSEO's "Written by" item, keep the rest (re-indexed so the label numbers stay contiguous). */
function umbra_aioseo_strip_twitter_author($items) {
    if (!is_array($items)) {
        return $items;
    }
    $label = __('Written by', 'all-in-one-seo-pack');
    foreach ($items as $i => $item) {
        if (is_array($item) && isset($item['label']) && $item['label'] === $label) {
            unset($items[$i]);
        }
    }
    return array_values($items);
}

/**
 * Clear Rank Math's and AIOSEO's cached author sitemap data once per change of
 * the active SEO plugin versions (and again after any Umbra settings change), so
 * nothing cached before the protection was on keeps being served. The outcome is
 * recorded so the status panel can say if the Rank Math purge failed.
 */
function umbra_seo_purge_author_caches() {
    $rm  = defined('RANK_MATH_VERSION') ? (string) RANK_MATH_VERSION : '';
    $aio = defined('AIOSEO_VERSION') ? (string) AIOSEO_VERSION : '';
    if ($rm === '' && $aio === '') {
        return;
    }
    $sig    = 'rm:' . $rm . '|aio:' . $aio;
    $stored = get_option(UMBRA_SEO_PURGE_OPTION, array());

    // Self-heal: a cached author sitemap found while this protection is on is purged
    // whatever the signature says.
    $rm_stale = false;
    if ($rm !== '' && class_exists('\RankMath\Sitemap\Cache') && method_exists('\RankMath\Sitemap\Cache', 'cached_files')) {
        $files    = \RankMath\Sitemap\Cache::cached_files();
        $rm_stale = is_array($files) && in_array('author', $files, true);
    }

    if (!$rm_stale && is_array($stored) && isset($stored['sig']) && $stored['sig'] === $sig) {
        return;
    }

    $rm_ok = true;
    if ($rm !== '' && class_exists('\RankMath\Sitemap\Cache')
        && method_exists('\RankMath\Sitemap\Cache', 'invalidate_storage')
        && method_exists('\RankMath\Sitemap\Cache', 'get_cache_directory')) {
        \RankMath\Sitemap\Cache::invalidate_storage();
        // Verify, rather than assume, that the purge worked.
        $left  = glob(trailingslashit(\RankMath\Sitemap\Cache::get_cache_directory()) . '*.xml');
        $rm_ok = empty($left);
    }

    if ($aio !== '' && function_exists('aioseo')) {
        $core = isset(aioseo()->core) ? aioseo()->core : null;
        if (is_object($core) && isset($core->cache) && is_object($core->cache) && method_exists($core->cache, 'clearPrefix')) {
            $core->cache->clearPrefix('aioseo_sitemap_author');
        }
    }

    update_option(UMBRA_SEO_PURGE_OPTION, array('sig' => $sig, 'rankmath_ok' => $rm_ok, 'at' => time()), false);
}

/** Re-arm the purge (any Umbra settings change). */
function umbra_seo_reset_cache_purge() {
    if (false !== get_option(UMBRA_SEO_PURGE_OPTION, false)) {
        delete_option(UMBRA_SEO_PURGE_OPTION);
    }
}

/**
 * Clear the page caches, so none keeps serving pages built with the old settings: after a login
 * slug change, a stored copy of the login kept answering at the old slug. Each entry is that
 * cache plugin's own purge-everything API (checked in its source); absent plugins are skipped,
 * and a failing one never stops the others or the settings save. SiteGround Optimizer clears its
 * cache itself on every settings save and plugin (de)activation.
 */
function umbra_purge_page_caches() {
    $purges = array(
        array('action', 'litespeed_purge_all'),                 // LiteSpeed Cache
        array('action', 'breeze_clear_all_cache'),              // Breeze
        array('action', 'cache_enabler_clear_complete_cache'),  // Cache Enabler
        array('action', 'rt_nginx_helper_purge_all'),           // Nginx Helper
        array('action', 'wphb_clear_page_cache'),               // Hummingbird
        array('action', 'swcfpc_purge_cache'),                  // Super Page Cache
        array('function', 'w3tc_flush_all'),                    // W3 Total Cache
        array('function', 'wp_cache_clear_cache'),              // WP Super Cache
        array('function', 'wpfc_clear_all_cache'),              // WP Fastest Cache
        array('function', 'wpo_cache_flush'),                   // WP-Optimize
        array('function', 'rocket_clean_domain'),               // WP Rocket
    );
    // A cache plugin may report its purge as a settings notice (WP Fastest Cache: "All cache files
    // have been deleted"), and WordPress then leaves out its own "Settings saved.", so the notices
    // are put back as they were, keeping only an error or warning a cache raised (a failed purge).
    global $wp_settings_errors;
    $notices = is_array($wp_settings_errors) ? $wp_settings_errors : array();
    foreach ($purges as $purge) {
        try {
            if ('action' === $purge[0]) {
                if (has_action($purge[1])) {
                    do_action($purge[1]);
                }
            } elseif (function_exists($purge[1])) {
                call_user_func($purge[1]);
            }
        } catch (\Throwable $e) {
            // Best effort: the next cache, and the save, go on.
        }
    }
    $added = is_array($wp_settings_errors) ? array_slice($wp_settings_errors, count($notices)) : array();
    $wp_settings_errors = $notices;
    foreach ($added as $notice) {
        if (isset($notice['type']) && in_array($notice['type'], array('error', 'warning'), true)) {
            $wp_settings_errors[] = $notice;
        }
    }
    // LiteSpeed server cache without its plugin: the server purges on this response header.
    if (!has_action('litespeed_purge_all') && !headers_sent()) {
        header('X-LiteSpeed-Purge: *');
    }
}

/**
 * pre_update_option_umbra_settings: a Save with nothing changed stops in update_option() before
 * the write and before update_option_umbra_settings, so the caches are cleared here; this is the
 * Save the upgrade note asks for. A changed value is cleared after the write instead.
 */
function umbra_purge_page_caches_on_unchanged_save($value, $old_value) {
    if ($value === $old_value || maybe_serialize($value) === maybe_serialize($old_value)) {
        umbra_purge_page_caches();
    }
    return $value;
}

/**
 * Replace the author display name with the generic label on the front end only
 * (not admin, not feeds). Returning the label instead of the real name means the
 * real username or display name is never written into the page source, not merely
 * hidden with CSS. Feeds are left to umbra_strip_feed_author.
 */
function umbra_hide_author_name($display_name) {
    if (is_admin() || is_feed()) {
        return $display_name;
    }
    return umbra_hide_author_label();
}

/**
 * Replace the real author name in the author-archive page title with the label,
 * so a theme (classic or block Query Title) that builds the title from the queried
 * author object still shows the label, not the real display name or nicename. Only
 * touches author archives on the front end; feeds and admin are left alone.
 */
function umbra_hide_author_archive_title($title) {
    if (is_admin() || is_feed() || !is_author()) {
        return $title;
    }
    $author = get_queried_object();
    if (!is_object($author)) {
        return $title;
    }
    $label   = umbra_hide_author_label();
    $needles = array();
    foreach (array('display_name', 'user_nicename', 'nickname', 'user_login') as $field) {
        if (!empty($author->$field) && is_string($author->$field)) {
            $needles[] = $author->$field;
        }
    }
    foreach (array_unique($needles) as $needle) {
        $title = str_replace($needle, $label, $title);
    }
    return $title;
}

function umbra_strip_feed_author($display_name) {
    return is_feed() ? '' : $display_name;
}

function umbra_disable_author_archives() {
    if (is_author()) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}

/* Run any pending settings migration first (gated by the schema version, so it
 * writes at most once per upgrade), then wire everything up at include time so
 * the feature registrar reads the migrated settings. */
umbra_maybe_upgrade();
umbra_register_features();
