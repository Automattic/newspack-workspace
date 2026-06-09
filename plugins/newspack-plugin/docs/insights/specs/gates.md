# Tab 4: Gates — Product Spec

> Companion to `formulas/tab-4-gates.md`. This document specifies the UI structure, empty-state behavior, and product decisions. Formula references point at the formulas doc; no SQL lives here.

## Status: placeholder phase

This tab ships in two phases:

**Phase 1 (current): Full UI with placeholder values.** All metrics display "0" (counts), "0%" (rates), or "$0.00" (revenue). The tab is fully clickable, navigable, and styled. A single banner at the top explains the pending state. The goal is to lock in visual structure and surface UX issues before BigQuery integration lands.

**Phase 2: Wire up real data via the BQ query proxy** (NPPD-1630). Each metric in this spec maps to a `query_name` in the BQ catalog. Phase 2 work is swap-in-place at the metric orchestrator layer; the UI does not change.

This spec describes Phase 2 — the intended final state. Phase 1 is identical except every value is the placeholder.

## Summary

The Gates tab gives publishers a publisher-facing read on how their content gates (paywalls and regwalls) are performing. It answers: how many readers are seeing gates, how many of those readers convert, how many gate exposures it takes before a conversion happens, and which gates work best.

Sources: GA4 event data via BigQuery for impressions and conversion intent; local Woo for paid conversion completion (joined on `gate_post_id` and timestamp). See `formulas/tab-4-gates.md` for the queries.

## Visibility heuristic

