# Plans

DataViews admin page under **Audience → Plans** (page slug
`newspack-audience-subscription-products`; the directory keeps its historical
`subscription-products` name). Gated behind the `NEWSPACK_PLANS_UI` feature flag and
WooCommerce Subscriptions availability. Lists, creates, and edits subscription products in
a consolidated, productized model — the list is subscription-centric but also pulls in
**one-time donation** products. Part of the Reader Subscription Management (RSM) work.

## What it does

Lists WooCommerce Subscriptions products in a consolidated, productized model:
name, type, base price + billing period, active subscription count, category,
status. Table + grid, search, filters (type / status / category), client-side
`filterSortAndPaginate`, row action → edit modal.

## Grouping chip-bar: Subscriptions / Donations / All

A top-level chip-bar (modeled on the transactional-emails chip pattern, not a
DataViews column filter) scopes the list by **donation vs non-donation**, using the
canonical `Donations::is_donation_product()` (the "designate as donation" product
checkbox → `_newspack_is_donation` meta, with variation inheritance + legacy
products). Defaults to **Subscriptions** (non-donation) so membership tiers lead;
**Donations** and **All** are one click away. Chips show per-group counts. Donation
vs subscription is an *orthogonal* flag — it cross-cuts cadence (a donation can be
recurring or one-time), so it's a scope chip, not a type. Cadence (recurring vs
one-time) and a possible Merch class are deliberately deferred (Phase 2/3).

## Naming: "Availability" vs "Unlocks" (and the Access-control collision)

"Access" is overloaded in this area — **Access control** is the separate Newspack
content-gating feature (a sibling Audience page; data model "access rules"). So we
split the concept into two deliberately-distinct columns:

- **Availability** (Public / Private / Free) — how the *plan itself* is offered. A
  property of the product. (Renamed from "Access" to avoid the collision.)
- **Unlocks** — which content *gates* this product satisfies. A reverse lookup into the
  Access control feature: each `np_content_gate`'s `custom_access.access_rules` has a
  `subscription` rule listing product IDs; `get_product_gate_map()` inverts that so each
  product row shows the gates it unlocks (chips linking to the gate). Named "Unlocks",
  not "Access", to stay clearly distinct from "Access control" while relating to it.

This pairing is the cross-feature tie-in the RSM model wants: one view shows both *how a
plan is sold* and *what buying it grants*.

## Availability (derived) — and why Category is demoted

We checked production: how do real publishers use `product_cat` on subscription
products? Across 6 Newspack publishers:

| Publisher | Category use on subscription products |
|---|---|
| Lookout | Heavy — `Private Subscriptions` (19) · `Subscriptions` (8) · `Free Subscriptions` (5) |
| Richland Source | Light — `Private subscriptions` (1), rest uncategorized |
| Block Club, MinnPost, Sahan Journal | None (all uncategorized) |

Takeaway: Category is uncategorized noise for the majority, so it's **demoted** — kept
as a *hideable, off-by-default* column and still a filter, but not shown by default.
Where it *is* used, it encodes **access tier** (Private / Free / Public), with the
exact label `Private subscriptions` recurring across two unrelated publishers.

So we surface that signal directly as a derived **Availability** column (`derive_availability()`):
- `free` — base price is 0, or a category name contains "free".
- `private` — a category name contains "private" (the explicit convention).
- `public` — otherwise.

Deliberately NOT derived from `catalog_visibility = hidden`: Newspack hides
donation/RAS products from the catalog for unrelated reasons, so that signal is too
noisy (it false-flagged the Donate products as private). This is heuristic — a
placeholder. See the PRD note: availability/entitlement wants to be a first-class typed
field, not inferred from category strings.

Because it's derived and mostly "Public" for most publishers, Availability ships
**off by default** — defined as a filter and a toggleable column, but not in the default
column set. The default columns are hard facts (price, active subs, status) plus the RSM
differentiator (unlocks); `type`, `category`, and `availability` all live behind the
column picker.

## Creating & editing (no WooCommerce needed)

