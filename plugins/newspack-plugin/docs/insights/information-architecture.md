# Information Architecture

## Overview

Newspack Insights is organized into 8 tabs, structured around publisher jobs-to-be-done rather than around the underlying data source. A publisher should be able to scan the tab nav, recognize the question they're trying to answer, and click in — without needing to know whether the answer lives in BigQuery, in WooCommerce, in Newspack Campaigns, or somewhere else.

This document is the source of truth for the tab structure, the rationale, the v1/v1.1/v2 sequencing, and the cross-cutting principles that determine where any given metric lives.

## IA Principles

### 1. Jobs-to-be-done, not data sources

Publishers think in questions. "How big is my audience?" "Who's converting?" "Are paywalls working?" They don't think "let me look at the BigQuery-sourced data" vs "let me look at the WooCommerce-sourced data." The tab structure follows their mental model.

This means some tabs (notably Tab 3: Conversion Journey) span multiple data sources. The cost is more complex queries internally; the benefit is publishers find what they're looking for without learning Newspack's data architecture.

### 2. Hide what doesn't apply

Not every Newspack publisher uses every feature. A donation-only publisher has no paywall data. A paywall-only publisher has no donation data. A non-GAM publisher has no advertising data. A regional non-US publisher has no DMA geographic data.

When a publisher hasn't enabled or doesn't use a feature, the corresponding tab (or section within a tab) hides entirely rather than showing zero values or empty states. Detection happens at boot via lightweight probe queries and is cached.

This applies tab-by-tab AND section-by-section within tabs. A publisher with subscriptions but no donations sees Tab 6 (Subscribers) but not Tab 7 (Donors). A publisher with both sees both. The Conversion Journey tab adapts internally — Subscriber → Donor cross-upsell only appears if both groups exist.

### 3. Diagnostic, not just descriptive

Tab 3 in particular surfaces opportunity buckets ("X readers registered but haven't converted in 90 days") and at-risk buckets ("X subscribers have payment retry scheduled"). The intent is to make the data actionable, not just observed.

Other tabs follow descriptive conventions; Tab 3 is intentionally prescriptive. This is a deliberate UX choice rooted in publisher feedback that Looker Studio dashboards were too passive — beautiful charts that didn't suggest action.

### 4. Direct vs Influenced attribution where it matters

Tabs 4 (Gates), 5 (Prompts), and 7 (Donors) include both Direct and Influenced conversion rates. Tab 3 (Conversion Journey) surfaces them in one place as a cross-cutting view.

- **Direct** = the conversion event has the surface ID tagged on it (e.g., `gate_post_id IS NOT NULL` on the `np_reader_registered` event)
- **Influenced** = the user had at least one impression of the surface within the lookback window (7d for free conversions, 14d for paid), regardless of whether the conversion event was tagged

