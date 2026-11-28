# Phase 3 — iMIS/RiSE retirement: discovery

**Status:** complete inventory (from RiSE content export, 2026-11-28) · **Scope:**
catalogue every public surface still served by iMIS, map each to its WordPress target,
so the public site can be cut over from the Causeis **Launchpad** theme to the
sccadian + wormiss WordPress build.

---

## Headline finding (reframes Phase 3)

The live public site at **`www.skincancercollege.org` is still served entirely by
iMIS RiSE through the Causeis "Launchpad" theme** — ASP.NET WebForms, `/web/Shared_Content/…`
paths, `ci*` web-part controls, "Powered by Launchpad" (causeis.com). It is **not**
WordPress today. So Phase 3 is a **cutover of the whole public head** from Launchpad to
the sccadian/wormiss WordPress build (which must live on a separate host/staging not
linked from the live site), leaving only the transactional flows pointing back into iMIS.

## Source

A RiSE content export (`AsiDocumentExport`) provided by the owner on 2026-11-28:
**157 unique content pages (CON) + 393 navigation nodes (NAV)**, almost all Published.
Classified below by stripping the base64 blobs and bucketing by `Path`. This is the
authoritative tree (the live site has no crawlable WordPress sitemap). The raw classified
list is in the session's `scratchpad/p3/inventory.txt`.

The one split that matters: of the 157 content pages, **~56 are public** (the head to
migrate) and **~96 are iMIS back-office** (`/iCore/…` admin screens and staff
dashboards) that **stay in iMIS** and are out of scope for the public cutover.

---

## Migration status — staging snapshot (2026-11-28)

WordPress staging is live at **`https://website.scca.nohost.me`** (YunoHost; not
reachable from outside its network, so this is from its page sitemap). The **sccadian
theme + wormiss + lad are installed** — the sitemap exposes `page`, `post`,
`wormiss_event` and `lad_doctor` post types. Most of §A is already imported; the gaps
and cleanup below are what remains. WooCommerce is also present (`/shop/`, `/cart/`,
`/my-account/`) — see Notes.

**Data loaded:** the sub-sitemaps show the directory, events and news already populated —
**317 `lad_doctor` pins** (`/doctor/…`), imported **and geocoded** (coordinates populated,
so pins render), **~39 `wormiss_event` entries**
(`/event/…`) and **7 news posts**. A few events are test/legacy to prune
(`advanced-certificate-of-awesomeness27`, `tutorial-sandpit-enrolment`, `cscm-legacy-26`,
and `certificate-of-dermoscopy-2…6` duplicates).

Plus a members-only blog post type **`wormiss_blog`** (SkinTalk / Smart-Blogs), correctly
gated so it is excluded from the public sitemap, at **5,232 entries** — **confirmed genuine**
(imported via the wormiss importer, batched/sequenced, which took several days). Two
implications: (1) the members-only gate must hold across all 5,232 posts — the
privacy-load-bearing path — so verify there is no public leak at that scale (REST, feeds,
search); (2) a from-scratch re-import is a multi-day job, so the blog is already migrated and
**cutover should rely on incremental sync, not a re-import**.

**Done (on staging):** Home; About Us (+ `ambassadors`, `our-board` with per-member
pages); Accreditation; the full Education tree (essential / advanced / capstone /
introductory groupings + all eight course pages, fellowship, scholarships,
micro-learning, recognition-of-prior-learning, nursing-education, access-learning-hub);
Membership + join-now; CPD (participation/compliance, requirements, get-started with
activity-assessment / PDP / cpd-requirements) and **all member program-level pages**
(culturally-safe-practice, ethical-practice, addressing-health-inequities,
professionalism, CPR) + member CPD AU/NZ; FAQs; **Locate a Doctor** AU/NZ (lad);
members' blog; News; Events + Events-calendar; Sign-in + My Account; Positions Vacant,
Resources, SkinTalk, Noticeboard, Webinar/Video library; Risk-Prediction-Tool; promo-pack
/ brochure order pages; Congress 2027 — **landing page only**.

**Still to migrate (missing on staging):**
- **Scan Your Skin** consumer microsite — `check-your-skin`, `myths`, `skin-cancer-types`,
  `understanding-skin-cancer`, `what-to-expect-during-a-skin-check`, the landing, and the
  `scan-locate-a-doctor` AU/NZ finders. (Only the risk tool + brochure-order page exist.)
  This is the main content gap.