The Gates tab appears whenever the publisher has the Newspack gates feature enabled. Check via the existing newspack-plugin gates feature API; do not check for gate activity in BQ (we don't want to make a BQ query just to decide tab visibility, and the placeholder phase needs the tab visible regardless).

For Phase 1, the tab can be unconditionally visible since it's still being validated.

## Why Gates uses a different layout than Subscribers / Donors

Subscribers and Donors are entity-state tabs: there's a population (subscribers, donors) with a current state (active, MRR) that changes over time (new, churned, lapsed). The at-a-glance / time-windowed / specialty / table shape fits because publishers want to know "where do I stand right now, what changed in this period, who's the cohort to watch, what's per-product."

Gates is a behavioral funnel tab. There is no entity state. Gates themselves are configuration; everything publishers care about is reader behavior (impressions, intent, conversion). The shape that fits is funnel-shaped: top-of-funnel exposure, then conversion (split by gate type), then journey analysis, then per-gate breakdown. That's the order this spec follows.

## Tab structure: 5 sections, top to bottom

1. **Gate exposure** — top-of-funnel scorecards
2. **Free reader conversion** — registration gate performance
3. **Paid reader conversion** — paywall gate performance, including revenue
4. **How readers convert** — funnel visualization + exposure-to-conversion distribution
5. **Performance by gate** — per-gate breakdown table

Sections render in this order. Each has a header, section caption, and content. Section captions follow the Tab 6/7 convention: gray-700, 14px, line-height 1.5, immediately below the section header.

## Top-of-tab banner (Phase 1 only)

A single dismissible banner appears above Section 1 during the placeholder phase. Copy:

> **This tab is live in preview mode.** Real-time metrics will populate once BigQuery integration is complete. The structure, sections, and visualizations are final.

Style: light blue background, info icon, dismissible via X but reappears on page reload (don't persist dismissal).

Remove this banner entirely when Phase 2 lands.

---

## Section 1: Gate exposure

**Header:** Gate exposure
**Caption:** Top of the funnel. How many readers see gates in this timeframe.

**Layout:** 4 scorecards in a single row.

### Card 1.1: Total Gate Impressions

- **Subtitle:** Every gate view in this timeframe
- **Value type:** Count
- **Placeholder:** 0
- **Comparison mode:** Yes (delta vs prior period)
- **Formula:** `formulas/tab-4-gates.md` → "Total Gate Impressions (selected period)"

### Card 1.2: Unique Readers Reached

- **Subtitle:** Distinct readers who saw at least one gate
- **Value type:** Count
- **Placeholder:** 0
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-4-gates.md` → "Unique Readers Who Saw a Gate"

### Card 1.3: Avg Exposures per Reader

- **Subtitle:** How many times a typical reader sees a gate
- **Value type:** Decimal (1 place)
- **Placeholder:** 0.0
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-4-gates.md` → "Avg Gate Exposures per Reader"

### Card 1.4: Sessions With a Gate

- **Subtitle:** % of sessions that hit at least one gate
- **Value type:** Percentage
- **Placeholder:** 0%
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-4-gates.md` → "% of Sessions With a Gate Trigger"

---

## Section 2: Free reader conversion

**Header:** Free reader conversion
**Caption:** How effectively registration gates convert visitors into registered readers. Direct counts registrations that happened in the same session as a registration gate impression. Influenced counts registrations that happened in a later session within 7 days of a registration gate impression.

**Layout:** 2 scorecards side-by-side, full width (no third column).

### Card 2.1: Regwall Conversion (Direct)

- **Subtitle:** Sessions with a registration after a registration gate impression ÷ sessions with a registration gate impression
- **Value type:** Percentage
- **Placeholder:** 0%
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-4-gates.md` → "Regwall Conversion Rate (Direct)"

### Card 2.2: Regwall Conversion (Influenced, 7d)

- **Subtitle:** Readers who registered in a later session within 7 days of seeing a registration gate ÷ readers who saw a registration gate
- **Value type:** Percentage
- **Placeholder:** 0%
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-4-gates.md` → "Regwall Conversion Rate (Influenced, 7d lookback)"

---

## Section 3: Paid reader conversion

**Header:** Paid reader conversion
**Caption:** How effectively paywall gates convert visitors into paying subscribers. Direct counts subscriptions that happened in the same session as a paywall impression. Influenced counts subscriptions that happened in a later session within 14 days of a paywall impression. Revenue is computed from actual Woo orders, not gate-event amounts.

**Layout:** 4 scorecards in a single row.

### Card 3.1: Paywall Conversion (Direct)

- **Subtitle:** Sessions with a subscription after a paywall impression ÷ sessions with a paywall impression
- **Value type:** Percentage
- **Placeholder:** 0%
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-4-gates.md` → "Paywall Conversion Rate (Direct)"

### Card 3.2: Paywall Conversion (Influenced, 14d)

- **Subtitle:** Readers who subscribed in a later session within 14 days of seeing a paywall ÷ readers who saw a paywall
- **Value type:** Percentage
- **Placeholder:** 0%
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-4-gates.md` → "Paywall Conversion Rate (Influenced, 14d lookback)"

### Card 3.3: Total Paywall Revenue (Direct)

- **Subtitle:** Sum of Woo order totals from subscriptions completed in the same session as a paywall impression
- **Value type:** Currency
- **Placeholder:** $0.00
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-4-gates.md` → "Total Revenue from Paywall (Direct)"

### Card 3.4: Avg Revenue per Paywall Conversion

- **Subtitle:** Total paywall revenue ÷ paywall conversions
- **Value type:** Currency
- **Placeholder:** $0.00
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-4-gates.md` → "Avg Revenue per Paywall Conversion"

---

## Section 4: How readers convert

**Header:** How readers convert
**Caption:** The journey from gate impression to conversion. The funnel shows where readers drop off; the distribution shows how many touches it typically takes before conversion.

**Layout:** Two visualizations side-by-side, equal width.

### Viz 4.1: Conversion funnel (left)

- **Type:** Funnel chart, vertical, three stages
- **Stages:** Impression → Engagement → Conversion
  - Impression: Total gate impressions in window
  - Engagement: Form submission on a gate (any type)
  - Conversion: Registration or paywall conversion in a session that included a gate impression
- **Values:** Distinct user count at each stage
- **Labels:** Each stage shows count + percentage of stage 1
- **Drop-off labels:** Between stages, show % drop-off
- **Placeholder:** All stages show 0, drop-off labels hidden when all zeros
- **Formula:** `formulas/tab-4-gates.md` → "Funnel: Gate Impression → Engagement → Conversion (rolled up)"

### Viz 4.2: Exposures before conversion (right)

- **Type:** Distribution table OR bar chart (whichever component library handles cleaner; default to table per existing component vocabulary)
- **Buckets:** 1 exposure, 2 exposures, 3–5 exposures, 6+ exposures
- **Columns/bars:** Bucket label, count of converters, % of total converters
- **Caption beneath:** "Of readers who converted, this is how many gates they saw first."
- **Placeholder:** All buckets show 0 / 0%
- **Formula:** `formulas/tab-4-gates.md` → "Table: Gate Exposures Before Conversion (buckets)"

---

## Section 5: Performance by gate

**Header:** Performance by gate
**Caption:** Per-gate breakdown for the selected timeframe. Click any column to re-sort.

**Layout:** Single table, full width.

**Columns:**

| Column | Type | Notes |
|---|---|---|
| Gate name | String | Enriched server-side from `wp_posts.post_title` using `gate_post_id` |
| Impressions | Count | Total impressions for this gate in window |
| Unique viewers | Count | Distinct readers who saw this gate |
| Regwall conversions | Count | Registrations attributed to this gate (last impression in the conversion session) |
| Regwall conversion rate | Percentage | Conversions ÷ registration-block impressions for this gate |
| Paywall conversions | Count | Paywall conversions attributed to this gate (last impression in the conversion session) |
| Paywall conversion rate | Percentage | Conversions ÷ checkout-button impressions for this gate |

**Sort:** Default Impressions, descending. Every column header is clickable. On first click of a new column, numeric columns open DESC (largest first); the Gate name column opens ASC (alphabetical). Clicking the same active column toggles direction. Null cells (em-dash) always sort to the bottom regardless of direction — a gate without a registration block has no Regwall conversion rate to compare, so a "—" should never claim the top of an ascending sort.

**Empty-cell convention:** If a gate doesn't have a registration block, the Regwall columns show em-dash ("—"). If it doesn't have a checkout button, the Paywall columns show em-dash. Same convention as Tab 7's Donations by tier table.

**Placeholder:** Empty state shows a single row reading "No gate data yet. Performance metrics will appear once readers begin interacting with your gates."

**Row limit:** 50. Most publishers have fewer than 20 gates; the limit guardrails against display issues for outlier publishers.

**Formula:** `formulas/tab-4-gates.md` → "Table: Performance by Gate"

---

## About Direct vs Influenced

Add as a small dismissible info callout immediately below the Phase 1 preview banner, above the Section 1 header. The Direct vs Influenced framing is foundational to Sections 2 and 3, so publishers should encounter it before reading any section that uses the terms. One-time display per session.

> **About Direct vs Influenced conversion**
> 
> **Direct** conversions happen in the same session as a gate impression. The gate is credited regardless of whether checkout happens on the same page (embedded checkout block) or after clicking through to a subscription page.
> 
> **Influenced** conversions happen after a gate impression but in a later session, within a lookback window (7 days for free conversions, 14 days for paid).
> 
> Same-session is Direct. Later-session-within-lookback is Influenced. The two are mutually exclusive and together capture every gate-touched conversion within the lookback period.

## Comparison mode

Same behavior as Tabs 6 and 7. When the user toggles "Compare to previous period," every scorecard in Sections 1, 2, and 3 renders a delta below the value. Direction signaling:

- Higher value is always better for Gates metrics: increases render green, decreases render red.
- No metric uses `lowerIsBetter` semantics on this tab.

Visualizations in Section 4 do not render comparison overlays in v1. The funnel and distribution show only the current period. Comparison mode for visualizations is a v1.1 candidate.

The Performance by gate table does not show deltas per-row in v1. Comparison adds visual complexity to a dense table for unclear gain.

## Date range picker behavior

Same as Tabs 6/7. The picker affects all sections. All metrics in this tab are window-scoped (no current-state metrics like "Active Donors"); changing the date range updates every value on the page.

The dynamic section headers used on Tabs 6/7 ("In the last 30 days") are not used here because there's no current-state section to contrast with. Date scope is communicated through the global picker.

## Empty-state details (Phase 1 specifics)

For Phase 1, every metric class returns a payload with `pending: true`. The MetricCard component reads the flag and renders the placeholder value with normal styling (no greyed-out treatment, no per-card tooltip). The single top-of-tab banner is the only signal that the tab is pending.

Placeholder values by type:

| Metric type | Placeholder |
|---|---|
| Count (impressions, viewers, conversions) | 0 |
| Rate / percentage | 0% |
| Currency | $0.00 |
| Decimal average | 0.0 |
| Funnel stage values | 0 (drop-off labels hidden) |
| Distribution bucket values | 0 / 0% |
| Table rows | Empty state row (see Section 5) |

Subtitles, captions, comparison toggle, and date picker all function normally during Phase 1. The user can navigate, interact, and explore the tab structure even though values are zero.

## Open questions for Phase 2

Surface during build, decide based on real data:

1. **Paywall-capable gate identification.** Under session-scoped attribution, the paywall conversion rate denominator needs to identify which gate impressions were "paywall-capable." Three options under consideration: param-based (`gate_has_checkout_button='yes'`, misses link-style buttons), server-side classification (orchestrator looks up gate config from `wp_posts`/popups config), or behavioral (any gate that appeared in a session with a paywall conversion). v1 recommendation: behavioral; v1.1: server-side classification. See `formulas/tab-4-gates.md` for SQL implications.
2. **Paywall completion match window.** Currently defaulted to 30 minutes in the formula doc. Worth tuning once we have production paywall data and can see actual gate-event → Woo-completion latency distributions.
3. **"Unclassified" gate exposures.** Adswerve's investigation surfaced that many publishers have gate impressions where neither `gate_has_registration_block` nor `gate_has_checkout_button` is set. Decide: include these as "Other" with separate counts, omit silently, or add a data-quality footnote. Recommendation: footnote acknowledging the gap, exclude from denominators.
4. **Multi-currency.** Defer to v1.1. Publishers with multi-currency operations will see correctly summed totals in their primary currency only; flag with footnote.
5. **Funnel visualization library.** Lock in which data-viz component renders the funnel cleanly (check the existing 8-component library: scorecard, table, funnel, line-chart, box-plot, pie-chart, skeleton, stale-pill). The funnel component should already exist.
6. **Per-gate sort behavior in the table.** Sortable on every column? Default desc on impressions? Confirm with design.

## BQ query catalog (handoff to NPPD-1630)

Each metric in this spec maps to a `query_name` in the Newspack Manager BQ query catalog. Naming convention: `gates_{metric_slug}`. Suggested initial catalog entries: