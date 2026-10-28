/**
 * DataViews
 *
 * Wrapper around @wordpress/dataviews with Newspack styling.
 */

/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';
import { DataViews as BaseDataViews } from '@wordpress/dataviews';
import { Stack } from '@wordpress/ui';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import './style.scss';

type DataViewsProps< Item > = React.ComponentProps< typeof BaseDataViews< Item > > & {
	className?: string;
	/**
	 * Show every filter as a chip beside the search instead of behind the
	 * filter toggle.
	 */
	inlineFilters?: boolean;
};

function InlineFiltersLayout( { header, search, searchLabel }: { header?: React.ReactNode; search: boolean; searchLabel?: string } ) {
	return (
		<>
			<Stack direction="row" justify="space-between" align="flex-start" gap="xs" className="dataviews__view-actions">
				<Stack direction="row" align="center" gap="sm" wrap="wrap" className="dataviews__search">
					{ search && <BaseDataViews.Search label={ searchLabel } /> }
					<BaseDataViews.Filters />
				</Stack>
				<Stack direction="row" align="center" gap="xs" className="newspack-dataviews__view-config">
					<BaseDataViews.LayoutSwitcher />
					<BaseDataViews.ViewConfig />
					{ header }
				</Stack>
			</Stack>
			<BaseDataViews.Layout />
			<BaseDataViews.Footer />
		</>
	);
}

function DataViews( { className, inlineFilters = false, fields, children, ...props }: DataViewsProps< unknown > ) {
	// A primary filter is always on show, so the toggle has nothing left to hide.
	const shownFields = useMemo(
		() =>
			inlineFilters
				? fields.map( field => ( field.filterBy ? { ...field, filterBy: { ...field.filterBy, isPrimary: true } } : field ) )
				: fields,
		[ fields, inlineFilters ]
	);
	const layout =
		children ??
		( inlineFilters ? (
			<InlineFiltersLayout header={ props.header } search={ props.search ?? true } searchLabel={ props.searchLabel } />
		) : undefined );

	return (
		<div className={ classnames( 'newspack-dataviews', className ) }>
			<BaseDataViews { ...props } fields={ shownFields }>
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

export type { Action, Field, View } from '@wordpress/dataviews';
