# CollapsibleGroup

A stack of independently collapsible items, separated by dividers and sitting flush with the surrounding column. Built on `Collapsible` from `@wordpress/ui`.

Each item is its own disclosure: opening one does not close the others. This is deliberately not a W3C accordion, which coordinates its panels and moves focus between headers with the arrow keys, so the component is not named for one. It is also not `CollapsibleCard` from the same library, which renders its root as a `Card` and so brings a border, a background and card padding; this component sits flush with the column around it.

A collapsed item is hidden with `hidden="until-found"`, so the browser's find-in-page can match text inside it and expand the item to reveal the result. Find-in-page can only match text that exists, so a collapsed item keeps its children mounted, out of the focus order and the accessibility tree but present in the DOM. Every item therefore renders on first paint, and a child's state and effects persist while it is closed. Keep expensive children, or anything that fetches on mount, behind the item's own open state.

## Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `className` | `string` | — | Additional class on the group wrapper. |
| `hideSingleTitle` | `boolean` | `false` | When the group holds exactly one item, render it open and drop its title. Use it where a group can collapse to a single section and the title would repeat the heading above it. |
| `gap` | `GapSize` | `'xl'` | `Stack` gap, on the design system's token scale (`'xl'` is 24px). The divider is a sibling of the items rather than part of one, so the gap applies on both sides of it and items sit twice this far apart: 49px at the default. |
| `titleLevel` | `1 \| 2 \| 3 \| 4 \| 5 \| 6` | inherited, or `2` | Heading level for every item title. Set it once on the group so the items share one place in the document outline: under a section header rendered as `h2`, pass `3`. It changes the tag only, never the size, so the same group looks the same wherever it sits. A group nested inside another matches the level it inherits rather than descending a step, so give a nested group its own `titleLevel`. A value outside the range is clamped to the nearest valid level. |

Children must be `CollapsibleGroup.Item`. Bare text and numbers are dropped; any other element counts as one item and takes a divider, so a wrapper component, or a `Fragment` holding two items, puts the dividers in the wrong place.

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
