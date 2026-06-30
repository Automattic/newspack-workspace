# Subscribers Demo

A design prototype for a people-first subscriber management experience, shipped
for internal review. It is a **hidden wizard** (not in the admin menu) with mock
data, so reviewers can explore the full flow without a populated WooCommerce
store. Reachable at `admin.php?page=newspack-subscribers-demo` **when the site
is in Newspack debug mode**.

It is a prototype: there is no real backend. All data is mocked and edits persist
to `localStorage`. This README explains how it is structured and, importantly,
**what a developer would reuse vs. rebuild** to turn it into a working wizard.

## Structure

```
subscribersDemo/
├── index.js              Entry point: mounts the Wizard with routed sections.
├── screens/              The four views (presentation).
│   ├── SubscriberList.jsx    L0 list (DataViews).
│   ├── GroupList.jsx         L0 group list (DataViews).
│   ├── PersonProfile.jsx     L1 subscriber profile.
│   ├── GroupDetail.jsx       L1 group detail.
│   ├── SubscriberNotices.jsx Shared copy + render for the header notices strip.
│   └── style.scss
├── flows/                Modal actions (refund, plan change, invite, …) + ConfirmFlow.
│   ├── free-access.jsx       Shared building blocks: ModeRadio, ChargeNote,
│   │                         PaymentLinkNote, FreeAccessFields, useFreeAccess,
│   │                         paymentModeOptions, isCardExpired, hasUsableCard.
│   └── steps.jsx             Shared two-step scaffolding: useTwoStep, StepButtons.
├── data/                 ← the entire data-access layer (see below).
├── format.js             Currency, date, and relative-time formatting.
├── status.js             Subscription-status labels, badge levels, display reducer.
├── labels.js             Publisher-configurable group label (from localized config).
└── use-portals.js        DOM-portal hooks (full-width notices, header avatar).
```

The PHP side is a single class, `includes/wizards/class-subscribers-demo.php`:
the wizard scaffold, debug-mode gating, localized config (group label, currency,
avatar setting), and the one **real** REST endpoint (`POST …/avatars`).

## Reusing this as a real wizard

The split is clean and deliberate: **everything outside `data/` is presentation
and reuses as is** (screens, flows, formatting, styles, the PHP scaffold).
**Everything inside `data/` is the prototype↔production seam** and gets rewritten
against real REST endpoints. The screens and flows never call a backend directly;
every read and write goes through a function exported from `data/`.

Rough estimate: ~70% of the code (views, flows, utils, PHP, avatars) carries over;
~30% (the `data/` layer and the call sites that import it) is rebuilt.

### Staying 1:1 (do not rebuild the UI)

The look and the flows are meant to ship **identical** to this prototype. The way
to guarantee that is to **not rebuild the presentation layer at all**: reuse
`screens/`, `flows/`, `style.scss`, and the component choices verbatim, and swap
only `data/`. Fidelity is then an architectural property, not something to
re-verify pixel by pixel.

The one thing that can break 1:1 is **data-shape drift**. The reused components
render whatever `data/` returns, so the real REST responses must match the
[data model](#data-model) exactly (same fields, same status vocabulary,
`amount` as a number, dates as `YYYY-MM-DD`, etc.). If a real field is missing or
shaped differently, adapt it in the data hook so the components keep receiving the
documented shape, rather than editing the screens. The catalog below records the
screens' visual states and each flow's steps so behavior can be checked against
the rebuild.

### The data layer (`data/`)

