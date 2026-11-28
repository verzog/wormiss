# Making iMIS headless with WordPress

**Status:** in progress — Phases 1 & 2 shipped (wormiss v0.76.0; doctor-sync +
nightly schedule + geocoding); owner decisions in §7; Phase 3 (RiSE retirement) pending ·
**Scope:** iMIS stays the system of record (data, payments,
membership lifecycle); WordPress (the **sccadian** block theme + the **wormiss**
bridge) becomes the only public and member-facing "head". No visitor ever lands
on an iMIS/RiSE page.

**Key fact (read first):** this is *not* greenfield. wormiss is already a working
iMIS bridge (v0.74). Most of "headless iMIS" is done. This doc inventories what
exists, names the gaps, and plans the one large piece that is still an island —
the **Locate a Doctor** directory — plus the audit needed to retire the last
RiSE pages. It is deliberately a "don't rebuild what's built" document.

---

## 0. What "headless" means here

| Layer | System | Notes |
|---|---|---|
| Data / records | **iMIS** | Contacts (Party), membership types/status/dates, events, CPD, activities. The source of truth. |
| Money / lifecycle | **iMIS** | Checkout, pricing, coupons, subscriptions, renewals, invoices, dunning, gateways. **Stays in iMIS, by design** (see `memberpress-replacement-spec.md`). |
| Presentation | **WordPress** | `sccadian` theme renders every public and member page; `wormiss` pulls iMIS data in and gates it. |
| Integration | **wormiss** | Server-side OAuth to iMIS, IQA + Events + Party calls, caching, sync, SSO, content gating. |

"Headless" is achieved when **no public URL points at RiSE** and every member
touchpoint (sign-in, profile, members-only content, events, CPD, the directory)
is served by WordPress, with iMIS reached only server-side.

---

## 1. What already exists — inventory (do not rebuild)

**wormiss** (`includes/class-wormiss-*.php`), already shipped:

- **REST client** (`rest-client`): OAuth **password grant** to `{base_url}/token`,
  bearer token cached with expiry, **one retry on 401**, timeout from settings.
  Service account URL/username/password from settings or the
  `WORMISS_IMIS_URL` / `WORMISS_IMIS_PASSWORD` constants; the password lives in
  its own option and is never logged. Methods: `run_iqa()` →
  `GET /api/iqa?QueryName=…` with paging; `get_events()` → `GET /api/Event`;
  `get_party()` / `update_party()` → `GET|PUT /api/Party/{id}`; document execute.
- **IQA display** (`iqa-display`): `[wormiss_iqa]` shortcode + `wormiss/iqa` block.
  **Only whitelisted columns reach the page**; personal-column detection; audience
  gate (`may_see`); admin-placed check; **transient cache with a "last good copy
  if iMIS fails" fallback**. *This is the safe server-side-proxy-with-cache
  pattern already done.*
- **Events / CPD**: full read + sync + push (`events`, `open-events`,
  `event-types`, `event-sync`, `event-cpd`, `event-registration`, `event-import`,
  `event-push`, `event-editor`).
- **Membership & profile**: `member`, `member-display`, `member-push`,
  `membership` — each member's iMIS type/status/join/expiry synced onto their WP
  account.
- **Members-only content**: `access`, `access-rules`, `members-only` — **both
  per-post and site-wide rule-based** gating by iMIS type/status (a category, tag,
  post type or page tree via `Wormiss_Access_Rules` + its editor in
  `Wormiss_Admin`), fails closed, cache-safe teaser, "Members only" block + editor
  panel. Tested (`test-access-rules.php`).
- **Sign-in / SSO / identity**: `sign-in`, `sign-in-check`, `user-sync`, `roles`.
- **Content**: `blog*`, `news-import`, `page-import`, `page-blocks`,
  `page-layout`, `page-menu`.
- **Media governance**: `media*` — categories, role × category gate, **and
  byte-level protected delivery** (`Wormiss_Media_Delivery`: gated
  `/wormiss-media/<id>` endpoint, `X-Accel-Redirect` / `X-Sendfile` / readfile
  fallback, protected store, content-URL rewriting; registered in
  `Wormiss_Plugin::init()`; tested `test-media-delivery.php`). See
  `media-access-spec.md`.
