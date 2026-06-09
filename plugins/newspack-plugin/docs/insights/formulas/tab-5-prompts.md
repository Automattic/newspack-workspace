# Tab 5: Prompts — Formulas

Reference: see `formulas/README.md` for conventions used throughout (PARAM() shorthand, partition filtering, user_id fallback, etc.). See `../event-reference.md` for the canonical event spec.

## Conventions specific to this tab

- **Prompt intent:** each prompt has exactly one intent, encoded as `np_prompt_interaction.action_type ∈ {donation, registration, newsletters_subscription}`. Subscription-intent prompts use `action_type = registration` because the modal flow includes account creation; subscriptions are then attributed via the modal checkout event chain.
- **Join key:** `newspack_popup_id` ties events from impression → engagement → conversion. The equivalent of Gates' `gate_post_id` join chain.
- **Paywall completion:** same pattern as Gates — `np_modal_checkout_interaction(form_submission, checkout_button)` is an attempt; success requires a matching Woo order within the 30-min window. See `../open-questions.md` for the window default discussion.
- **Influenced lookback:** 7 days for free conversions (registration, newsletter signup). 14 days for paid (subscription, donation).

## Section: Prompt exposure

### Total Prompt Impressions (selected period)

What it measures: count of every prompt-seen event in the window.

- Numerator: `np_prompt_interaction` events with `action = 'seen'`
- Denominator: N/A (scorecard)

```sql
SELECT COUNT(*) AS prompt_impressions
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'np_prompt_interaction'
  AND PARAM('action') = 'seen'
```

Notes: `action = 'seen'` excludes `loaded` (impression registered before viewport entry) and `dismissed/clicked` (interactions, not impressions).

### Unique Readers Who Saw a Prompt

```sql
SELECT COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_prompt_viewers
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'np_prompt_interaction'
  AND PARAM('action') = 'seen'
```

### Avg Prompts per Reader

```sql
WITH exposures AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    COUNT(*) AS exposure_count
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_prompt_interaction'
    AND PARAM('action') = 'seen'
  GROUP BY uid
)
SELECT
  SUM(exposure_count) / COUNT(*) AS avg_prompts_per_reader
FROM exposures
```

## Section: Prompt engagement

### Click-Through Rate

What it measures: of all prompt impressions, what % resulted in a click.

- Numerator: `np_prompt_interaction` events with `action = 'clicked'`
- Denominator: `np_prompt_interaction` events with `action = 'seen'`

```sql
SELECT
  SAFE_DIVIDE(
    COUNTIF(PARAM('action') = 'clicked'),
    COUNTIF(PARAM('action') = 'seen')
  ) AS click_through_rate
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'np_prompt_interaction'
```

Notes: `clicked` is distinct from `form_submission`. A click on a "Subscribe" button that links out to a checkout page fires `clicked`; a click on an inline registration form's submit button fires `form_submission` (or similar — verify against newspack-campaigns source). We're measuring intent-to-engage here.

### Form Submission Rate

What it measures: of impressions on prompts with embedded forms, what % submitted the form.

- Numerator: `np_prompt_interaction` events with `action = 'form_submission'` and any of the form-intent flags
- Denominator: `np_prompt_interaction` events with `action = 'seen'` and a form-bearing block present

```sql
SELECT
  SAFE_DIVIDE(
    COUNTIF(PARAM('action') = 'form_submission'),
    COUNTIF(PARAM('action') = 'seen'
            AND (PARAM('prompt_has_registration_block') = 'yes'
                 OR PARAM('prompt_has_donation_block') = 'yes'
                 OR PARAM('prompt_has_newsletters_subscription_block') = 'yes'))
  ) AS form_submission_rate
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'np_prompt_interaction'
```

Notes: only relevant for prompts that contain a submittable form. Button-only prompts that link out won't appear in either numerator or denominator.

### Dismissal Rate

What it measures: of prompt impressions, what % were explicitly dismissed (vs clicked or just left).

```sql
SELECT
  SAFE_DIVIDE(
    COUNTIF(PARAM('action') = 'dismissed'),
    COUNTIF(PARAM('action') = 'seen')
  ) AS dismissal_rate
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'np_prompt_interaction'
```

Notes: high dismissal rate is a warning signal — readers are actively rejecting the prompt. Worth flagging in UI when dismissal rate > 70% on a prompt with substantial impressions.

## Section: Prompt conversion (Direct)

### Registration Conversion Rate (Direct)

What it measures: % of registration-intent prompt impressions that produced a registration.

