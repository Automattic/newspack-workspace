/**
 * DataViews
 *
 * Wrapper around @wordpress/dataviews with Newspack styling.
 */

/**
 * WordPress dependencies
 */
import { useContext, useMemo } from '@wordpress/element';
import { DataViews as BaseDataViews, type View } from '@wordpress/dataviews';
import { Stack } from '@wordpress/ui';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import { columnShareWidth, evenColumns, titleColumnWidth, withoutColumnStyles } from './even-columns';
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
};

function ToolbarLayout( {
	header,
	search,
	searchLabel,
	inlineFilters,
	viewOptions,
}: {
	header?: React.ReactNode;
	search: boolean;
	searchLabel?: string;
	inlineFilters: boolean;
	viewOptions: boolean;
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
			<BaseDataViews.Footer />
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
	// A table inside a card defaults to compact rows; a view that sets its own density keeps it.
	const isInCard = useContext( TableCardContext );
	const cardView = useMemo(
		() =>
			isInCard && 'table' === view.type && ! view.layout?.density
				? ( { ...view, layout: { ...view.layout, density: 'compact' } } as View )
				: view,
		[ isInCard, view ]
	);
	const titledView = useMemo(
		() => ( cardView.titleField && cardView.showTitle === false ? { ...cardView, showTitle: true } : cardView ),
		[ cardView ]
	);
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
	const layout =
		children ??
		( inlineFilters || ! viewOptions ? (
			<ToolbarLayout
				header={ props.header }
				search={ props.search ?? true }
				searchLabel={ props.searchLabel }
				inlineFilters={ inlineFilters }
				viewOptions={ viewOptions }
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
