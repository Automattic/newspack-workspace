/**
 * DataViews
 *
 * Wrapper around @wordpress/dataviews with Newspack styling.
 */

/**
 * WordPress dependencies
 */
import { DataViews as BaseDataViews } from '@wordpress/dataviews';

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
};

function DataViews( { className, ...props }: DataViewsProps< unknown > ) {
	return (
		<div className={ classnames( 'newspack-dataviews', className ) }>
			<BaseDataViews { ...props } />
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
