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
 * Umbra protection status panel (v2.3.0).
 *
 * Three jobs, all best-effort and none ever fatal:
 *
 *  1. Capture runtime health on a front-end hook (`wp`). The only protection
 *     whose effectiveness depends on a third party is the Yoast author scrub:
 *     its filters attach at `init` only when Yoast is active, and that state is
 *     real only on a front-end request (Yoast's front-end presenters/schema are
 *     not loaded in wp-admin). So we record, from the front end, whether Yoast
 *     is active, its version, and whether our scrub callbacks actually attached.
 *
 *  2. Render a "Protection status" table on the settings page: each feature with
 *     Enabled (yes/no) and an Effective indicator (green / amber / red / off).
 *     For a feature with no dependency, Effective = Enabled. For the Yoast scrub
 *     and the custom login, Effective reflects the real dependency.
 *
 *  3. Raise a dashboard admin notice ONLY when an enabled protection is degraded
 *     (red), naming what lapsed. No notice when everything is green (no nagging).
 *
 * Plus an optional "Run self-test": a best-effort loopback that fetches the home
 * page, a recent post and an author archive and reports whether the generator
 * meta, a real author name/nicename, or the custom-login slug appears.
 */

defined('ABSPATH') || exit;

/* Where the front-end runtime snapshot is stored (not autoloaded). */
define('UMBRA_RUNTIME_OPTION', 'umbra_runtime_status');

/* Oldest Yoast release whose author filter names match what we hook. Yoast's
 * presenters/schema-graph API (the filters we rely on) is the 14.0+ shape. */
define('UMBRA_YOAST_MIN', '14.0');

/* -------------------------------------------------------------------------
 * 1. Runtime capture on the front end.
 * ---------------------------------------------------------------------- */

add_action('wp', 'umbra_capture_runtime_status', PHP_INT_MAX);

/**
 * Record, from a real front-end view, whether the Yoast author scrub attached.
 * Writes only when the snapshot actually changes, so it is not a per-request DB
 * write. Wrapped so a missing Yoast constant or option API never fatals.
 */
function umbra_capture_runtime_status() {
    if (is_admin()) {
        return;
    }

    $yoast_active = defined('WPSEO_VERSION');
    $attached     = false;
    if ($yoast_active) {
        // Our scrub callbacks are registered at `init` when Yoast is active.
        // has_filter() returning a priority (not false) proves they attached;
        // paired with the version range this stands in for "Yoast still fires
        // the filter names we rely on".
        $attached = (false !== has_filter('wpseo_schema_graph', 'umbra_yoast_scrub_schema_author'))
                 && (false !== has_filter('wpseo_frontend_presenters', 'umbra_yoast_remove_author_presenter'));
    }

    // Rank Math and All in One SEO (v2.5.2): same proof, from the filters their
    // scrubs register at `init` when the plugin is active.
    $rm_active  = defined('RANK_MATH_VERSION');
    $aio_active = defined('AIOSEO_VERSION');

    $snapshot = array(
        'captured_at'             => time(),
        'yoast_active'            => $yoast_active,
        'yoast_version'           => $yoast_active ? (string) WPSEO_VERSION : '',
        'yoast_scrub_attached'    => $attached,
        'rankmath_active'         => $rm_active,
        'rankmath_version'        => $rm_active ? (string) RANK_MATH_VERSION : '',
        'rankmath_scrub_attached' => $rm_active
            && (false !== has_filter('rank_math/sitemap/entry', 'umbra_rankmath_drop_author_entry'))
            && (false !== has_filter('rank_math/schema/validated_data', 'umbra_rankmath_scrub_schema_author')),
        'aioseo_active'           => $aio_active,
        'aioseo_version'          => $aio_active ? (string) AIOSEO_VERSION : '',
        'aioseo_scrub_attached'   => $aio_active
            && (false !== has_filter('aioseo_sitemap_author_archives', 'umbra_aioseo_no_author_entries'))
            && (false !== has_filter('aioseo_schema_output', 'umbra_aioseo_scrub_schema_author')),
    );

    $stored  = get_option(UMBRA_RUNTIME_OPTION, array());
    $changed = !is_array($stored)
        || empty($stored['captured_at'])
        || (bool) $stored['yoast_active'] !== $snapshot['yoast_active']
        || (string) $stored['yoast_version'] !== $snapshot['yoast_version']
        || (bool) $stored['yoast_scrub_attached'] !== $snapshot['yoast_scrub_attached'];
    // The v2.5.2 fields may be missing from a snapshot stored by an older version.
    foreach (array('rankmath_active', 'rankmath_version', 'rankmath_scrub_attached', 'aioseo_active', 'aioseo_version', 'aioseo_scrub_attached') as $field) {
        if (!$changed && (!array_key_exists($field, $stored) || $stored[$field] !== $snapshot[$field])) {
            $changed = true;
        }
    }
    if ($changed) {
        update_option(UMBRA_RUNTIME_OPTION, $snapshot, false);
    }
}

