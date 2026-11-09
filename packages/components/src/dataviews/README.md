# DataViews

`@wordpress/dataviews` with Newspack's list rules applied: readable column widths, one-line statuses and dates, a title that can't be hidden, and filters beside the search with `inlineFilters`.

```jsx
import { DataViews } from 'newspack-components';
```

Column widths are shares of the table. When the list has row actions, `ACTIONS_COLUMN_SHARE` of the width is held back for the actions column, so the shares do not add up to more than the table and push the last column under it.

## Lists of names in a cell

- **Title cell:** the first two names, then a muted "+N more <noun>" with a tooltip listing the rest.
- **Every other cell:** a [`CellList`](../cell-list/README.md): two names, then "Show N more" / "Show less". A single leftover name shows inline, so no toggle ever reads "Show 1 more".
- Exclusions go under the list as a muted "N excluded" line whose tooltip lists the excluded names.

## Where a list sits

- **Full-width page:** render `DataViews` directly. The list runs edge to edge with the page gutter.
- **Anywhere else** (a detail screen, a settings section, beside other content): put it in a [`TableCard`](../table-card/README.md). Don't let a list float in the page next to other content.

Inside a `TableCard`, the list:

- keeps the card's 24px side padding instead of the page's 48px gutter, so the first column lines up with the card title
- drops the negative margins a list gets on a page that isn't full width

Rows keep the default density wherever the list sits. Set `layout.density: 'compact'` on the view only when the list holds a lot of data, such as a table of every product in the catalog.

```jsx
<TableCard heading={ 2 } title={ __( 'Invitations', 'newspack-plugin' ) }>
	<DataViews { ...props } />
</TableCard>
```

## Dates and times

Every date or time in a list follows the site's Settings › General: its date format, time format and timezone. Format them with the helpers rather than `formats.datetime`, which core hard-codes as `F j, Y g:i a` whatever the site uses, or DataViews' built-in `datetime` field type, which formats with it.

```jsx
import { formatSiteDate, formatSiteDateTime, formatSiteTime } from 'newspack-components';

render: ( { item } ) => <span className="newspack-dataviews__date">{ formatSiteDateTime( item.created_at ) }</span>,
```

| Helper | Output |
| --- | --- |
| `formatSiteDate( value )` | The site's date format |
| `formatSiteDateTime( value )` | The site's date format, then its time format |
| `formatSiteTime( value )` | The site's time format |

Each takes:

- **A bare `YYYY-MM-DD` date.** Treated as a calendar day, so it shows the same day in every browser and timezone.
- **An ISO 8601 string.** Respects its offset and shows the instant in the site's timezone. Append `Z` or `+00:00` to a GMT value that has none.
- **A MySQL `YYYY-MM-DD HH:MM:SS` string without an offset.** Read as site time, like a post's `date`.
- **A Unix timestamp in seconds**, as a number or a numeric string.
- **A `Date`.**

An empty or invalid value returns `''`. The time keeps AM/PM when the site's time format has it; never force a 24-hour format.
