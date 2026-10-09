# Umbra 🛡️

A WordPress hardening plugin that hides what your site is running and shuts down the most common ways attackers probe it, without breaking anything your site already does.

## Features

- **Fingerprint removal** - Hides the details that reveal you're running WordPress and which version, so attackers can't match your site to a known vulnerability.
- **User and author enumeration blocking** - Stops anyone from discovering valid usernames on your site, which is usually step one before a password-guessing attack.
- **REST users protection** - Hides your list of site users from anyone who isn't logged in.
- **oEmbed, sitemap and feed author stripping** - Closes the quieter leaks (embeds, sitemaps, RSS feeds) that would otherwise still give away usernames.
- **SEO plugin author protection** - Stops Yoast SEO, Rank Math and All in One SEO from listing your authors in their sitemaps or naming them in your pages' search data.
- **Neutral sign-up and password-reset forms** - The registration and lost-password forms answer the same way whether or not an account exists, so they can't be used to check which usernames or emails are registered.
- **XML-RPC off** - Disables a legacy WordPress feature that's commonly abused for brute-force and flood attacks.
- **Custom admin URL** - Moves your login page to a secret address only you know, so bots scanning for `/wp-admin` can't even find it.
- **Hide post author** - Replaces your real name on posts and author pages with a label you choose, so your admin username is never shown publicly.
- **`.htaccess` hardening** - Blocks direct access to files that leak plugin and theme versions, and closes a few other common entry points at the server level.
- **Backup and log file protection** - Blocks downloads of leftover `wp-config.php` backups and the WordPress debug log, which can expose your database password and server details.
- **Status panel** - Shows you at a glance whether every protection above is actually working, and warns you the moment one silently stops.
- **Info tab** - Shows the version, the author, the official download page and the license, next to the settings.

Every plugin feature except the status panel and the Info tab is a toggle on its own settings page, and the hardened defaults are already on the moment you activate the plugin. The `.htaccess` hardening and the backup and log file protection come from the server rules file, which you paste in once (step 4 below).

## Install

1. **Download.** Go to the [latest release](https://github.com/KharimMchatta/Umbra/releases/latest) and download `umbra-vX.Y.Z.zip` (the plugin) and `htaccess-hardening.conf` (the server rules). To check that the files are genuine, see [Download only from the official source](#download-only-from-the-official-source) below.
2. **Upload.** In your WordPress admin, go to **Plugins -> Add New -> Upload Plugin**, choose the ZIP file you just downloaded, then click **Install Now**.
3. **Activate.** Click **Activate**. Umbra turns on with its hardened defaults and adds its own **Umbra** menu to the sidebar.
4. **Harden the server too.** Open the `htaccess-hardening.conf` file you downloaded, copy its contents, and paste them into your site's `.htaccess` file, above the `# BEGIN WordPress` line. Do this through your hosting File Manager or FTP. This step needs an Apache server.

That's the whole install. Step 4 is the only part that isn't done from inside WordPress itself.

> **Locked yourself out of a custom login URL?** Deactivate the plugin (another admin session, or rename its folder over FTP) and the normal login returns immediately. Nothing to clean up.

## Rollback

Deactivate the plugin at any time and all hardening stops immediately; the normal login returns. Your settings stay saved if you turn it back on. Remove the pasted block from `.htaccess` to undo the server-side rules too.

## Download only from the official source

Umbra is published only on its [GitHub releases page](https://github.com/KharimMchatta/Umbra/releases). A copy from anywhere else did not come from the author and may have been changed, which matters for a security plugin.

From 2.7.5 on, every release also lists the SHA-256 checksum of the plugin zip and of `htaccess-hardening.conf` in `SHA256SUMS`. Download it with the other files, put them in the same folder, and check them:

- **macOS:** `shasum -a 256 -c SHA256SUMS`
- **Linux:** `sha256sum -c SHA256SUMS`
- **Windows (PowerShell):** `Get-FileHash .\umbra-vX.Y.Z.zip -Algorithm SHA256`, then compare the result with the line for that file in `SHA256SUMS`.

Every file should report `OK` or show a matching checksum. If one does not, do not install it; download it again from the releases page.

Umbra also declares this repository as its only update source, so wordpress.org will not offer your site a different plugin that takes the name "umbra" there as an Umbra update.

## License

Umbra is free software under the [GNU General Public License, version 3 or later](LICENSE), with additional terms under section 7 of that License, set out in [NOTICE](NOTICE):

- **Keep the credit.** Every copy, and every work based on Umbra, keeps the copyright notice, the notice at the top of each file, and the attribution "Umbra by Kharim Mchatta" with the address https://github.com/KharimMchatta/Umbra, including in the legal notices it displays (Umbra shows them on the Info tab of its admin page).
- **Do not claim it as your own.** Umbra, or a work based on it, may not be presented as someone else's original work.
- **Mark modified versions.** A modified version must make clear, in its name and its legal notices, that it is a modified version of Umbra by Kharim Mchatta, and say who changed it, and when. The License grants no rights to the name "Umbra".

You are free to use Umbra on any site, to study it, to change it, and to share it under these terms.

## About

Umbra is built and maintained by **Kharim Mchatta**, [github.com/KharimMchatta](https://github.com/KharimMchatta). Copyright (C) 2026 Kharim Mchatta.