- **Other**: `woocommerce`, `mail-divert` (staging safety), `batch`, `logger`,
  `activity`, `completion`, `settings`, `admin`.

**sccadian** — the SCCA WordPress block theme (the presentation layer).

**lad (Locate a Doctor)** — a **standalone** WordPress plugin: `lad_doctor` CPT,
public read-only REST (`GET /wp-json/lad/v1/doctors`, publish-only), Leaflet map,
and an importer that accepts **manual CSV exports** from iMIS Causeis Smart Maps.
Now wired into the bridge by the wormiss doctor-sync (Phases 1 & 2, below); the CSV
importer stays as the standalone / fallback path.

> **Update — lad v1.9.0.** Since this plan was first written, lad has gained a
> native `_lad_location_id` meta (per-practice location, e.g. the SCCA workplace
> address number), an opt-in CSV-import reconcile (`lad_enable_reconcile`), and
> geocode diagnostics with a retry-failed control. So the multi-location key and
> the geocoder the sync relies on are first-class in lad, not bridge-only — and
> lad's own reconcile is an alternative to the IQA sync's, so enable one feed, not
> both (§5).

**MemberPress** — installed but never configured; removable with no cutover risk.

---

## 2. Target architecture

```
                 server-side only, least privilege
   iMIS (SoR) ───────── OAuth password grant ──────────▶ wormiss REST client
   (Party, IQA,         (service account w/ RemoteService         │
    Events, CPD)         role; per-IQA "Available via REST")      │
                                                                  ▼
                                      transient cache  +  CPT mirrors (e.g. lad_doctor)
                                      "last good copy" fallback          │
                                                                  ▼
                                      sccadian theme + wormiss blocks + lad map
                                                 (the only public head)
```

Principles (most already honoured by wormiss — apply them to lad too):

1. **Every iMIS call is server-side.** The browser never sees iMIS, a token, or a
   raw IQA. Public JSON is served from WordPress's own copy (as lad already does).
2. **Least-privilege API account** with access only to the specific secured IQAs
   needed (section 3).
3. **Cache + last-good fallback** so an iMIS outage or the current API lockdown
   degrades gracefully rather than blanking the site.
4. **Fail closed** for anything gated; **enforce privacy in the pipeline**, not
   only in the source query (section 3).
5. **Secrets via constants** (`WORMISS_IMIS_URL` / `WORMISS_IMIS_PASSWORD`) in
   `wp-config.php`, not left in the options table where avoidable.

---

## 3. Security & privacy constraints (load-bearing)

From the **iMIS IQA API security advisory** work and ASI's public guidance:

- **The IQA REST surface is being locked down by ASI pending a patch.** Treat
  automated pull as **blocked until confirmed restored** — this is the headline
  dependency, not a code problem (section 6, Phase 0).
- **Per-IQA + per-account grants are required.** Each IQA called over REST must
  have **"Available via the REST API"** ticked on its Security tab
  (`IsAvailableForREST`), and the service account must hold the **RemoteService**
  security role. Without both, `/api/iqa` and `/api/QueryService` return **404 for
  any user** — a *permission* result, not a missing query. Don't chase these 404s
  as integration bugs.
- `QueryDefinition` is restricted for public users (EMS 20.3.231); the legacy
  `queryexport.aspx` was removed and direct IQA page access limited to sys-admin.
  **Never expose an IQA to public/unauthenticated users.**
- **PII posture:** iMIS Party records carry names, postal addresses, phones, and
  sometimes DOB **even when email addresses are anonymised**. The staging box held
  stale June mailinator data; never leave member PII on a web-reachable box.
- **Public-directory consent (lad):** `OptOut` must draft **all** of a doctor's
  location pins; **only practice/work addresses** publish (home/personal skipped).
  The source query filters these, *and* the pipeline re-enforces them — "the
  plugin is the safety net, not the only line of defence."

---

## 4. Domain-by-domain status