- **Congress 2027 sub-pages** — program, speakers, sponsors, venue, registration (only the
  landing exists).
- **SkinPro CPD library search** (`SkinPro-CPD-Search`) — confirm; the video/webinar
  libraries exist but the CPD search specifically may not.

**Cleanup before cutover (duplicate slugs from repeated page-imports — the "not full
setup yet"):** `join-now` ×4, `cpd-requirements` ×3, `cpd-participation-compliance` ×2,
`education-3`, `accreditation-3-2`, `locate-a-doctor-2`, `my-account-2`, `membership`
vs `membership-2`, `australia-2` / `new-zealand-2`, and the default `sample-page`. A
dedup / import-reconcile pass is needed (the same reconcile discipline lad and the
doctor-sync already apply).

**Notes / to confirm:**
- **WooCommerce** is installed. The plan keeps payments in iMIS (§C) — is WooCommerce
  intended for a WordPress store (non-membership items) or placeholder to remove? Confirm
  so Cart/Shop resolves to one path.
- **Site topology (recommendation):** a **single** WordPress site with Scan Your Skin and
  Congress as sections — **not** WordPress Multisite. YunoHost packages WP single-site;
  Multisite complicates upgrades/SSO and wouldn't share the lad doctor data across subsites.
  If `scanyourskin.org` needs its own brand/domain, use a domain alias or a separate
  YunoHost WP instance rather than Multisite.

---

## A. Public pages → migrate to WordPress

### A1. General content (`@/web/…`, 23 pages + home) → WP pages via wormiss **page-import**
- Home — `@/web/default` (+ `~/Home`, `~/Home-Mobile`) → sccadian home page
- About Us — `about-us`, `meet-our-ambassador`, `our-board` (`SYS-iframe-test` is a test page, drop)
- Accreditation — `accredited-skin-cancer-doctors` → page + the Locate-a-Doctor map (A4)
- Education — `education`, 8 course pages (`certificate-of-dermoscopy`, `certificate-of-skin-cancer-medicine`, `introduction-to-skin-cancer`, `understanding-skin-cancer`, `advanced-clinical-certificate-of-dermoscopy`, `advanced-clinical-certificate-of-skin-cancer-medicine-and-surgery`, `advanced-skin-surgery-workshop`, `skin-surgery-masterclass`), `fellowship`, `further-learning-scholarships`, `micro-learning`, `rpl`, `Nursing-Education-Pathway` → WP pages; course **delivery** is Moodle (see C integration), course **events** via wormiss events
- Membership — `join-now` → WP content page; the actual **join/renew/pay stays iMIS** (§C)
- SkinPro CPD (public) — `skinpro-cpd-join`, `understand-cpd-requirements`, `cpd-participation-and-compliance`, `frequently-asked-questions` → WP content; **join/subscribe stays iMIS**

### A2. Member-gated content (`@/web/member…`, 11 pages) → WP + wormiss members-only gating
- `member-access/cpd-in-australia`, `member-access/cpd-in-new-zealand`
- `skinpro-cpd/member/`: `cpd-framework`, `cpr-training`, `culturally-safe-practice`,
  `ethical-practice`, `health-inequities`, `professionalism`, `PDP`,
  `program-level-requirements`, `understand-cpd-requirements`
- → WordPress pages gated by `Wormiss_Access` (iMIS membership type/status), per the headless plan.

### A3. Microsites (14 pages) → WP pages / sections
- **Congress 2027** — `@/Conference/`: `Congress-2027`, `default`, `program`, `speakers`,
  `sponsors`, `venue`, `registration` → WP microsite; **registration/payment stays iMIS** (§C)
- **Scan Your Skin** (public awareness) — `@/ScanYourSkin/`: `Default`, `check-your-skin`,
  `understanding-skin-cancer`, `skin-cancer-types`, `myths`, `what-to-expect-during-a-skin-check`,
  `Risk-Prediction-Tool` → WP public microsite (the Risk-Prediction-Tool may be an embedded app)

### A4. Public Smart-Suite surfaces (6) → existing plugins
- **Locate a Doctor** — `Smart-Maps/Public/locate-a-doctor-au`, `…-nz`, **and**
  `…/scan-locate-a-doctor-au`, `…/scan-locate-a-doctor-nz` (the public "Scan Your Skin"
  doctor finder) → **lad + wormiss doctor-sync (already built, Phases 1 & 2)**; the two
  `scan-` variants are the same directory embedded in the Scan Your Skin microsite (lad
  shortcode/block with `region` + a scan context)
- **Members' blog** — `Smart-Blogs/Public/Smart-Blogs` → wormiss **blog** (import + gate)
- **SkinPro CPD search** — `Smart-Library/Public/SkinPro-CPD-Search` → a WP CPD resource
  search (wormiss IQA display, or a WP search over imported library items)

### A5. Sign-in (1) → wormiss SSO
- `Shared_Content/ContactManagement/IndividualAccountPage` (+ nav `~/Login`, `~/My-Profile`,
  `~/Membership/Member-Portal`) → wormiss **sign-in / SSO** + **member-display** / "My membership"

## B. Stays in iMIS — back-office (96 pages, not public, out of scope)

All `@/iCore/…` admin screens (Commerce, Finance, Pay-Central, Membership AutoPay, Events
management, Store, Forms, Groups, System Settings, Tasks, Tools) and all Smart-Suite / RiSE
**staff dashboards** (`…/Staff/…`, `/Shared_Content/Staff/Dashboards/…`: Accreditation, CPD,
Case Management, Contact-Us, Courses, Finance, Membership, Images, Higher Logic, …). These
are the iMIS application itself — staff keep using them; nothing to migrate.

## C. Stays in iMIS — transactional & integrations (deep-link, don't rebuild)

Per the headless plan (§0/§4): payments and the membership lifecycle stay in iMIS.
- **Cart / Shop / checkout**, **membership join/renew**, **SkinPro CPD join/subscribe**,
  **event registration & payment**, **Pay-Central** → WordPress deep-links out to iMIS.
- **Course / webinar delivery** — `Shared_Content/Events/Moodle-Events`, `Moodle-Reports`,
  `Zoom-Events` → Moodle/Zoom integration stays; events surface in WordPress via wormiss
  events, delivery happens in Moodle (wormiss already bridges WordPress ↔ Moodle).

## D. Separate surfaces → keep (out of scope)

- **Higher Logic community** — `scca.connectedcommunity.org` (its staff side appears as the
  `…/Staff/Dashboards/HigherLogic` dashboard). A Higher Logic product, not RiSE; keep as-is.

---

## E. Remaining gaps (the RiSE tree is now resolved)

1. **WordPress site host** — where is the sccadian/wormiss build today (staging URL), and how
   much of §A has wormiss already imported (pages / news / events / blog)?
2. **Cutover plan** — how `www` is switched from Launchpad to WordPress (DNS / reverse-proxy),
   and the exact paths that must deep-link back to iMIS afterwards (§C).
3. Confirm the two `scan-locate-a-doctor` maps are the same data as `locate-a-doctor` (just
   embedded in Scan Your Skin), so lad serves both with no extra feed.

## F. Open questions for the owner

1. Where is the WordPress site (host/URL), and what has wormiss imported into it so far?
2. Is the plan a full cutover, or WordPress alongside Launchpad section-by-section first?
3. Scan Your Skin and Congress — migrate as WordPress sections of the main site, or keep as
   their own microsites?
4. Confirm the §C list is the complete set that stays iMIS-served after cutover.

## G. Next step — section-by-section cutover checklist

With §A as the migration backlog, the natural order (each a slice of the bridge that mostly
exists):
1. **Locate a Doctor** (A4) — done; point `/maps` + the accreditation + Scan Your Skin finders
   at the WordPress map.
2. **Content pages** (A1) + **microsites** (A3) — wormiss page-import → sccadian pages.
3. **News / blog** (A4 blog) — wormiss news/blog import.
4. **Members-only content** (A2) + **sign-in** (A5) — wormiss SSO + access gating.
5. **Events / courses** — wormiss events; delivery stays in Moodle; payment stays iMIS.
6. **Cutover** — switch `www`, leave §C deep-linking to iMIS; retire the Launchpad pages.
