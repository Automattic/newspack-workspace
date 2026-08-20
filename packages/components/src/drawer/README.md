# Drawer

A modal panel that slides in from the right edge and spans the full height of
the viewport. The page behind it is inert.

The API is compound: a `Drawer.Root` and one subcomponent per slot. It mirrors
the `Drawer` that ships in `@wordpress/ui`, so adopting that package later, once
it stabilises, is close to a rename inside this wrapper. `Drawer.Divider` is
the one part with no counterpart there.

The parts hang off one exported object rather than the flat named exports the
rest of this package uses. That is deliberate, to keep them recognisable against
`@wordpress/ui`'s own `Drawer`. It is also the shape compound components in this
package follow, `EmptyState` included.

```jsx
import { Drawer } from 'newspack-components';
```

The component imports its own stylesheet, so the barrel ships the CSS with it.
There is nothing separate to import.

## Keep it mounted

**`Drawer.Root` takes `isOpen` and stays mounted.** It owns its own exit
animation, so it has to still be in the tree while the slide-out plays.

```jsx
// Yes.
<Drawer.Root isOpen={ isOpen } onRequestClose={ close }>
	{ slots }
</Drawer.Root>

// No. Unmounting cuts the slide-out, and the panel vanishes instead.
{ isOpen && <Drawer.Root isOpen onRequestClose={ close }>{ slots }</Drawer.Root> }
```

It renders nothing while closed, so leaving it mounted costs nothing.

## Usage

```jsx
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Icon, settings } from '@wordpress/icons';
import { Drawer } from 'newspack-components';

const [ isOpen, setIsOpen ] = useState( false );

<Drawer.Root isOpen={ isOpen } size="large" isDirty={ isDirty } onRequestClose={ () => setIsOpen( false ) }>
	<Drawer.Header>
		<Icon className="newspack-drawer__icon" icon={ settings } size={ 24 } />
		<Drawer.Title>{ __( 'Edit Styles', 'newspack-plugin' ) }</Drawer.Title>
		<Drawer.CloseIcon />
	</Drawer.Header>
	<Drawer.Content>{ fields }</Drawer.Content>
	<Drawer.Divider />
	<Drawer.Content padding={ 0 }>{ flushTable }</Drawer.Content>
	<Drawer.Footer>
		<Drawer.Action variant="secondary" closes>
			{ __( 'Cancel', 'newspack-plugin' ) }
		</Drawer.Action>
		<Drawer.Action variant="primary" isBusy={ inFlight } onClick={ save }>
			{ __( 'Save', 'newspack-plugin' ) }
		</Drawer.Action>
	</Drawer.Footer>
</Drawer.Root>
```

Every slot is optional and nothing is composed for you: a drawer with no
`Drawer.Header` renders no header markup at all.

## `Drawer.Root`

