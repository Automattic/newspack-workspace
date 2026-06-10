# Tab 5: Prompts

Behavioral funnel tab covering Newspack Campaigns (prompts). Sourced from GA4 `np_prompt_interaction` event data via BigQuery, with paid conversion completion via local Woo. Conceptually parallel to Tab 4 (Gates), with four conversion intents instead of two: donation, registration, subscription, and newsletter signup.

Spec mirrors Tab 4's structure where possible. Where Prompts diverges, it's because the underlying data model differs: prompts carry richer intent + placement + title metadata in event params, and the conversion space is wider.

Cross-reference: `formulas/tab-5-prompts.md` for the SQL, `tab-4-gates.md` for the parallel patterns.

## Tab visibility

Gated only by `NEWSPACK_INSIGHTS_ENABLED`. No separate preview flag — the Prompts tab work lands on `feat/insights-rsm` (the Insights v1 integration branch) and isn't visible to publishers until that branch merges to main as a single Insights v1 release event.

## Top of tab

### Direct vs Influenced explainer (lifted from Tab 4, with substitution)

A dismissable callout sits between the tab header and Section 1. Body text — lifted from Tab 4 and substituting "gate" → "prompt":

> **Direct** conversions happen in the same session as a prompt impression. The prompt is credited regardless of whether checkout happens on the same page (embedded checkout block) or after clicking through to a subscription page.
>
> **Influenced** conversions happen after a prompt impression but in a later session, within a lookback window (7 days for free conversions, 14 days for paid).
>
> Same-session is Direct. Later-session-within-lookback is Influenced. The two are mutually exclusive and together capture every prompt-touched conversion within the lookback period.

Dismissal is session-only — reappears on page reload, matching Tab 4's treatment.

## Section 1: Prompt exposure

**Caption:** Top of the funnel. How many readers see prompts in this timeframe.

Three scorecards in a row.

### Card 1.1 — TOTAL PROMPT IMPRESSIONS

- Value format: `count`
- Subtitle: Every prompt view in this timeframe
- Formula reference: `formulas/tab-5-prompts.md#total-prompt-impressions-selected-period`

### Card 1.2 — UNIQUE READERS REACHED

- Value format: `count`
- Subtitle: Distinct readers who saw at least one prompt
- Formula reference: `formulas/tab-5-prompts.md#unique-readers-who-saw-a-prompt`

### Card 1.3 — AVG PROMPTS PER READER

- Value format: `decimal` (one decimal place, e.g. `2.4`)
- Subtitle: How many prompts a typical reader sees
- Formula reference: `formulas/tab-5-prompts.md#avg-prompts-per-reader`

## Section 2: Prompt engagement

**Caption:** How readers respond to prompts they see. Engagement is any interaction beyond just seeing the prompt.

Three scorecards in a row.

### Card 2.1 — CLICK-THROUGH RATE

- Value format: `rate` (percentage)
- Subtitle: Clicks ÷ prompt impressions
- Formula reference: `formulas/tab-5-prompts.md#click-through-rate`

### Card 2.2 — FORM SUBMISSION RATE

- Value format: `rate` (percentage)
- Subtitle: Form submissions ÷ impressions on form-bearing prompts
- Formula reference: `formulas/tab-5-prompts.md#form-submission-rate`

### Card 2.3 — DISMISSAL RATE

- Value format: `rate` (percentage)
- Subtitle: Explicit dismissals ÷ prompt impressions
- Formula reference: `formulas/tab-5-prompts.md#dismissal-rate`

## Section 3: Free reader conversion

**Caption:** How effectively prompts convert readers into registered readers and newsletter subscribers. Direct counts conversions in the same session as a prompt impression. Influenced counts conversions in a later session within 7 days of seeing a prompt.

Four scorecards in a row.

### Card 3.1 — REGISTRATION CONVERSION (DIRECT)

- Value format: `rate`
- Subtitle: Sessions with a registration after a registration-intent prompt impression ÷ sessions with a registration-intent prompt impression
- Formula reference: `formulas/tab-5-prompts.md#registration-conversion-rate-direct`

### Card 3.2 — REGISTRATION CONVERSION (INFLUENCED, 7D)

- Value format: `rate`
- Subtitle: Readers who registered in a later session within 7 days of seeing a registration-intent prompt ÷ readers who saw a registration-intent prompt
- Formula reference: `formulas/tab-5-prompts.md#registration-conversion-rate-influenced-7d-lookback`

