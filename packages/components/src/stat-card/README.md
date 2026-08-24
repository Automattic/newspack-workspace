# StatCard

One figure presented as a scorecard: what it is, the number itself, and what the
number counts. Cards sit in a row, so they share a type scale and a null
treatment rather than each screen inventing its own.

The API is compound: a `StatCard.Root` and one subcomponent per slot. As `Drawer`
does, the parts hang off one exported object, which keeps a seven-part component
to one name on the barrel.

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
itself rather than relying on the package's global remap. It declares the darker
and lighter steps with it, which its own rules never touch, so that a control in
a slot, a button in `suffix` or in `Footer`, takes the same accent as the figure
for its fill, its hover and its focus ring. Through the barrel those values are
already global and the block changes nothing; it earns its keep on the
deep-import route the rest of this package recommends.

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
to a zero that genuinely is one. `undefined` and a blank string take the same
path, so `value={ data?.count }` is safe before the data arrives, and a field
that reports "no data" as `""`, or as a padded sentinel, gets the glyph rather
than an empty hero.

A zero is a figure and renders as one. That distinction is the whole reason the
glyph exists, so nothing in the component may treat `0` as missing.

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

That query is why the parts insist on a `Root`: a `StatCard.Value` rendered
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
in `suffix`, is therefore trapped by the card unless it portals out.
`InfoButton` portals to the shared overlay slot and the tooltips and popovers in
`@wordpress/components` portal by default, so this mostly matters if a consumer
registers its own `Popover.Slot` inside a card.

For a hero that is a phrase rather than a number ("0 of 17", "No conversions"),
pass `variant="text"`. It keeps the slot and drops the display scale, which
would otherwise wrap a sentence across three lines.

## Naming the figure to screen readers

A visible figure whose meaning rests on punctuation or a glyph needs saying
differently out loud. `valueLabel` replaces the spoken text: the visible span
goes `aria-hidden`, and the label follows in a `VisuallyHidden` from
`@wordpress/ui`, which brings its own CSS. The card asks nothing of the host
page for that, where wp-admin's `.screen-reader-text` would have made every
consumer's stylesheet part of the contract.

This is deliberately not `role="img"` with an `aria-label`. ARIA prohibits
naming a generic element, so the label needs a role to survive, and `img` makes
NVDA and VoiceOver announce "graphic" for what is a typographic placeholder.
Hiding the glyph and supplying real text avoids both.

The null glyph gets "Not applicable" by default, or whatever `labels` on the
`Root` puts in its place. Pass `valueLabel` to say something more specific, e.g.
why the figure is missing. An empty or blank `valueLabel` falls back to that
default rather than hiding the figure behind a name that says nothing.

### Outside `newspack-plugin`

The spoken defaults, "Not applicable", "Up" and "Down", carry the
`newspack-plugin` text domain, as every string in this package does. WordPress
resolves JS translations per script handle, so a bundle registered against
another domain never loads them and they read in English.

`labels` on `StatCard.Root` replaces all three at once, from whichever domain the
consumer is registered under:

```jsx
<StatCard.Root
	labels={ {
		notApplicable: __( 'Not applicable', 'newspack-manager' ),
		up: __( 'Up', 'newspack-manager' ),
		down: __( 'Down', 'newspack-manager' ),
	} }
>
```

A wrapper component that renders the `Root`, which is how both adopters use this,
sets it once and every card underneath is right. `valueLabel`, `directionLabel`
and `label` are unchanged: they remain the per-instance override for one figure
or one change that needs saying differently, and they still win. A blank entry in
`labels` falls back to the built-in default, so a translation that came back
empty cannot leave the glyph or the arrow unnamed.

## Anatomy, not policy

The component is the chrome, the layout and the type scale. Anything that
decides *what* to show is the consumer's.

That line matters most for Insights, whose `MetricCard` wraps this one and adds
period-over-period deltas, warming states, "not configured" overlays and
zero-fallback heroes. None of that belongs here; a rule about a Google Analytics
property is not a rule about a card. `MetricCard` composes the slots and keeps
its own props.

## Refs and pass-through props

Every part forwards a ref to the element it renders and passes any prop its own
table does not name straight through to that element, so `id`, `style`, `title`
and `data-*` all land on the DOM node:

| Part | The element it renders |
|------|------------------------|
| `Root` | The card |
| `Label` | The label row, not the heading |
| `Body` | The body column |
| `Value` | The figure, not the row it shares with a `suffix` |
| `Delta` | The delta |
| `Secondary` | The secondary line |
| `Footer` | The footer column |

That is what lets a wrapper hang the unabbreviated amount off a `$1.2M` without
wrapping the figure in an element the body layout would then have to carry.

## Class names

The component emits these, so a stylesheet can hook onto any of them:

| Class | Element |
|-------|---------|
| `newspack-stat-card` | The card |
| `newspack-stat-card__content` | The content column |
| `newspack-stat-card__label` | The label row |
| `newspack-stat-card__label-text` | The heading inside that row |
| `newspack-stat-card__body` | The body column |
| `newspack-stat-card__figure` | The row the figure shares with a `Value` `suffix`, present only when there is one |
| `newspack-stat-card__value` | The figure |
| `newspack-stat-card__delta` | The delta |
| `newspack-stat-card__secondary` | The secondary line |
| `newspack-stat-card__footer` | The footer column |
| `newspack-stat-card__description` | Each paragraph of the description |

