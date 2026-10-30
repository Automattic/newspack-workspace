# CardSortableList

A drag-and-drop sortable list of cards, built on the `@wordpress/ui` Card. Each row shows a drag handle and up/down buttons, then the title with its badges and description, then an optional toggle and actions menu on the right. Items can be reordered by dragging with a pointer or by using the up/down chevron buttons (keyboard accessible). The list locks its height during a drag to prevent layout shifts.

## Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `disabled` | `boolean` | `false` | When `true`, all drag and button controls are disabled via `Disabled`. |
| `items` | `DraggableItem[]` | `[]` | Array of items to render. See the `DraggableItem` shape below. |
| `onDragCallback` | `(index: number, targetIndex: number) => void` | `() => {}` | Callback fired after a reorder completes (drag-and-drop or button move). Receives the original index and the new index. |

### `DraggableItem`

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` \| `number` | Unique identifier for the item. Used as the React `key`. |
| `title` | `string` | Label rendered in the card header. |
| `badge` | `{ label: string; intent?: BadgeIntent }` | Status badge for the row. Omit it, or leave `label` empty, to render no badge. `intent` defaults to `none`. See [`BadgeIntent`](../types.ts) for the available intents. |
| `description` | `ReactNode` | Text under the title. |
| `toggleChecked`, `onToggleChange` | `boolean`, `() => void` | Shows a toggle before the actions menu. Its label is visually hidden and its accessible name is the item `title`. Omit `onToggleChange` for no toggle. |
| `actions` | `{ label; action; destructive?; disabled?; icon? }[]` | Items for the row's overflow menu. |
| `actionsLabel` | `string` | Accessible name of the overflow menu button. Defaults to "More actions"; pass one that names the row. |
| `secondaryBadge` | `{ label: string; intent?: BadgeIntent; tooltip?: string }` | A second badge after `badge`, such as a warning. With `tooltip`, it renders as a `TooltipBadge` that explains it. Omit it, or leave `label` empty, to render none. `intent` defaults to `none`. |

## `CardListRow`

The card a sortable row is built from, for a list that isn't sortable. It takes one `DraggableItem` as `item`, without the drag handle and move buttons, and passes other props to the card. `size="large"` uses even padding, for a row that stands alone on a page. Lay the rows out with `Stack`.

## Usage

```jsx
import { CardSortableList } from 'newspack-components';

const [ items, setItems ] = useState( [
	{ id: 1, title: 'Homepage', badge: { label: 'Active', intent: 'stable' } },
	{ id: 2, title: 'About',    badge: { label: 'Draft',  intent: 'draft'  } },
	{ id: 3, title: 'Contact',  badge: { label: 'Review', intent: 'medium' } },
] );

const handleReorder = ( fromIndex, toIndex ) => {
	const reordered = [ ...items ];
	const [ moved ] = reordered.splice( fromIndex, 1 );
	reordered.splice( toIndex, 0, moved );
	setItems( reordered );
};

<CardSortableList
	items={ items }
	onDragCallback={ handleReorder }
/>

// Disabled state (controls are rendered but non-interactive)
<CardSortableList
	items={ items }
	disabled={ true }
	onDragCallback={ handleReorder }
/>
```
