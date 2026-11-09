# DataViews

`@wordpress/dataviews` with Newspack's list rules applied: readable column widths, one-line statuses and dates, a title that can't be hidden, and filters beside the search with `inlineFilters`.

```jsx
import { DataViews } from 'newspack-components';
```

## Where a list sits

- **Full-width page:** render `DataViews` directly. The list runs edge to edge with the page gutter.
- **Anywhere else** (a detail screen, a settings section, beside other content): put it in a [`TableCard`](../table-card/README.md). Don't let a list float in the page next to other content.

Inside a `TableCard`, the list:

- keeps the card's 24px side padding instead of the page's 48px gutter, so the first column lines up with the card title
- drops the negative margins a list gets on a page that isn't full width

Rows keep the default density wherever the list sits. Set `layout.density: 'compact'` on the view only when the list holds a lot of data, such as a long table of figures or prices.

```jsx
<TableCard heading={ 2 } title={ __( 'Invitations', 'newspack-plugin' ) }>
	<DataViews { ...props } />
</TableCard>
```
