# wormiss — functional smoke test

A manual, browser-only checklist for confirming the member-facing features work on
a live or staging site. No code required.

**Set up a few test identities** and keep them in separate browsers (or one normal +
one private/incognito window):

- an **administrator**,
- a **member** — a WordPress account linked to a current iMIS membership, with a
  blog-reader role where the blog is tested,
- a **non-member** — a plain subscriber with no current membership,
- **logged out** (the public).

The rule of thumb for every gate: it should be visible to the administrator and the
member, and hidden (teaser, 403, or simply absent) for the non-member and the public.

---

## Two "category" systems — don't mix them up

wormiss has two separate category taxonomies that do different jobs:

| To restrict… | Use | Where |
|---|---|---|
| Who can **read a post or page** | Members-only rules, or the per-post "Members only" switch | Settings → Wormiss → Members; by **post** category / tag / post type / page tree |
| Who can **fetch an image file** | "Protected delivery" / "Lock to post" flags | Media → Media categories |

- **Post categories** (Posts → Categories, e.g. "News") drive the members-only
  *content* rules. They appear in the rules dropdown under "Categories".
- **Media categories** (Media → Media categories) protect *image files*. They carry
  the protected/lock flags and are **not** shown in the members-only rules — that is
  by design.
- **Pages have no categories.** To gate pages in bulk, use the rule type "Page and
  its child pages"; for one page, its "Members only" switch.

---

## How membership is determined (members vs non-members)

A user counts as a **member** when, at the moment access is checked:

1. Their WordPress account has a linked **iMIS ID** (from the "member ID" user field,
   e.g. `imis_id`, set by single sign-on *and* written by the member sync), and
2. If a **membership query** is configured (Settings → Wormiss → Members →
   "Members-only content"), their iMIS record's **status is one of the Current
   statuses** (e.g. `A`). Without a membership query, any account with an iMIS ID
   counts as a member.

So, in practice: **member = linked iMIS ID + current status.** Everyone else — no
iMIS ID, a lapsed/non-current status, or not synced — is a **non-member** and gets
the teaser. Access also requires being logged in (a logged-out visitor is prompted to
sign in). A rule's optional membership *types*/*statuses* narrow it further (blank =
any current member). The daily member sync keeps the linkage and status up to date;
its last run is shown on the Members tab.

## 0. Installed cleanly

- [ ] Plugins screen shows wormiss at the expected version, active, no error notice.
- [ ] Settings → Wormiss tabs load with no PHP warning at the top.

## 1. Members-only rules (bulk content gating)

- [ ] Settings → Wormiss → Members → **"Members-only rules"**: add a rule for a
      **post category** (e.g. News), leave types/statuses blank, Save. Put a test
      post in that category.
- [ ] **Logged out / non-member:** the post shows the teaser + "Sign in to read it",
      not the body.
- [ ] **Member:** full content.
- Who counts as a "member" is set under **Members-only content** on the same tab:
  the membership query + type/status columns. A rule's optional types/statuses narrow
  it (blank = any current member). Non-members and the public are always blocked —
  there is no separate "apply to non-members" step; restricting the content is the step.
- Precedence: a post's own "Members only" switch always overrides a rule; the most
  specific rule wins (page tree > category/tag > whole post type).

## 2. Media categories + editor library gate

- [ ] Media → Media categories: add a couple. Settings → Wormiss → Media →
      **"Department access to categories"**: tick which departments see which, Save.
- [ ] A non-admin staff user's Media Library shows only their allowed categories plus
      their own uploads; administrators see everything.
- Members (subscribers) have no Media Library / file picker at all, so this gate is
  for staff/editors only.

## 3. Protected delivery ⚠️ needs one server step

**Important — flagging a category vs. moving the file.** Flagging a media category
"Protected delivery" makes wormiss point *references* to its images at the gated
`/wormiss-media/<id>` endpoint. It does **not**, on its own, move an
**already-uploaded** image's original file out of the public uploads folder — so a
**manually uploaded** image's raw uploads URL stays directly reachable. The original
is moved into the private store only when the image is brought in through the **blog
import** (which calls the store step). So test protected delivery with a
**blog-imported** image in an already-protected category, and treat manual uploads as
"references gated, original file still public" until a migration path stores them.

- [ ] If the Media tab shows the "stored under wp-content, not truly private" warning,
      set it up properly. In `wp-config.php`:
      `define('WORMISS_PROTECTED_DIR', '/path/outside/webroot/wormiss-protected');`
      and in nginx:
      `location /wormiss-media-internal/ { internal; alias /path/outside/webroot/wormiss-protected/; }`
- [ ] With the import's picture category flagged **"Protected delivery"** (section 5),
      import a blog post that has a picture. That picture's original is moved into the
      private store: its **uploads URL should 404/not load**, and it loads only via
      `/wormiss-media/<id>` for someone allowed to see it.
- [ ] A **manually uploaded** image you merely *assign* to a protected category: its
      `/wormiss-media/<id>` references are gated, but its original uploads URL is still
      reachable (expected — it was never moved into the store).

## 4. Lock to post (strict image protection) 🔒

- [ ] Media → Media categories → edit a category → tick **"Lock to post"** (it implies
      protected delivery). Use a **blog-imported** picture in that category — imported
      pictures are attached to their post *and* moved into the private store, which is
      what lock-to-post needs. (Merely assigning the category to a manual upload gates
      references but leaves the original file public, as in section 3.)
- [ ] **Member**, viewing that post: the image displays normally.
- [ ] In the member's browser, right-click the image → **Copy image address** — it
      looks like `…/wormiss-media/<id>?t=<token>`.
  - [ ] Open the **bare** URL (delete the `?t=…`) → **403 Forbidden**.
  - [ ] Paste the **tokened** URL into a **different member's** browser → **403**
        (the token is bound to the first member).
  - [ ] Wait ~10+ minutes, reload the tokened URL → **403** (expired). Re-opening the
        post mints a fresh token.
- [ ] **Logged out:** the post is a teaser and the image → **403**.
- [ ] **Administrator:** the image loads (admins are exempt).
- Honest limit: a member viewing the post can still **save** an image they can see.
  Locking stops link-sharing and out-of-context fetches, not screen-grabs.

## 5. Members' blog pictures (iMIS blog import)

- [ ] Settings → Wormiss → Members' blog → **"Media category for imported pictures"**:
      choose a category (flag it protected, or lock-to-post, to serve blog pictures
      privately/strictly). Run the import.
- [ ] Imported pictures are filed into that category; if protected/locked they are
      served through the gated endpoint.
- [ ] **Author check:** open an imported picture in Media Library → list view — its
      **Author** should be the post's author (the member), not the account that ran
      the import.

---

## Quick "is it actually blocking?" test

Open a members-only post in a private/incognito window (logged out) → teaser. Then as
a plain subscriber with no membership → teaser. Then as a current member → full
content. If a non-member sees the full content, check that the membership query is
configured and that the rule's types/statuses aren't accidentally matching everyone.
