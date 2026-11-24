/* eslint-disable no-bitwise */
/**
 * L0 — Group list (DataViews, full-width).
 *
 * Admin-facing list of every group/team subscription on the site. A site can
 * hold thousands of groups, so the list is server-paginated like the subscriber
 * list: the endpoint filters by status and subscription, sorts, and searches by
 * group name, owner name, owner email and subscription.
 *
 * A row is a group, so clicking it opens that group's detail screen inside the
 * wizard. The product name in the Subscription column keeps its own link to the
 * WooCommerce subscription — the money screen, which the detail view does not
 * replace — and the owner's own screen is reached from the detail view rather
 * than from here, so one row resolves to exactly one destination.
 */

/**
 * WordPress dependencies.
 */
import { useLayoutEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Badge } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, DataViews, Router, StatusBadge, Waiting } from '../../../../packages/components/src';
import { fmtDate } from '../format';
import './style.scss';
import LoadFailureNotice from '../components/LoadFailureNotice';
import RefreshOverlay, { useAnnounceRefresh } from '../components/RefreshOverlay';
import { useRetryFocus } from '../use-retry-focus';
import { useGroups } from '../data/use-groups';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { STATUS_INDICATORS, STATUS_LABELS } from '../status';
import { GROUP_LABEL_PLURAL, groupCountLabel, groupLoadFailedLabel } from '../labels';
import { SubscriptionLink } from '../links';

const FIT_COLUMNS = [ 'status' ];

const { useHistory } = Router;

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'createdAt', direction: 'desc' },
	search: '',
	// `product` is visible by default because it is the only place a group's
	// subscription is reachable — see the owner field below for why the title
	// cell can't carry that link.
	fields: [ 'status', 'product', 'members', 'createdAt' ],
	// Hide cancelled groups by default: they add noise with little value.
	// Clearing the Status filter brings them back.
	filters: [ { field: 'status', operator: 'isNot', value: 'cancelled' } ],
	layout: {},
	titleField: 'owner',
};

