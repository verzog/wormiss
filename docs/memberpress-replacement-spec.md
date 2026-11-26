# wormiss — MemberPress replacement (content gating)

**Status:** scoping · **Scope:** replace MemberPress's *content-gating* role — **payments stay in iMIS**

**Key fact (confirmed with the owner):** MemberPress was **installed but never
configured** — no Rules, no Memberships, no gated content. So there is **nothing
to migrate** and **no cutover risk**: removing MemberPress un-gates nothing,
because it was gating nothing. This document is therefore not a migration plan;
it is (a) a note that MemberPress can simply be removed, and (b) a record of what
wormiss already provides and the one optional feature it does not.

Everything MemberPress did for **money** (checkout, pricing pages, coupons,
subscriptions, invoices, renewals, dunning, gateways) was always out of scope —
payments and the membership lifecycle live in iMIS, and wormiss already syncs
each member's type, status, join and expiry dates onto their WordPress account.

---

## What to do now

1. **Deactivate and delete MemberPress.** Nothing depends on it. There are no
   MemberPress rules, pages, shortcodes or membership records to preserve
   (owner confirmed it was never configured; a quick search of content and the
   active theme for `[mepr-` / `MeprXxx` before deletion is a cheap safety check).
2. **Use wormiss for members-only content going forward** — it already covers
   the common cases (see below). No new code is required to replace MemberPress.

That's the whole "replacement." The rest of this doc is reference.

---

## What wormiss already provides for members-only content

| Need | wormiss today |
|---|---|
| Mark a page/post (any editor post type) as members-only | `Wormiss_Access` + `Wormiss_Members_Only`: per-post meta, enforced on `the_content` |
| Restrict by membership tier | Per-post allowed iMIS **types** and **statuses**, matched against the member's synced iMIS record (fails closed) |
| "This is for members" message + teaser | `Wormiss_Access::notice()` + excerpt/first-55-words teaser with a sign-in link; excerpt- and page-cache-safe |
| Protect only part of a page | The **"Members only" block** (gates its inner blocks) |
| Editor control | Gutenberg **"Members only" panel**: switch + type/status lists, per post |
| Who is a current member | iMIS membership synced per account; sign-in blocked when not current |
| Member-facing account info | "My membership" block + read-only profile section |
| Audit | `Wormiss_Activity` logs reads of members-only content |

The one thing wormiss does **not** have is **rule-based (bulk) gating** — "make
this whole category / section members-only in one place" rather than flagging
each item. MemberPress had this; wormiss files restrictions per post.

---

## Optional future enhancement — rule-based gating

**Not needed to remove MemberPress**, and not urgent (nothing used it). Worth
building only if, in real use, flagging items one by one becomes a chore — e.g.
"every post in the *Members* category should be members-only automatically."

If/when wanted, it is a small layer on top of the existing `Wormiss_Access`:

- **A rule =** a **match** (a category and optionally its children, a tag, a
  whole post type, or a page + its descendants) + the **same access terms
  wormiss already uses** (allowed iMIS types / statuses, or "any current member").
- **Resolution:** `Wormiss_Access::post_rules()` returns the post's own setting
  **or** the strongest matching site rule. The existing enforcement, teaser,
  block, activity log and media gate then all honour it unchanged, because they
  already route through `Wormiss_Access`.
- **Precedence (proposed):** explicit per-post setting wins; among site rules,
  most specific wins (page tree > category/tag > post type); a post can be marked
  explicitly public to override a broad rule.
- **Admin UI:** a small "Members-only rules" table under the wormiss settings.
- **Fail closed and cached** — same posture as `Wormiss_Access` today.

Shippable as two PRs when the need is real: **rules engine + tests**, then
**rules admin screen**. No migration PR and no parity/dry-run report are needed,
since there is no MemberPress configuration to reconcile against.

---

## Out of scope (explicit)

Payments and the membership lifecycle (checkout, pricing/registration pages,
coupons, subscriptions, renewals, invoices, dunning, gateways) — all iMIS.
Drip and content expiration — not used. wormiss gates content; it does not sell
or renew membership.
