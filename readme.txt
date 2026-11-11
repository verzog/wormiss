=== Wormiss – iMIS bridge ===
Contributors: verzog
Tags: imis, membership, association, events, iqa
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Connects WordPress to the iMIS association management system. Show iMIS query results, such as upcoming events, on any page.

== Description ==

Wormiss talks to iMIS directly through its REST API (no middleware). This first
version shows the results of iMIS IQA queries on pages:

* An **iMIS data** block (and a `[wormiss_iqa]` shortcode) shows a query's results
  as a table, cards or a list, with your choice of columns and headings, dates in
  the site's date format, and an optional link for each row.
* **Only approved queries can be shown.** An administrator lists the IQA queries
  that may appear on pages; a block can never run any other query.
* **Fast and resilient.** Results are cached for a set time, and if iMIS is
  unavailable the last good copy is shown instead of an error.
* **Audit log.** Every call to iMIS is logged (Tools > iMIS Bridge log), with
  passwords and tokens always removed, and old entries deleted automatically.

Planned next: members' own iMIS data when signed in with iMIS single sign-on,
members-only content, and recording WordPress activity in iMIS.

iMIS is a trademark of Advanced Solutions International, Inc. This plugin is not
made or endorsed by ASI.

== Installation ==

1. Upload the plugin zip under *Plugins > Add New Plugin > Upload Plugin*, then activate it.
2. In iMIS, create a dedicated service account with the RemoteService role and
   only the rights it needs (read the queries you will show). Do not use a
   person's login.
3. Go to *Settings > iMIS Bridge* and enter the iMIS site address (for example
   `https://members.example.org`, without `/api`), the service-account username
   and password. To keep the password out of the database, set it in
   `wp-config.php` instead:

    define( 'WORMISS_IMIS_URL', 'https://members.example.org' );
    define( 'WORMISS_IMIS_USERNAME', 'svc-wordpress' );
    define( 'WORMISS_IMIS_PASSWORD', '...' );

4. Under *Approved IQA queries*, list the queries pages may show, one per line,
   for example `$/Events/UpcomingEvents`. Anyone can see a page, so approve only
   queries whose results are meant to be public.
5. Click *Test connection*, then add the *iMIS data* block to a page.

== Frequently Asked Questions ==

= How do I choose columns? =

In the block settings, list the IQA columns separated by commas, optionally with a
heading: `Event=EventName, Date=StartDate, City`. Leave it blank to show every
column. The first column becomes the heading of each card or list item.

= How do I link each row to iMIS? =

Give a web address with column names in braces, for example
`https://members.example.org/Events/Detail?id={EventKey}`. The first column is
linked. Only http and https addresses are used.

= Can I use it without the block editor? =

Yes: `[wormiss_iqa query="$/Events/UpcomingEvents" columns="Event=EventName, Date=StartDate" layout="table" limit="10"]`.
Other options: `parameters` (prompt values separated by `|`), `link` and `empty_text`.

== Changelog ==

= 0.1.0 =
* First release: iMIS connection, connection test, audit log, and the iMIS data block and shortcode.