| Domain | Head today | Headless? | Remaining work |
|---|---|---|---|
| Directory (Locate a Doctor) | WordPress, **CSV-fed** | Partly | Automate the iMIS feed (section 5) |
| Events / CPD listings | WordPress (wormiss) | **Yes** | Audit any residual RiSE event pages |
| Membership / profile | WordPress (synced) | **Yes** | — |
| Members-only content | WordPress (wormiss) | **Yes** | — (per-post + site-wide rules both built) |
| Sign-in / SSO | WordPress (wormiss) | **Yes** | Confirm no RiSE login is linked |
| Join / renew / pay | **iMIS (by design)** | N/A | Deep-link out to iMIS cleanly |
| News / blog / pages | WordPress (imported) | **Yes** | — |
| Media | WordPress (wormiss) | **Yes** | — (protected delivery built; nginx config only) |

---

## 5. The main new build: fold Locate a Doctor into the bridge

**Today:** build an IQA in iMIS → export CSV/xlsx by hand → upload → lad importer
(batched) → geocode → publish → `lad/v1/doctors` → Leaflet. Error-prone: schema
drift, duplicate pins, and a human in the loop on every refresh.

**Target:** wormiss runs the SCCA doctor IQA on a schedule via `run_iqa()`, maps
rows to `lad_doctor` posts, and lad stays pure presentation. The CSV importer is
kept as a fallback and for standalone installs.

**Data contract** (reuse, don't reinvent): `LAD_Meta::fields()` (clinic, address,
suburb, state, postcode, region, phone, email, website, lat, lng) + hidden
`_lad_imis_id` + `_lad_location_id`; `lad_accreditation` taxonomy; featured image
= photo.

**The known SCCA query** joins four views — `vBoCauseisContact`, `vBoSmart_Maps`,
`vBoSCCA_Smart_Maps_Intial_Data_View`, `vBoSmart_Maps_Multi` — filtered
`WHERE [vBoSmart_Maps].[OptOut] <> 1` (full text in the appendix). Lessons already
paid for, to bake into the sync:

- **iMIS ID alone is not unique** once a doctor has multiple practices. Key on
  **iMIS ID + location** (`Location` / workplace `AddressNumber` → `_lad_location_id`,
  a native lad field as of v1.9.0).
- **Do not join two "one row per location" views at once** — that is a cartesian
  product (2 workplaces × 2 locations = 4 phantom pins). Use one per-location view.
- **Withdrawals must be reconciled, not just filtered.** The IQA filters
  `OptOut <> 1`, so an opted-out doctor (or a now-excluded practice address)
  **disappears from the feed entirely** — a row-by-row upsert never sees an
  `OptOut` value and would leave their existing pins published. The sync must
  therefore **draft any published `lad_doctor` whose (iMIS ID + location) is absent
  from a refresh — but only after a confirmed-complete, successful fetch**, so a
  partial, empty or failed pull can never mass-unpublish the directory. (Belt-and-
  braces alternative: a second "withdrawal" IQA returning opted-out iMIS IDs.)
  Non-practice addresses likewise never publish.

**Design decision (confirmed with owner):** **wormiss owns the iMIS pull** and
writes `lad_doctor` posts; lad consumes them and **stays installable standalone on
CSV** (so it keeps working with no wormiss present). Rationale: reuse wormiss's
OAuth, token cache, 401-retry, logging and cache/last-good infra rather than
duplicating an iMIS client inside lad.

---

## 6. Phasing (one concern per PR, wormiss/lad conventions)

**Phase 0 — Confirm the channel (dependency, not code).**
Assume REST access will be available (**status TBA** — confirm with Causeis/ASI);
build Phase 1 against that assumption in parallel, but **gate go-live** on it being
green. Stand up a least-privilege service account with the **RemoteService** role;
tick **"Available via the REST API"** on the doctor IQA (and on `PlacesTaken` /
registrations if still used); smoke-test one read through wormiss. If access is
not restored, Phase 1 falls back to the existing manual CSV path unchanged.

**Phase 1 — Doctor directory sync (on-demand).**
New `includes/class-wormiss-doctor-sync.php`: `run_iqa()` → normalise →
`lad_doctor` upsert keyed on iMIS ID + location; OptOut/practice/multi-location
enforced, **plus withdrawal reconciliation (§5) after a confirmed-complete fetch**;
batched like the existing importer; cached with last-good fallback; a **"Sync now"
button** in the wormiss admin. CSV importer untouched. Test-heavy (reuse lad's
duplicate/opt-out test cases, and add a reconciliation-on-complete-only test).