Both views matter and publishers should be able to see both. Direct undercounts (a reader saw a prompt, didn't click, then registered via direct form three days later — Direct misses this); Influenced overcounts (correlation is not causation). Pairing them gives publishers a realistic range.

### 5. Net of refunds throughout

Revenue metrics on Tabs 6 and 7 default to net of refunds, using the elegance of HPOS/legacy refund records being stored with negative `total_amount`. Gross revenue available as a secondary view but defaults are net.

### 6. Per-publisher storage backend dispatch

Some Newspack publishers are on HPOS (`wp_wc_orders` + `wp_wc_orders_meta`); some are on legacy CPT (`wp_posts` + `wp_postmeta`). Insights detects which is active per publisher (via `woocommerce_custom_orders_table_enabled` option) and dispatches queries accordingly.

This is an internal abstraction; publishers don't see it. From the publisher's perspective, Tab 6 and Tab 7 just work.

### 7. Graceful degradation

When a metric's underlying data isn't available (scroll tracking not enabled, GAM not connected, custom dimensions not provisioned), the affected section hides with a diagnostic message explaining what to do, NOT with a blank state or an error.

## The 8 Tabs

### Tab 1: Audience Overview

**Job-to-be-done:** "How big is my reach?"

The entry tab. First thing a publisher sees when opening Insights. Powered by the GA4 Data API in v1; swaps to BigQuery in v1.1 (NPPD-1630), with a few metrics BQ-only and hidden until then. See `specs/audience.md`.

**Sections:**
- Reach (scorecards: active readers, sessions, pageviews, avg sessions per reader)
- Audience composition (returning rate, newsletter subscriber rate, logged-in rate, local reader rate, engaged session rate)
- Time trends (active readers over time, new vs returning over time, day of week, hour of day)
- Traffic sources (PieChart by medium, top campaigns table)
- Devices (PieChart)
- Geographic (top countries, regions, cities, DMAs — drill-down)
- Content performance (top pages by views, by readers, top authors)

### Tab 2: Engagement

**Job-to-be-done:** "Are readers engaging?"

The engagement-quality deep dive. Picks up where Tab 1 leaves off. Powered by the GA4 Data API in v1; swaps to BigQuery in v1.1 (NPPD-1630), with a few metrics BQ-only and hidden until then. See `specs/engagement.md`.

**Sections:**
- Overall quality (avg pages per session, engaged session duration, bounce rate, scroll depth rate)
- Distributions (pages per session BoxPlot, scroll depth BoxPlot)
- Content engagement (most-engaged articles via composite score, articles by completion rate, by avg time, top categories/authors)
- Reader segments (engagement by device, by traffic source, returning vs new)
- Author loyalty (reader-author affinity BoxPlot, top authors by repeat reader rate)
- Time patterns (engagement by day of week)

### Tab 3: Conversion Journey

**Job-to-be-done:** "How are people moving from stranger to supporter?"

The most strategic tab. Cross-cutting — joins reach (Tab 1) to gates/prompts/subscribers/donors (Tabs 4-7).

**Sections:**
- Reader Lifecycle Funnel (the marquee 5-stage funnel)
- Per-journey funnels (Anonymous → Registered, Registered → Subscriber, Registered → Donor, Subscriber ↔ Donor)
- Conversion source attribution (PieCharts for new registrations, subscribers, donors by gate/prompt/direct)
- Time-to-convert distributions (BoxPlots for register, subscribe, donate, cross-upsell)
- Cohort retention (LineCharts for registration → conversion and subscriber retention)
- Conversion rate trends (LineCharts, multi-series)
- Stuck stages (diagnostic — stale registered, at-risk subscribers, lapsed donors, top non-converting pages)
- Cross-tab Influenced attribution (duplicated from Tabs 4, 5, 6, 7 for the single-page view)

### Tab 4: Gates

**Job-to-be-done:** "How are paywalls/regwalls performing?"

Surface-specific deep dive on gate performance. BQ for gate events, joined to local Woo for paywall completion.

**Sections:**
- Gate exposure (impressions, unique viewers, avg exposures per reader, % of sessions with a gate)
- Gate conversion Direct (regwall + paywall conversion rates, both impression-level and user-level)
- Gate conversion Influenced (regwall 7d, paywall 14d)
- Revenue from gates (Direct + Influenced)
- Gate funnel (impression → engagement → conversion)
- Performance breakdowns (Table by gate, Table by content type)
- Exposure-to-conversion distribution (buckets)

### Tab 5: Prompts

**Job-to-be-done:** "How are campaign prompts performing?"

Same shape as Tab 4 but for Campaigns prompts. BQ for prompt events, joined to local Woo for donation/subscription completion.

**Sections:**
- Prompt exposure (impressions, unique viewers, avg prompts per reader)
- Prompt engagement (CTR, form submission rate, dismissal rate)
- Prompt conversion Direct (registration, donation, subscription, newsletter — four types)
- Prompt conversion Influenced (4 types × appropriate lookback)
- Revenue from prompts (donation + subscription, Direct + Influenced)
- Prompt funnel (impression → engagement → conversion)
- Performance breakdowns (Table by prompt, by intent, by placement)

### Tab 6: Subscribers

**Job-to-be-done:** "How are my paid memberships performing?"

Non-donation subscription deep dive. Mostly local Woo SQL. Hide tab entirely when publisher has zero non-donation subscription products.

**Sections:**
- Subscriber counts (active, new in window, churned, churn rate)
- Revenue (MRR, ARR, gross/net subscription revenue, ARPU, refund rate)
- Subscription tenure and lifecycle (tenure BoxPlot, LTV by acquisition source [v1.1], upcoming renewals, payment retry rate)
- Performance breakdowns (per-product table, cancellation reasons table)

### Tab 7: Donors

**Job-to-be-done:** "How is my donor base performing?"

Donation deep dive — both one-time and recurring donations. Mostly local Woo SQL with donation-product classification. Hide tab when zero donation activity.

**Sections:**
- Donor counts (active recurring, recurring by frequency, active any, new, lapsed, churn)
- Revenue (gross/net donation revenue, by frequency, average gift, donation MRR, refund rate)
- Donor lifecycle (retention cohort LineChart, time between donations BoxPlot, avg gift by frequency)
- Performance breakdowns (donation drives table, gift bucket distribution, cancellation reasons)

### Tab 8: Advertising

**Job-to-be-done:** "How is my ad stack performing?"

Ad revenue and inventory performance. Reads live from the GAM (Google Ad Manager) API (`ReportService`), authenticated through Newspack's existing Google OAuth connection — the same connection used for GA4 (its scopes already include `admanager`). Not BigQuery. Tab visibility is based on whether Google Ad Manager is active on the site (the GAM ad provider is enabled — `Client::is_gam_active()`); reporting readiness (OAuth `admanager` scope + a configured network code — `Client::can_run_reports()`) is checked inside the tab, which shows a "finish connecting" diagnostic when GAM is active but reporting isn't fully wired up.

**Sections:**
- Headline scorecards (impressions, revenue, fill rate, eCPM, CTR, viewability)
- Revenue trends (over time, eCPM over time, impressions over time)
- Revenue mix (direct vs programmatic split, by ad format)
- Performance by inventory (top ad units, top advertisers — direct sold only)
- Performance breakdowns (by device, top countries)
- Content category performance deferred to v1.1 (requires GAM API × GA4 BQ cross-system join)

## v1 Cut Decisions

v1 is intentionally narrower than the full 8-tab vision. Looker Studio is broken in production now; publishers need a working replacement faster than they need every metric.

### v1 ships:

**Full functionality:**
- Tab 1: Audience Overview
- Tab 3: Conversion Journey
- Tab 4: Gates
- Tab 5: Prompts

**Local-Woo-only (no BQ dependency, ships first):**
- Tab 6: Subscribers
- Tab 7: Donors

**Wizard chrome:**
- Top-level page registered under Newspack admin menu
- 8-tab navigation with conditional visibility
- Date range picker with presets
- Comparison toggle
- Stub tabs for v1.1 and v2 content (Tabs 2 and 8 show "Coming soon")

### v1.1 (post-launch):

- Tab 6: LTV by acquisition source (requires BQ wrapper)
- Tab 7: Configurable trailing window for "active donor"
- Tab 3: Heuristic improvements (multi-author splitting, page URL canonicalization)
- Article freshness metric (depends on NPPD-1621: add post_published_date custom dimension)
- Configurable engagement composite score weighting

### v2:

- Tab 2: Engagement (full implementation)
- Tab 8: Advertising (depends on building a GAM reporting runner in the Insights module + a pilot publisher)
- Tab 8: Content category performance (GAM API × GA4 BQ cross-system join)
- Header bidding (Prebid partner performance)

### v1 deferrals worth knowing:

- **Cohort retention** (Tab 3, Tab 7) requires Action Scheduler pre-warm to be operational (NPPD-1606); if pre-warm isn't ready by ship date, these LineCharts ship as empty states with diagnostic message
- **Local reader rate** (Tab 1) requires the coverage-area setting UI (NPPD-1620); ships as hidden section until setting exists
- **GAM data** (Tab 8) requires a GAM reporting runner in the Insights module (`newspack-plugin/includes/wizards/insights/` — no reporting code exists yet anywhere) and a publisher connected to GAM via OAuth with a network code; pilot publisher TBD

## Cross-cutting decisions

### Date range

Single date range picker at the top of the wizard, applied across all tabs. Presets: Last 7 days, Last 30 days (default), Last 90 days, This month, Last month, Custom.

Date range is preserved across tab switches (publisher doesn't have to re-pick when moving tabs). State persisted in URL query string for shareable/refreshable views.

### Comparison mode

Single toggle "Compare to previous period" applied across all tabs. When on, metrics show the delta from the equivalent prior period (e.g., "this month vs last month").

Not all metrics support comparison meaningfully. Cohort retention, distribution BoxPlots, and current-state metrics (active subscribers, MRR) hide their delta indicator when comparison mode is on but the metric is point-in-time.

### Tab visibility

Tab visibility is computed at boot via lightweight detection queries:

- Tab 6 visible if non-donation subscription product count > 0
- Tab 7 visible if any donation product activity ever
- Tab 8 visible if Google Ad Manager is active on the site (the GAM ad provider is enabled — `Client::is_gam_active()`); reporting readiness (OAuth scope + network code — `Client::can_run_reports()`) is handled in-tab, not as a visibility gate
- Scroll-dependent sections within Tab 2 visible if scroll events fired in last 7 days
- Local Reader Rate within Tab 1 visible if coverage area setting is configured

Detection results are cached daily. Force-refresh available via Insights settings.

### Settings location

Insights-specific settings live in a separate admin page accessible via a gear icon in the wizard header. Settings categories:

- Coverage area (geographic config for "local reader" detection)
- Donation product classification (for the Ambassador-style misclassification problem — NPPD-1619)
- GAM timezone alignment (optional, for Tab 8 report windows)
- Engagement composite score weighting (v1.1)
- Trailing window for "active donor" (v1.1)
- Timezone (defaults to WP site timezone, override available)

Settings page is its own future issue. v1 ships with all settings at defaults; no UI exists yet for changing them.

## Data source map

For engineering reference: which data source powers which tab.

| Tab | GA4 (BQ) | Woo (local) | GAM (API) | Custom dimensions used |
|-----|----------|-------------|----------|------------------------|
| 1: Audience Overview | ✓ | — | — | `logged_in`, `is_newsletter_subscriber`, `is_subscriber`, `is_donor`, `categories`, `author` |
| 2: Engagement | ✓ | — | — | (same as Tab 1) + `post_type`, `post_published_date` (NPPD-1621) |
| 3: Conversion Journey | ✓ | ✓ | — | conversion event params + Woo orders |
| 4: Gates | ✓ | ✓ | — | `gate_*` family |
| 5: Prompts | ✓ | ✓ | — | `newspack_popup_id`, `action_type`, `prompt_*` family |
| 6: Subscribers | — | ✓ | — | n/a |
| 7: Donors | — | ✓ | — | n/a |
| 8: Advertising | — | — | ✓ | n/a |

## Related Linear issues

- NPPD-1602 — Wizard chrome (this PR)
- NPPD-1603 — Naming decision (Insights vs Analytics)
- NPPD-1604, 1607, 1608, 1609, 1616, 1617, 1618, 1624 — Per-tab UI implementations
- NPPD-1610, 1611, 1612, 1613, 1614 — Per-tab formula writing
- NPPD-1619 — Donation product classification (Ambassador problem)
- NPPD-1620 — Coverage area setting (for Local Reader Rate)
- NPPD-1621 — Add post_type and post_published_date to GA4 custom dimensions
- NPPD-1598, 1599 — BigQuery wrapper
- NPPD-1600 — IAM/credentials provisioning
- NPPD-1601 — Site isolation validator stack
- NPPD-1605, 1606 — Cache layer + pre-warm
- NPPD-1615 — Decide permanent home for insights-docs

## Cross-references

- Formula reference: `formulas/` directory (one file per tab + schema reference)
- Component design spec: `component-design-spec.md`
- Event reference: `event-reference.md`
- Architecture: `architecture.md`
- Open questions: `open-questions.md`