Owns the panel, the close funnel and the confirmation. Everything behavioural
lives here.

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The slots. See the constraint under [`Drawer.Content`](#drawercontent). |
| `className` | `string` | — | Additional CSS class on the panel. |
| `confirmButtonText` | `string` | `'Discard Changes'` | Confirm label on the built-in unsaved-changes dialog. |
| `confirmCloseMessage` | `React.ReactNode` | `'You have unsaved changes that will be lost. Discard changes?'` | Body of the built-in unsaved-changes dialog. |
| `contentLabel` | `string` | — | Accessible name when the design has no visible title. Ignored when a `Drawer.Title` is rendered. |
| `describedBy` | `string` | — | Id of an element describing the panel, for the frame's `aria-describedby`. |
| `isDirty` | `boolean` | `false` | Routes every close through a confirmation. See [Closing and confirmation](#closing-and-confirmation). |
| `isOpen` | `boolean` | `false` | Whether the panel is showing. Keep the Root mounted either way. |
| `onRequestClose` | `() => void` | — | **Required.** Called once a close is confirmed. |
| `requestConfirm` | `( callback: () => void ) => void` | — | Delegates confirmation to a dialog you already own. |
| `ref` | `React.Ref< HTMLDivElement >` | — | Lands on the overlay, which is what core Modal forwards a ref to. |
| `size` | `'small'` \| `'medium'` \| `'large'` \| `'x-large'` \| `'full'` | `'medium'` | Panel width. An unknown value falls back to `medium` and warns in development. |
| `style` | `React.CSSProperties` | — | Merged into the panel's own style. |

### Sizes

| `size` | Width |
|---|---|
| `small` | 280px |
| `medium` (default) | 350px |
| `large` | 480px |
| `x-large` | 640px |
| `full` | 100vw |

At 600px and below every size goes full width.

## `Drawer.Header`

A flex row, pinned above the body. Compose an icon, a `Drawer.Title` and a
`Drawer.CloseIcon` inside it; the order is yours. Takes `className` and
`children`.

Give a leading icon `className="newspack-drawer__icon"`, which stops it
shrinking and fills it with `currentcolor`.

## `Drawer.Title`

Renders an `h2` and registers its id with the Root, which wires it to the
panel's `aria-labelledby`. Takes `className` and `children`.

A single plain-string child also registers its text, and `Drawer.CloseIcon`
composes that into "Close {title}". Mixed children are not a string, so the
close button falls back to a bare "Close". Build the string first to keep the
composed label.

```jsx
// The close button is named "Close Edit Styles".
<Drawer.Title>{ __( 'Edit Styles', 'newspack-plugin' ) }</Drawer.Title>

// Interpolated, so the children are an array and the close button is
// named "Close".
<Drawer.Title>{ __( 'Edit', 'newspack-plugin' ) } { name }</Drawer.Title>

// One string child again, so the composed label is back.
// translators: %s: the item being edited.
<Drawer.Title>{ sprintf( __( 'Edit %s', 'newspack-plugin' ), name ) }</Drawer.Title>
```

## `Drawer.CloseIcon`

An icon-only button. Clicking it goes through the Root's close funnel, so the
unsaved-changes confirmation always applies.

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `className` | `string` | — | Additional CSS class. |
| `icon` | `JSX.Element` | `close` from `@wordpress/icons` | Icon to render. |
| `label` | `string` | `'Close {title}'`, or `'Close'` without one | Tooltip and accessible name. |

## `Drawer.Content`

The body. Repeatable: each `Drawer.Content` is a section, rendered as a VStack
with a 16px gap by default; `gap` changes it. Children space through the gap
alone; their own top and bottom margins are reset.

Anything can go in a section: an element, a control, a nested stack, or plain
text. Each child becomes a row separated by the gap, and a run of plain text and
string interpolations stays one row, so `Edited by { name }` reads as a sentence
rather than stacking. Whitespace between two elements is not a row of its own.

An element always starts a new row, including inside a sentence: `Edited by
<strong>{ name }</strong>` is two rows, not one. Wrap a sentence that mixes text
with markup in your own element and hand the section that instead.

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | Section content. |
| `className` | `string` | — | Additional CSS class. |
| `gap` | `number` | `4` | Space between the section's children, on the 4px scale, as VStack's `spacing`. `4` is 16px. |
| `padding` | `number` | `6` | On the 4px scale, as VStack's `spacing`. `6` is 24px; `0` is a flush section that brings its own padding. |

Consecutive sections share one scroll container, so they scroll together between
the pinned header and footer. Sections are not self-separating: put a
[`Drawer.Divider`](#drawerdivider) between two of them to draw a rule. The
first section drops its top padding when a header precedes it, and the last
section drops its bottom padding when a footer follows, so the seam at each end
is the chrome's own 16px padding. Without that chrome the section keeps its own
padding and sits against the edge of the panel.

**Sections must be direct children of `Drawer.Root`.** The Root walks its direct
children to build that scroll container. A section nested in a fragment or any
other wrapper is passed straight through and never grouped, silently. Arrays are
fine.

```jsx
// Yes.
<Drawer.Root isOpen={ isOpen } onRequestClose={ close }>
	<Drawer.Content>{ fields }</Drawer.Content>
	{ hasTable && <Drawer.Content padding={ 0 }>{ table }</Drawer.Content> }
</Drawer.Root>

// No. The fragment is one non-Content child, so neither section is grouped.
<Drawer.Root isOpen={ isOpen } onRequestClose={ close }>
	<>
		<Drawer.Content>{ fields }</Drawer.Content>
		<Drawer.Content padding={ 0 }>{ table }</Drawer.Content>
	</>
</Drawer.Root>
```

**Guard a conditional section on a boolean.** `null`, `undefined` and `false`
are dropped and leave the run intact, but `0` and `''` are not: they count as
non-Content children, so `{ items.length && <Drawer.Content/> }` with an empty
array renders a stray "0" in the drawer and splits the run in two.

```jsx
// Yes.
{ !! items.length && <Drawer.Content>{ list }</Drawer.Content> }
{ items.length ? <Drawer.Content>{ list }</Drawer.Content> : null }

// No. An empty array leaves a "0" in the drawer and a split body.
{ items.length && <Drawer.Content>{ list }</Drawer.Content> }
```

A child that is neither a `Drawer.Content` nor a `Drawer.Divider` splits the
sections around it into two scroll containers. Each container is keyed by its
position among the bodies, not among the children, so a drawer with one body
keeps it, and its scroll position, when a sibling above it is toggled. With
several bodies, a change that merges or splits an earlier run re-keys the ones
after it, which resets their scroll.

## `Drawer.Divider`

A full-width rule between two sections. It joins the run, so it sits inside the
same scroll container and travels with the sections rather than splitting them.

Takes `className`. Renders an `<hr>`, so assistive technology announces it as a
separator.

```jsx
<Drawer.Content>{ fields }</Drawer.Content>
<Drawer.Divider />
<Drawer.Content>{ moreFields }</Drawer.Content>
```

## `Drawer.Footer`

A pinned container for `Drawer.Action` elements. The layout is pure CSS on the
child count.

| Children | Layout |
|---|---|
| 1 | Row, the button fills it |
| 2 | Row, split 50/50 |
| 3 or more | Column, full-width buttons |

Convention, not enforced: with two actions pass the secondary first, so the
primary sits on the right; with three pass primary, secondary, tertiary
top-down.

Takes `className` and `children`.

## `Drawer.Action`

A footer button, wrapping the Newspack `Button` so `href` and router behaviour
work as they do elsewhere. Children are the visible label.

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `ariaLabel` | `string` | — | Extends the visible label with context. Must contain it. |
| `children` | `React.ReactNode` | — | The visible label. |
| `className` | `string` | — | Additional CSS class. |
| `closes` | `boolean` | `false` | Requests the drawer close through the Root's funnel after `onClick`, so the unsaved-changes confirmation applies. |
| `disabled` | `boolean` | — | Disables the button. |
| `href` | `string` | — | Renders a link instead of a button. Cannot be combined with `onClick` or `closes`. |
| `isBusy` | `boolean` | — | Busy styling, for a save in flight. |
| `isDestructive` | `boolean` | — | Destructive styling. |
| `onClick` | `( event?: React.MouseEvent ) => void` | — | Click handler. Receives the event. |
| `variant` | `'primary'` \| `'secondary'` \| `'tertiary'` \| `'link'` | — | Button variant. |

`ariaLabel` renders as a plain `aria-label`, not the WP `Button` `label` that
would put a tooltip on a button which already has visible text.

An action either navigates or acts. `href` with `onClick` or `closes` is a type
error: the underlying `Button` drops the `href` as soon as a handler is present,
and a link that also runs the close funnel would navigate away while the
unsaved-changes confirmation was still opening.

Without `closes`, an action runs the `onClick` you give it and nothing else,
and `isDirty` does not cover it. Give a Cancel or Close action `closes` so it
goes through the same funnel as `Drawer.CloseIcon`; leave it off a Save, which
should close by flipping `isOpen` once its work succeeds.

## Closing and confirmation

Every close route, the `Drawer.CloseIcon`, Escape and an overlay press, goes
through one handler in the Root. Core Modal's own close paths are switched off,
so nothing can close the panel behind that handler.

**`isDirty` false.** The handler calls `onRequestClose`, the parent sets `isOpen`
false, and the slide-out plays.

**`isDirty` true.** The handler opens the confirm dialog above the drawer, which
has not moved. "Discard Changes" calls `onRequestClose`, and only then does the
slide-out play. Cancel does nothing and the panel is still where it was. A
refused close never animates.

Raise `isDirty` while a save is in flight too, so an in-progress request cannot
be closed out from under.

```jsx
<Drawer.Root isOpen={ isOpen } isDirty={ isDirty || inFlight } onRequestClose={ close }>
	{ slots }
</Drawer.Root>
```

**Footer actions with `closes` are covered.** `closes` sends the action through
the same handler as the close icon, so a Cancel written that way gets the
confirmation and a Save without it does not.

```jsx
<Drawer.Footer>
	<Drawer.Action variant="secondary" closes>{ __( 'Cancel', 'newspack-plugin' ) }</Drawer.Action>
	<Drawer.Action variant="primary" onClick={ save }>{ __( 'Save', 'newspack-plugin' ) }</Drawer.Action>
</Drawer.Footer>
```

The action's own `onClick` runs *before* the handler, so its side effects survive
a cancelled confirmation. Keep anything that must not happen twice out of an
action that also closes.

**Another modal opening closes a clean panel, and leaves a dirty one alone.**
Core shows one modal at a time and dismisses the drawer as a sibling mounts. A
clean panel takes that and closes. A dirty one does not answer it at all: its own
confirmation would render underneath the modal that just opened, and a delegated
confirmation *is* the modal that triggered the dismissal. So a dirty panel stays
open behind the new dialog and is still there once it is dealt with.

**Inside a wizard that already runs `useUnsavedChangesDialog`, pass its
`requestConfirm`.** The Root then delegates and mounts no dialog of its own. Two
live dialogs prompt each other: the Root's cancel path calls `history.replace`,
which the wizard's navigation blocker catches.

**The guard's `when` has to cover everything the Root's `isDirty` covers.** A
delegated `requestConfirm` runs its callback immediately, with no dialog, when
its own `when` is false. The Root cannot see that flag, so a panel whose
`isDirty` is true while the guard's `when` is false closes with no prompt and
loses the edits. Drive both from the same state, as below, or widen `when` to
include the panel's. The Root warns in development when it notices a delegated
confirmation closing the panel without prompting.

**A delegated dialog is yours to dismiss.** The Root only ever withdraws the one
it owns. If the panel closes while your prompt is up — a save that lands while
the user is still looking at it — the prompt stays, and answering it calls
`onRequestClose` on a panel that has already gone. Call the hook's
`cancelConfirm` yourself when you close the panel out from under it. The Root
deliberately does not: the same dialog serves the whole wizard, and it has no way
to tell a prompt it raised from one your own navigation guard raised.

```jsx
const { confirmDialog, requestConfirm, cancelConfirm } = useUnsavedChangesDialog( { when: isDirty } );

const save = async () => {
	await persist();
	// The panel closes on its own here, so withdraw the prompt along with it.
	cancelConfirm();
	setIsOpen( false );
};

<Drawer.Root
	isOpen={ isOpen }
	isDirty={ isDirty }
	requestConfirm={ requestConfirm }
	onRequestClose={ close }
>
	{ slots }
</Drawer.Root>
{ confirmDialog }
```

## Accessibility

**Name the panel.** A `Drawer.Title` supplies `aria-labelledby`; a `contentLabel`
on the Root supplies `aria-label` when the design has no visible title. With
neither, the Root warns in development: an unnamed dialog is announced without a
name when focus enters it.

**Give terse actions context.** "Save" alone answers neither "save what" nor
"save where" for someone who lands on the button directly. `ariaLabel` extends
the visible text:

```jsx
<Drawer.Action variant="primary" ariaLabel={ __( 'Save styles', 'newspack-plugin' ) } onClick={ save }>
	{ __( 'Save', 'newspack-plugin' ) }
</Drawer.Action>
```

`ariaLabel` **must contain** the visible label. Voice control matches spoken
commands against the accessible name, so a button reading "Save" whose name is
"Apply changes" stops responding to "click Save" (WCAG 2.5.3, Label in Name).
`Drawer.Action` warns in development when the visible string is missing from
`ariaLabel`. Icon-only actions are not supported, since there is no visible text
to extend.

The rule holds per locale, and the check only sees the translated strings. The
two land in the POT as unrelated entries, so give the `ariaLabel` a `translators:`
comment naming the visible label it has to contain — otherwise the guarantee is
lost in any locale where they drift apart, and the warning only fires for someone
running that locale in development.

```jsx
/* translators: extended label for the Save button. Must contain the word "Save" as translated below. */
ariaLabel={ __( 'Save styles', 'newspack-plugin' ) }
```

**Reduced motion.** Under `prefers-reduced-motion: reduce` the slide and the
scrim fade are dropped, and the panel is removed at once rather than waiting on
an animation that never runs.

**Scrolling without a mouse.** A scroll container that holds nothing focusable
cannot be reached from the keyboard in every browser, so the body takes
`tabindex="0"` and the "Scrollable section" label whenever it actually overflows,
and drops both when it does not (WCAG 2.1.1). Core Modal does this for its own
container, but the drawer scrolls in the body instead, leaving that one no taller
than its box. Overflow is measured on the body and its sections, so a section
that grows after mount is picked up.

## Popovers

The Root wraps its children in a `SlotFillProvider` and renders a `Popover.Slot`
beside them, so dropdowns and pickers inside the drawer stay visible. Without
both, a popover
portalled to the body would land in a container Modal has marked `aria-hidden`,
and our own `modal/style.scss` blanks popovers while a modal is open.

That provider starts a fresh registry rather than chaining to a parent one. **A
`Fill` rendered inside the drawer cannot reach a `Slot` outside it**: it renders
nothing, silently. Move the `Slot` inside the drawer.

## Outside the Root

`Drawer.Title` and `Drawer.CloseIcon` read the Root's private context and throw
"Drawer subcomponents must be rendered inside Drawer.Root." when they are
rendered anywhere else.

## On a `@wordpress/components` upgrade

The panel reaches into core Modal in three places that are internals rather than
a documented contract, and the peer range is a caret. Check them on a bump:

- The Root finds the frame with `.components-modal__frame` inside the node Modal
  forwards its ref to, and uses it for focus return and `inert`. It warns in
  development when that lookup comes back empty.
- `__experimentalHideHeader` suppresses core's own header. Losing it puts a
  second header above `Drawer.Header`.
- `style.scss` targets `.components-modal__content` and
  `.components-modal__children-container` for the panel's layout.
- `shouldCloseOnEsc={ false }`, `shouldCloseOnClickOutside={ false }` and
  `__experimentalHideHeader` are what leave core with no close path of its own.
  Re-enabling any of them — a header for `headerActions`, say — gives core a way
  to close a dirty panel with no confirmation.

Core Modal is externalised to the `wp.components` runtime global at build time,
so the version that actually runs is the one on the publisher's WordPress, not
the one resolved here.