- Numerator: `np_reader_registered` events where `newspack_popup_id` is not null
- Denominator: `np_prompt_interaction` events with `action = 'seen'` AND `prompt_has_registration_block = 'yes'` AND `action_type = 'registration'`

```sql
SELECT
  COUNTIF(event_name = 'np_reader_registered'
          AND PARAM('newspack_popup_id') IS NOT NULL)
    /
  NULLIF(COUNTIF(
    event_name = 'np_prompt_interaction'
    AND PARAM('action') = 'seen'
    AND PARAM('action_type') = 'registration'
    AND PARAM('prompt_has_registration_block') = 'yes'
  ), 0) AS registration_conversion_direct
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name IN ('np_reader_registered', 'np_prompt_interaction')
```

Notes: this measures *registration intent* prompts converting to registrations. Donation/subscription prompts that incidentally cause registrations are excluded — that's their own intent's metric.

### Donation Conversion Rate (Direct)

What it measures: % of donation-intent prompt impressions that produced a completed donation.

- Numerator: `np_modal_checkout_interaction` events with `action = 'form_submission'`, `action_type = 'donation'`, `newspack_popup_id` not null AND user has a completed Woo order within 30 min
- Denominator: `np_prompt_interaction` events with `action = 'seen'`, `action_type = 'donation'`, `prompt_has_donation_block = 'yes'`

```sql
-- BQ portion: get donation conversion attempts tagged by prompt
WITH donation_attempts AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    PARAM('newspack_popup_id') AS popup_id,
    event_timestamp,
    PARAM_FLOAT('amount') AS attempted_amount,
    PARAM('currency') AS currency
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_modal_checkout_interaction'
    AND PARAM('action') = 'form_submission'
    AND PARAM('action_type') = 'donation'
    AND PARAM('newspack_popup_id') IS NOT NULL
),
prompt_impressions AS (
  SELECT COUNT(*) AS denominator
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_prompt_interaction'
    AND PARAM('action') = 'seen'
    AND PARAM('action_type') = 'donation'
    AND PARAM('prompt_has_donation_block') = 'yes'
)
SELECT
  (SELECT COUNT(*) FROM donation_attempts) AS attempts,
  (SELECT denominator FROM prompt_impressions) AS denominator
-- Filter attempts in PHP by which UIDs have completed Woo donation orders within 30 min
```

Then in PHP, filter the BQ `donation_attempts` rows by completed Woo orders:

```sql
-- Local Woo query, parameterized by UID list from BQ
SELECT DISTINCT customer_id
FROM wp_wc_orders o
JOIN wp_wc_order_product_lookup p ON o.id = p.order_id
WHERE o.customer_id IN (:bq_uids)
  AND o.status IN ('completed', 'processing')
  AND o.date_created_gmt BETWEEN :event_ts_min AND :event_ts_max_plus_30min
  AND p.product_id IN (:donation_product_ids)
```

Notes:
- Distinguishing donations from subscriptions requires filtering by product ID (donation products) or by `product_type` in `np_modal_checkout_interaction` when available. Without that filter we'd count subscription completions as donations.
- Same 30-min window as Gates. See `../open-questions.md`.

### Subscription Conversion Rate (Direct)

What it measures: % of subscription-intent prompt impressions that produced a completed subscription.

- Numerator: `np_modal_checkout_interaction` events with `action = 'form_submission'`, `action_type = 'checkout_button'`, `newspack_popup_id` not null AND user has a completed Woo subscription order within 30 min
- Denominator: `np_prompt_interaction` events with `action = 'seen'`, `action_type = 'registration'` (subscription-intent prompts use registration action_type), `prompt_has_registration_block = 'yes'`

```sql
-- BQ portion same shape as donation, but action_type=checkout_button
WITH subscription_attempts AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    PARAM('newspack_popup_id') AS popup_id,
    event_timestamp,
    PARAM_FLOAT('amount') AS attempted_amount
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_modal_checkout_interaction'
    AND PARAM('action') = 'form_submission'
    AND PARAM('action_type') = 'checkout_button'
    AND PARAM('newspack_popup_id') IS NOT NULL
)
-- Denominator: subscription-intent prompt impressions (filtered in PHP or separate CTE)
-- Filter attempts in PHP against Woo subscription products
```

Notes:
- Subscription-intent prompts in the data model are tagged `action_type=registration` (since the modal includes account creation flow). The product type at checkout distinguishes them from pure registration prompts.
- Woo filter is similar to donation but on subscription product IDs.

### Newsletter Signup Conversion Rate (Direct)

What it measures: % of newsletter-intent prompt impressions that produced a newsletter signup.

