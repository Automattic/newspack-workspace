# Architecture

Synthesized from the migration plan and architectural decisions made during the design phase. The public P2 announcement at <https://newspackp2.wordpress.com/2026/05/27/newspack-insights-a-native-data-hub-to-replace-looker-studio/> is the public version of this content; this doc may diverge as engineering input arrives. Reconcile against the P2 narrative before circulating externally.

## Background

Newspack Insights is a native feature of newspack-plugin (not a standalone plugin) that replaces existing Looker Studio dashboards and adds new metrics impossible to express in Looker. Lives under the Newspack admin menu as a top-level submenu (naming pending — see [open-questions.md](open-questions.md)). Replaces publisher reliance on an external dashboard, which means we no longer pay for cross-site benchmarking (out of scope for v1) but we gain control over the metric definitions and the surface they're shown on.

## Data sources

Three data planes, split by the natural shape of the data:

- **BigQuery, GA4 export** for event-level audience and behavioral analytics. Per-publisher GA4 export. See "BigQuery access model" below for the IAM and dataset architecture.
- **Google Ad Manager (GAM) API** for ad-stack performance data on Tab 8 (Advertising). Read live via GAM's `ReportService`, NOT via BigQuery. Authenticated through Newspack's existing Google OAuth connection — the *same* connection that powers GA4 (its `REQUIRED_SCOPES` already include `admanager` alongside `analytics`). Reuses the GAM connection and network code that `newspack-ads` already maintains. No separate dataset, no IAM provisioning, no opt-in BQ export. OAuth only — never service-account credentials (those are open-source/self-hosted, not managed customers). Tab 8 hides when the OAuth token lacks the GAM scope or no GAM network code is configured. See `formulas/tab-8-advertising.md` for the connection model.
- **Local WordPress database** for Woo/ESP data (orders, subscriptions, newsletter signups, contact lists). This data already lives on the site; round-tripping it through BQ would be pointless duplication.

Some metrics need to join across the planes (e.g., gate-attributed paid conversions, which need a BQ event for the gate impression and a local Woo order for the completed purchase). The metric class is responsible for owning the join logic.

## BigQuery access model

[DECISION NEEDED — this section reflects two possible architectures. Engineering input required to confirm.]

The fundamental question: where does the publisher's GA4 event data live, and how does Newspack's BigQuery wrapper authenticate to read it?

**Option 1: Per-publisher GCP projects (decentralized)**

Each publisher's GA4 property exports to a BQ dataset in their own GCP project. Publishers grant a centralized Newspack service account `BigQuery Data Viewer` + `BigQuery Job User` IAM roles on their export dataset. Newspack's SA reaches across project boundaries via cross-project IAM.

Pros: matches how Google's own GA4 → BQ export tooling encourages publishers to configure their integration. Publishers retain ownership of their raw event data. Newspack's GCP project stays minimal.

Cons: every new publisher requires a manual IAM grant on their side. Onboarding ops overhead.

**Option 2: Newspack-owned GCP project (centralized)**

Newspack owns the GCP project. Each publisher's GA4 export targets a publisher-specific dataset within Newspack's project. Newspack manages the SA, datasets, and IAM internally.

Pros: zero per-publisher onboarding ops (Newspack provisions the dataset when a publisher signs up). Faster iteration on shared infrastructure.

Cons: Newspack pays the BQ storage and query costs at scale. Publishers don't have raw data ownership. Tighter coupling between Newspack and publisher analytics.

**Mixed reality:** some publishers may already have their own GCP setup; others may want Newspack to host. The wrapper architecture must support both. Likely implementation: a project ID + dataset ID pair per publisher, set in wp-config constants, with the wrapper agnostic about whether the project is publisher-owned or Newspack-owned.

GAM data does NOT participate in this question — it is not a BigQuery plane. Tab 8 reads GAM live via the GAM API over the existing Google OAuth connection (see the data-planes list above and `formulas/tab-8-advertising.md`). No GAM dataset, no GAM GCP project, no GAM IAM.

## Site isolation

Site isolation is a **P0 requirement**. Strategy is belt-and-suspenders across seven layers:

