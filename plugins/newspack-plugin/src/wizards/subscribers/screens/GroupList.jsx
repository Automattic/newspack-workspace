/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise */
/**
 * L0 — Group list (DataViews, full-width).
 *
 * Admin-facing list of every group/team subscription on the site. Unlike the
 * subscriber list, the group set is small enough to load in full and filter,
 * sort and paginate client-side. Filterable by status and plan, sortable,
 * click-through to the native subscription edit screen until the in-wizard group
 * detail lands (NPPD-1753 PR 4).
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Badge, DataViews, Waiting } from '../../../../packages/components/src';
import { fmtDate } from '../format';
import './style.scss';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { useGroups } from '../data/use-groups';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { GROUP_STATUS_LABELS, GROUP_STATUS_BADGE_LEVEL } from '../status';
import { GROUP_LABEL_PLURAL, GROUP_LABEL_PLURAL_LOWER } from '../labels';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'createdAt', direction: 'desc' },
	search: '',
	fields: [ 'members', 'status', 'createdAt' ],
	// Hide cancelled groups by default: they add noise with little value. Still
	// reachable by ticking "Cancelled" in the Status filter (or clearing it).
	filters: [ { field: 'status', operator: 'isAny', value: [ 'active', 'on-hold' ] } ],
	layout: {},
	titleField: 'owner',
};

export default function GroupList() {
	const [ view, setView ] = useState( DEFAULT_VIEW );

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const { groups, loading: groupsLoading } = useGroups();

	const openGroup = item => {
		if ( item?.editUrl ) {
			window.location.href = item.editUrl;
		}
	};

	// Resolve owner avatar URLs once, keyed by group id. The list is held behind
	// a spinner until they resolve so the avatars don't flash in after the table.
	const ownerEmails = useMemo( () => groups.map( g => g.owner?.email ), [ groups ] );
	const { avatars: avatarsByEmail, loading: avatarsLoading } = useAvatars( ownerEmails );
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
				render: ( { item } ) => {
					const details = (
						<div data-group-id={ item.id }>
							<HStack spacing={ 2 } justify="flex-start" alignment="center" expanded={ false }>
								{ item.owner ? <span>{ item.owner.name }</span> : <span>—</span> }
								{ item.seatRequest && (
									<Badge
										level="warning"
										text={
											item.seatRequest.status === 'awaiting-payment'
												? __( 'Awaiting payment', 'newspack-plugin' )
												: __( 'Seat increase requested', 'newspack-plugin' )
										}
									/>
								) }
							</HStack>
							<div className="newspack-subscribers__email">{ item.plan }</div>
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
				render: ( { item } ) => <span>{ item.plan }</span>,
				enableSorting: false,
			},
			{
				id: 'members',
				label: __( 'Members', 'newspack-plugin' ),
				// The endpoint returns the owner-inclusive member count directly.
				getValue: ( { item } ) => item.members,
				render: ( { item } ) => (
					<span>
						{ item.members } / { item.seatLimit }
					</span>
				),
				enableSorting: true,
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				elements: Object.entries( GROUP_STATUS_LABELS ).map( ( [ value, label ] ) => ( { value, label } ) ),
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => item.status,
				render: ( { item } ) => <Badge level={ GROUP_STATUS_BADGE_LEVEL[ item.status ] } text={ GROUP_STATUS_LABELS[ item.status ] } />,
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

	// Whole-row click → subscription edit (DataViews only wires up the title cell).
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
			openGroup( item );
		}
	};

	const total = paginationInfo?.totalItems ?? 0;

	// Surface the group count in the header breadcrumb, e.g. "/ Groups (14)".
	useEffect( () => {
		setHeaderData( {
			sectionName: (
				<>
					{ GROUP_LABEL_PLURAL }{ ' ' }
					<span
						className="newspack-subscribers__header-count"
						aria-label={ sprintf( __( '%1$s %2$s total', 'newspack-plugin' ), total.toLocaleString(), GROUP_LABEL_PLURAL_LOWER ) }
					>
						{ `(${ total.toLocaleString() })` }
					</span>
				</>
			),
		} );
	}, [ setHeaderData, total ] );

	if ( groupsLoading || avatarsLoading ) {
		return (
			<div className="newspack-subscribers__loading">
				<Waiting isCenter />
			</div>
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
				onClickItem={ openGroup }
				search
			/>
		</div>
	);
}
