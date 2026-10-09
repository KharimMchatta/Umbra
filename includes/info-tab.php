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
 * Umbra Info tab: who made Umbra, where its official releases are published, and its
 * legal notices.
 *
 * The notices on this tab are Umbra's Appropriate Legal Notices under section 0 of the
 * GNU GPL version 3: the copyright notice, that there is no warranty, that Umbra may be
 * conveyed under the License, and where to read the License. Section 5(d) of the License
 * requires a distributed modified version to keep displaying them, and term 1 of the
 * NOTICE file requires the author attribution to stay in them. They are written out here,
 * not read from a file, so they show even when LICENSE or NOTICE is missing.
 */

defined('ABSPATH') || exit;

/* The official home of Umbra: its releases are published only here. */
define('UMBRA_SOURCE_URL', 'https://github.com/KharimMchatta/Umbra');
define('UMBRA_AUTHOR_URL', 'https://github.com/KharimMchatta');

/** The open tab of the Umbra page: 'info', or 'settings' for anything else. */
function umbra_admin_tab() {
    $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
    return ($tab === 'info') ? 'info' : 'settings';
}

/** The tab bar under the page title, in WordPress's own nav-tab style. */
function umbra_render_page_tabs($active) {
    $tabs = array(
        'settings' => array('Settings', admin_url('admin.php?page=umbra')),
        'info'     => array('Info', admin_url('admin.php?page=umbra&tab=info')),
    );
    echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="Umbra sections">';
    foreach ($tabs as $key => $tab) {
        $current = ($key === $active);
        printf(
            '<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
            esc_url($tab[1]),
            $current ? ' nav-tab-active' : '',
            $current ? ' aria-current="page"' : '',
            esc_html($tab[0])
        );
    }
    echo '</nav>';
}

/* Name the Info tab in the browser title too, so the two tabs can be told apart. */
add_filter('admin_title', 'umbra_info_admin_title');
function umbra_info_admin_title($admin_title) {
    global $plugin_page;
    if ($plugin_page === 'umbra' && umbra_admin_tab() === 'info') {
        return 'Info &lsaquo; ' . $admin_title;
    }
    return $admin_title;
}

/** The text of a file that ships with Umbra (LICENSE, NOTICE), or '' when it is missing. */
function umbra_shipped_file($name) {
    $path = plugin_dir_path(UMBRA_FILE) . $name;
    if (!is_readable($path)) {
        return '';
    }
    $text = file_get_contents($path);
    return is_string($text) ? $text : '';
}

