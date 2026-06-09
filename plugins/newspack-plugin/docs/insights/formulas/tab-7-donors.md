# Tab 7: Donors — Formulas

Reference: see `formulas/README.md` for conventions used throughout. See `./subscription-donation-schema.md` for the canonical schema reference covering HPOS/legacy storage detection, donation vs non-donation product classification, refund handling, and subscription metadata keys. This tab assumes that reference is the source of truth for SQL patterns.

## Scope

Tab 7 covers **donations**: one-time gifts AND recurring donations. The donation product set is the union of two paths (per schema doc):

1. **Legacy path:** the Newspack-generated grouped product family — parent referenced by the `newspack_donation_product_id` option, with three children (One-Time simple, Monthly subscription, Yearly subscription).
2. **New flag path:** any product with `_newspack_is_donation = 'yes'` postmeta (or whose parent has that flag, for variations). Added v6.41.0 May 2026, near-zero adoption as of June 2026.

Both paths combine into a single `:donation_product_ids` set used throughout this tab. The classification gap is real: publishers using custom subscription products as donation tiers (e.g., Block Club Chicago's "Ambassador") will appear in Tab 6 (Subscribers), not here, until they adopt the flag. See open items.

Non-donation recurring revenue (paid memberships, paywall access, etc.) lives in Tab 6.

## Tab visibility

Hide this tab entirely when the publisher has zero donation activity. Detection at boot:

```sql
SELECT EXISTS (
  SELECT 1 FROM {prefix}wc_order_product_lookup
  WHERE product_id IN (:donation_product_ids)
  LIMIT 1
) AS has_donation_activity;
```

If 0, hide Tab 7. Refresh daily.

## Conventions specific to this tab

- **Donation order:** any `shop_order` whose line items include at least one product in `:donation_product_ids`. Per `\Newspack\Donations::is_donation_order()`.
- **Donor:** any customer who has made at least one completed donation order.
- **Active donor (recurring):** a customer with at least one `wc-active` recurring donation subscription (Monthly or Yearly, or any flagged subscription product).
- **Active donor (any):** a customer who has made at least one donation in the trailing N days. Defaults to 365 days. Distinguishes one-time donors who can't be "active" in a subscription sense but still recently active.
- **Lapsed donor:** a customer with at least one donation in their history but no donation in the trailing N days (default 365). Configurable per publisher.
- **Recurring donation frequency:** read from `_subscription_period` on the subscription product (`month` or `year` for Newspack's standard family).
- **Join surface for product scoping:** queries that scope on `shop_order` rows (gross/net donation revenue, refund rate, retention cohort, time-between-donations, average gift, drives performance, distribution by amount, active/lapsed/new donor counts) use `{prefix}wc_order_product_lookup` — Woo Analytics populates it correctly for `shop_order` line items. Queries that scope on `shop_subscription` rows (active recurring donors, recurring donor MRR, recurring cancellation reasons) instead join through `{prefix}woocommerce_order_items` + `{prefix}woocommerce_order_itemmeta` (`meta_key = '_product_id'`). The lookup table is shop_order-only on production publishers (see schema doc caveat 1).

## Section: Donor counts

### Active Recurring Donors (current)

Distinct customers with at least one active recurring donation subscription.

**HPOS:**
```sql
SELECT COUNT(DISTINCT o.customer_id) AS active_recurring_donors
FROM {prefix}wc_orders o
JOIN {prefix}woocommerce_order_items oi
    ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
JOIN {prefix}woocommerce_order_itemmeta oim
    ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
WHERE o.type = 'shop_subscription'
  AND o.status = 'wc-active'
  AND oim.meta_value IN (:donation_product_ids);
```

**Legacy:**
```sql
SELECT COUNT(DISTINCT cust.meta_value) AS active_recurring_donors
FROM {prefix}posts p
JOIN {prefix}postmeta cust
    ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
JOIN {prefix}woocommerce_order_items oi
    ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
JOIN {prefix}woocommerce_order_itemmeta oim
    ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
WHERE p.post_type = 'shop_subscription'
  AND p.post_status = 'wc-active'
  AND oim.meta_value IN (:donation_product_ids);
```

Notes:
- Recurring donors only — one-time donors don't have `shop_subscription` rows. Use "Active Donors (any, trailing 365d)" for the combined view.
- A donor giving monthly AND yearly counts once.

### Active Recurring Donors by Frequency

Split the above by `_subscription_period` for the donut/PieChart on the tab.

**HPOS:**
```sql
SELECT
  prd.meta_value AS frequency,
  COUNT(DISTINCT o.customer_id) AS donor_count
FROM {prefix}wc_orders o
JOIN {prefix}woocommerce_order_items oi
    ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
JOIN {prefix}woocommerce_order_itemmeta oim
    ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
JOIN {prefix}postmeta prd
    ON prd.post_id = CAST(oim.meta_value AS UNSIGNED) AND prd.meta_key = '_subscription_period'
WHERE o.type = 'shop_subscription'
  AND o.status = 'wc-active'
  AND oim.meta_value IN (:donation_product_ids)
GROUP BY prd.meta_value;
```

Notes:
- `_subscription_period` lives on the product (not the subscription order), so we join through `postmeta` regardless of HPOS/legacy storage. (Products are always in `wp_posts` / `wp_postmeta`; HPOS only affects orders.)
- Expected values: `month`, `year`. Anything else is a data anomaly — surface in UI as a single "Other" bucket.

### Active Donors (any, trailing 365 days)

Distinct customers who made at least one completed donation order in the trailing window. Includes one-time donors.

**HPOS:**
```sql
SELECT COUNT(DISTINCT o.customer_id) AS active_donors_any
FROM {prefix}wc_orders o
JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
WHERE o.type = 'shop_order'
  AND o.status IN ('wc-completed', 'wc-processing')
  AND o.date_created_gmt >= DATE_SUB(NOW(), INTERVAL 365 DAY)
  AND opl.product_id IN (:donation_product_ids);
```

Notes:
- 365 days is the default trailing window. Configurable per publisher (some prefer 730d for major-donor cycles).
- This metric IS sensitive to the trailing window choice. Document it prominently in UI ("Active in last 12 months").

### New Donors (in window)

Distinct customers whose FIRST donation order completed in the window.

**HPOS:**
```sql
WITH first_donations AS (
  SELECT
    o.customer_id,
    MIN(o.date_created_gmt) AS first_donation_date
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND o.status IN ('wc-completed', 'wc-processing')
    AND opl.product_id IN (:donation_product_ids)
  GROUP BY o.customer_id
)
SELECT COUNT(*) AS new_donors
FROM first_donations
WHERE first_donation_date BETWEEN :start AND :end;
```

Notes:
- "First donation" makes this strictly new donors — a returning donor making their second one-time gift doesn't count.
- For "donations in window" (regardless of donor history), drop the MIN and count completed donation orders directly.

### Lapsed Donors (count)

Customers with prior donation history but no donation in the last 365 days.

**HPOS:**
```sql
WITH ever_donors AS (
  SELECT DISTINCT o.customer_id
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND o.status IN ('wc-completed', 'wc-processing')
    AND opl.product_id IN (:donation_product_ids)
),
recent_donors AS (
  SELECT DISTINCT o.customer_id
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND o.status IN ('wc-completed', 'wc-processing')
    AND o.date_created_gmt >= DATE_SUB(NOW(), INTERVAL 365 DAY)
    AND opl.product_id IN (:donation_product_ids)
)
SELECT COUNT(*) AS lapsed_donors
FROM ever_donors e
LEFT JOIN recent_donors r ON e.customer_id = r.customer_id
WHERE r.customer_id IS NULL;
```

Notes:
- Lapsed is a signal for re-engagement campaigns. Pair with Tab 5 (Prompts) for "we showed lapsed donors X campaign → Y reactivated."

### Churn Rate (recurring donors, in window)

Same approach as Tab 6's subscriber churn, scoped to donation products.

```
donor_churn_rate = recurring_donors_churned_in_window / active_recurring_donors_at_start_of_window
```

Compute via two queries: active recurring donors at `:start - 1 day` and donor cancellations (any active recurring sub transitioning to cancelled/expired with no other active recurring sub) during the window.

Notes:
- One-time donors can't churn — they were never subscribed. Recurring donor churn is the relevant lifecycle metric.

## Section: Revenue

### Donation Revenue (gross, in window)

Sum of completed donation order totals in the window.

**HPOS:**
```sql
SELECT SUM(o.total_amount) AS gross_donation_revenue
FROM {prefix}wc_orders o
JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
WHERE o.type = 'shop_order'
  AND o.status IN ('wc-completed', 'wc-processing')
  AND o.date_created_gmt BETWEEN :start AND :end
  AND opl.product_id IN (:donation_product_ids);
```

**Legacy:** same pattern with `{prefix}posts` and `_order_total` postmeta.

Notes:
- This includes both one-time and recurring donations (the renewal orders for monthly/yearly donations are `shop_order` rows, not `shop_subscription` rows).
- "Gross" — does not subtract refunds. For net, use the formula below.

### Donation Revenue (net of refunds, in window)

Per schema doc's net revenue formula, scoped to donation products. Include both `shop_order` and `shop_order_refund` rows; refunds are stored as negative `total_amount` so summation gives correct net.

**HPOS:**
```sql
SELECT SUM(o.total_amount) AS net_donation_revenue
FROM {prefix}wc_orders o
WHERE o.type IN ('shop_order', 'shop_order_refund')
  AND o.status IN ('wc-completed', 'wc-processing')
  AND o.date_created_gmt BETWEEN :start AND :end
  AND (
    (o.type = 'shop_order' AND o.id IN (
      SELECT order_id FROM {prefix}wc_order_product_lookup
      WHERE product_id IN (:donation_product_ids)
    ))
    OR
    (o.type = 'shop_order_refund' AND o.parent_order_id IN (
      SELECT order_id FROM {prefix}wc_order_product_lookup
      WHERE product_id IN (:donation_product_ids)
    ))
  );
```

Notes:
- Refund date is when the refund was processed, NOT when the original donation was made. A refund in May against a December donation will reduce May's net.
- For "true net donation revenue earned in window" (window-anchored to ORIGINAL donation date with subsequent refunds netted in), the query is more complex — defer to v1.1 if publishers ask.

### Donation Revenue by Frequency (in window)

Split donation revenue between one-time and recurring for the PieChart.

**HPOS:**
```sql
SELECT
  CASE
    WHEN EXISTS (
      SELECT 1 FROM {prefix}postmeta
      WHERE post_id = opl.product_id
        AND meta_key = '_subscription_period'
        AND meta_value = 'month'
    ) THEN 'monthly'
    WHEN EXISTS (
      SELECT 1 FROM {prefix}postmeta
      WHERE post_id = opl.product_id
        AND meta_key = '_subscription_period'
        AND meta_value = 'year'
    ) THEN 'yearly'
    ELSE 'one_time'
  END AS frequency,
  SUM(o.total_amount) AS revenue
FROM {prefix}wc_orders o
JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
WHERE o.type = 'shop_order'
  AND o.status IN ('wc-completed', 'wc-processing')
  AND o.date_created_gmt BETWEEN :start AND :end
  AND opl.product_id IN (:donation_product_ids)
GROUP BY frequency;
```

Notes:
- Monthly and yearly donations are recurring; their renewals show up as new `shop_order` rows here, so monthly revenue scales with months in window.
- For "monthly equivalent recurring donation revenue" (annualized-then-divided-by-12), use the MRR formula below.

### Average Gift

```
avg_gift = gross_donation_revenue / count_of_donation_orders_in_window
```

Plain arithmetic. Compute separately for one-time vs recurring if useful.

### Monthly Recurring Donation Revenue (MRR-equivalent, current)

Donation MRR — for monitoring recurring donation health independent of one-time activity.

**HPOS:**
```sql
SELECT
  SUM(
    CASE
      WHEN prd.meta_value = 'month' AND pri.meta_value = '1' THEN o.total_amount
      WHEN prd.meta_value = 'year' AND pri.meta_value = '1' THEN o.total_amount / 12
      ELSE o.total_amount
    END
  ) AS donation_mrr
FROM {prefix}wc_orders o
JOIN {prefix}woocommerce_order_items oi
    ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
JOIN {prefix}woocommerce_order_itemmeta oim
    ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
JOIN {prefix}postmeta prd
    ON prd.post_id = CAST(oim.meta_value AS UNSIGNED) AND prd.meta_key = '_subscription_period'
JOIN {prefix}postmeta pri
    ON pri.post_id = CAST(oim.meta_value AS UNSIGNED) AND pri.meta_key = '_subscription_period_interval'
WHERE o.type = 'shop_subscription'
  AND o.status = 'wc-active'
  AND oim.meta_value IN (:donation_product_ids);
```

Notes:
- Same normalization as Tab 6's MRR but scoped to donations.
- One-time donations are excluded by design — MRR is recurring only. Use "Donation Revenue (gross, in window)" for one-time + recurring.
- This query reads billing frequency from the product's `_subscription_period` / `_subscription_period_interval` (not the subscription's `_billing_period` / `_billing_interval` as Tab 6's MRR does). Both are valid for the canonical Newspack donation family where product-level and subscription-level frequencies agree. The product-side read means each line item contributes its own per-line-item MRR — for the standard one-line-item donation subscription this is the desired behavior; for hypothetical multi-line-item donation subscriptions, each line item's product determines its own contribution. The Tab 6 subscription-side MRR uses a different (DISTINCT order_id wrapped) pattern because it reads the subscription's own billing meta and sums `total_amount` once per subscription.

### Refund Rate (donation orders, in window)

```sql
WITH donation_orders_in_window AS (
  SELECT id FROM {prefix}wc_orders
  WHERE type = 'shop_order'
    AND status IN ('wc-completed', 'wc-processing')
    AND date_created_gmt BETWEEN :start AND :end
    AND id IN (
      SELECT order_id FROM {prefix}wc_order_product_lookup
      WHERE product_id IN (:donation_product_ids)
    )
),
donation_refunds_in_window AS (
  SELECT r.id FROM {prefix}wc_orders r
  WHERE r.type = 'shop_order_refund'
    AND r.date_created_gmt BETWEEN :start AND :end
    AND r.parent_order_id IN (
      SELECT order_id FROM {prefix}wc_order_product_lookup
      WHERE product_id IN (:donation_product_ids)
    )
)
SELECT
  (SELECT COUNT(*) FROM donation_refunds_in_window) /
  NULLIF((SELECT COUNT(*) FROM donation_orders_in_window), 0)
    AS refund_rate;
```

Notes:
- Donation refunds tend to be lower-frequency than subscription refunds but higher-value (large one-time gifts being refunded for tax-year reasons, donor regret on impulse gifts, etc.).
- Surface `_refund_reason` postmeta in UI when populated — donors often give explanations on donation refunds that don't appear in subscription refunds.

## Section: Donor lifecycle and engagement

### Donor Retention Cohort (LineChart)

For each cohort (donors who first gave in month M), what % are still active N months later? Use the LineChart with reference line at a target retention rate (e.g., 30% at 12 months).

**HPOS:**
```sql
WITH first_donations AS (
  SELECT
    o.customer_id,
    DATE_FORMAT(MIN(o.date_created_gmt), '%Y-%m') AS cohort_month,
    MIN(o.date_created_gmt) AS first_date
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND o.status IN ('wc-completed', 'wc-processing')
    AND opl.product_id IN (:donation_product_ids)
  GROUP BY o.customer_id
),
subsequent_donations AS (
  SELECT
    fd.customer_id,
    fd.cohort_month,
    TIMESTAMPDIFF(MONTH, fd.first_date, o.date_created_gmt) AS months_since_first
  FROM first_donations fd
  JOIN {prefix}wc_orders o ON o.customer_id = fd.customer_id
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND o.status IN ('wc-completed', 'wc-processing')
    AND opl.product_id IN (:donation_product_ids)
    AND o.date_created_gmt > fd.first_date
)
SELECT
  cohort_month,
  months_since_first,
  COUNT(DISTINCT customer_id) AS retained_donors,
  COUNT(DISTINCT customer_id) * 100.0 /
    (SELECT COUNT(*) FROM first_donations fd2 WHERE fd2.cohort_month = sd.cohort_month) AS retention_pct
FROM subsequent_donations sd
GROUP BY cohort_month, months_since_first
ORDER BY cohort_month, months_since_first;
```

Notes:
- Heavy query. Pre-warm via Action Scheduler (NPPD-1606), refresh weekly.
- Surface as LineChart with one series per cohort month, x-axis = months since first donation, y-axis = retention %.
- Reference line at a target (e.g., 30% at 12 months) is the publisher-set goal — pull from a setting.

### Time Between Donations (BoxPlot)

For donors with 2+ donations, distribution of gaps between consecutive donations. Tells the publisher whether their donors give once-and-done, periodically, or steadily.

**HPOS:**
```sql
WITH donor_donations AS (
  SELECT
    o.customer_id,
    o.date_created_gmt,
    LAG(o.date_created_gmt) OVER (PARTITION BY o.customer_id ORDER BY o.date_created_gmt) AS prev_donation_date
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND o.status IN ('wc-completed', 'wc-processing')
    AND opl.product_id IN (:donation_product_ids)
)
SELECT
  TIMESTAMPDIFF(DAY, prev_donation_date, date_created_gmt) AS gap_days
FROM donor_donations
WHERE prev_donation_date IS NOT NULL;
```

Notes:
- Use linear y-scale for typical distributions; sqrt for heavy right-skew.
- Recurring donors will dominate the low end (monthly = ~30 day gaps). For "intentional return giving," consider filtering to one-time donations only (require `opl.product_id NOT IN` the recurring donation subset).

### Average Gift by Frequency

Scorecard, three values: one-time, monthly, yearly.

**HPOS:**
```sql
SELECT
  CASE
    WHEN EXISTS (SELECT 1 FROM {prefix}postmeta WHERE post_id = opl.product_id AND meta_key = '_subscription_period' AND meta_value = 'month') THEN 'monthly'
    WHEN EXISTS (SELECT 1 FROM {prefix}postmeta WHERE post_id = opl.product_id AND meta_key = '_subscription_period' AND meta_value = 'year') THEN 'yearly'
    ELSE 'one_time'
  END AS frequency,
  AVG(o.total_amount) AS avg_gift
FROM {prefix}wc_orders o
JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
WHERE o.type = 'shop_order'
  AND o.status IN ('wc-completed', 'wc-processing')
  AND o.date_created_gmt BETWEEN :start AND :end
  AND opl.product_id IN (:donation_product_ids)
GROUP BY frequency;
```

Notes:
- For monthly: avg gift is the per-charge amount, not the annualized value. A monthly donor giving $15/mo has an avg gift of $15.

## Section: Performance breakdown

### Table: Donation Drives Performance (in window)

If the publisher uses donation prompts (Tab 5), this table shows top-performing donation prompts. Cross-references Tab 5 data.

```sql
SELECT
  pm_popup.meta_value AS newspack_popup_id,
  pm_title.meta_value AS prompt_title,
  COUNT(DISTINCT o.id) AS donation_count,
  SUM(o.total_amount) AS donation_revenue,
  AVG(o.total_amount) AS avg_gift
FROM {prefix}wc_orders o
JOIN {prefix}wc_orders_meta pm_popup ON pm_popup.order_id = o.id AND pm_popup.meta_key = '_newspack_popup_id'
LEFT JOIN {prefix}wc_orders_meta pm_title ON pm_title.order_id = o.id AND pm_title.meta_key = '_prompt_title'
JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
WHERE o.type = 'shop_order'
  AND o.status IN ('wc-completed', 'wc-processing')
  AND o.date_created_gmt BETWEEN :start AND :end
  AND opl.product_id IN (:donation_product_ids)
  AND pm_popup.meta_value IS NOT NULL
  AND pm_popup.meta_value != ''
GROUP BY pm_popup.meta_value, pm_title.meta_value
ORDER BY donation_revenue DESC
LIMIT 20;
```

Notes:
- The `_newspack_popup_id` and `_prompt_title` meta are set by `Donations::checkout_create_order_line_item()` (verified in source). These are the canonical attribution fields for "which prompt drove this donation."
- This is the local-Woo version. The BQ-side version (Tab 5's "Performance by Prompt" with donation conversion column) gives the impression-to-conversion ratio. They're complementary.
- LIMIT 20: publishers running many simultaneous drives may need a higher limit; defer to product decision.

### Table: Donation Distribution by Amount (in window)

For the PieChart or a Table showing how donations are split by gift size. Buckets are conventional fundraising tiers — adjust per publisher.

```sql
SELECT
  CASE
    WHEN o.total_amount < 25 THEN '<$25'
    WHEN o.total_amount < 100 THEN '$25-99'
    WHEN o.total_amount < 250 THEN '$100-249'
    WHEN o.total_amount < 1000 THEN '$250-999'
    ELSE '$1000+'
  END AS gift_bucket,
  COUNT(*) AS donation_count,
  SUM(o.total_amount) AS total_revenue
FROM {prefix}wc_orders o
JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
WHERE o.type = 'shop_order'
  AND o.status IN ('wc-completed', 'wc-processing')
  AND o.date_created_gmt BETWEEN :start AND :end
  AND opl.product_id IN (:donation_product_ids)
GROUP BY gift_bucket
ORDER BY MIN(o.total_amount);
```

Notes:
- The bucket thresholds are reasonable defaults; some publishers want major-donor cutoffs (e.g., $500+ as "major gifts"). Make configurable in v1.1.
- Useful to surface major-donor concentration: "X% of revenue comes from Y% of donations" insight is implicit in the bucket distribution.

### Table: Cancellation Reasons (recurring donors, in window)

Same as Tab 6's cancellation reasons table, scoped to donation products. The reasons taxonomy is shared (`manually-cancelled`, `user-cancelled`, `expired`, etc.).

```sql
SELECT
  COALESCE(om.meta_value, 'unknown') AS cancellation_reason,
  COUNT(*) AS count
FROM {prefix}wc_orders o
LEFT JOIN {prefix}wc_orders_meta om ON om.order_id = o.id AND om.meta_key = 'newspack_subscriptions_cancellation_reason'
JOIN {prefix}wc_orders_meta sch ON sch.order_id = o.id AND sch.meta_key = '_schedule_cancelled'
WHERE o.type = 'shop_subscription'
  AND o.status IN ('wc-cancelled', 'wc-expired')
  AND o.id IN (
    SELECT DISTINCT oi.order_id
    FROM {prefix}woocommerce_order_items oi
    JOIN {prefix}woocommerce_order_itemmeta oim
        ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
    WHERE oi.order_item_type = 'line_item'
      AND oim.meta_value IN (:donation_product_ids)
  )
  AND sch.meta_value BETWEEN :start AND :end
GROUP BY cancellation_reason
ORDER BY count DESC;
```

Notes:
- For donors, "expired" is rare (most don't have end dates); "user-cancelled" dominates.
- The donation-product filter uses the `DISTINCT order_id` sub-select pattern so subscriptions with multiple donation line items aren't counted multiple times under the same reason. See schema doc's "Multi-line-item dedup pattern."

## Open items specific to Tab 7

1. **Publisher-created donation products are misclassified.** Verified at Block Club Chicago: products like "Ambassador" and "Block Club Captain" are `variable-subscription` types that the publisher considers donations, but Insights classifies them as subscriptions (per `is_donation_product()` logic). They'll appear in Tab 6, not Tab 7. The v6.41.0 `_newspack_is_donation` flag is the publisher's solution but has near-zero adoption. **Needs UX answer:** an Insights settings UI for product classification, AND publisher education to adopt the flag. Worth surfacing in UI on Tab 7 as "Have donation products outside the standard family? Flag them in Donations settings to include them here."

2. **One-time donor "activity" definition.** The trailing-365d window for "active donors (any)" is conventional but arbitrary. Some publishers think of donors as active forever; others use 730d. Make configurable in v1.1.

3. **Recurring donation renewal date vs original date.** Donation MRR uses the current active state. Monthly recurring revenue in a window uses renewal `shop_order` rows. These can diverge: a monthly donor cancels at end of June; their July MRR contribution disappears but they still contributed to June revenue. Both metrics are correct for their question; UI labeling matters.

4. **Refunds and donor lifecycle counting.** A refunded donation still appears in cohort analysis (the customer is still in `first_donations`). For "donors who gave AND kept their gift," exclude refunded customers — but most publishers don't think this granularly. Document as edge case; default behavior keeps refunded donors in cohorts.

5. **Memorial / honor donations and dedications.** Some publishers' donation forms capture "in memory of" or "in honor of" data via custom meta. Not standardized across Newspack publishers. Park as v2 — requires per-publisher schema discovery.

6. **Tax-deductibility flagging.** US publishers care about distinguishing tax-deductible from non-deductible giving (memberships sometimes have benefits that make them non-deductible). Not currently tracked at the order level in Newspack. Park as v2.

7. **Multi-line-item donation subscription attribution.** A donation `shop_subscription` with multiple line items is uncommon for the canonical Newspack donation family (each subscription points to one of monthly/yearly product). For the recurring-donor count queries this isn't an issue — `COUNT(DISTINCT customer_id)` naturally dedupes. For Cancellation Reasons the `DISTINCT order_id` sub-select pattern prevents one cancelled multi-line-item donation subscription from contributing multiple times to one reason bucket. The Monthly Recurring Donation Revenue query intentionally reads frequency from each line item's product (not the subscription's billing meta), so a hypothetical multi-line-item donation subscription's `total_amount` would be summed once per line item. This differs from Tab 6's MRR pattern and is documented inline on that query.

## Cross-references

- Schema reference: `./subscription-donation-schema.md`
- BQ conventions: `./README.md`
- Architecture: `../architecture.md`
- Open questions: `../open-questions.md`
- Tab 5 (Prompts) for donation conversion via campaign attribution: `./tab-5-prompts.md`
- Tab 6 (Subscribers) for the parallel structure on non-donation subscriptions: `./tab-6-subscribers.md`