### Card 3.3 — NEWSLETTER SIGNUP CONVERSION (DIRECT)

- Value format: `rate`
- Subtitle: Sessions with a newsletter signup after a newsletter-intent prompt impression ÷ sessions with a newsletter-intent prompt impression
- Formula reference: `formulas/tab-5-prompts.md#newsletter-signup-conversion-rate-direct`

### Card 3.4 — NEWSLETTER SIGNUP CONVERSION (INFLUENCED, 7D)

- Value format: `rate`
- Subtitle: Readers who signed up for a newsletter in a later session within 7 days of seeing a newsletter-intent prompt ÷ readers who saw a newsletter-intent prompt
- Formula reference: `formulas/tab-5-prompts.md#newsletter-signup-conversion-rate-influenced-7d-lookback`

## Section 4: Paid reader conversion

**Caption:** How effectively prompts convert readers into donors and subscribers. Direct counts conversions in the same session as a prompt impression. Influenced counts conversions in a later session within 14 days of seeing a prompt.

Four scorecards in a row.

### Card 4.1 — DONATION CONVERSION (DIRECT)

- Value format: `rate`
- Subtitle: Sessions with a completed donation after a donation-intent prompt impression ÷ sessions with a donation-intent prompt impression
- Formula reference: `formulas/tab-5-prompts.md#donation-conversion-rate-direct`

### Card 4.2 — DONATION CONVERSION (INFLUENCED, 14D)

- Value format: `rate`
- Subtitle: Readers who completed a donation in a later session within 14 days of seeing a donation-intent prompt ÷ readers who saw a donation-intent prompt
- Formula reference: `formulas/tab-5-prompts.md#donation-conversion-rate-influenced-14d-lookback`

### Card 4.3 — SUBSCRIPTION CONVERSION (DIRECT)

- Value format: `rate`
- Subtitle: Sessions with a completed subscription after a subscription-intent prompt impression ÷ sessions with a subscription-intent prompt impression
- Formula reference: `formulas/tab-5-prompts.md#subscription-conversion-rate-direct`

### Card 4.4 — SUBSCRIPTION CONVERSION (INFLUENCED, 14D)

- Value format: `rate`
- Subtitle: Readers who completed a subscription in a later session within 14 days of seeing a subscription-intent prompt ÷ readers who saw a subscription-intent prompt
- Formula reference: `formulas/tab-5-prompts.md#subscription-conversion-rate-influenced-14d-lookback`

## Section 5: Revenue from prompts

**Caption:** Sum of Woo order totals from donations and subscriptions completed after a prompt impression. Direct totals revenue from same-session completions. Influenced totals revenue from later-session completions within 14 days of seeing a prompt.

Four scorecards in a row.

### Card 5.1 — DONATION REVENUE (DIRECT)

- Value format: `currency`
- Subtitle: Sum of Woo donation order totals from same-session completions after a donation-intent prompt impression
- Formula reference: `formulas/tab-5-prompts.md#total-donation-revenue-from-prompts-direct`

### Card 5.2 — DONATION REVENUE (INFLUENCED, 14D)

- Value format: `currency`
- Subtitle: Sum of Woo donation order totals from later-session completions within 14 days of seeing a donation-intent prompt
- Formula reference: `formulas/tab-5-prompts.md#total-donation-revenue-from-prompts-influenced-14d`

### Card 5.3 — SUBSCRIPTION REVENUE (DIRECT)

- Value format: `currency`
- Subtitle: Sum of Woo subscription order totals from same-session completions after a subscription-intent prompt impression
- Formula reference: `formulas/tab-5-prompts.md#total-subscription-revenue-from-prompts-direct`

### Card 5.4 — SUBSCRIPTION REVENUE (INFLUENCED, 14D)

- Value format: `currency`
- Subtitle: Sum of Woo subscription order totals from later-session completions within 14 days of seeing a subscription-intent prompt
- Formula reference: `formulas/tab-5-prompts.md#total-subscription-revenue-from-prompts-influenced-14d`

## Section 6: How readers convert

**Caption:** The journey from prompt impression to conversion. The funnel shows where readers drop off; the distribution shows how many touches it typically takes before conversion.

Two-column layout: Funnel on the left, Distribution table on the right. Matches Tab 4's treatment.

### Funnel

Three stages, vertical SVG. Stage values are distinct user counts.

