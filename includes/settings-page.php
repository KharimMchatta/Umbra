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
 * Umbra settings page: one option array, registered with the Settings API,
 * rendered as a plain-language description + toggle per feature, with the
 * custom-login slug field and a Save button. Nonce and capability come from the
 * Settings API (settings_fields / options.php checks manage_options).
 */

defined('ABSPATH') || exit;

/* Register the single option with its sanitize callback. */
add_action('admin_init', 'umbra_register_setting');
function umbra_register_setting() {
    register_setting(
        'umbra',
        UMBRA_OPTION,
        array(
            'type'              => 'array',
            'sanitize_callback' => 'umbra_sanitize_settings',
            'default'           => umbra_default_settings(),
        )
    );
}

/* Add the admin menu as its own top-level item. */
add_action('admin_menu', 'umbra_admin_menu');
function umbra_admin_menu() {
    add_menu_page(
        'Umbra',
        'Umbra',
        'manage_options',
        'umbra',
        'umbra_render_settings_page',
        'dashicons-shield'
    );
}

/* A "Settings" action link on the plugin's row in the Plugins list. */
add_filter('plugin_action_links_' . plugin_basename(UMBRA_FILE), 'umbra_plugin_action_links');
function umbra_plugin_action_links($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=umbra')) . '">' . esc_html__('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * The feature rows: key => [label, description]. The order is the page order.
 * These drive both the UI and keep the descriptions in one place.
 */
function umbra_feature_rows() {
    return array(
        'generator' => array(
            'Remove the WordPress version (generator)',
            'Removes the <meta name="generator"> tag and the WordPress version from feeds, so the core version is not advertised. Also removes the generator tag, version numbers and credit comments that Yoast SEO, Rank Math and All in One SEO print in the page head.',
        ),
        'strip_ver' => array(
            'Strip ?ver= from front-end assets',
            'Removes the ?ver= query string from front-end CSS and JS URLs, which otherwise leak core and plugin versions. The admin and block editor keep their cache-busting.',
        ),
        'head_links' => array(
            'Remove fingerprinting head links',
            'Removes RSD, Windows Live Writer, shortlink, adjacent/rel, oEmbed discovery and the REST API link tags from the page head.',
        ),
        'emoji' => array(
            'Remove emoji detection scripts',
            'Removes the emoji detection script and styles (front end and admin) and the s.w.org DNS-prefetch hint.',
        ),
        'login_error' => array(
            'Generic login error message',
            'Replaces the login error so it never reveals whether the username or the password was wrong.',
        ),
        'neutral_forms' => array(
            'Neutral registration and lost-password responses',
            'The registration and lost-password forms answer the same way whether or not a username or email exists, so they cannot be used to confirm accounts. A lost-password request for an unknown account shows the usual "check your email" page and sends nothing; a registration that reuses an existing username or email shows the usual "registration complete" page and creates nothing. Trade-off: a visitor who mistypes gets no email instead of an error.',
        ),
        'rest_users' => array(
            'Block REST user enumeration',
            'Hides the /wp-json/wp/v2/users endpoints from unauthenticated requests. Logged-in requests (the block editor, wp-admin) keep them, so the back end is unaffected.',
        ),
        'author_enum' => array(
            'Block author enumeration and author leaks',
            'Blocks user/author ID, slug and nicename (login) enumeration via the author params (including the %61uthor=, author[]= and mixed-case bypasses), REST users, REST _embed, oEmbed, feeds, sitemaps (core, Yoast, Rank Math and All in One SEO), author-archive links and the author schema, author meta and "Written by" data of Yoast, Rank Math and All in One SEO. Author-archive links point home, so no byline href carries the nicename. A display-name byline printed by the theme is site content, not an enumeration leak; use "Hide post author" below for maximum stealth, and prefer a separate non-admin author account.',
        ),
        'x_pingback' => array(
            'Remove the X-Pingback header',
            'Removes the X-Pingback response header, a WordPress tell and a pingback-abuse target.',
        ),
        'xmlrpc' => array(
            'Disable XML-RPC',
            'Turns off the whole XML-RPC interface and its pingback methods (a brute-force and pingback-DDoS vector). Breaks Jetpack and the official WordPress mobile app, which use XML-RPC.',
        ),
        'strip_feed_authors' => array(
            'Strip author names from feeds',
            'Removes the author display name (the <dc:creator> leak) from RSS/Atom feeds. Feeds still work and still list their items.',
        ),
        'disable_feeds' => array(
            'Disable RSS/Atom feeds',
            'Replaces every feed with a short notice. Off by default: a feed is not a fingerprint, so feeds stay on and simply no longer leak the version.',
        ),
        'disable_author_archives' => array(
            'Disable author archive pages',
            'Redirects every /author/<slug>/ archive page home for maximum stealth. Off by default because it changes the front end; the author-enumeration block above already stops the id-to-name pivot.',
        ),
    );
}

/* Render the settings page. */
function umbra_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $settings = umbra_get_settings();
    $rows     = umbra_feature_rows();
    ?>
    <div class="wrap">
        <h1>Umbra</h1>
        <?php
        // A top-level menu page does not get WordPress's automatic settings notices
        // (only pages under Settings do), so show "Settings saved." and the login-slug
        // validation errors here, or a rejected slug would switch the custom login off silently.
        settings_errors();

        // Two tabs: Settings (everything below) and Info (author, official source, legal notices).
        $tab = umbra_admin_tab();
        umbra_render_page_tabs($tab);
        if ($tab === 'info') :
            umbra_render_info_tab();
        else :
        ?>
        <p>WordPress hardening against fingerprinting and user/author enumeration. Each feature below is a toggle; the defaults match the hardened baseline. Pair this with the Apache rules in <code>htaccess-hardening.conf</code> for web-server-layer coverage.</p>

        <?php umbra_render_status_panel(); ?>

        <form method="post" action="options.php">
            <?php settings_fields('umbra'); ?>

            <h2 class="title">Fingerprint and enumeration protections</h2>
            <table class="form-table" role="presentation">
                <tbody>
                <?php foreach ($rows as $key => $row) :
                    list($label, $description) = $row; ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($label); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(UMBRA_OPTION); ?>[<?php echo esc_attr($key); ?>]"
                                       value="1" <?php checked(!empty($settings[$key])); ?> />
                                <?php echo esc_html($description); ?>
                            </label>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 class="title">Hide post author</h2>
            <p>Replaces the author name shown on posts and author pages with a generic label, so the real username or display name is never exposed in the page. The name stays in the database for ownership.</p>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">Hide post author</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(UMBRA_OPTION); ?>[hide_author]"
                                       value="1" <?php checked(!empty($settings['hide_author'])); ?> />
                                Replace the author name on posts and author pages with the label below. Off by default; comment author names are not affected.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="umbra_hide_author_label">Author label</label></th>
                        <td>
                            <input type="text" id="umbra_hide_author_label"
                                   name="<?php echo esc_attr(UMBRA_OPTION); ?>[hide_author_label]"
                                   value="<?php echo esc_attr($settings['hide_author_label']); ?>"
                                   class="regular-text" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>" />
                            <p class="description">Shown in place of the author name. Defaults to the site name if left empty.</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2 class="title">Custom login URL</h2>
            <p>Hide <code>wp-admin</code> and <code>wp-login.php</code> behind a secret path. Off by default. When on, <code>wp-login.php</code> and the logged-out <code>/wp-admin</code> return a 404, and the login is served only at your slug. Deactivating the plugin restores the stock login immediately.</p>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">Enable custom login URL</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(UMBRA_OPTION); ?>[custom_login]"
                                       value="1" <?php checked(!empty($settings['custom_login'])); ?> />
                                Serve the login only at the slug below. Requires a slug to take effect.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="umbra_login_slug">Login slug</label></th>
                        <td>
                            <code><?php echo esc_html(trailingslashit(home_url('/'))); ?></code>
                            <input type="text" id="umbra_login_slug"
                                   name="<?php echo esc_attr(UMBRA_OPTION); ?>[custom_login_slug]"
                                   value="<?php echo esc_attr($settings['custom_login_slug']); ?>"
                                   class="regular-text" placeholder="e.g. vaultgate" />
                            <p class="description">Letters, numbers and dashes only. Cannot be <code>wp-admin</code>, <code>wp-login</code>, <code>admin</code> or the slug of an existing post or page.</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button('Save Changes'); ?>
        </form>

        <?php umbra_render_self_test(); ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Why a login slug cannot be used, as array(error code, message), or null when it
 * can: a reserved name, characters outside a-z, 0-9, - and _, or the slug of any
 * existing post, page or attachment in any status, child pages included (deleting a
 * parent moves its children to the top level). With /%postname%/ permalinks a post's
 * address is /<slug>/ too, so a match
 * would publish the login URL in the post's links, feed and sitemap.
 *
 * $active is for the slug already in use: only an item that is public now counts.
 * While the custom login is on, the reservation in custom-login.php moves a draft or
 * scheduled item off the address as it goes public, so one holding the name must not
 * switch the login off.
 */
function umbra_login_slug_problem($slug, $active = false) {
    global $wpdb;
    if (in_array($slug, array('wp-admin', 'wp-login', 'wp-login-php', 'admin'), true)) {
        return array('umbra_slug_reserved', 'That login slug is reserved. Choose another.');
    }
    // sanitize_title keeps letters outside a-z as percent-encoded bytes (ログイン becomes
    // %e3%83%ad...), and the login is matched against the decoded request path, so such a
    // slug would never match and the admin would be locked out.
    if (strpos($slug, '%') !== false) {
        return array('umbra_slug_chars', 'Use only Latin letters (a-z), numbers, hyphens and underscores in the login slug.');
    }
    // htaccess-hardening.conf denies any path whose name contains "wp-config" (403), so the login
    // would never be reached. Its other rules all need a dot, which sanitize_title removes.
    if (stripos($slug, 'wp-config') !== false) {
        return array('umbra_slug_blocked', 'That login slug contains "wp-config", which the Umbra .htaccess rules block. Choose another.');
    }
    // The web server answers a real file or folder at the site root, and many hosts map
    // server pages (/icons/, /server-status/, /cgi-bin/, /cpanel/) before WordPress runs, so the
    // login would never be reached; the feeds, the core login and dashboard redirects and the
    // REST root would put it at a well-known address.
    if (umbra_login_slug_is_site_address($slug)) {
        return array('umbra_slug_route', 'That login slug is already an address on this site (a folder, a server or hosting panel page, a feed, a WordPress redirect or the REST API). Choose another.');
    }
    $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ('post', 'page', 'attachment')";
    if ($active) {
        $sql .= " AND (post_status = 'publish' OR post_type = 'attachment')";
    }
    if ($wpdb->get_var($wpdb->prepare($sql . ' LIMIT 1', $slug))) {
        return array('umbra_slug_page', 'That login slug is already used by a post or page. Choose another.');
    }
    return null;
}

/** True when /<slug>/ is already answered by the web server or a well-known WordPress route. */
function umbra_login_slug_is_site_address($slug) {
    $routes = array(
        // WordPress: folders, REST root, the feeds at the root, and the addresses core
        // redirects to the login or the dashboard.
        'wp-content', 'wp-includes', 'wp-json', 'feed', 'rss', 'rss2', 'atom', 'rdf', 'embed', 'login', 'dashboard',
        // Apache and Debian aliases.
        'icons', 'server-status', 'server-info', 'cgi-bin', 'manual', 'javascript',
        // Hosting panels (cPanel, Plesk) and the webmail and database tools they map on every site.
        'cpanel', 'whm', 'webmail', 'webdisk', 'securecpanel', 'securewhm', 'securewebmail', 'cgi-sys', 'mailman',
        'pipermail', 'phpmyadmin', 'roundcube', 'squirrelmail', 'awstats', 'plesk-stat',
    );
    if (function_exists('rest_get_url_prefix')) {
        $routes[] = rest_get_url_prefix();
    }
    if (in_array($slug, $routes, true)) {
        return true;
    }
    $dirs = array(ABSPATH);
    if (function_exists('get_home_path')) {
        $dirs[] = get_home_path();
    }
    foreach ($dirs as $dir) {
        // is_link too: file_exists() is false for a symlink whose target is missing, and the
        // server answers that path itself (403 on Apache).
        $path = trailingslashit($dir) . $slug;
        if (file_exists($path) || is_link($path)) {
            return true;
        }
    }
    return false;
}

/**
 * Sanitize and validate on save. Every boolean becomes 0/1; the slug is run
 * through sanitize_title and rejected if empty-while-enabled, reserved, or an
 * existing post or page slug. Invalid input raises a settings error and is corrected,
 * never stored raw. A rejected slug keeps a working custom login at its previous slug.
 */
function umbra_sanitize_settings($input) {
    if (!is_array($input)) {
        $input = array();
    }

    $out = array();

    foreach (umbra_bool_keys() as $key) {
        $out[$key] = empty($input[$key]) ? 0 : 1;
    }

    // Hide-author label: plain text, falls back to the site name when left empty.
    $label = isset($input['hide_author_label']) ? sanitize_text_field((string) $input['hide_author_label']) : '';
    if ($label === '') {
        $label = get_bloginfo('name');
    }
    $out['hide_author_label'] = $label;

    // Slug: lowercase, dashes, no reserved names, no collision with a post or page.
    $slug = isset($input['custom_login_slug']) ? sanitize_title((string) $input['custom_login_slug']) : '';

    // The slug in use right now (custom login on), checked against public items only.
    $previous    = umbra_get_settings();
    $active_slug = (!empty($previous['custom_login']) && isset($previous['custom_login_slug'])) ? (string) $previous['custom_login_slug'] : '';

    $problem = ($slug !== '') ? umbra_login_slug_problem($slug, $active_slug !== '' && $slug === $active_slug) : null;
    if ($problem) {
        add_settings_error('umbra', $problem[0], $problem[1], 'error');
        $slug = '';
    }

    // A rejected or empty slug never switches a working custom login off by itself:
    // while the box stays ticked, the previous slug is kept, so one typo cannot put
    // the stock wp-login.php back online. Unticking the box is how to turn it off.
    if ($out['custom_login'] && $slug === '' && $active_slug !== '' && !umbra_login_slug_problem($active_slug, true)) {
        add_settings_error('umbra', 'umbra_slug_kept', 'The login slug was not changed. The custom login stays on at your previous slug: ' . esc_html($active_slug), 'warning');
        $slug = $active_slug;
    }

    $out['custom_login_slug'] = $slug;

    // Enabling the custom login with no valid slug is a no-op and would lock nothing;
    // turn it back off and say so, so the admin is never misled into thinking it is active.
    if ($out['custom_login'] && $out['custom_login_slug'] === '') {
        add_settings_error('umbra', 'umbra_slug_required', 'Enter a valid login slug to enable the custom login URL. It has been left off.', 'error');
        $out['custom_login'] = 0;
    }

    // A refused slug never wipes the stored one either: with the custom login off, the previous
    // slug stays in the field for the next time the login is turned on (it is checked again then).
    if ($problem && !$out['custom_login'] && isset($previous['custom_login_slug'])) {
        $out['custom_login_slug'] = (string) $previous['custom_login_slug'];
    }

    return $out;
}
