# Add Subscription Flow — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give an admin one reusable flow to add any catalog subscription to a reader, choosing to comp it, send a payment link, or charge a card, with `ResubscribeFlow` absorbed into it.

**Architecture:** Generalize `flows/ResubscribeFlow.jsx` into `flows/AddSubscriptionFlow.jsx` (single file, internal step sub-components, mirroring the existing `PlanPicker` pattern). Every trigger (the new "Add subscription" button, the cancelled-card and empty-state "Resubscribe", the group restore) mounts the same component with props that pre-scope it. Reads/writes stay inside the `data/` seam and the `completeFlow({ message, mutate, ... })` contract.

**Tech Stack:** React (function components, hooks), `@wordpress/components` + `@wordpress/element` + `@wordpress/i18n`, the prototype's `packages/components/src` re-exports, `localStorage` persistence via `data/`.

## Global Constraints

- **No unit-test harness for this prototype.** The subscribersDemo flows have no Jest tests, and the project runs JS tests only as a full suite (`AGENTS.md`). Per project convention this prototype is verified live in the browser. Each task therefore ends with build + lint + a concrete manual browser check, not a failing-test cycle. This is a deliberate deviation from the skill's default TDD loop, justified by project precedence.
- **Build:** `pnpm --filter newspack run build` (two pre-existing bundle-size warnings are unrelated and expected).
- **Lint:** `pnpm --filter newspack exec newspack-scripts wp-scripts lint-js 'src/wizards/subscribersDemo/**/*.{js,jsx}' --fix`
- **Run:** reload `admin.php?page=newspack-subscribers-demo#/` in Brave (site must be in Newspack debug mode). A `DATA_VERSION` bump in `data/storage.js` wipes `newspack-subscribers-demo:*` localStorage; bump it only when a task changes the seed shape (none here do).
- **Conventions:** keep code comments minimal; no em dashes in prose; conventional commits (`feat`/`refactor`/`docs(subscribers): …`, subject ≤72 chars); Co-Authored-By trailer required; commit per task, do not push.
- **Data-shape fidelity:** new fields (`status: 'pending'`, `freeCyclesRemaining`, the convert-to amount, the `"Payment link sent"` order type) are additive so existing cards/lists keep rendering. Document them in the README (Task 10).
- All paths below are relative to `plugins/newspack-plugin/src/wizards/subscribersDemo/`.

---

### Task 1: Add the `pending` status to the shared vocabulary

**Files:**
- Modify: `status.js`

**Interfaces:**
- Produces: `STATUS_LABELS.pending`, `STATUS_BADGE_LEVEL.pending`, `STATUS_RANK.pending` — consumed by the pending card (Task 7) and any status badge.

- [ ] **Step 1: Add the label, badge level, and rank**

In `status.js`, extend the three maps. Replace:

```js
export const STATUS_LABELS = {
	active: __( 'Active', 'newspack-plugin' ),
	'on-hold': __( 'On hold', 'newspack-plugin' ),
	cancelled: __( 'Cancelled', 'newspack-plugin' ),
};

export const STATUS_BADGE_LEVEL = {
	active: 'success',
	'on-hold': 'warning',
	cancelled: 'error',
};

// Active first, then on-hold, then cancelled.
export const STATUS_RANK = { active: 0, 'on-hold': 1, cancelled: 2 };
```

with:

```js
export const STATUS_LABELS = {
	active: __( 'Active', 'newspack-plugin' ),
	pending: __( 'Pending', 'newspack-plugin' ),
	'on-hold': __( 'On hold', 'newspack-plugin' ),
	cancelled: __( 'Cancelled', 'newspack-plugin' ),
};

export const STATUS_BADGE_LEVEL = {
	active: 'success',
	pending: 'info',
	'on-hold': 'warning',
	cancelled: 'error',
};

// Active first, then pending, then on-hold, then cancelled.
export const STATUS_RANK = { active: 0, pending: 1, 'on-hold': 2, cancelled: 3 };
```

`displayStatuses` needs no change: `pending` is non-cancelled, so it survives the cancelled-hiding filter and sorts by the new rank.

- [ ] **Step 2: Build**

Run: `pnpm --filter newspack run build`
Expected: builds with only the two known size warnings.

- [ ] **Step 3: Lint**

Run: `pnpm --filter newspack exec newspack-scripts wp-scripts lint-js 'src/wizards/subscribersDemo/**/*.{js,jsx}' --fix`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add plugins/newspack-plugin/src/wizards/subscribersDemo/status.js
git commit -m "feat(subscribers): add pending status to the shared vocabulary" \
  -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Rename `ResubscribeFlow` to `AddSubscriptionFlow` (behavior-preserving)

This is a pure refactor: copy the file under the new name, repoint the import and render, delete the old file. Behavior is identical so it is independently verifiable against today's resubscribe flow.

**Files:**
- Create: `flows/AddSubscriptionFlow.jsx` (content of `flows/ResubscribeFlow.jsx`, renamed)
- Delete: `flows/ResubscribeFlow.jsx`
- Modify: `screens/PersonProfile.jsx:67` (import), `screens/PersonProfile.jsx:1207-1215` (render)

**Interfaces:**
- Produces: `AddSubscriptionFlow( { subscriber, group, onClose, onComplete, onOpenPaymentUpdate } )` — the shared shell every later task extends.

- [ ] **Step 1: Create the renamed component**

Copy `flows/ResubscribeFlow.jsx` to `flows/AddSubscriptionFlow.jsx`. In the new file, rename the default export function `ResubscribeFlow` to `AddSubscriptionFlow` and update the top doc comment from `Flow B — Resubscribe.` to `Add subscription flow (absorbs the former Resubscribe flow).`. Leave all other logic untouched for now.

