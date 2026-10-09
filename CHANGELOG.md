# Changelog

Versioning: MAJOR.MINOR.SECURITY. MAJOR is a major change and resets everything (2.0.0). MINOR counts updates and non-security bug fixes. SECURITY goes up by 1 for each release that contains security fixes and does not reset when MINOR goes up.
Example: 1.0.0, 1.1.0, 1.2.1 (updates plus a security fix), 1.2.2 (security fix only), 1.3.2 (updates only), 2.0.0 (major change).

## [2.8.5] - 2026-10-09

Update only, so MINOR +1 (SECURITY stays at 5). Documentation wording in the changelog and code comments tidied for the public repository. No code, behaviour, setting or default changed; the only code difference from 2.7.5 is the version number.

Other changes:

- Changelog and code comments: wording tidied (`umbra.php`, `includes/custom-login.php`).

## [2.7.5] - 2026-10-09

New features and a security fix, so MINOR +1 and SECURITY +1. Umbra gets a license, an Info tab that credits its author and names its official source, and release checksums, and wordpress.org no longer offers updates for Umbra.

Security:

- **Updates come only from Umbra's official source.** Umbra had no `Update URI` header, so the wordpress.org update check treated it like any plugin listed there. The name `umbra` is not taken on wordpress.org (checked 2026-10-09), but a plugin published there under that name later would have been offered to every Umbra site as an update, and installing it would replace Umbra with someone else's code. The `Update URI` header now names the GitHub repository, and wordpress.org returns no update for a plugin whose Update URI points elsewhere (WordPress 5.8 and later). Present in every release.

Features:

- **License.** Umbra is licensed under the GNU General Public License, version 3 or later (`LICENSE`), with additional terms under section 7 of that License (`NOTICE`): keep the author attribution, do not misrepresent the origin, and mark a modified version as a modified version of Umbra by Kharim Mchatta; no rights are granted to the name "Umbra". Every source file carries the copyright and license notice, and the plugin header names the license.
- **Info tab.** The Umbra page has two tabs, Settings and Info. Info shows what Umbra does, the version, the author, the official source, how to check a download, and the legal notices, with the full additional terms and License readable in place. The Settings tab is unchanged.
- **Release checksums.** Each release adds `SHA256SUMS`, the SHA-256 checksum of the plugin zip and of `htaccess-hardening.conf`, and the README explains how to check them. `tools/build-release.sh` builds the release assets from a tag; the plugin zip now includes `LICENSE` and `NOTICE`.

Other changes:

- `htaccess-hardening.conf` gains a copyright and license comment at the top. Its rules are unchanged, so there is nothing to re-paste.
- README: the official source, how to check a download, and the license.

## [2.6.4] - 2026-10-08

Security fixes and non-security fixes, so MINOR +1 and SECURITY +1. Fixes a login copy left in page caches after the login slug changes and lets the login open at any capitalisation of the slug (both found in a real-site test), and closes the two 2.5.3 known limitations about login slugs that lock the admin out or sit at a well-known address.

Security:

