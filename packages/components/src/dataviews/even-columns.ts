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
 * The visible columns beside the title, and the title's share of the width.
 *
 * @param view The DataViews view.
 * @return The other columns, and the title's share (0 when it isn't shown).
 */
function getShares( view: View ): { columns: string[]; titleShare: number } {
	const hasTitle = Boolean( view.titleField ) && view.showTitle !== false;
	const columns = ( view.fields ?? [] ).filter( id => id !== view.titleField );
	const titleShare = hasTitle ? Math.max( MIN_TITLE_SHARE, 100 / ( columns.length + 1 ) ) : 0;
	return { columns, titleShare };
}

/**
 * Gives a table consistent column widths: the title takes an equal share, and
 * never less than 30%, and every other visible column splits the rest evenly.
 * Without it the browser hands all spare width to the title column, so a short
 * title still fills half the table. The widths follow whichever columns the
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
	const { columns, titleShare } = getShares( view );
	if ( ! columns.length ) {
		return view;
	}
	const columnShare = percent( ( 100 - titleShare ) / columns.length );
	const styles: ColumnStyles = {};
	columns.forEach( id => {
		styles[ id ] = { width: columnShare };
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