`newspack-stat-card__action` is the one exception, and it runs the other way: the card
carries the rule but never applies the class, so an action in `Footer` takes it by hand.

## `StatCard.Root`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The slots. |
| `className` | `string` | — | Merged onto the card, alongside `newspack-stat-card`. |
| `heading` | `2`–`6` | `3` | Heading level for `StatCard.Label`, passed through context. |
| `labels` | `{ notApplicable, up, down }` | The built-in strings | Spoken defaults for every card underneath. See [Outside `newspack-plugin`](#outside-newspack-plugin). |

Renders `Card.Root` / `Card.Content` from `@wordpress/ui`, and owns the
container query.

## `StatCard.Label`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The label text. |
| `className` | `string` | — | Merged onto the label row, not the heading. |
| `heading` | `2`–`6` | Root's | Overrides the level set on `Root`. |
| `suffix` | `React.ReactNode` | — | Rendered beside the heading, e.g. an info button. |

`suffix` sits next to the heading rather than inside it, so a control there stays
out of the document outline and off the heading's accessible name. Supplementary
context belongs in an `InfoButton`, which already carries the popup, the touch
behaviour and the accessible name; the slot itself takes anything.

The card pulls an `InfoButton` in that slot back to the 20px line
`heading-large()` gives the heading, so a card carrying one and a card without
still have their figures level. The button makes no assumption about its host, so
the trim lives here rather than on it.

```jsx
<StatCard.Label
	suffix={
		<InfoButton
			description={ __( 'Averaged across the timeframe.', 'newspack-plugin' ) }
			triggerLabel={ __( 'More information about Average order value', 'newspack-plugin' ) }
		/>
	}
>
	{ __( 'Average order value', 'newspack-plugin' ) }
</StatCard.Label>
```

Any other control in that slot has to hold the 20px line itself, or it grows the
row and drops that card's figure below the rest.

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
| `value` | `string` \| `number` \| `null` \| `undefined` | — | **Required.** Pre-formatted. `null`, `undefined` and a blank string render the null glyph. |
| `valueLabel` | `string` | Root's `notApplicable` when null | Spoken instead of the visible value. |
| `variant` | `'figure'` \| `'text'` | `'figure'` | `text` drops the hero scale for a phrase. |

With a `suffix`, the figure and the suffix share a baseline-aligned row. Without
one, the figure renders on its own with no extra wrapper.

## `StatCard.Delta`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `children` | `React.ReactNode` | — | The change, pre-formatted. Must be non-interactive. |
| `className` | `string` | — | Merged onto the delta. |
| `direction` | `'up'` \| `'down'` | — | **Required.** Which arrow to show. |
| `directionLabel` | `string` | Root's `up` or `down` | Spoken in place of the direction. |
| `label` | `string` | — | Spoken in place of the whole delta. Wins over `directionLabel`. |
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

The tone does not survive it. "Up 2%" reads the same whether the rise is good
news or bad, because that difference lives only in the colour. Where it matters,
put it in words: `label` replaces the whole spoken delta, and the arrow and the
change are hidden behind it.

That hiding is why the children must be text rather than a control. Anything
focusable there would still take tab focus while being hidden from the
accessibility tree, which is a state a screen-reader user cannot make sense of.

```jsx
<StatCard.Delta
	direction="up"
	tone="negative"
	label={ sprintf(
		// translators: %s is the change, e.g. "2%".
		__( '%s more refunds than last month', 'newspack-plugin' ),
		'2%'
	) }
>
	2%
</StatCard.Delta>
```

A blank `label` or `directionLabel` counts as none, and falls back the way an
empty one does, so a delta is never left announcing whitespace.

`label` is also the way out of the default's word order. "Up 2%" is the arrow's
text followed by the children, which the markup fixes: a language that wants the
figure inside the phrase, or the direction after it, cannot get there by swapping
one word with `directionLabel`. One translatable sentence can.

A direction outside `up` and `down` shows no arrow and warns outside production.
With nothing else to go on it says nothing, rather than naming the opposite
direction. A `directionLabel` or a `label` is still spoken, because the caller
chose those words.

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
would be split across several blocks. A Fragment does not: `createInterpolateElement`
returns one, and the sentence inside it stays part of the run, styled like any
other description.

```jsx
<StatCard.Footer>
	{ createInterpolateElement( __( 'Applies to <b>12</b> products.', 'newspack-plugin' ), {
		b: <strong />,
	} ) }
</StatCard.Footer>
```

Anything else you want kept together, wrap yourself and it passes through as one:

```jsx
<StatCard.Footer>
	<p className="newspack-stat-card__description">
		Applies to <strong>12</strong> products.
	</p>
</StatCard.Footer>
```

That rule keys on the wrapper rather than on what it holds, so a Fragment is
folded into the run whatever is inside it. A group of non-text content, two
buttons say, needs a real element around it in the same way: inside a Fragment
both would land in the description `<p>` and take its quiet 12px type, and
anything rendering a `<div>` there would trip React's nesting warning as well.

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

Every subcomponent reads Root's context. Outside one it throws "StatCard
subcomponents must be rendered inside StatCard.Root.", which is what surfaces the
mistake in development and in tests.

A production build warns and falls back to the default context instead. Nothing
in this package or in `newspack-plugin` puts an error boundary above these cards,
so throwing there would blank an admin screen; a figure sized against the wrong
container is the smaller failure of the two.
