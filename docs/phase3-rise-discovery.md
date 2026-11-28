# Phase 3 — iMIS/RiSE retirement: discovery (first pass)

**Status:** discovery, first pass (home-page derived) · **Scope:** catalogue every
public surface still served by iMIS, map each to its WordPress target, so the public
site can be cut over from the Causeis **Launchpad** theme to the sccadian + wormiss
WordPress build.

---

## Headline finding (reframes Phase 3)

The live public site at **`www.skincancercollege.org` is still served entirely by
iMIS RiSE through the Causeis "Launchpad" theme** — ASP.NET WebForms, `/web/Shared_Content/…`
paths, `__doPostBack`, `ci*` web-part controls (`ciHomepageCarousel`, `ciHomepageEvents`),
and a "Powered by Launchpad" footer linking to causeis.com. It is **not** WordPress:
`sitemap.xml`, `sitemap_index.xml` and `wp-sitemap.xml` all 404, and nothing on the
home page links to a WordPress site.

So Phase 3 is **not** "retire a few leftover RiSE pages left behind by a migration."
The whole public head is still iMIS/Launchpad, and the sccadian/wormiss WordPress build
(the thing wormiss's page/news/event importers feed) must live on a **separate host or
staging URL not linked from the live site**. Phase 3 is therefore a **cutover**: stand
the WordPress site up with the content below, then switch `www` from Launchpad to it,
leaving only the transactional flows pointing back into iMIS.

## Method & limits

Read: the home page (HTTP 200) and the three usual WordPress sitemap URLs (all 404,
as expected for a non-WordPress site). This catalogue is therefore the **home-page
information architecture** — header/utility nav, footer "Quick Links", and hero/card
links. It is **not exhaustive**: deep sections, second-level pages and anything not
linked from the home page are not yet captured. A complete list needs the iMIS
RiSE/Launchpad **content tree** (admin export) or a crawl of the Launchpad site's own
navigation (§E).

---

## A. Public content surfaces → migrate to WordPress

| Surface | Current iMIS/Launchpad URL | Headless target (wormiss capability) |
|---|---|---|
| Home | `/` | sccadian theme home page |
| About Us | `/web/about-us/about-us.aspx` | WP page (wormiss **page-import**) |
| Courses / Education | `/web/education/education.aspx` | WP page + wormiss **events** |
| Events (list) | `/events` | wormiss **events** (sync) |
| Event detail / register | `/EventDetail?EventKey=…` | wormiss **events + registration**; payment stays iMIS (§C) |
| SkinPro CPD | `/web/skinpro-cpd/skinpro-cpd-join.aspx` | wormiss **event-cpd** + members-only gating; join/pay stays iMIS |
| Membership | `/web/membership/join-now.aspx` | WP content page; **join/renew/pay stays iMIS** (§C) |
| Latest News | `/web/Latest-News/Latest-News.aspx` | wormiss **news-import** → WP posts |
| Accreditation | `/web/accreditation/accredited-skin-cancer-doctors.aspx` | WP page |
| **Locate a Doctor** | `/maps`, `/web/Shared_Content/Smart-Suite/Smart-Maps/Public/locate-a-doctor-au.aspx` | **lad plugin + wormiss doctor-sync — already built (Phases 1 & 2)** |
| SCCA New Zealand | `/web/SCCA-New-Zealand` | WP page(s) + lad `region="NZ"` |
| Conference / Congress 2027 | `/conference` | WP page / microsite |
| Positions Vacant | `/jobs` | WP (page or simple CPT) |
| Policies / Resources | `/ResourcesSearch?…`, `/Resource?resource=NN` | WP media/documents library |
| Contact / Feedback | `/contactus`, `/feedback` | WP pages + a form plugin |
| Scan Your Skin | `/ScanYourSkin` (and the `scanyourskin.org` microsite) | WP page / separate microsite — confirm scope (§D) |

Locate a Doctor is the **pilot already delivered**: the live `/maps` Smart-Maps page is
exactly what `lad` + the wormiss doctor-sync replace.

## B. Authenticated / self-service → migrate via wormiss SSO

| Surface | Current URL | Headless target |
|---|---|---|
| Login / portal | `/web/Shared_Content/ContactManagement/IndividualAccountPage.aspx`, `/Shared_Content/ContactManagement/IndividualAccountPage.aspx`, `/MyAccount` | wormiss **sign-in / SSO** |
| My Profile / My membership | `/MyAccount` | wormiss **member-display** + "My membership" block (read-only profile) |

## C. Transactional → stays in iMIS by design (deep-link, do not rebuild)

Per the headless plan (§0/§4), payments and the membership lifecycle stay in iMIS;
WordPress deep-links out cleanly.

- Shop / Cart — `/web/Shared_Content/Shop/Store-Layouts/Cart_Home.aspx`
- Membership **join / renew** — `/web/membership/join-now.aspx`
- SkinPro CPD **join** — `/web/skinpro-cpd/skinpro-cpd-join.aspx`
- Event **registration & payment** — from `/EventDetail?EventKey=…`

## D. Separate surfaces → decide (likely out of scope)

- **Higher Logic community** — `scca.connectedcommunity.org`. A Higher Logic product,
  not RiSE; keep as-is or migrate is a separate decision (likely keep).
- **`scanyourskin.org`** — public-awareness microsite; confirm whether it is in scope
  for this project at all.

---

## E. Gaps — needed for a complete, page-by-page catalogue

1. The iMIS RiSE/**Launchpad content tree** — an admin can export or screenshot the page
   list and navigation. (Public pages are not in a crawlable WordPress sitemap, so this
   is the authoritative source.)
2. The **WordPress site host** (staging URL?) and how much content wormiss has already
   imported into it (pages / news / events).
3. A confirmed **cutover plan** — how `www` is switched from Launchpad to WordPress
   (DNS / reverse-proxy / host), and exactly which paths must deep-link back to iMIS
   afterwards.

## F. Open questions for the owner

1. **Where is the WordPress site today** (host / URL), and how much has wormiss already
   imported into it?
2. Can you **export the iMIS/Launchpad page tree** (RiSE content pages + navigation) so
   this first pass can be completed?
3. **Higher Logic** (`scca.connectedcommunity.org`) and **`scanyourskin.org`** — in or
   out of scope for retirement?
4. Is the plan a **full cutover** (replace Launchpad with the WordPress site), or run
   WordPress alongside Launchpad for specific sections first?
5. Confirm which flows **stay iMIS-served** after cutover (join/renew, shop/cart, event
   payment — §C), so the WordPress pages deep-link rather than reimplement them.

## Next step

With the RiSE page-tree export (§E.1) this first pass becomes a complete, page-by-page
**cutover checklist** — migrate / deep-link / retire, each mapped to the wormiss importer
or sync that carries it. A deeper crawl of the Launchpad site (following `/web/…` links)
can expand the inventory meanwhile, but the admin export is the authoritative list.