1. **Per-publisher dataset** — every publisher's data lives in a uniquely-named dataset, never shared across publishers
2. **wp-config constant binding** — dataset name lives in `NEWSPACK_INSIGHTS_BQ_DATASET` and parallel constants in the publisher's wp-config. Never a runtime setting, never an option, never editable from wp-admin
3. **Regex validator at every call site** — `^[a-zA-Z0-9_]+$` on dataset name before it enters any SQL. Reject anything else
4. **Constant-match check** — every query call confirms the dataset it's about to query matches the constant. Refuse to execute otherwise
5. **Named query parameters** — all variable interpolation goes through BQ's named parameters API, never string concatenation
6. **Boot-time sanity check** — when the wrapper initializes, query the dataset's INFORMATION_SCHEMA to confirm it exists and matches the configured property_id. Fail loud if mismatched
7. **Audit log** — every BQ job submitted logged to local DB: timestamp, dataset, query (or query hash), user_id of admin who triggered it, success/failure. Retain 90 days minimum

GAM data (Tab 8) is out of scope for this BQ isolation stack — it's not a BigQuery plane. GAM isolation is enforced differently: the GAM API session is bound to the publisher's own OAuth credentials and their single configured network code (`GAM_Model::get_active_network_code()`), so a site can only ever read its own GAM network. No cross-publisher dataset risk exists because there's no shared dataset. The audit log should still record GAM report jobs (timestamp, network code, report query/hash, admin user_id, success/failure).

See NPPD-1601 for the implementation issue (GA4 BQ isolation).

## Storage backend dispatch (HPOS vs legacy CPT)

Newspack publishers are split between two WooCommerce order storage backends. Some run HPOS (`wp_wc_orders` + `wp_wc_orders_meta`), some run legacy CPT (`wp_posts` + `wp_postmeta`). Insights detects which is active per publisher and dispatches queries accordingly.

**Detection:**

```sql
SELECT option_value FROM {prefix}options
WHERE option_name = 'woocommerce_custom_orders_table_enabled';
```

- `yes` → HPOS active. Orders + subscriptions in `{prefix}wc_orders` (type column distinguishes), meta in `{prefix}wc_orders_meta`.
- `no` (or missing) → legacy CPT active. Orders + subscriptions in `{prefix}posts`, meta in `{prefix}postmeta`.

Additionally check `woocommerce_custom_orders_table_data_sync_enabled`. If `yes`, both backends in sync (HPOS preferred for performance). If `no`, read ONLY from the active backend.

**Implementation pattern:** an `Insights\Storage` interface with two implementations (`HPOS_Storage`, `Legacy_Storage`), each exposing the same set of query methods. Metric classes call the interface; the backend is selected once per request based on the publisher's storage setting.

The `wc_order_product_lookup` table (populated by Woo Analytics, present on both backends) provides a stable join surface that works regardless of which backend is active. Where possible, queries use this table to avoid backend-specific joins.

See `formulas/subscription-donation-schema.md` for the canonical schema reference covering both backends and the per-publisher detection pattern.

## Multisite prefix handling

Newspack publishers are split between single-site setups (`wp_` prefix) and multisite blogs (`wp_{blog_id}_` prefix, e.g., `wp_5_` for Block Club Chicago). Insights reads `$wpdb->prefix` at runtime; never hardcoded.

Storage backend dispatch and multisite prefix handling are independent — a publisher can be on HPOS with a multisite prefix (Block Club Chicago) or on legacy CPT with a standard prefix (Richland Source). The wrapper handles all four combinations.

## Caching strategy

- **Custom table** `wp_newspack_insights_cache` with columns: `cache_key`, `query_hash`, `query_signature`, `tab`, `metric`, `payload JSON`, `computed_at`, `expires_at`, `last_accessed_at`. Rejected transients (wp_options bloat at scale, no observability).
- **Action Scheduler pre-warm** — hourly job iterates registered metrics and refreshes the cache. newspack-plugin already depends on `woocommerce/action-scheduler` ^3.9, so no new infrastructure.
- **Stale-while-revalidate** — admin UI reads only from cache. Served payload includes `computed_at` and a `stale` flag; if expired, an async refresh is fired and the stale payload is returned for the current request.
- **No synchronous BQ on page load.** Ever. This is structural: the REST endpoint reads from cache only, the cache is refreshed out-of-band.
- **Cost guardrail** — every query is dry-run first to estimate bytes; jobs over a configurable byte ceiling (default 10 GiB) are refused before execution. Audit log records `total_bytes_processed` for after-the-fact spend visibility.
- **Expensive query refresh cadence** — cohort retention (Tab 3, Tab 7) and reader-author affinity (Tab 2) refresh weekly rather than hourly. The pre-warm job inspects each metric's `refresh_interval` annotation.