| Stage | Label | Definition |
|---|---|---|
| 1 | Impression | Distinct readers with at least one `np_prompt_interaction(action=seen)` event |
| 2 | Engagement | Distinct readers with at least one `np_prompt_interaction(action ∈ clicked, form_submission)` event |
| 3 | Conversion | Distinct readers who registered, signed up for a newsletter, completed a donation, or completed a subscription, where any of those events is tagged with a `newspack_popup_id` |

Stage 3 is a rollup across the four conversion types. The per-intent breakdowns live in Sections 3 / 4 / 5. The funnel is intentionally permissive (no time-window enforcement between impression and conversion) so it's readable at a glance — the per-prompt table below carries the rigor.

Formula reference: `formulas/tab-5-prompts.md#funnel-prompt-impression-engagement-conversion-rolled-up`

### Distribution table

Bucketed exposure counts among readers who converted. Same shape as Tab 4.

| Bucket label | Bucket condition |
|---|---|
| 1 exposure | Reader saw exactly 1 prompt impression before converting |
| 2 exposures | Reader saw exactly 2 prompt impressions before converting |
| 3–5 exposures | Reader saw 3 to 5 prompt impressions before converting |
| 6+ exposures | Reader saw 6 or more prompt impressions before converting |

Caption under the table: Of readers who converted, this is how many prompts they saw first.

## Section 7: Performance breakdown

**Caption:** Per-prompt, per-intent, and per-placement breakdowns for the selected timeframe. Click any column to re-sort.

Three stacked tables. Same sortable table chrome as the Tab 4 Performance by gate table.

### Table 7.1 — Performance by prompt

One row per prompt, sorted by impressions descending by default.

| Column | Format | Source |
|---|---|---|
| Prompt | text | `prompt_title` from event params (no WP enrichment needed) |
| Intent | text | `action_type` value: `donation` / `registration` / `newsletters_subscription` |
| Placement | text | `prompt_placement` value: e.g. `overlay`, `inline`, `above-header` |
| Impressions | count | Total `action=seen` count |
| Unique viewers | count | Distinct users with `action=seen` |
| CTR | rate | Clicks ÷ impressions |
| Form submission rate | rate | Form submissions ÷ impressions |
| Dismissal rate | rate | Dismissals ÷ impressions |
| Registrations | count | `np_reader_registered` events tagged with this `newspack_popup_id` |
| Newsletter signups | count | `np_newsletter_subscribed` events tagged with this `newspack_popup_id` |
| Donation conversions | count | Per-prompt Woo donation completions matched to attempts within 30 min (via `Woo_Order_Resolver`) |
| Donation conversion rate | rate | `donation_conversions ÷ sessions_with_prompt_impression` |
| Subscription conversions | count | Per-prompt Woo subscription completions matched to attempts within 30 min (via `Woo_Order_Resolver`) |
| Subscription conversion rate | rate | `subscription_conversions ÷ sessions_with_prompt_impression` |

The donation/subscription columns report **conversions** (Woo-completed outcomes), not attempts — aligning with the Gates v1.1 decision (NPPD-1684). Count + rate cells right-aligned. Em-dash for non-applicable cells (e.g. CTR on a button-less prompt, or donation conversions on a registration-intent prompt) in muted gray to distinguish from a real zero.

Empty-state row when no data: "No prompt data yet. Performance metrics will appear once readers begin interacting with your prompts."

Truncated at LIMIT 50 (top by impressions). The UI shows the top 10 by the sorted column with a "See more" toggle that reveals the rest; caption note: "Showing the top 10 prompts by the sorted column; use 'See more' to reveal the rest. Capped at the top 50 prompts by impressions — lower-traffic prompts beyond that may not appear."

Formula reference: `formulas/tab-5-prompts.md#table-performance-by-prompt`

### Table 7.2 — Performance by prompt intent

One row per intent (donation / registration / newsletter signup). Aggregates across all prompts of that intent.

| Column | Format | Source |
|---|---|---|
| Intent | text | `donation` / `registration` / `newsletters_subscription` (display: title case) |
| Impressions | count | |
| Unique viewers | count | |
| CTR | rate | |
| Form submission rate | rate | |
| Dismissal rate | rate | |

Caption note: "Answers 'are my donation prompts working better than my registration prompts?' at a glance."

Formula reference: `formulas/tab-5-prompts.md#table-performance-by-prompt-intent`

### Table 7.3 — Performance by prompt placement

One row per placement (`overlay`, `inline`, `above-header`, etc.). Aggregates across all prompts at that placement.

