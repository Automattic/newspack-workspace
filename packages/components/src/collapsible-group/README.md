# CollapsibleGroup

A stack of independently collapsible items, separated by dividers and sitting flush with the surrounding column. Built on `Collapsible` from `@wordpress/ui`.

Each item is its own disclosure: opening one does not close the others. This is deliberately not a W3C accordion, which coordinates its panels and moves focus between headers with the arrow keys, so the component is not named for one.

A collapsed item is hidden with `hidden="until-found"`, so the browser's find-in-page can match text inside it and expand the item to reveal the result.

## Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `className` | `string` | — | Additional class on the group wrapper. |
| `hideSingleTitle` | `boolean` | `false` | When the group holds exactly one item, render it open and drop its title. Use it where a group can collapse to a single section and the title would repeat the heading above it. |
| `spacing` | `number` | `6` | `VStack` spacing between items, in 4px units. |
| `titleLevel` | `1 \| 2 \| 3 \| 4 \| 5 \| 6` | `2` | Heading level for every item title. Set it once on the group so the items share one place in the document outline: under a section header rendered as `h2`, pass `3`. |

### `CollapsibleGroup.Item`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `className` | `string` | — | Additional class on the item. |
| `defaultOpen` | `boolean` | `false` | Whether the item starts expanded. |
| `title` | `string` | — | Trigger label, rendered as a button inside the heading. Without a title there is no trigger and the content renders permanently open. |

## Usage

```jsx
import { CollapsibleGroup } from 'newspack-components';

<CollapsibleGroup titleLevel={ 3 }>
	<CollapsibleGroup.Item title="Contact fields" defaultOpen>
		…
	</CollapsibleGroup.Item>
	<CollapsibleGroup.Item title="Tags and segments">
		…
	</CollapsibleGroup.Item>
</CollapsibleGroup>

// A group that may collapse to one section
<CollapsibleGroup hideSingleTitle>
	{ groups.map( group => (
		<CollapsibleGroup.Item key={ group.id } title={ group.section }>
			…
		</CollapsibleGroup.Item>
	) ) }
</CollapsibleGroup>
```
