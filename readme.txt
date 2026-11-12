=== Wormiss – iMIS bridge ===
Contributors: verzog
Tags: imis, membership, association, events, members
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.28.0
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

Yes. Under Events > Event types, add a type (e.g. Workshop, Conference, Webinar) with its iMIS template event ID and, optionally, an iMIS event category ID. Tick one type on each event. A new event is copied from its type's template; the category, if set, is written to the event each time it is sent. A type can also have a default capacity (used when an event leaves its own Capacity at 0), and "Create this category in iMIS when saved" makes the type's iMIS event category for you if it does not exist yet. Events with no type, or whose type has no template, use the default template in Wormiss > Settings. The template only matters when the event is first created in iMIS.

= Can members register for events on the website? =

For free events, yes. Under Wormiss > Settings > iMIS events, tick "Members register for free events here" (keep the dry run on first). Events whose iMIS registration type is "registration required" (no fee) then show a Register button to signed-in members with an iMIS ID, in the Upcoming iMIS events block and on Event pages; Event pages also show "You're registered" and a Cancel button. Members always register themselves, using the event's first registration option. Paid events keep linking to iMIS to pay.

= Can members' WordPress accounts come from iMIS? =

Yes. Build an iMIS IQA with no prompts that returns ID, Email, FirstName, LastName and Status for the members who should have website accounts (tick "Available via REST API"). Under Wormiss > Settings > Sync members into WordPress, enter its path, choose the role for new members (only roles without editing rights are offered), and tick "Sync members daily" with the dry run still ticked. Press "Run the member sync now" and read the report. When it looks right, untick the dry run. Members are matched by iMIS ID, then email; new ones get the iMIS ID as their username and no email. Members whose Status is not in the Current statuses (Members-only content) are blocked from signing in until it is current again. Staff accounts are never changed.

= Can I make a page for an event that is already in iMIS? =

Yes. Add an Event (Wormiss > Add New Event), give it a title and any text you like, enter the iMIS event's ID (e.g. CONGRESS26) in "Existing iMIS event ID" in the iMIS event panel, and save. The page then takes its dates, place, places left and Register button from iMIS. WordPress never changes or cancels a linked event in iMIS. Clear the box and save to unlink it.

= Can Wormiss make pages for all our iMIS events? =

Yes. Under Wormiss > Settings > Import iMIS events, tick "Import iMIS events daily" and press "Import iMIS events now". Each upcoming iMIS event (the same ones the events block lists) gets a linked Event page with its iMIS description, image and event type, saved as a draft (or published straight away with "Publish imported pages"). Titles follow iMIS; dates, place and places left always come from iMIS. Nothing is changed in iMIS, finished events keep their pages, and a page you trash is not made again. For historical records, set "Also import past events since" to a date to bring in finished events from then on as well.

= Can members join a waitlist on the website? =

Yes, for free events. Set the event's waitlist to "Anyone can join" (in its iMIS event panel, its event type, or in iMIS) and set up the places count IQA. When the event is full, its page shows "Join the waitlist" to signed-in members. Members already on the waitlist see "You're on the waitlist" and can leave it. iMIS offers freed places to the waitlist as set in iMIS (or by "When a place frees up").

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

= 0.28.0 =
* Event details are edited in the page. Three blocks for event pages: Event facts (when, venue and address, online link, places and the Register button), CPD information (the CPD table) and iMIS settings (event code, existing iMIS event, attendance and waitlist; shown to editors only, never on the website). On an Event made in WordPress each is edited in place, laid out as on the website; on a linked or imported event they show what iMIS has. Events open inside their page template (WordPress 6.8 and later, and in the editor on earlier versions), with these blocks editable there, so editors see the page as it will look.
* When the event template places the Event facts or CPD information block, they are no longer added to the event's text as well.
* In the block editor the "Event details" box is gone (the classic editor keeps it). A block editor save is sent to iMIS as soon as it has finished.
* CPD lines set in WordPress show on the event page straight away. Saving other details never sets an event's CPD (so never removes iMIS's lines), and only editors and administrators can change CPD lines.

= 0.27.1 =
* "Open Event details" button in the sidebar's iMIS event panel: newer WordPress folds the boxes under the content into a closed "Meta Boxes" panel at the bottom of the editor, which is easy to miss. The button opens that panel (and the Event details box, if collapsed) and scrolls to it.

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
