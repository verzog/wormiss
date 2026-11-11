=== Wormiss – iMIS bridge ===
Contributors: verzog
Tags: imis, membership, association, events, members
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Connects WordPress to the iMIS association management system. Show iMIS query results on pages, and show signed-in members their own iMIS data.

== Description ==

Wormiss talks to iMIS directly through its REST API (no middleware).

**iMIS data on pages**

* An **iMIS data** block (and a `[wormiss_iqa]` shortcode) shows a query's results
  as a table, cards or a list, with your choice of columns and headings, dates in
  the site's date format, and an optional link for each row.
* **Only approved queries can be shown.** An administrator lists the IQA queries
  that may appear on pages; a block can never run any other query.
* **Fast and resilient.** Results are cached for a set time, and if iMIS is
  unavailable the last good copy is shown instead of an error.
* **Audit log.** Every call to iMIS is logged (Tools > iMIS Bridge log), with
  passwords and tokens always removed, and old entries deleted automatically.

**Members' own data**

* A **My iMIS data** block (and a `[wormiss_member]` shortcode) shows the
  signed-in member their own iMIS data, such as membership status, CPD points or
  course results, as details, a table, cards or a list.
* **A page can never choose whose data is read.** The member's iMIS ID is added
  on the server as the first prompt of an approved member query.
* The iMIS ID comes from the member's WordPress account: a user field filled by
  your iMIS single sign-on plugin (default `imis_id`), or the username.
  Administrators can see and correct it on the user's profile; members cannot
  change their own.
* Results are cached per member for a few minutes, and pages showing them are
  marked not to be cached by page-caching plugins. Visitors who are not signed in
  see a sign-in link.
* Privacy tools: *Tools > Export Personal Data* and *Erase Personal Data* include
  a member's audit-log entries.

**Members-only content**

* Make any post or page **members only** from the *Members only* panel in the
  editor sidebar, optionally for certain iMIS membership types (e.g. Fellow) or
  statuses (e.g. Active). Others see the **title and excerpt**, then a
  "Sign in to read it" message (or "your membership doesn't include it").
* The **Members only** block hides any blocks inside it from non-members.
* A member's type and status come from an approved membership query (their iMIS
  ID as the first prompt), cached per member. Without one, any signed-in member
  with an iMIS ID counts. Access fails closed: if iMIS cannot be read and there is
  no earlier copy, the content stays hidden.
* The full text never reaches non-members: the page, feeds, the REST API and
  excerpts all get the teaser. Anyone who can edit the post always sees it.

**Recording activity in iMIS**

* Things signed-in members do here are written to iMIS as activity records (one
  activity type you choose, e.g. `WEBACTIVITY`): **reads** of members-only posts
  and pages, **downloads** through tracked links
  (`[wormiss_download id="123"]Slides[/wormiss_download]`), clicks on a **Mark as
  done** block (with optional CPD points), and **form submissions** (Gravity
  Forms, WPForms, Contact Form 7; developers can use the `wormiss_record_activity`
  action).
* Each record carries what happened, the title and page address, the date, CPD
  points and the WordPress post ID, in the full record format iMIS accepts.
* Off, and a **dry run** (log only), until an administrator turns it on. Records
  are sent in the background, so pages never wait on iMIS, and a failed send is
  retried twice. A member's read or download of the same item is recorded once a
  day; a Mark as done block once ever.
* Mark as done points come from the saved block, never from the browser, and the
  member must be able to read the page.

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
6. For members' own data: under *Members' own data*, choose where each member's
   iMIS ID is kept, and list the approved member queries. In iMIS, build each
   member query with a required filter on the contact ID as its **first**
   prompt (for example `ID = @Prompt`). Then add the *My iMIS data* block to a
   members' page.

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

= Which single sign-on plugin do I need? =

Any that can save the member's iMIS ID onto their WordPress account (OpenID
Connect and SAML plugins usually can, as a mapped user attribute). Set its user
field name under *Members' own data*. Developers can supply the ID another way
with the `wormiss_member_imis_id` filter.

= Can a member see someone else's data? =

Not through Wormiss: the iMIS ID is read from the signed-in member's own account
on the server, member queries are on their own approved list, and members cannot
edit their iMIS ID.

= How do I make content members only? =

For a whole post or page, open the *Members only* panel in the editor sidebar
and switch it on; add membership types or statuses to narrow it. For part of a
page, add a *Members only* block and put the private blocks inside it. To check
types or statuses, set a membership query under *Members-only content*.

= What does an activity record contain? =

`ACTIVITY_TYPE` (your type), `PartyId` (the member), `DESCRIPTION` (what it was
about), `NOTE` (details and page address), `NOTE_2` (read, download, completion
or form), `SOURCE_CODE` (WORDPRESS), `TRANSACTION_DATE` and `THRU_DATE`, `UF_4`
(CPD points) and `UF_5` (WordPress post ID). Every other Activity column is sent
empty, as iMIS requires. Check a dry run in the audit log before switching it on,
and confirm the records against a test iMIS first.

= Are downloaded files protected? =

No: the tracked link records the download and then opens the file, but files in
the Media Library can still be opened directly by anyone with their address.

== Changelog ==

= 0.4.0 =
* Recording activity in iMIS: reads of members-only content, tracked downloads, the Mark as done block (with CPD points) and form submissions, as activity records of one configured type; off and a dry run by default, sent in the background with retries.

= 0.3.0 =
* Members-only content: the Members only editor panel for posts and pages (teaser for non-members), the Members only block, membership type and status rules from an approved membership query, and site-wide current statuses.

= 0.2.0 =
* Members' own data: the My iMIS data block and `[wormiss_member]` shortcode, approved member queries, the member's iMIS ID from a user field or the username, an iMIS ID field on user profiles for administrators, a Details layout, and privacy export and erasure of audit-log entries.

= 0.1.0 =
* First release: iMIS connection, connection test, audit log, and the iMIS data block and shortcode.