/** The stored front-end snapshot, or an empty array when none has run yet. */
function umbra_get_runtime_status() {
    $stored = get_option(UMBRA_RUNTIME_OPTION, array());
    return is_array($stored) ? $stored : array();
}

/* -------------------------------------------------------------------------
 * 2. Status model.
 * ---------------------------------------------------------------------- */

/**
 * Build the status rows: label, enabled (bool) and state (green|amber|red|off),
 * with a short note. Simple features map Effective to Enabled; the custom login
 * and the Yoast scrub carry their real dependency state.
 */
function umbra_status_rows() {
    $settings = umbra_get_settings();
    $features = umbra_feature_rows();
    $rows     = array();

    foreach ($features as $key => $row) {
        $enabled = !empty($settings[$key]);
        $rows[]  = array(
            'label'   => $row[0],
            'enabled' => $enabled,
            'state'   => $enabled ? 'green' : 'off',
            'note'    => '',
        );
    }

    // Hide post author (its own UI section; no external dependency).
    $hide = !empty($settings['hide_author']);
    $rows[] = array(
        'label'   => 'Hide post author',
        'enabled' => $hide,
        'state'   => $hide ? 'green' : 'off',
        'note'    => '',
    );

    // Custom login URL: effective only with a valid slug.
    $cl   = !empty($settings['custom_login']);
    $slug = isset($settings['custom_login_slug']) ? (string) $settings['custom_login_slug'] : '';
    if (!$cl) {
        $rows[] = array('label' => 'Custom login URL', 'enabled' => false, 'state' => 'off', 'note' => '');
    } elseif ($slug === '') {
        $rows[] = array('label' => 'Custom login URL', 'enabled' => true, 'state' => 'red', 'note' => 'Enabled but no slug is set, so the login is NOT hidden.');
    } else {
        $rows[] = array('label' => 'Custom login URL', 'enabled' => true, 'state' => 'green', 'note' => 'Serving the login at /' . $slug);
    }

    // Yoast author scrub: part of "Block author enumeration", dependent on Yoast.
    $rows[] = umbra_yoast_scrub_status_row($settings);

    // Rank Math and All in One SEO author scrubs (v2.5.2), same dependency model.
    $rows[] = umbra_seo_scrub_status_row($settings, 'Rank Math author scrub', 'rankmath', 'Rank Math');
    $rows[] = umbra_seo_scrub_status_row($settings, 'All in One SEO author scrub', 'aioseo', 'All in One SEO');

    return $rows;
}

/**
 * Status row for the Rank Math / All in One SEO author scrub, derived from the
 * front-end snapshot ($prefix: 'rankmath' or 'aioseo'). Red when the plugin is
 * active but the scrub did not attach (a plugin update changed its hooks); amber
 * when Rank Math's old sitemap cache could not be cleared.
 */
