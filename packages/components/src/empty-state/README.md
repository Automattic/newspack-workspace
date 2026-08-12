# EmptyState

The "nothing here yet" treatment for a list screen or an onboarding view: an
icon, a title, a short description, and usually one call to action.

The API is compound: an `EmptyState.Root` and one subcomponent per slot. The
parts hang off one exported object, as `Drawer`'s do.

Root brings the layout its consumers used to hand-write, including the `start`
and `end` attributes the `Grid` stylesheet matches on. It brings no stylesheet
of its own.

## Importing

The package barrel and the component's own entry point both work:

```jsx
// The barrel.
import { EmptyState } from 'newspack-components';

// The component on its own.
import EmptyState from '../../packages/components/src/empty-state';
```

Take the barrel where the bundle already pulls the package in wholesale, as the
newsletters admin shell does. Import by path where a bundle should stay narrow:
the barrel reaches `Page`, whose stylesheet carries a `:root` block of
design-system token overrides, and that block then rides into every bundle that
touches the barrel.

One by-path import does not settle it, and today none of them do. The package
declares no `sideEffects`, so a bundler cannot drop anything the barrel
re-exports, and every newspack-plugin screen using `EmptyState` still imports
`Button` or `Card` from the barrel on a neighbouring line. Until the package
declares `sideEffects`, importing by path is the direction of travel rather than
a saving already banked.

## Usage

```jsx
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { envelope } from '@wordpress/icons';
import { EmptyState } from 'newspack-components';

<EmptyState.Root className={ EMPTY_STATE_CLASS }>
	<EmptyState.Header
		icon={ envelope }
		title={ __( 'Get started with newsletters', 'newspack-newsletters' ) }
		description={ __( 'Compose, schedule, and send newsletters to your subscribers.', 'newspack-newsletters' ) }
	/>
	<EmptyState.Actions>
		<Button variant="primary" href={ addNewHref }>
			{ __( 'Add Newsletter', 'newspack-newsletters' ) }
		</Button>
	</EmptyState.Actions>
</EmptyState.Root>
```

Every slot except `Root` is optional, and anything else you pass to `Root`
becomes a sibling of the header at the same 8-unit gap. A screen that offers
choices rather than one action can drop a stack of cards in instead of
`EmptyState.Actions`. Pass elements: `Root`'s stack drops bare strings, so wrap
loose text in a `<p>`.

## Consumers own their wrappers

The component does not position itself on the page, so each screen decides
whether it needs a wrapper at all.

Pass a class to `Root` when the styling targets the empty state itself. The
newsletters screens do that, exporting the class from the shell rather than
repeating the literal: the shell keys `:has()` off it to hide its header and
hold the main region to 1006px, and both are rules about an empty state being
on screen.

Wrap `Root` in your own element when the wrapper is page layout that would
still be there without an empty state. `institutions/onboarding.tsx` does that:
`newspack-wizard__column` is the wizard's own column width, and the view
would want it whatever it rendered.

## Strict-empty only

**Render this when the *unfiltered* collection is empty.** A search or filter
that matches nothing keeps the DataViews "no results" treatment, which tells
the reader their query was too narrow rather than that they have nothing.

The component cannot enforce that: it never sees the collection. In the
newsletters admin shell the rule lives in `isStrictlyEmpty`.

## Actions take any button

`EmptyState.Actions` renders whatever you give it, so each consumer keeps its
own `Button`. newspack-newsletters passes the `@wordpress/components` one and
newspack-plugin passes this package's.

That is also why there is no CTA invariant. The component this replaced took a
`ctaHref` / `ctaOnClick` pair and required exactly one, throwing in development.
With a children slot there is no pair to check. A button that navigates takes
`href`; one that opens something in place takes `onClick`.

## `EmptyState.Root`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The slots, plus any custom body. |
| `className` | `string` | — | Merged onto the grid. |
| `size` | `'default'` \| `'small'` | `'default'` | Read by `EmptyState.Header`. `small` suits an empty state standing in for a panel inside a card. |

The grid always carries `newspack-empty-state`, and `className` lands there
rather than on a wrapper, because consumers key off both. With no stylesheet in
the component, that class is the whole styling surface of the spine.

## `EmptyState.Header`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `className` | `string` | — | Merged onto `.newspack-section-header__container`, alongside `newspack-empty-state__header`. |
| `description` | `React.ReactNode` | — | One or two sentences on what would fill the screen. |
| `heading` | `number` | `3` when small, `2` otherwise | HTML heading level. |
| `icon` | `JSX.Element` | — | From `@wordpress/icons` or `newspack-icons`. |
| `title` | `string` | — | **Required.** |

`heading` follows `size` by default but stays yours to set. Heading level is a
document-outline concern rather than a visual one, so a headerless screen that
needs this to be its `h1` passes `heading={ 1 }`.

## `EmptyState.Actions`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | Usually one primary button. |
| `className` | `string` | — | Merged onto the stack. |
| `orientation` | `'row'` \| `'column'` | `'row'` | `column` stacks the actions, for a button above a link or an explanatory note. |
| `spacing` | `number` | `2` | Gap between actions, on the `@wordpress/components` spacing scale. |

A centred stack, carrying `newspack-empty-state__actions`. With one action,
prefer a single primary button: an empty state asking for two decisions at once
is usually a sign the screen needs an onboarding view instead.

## Outside the Root

`EmptyState.Header` and `EmptyState.Actions` read Root's context and throw
"EmptyState subcomponents must be rendered inside EmptyState.Root." when
rendered anywhere else.