/* Render the Info tab. */
function umbra_render_info_tab() {
    $releases = UMBRA_SOURCE_URL . '/releases';
    $notice   = umbra_shipped_file('NOTICE');
    $license  = umbra_shipped_file('LICENSE');
    ?>
    <style>
        .umbra-info { max-width: 760px; }
        .umbra-info .form-table th { width: 160px; }
        .umbra-info details { margin: 0 0 12px; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff; }
        /* The admin theme colour where WordPress defines it, with the classic link blue as the fallback. */
        .umbra-info summary { padding: 10px 14px; cursor: pointer; font-weight: 600; color: var(--wp-admin-theme-color, #2271b1); border-radius: 4px; }
        .umbra-info summary:hover { color: var(--wp-admin-theme-color-darker-10, #135e96); background: #f6f7f7; }
        .umbra-info summary:focus-visible,
        .umbra-info pre:focus-visible { box-shadow: 0 0 0 2px var(--wp-admin-theme-color, #2271b1); outline: 2px solid transparent; }
        .umbra-info details[open] summary { border-bottom: 1px solid #c3c4c7; border-radius: 4px 4px 0 0; }
        .umbra-info pre { margin: 0; padding: 12px 14px; max-height: 420px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 13px; line-height: 1.5; }
        .umbra-info .umbra-missing { margin: 12px 14px; }
    </style>
    <div class="umbra-info">
        <h2 class="title">About Umbra</h2>
        <p>Umbra hardens WordPress against the reconnaissance that comes before an attack. It removes the fingerprints that reveal WordPress and its version, blocks user and author enumeration, answers the registration and lost-password forms the same way whether or not an account exists, and can move the login to an address only you know. The REST API, sitemaps, feeds and the block editor keep working.</p>
        <p>Umbra is built and maintained by Kharim Mchatta.</p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">Version</th>
                    <td><?php echo esc_html(UMBRA_VERSION); ?></td>
                </tr>
                <tr>
                    <th scope="row">Author</th>
                    <td>Kharim Mchatta, <a href="<?php echo esc_url(UMBRA_AUTHOR_URL); ?>">github.com/KharimMchatta</a></td>
                </tr>
                <tr>
                    <th scope="row">Official source</th>
                    <td><a href="<?php echo esc_url(UMBRA_SOURCE_URL); ?>">github.com/KharimMchatta/Umbra</a></td>
                </tr>
                <tr>
                    <th scope="row">License</th>
                    <td>GNU General Public License, version 3 or later, with additional terms (see below)</td>
                </tr>
            </tbody>
        </table>

        <h2 class="title">Download Umbra only from the official source</h2>
        <p>Umbra is published only on its <a href="<?php echo esc_url($releases); ?>">GitHub releases page</a>. A copy from anywhere else did not come from the author and may have been changed.</p>
        <p>From 2.7.5 on, every release lists the SHA-256 checksum of the plugin zip and of <code>htaccess-hardening.conf</code> in <code>SHA256SUMS</code>. Before you upload a new version to WordPress, check that the checksum of the file you downloaded matches the one listed there: run <code>shasum -a 256 &lt;file&gt;</code> on macOS, <code>sha256sum &lt;file&gt;</code> on Linux, or <code>Get-FileHash &lt;file&gt;</code> in Windows PowerShell.</p>

        <h2 class="title">License and legal notices</h2>
        <p><strong>Umbra by Kharim Mchatta</strong>, <a href="<?php echo esc_url(UMBRA_SOURCE_URL); ?>">github.com/KharimMchatta/Umbra</a>. Copyright &copy; 2026 Kharim Mchatta.</p>
        <p>Umbra is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version, with the additional terms under section 7 of that License shown below.</p>
        <p>Umbra is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.</p>
        <p>The additional terms, in short: every copy, and every work based on Umbra, keeps the copyright notice, the notice at the top of each file and the attribution "Umbra by Kharim Mchatta" with its address, including in these notices; nobody may present Umbra, or a work based on it, as their own; and a modified version must be marked as a modified version of Umbra by Kharim Mchatta, saying who changed it and when. The License grants no rights to the name "Umbra". The full License is in the <code>LICENSE</code> file and the additional terms in the <code>NOTICE</code> file, both in Umbra's plugin folder.</p>

        <details>
            <summary>Read the additional terms (NOTICE)</summary>
            <?php if ($notice !== '') : ?>
                <pre tabindex="0" aria-label="Umbra additional terms (NOTICE)"><?php echo esc_html($notice); ?></pre>
            <?php else : ?>
                <p class="umbra-missing">The NOTICE file is missing from this copy of Umbra. Read it at <a href="<?php echo esc_url(UMBRA_SOURCE_URL . '/blob/main/NOTICE'); ?>">github.com/KharimMchatta/Umbra</a>.</p>
            <?php endif; ?>
        </details>
        <details>
            <summary>Read the GNU General Public License, version 3 (LICENSE)</summary>
            <?php if ($license !== '') : ?>
                <pre tabindex="0" aria-label="GNU General Public License, version 3 (LICENSE)"><?php echo esc_html($license); ?></pre>
            <?php else : ?>
                <p class="umbra-missing">The LICENSE file is missing from this copy of Umbra. Read the License at <a href="https://www.gnu.org/licenses/gpl-3.0.html">gnu.org/licenses/gpl-3.0.html</a>.</p>
            <?php endif; ?>
        </details>
    </div>
    <?php
}