- Numerator: `np_newsletter_subscribed` events with `newspack_popup_id` not null
- Denominator: `np_prompt_interaction` events with `action = 'seen'`, `action_type = 'newsletters_subscription'`, `prompt_has_newsletters_subscription_block = 'yes'`

```sql
SELECT
  COUNTIF(event_name = 'np_newsletter_subscribed'
          AND PARAM('newspack_popup_id') IS NOT NULL)
    /
  NULLIF(COUNTIF(
    event_name = 'np_prompt_interaction'
    AND PARAM('action') = 'seen'
    AND PARAM('action_type') = 'newsletters_subscription'
    AND PARAM('prompt_has_newsletters_subscription_block') = 'yes'
  ), 0) AS newsletter_signup_conversion_direct
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name IN ('np_newsletter_subscribed', 'np_prompt_interaction')
```

Notes: simplest of the four conversion types — no Woo join needed since newsletter signups complete in-event.

## Section: Prompt conversion (Influenced)

Influenced asks: of users who converted in the window, what % had prompt exposure in the lookback period?

User-level only (per the family pattern established in Tab 4).

### Registration Conversion Rate (Influenced, 7d lookback)

```sql
WITH all_registrations AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    event_timestamp AS reg_timestamp
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_reader_registered'
),
prompt_exposures_extended AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    event_timestamp AS prompt_timestamp
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN
    FORMAT_DATE('%Y%m%d', DATE_SUB(PARSE_DATE('%Y%m%d', @start_date), INTERVAL @influenced_window_days DAY))
    AND @end_date
    AND event_name = 'np_prompt_interaction'
    AND PARAM('action') = 'seen'
    AND PARAM('action_type') = 'registration'
    AND PARAM('prompt_has_registration_block') = 'yes'
),
influenced_registrations AS (
  SELECT DISTINCT r.uid
  FROM all_registrations r
  INNER JOIN prompt_exposures_extended p
    ON r.uid = p.uid
    AND p.prompt_timestamp < r.reg_timestamp
    AND p.prompt_timestamp >= TIMESTAMP_SUB(TIMESTAMP_MICROS(r.reg_timestamp), INTERVAL @influenced_window_days DAY)
),
total_prompt_viewers AS (
  SELECT COUNT(DISTINCT uid) AS denominator
  FROM prompt_exposures_extended
)
SELECT
  (SELECT COUNT(*) FROM influenced_registrations)
    /
  NULLIF((SELECT denominator FROM total_prompt_viewers), 0)
  AS registration_conversion_influenced
```

Notes:
- `@influenced_window_days = 7` for registration (free conversion).
- Higher bytes-processed cost than Direct because of the wider scan.

### Donation Conversion Rate (Influenced, 14d lookback)

Same shape as Registration Influenced but:
- Numerator filters on completed Woo donation orders (same Woo join as Direct donation)
- Denominator filters prompts on `action_type = 'donation'`, `prompt_has_donation_block = 'yes'`
- `@influenced_window_days = 14`

### Subscription Conversion Rate (Influenced, 14d lookback)

Same shape but:
- Numerator filters on completed Woo subscription orders
- Denominator filters prompts on `action_type = 'registration'`, `prompt_has_registration_block = 'yes'` (subscription-intent prompts use registration action_type)
- `@influenced_window_days = 14`

### Newsletter Signup Conversion Rate (Influenced, 7d lookback)

Same shape but:
- Numerator filters on `np_newsletter_subscribed` events
- Denominator filters prompts on `action_type = 'newsletters_subscription'`, `prompt_has_newsletters_subscription_block = 'yes'`
- `@influenced_window_days = 7`

## Section: Revenue from prompts

### Total Donation Revenue from Prompts (Direct)

- Sum of completed Woo donation order amounts from users whose attempt fired `np_modal_checkout_interaction(form_submission, donation, newspack_popup_id IS NOT NULL)`

```sql
-- BQ side: get attempted donations tagged by prompt
SELECT
  COALESCE(user_id, user_pseudo_id) AS uid,
  PARAM('newspack_popup_id') AS popup_id,
  PARAM_FLOAT('amount') AS attempted_amount,
  PARAM('currency') AS currency,
  event_timestamp
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'np_modal_checkout_interaction'
  AND PARAM('action') = 'form_submission'
  AND PARAM('action_type') = 'donation'
  AND PARAM('newspack_popup_id') IS NOT NULL
-- Then in PHP: filter UIDs by completed Woo donation orders within 30-min window
-- Sum actual Woo order totals, not attempted_amount
```

Notes: same pattern as Gates revenue — actual Woo totals are authoritative, BQ `amount` is fallback only.