| File | Role | Prototype vs. production |
|------|------|--------------------------|
| `data/mock-subscribers.js` | Seeds `SUBSCRIBERS` (6 fixtures + 80 deterministic randoms); exports subscriber reads/writes, plans, tags, newsletters. | **Mock.** Replace the seed + the `getStored*`/`setStored*` functions with REST calls. |
| `data/mock-groups.js` | Seeds `GROUPS`; exports group reads/writes plus seat/invite logic (`seatsUsed`, `inviteCapacity`, `isGroupActive`, …). | **Mock data, real logic.** The seat/invite helpers are pure and worth keeping; the seed and `getStored*`/`getAllGroups` get rewritten. |
| `data/storage.js` | `localStorage` persistence (`readStore`, `writeStore`, `purgeStaleStorage`) so demo edits survive a refresh. | **Mock.** Delete in production; persistence moves server-side. |
| `data/use-avatars.js` | `useAvatars` hook fetching the `/avatars` REST endpoint, keyed by email. | **Real.** Already the production pattern, and the template for every other data hook. |

The plan/newsletter/tag constants (`DIGITAL_PLANS`, `PRINT_PLANS`, `TEAM_PLANS`,
`NEWSLETTERS`, `KNOWN_TAGS`) are seed literals that in production come from
WooCommerce products and the newsletter/ESP integration.

### How the screens/flows consume `data/`

**Reads** are direct function calls / array imports: `getSubscriberById(id)`,
`getStoredSubscriber(id)`, `SUBSCRIBERS`, `getAllGroups()`, `getGroupById(id)`,
`getGroupsForSubscriber(id)`, `getStoredNotes/Tags/Newsletters(id)`,
`NEWSLETTERS`, `ALL_TAGS`, `ALL_GROUP_PLAN_NAMES`.

**Writes** go through two paths:

1. `setStoredSubscriber(id, record)` / `setStoredGroup(id, record)` — persist a
   full mutated record.
2. The **`mutate` closure** convention: flows return
   `onComplete({ message, mutate })` where `mutate(prev) => next` transforms the
   current record. The host screen applies it with `setState` and persists via
   `setStored*`. (`PersonProfile` also bumps a `groupsRefresh` counter to re-read
   group data after a mutation, since groups live in module state, not React state.)

This `mutate` shape is a reasonable **optimistic-update** contract and mostly
survives the move to real endpoints: `setStored*` becomes a REST write, and
`groupsRefresh` is replaced by query invalidation / refetch.

## Screens and flows (the 1:1 surface)

This is the behavior to preserve. The code in `screens/` and `flows/` is the
source of truth; this is a map of it.

### Screens

- **SubscriberList** (`/`) — DataViews list: filter, sort, search, paginate; held
  behind a spinner until avatars resolve. Avatar + name/email column, **Status**
  (active-first badges; cancelled hidden while a live plan remains; the Cancelled
  filter matches only fully churned readers, never one with a live plan),
  **Subscription** (individual plans + group plan with an Owner/Member role badge;
  cancelled plans are hidden here too while a live one remains, though the filter
  still matches them), Last payment, Member since (date + relative), optional
  Last seen, Tags, Newsletters. Live header count; whole row clicks through.
- **GroupList** (`/groups`) — DataViews list of groups; cancelled hidden by default
  (re-enable via the Status filter). Owner avatar + plan, members `used / limit`,
  status badge, created date. Whole row clicks through.
