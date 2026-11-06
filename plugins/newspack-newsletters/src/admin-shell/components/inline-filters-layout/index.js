/**
 * DataViews layout with the filter chips beside the search and the view
 * options (plus any `header` content) on the right, matching the admin
 * lists in newspack-plugin. Rendered as the DataViews children, so it
 * replaces the default layout's filter toggle and the row of chips under
 * the search.
 *
 * Only filters marked `isPrimary` show as chips. DataViews renders the
 * "Add filter" button only beside a visible chip, so a list using this
 * layout needs at least one primary filter or its other filters can't be
 * reached.
 */

import { DataViews } from '@wordpress/dataviews/wp';

export default function InlineFiltersLayout( { header } ) {
	return (
		<>
			<div className="dataviews__view-actions newspack-newsletters-list__toolbar">
				<div className="dataviews__search newspack-newsletters-list__filters">
					<DataViews.Search />
					<DataViews.Filters />
				</div>
				<div className="newspack-newsletters-list__view-config">
					<DataViews.LayoutSwitcher />
					<DataViews.ViewConfig />
					{ header }
				</div>
			</div>
			<DataViews.Layout />
			<DataViews.Footer />
		</>
	);
}