```bash
git mv plugins/newspack-plugin/src/wizards/subscribersDemo/flows/ResubscribeFlow.jsx \
       plugins/newspack-plugin/src/wizards/subscribersDemo/flows/AddSubscriptionFlow.jsx
```

Then edit the moved file: change `export default function ResubscribeFlow(` to `export default function AddSubscriptionFlow(` and update the header comment.

- [ ] **Step 2: Repoint the import in PersonProfile**

`screens/PersonProfile.jsx:67`, replace:

```js
import ResubscribeFlow from '../flows/ResubscribeFlow';
```

with:

```js
import AddSubscriptionFlow from '../flows/AddSubscriptionFlow';
```

- [ ] **Step 3: Repoint the render**

`screens/PersonProfile.jsx:1207-1215`, replace:

```js
			{ modal?.kind === 'resubscribe' && (
				<ResubscribeFlow
					subscriber={ subscriber }
					group={ modal.group }
					onClose={ closeModal }
					onComplete={ completeFlow }
					onOpenPaymentUpdate={ () => setModal( { kind: 'payment' } ) }
				/>
			) }
```

with:

```js
			{ modal?.kind === 'resubscribe' && (
				<AddSubscriptionFlow
					subscriber={ subscriber }
					group={ modal.group }
					onClose={ closeModal }
					onComplete={ completeFlow }
					onOpenPaymentUpdate={ () => setModal( { kind: 'payment' } ) }
				/>
			) }
```

(The `modal.kind === 'resubscribe'` key is kept here; Task 3 introduces the unscoped entry point.)

- [ ] **Step 4: Build, lint**

Run: `pnpm --filter newspack run build`
Run: `pnpm --filter newspack exec newspack-scripts wp-scripts lint-js 'src/wizards/subscribersDemo/**/*.{js,jsx}' --fix`
Expected: builds; no lint errors; `grep -rn ResubscribeFlow src/wizards/subscribersDemo` returns nothing outside the spec/plan docs.

- [ ] **Step 5: Browser verify (no behavior change)**

Open a cancelled reader (e.g. the empty-state or a cancelled card), click Resubscribe. Confirm the modal opens and the resubscribe paths (plan picker, send link, grant free access) behave exactly as before.

- [ ] **Step 6: Commit**