export default function GroupList() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const history = useHistory();

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const { groups, total, pages, products, loading: groupsLoading, settled, error, reload } = useGroups( view );

	// A row is a group, so it opens that group's detail screen in the wizard. The
	// owner's own screen is reachable from the group detail rather than from here
	// — one row, one destination. The product name keeps its own link to the
	// WooCommerce subscription, which the detail screen does not replace.
	const openGroup = item => {
		if ( item?.id ) {
			history.push( `/groups/${ item.id }` );
		}
	};

	// Subscription filter options: every product behind a group on the site, as
	// the endpoint lists them, not /plans, which also lists individual plans that
	// match no group.
	const productElements = useMemo( () => products.map( n => ( { value: n, label: n } ) ), [ products ] );

	const fields = useMemo(
		() => [
			{
				id: 'owner',
				label: __( 'Owner', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.owner?.name || '',
				// Owners tell groups apart where group names repeat, so the owner leads.
				// The group's name sits under it only when it was renamed: an unnamed
				// group takes its subscription's name, which the next column shows.
				//
				// This is the DataViews title field, which ColumnPrimary wraps in an
				// ItemClickWrapper: a role="button" whose own Enter/Space handler fires
				// onClickItem regardless of where the key originated, so a link nested
				// here would resolve to two destinations at once. The subscription's
				// link lives in the `product` column instead.
				render: ( { item } ) => (
					<div data-group-id={ item.id }>
						<HStack spacing={ 2 } justify="flex-start" alignment="center" expanded={ false }>
							<span>{ item.owner?.name || '—' }</span>
							{ item.seatRequest && (
								<Badge intent="medium">
									{ item.seatRequest.status === 'awaiting-payment'
										? __( 'Awaiting payment', 'newspack-plugin' )
										: __( 'Seat increase requested', 'newspack-plugin' ) }
								</Badge>
							) }
						</HStack>
						{ item.plan && item.plan !== item.product && <div className="newspack-subscribers__muted">{ item.plan }</div> }
					</div>
				),
				enableSorting: true,
			},
			{
				id: 'product',
				label: __( 'Subscription', 'newspack-plugin' ),
				enableGlobalSearch: true,
				elements: productElements,
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => item.product || '',
				// A deleted product leaves no name; the placeholder keeps the only link
				// to the group's subscription on the list.
				render: ( { item } ) => (
					<SubscriptionLink href={ item.editUrl }>{ item.product || __( '(Subscription)', 'newspack-plugin' ) }</SubscriptionLink>
				),
				enableSorting: false,
			},
			{
				id: 'members',
				label: __( 'Members', 'newspack-plugin' ),
				// The endpoint returns the owner-inclusive member count directly.
				getValue: ( { item } ) => item.members,
				// The count comes from each group's people, which the endpoint reads
				// per row on the page it returns, so it cannot sort the whole list by it.
				enableSorting: false,
				// A seat limit of 0 means the group is uncapped, so there is no
				// denominator to show against the count.
				render: ( { item } ) => (
					<span>
						{ item.seatLimit > 0
							? `${ item.members } / ${ item.seatLimit }`
							: /* translators: %s: number of members in the group. */
							  sprintf( __( '%s / Unlimited', 'newspack-plugin' ), item.members ) }
					</span>
				),
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				elements: Object.entries( STATUS_LABELS ).map( ( [ value, label ] ) => ( { value, label } ) ),
				filterBy: { operators: [ 'is', 'isNot' ] },
				getValue: ( { item } ) => item.status,
				// The endpoint sorts by name, owner and created date only.
				enableSorting: false,
				render: ( { item } ) => <StatusBadge status={ STATUS_INDICATORS[ item.status ] }>{ STATUS_LABELS[ item.status ] }</StatusBadge>,
			},
			{
				id: 'createdAt',
				label: __( 'Created', 'newspack-plugin' ),
				getValue: ( { item } ) => item.createdAt,
				render: ( { item } ) => <span className="newspack-dataviews__date">{ fmtDate( item.createdAt ) }</span>,
				enableSorting: true,
			},
		],
		[ productElements ]
	);

	// Whole-row click → the group's detail screen (DataViews only wires up the
	// title cell). The product name inside the row is a real link and is skipped
	// by the `a` guard below, so it keeps its own subscription target.
	//
	// DEPENDS ON DATAVIEWS INTERNAL MARKUP: the row is located by the
	// `dataviews-view-table__row` class, which DataViews owns and could rename on
	// upgrade — whole-row click-through would then silently stop working (grep for
	// "DEPENDS ON DATAVIEWS INTERNAL MARKUP" when bumping @wordpress/dataviews).
	// Keyboard users are unaffected either way: the title cell is a real button
	// wired to the same handler, which is the accessible path here.
	const onRowClick = event => {
		if ( event.target.closest( 'a, button, input, label, [role="button"], [role="checkbox"]' ) ) {
			return;
		}
		const row = event.target.closest( 'tbody tr.dataviews-view-table__row' );
		if ( ! row ) {
			return;
		}
		// Resolve by the id stamped on the title cell, not the row's DOM position.
		const id = row.querySelector( '[data-group-id]' )?.getAttribute( 'data-group-id' );
		const item = groups.find( g => String( g.id ) === String( id ) );
		if ( item ) {
			openGroup( item );
		}
	};

	// Surface the group count in the header breadcrumb, e.g. "/ Groups (14)".
	// Before paint: this carries the width override, and an error arrives outside a
	// React event, so a passive effect would paint full-bleed for one frame first.
	useLayoutEffect( () => {
		setHeaderData( {
			// Header data is merged, so the explicit `undefined` is what clears a
			// previous `false`; a retry never changes the route, so nothing else would.
			fullWidth: error ? false : undefined,
			sectionName: [
				{
					label: GROUP_LABEL_PLURAL,
					count: groupsLoading || error ? undefined : total,
					countLabel: groupCountLabel( total ),
				},
			],
		} );
	}, [ setHeaderData, total, groupsLoading, error ] );

	const { retryRef, retry } = useRetryFocus( { settled: ! groupsLoading, failed: Boolean( error ), reload } );

	// Only the load that has nothing to show blanks the screen. Search, filters and
	// sorting are refetches, and unmounting DataViews for them would take the
	// search box's focus with it. See the subscriber list, which does the same.
	//
	// A refetch with rows showing covers the screen with a spinner (see
	// RefreshOverlay). With no rows to cover, DataViews' own spinner stands in for
	// the empty state.
	const isRefreshing = groupsLoading && groups.length > 0;
	useAnnounceRefresh( isRefreshing, groupCountLabel( total ) );

	if ( groupsLoading && ! settled ) {
		return (
			<div className="newspack-subscribers__loading">
				<Waiting isCenter />
			</div>
		);
	}

	// A failed read must not read as "this site has no groups": say so, and offer
	// a retry.
	if ( error ) {
		const message = groupLoadFailedLabel( error );
		return (
			<LoadFailureNotice
				message={ message }
				action={
					<Button variant="link" ref={ retryRef } onClick={ retry }>
						{ __( 'Retry', 'newspack-plugin' ) }
					</Button>
				}
			/>
		);
	}

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events
		<div className="newspack-subscribers__clickable-rows" onClick={ onRowClick } aria-busy={ isRefreshing || undefined }>
			<DataViews
				fitColumns={ FIT_COLUMNS }
				countLabel={ groupCountLabel }
				data={ groups }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				isLoading={ groupsLoading && ! isRefreshing }
				paginationInfo={ { totalItems: total, totalPages: pages } }
				defaultLayouts={ { table: {} } }
				getItemId={ item => item.id }
				onClickItem={ openGroup }
				search
				inlineFilters
			/>
			{ isRefreshing && <RefreshOverlay /> }
		</div>
	);
}
