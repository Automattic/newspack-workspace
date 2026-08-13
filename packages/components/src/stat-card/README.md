# StatCard

One figure presented as a scorecard: what it is, the number itself, and what the
number counts. Cards sit in a row, so they share a type scale and a null
treatment rather than each screen inventing its own.

The API is compound: a `StatCard.Root` and one subcomponent per slot. The parts
hang off one exported object rather than this package's usual flat exports,
which keeps a six-part component to one name on the barrel.

## Importing

The package barrel and the component's own entry point both work:

```jsx
// The barrel.
import { StatCard } from 'newspack-components';

// The component on its own.
import StatCard from '../../packages/components/src/stat-card';
```

The card's chrome is not self-contained. `Card.Root` from `@wordpress/ui` takes
its background, border, radius and padding from `--wpds-*` custom properties
with no fallbacks, and this package defines those in one place only:
`page/style.scss`, which imports the design-token sheet. The package's own
`style.scss` separately remaps `--wp-admin-theme-color` to the Newspack primary,
which is the hero figure's colour. Both ride in with the barrel.

So a by-path import keeps the bundle narrow at the cost of the card's
appearance, unless that bundle already renders `Page` or pulls the token sheet
in some other way. Without it the card is an unpadded, borderless, transparent
box and the figure takes the publisher's wp-admin colour scheme. Take the narrow
import where the screen already has the tokens; take the barrel otherwise.

## Usage

```jsx
import { __ } from '@wordpress/i18n';
import { StatCard } from 'newspack-components';

<StatCard.Root>
	<StatCard.Label>{ __( 'Subscribers reached', 'newspack-plugin' ) }</StatCard.Label>
	<StatCard.Body>
		<StatCard.Value value="1,284" />
	</StatCard.Body>
	<StatCard.Footer>
		{ __( 'Readers who received at least one campaign this month.', 'newspack-plugin' ) }
	</StatCard.Footer>
</StatCard.Root>
```

Every slot except `Root` is optional. `Body` is what pins `Footer` to the bottom
of the card, so a row of cards with descriptions of different lengths still has
its numbers on one line.

## The figure is the caller's to format

`StatCard.Value` takes a string or a number, not an element. Currency symbols,
thousands separators, percentages, abbreviated millions and locale all belong to
the screen that knows what the figure means; the component only sizes it.

The one thing it does own is the absence of a figure. Pass `value={ null }` and
it renders the null glyph, standing in for "there is no number here" as opposed
to a zero that genuinely is one. `undefined` takes the same path, so
`value={ data?.count }` is safe before the data arrives.

## Scale and the container query

The hero figure is `clamp( 20px, 14cqi, 44px )`, against a
`container-type: inline-size` on `Root`. A four-figure number in a narrow column
shrinks to fit rather than overflowing or forcing a smaller fixed size on every
card in the row, and the floor stops it shrinking under its own label.

That query is why the parts throw outside a `Root`: a `StatCard.Value` rendered
loose would size against whichever container it happened to land in, which fails
quietly and looks like a styling bug.

Inline-size containment has a second consequence: the card contributes nothing
to its own intrinsic width, so **the parent layout has to give `Root` a definite
inline size**. A grid track or a `flex: 1` item is fine. Dropped somewhere its
width would come from its contents, such as an `inline-block` or a table cell,
it collapses to nothing. Equal widths across a row are what keep one type scale
across that row.

For a hero that is a phrase rather than a number ("0 of 17", "No conversions"),
pass `variant="text"`. It keeps the slot and drops the display scale, which
would otherwise wrap a sentence across three lines.

## Naming the figure to screen readers

A visible figure whose meaning rests on punctuation or a glyph needs saying
differently out loud. `valueLabel` replaces the spoken text: the visible span
goes `aria-hidden`, and the label follows in `.screen-reader-text`.

This is deliberately not `role="img"` with an `aria-label`. ARIA prohibits
naming a generic element, so the label needs a role to survive, and `img` makes
NVDA and VoiceOver announce "graphic" for what is a typographic placeholder.
Hiding the glyph and supplying real text avoids both.

The null glyph gets "Not applicable" by default. Pass `valueLabel` to say
something more specific, e.g. why the figure is missing. An empty `valueLabel`
falls back to that default rather than leaving the glyph unnamed.

## Anatomy, not policy

The component is the chrome, the layout and the type scale. Anything that
decides *what* to show is the consumer's.

That line matters most for Insights, whose `MetricCard` wraps this one and adds
period-over-period deltas, warming states, "not configured" overlays and
zero-fallback heroes. None of that belongs here; a rule about a Google Analytics
property is not a rule about a card. `MetricCard` composes the slots and keeps
its own props.

## `StatCard.Root`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The slots. |
| `className` | `string` | — | Merged onto the card, alongside `newspack-stat-card`. |
| `heading` | `2`–`6` | `3` | Heading level for `StatCard.Label`, passed through context. |

Renders `Card.Root` / `Card.Content` from `@wordpress/ui`, and owns the
container query. Forwards a ref to the card element, and passes any other props
(`id`, `style`, `data-*`) through to it, so a wrapper can reach the DOM node.

## `StatCard.Label`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The label text. |
| `className` | `string` | — | Merged onto the label row, not the heading. |
| `heading` | `2`–`6` | Root's | Overrides the level set on `Root`. |
| `suffix` | `React.ReactNode` | — | Rendered beside the heading, e.g. an info button. |

`suffix` sits next to the heading rather than inside it, so a control there stays
out of the document outline and off the heading's accessible name.

A level outside 2–6 falls back to `3` and warns outside production, rather than
rendering an element that is not a heading at all.

## `StatCard.Body`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The value, plus anything that belongs with it. |
| `className` | `string` | — | Merged onto the body. |

A column that takes the free space. Put `StatCard.Value` in it, plus a
`StatCard.Secondary` line or a consumer-owned element such as a delta.

## `StatCard.Value`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `className` | `string` | — | Merged onto the value. |
| `value` | `string` \| `number` \| `null` | — | **Required.** Pre-formatted. `null` renders the null glyph. |
| `valueLabel` | `string` | "Not applicable" when null | Spoken instead of the visible value. |
| `variant` | `'figure'` \| `'text'` | `'figure'` | `text` drops the hero scale for a phrase. |

## `StatCard.Secondary`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | A short qualifying line under the value. |
| `className` | `string` | — | Merged onto the line. |

## `StatCard.Footer`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The description, plus any action. |
| `className` | `string` | — | Merged onto the footer. |

Pinned to the bottom. A run of text children shares one `<p>` carrying the
description styling, so `<StatCard.Footer>Applies to { count } products</StatCard.Footer>`
is one sentence rather than three stacked lines; elements pass through
untouched, which is how an action lands under the text.

An action keeps the description's type scale by taking the
`newspack-stat-card__action` class:

```jsx
<StatCard.Footer>
	{ __( 'Products this rule applies to.', 'newspack-plugin' ) }
	<Button isLink className="newspack-stat-card__action" onClick={ onView }>
		{ __( 'See the products', 'newspack-plugin' ) }
	</Button>
</StatCard.Footer>
```

## `STAT_CARD_NULL_GLYPH`

The glyph `StatCard.Value` shows for `null`, exported so a table under a row of
cards can show the same one:

```jsx
import { STAT_CARD_NULL_GLYPH } from 'newspack-components';
```

## Outside the Root

Every subcomponent reads Root's context and throws "StatCard subcomponents must
be rendered inside StatCard.Root." when rendered anywhere else.
