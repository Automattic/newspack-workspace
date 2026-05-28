# CardSortableList

A drag-and-drop sortable list of cards. Each item displays a title and a status badge. Items can be reordered by dragging with a pointer or by using the up/down chevron buttons (keyboard accessible). The list locks its height during a drag to prevent layout shifts.

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
| `badgeLevel` | `'default'` \| `'success'` \| `'info'` \| `'warning'` \| `'error'` | Visual style of the status badge. |
| `badgeText` | `string` | Text displayed inside the badge. |

## Usage

```jsx
import { CardSortableList } from 'newspack-components';

const [ items, setItems ] = useState( [
	{ id: 1, title: 'Homepage', badgeLevel: 'success', badgeText: 'Active' },
	{ id: 2, title: 'About',    badgeLevel: 'default', badgeText: 'Draft'  },
	{ id: 3, title: 'Contact',  badgeLevel: 'warning', badgeText: 'Review' },
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
