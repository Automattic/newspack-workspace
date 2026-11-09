/**
 * Even column widths for DataViews tables.
 */

/**
 * External dependencies.
 */
import type { View } from '@wordpress/dataviews';

const MIN_TITLE_SHARE = 30;

type ColumnStyles = Record< string, { width?: string } >;

const FIT_WIDTH = '1%';

const percent = ( share: number ) => `${ Number( share.toFixed( 2 ) ) }%`;

/**
 * The visible columns beside the title, and each column's share of the width.
 * Fitted columns take no share, so the others split the whole width.
 *
 * @param view       The DataViews view.
 * @param fitColumns Columns sized to their content.
 * @return The other columns, the fitted ones, the title's share (0 when it
 *         isn't shown), and the share each other column gets.
 */
function getShares(
	view: View,
	fitColumns: string[] = []
): { columns: string[]; fitted: string[]; titleShare: number; columnShare: number } {
	const hasTitle = Boolean( view.titleField ) && view.showTitle !== false;
	const shown = ( view.fields ?? [] ).filter( id => id !== view.titleField );
	const fitted = shown.filter( id => fitColumns.includes( id ) );
	const columns = shown.filter( id => ! fitColumns.includes( id ) );
	const titleShare = hasTitle ? Math.max( MIN_TITLE_SHARE, 100 / ( columns.length + 1 ) ) : 0;
	const columnShare = columns.length ? ( 100 - titleShare ) / columns.length : 0;
	return { columns, fitted, titleShare, columnShare };
}

/**
 * Gives a table consistent column widths: the title takes an equal share, and
 * never less than 30%, and every other visible column splits the rest evenly.
 * Without it the browser hands all spare width to the title column, so a short
 * title still fills half the table. DataViews never sizes the title column, so a
 * caller that renders DataViews itself must also apply `titleColumnWidth` and
 * `columnShareWidth`, as the shared DataViews component does. The widths follow
 * whichever columns the viewer has switched on, so they are worked out on every
 * render and never stored with the view.
 *
 * Columns named in `fitColumns`, such as a status or a count, shrink to their
 * content and take no share, so the other columns split the space they free.
 *
 * @param view       The DataViews view.
 * @param fitColumns Columns sized to their content.
 * @return The view with column widths, unchanged for any layout but a table.
 */
export function evenColumns< V extends View >( view: V, fitColumns: string[] = [] ): V {
	if ( view.type !== 'table' ) {
		return view;
	}
	const { columns, fitted, columnShare } = getShares( view, fitColumns );
	if ( ! columns.length && ! fitted.length ) {
		return view;
	}
	const styles: ColumnStyles = {};
	columns.forEach( id => {
		styles[ id ] = { width: percent( columnShare ) };
	} );
	fitted.forEach( id => {
		styles[ id ] = { width: FIT_WIDTH };
	} );
	return { ...view, layout: { ...view.layout, styles } };
}

/**
 * The title column's width for a table that `evenColumns` sized. DataViews
 * never applies a width to the title column, so a table gives it one through
 * the `--newspack-dataviews-title-width` custom property instead.
 *
 * @param view       The DataViews view.
 * @param fitColumns Columns sized to their content.
 * @return The width, or undefined when the view has no visible title column.
 */
export function titleColumnWidth( view: View, fitColumns: string[] = [] ): string | undefined {
	if ( view.type !== 'table' ) {
		return undefined;
	}
	const { columns, titleShare } = getShares( view, fitColumns );
	return titleShare && columns.length ? percent( titleShare ) : undefined;
}

/**
 * The width each column beside the title gets from `evenColumns`, as a share
 * of the table's container, for capping cell content so text wraps at its
 * column's share rather than widening the column. Columns fitted to their
 * content still count here: the share is of the whole container, and a fitted
 * column's real width isn't known, so leaving it out would let text grow into
 * space the fitted column still takes and push the table wider.
 *
 * @param view The DataViews view.
 * @return The width in container query units, or undefined when the view has
 *         no columns beside the title.
 */
export function columnShareWidth( view: View ): string | undefined {
	if ( view.type !== 'table' ) {
		return undefined;
	}
	const { columns, columnShare } = getShares( view );
	return columns.length ? `${ Number( columnShare.toFixed( 2 ) ) }cqi` : undefined;
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