### Total Donation Revenue from Prompts (Influenced, 14d)

Same as Direct revenue but qualifying Woo orders come from users with any donation-intent prompt impression in the 14-day lookback.

### Total Subscription Revenue from Prompts (Direct)

Same shape as Donation Direct Revenue, filtered to subscription products.

### Total Subscription Revenue from Prompts (Influenced, 14d)

Same shape as Donation Influenced Revenue, filtered to subscription products.

## Section: Funnel

### Funnel: Prompt Impression → Engagement → Conversion (rolled up)

Three-stage funnel, distinct user counts. Engagement = any non-seen, non-dismissed interaction (click or form submission). Conversion = any of the four converted outcomes.

```sql
WITH
  stage1_impression AS (
    SELECT DISTINCT COALESCE(user_id, user_pseudo_id) AS uid
    FROM `{project}.{dataset}.events_*`
    WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
      AND event_name = 'np_prompt_interaction'
      AND PARAM('action') = 'seen'
  ),
  stage2_engagement AS (
    SELECT DISTINCT COALESCE(user_id, user_pseudo_id) AS uid
    FROM `{project}.{dataset}.events_*`
    WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
      AND event_name = 'np_prompt_interaction'
      AND PARAM('action') IN ('clicked', 'form_submission')
  ),
  stage3_conversion AS (
    SELECT DISTINCT COALESCE(user_id, user_pseudo_id) AS uid
    FROM `{project}.{dataset}.events_*`
    WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
      AND PARAM('newspack_popup_id') IS NOT NULL
      AND (event_name = 'np_reader_registered'
        OR event_name = 'np_newsletter_subscribed'
        OR (event_name = 'np_modal_checkout_interaction'
            AND PARAM('action') = 'form_submission'))
  )
SELECT
  (SELECT COUNT(*) FROM stage1_impression) AS step_1_impression,
  (SELECT COUNT(*) FROM stage2_engagement) AS step_2_engagement,
  (SELECT COUNT(*) FROM stage3_conversion) AS step_3_conversion
```

Notes:
- Stage 3 unions four conversion types (registration, newsletter, donation attempt, subscription attempt). It's a rollup; the per-intent breakdowns live elsewhere on the tab.
- For more rigorous funnel attribution, stage 3 should require the conversion event to occur *after* the prompt impression (within some window). Punted for v1 — the per-prompt breakdown table below carries the rigor; the top-level funnel is intentionally permissive to be readable at a glance.

## Section: Performance breakdown

### Table: Performance by Prompt

Row per prompt, with impressions and conversion rates by intent.

```sql
WITH prompt_impressions AS (
  SELECT
    PARAM('newspack_popup_id') AS popup_id,
    PARAM('prompt_title') AS prompt_title,
    PARAM('action_type') AS intent,
    PARAM('prompt_placement') AS placement,
    COUNT(*) AS impressions,
    COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_viewers,
    COUNTIF(PARAM('action') = 'clicked') AS clicks,
    COUNTIF(PARAM('action') = 'form_submission') AS form_submissions,
    COUNTIF(PARAM('action') = 'dismissed') AS dismissals
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_prompt_interaction'
    AND PARAM('newspack_popup_id') IS NOT NULL
  GROUP BY popup_id, prompt_title, intent, placement
),
prompt_registrations AS (
  SELECT
    PARAM('newspack_popup_id') AS popup_id,
    COUNT(*) AS registrations
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_reader_registered'
    AND PARAM('newspack_popup_id') IS NOT NULL
  GROUP BY popup_id
),
prompt_newsletter_signups AS (
  SELECT
    PARAM('newspack_popup_id') AS popup_id,
    COUNT(*) AS newsletter_signups
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_newsletter_subscribed'
    AND PARAM('newspack_popup_id') IS NOT NULL
  GROUP BY popup_id
),
prompt_checkout_attempts AS (
  SELECT
    PARAM('newspack_popup_id') AS popup_id,
    PARAM('action_type') AS checkout_type,
    COUNT(*) AS attempts
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_modal_checkout_interaction'
    AND PARAM('action') = 'form_submission'
    AND PARAM('newspack_popup_id') IS NOT NULL
  GROUP BY popup_id, checkout_type
)
SELECT
  i.popup_id,
  i.prompt_title,
  i.intent,
  i.placement,
  i.impressions,
  i.unique_viewers,
  SAFE_DIVIDE(i.clicks, i.impressions) AS ctr,
  SAFE_DIVIDE(i.form_submissions, i.impressions) AS form_submission_rate,
  SAFE_DIVIDE(i.dismissals, i.impressions) AS dismissal_rate,
  COALESCE(r.registrations, 0) AS registrations,
  COALESCE(n.newsletter_signups, 0) AS newsletter_signups,
  COALESCE(SUM(IF(c.checkout_type = 'donation', c.attempts, 0)), 0) AS donation_attempts,
  COALESCE(SUM(IF(c.checkout_type = 'checkout_button', c.attempts, 0)), 0) AS subscription_attempts
FROM prompt_impressions i
LEFT JOIN prompt_registrations r USING (popup_id)
LEFT JOIN prompt_newsletter_signups n USING (popup_id)
LEFT JOIN prompt_checkout_attempts c USING (popup_id)
GROUP BY
  i.popup_id, i.prompt_title, i.intent, i.placement,
  i.impressions, i.unique_viewers, i.clicks, i.form_submissions,
  i.dismissals, r.registrations, n.newsletter_signups
ORDER BY i.impressions DESC
LIMIT 50
```

