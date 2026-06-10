# Newspack Subscription & Donation Schema Reference

Verified June 2026 against two production Newspack publishers (Block Club Chicago on HPOS, Richland Source on legacy CPT) and the canonical detection code in `includes/class-donations.php` in newspack-plugin.

## TL;DR

Newspack publishers have two categorically different types of recurring revenue products in WooCommerce:

- **Donations** — created by Newspack via a single grouped product family (one parent + three children: one-time, monthly, yearly). Almost universal across publishers.
- **Non-donation subscriptions** — regular Woo Subscriptions products (paid memberships, paywall access, etc). Common at paywall publishers; rare at donation-only publishers. We rarely have a publisher running both.

Both share the same Woo Subscriptions schema (`_subscription_period`, `_schedule_*`, etc). The classification happens at the product level via the donation detection logic below.

Order storage is split between two backends across the network: HPOS (`wp_wc_orders`) and legacy CPT (`wp_posts`). Insights must detect which is active per publisher and query accordingly.

## Storage backend detection

```sql
SELECT option_value FROM {prefix}options
WHERE option_name = 'woocommerce_custom_orders_table_enabled';
```

- `yes` → HPOS active. Orders + subscriptions in `{prefix}wc_orders` (`type` column distinguishes `shop_order` vs `shop_subscription`). Meta in `{prefix}wc_orders_meta`.
- `no` (or missing) → legacy CPT active. Orders + subscriptions in `{prefix}posts` (`post_type` column). Meta in `{prefix}postmeta`.

Additionally check `woocommerce_custom_orders_table_data_sync_enabled`:
- If `yes` → both backends in sync, read either (HPOS preferred for performance)
- If `no` → read ONLY from the active backend; the other is stale or empty

Verified examples:
- Block Club Chicago — HPOS active, sync off
- Richland Source — Legacy CPT active, HPOS table exists but empty

## Multisite table prefix

