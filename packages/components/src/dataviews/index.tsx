/**
 * DataViews
 *
 * Wrapper around @wordpress/dataviews with Newspack styling.
 */

/**
 * WordPress dependencies
 */
import { useContext, useMemo } from '@wordpress/element';
import { DataViews as BaseDataViews } from '@wordpress/dataviews';
import { Stack } from '@wordpress/ui';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import { columnShareWidth, evenColumns, titleColumnWidth, withoutColumnStyles } from './even-columns';
import CountFooter, { type CountLabel } from './count-footer';
import { TableCardContext } from '../table-card/context';
import './style.scss';

type DataViewsProps< Item > = React.ComponentProps< typeof BaseDataViews< Item > > & {
	className?: string;
	/**
	 * Show each field that declares `filterBy` as a filter chip beside the
	 * search, with no filter toggle. A field left filterable by DataViews'
	 * defaults (elements but no `filterBy`) stays behind "Add filter".
	 */
	inlineFilters?: boolean;
	/**
	 * Offer the view options (layout, columns, density, page size). Turn off
	 * for a short, fixed list whose columns are not worth changing.
	 */
	viewOptions?: boolean;
	/**
	 * Names the footer count with the list's own noun instead of core's "N
	 * items". Receives the count and the count formatted for the locale:
	 * `( count, formatted ) => sprintf( _n( '%s member', '%s members', count, 'newspack-plugin' ), formatted )`.
	 */
	countLabel?: CountLabel;
	/**
	 * Lay the list out as inside a `TableCard`: no page gutter and no negative
	 * margins. For a table in a container that brings its own padding, such as
	 * a modal.
	 */
	embedded?: boolean;
};

function ToolbarLayout( {
	header,
	search,
	searchLabel,
	inlineFilters,
	viewOptions,
	footer,
}: {
	header?: React.ReactNode;
	search: boolean;
	searchLabel?: string;
	inlineFilters: boolean;
	viewOptions: boolean;
	footer?: React.ReactNode;
} ) {
	return (
		<>
			<Stack direction="row" justify="space-between" align="flex-start" gap="xs" className="dataviews__view-actions">
				<Stack direction="row" align="center" gap="sm" wrap="wrap" className="dataviews__search">
					{ search && <BaseDataViews.Search label={ searchLabel } /> }
					{ inlineFilters ? <BaseDataViews.Filters /> : <BaseDataViews.FiltersToggle /> }
				</Stack>
				<Stack direction="row" align="center" gap="xs" className="newspack-dataviews__view-config">
					{ viewOptions && (
						<>
							<BaseDataViews.LayoutSwitcher />
							<BaseDataViews.ViewConfig />
						</>
					) }
					{ header }
				</Stack>
			</Stack>
			{ ! inlineFilters && <BaseDataViews.FiltersToggled className="dataviews-filters__container" /> }
			<BaseDataViews.Layout />
			{ footer ?? <BaseDataViews.Footer /> }
		</>
	);
}

function DataViews( {
	className,
	inlineFilters = false,
	viewOptions = true,
	fields,
	children,
	view,
	onChangeView,
	countLabel,
	embedded = false,
	...props
}: DataViewsProps< unknown > ) {
	// A row without its title can't be told apart from the next, so the title is
	// never offered as a field to hide, and a stored view that hid it shows it again.
	// A primary filter is always on show, so the toggle has nothing left to hide.
	const shownFields = useMemo(
		() =>
			fields.map( field => {
				const shown = field.id === view.titleField ? { ...field, enableHiding: false } : field;
				return inlineFilters && shown.filterBy ? { ...shown, filterBy: { ...shown.filterBy, isPrimary: true } } : shown;
			} ),
		[ fields, inlineFilters, view.titleField ]
	);
	const isInCard = useContext( TableCardContext ) || embedded;
	const titledView = useMemo( () => ( view.titleField && view.showTitle === false ? { ...view, showTitle: true } : view ), [ view ] );
	const evenView = useMemo( () => evenColumns( titledView ), [ titledView ] );
	const columnWidths = useMemo( () => {
		const widths: Record< string, string > = {};
		const titleWidth = titleColumnWidth( titledView );
		const columnWidth = columnShareWidth( titledView );
		if ( titleWidth ) {
			widths[ '--newspack-dataviews-title-width' ] = titleWidth;
		}
		if ( columnWidth ) {
			widths[ '--newspack-dataviews-column-width' ] = columnWidth;
		}
		return Object.keys( widths ).length ? ( widths as React.CSSProperties ) : undefined;
	}, [ titledView ] );
	const footer =
		countLabel && ! evenView.infiniteScrollEnabled ? (
			<CountFooter
				countLabel={ countLabel }
				view={ evenView }
				data={ props.data }
				actions={ props.actions }
				paginationInfo={ props.paginationInfo }
				isLoading={ props.isLoading }
			/>
		) : undefined;
	const layout =
		children ??
		( inlineFilters || ! viewOptions || footer ? (
			<ToolbarLayout
				header={ props.header }
				search={ props.search ?? true }
				searchLabel={ props.searchLabel }
				inlineFilters={ inlineFilters }
				viewOptions={ viewOptions }
				footer={ footer }
			/>
		) : undefined );

	return (
		<div className={ classnames( 'newspack-dataviews', { 'newspack-dataviews--in-card': isInCard }, className ) } style={ columnWidths }>
			<BaseDataViews
				{ ...props }
				view={ evenView }
				onChangeView={ nextView => onChangeView( withoutColumnStyles( nextView ) ) }
				fields={ shownFields }
			>
				{ layout }
			</BaseDataViews>
		</div>
	);
}

// The implementation is typed against the `unknown` instantiation because
// BaseDataViews' conditionally-optional `getItemId` prop cannot be related
// to a JSX spread while `Item` is an unresolved type parameter. The export
// re-types it with the generic signature so call sites get item-aware
// typing for `data`, `fields`, `actions`, `getItemId`, etc. The assertion
// is verified by the compiler: the generic signature is assignable to the
// implementation's concrete one.
export default DataViews as < Item >( props: DataViewsProps< Item > ) => React.JSX.Element;

export { columnShareWidth, evenColumns, titleColumnWidth, withoutColumnStyles } from './even-columns';
export type { Action, Field, View } from '@wordpress/dataviews';
