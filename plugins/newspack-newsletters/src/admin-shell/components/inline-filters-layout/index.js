/**
 * DataViews layout with the filter chips beside the search and the view
 * options (plus any `header` content) on the right, matching the admin
 * lists in newspack-plugin. Rendered as the DataViews children, so it
 * replaces the default layout's filter toggle and the row of chips under
 * the search.
 *
 * Only filters marked `isPrimary` show as chips; any others sit behind the
 * "Add filter" button in the same row.
 */

import { DataViews } from '@wordpress/dataviews/wp';

export default function InlineFiltersLayout( { header, search = true, searchLabel } ) {
	return (
		<>
			<div className="dataviews__view-actions newspack-newsletters-list__toolbar">
				<div className="dataviews__search newspack-newsletters-list__filters">
					{ search && <DataViews.Search label={ searchLabel } /> }
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
