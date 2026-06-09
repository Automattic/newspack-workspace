# Tab 6: Subscribers — Formulas

Reference: see `formulas/README.md` for conventions used throughout. See `./subscription-donation-schema.md` for the canonical schema reference covering HPOS/legacy storage detection, donation vs non-donation product classification, refund handling, and subscription metadata keys. This tab assumes that reference is the source of truth for SQL patterns.

## Scope

Tab 6 covers **non-donation subscriptions**: paid memberships, paywall access, paid newsletter subscriptions, print circulation, etc. Donation-driven recurring revenue (Newspack's canonical "Donate: Monthly" / "Donate: Yearly" family, plus any product flagged with `_newspack_is_donation`) lives in Tab 7.

The product filter throughout this tab is `product_id NOT IN (:donation_product_ids)`. Where the inverse appears, it's intentional (clarifying a metric's scope).

## Tab visibility

Hide this tab entirely when the publisher has zero non-donation subscription products active. Detection query (run at boot, cache):

```sql
SELECT COUNT(*) AS non_donation_sub_count
FROM {prefix}posts p
JOIN {prefix}term_relationships tr ON p.ID = tr.object_id
JOIN {prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
JOIN {prefix}terms t ON tt.term_id = t.term_id
WHERE p.post_type = 'product'
  AND p.post_status = 'publish'
  AND tt.taxonomy = 'product_type'
  AND t.slug IN ('subscription', 'variable-subscription')
  AND p.ID NOT IN (:donation_product_ids);
```

If 0, hide Tab 6. Refresh this detection daily — publishers can add new subscription products at any time.

## Conventions specific to this tab

- **Subscription anchor:** the parent `shop_subscription` row is the recurring agreement. Each renewal is a separate `shop_order` row with `parent_subscription_id` (HPOS) or `_subscription_renewal` postmeta (legacy) linking back.
- **Active subscriber:** a distinct `customer_id` with at least one `shop_subscription` row where `status = 'wc-active'`.
- **MRR (Monthly Recurring Revenue):** normalize all subscriptions to a monthly rate. Yearly subscriptions contribute `total / 12`. Quarterly contribute `total / 3`. Monthly contribute their `total`.
- **Churn measurement:** based on `_schedule_cancelled` or status transitioning to `wc-cancelled` / `wc-expired` within the window.
- **Join surface for product scoping:** subscription-scoped queries (active count, new/churned, MRR, tenure, performance, retry rate, upcoming renewals, cancellation reasons) join through `{prefix}woocommerce_order_items` + `{prefix}woocommerce_order_itemmeta` (`meta_key = '_product_id'`). `wc_order_product_lookup` is shop_order-only on production publishers (see schema doc caveat 1). Revenue queries that scope on `shop_order` rows (Gross, Net, Refund Rate) continue to use the lookup — those queries are correct as written.

## Section: Subscriber counts

### Active Subscribers (current)

What it measures: distinct customers with at least one active non-donation subscription right now.

- Numerator: distinct `customer_id` from `shop_subscription` rows with `status = 'wc-active'` AND product NOT IN donation set

**HPOS:**
```sql
SELECT COUNT(DISTINCT o.customer_id) AS active_subscribers
FROM {prefix}wc_orders o
JOIN {prefix}woocommerce_order_items oi
    ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
JOIN {prefix}woocommerce_order_itemmeta oim
    ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
WHERE o.type = 'shop_subscription'
  AND o.status = 'wc-active'
  AND oim.meta_value NOT IN (:donation_product_ids);
```

**Legacy:**
```sql
SELECT COUNT(DISTINCT cust.meta_value) AS active_subscribers
FROM {prefix}posts p
JOIN {prefix}postmeta cust
    ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
JOIN {prefix}woocommerce_order_items oi
    ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
JOIN {prefix}woocommerce_order_itemmeta oim
    ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
WHERE p.post_type = 'shop_subscription'
  AND p.post_status = 'wc-active'
  AND oim.meta_value NOT IN (:donation_product_ids);
```