function umbra_seo_scrub_status_row($settings, $label, $prefix, $name) {
    if (empty($settings['author_enum'])) {
        return array('label' => $label, 'enabled' => false, 'state' => 'off', 'note' => 'Part of "Block author enumeration", which is off.');
    }

    $rt = umbra_get_runtime_status();
    if (empty($rt['captured_at']) || !array_key_exists($prefix . '_active', $rt)) {
        return array('label' => $label, 'enabled' => true, 'state' => 'amber', 'note' => 'Not checked yet. Load any front-end page once to run the runtime check.');
    }
    if (empty($rt[$prefix . '_active'])) {
        return array('label' => $label, 'enabled' => true, 'state' => 'green', 'note' => sprintf('%s is not active; there is nothing to scrub.', $name));
    }

    $ver = isset($rt[$prefix . '_version']) ? (string) $rt[$prefix . '_version'] : '';
    $ver = $ver !== '' ? $ver : '?';

    if (empty($rt[$prefix . '_scrub_attached'])) {
        return array(
            'label'   => $label,
            'enabled' => true,
            'state'   => 'red',
            'note'    => sprintf('%1$s %2$s is active but the Umbra author scrub did not attach. A %1$s update may have changed its hooks. Update Umbra.', $name, $ver),
        );
    }

    if ('rankmath' === $prefix) {
        $purge = get_option(UMBRA_SEO_PURGE_OPTION, array());
        if (is_array($purge) && array_key_exists('rankmath_ok', $purge) && !$purge['rankmath_ok']) {
            return array(
                'label'   => $label,
                'enabled' => true,
                'state'   => 'amber',
                'note'    => sprintf('Attached (Rank Math %s), but its old sitemap cache could not be cleared. Clear it once: Rank Math, Sitemap Settings, Save Changes.', $ver),
            );
        }
    }

    return array('label' => $label, 'enabled' => true, 'state' => 'green', 'note' => sprintf('Attached (%s %s).', $name, $ver));
}

/** The Yoast author-scrub status row, derived from the front-end snapshot. */
function umbra_yoast_scrub_status_row($settings) {
    $label = 'Yoast author schema scrub';

    if (empty($settings['author_enum'])) {
        return array('label' => $label, 'enabled' => false, 'state' => 'off', 'note' => 'Part of "Block author enumeration", which is off.');
    }

    $rt = umbra_get_runtime_status();
    if (empty($rt['captured_at'])) {
        return array('label' => $label, 'enabled' => true, 'state' => 'amber', 'note' => 'Not checked yet. Load any front-end page once to run the runtime check.');
    }
    if (empty($rt['yoast_active'])) {
        return array('label' => $label, 'enabled' => true, 'state' => 'green', 'note' => 'Yoast is not active; there is nothing to scrub.');
    }

    $ver = isset($rt['yoast_version']) ? (string) $rt['yoast_version'] : '';

    if (empty($rt['yoast_scrub_attached'])) {
        return array(
            'label'   => $label,
            'enabled' => true,
            'state'   => 'red',
            'note'    => sprintf('Yoast %s is active but the Umbra author scrub did not attach. A Yoast update may have changed its API. Update Umbra.', $ver !== '' ? $ver : '(unknown)'),
        );
    }

    if ($ver !== '' && version_compare($ver, UMBRA_YOAST_MIN, '<')) {
        return array(
            'label'   => $label,
            'enabled' => true,
            'state'   => 'amber',
            'note'    => sprintf('Yoast %s is older than the tested range (%s+). Confirm the author data is scrubbed.', $ver, UMBRA_YOAST_MIN),
        );
    }

    return array('label' => $label, 'enabled' => true, 'state' => 'green', 'note' => sprintf('Attached (Yoast %s).', $ver !== '' ? $ver : '?'));
}

/** Rows that are enabled but degraded (red). Drives the admin notice. */
function umbra_degraded_rows() {
    $degraded = array();
    foreach (umbra_status_rows() as $row) {
        if ($row['state'] === 'red') {
            $degraded[] = $row;
        }
    }
    return $degraded;
}

/* -------------------------------------------------------------------------
 * 3. Dashboard alert: only when something enabled is degraded.
 * ---------------------------------------------------------------------- */

add_action('admin_notices', 'umbra_degraded_notice');