**Phase 2 — Nightly schedule + geocode + resilience.**
Add a **nightly** run (wp-cron or adhoc task) alongside the Phase 1 on-demand
button — both paths, per the owner. Throttled and resumable, **kill-switch
setting**, hide controls while a run is queued; geocode only new/changed addresses
via the **planned Google Maps key** (fast/bulk) with Nominatim as fallback; clear
`_lad_geocode_failed` on refresh; surface failures in the wormiss logger.

**Phase 3 — Retire the last RiSE head (discovery-led).**
No list of remaining RiSE pages exists yet, so this **starts with a discovery
pass**: enumerate every public URL still served by iMIS/RiSE, then migrate or
retire each, add redirects, and confirm sign-in/join/renew deep-link cleanly to
iMIS and nothing else does. Deactivate/delete MemberPress (after the `[mepr-` /
`MeprXxx` safety grep).

**Phase 4 — Configure the already-built hardening (no new code).**
Both items the older scoping docs filed as "future" are already implemented at this
commit: **site-wide rule-based gating** (`Wormiss_Access_Rules` +
`Wormiss_Access::post_rules()` + the rules editor in `Wormiss_Admin`; tested) and
**protected media delivery** (`Wormiss_Media_Delivery`, registered in
`Wormiss_Plugin::init()`; tested). Remaining is **deployment/configuration, not a
build**: define the desired site-wide members-only rules, and add the nginx
internal `location` that `X-Accel-Redirect` points into so flagged categories serve
from the protected store. `memberpress-replacement-spec.md` and
`media-access-spec.md` predate these shipping — treat them as history.

---

## 7. Decisions (confirmed with owner)

1. **Sync home:** wormiss owns the pull and writes `lad_doctor`; lad stays
   installable standalone on CSV (no hard wormiss dependency).
2. **Cadence:** both — a nightly schedule *and* an on-demand "Sync now" button.
3. **iMIS API status:** assume REST access will be available (**TBA** — to be
   confirmed with Causeis/ASI); go-live is gated on it, with the manual CSV path as
   the fallback until then.
4. **Geocoding at scale:** use the **planned Google Maps key** for bulk geocoding,
   with Nominatim as fallback.
5. **Remaining RiSE pages:** no list exists — **Phase 3 starts with a discovery
   pass**.

---

## 8. Conventions (match the house standard)

WordPress Coding Standards (phpcs, warnings fail), GPL-3.0-or-later © Vernon Spain,
AU/UK spelling, security order (capability → nonce → sanitise; escape output,
`textContent` not `innerHTML`), Privacy API, CI as wormiss/lad already run it,
bulk work batched/throttled/confirmed/kill-switched. Branch
`vdiddy/upbeat-einstein-longgo`, one concern per PR, bump versions + changelog.

---

## Appendix — the current hand-run SCCA doctor IQA (reference)

The live, manually-run query as of the multi-location work (sanitise view/column
names if they drift; this is the feed to automate in Phase 1):

```
SELECT TOP 10000
  c.Company, c.Country, c.County, c.Email, c.Website,
  c.[ID] AS [iMISID], c.FirstName, c.LastName, c.[Work Phone], c.[Zip] AS [postcode],
  sm.[ID] AS [Smart_Maps Id], sm.[OptOut] AS [Smart_Maps OptOut], sm.AddressNumber, sm.UpdatedOn,
  init.AddressNumber, init.FullAddress, init.FullName, init.ID, init.Workplace,
  multi.GeocodeAddress, multi.OptOut, multi.[Postcode] AS [pc2], multi.State, multi.Suburb, multi.Location
FROM vBoCauseisContact c
  JOIN vBoSmart_Maps sm ON sm.ID = c.ID
  JOIN vBoSCCA_Smart_Maps_Intial_Data_View init ON init.ID = c.ID   -- one row per workplace
  JOIN vBoSmart_Maps_Multi multi ON multi.ID = c.ID                 -- one row per location
WHERE ([vBoSmart_Maps].[OptOut] <> 1)
```

**Warning:** joining both `init` (per-workplace) and `multi` (per-location) at once
produces a cartesian product. The sync should read **one** per-location source and
key on iMIS ID + `Location`. Because the query filters `OptOut <> 1`, withdrawn
doctors are *absent* from the feed rather than flagged — so they must be caught by
reconciliation (§5), not by reading an `OptOut` value per row.
