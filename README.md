# Wormiss – iMIS bridge for WordPress

Connects WordPress to the iMIS association management system through the iMIS REST
API. Adapted from the Moodle plugin
[`local_membershipbridge`](https://github.com/verzog/moodle-local_membershipbridge),
whose iMIS REST client and audit log it reuses.

## What it does (0.1.0)

* **iMIS data block and shortcode:** show an iMIS IQA query's results on a page as a
  table, cards or a list, with chosen columns and headings, site-formatted dates
  (DD/MM/YYYY on an Australian site) and an optional link per row.
* **Approved queries only:** an administrator lists the IQA queries pages may show;
  blocks and shortcodes cannot run anything else.
* **Caching with a fallback:** results are kept for a set time (default 15 minutes);
  if iMIS fails, the last good copy (up to a week old) is shown.
* **Connection test** on the settings page, and an **audit log** of every call
  (Tools > iMIS Bridge log) with credentials always redacted.

Planned, each as its own release:

1. Members' own data (membership status, CPD, results) for members signed in with
   iMIS single sign-on.
2. Members-only content, by iMIS membership type or status.
3. Recording WordPress activity in iMIS as activity records.

## Requirements

* WordPress 6.6 or later. CI tests 6.6 and the latest release.
* PHP 8.1 or later. CI tests 8.1 to 8.4.
* An iMIS site with the REST API, and a dedicated iMIS service account
  (RemoteService role, least privilege). Each iMIS API user may need a licence.

## Install via ZIP

1. Download `wormiss-v<version>.zip` from the latest GitHub release (not the
   "Source code" zip, which has the wrong folder name).
2. In WordPress: *Plugins > Add New Plugin > Upload Plugin*, choose the zip, then
   *Activate*.

## Install manually

Copy this folder to `wp-content/plugins/wormiss` and activate it under *Plugins*.

## Configuration

See `readme.txt` (Installation and FAQ) for the settings, the `wp-config.php`
constants and the block/shortcode options.

## Development

```bash
composer install
composer lint
composer phpcs
```

CI (`.github/workflows/ci.yml`) runs the static checks, then PHPUnit against the
WordPress test suite on WordPress 6.6 and latest, PHP 8.1 to 8.4. Tests fake iMIS by
intercepting WordPress HTTP requests (`tests/class-wormiss-fake-imis.php`), so no
iMIS site is needed.

### Releasing

1. Bump `Version:` and `WORMISS_VERSION` in `wormiss.php` and `Stable tag:` in
   `readme.txt` (CI checks they match); merge to `main` and wait for CI.
2. Publish a GitHub release tagged `v` + that version.
3. The *Release zip* workflow checks the tag and attaches `wormiss-v<version>.zip`
   with a `wormiss/` folder.

## Licence

GNU GPL v3 or later. See `LICENSE`. © 2024–2026 Vernon Spain.

iMIS is a trademark of Advanced Solutions International, Inc.; this plugin is not
made or endorsed by ASI.
