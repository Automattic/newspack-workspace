/**
 * Even column widths for DataViews tables.
 */

/**
 * External dependencies.
 */
import type { View } from '@wordpress/dataviews';

const MIN_TITLE_SHARE = 30;

type ColumnStyles = Record< string, { width?: string } >;

const percent = ( share: number ) => `${ Number( share.toFixed( 2 ) ) }%`;

/**
 * The visible columns beside the title, and each column's share of the width.
 *
 * @param view The DataViews view.
 * @return The other columns, the title's share (0 when it isn't shown), and
 *         the share each other column gets.
 */
function getShares( view: View ): { columns: string[]; titleShare: number; columnShare: number } {
	const hasTitle = Boolean( view.titleField ) && view.showTitle !== false;
	const columns = ( view.fields ?? [] ).filter( id => id !== view.titleField );
	const titleShare = hasTitle ? Math.max( MIN_TITLE_SHARE, 100 / ( columns.length + 1 ) ) : 0;
	const columnShare = columns.length ? ( 100 - titleShare ) / columns.length : 0;
	return { columns, titleShare, columnShare };
}

/**
 * Gives a table consistent column widths: the title takes an equal share, and
 * never less than 30%, and every other visible column splits the rest evenly.
 * Without it the browser hands all spare width to the title column, so a short
 * title still fills half the table. DataViews never sizes the title column, so a
 * caller that renders DataViews itself must also apply `titleColumnWidth`, as
 * the shared DataViews component does. The widths follow whichever columns the
 * viewer has switched on, so they are worked out on every render and never
 * stored with the view.
 *
 * @param view The DataViews view.
 * @return The view with column widths, unchanged for any layout but a table.
 */
export function evenColumns< V extends View >( view: V ): V {
	if ( view.type !== 'table' ) {
		return view;
	}
	const { columns, columnShare } = getShares( view );
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
export function titleColumnWidth( view: View ): string | undefined {
	if ( view.type !== 'table' ) {
		return undefined;
	}
	const { columns, titleShare } = getShares( view );
	return titleShare && columns.length ? percent( titleShare ) : undefined;
}

/**
 * The width each column beside the title gets from `evenColumns`, as a share
 * of the table's container. A cell keeps its text on one line up to this
 * width and wraps beyond it, so long text never pushes its column wider than
 * its share, while short text never wraps inside it.
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