Notes:
- `prompt_title` is captured directly in the event params, no WP enrichment needed (unlike Gates which only have `gate_post_id`).
- Donation and subscription columns show *attempts* — the table needs PHP post-processing to convert to *completions* via the Woo join. v1 may ship with the attempts column labeled explicitly and add the completion column in a follow-up.
- `LIMIT 50` guardrail. Publishers with >50 prompts get the top 50 by impressions with a footnote noting truncation.

### Table: Performance by Prompt Intent

Aggregated across all prompts of a given intent.

```sql
WITH prompt_data AS (
  SELECT
    PARAM('action_type') AS intent,
    COUNT(*) AS impressions,
    COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_viewers,
    COUNTIF(PARAM('action') = 'clicked') AS clicks,
    COUNTIF(PARAM('action') = 'form_submission') AS form_submissions,
    COUNTIF(PARAM('action') = 'dismissed') AS dismissals
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_prompt_interaction'
    AND PARAM('action') = 'seen'
  GROUP BY intent
)
SELECT
  intent,
  impressions,
  unique_viewers,
  SAFE_DIVIDE(clicks, impressions) AS ctr,
  SAFE_DIVIDE(form_submissions, impressions) AS form_submission_rate,
  SAFE_DIVIDE(dismissals, impressions) AS dismissal_rate
FROM prompt_data
ORDER BY impressions DESC
```

Notes: this answers the "are my donation prompts working better than my registration prompts" question at a glance. Conversion completion columns added separately via per-intent Direct queries.

### Table: Performance by Prompt Placement

```sql
SELECT
  PARAM('prompt_placement') AS placement,
  COUNT(*) AS impressions,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_viewers,
  SAFE_DIVIDE(COUNTIF(PARAM('action') = 'clicked'), COUNTIF(PARAM('action') = 'seen')) AS ctr,
  SAFE_DIVIDE(COUNTIF(PARAM('action') = 'dismissed'), COUNTIF(PARAM('action') = 'seen')) AS dismissal_rate
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'np_prompt_interaction'
GROUP BY placement
ORDER BY impressions DESC
```

Notes: answers "do my overlay prompts perform better than inline?" Useful for publishers choosing placement defaults.

## Open items specific to Tab 5

1. **Subscription-intent prompt `action_type` ambiguity:** subscription prompts share `action_type = 'registration'` with pure registration prompts. The actual distinction is in the modal checkout product type. The current spec relies on this convention; if Newspack Campaigns ever introduces a dedicated subscription-intent action type, the formulas above need updating. Document at the campaign-config level too so it's clear.

2. **Click attribution for link-out prompts:** prompts that link to an external landing page register a `clicked` event but the conversion happens off-prompt. The Influenced metric captures this correctly within the lookback window. Worth surfacing in UI when the gap between click rate and form_submission rate is large — likely a button-prompt that should be tracked via Influenced primarily.

3. **`prompt_frequency` parameter:** the help doc lists this on `np_prompt_interaction` but it's not used in any v1 metric. Could be useful for "are once-per-session prompts performing better than always-shown" analysis. Park for v2.

4. **A/B variant breakdown:** `ab_test_id` and `ab_variant` are on every prompt event. The performance breakdown could be sliced by variant for A/B comparison. Park for v2 (or v1.1 if A/B testing is heavily used).

## Cross-references

- Event reference: `../event-reference.md`
- BQ conventions: `./README.md`
- Architecture: `../architecture.md`
- Open questions: `../open-questions.md`
- Tab 4 (Gates) for similar Direct/Influenced + Woo-join patterns: `./tab-4-gates.md`