# StatusIndicator

A status glyph followed by its label, for the Status column of a DataView.

A badge is an attention marker. In a column where every row carries one it marks
nothing and adds a block of colour to each row, so the quiet treatment is the
default there and a badge is kept for the rare row that genuinely stands out,
such as a group with a seat request waiting on payment.

## Importing

```jsx
// The barrel.
import { StatusIndicator } from 'newspack-components';

// The component on its own.
import StatusIndicator from '../../packages/components/src/status-indicator';
```

## Usage

```jsx
import { published } from '@wordpress/icons';

<StatusIndicator icon={ published }>{ __( 'Active', 'newspack-plugin' ) }</StatusIndicator>;
```

In a field definition:

```jsx
{
	id: 'status',
	label: __( 'Status', 'newspack-plugin' ),
	getValue: ( { item } ) => item.status,
	render: ( { item } ) => <StatusIndicator icon={ STATUS_ICONS[ item.status ] }>{ item.status_label }</StatusIndicator>,
	elements: statusElements,
	filterBy: { operators: [ 'is' ] },
}
```

## Props

| Prop | Type | Required | Description |
| --- | --- | --- | --- |
| `icon` | `Icon`'s `icon` prop | yes | The status glyph, from `@wordpress/icons`. |
| `children` | `ReactNode` | no | The status label. |

Anything else is spread onto the wrapper, a `Stack` from `@wordpress/ui`, which
takes the props of a `div`.

## Choosing an icon

**No two statuses in one column may share a glyph.** A DataViews Status column
offers its statuses as separate filters, so two that look identical make two
different states indistinguishable in the one place the difference matters. This
is the constraint the badge intents carried before, and it survives the move
unchanged.

The glyph does the separating on its own: the component inherits its colour from
the surrounding text and tints nothing, so no status leans on colour to carry
its meaning. That also means a state that needs to shout cannot, which is the
trade the quiet treatment makes.

## The icon's footprint

`@wordpress/icons` draws a 16px glyph inside a 24px viewBox, so a 24px icon
carries 4px of transparent padding on every side. The component trims that back
to the visible footprint with a negative margin, which is what makes the 8px gap
measure 8px between the glyph and the label rather than 12px. An icon that fills
its viewBox would be cropped by 4px a side; the statuses all come from
`@wordpress/icons`, which does not.