See NPPD-1605 (cache table + read/write API + SWR logic) and NPPD-1606 (Action Scheduler pre-warm + cost guardrails) for implementation issues.

## Component approach

Data viz vocabulary lives in `packages/components/src/` alongside the existing component library:

- **Six metric components**: Scorecard, Table, Funnel, LineChart, BoxPlot, PieChart
- **Two foundational primitives** (extracted at the second usage rule): Skeleton, StalePill

Each component accepts the same edge-state vocabulary (`loading` / `error` / `stale` / empty data placeholder) and uses the same design tokens (`@wordpress/base-styles/colors` for neutrals, `packages/colors/colors.module.scss` for semantic / branded colors).

**Responsive strategy is split by component type:**

- **Reflow** at narrow widths: Funnel (legend-below mode triggered via ResizeObserver), Table (scrollable body)
- **Horizontal scroll** at narrow widths: LineChart, BoxPlot (charts can't reflow without losing meaning)
- **CSS auto-fill / flex-wrap**: Scorecard (grid), PieChart (donut + legend wrap)

This split is intentional design system shape — each component uses the cheapest mechanism that gracefully degrades for its layout.

Charts built on Recharts (`^3.8.1`). BoxPlot uses Recharts' `<Customized>` escape hatch since Recharts has no native BoxPlot; we get polished axes/grid for free and only own the shape rendering and a custom tooltip.

## Wizard chrome

Insights uses a single-wizard, client-side-routing pattern — a third convention in the wizards directory alongside Audience's sibling-wizards pattern and Settings' Wizard_Section subclasses pattern.

Reason: Insights UX requires shared persistent state across all 8 tabs (date range, comparison mode, "last updated" timestamp). The sibling-wizards pattern fights that because each tab is a page reload that loses state. The Settings section pattern doesn't address UI routing. Client-side routing with URL query persistence is the only path that gives the publisher experience we want.

Structural details:

- One `Insights_Wizard` class extending `Wizard`, slug `newspack-insights`, registered with `$parent_menu = 'newspack-dashboard'`
- Eight section classes in `includes/wizards/insights/` (`Insights_Section_Audience`, etc.) as plain classes with a static `init()` hook point for future REST registration and a `SECTION_NAME` constant. NOT extending `Wizard_Section`.
- React side handles tab routing with URL query persistence for active tab and date range

See NPPD-1602 for the implementation issue.

## BigQuery wrapper — pending decision

Two viable paths (see [open-questions.md](open-questions.md) for detail):

- **A. Thin REST wrapper (~400 LOC)** using existing `google/auth` ^1.15 dep + WordPress's `wp_remote_request`. Zero new composer deps.
- **B. Full `google/cloud-bigquery` SDK** (~25MB vendor footprint, ~150 autoloaded classes).

Migration plan recommends A. Pending engineering input via the P2 and NPPD-1599.

Wrapper covers the GA4 dataset only. GAM is not a BQ plane — it's read via the GAM API over the existing OAuth connection (see Tab 8), so it doesn't use this wrapper or the dataset-binding isolation stack.

## Custom dimensions and event params

Newspack provisions a standard set of GA4 custom dimensions on every publisher's GA4 property via `Newspack\GA4_Custom_Dimensions`. Current list includes 27 dimensions covering reader status, conversion surfaces, content metadata, and commerce attribution.

Notable gaps relevant to Insights:

- `post_type` — needed for Tab 2 (Engagement) article filtering. Tracked in NPPD-1621
- `post_published_date` — needed for Tab 2 article freshness metric. Tracked in NPPD-1621

Until NPPD-1621 ships, Tab 2 uses URL pattern matching to detect article pages and defers the freshness metric to v1.1.

See `event-reference.md` for the full event param documentation.

## Migration approach

Status as of June 2026:

**Completed:**

- Data viz component library (Scorecard, Table, Funnel, LineChart, BoxPlot, PieChart, Skeleton, StalePill) — built on `katie/data-viz-components` branch
- Demo gallery at `?page=newspack-data-viz-demo` for visual review
- Component design spec written (`component-design-spec.md`)
- Information architecture finalized (`information-architecture.md`)
- All formula docs written for the 8 tabs (`formulas/tab-*.md`) plus schema reference

**In progress:**

- Insights wizard chrome (NPPD-1602) — single wizard, 8-tab client-side routing, date picker, comparison toggle, stub tab content

**Planned, ordered by dependency:**

1. Tab 6 (Subscribers) and Tab 7 (Donors) — local Woo only, no BQ dependency. Ships first as immediate Looker replacement for subscription health and donor metrics. (NPPD-1616, NPPD-1617)
2. BigQuery wrapper (NPPD-1598, 1599) and IAM/credentials provisioning (NPPD-1600)
3. Site isolation validator stack (NPPD-1601)
4. Cache layer (NPPD-1605) and pre-warm (NPPD-1606)
5. Tabs 1, 3, 4, 5 — BQ-dependent v1 cut tabs (NPPD-1608, 1609, 1604, 1607)
6. v1.1: Tab 6 LTV by acquisition source, configurable settings, article freshness once NPPD-1621 lands
7. v2: Tab 2 (Engagement) full implementation, Tab 8 (Advertising) — pending GAM data and pilot publisher

**Parallel ops workstream** (not PRs, blocks production deploy of BQ-dependent tabs):

- Provision the BigQuery access model (per-publisher datasets, SA credentials, IAM grants) — see "BigQuery access model" decision
- Document the per-publisher onboarding pattern
- Decide and document the credentials-file delivery channel (likely Newspack Manager push)

See NPPD-1600 for the ops workstream issue.

## Components NOT in this architecture

Worth explicitly naming so engineering review knows what's out of scope:

- **Cross-site benchmarking** — intentionally out of scope for v1. This was Looker's distinguishing feature and our reason to leave it is precisely that we don't need it.
- **Real-time data** — admin UI reads from cache; data freshness is bounded by the pre-warm cadence (default hourly, weekly for expensive metrics).
- **Per-user dashboards** — Insights is a publisher-level surface, not a per-user analytics product.
- **Data export** — not in v1. Add later if publishers ask.
- **Header bidding (Prebid partner performance)** — deferred from Tab 8 v1 scope. Future v1.x if publishers ask.
- **Content category × ad revenue** — deferred from Tab 8 to v1.1. Requires joining GAM API report rows (by URL) against the GA4 BQ export (for category) — a cross-system join, not a cross-dataset one.
- **Reader-author affinity** — included in Tab 2 v2 scope but BoxPlot rendering is computationally heavy. May simplify if performance is poor at scale.
- **Article freshness vs engagement** — Tab 2 metric deferred to v1.1 pending NPPD-1621.
- **Tab 2 (Engagement) overall** — full tab deferred to v2 cut. Components and formulas are written; tab UI not built for v1 launch.
- **LTV by acquisition source** (Tab 6) — deferred to v1.1 pending BQ wrapper operational.
- **Configurable settings UI** — coverage area (NPPD-1620), donation product classification (NPPD-1619), GAM timezone alignment (Tab 8), engagement composite weighting, trailing window for "active donor" — all settings exist as concepts but no settings page in v1. Defaults applied throughout.

## Known data classification limitations

Documented for engineering and publisher-facing transparency:

- **Ambassador-style donation products** — publishers who use custom Woo subscription products as donation tiers (e.g., Block Club Chicago's "Ambassador") will see those products classified as Subscribers, not Donors, by default. Resolution via v6.41.0+ `_newspack_is_donation` flag (low adoption as of June 2026) or future Insights classification UI. See NPPD-1619.
- **Custom dimensions backfill** — adding `post_type` and `post_published_date` to GA4 custom dimensions doesn't backfill historical events. Metrics depending on them apply only to events fired after the dimensions are provisioned.
- **Storage backend sync** — when `woocommerce_custom_orders_table_data_sync_enabled` is `no` (the common case), the inactive backend is stale. Insights reads only the active backend.

## Cross-references

- Information architecture: [information-architecture.md](information-architecture.md)
- Formula reference: `formulas/` directory
- Component design spec: [component-design-spec.md](component-design-spec.md)
- Event reference: [event-reference.md](event-reference.md)
- Schema reference (Woo subscriptions and donations): `formulas/subscription-donation-schema.md`
- Open questions: [open-questions.md](open-questions.md)