function umbra_degraded_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $degraded = umbra_degraded_rows();
    if (empty($degraded)) {
        return; // Everything green: do not nag.
    }
    echo '<div class="notice notice-error"><p><strong>Umbra: a protection has lapsed.</strong></p><ul style="list-style:disc;margin-left:20px;">';
    foreach ($degraded as $row) {
        echo '<li>' . esc_html($row['label']) . ': ' . esc_html($row['note']) . '</li>';
    }
    echo '</ul><p>' . sprintf(
        'Review the <a href="%s">Umbra status panel</a>.',
        esc_url(admin_url('admin.php?page=umbra'))
    ) . '</p></div>';
}

/* -------------------------------------------------------------------------
 * 2b. Panel render (called from the settings page).
 * ---------------------------------------------------------------------- */

/** A coloured status dot + plain-text state. */
function umbra_status_badge($state) {
    $map = array(
        'green' => array('#46b450', 'Effective'),
        'amber' => array('#ffb900', 'Check'),
        'red'   => array('#dc3232', 'Degraded'),
        'off'   => array('#c3c4c7', 'Off'),
    );
    $m = isset($map[$state]) ? $map[$state] : $map['off'];
    return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr($m[0]) . ';margin-right:6px;vertical-align:middle;"></span>' . esc_html($m[1]);
}

