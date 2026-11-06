/**
 * Even column widths for DataViews tables.
 */

/**
 * External dependencies.
 */
import type { View } from '@wordpress/dataviews';

const TITLE_SHARE = 30;

type ColumnStyles = Record< string, { width?: string } >;

/**
 * Gives a table consistent column widths: every visible column except the title
 * takes an equal share of 70%, and the title keeps what is left. Without it the
 * browser hands all spare width to the title column, so a short title still
 * fills half the table. DataViews does not apply a width to the title column
 * itself, so a row actions column comes out of the title's share. The widths
 * follow whichever columns the viewer has switched on, so they are worked out on
 * every render and never stored with the view.
 *
 * @param view The DataViews view.
 * @return The view with column widths, unchanged for any layout but a table.
 */
export function evenColumns< V extends View >( view: V ): V {
	if ( view.type !== 'table' ) {
		return view;
	}
	const hasTitle = Boolean( view.titleField ) && view.showTitle !== false;
	const columns = ( view.fields ?? [] ).filter( id => id !== view.titleField );
	if ( ! hasTitle && ! columns.length ) {
		return view;
	}
	const titleShare = columns.length ? TITLE_SHARE : 100;
	const columnShare = `${ Number( ( ( hasTitle ? 100 - titleShare : 100 ) / columns.length ).toFixed( 2 ) ) }%`;
	const styles: ColumnStyles = {};
	if ( hasTitle && view.titleField ) {
		styles[ view.titleField ] = { width: `${ titleShare }%` };
	}
	columns.forEach( id => {
		styles[ id ] = { width: columnShare };
	} );
	return { ...view, layout: { ...view.layout, styles } };
}

/**
 * Drops the widths `evenColumns` adds, so a view handed back by DataViews is
 * stored without them.
 *
 * @param view The DataViews view.
 * @return The view without column widths.
 */
export function withoutColumnStyles< V extends View >( view: V ): V {
	const { layout } = view as { layout?: Record< string, unknown > & { styles?: ColumnStyles } };
	if ( ! layout?.styles ) {
		return view;
	}
	const { styles, ...rest } = layout; // eslint-disable-line @typescript-eslint/no-unused-vars
	return { ...view, layout: rest };
}
