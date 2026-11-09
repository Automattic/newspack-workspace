# TableCard

A card frame for a table: an optional heading, the table shown edge-to-edge,
and padded slots above and below it. It pins the composition the Gutenberg
DataViews docs demonstrate as "With Card" — `@wordpress/ui`'s compound `Card`
with the table in `Card.FullBleed` — so screens don't each reassemble the
spacing and slot order by hand.

Nothing in it is DataViews-specific. Any content that should run to the card's
edges works: a DataViews table, a plain `<table>`, an embed.

```jsx
import { TableCard } from 'newspack-components';
```

## When to use it

Every DataViews list that isn't on a full-width page goes in a `TableCard`. A shared `DataViews` inside the card switches to the card's padding on its own; see the [DataViews README](../dataviews/README.md).

## Usage

```jsx
import { __ } from '@wordpress/i18n';
import { useId } from '@wordpress/element';
import { TableCard } from 'newspack-components';

const titleId = useId();

<TableCard
	title={ __( 'Price Schedule', 'newspack-plugin' ) }
	titleId={ titleId }
	actions={ <Button variant="tertiary" size="compact">{ __( 'Add Price', 'newspack-plugin' ) }</Button> }
>
	<div role="region" aria-labelledby={ titleId }>
		<DataViews … />
	</div>
</TableCard>
```

## Props

- `title` — optional heading, rendered in a `Card.Header` as a real heading
  element (`h3` by default; see `heading`). `0` renders; `''`/`null`/
  `undefined`/booleans (the leftovers of `cond && '…'`) render no heading.
- `titleId` — id placed on the heading element, so a region inside the card can
  name itself with `aria-labelledby` instead of duplicating the visible title
  in an `aria-label`. Only meaningful together with `title`: with no heading
  rendered, an `aria-labelledby` pointing at it names nothing.
- `heading` — heading level for the title, `1`–`6`. Defaults to `3`, one level
  under the `SectionHeader` (`h2`) most wizard screens use.
- `actions` — controls rendered in the card header: opposite the title when
  there is one, end-aligned on their own otherwise. A header renders when
  either `title` or `actions` is renderable (same falsy rules as `title`).
- `before` / `after` — content rendered inside the card padding, above and
  below the table: stats rows, notes, footer actions. Slot children stretch to
  the card's full content width; wrap a lone control in a `Stack` from
  `@wordpress/ui` to keep its natural width.
- `className` — forwarded to `Card.Root`, the card's outermost element.
- `children` — the table itself, rendered edge-to-edge via `Card.FullBleed`.
  The card supplies no cell padding: pad the table's first and last columns to
  the card's own padding (24px — `$grid-unit-30`, matching upstream's
  `--wpds-dimension-padding-2xl`) or the outer columns land against the card
  border.

## How the edges behave

Whether the table touches the card's top and bottom edges is decided by
upstream `:first-child`/`:last-child` rules, and absent slots render no DOM
node, so the shape follows from which props are passed:

- No `title`, no `actions`, no `before`, no `after` — the table is flush to
  all four card edges.
- `title` or `actions` present — a header sits above; the table is inset from
  the top by the card's header→content gap token (upstream's
  `.header + .content` rule zeroes the content's own top padding and reconciles
  the header's bottom padding to that gap with a compensating margin).
- `before` / `after` present — the slot sits in the padding and the table no
  longer touches that edge.

A slot that appears conditionally (a "Show N more" button only when the table is
long) therefore changes the card's shape with the condition. That can be the
right call — a card with a footer control legitimately looks different from one
without — but make it deliberately, knowing a data-size threshold can flip it.

## On a `@wordpress/ui` upgrade

The component is a thin composition over `@wordpress/ui` internals that are not
a stable contract. The package pins `^0.16.0` (0.x, so 0.16.x only); when
bumping past it, re-check:

- `Card.FullBleed`'s placement rules — the edge behaviour above comes from its
  stylesheet, not from this component.
- `Card.Content` composed with `Stack` via the `render` prop — the documented
  way to add inter-slot spacing while keeping `FullBleed` a direct child.
- `Card.Title`'s default rendering (a `div`; this component swaps in a heading
  via `render`) and the global-CSS-defense rules that keep wp-admin's heading
  styles from restyling it.
- That the card padding is still 24px — a Newspack `DataViews` inside the card
  keeps the 24px list gutter to match it, and the two values drift independently.
- Every consumer, visually: Group detail's Members and Invitations cards, the
  rule editor's Price Schedule and Affected Products cards, and the
  components-demo section, which is the cheapest place to eyeball a bump.
