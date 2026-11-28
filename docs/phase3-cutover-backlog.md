# Phase 3 — cutover build backlog (ordered)

**Status:** ready to execute · **Source:** the gaps in `phase3-rise-discovery.md` (staging
is ~90% built; this is what remains to finish the build and cut `www` over from the Causeis
Launchpad site to the sccadian + wormiss WordPress site).

Ordering is dependency-aware: **Epic 0** unblocks everything, **1 & 2** can run in parallel,
**3** (verification) gates **4** (cutover). Tags: **[owner]** = a decision or external
action only the owner can do; **[dev]** = build work.

---

## Epic 0 — Foundations / unblockers (do first; small)

0.1 **[dev] Fix scheduled jobs behind SSO.** With staging behind YunoHost SSO, default
HTTP wp-cron is gated, so the nightly doctor sync, geocode continuation and imports stop.
Set `define( 'DISABLE_WP_CRON', true )` and a system crontab running WP-CLI
(`wp cron event run --due-now`). *Done when:* a scheduled sync fires and is logged while
the site is gated.

0.2 **[owner] Three decisions that gate later work:**
- **WooCommerce** — keep as a WordPress store (non-membership items) or remove? Everything
  else financial stays in iMIS. (Gates 4.2 and the cart/shop surface.)
- **Site topology** — confirm **single WordPress site** with Scan Your Skin + Congress as
  sections (recommended; not Multisite). (Gates 1.1/1.2 build approach.)
- **Confirm the stays-in-iMIS list** (cart, join/renew, event payment, Pay-Central) so
  pages deep-link rather than reimplement. (Gates 4.2.)

0.3 **[owner] iMIS REST access (Phase 0 dependency).** Service account with the
**RemoteService** role + the doctor IQA flagged "Available via the REST API", for the
ongoing automated doctor sync. CSV import is the fallback until this lands — not blocking,
but the directory can't auto-refresh without it.

## Epic 1 — Content gaps (finish the build) — parallel with Epic 2

1.1 **[dev] Scan Your Skin microsite.** Build the 7 consumer pages (`check-your-skin`,
`myths`, `skin-cancer-types`, `understanding-skin-cancer`, `what-to-expect`, the landing,
`risk-prediction-tool` already exists) + the `scan-locate-a-doctor` AU/NZ finders (lad
block with region + scan context — reuses the existing directory, no new feed). *Done:*
all pages live, finders render pins. *Dep:* 0.2 topology.

1.2 **[dev] Congress 2027 sub-pages.** `program`, `speakers`, `sponsors`, `venue`,
`registration` (registration **deep-links to iMIS**). Landing already exists. *Dep:* 0.2.

1.3 **[dev] SkinPro CPD library search.** Confirm the `SkinPro-CPD-Search` surface is still
wanted; if so, build it (wormiss IQA display, or a WP search over the imported library).
*Done:* members can search CPD resources.

## Epic 2 — Cleanup (pre-cutover hygiene) — parallel with Epic 1

2.1 **[dev] Root-cause duplicate imports (most valuable).** Repeated page-imports created
`-2/-3/-4` duplicates. Make wormiss page-import **idempotent / reconciling** (match on a
stable key, update in place, optional reconcile — mirroring lad's reconcile and the
doctor-sync keying) so re-runs stop creating duplicates. *Done:* a re-import creates zero
new duplicates. *Do before 2.2* so cleanup isn't undone.

2.2 **[dev] De-duplicate pages & prune placeholders.** Collapse `join-now`×4,
`cpd-requirements`×3, `cpd-participation-compliance`×2, `education-3`, `accreditation-3-2`,
`locate-a-doctor-2`, `my-account-2`, `membership`/`membership-2`, `australia-2`/
`new-zealand-2` to one canonical slug each (redirect the discarded ones); delete the default
`sample-page`. *Done:* one page per section; dups 301 to the canonical.

2.3 **[dev] Prune test/duplicate events.** Remove `advanced-certificate-of-awesomeness27`,
`tutorial-sandpit-enrolment`, `cscm-legacy-26`, and the `certificate-of-dermoscopy-2…6`
duplicates (confirm which are real instances first). *Dep:* 2.1 so they don't recur.

## Epic 3 — Verification (privacy-load-bearing; gates cutover)

3.1 **[dev/owner] Members-only gate at scale.** Verify the 5,232 `wormiss_blog` posts and
all member-gated pages are **not reachable anonymously** — via the front end, REST
(`/wp-json/`), feeds, search, or the sitemap — and *are* reachable by a current member.
Needs a window with SSO lifted (or a scoped whitelist). *Done:* anonymous = 403/redirect,
member = 200, on a sample across the 5,232.

3.2 **[dev] Public REST / map hardening.** `GET /wp-json/lad/v1/doctors` serves **publish
only** (no drafts, no opted-out records), and the public map renders for an anonymous
visitor. *Done:* anonymous fetch returns only public doctors; map loads logged-out.

3.3 **[dev] Member sign-in end-to-end.** Once a page is public, test the wormiss iMIS SSO
sign-in → gated content visible → sign-out. *Done:* a real member can sign in and see
members-only content.

## Epic 4 — Cutover (after Epic 3 is green)

4.1 **[dev] Redirect map.** Every old Launchpad URL → new WP slug (from the discovery
inventory): `/web/…`, `/EventDetail?EventKey=…`, `/maps`, `/ScanYourSkin/…`, `/Conference/…`,
`/Membership/…`, etc. Preserve SEO and existing inbound links. *Done:* every public old URL
301s to its WP equivalent.

4.2 **[dev] Confirm iMIS deep-links.** Cart/shop, join/renew, event registration/payment,
Pay-Central link out to iMIS cleanly from the WordPress pages (per 0.2). *Done:* each
transactional CTA reaches the right iMIS page.

4.3 **[owner/dev] Flip the site public + switch `www`.** Lift YunoHost SSO to public with
**wormiss doing the gating** (not SSO), keeping only the deliberately-gated paths; point
`www.skincancercollege.org` at WordPress (DNS / reverse-proxy). Rely on **incremental
sync**, not a from-scratch re-import (the blog import is a multi-day job). *Done:* `www`
serves WordPress; members-only content still gated by wormiss.

4.4 **[owner/dev] Retire Launchpad.** Decommission the Launchpad public pages once the
redirects and cutover are confirmed; iMIS back-office (`/iCore/…`, staff dashboards) and the
transactional flows (§C) stay. *Done:* no public URL serves Launchpad; back-office unchanged.

---

## Critical path

`0.1 + 0.2` → (Epic 1 ∥ Epic 2, with 2.1 before 2.2/2.3) → `Epic 3` → `4.1 + 4.2` → `4.3` →
`4.4`. Epic 0.3 (iMIS REST) runs alongside and only blocks *automated* doctor refresh, not
cutover (CSV fallback covers it).