function umbra_render_status_panel() {
    $rows = umbra_status_rows();
    ?>
    <h2 class="title">Protection status</h2>
    <p>Each protection and whether it is enabled and effective. Most features are effective whenever enabled; the Yoast, Rank Math and All in One SEO author scrubs and the custom login depend on a third party or a slug, so their effective state is checked at runtime.</p>
    <table class="widefat striped" style="max-width:760px;">
        <thead>
            <tr>
                <th scope="col">Protection</th>
                <th scope="col" style="width:90px;">Enabled</th>
                <th scope="col" style="width:130px;">Effective</th>
                <th scope="col">Notes</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row) : ?>
            <tr>
                <td><?php echo esc_html($row['label']); ?></td>
                <td><?php echo $row['enabled'] ? 'Yes' : 'No'; ?></td>
                <td><?php echo umbra_status_badge($row['state']); // phpcs: safe static markup ?></td>
                <td><?php echo esc_html($row['note']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/* -------------------------------------------------------------------------
 * 4. Optional self-test (best-effort loopback).
 * ---------------------------------------------------------------------- */

/**
 * Fetch the home page, a recent post and an author archive over the HTTP API and
 * report what a logged-out visitor would see. Best-effort: a loopback failure is
 * reported as unavailable, never as a pass, and nothing here is ever fatal.
 */
function umbra_run_self_test() {
    $out = array('targets' => array(), 'checks' => array());

    if (!function_exists('wp_remote_get')) {
        $out['error'] = 'The WordPress HTTP API is unavailable, so the loopback self-test cannot run.';
        return $out;
    }

    $settings = umbra_get_settings();

    // Build the target list.
    $targets = array('Home page' => home_url('/'));

    $recent    = get_posts(array('numberposts' => 1, 'post_status' => 'publish'));
    $author_id = 0;
    if (!empty($recent) && is_object($recent[0])) {
        $targets['Recent post'] = get_permalink($recent[0]->ID);
        $author_id = (int) $recent[0]->post_author;
    }

    // Author archive, built raw (not via get_author_posts_url, which our own
    // author_link filter rewrites to home) so we actually hit the author route.
    $nicename = $author_id ? (string) get_the_author_meta('user_nicename', $author_id) : '';
    if ($nicename !== '') {
        $targets['Author archive'] = home_url('/author/' . $nicename . '/');
    }

    // Fetch each target.
    $bodies = array();
    foreach ($targets as $name => $url) {
        $resp = wp_remote_get($url, array('timeout' => 5, 'redirection' => 5, 'sslverify' => false));
        if (is_wp_error($resp)) {
            $out['targets'][$name] = array('url' => $url, 'ok' => false, 'detail' => 'Loopback unavailable: ' . $resp->get_error_message());
            continue;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $bodies[$name] = $body;
        $out['targets'][$name] = array('url' => $url, 'ok' => true, 'detail' => 'HTTP ' . $code . ', ' . strlen($body) . ' bytes');
    }

    if (empty($bodies)) {
        $out['error'] = 'No pages could be fetched over the loopback, so the content checks could not run.';
        return $out;
    }

    $all = implode("\n", $bodies);

    // Check 1: generator meta.
    $gen = (bool) preg_match('/<meta[^>]+name=["\']generator["\']/i', $all);
    $out['checks'][] = array(
        'label'  => 'WordPress generator meta tag',
        'found'  => $gen,
        'verdict' => $gen ? 'PRESENT (the "Remove the WordPress version" protection may be off or overridden)' : 'absent',
    );

    // Check 2: real author name / nicename.
    $names = array();
    if ($author_id) {
        $dn = (string) get_the_author_meta('display_name', $author_id);
        if ($dn !== '') { $names[] = $dn; }
        if ($nicename !== '') { $names[] = $nicename; }
    }
    $names = array_values(array_unique(array_filter($names)));
    $leaked = array();
    foreach ($names as $n) {
        if ($n !== '' && stripos($all, $n) !== false) {
            $leaked[] = $n;
        }
    }
    $hide_on = !empty($settings['hide_author']);
    if (empty($names)) {
        $verdict = 'no author to check';
        $found   = false;
    } elseif (empty($leaked)) {
        $verdict = 'absent';
        $found   = false;
    } else {
        $found   = true;
        $verdict = $hide_on
            ? 'PRESENT despite "Hide post author" being on: ' . implode(', ', $leaked)
            : 'present (expected: "Hide post author" is off, so the theme byline shows the name): ' . implode(', ', $leaked);
    }
    $out['checks'][] = array('label' => 'Real author display name or nicename', 'found' => $found, 'verdict' => $verdict);

    // Check 3: custom-login slug leak.
    $slug = isset($settings['custom_login_slug']) ? (string) $settings['custom_login_slug'] : '';
    if (!empty($settings['custom_login']) && $slug !== '') {
        $slug_found = (stripos($all, '/' . $slug) !== false);
        $out['checks'][] = array(
            'label'   => 'Custom-login slug in page source',
            'found'   => $slug_found,
            'verdict' => $slug_found ? 'PRESENT (a theme/widget renders a login link to the slug; probing cannot discover it, but this link exposes it)' : 'absent',
        );
    } else {
        $out['checks'][] = array('label' => 'Custom-login slug in page source', 'found' => false, 'verdict' => 'not applicable (custom login off)');
    }

    return $out;
}

/** The self-test form and, when just submitted, its results. */
function umbra_render_self_test() {
    ?>
    <h2 class="title">Run self-test</h2>
    <p>A best-effort loopback check: fetches the home page, a recent post and an author archive as a logged-out visitor and reports whether the generator meta, a real author name, or the custom-login slug appears in the delivered HTML.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=umbra')); ?>">
        <?php wp_nonce_field('umbra_selftest', 'umbra_selftest_nonce'); ?>
        <input type="hidden" name="umbra_run_selftest" value="1" />
        <?php submit_button('Run self-test', 'secondary', 'umbra_selftest_submit', false); ?>
    </form>
    <?php

    if (empty($_POST['umbra_run_selftest'])) {
        return;
    }
    if (!current_user_can('manage_options') || !check_admin_referer('umbra_selftest', 'umbra_selftest_nonce')) {
        return;
    }

    $result = umbra_run_self_test();

    echo '<h3>Self-test results</h3>';

    if (!empty($result['error'])) {
        echo '<div class="notice notice-warning inline"><p>' . esc_html($result['error']) . '</p></div>';
    }

    if (!empty($result['targets'])) {
        echo '<p><strong>Pages fetched</strong></p><ul style="list-style:disc;margin-left:20px;">';
        foreach ($result['targets'] as $name => $t) {
            echo '<li>' . esc_html($name) . ' (' . esc_html($t['url']) . '): ' . esc_html($t['detail']) . '</li>';
        }
        echo '</ul>';
    }

    if (!empty($result['checks'])) {
        echo '<table class="widefat striped" style="max-width:760px;"><thead><tr><th scope="col">Check</th><th scope="col">Result</th></tr></thead><tbody>';
        foreach ($result['checks'] as $c) {
            echo '<tr><td>' . esc_html($c['label']) . '</td><td>' . esc_html($c['verdict']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
