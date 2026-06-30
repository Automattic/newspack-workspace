/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise */
/**
 * L0 — Group list (DataViews, full-width).
 *
 * Admin-facing list of every group/team subscription on the site. Mirrors
 * SubscriberList: filterable by status and plan, sortable, click-through to
 * the group detail screen.
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
import { Badge, DataViews, Router, Waiting } from '../../../../packages/components/src';
import { fmtDate } from '../format';
import './style.scss';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { getSubscriberById } from '../data/mock-subscribers';
import { getAllGroups, seatsUsed, ALL_GROUP_PLAN_NAMES, GROUP_STATUS_LABELS, GROUP_STATUS_BADGE_LEVEL } from '../data/mock-groups';
import { GROUP_LABEL_PLURAL, GROUP_LABEL_PLURAL_LOWER } from '../labels';

const { useHistory } = Router;

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
	const history = useHistory();
	const [ view, setView ] = useState( DEFAULT_VIEW );

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const openGroup = id => history.push( `/group/${ id }` );

	const groups = useMemo( () => getAllGroups(), [] );

	// Resolve owner avatar URLs once, keyed by group id. The list is held behind
	// a spinner until they resolve so the avatars don't flash in after the table.
	const ownerEmails = useMemo( () => groups.map( g => getSubscriberById( g.ownerId )?.email ), [ groups ] );
	const { avatars: avatarsByEmail, loading } = useAvatars( ownerEmails );
	const avatars = useMemo( () => {
		const byId = {};
		groups.forEach( g => {
			const email = getSubscriberById( g.ownerId )?.email;
			byId[ g.id ] = email ? avatarsByEmail[ email ] : undefined;
		} );
		return byId;
	}, [ groups, avatarsByEmail ] );

	const fields = useMemo(
		() => [
			{
				id: 'owner',
				label: __( 'Owner', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => getSubscriberById( item.ownerId )?.name || '',
				render: ( { item } ) => {
					const owner = getSubscriberById( item.ownerId );
					const details = (
						<div>
							{ owner ? <div>{ owner.name }</div> : <span>—</span> }
							<div className="newspack-subscribers-demo__email">{ item.plan }</div>
						</div>
					);
					if ( ! SHOW_AVATARS ) {
						return details;
					}
					return (
						<HStack spacing={ 3 } justify="flex-start" alignment="center">
							<img className="newspack-subscribers-demo__avatar" src={ avatars[ item.id ] } alt="" width={ 32 } height={ 32 } />
							{ details }
						</HStack>
					);
				},
				enableSorting: true,
			},
			{
				id: 'plan',
				label: __( 'Subscription', 'newspack-plugin' ),
				elements: ALL_GROUP_PLAN_NAMES.map( n => ( {
					value: n,
					label: n,
				} ) ),
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => item.plan,
				render: ( { item } ) => <span>{ item.plan }</span>,
				enableSorting: false,
			},
			{
				id: 'members',
				label: __( 'Members', 'newspack-plugin' ),
				getValue: ( { item } ) => seatsUsed( item ),
				render: ( { item } ) => (
					<span>
						{ seatsUsed( item ) } / { item.seatLimit }
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
		[ avatars ]
	);

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( groups, view, fields ), [ groups, view, fields ] );

	// Whole-row click → group detail (DataViews only wires up the title cell).
	const onRowClick = event => {
		if ( event.target.closest( 'a, button, input, label, [role="button"], [role="checkbox"]' ) ) {
			return;
		}
		const row = event.target.closest( 'tbody tr.dataviews-view-table__row' );
		if ( ! row ) {
			return;
		}
		const index = Array.from( row.parentNode.children ).indexOf( row );
		const item = processedData[ index ];
		if ( item ) {
			openGroup( item.id );
		}
	};

	const total = paginationInfo?.totalItems ?? 0;

	// Surface the cohort count in the header breadcrumb, e.g. "/ Cohorts 14".
	useEffect( () => {
		setHeaderData( {
			sectionName: (
				<>
					{ GROUP_LABEL_PLURAL }{ ' ' }
					<span
						className="newspack-subscribers-demo__header-count"
						aria-label={ sprintf( __( '%1$s %2$s total', 'newspack-plugin' ), total.toLocaleString(), GROUP_LABEL_PLURAL_LOWER ) }
					>
						{ `(${ total.toLocaleString() })` }
					</span>
				</>
			),
		} );
	}, [ setHeaderData, total ] );

	if ( loading ) {
		return (
			<div className="newspack-subscribers-demo__loading">
				<Waiting isCenter />
			</div>
		);
	}

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events
		<div className="newspack-subscribers-demo__clickable-rows" onClick={ onRowClick }>
			<DataViews
				data={ processedData }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ item => item.id }
				onClickItem={ item => openGroup( item.id ) }
				search
			/>
		</div>
	);
}
