/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise */
/**
 * L0 — Subscriber list (DataViews, full-width).
 *
 * Server-paginated: filtering, sorting, search and paging run against the REST
 * endpoint (via useSubscribers), not a client-side array, so the table scales to
 * large reader bases. Each row's group memberships arrive embedded on the item
 * (item.groups), so the Status/Subscription/Group-role columns resolve without a
 * second lookup. Click targets follow the rule both tabs share: the row (and the
 * subscriber name in it) opens that person, while a plan name in the Subscription
 * column opens that subscription. The person now resolves in-wizard to their
 * profile; the plan name still opens the native subscription edit screen, since
 * nothing in the wizard replaces it yet.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Badge, Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, DataViews, Notice, Router, StatusIndicator, Waiting } from '../../../../packages/components/src';
import { formatCount } from '../../../../packages/components/src/breadcrumbs/format-count';
import './style.scss';
import { fmtRelative, fmtDate } from '../format';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { useSubscribers } from '../data/use-subscribers';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { GROUP_LABEL, ROLE_LABELS, groupRoleLabel } from '../labels';
import { SubscriptionLink } from '../links';
import { STATUS_INDICATORS, STATUS_LABELS, displayStatuses, statusRank } from '../status';

// A subscriber's group memberships, in the shape the column helpers expect
// ([{ group, role }]). The endpoint embeds them flat on the item as
// item.groups = [{ id, plan, status, role, editUrl }].
const groupEntriesOf = item => ( item.groups || [] ).map( g => ( { group: { plan: g.plan, status: g.status, editUrl: g.editUrl }, role: g.role } ) );

// Every subscription a subscriber has, group and individual alike: cohorts they
// own or belong to (tagged by role) plus their own individual subscriptions.
// Each entry carries its own status so the column can show them independently.
// `groupEntries` is the subscriber's [{ group, role }] memberships.
const planEntries = ( item, groupEntries ) => {
	const cohorts = ( groupEntries || [] ).map( ( { group, role } ) => ( {
		plan: group.plan,
		status: group.status,
		editUrl: group.editUrl,
		role,
	} ) );
	const individual = ( item.subscriptions || [] ).map( s => ( {
		plan: s.plan,
		status: s.status,
		editUrl: s.editUrl,
		role: null,
	} ) );
	// Active subscriptions list first, then on-hold, then cancelled.
	return [ ...cohorts, ...individual ].sort( ( a, b ) => statusRank( a.status ) - statusRank( b.status ) );
};

// Plan entries to show in the Subscription column: a cancelled plan is dropped
// whenever the reader still has a live one, since it's no longer what they're
// paying for. A fully churned reader keeps their cancelled plans. This is one of
// the four copies of that invariant — see the SOURCE OF TRUTH note on
// displayStatuses in status.js before changing it.
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

const { useHistory, useLocation } = Router;

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
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const history = useHistory();
	const location = useLocation();

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	// The server owns filter/sort/paginate; this page's rows come back already
	// narrowed. Group-role, tag, newsletter and plan filters arrive in later
	// slices (the endpoint honors status here); sorting is name / member-since.
	const { items, total, pages, loading: subscribersLoading, error, reload } = useSubscribers( view );

	// Resolve avatar URLs for the current page, keyed by subscriber id. The table
	// renders immediately with the avatar placeholder and each avatar fills in as
	// it resolves — blanking the whole table on every page/sort/filter change
	// would be a far heavier flash than the one it avoids.
	const emails = useMemo( () => items.map( s => s.email ), [ items ] );
	const { avatars: avatarsByEmail } = useAvatars( emails );
	const avatars = useMemo( () => {
		const byId = {};
		items.forEach( s => {
			byId[ s.id ] = avatarsByEmail[ s.email ];
		} );
		return byId;
	}, [ items, avatarsByEmail ] );

	// Clicking a person opens their in-wizard profile. The current list route
	// travels as `from` so the profile's back-nav and breadcrumb return here
	// rather than guessing: HashRouter drops location.state on reload, so the
	// origin has to survive in the URL.
	const openSubscriber = item => {
		if ( item?.id ) {
			history.push( `/subscribers/${ item.id }?from=${ encodeURIComponent( `#${ location.pathname }` ) }` );
		}
	};

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Subscriber', 'newspack-plugin' ),
				enableGlobalSearch: true,
				enableSorting: true,
				getValue: ( { item } ) => `${ item.name } ${ item.email }`,
				render: ( { item } ) => {
					const details = (
						<div>
							<div>{ item.name }</div>
							<div className="newspack-subscribers__email">{ item.email }</div>
						</div>
					);
					if ( ! SHOW_AVATARS ) {
						return <div data-subscriber-id={ item.id }>{ details }</div>;
					}
					return (
						<HStack data-subscriber-id={ item.id } spacing={ 3 } justify="flex-start" alignment="center">
							{ avatars[ item.id ] ? (
								<img className="newspack-subscribers__avatar" src={ avatars[ item.id ] } alt="" width={ 32 } height={ 32 } />
							) : (
								<span className="newspack-subscribers__avatar" aria-hidden="true" />
							) }
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
				enableSorting: false,
				// The Cancelled filter means fully churned: the endpoint resolves it
				// against the same reduced display set the badges use, so cancelled is
				// hidden while any active or on-hold plan remains.
				getValue: ( { item } ) => subscriberStatuses( item, groupEntriesOf( item ) ),
				// A subscriber can hold several statuses at once, so they stack rather
				// than sitting on one line, where two icon-and-label pairs would run
				// together into one phrase.
				render: ( { item } ) => (
					<Stack direction="column" align="flex-start" gap="sm">
						{ subscriberStatuses( item, groupEntriesOf( item ) ).map( status => (
							<StatusIndicator key={ status } status={ STATUS_INDICATORS[ status ] }>
								{ STATUS_LABELS[ status ] }
							</StatusIndicator>
						) ) }
					</Stack>
				),
			},
			{
				id: 'plans',
				label: __( 'Subscription', 'newspack-plugin' ),
				// Plan filtering waits on the plans endpoint (NPPD-1753 PR 6); this is
				// a display-only column for now.
				enableSorting: false,
				render: ( { item } ) => {
					const entries = visiblePlanEntries( planEntries( item, groupEntriesOf( item ) ) );
					if ( entries.length === 0 ) {
						return <span>—</span>;
					}
					// 8px between subscriptions so each reads as a distinct block. A
					// group entry is tagged "(Group)"; the subscriber's role in it is
					// L1 information (profile card, group members table). The plan
					// name links to that subscription while the row resolves to the
					// person, so the two affordances stay distinct — see links.jsx.
					return (
						<VStack spacing={ 2 } alignment="left">
							{ entries.map( ( e, i ) => (
								<div key={ i }>
									<SubscriptionLink href={ e.editUrl }>{ e.plan }</SubscriptionLink>
									{ e.role && <>&nbsp;{ `(${ GROUP_LABEL })` }</> }
								</div>
							) ) }
						</VStack>
					);
				},
			},
			{
				id: 'groupRole',
				label: groupRoleLabel(),
				// Hidden by default (not in DEFAULT_VIEW fields). Display-only until
				// the endpoint gains a group-role filter (NPPD-2111). One line per
				// group, plan-qualified only when the reader belongs to more than one.
				enableSorting: false,
				render: ( { item } ) => {
					const entries = groupEntriesOf( item );
					if ( entries.length === 0 ) {
						return <span>—</span>;
					}
					return (
						<VStack spacing={ 2 } alignment="left">
							{ entries.map( ( e, i ) => (
								<div key={ i }>{ entries.length > 1 ? `${ ROLE_LABELS[ e.role ] } (${ e.group.plan })` : ROLE_LABELS[ e.role ] }</div>
							) ) }
						</VStack>
					);
				},
			},
			{
				id: 'lastPayment',
				label: __( 'Last payment', 'newspack-plugin' ),
				// Not server-sortable in this slice.
				enableSorting: false,
				render: ( { item } ) => <span>{ item.lastPayment ? fmtDate( item.lastPayment ) : '—' }</span>,
			},
			{
				id: 'memberSince',
				label: __( 'Member since', 'newspack-plugin' ),
				enableSorting: true,
				getValue: ( { item } ) => item.memberSince,
				render: ( { item } ) =>
					item.memberSince ? (
						<div>
							<div>{ fmtDate( item.memberSince ) }</div>
							<div className="newspack-subscribers__muted">{ fmtRelative( item.memberSince ) }</div>
						</div>
					) : (
						<span>—</span>
					),
			},
			{
				id: 'lastSeen',
				label: __( 'Last seen', 'newspack-plugin' ),
				// Wired to reader activity in a later slice; hidden by default.
				enableSorting: false,
				render: ( { item } ) =>
					item.lastSeen ? (
						<div>
							<div>{ fmtDate( item.lastSeen ) }</div>
							<div className="newspack-subscribers__muted">{ fmtRelative( item.lastSeen ) }</div>
						</div>
					) : (
						<span>—</span>
					),
			},
			{
				id: 'tags',
				label: __( 'Tags', 'newspack-plugin' ),
				// Populated in a later slice (NPPD-1753 PR 7); hidden by default.
				enableSorting: false,
				render: ( { item } ) => (
					<HStack spacing={ 1 } justify="flex-start" wrap>
						{ ( item.tags || [] ).map( t => (
							<Badge key={ t } intent="none">
								{ t }
							</Badge>
						) ) }
					</HStack>
				),
			},
			{
				id: 'newsletters',
				label: __( 'Newsletters', 'newspack-plugin' ),
				// Populated in a later slice (NPPD-1753 PR 7); hidden by default.
				enableSorting: false,
				render: ( { item } ) => <div>{ ( item.newsletters || [] ).join( ', ' ) }</div>,
			},
		],
		[ avatars ]
	);

	// DataViews only makes the title cell clickable; delegate clicks from the
	// rest of the row to the same target, ignoring genuinely interactive elements
	// (the title button, selection checkbox, links).
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
		// Resolve by the id stamped on the name cell, not the row's DOM position.
		const id = row.querySelector( '[data-subscriber-id]' )?.getAttribute( 'data-subscriber-id' );
		const item = items.find( s => String( s.id ) === String( id ) );
		if ( item ) {
			openSubscriber( item );
		}
	};

	// Surface the subscriber count in the header breadcrumb, e.g. "/ Subscribers (85)".
	useEffect( () => {
		setHeaderData( {
			sectionName: [
				{
					label: __( 'Subscribers', 'newspack-plugin' ),
					count: subscribersLoading || error ? undefined : total,
					countLabel: sprintf(
						/* translators: %s: number of subscribers matching the current view. */
						_n( '%s subscriber', '%s subscribers', total, 'newspack-plugin' ),
						formatCount( total )
					),
				},
			],
		} );
	}, [ setHeaderData, total, subscribersLoading, error ] );

	if ( subscribersLoading ) {
		return (
			<div className="newspack-subscribers__loading">
				<Waiting isCenter />
			</div>
		);
	}

	// A failed read must not read as "this site has no subscribers": say so, and
	// offer a retry.
	if ( error ) {
		return (
			<Notice isError noticeText={ sprintf( __( 'Could not load subscribers: %s', 'newspack-plugin' ), error ) }>
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
				data={ items }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ { totalItems: total, totalPages: pages } }
				defaultLayouts={ { table: {} } }
				getItemId={ item => item.id }
				onClickItem={ openSubscriber }
				search
			/>
		</div>
	);
}