Add/Edit is a **full-page routed wizard section**, mirroring the institutions editor:
- `index.tsx` registers routes — `/` (list), `/new` (add), `/edit/:id` (edit).
- `product-edit.tsx` is the route wrapper: it loads context (the product to edit, available
  categories, bundle candidates, currency) by reusing the list endpoint, then renders
  `product-form.tsx` once ready.
- `product-form.tsx` is the full-page form — a 2-column `Grid` (SectionHeader left, fields
  right) with `Divider`s. **Save / back live in the wizard header** (set via `setHeaderData`,
  not inline buttons), exactly like `InstitutionEdit`. It `POST`s to create or `PUT`s to
  update, shows a success snackbar, then `history.push('/')` back to the list (which refetches
  on mount). The list's **Add plan** action is an `href` to `#/new`; the row **Edit** action
  and the product-name link `history.push('/edit/{id}')`. It covers all three types, plus a
category picker (`FormTokenField` over `available_categories`), an **Availability** select
(public/private/free → maps to the convention category, since availability is derived not
stored), group subscription, and donation. On edit it also shows the read-only Unlocks
context.

Constraints (deliberate):
- **Type is locked on edit** — changing a live product's WC type is unsafe.
- **Variable plans**: edit price/period/interval, add plans, remove plans. Existing plan
  **labels are read-only** (renaming a WC variation attribute mid-flight is finicky); new
  plans get a label. `sync_variable_variations()` diffs by variation id (update / create /
  delete).
- Group subscription is one product-level value applied to all plans (the model supports
  per-variation; the form simplifies).
