# Add Subscription Flow — Design

Date: 2026-06-18
Prototype: `src/wizards/subscribersDemo/`
Status: approved, ready for implementation plan

## Goal

Give an admin the ability to add a subscription to any reader, choosing how
payment is collected: grant it free, send a payment link the reader pays
themselves, or charge a card on their behalf. The catalog spans every product
type (individual digital, individual print, group/team), and the flow adapts its
follow-up steps to what was selected.

The three payment modes already exist in fragments inside `ResubscribeFlow`
(`sendLink`, the `comped` PlanPicker, charge-now when a card is on file). This
work generalizes that flow into a single reusable one and rounds out the gaps.

## Guiding principle: one shared flow

Consistency is the driver. Rather than maintaining the new entry point and the
existing resubscribe path in parallel, `ResubscribeFlow` is **absorbed** into a
single `flows/AddSubscriptionFlow.jsx`. Every trigger mounts the same component
with props that pre-scope it. The card picker, the comp-for-N-cycles control, the
pending-link row, the custom price, and the notify toggle then live in exactly
one place and cannot drift between entry points.

### Entry points

| Trigger | Behavior |
|---------|----------|
| **New** — "Add subscription" button in the `PersonProfile` Subscriptions section header | Opens the flow unscoped, available in any reader state (so a second plan can be added alongside an existing one). |
| **Existing** — cancelled subscription card "Resubscribe" | Opens the flow pre-seeded with the prior plan. |
| **Existing** — profile empty-state "Resubscribe" | Opens the flow pre-seeded with the prior plan. |
| **Existing** — group on-hold/cancelled "Resubscribe" | Group restore path, unchanged in behavior, routed through the same component. |

`ResubscribeFlow` is removed as a standalone file; its call sites import
`AddSubscriptionFlow` with the appropriate props.

## Step 1 — catalog picker

A unified picker over the full catalog, grouped by type:

- `DIGITAL_PLANS`, `PRINT_PLANS` — individual plans.
- `TEAM_PLANS` — group/team plans.

Branching by selection:

- **Individual plan** continues directly to Step 2.
- **Group plan** inserts a seat-setup sub-step first: this reader becomes the
  group owner, and the admin sets the seat limit. Then continues to Step 2.
  Group payment is billed to the owner (this reader), consistent with how groups
  already work.

This step also carries:

- **Custom price field**, defaulted to the catalog amount and editable. It is the
  discount/override mechanism and feeds whichever payment mode follows (the
  charge amount, the link amount, and the post-comp conversion amount).
- **Duplicate-plan guard**: if the reader already holds an active subscription in
  the same family, an inline warning appears before the admin can confirm. It
  warns, it does not block.

## Step 2 — payment mode

Three choices, each fully specified:

### 1. Free

A "Free for `[N]` cycles" control:

- Set to ongoing: today's comp behavior is preserved (no billing ever,
  `nextBillingDate` null).
- Set to N cycles: free for N cycles, then converts to paid at the chosen price.

The existing grant-free-access path gets this same control, so both the new flow
and the resubscribe path behave identically.

**Conversion-with-no-card wrinkle.** "Free for N cycles then converts" needs a
card to convert against. If no card is on file when the free cycles end, the
subscription flips to the existing on-hold / payment-link dunning state rather
than silently failing. This reuses the dunning path the prototype already has;
no new state is invented for it.

### 2. Send payment link

The reader pays themselves, so the subscription does not exist yet. This writes a
real pending state in two places:

- A **pending subscription card** in the Subscriptions section: status
  `pending`, "link sent" label with a relative timestamp, and resend/cancel
  actions. Resolves to active when paid. Since the prototype has no real
  payment, the card carries a **"Mark as paid"** affordance that simulates the
  reader completing checkout: it flips the card to active and logs the payment
  to Billing history.
- A **"Payment link sent"** entry in Billing history (no amount), alongside the
  existing non-payment events ("Joined", "Reactivated").

`ResubscribeFlow`'s former fire-and-forget send-link path is rewired to this same
mechanism. No more snackbar-and-forget.

### 3. Charge now

Card picker with smart defaulting:

- Default card pre-selected.
- Single card auto-selects.
- Multiple cards: admin chooses.
- No card on file: branches into `PaymentUpdateFlow` to enter one on the reader's
  behalf, then charges.

Logs a real payment to Billing history.

### Notify toggle (all three modes)

A "Send a confirmation email" toggle on the confirm step, mirroring
`AddMembersFlow`'s welcome-email switch. Lets the admin add quietly or notify.

## Data model additions

All additive, so the L0 lists and existing cards keep rendering unchanged.

- Subscription gains `status: 'pending'` (link sent) and a free-cycles field
  (e.g. `freeCyclesRemaining`, plus the convert-to amount). The existing `amount`
  field absorbs the custom price; no new field needed for the override.
- Billing-history event vocabulary gains `"Payment link sent"` (no amount).

Per the prototype's data-shape-fidelity rule, these new fields are documented here
so the production REST responses can match them exactly.

## Reuse vs. new

**Reuses as-is:** `PaymentUpdateFlow`, billing-history rendering, the
snackbar/notice conventions, and the `onComplete({ message, mutate })` contract.

**New:** the `AddSubscriptionFlow` shell (absorbing `ResubscribeFlow`), the
unified catalog picker, the group seat-setup sub-step, the pending-subscription
card rendering, the free-for-N-cycles control, and the card-picker step.

## Out of scope (YAGNI)

- Scheduling a paid subscription to start on a future calendar date (the
  free-cycles control covers the "delay billing" need).
- Coupon-code objects. The custom price field covers ad-hoc discounts; reusable
  coupon products are a production-catalog concern, not a prototype one.
- Partial refunds or proration on add (handled by the existing `RefundFlow` /
  `PlanChangeFlow`).

## Productionizing notes

This stays inside the prototype's existing seam: the screens and the flow read
and write only through `data/`. In production the pending-subscription state, the
free-cycles conversion, and the charge all become REST writes under the wizard's
`wizard/newspack-subscribers-demo/…` namespace, backed by WooCommerce
products/subscriptions and the payment gateway. The flow's UI does not change; it
is the `data/` layer that is rebuilt, consistent with the README's reuse split.