Notes:
- A reader with two active subscriptions counts once.
- "Active" excludes `wc-pending-cancel` (reader is leaving but still active until billing-cycle end). For "active + pending cancel" use `IN ('wc-active', 'wc-pending-cancel')`.

### New Subscribers (in window)

Distinct customers whose FIRST non-donation subscription started in the window.

**HPOS:**
```sql
WITH first_subs AS (
  SELECT
    o.customer_id,
    MIN(om.meta_value) AS first_start_date
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_orders_meta om ON om.order_id = o.id AND om.meta_key = '_schedule_start'
  JOIN {prefix}woocommerce_order_items oi
      ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
  JOIN {prefix}woocommerce_order_itemmeta oim
      ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
  WHERE o.type = 'shop_subscription'
    AND oim.meta_value NOT IN (:donation_product_ids)
  GROUP BY o.customer_id
)
SELECT COUNT(*) AS new_subscribers
FROM first_subs
WHERE first_start_date BETWEEN :start AND :end;
```

**Legacy:**
```sql
WITH first_subs AS (
  SELECT
    cust.meta_value AS customer_id,
    MIN(start.meta_value) AS first_start_date
  FROM {prefix}posts p
  JOIN {prefix}postmeta cust ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
  JOIN {prefix}postmeta start ON start.post_id = p.ID AND start.meta_key = '_schedule_start'
  JOIN {prefix}woocommerce_order_items oi
      ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
  JOIN {prefix}woocommerce_order_itemmeta oim
      ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
  WHERE p.post_type = 'shop_subscription'
    AND oim.meta_value NOT IN (:donation_product_ids)
  GROUP BY cust.meta_value
)
SELECT COUNT(*) AS new_subscribers
FROM first_subs
WHERE first_start_date BETWEEN :start AND :end;
```

Notes:
- "First subscription" is what makes this "new subscribers" rather than "subscription starts." A reader who cancels and re-subscribes within the window doesn't count as new.
- For "subscription starts in window" (which includes resubscribes), drop the MIN aggregation and count subscriptions directly.

### Churned Subscribers (in window)

Distinct customers whose ALL non-donation subscriptions ended in the window.

**HPOS:**
```sql
WITH cancellations AS (
  SELECT
    o.customer_id,
    o.id AS subscription_id,
    om.meta_value AS cancelled_date
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_orders_meta om ON om.order_id = o.id AND om.meta_key = '_schedule_cancelled'
  JOIN {prefix}woocommerce_order_items oi
      ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
  JOIN {prefix}woocommerce_order_itemmeta oim
      ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
  WHERE o.type = 'shop_subscription'
    AND o.status IN ('wc-cancelled', 'wc-expired')
    AND oim.meta_value NOT IN (:donation_product_ids)
    AND om.meta_value BETWEEN :start AND :end
    AND om.meta_value != ''
),
still_active AS (
  SELECT DISTINCT o.customer_id
  FROM {prefix}wc_orders o
  JOIN {prefix}woocommerce_order_items oi
      ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
  JOIN {prefix}woocommerce_order_itemmeta oim
      ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
  WHERE o.type = 'shop_subscription'
    AND o.status = 'wc-active'
    AND oim.meta_value NOT IN (:donation_product_ids)
)
SELECT COUNT(DISTINCT c.customer_id) AS churned_subscribers
FROM cancellations c
LEFT JOIN still_active a ON c.customer_id = a.customer_id
WHERE a.customer_id IS NULL;
```

(Legacy version follows the same pattern with `{prefix}postmeta` joins for `_customer_user` and `_schedule_cancelled`.)

