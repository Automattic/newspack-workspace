# Open Questions

Running log of pending decisions that block or shape implementation. Each entry has a status, current default position if any, and what's needed to resolve.

Conventions for this doc:

- **Status** is one of: `OPEN` (no working default; blocked), `WORKING DEFAULT` (we have a position; can proceed; revisit before lock), `DEFERRED` (pushed to v1.1 or v2 by scope decision)
- Each entry links to its Linear issue when one exists
- Date stamps note when the question was raised, not when it was last edited

---

## 1. BigQuery wrapper: thin REST vs full SDK

**Status:** WORKING DEFAULT (thin REST). Pending engineering input via P2.
**Linear:** NPPD-1599
**Raised:** May 2026

**Context.** Newspack Insights needs to authenticate with a service account and run a handful of parameterized read queries against publishers' GA4 BigQuery export. Two viable implementations:

- **A. Thin REST wrapper, ~400 LOC.** Uses the existing `google/auth` ^1.15 dep (already in newspack-plugin's composer.json for the GA4 Data API integration at `includes/oauth/class-google-oauth-ga4-client.php`) plus WordPress's `wp_remote_request`. Zero new composer deps.
- **B. Full `google/cloud-bigquery` SDK.** ~25MB in `vendor/` with transitive deps, ~150 autoloaded classes.

**Working default: A.** Reasoning:

- We only need 4 BQ operations (`jobs.query`, `jobs.get`, `jobs.getQueryResults`, dry-run)
- Stays light — ~25MB vendor weight for 4 ops is poor cost/benefit
- We already have `google/auth` for the hard part (JWT signing, token caching)
- Precedent in newspack-plugin (`class-google-oauth-ga4-client.php` uses the same pattern)
- Marshaling risk mitigated because metric queries return only scalar columns (no structs, no repeated types)
- Reversibility: adding the SDK later is straightforward; removing it once it's a dep is harder

**Needed to resolve.** Engineering review weighs in. Default to A unless someone surfaces a concrete reason to lift the SDK.

---

## 2. GCP project architecture: per-publisher vs Newspack-owned vs hybrid

**Status:** OPEN — biggest unresolved architectural question.
**Linear:** NPPD-1600
**Raised:** June 2026

**Context.** Two physically different architectures for where publishers' GA4 BigQuery export data lives:

- **Per-publisher GCP projects (decentralized).** Each publisher's GA4 property exports to a BQ dataset in their own GCP project. Publishers grant a centralized Newspack service account `BigQuery Data Viewer` + `BigQuery Job User` IAM roles on their export dataset. Newspack's SA reaches across project boundaries via cross-project IAM.
- **Newspack-owned GCP project (centralized).** Newspack owns the GCP project. Each publisher's GA4 export targets a publisher-specific dataset within Newspack's project. Newspack manages the SA, datasets, and IAM internally.

Each has materially different operational and cost implications:

- Per-publisher: matches how GA4 → BQ export tooling encourages publishers to configure their setup; publishers retain raw data ownership; every new publisher requires manual IAM grant on their side.
- Newspack-owned: zero per-publisher onboarding ops; Newspack pays BQ storage and query costs at scale; publishers don't have raw data ownership.

**Mixed reality.** Some Newspack publishers may already have their own GCP setup (especially larger publishers); smaller publishers may want Newspack to host. The wrapper architecture likely needs to support both via wp-config constants — a project ID + dataset ID pair per publisher, with the wrapper agnostic about who owns the underlying project.

GAM data (Tab 8) is NOT affected by this question — it's read via the GAM API over the existing OAuth connection, not BigQuery. See question #8 (resolved).

**Needed to resolve.** Engineering conversation with whoever owns Newspack ops/infrastructure. Probably @stevedeckert or equivalent. Decision needs to land before NPPD-1600 (IAM provisioning) can be executed.

---

## 3. Naming: Insights vs Analytics

**Status:** WORKING DEFAULT (Insights). Pending decision.
**Linear:** NPPD-1603
**Raised:** May 2026

**Context.** Top-level submenu label under the Newspack admin menu. Both candidates fit.

**Working default: Insights.** Single word, signals "actionable observations" rather than "raw config," fits the existing wizard naming pattern (Dashboard / Setup / Settings / Audience / Newsletters / Advertising).

**Alternative: Analytics.** More literal, slightly more enterprise-flavor. Also fine.

**Rejected:**

- "Audience Analytics" — collides with the existing Audience wizard's submenu hierarchy
- "Publisher Dashboard" — Dashboard already exists as a top-level Newspack wizard
- "Data" — too cold for a user-facing label
- "Reports" — sounds static, doesn't communicate the dashboard nature
- "Stats" — sounds shallow

**Needed to resolve.** Pick one. Either works. Worth a quick informal poll with @kinseywilson / @adamcassis since they think about positioning.

---

## 4. Paywall completion match window

**Status:** WORKING DEFAULT (30 minutes). Pending validation against real production data.
**Linear:** N/A (this is a tuning question, not a structural decision)
**Raised:** May 2026

**Context.** With `form_submission_success` deprecated, paid checkout completion is tracked by matching `gate_post_id` across:

- `np_modal_checkout_interaction` (intent — `action='form_submission'`) →
- WooCommerce order completion in the local DB (+ optionally `np_reader_registered` for registration-required products)

The match window is the question: how long after the gate-modal interaction do we still attribute a completed order back to that gate?

**Working default: 30 minutes** (roughly equivalent to a single GA4 session).

**Open considerations:**

- Production data likely shows a non-trivial tail of checkouts that complete in a second visit (Stripe authentication redirect, 3DS step, mobile → desktop handoff, "let me check with my partner first"). Some of these legitimately came from the gate but won't be captured by a 30-minute window
- May need a primary attribution window (30 min, conservative) plus an extended attribution window (24h or 7d, with a "weak attribution" flag in the metric output)
- Could be a per-publisher configurable knob rather than a hard-coded default

**Needed to resolve.** Engineering review against real production gate-to-completed-order timing once IAM grant is in place and we can query a real publisher's data. Until then, 30 minutes is the working default for formula drafts.

---

## 5. Donation product classification: Ambassador-style misclassification

**Status:** WORKING DEFAULT (document the limitation). Strategy decision pending.
**Linear:** NPPD-1619
**Raised:** June 2026

**Context.** Newspack publishers commonly create custom Woo subscription products that they consider donations — donation tiers, sponsorship levels, member-donor hybrids. The canonical `\Newspack\Donations::is_donation_product()` logic does NOT classify these as donations. Insights will route them to Tab 6 (Subscribers) instead of Tab 7 (Donors), creating a publisher-facing data discrepancy.

Verified at Block Club Chicago (June 2026): "Ambassador" and "Block Club Captain" are `variable-subscription` products presented as donation tiers; they fail all three detection paths in `is_donation_product()` and are classified as subscriptions by default.

**Three options under consideration:**

- **A. Document the limitation, nudge publishers to use the v6.41.0 `_newspack_is_donation` flag.** Lowest engineering cost; relies on publisher action. UI banner on Tab 7: "Have donation products outside the standard family? Flag them in Donations settings to include them here."
- **B. Build per-product classification UI inside Insights settings.** New settings page lets publisher mark each product as Donation or Subscription. Internally writes `_newspack_is_donation` flag (benefits other Newspack systems too). More discoverable than (A).
- **C. Heuristic detection.** Pattern-match product names containing "donate," "support," "member," "ambassador," etc. Fragile, false-positive risk.

**Working default: A, with (B) as v1.1 follow-up.** Ship v1 with the publisher-facing nudge UI; build the classification settings page once we see publisher demand.

**Needed to resolve.** Decision on whether (B) belongs in v1 or v1.1. Affects Tab 7 launch scope.

---

## 6. Coverage area setting: auto-detection vs publisher-configured

**Status:** WORKING DEFAULT (publisher-configured, with auto-suggest help). Implementation pending.
**Linear:** NPPD-1620
**Raised:** June 2026

**Context.** Tab 1's Local Reader Rate metric requires per-publisher geo configuration. The setting itself is straightforward (an array of `{country, region, city, metro}` tuples); the question is how to populate it.

**Three options:**

- **A. Empty default, manual configuration.** Publisher must explicitly configure coverage area. Local Reader Rate metric hides until configured. Pros: explicit, no false defaults. Cons: friction; many publishers may never configure it and lose the metric.
- **B. Auto-detect from GA4 data.** Boot-time query for top 10 cities, top 5 regions, top 3 DMAs from last 90 days; auto-populate with those, let publisher confirm/edit. Pros: zero friction; reasonable default; surfaces interesting data. Cons: assumes audience location = coverage area (true for hyperlocal publishers but not, e.g., for Texas Tribune whose statewide coverage includes outliers).
- **C. Auto-suggest with manual confirmation.** Boot-time auto-detect produces suggestions; settings UI shows them with checkboxes; publisher confirms which apply. Compromise between A and B.

**Working default: C.** Auto-suggest from GA4 audience data, publisher confirms via settings UI. Best balance of friction reduction and explicit publisher intent.

**Needed to resolve.** Confirm with engineering that the auto-suggest query is cheap enough to run at settings-page-load (it's a one-shot BQ query against the last 90 days — should be sub-second after first cache hit).

---

## 7. Cohort retention refresh cadence

**Status:** WORKING DEFAULT (weekly). Pending NPPD-1606 implementation.
**Linear:** NPPD-1606
**Raised:** June 2026

**Context.** Cohort retention queries (Tab 3 registrations → conversion, Tab 7 subscriber/donor retention curves) are the most expensive queries in Insights. Window-function aggregations across months of event data and Woo orders.

**Working default: weekly refresh.** Action Scheduler pre-warm refreshes most metrics hourly, but cohort retention runs once per week (Monday early morning) and cache lasts the week. Acceptable freshness for cohort analysis since cohorts are stable; cohort movement over a week is meaningful.

**Open considerations:**

- Pre-warm infrastructure needs to support per-metric refresh intervals (`refresh_interval` annotation on each metric class)
- Worth setting up a probe metric ("most expensive query bytes scanned") in the audit log so we can observe actual costs once running
- Some publishers may want daily refresh; configurable per-publisher in v1.1?

**Needed to resolve.** Per-metric refresh interval pattern needs to be designed as part of NPPD-1606. Validate cost assumptions against real publisher data once running.

---

## 8. GAM data source: BigQuery vs GAM API

**Status:** RESOLVED (June 2026) — GAM API over the existing OAuth connection. No BigQuery.
**Linear:** NPPD-1618 (Tab 8 UI), NPPD-1614 (Tab 8 formulas)
**Raised:** June 2026

**Original context.** Tab 8 was scoped against Google Ad Manager (GAM) Data Transfer Reports — a separate BQ dataset from GA4 — which raised a binding question paralleling #2: where the GAM dataset lives, whether auth matches GA4's, how publishers configure it, and whether the isolation validator extends to it.

**Resolution.** Abandon the BigQuery path entirely. We discovered Newspack already has a working GAM connection in `newspack-ads`, and — critically — Newspack's existing Google OAuth connection (`Newspack\Google_OAuth`) **already requests the Ad Manager scope** (`https://www.googleapis.com/auth/admanager`) in its `REQUIRED_SCOPES`, right alongside the analytics scopes. So:

- **One OAuth connection covers both GA4 and GAM.** Any publisher connected for GA4 has already granted GAM access. Nothing extra to connect, no opt-in BQ export to provision (which most publishers don't have anyway).
- **GAM data is read live via the GAM API (`ReportService`)**, not BigQuery. There is no GAM dataset, no GAM GCP project, no GAM IAM, no `NEWSPACK_INSIGHTS_GAM_*` constants.
- **OAuth only — never service account.** `newspack-ads` supports a service-account JSON mode, but that's for open-source / self-hosted users; it does not apply to managed Newspack customers. Insights must authenticate GAM via OAuth exclusively (`Google_Services_Connection::get_oauth2_credentials()`), gated on `Google_OAuth::token_has_scope('.../admanager')`. Do not use `GAM_Model::get_api()` unconditionally — it prefers a service account when one is present.
- **Network code** comes from `newspack-ads` (`GAM_Model::get_active_network_code()`); no separate binding.
- **Isolation:** the GAM session is bound to the publisher's own OAuth credentials + their single network code, so there's no shared-dataset cross-publisher risk and the BQ isolation validator (#2 / NPPD-1601) does not apply to GAM.

This also decouples Tab 8 from question #2 — GAM no longer depends on the GA4 GCP-project decision at all.

**Remaining work (not a blocker, just implementation).** No GAM reporting code exists yet. A reporting client (run report → poll → download → parse) must be written in the **Insights module** (`newspack-plugin/includes/wizards/insights/gam/`), grouped with the other Insights code — NOT in `newspack-ads`. Insights gets OAuth creds from newspack-plugin's own `Google_OAuth` and reads only the network code from `newspack-ads`. **API-surface sub-decision RESOLVED (NPPD-1662 pre-flight): SOAP via `newspack-ads`' vendored `googleads/googleads-php-lib` (bumped to `^72.0` for API `v202602`)** — not the earlier "thin REST" idea, because REST reporting exists only in the Beta Ad Manager API with a different model that would force rewriting every metric definition. REST migration deferred to NPPD-1664. Tracked/implemented in NPPD-1662. See `formulas/tab-8-advertising.md`.

---

## 9. Settings page location and shape

**Status:** OPEN. Multiple settings concepts exist but no UI yet.
**Linear:** None yet
**Raised:** June 2026

**Context.** Several Insights features require publisher settings:

- Coverage area config (NPPD-1620) — for Local Reader Rate
- Donation product classification (NPPD-1619) — for Ambassador-style products
- GAM timezone alignment (Tab 8) — optional; align report windows with the GAM network timezone (see question #8)
- Engagement composite score weighting (v1.1)
- Trailing window for "active donor" (v1.1)
- Timezone (defaults to WP site timezone, override available)
- Tab visibility overrides (e.g., publisher wants to force-hide Tab 7 even though donation activity exists)

**Three possible shapes:**

- **A. Separate wizard.** New `Insights_Settings_Wizard` registered under Newspack admin menu. Pros: matches existing Newspack pattern. Cons: another menu item.
- **B. Gear icon in Insights wizard header.** Settings dialog/modal accessible from within the Insights page itself. Pros: contextual, doesn't add menu items. Cons: less discoverable from a "set up everything before using" workflow.
- **C. Section in Newspack Settings.** Insights settings live under the existing Newspack Settings wizard. Pros: centralizes settings. Cons: pollutes Newspack Settings; future Insights features would have to coordinate with the Settings wizard team.

**Working default position:** B for v1 (just the settings the v1 launch needs), revisit for v1.1.

**Needed to resolve.** Decision on shape before any settings UI work begins. Probably bundle with NPPD-1620 since that's the first settings UI to land.

---

## 10. Default trailing window for "active donor"

**Status:** WORKING DEFAULT (365 days). Configurability deferred.
**Linear:** N/A (tuning question)
**Raised:** June 2026

**Context.** Tab 7's "Active Donors (any)" metric defines "active" as "made a donation in the trailing N days." Default N = 365.

**Open considerations:**

- 365 days matches conventional fundraising cycles (annual giving)
- Some publishers think of donors as active forever once they've given; 730d or "ever" might be their preference
- Some publishers do "monthly active donor" cohort tracking; 30d would matter
- Configurability ranges this from one setting to a multi-window comparison view

**Working default: 365 days, not configurable in v1.** Document trailing window prominently in UI ("Active in last 12 months"). Revisit configurability in v1.1.

**Needed to resolve.** Publisher feedback after v1 launch. If multiple publishers request different windows, configurability becomes a higher priority for v1.1.

---

## 11. Storage backend detection caching

**Status:** WORKING DEFAULT (daily re-check). Implementation pending.
**Linear:** Implicit in NPPD-1605 (cache infrastructure)
**Raised:** June 2026

**Context.** Insights detects whether each publisher is on HPOS or legacy CPT via the `woocommerce_custom_orders_table_enabled` option. Detection runs at boot to dispatch queries to the right backend. Publishers can migrate between backends, which would invalidate the cached detection.

**Working default: daily re-check.** Detection result cached in `wp_options` (`newspack_insights_storage_backend` + timestamp), re-checked once per day on first wizard load.

**Needed to resolve.** Confirm that publisher HPOS migrations are rare enough that daily is fine. If publishers migrate frequently (e.g., during the rollout phase), more frequent detection or detection-on-every-query may be needed. Implementation question for NPPD-1605/1606.

---

## 12. Cache invalidation triggers

**Status:** WORKING DEFAULT (time-based TTL + manual flush). Per-metric annotations deferred.
**Linear:** NPPD-1605
**Raised:** June 2026

**Context.** The cache table (NPPD-1605) uses time-based TTL (30 min default per metric). But certain events should invalidate cache proactively:

- Publisher changes a setting (coverage area, donation classification) — invalidate dependent metrics
- New donation product created / existing one flagged — invalidate donation product ID cache
- Newspack version upgrade — invalidate everything (assume formula or query shape may have changed)
- Storage backend migration (HPOS rollout) — invalidate everything
- Manual flush button in wp-admin (operator escape hatch)

**Working default: time-based TTL + manual flush button.** v1 ships with the two simplest mechanisms. Per-metric invalidation annotations and event-driven invalidation are v1.1.

**Needed to resolve.** Whether v1 needs more sophisticated invalidation before launch. If publishers hit "stale data after settings change" frequently, invalidation triggers become higher priority for v1.1.

---

## 13. The `_newspack_is_donation` flag adoption strategy

**Status:** OPEN. Connected to question #5 (Ambassador classification).
**Linear:** NPPD-1619
**Raised:** June 2026

**Context.** v6.41.0 (May 2026) added the `_newspack_is_donation` postmeta flag, giving publishers a way to mark any Woo product as a donation. Zero adoption observed at production publishers as of June 2026 (verified on Block Club Chicago and Richland Source — neither has any products with the flag set).

The flag is the canonical solution to the Ambassador classification problem, but it requires publishers to take action. Insights can either:

- **A. Educate passively.** Document the flag in help docs, mention it in Insights UI when the Donors tab is light, hope adoption grows organically.
- **B. Surface explicitly in Insights.** Banner on Tab 7: "Have donation products outside the standard family? Click here to flag them." Click leads to Donations settings.
- **C. Build the classification UI inside Insights itself** (the option B from question #5). Solves the discoverability problem; writes the flag on behalf of the publisher.

**Working default: B for v1, C for v1.1.** Surface the nudge in v1; build the classification UI in v1.1 if publishers ask.

**Needed to resolve.** Tied to question #5. Same decision point.

---

## 14. Insights cache table location

**Status:** WORKING DEFAULT (`{prefix}newspack_insights_cache`). Pending engineering review.
**Linear:** NPPD-1605
**Raised:** June 2026

**Context.** Custom MySQL table for the SWR cache. Two questions:

- Table name and prefixing convention. Default: `{prefix}newspack_insights_cache` (e.g., `wp_newspack_insights_cache` or `wp_5_newspack_insights_cache` for multisite). Standard Newspack convention.
- Table lifecycle. Created on plugin activation (`dbDelta`)? Created lazily on first Insights wizard load? What happens on plugin uninstall — drop or preserve?

**Working default:** dbDelta on plugin activation; preserve on deactivation (cache is regenerable but recomputing is expensive); offer "Drop Insights cache" option on uninstall.

**Needed to resolve.** Engineering review of NPPD-1605 implementation. Plugin lifecycle hooks need to be wired correctly.

---

## 15. Action Scheduler queue size at scale

**Status:** OPEN. Risk that hasn't been quantified.
**Linear:** NPPD-1606
**Raised:** June 2026

**Context.** Pre-warm strategy uses Action Scheduler to refresh caches hourly. If a publisher has many metrics (~50 across all tabs after v1 launch + v1.1) and the schedule is hourly, that's ~1200 jobs/day per publisher. Across all Newspack publishers, that's potentially hundreds of thousands of jobs.

Action Scheduler is robust but not unbounded. Open questions:

- Does the queue grow unbounded if pre-warm jobs run slower than they're scheduled?
- What's the per-publisher throughput limit before Action Scheduler degrades?
- Is there a network-level queue ops dashboard?

**Working default: hourly cadence + per-publisher independence + observability via audit log.** v1 ships with this assumption; monitor at first multi-publisher rollout.

**Needed to resolve.** Engineering capacity-planning question. May need a probe deployment at one or two publishers to observe Action Scheduler behavior before broad rollout.

---

## Resolved questions (archive)

Questions that have been answered. Moved here so they're searchable without cluttering active questions.

### `n build` script filters by directory name instead of package name

**Status:** RESOLVED (workaround documented in monorepo dev notes; upstream fix pending).
**Raised:** May 2026
**Resolved:** June 2026 (moved out of architectural open questions; tracked as a build-tooling bug in monorepo dev notes)

The `n build <plugin-dir>` script invokes pnpm with `--filter "$pkg"` where `$pkg` is the basename of the project directory. For newspack-plugin, directory name (`newspack-plugin`) differs from package.json name (`newspack`), so `n build newspack-plugin` silently does nothing.

Workaround:

```bash
docker exec newspack_dev sh -c "cd /newspack-monorepo && pnpm --filter newspack run build"
```

This is a build-tooling bug, not an Insights architectural decision. Tracked in the monorepo's dev notes / standalone monorepo issue.

---

## Cross-references

- Architecture: [architecture.md](architecture.md)
- Information architecture: [information-architecture.md](information-architecture.md)
- Schema reference: [formulas/subscription-donation-schema.md](formulas/subscription-donation-schema.md)
- Event reference: [event-reference.md](event-reference.md)
- Component design spec: [component-design-spec.md](component-design-spec.md)