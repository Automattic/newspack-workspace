/**
 * WordPress dependencies
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { DataViews as BaseDataViews } from '@wordpress/dataviews';
import type { Action, View } from '@wordpress/dataviews';
import { __, sprintf } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import { formatCount } from '../breadcrumbs/format-count';

export type CountLabel = ( count: number, formattedCount: string ) => string;

type PaginationInfo = { totalItems: number; totalPages: number };

/**
 * The footer count, named with the list's own noun: "6 members", or "10 of 616
 * newsletters" when the page shows only part of the list.
 *
 * @param countLabel Formats a count with the list's noun.
 * @param shown      Items on the current page.
 * @param total      Items in the whole list.
 * @return The footer message.
 */
export function getCountMessage( countLabel: CountLabel, shown: number, total: number ): string {
	const totalText = countLabel( total, formatCount( total ) );
	if ( total <= shown ) {
		return totalText;
	}
	return sprintf(
		/* translators: 1: number of items on this page, 2: total number of items with their name, e.g. "616 newsletters". */
		__( '%1$s of %2$s', 'newspack-plugin' ),
		formatCount( shown ),
		totalText
	);
}

/**
 * DataViews' own footer, with the count from `countLabel` instead of core's
 * "N items". Shows under the same conditions as core's: a count when a row has a
 * bulk action, pagination when there is more than one page.
 */
export default function CountFooter< Item >( {
	countLabel,
	view,
	data,
	actions = [],
	paginationInfo,
	isLoading = false,
}: {
	countLabel: CountLabel;
	view: View;
	data: Item[];
	actions?: Action< Item >[];
	paginationInfo: PaginationInfo;
	isLoading?: boolean;
} ) {
	// Like core, keep the last settled numbers while a refresh is in flight.
	const settled = useRef( { data, paginationInfo } );
	useEffect( () => {
		if ( ! isLoading ) {
			settled.current = { data, paginationInfo };
		}
	}, [ data, paginationInfo, isLoading ] );
	const isRefreshing = isLoading && settled.current.data.length > 0;
	const shownData = isRefreshing ? settled.current.data : data;
	const { totalItems = 0, totalPages } = isRefreshing ? settled.current.paginationInfo : paginationInfo;

	const [ isDelayedRefreshing, setIsDelayedRefreshing ] = useState( false );
	useEffect( () => {
		if ( ! isRefreshing ) {
			return;
		}
		const timeout = setTimeout( () => setIsDelayedRefreshing( true ), 400 );
		return () => {
			clearTimeout( timeout );
			setIsDelayedRefreshing( false );
		};
	}, [ isRefreshing ] );

	const hasBulkActions = useMemo(
		() =>
			[ 'table', 'grid' ].includes( view.type ) &&
			shownData.some( item => actions.some( action => action.supportsBulk && ( ! action.isEligible || action.isEligible( item ) ) ) ),
		[ view.type, shownData, actions ]
	);
	const hasPagination = ! view.infiniteScrollEnabled && totalItems > 0 && totalPages > 1;

	if ( ! totalItems || ( ! hasBulkActions && ! hasPagination ) ) {
		return null;
	}

	return (
		<div className="dataviews-footer" { ...( isRefreshing ? { inert: '' } : {} ) }>
			<Stack
				direction="row"
				justify="end"
				align="center"
				gap="sm"
				className={ classnames( 'dataviews-footer__content', { 'is-refreshing': isDelayedRefreshing } ) }
			>
				{ hasBulkActions && (
					<span className="dataviews-footer__item-count">{ getCountMessage( countLabel, shownData.length, totalItems ) }</span>
				) }
				<BaseDataViews.Pagination />
			</Stack>
		</div>
	);
}
