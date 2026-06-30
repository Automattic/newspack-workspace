/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise */
/**
 * L0 — Subscriber list (DataViews, full-width).
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Badge, DataViews, Router, Waiting } from '../../../../packages/components/src';
import './style.scss';
import { fmtRelative, fmtDate } from '../format';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { SUBSCRIBERS, DIGITAL_PLANS, PRINT_PLANS, ALL_TAGS, NEWSLETTERS } from '../data/mock-subscribers';
import { getAllGroups, ALL_GROUP_PLAN_NAMES } from '../data/mock-groups';
import { GROUP_LABEL } from '../labels';
import { STATUS_LABELS, STATUS_BADGE_LEVEL, STATUS_RANK, displayStatuses } from '../status';

const { useHistory } = Router;

// Every subscription a subscriber has, group and individual alike: cohorts they
// own or belong to (tagged by role) plus their own individual subscriptions.
// Each entry carries its own status so the column can show them independently.
// `groupEntries` is the subscriber's precomputed [{ group, isOwner }] memberships.
const planEntries = ( item, groupEntries ) => {
	const cohorts = ( groupEntries || [] ).map( ( { group, isOwner } ) => ( {
		plan: group.plan,
		status: group.status,
		role: isOwner ? 'owner' : 'member',
	} ) );
	const individual = ( item.subscriptions || [] ).map( s => ( {
		plan: s.plan,
		status: s.status,
		role: null,
	} ) );
	// Active subscriptions list first, then on-hold, then cancelled.
	return [ ...cohorts, ...individual ].sort( ( a, b ) => STATUS_RANK[ a.status ] - STATUS_RANK[ b.status ] );
};

// Plan entries to show in the Subscription column: a cancelled plan is dropped
// whenever the reader still has a live (active/on-hold) one, since it's no longer
// what they're paying for. A fully churned reader keeps their cancelled plans.
const visiblePlanEntries = entries => {
	const hasLive = entries.some( e => e.status !== 'cancelled' );
	return hasLive ? entries.filter( e => e.status !== 'cancelled' ) : entries;
};

// The status badge(s) a subscriber gets in the list: every distinct status
// across all their subscriptions, active-first, with cancelled hidden when any
// live plan remains. See displayStatuses.
const subscriberStatuses = ( item, groupEntries ) =>
	displayStatuses(
		planEntries( item, groupEntries ).map( e => e.status ),
		item.status
	);

const ALL_PLAN_NAMES = [ ...DIGITAL_PLANS, ...PRINT_PLANS ].map( p => p.name );

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'memberSince', direction: 'desc' },
	search: '',
	fields: [ 'status', 'plans', 'lastPayment', 'memberSince' ],
	filters: [],
	layout: {},
	titleField: 'name',
};

export default function SubscriberList() {
	const history = useHistory();
	const [ view, setView ] = useState( DEFAULT_VIEW );

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	// Resolve avatar URLs once, keyed by subscriber id. The list is held behind a
	// spinner until they resolve so the avatars don't flash in after the table.
	const emails = useMemo( () => SUBSCRIBERS.map( s => s.email ), [] );
	const { avatars: avatarsByEmail, loading } = useAvatars( emails );
	const avatars = useMemo( () => {
		const byId = {};
		SUBSCRIBERS.forEach( s => {
			byId[ s.id ] = avatarsByEmail[ s.email ];
		} );
		return byId;
	}, [ avatarsByEmail ] );

	const openProfile = id => history.push( `/profile/${ id }` );

	// Index every subscriber's group memberships (owner or member) once, so the
	// Status and Subscription columns resolve from a map instead of re-reading
	// group storage per row on every search keystroke, sort, or filter.
	const groupsBySubscriber = useMemo( () => {
		const index = {};
		getAllGroups().forEach( group => {
			const ids = new Set( [ group.ownerId, ...( group.members || [] ).map( m => m.subscriberId ) ] );
			ids.forEach( id => {
				if ( ! index[ id ] ) {
					index[ id ] = [];
				}
				index[ id ].push( { group, isOwner: group.ownerId === id } );
			} );
		} );
		return index;
	}, [] );

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Subscriber', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => `${ item.name } ${ item.email }`,
				render: ( { item } ) => {
					const details = (
						<div>
							<div>{ item.name }</div>
							<div className="newspack-subscribers-demo__email">{ item.email }</div>
						</div>
					);
					if ( ! SHOW_AVATARS ) {
						return <div data-subscriber-id={ item.id }>{ details }</div>;
					}
					return (
						<HStack data-subscriber-id={ item.id } spacing={ 3 } justify="flex-start" alignment="center">
							<img className="newspack-subscribers-demo__avatar" src={ avatars[ item.id ] } alt="" width={ 32 } height={ 32 } />
							{ details }
						</HStack>
					);
				},
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				elements: Object.entries( STATUS_LABELS ).map( ( [ value, label ] ) => ( { value, label } ) ),
				filterBy: { operators: [ 'isAny' ] },
				// Filter on the same reduced display set the badges use, so a
				// Cancelled filter means fully churned: cancelled is hidden while any
				// active or on-hold plan remains, and only matches when every plan is
				// cancelled.
				getValue: ( { item } ) => subscriberStatuses( item, groupsBySubscriber[ item.id ] ),
				render: ( { item } ) => (
					<HStack spacing={ 2 } justify="flex-start" alignment="center" wrap>
						{ subscriberStatuses( item, groupsBySubscriber[ item.id ] ).map( status => (
							<Badge key={ status } level={ STATUS_BADGE_LEVEL[ status ] } text={ STATUS_LABELS[ status ] } />
						) ) }
					</HStack>
				),
			},
			{
				id: 'plans',
				label: __( 'Subscription', 'newspack-plugin' ),
				elements: [ ...ALL_PLAN_NAMES, ...ALL_GROUP_PLAN_NAMES ].map( n => ( {
					value: n,
					label: n,
				} ) ),
				filterBy: { operators: [ 'isAny' ] },
				// Return an array so the isAny filter matches on any of the lines.
				getValue: ( { item } ) => planEntries( item, groupsBySubscriber[ item.id ] ).map( e => e.plan ),
				render: ( { item } ) => {
					const entries = visiblePlanEntries( planEntries( item, groupsBySubscriber[ item.id ] ) );
					if ( entries.length === 0 ) {
						return <span>—</span>;
					}
					// 8px between subscriptions so each (a group's plan + its
					// Owner/Member line included) reads as a distinct block.
					return (
						<VStack spacing={ 2 } alignment="left">
							{ entries.map( ( e, i ) =>
								e.role ? (
									<div key={ i }>
										<HStack spacing={ 2 } justify="flex-start" alignment="center">
											<span>{ e.plan }</span>
											<Badge level={ e.role === 'owner' ? 'info' : undefined } text={ GROUP_LABEL } />
										</HStack>
										<div className="newspack-subscribers-demo__muted">
											{ e.role === 'owner' ? __( 'Owner', 'newspack-plugin' ) : __( 'Member', 'newspack-plugin' ) }
										</div>
									</div>
								) : (
									<div key={ i }>{ e.plan }</div>
								)
							) }
						</VStack>
					);
				},
				enableSorting: false,
			},
			{
				id: 'lastPayment',
				label: __( 'Last payment', 'newspack-plugin' ),
				// Covered group members have no payment of their own; coerce null to
				// an empty string so the DataViews sort comparator doesn't choke.
				getValue: ( { item } ) => item.lastPayment || '',
				render: ( { item } ) => <span>{ item.lastPayment ? fmtDate( item.lastPayment ) : '—' }</span>,
			},
			{
				id: 'memberSince',
				label: __( 'Member since', 'newspack-plugin' ),
				getValue: ( { item } ) => item.memberSince,
				render: ( { item } ) => (
					<div>
						<div>{ fmtDate( item.memberSince ) }</div>
						<div className="newspack-subscribers-demo__muted">{ fmtRelative( item.memberSince ) }</div>
					</div>
				),
			},
			{
				id: 'lastSeen',
				label: __( 'Last seen', 'newspack-plugin' ),
				// Never-returning readers have no last_active; coerce null to an
				// empty string so the DataViews sort comparator doesn't choke.
				getValue: ( { item } ) => item.lastSeen || '',
				render: ( { item } ) =>
					item.lastSeen ? (
						<div>
							<div>{ fmtDate( item.lastSeen ) }</div>
							<div className="newspack-subscribers-demo__muted">{ fmtRelative( item.lastSeen ) }</div>
						</div>
					) : (
						<span>—</span>
					),
			},
			{
				id: 'tags',
				label: __( 'Tags', 'newspack-plugin' ),
				elements: ALL_TAGS.map( t => ( { value: t, label: t } ) ),
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => item.tags || [],
				render: ( { item } ) => (
					<HStack spacing={ 1 } justify="flex-start" wrap>
						{ ( item.tags || [] ).map( t => (
							<Badge key={ t } text={ t } />
						) ) }
					</HStack>
				),
				enableSorting: false,
			},
			{
				id: 'newsletters',
				label: __( 'Newsletters', 'newspack-plugin' ),
				elements: NEWSLETTERS.map( n => ( {
					value: n.id,
					label: n.name,
				} ) ),
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => item.newsletters || [],
				render: ( { item } ) => (
					<div>
						{ ( item.newsletters || [] )
							.map( id => NEWSLETTERS.find( n => n.id === id )?.name )
							.filter( Boolean )
							.join( ', ' ) }
					</div>
				),
				enableSorting: false,
			},
		],
		[ avatars, groupsBySubscriber ]
	);

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( SUBSCRIBERS, view, fields ), [ view, fields ] );

	// DataViews only makes the title cell clickable; delegate clicks from the
	// rest of the row to the same profile, ignoring genuinely interactive
	// elements (the title button, selection checkbox, links). Rows map 1:1 to
	// the current page's data, so the <tr> position resolves the item.
	const onRowClick = event => {
		if ( event.target.closest( 'a, button, input, label, [role="button"], [role="checkbox"]' ) ) {
			return;
		}
		const row = event.target.closest( 'tbody tr.dataviews-view-table__row' );
		if ( ! row ) {
			return;
		}
		// Resolve by the id stamped on the name cell, not the row's DOM position.
		const id = row.querySelector( '[data-subscriber-id]' )?.getAttribute( 'data-subscriber-id' );
		if ( id ) {
			openProfile( id );
		}
	};

	const total = paginationInfo?.totalItems ?? 0;

	// Surface the subscriber count in the header breadcrumb, e.g. "/ Subscribers 85".
	useEffect( () => {
		setHeaderData( {
			sectionName: (
				<>
					{ __( 'Subscribers', 'newspack-plugin' ) }{ ' ' }
					<span
						className="newspack-subscribers-demo__header-count"
						aria-label={ sprintf( __( '%s subscribers total', 'newspack-plugin' ), total.toLocaleString() ) }
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
				onClickItem={ item => openProfile( item.id ) }
				search
			/>
		</div>
	);
}
