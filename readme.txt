=== Wormiss – iMIS bridge ===
Contributors: verzog
Tags: imis, membership, association, events, members
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.57.0
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
* **Audit log.** Every call to iMIS is logged (Wormiss > Audit log), with
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
* The **My membership** block shows a signed-in member their membership type,
  status, join date and paid-until date, as copied from iMIS by the daily member
  sync (Settings > Members).

**Recording activity in iMIS**

* Things signed-in members do here are written to iMIS as activity records (one
  activity type you choose, e.g. `WEBACT`; iMIS allows up to 10 characters): **reads** of members-only posts
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
3. Go to *Wormiss > Settings* and enter the iMIS site address (for example
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

= How do I show upcoming iMIS events? =

Add the Upcoming iMIS events block (or the [wormiss_events] shortcode, e.g. [wormiss_events limit="3" category="Workshop" button_text="Register"]). It reads events from the iMIS Events API, so the service account needs read access to events. Under Wormiss > Settings > iMIS events, choose which event status codes count as open (default A) and where each event links (default /iCore/Events/Event_display.aspx?EventKey={EventId}, on the iMIS site). Event templates, unpublished events and events that have finished are never listed.

= How do I create iMIS events from WordPress? =

In iMIS, set up a template event with your usual prices, registration options and sessions. Under Wormiss > Settings > Send WordPress events to iMIS, enter its event code, and turn sending on with the dry run still ticked. Then add an Event (Events > Add New Event), fill in the "Event details" box under the content (dates, place and address, places, waitlist, and the event code on the iMIS tab) and publish. The panel shows what iMIS said. When dry runs are accepted, untick the dry run and update the event: it is copied from the template and saved in iMIS, and later updates change it there. Only editors and administrators can send events. The service account needs rights to create and edit events.

= Can different kinds of event use different iMIS templates? =

Yes. Under Events > Event categories, add a category (e.g. Workshop, Conference, Webinar) with its iMIS template event ID and, optionally, an iMIS event category ID. Tick one type on each event. A new event is copied from its type's template; the category, if set, is written to the event each time it is sent. A type can also have a default capacity (used when an event leaves its own Capacity at 0), and "Create this category in iMIS when saved" makes the type's iMIS event category for you if it does not exist yet. Events with no type, or whose type has no template, use the default template in Wormiss > Settings. The template only matters when the event is first created in iMIS.

= Can members register for events on the website? =

For free events, yes. Under Wormiss > Settings > iMIS events, tick "Members register for free events here" (keep the dry run on first). Events whose iMIS registration type is "registration required" (no fee) then show a Register button to signed-in members with an iMIS ID, in the Upcoming iMIS events block and on Event pages; Event pages also show "You're registered" and a Cancel button. Members always register themselves, using the event's first registration option. Paid events keep linking to iMIS to pay.

= Can members' WordPress accounts come from iMIS? =

Yes. Build an iMIS IQA with no prompts that returns ID, Email, FirstName, LastName and Status for the members who should have website accounts (tick "Available via REST API"). Under Wormiss > Settings > Sync members into WordPress, enter its path, choose the role for new members (only roles without editing rights are offered), and tick "Sync members daily" with the dry run still ticked. Press "Run the member sync now" and read the report. When it looks right, untick the dry run. Members are matched by iMIS ID, then email; new ones get the iMIS ID as their username and no email. Members whose Status is not in the Current statuses (Members-only content) are blocked from signing in until it is current again. Staff accounts are never changed.

= Can I make a page for an event that is already in iMIS? =

Yes. Add an Event (Wormiss > Add New Event), give it a title and any text you like, enter the iMIS event's ID (e.g. CONGRESS26) in "Existing iMIS event ID" in the iMIS event panel, and save. The page then takes its dates, place, places left and Register button from iMIS. WordPress never changes or cancels a linked event in iMIS. Clear the box and save to unlink it.

= Can Wormiss make pages for all our iMIS events? =

Yes. Under Wormiss > Settings > Import iMIS events, tick "Import iMIS events daily" and press "Import iMIS events now". Each upcoming iMIS event (the same ones the events block lists) gets a linked Event page with its iMIS description, image and event category, saved as a draft (or published straight away with "Publish imported pages"). Titles follow iMIS; dates, place and places left always come from iMIS. Nothing is changed in iMIS, finished events keep their pages, and a page you trash is not made again. For historical records, set "Also import past events since" to a date to bring in finished events from then on as well.

= Can I bring our iMIS news articles into WordPress? =

Yes. In iMIS, save an IQA that lists your news articles, one row per article and tag, with the Document Version Key column (and ideally Alternate Name, Document Description, Publication Date, Tag Name and Thumbnail Image), with no prompts. Under Wormiss > Settings > Import iMIS news, enter it as the News query, tick "Import iMIS news daily", and press "Import iMIS news now" with the dry run on to see what would be made. Then untick the dry run. Each article becomes a post in the News category with its tags, date, summary, full text and images. To keep your own changes to a post, tick "Stop updating from iMIS" in its iMIS news box. To check one article first, use "Check a news article" on the same page.

= Can members join a waitlist on the website? =

Yes, for free events. Set the event's waitlist to "Anyone can join" (in its iMIS event panel, its event category, or in iMIS) and set up the places count IQA. When the event is full, its page shows "Join the waitlist" to signed-in members. Members already on the waitlist see "You're on the waitlist" and can leave it. iMIS offers freed places to the waitlist as set in iMIS (or by "When a place frees up").

= How do I show CPD hours on event pages? =

Tick "Show CPD hours" under Wormiss > Settings > iMIS events. Each event page then shows the hours from its CPD Hours panel in iMIS, added up per category, with the program level requirements. The service account needs read access to the EventCPD business object.

= How do I show places left on an event? =

The iMIS Events API reads registrations one member at a time, so the count comes from an IQA. Build one with a single prompt, the event ID, that returns one row per place taken (leave out cancelled and waitlisted registrations), or one row with a numeric Registered column. Tick "Available via REST API". Enter its path under Wormiss > Settings > iMIS events > Places count IQA. Event pages then show places left; the capacity comes from the iMIS event, and 1000 or more shows as "Places available".

= Do changes members make in WordPress reach iMIS? =

Their first name, last name and email can. Under Wormiss > Settings > Sync members into WordPress, tick "Send member changes to iMIS" (keep the dry run on first and check the audit log). A changed name or email is then saved on the member's iMIS contact in the background. Nothing else is sent, and staff accounts are never sent.

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

= 0.57.0 =
* Protected delivery (part one of the mechanism): images in a category flagged for protected delivery are now served through a gated endpoint, /wormiss-media/<id>, instead of a public file URL. The endpoint checks the same access as the editor/library gate (administrators and the department-by-category matrix) plus the members-only gate for front-end members, and otherwise returns 403. Files are kept in a private store (set WORMISS_PROTECTED_DIR in wp-config.php to a path outside the web root; a hardened wp-content folder is used otherwise, with an admin warning).
* nginx: add one internal location so the endpoint can stream files with X-Accel-Redirect (Apache X-Sendfile and a PHP fallback are also supported). Adjust the path to match WORMISS_PROTECTED_DIR:
      location /wormiss-media-internal/ { internal; alias /path/outside/webroot/wormiss-protected/; }
* Populating the private store for members' blog images, and rewriting old image URLs in existing blog posts, follow in the next release.

= 0.56.0 =
* Media categories can be flagged for "protected delivery" (a checkbox on each category under Media > Media categories, and a column in the list). This marks a category whose images are meant to be served with byte-level protection; a protected parent protects its sub-categories too. The protected storage and the gated delivery endpoint that use this flag follow in the next release.

= 0.55.0 =
* Bulk categorise: a new tool (Settings > Media) adds one media category to many existing images at once — either images with no category yet, or all media. It runs in the background in small, resumable batches, asks for confirmation before starting, shows progress and can be cancelled, and the start control is hidden while a run is in progress. It can be turned off site-wide (the wormiss_media_bulk_enabled filter or the WORMISS_MEDIA_BULK_DISABLED constant).

= 0.54.0 =
* Media admin: a new "Media" tab in Wormiss Settings with the department-by-category access matrix (tick which categories each department may see and use; administrators only), plus two settings — the roles that count as staff, and the departments that may also see uncategorised images.
* Uncategorised images (ones with no media category) are visible to their uploader and administrators by default; a department can now be granted visibility of them via the new setting.

= 0.53.0 =
* Media access control: who can see and use which images in the editor and Media Library is now governed by a department-by-category rule. A top-level media category is created for each department (Marketing, Membership, Education, Management, Events, Operations), and by default each department is allowed its own category.
* A non-administrator sees only images they uploaded themselves plus images in categories allowed to their departments; everything else is hidden in the "Add media" modal, the Media grid, the Media Library list and the REST media endpoint, and cannot be opened, edited or deleted by ID. Administrators see everything.
* This controls visibility and use in the editor/library. It does not change whether a file's public URL is reachable; that (byte-level protected delivery) is a later release. The admin screen for editing the department-by-category matrix and a bulk-categorise tool are also still to come.

= 0.52.0 =
* Internal roles: two new roles, Wormiss Staff (use and data entry) and Wormiss Manager (reporting and configuration, plus oversight of others' posts). Managers are a superset of Staff; neither has full site administration.
* Departments: a new "Wormiss departments" field on each user's profile (Marketing, Membership, Education, Management, Events, Operations). A person has one level (Staff or Manager) and may belong to several departments; departments decide what they can see or touch (used by media categories now, and content later).
* Wormiss manages these roles itself: they are created on activation, kept in step on update, and removed on uninstall. Both roles count as staff for media auto-filing.

= 0.51.0 =
* Media categories: a new hierarchical "Media categories" taxonomy on images and other media, so the library can be organised into curated buckets (Logos, Headshots, Event photos, ...) with sub-categories. Manage them under Media > Media categories.
* A reserved "End-user images" category is created automatically and cannot be deleted.
* Images and other media can be put into categories from the Attachment details panel in the media modal, and the media modal and the Media grid gain a category dropdown to filter the library by category.
* Uploads by non-staff users are filed into "End-user images" automatically, so end-user uploads can be managed as a group. By default staff means administrators and a "staff" role; the roles that count as staff are a setting. System imports are left alone.
* This is the first step of the media-access feature: it organises media only. Controlling who may see or use which images is a later release.

= 0.50.0 =
* Membership details on members' accounts: the daily member sync now also copies each member's type, status, join date and expiry ("paid until") date from the members query. The column names are settings on Settings > Members (MemberType, JoinDate and PaidThrough by default; blank to not copy one). iMIS stays the record: the copy is replaced by each sync.
* New My membership block: shows the signed-in member their membership details (an expired date is marked). The details also show, read only, on each member's profile in the admin.
* The details are included in Tools > Export Personal Data and removed by Erase Personal Data, and the suggested privacy policy text mentions them.

= 0.49.0 =
* Wormiss menu: each list now comes before its Add New (Events, Add New Event, Event categories, Add New Event Category).
* Settings are now in tabs: Connection, Members, Sign-in, Events, News, Members' blog, and Activity & logs. Each tab has its own Save button and its own "run now" buttons and checks, and saving one tab leaves the other tabs' settings as they were. The "run now" buttons come back to their own tab.

= 0.48.0 =
* "Event types" are now called "Event categories" everywhere in the admin (menu, screens, bulk actions, the Open events block and messages), as iMIS calls them; levels such as Essential are sub-categories. Their web addresses (/event-type/…) and settings are unchanged.
* Wormiss menu: a new "Add New Event Category" item under "Add New Event". "Add New Event" (and the Events list's Add New button) first asks "What are you adding?": an event or an event category. People who cannot add categories go straight to a new event.

= 0.47.1 =
* Open events block: an event linked to iMIS follows the closing date iMIS lists now (an extended or brought-forward closing date counts at once), not the one kept when it was linked; an event made here and sent to iMIS also uses its iMIS closing date. Every event of the type is checked, so an open one is never missed when there are many.

= 0.47.0 =
* New "Open events" block: the events of one event type (and its sub-types) still open for enrolment, soonest first, as cards with the place, dates, "Enrolments close" date, summary and a Learn more button to the event page. An event drops off once its iMIS registration closes, else when it starts. Choose the type in the block's settings; a message shows when none are open. For pages such as a Course Education Pathway with a section per level.
* Import iMIS events: an event's iMIS category becomes its event type even when iMIS sends the category without a Name (its Description, else its ID, is used). The import report now says how many events had an iMIS category, and which, or that iMIS sent none.

= 0.46.0 =
* Events list: Bulk actions has "Set event type" and "Remove event type", with an entry for each type (sub-types under their main type). Tick events, choose a type and Apply; a message says how many were changed. An event has one type (each type has its own iMIS template), so setting a type replaces the old one. Changed events are queued for iMIS as a save would queue them. Only people who may assign event types see them.
* Import iMIS events: an imported event keeps a sub-type chosen here (e.g. Essential under Courses) when that sits under its iMIS category's type; only a type from outside the category is set back. The type for a category is found by its iMIS category ID first, so a type renamed here is still used instead of a second one being made.

= 0.45.6 =
* Sign-in page: signing in from the sign-in page itself (not from a "Sign in" link that says where to go back to) showed a blank white page, though it had signed you in. It now goes to the home page, as intended.

= 0.45.5 =
* Sign-in page: a website password containing ', " or \ now works. WordPress keeps a password set on the Profile screen with a backslash before those characters (as it checks them on wp-login.php); the sign-in page now accepts it either way.
* Check a sign-in also says whether WordPress's own password checking works on this site, and where its password functions come from (a plugin can replace them).

= 0.45.4 =
* The sign-in changes in 0.45.2 and 0.45.3 are undone: signing in works exactly as in 0.45.1 again (wp-admin sign-ins go to the sign-in page, and website passwords are checked as before).
* New "Check a sign-in" on Wormiss > Settings, for administrators: type a username, email or iMIS ID and a password to see each step the sign-in page takes (iMIS, the account found, whether it is linked to iMIS or is staff, whether the website password matches, confirmation, blocks and pauses) and its answer, to see why a sign-in is refused. Nobody is signed in, no failed try is counted, and the password is never kept or shown.

= 0.45.3 =
* Signing in to the admin area (wp-admin) uses WordPress's own sign-in page (wp-login.php) again, not the members' sign-in page, so a host that signs staff in there, such as YunoHost's single sign-on, works as before. Every other Sign in link and redirect, including the members' blog's, still goes to the sign-in page.

= 0.45.2 =
* Sign-in page: staff and other website accounts are now checked through WordPress's normal sign-in (as wp-login.php does), so a password kept elsewhere works there too. On YunoHost, whose WordPress checks your YunoHost password through its LDAP plugin, administrators can sign in on the sign-in page with that password again. Members still sign in with their iMIS password.

= 0.45.1 =
* Members' blog pictures side by side are no longer cropped: each sits whole in a box of the row's shape (on a light grey background), so nothing at a clinical picture's edges is cut off and the row still lines up.

= 0.45.0 =
* Members' blog pictures grouped as on the iMIS blog: two or more pictures one after another, with nothing between them, now show side by side (two as a pair, three across, four as two pairs, more in threes; stacked on phones) instead of one under another at full width. Pictures already in a row or grid are left as they are. This happens as a post is shown, so every post gets it without a new import.
* A click or tap on a picture in a blog post opens it large; a click anywhere, Close or Escape closes it.

= 0.44.0 =
* New "Blog post actions" block, the rail beside a members' blog post as on the iMIS blog: Like (with how many members like the post), Save (to find it again), Comments (with the count; jumps to the discussion) and Share (the device's share menu, or copies the link). Likes and saves change without reloading the page; without JavaScript the page reloads instead. Only members who can read the blog can like or save.
* Blog filters: a "Saved posts only" tick box, for signed-in members, shows just the posts they have saved.
* A member's likes and saved posts are removed when their account is deleted, and by Tools > Erase Personal Data.

= 0.43.0 =
* Members' blog posts closer to the iMIS blog: pictures side by side (a row of columns, or a grid of two or more) are cropped to one shape so each row lines up; on phones, stacked, they keep their own shape.
* The Blog post details block can show the author card: their initials, "By" and their name, and the biography from their WordPress profile.

= 0.42.0 =
* Email testing, like Moodle's "divert all emails": in Wormiss > Settings > Email testing, "Divert all emails to" sends every email the site sends (with its copies) to one address instead, its subject starting "[Diverted from ...]"; "Do not divert emails to" lists addresses and whole domains that still get theirs (an email goes out as normal only when all its recipients are on that list; otherwise it comes to you alone, so no hidden copy is shown to anyone). WORMISS_DIVERT_EMAILS_TO and WORMISS_DIVERT_EMAILS_EXCEPT in wp-config.php do the same and win, for a test copy of the site. A warning shows across the admin screens while it is on.

= 0.41.0 =
* Sign-in page: a "Sign in" block and page (Wormiss > Settings > Sign-in page > Create the sign-in page). Members sign in with their iMIS ID or email and iMIS password, checked with iMIS, into the website account the member sync made for them; staff and other website accounts use their website password. "Forgot your password?" goes to the iMIS page you set, and a "Not a member yet?" panel has a Become a member button (to the iMIS join page you set) and, when "Free website accounts" is ticked, a small form for a free website account (not a membership). With "Confirm free accounts' email" ticked, a free account works only once its owner opens a link emailed to them (48 hours). Both are off by default. Free accounts are never linked to a member by the member sync. With the page chosen, every Sign in link and redirect, including the members' blog's, goes there instead of the server's own login (such as YunoHost's). Repeated wrong passwords pause sign-ins for 15 minutes; lapsed members stay out.
* An iMIS ID column on the Users screen ("Free account" for free website accounts), and the signed-in card shows the member's iMIS ID.

= 0.40.0 =
* Members' blog: comments on blog posts, with their writers' names, are now left out of every public comment list (a post's discussion, "Latest comments" and the REST API) for people who cannot read the blog.
* Blog filters laid out as on the iMIS blog: Category, Subcategories, Tags, Authors, Published date (from and to in one field) and Keyword. Each choice applies as soon as it is picked, a "Date published" button turns the order between newest and oldest first, and Reset clears them. Without JavaScript a Filter button does the same.
* New "Blog post details" block: a post's comment count (for the blog's cards); its author, date and comment count (under the title); or a column with Back to posts, the author's initials and name, reading time and date (beside the post).
* The "Access restricted" panel (shown after the intro when the blog is not hidden) now matches the iMIS blog: a lock, "Sign in or join to continue reading", and Sign in and (where sign-up is on) Create account buttons.

= 0.39.0 =
* Members' blog for members only: the blog home, posts, categories and topics (and their feeds) now send visitors to the sign-in page and back, and show signed-in people who are not readers the site's "not found" page; nothing of a post is shown, not even its title or intro. Blog posts are also left out of site searches and other post listings, and the REST API's blog posts, categories and topics answer readers only; no embeds of blog posts and no blog in the XML sitemaps. Blog pages are never page-cached. Readers are as before: staff plus the roles ticked in Settings > Members' blog. Pictures copied into the Media Library can still be opened by anyone with their address.

= 0.38.1 =
* Blog import, faster pictures: imported blog pictures get only the sizes the blog shows (thumbnail, medium and large) instead of every size the site has, so each picture is copied several times faster. Other uploads are unchanged; the sizes can be changed with the wormiss_blog_image_sizes filter.
* Blog import: posts whose iMIS content is only in their preview data (BlogPostData left empty, as when a post is edited after it was published) are now imported from it instead of being skipped as unreadable.

= 0.38.0 =
* Members' blog filters: a "Blog filters" block for the blog home, category and topic pages, with Category, Level, Topic, Author, Published from/to and a keyword search. Choosing a level (for example All Levels) finds it under every category. For visitors who cannot read the blog, the keyword search looks at titles and summaries only, so members-only text cannot be found by searching.

= 0.37.1 =
* Blog import: "Seconds per blog batch" in Settings > Members' blog (10 to 60, default 40). Each batch asks PHP for the time it needs for that request only (twice its seconds plus 30), so a site with PHP's usual 30-second limit no longer cuts batches to 15 seconds; page views keep their limit. Where PHP will not allow more, a batch keeps to half the limit there is, as before.

= 0.37.0 =
* Blog import, closer to the iMIS posts: grids keep their columns (for example two pictures side by side, wrapping onto more rows; one column on phones); image showcases (galleries) come across as a grid of pictures with captions; polls show their question and choices in a "Poll" box (votes are not in iMIS's blog data, so readers answer in the discussion); template text the author never changed ("Text", "Lorem ipsum…", "Your question", "Option 1") is left out.
* Blog import: each post is matched to the iMIS template it was made from (by the blocks only that template has), shown in the post's "iMIS blog" box and as a class on the post (wormiss-blog-post--template-3 and so on) so each template can be styled; old HTML posts are "Legacy", others "Other". The templates are read from iMIS with the post list, so new templates are picked up by themselves. Posts already imported change on the next import.

= 0.36.0 =
* Members' blog listing pages (/blogs/, its categories and topics) show 12 posts a page, as on the iMIS blog.
* A blog post's categories in a Post Terms block are marked by level (wormiss-term--category for the category, wormiss-term--subcategory for the level beneath it), so a theme can show them as differently coloured badges on blog cards. The SCCA theme shows the category in purple and the level in orange.

= 0.35.1 =
* Blog import speed is now in Settings > Members' blog: "Blog posts per batch" (1 to 20, default 10, was 3) and "Minutes between blog batches" (1 to 10, default 1, was 2). Each batch copies up to 3 pictures per post and still stops after about 20 seconds, carrying on in the next batch, so a busy or slow site simply does less.

= 0.35.0 =
* Members' blog: the solution is closed until the reader chooses to see it, as on the iMIS blog: a box with an eye icon, "Solution", a short prompt to think it through first and a "Show the solution" button (then "Hide the solution"). It works without scripts and from the keyboard. Before its reveal time readers still see when it will be published (now with the eye icon); staff can open it early, marked as staff only. Non-readers never receive the solution.

= 0.34.2 =
* Check a blog post now also shows the Smart Blogs blog templates (each one's name and layout, decoded) and their fields, to plan a layout here for each iMIS template. Read only: nothing is changed in iMIS.

= 0.34.1 =
* Blog import: up to 3 posts per batch (was 1), still within each batch's 20-second limit and 8 pictures, so the first full import of a large blog takes about a third as long.

= 0.34.0 =
* Uploads: every picture uploaded to the site (imported news and blog pictures, and anything staff upload) is scaled down to 1600 pixels on its longest side, and that copy is used everywhere, for smaller files and faster pages. Pictures already in the Media Library are not changed. The size can be changed with the wormiss_image_max_size filter (0 for WordPress's own 2560).
* Members' blog posts in a simple Bootstrap-style layout: iMIS rows of blocks become rows of columns (stacked on phones), pictures fit their column, old posts' tables scroll on phones and quotes look like quotes, and empty paragraphs and runs of line breaks used as spacing are taken out. The solution shows in a card, and the "will be published on" and members-only messages in alert boxes. A small stylesheet loads on blog pages only; the theme sets colours and fonts. Posts already imported change on the next blog import.

= 0.33.1 =
* Blog import no longer stops the site: the list of blog posts is read from iMIS a page at a time (over several batches for a long blog), keeping only each post's details, instead of every post's full text at once.
* Blog and news imports: each batch stops starting new work after about 20 seconds (less where PHP allows less), and the next batch carries on. Progress is saved after every post, and the next batch is booked when a batch starts, so a batch the host stops part way is carried on rather than repeated. A post that has stopped two batches is skipped with a note and tried again in the next import.
* Blog import: one post per batch, at most 8 pictures per batch, and 2 minutes between batches. Commenters' names are looked up in iMIS only while the batch has time; the rest are done in the next batch. A reply whose parent comment was not saved is linked on the next try instead of being left at the top level.

= 0.33.0 =
* Blog import: every iMIS blog category and subcategory is now made in WordPress (Blogs > Categories), with each subcategory under its parent, including ones no post uses yet, so the list matches iMIS. Each category remembers which iMIS category it is, so renaming or moving it in iMIS updates it here on the next import instead of making a new one. Categories made by 0.32.0 are matched by name and kept. A dry run only says how many it would make.

= 0.32.0 =
* Members' blog: a new "Members' blog" section at /blogs/ with its own categories (and subcategories) and topics. Readers are the roles you tick in Wormiss > Settings > Members' blog (e.g. a paid membership role), plus staff; everyone else sees each post's intro and a sign-in message, never the full text, the solution or the discussion (on the page, in feeds or through the REST API). Each post's solution shows to readers from its reveal time; staff see it earlier, marked with that time.
* Import the iMIS blog (Smart Blogs): every published post with its text (old posts' HTML tidied; new posts' text, pictures and dividing lines), its intro (what iMIS shows to non-members; old posts get their first paragraph), its solution and reveal time, header picture, category, subcategory, tags and author (the member's WordPress account by iMIS ID). Every comment comes too, with replies under their parents, linked to the commenter's account or named from iMIS. Pictures are copied into the Media Library. Links to old iMIS blog addresses go to the post here. Small batches every minute, a dry run, and "Stop updating from iMIS" per post. No CPD is recorded for imported activity; nothing is changed in iMIS.

= 0.31.1 =
* Audit log: "Download as text" saves the newest 20, 50, 200 or 1000 entries as a plain text file (time, call, result, error, user, iMIS ID and, if ticked, what was sent and what iMIS replied, laid out for reading), for keeping or sending to support. Administrators only; passwords and tokens are never in it.

= 0.31.0 =
* Check a blog post (Settings, administrators only): enter a members' blog post's blog key to see what iMIS (Smart Blogs) keeps for it, to plan moving the blog into WordPress: the post with its text and preview decoded, its first comments, the fields of blog posts, comments and the CPD logbook, the blog CPD rates, categories and Smart Blogs settings. Read only: nothing is changed in iMIS, and only a plain blog key is accepted.

= 0.30.4 =
* Imported news: links iMIS writes to its own pages as [@]path (e.g. "become a member") now go to the iMIS site instead of a page that does not exist here.
* Imported news: every link in an article opens in a new tab.
* Imported news: a heading that repeats the title is left out even when the title has an ampersand or similar character (it showed twice).

= 0.30.3 =
* When iMIS refuses a request with its validation errors (for example an IQA that cannot run), the settings page and notes now show iMIS's own message, not only "HTTP 400". This applies to every Wormiss feature that talks to iMIS.

= 0.30.2 =
* Imported news keeps its pictures where iMIS shows them: the first picture sits beside the text before it (as on the iMIS page; stacked on phones), and other single pictures stay in their place. The first is still the featured image for news cards, but is not shown twice on the article itself.
* Italic or bold text that runs over several paragraphs in iMIS stays italic or bold in every paragraph.

= 0.30.1 =
* Imported news always has an author: "Author for imported news" (Wormiss > Settings > Import iMIS news), or the first administrator if none is chosen. Posts made by the background batches (when no one is signed in) used to have none; those get the author on the next import, changed or not.

= 0.30.0 =
* Import iMIS news (Wormiss > Settings > Import iMIS news): once a day, or on "Import iMIS news now", each article your news IQA lists (one row per article and tag, with its Document Version Key) becomes a post in the News category (or the one you choose), with all its iMIS tags, its publication date, summary and full text. The text comes from the article's iMIS content page, in page order: the title heading is left out, the image-only block becomes the featured image, and old fonts, inline styles and stray line breaks are tidied away so posts take on the theme's look. Images on the iMIS site, featured and in the text, are copied into the Media Library (each once). Members-only iMIS articles are members-only here too.
* iMIS stays the source: a changed article updates its post on the next import, unless an editor ticks "Stop updating from iMIS" in the post's iMIS news box. Only published iMIS articles are imported; a post you trash is not made again; nothing is changed in iMIS.
* Off, and a dry run (reports what it would make or update), until turned on. Works in batches of 5 posts and 6 images every 2 minutes, so the site is not slowed.

= 0.29.2 =
* Check a news article uses iMIS's Document service as iMIS documents it: the document is found by its version ID (the news view's Document Version Key) with the FindByVersionId operation, which only reads, then read by its document ID. Its content, sent as binary, is decoded and shown as text. It also shows the Document and DocumentSummary services' own descriptions of their fields and operations. Replies are shown up to 50,000 characters. The business-object style lookups, which the Document service does not accept, are gone.

= 0.29.1 =
* Check a news article now looks where iMIS keeps an article: the Document found by its Document Version Key, then read by its own Document Key, and its web address (DocumentPath). The Document's content (Blob) is decoded and shown as text, unzipped if compressed. The content item and content lookups, which iMIS does not answer for news, are gone.

= 0.29.0 =
* Check a news article (Settings, administrators only): enter an iMIS news article's Document Version Key to see what iMIS gives out for it (content items, content and document lookups), to plan importing news. Read only: nothing is changed in iMIS, only a key in the expected form is accepted, and the replies are also kept in the audit log.

= 0.28.1 =
* Events open inside their page template again on current WordPress, which reads the post type's editor default mode (the 0.28.0 setting was only read by WordPress 6.8). Events are always edited in their template, so editors see where each detail goes and how the page will look: the editor switches to it once it has finished loading (switching earlier was undone), and switches back if "Show template" is turned off.

= 0.28.0 =
* Event details are edited in the page. Three blocks for event pages: Event facts (when, venue and address, online link, places and the Register button), CPD information (the CPD table) and iMIS settings (event code, existing iMIS event, attendance and waitlist; shown to editors only, never on the website). On an Event made in WordPress each is edited in place, laid out as on the website; on a linked or imported event they show what iMIS has. Events open inside their page template (WordPress 6.8 and later, and in the editor on earlier versions), with these blocks editable there, so editors see the page as it will look.
* When the event template places the Event facts or CPD information block, they are no longer added to the event's text as well.
* In the block editor the "Event details" box is gone (the classic editor keeps it), so it can no longer be missed in the folded-away "Meta Boxes" panel. A block editor save is sent to iMIS as soon as it has finished.
* CPD lines set in WordPress show on the event page straight away. Saving other details never sets an event's CPD (so never removes iMIS's lines), and only editors and administrators can change CPD lines.

= 0.27.0 =
* Set CPD hours in WordPress: on an Event made in WordPress, editors and administrators fill in the CPD hours tab of Event details, one line per program level requirement (hours in each category, decimals allowed; Add line, Remove). When the event is sent, its CPD Hours panel in iMIS is made to match: lines changed (by their iMIS row number), added (keeping the number iMIS gives them) and removed. The tab starts from the lines already in iMIS, so a first save keeps them, and nothing in iMIS changes until someone saves the tab. A dry run logs what would change. Events linked to or imported from iMIS still show their CPD read only.

= 0.26.0 =
* Approved IQA queries are locked down. For each approved query, Wormiss > Settings sets who may see it (staff, signed-in members or everyone) and the only columns that may show; a page asking for other columns never gets them. A query that lists people (ticked, or with a column that looks personal: names, emails, phones, addresses, birth dates, IDs) is never shown on the website, to anyone, and is flagged in red. Only administrators can add the iMIS data block (it is hidden from other users' inserter) and its shortcode, and data only shows on pages an administrator made and last edited. The settings list the pages that show each query.
* Upgrading: existing approved queries start as staff only with no columns, so they show nothing until an administrator sets them up.

= 0.25.1 =
* A lighter iMIS event import, so it no longer slows the site while it runs: iMIS's event list is read once at the start of an import and kept for its later batches (each batch used to read every event again); each batch makes or changes at most 5 pages and downloads at most 2 images (each image is saved in several sizes), every 2 minutes. A kept list older than 30 minutes is read again.

= 0.25.0 =
* CPD hours on event pages (Wormiss > Settings > iMIS events > Show CPD hours, off by default): each event's rows from its iMIS CPD Hours panel (the EventCPD business object) are read, cached, and shown after the event's text as a "CPD information" table, with the Australian and New Zealand category names and the hours added up per category (decimals allowed), plus the program level requirements covered. The Event details box gets a CPD hours tab listing the rows as iMIS has them. Read only: CPD hours are still changed in iMIS.

= 0.24.0 =
* "Event details": a full-width box under an Event's content, with tabs for When, Where (place, address, online link), Places (capacity and attendance), Waitlist and iMIS (event code, existing iMIS event ID), in place of the long list of fields in the sidebar. It saves the same details, cleaned the same way, before the event is sent to iMIS. The sidebar's "iMIS event" panel now just shows whether the event is sent, what happened last time and places left. A linked event shows only its link.

= 0.23.0 =
* Event address: street (two lines), suburb or city, state, postcode and country (Australia or New Zealand) in the iMIS event panel. iMIS keeps an event's address with the event (its "fullAddressAttribute"), so with any part filled in, the whole address is saved there when the event is sent (a blank state or country keeps the template's); with none, the template's address stays. The event page shows the address under the place. Before, only the place name was sent, and iMIS kept the template's address.

= 0.22.2 =
* iMIS event category IDs are up to 5 characters (iMIS's own limit; a longer one, e.g. COURSE, made iMIS fail the whole event save with "HTTP 500"). The event type screen says why a longer ID was not kept, and a type saved earlier with one stops the send with a clear reason.
* Before an event is sent, Wormiss checks that iMIS has its type's category, and says so when it does not, instead of saving the event under a category iMIS lacks.
* When iMIS does not let Wormiss create an event category (HTTP 501), the type screen says to create it in iMIS.

= 0.22.1 =
* The audit log shows what was sent to iMIS and what iMIS replied ("Show" on each line), when "Keep full request and response bodies" is on, so a rejected call can be checked. Passwords and tokens are removed before anything is saved.

= 0.22.0 =
* Send member changes to iMIS (Wormiss > Settings > Sync members into WordPress): when a member's first name, last name or email changes in WordPress (their profile, or an administrator), their iMIS contact is read, those three are set (the primary email; other emails and details are kept), and it is saved, in the background. Blank WordPress values never clear iMIS's. Only members with an iMIS ID; staff accounts and the member sync's own changes (which come from iMIS) are never sent. Off, and a dry run, by default.

= 0.21.0 =
* "Send events to iMIS now" (Wormiss > Settings), also run once a day: every published Event made in WordPress that iMIS does not have up to date (never sent, edited since it was last saved there, last sent with a problem, or only checked in a dry run) is sent, 10 a minute in the background, through the usual sync, so the sending switch and dry run apply. Events linked to or imported from iMIS are never sent. Each run is in the audit log.

= 0.20.0 =
* The iMIS event import works in batches: each run makes or changes at most 20 pages (each may download an image), then carries on in the background a minute later from where it stopped, until every event is done, so a large import never stops the page with a time limit. Unchanged pages cost nothing. The settings page shows how many events are still to check. Replaces the old 100-pages-per-run limit.

= 0.19.0 =
* Imported event pages carry iMIS's details: the full event description (with its formatting) as the page text, the iMIS event image as the featured image (downloaded once into the Media Library), and the iMIS category as the Event type (created with the category's ID when missing). Title and description follow iMIS whenever they change there.
* "Publish imported pages" (Wormiss > Settings > Import iMIS events): imported pages go live straight away, and drafts the import made earlier are published on the next import. A page an editor unpublishes afterwards stays a draft.
* Event pages linked to iMIS show when registration closes, while it is still to come.

= 0.18.0 =
* Import past iMIS events: "Also import past events since" (Wormiss > Settings > Import iMIS events) brings in finished events that started on or after that date too, as linked draft pages, for historical records. Their iMIS publish dates are ignored and expired or archived events are included; templates and unlisted statuses still are not. Up to 100 pages per import; blank (the default) imports upcoming events only.

= 0.17.0 =
* Import iMIS events: once a day (or on "Import iMIS events now" in Wormiss > Settings), each upcoming iMIS event the events block would list gets a linked Event page, saved as a draft for an editor to publish, with its iMIS name and description. Later imports keep titles in step with iMIS and refresh the event details kept on the page; a page someone trashed is not made again, and pages WordPress sent to iMIS are left alone. Nothing is changed in iMIS and no page is ever removed. Off by default.
* Linking an Event to an existing iMIS event now falls back to the events list when iMIS cannot read that event on its own.

= 0.16.0 =
* Link to an existing iMIS event: enter its ID (e.g. CONGRESS26) in the Event's "Existing iMIS event ID" box and save. Wormiss checks it is in iMIS and links the page, which then shows its dates, place, event code, places left and Register button from iMIS. A linked event is only read: WordPress never sends, changes or cancels it (trashing the page leaves it alone). Clear the box to unlink. Editors and administrators only.

= 0.15.1 =
* Member sync works in batches: each run creates or changes at most 50 accounts, then carries on in the background a minute later until every member is done, so a large membership no longer stops the page with a "critical error" (time limit). Runs never overlap, and the settings page shows how many members are still to check. A dry run still counts everything at once.

= 0.15.0 =
* Join the waitlist: on a full free event whose iMIS waitlist is open to everyone ("Anyone can join"), signed-in members see "Join the waitlist" on the Event page. It registers them on the iMIS waitlist (RegisterEvent with Waitlist), after checking the event is still full. They then see "You're on the waitlist" with "Leave the waitlist". Needs the places count IQA, so the site knows the event is full. Uses the registration switch and dry run.

= 0.14.0 =
* Event numbers: each event (or its event type, as a default) can set minimum and expected attendance, written to the event's main registration option in iMIS. Attendance that cannot fit the capacity is flagged before sending.
* Waitlist: each event (or its type) can choose who may join the iMIS waitlist (no one, staff only, or anyone) and whether freed places go to the waitlist automatically. Blank keeps the setting in iMIS.
* Places left: with a places-count IQA set in Wormiss > Settings > iMIS events, Event pages show "12 of 30 places left", "Full" or (capacity 1000 or more) "Places available", and the editor panel shows the same. A full free event shows "This event is full." instead of Register, and registering checks again. Counts are cached like the events list.
* Event pages show the event code; the Events list has Event code and iMIS columns. Event codes are limited to 10 characters, as in iMIS.
* Event type category IDs can have / between parts (e.g. M/L).
* Optional: list only events inside their iMIS publish dates ("Follow iMIS publish dates", off by default).

= 0.13.0 =
* Member sync: once a day (or on "Run the member sync now"), members from an iMIS IQA are created or updated as WordPress users (login = iMIS ID, a chosen non-staff role, no emails), matched by iMIS ID then email. Members whose status is not current are blocked from signing in (password and single sign-on) until it is current again. Staff are never changed; a run that would block an unusually large share blocks no one. Off and a dry run by default; each run's report shows in Settings and the audit log.

= 0.12.0 =
* One Wormiss menu in the admin, with an Overview (connection, and what is on or in a dry run), Events, Add New Event, Event types, Settings and the audit log. Settings moved from Settings > iMIS Bridge and the log from Tools > iMIS Bridge log; the old addresses redirect.

= 0.11.0 =
* Event types can have a default capacity, used for their events when an event's own Capacity is 0.
* "Create this category in iMIS when saved" on an event type creates its iMIS event category (POST /api/EventCategory) if it does not exist yet, with an ID made from the name when none is given; follows the event sending switch and dry run. The result shows on the type's edit screen.

= 0.10.0 =
* Free event registration: on free iMIS events (registration required, no fee), signed-in members with an iMIS ID get a Register button in the Upcoming iMIS events block and on Event pages, and can cancel from the Event page. Registers through iMIS (RegisterEvent, without payment, for the member themselves) after checking the event is open and they are not already registered. Off and a dry run by default. Paid events still link to iMIS.

= 0.9.0 =
* WooCommerce purchases: each paid order (processing or completed) by a member with an iMIS ID is recorded once as an iMIS activity, with the items and order number in the description and note and the order total in Amount. Nothing is created in iMIS finance. Off by default; uses the activity type and dry run of Record activity in iMIS.

= 0.8.0 =
* CPD points from Mark as done also go in the iMIS activity's Quantity/CEU, with the CEU type set in a new "CEU type for CPD points" setting (UF_4 keeps the points too).
* The iMIS activity type and CEU type settings are limited to 10 characters, as in iMIS.

= 0.7.0 =
* Event types (Events > Event types), each with its own iMIS template event and optional iMIS event category; events without a type, or whose type has no template, use the default template. An event with more than one type is not sent until one is chosen.

= 0.6.0 =
* Events post type sent to iMIS: a new event copies an iMIS template event (CloneEvent) and saves the WordPress title, description, dates, place, online link and capacity over it; later saves update it. Off and a dry run (iMIS _validate only) by default; optional cancel in iMIS when trashed; the event page shows when, where and a Register button.

= 0.5.0 =
* Upcoming iMIS events block and [wormiss_events] shortcode: events read from the iMIS Events API, soonest first, as cards or a list, each linking to its iMIS page; templates, unpublished, closed and finished events left out; cached with a fallback copy.

= 0.4.0 =
* Recording activity in iMIS: reads of members-only content, tracked downloads, the Mark as done block (with CPD points) and form submissions, as activity records of one configured type; off and a dry run by default, sent in the background with retries.

= 0.3.0 =
* Members-only content: the Members only editor panel for posts and pages (teaser for non-members), the Members only block, membership type and status rules from an approved membership query, and site-wide current statuses.

= 0.2.0 =
* Members' own data: the My iMIS data block and `[wormiss_member]` shortcode, approved member queries, the member's iMIS ID from a user field or the username, an iMIS ID field on user profiles for administrators, a Details layout, and privacy export and erasure of audit-log entries.

= 0.1.0 =
* First release: iMIS connection, connection test, audit log, and the iMIS data block and shortcode.