Newspack publishers split between:
- Single-site setups: `wp_` prefix
- Multisite blog setups: `wp_{blog_id}_` prefix (e.g., `wp_5_` for Block Club's blog ID)

Use `wp cli db prefix` lookup or read `$wpdb->prefix` at runtime; never hardcode.

## The donation product family

Created by Newspack via `\Newspack\Donations::update_donation_product()`. Verified identical structure on Block Club Chicago and Richland Source.

ONE `grouped` parent product, identified by the `newspack_donation_product_id` option:

```sql
SELECT option_value AS parent_donation_product_id
FROM {prefix}options
WHERE option_name = 'newspack_donation_product_id';
```

The parent has THREE child products, IDs stored as a serialized array in postmeta key `_children`:

```sql
SELECT meta_value FROM {prefix}postmeta
WHERE post_id = :parent_id AND meta_key = '_children';
-- meta_value is PHP-serialized: a:3:{i:0;i:N1;i:1;i:N2;i:2;i:N3;}
```

Unserialize to get an array of three child product IDs. The three children represent:
- One-time donation — `WC_Product_Simple`, `_nyp = 'yes'` (Name Your Price)
- Monthly donation — `WC_Product_Subscription`, `_subscription_period = 'month'`, `_subscription_period_interval = 1`
- Yearly donation — `WC_Product_Subscription`, `_subscription_period = 'year'`, `_subscription_period_interval = 1`

Product types are determined via the `product_type` taxonomy in `{prefix}term_relationships` (not via postmeta).

Critical: a publisher can have OTHER grouped products too. Richland Source has a second grouped product called "Plan options". Do not assume any grouped product is the donation parent — only the one referenced by the `newspack_donation_product_id` option.

## Donation detection (canonical, per `class-donations.php`)

Per `\Newspack\Donations::is_donation_product( $product_id )`. Three detection paths, checked in order:

### Path 1: Manual donation flag (added v6.41.0, May 2026)

```sql
SELECT 1 FROM {prefix}postmeta
WHERE post_id = :product_id
  AND meta_key = '_newspack_is_donation'
  AND meta_value = 'yes';
```

If meta exists with value `yes` (Woo's `wc_bool_to_string(true)`), the product is a donation. Publishers can flag any Woo product as a donation via Donations settings UI. Verified zero adoption on Block Club Chicago and Richland Source as of June 2026 — too new.

### Path 2: Variation inheritance

If product is type `variation` or `subscription_variation`, check the parent's `_newspack_is_donation` flag (same query, but on `parent_id`). Variations inherit the flag from their `variable` / `variable-subscription` parent.

### Path 3: Legacy parent/child match (the universal path)

Compute the donation product family ID set:
- Parent ID = `newspack_donation_product_id` option value
- Child IDs = unserialize the parent's `_children` postmeta

A product is a donation if its ID matches the parent OR any child. This is the path that catches virtually all donations on Newspack publishers today.

### Combined: get all donation product IDs

```sql
-- Path 1+2: flagged products
SELECT post_id FROM {prefix}postmeta
WHERE meta_key = '_newspack_is_donation' AND meta_value = 'yes';

-- Path 3: legacy family (PHP unserialize required for _children)
SELECT option_value AS parent_id
FROM {prefix}options
WHERE option_name = 'newspack_donation_product_id';

SELECT meta_value AS children_serialized
FROM {prefix}postmeta
WHERE post_id = :parent_id AND meta_key = '_children';
```

Union the three sets in PHP. Cache aggressively — donation product IDs change rarely.

## Order-level classification: is THIS order a donation?

Per `\Newspack\Donations::is_donation_order( $order )`: iterate order line items; if ANY line item's product_id is in the donation product set, the order is a donation order.

For Insights, use `{prefix}wc_order_product_lookup` (works on both HPOS and legacy as long as Woo Analytics is enabled — which it is on all Newspack sites):

```sql
SELECT DISTINCT order_id
FROM {prefix}wc_order_product_lookup
WHERE product_id IN (:donation_product_ids);
```

That's the order set for "donation orders." Inverse set is "non-donation orders" (paid memberships, etc).

## Subscription metadata (same keys on both HPOS and legacy)

Whether stored in `{prefix}wc_orders_meta` (HPOS) or `{prefix}postmeta` (legacy), the meta keys are identical:

**Schedule:**
- `_schedule_start` — when the subscription began
- `_schedule_next_payment` — next scheduled renewal
- `_schedule_end` — when it will end (recurring sub with end date) or did end (cancelled)
- `_schedule_cancelled` — when cancellation was processed (if cancelled)
- `_schedule_trial_end` — end of trial period
- `_schedule_payment_retry` — next retry attempt for failed payment

**Billing cycle:**
- `_billing_period` — `month`, `year` (others theoretically possible)
- `_billing_interval` — usually `1`; could be `3` for quarterly

**Status / behavior:**
- `_trial_period` — duration of trial (or empty)
- `_suspension_count` — how many times suspended
- `_requires_manual_renewal` — `true`/`false` whether auto-renew is off
- `_last_order_date_created` — most recent renewal order timestamp

**Newspack-specific:**
- `newspack_subscriptions_cancellation_reason` — verified publisher-facing values: `manually-cancelled`, `user-cancelled`, `expired`, `manually-pending-cancel`, `user-pending-cancel`
- `_newspack_referer` — referring URL captured at signup

## Subscription product types (for detection beyond order classification)

`{prefix}term_relationships` joined to `{prefix}term_taxonomy` where `taxonomy = 'product_type'`. Slugs:

- `simple` — one-time purchase product
- `subscription` — Woo Subscriptions single product
- `variable-subscription` — Woo Subscriptions with variations (e.g., monthly/yearly toggle on the same product)
- `subscription_variation` — a child variation of a variable-subscription
- `grouped` — Woo grouped product (Newspack's donation parent uses this)

Verified product types on Richland Source: `variable-subscription` for "Fan Membership", "Fan Duo Membership", "Fan Engaged Reader Membership"; `simple` for "StoryBridge Membership"; `subscription` for "Company Access Membership"; the standard donation family for "Donate" grouped + children.

## Status values

For subscriptions (whether in HPOS `status` column or legacy `post_status`):
- `wc-active` — currently active
- `wc-cancelled` — fully cancelled
- `wc-expired` — reached end date naturally
- `wc-pending-cancel` — cancellation scheduled, still active until then
- `wc-on-hold` — payment problem, paused
- `wc-pending` — initial state, not yet activated
- `trash` — soft-deleted (no `wc-` prefix — raw post_status value)

For renewal orders (`shop_order` type):
- `wc-completed`, `wc-processing` — paid
- `wc-pending`, `wc-failed`, `wc-on-hold` — not paid
- `wc-cancelled`, `wc-refunded` — exclude from revenue

## Practical SQL: donation revenue in a window

HPOS version (Block Club style):

```sql
SELECT SUM(o.total) AS donation_revenue
FROM {prefix}wc_orders o
WHERE o.type = 'shop_order'
  AND o.status IN ('wc-completed', 'wc-processing')
  AND o.date_created_gmt BETWEEN :start AND :end
  AND o.id IN (
    SELECT DISTINCT order_id
    FROM {prefix}wc_order_product_lookup
    WHERE product_id IN (:donation_product_ids)
  );
```

Legacy version (Richland Source style):

```sql
SELECT SUM(CAST(pm.meta_value AS DECIMAL(15,2))) AS donation_revenue
FROM {prefix}posts p
JOIN {prefix}postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
WHERE p.post_type = 'shop_order'
  AND p.post_status IN ('wc-completed', 'wc-processing')
  AND p.post_date_gmt BETWEEN :start AND :end
  AND p.ID IN (
    SELECT DISTINCT order_id
    FROM {prefix}wc_order_product_lookup
    WHERE product_id IN (:donation_product_ids)
  );
```

The `wc_order_product_lookup` table is populated by Woo's analytics regardless of HPOS state, so it's a stable join surface for both backends.

Non-donation subscription revenue is the same query with `NOT IN` for the donation product IDs.

## Choosing the right join surface: lookup vs order items

Two tables can answer "what products did this order contain?" but they cover different row sets:

- **`{prefix}wc_order_product_lookup`** — Woo Analytics indexes line items here. Production data confirms this table is **`shop_order`-only**: subscription records (`shop_subscription`) and refunds (`shop_order_refund`) are NOT indexed. Refunds are reachable through `parent_order_id` traversal back to a shop_order, which IS indexed.
- **`{prefix}woocommerce_order_items` + `{prefix}woocommerce_order_itemmeta`** — pre-HPOS line item tables. Populated for every order type (including `shop_subscription`) on both backends. Slightly heavier joins because the product_id lives in itemmeta (`meta_key = '_product_id'`), but it's the only working path for subscription product scoping.

Use the lookup when scoping on `shop_order` / `shop_order_refund` (Tab 6 revenue queries, Tab 7 donation order queries, retention cohorts). Use the order item tables when scoping on `shop_subscription` rows (Tab 6 subscriber counts/MRR/tenure/performance, Tab 7 active recurring donor counts).

### Multi-line-item dedup pattern

A single subscription can have multiple line items. A naive JOIN through `woocommerce_order_items` would produce one row per line item per order, which inflates `SUM()` aggregates (MRR, upcoming-renewal value, retry counts, cancellation reason buckets).

The implementation pattern is to wrap the non-donation product filter in a `DISTINCT order_id` sub-select so each qualifying order contributes exactly once to the outer aggregate:

```sql
SELECT SUM(o.total_amount) AS some_aggregate
FROM {prefix}wc_orders o
JOIN {prefix}wc_orders_meta om
    ON om.order_id = o.id AND om.meta_key = '_schedule_next_payment'
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

For non-aggregate queries (e.g. `COUNT(DISTINCT o.customer_id)`, per-product `GROUP BY`), the direct JOIN through `woocommerce_order_items` + `woocommerce_order_itemmeta` is fine — the outer DISTINCT or GROUP BY handles dedup naturally. Use the wrapped form for `SUM()` aggregates that operate on a per-order field (`total_amount`, etc.).

## Practical SQL: active subscribers (non-donation only)

HPOS:

```sql
SELECT COUNT(DISTINCT o.customer_id) AS active_non_donation_subscribers
FROM {prefix}wc_orders o
JOIN {prefix}woocommerce_order_items oi
    ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
JOIN {prefix}woocommerce_order_itemmeta oim
    ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
WHERE o.type = 'shop_subscription'
  AND o.status = 'wc-active'
  AND oim.meta_value NOT IN (:donation_product_ids);
```

Legacy:

```sql
SELECT COUNT(DISTINCT cust.meta_value) AS active_non_donation_subscribers
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

## Caveats and edge cases

1. **`wc_order_product_lookup` is shop_order-only on production publishers.** Verified against Block Club Chicago (HPOS: 39,461 shop_order rows indexed, 0 shop_subscription, 0 shop_order_refund) and Richland Source (legacy: 13,279 shop_order, 0 shop_subscription, 1 shop_order_refund — likely edge case). Subscription product scoping must use `woocommerce_order_items` + `woocommerce_order_itemmeta` (`meta_key = '_product_id'`) instead. Refunds aren't indexed either, but their `parent_order_id` points to a `shop_order` which IS indexed, so refund classification via parent traversal still works through the lookup table.

2. **HPOS rollout incomplete across the network.** Some publishers on HPOS, some still on legacy. Insights must dispatch dynamically per publisher based on the `woocommerce_custom_orders_table_enabled` option.

3. **Publishers running both donation and non-donation subscriptions are rare.** Most are one or the other. For Insights UI: hide the Subscribers tab entirely when there are zero non-donation subscriptions; hide Donors tab when there are zero donations.

4. **The `_newspack_is_donation` flag is new (v6.41.0, May 2026).** Detection must include both legacy parent/child path AND the new flag path. Don't skip the legacy path even after publishers adopt the flag — existing donation orders predating the flag still need detection.

5. **Refunds.** This doc's queries exclude `wc-refunded` orders, which is correct for "gross donation revenue." For "net revenue," subtract refund line items separately — those live in `{prefix}posts` or `{prefix}wc_orders` with `post_type / type = 'shop_order_refund'`.

6. **Renewal order vs parent subscription.** A `shop_subscription` row is the recurring AGREEMENT. Each renewal is a separate `shop_order` row linked via the `_subscription_renewal` postmeta (or HPOS equivalent). When counting "donations in the last 30 days," you want renewal `shop_order` rows, not the parent `shop_subscription` rows. When counting "active subscribers," you want the parent `shop_subscription` rows.

7. **Variation products.** A `variable-subscription` parent has child `subscription_variation` products. The `_subscription_period` and `_subscription_price` typically live on the variation, not the parent. For variable subscriptions, query variations.

## Refunds

Refunds are stored as separate order records (not as a status on the original order). They link back to the original via `parent_order_id`.

### Refund storage

**HPOS:**
```sql
SELECT id, status, parent_order_id, total_amount, currency, date_created_gmt
FROM {prefix}wc_orders
WHERE type = 'shop_order_refund';
```

**Legacy CPT:**
```sql
SELECT ID, post_status, post_parent AS parent_order_id, post_date_gmt
FROM {prefix}posts
WHERE post_type = 'shop_order_refund';
```

In both backends:
- Refunds always have `status` / `post_status = 'wc-completed'`
- `total_amount` (HPOS) and `_order_total` postmeta (legacy) are stored as **NEGATIVE values** — e.g., `-79.00`. This is critical for net revenue: a plain SUM across order rows including refunds gives correct net.
- `date_created_gmt` / `post_date_gmt` is when the refund was processed, NOT when the original order was placed.

### Refund metadata

Same five Newspack/Woo meta keys in both backends (`{prefix}wc_orders_meta` for HPOS, `{prefix}postmeta` for legacy):

- `_refund_amount` — absolute positive value (e.g., `79.00` for a `-79.00` refund)
- `_refund_type` — `full` or `partial`
- `_refund_reason` — free-text publisher entry (often blank)
- `_refunded_by` — user_id of admin who processed the refund
- `_refunded_payment` — `'1'` if gateway refund was actually executed; empty if manual adjustment only (no money returned to customer)

### Classifying a refund as donation vs non-donation

Refund records do NOT have line items of their own. To classify, trace through `parent_order_id` to the original order, then check that order's product line items:

```sql
SELECT
  r.id AS refund_id,
  r.total_amount AS refund_amount,
  r.parent_order_id,
  -- Classify by original order's products
  CASE
    WHEN EXISTS (
      SELECT 1 FROM {prefix}wc_order_product_lookup opl
      WHERE opl.order_id = r.parent_order_id
        AND opl.product_id IN (:donation_product_ids)
    ) THEN 'donation'
    ELSE 'non_donation'
  END AS revenue_type
FROM {prefix}wc_orders r
WHERE r.type = 'shop_order_refund'
  AND r.date_created_gmt BETWEEN :start AND :end;
```

### Net revenue formula (both backends)

The clean way: include both `shop_order` and `shop_order_refund` rows; sum across `total_amount` (or `_order_total` for legacy); negatives cancel out positives correctly.

**HPOS:**
```sql
SELECT SUM(total_amount) AS net_revenue
FROM {prefix}wc_orders
WHERE type IN ('shop_order', 'shop_order_refund')
  AND status IN ('wc-completed', 'wc-processing')
  AND date_created_gmt BETWEEN :start AND :end
  AND id IN (
    -- Donation orders + their parent orders (for refunds)
    SELECT DISTINCT order_id
    FROM {prefix}wc_order_product_lookup
    WHERE product_id IN (:donation_product_ids)
    UNION
    SELECT DISTINCT r.id
    FROM {prefix}wc_orders r
    JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = r.parent_order_id
    WHERE r.type = 'shop_order_refund'
      AND opl.product_id IN (:donation_product_ids)
  );
```

**Legacy:**
```sql
SELECT SUM(CAST(pm.meta_value AS DECIMAL(15,2))) AS net_revenue
FROM {prefix}posts p
JOIN {prefix}postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
WHERE p.post_type IN ('shop_order', 'shop_order_refund')
  AND p.post_status IN ('wc-completed', 'wc-processing')
  AND p.post_date_gmt BETWEEN :start AND :end
  AND (
    -- shop_order rows that contain donation products
    p.ID IN (
      SELECT DISTINCT order_id FROM {prefix}wc_order_product_lookup
      WHERE product_id IN (:donation_product_ids)
    )
    -- OR shop_order_refund rows whose parent contains donation products
    OR p.post_parent IN (
      SELECT DISTINCT order_id FROM {prefix}wc_order_product_lookup
      WHERE product_id IN (:donation_product_ids)
    )
  );
```

### Insights metrics derived from refunds

For Tab 6 (Subscribers) and Tab 7 (Donors), these become natural scorecards:

- **Refund rate (count)**: refund records / total orders in window
- **Refund rate (revenue)**: ABS(refund_amount sum) / gross revenue sum
- **Average refund**: ABS(refund_amount sum) / refund count
- **Time to refund**: BoxPlot of (refund date - original order date), in days
- **Refund reasons (when populated)**: PieChart of `_refund_reason` values

### Caveats specific to refunds

1. **Refund date vs original date is a real distinction.** "Donation revenue in May" means orders placed in May summed gross. "Net donation revenue in May" means (orders placed in May) + (refunds processed in May) — and those refunds may be against orders placed months earlier. Be explicit in metric definitions which date is the window anchor.

2. **`_refunded_payment` matters for accuracy.** Manual refunds (no gateway action) might be data corrections, accounting adjustments, or operational mistakes — they reduce reported revenue but don't reflect actual money flowing back to the customer. Insights v1 should default to including them in net revenue but flag the option to exclude.

3. **Publisher-created subscription products treated as donations aren't caught here.** Per the donation detection section: products outside the canonical Newspack donation family + the v6.41.0 flag are classified as subscriptions, even when the publisher considers them donations (e.g., Block Club Chicago's "Ambassador" tier). Refunds against those products will appear in Subscribers refund metrics, not Donors. Publishers should use the `_newspack_is_donation` flag (v6.41.0+) to reclassify.

4. **Subscription cancellations are NOT refunds.** A subscriber cancelling future renewals doesn't generate a refund record — it just stops future `shop_order` rows. Refunds happen separately when an admin processes a refund through the order detail page. Cancellation metrics live in subscription status counts; refund metrics live in refund order rows. Don't confuse them.

## Sources

- `includes/class-donations.php` (newspack-plugin trunk, June 2026 snapshot)
- `includes/plugins/woocommerce/class-woocommerce-products.php` (donation flag meta key definition)
- Production data on Block Club Chicago (blockclubchicago.org, HPOS, multisite blog 5)
- Production data on Richland Source (richlandsource.com, legacy CPT, single-site)