- **A changed login slug takes effect at once behind a page cache.** Page caches skip `wp-login.php` by its file name, but the login served at the slug looks like an ordinary page to them, and most of them ignore the no-store header WordPress sends with it. So the first logged-out visit stored the login page, and after the slug was changed the old address kept serving that copy until the cache expired; logging in from a stored copy also bounced back to the form on the first try, because a copy cannot set WordPress's test cookie. The login at the slug and Umbra's own 404 pages now tell caches not to store them (the `DONOTCACHEPAGE` constant and the `X-LiteSpeed-Cache-Control`, `X-Accel-Expires` and `cf-edge-cache` headers). Every save of the Umbra settings (also a Save with nothing changed), activating Umbra and deactivating it now clear the page cache through the cache plugin's own purge function: LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Rocket, WP Fastest Cache, Cache Enabler, Breeze, WP-Optimize, Hummingbird, Nginx Helper and Super Page Cache, plus a LiteSpeed server cache running without its plugin. SiteGround Optimizer already clears its cache on any settings save. The settings page still says "Settings saved." when a cache plugin reports its own purge, and still shows a cache plugin's error or warning if its purge fails.
- **A login slug containing `wp-config` is refused.** `htaccess-hardening.conf` denies any address whose name contains `wp-config`, so such a slug was accepted and the login then answered 403 while `wp-login.php` stayed hidden. The settings page now refuses it and keeps the previous slug. The other rules in the file need a dot, which a slug cannot contain.
- **Hosting panel and server addresses are refused as login slugs.** On many hosts `/cpanel/`, `/whm/`, `/webmail/`, `/webdisk/`, `/phpmyadmin/`, `/roundcube/`, Debian's `/javascript/` and similar addresses are answered before WordPress runs, so the login was unreachable there. They are now refused like the folder and server-page names in 2.5.3.
- **Core feed and redirect addresses are refused as login slugs.** `rss`, `rss2`, `atom`, `rdf`, `embed`, `login` and `dashboard` put the login at an address bots already probe. They are now refused.
- **A broken symlink at the site root is refused as a login slug.** A symlink whose target is missing is not seen as a file, so its name was accepted, and Apache then answered the login address with 403. It is now refused like any other root file or folder.
- **A trailing backslash no longer reaches the login.** The slug was matched after stripping trailing slashes and backslashes, so `/<slug>\` (or `/<slug>%5C`) served the login, and a server rule that protects the slug's address (an IP allow-list or a password on `/<slug>/`) did not cover it: a login there succeeded. Only trailing slashes are stripped now; the backslash forms answer 404. Present in every release (checked back to 2.4.0). Found by the independent check.

Fixes:

- **A refused login slug no longer wipes the stored slug while the custom login is off.** Turning the custom login on with a refused slug, or saving a refused slug with it off, stored an empty slug, so the slug saved earlier had to be typed again. The previous slug now stays in the field (it is checked again when the login is turned on). Present in every release with the custom login (checked back to 2.4.0). Found by the independent check.
- **Any capitalisation of the slug leads to the login.** The slug is saved in lower case and was matched exactly, so typing `/Vaultgate/` for the slug `vaultgate` showed the theme's "page not found", which looks just like a broken login. Any other capitalisation is now redirected to the slug as saved (`/Vaultgate/` to `/vaultgate/`, `/?Vaultgate` to `/?vaultgate`, the rest of the address kept), so a server rule that protects the slug's own address and password-reset links keep working; `wp-login.php`, `/wp-admin/` and every other address answer as before. Because every capitalisation now leads to the login, a stored item address (guid) that is the login URL in another capitalisation (`/Vaultgate/`) is replaced like the login URL itself, on save and by the settings sweep. Found in a real-site test.

Upgrade note: open the Umbra settings and press Save once after updating. This clears the page cache, including a login copy stored under an earlier version. A login slug saved under 2.5.3 that is now refused keeps working until that Save; the Save then refuses it and turns the custom login off with an error (the stock login comes back), so choose a new slug and turn it on again. If your host or CDN runs its own cache outside WordPress, purge it once after changing the login slug.

Known limitations (open):

- A guid that is a login address with an extra query string or an encoded character (`/<slug>/?x=1`, `/%76<rest of slug>/`) still serves the login but is not treated as the login URL, so it is not replaced. These guids only exist when an importer or another plugin stores them. Also present in 2.5.3.
- Cloudflare APO through the official Cloudflare plugin: new copies of the login are no longer stored, but that plugin has no public purge function, so purge everything in Cloudflare once after changing the login slug.

## [2.5.3] - 2026-10-08

Security fixes only, so SECURITY +1. Closes the 2.5.2 known limitation about the login URL left in stored item addresses (guids), and an admin lockout found while checking it.

Security:

- **Attachments and private posts no longer keep the login URL as their guid.** Only items saved as published were checked. Attachments never pass WordPress's status change, so an attachment whose guid was the login URL showed it in the public REST media route. Attachments are now checked when they are added or edited, and private posts on every save.
- **http/https and www copies of the login URL are recognised.** The check compared the guid with the login URL exactly, so `https://`, `www.` or a missing trailing slash slipped through and the login URL stayed in the feed and the REST API. The scheme is now ignored, and so are a leading `www.` and a trailing slash; the host, port, path, query and fragment must still match, so near-miss addresses such as `/<slug>-2/` or `/<slug>/?x=1` are left alone.
- **Items stored before the login was set are swept.** Items stored before the custom login was turned on, before the slug changed, or while Umbra was inactive kept the login URL as their guid until their next save. Every save of the settings with the custom login on (also a Save with nothing changed), and activating Umbra, now replace every such guid in any post type or status straight away: one database query per save, plus an update and a cache re-read per item found.
- **A login slug in a non-Latin script is refused instead of locking the admin out.** WordPress stores letters outside a-z as percent-encoded bytes (`ログイン` became `%e3%83%ad...`), and the login is matched against the decoded address, so the login answered only at a double-encoded address while `wp-login.php` and `/wp-admin/` stayed hidden. The settings page now refuses such a slug ("Use only Latin letters (a-z), numbers, hyphens and underscores in the login slug."); a working custom login stays on at its previous slug, and one that was off stays off with the stock login available. Accented Latin letters still work (`Café` becomes `cafe`). Present since the custom login was added.
- **Post statuses from other plugins are judged by their flags, not their names.** A post in a public status registered by another plugin (an "archived" status, say) kept a login-URL guid through its saves and showed it in the anonymous REST API and the feed. Public statuses are now handled like published ones (slug moved off the login address, guid replaced) and private-flag statuses like private ones (guid replaced).
- **Switching the permalink structure sweeps.** The login URL changes form when the permalinks change (`/<slug>/` or `/?<slug>`), so a guid stored in the new form became the login URL without being replaced. A permalink switch now runs the same sweep.
- **A login slug that is already an address on the site is refused.** A slug naming a real folder or file at the site root (`wp-content`, `wp-includes`) or a server page many hosts map before WordPress (`icons`, `server-status`, `server-info`, `cgi-bin`, `manual`) was accepted with "Settings saved.", and the login was then unreachable while `wp-login.php` stayed hidden; `feed` and `wp-json` put the login on the site feed or the REST API root. The settings page now refuses them ("That login slug is already an address on this site...") and keeps the previous slug. Present since the custom login was added.

