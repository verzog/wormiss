# Wormiss – iMIS bridge for WordPress

Connects WordPress to the iMIS association management system through the iMIS REST
API. Adapted from the Moodle plugin
[`local_membershipbridge`](https://github.com/verzog/moodle-local_membershipbridge),
whose iMIS REST client and audit log it reuses.

## What it does (0.8.0)

* **iMIS data block and shortcode:** show an iMIS IQA query's results on a page as a
  table, cards or a list, with chosen columns and headings, site-formatted dates
  (DD/MM/YYYY on an Australian site) and an optional link per row.
* **Approved queries only:** an administrator lists the IQA queries pages may show;
  blocks and shortcodes cannot run anything else.
* **Caching with a fallback:** results are kept for a set time (default 15 minutes);
  if iMIS fails, the last good copy (up to a week old) is shown.
* **Connection test** on the settings page, and an **audit log** of every call
  (Tools > iMIS Bridge log) with credentials always redacted.

* **My iMIS data block and shortcode:** show a signed-in member their own iMIS data
  (membership status, CPD, results) from an approved member query. The member's
  iMIS ID, from a user field set by single sign-on or the username, is always the
  query's first prompt, added on the server. Cached per member; pages are marked
  not to be page-cached; signed-out visitors get a sign-in link.
* **iMIS ID on user profiles** for administrators (members cannot change their own),
  and privacy export and erasure of a member's audit-log entries.

* **Members-only content:** posts and pages (editor sidebar panel) and a Members
  only block, by iMIS membership type and status from an approved membership
  query. Non-members see a teaser (title and excerpt, then a sign-in message); the
  full text never reaches the page, feeds, REST API or excerpts. Fails closed.

* **Activity records in iMIS:** reads of members-only content, tracked downloads
  (`[wormiss_download]`), a Mark as done block (with CPD points, read from the saved
  block) and form submissions (Gravity Forms, WPForms, Contact Form 7, or the
  `wormiss_record_activity` action), written as iMIS Activity records of one
  configured type in the full record shape the Moodle bridge proved. CPD points also
  go in the activity's Quantity/CEU, with a chosen CEU type. Off and a dry
  run by default; sent in the background via WP-Cron with two retries.

* **Upcoming iMIS events block and shortcode** (`[wormiss_events]`): lists events
  from the iMIS Events API, soonest first, as cards or a list (date and time,
  place, short description, image), each with a button to its iMIS page. Only
  real, published, open events (status A by default) that have not finished;
  templates are left out. Optional category filter. Cached like the data block.

* **Events sent to iMIS:** an Events post type (with a sidebar panel for the event
  code, start, end, place, online link and capacity). When an editor publishes or
  updates one, it is sent in the background: a new event is a copy of an iMIS
  template event (CloneEvent, bringing its prices, registration options and program
  items) with the WordPress details saved over it; later saves update it. Off and a
  dry run by default (the dry run asks iMIS to check the event and creates nothing).
  Optionally, trashing a sent event cancels it in iMIS. The event's page shows when
  and where, with a Register button to iMIS.

* **Event types** (Events > Event types): each type has its own iMIS template
  event, and optionally an iMIS event category given to its events on every send.
  An event with no type, or a type with no template, uses the default template.
  Types also have their own archive pages (`/event-type/<type>/`).

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
