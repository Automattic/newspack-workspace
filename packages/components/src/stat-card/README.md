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

Both are safe. `Card.Root` from `@wordpress/ui` takes its background, border,
radius and padding from `--wpds-*` custom properties, which arrive with the
design-token sheet that `page/style.scss` imports, and that sheet rides in with
the barrel. But the CSS `@wordpress/ui` actually ships carries a fallback on
each of those properties, and the fallbacks are the same light-theme values the
token sheet sets, so a card outside the sheet renders the same chrome. Only a
consumer opting into non-default theme settings, a different corner radius say,
would see the two diverge.

The figure is unaffected either way: the card declares the Newspack accent on
itself rather than relying on the package's global remap.

The exported prop types travel with neither route. The barrel is a `.js` file so
it cannot re-export types, and the package ships no declarations (it compiles
with Babel and sets no `types` field), so `StatCardRootProps` and its siblings
are reachable only through a path import into `src/stat-card` from inside this
monorepo.

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

The hero figure is `clamp( 20px, 14cqi, 48px )`, against a
`container-type: inline-size` on `Root`. A four-figure number in a narrow column
shrinks to fit rather than overflowing or forcing a smaller fixed size on every
card in the row, and the floor stops it shrinking under its own label.

The ceiling and its ratio are `$font-size-3x-large` and
`$font-line-height-3x-large` in the package's `src/_variables.scss`, which
carries the steps this package needs above the `@wordpress/base-styles` scale.
The line height is unitless on purpose: the font size is fluid, so a fixed
value from the base-styles pairs would drift out of proportion as the figure
shrinks.

That query is why the parts throw outside a `Root`: a `StatCard.Value` rendered
loose would size against whichever container it happened to land in, which fails
quietly and looks like a styling bug.

Inline-size containment has a second consequence: the card contributes nothing
to its own intrinsic width, so **the parent layout has to give `Root` a definite
inline size**. A grid track or a `flex: 1` item is fine. Dropped somewhere its
width would come from its contents, such as an `inline-block` or a table cell,
it collapses to nothing. Equal widths across a row are what keep one type scale
across that row.

It also makes the card a containing block for `position: absolute` and
`position: fixed` descendants, and `Card.Root` clips its overflow. Anything
positioned that renders inline inside the card, such as a popover on a control
in `suffix`, is therefore trapped by the card unless it portals out. The
tooltips and popovers in `@wordpress/components` portal by default, so this
mostly matters if a consumer registers its own `Popover.Slot` inside a card.

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
| `suffix` | `React.ReactNode` | — | Rendered in a row beside the figure, e.g. a `StatCard.Delta`. |
| `value` | `string` \| `number` \| `null` \| `undefined` | — | **Required.** Pre-formatted. `null` and `undefined` render the null glyph. |
| `valueLabel` | `string` | "Not applicable" when null | Spoken instead of the visible value. |
| `variant` | `'figure'` \| `'text'` | `'figure'` | `text` drops the hero scale for a phrase. |

With a `suffix`, the figure and the suffix share a baseline-aligned row. Without
one, the figure renders on its own with no extra wrapper.

## `StatCard.Delta`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The change, pre-formatted. |
| `className` | `string` | — | Merged onto the delta. |
| `direction` | `'up'` \| `'down'` | — | **Required.** Which arrow to show. |
| `directionLabel` | `string` | "Up" or "Down" | Spoken in place of the direction. |
| `tone` | `'positive'` \| `'negative'` \| `'neutral'` | `'neutral'` | Which colour to use. |

```jsx
<StatCard.Value
	value="1,284"
	suffix={ <StatCard.Delta direction="up" tone="positive">2%</StatCard.Delta> }
/>
```

**`direction` and `tone` are deliberately separate.** A rise is not always good
news: a refund rate climbing 2% wants an up arrow and a negative tone. The
component owns the arrow, the size and the colour; the caller, which is the only
one that knows what the figure means, decides which of them applies.

The arrow is `aria-hidden` and its meaning supplied as text, so the delta reads
as "Up 2%" rather than as a glyph. That also means the direction survives for
anyone who cannot use the colour, which the colour alone would not.

## `StatCard.Secondary`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | A short qualifying line under the value. |
| `className` | `string` | — | Merged onto the line. |

It takes the figure's colour and a heading scale, so it reads as part of the
headline rather than a note under it. The quiet line is the footer's description.

## `StatCard.Footer`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The description, plus any action. |
| `className` | `string` | — | Merged onto the footer. |

Pinned to the bottom. A run of text children shares one `<p>` carrying the
description styling, so `<StatCard.Footer>Applies to { count } products</StatCard.Footer>`
is one sentence rather than three stacked lines; elements pass through
untouched, which is how an action lands under the text.

An element ends the run, so a description with inline markup in the middle of it
would be split across several blocks. Wrap that description yourself and it
passes through as one:

```jsx
<StatCard.Footer>
	<p className="newspack-stat-card__description">
		Applies to <strong>12</strong> products.
	</p>
</StatCard.Footer>
```

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