- **PersonProfile** (`/profile/:id`) — header avatar (spinner until resolved),
  name, email, last seen, status badges. **Full-width notices** above the content
  (copy + rendering centralized in `SubscriberNotices.jsx`, shared with
  `GroupDetail`) use one action-first voice leading with the plan name in bold and
  a guaranteed CTA. Covered: an individual or owned-group on-hold subscription with
  a "Reactivate" CTA (opens `GuidedFixFlow`), folding in the "(last charged <date>)"
  clause when known; and a reader whose access comes through an on-hold group they
  don't own, pointing to the owner ("View owner"). A cancellation is intentional and
  reversible from its card, so it raises no individual notice. Sections:
  Newsletters (per-newsletter toggles, "View more/less", "Unsubscribe from all"),
  Subscriptions (individual + group cards merged and ordered active → pending →
  on-hold → cancelled then newest-first; cancelled plans collapse behind "View more";
  an "Add subscription" button always appears below the cards. Each card's header is
  the plan name plus its status badge(s) left-aligned (a group card also carries a
  "Group" badge), with the per-status actions moved into a right-side "more" kebab
  matching the payment-method cards: active → Change subscription (when other plans
  exist) + Refund or cancel (just Cancel when free-forever); on-hold → Reactivate +
  Cancel; cancelled → Resubscribe; a group card adds Manage group (and its title
  links through to the group page), a member card adds Remove from group, and a
  pending card offers Mark as paid / Resend link / Cancel (each item aria-labelled
  with the plan). A paid active or on-hold subscription also
  offers "Change payment method" (opens `ChangePaymentMethodFlow`), shown only when the
  reader has more than one card. A pending card's body shows "Payment link
  sent N days ago. Awaiting payment."; every other card lays its details out as a
  fixed two-column labelled grid (First subscribed, Billing, Last payment, Next
  billing) so the cells never move between statuses — the Next billing cell shows the
  Cancelled date for a cancelled subscription, and any missing value renders as an
  em-dash. A group card shows its seat usage ("6 of 25 seats") as a muted line under
  the title (the same treatment as a payment card's expiry), on both owner and member
  cards, since seats describe the group rather than the owner's billing. A member's
  group card drops the billing rows and instead shows Joined and Owner, since the
  billing belongs to the owner),
  Payment methods (empty state, or headerless cards wrapping a brand icon beside a
  bold "{brand} ending in {last4}" line with inline status badges — a "Default"
  badge on the default card and/or a red "Expired" badge once past `expiry`
  (`isCardExpired`), so a default card that has expired shows both; a card that is
  neither gets an invisible (aria-hidden) "Active" badge that only reserves the
  badge height, so the rows stay aligned regardless of which badges show — an
  "Expiry MM/YY" line beneath it, and a 32px kebab — vertical-dots — menu of
  Edit / Make default / Remove (each item aria-labelled with the card, since the
  menu loses the toggle's context once open); Make default re-flags the card and
  snackbars "{card} set as default." but is hidden for an expired card (which can't
  be charged, so it can't become the default — it can still be removed), Remove opens
  `RemovePaymentFlow` to confirm first), **Billing
  history** (searchable DataViews — Subscription, Date, Type, Amount; date-sorted
  newest first; includes group payments; folds in the start date when a plan recurs;
  records a "Joined <group>" event for memberships; a "Subscription payment" event
  when a card is charged; a "Payment link sent" event with no amount when a
  payment-link subscription is created; a "Granted free access" event when free
  access is granted on add; and a "Reactivated" event with no amount when an on-hold
  subscription resumes without a charge).
  Private-note cards; kebab actions (manage tags, add note, …).
- **GroupDetail** (`/group/:id`) — header leads with the plan, owner appended;
  status badge; full-width notice when on-hold (the `SubscriberNotices`
  `groupOnHoldNotice` builder, deferring to the owner via "View owner"). A
  cancelled group shows no notice: the status badge and disabled actions say so.
  Members DataViews
  (avatar + name/email column, role badges, per-row kebab, "Remove member" as a
  per-row and bulk action), seat count + Adjust seats + a single "Add members"
  menu (Add directly / Invite by email / Copy invite link, plus Regenerate /
  Disable invite link once a link exists). The Invitations DataViews
  (Pending/Expired badges, "Accept on behalf" as a per-row and bulk action) only
  appears when there are pending invites. When a `seatRequest` is outstanding, a
  full-width notice shows the requested seat count and status, with admin CTAs
  Decline (removes the request) and Mark as paid (applies the limit immediately,
  logs a manual "Seat upgrade payment" order); when `awaiting-payment` the notice
  also shows the amount. The owner originates such a request from the front-end My
  Account group page (a real feature, not part of this prototype). The **GroupList**
  shows a badge on groups with a pending or awaiting-payment request so admins can
  spot them at a glance.

**Admin superpower (divergence from owner parity).** Beyond the owner-facing My
Account flow (#148), an admin can add members directly (Add members → Add directly) and
accept or bulk-approve pending invitations on the invitee's behalf, creating
accounts for unknown emails. Both paths respect the seat limit. Runtime adds are
additive — they don't apply the seed-time covered-member clearing that strips a
member's own individual subscription.

### Flows (modals)

Each ends in a brief **snackbar** confirmation; persistent state stays a notice.
All mutate through the `onComplete({ message, mutate })` convention above.

| Flow | Trigger | Steps / states | Outcome |
|------|---------|----------------|---------|
| `RefundFlow` | Subscription "Refund or cancel" (just "Cancel" for a free-forever or non-active sub) / on-hold "Cancel" | Active paid: radio (refund only / cancel only / both) with per-choice detail; on-hold, cancelled, or free-forever (zero-amount): straight cancel confirm, since there is nothing to refund. Busy state. | Subscription → cancelled (cancels the subscriber if nothing active remains, logs a Cancellation order) or group cancelled. |
| `AddSubscriptionFlow` | "Add subscription" button (Subscriptions section); cancelled individual card "Resubscribe"; profile empty-state "Resubscribe". (A cancelled **group** resubscribes through `GuidedFixFlow`, not here — a group has a fixed plan and members to restore, so it gets the same rich charge/link/free flow as a reactivation rather than the plan picker.) | **Individual — two steps.** Step 1: `SelectControl` spanning all plan families (Digital / Print / Team) with a duplicate-family warning when the reader already holds an active plan in that family; a `ModeRadio` picks how to add it — "Charge the customer now" (hidden when `hasUsableCard` is false), "Send a payment link", or "Grant free access" — each with a one-line help description; Cancel / Continue. Team plans skip step 2 and route directly to a seat-setup step. Step 2: bold plan name; charge and link modes show a price `TextControl`; `ChargeNote` ("Billing will start today. First charge: … Next renewal in …") for charge, `PaymentLinkNote` ("A payment link will be emailed to … activates once they pay") for link, `FreeAccessFields` (segmented "For a number of cycles" / "Indefinitely" control) for comp; "Send a confirmation email" checkbox for charge and comp but not link; Back / Confirm. **Charge now:** one card is charged immediately; multiple cards show a card picker; no card opens `PaymentUpdateFlow`, then charges on save. **Send payment link:** creates a `pending` subscription with `linkSentAt`; logs a "Payment link sent" billing-history entry (`amount null`). **Grant free access:** indefinite comp (`amount 0`, `nextBillingDate null`) or free for N cycles then billing starts (`freeCyclesRemaining`); logs "Granted free access". **Team plan:** seat-setup step (number input, min 1, owner occupies one seat); confirm calls `createGroup` and emits `groupCreate`. | Subscription added (active, comped, or pending). |
| `PlanChangeFlow` | Active subscription "Change subscription" | `SelectControl` of same-family plans; shows new charge + proration; group downgrade below current members is blocked with a notice. | Plan/amount/cadence (and group seat cap) updated. |
| `ChangePaymentMethodFlow` | Paid active/on-hold subscription "Change payment method" (multi-card readers only) | `SelectControl` of the reader's usable (non-expired) cards, current preselected, default flagged; Confirm disabled until a different card is chosen. Falls back to a "no usable cards" notice. Busy on save. | Subscription's `paymentMethodId` updated; snackbars "Payment method for {plan} changed to {card}." |
| `PaymentUpdateFlow` | "Edit" a card / "Add payment method" / from GuidedFix or Resubscribe | Card number, expiry, CVC with inline validation; brand auto-detected from BIN. Busy on save. | Card added or updated (first card becomes default). |
| `RemovePaymentFlow` | Payment-method kebab "Remove" | Small `ConfirmFlow` confirmation: "Remove **{brand} ending in {last4}**? It can no longer be used for future payments." with a destructive Remove. | Card deleted from `paymentMethods`; snackbars "{card} removed." |
| `GuidedFixFlow` | "Reactivate" on an on-hold notice or card; **cancelled group "Resubscribe"** (the shared "bring a fixed-plan subscription/group back to active" flow). | **Two steps, mirroring `AddSubscriptionFlow`.** Step 1: bold plan name + `ModeRadio` — "Charge the customer now" (hidden when `hasUsableCard` is false), "Send a payment link", or "Reactivate / Resubscribe for free" — each with a one-line help description; Cancel / Continue. Step 2: `ChargeNote` for charge, `PaymentLinkNote` for link, `FreeAccessFields` for free; "Send a confirmation email" checkbox for charge and free but not link; Back / Confirm. A `verb` prop (`reactivate` for on-hold, `resubscribe` for cancelled, derived from the target's status) swaps the title, radio labels, and help copy. Charge and free paths call `reactivateSubscription` / `reactivateFree` (or the group equivalents on the group detail screen); link calls `onSendPaymentLink`. | Subscription/group returned to active; logs "Subscription payment" when charged, "Reactivated" (no amount) when free, or "Payment link sent" when a link is sent. The snackbar reads "{plan} reactivated." or "{plan} resubscribed." to match the verb. |
| `SubscriptionDetailsDrawer` | "View subscription" kebab on group detail | Right-edge panel that slides in on open and out on close (no modal chrome, custom overlay). Shows the plan name as the title, then a self-contained snapshot in two tiers: an identity tier (Status badge, Owner as a link to the owner profile, Seats `x of y`), a divider, then the billing tier in the same order as the person-profile group card: First subscribed, Billing (price / cadence), Last payment (or "Free access"), and Next billing (or Cancelled date when cancelled). A pinned footer (mirroring the newsletters quick-edit footer: white, sticky, no divider, buttons split 50/50) carries the same status-gated CTAs as the person-profile group card: active → Change subscription (when other plans exist) + Refund or cancel (just Cancel when free-forever); on-hold → Reactivate + Cancel; cancelled → Resubscribe. Each CTA closes the drawer and opens the matching flow — Change subscription → `PlanChangeFlow`, Refund or cancel / Cancel → `RefundFlow`, Reactivate **and** Resubscribe → `GuidedFixFlow` (verb-aware) — which mutate the group on screen (and log owner billing-history orders on reactivate/resubscribe). | Display + actions; closes with the × button, overlay click, or any CTA. |
| `NoteFlow` | "Add private note" / note "Edit" | Textarea, save disabled until non-empty. | Note added or edited. |
| `TagsFlow` | "Manage tags" | `FormTokenField` with known-tag suggestions; save when changed. | Tags replaced. |
| `InviteMemberFlow` | "Add members → Invite by email" | `FormTokenField` multi-email, capacity-aware (warns at zero seats, trims over capacity, de-dupes vs. pending). | Pending email invites added. |
| `AddMembersFlow` | "Add members → Add directly" | `FormTokenField` multi-email, capacity-aware (trims over capacity with a warning), "Send a welcome email" checkbox. Existing accounts resolve by email; unknown emails create a stub account. | Members added immediately; a matching pending invite is consumed. |
| `AcceptInviteFlow` | Invitations "Accept on behalf" (row or bulk) | Confirms the count with a "Send a welcome email" checkbox. | Pending invite(s) converted to members; seat count unchanged. |
| `AdjustSeatsFlow` | "Adjust seats" | Number input floored at reserved seats, raise only while active; two-step input → confirm summary. When raising the limit, offers Increase for free or Increase & send a payment link (admin-entered amount); can be opened pre-filled from an owner seat request. | Seat limit updated (free path: immediate; payment-link path: sends link and sets `awaiting-payment` on the request). |
| `MakeOwnerFlow` | Member kebab "Make owner" | `ConfirmFlow`. | Ownership transferred; previous owner demoted. |
| `RemoveMemberFlow` | Member kebab / bulk "Remove member" (row or bulk) | `ConfirmFlow` (destructive); pluralizes the copy for multiple members. The owner is never eligible. | Member(s) removed; seats freed. |
| `RegenerateLinkFlow` / `DisableLinkFlow` | "Add members" menu (when a link exists) | `ConfirmFlow` (disable is destructive). Copy invite link itself is no modal: it copies and creates the link if none exists. | Link regenerated / disabled. |
| `ResendInviteFlow` / `CancelInviteFlow` | Invite row kebab | `ConfirmFlow` (cancel is destructive). | Invite `sentAt` refreshed / invite removed. |

`ConfirmFlow` is the shared scaffold (title, body, Cancel + primary/destructive
Confirm) behind the six simple confirmations above.

The owner's "Request more seats" / seat-upgrade payment is **not** part of this
prototype: it ships as a real front-end feature on the My Account group page
(`includes/plugins/woocommerce/.../templates/v1/group.php`). The prototype only
covers the admin-side response to such a request (the seat-request notice with
its Decline / Mark as paid actions, and the request badge on `GroupList`). The
real owner template lives at
`includes/plugins/woocommerce/my-account/templates/v1/group.php`.

## Data model

```js
// Subscriber
{
  id, name, email,
  status,            // 'active' | 'on-hold' | 'cancelled'  (subscriber-level)
  memberSince,       // 'YYYY-MM-DD'
  lastPayment,       // 'YYYY-MM-DD' | null
  lastSeen,          // 'YYYY-MM-DD' | null
  subscriptions: [ { id, plan,
                      status,              // 'active' | 'pending' | 'on-hold' | 'cancelled'
                                           // 'pending' = payment link sent, awaiting payment
                      access, cadence, startDate, nextBillingDate, amount,
                      freeCyclesRemaining, // number = free for N cycles, then billing starts
                                           // null   = free indefinitely (no future billing)
                      linkSentAt } ],      // 'YYYY-MM-DD' — set on 'pending' subs only
  paymentMethods: [ { id, type, last4, expiry, isDefault } ],
  orders: [ { id, date,
               amount,               // null for non-financial events
               type,                 // 'Subscription payment' | 'Payment link sent' |
                                     // 'Granted free access' | 'Reactivated' |
                                     // 'Refund' | 'Cancellation' | 'Joined <group>'
               subscriptionId } ],   // → an individual sub id or an owned group id
  alerts: [ { id, level, title, message } ],   // id 'alert_pay' is special-cased
  tags: [ 'vip', … ],
  newsletters: [ 'daily', … ],                 // newsletter ids
}

// Group (a subscription that adds members)
// Runtime-created groups (via AddSubscriptionFlow → team plan) start active with
// the owner as the only member. They are persisted to localStorage via
// setStoredGroup and surfaced by getAllGroups alongside the seeded fixtures.
{
  id, ownerId, plan, cadence, amount,
  status,            // 'active' | 'on-hold' | 'cancelled'
  seatLimit, createdAt,
  nextBillingDate,   // 'YYYY-MM-DD' | null
  members: [ { subscriberId, joinedAt, role } ],   // role: 'owner' | 'member'
  invites: [
    { id, type: 'email', email, status, sentAt },  // status 'pending'; expiry derived from sentAt
    { id, type: 'link', status, createdAt },        // one persistent reusable link
  ],
  seatRequest: {            // null when no outstanding request
    target,                 // requested total seat count (> seatLimit)
    requestedAt,            // YYYY-MM-DD
    status,                 // 'pending' | 'awaiting-payment'
    amount,                 // set when admin sends a payment link
    linkSentAt,             // set when admin sends a payment link
  } | null,
}
```

**Date derivation.** Billing dates are kept cadence-consistent at seed time. An
active subscription's (or group's) `nextBillingDate` is one cadence after its most
recent payment. An on-hold subscription's (or group's) failed renewal sits one
cadence after its last successful payment — which is the date `lastPayment`
reflects, so the on-hold notice and the list's Last payment column point at the
real charge, never the failed attempt. Cancelled plans keep their real final
payment plus a later cancellation. The one exception: a plan younger than a full
cadence keeps its recent seeded failure with the prior payment clamped to the join
date (a first renewal that failed).

## Configuration

Two hidden per-site `wp-config.php` knobs, surfaced through the wizard's localized
data (not exposed settings):

- `NEWSPACK_SUBSCRIBERS_DEMO_NEWSLETTERS_LIMIT` (default 4) — newsletters shown
  before the profile's "View more" toggle.
- `NEWSPACK_SUBSCRIBERS_DEMO_CANCELLED_LIMIT` (default 1) — cancelled subscriptions
  shown before "View more" when the reader has no live plan. When a live plan
  remains, every cancelled subscription is hidden regardless of this value.

Both collapse with the same min-hidden rule (a toggle that would reveal a single
item just shows it instead).

## Productionizing checklist

1. **Introduce data hooks** mirroring `useAvatars`: e.g. `useSubscriber(id)`,
   `useSubscribers(view)`, `useGroup(id)`, `useGroups()`, backed by
   `@wordpress/data` or the wizard's `useWizardApiFetch`. Point the screens at
   these instead of the `data/` mock-module imports.
2. **Implement the REST endpoints** the reads/writes imply (all under the wizard's
   existing `wizard/newspack-subscribers-demo/…` namespace):
   - `GET subscribers` (list, server-side filter/sort/paginate), `GET subscribers/{id}`
   - `GET groups`, `GET groups/{id}`
   - writes for subscriptions (refund/cancel/change/resubscribe), payment methods,
     tags, notes, newsletters, and group invites/members/seats/ownership.
   - `POST avatars` already exists.
3. **Delete `data/storage.js`** and the `getStored*`/`setStored*` functions;
   persistence is now server-side and shared across admins.
4. **Drop the mock seeds** (`FIXTURES`/`EXTRAS`/`makeRandom`) from
   `data/mock-subscribers.js` and `data/mock-groups.js`; keep the pure helpers in
   `mock-groups.js` (seat/invite math) if useful.
5. **Source the constants** (`DIGITAL_PLANS`, `PRINT_PLANS`, `TEAM_PLANS`,
   `NEWSLETTERS`) from real products / integrations instead of literals.

## Known prototype shortcuts

- **`localStorage` persistence** is per-browser, not shared between admins; a
  `DATA_VERSION` bump in `data/storage.js` wipes it (no migration).
- **`setTimeout` fake-async** in the flows has no in-flight cancellation guard.
- The in-memory `SUBSCRIBERS`/`GROUPS` arrays are never mutated, so the L0 lists
  and filter elements reflect only seeded values even after L1 edits.
- Avatar resolution is real, but the column-layout decision uses a synchronously
  localized `SHOW_AVATARS` flag; the endpoint also returns `show` for correctness.
- **Runtime-created groups** (team-plan path in `AddSubscriptionFlow`) are
  persisted to the store by `setStoredGroup` and surfaced by `getAllGroups`, which
  reads store-only ids in addition to the seeded `GROUPS` array. They appear
  immediately in `GroupList` and in the owner's profile without any array mutation.
- **Free-for-N-cycles conversion with no card on file** is not silently attempted.
  If the subscriber has no card and the comp path is skipped (i.e. a non-comped
  subscription), the flow calls `onOpenPaymentUpdate` to collect a card first.
  Once a card exists, the normal charge-now path applies. The free-to-paid
  conversion itself (when `freeCyclesRemaining` reaches zero) is not simulated in
  the prototype; productionizing it reuses the existing on-hold/dunning path rather
  than requiring new state.
