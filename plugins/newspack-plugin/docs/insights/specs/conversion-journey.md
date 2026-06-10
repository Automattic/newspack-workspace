# Tab 3: Conversion Journey — Product Spec

> Companion to `formulas/tab-3-conversion-journey.md`. This document specifies the UI structure, empty-state behavior, and product decisions. Formula references point at the formulas doc; no SQL lives here.

## Status: placeholder phase

This tab ships in two phases:

**Phase 1 (current): Full UI with placeholder values.** All scorecards display "0" (counts), "0%" (rates), or "$0.00" (currency). Funnels render all-zero stages with the empty-state message. PieCharts, cumulative LineCharts, cohort views, and weekly trends render their empty states. The tab is fully clickable, navigable, and styled. A single banner at the top explains the pending state. The goal is to lock in visual structure and surface UX issues before BigQuery integration lands.

**Phase 2: Wire up real data via the BQ query proxy** (NPPD-1630). Each metric in this spec maps to a `query_name` in the BQ catalog. Phase 2 work is swap-in-place at the metric orchestrator layer; the UI does not change.

This spec describes Phase 2 — the intended final state. Phase 1 is identical except every value is the placeholder.

## Summary

The Conversion Journey tab is the cross-cutting analytical view: how do readers move from anonymous visitor to engaged reader to registered reader to supporter? Unlike the per-source tabs (Gates, Prompts) that ask "how is THIS surface performing?", this tab asks "how does the whole funnel perform, regardless of which surface readers came through?"

It's the strategic tab. Publishers spend the most analytical time here. It's also the most computationally expensive — cohort retention in particular requires pre-warming via Action Scheduler (NPPD-1606), and the source-attribution and time-to-convert sections involve BQ-to-Woo joins that are heavier than the per-source tabs.

Sources: GA4 event data via BigQuery for lifecycle stages, registration events, and source attribution; local Woo for subscription and donation completions, joined on customer ID and timestamp. See `formulas/tab-3-conversion-journey.md` for the queries.

## Visibility heuristic

Always visible when `NEWSPACK_INSIGHTS_ENABLED`. Unlike Gates (which checks the gates feature) or Advertising (which checks GAM connection), the Conversion Journey tab is universally applicable — every Newspack publisher has reader-revenue funnels worth tracking, even if some sections are sparse for publishers with small donor or subscriber programs.

## Why Conversion Journey has its own tab

Each tab serves a question. Subscribers and Donors answer "where do I stand on these supporters?" Gates and Prompts answer "how are my conversion surfaces performing?" Conversion Journey answers something different: "how does the entire reader funnel work in aggregate, across all surfaces?"

It's the tab publishers open when they want to think strategically — diagnose where the funnel is leaking, identify cohorts to invest in, see whether their conversion rates are improving over time, find articles that aren't pulling their weight. The math is necessarily expensive (cross-tab aggregation, cohort analysis, distribution computations), which is why it has its own tab rather than living as sections of another.

## Tab structure: 8 sections, top to bottom

1. **The reader lifecycle** — 5-stage marquee funnel from anonymous to supporter
2. **Per-journey conversion funnels** — four focused funnels for the key conversion paths
3. **Where conversions come from** — source attribution across gates, prompts, and direct forms
4. **How long conversions take** — time-to-convert distributions per source
5. **Cohort retention** — registration and subscription cohort curves
6. **Conversion rate trends** — weekly rates over the window
7. **Cross-tab influenced attribution** — Influenced rates from Tabs 4, 5, 6, 7, centralized
8. **Opportunity buckets** — diagnostic counts and underperforming pages

Sections render in this order, top to bottom. The narrative flow moves from descriptive (what happened) through analytical (how it happens) to prescriptive (what to do about it). Each section has a header, section caption, and content. Section captions follow the convention used on Tabs 6 and 7: gray-700, 14px, line-height 1.5, immediately below the section header.