Upgrade note: on a site already running the custom login, open the Umbra settings and press Save once after updating, so the sweep runs. Updating a plugin does not run its activation step. A site that saved a non-Latin login slug under 2.5.2 still answers only at the double-encoded address until that Save; the Save then refuses the slug and turns the custom login off (the stock login comes back), so choose a new Latin slug and turn it on again. The same happens to a slug saved under 2.5.2 that names a folder, a server page, the feed or the REST API.

Known limitations (open):

- A guid that is a login address with an extra query string or an encoded character (`/<slug>/?x=1`, `/%76<rest of slug>/`) still serves the login but is not treated as the login URL, so it is not replaced. These guids only exist when an importer or another plugin stores them. Also present in 2.5.2.
- A login slug containing `wp-config` is accepted, and `htaccess-hardening.conf` then answers the login address with 403 (it denies any file name containing `wp-config`). Do not use `wp-config` in the slug. Also present in 2.5.2.
- Hosting control-panel addresses (`cpanel`, `whm`, `webmail`) and core feed or admin-redirect addresses (`rss`, `rss2`, `atom`, `rdf`, `embed`, `login`, `dashboard`) are accepted as slugs; the first group makes the login unreachable on hosts that map them, the second puts the login at a well-known address. Use a slug of your own. Also present in 2.5.2.

## [2.5.2] - 2026-10-07

Closes the enumeration and exposure gaps found when the plugin was checked against a list of common attacker techniques, plus the bugs found while testing them and the issues found by repeated independent review. Re-paste `htaccess-hardening.conf`: the block shipped with 2.4.0 is removed by WordPress on the next permalink save (see Security). It contains features and security fixes, so MINOR goes up and SECURITY goes up by 1, giving 2.5.2.

Numbering note: 2.3.0 and 2.4.0 were mis-numbered. Under this scheme the security digit never resets when MINOR goes up, and 2.2.1 already carried a security fix, so they should have been 2.3.1 and 2.4.1. They are left as published; 2.5.2 is worked out from the scheme.

Security:

