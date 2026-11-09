# CellList

A list of items inside a table cell, one per line: the first two, then "Show N more" / "Show less" for the rest. The count tells the reader how much is hidden before they open it. When only one item would be hidden, it shows inline and there is no toggle, because "Show 1 more" saves nothing.

```jsx
import { CellList } from 'newspack-components';
```

Use it for every cell that lists several named things, such as plans, gates or rules, except the title column (see the [DataViews README](../dataviews/README.md)). Show each item as plain text, or as a link when it has its own screen. Don't put the items in badges: a badge marks a state, such as Active or Failed (see the [StatusIndicator README](../status-indicator/README.md)).

```jsx
<CellList>
	{ item.unlocks.map( gate => (
		<a key={ gate.id } href={ gateEditUrl( gate.id ) }>
			{ gate.title }
		</a>
	) ) }
</CellList>
```

## Props

- `children`: the items, one per line. Each needs a `key`.
- `limit`: how many items show before "Show N more". Defaults to `2`. A list one item over the limit shows every item.
- `defaultExpanded`: whether the list starts open.
- `onToggle`: called with the new state when the list opens or closes.

DataViews remounts its cells when the fields are rebuilt, which closes an open list. When a list has to stay open across that, keep the open rows outside the cell and pass them back through `defaultExpanded` and `onToggle`.