## Top-of-tab banner (Phase 1 only)

A single dismissible banner appears above Section 1 during the placeholder phase. Copy:

> **This tab is live in preview mode.** Real-time metrics will populate once BigQuery integration is complete. The structure, sections, and visualizations are final.

Style: light blue background, info icon, dismissible via X but reappears on page reload (don't persist dismissal).

Remove this banner entirely when Phase 2 lands.

## About cohort retention freshness

Add as a small dismissible info callout immediately below the Phase 1 preview banner, above the Section 1 header. The pre-warm-and-cache pattern affects how publishers should interpret the cohort retention section, so they should encounter the explanation before reading it. One-time display per session.

> **About cohort retention freshness**
>
> Cohort retention metrics are pre-computed and refreshed weekly. The values on this page reflect the most recent weekly snapshot, not real-time data. This is intentional — the queries that produce cohort curves are too expensive to run on every page load, and weekly granularity is the appropriate cadence for retention analysis.
>
> Other metrics on this tab (funnels, source mix, conversion rates, time-to-convert) update with each page view within the selected window.

---

## Section 1: The reader lifecycle

**Header:** The reader lifecycle
**Caption:** The marquee view. How readers progress from first-time visitor through engagement, registration, and newsletter signup to becoming a supporter.

**Layout:** Single full-width funnel visualization.

### Viz 1.1: Reader lifecycle funnel

- **Type:** Funnel chart, vertical, five stages
- **Stages:**
  1. **Anonymous reader** — distinct users with any event in the window
  2. **Engaged reader** — distinct users with at least one `session_engaged = 1` event
  3. **Registered reader** — distinct users logged in or who registered in the window
  4. **Newsletter subscriber** — distinct users with `is_newsletter_subscriber = 'yes'`
  5. **Subscriber or donor** — distinct users with `is_subscriber = 'yes'` OR `is_donor = 'yes'`
- **Values:** Distinct user count at each stage
- **Labels:** Each stage shows count + percentage of stage 1 (anonymous baseline). The funnel widget uses `compactMode={true}` for the 5-step layout.
- **Stage nesting:** Each stage's count includes all readers in deeper stages — a "supporter" is also "registered" is also "engaged" is also "anonymous." This is intentional: the funnel shows "of N anonymous readers, M became engaged, K registered, etc." Document this in the caption tooltip.
- **Drop-off labels:** Between stages, show % drop-off from the prior stage.
- **Placeholder:** All stages show 0, drop-off labels hidden when all zeros.
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Reader Lifecycle Funnel (in window)"

---

## Section 2: Per-journey conversion funnels

**Header:** Per-journey conversion funnels
**Caption:** Focused conversion paths. Each funnel shows where readers drop off within a specific journey — anonymous to registered, registered to paid, paid to donor.

**Layout:** Four small funnels in a 2-column grid. Funnels 2.1 and 2.2 in the top row, 2.3 and 2.4 in the bottom row. On narrow viewports, stacks to single column.

### Viz 2.1: Anonymous → Registered

- **Type:** Funnel chart, vertical, three stages
- **Stages:**
  1. Anonymous — distinct users with any event in window
  2. Saw a conversion surface — distinct users who saw a gate or prompt with a registration block or link
  3. Registered — distinct users who fired `np_reader_registered`
- **Caption note:** "The free-conversion path. Combines gate and prompt impressions to make the funnel readable; per-surface breakdowns live in Tabs 4 and 5."
- **Placeholder:** All stages show 0
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Funnel: Anonymous → Registered"

### Viz 2.2: Registered → Subscriber

- **Type:** Funnel chart, vertical, three stages
- **Stages:**
  1. Registered in window
  2. Saw a subscription-intent surface (gate with checkout button OR subscription-intent prompt)
  3. Became subscriber (non-donation Woo subscription)
- **Caption note:** "The paid-upsell path. Subscription excludes donation products; donor conversions are in the next funnel."
- **Placeholder:** All stages show 0
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Funnel: Registered → Subscriber (non-donation)"

### Viz 2.3: Registered → Donor

- **Type:** Funnel chart, vertical, three stages
- **Stages:**
  1. Registered in window
  2. Saw a donation-intent surface
  3. Became donor (completed donation order)
- **Caption note:** "The donation-conversion path."
- **Placeholder:** All stages show 0
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Funnel: Registered → Donor"

### Viz 2.4: Subscriber → Donor (cross-upsell)

- **Type:** Funnel chart, vertical, two stages
- **Stages:**
  1. Active subscriber
  2. Also donor (made a donation in window)
- **Caption note:** "Cross-upsell visibility for publishers running both subscriptions and donations."
- **Visibility:** Hidden when the publisher has fewer than 50 active subscribers OR fewer than 50 active donors. Below that threshold the funnel is noise. When hidden, the grid cell shows an empty-state note: "Cross-upsell view appears when both subscription and donation programs have at least 50 active participants."
- **Placeholder:** Both stages show 0
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Funnel: Subscriber → Donor (cross-upsell)"

---

## Section 3: Where conversions come from

**Header:** Where conversions come from
**Caption:** Source attribution for new conversions in the window. Gate, prompt, or direct (standalone form) — which surfaces drive your registrations, subscriptions, and donations?

**Layout:** Three PieCharts side-by-side, equal width. On narrow viewports, stacks to single column.

### Viz 3.1: Source mix — new registrations

- **Type:** PieChart
- **Slices:** Gate / Prompt / Direct (no UTM-style breakdown — surface type only)
- **Source identification:**
  - Gate: registration event has `gate_post_id` set
  - Prompt: registration event has `newspack_popup_id` set, no `gate_post_id`
  - Direct: neither set (standalone form, e.g. My Account)
- **Center label:** Total new registrations in window
- **Legend:** Slice label + count + percentage
- **Placeholder:** Empty PieChart with empty-state message: "Source data will appear once registrations occur in this window."
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Source Mix: New Registrations (PieChart)"

### Viz 3.2: Source mix — new subscribers

- **Type:** PieChart
- **Slices:** Same Gate / Prompt / Direct classification
- **Source identification:** Joined from BQ checkout events tagged with gate/popup IDs to completed Woo non-donation subscription orders within 30 min
- **Center label:** Total new subscribers in window
- **Placeholder:** Same empty-state pattern
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Source Mix: New Subscribers (PieChart)"

### Viz 3.3: Source mix — new donors

- **Type:** PieChart
- **Slices:** Same Gate / Prompt / Direct classification
- **Source identification:** Joined from BQ checkout events to completed Woo donation orders
- **Center label:** Total new donors in window
- **Placeholder:** Same empty-state pattern
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Source Mix: New Donors (PieChart)"

---

## Section 4: How long conversions take

**Header:** How long conversions take
**Caption:** Cumulative conversion curves per cohort. Each line shows what percentage of readers had converted by day N. Steeper early curves mean faster conversion; flatter curves mean longer tails. Median is where the line crosses 50%.

**Layout:** 2×2 grid of LineCharts. On narrow viewports, stacks to single column.

### Viz 4.1: Time to register

- **Type:** LineChart, single series, cumulative distribution
- **Cohort:** Readers who registered in the window
- **Measure:** For each day N from first session, the % of the cohort that had registered by day N
- **X-axis:** Days since first session, 0 to 90
- **Y-axis:** Cumulative % registered, 0 to 100
- **Grouping:** Single line — the universal "how long does it take to register?"
- **Caption note:** "Includes readers whose first session was within the last 90 days. Earlier first sessions are truncated."
- **Placeholder:** Empty LineChart with empty-state message: "Time-to-register data will appear once registrations occur in this window."
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Time-to-Register Cumulative Distribution"

### Viz 4.2: Time to subscribe

- **Type:** LineChart, multi-series, cumulative distribution
- **Cohort:** New subscribers in the window
- **Measure:** For each day N from registration, the % of the cohort that had subscribed by day N
- **X-axis:** Days since registration, 0 to 365
- **Y-axis:** Cumulative % subscribed, 0 to 100
- **Grouping:** Three lines by source — Gate, Prompt, Direct (from the registration event's surface attribution)
- **Caption note:** "The steeper line converts faster. Gaps between lines at any day point show which source is winning at that horizon."
- **Lookback cap:** 365 days from registration; later conversions truncated
- **Placeholder:** Empty LineChart
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Time-to-Subscribe Cumulative Distribution"

### Viz 4.3: Time to donate

- **Type:** LineChart, multi-series, cumulative distribution
- **Cohort:** New donors in the window
- **Measure:** For each day N from registration, the % of the cohort that had donated by day N
- **X-axis:** Days since registration, 0 to 365
- **Y-axis:** Cumulative % donated, 0 to 100
- **Grouping:** Same three lines (Gate, Prompt, Direct)
- **Caption note:** "Donors typically take longer to convert than subscribers — this view shows that lag and whether it varies by source."
- **Lookback cap:** 365 days
- **Placeholder:** Empty LineChart
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Time-to-Donate Cumulative Distribution"

### Viz 4.4: Subscriber → donor lag

- **Type:** LineChart, single series, cumulative distribution
- **Cohort:** Readers who became subscribers BEFORE donating
- **Measure:** For each day N from first subscription, the % of the cohort that had also donated by day N
- **X-axis:** Days since first subscription
- **Y-axis:** Cumulative % donated, 0 to 100
- **Visibility:** Hidden when the publisher has fewer than 50 readers in this cohort. Empty-state note when hidden: "Subscriber-to-donor lag appears when at least 50 readers have both subscribed and donated."
- **Placeholder:** Empty LineChart
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Subscriber → Donor Lag Cumulative Distribution"

---

## Section 5: Cohort retention

**Header:** Cohort retention
**Caption:** Retention curves by monthly cohort. The vertical axis is the share of each cohort still on a given lifecycle stage at each point in time. Updated weekly (see callout above).

**Layout:** Two LineCharts stacked vertically. On wide viewports, optionally side-by-side.

### Viz 5.1: Registration → conversion cohort

- **Type:** LineChart, multi-series
- **Series:** One line per monthly registration cohort (up to 12 months back)
- **X-axis:** Months since registration (0, 1, 2, …)
- **Y-axis:** % of cohort that converted (subscribed OR donated) by month N
- **Reference line:** Publisher-set conversion target (e.g., "15% at 6 months") — sourced from a Newspack settings option per NPPD-1606's design. Phase 1 hardcodes 15% at month 6; Phase 2 makes it configurable.
- **Caption note:** "Updated weekly. Old cohorts with insufficient history are dropped."
- **Placeholder:** Empty LineChart with empty-state message: "Cohort data will appear after the first weekly refresh."
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Cohort Retention Curve: Registrations → Conversion"

### Viz 5.2: Subscriber retention cohort

- **Type:** LineChart, multi-series
- **Series:** One line per monthly subscriber cohort
- **X-axis:** Months since first subscription
- **Y-axis:** % of cohort still actively subscribed at month N
- **Reference line:** Publisher-set retention target (e.g., "70% at 12 months") — Phase 1 hardcodes 70% at month 12; Phase 2 makes it configurable.
- **Caption note:** "Active-subscriber retention. Donor retention lives on Tab 7."
- **Placeholder:** Same empty-state pattern
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Cohort Retention Curve: Subscribers → Retention"

---

## Section 6: Conversion rate trends

**Header:** Conversion rate trends
**Caption:** Weekly conversion rates across the selected window. Useful for spotting acceleration, plateaus, or seasonality.

**Layout:** Single full-width LineChart.

### Viz 6.1: Weekly conversion rates

- **Type:** LineChart, multi-series, weekly granularity
- **Series:**
  - Registration conversion rate (new registrations ÷ active readers)
  - Subscription attempt rate (subscription form submissions ÷ new registrations)
- **X-axis:** Week start (ISO week)
- **Y-axis:** Percentage
- **Note:** Subscription rate uses BQ attempts, not Woo completions, for stability. Tab 6 has the completion-accurate view. Document in tooltip.
- **Aggregation:** For windows > 12 weeks, aggregate to monthly at display time so the chart stays readable.
- **Placeholder:** Empty LineChart with empty-state message: "Weekly trends will appear once the window contains at least 4 weeks of data."
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Conversion Rate Trends (LineChart, multi-series)"

---

## Section 7: Cross-tab influenced attribution

**Header:** Cross-tab influenced attribution
**Caption:** Influenced conversion rates from your Gates and Prompts tabs, centralized so you don't have to bounce between tabs to compare. Influenced means the reader saw a gate or prompt in the lookback window before converting in a later session.

**Layout:** 4 scorecards in a single row.

### Card 7.1: Influenced registration rate

- **Subtitle:** % of new registrations whose user saw a gate or prompt in the 7 days prior
- **Value type:** Percentage
- **Placeholder:** 0%
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Influenced Registration Rate (7d lookback)"

### Card 7.2: Influenced subscription rate

- **Subtitle:** % of new subscribers whose user saw a subscription-intent surface in the 14 days prior
- **Value type:** Percentage
- **Placeholder:** 0%
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Influenced Subscription Rate (14d lookback)"

### Card 7.3: Influenced donation rate

- **Subtitle:** % of new donors whose user saw a donation-intent surface in the 14 days prior
- **Value type:** Percentage
- **Placeholder:** 0%
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Influenced Donation Rate (14d lookback)"

### Card 7.4: Influenced newsletter signup rate

- **Subtitle:** % of new newsletter signups whose user saw a newsletter-intent surface in the 7 days prior
- **Value type:** Percentage
- **Placeholder:** 0%
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Influenced Newsletter Signup Rate (7d lookback)"

---

## Section 8: Opportunity buckets

**Header:** Opportunity buckets
**Caption:** Where the funnel has slack. These are diagnostic counts and underperforming pages — readers and content that could move with attention.

**Layout:** Three scorecards in a row at the top, single full-width table below.

### Card 8.1: Stale registered readers

- **Subtitle:** Registered but never converted, no activity in 90 days
- **Value type:** Count
- **Placeholder:** 0
- **Comparison mode:** No (snapshot count, not a windowed metric)
- **Action framing:** Tooltip — "Consider a re-engagement campaign."
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Stale Registered Readers"

### Card 8.2: At-risk subscribers

- **Subtitle:** Active subscribers with a failed-payment retry scheduled
- **Value type:** Count
- **Placeholder:** 0
- **Comparison mode:** No
- **Action framing:** Tooltip — "Reach out before retry fails."
- **Formula:** `formulas/tab-3-conversion-journey.md` → "At-Risk Subscribers"

### Card 8.3: Lapsed donors

- **Subtitle:** Donors with no donation in the last 365 days
- **Value type:** Count
- **Placeholder:** 0
- **Comparison mode:** No
- **Action framing:** Tooltip — "Consider a winback campaign."
- **Cross-reference:** Same definition as Tab 7's Lapsed Donors scorecard; this is a duplicated diagnostic view.
- **Formula:** `formulas/tab-3-conversion-journey.md` → "Lapsed Donors"

### Table 8.4: Top pages that don't convert

**Columns:**

| Column | Type | Notes |
|---|---|---|
| Page title | String | From `page_title` event param; v1.1 may join to `wp_posts` for canonical titles |
| Page URL | String | From `page_location`, displayed as link |
| Pageviews | Count | Total in window |
| Unique readers | Count | Distinct users with at least one pageview |
| Conversion rate | Percentage | Conversions on this page ÷ unique readers |

**Sort:** Default by Pageviews descending then Conversion Rate ascending — surfaces high-traffic, low-conversion pages. Every column header is clickable.

**Filter:** Singular content only (post_id IS NOT NULL). Archives and homepage excluded.

**Threshold:** Minimum 100 pageviews per page to appear (avoids noise from low-traffic URLs).

**Row limit:** Top 25 by sort order.

**Empty state:** "No qualifying pages yet. Pages with at least 100 pageviews and a measurable conversion rate will appear here."

**Action framing:** Caption beneath the table — "These pages get traffic but don't drive registrations. Consider adding a gate or prompt where engagement is high but conversion is low."

**Formula:** `formulas/tab-3-conversion-journey.md` → "Top Pages That Don't Convert (Table)"

---

## Comparison mode

When the user toggles "Compare to previous period," scorecards in Section 7 (Cross-tab influenced attribution) render deltas below the value. Same direction signaling as other tabs: higher value is better for influenced rates, so increases render green and decreases render red.

**No comparison rendering on:**

- Visualizations in Sections 1, 2, 4, 5, 6 — funnels, cumulative LineCharts, cohort LineCharts, weekly trend LineCharts. Adding a second period to these visualizations adds visual complexity for unclear gain in v1; revisit in v1.1.
- PieCharts in Section 3. Source mix comparisons across periods are interesting in principle, but the standard "compare two PieCharts" UI doesn't read well at a glance. Revisit in v1.1 if there's signal.
- Scorecards in Section 8. These are snapshot counts ("readers currently in this state"), not windowed metrics — comparing snapshots across windows is conceptually confused.

## Date range picker behavior

The picker affects every section except Section 5 (Cohort retention) and the snapshot scorecards in Section 8 (8.1, 8.2, 8.3).

- **Section 5:** Cohort retention is pre-computed weekly, independent of the picker. The cohorts shown are the most recent 12 months regardless of the selected window. Document in the cohort retention callout.
- **Section 8 scorecards:** These are current-state counts ("readers currently stale," "subscribers currently at risk," "donors currently lapsed"). The picker does not affect them. The Top Pages table (8.4) IS windowed.

The dynamic section headers used on Tabs 6 and 7 ("In the last 30 days") are used here for windowed sections (1, 2, 3, 4, 6, 7, 8.4). Snapshot sections (5, 8.1–8.3) use static headers.

## Empty-state details (Phase 1 specifics)

For Phase 1, every metric class returns a payload with `pending: true`. The MetricCard component reads the flag and renders the placeholder value with normal styling (no greyed-out treatment, no per-card tooltip). The single top-of-tab banner is the only signal that the tab is pending.

Placeholder values by type:

| Metric type | Placeholder |
|---|---|
| Count (registrations, subscribers, donors, readers) | 0 |
| Rate / percentage (Influenced rates, conversion rates) | 0% |
| Currency (no currency metrics on this tab in v1) | n/a |
| Decimal average | 0.0 |
| Funnel stage values | 0 (drop-off labels hidden) |
| PieChart | Empty state — "Source data will appear once conversions occur in this window." |
| LineChart (cumulative) | Empty state — "Time-to-convert data will appear once conversions occur in this window." |
| LineChart (cohort) | Empty state — "Cohort data will appear after the first weekly refresh." |
| LineChart (trends) | Empty state — "Weekly trends will appear once the window contains at least 4 weeks of data." |
| Table row | Empty state row (see Section 8.4) |

Subtitles, captions, comparison toggle, and date picker all function normally during Phase 1. The user can navigate, interact, and explore the tab structure even though values are zero.

## Open questions for Phase 2

Surface during build, decide based on real data:

1. **Cohort retention pre-warm cadence.** The formula doc and NPPD-1606 specify weekly refresh on Monday early morning. Confirm the schedule survives staging on a real publisher; tune if the query takes longer than expected on large publishers.
2. **Stale Registered scale.** The query requires a BQ → Woo round trip with the recently-active UID set. For publishers with > 10K registered users this could be slow. Consider materializing the "recently active UIDs" set into the cache table on a schedule; defer if the round trip is acceptable at typical publisher scale.
3. **Top Pages That Don't Convert sensitivity.** The 100-pageview threshold is a starting guess. Adjust per publisher scale once real data arrives. Also: some publishers will see this as helpful, others as pushy ("you should add a paywall here"). Worth A/B testing the section visibility with the first cohort of Insights users.
4. **Cross-upsell threshold.** Section 2.4 and Section 4.4 are gated at 50 active subscribers AND 50 active donors. Validate against real publisher data; adjust if 50 is too high (sparse cross-upsell data is still informative) or too low (noisy headlines).
5. **Subscription rate trend source.** Section 6 uses BQ attempts for trend stability. Decide whether to add a second series tracking Woo completions for accuracy-focused publishers, or leave that to Tab 6 entirely.
6. **Ambassador-style classification.** NPPD-1619 — the misclassification of donation-tier subscriptions affects Section 3.2 (source mix new subscribers may overcount), Section 3.3 (may undercount donors), and Section 2.4 (cross-upsell funnel underestimates). Same fix path as Tab 6/7 — Phase 2 picks up whatever resolution lands there.
7. **Window vs lookback discipline.** Some metrics use the publisher-selected window; others extend lookback (90d for first session, 365d for cohort retention, 7-14d for Influenced). Surface the actual lookback range for each metric in tooltips, since publishers will be confused otherwise.
8. **Wp_posts enrichment in Section 8.4.** Phase 2 may add a join to local `wp_posts` via `post_id` for canonical titles and author/category enrichment. New join pattern not currently used; defer to v1.1 if it adds material complexity.
9. **Cohort retention targets.** Section 5's reference lines are hardcoded at 15% at month 6 (registration → conversion) and 70% at month 12 (subscriber retention). Phase 2 should expose these as publisher-configurable settings. Default values are placeholders, not benchmarks.

## BQ query catalog (handoff to NPPD-1630)

Each metric in this spec maps to a `query_name` in the Newspack Manager BQ query catalog. Naming convention: `conversion_journey_{metric_slug}`. Suggested initial catalog entries:

- `conversion_journey_lifecycle_funnel`
- `conversion_journey_funnel_anon_to_registered`
- `conversion_journey_funnel_registered_to_subscriber`
- `conversion_journey_funnel_registered_to_donor`
- `conversion_journey_funnel_subscriber_to_donor`
- `conversion_journey_source_mix_registrations`
- `conversion_journey_source_mix_subscribers`
- `conversion_journey_source_mix_donors`
- `conversion_journey_time_to_register`
- `conversion_journey_time_to_subscribe`
- `conversion_journey_time_to_donate`
- `conversion_journey_sub_to_donor_lag`
- `conversion_journey_cohort_registration_to_conversion`
- `conversion_journey_cohort_subscriber_retention`
- `conversion_journey_weekly_rates`
- `conversion_journey_influenced_registration_7d`
- `conversion_journey_influenced_subscription_14d`
- `conversion_journey_influenced_donation_14d`
- `conversion_journey_influenced_newsletter_7d`
- `conversion_journey_stale_registered`
- `conversion_journey_at_risk_subscribers`
- `conversion_journey_lapsed_donors`
- `conversion_journey_top_pages_no_conversion`

The Sub→Donor cross-upsell funnel (2.4), Stale Registered count (8.1), At-Risk Subscribers (8.2), Lapsed Donors (8.3), and Cohort Subscriber Retention (5.2) are local-only (Woo) and don't need BQ catalog entries — they live entirely in PHP. Marked here for completeness; the BQ catalog skips them.
