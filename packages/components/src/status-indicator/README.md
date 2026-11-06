# StatusIndicator

A status glyph followed by its label, for the Status column of a DataView. Both
are tinted by what the status means, so a column can be scanned by color.

A badge is an attention marker. In a column where every row carries one it marks
nothing and adds a block of color to each row, so a tinted glyph and label carry
the color there, and a badge is kept for the rare row that genuinely stands out,
such as a group with a seat request waiting on payment.

## Importing

```jsx
// The barrel.
import { StatusIndicator } from 'newspack-components';

// The component on its own.
import StatusIndicator from '../../packages/components/src/status-indicator';

// The vocabulary, for a column's own test. Deliberately not on the barrel: that
// entry also pulls `Page`'s `:root` token block and the wizards store into
// whichever bundle asks for it.
import { statusGlyph, statusTone, STATUS_NAMES } from '../../packages/components/src/status-indicator';
```

## Usage

Name the status and the component draws it:

```jsx
<StatusIndicator status="active">{ __( 'Active', 'newspack-plugin' ) }</StatusIndicator>;
```

In a field definition, where the screen maps its own status keys onto the
vocabulary:

```jsx
{
	id: 'status',
	label: __( 'Status', 'newspack-plugin' ),
	getValue: ( { item } ) => item.status,
	render: ( { item } ) => <StatusIndicator status={ STATUS_INDICATORS[ item.status ] }>{ item.status_label }</StatusIndicator>,
	elements: statusElements,
	filterBy: { operators: [ 'is' ] },
}
```

## Props

| Prop | Type | Required | Description |
| --- | --- | --- | --- |
| `status` | `StatusName` | one of the two | The status to draw, from the vocabulary below. |
| `icon` | `Icon`'s `icon` prop | one of the two | A glyph, for a field the vocabulary does not cover. Never tinted. |
| `tone` | `StatusTone` | no | Overrides the tone of `status`. Only with `status`. |
| `children` | `ReactNode` | yes | The status label. `@wordpress/primitives` forces `aria-hidden` on the glyph, so this is the whole accessible name. |

`status` and `icon` are mutually exclusive, and one of them is required.
Anything else is spread onto the wrapper, a `Stack` from `@wordpress/ui`, which
takes the props of a `div`.

## The vocabulary

| Name | Glyph | Tone | What it means |
| --- | --- | --- | --- |
| `active` | check circle | success | Live now: a published plan, a running subscription. |
| `done` | check circle | success | Finished successfully: a sent newsletter, a completed sync. |
| `scheduled` | clock | info | Waiting for a date to arrive. |
| `draft` | half circle | neutral | Not live yet, or switched off. |
| `pending` | part-filled circle | info | Waiting on something outside the publisher's hands. |
| `attention` | exclamation circle | warning | Live but needing a look, usually a payment. |
| `error` | error | error | Failed, and the one state that asks the reader to act. |
| `progress` | update | info | Running right now. |
| `cancelled` | slash circle | error | Stopped on purpose. |
| `ended` | slash circle | error | Stopped because its window closed. |
| `private` | lock | none | Live, but not publicly reachable. |
| `trash` | trash | neutral | Binned. |

The component owns this so one meaning draws one mark everywhere. Before it
existed the vocabulary lived in ten screen-level maps, and had already drifted:
Pending was the half circle in Subscribers and the part-filled one in the
Audience lists.

**No two statuses in one column may draw the same mark.** A DataViews Status
column offers its statuses as separate filters, so two that look identical make
two different states indistinguishable in the one place the difference matters.

Two names may share a glyph where they read differently at the call site but
mean the same to a reader: a sent newsletter is finished rather than live, and
an ad whose window closed was not cancelled. Splitting them leaves room to draw
them apart later without touching a consumer. The pairs are `active`/`done` and
`cancelled`/`ended`, and `index.test.js` pins that list, so a column keeps the
rule by using distinct names and at most one half of a pair. `statusGlyph` is
exported for the columns that want to assert it directly.

## Color

Each tone is the design system's `--wpds-color-foreground-content-<tone>-weak`
token, applied to the glyph and label through a
`newspack-status-indicator--<tone>` modifier. A screen that loads no token sheet
still reads correctly: the status falls back to the text color. Each tone has
its own rule in `style.scss`, so a tone added to `StatusTone` in `statuses.ts`
needs a rule too, or it renders untinted without any error.

Red means stopped: failed (`error`), cancelled (`cancelled`) or past its window
(`ended`). The glyph tells failed apart from the other two. A column that shows
a failure next to a deliberate stop can pass `tone="neutral"` to the stop, as
Scheduled Actions does, so only the failure reads red.

Amber is kept for `attention` alone, so it always means "look at this". States
still in flight (`scheduled`, `pending`, `progress`) share blue and are told
apart by their glyphs.

Color backs the glyph and label up; it does not replace them. The glyph does the
separating on its own, so no status leans on color to carry its meaning, and two
statuses may share a tone where the glyphs tell them apart (`scheduled` and
`progress`, or `draft` and `trash`). `private` has no tone: it is live, and
tinting it like `active` would make the two read alike. A status drawn with a
glyph passed through `icon` is never tinted, since a classifying field has no
lifecycle to color. `statusTone` is exported alongside `statusGlyph` for tests.

`tone` overrides a status's own tone for a screen where the state means
something narrower than its glyph suggests. Scheduled Actions uses it for
Canceled: a canceled job was stopped on purpose and leaves nothing to act on, so
it stays neutral rather than reading like Failed.

## When to pass a glyph instead

`icon` is for fields that classify rather than track a lifecycle, where a status
name would be the wrong shape. Two exist: Plans' Availability (a gift, a lock
and a globe for Free, Private and Public) and newsletters' Visibility (a globe
or an envelope). Both are still Status-column-shaped in every other way, and
both keep the same distinctness rule.

## The icon's footprint

`@wordpress/icons` draws a 16px glyph inside a 24px viewBox, so a 24px icon
carries 4px of transparent padding on every side. The component trims that back
to the visible footprint with a negative margin, which is what makes the 8px gap
measure 8px between the glyph and the label rather than 12px. An icon that fills
its viewBox would be cropped by 4px a side; the statuses all come from
`@wordpress/icons`, which does not.

The margin is derived rather than written as `-4px`, so it follows the two
values it depends on: `calc((#{wp-vars.$grid-unit-20} - #{wp-vars.$icon-size}) / 2)`,
the glyph's footprint minus the box it sits in, halved for one side. Sass folds
it to a literal at build time, so nothing is paid for at runtime.

The box it sits in is the `size={ 24 }` the component passes to `Icon`, which is
`$icon-size`. The two are written in different files, so anything that changes
the rendered size has to change the token the margin reads, or the trim stops
matching the padding it is there to remove.
