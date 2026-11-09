# DataViews

`@wordpress/dataviews` with Newspack's list rules applied: readable column widths, one-line statuses and dates, a title that can't be hidden, and filters beside the search with `inlineFilters`.

```jsx
import { DataViews } from 'newspack-components';
```

## Lists of names in a cell

- **Title cell:** the first two names, then a muted "+N more <noun>" that opens a popover listing the rest.
- **Every other cell:** a [`CellList`](../cell-list/README.md): two names, then "Show N more" / "Show less".
- Exclusions go under the list as a muted "N excluded" line that opens a popover listing the excluded names.
- In both kinds of cell, a single leftover name shows inline rather than behind "+1 more" or "Show 1 more".

## Where a list sits

- **Full-width page:** render `DataViews` directly. The list runs edge to edge with the page gutter.
- **Anywhere else** (a detail screen, a settings section, beside other content): put it in a [`TableCard`](../table-card/README.md). Don't let a list float in the page next to other content.

Inside a `TableCard`, the list:

- keeps the card's 24px side padding instead of the page's 48px gutter, so the first column lines up with the card title
- drops the negative margins a list gets on a page that isn't full width

A table that sits in a container with its own padding, such as a modal, takes `embedded`. It lays out as inside a `TableCard`, with no page gutter and no negative margins, so it keeps the wrapper's column widths without being pushed in from the container's edges:

```jsx
<Modal title={ __( 'Affected Products', 'newspack-plugin' ) } size="large">
	<DataViews embedded { ...props } />
</Modal>
```

Rows keep the default density wherever the list sits. Set `layout.density: 'compact'` on the view only when the list holds a lot of data, such as a table of every product in the catalog.

```jsx
<TableCard heading={ 2 } title={ __( 'Invitations', 'newspack-plugin' ) }>
	<DataViews { ...props } />
</TableCard>
```

## Column widths

In a table, the title takes an even share of the width and never less than 30%, and every other visible column splits the rest evenly. A text cell can grow to a 30ch readable minimum before it wraps.

A short column, such as a status badge or a count, doesn't need an even share. Name it in `fitColumns` and it shrinks to its content:

```jsx
<DataViews fitColumns={ [ 'status', 'active_subscriptions' ] } { ...props } />
```

- A fitted column is as wide as its widest cell or its header label, whichever is wider, plus the cell padding. The header label never wraps.
- Fitted columns take no share and skip the 30ch minimum. The other columns split the space they free, and the title is still never narrower than any of them.
- An id for a column the viewer has switched off is ignored.
- Keep it for content with a known, short width. A column of free text stays on an even share.

## Naming the count

DataViews' footer counts rows as "6 items". Give the list a `countLabel` so the count names what it holds: "6 members", or "10 of 1,200 subscribers" when the page shows only part of the list.

`countLabel( count, formatted )` receives the total and the total formatted for the site's locale, and returns the phrase. Use `_n()` so the noun follows the count:

```jsx
<DataViews
	countLabel={ ( count, formatted ) =>
		sprintf(
			/* translators: %s: number of members in the group. */
			_n( '%s member', '%s members', count, 'newspack-plugin' ),
			formatted
		)
	}
	{ ...props }
/>
```

- The footer shows under the same conditions as core's: a count when a row has a bulk action, pagination when there is more than one page.
- Without `countLabel`, or with infinite scroll, core's own footer and "N items" stay.
- A list that passes its own `children` layout renders its own footer, so `countLabel` has no effect there.

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