| Column | Format | Source |
|---|---|---|
| Placement | text | `prompt_placement` value (display: title case) |
| Impressions | count | |
| Unique viewers | count | |
| CTR | rate | |
| Dismissal rate | rate | |

Caption note: "Answers 'do my overlay prompts perform better than inline?' Useful for choosing placement defaults."

Formula reference: `formulas/tab-5-prompts.md#table-performance-by-prompt-placement`

## Empty states

When a section has data but zero of a specific category:

- A specific scorecard with value zero renders the zero (e.g. "0%", "$0.00") — does not render as empty
- Comparison delta on a card renders normally when comparison is on; suppresses when off (see MetricCard `pending` pattern from Tab 4)
- Performance breakdown tables show their data — if a table has zero rows, the empty-state row above appears

When the whole tab has zero data (no prompts active, no event data ingested yet):

- Every scorecard shows zero per its format type
- Funnel shows its empty-state message ("Not enough data to chart the funnel.") — the SVG funnel needs a non-zero top stage to chart proportions, so an all-zero window renders the message rather than three zero-height bands (matches the Gates funnel)
- Distribution shows four buckets with zero / 0%
- Performance by prompt table shows the empty-state row above
- Performance by intent table shows zero rows
- Performance by placement table shows zero rows

## Notes for implementation

### Subscription-intent vs registration-intent prompts (v1 simplification)

Subscription-intent prompts and pure-registration prompts share `action_type = 'registration'` in the data model. The actual distinction is the modal checkout product type at conversion time. v1 ships with both lumped under "registration" intent in the prompt breakdown table — the conversion metrics still distinguish them (Section 4 Subscription Conversion vs Section 3 Registration Conversion) because the completion side filters by Woo product type.

v1.1 may add a "subscription-intent" badge to the per-prompt table by inferring intent from whether the prompt has ever converted to a subscription product. Out of scope for v1.

### Newsletter conversion uses event-only attribution

Newsletter signup conversions don't need a Woo join (signups complete in-event via `np_newsletter_subscribed`). Simplest of the four conversion types — the orchestrator can compute it from BQ alone with no PHP post-processing.

### Donation and subscription conversions need Woo post-processing

The BQ query returns attempts (form submissions). Completions require joining against `wp_wc_orders` with a 30-min window from the attempt event, scoped by Woo product type. Same pattern as Tab 4's paywall conversion. Lives in PHP, not the catalog query. This is what backs the per-prompt **Donation conversions / Subscription conversions** columns (count + rate) in Table 7.1 — the table reports conversions, not attempts.

### A/B variant tracking (v2)

`ab_test_id` and `ab_variant` are present on every prompt event. v1 ignores them; v2 may slice the per-prompt table by variant. File a follow-up ticket.

### `prompt_frequency` parameter (v2)

The help doc references a `prompt_frequency` event param. v1 ignores it; v2 may use it for "once-per-session prompts vs always-shown" comparison. File a follow-up ticket.

## Open questions

These remain to be decided. Defaults below are best-guess; document choices when settled.

1. **Performance by prompt LIMIT.** Spec says 50 with a caption note. Acceptable for most publishers. If any publisher has >50 active prompts and the truncation becomes a real problem, consider pagination in v1.1.

2. **Performance by prompt — completion column vs attempt column. (Resolved.)** The per-prompt table ships with **conversion** columns — `Donation conversions` / `Donation conversion rate` / `Subscription conversions` / `Subscription conversion rate` — not attempt columns, computed via the Woo join (`Woo_Order_Resolver`). This aligns with the Gates v1.1 decision (NPPD-1684): show outcomes, not engagement intent. Phase 1 ships the final column set with placeholder zeros; Phase 2 (NPPD-1682) populates real Woo-joined values. The cost is one Woo query per visible prompt, bounded by the top-50 cap.

3. **Funnel rigor — time-window enforcement.** Spec funnel is permissive (no time window between impression and conversion). The Tab 4 funnel has the same caveat. If publishers ask "how can these add up?" we have an answer (rigor lives in the per-prompt table). If they don't ask, no change.

4. **Sticky dismissal of the Direct vs Influenced explainer.** Currently session-only — reappears on page reload. Tab 4 has the same treatment. If publishers report it as repetitive, change to permanent dismissal stored in user meta. Defer until signal.

## Cross-references

- Formulas: `formulas/tab-5-prompts.md`
- Tab 4 (Gates) spec for the parallel patterns: `specs/tab-4-gates.md`
- Event reference: `event-reference.md`
- Open questions (project-wide): `open-questions.md`
