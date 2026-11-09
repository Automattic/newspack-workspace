/**
 * Even column widths for DataViews tables.
 */

/**
 * External dependencies.
 */
import type { View } from '@wordpress/dataviews';

const MIN_TITLE_SHARE = 30;

export const ACTIONS_COLUMN_SHARE = 13;

type ColumnStyles = Record< string, { width?: string } >;

const percent = ( share: number ) => `${ Number( share.toFixed( 2 ) ) }%`;

/**
 * The visible columns beside the title, and each column's share of the width.
 *
 * @param view     The DataViews view.
 * @param reserved Share of the width held back for the actions column, which sits
 *                 beside the shares rather than inside them.
 * @return The other columns, the title's share (0 when it isn't shown), and
 *         the share each other column gets.
 */
function getShares( view: View, reserved = 0 ): { columns: string[]; titleShare: number; columnShare: number } {
	const hasTitle = Boolean( view.titleField ) && view.showTitle !== false;
	const columns = ( view.fields ?? [] ).filter( id => id !== view.titleField );
	const available = 100 - reserved;
	const titleShare = hasTitle ? Math.max( MIN_TITLE_SHARE, available / ( columns.length + 1 ) ) : 0;
	const columnShare = columns.length ? ( available - titleShare ) / columns.length : 0;
	return { columns, titleShare, columnShare };
}

/**
 * Gives a table consistent column widths: the title takes an equal share, and
 * never less than 30%, and every other visible column splits the rest evenly.
 * Without it the browser hands all spare width to the title column, so a short
 * title still fills half the table. DataViews never sizes the title column, so a
 * caller that renders DataViews itself must also apply `titleColumnWidth` and
 * `columnShareWidth`, as the shared DataViews component does. The widths follow whichever columns the
 * viewer has switched on, so they are worked out on every render and never
 * stored with the view.
 *
 * @param view The DataViews view.
 * @return The view with column widths, unchanged for any layout but a table.
 */
export function evenColumns< V extends View >( view: V, reserved = 0 ): V {
	if ( view.type !== 'table' ) {
		return view;
	}
	const { columns, columnShare } = getShares( view, reserved );
	if ( ! columns.length ) {
		return view;
	}
	const styles: ColumnStyles = {};
	columns.forEach( id => {
		styles[ id ] = { width: percent( columnShare ) };
	} );
	return { ...view, layout: { ...view.layout, styles } };
}

/**
 * The title column's width for a table that `evenColumns` sized. DataViews
 * never applies a width to the title column, so a table gives it one through
 * the `--newspack-dataviews-title-width` custom property instead.
 *
 * @param view The DataViews view.
 * @return The width, or undefined when the view has no visible title column.
 */
export function titleColumnWidth( view: View, reserved = 0 ): string | undefined {
	if ( view.type !== 'table' ) {
		return undefined;
	}
	const { columns, titleShare } = getShares( view, reserved );
	return titleShare && columns.length ? percent( titleShare ) : undefined;
}

/**
 * The width each column beside the title gets from `evenColumns`, as a share
 * of the table's container, for capping cell content so text wraps at its
 * column's share rather than widening the column.
 *
 * @param view The DataViews view.
 * @return The width in container query units, or undefined when the view has
 *         no columns beside the title.
 */
export function columnShareWidth( view: View, reserved = 0 ): string | undefined {
	if ( view.type !== 'table' ) {
		return undefined;
	}
	const { columns, columnShare } = getShares( view, reserved );
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