- **Rank Math and All in One SEO author sitemaps removed.** With "Block author enumeration" on, each plugin's author sitemap is dropped from its sitemap index and has no entries, and Rank Math's HTML sitemap lists no authors, so neither plugin publishes author archive addresses (which carry the login name). Guarded: a no-op when the plugin is absent. Hooks confirmed against Rank Math 1.0.280 and All in One SEO 5.0.2.1 source.
- **Cached author sitemap data cleared.** Author sitemap data either plugin cached before the protection was on is cleared once whenever the active SEO plugin versions change, on activation and after any Umbra settings change, and again whenever cached author data turns up while the protection is on, so nothing cached while Umbra was not running is served. The status panel says if the Rank Math clean-up did not work. Found by the independent review.
- **Rank Math and All in One SEO author schema and meta scrubbed.** The author's name, author address and profile image are removed from both plugins' structured data, author meta and "Written by" data, wherever the plugins output them (page head, REST API and Rank Math's headless head data). The site's own Person (when the site represents a person) and the rest of the schema are kept. Same protection the Yoast integration already had. Partly found by the independent review.
- **Neutral registration and lost-password responses (new toggle, on by default).** The lost-password form answered a real account with a redirect to "check your email" and an unknown one with an error page, and the registration form did the reverse for an existing username or email, so both confirmed whether an account exists (the generic login error hid the wording but not the different response). Now an unknown account on lost password, and an existing username or email on registration, get exactly the response a success gets (the same redirect core sends), and nothing is sent or created. Other errors (an empty field, a malformed email, a captcha) are still shown and say nothing about existence. Only the wp-login.php forms are affected, never a programmatic call.
- **`wp-register.php` returns 404** with the custom login on (plugin) and at the server layer (`htaccess-hardening.conf`), instead of a 301 to the registration page.
- **`wp-config.php` backup and editor-swap copies denied** at the server layer (`htaccess-hardening.conf`): any file name containing `wp-config`, case-insensitive (`wp-config.php.bak`, `.old`, `.save`, `~`, `.wp-config.php.swp`, `#wp-config.php#`, `backup-wp-config.php` and similar). A copy is not run as PHP, so it would otherwise be served as plain text with the database password. Present and absent copies get the same 403, so a probe cannot tell which exist.
- **`debug.log` denied** at the server layer (`htaccess-hardening.conf`), including rotated copies, with the same no-oracle behaviour.
- **A login slug that matches an existing post is rejected.** The custom login slug was checked only against pages, so a slug equal to a post's slug (published or draft) was accepted. With `/%postname%/` permalinks that post's address is the login URL, so the login was published in the post's links, the feed and the sitemap. The slug is now rejected when it matches any post, page or attachment in any status, with an error. Found by the independent review.
- **Posts created later cannot take the login address.** The check above runs when settings are saved, so a post, top-level page or attachment published afterwards (or a draft or trashed post restored and published) could still take the slug and publish the login URL. While the custom login is on, WordPress now gives such an item the next free address (`<slug>-2`) through its own slug checks, and an item that goes public on the slug through `wp_publish_post()`, or is converted to a post or page with `set_post_type()` (a reusable block named after the slug, say), both of which skip those checks, is moved the same way; a guid that equals the login URL is replaced whenever the item is saved as published, even after WordPress has moved its slug, so feeds and the REST API do not carry it. Child pages and attached files are included, because deleting a parent moves them to the top level with no slug check, and the settings save also rejects a slug that a child page already holds. Found by the independent review.
- **Encoded `?` in the path no longer reaches registration through the slug.** With the server rule removed, `/<slug>%3F/../wp-signup.php` was treated as the login slug while Apache ran `wp-signup.php`, which then showed the login form. The slug match now cuts the query at the first literal `?` before decoding, the same as the hidden-endpoint check, and no longer reads a leading `//` as a host name (Apache merges the slashes and runs the file after them). Both needed the slug already and disclosed nothing. Found by the independent review.
- **`htaccess-hardening.conf` is no longer wiped by WordPress.** Its second line quoted WordPress's own start-marker text, and WordPress looks for its markers anywhere in a line, so saving permalinks (even just opening Settings > Permalinks) replaced the whole Umbra block with WordPress's own rules: `readme.html`, `debug.log`, `wp-config.php` backup copies and the other protected files became reachable again. The comment is reworded and now warns never to use the marker text in the block. If you pasted an earlier version, check that it is still in your `.htaccess` and paste this one. Found by the independent review.
- **`htaccess-hardening.conf`: the endpoint rule also covers the path-info form** (`/wp-activate.php/x`), which Apache runs as `wp-activate.php`; it 302'd to a literal `wp-login.php` (no slug) and now gets the 404. Re-paste the rules block to pick this up. Found by the independent review.
- **SEO plugin fingerprints removed from the page head** (part of "Remove the WordPress version"): Yoast's "optimized with the Yoast SEO plugin vX" comments, Rank Math's credit comments, and All in One SEO's version number, generator tag and credit comments. All in One SEO prints its generator tag and comments with no filter, so Umbra removes exactly those from a small buffer around All in One SEO's own head output (opened and closed inside `wp_head`); nothing else in the page is touched.

Fixes:

- **The generic login error now applies to the login form only.** It replaced every error on wp-login.php, so an expired password-reset link or a registration with an empty email showed "Username or password is incorrect." The register, lost-password and reset screens now show their real messages; the login form still never says which half was wrong.
- **A search for an endpoint name no longer returns 404.** With the custom login on, any request whose query string named `wp-login.php`, `wp-signup.php`, `wp-activate.php` or `wp-register.php` (a search for "wp-login.php", say) got a 404, because the check read the whole address including the query. It now reads the path only. Every direct hit on those files still returns 404, including path tricks such as `//wp-login.php` and `/%3F/../wp-login.php`. Found by the independent review.
- **A rejected login slug keeps a working custom login on.** A reserved, colliding or empty slug used to switch the custom login off, so one typo put the stock `wp-login.php` back online until the next save. Now, if the custom login was on and its box stays ticked, the previous slug is kept and a warning says so; unticking the box is how to turn it off. If it was off before, it stays off with the error, as before. A draft or scheduled post holding the active slug no longer makes an unchanged save switch the custom login off (it is moved off the address when it goes public); only a published item already on the address still does, with an error. Found by the independent review.
- **Settings notices are shown.** Since the settings page moved to its own top-level menu, WordPress no longer displayed its notices there: no "Settings saved." and, worse, no error when a login slug was rejected, so a reserved or empty slug switched the custom login off silently. The page now shows them.

Known limitations (open, not fixed in this release):

- With "Generic login error" on, a wrong password for an existing account and an unknown username still give responses that differ in more than the message (the username field and the page length), so the login form can still confirm that an account exists.
- The lost-password and registration forms can answer measurably slower for an existing account; mail sending and password hashing happen in WordPress core, so a plugin can only partly even this out.
- Some Yoast SEO REST output was not re-examined in this release.
- A guid that equals the login URL is replaced the next time the post or page is saved as published. Attachments, private posts, an http/https or www variant of the URL, and items not saved since the custom login was turned on are not swept.

Status panel:

- New rows for the Rank Math and All in One SEO author scrubs, using the same runtime check as the Yoast row (red when the plugin is active but the scrub did not attach; amber when Rank Math's old sitemap cache could not be cleared). The new toggle appears with the other features.

## [2.4.0] - 2026-10-06

Renamed Nuke Press to **Umbra**. Update, so MINOR goes up; the security counter stays at 1 (no security fix in this release), giving 2.3.0 -> 2.4.0. No feature, behaviour or default changed.

- **Plugin, file, constant and option names renamed.** Folder `nuke-press/` -> `umbra/`; main file `nuke-press.php` -> `umbra.php`; plugin header "Nuke Press" -> "Umbra"; constants `NUKEPRESS_*` -> `UMBRA_*`; every `nukepress_*` function -> `umbra_*`; the stored settings option `nukepress_settings` -> `umbra_settings` and the schema-version option `nukepress_schema_version` -> `umbra_schema_version`.
- **Clean break, no migration.** The plugin has never been deployed to a live WordPress site, so there is no stored `nukepress_settings` data anywhere to carry forward. A fresh install seeds `umbra_settings` directly; no legacy-option migration code was added.
- **`htaccess-hardening.conf` and `README.md` updated** to the new name throughout. Earlier `test/v1.0.0` - `v2.3.0/` evidence folders and the changelog entries below are left as the historical record of what shipped under the old name.

## [2.3.0] - 2026-10-04

Update-resilience hardening plus a protection status panel. Feature release, so MINOR goes up; the security counter stays at 1 (no security fix in this release), giving 2.2.1 -> 2.3.0. Defaults are unchanged; no new settings keys, so the settings schema is untouched and nothing is migrated.

Context (unchanged and preserved): WordPress core/theme/plugin updates do not deactivate an active plugin or drop its hooks, and Nuke Press re-registers its filters on every request, so the baseline protection already survived updates. This release hardens the narrow real edges and makes any silent lapse visible.

Robustness:

- **Last word via late priority.** The filters that remove or empty data now run at `PHP_INT_MAX`, so a plugin that registers later and re-adds what we stripped cannot win: `the_generator`, `rest_endpoints` (REST users removal), `oembed_response_data`, `body_class`, `author_link`, `wp_sitemaps_add_provider` (users sitemap), `wp_headers` (X-Pingback), `xmlrpc_enabled`/`xmlrpc_methods`, the author-name filters (`the_author`, `get_the_author`, `get_the_author_display_name`), `get_the_archive_title`, the feed-author filter, and the Yoast scrub filters (`wpseo_schema_graph`, `wpseo_frontend_presenters`, `wpseo_enhanced_slack_data`, `wpseo_sitemap_exclude_author`). Hooks that are early by design are deliberately left alone: the author-enumeration block stays at `template_redirect` priority 0 (before core canonical), `redirect_canonical` stays as is, and the asset-version strippers stay at their proven 9999.
- **Defensive guards.** The core users-sitemap filter registers only when the core sitemaps API is present (`wp_sitemaps_get_server`), degrading to a no-op on an older or sitemap-less install instead of assuming the hook. The Yoast integration stays gated by `defined('WPSEO_VERSION')` and every Yoast callback keeps its array/object/class existence checks, so a Yoast schema or presenter shape change is a no-op, never a fatal or a notice.
- **Self-healing custom login (confirmed).** The custom login matches the request path at `wp_loaded`/`plugins_loaded` and adds no rewrite rules, so a `wp rewrite flush` by core or another plugin cannot disable it and recovery needs no cleanup. No change was needed; the design already meets the requirement.

Feature:

- **Protection status panel + dependency alert.** The settings page now shows a "Protection status" table: each feature with Enabled (yes/no) and an Effective indicator (green / amber / red / off). Features with no dependency map Effective to Enabled; the custom login reflects whether a valid slug is set; the Yoast author scrub reflects a runtime check captured on a front-end hook (`wp`) of whether Yoast is active, its version, and whether our scrub callbacks actually attached. A dashboard admin notice (for `manage_options`) fires only when an enabled protection is degraded (for example Yoast active but the author scrub not attached, which a Yoast API change would cause), naming what lapsed and to update Nuke Press; no notice when everything is green. A best-effort "Run self-test" button does a loopback `wp_remote_get` of the home page, a recent post and an author archive and reports whether the generator meta, a real author name/nicename, or the custom-login slug appears, saying so plainly when the loopback is unavailable rather than claiming a pass. Nothing in the panel is ever fatal.

Docs:

- README "What it does" renamed to **Features** and rewritten as a short bullet list, with the custom-login setup/recovery, the "admin should not be a public author" note, the `.htaccess` paste step, testing and rollback kept but trimmed to be scannable.
- Deployment-test evidence (the protection matrix before vs after core/theme/Yoast updates, the last-word mu-plugin probe, the status-panel degraded-state proof, flush/deactivation recovery, the UX pass and a headed block-editor publish on WordPress / PHP 8.3 with Yoast) recorded under `test/v2.3.0/`.

## [2.2.1] - 2026-10-04

New "Hide post author" feature, replacing the rough `strip_bylines` toggle from 2.1.1. Feature change, so MINOR goes up; the security counter stays at 1 (no security fix in this release), giving 2.1.1 -> 2.2.1. Defaults are unchanged: `hide_author` is off, so a fresh install still behaves as before.

Feature:

- **"Hide post author" (`hide_author`, off by default) with an editable label (`hide_author_label`, default = the site name).** When on, the real author display name is replaced with a generic label on the front end only (not in the admin, not in feeds), everywhere the theme prints it: classic bylines (`the_author` / `get_the_author`), block-theme bylines (`get_the_author_display_name`, used by the core post-author / post-author-name blocks) and the author-archive page title (`get_the_archive_title`, with the queried author's display name, nicename, nickname and login scrubbed to the label). The real name is removed from the page *source*, not merely hidden with CSS, so the username or display name is never exposed; it stays in the database for ownership. Scope is post bylines + author pages only: comment author names are deliberately not filtered. The label is validated with `sanitize_text_field` and falls back to the site name when left empty.
- **Supersedes the v2.1.1 `strip_bylines` toggle.** `strip_bylines` blanked the byline to an empty string; "Hide post author" replaces it with a readable label instead and also covers the author-archive title. `strip_bylines` no longer exists in the settings array, the UI or the code. A one-time migration (gated by a stored schema version) folds it forward: on upgrade, if `strip_bylines` was on, `hide_author` is turned on, and the retired key is dropped, so nobody loses the behaviour.
- Deployment-test evidence (the before/after HTML-source grep for an admin-authored and an author-authored post and the author archive, the strip_bylines -> hide_author migration check, the 2.1.1 regression and custom-login matrix, the UX proof and a headed block-editor publish on WordPress / PHP 8.3 with Yoast) recorded under `test/v2.2.1/`.

## [2.1.1] - 2026-10-04

Security fixes for two findings from an independent adversarial review of 2.0.0, plus two approved usability changes. The 2.0.0 defaults are unchanged, so a fresh install still behaves as before (the one new toggle, `strip_bylines`, is off by default).

Security:

- **Custom-login slug leak via `wp-signup.php` closed; `wp-activate.php` gated.** In 2.0.0, with the custom login on, an unauthenticated `GET /wp-signup.php` ran normally and its registration redirect was rewritten to the secret slug (`302 Location: .../<slug>/?action=register`), disclosing it. Fixed two ways in PHP (defense in depth): direct hits to `wp-signup.php` now return a 404 the same way `wp-login.php` does, and the login-URL rewrite filter no longer emits the slug to an unauthenticated client on a non-slug request (it rewrites only for a logged-in user or when the request is the slug login page itself). `wp-activate.php` never leaked the slug (it 302s to a literal `wp-login.php`, which itself 404s) and, because it defines `WP_INSTALLING`, WordPress loads no plugins on that request, so it cannot be gated in plugin PHP; `htaccess-hardening.conf` now 404s both `wp-signup.php` and `wp-activate.php` at the server layer. Logout, lost-password and the slug login page are unaffected.
- **Yoast head/schema and theme-byline author enumeration closed.** In 2.0.0, with `author_enum` on, `/wp-json/wp/v2/posts` and the post HTML still exposed the author nicename (login) and display name through Yoast's `yoast_head_json`/`yoast_head` (author-archive URL and Person schema) and the block-theme byline linked to `/author/<nicename>/`. The plugin previously stripped only Yoast's author *sitemap*. Fixed: every author-archive link now points home (core `author_link` filter), so no byline href or Yoast-derived author URL carries the nicename; and Yoast's Author Person Schema piece is removed from the graph (so neither `yoast_head` nor `yoast_head_json` carries an author name or URL), guarded by Yoast being active. Author archives still 301 home as before.

Usability:

- **"Blank theme bylines" toggle (`strip_bylines`, new, off by default).** When on, the author display name in the theme byline is blanked on the front end (not in the admin, not in feeds). Off by default because blanking the byline changes what visitors see; the author-archive link is neutralised regardless by `author_enum`.
- **Corrected the `author_enum` description** so it states exactly what it blocks (ID, slug and nicename enumeration via author params, REST users, _embed, oEmbed, feeds, core and Yoast sitemaps, author-archive links and Yoast author schema) and notes that a public display-name byline printed by the theme is site content (use `strip_bylines`, and prefer a separate non-admin author account), with no overclaiming.

UX:

- **Settings link on the Plugins row.** The Nuke Press row in the Plugins list now shows a "Settings" action link to the settings page.
- **Top-level "Nuke Press" menu.** The settings page moved from under the core Settings menu to its own top-level admin menu item (`dashicons-shield`). Same page, same capability (`manage_options`); the Settings link points to the new location.
- Deployment-test evidence (the F1 and F2 before/after attacks, the full 2.0.0 regression and custom-login matrix, the UX proof and a headed block-editor publish on WordPress / PHP 8.3 with Yoast 28.6) recorded under `test/v2.1.1/`.

## [2.0.0] - 2026-10-04

Major change: Nuke Press becomes a standard activatable plugin with a settings UI, adds a custom login URL, and hardens author enumeration against the encoding and array-parameter bypasses. The defaults match the 1.1.0 behaviour, so a fresh install with default settings behaves exactly as before.

Highlights:

- **Standard plugin + Settings UI.** Converted from a constants-only mu-plugin to a standard plugin (`nuke-press.php` + `includes/`). Every feature is now a toggle on a Settings -> Nuke Press page (single `nukepress_settings` option, `register_setting` with a validating sanitize callback, nonce via `settings_fields`, `manage_options`). Each toggle registers its hooks only when on; activation seeds the hardened defaults. The old `wp-config.php` constants are replaced by these toggles.
- **Author-enumeration bypass hardening.** The raw `$_SERVER['QUERY_STRING']` regex is replaced by a parsed check at `template_redirect` priority 0 (`is_author()`, `get_query_var('author')`/`author_name`) plus a case-insensitive `$_GET` scan. This closes the `%61uthor=1` (URL-encoded), `author[]=1` (array) and mixed-case bypasses that slipped past 1.1.0 and disclosed the author username. Documented finding: in 1.1.0 `%61uthor=1` 301-redirects to `/author/admin/`; in 2.0.0 every variant 301-redirects home.
- **More author leaks closed.** Unauthenticated REST `_embed` no longer exposes an author name (the author link is removed; the numeric id stays). The Yoast SEO author sitemap is emptied when Yoast is active (`wpseo_sitemap_exclude_author`), alongside the core users sitemap. oEmbed author stripping verified for both json and xml.
- **Custom login URL (new, off by default).** A toggle + sanitized slug hides `wp-admin` behind a secret path: the slug serves the login, while `wp-login.php` and the logged-out `/wp-admin` return a real 404. `admin-ajax.php`, the REST API, WP-Cron, logout, password reset and lost-password keep working through the slug. The slug is never disclosed to an unauthenticated request; the `wp-login.php?action=postpass` (CVE-2024-2473) and crafted-`Referer`-to-`options.php` (CVE-2021-24917) bypasses are specifically defended. Deactivating the plugin restores the stock login immediately (the recovery path), and no rewrite rules are added.
- `htaccess-hardening.conf` is unchanged from 1.1.0.
- Deployment-test evidence (the v1.1.0-vs-v2.0.0 bypass matrix, the custom-login matrix including both CVE bypasses and the deactivation recovery, the UI toggle proof, the headed block-editor publish, and a clean WP_DEBUG log on WordPress 7.1.2 / PHP 8.3) recorded under `test/v2.0.0/`.

## [1.1.0] - 2026-10-04

Closes the user-enumeration and version-fingerprint gaps found in an audit, without changing any of the 1.0.0 behaviour.

Highlights:

- **Users sitemap removed.** Drops the users provider from the core sitemap, so `/wp-sitemap-users-1.xml` (an author-slug list) and its entry in `/wp-sitemap.xml` are gone, while the posts and pages sitemaps stay.
- **oEmbed author leak closed.** Strips `author_name` and `author_url` from oEmbed responses (`/wp-json/oembed/1.0/embed`) without disabling embeds; the title and html payload are left in place.
- **Author body-class slug leak closed.** Strips the `author-<slug>` / `author-<id>` body classes that exposed the author nicename on the front end.
- **Feed author names stripped.** Removes the `<dc:creator>` author-name leak from RSS/Atom feeds by default, keeping feeds working and still listing items. New `NUKEPRESS_STRIP_FEED_AUTHORS` toggle (default `true`).
- **Optional author-archive disable.** New `NUKEPRESS_DISABLE_AUTHOR_ARCHIVES` toggle (default `false`): when on, 301-redirects every `/author/<slug>/` archive home for maximum stealth. Off by default because the `?author=N` redirect already blocks the ID-to-name pivot and this changes the front end.
- **Plugin/theme readme blocking.** The `.htaccess` `FilesMatch` now also denies `readme.txt`, `readme.md`, `changelog.txt`, `changelog.md` and `changes.txt` by basename site-wide, so `wp-content/plugins/*/readme.txt` and similar stop leaking plugin names and versions. The existing `readme.html`, `license.txt` and wp-config/install denials are kept; `sitemap.xml`, `robots.txt` and `ads.txt` are untouched.
- Deployment-test evidence (before/after for all six additions, the regression and the back-end pass) recorded under `test/v1.1.0/`.

## [1.0.0] - 2026-10-04

Highlights:

- Rewritten from the legacy `functions.php` snippet into a drop-in **must-use plugin** (`nuke-press.php`) plus a **scoped Apache `.htaccess`** file (`htaccess-hardening.conf`). As an mu-plugin it loads automatically and survives theme and core updates, instead of being pasted into a theme where an update wiped it.
- **Back-end safe by design.** User enumeration is blocked only for unauthenticated requests, so the REST API, the block editor and wp-admin keep working. The whole REST API is no longer disabled.
- Modernised to current, non-deprecated APIs. The dead `rest_enabled`, `json_enabled` and `json_jsonp_enabled` filters (removed from modern WordPress) are gone, the `_return_false` typo is fixed, the unguarded `$_SERVER['QUERY_STRING']` read is now guarded (no PHP notices on PHP 8.3), and author enumeration redirects home with a 301 instead of calling `die()`.
- RSS feeds stay on by default and simply no longer leak the WordPress version; a `NUKEPRESS_DISABLE_FEEDS` toggle restores the old disable-all-feeds behaviour.
- XML-RPC is disabled by default via the `NUKEPRESS_DISABLE_XMLRPC` toggle (overridable from `wp-config.php`), with the Jetpack / WordPress mobile app caveat documented.
- The `.htaccess` block targets named version-leaking files (`readme.html`, `license.txt`, wp-config and install files, `xmlrpc.php`) rather than a blanket `.html`/`.xml`/`.txt` deny, so `sitemap.xml`, `robots.txt` and `ads.txt` keep working. Adds current security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`); the deprecated `X-XSS-Protection` is left out.
- Added a README (purpose, what it deliberately does not break, two install methods, toggles, a curl test checklist and rollback) and recorded deployment-test evidence under `test/`.
