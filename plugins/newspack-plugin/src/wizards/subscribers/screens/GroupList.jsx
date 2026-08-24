/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise */
/**
 * L0 — Group list (DataViews, full-width).
 *
 * Admin-facing list of every group/team subscription on the site. Unlike the
 * subscriber list, the group set is small enough to load in full and filter,
 * sort and paginate client-side. Filterable by status and plan, sortable. Click
 * targets follow the rule both tabs share: the row (and the owner name in it)
 * opens that person's user-edit screen, while the plan name in the Subscription
 * column opens that group's subscription. Both are the native admin screens until
 * the in-wizard group detail lands (NPPD-1753 PR 4).
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Badge } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, DataViews, Notice, StatusIndicator, Waiting } from '../../../../packages/components/src';
import { fmtDate } from '../format';
import './style.scss';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { useGroups } from '../data/use-groups';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { STATUS_INDICATORS, STATUS_LABELS } from '../status';
import { GROUP_LABEL_PLURAL, groupCountLabel, groupLoadFailedLabel } from '../labels';
import { SubscriptionLink } from '../links';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'createdAt', direction: 'desc' },
	search: '',
	// `plan` is visible by default because it is the only place a group's
	// subscription is reachable — see the owner field below for why the title
	// cell can't carry that link.
	fields: [ 'plan', 'members', 'status', 'createdAt' ],
	// Hide cancelled groups by default: they add noise with little value. Still
	// reachable by ticking "Cancelled" in the Status filter (or clearing it).
	// A group awaiting its first payment sits in `pending`, so it stays visible.
	filters: [ { field: 'status', operator: 'isAny', value: [ 'active', 'pending', 'on-hold' ] } ],
	layout: {},
	titleField: 'owner',
};

export default function GroupList() {
	const [ view, setView ] = useState( DEFAULT_VIEW );

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const { groups, loading: groupsLoading, error, reload } = useGroups();

	// The row's title is the owner, so the row resolves to that person. The group
	// itself is reachable from the plan name, which links to its subscription.
	// A group whose owner no longer exists has nothing to open, so its row is not
	// clickable (isItemClickable below) rather than silently doing nothing.
	const openOwner = item => {
		if ( item?.owner?.editUrl ) {
			window.location.href = item.owner.editUrl;
		}
	};
	const hasOwnerLink = item => !! item?.owner?.editUrl;

	// Resolve owner avatar URLs, keyed by group id. The table renders immediately
	// with the avatar placeholder and each avatar fills in as it resolves.
	const ownerEmails = useMemo( () => groups.map( g => g.owner?.email ), [ groups ] );
	const { avatars: avatarsByEmail } = useAvatars( ownerEmails );
	const avatars = useMemo( () => {
		const byId = {};
		groups.forEach( g => {
			const email = g.owner?.email;
			byId[ g.id ] = email ? avatarsByEmail[ email ] : undefined;
		} );
		return byId;
	}, [ groups, avatarsByEmail ] );

	// Plan filter options come from the loaded groups (the plans endpoint arrives
	// in a later slice); distinct, in first-seen order.
	const planElements = useMemo(
		() => [ ...new Set( groups.map( g => g.plan ).filter( Boolean ) ) ].map( n => ( { value: n, label: n } ) ),
		[ groups ]
	);

	const fields = useMemo(
		() => [
			{
				id: 'owner',
				label: __( 'Owner', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.owner?.name || '',
				// The secondary line is the owner's email, mirroring the subscriber
				// list's title cell — not the plan. This is the DataViews title
				// field, which ColumnPrimary wraps in an ItemClickWrapper: a
				// role="button" whose own Enter/Space handler fires onClickItem
				// regardless of where the key originated, so a link nested here
				// resolves to two destinations at once, and ARIA treats descendants
				// of role="button" as presentational. The plan's link lives in the
				// `plan` column below, outside the wrapper.
				render: ( { item } ) => {
					const details = (
						<div data-group-id={ item.id }>
							<HStack spacing={ 2 } justify="flex-start" alignment="center" expanded={ false }>
								{ item.owner ? <span>{ item.owner.name }</span> : <span>—</span> }
								{ item.seatRequest && (
									<Badge intent="medium">
										{ item.seatRequest.status === 'awaiting-payment'
											? __( 'Awaiting payment', 'newspack-plugin' )
											: __( 'Seat increase requested', 'newspack-plugin' ) }
									</Badge>
								) }
							</HStack>
							<div className="newspack-subscribers__email">{ item.owner?.email }</div>
						</div>
					);
					if ( ! SHOW_AVATARS ) {
						return details;
					}
					return (
						<HStack spacing={ 3 } justify="flex-start" alignment="center">
							{ avatars[ item.id ] ? (
								<img className="newspack-subscribers__avatar" src={ avatars[ item.id ] } alt="" width={ 32 } height={ 32 } />
							) : (
								<span className="newspack-subscribers__avatar" aria-hidden="true" />
							) }
							{ details }
						</HStack>
					);
				},
				enableSorting: true,
			},
			{
				id: 'plan',
				label: __( 'Subscription', 'newspack-plugin' ),
				elements: planElements,
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => item.plan,
				render: ( { item } ) => <SubscriptionLink href={ item.editUrl }>{ item.plan }</SubscriptionLink>,
				enableSorting: false,
			},
			{
				id: 'members',
				label: __( 'Members', 'newspack-plugin' ),
				// The endpoint returns the owner-inclusive member count directly.
				getValue: ( { item } ) => item.members,
				// A seat limit of 0 means the group is uncapped, so there is no
				// denominator to show against the count.
				render: ( { item } ) => (
					<span>
						{ item.seatLimit > 0
							? `${ item.members } / ${ item.seatLimit }`
							: sprintf( __( '%s / Unlimited', 'newspack-plugin' ), item.members ) }
					</span>
				),
				enableSorting: true,
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				elements: Object.entries( STATUS_LABELS ).map( ( [ value, label ] ) => ( { value, label } ) ),
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => item.status,
				render: ( { item } ) => (
					<StatusIndicator status={ STATUS_INDICATORS[ item.status ] }>{ STATUS_LABELS[ item.status ] }</StatusIndicator>
				),
			},
			{
				id: 'createdAt',
				label: __( 'Created', 'newspack-plugin' ),
				getValue: ( { item } ) => item.createdAt,
				render: ( { item } ) => <span>{ fmtDate( item.createdAt ) }</span>,
				enableSorting: true,
			},
		],
		[ avatars, planElements ]
	);

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( groups, view, fields ), [ groups, view, fields ] );

	// Whole-row click → the owner's user edit (DataViews only wires up the title
	// cell). The plan name inside the row is a real link and is skipped by the
	// `a` guard below, so it keeps its own subscription target.
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
		// Resolve by the id stamped on the owner cell, not the row's DOM position.
		const id = row.querySelector( '[data-group-id]' )?.getAttribute( 'data-group-id' );
		const item = groups.find( g => String( g.id ) === String( id ) );
		if ( item ) {
			openOwner( item );
		}
	};

	const total = paginationInfo?.totalItems ?? 0;

	// Surface the group count in the header breadcrumb, e.g. "/ Groups (14)".
	useEffect( () => {
		setHeaderData( {
			sectionName: [
				{
					label: GROUP_LABEL_PLURAL,
					count: groupsLoading || error ? undefined : total,
					countLabel: groupCountLabel( total ),
				},
			],
		} );
	}, [ setHeaderData, total, groupsLoading, error ] );

	if ( groupsLoading ) {
		return (
			<div className="newspack-subscribers__loading">
				<Waiting isCenter />
			</div>
		);
	}

	// A failed read must not read as "this site has no groups": say so, and offer
	// a retry.
	if ( error ) {
		return (
			<Notice isError noticeText={ groupLoadFailedLabel( error ) }>
				<Button variant="link" onClick={ reload }>
					{ __( 'Retry', 'newspack-plugin' ) }
				</Button>
			</Notice>
		);
	}

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events
		<div className="newspack-subscribers__clickable-rows" onClick={ onRowClick }>
			<DataViews
				data={ processedData }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ item => item.id }
				onClickItem={ openOwner }
				isItemClickable={ hasOwnerLink }
				search
			/>
		</div>
	);
}