```bash
git add -A plugins/newspack-plugin/src/wizards/subscribersDemo/flows plugins/newspack-plugin/src/wizards/subscribersDemo/screens/PersonProfile.jsx
git commit -m "refactor(subscribers): rename ResubscribeFlow to AddSubscriptionFlow" \
  -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Add the unscoped "Add subscription" entry point

Adds a footer button to the Subscriptions section (mirroring the Payment methods "Add payment method" footer button) so the flow can be opened on any reader in any state. This makes Tasks 4-9 testable against active readers, not just cancelled ones.

**Files:**
- Modify: `screens/PersonProfile.jsx` (Subscriptions `Row`, ~line 910-1078; the modal render block ~line 1207)
- Modify: `flows/AddSubscriptionFlow.jsx` (accept an `addMode` prop that skips the prior-plan seeding)

**Interfaces:**
- Consumes: `AddSubscriptionFlow` from Task 2.
- Produces: `modal.kind === 'addSubscription'`; `AddSubscriptionFlow` prop `addMode: boolean` (default false). When true, the flow starts at the catalog picker with no prior-plan seeding and the title reads "Add subscription".

- [ ] **Step 1: Make the flow title and start adapt to add mode**

In `flows/AddSubscriptionFlow.jsx`, change the signature to accept `addMode`:

```js
export default function AddSubscriptionFlow( { subscriber, group, addMode = false, onClose, onComplete, onOpenPaymentUpdate } ) {
```

Change the `Modal` title (currently `__( 'Resubscribe', 'newspack-plugin' )`) to branch:

```js
		<Modal title={ addMode ? __( 'Add subscription', 'newspack-plugin' ) : __( 'Resubscribe', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
```

The existing `hasPaymentMethod`-based initial `step` logic is unchanged; in add mode the catalog picker (Task 4) handles plan selection, and `PlanPicker`'s prior-plan seeding simply finds no cancelled/on-hold sub for an active reader and falls back to the first catalog plan, which is correct.

- [ ] **Step 2: Add the footer button to the Subscriptions section**

In `screens/PersonProfile.jsx`, the Subscriptions `Row` closes at line ~1077 (`</VStack></Row>` after the `View more` IIFE). Immediately before the closing `</VStack>` of that Row's `<VStack spacing={ 4 } ref={ subscriptionsListRef }>`, add a footer button, matching the Payment methods pattern:

```js
						<HStack justify="flex-start">
							<Button variant="secondary" size="compact" onClick={ () => setModal( { kind: 'addSubscription' } ) }>
								{ __( 'Add subscription', 'newspack-plugin' ) }
							</Button>
						</HStack>
```

- [ ] **Step 3: Render the flow for the new modal kind**

In `screens/PersonProfile.jsx`, just after the `modal?.kind === 'resubscribe'` block (~line 1215), add:

```js
			{ modal?.kind === 'addSubscription' && (
				<AddSubscriptionFlow
					subscriber={ subscriber }
					addMode
					onClose={ closeModal }
					onComplete={ completeFlow }
					onOpenPaymentUpdate={ () => setModal( { kind: 'payment' } ) }
				/>
			) }
```

- [ ] **Step 4: Build, lint, browser verify**

Run build + lint (commands above). Open an active reader, confirm an "Add subscription" button now sits below the subscription cards, opens the flow titled "Add subscription", and that confirming a plan adds a second active subscription card (PlanPicker already appends rather than replaces).

- [ ] **Step 5: Commit**

```bash
git add plugins/newspack-plugin/src/wizards/subscribersDemo/screens/PersonProfile.jsx plugins/newspack-plugin/src/wizards/subscribersDemo/flows/AddSubscriptionFlow.jsx
git commit -m "feat(subscribers): add an Add subscription entry point" \
  -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Unified individual-catalog picker with custom price and duplicate guard

Replaces the `DIGITAL_PLANS`-only `SelectControl` in `PlanPicker` with a grouped picker over digital + print, adds an editable price field defaulted to the catalog amount, and warns when the reader already holds an active same-family plan. (Group/team plans are added in Task 9.)

**Files:**
- Modify: `flows/AddSubscriptionFlow.jsx` (the `PlanPicker` sub-component, lines ~17-100)

**Interfaces:**
- Consumes: `DIGITAL_PLANS`, and now `PRINT_PLANS`, from `../data/mock-subscribers`.
- Produces: the created subscription's `amount` is the (possibly overridden) price; family is digital or print.

- [ ] **Step 1: Import the print plans and a family helper**

In `flows/AddSubscriptionFlow.jsx`, update the import:

```js
import { DIGITAL_PLANS, PRINT_PLANS } from '../data/mock-subscribers';
```

Add, above `PlanPicker`:

```js
// Catalog grouped by family, so the picker can label options and the duplicate
// guard can compare like for like.
const CATALOG = [
	{ family: 'digital', label: __( 'Digital', 'newspack-plugin' ), plans: DIGITAL_PLANS },
	{ family: 'print', label: __( 'Print', 'newspack-plugin' ), plans: PRINT_PLANS },
];
const planFamily = name => CATALOG.find( g => g.plans.some( p => p.name === name ) )?.family || null;
```

- [ ] **Step 2: Rebuild `PlanPicker` with the grouped picker, price field, and guard**

Replace the body of `PlanPicker` (the part from the `priorSub`/`plans` derivation through the returned JSX) so that: options span both families (grouped via `optgroup`-style labels in the option text), a `customAmount` state defaults to the selected plan's amount and resets on plan change, and a duplicate warning renders when `subscriber.subscriptions` has an active plan in the same family. The `submit` closure writes `amount: comped ? 0 : Number( customAmount )`.

```js
function PlanPicker( { subscriber, onComplete, onCancel, onGrantFreeAccess, comped = false } ) {
	const priorSub =
		( subscriber.subscriptions || [] ).find( s => s.status === 'cancelled' || s.status === 'on-hold' ) || ( subscriber.subscriptions || [] )[ 0 ];
	const allPlans = CATALOG.flatMap( g => g.plans );
	const priorPlan = priorSub ? allPlans.find( p => p.name === priorSub.plan ) : null;
	const [ planName, setPlanName ] = useState( priorPlan?.name || allPlans[ 0 ].name );
	const plan = allPlans.find( p => p.name === planName );
	const [ customAmount, setCustomAmount ] = useState( String( plan.amount ) );
	const [ loading, setLoading ] = useState( false );

	// Reset the price to the catalog amount whenever the plan changes.
	const onChangePlan = name => {
		setPlanName( name );
		setCustomAmount( String( allPlans.find( p => p.name === name ).amount ) );
	};

	const family = planFamily( planName );
	const hasActiveSameFamily = ( subscriber.subscriptions || [] ).some(
		s => s.status === 'active' && planFamily( s.plan ) === family
	);

	const submit = () => {
		setLoading( true );
		setTimeout( () => {
			onComplete( {
				type: 'success',
				message: comped
					? sprintf( __( 'Granted %1$s free access to %2$s.', 'newspack-plugin' ), subscriber.name, planName )
					: sprintf( __( 'Added %2$s for %1$s.', 'newspack-plugin' ), subscriber.name, planName ),
				mutate: s => {
					const newSub = {
						id: 'sub_new_' + Date.now(),
						plan: plan.name,
						status: 'active',
						access: plan.access,
						cadence: plan.cadence,
						nextBillingDate: comped ? null : new Date( Date.now() + 30 * 86400000 ).toISOString().slice( 0, 10 ),
						amount: comped ? 0 : Number( customAmount ),
					};
					return { ...s, status: 'active', subscriptions: [ ...( s.subscriptions || [] ), newSub ] };
				},
			} );
		}, 700 );
	};

	return (
		<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
			<SelectControl
				label={ __( 'Choose a subscription', 'newspack-plugin' ) }
				value={ planName }
				options={ CATALOG.flatMap( g =>
					g.plans.map( p => ( {
						label: `${ g.label } — ${ p.name } ($${ p.amount }/${ p.cadence === 'Monthly' ? 'mo' : 'yr' })`,
						value: p.name,
					} ) )
				) }
				onChange={ onChangePlan }
			/>
			{ ! comped && (
				<TextControl
					type="number"
					label={ __( 'Price', 'newspack-plugin' ) }
					value={ customAmount }
					onChange={ setCustomAmount }
					help={
						Number( customAmount ) !== plan.amount
							? sprintf( __( 'Catalog price is %s.', 'newspack-plugin' ), fmtCurrency( plan.amount ) )
							: undefined
					}
				/>
			) }
			{ hasActiveSameFamily && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						__( '%s already has an active %s subscription. Adding another will not replace it.', 'newspack-plugin' ),
						subscriber.name,
						family
					) }
				</Notice>
			) }
			{ comped ? (
				<p>
					{ createInterpolateElement(
						sprintf(
							// translators: %s is the plan name (bold).
							__( 'This grants <name>%s</name> ongoing free access, with no billing.', 'newspack-plugin' ),
							plan.name
						),
						{ name: <strong /> }
					) }
				</p>
			) : (
				<p>
					{ sprintf(
						__( 'Billing will start today. First charge: %1$s. Next renewal in %2$s.', 'newspack-plugin' ),
						fmtCurrency( Number( customAmount ) ),
						plan.cadence === 'Monthly' ? '30 days' : '1 year'
					) }
				</p>
			) }
			<HStack spacing={ 2 } justify="flex-end">
				<Button variant="tertiary" size="compact" disabled={ loading } onClick={ onCancel }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				{ onGrantFreeAccess && (
					<Button variant="secondary" size="compact" disabled={ loading } onClick={ onGrantFreeAccess }>
						{ __( 'Grant free access', 'newspack-plugin' ) }
					</Button>
				) }
				<Button variant="primary" size="compact" isBusy={ loading } disabled={ loading } onClick={ submit }>
					{ comped ? __( 'Grant free access', 'newspack-plugin' ) : __( 'Confirm', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
```

- [ ] **Step 3: Add the `TextControl` and `Notice` imports**

`Button`, `Modal`, `SelectControl` stay imported from `../../../../packages/components/src`. `Notice` and `TextControl` are not in that re-export, so import them from `@wordpress/components` (none are experimental, so no `no-unsafe-wp-apis` disable is needed), matching `PersonProfile`'s import style. Add to `flows/AddSubscriptionFlow.jsx`:

```js
import { Notice, TextControl } from '@wordpress/components';
```

- [ ] **Step 4: Build, lint, browser verify**

Open an active digital subscriber, Add subscription: confirm the picker lists both Digital and Print options, that selecting Print and editing the price flows the custom amount into the new card and billing copy, and that choosing a digital plan shows the duplicate-family warning while a print plan does not.

- [ ] **Step 5: Commit**

```bash
git add plugins/newspack-plugin/src/wizards/subscribersDemo/flows/AddSubscriptionFlow.jsx
git commit -m "feat(subscribers): grouped catalog picker with price and dup guard" \
  -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Free-for-N-cycles comp control

Replaces the binary comp with a control that grants free access for N cycles then converts to paid, with "ongoing" preserving today's forever-free behavior. Applied to the single `PlanPicker`, so both the resubscribe and add entry points get it.

**Files:**
- Modify: `flows/AddSubscriptionFlow.jsx` (`PlanPicker`, the `comped` branch)

**Interfaces:**
- Produces: a comped subscription with `freeCyclesRemaining: number | null` (null = ongoing) and, when finite, `amount` set to the convert-to price so the conversion has a target. The no-card conversion fallback is realized in Task 7's dunning reuse and documented in Task 10.

- [ ] **Step 1: Add the free-cycles state and control to `PlanPicker`**

Inside `PlanPicker`, add state below `customAmount`:

```js
	// null = ongoing free (no billing ever); a number = free for N cycles then converts.
	const [ freeCycles, setFreeCycles ] = useState( '3' );
	const [ freeForever, setFreeForever ] = useState( false );
```

In the comped submit path, set the new fields. Change the `mutate` `newSub` so that when `comped`:

```js
					const ongoing = freeForever;
					const cyclesNum = Math.max( 1, Number( freeCycles ) || 1 );
					const newSub = {
						id: 'sub_new_' + Date.now(),
						plan: plan.name,
						status: 'active',
						access: plan.access,
						cadence: plan.cadence,
						// Ongoing comp never bills; a finite comp converts to the catalog
						// price after N cycles, so it carries that amount and a countdown.
						nextBillingDate: ongoing ? null : new Date( Date.now() + 30 * 86400000 ).toISOString().slice( 0, 10 ),
						amount: comped ? ( ongoing ? 0 : plan.amount ) : Number( customAmount ),
						...( comped ? { freeCyclesRemaining: ongoing ? null : cyclesNum } : {} ),
					};
```

- [ ] **Step 2: Render the comp duration control**

In the `comped` JSX branch (replacing the single explanatory `<p>`), render a forever toggle and, when not forever, a cycle count:

```js
			{ comped && (
				<>
					<ToggleControl
						label={ __( 'Free indefinitely (no billing)', 'newspack-plugin' ) }
						checked={ freeForever }
						onChange={ setFreeForever }
					/>
					{ ! freeForever && (
						<TextControl
							type="number"
							label={ __( 'Free cycles before billing starts', 'newspack-plugin' ) }
							value={ freeCycles }
							onChange={ setFreeCycles }
							help={ sprintf(
								__( 'Then converts to %s at the catalog price.', 'newspack-plugin' ),
								fmtCurrency( plan.amount )
							) }
						/>
					) }
					<p>
						{ freeForever
							? createInterpolateElement(
									sprintf(
										// translators: %s is the plan name (bold).
										__( 'Grants <name>%s</name> ongoing free access, with no billing.', 'newspack-plugin' ),
										plan.name
									),
									{ name: <strong /> }
							  )
							: sprintf(
									// translators: 1: cycle count, 2: plan name, 3: price.
									__( 'Free for %1$s cycles, then %2$s bills at %3$s.', 'newspack-plugin' ),
									Math.max( 1, Number( freeCycles ) || 1 ),
									plan.name,
									fmtCurrency( plan.amount )
							  ) }
					</p>
				</>
			) }
```

Remove the old `comped ? ( <p>…</p> ) : ( <p>…</p> )` block's comped half (keep the non-comped billing `<p>` for the paid path).

- [ ] **Step 3: Add the `ToggleControl` import**

Extend the `@wordpress/components` import added in Task 4 to also include `ToggleControl`:

```js
import { Notice, TextControl, ToggleControl } from '@wordpress/components';
```

- [ ] **Step 4: Build, lint, browser verify**

Grant free access to a reader: confirm the "Free indefinitely" toggle on shows the forever copy and creates a $0 sub with no next-billing date; toggle off shows the cycle field and creates a sub whose card shows the catalog price with a next-billing date.

- [ ] **Step 5: Commit**

```bash
git add plugins/newspack-plugin/src/wizards/subscribersDemo/flows/AddSubscriptionFlow.jsx
git commit -m "feat(subscribers): free-for-N-cycles comp control" \
  -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Card picker for charge-now

Adds a card-source step before charging: default card preselected, single card auto-selects, multiple cards let the admin choose, and no card on file branches into the existing `PaymentUpdateFlow`.

**Files:**
- Modify: `flows/AddSubscriptionFlow.jsx` (add a `CardPicker` sub-component and a `card` step ahead of the paid `PlanPicker` submit)

**Interfaces:**
- Consumes: `subscriber.paymentMethods` (`{ id, type, last4, expiry, isDefault }`), `onOpenPaymentUpdate` (already a prop).
- Produces: the chosen card id is recorded on the created subscription's order (Task 7 logs the payment).

- [ ] **Step 1: Add a `CardPicker` sub-component**

Above the default export in `flows/AddSubscriptionFlow.jsx`:

```js
// Choose which card to charge. Default preselected; a single card auto-selects;
// no card is handled by the caller branching to PaymentUpdateFlow.
function CardPicker( { subscriber, onBack, onConfirm } ) {
	const cards = subscriber.paymentMethods || [];
	const [ cardId, setCardId ] = useState( ( cards.find( c => c.isDefault ) || cards[ 0 ] ).id );
	return (
		<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
			<SelectControl
				label={ __( 'Charge which card', 'newspack-plugin' ) }
				value={ cardId }
				options={ cards.map( c => ( {
					label: `${ c.type } ····${ c.last4 }${ c.isDefault ? __( ' (default)', 'newspack-plugin' ) : '' }`,
					value: c.id,
				} ) ) }
				onChange={ setCardId }
			/>
			<HStack spacing={ 2 } justify="flex-end">
				<Button variant="tertiary" size="compact" onClick={ onBack }>
					{ __( 'Back', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" size="compact" onClick={ () => onConfirm( cardId ) }>
					{ __( 'Confirm and charge', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
```

- [ ] **Step 2: Route the paid path through the card step**

In the default `AddSubscriptionFlow`, the paid `PlanPicker` currently calls `onComplete` directly. Change it so a paid confirmation with no card branches to `onOpenPaymentUpdate`, with one card charges immediately, and with multiple cards advances to a `card` step. The simplest seam: have `PlanPicker`'s paid `submit` call a new `onPaid( newSubDraft )` callback (passed from the shell) instead of `onComplete`, and let the shell decide. Add `onPaid` to `PlanPicker`'s props and replace its paid `onComplete(...)` call with `onPaid( newSub )` (move the `newSub` object construction out so both comp and paid build it, but comp still calls `onComplete`).

In the shell, hold a draft and render the card step:

```js
	const [ paidDraft, setPaidDraft ] = useState( null );

	const onPaid = draft => {
		const cards = subscriber.paymentMethods || [];
		if ( ! cards.length ) {
			onOpenPaymentUpdate();
			return;
		}
		if ( cards.length === 1 ) {
			finishPaid( draft, cards[ 0 ].id );
			return;
		}
		setPaidDraft( draft );
		setStep( 'card' );
	};

	const finishPaid = ( draft, cardId ) => {
		onComplete( {
			type: 'success',
			message: sprintf( __( 'Added %1$s for %2$s.', 'newspack-plugin' ), draft.plan, subscriber.name ),
			mutate: s => ( { ...s, status: 'active', subscriptions: [ ...( s.subscriptions || [] ), draft ], _chargedCard: cardId } ),
		} );
	};
```

Add the `card` step to the step switch:

```js
	} else if ( step === 'card' ) {
		body = <CardPicker subscriber={ subscriber } onBack={ () => setStep( hasPaymentMethod ? 'plan' : 'choose' ) } onConfirm={ cardId => finishPaid( paidDraft, cardId ) } />;
	}
```

(The `_chargedCard` marker is consumed by Task 7's order logging; remove it from the persisted record there.)

- [ ] **Step 3: Build, lint, browser verify**

On a reader with two cards, Add subscription, pick a paid plan, Confirm: the card step appears with the default preselected; confirming charges and adds the sub. On a reader with one card, the card step is skipped. On a reader with no card, the flow opens PaymentUpdateFlow.

- [ ] **Step 4: Commit**

```bash
git add plugins/newspack-plugin/src/wizards/subscribersDemo/flows/AddSubscriptionFlow.jsx
git commit -m "feat(subscribers): card picker for charge-now" \
  -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Pending link state, mark-as-paid, and billing-history event

The send-link path writes a real pending subscription card and a "Payment link sent" billing-history entry instead of a fire-and-forget snackbar. The pending card carries resend, cancel, and a "Mark as paid" affordance that simulates checkout.

**Files:**
- Modify: `flows/AddSubscriptionFlow.jsx` (`sendLink` writes a pending sub draft via `onComplete` mutate)
- Modify: `screens/PersonProfile.jsx` (render pending cards in the Subscriptions list; mark-as-paid / resend / cancel handlers; log the "Payment link sent" order in the mutate)

**Interfaces:**
- Consumes: `STATUS_LABELS.pending` etc. (Task 1).
- Produces: a subscription with `status: 'pending'`, plus an order `{ type: 'Payment link sent', amount: null }`. Mark-as-paid flips it to `active` and logs a `Subscription payment` order.

- [ ] **Step 1: Make `sendLink` create a pending subscription**

In `flows/AddSubscriptionFlow.jsx`, `sendLink` currently only sets a snackbar. The send-link button lives in the `choose` step but needs the chosen plan; in add mode the picker precedes it. Simplest faithful approach: move "Send payment link" to be a third action on the `PlanPicker` paid step (alongside Cancel / Grant free access / Confirm), so the plan and price are known. Add an `onSendLink( draft )` prop to `PlanPicker` and a button:

```js
				<Button variant="secondary" size="compact" disabled={ loading } onClick={ () => onSendLink( buildPaidDraft() ) }>
					{ __( 'Send payment link', 'newspack-plugin' ) }
				</Button>
```

where `buildPaidDraft()` returns the same `newSub` object the paid path builds but with `status: 'pending'`, `id: 'sub_pending_' + Date.now()`, and `linkSentAt: new Date().toISOString().slice( 0, 10 )`.

In the shell, implement `onSendLink`:

```js
	const onSendLink = draft => {
		onComplete( {
			type: 'success',
			message: sprintf( __( 'Payment link sent to %s.', 'newspack-plugin' ), subscriber.email ),
			mutate: s => ( {
				...s,
				subscriptions: [ ...( s.subscriptions || [] ), draft ],
				orders: [
					{ id: 'ord_link_' + Date.now(), date: draft.linkSentAt, amount: null, type: __( 'Payment link sent', 'newspack-plugin' ), subscriptionId: draft.id },
					...( s.orders || [] ),
				],
			} ),
		} );
	};
```

Keep the existing `choose`-step "Send resubscribe link" for the no-card resubscribe path, but route it through the same `onSendLink` once a plan is chosen; for the group restore path `sendLink` is not used.

- [ ] **Step 2: Render pending subscription cards in PersonProfile**

In `screens/PersonProfile.jsx`, the subscriptions `items` array maps `subscriber.subscriptions`. A `pending` sub should render a distinct card. Inside the `subscriber.subscriptions.map( sub => { ... } )` (line ~920), branch at the top:

```js
								if ( sub.status === 'pending' ) {
									return {
										status: sub.status,
										date: sub.linkSentAt || sub.startDate,
										node: (
											<Card key={ sub.id } __experimentalCoreCard __experimentalCoreProps={ {
												header: (
													<HStack justify="space-between">
														<h4>{ sub.plan }</h4>
														<Badge level={ STATUS_BADGE_LEVEL.pending } text={ STATUS_LABELS.pending } />
													</HStack>
												),
											} }>
												<VStack spacing={ 4 }>
													<div>
														{ sprintf( __( 'Payment link sent %s. Awaiting payment.', 'newspack-plugin' ), fmtRelative( sub.linkSentAt ) ) }
													</div>
													<HStack spacing={ 2 } justify="flex-start">
														<Button variant="primary" size="compact" onClick={ () => markPendingPaid( sub ) }>
															{ __( 'Mark as paid', 'newspack-plugin' ) }
														</Button>
														<Button variant="secondary" size="compact" onClick={ () => resendPendingLink( sub ) }>
															{ __( 'Resend link', 'newspack-plugin' ) }
														</Button>
														<Button variant="tertiary" size="compact" isDestructive onClick={ () => cancelPending( sub ) }>
															{ __( 'Cancel', 'newspack-plugin' ) }
														</Button>
													</HStack>
												</VStack>
											</Card>
										),
									};
								}
```

`fmtRelative` is exported from `../format` but `PersonProfile` does not import it yet, so extend its format import (line 48) to `import { fmtCurrency, fmtRelative } from '../format';`.

- [ ] **Step 3: Add the pending handlers**

Near `reactivateSubscription` (line ~461) in `screens/PersonProfile.jsx`, add:

```js
	const markPendingPaid = sub => {
		const date = new Date().toISOString().slice( 0, 10 );
		const nextBillingDate = new Date( Date.now() + 30 * 86400000 ).toISOString().slice( 0, 10 );
		const order = { id: `ord_${ sub.id }_paid`, date, amount: sub.amount, type: __( 'Subscription payment', 'newspack-plugin' ), subscriptionId: sub.id };
		setSubscriber( prev => ( {
			...prev,
			status: 'active',
			subscriptions: prev.subscriptions.map( s => ( s.id === sub.id ? { ...s, status: 'active', nextBillingDate } : s ) ),
			orders: [ order, ...( prev.orders || [] ) ],
		} ) );
		setSnackbar( { message: sprintf( __( '%s is now active.', 'newspack-plugin' ), sub.plan ) } );
	};

	const resendPendingLink = sub => {
		setSubscriber( prev => ( {
			...prev,
			subscriptions: prev.subscriptions.map( s => ( s.id === sub.id ? { ...s, linkSentAt: new Date().toISOString().slice( 0, 10 ) } : s ) ),
		} ) );
		setSnackbar( { message: sprintf( __( 'Payment link resent to %s.', 'newspack-plugin' ), subscriber.email ) } );
	};

	const cancelPending = sub => {
		setSubscriber( prev => ( { ...prev, subscriptions: prev.subscriptions.filter( s => s.id !== sub.id ) } ) );
		setSnackbar( { message: __( 'Pending subscription cancelled.', 'newspack-plugin' ) } );
	};
```

- [ ] **Step 4: Build, lint, browser verify**

Add subscription, Send payment link: a Pending card appears with the relative timestamp, and Billing history shows a "Payment link sent" row (no amount). Mark as paid flips the card to Active and adds a Subscription payment row. Resend updates the timestamp; Cancel removes the card.

- [ ] **Step 5: Commit**

```bash
git add plugins/newspack-plugin/src/wizards/subscribersDemo/flows/AddSubscriptionFlow.jsx plugins/newspack-plugin/src/wizards/subscribersDemo/screens/PersonProfile.jsx
git commit -m "feat(subscribers): pending payment-link subscriptions" \
  -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Notify-reader toggle

A "Send a confirmation email" toggle on the confirm steps, mirroring `AddMembersFlow`'s welcome-email switch, defaulting on.

**Files:**
- Modify: `flows/AddSubscriptionFlow.jsx` (`PlanPicker` and `CardPicker` confirm steps)

**Interfaces:**
- Produces: appends a sentence to the success `message` when on; no persisted field (notification is a side effect in production).

- [ ] **Step 1: Add the toggle state and control**

In `PlanPicker`, add `const [ notify, setNotify ] = useState( true );` and render, just above the action `HStack`:

```js
			<ToggleControl
				label={ __( 'Send a confirmation email', 'newspack-plugin' ) }
				checked={ notify }
				onChange={ setNotify }
			/>
```

Thread `notify` into the drafts so the shell can adjust the message: include `_notify: notify` on the draft object built by `buildPaidDraft()`/comp submit, and in `finishPaid`/`onSendLink`/comp `onComplete`, append when `draft._notify`:

```js
				message: ( draft._notify ? sprintf( __( '%s A confirmation email was sent.', 'newspack-plugin' ), base ) : base )
```

where `base` is the existing message. Strip `_notify` (and `_chargedCard` from Task 6) from the persisted record in each `mutate` by not copying them onto the stored sub.

- [ ] **Step 2: Build, lint, browser verify**

Add a subscription with the toggle on: snackbar mentions the confirmation email. Toggle off: snackbar omits it. Confirm the persisted subscription record has no `_notify`/`_chargedCard` keys (View raw is a no-op, so inspect via React devtools or localStorage).

- [ ] **Step 3: Commit**

```bash
git add plugins/newspack-plugin/src/wizards/subscribersDemo/flows/AddSubscriptionFlow.jsx
git commit -m "feat(subscribers): notify-reader toggle on add subscription" \
  -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Group/team subscription branch

Adds team plans to the catalog. Selecting one inserts a seat-setup sub-step, then creates a new group with this reader as owner. Because `getAllGroups()` only iterates the static `GROUPS` array, runtime-created groups need the data layer to also surface store-only group ids.

**Files:**
- Modify: `data/mock-groups.js` (`getAllGroups` to include store-only groups; a `createGroup` helper)
- Modify: `flows/AddSubscriptionFlow.jsx` (team plans in catalog; seat step; group create on confirm)
- Modify: `screens/PersonProfile.jsx` (`completeFlow` to persist a `groupCreate`)

**Interfaces:**
- Consumes: `TEAM_PLANS` from `../data/mock-groups` (`{ name, cadence, amount, seats }`).
- Produces: `completeFlow` accepts `groupCreate` (a full group record) and writes it via `setStoredGroup`; `getAllGroups` returns store-only groups so it appears on the profile and Groups list.

- [ ] **Step 1: Surface runtime-created groups in the data layer**

In `data/mock-groups.js`, replace `getAllGroups` so store-only ids are included:

```js
export function getAllGroups() {
	const store = readStore( GROUPS_STORAGE_KEY );
	const seeded = GROUPS.map( g => ( Object.prototype.hasOwnProperty.call( store, g.id ) ? store[ g.id ] : g ) );
	const seededIds = new Set( GROUPS.map( g => g.id ) );
	const created = Object.keys( store )
		.filter( id => ! seededIds.has( id ) )
		.map( id => store[ id ] );
	return [ ...seeded, ...created ];
}
```

Add a `createGroup` helper:

```js
// Build a new group record owned by `ownerId` from a team plan. Persistence is
// the caller's job (setStoredGroup), matching how the rest of the seam works.
export function createGroup( { ownerId, plan, cadence, amount, seatLimit } ) {
	const createdAt = new Date().toISOString().slice( 0, 10 );
	return {
		id: 'grp_new_' + Date.now(),
		ownerId,
		plan,
		cadence,
		amount,
		status: 'active',
		seatLimit,
		createdAt,
		nextBillingDate: new Date( Date.now() + 30 * 86400000 ).toISOString().slice( 0, 10 ),
		members: [ { subscriberId: ownerId, joinedAt: createdAt, role: 'owner' } ],
		invites: [],
	};
}
```

- [ ] **Step 2: Add team plans to the catalog and a seat step**

In `flows/AddSubscriptionFlow.jsx`, import `TEAM_PLANS` and `createGroup`:

```js
import { TEAM_PLANS, createGroup } from '../data/mock-groups';
```

Add a third `CATALOG` group with `family: 'team'` and `plans: TEAM_PLANS` (team plans have `seats` not `access`; the option label uses `${ p.name } ($${ p.amount }/…)`). When the selected plan's family is `team`, `PlanPicker`'s Confirm should advance to a seat step rather than building an individual sub. Add a `SeatStep` sub-component:

```js
function SeatStep( { plan, onBack, onConfirm } ) {
	const [ seats, setSeats ] = useState( String( plan.seats ) );
	return (
		<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
			<TextControl
				type="number"
				label={ __( 'Seats', 'newspack-plugin' ) }
				value={ seats }
				onChange={ setSeats }
				help={ __( 'The owner occupies one seat.', 'newspack-plugin' ) }
			/>
			<HStack spacing={ 2 } justify="flex-end">
				<Button variant="tertiary" size="compact" onClick={ onBack }>
					{ __( 'Back', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" size="compact" onClick={ () => onConfirm( Math.max( 1, Number( seats ) || 1 ) ) }>
					{ __( 'Confirm', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
```

In the shell, when `PlanPicker` confirms a team plan, render `SeatStep`, then on confirm call `onComplete` with a `groupCreate`:

```js
	const finishGroup = ( plan, seatLimit ) => {
		const group = createGroup( { ownerId: subscriber.id, plan: plan.name, cadence: plan.cadence, amount: plan.amount, seatLimit } );
		onComplete( {
			type: 'success',
			message: sprintf( __( 'Started %1$s for %2$s.', 'newspack-plugin' ), plan.name, subscriber.name ),
			groupCreate: group,
		} );
	};
```

(Wire `PlanPicker` to call an `onTeam( plan )` prop when the chosen family is team, instead of `submit`; the shell sets a `teamPlan` state and advances to a `seat` step that renders `SeatStep`.)

- [ ] **Step 3: Persist `groupCreate` in completeFlow**

In `screens/PersonProfile.jsx`, extend `completeFlow`'s destructure and body (line ~542):

```js
	const completeFlow = ( { message, mutate, groupCancel, groupChange, groupRestore, groupCreate } ) => {
```

and before `setSnackbar`:

```js
		if ( groupCreate ) {
			setStoredGroup( groupCreate.id, groupCreate );
			setGroupsRefresh( n => n + 1 );
		}
```

- [ ] **Step 4: Build, lint, browser verify**

Add subscription, pick a Team plan, set seats, Confirm: a group card appears in the reader's Subscriptions section (owner view, seat usage), and the group shows on the Groups list (`#/groups`). Confirm seat count and owner membership are correct.

- [ ] **Step 5: Commit**

```bash
git add plugins/newspack-plugin/src/wizards/subscribersDemo/flows/AddSubscriptionFlow.jsx plugins/newspack-plugin/src/wizards/subscribersDemo/data/mock-groups.js plugins/newspack-plugin/src/wizards/subscribersDemo/screens/PersonProfile.jsx
git commit -m "feat(subscribers): add group subscriptions from the catalog" \
  -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Update the README

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Update the flows table, screens bullets, and data model**

- Remove the `ResubscribeFlow` row from the Flows table; add an `AddSubscriptionFlow` row describing: catalog picker (digital/print/team) with custom price and duplicate-family guard; three payment modes (comp with free-for-N-cycles, send payment link → pending card, charge now with card picker); notify toggle; and that it absorbs the former Resubscribe triggers.
- Update the PersonProfile screen bullet: Subscriptions section now has an "Add subscription" button and can show a Pending card (Mark as paid / Resend / Cancel); Billing history can show a "Payment link sent" event.
- Update the Data model: subscription gains `status: 'pending'`, `freeCyclesRemaining`, `linkSentAt`; group records can be created at runtime.
- Add a Known prototype shortcuts bullet: runtime-created groups are surfaced by `getAllGroups` reading store-only ids; the free-for-N-cycles conversion with no card on file reuses the existing on-hold/dunning path rather than silently failing.

- [ ] **Step 2: Commit**

```bash
git add plugins/newspack-plugin/src/wizards/subscribersDemo/README.md
git commit -m "docs(subscribers): document the add-subscription flow" \
  -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-review

**Spec coverage:**
- Shared flow absorbing ResubscribeFlow → Tasks 2, 3.
- Catalog picker spanning individual + group, adapting by selection → Tasks 4 (individual), 9 (group).
- Custom price / discount → Task 4.
- Duplicate-plan guard → Task 4.
- Free-for-N-cycles comp, applied to both entry points → Task 5.
- Send-link pending card + "Payment link sent" billing event + mark-as-paid sim → Task 7.
- Charge-now card picker (default/pick/enter) → Task 6.
- Notify toggle → Task 8.
- `pending` status, `freeCyclesRemaining`, billing event vocabulary → Tasks 1, 5, 7.
- No-card conversion fallback → documented Task 10; relies on the existing dunning path (no new state).
- README → Task 10.

**Type consistency:** the created-subscription draft fields (`id`, `plan`, `status`, `access`, `cadence`, `nextBillingDate`, `amount`, and additive `freeCyclesRemaining`/`linkSentAt`) match the data model. `groupCreate` mirrors `groupRestore`'s handling in `completeFlow`. `createGroup` returns the documented Group shape. Temporary draft markers (`_notify`, `_chargedCard`) are explicitly stripped before persistence.

**Resolved import notes:** `Button`/`Modal`/`SelectControl` come from `packages/components/src`; `Notice`/`TextControl`/`ToggleControl` come from `@wordpress/components` (Tasks 4-5). `fmtRelative` is exported from `../format` and is added to `PersonProfile`'s format import in Task 7.
