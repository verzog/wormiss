# wormiss — Media access & categories

**Status:** scoped, pre-implementation · **Scope:** site-wide (all roles), administrators exempt

A WordPress Media Library + editor governance feature: images are organised into
curated categories, and who can see/use which images while editing is controlled
by a role × category matrix. Sensitive categories (e.g. members' blog images) can
additionally be served with real byte-level protection.

This is a WordPress editor/media concern, not a Moodle/iMIS bridge concern — it
lives in wormiss because wormiss already imports and manages blog images in the
Media Library and owns the members-only gate those images need.

---

## Decisions (confirmed with the owner)

- **Home:** wormiss, applied to **all roles** (not only members).
- **Administrators are exempt** — they see and use everything (otherwise no one can curate).
- **Categories:** curated, hierarchical buckets the admin defines (Logos, Headshots,
  Course banners, Event photos, …).
- **Access model:** a full **role × category matrix** — each role may see only the
  categories granted to it, **plus images it uploaded itself**. Effective visibility
  for a non-admin user = (own uploads) ∪ (images in any category their role is allowed).
- **Uncategorised:**
  - A reserved **parent category "End-user images"**. Every upload by a **non-staff**
    user is auto-filed into it (on `add_attachment`), so all end-user uploads can be
    governed as a group.
  - A **staff** user's upload left uncategorised is visible to its **uploader (and
    admins) only** until filed.
- **Byte-level security:** only for categories flagged **"protected delivery"**
  (member-blog images, etc.); all other categories stay on the fast public path with
  editor/library governance only.

### Still to confirm
- **Who is "staff"?** Proposed default: roles with `edit_others_posts` (editor,
  admin, …) are staff; everyone else (members/subscribers/end-users) is non-staff.
  Make the staff-role list a setting.

---

## 1. Data model

- **Taxonomy** `wormiss_media_cat` (hierarchical) registered on the `attachment`
  object type. One reserved parent term: **End-user images**. An image may be in
  several categories.
- **Matrix** (wormiss option): for each editable role → the set of allowed category
  term ids. Plus an "uncategorised visible to" rule (default: uploader + admins).
- **Per-category flag:** `protected_delivery` (bool). Marked categories are served
  through the gated endpoint (section 4).
- **Staff roles** (wormiss option): which roles count as staff (default: roles with
  `edit_others_posts`).

## 2. Enforcement surfaces (the hard part)

A role × category gate only holds if enforced on **every** surface that lists or
serves attachments. Missing one leaks. Required hooks:

| Surface | Hook |
|---|---|
| "Add media" modal in editor + Media **grid** | `ajax_query_attachments_args` |
| Media Library **list** view (`upload.php`) | `pre_get_posts` / `parse_query` |
| Block editor / REST media (`/wp/v2/media`) | `rest_attachment_query` |
| Edit / delete / attach a specific image by ID | `map_meta_cap` (`edit_post`, `read_post`, `delete_post` for attachments) |
| Category filter dropdown in the modal + grid | `wp_enqueue_media` + a small `wp.media` view extension |

Effective query filter for a non-admin user: `author = current_user` **OR**
`tax_query` term in (own role's allowed categories). Admins: no filter.

## 3. Admin UI (under the existing wormiss menu)

- Manage category buckets (the taxonomy UI; the reserved "End-user images" parent is
  created on activation and not deletable).
- The **role × category matrix** — a roles × categories grid of checkboxes.
- The **staff-roles** setting and the **uncategorised-visibility** setting.
- A **bulk-categorise** tool for existing media (everything starts uncategorised).

## 4. Byte-level protected delivery (for flagged categories only)

For categories flagged `protected_delivery` (member-blog images, confidential docs):

- Store the files **outside the public path** (e.g. a `wormiss-protected/` dir nginx
  denies, or outside the web root). wormiss's blog-image import writes member-blog
  images here.
- Serve via a WordPress endpoint `/wormiss-media/<id>` that: checks the **same
  members-only / role gate wormiss already uses** (and the matrix), then streams the
  file using nginx **`X-Accel-Redirect`** (PHP authorises; nginx serves) — auth +
  performance. (Apache fallback: `X-Sendfile`.)
- Rewrite `<img src>` in blog content to the gated endpoint (wormiss already rewrites
  blog content and pictures — extend that).
- Non-member → 403; the raw file is never directly reachable.

**Honest limitations**
- Editor/library governance (sections 1–3) controls **visibility/usability in the
  editor**, NOT the public reachability of a file's raw URL. URL-level protection
  needs section 4.
- Section 4 applies only to flagged categories; normal media stays public (by design,
  for performance and public pages).
- Protected images can't be publicly CDN-cached; each request costs a PHP auth check
  (mitigated by `X-Accel-Redirect` + per-session cache headers).

## 5. Suggested phasing (one concern per PR, wormiss conventions)

1. **Categories** — the taxonomy, the reserved "End-user images" parent, the
   attachment picker, the modal/grid filter dropdown, and auto-filing non-staff
   uploads. Low risk, independently useful.
2. **Enforcement** — the role × category + own-media gate across all five surfaces,
   admin-exempt, uncategorised rule. Test-heavy.
3. **Admin matrix + staff/uncategorised settings + bulk-categorise** tool.
4. **Protected delivery** — the gated endpoint, protected storage, `X-Accel-Redirect`,
   content URL rewriting; wire member-blog images into it. (The security sub-project.)

## 6. Build vs buy

The **taxonomy half** (phase 1) is well served by plugins (Enhanced Media Library,
FileBird). The **role × category enforcement across every surface** (phase 2) and the
**gated delivery** (phase 4) are the genuinely custom parts and justify a
wormiss-native build. Option: use an existing plugin for the buckets, build only the
gate + delivery.

## 7. Conventions (match wormiss)

WordPress Coding Standards (phpcs, warnings fail), GPLv3 (© Vernon Spain), AU/UK
spelling, security order (capability → nonce → sanitise; escape output), Privacy API
if any personal data is stored, CI as wormiss already runs it. New code in
`includes/class-wormiss-media-*.php` registered from the plugin bootstrap; settings in
the existing wormiss settings/admin classes.
