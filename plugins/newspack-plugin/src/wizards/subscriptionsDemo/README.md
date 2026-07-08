> This folder is the subscriptions/commerce twin of `../subscribersDemo`. It shares that demo's mock dataset (people and plans are re-exported from `../subscribersDemo/data`) and layers on only the subscription-commerce surface.

# Subscriptions Demo

A design prototype for subscription and subscriber-discount management, shipped for
internal review. It is a **hidden wizard** (not in the admin menu) with mock data,
reachable at `admin.php?page=newspack-subscriptions-demo` **when the site is in
Newspack debug mode**.

Two tabs, both full-width DataViews lists. There is **no subscription detail
view** — the list is the overview, and per-row actions do the rest (an earlier
full-page/drawer detail was explored and dropped; see the design notes).

- **Subscriptions** (`/`, `screens/SubscriptionList.jsx`) — one row per
  subscription (digital, print, team): Status, Price, Billing, Subscribers,
  Total Sales, Total Revenue. Each row's kebab offers:
  - **View subscribers** — deep-links to the Subscribers demo, pre-filtered to
    this subscription (`admin.php?page=newspack-subscribers-demo#/?subscription=…`).
  - **Add subscriber discount** — opens the discount editor pre-scoped to this
    subscription (audience locked, picker hidden).
  - **View in WooCommerce** — opens the products screen, pre-searched by name.
- **Subscriber discounts** (`/discounts`, `screens/DiscountList.jsx`) — one row
  per discount rule: which subscription's subscribers get what off which store
  products. Subscription, Discount, Applies to, Status, Created. Row actions
  (Edit / Pause·Resume / Delete) and header actions (Add discount, Settings).

## The discount model

A subscriber discount says *"subscribers of subscription **X** get $/% off store
products **Y**."* Its **audience** is a subscription (a WooCommerce subscription
product); its **target** is a set of store products (all products / a category /
specific products, with exclusions). In a real build the membership oracle is
`Access_Rules::has_active_subscription( $user_id, $product_ids )` and the price is
applied via WooCommerce price filters.

## Structure

```
subscriptionsDemo/
├── index.js                Entry point: mounts the Wizard with the two tabbed sections.
├── screens/
│   ├── SubscriptionList.jsx  Subscriptions list + row actions.
│   ├── DiscountList.jsx      Subscriber-discounts list.
│   └── style.scss
├── flows/                  Modals (all drawer/dialog chrome shared via __sub-detail-*).
│   ├── DiscountRuleFlow.jsx     The discount editor (right-edge drawer).
│   ├── DiscountSettingsFlow.jsx Global discount settings.
│   └── ConfirmFlow.jsx          Shared confirm scaffold (pause/resume/delete).
├── data/                   The prototype↔production seam (see below).
├── format.js               Currency / date formatting.
└── labels.js               Publisher-configurable group label.
```

The PHP side is a single class,
`includes/wizards/class-subscriptions-demo.php`: the wizard scaffold, debug-mode
gating, localized config, and the `POST …/avatars` endpoint.

## Data (`data/`)

People and plan data have a **single source of truth: the Subscribers demo.**

| File | Role |
|------|------|
| `mock-subscribers.js` | Re-exports `SUBSCRIBERS` and the plan definitions from `../../subscribersDemo/data/mock-subscribers`, layering on the plan-level commercial extras (`status`, `totalSales`, `totalRevenue`). |
| `mock-groups.js` | Re-exports the Subscribers demo's group dataset, layering the same extras onto `TEAM_PLANS`. |
| `mock-discounts.js` | Subscriber-discount rules + presentation helpers (`discountLabel`, `targetingLabel`, `subscriberPrice`, …). Specific to this demo. |
| `mock-catalog.js` | The store products/categories a discount can target. Specific to this demo. |
| `plan-stats.js` | Pure roll-ups over the shared stores into per-subscription figures (`getAllPlans`, `getPlanStats`, `getSubscribersForPlan`). |
| `storage.js` | `localStorage` persistence for demo edits (discount rules/settings). |

Because the people/plan records are shared, a subscription's subscriber count
here matches the Subscribers demo list that "View subscribers" links into.

## Known prototype shortcuts

- **`localStorage` persistence** is per-browser; a `DATA_VERSION` bump in
  `data/storage.js` wipes it (no migration).
- `@wordpress/components` is externalized to `wp.components` at runtime, so verify
  any newer export against the live bundle, not just the build.
- DataViews sorts filters alphabetically by label; there is no per-filter order
  knob short of `isPrimary` (which also pins a filter always-visible).