Notes:
- Excludes customers whose subscriptions ended but who STILL have other active non-donation subs (they're not truly churned, just lost one product).
- "Cancelled" + "expired" both count as churn. Cancellation = active decision; expiration = end date reached. For separate views, split into two metrics.
- `_schedule_cancelled` can be empty even on cancelled subscriptions (legacy edge case). Filter `!= ''`.

### Churn Rate (in window)

```
churn_rate = churned_subscribers / active_subscribers_at_start_of_window
```

Compute via two queries: active count at `:start - 1 day` and churned during the window. Display as percentage.

## Section: Revenue

### MRR (Monthly Recurring Revenue, current)

Sum of normalized monthly value across all active non-donation subscriptions right now.

**HPOS:**
```sql
SELECT
  SUM(
    CASE
      WHEN bp.meta_value = 'month' AND bi.meta_value = '1' THEN o.total_amount
      WHEN bp.meta_value = 'year' AND bi.meta_value = '1' THEN o.total_amount / 12
      WHEN bp.meta_value = 'month' AND bi.meta_value = '3' THEN o.total_amount / 3
      WHEN bp.meta_value = 'month' AND bi.meta_value = '6' THEN o.total_amount / 6
      WHEN bp.meta_value = 'week' THEN o.total_amount * 4.345
      ELSE o.total_amount  -- conservative fallback; flag in caveat
    END
  ) AS mrr
FROM {prefix}wc_orders o
JOIN {prefix}wc_orders_meta bp ON bp.order_id = o.id AND bp.meta_key = '_billing_period'
JOIN {prefix}wc_orders_meta bi ON bi.order_id = o.id AND bi.meta_key = '_billing_interval'
WHERE o.type = 'shop_subscription'
  AND o.status = 'wc-active'
  AND o.id IN (
    SELECT DISTINCT oi.order_id
    FROM {prefix}woocommerce_order_items oi
    JOIN {prefix}woocommerce_order_itemmeta oim
        ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
    WHERE oi.order_item_type = 'line_item'
      AND oim.meta_value NOT IN (:donation_product_ids)
  );
```

Notes:
- Conservative `ELSE` clause for unrecognized periods. Most Newspack publishers stick to `month` and `year` with interval 1.
- "MRR" by convention excludes one-time orders. This query implicitly does that by filtering to `shop_subscription`.
- `_billing_interval` is stored as a string in meta — compare to `'1'`, `'3'`, etc.
- The non-donation filter is wrapped in `o.id IN (SELECT DISTINCT oi.order_id ...)` rather than joined directly. This dedupes subscriptions that have more than one non-donation line item so their `total_amount` isn't summed twice. See schema doc "Multi-line-item dedup pattern."

### ARR (Annual Recurring Revenue, current)

```
arr = mrr * 12
```

Plain arithmetic from MRR.

### Subscription Revenue (gross, in window)

Sum of `shop_order` totals from renewals + new subscription orders for non-donation products in the window.

**HPOS:**
```sql
SELECT SUM(o.total_amount) AS gross_subscription_revenue
FROM {prefix}wc_orders o
JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
WHERE o.type = 'shop_order'
  AND o.status IN ('wc-completed', 'wc-processing')
  AND o.date_created_gmt BETWEEN :start AND :end
  AND opl.product_id NOT IN (:donation_product_ids)
  AND EXISTS (
    SELECT 1 FROM {prefix}wc_order_product_lookup opl2
    WHERE opl2.order_id = o.id
      AND opl2.product_id IN (
        SELECT p.ID FROM {prefix}posts p
        JOIN {prefix}term_relationships tr ON p.ID = tr.object_id
        JOIN {prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        JOIN {prefix}terms t ON tt.term_id = t.term_id
        WHERE tt.taxonomy = 'product_type'
          AND t.slug IN ('subscription', 'variable-subscription', 'subscription_variation')
      )
  );
```

Notes:
- Two `wc_order_product_lookup` joins: one to filter OUT donation products, one to filter IN subscription-type products. Order must contain a subscription product to count.
- "Gross" — does not subtract refunds. For net, use the refund-aware formula in the schema doc.

### Subscription Revenue (net of refunds, in window)

Per schema doc's net revenue formula, scoped to non-donation subscription products. Include both `shop_order` and `shop_order_refund` rows; sum across totals; refunds are negative so they cancel correctly.

**HPOS:**
```sql
SELECT SUM(o.total_amount) AS net_subscription_revenue
FROM {prefix}wc_orders o
WHERE o.type IN ('shop_order', 'shop_order_refund')
  AND o.status IN ('wc-completed', 'wc-processing')
  AND o.date_created_gmt BETWEEN :start AND :end
  AND (
    -- Direct order: contains a subscription product (and no donation product)
    (o.type = 'shop_order' AND o.id IN (
      SELECT order_id FROM {prefix}wc_order_product_lookup opl
      WHERE opl.product_id IN (
        SELECT p.ID FROM {prefix}posts p
        JOIN {prefix}term_relationships tr ON p.ID = tr.object_id
        JOIN {prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        JOIN {prefix}terms t ON tt.term_id = t.term_id
        WHERE tt.taxonomy = 'product_type'
          AND t.slug IN ('subscription', 'variable-subscription', 'subscription_variation')
      )
      AND opl.product_id NOT IN (:donation_product_ids)
    ))
    OR
    -- Refund: its parent order is a subscription order (per above)
    (o.type = 'shop_order_refund' AND o.parent_order_id IN (
      SELECT order_id FROM {prefix}wc_order_product_lookup opl
      WHERE opl.product_id IN (
        SELECT p.ID FROM {prefix}posts p
        JOIN {prefix}term_relationships tr ON p.ID = tr.object_id
        JOIN {prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        JOIN {prefix}terms t ON tt.term_id = t.term_id
        WHERE tt.taxonomy = 'product_type'
          AND t.slug IN ('subscription', 'variable-subscription', 'subscription_variation')
      )
      AND opl.product_id NOT IN (:donation_product_ids)
    ))
  );
```

Notes:
- The subscription product type set should be cached at boot. The inner subquery is verbose for clarity; in production wrap it in a temp table or PHP-precomputed ID list.

### Average Revenue Per Subscriber (ARPU, monthly)

```
arpu_monthly = mrr / active_subscribers_count
```

### Refund Rate (subscription orders, in window)

```sql
WITH subscription_orders_in_window AS (
  SELECT id FROM {prefix}wc_orders
  WHERE type = 'shop_order'
    AND status IN ('wc-completed', 'wc-processing')
    AND date_created_gmt BETWEEN :start AND :end
    -- ... subscription product filter ...
),
subscription_refunds_in_window AS (
  SELECT r.id FROM {prefix}wc_orders r
  WHERE r.type = 'shop_order_refund'
    AND r.date_created_gmt BETWEEN :start AND :end
    AND r.parent_order_id IN (
      SELECT order_id FROM {prefix}wc_order_product_lookup opl
      WHERE opl.product_id NOT IN (:donation_product_ids)
      -- AND opl.product_id IN (:subscription_product_ids)
    )
)
SELECT
  (SELECT COUNT(*) FROM subscription_refunds_in_window) /
  NULLIF((SELECT COUNT(*) FROM subscription_orders_in_window), 0)
    AS refund_rate;
```

Notes:
- Refund rate by count, not revenue. For revenue-weighted, sum absolute refund amount / gross subscription revenue.
- Refund date is when the refund was PROCESSED, not when the original order was placed. See schema doc caveat.

## Section: Subscription tenure and lifecycle

### Subscription Tenure Distribution (BoxPlot)

For each active subscriber, compute current tenure in days. Aggregate as BoxPlot per subscription product family.

**HPOS:**
```sql
SELECT
  product_name,
  TIMESTAMPDIFF(DAY, start_date, NOW()) AS tenure_days
FROM (
  SELECT
    p.post_title AS product_name,
    om.meta_value AS start_date
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_orders_meta om ON om.order_id = o.id AND om.meta_key = '_schedule_start'
  JOIN {prefix}woocommerce_order_items oi
      ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
  JOIN {prefix}woocommerce_order_itemmeta oim
      ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
  JOIN {prefix}posts p ON p.ID = CAST(oim.meta_value AS UNSIGNED)
  WHERE o.type = 'shop_subscription'
    AND o.status = 'wc-active'
    AND oim.meta_value NOT IN (:donation_product_ids)
    AND om.meta_value != ''
) t
WHERE start_date < NOW();
```

Notes:
- Pass `tenure_days` per row to the BoxPlot component, grouped by `product_name`. Use the linear y-scale; consider sqrt if distribution is heavy-tailed.
- Filter out subscriptions where `start_date` is in the future or empty (data corruption edge case).
- BoxPlot's known y-domain caveat applies (see NPPD-1595). Pass explicit `yDomain={[0, dataMax]}` to render correctly.

### Lifetime Value (LTV) by Acquisition Source

This metric requires joining local Woo data to BigQuery for acquisition attribution. **Deferred to v1.1** once the BQ wrapper is operational.

v1 placeholder: LTV by acquisition source unavailable; show empty state with "Coming in v1.1" footnote when this section is rendered.

v1.1 sketch (for documentation purposes, not for v1 implementation):
```
For each customer with at least one non-donation subscription:
  - Sum all `_order_total` from completed orders for that customer where product is non-donation subscription
  - Subtract refund amounts where parent order is in that set
  - Group by acquisition source (from `_newspack_referer` postmeta, or BQ-attributed prompt/gate)
```

### Upcoming Renewals (count + value, next 30 days)

```sql
SELECT
  COUNT(*) AS upcoming_renewal_count,
  SUM(o.total_amount) AS upcoming_renewal_value
FROM {prefix}wc_orders o
JOIN {prefix}wc_orders_meta om ON om.order_id = o.id AND om.meta_key = '_schedule_next_payment'
WHERE o.type = 'shop_subscription'
  AND o.status = 'wc-active'
  AND o.id IN (
    SELECT DISTINCT oi.order_id
    FROM {prefix}woocommerce_order_items oi
    JOIN {prefix}woocommerce_order_itemmeta oim
        ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
    WHERE oi.order_item_type = 'line_item'
      AND oim.meta_value NOT IN (:donation_product_ids)
  )
  AND om.meta_value BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY);
```

Notes:
- `_schedule_next_payment` is the next scheduled renewal date.
- `total_amount` on the parent `shop_subscription` row is the *next renewal amount*, not the lifetime sum. WC stores it that way.

### Failed Payment Retry Rate (in window)

Subscriptions that hit `wc-on-hold` due to failed payment, then resolved within the window.

```sql
WITH retries AS (
  SELECT DISTINCT o.id AS subscription_id
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_orders_meta om ON om.order_id = o.id AND om.meta_key = '_schedule_payment_retry'
  JOIN {prefix}woocommerce_order_items oi
      ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
  JOIN {prefix}woocommerce_order_itemmeta oim
      ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
  WHERE o.type = 'shop_subscription'
    AND oim.meta_value NOT IN (:donation_product_ids)
    AND om.meta_value BETWEEN :start AND :end
    AND om.meta_value != ''
)
SELECT
  COUNT(*) AS retry_attempts,
  COUNT(CASE
    WHEN sub.status = 'wc-active' THEN 1
  END) AS successful_recoveries,
  SAFE_DIVIDE(
    COUNT(CASE WHEN sub.status = 'wc-active' THEN 1 END),
    COUNT(*)
  ) AS recovery_rate
FROM retries r
JOIN {prefix}wc_orders sub ON sub.id = r.subscription_id;
```

Notes:
- The `_schedule_payment_retry` postmeta is set when WC schedules a retry after a failed payment.
- "Successful recovery" = subscription went from `wc-on-hold` back to `wc-active`. Use status at the END of the window to determine.
- `SAFE_DIVIDE` is BQ syntax; for MySQL use `IFNULL(num/NULLIF(denom, 0), 0)`.

## Section: Performance breakdown

### Table: Performance by Subscription Product

```sql
SELECT
  p.ID AS product_id,
  p.post_title AS product_name,
  COUNT(DISTINCT CASE WHEN o.status = 'wc-active' THEN o.id END) AS active_subs,
  COUNT(DISTINCT CASE WHEN o.status IN ('wc-cancelled', 'wc-expired') THEN o.id END) AS churned_subs,
  SUM(CASE WHEN o.status = 'wc-active' THEN o.total_amount END) AS active_value,
  SUM(o.total_amount) AS lifetime_revenue
FROM {prefix}wc_orders o
JOIN {prefix}woocommerce_order_items oi
    ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
JOIN {prefix}woocommerce_order_itemmeta oim
    ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
JOIN {prefix}posts p ON p.ID = CAST(oim.meta_value AS UNSIGNED)
WHERE o.type = 'shop_subscription'
  AND oim.meta_value NOT IN (:donation_product_ids)
GROUP BY p.ID, p.post_title
ORDER BY active_subs DESC
LIMIT 50;
```

Notes:
- For publishers with 50+ subscription products (rare), limit to top 50 by active subscribers.
- The `lifetime_revenue` column is approximate — it's the sum of `total_amount` on the subscription rows themselves, which represents the renewal amount, not all historical renewals. For true cumulative LTV per product, see the v1.1 LTV section.

### Table: Cancellation Reasons (in window)

Per schema doc, valid `newspack_subscriptions_cancellation_reason` values are: `manually-cancelled`, `user-cancelled`, `expired`, `manually-pending-cancel`, `user-pending-cancel`.

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
      AND oim.meta_value NOT IN (:donation_product_ids)
  )
  AND sch.meta_value BETWEEN :start AND :end
