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

type DataViewsProps = React.ComponentProps< typeof BaseDataViews > & {
	className?: string;
};

export default function DataViews( { className, ...props }: DataViewsProps ) {
	return (
		<div className={ classnames( 'newspack-dataviews', className ) }>
			<BaseDataViews { ...props } />
		</div>
	);
}

export type { Action, Field, View } from '@wordpress/dataviews';