- Same WC cache caveats as create: explicit `product_type` term after save; the endpoint
  returns `{id,name}` and the list refetches (memcached can't surface the change same-request).

## Grouped "Plan groups" and group subscriptions

The page covers the full Newspack subscription model, not just flat products:

- **Grouped products ("Plan group")** — WooCommerce `grouped` products that bundle
  subscriptions to define a plan-**switching** set (Block Club's "Plan Options"). They're
  surfaced as a `grouped` row type; the list includes a grouped product only when it
  bundles ≥1 subscription child (`group_has_subscription_children`). A grouped product
  isn't priced itself, so its Price cell shows the **bundled plan chips**, and
  `active_subscriptions` **aggregates** (deduped) across the bundled children.
  Create: the Add modal's "Plan group (switching)" type offers a checkbox picker of
  existing subscriptions → `WC_Product_Grouped::set_children()`.
- **Group subscriptions (multi-seat)** — the `_newspack_group_subscription_enabled` /
  `_limit` product meta from the content-gate group-subscription feature. Surfaced as an
  off-by-default **"Members"** column ("Up to 25 members" / "Unlimited") + an edit-modal
  row, and creatable via a toggle + limit in the Add modal. The setting lives on
  `subscription` and `subscription_variation`, so for variable subs it's **per-variation**
  (the row summary collapses to a shared value or "Varies"); the create form applies one
  value across all plans for simplicity.

## Product-type filter (confirmed)

The REST query (`class-audience-subscription-products.php :: api_get_products`)
targets:

```php
'type' => [ 'subscription', 'variable-subscription', 'grouped' ]
```

`grouped` is included **only** when it bundles subscription children (see Plan groups
above). Plain `simple` products are excluded **except** one-time donations, which are
pulled in separately via the Donations API (`get_simple_donation_products()` — unions the
`_newspack_is_donation` meta with the legacy parent/child donation IDs, since one-time
donations like "Donate: One-Time" are legacy-detected, not meta-flagged). One-time rows
show type "One-time", a one-time price (no period), and `—` active subs; they're editable
(price only). `variable-subscription` is the **primary** path — it's how Block Club
Chicago (flagship) models membership tiers.

## Status handling (confirmed)

- The query requests `[ publish, private, draft, pending ]` — **`trash` is never
  fetched**, so trashed/retired products never appear.
- The default DataViews view filters to **`status is publish`**, so "(TEST COPY)"
  drafts and hidden strategy products don't clutter the default list.
- `draft` / `private` / `pending` remain reachable behind the **Status filter**
  (their elements are derived from the loaded data).

## Staging seed (both production shapes)

Two real publishers anchor the seed:

- **Block Club Chicago** — membership tiers as `variable-subscription` (Monthly /
  Annual variations).
- **Lookout** — a sprawl of `simple` subscriptions, one per price point / segment /
  intro offer, plus status noise (TEST COPY, Copy, draft, trash).

Run on staging (idempotent — tagged `_np_rsm_seed`, re-running cleans up; also
removes the old malformed Ambassador/Captain/Boss fixtures):

```bash
# Save the block below as rsm-seed.php inside the plugin dir, then:
./n wp eval-file /newspack-plugins/newspack-plugin/rsm-seed.php
# (delete the file afterwards — it's a dev tool, not product code)
```

<details>
<summary>rsm-seed.php</summary>

```php
<?php
// RSM Subscription Products — dev/staging seed. Seeds Block-Club-style variable-sub
// tiers AND Lookout-style simple-sub sprawl (+ status noise + active-sub dedup case).
// Idempotent via meta _np_rsm_seed. Run: wp eval-file rsm-seed.php
if ( ! function_exists( 'wc_get_products' ) || ! class_exists( 'WC_Subscriptions' ) ) {
	echo "WooCommerce Subscriptions not active — aborting.\n"; return;
}
$seeded = get_posts( [ 'post_type' => [ 'product', 'product_variation', 'shop_subscription' ], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_np_rsm_seed', 'meta_value' => '1' ] );
foreach ( $seeded as $id ) { wp_delete_post( $id, true ); }
foreach ( [ 319, 322, 325, 320, 321, 323, 324, 326, 327 ] as $id ) { if ( get_post( $id ) ) { wp_delete_post( $id, true ); } }
echo 'Removed ' . count( $seeded ) . " seeded posts + old fixtures.\n";

$cat = get_term_by( 'slug', 'memberships', 'product_cat' );
$cat_id = $cat ? $cat->term_id : ( wp_insert_term( 'Memberships', 'product_cat', [ 'slug' => 'memberships' ] )['term_id'] ?? 0 );
$cat_ids = $cat_id ? [ $cat_id ] : [];

$make_simple = function ( $name, $price, $period, $status, $cat_ids ) {
	$p = class_exists( 'WC_Product_Subscription' ) ? new WC_Product_Subscription() : new WC_Product_Simple();
	$p->set_name( $name ); $p->set_status( $status ); $p->set_catalog_visibility( 'visible' );
	$p->set_regular_price( (string) $price ); $p->set_price( (string) $price );
	if ( $cat_ids ) { $p->set_category_ids( $cat_ids ); }
	$p->update_meta_data( '_subscription_price', $price ); $p->update_meta_data( '_subscription_period', $period );
	$p->update_meta_data( '_subscription_period_interval', 1 ); $p->update_meta_data( '_subscription_length', 0 );
	$p->update_meta_data( '_np_rsm_seed', 1 ); $id = $p->save();
	wp_set_object_terms( $id, 'subscription', 'product_type' ); return $id;
};
$make_variable = function ( $name, $monthly, $annual, $cat_ids ) {
	$p = new WC_Product_Variable_Subscription(); $p->set_name( $name ); $p->set_status( 'publish' );
	$p->set_catalog_visibility( 'visible' ); if ( $cat_ids ) { $p->set_category_ids( $cat_ids ); }
	$attr = new WC_Product_Attribute(); $attr->set_name( 'Billing period' ); $attr->set_options( [ 'Monthly', 'Annual' ] );
	$attr->set_visible( true ); $attr->set_variation( true ); $p->set_attributes( [ $attr ] );
	$p->update_meta_data( '_np_rsm_seed', 1 ); $parent_id = $p->save(); $vids = [];
	foreach ( [ [ 'Monthly', 'month', $monthly ], [ 'Annual', 'year', $annual ] ] as $v ) {
		$var = new WC_Product_Variation(); $var->set_parent_id( $parent_id ); $var->set_attributes( [ 'billing-period' => $v[0] ] );
		$var->set_status( 'publish' ); $var->set_regular_price( (string) $v[2] );
		$var->update_meta_data( '_subscription_price', $v[2] ); $var->update_meta_data( '_subscription_period', $v[1] );
		$var->update_meta_data( '_subscription_period_interval', 1 ); $var->update_meta_data( '_subscription_length', 0 );
		$var->update_meta_data( '_np_rsm_seed', 1 ); $vids[] = $var->save();
	}
	if ( class_exists( 'WC_Product_Variable_Subscription' ) ) { WC_Product_Variable_Subscription::sync( $parent_id ); }
	return [ $parent_id, $vids ];
};

$tiers = [ [ 'Neighbor', 5, 50 ], [ 'Ambassador', 10, 79 ], [ 'Captain', 12, 100 ], [ 'Boss', 25, 250 ] ];
$tr = [];
foreach ( $tiers as $t ) { $tr[ $t[0] ] = $make_variable( $t[0], $t[1], $t[2], $cat_ids ); }
echo 'Created ' . count( $tr ) . " variable-sub tiers.\n";

foreach ( [ 5,8,10,12,15,20,25,30,40,50,75,100 ] as $price ) { $make_simple( '$' . $price . ' / month', $price, 'month', 'publish', $cat_ids ); }
foreach ( [ 50,80,100,150,200,500 ] as $price ) { $make_simple( '$' . $price . ' / year', $price, 'year', 'publish', $cat_ids ); }
foreach ( [ [ 'Student', 5 ], [ 'Teacher', 8 ], [ 'Champion', 50 ], [ 'One-for-One', 20 ] ] as $s ) { $make_simple( $s[0] . ' Membership', $s[1], 'month', 'publish', $cat_ids ); }
$make_simple( 'First Year Special', 30, 'year', 'publish', $cat_ids );
$make_simple( 'Intro Offer — First Month', 1, 'month', 'publish', $cat_ids );
$make_simple( '$10 / month (TEST COPY)', 10, 'month', 'publish', $cat_ids );
$make_simple( '$10 / month (Copy)', 10, 'month', 'publish', $cat_ids );
$make_simple( 'Draft Tier — do not show', 18, 'month', 'draft', $cat_ids );
$make_simple( 'Legacy Offering (private)', 9, 'month', 'private', $cat_ids );
wp_trash_post( $make_simple( 'Retired $7 / month', 7, 'month', 'publish', $cat_ids ) );

// Access-tier demos — mirrors the Private/Free subscription convention at Lookout/Richland.
$pc = get_term_by( 'slug', 'private-subscriptions', 'product_cat' ) ?: wp_insert_term( 'Private Subscriptions', 'product_cat', [ 'slug' => 'private-subscriptions' ] );
$fc = get_term_by( 'slug', 'free-subscriptions', 'product_cat' ) ?: wp_insert_term( 'Free Subscriptions', 'product_cat', [ 'slug' => 'free-subscriptions' ] );
$pid = is_object( $pc ) ? $pc->term_id : ( $pc['term_id'] ?? 0 );
$fid = is_object( $fc ) ? $fc->term_id : ( $fc['term_id'] ?? 0 );
$make_simple( 'Legacy Insider (private)', 15, 'month', 'publish', array_filter( [ $pid ] ) ); // → private
$make_simple( 'Comped Member', 0, 'month', 'publish', array_filter( [ $fid ] ) );            // → free
$make_simple( 'Community Free Pass', 0, 'year', 'publish', array_filter( [ $fid ] ) );        // → free
echo "Created simple-sub sprawl + status noise + access-tier demos.\n";

// "Unlocks" demo — content gates that require a subscription to the tiers.
if ( class_exists( 'Newspack\\Content_Gate' ) ) {
	$prev_gates = get_posts( [ 'post_type' => 'np_content_gate', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_np_rsm_gate_seed', 'meta_value' => '1' ] );
	foreach ( $prev_gates as $g ) { wp_delete_post( $g, true ); }
	$tier_id = function ( $name ) { $i = wc_get_products( [ 'name' => $name, 'type' => 'variable-subscription', 'limit' => 1, 'return' => 'ids' ] ); return $i ? (int) $i[0] : 0; };
	$mk_gate = function ( $title, $pids ) {
		$gid = wp_insert_post( [ 'post_type' => 'np_content_gate', 'post_status' => 'publish', 'post_title' => $title ] );
		update_post_meta( $gid, '_np_rsm_gate_seed', 1 );
		\Newspack\Content_Gate::update_custom_access_settings( $gid, [ 'active' => true, 'access_rules' => [ [ [ 'slug' => 'subscription', 'value' => array_values( array_filter( $pids ) ) ] ] ], 'metering' => [ 'enabled' => false, 'count' => 1, 'period' => 'month' ], 'gate_layout_id' => 0 ] );
	};
	$mk_gate( 'Premium Articles', [ $tier_id( 'Ambassador' ), $tier_id( 'Captain' ), $tier_id( 'Boss' ) ] );
	$mk_gate( 'Subscriber Archive', [ $tier_id( 'Boss' ) ] );
	echo "Created 2 content gates for the Unlocks column.\n";
}

$customers = get_users( [ 'number' => 2, 'role__in' => [ 'customer', 'subscriber', 'administrator' ], 'fields' => 'ID' ] );
list( $amb_parent, $amb_v ) = $tr['Ambassador'];
if ( function_exists( 'wcs_create_subscription' ) && $customers && count( $amb_v ) === 2 ) {
	foreach ( [ [ $amb_v[0], 'month', $customers[0] ], [ $amb_v[1], 'year', $customers[ count( $customers ) > 1 ? 1 : 0 ] ] ] as $pair ) {
		$variation = wc_get_product( $pair[0] ); if ( ! $variation ) { continue; }
		$sub = wcs_create_subscription( [ 'status' => 'pending', 'billing_period' => $pair[1], 'billing_interval' => 1, 'customer_id' => $pair[2] ] );
		if ( is_wp_error( $sub ) ) { continue; }
		$sub->add_product( $variation, 1 ); $sub->update_meta_data( '_np_rsm_seed', 1 );
		$sub->calculate_totals(); $sub->update_status( 'active' ); $sub->save();
	}
}
echo "Seed complete.\n";
```

</details>

## PRD evidence — product-shaped pricing workarounds

Both reference publishers express **pricing strategy and plan grouping as product
structures today** — exactly the workaround a policy engine replaces:

**Block Club Chicago** (variable-sub tier model):
- Tiers (Neighbor, Ambassador, Captain, Boss, Gift Membership) are all
  `variable-subscription` with Monthly/Annual variations.
- Pricing strategies are encoded as **private** `variable-subscription` products
  named "Annual Discounts" / "Legacy Offerings", plus a **grouped** "Plan Options"
  product. → product-shaped strategy + grouping.

**Lookout** (simple-sub-dominated model):
- ~33 `simple` subscription products (one per price point + segment) plus a single
  `variable-subscription` ("Lookout Membership").
- **Intro pricing** is separate "First Year" SKUs; **segment pricing**
  (Student / Teacher / Champion / One-for-One) is separate SKUs. → product-shaped
  stepped pricing + segment scope.

**Access tier as product_cat** (Lookout + Richland Source):
- Both encode subscription access by bending `product_cat` into `Private subscriptions`
  / `Free subscriptions` — the same labels, on two unrelated sites. Lookout uses it at
  scale (19 private / 5 free); Richland lightly (1 private). The other 4 sampled
  publishers don't categorize subscription products at all.
- → Access / entitlement (public vs comped/free vs private/hidden) is a recurring
  signal publishers reach for but have no first-class field for. It should be a typed
  attribute the consolidated model + entitlement layer own — not a category string.
  (The page's derived "Access" column is a placeholder demonstrating the normalized
  signal.)

Implication for the PRD: stepped/intro pricing, segment scoping, plan grouping, AND
access tier are all currently SKU/category sprawl. The policy + entitlement layers plus
this consolidated view collapse that sprawl into one product with overlaid, resolvable
policies and typed attributes.