GROUP BY cancellation_reason
ORDER BY count DESC;
```

Notes:
- "Unknown" reason can be substantial for older cancellations (the postmeta was added later). Don't be alarmed by a large unknown bucket if window includes pre-feature history.
- The non-donation filter uses the `DISTINCT order_id` sub-select pattern so subscriptions with multiple non-donation line items aren't counted multiple times under the same reason.

## Open items specific to Tab 6

1. **LTV by acquisition source deferred to v1.1.** Requires BQ wrapper to be operational. v1 ships without it; v1.1 backfills.

2. **MRR normalization for non-standard intervals.** The CASE statement assumes month/year intervals of 1, 3, 6, or 12. Verify against production data once Insights queries are running — if publishers have configured weird intervals (quarterly memberships, biweekly, etc.), the conservative ELSE fallback may distort MRR. Worth a sanity-check report in production: list any subscriptions with `_billing_period` or `_billing_interval` not in our recognized set.

3. **Variable subscriptions (`variable-subscription` parent + `subscription_variation` children).** The product lookup table contains the variation's `product_id`. Need to verify that variation product IDs are NOT accidentally captured in `:donation_product_ids` (they shouldn't be — donations are simple/grouped/subscription, not variations — but worth a test).

4. **Churned-subscriber attribution.** When a customer has 2 subscriptions and cancels 1, current logic excludes them from churn count. This might understate "subscription product abandonment" — losing one product is meaningful even if reader stays. For v1.1, consider a second metric: "Subscription terminations" (per-subscription) vs "Subscribers churned" (per-reader).

5. **Multi-line-item subscription attribution.** A single `shop_subscription` can have multiple line items (rare for Newspack, but possible). The Performance by Product table intentionally counts each line item toward its product — a subscription with two non-donation products contributes to BOTH products' `active_subs` counts and BOTH products' `active_value` totals. This is a v1 simplification; per-product revenue attribution that splits the `total_amount` across line items would require knowing the per-item subscription price (which lives on the product, not the order line, and adds complexity). For aggregate metrics (MRR, upcoming-renewal value, retry attempt counts, cancellation reason buckets), the queries wrap the non-donation filter in a `DISTINCT order_id` sub-select to dedupe multi-line-item subscriptions out of the `SUM()` / `COUNT()`. See schema doc's "Multi-line-item dedup pattern."

## Cross-references

- Schema reference: `./subscription-donation-schema.md`
- BQ conventions: `./README.md`
- Architecture: `../architecture.md`
- Open questions: `../open-questions.md`
- Tab 7 (Donors) for the parallel structure on donation orders: `./tab-7-donors.md`