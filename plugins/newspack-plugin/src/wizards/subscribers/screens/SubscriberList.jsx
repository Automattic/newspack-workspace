/* eslint-disable no-bitwise */
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
import { createPortal, useEffect, useLayoutEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Badge, Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, DataViews, Router, StatusIndicator, Waiting } from '../../../../packages/components/src';
import { formatCount } from '../../../../packages/components/src/breadcrumbs/format-count';
import './style.scss';
import LoadFailureNotice from '../components/LoadFailureNotice';
import { useRetryFocus } from '../use-retry-focus';
import { fmtRelative, fmtDate } from '../format';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { getSavedListView, saveListView } from '../data/list-view-state';
import { usePlans } from '../data/use-plans';
import { useSubscribers } from '../data/use-subscribers';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { GROUP_LABEL, ROLE_LABELS, groupRoleLabel } from '../labels';
import { SubscriptionLink } from '../links';
import { STATUS_INDICATORS, STATUS_LABELS, displayStatuses, statusRank } from '../status';

// A subscriber's group memberships, in the shape the column helpers expect
// ([{ group, role }]). The endpoint embeds them flat on the item as
// item.groups = [{ id, plan, status, role, editUrl, isComplimentary }].
const groupEntriesOf = item =>
	( item.groups || [] ).map( g => ( {
		group: { plan: g.plan, status: g.status, editUrl: g.editUrl, isComplimentary: !! g.isComplimentary },
		role: g.role,
	} ) );

// Every subscription a subscriber has, group and individual alike: cohorts they
// own or belong to (tagged by role) plus their own individual subscriptions.
// Each entry carries its own status so the column can show them independently.
// `groupEntries` is the subscriber's [{ group, role }] memberships.
const planEntries = ( item, groupEntries ) => {
	const cohorts = ( groupEntries || [] ).map( ( { group, role } ) => ( {
		plan: group.plan,
		status: group.status,
		editUrl: group.editUrl,
		isComplimentary: group.isComplimentary,
		role,
	} ) );
	const individual = ( item.subscriptions || [] ).map( s => ( {
		plan: s.plan,
		status: s.status,
		editUrl: s.editUrl,
		isComplimentary: !! s.isComplimentary,
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
	// Opening a profile unmounts the list; the saved view brings the reader back
	// to the filters, search, sort and page they left.
	const [ view, setView ] = useState( () => getSavedListView() || DEFAULT_VIEW );
	useEffect( () => {
		saveListView( view );
	}, [ view ] );
	const history = useHistory();
	const location = useLocation();

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const { plans, failed: plansFailed } = usePlans();

	// The server owns filter/sort/paginate; this page's rows come back already
	// narrowed. Group-role, tag and newsletter filters arrive in later slices (the
	// endpoint honors status and plan here); sorting is name / member-since.
	const { items, total, pages, loading: subscribersLoading, settled, error, reload } = useSubscribers( view );

	// A restored page sits past the end if the list shrank while the reader was on
	// a profile; step back to the last page rather than show "No results". Only an
	// empty response counts: right after a view change the new read hasn't started,
	// so `loading` and `pages` both still describe the previous view, and DataViews
	// never offers a page past `pages`. Before paint, so the past-the-end page
	// itself never shows.
	useLayoutEffect( () => {
		const lastPage = Math.max( pages, 1 );
		if ( ! subscribersLoading && ! error && items.length === 0 && view.page > lastPage ) {
			setView( current => ( { ...current, page: lastPage } ) );
		}
	}, [ subscribersLoading, error, items.length, pages, view.page ] );

	// Filter options for the Subscription column: every plan on the site, not just
	// the ones on this page. Plans the view already filters by stay options even if
	// the plan list fails to load: DataViews drops a filter with no options, which
	// would leave a restored filter applied with no chip to clear it.
	const selectedPlans = view.filters?.find( f => 'plans' === f.field )?.value;
	const planElements = useMemo(
		() => [ ...new Set( [ ...plans, ...( selectedPlans || [] ) ] ) ].map( name => ( { value: name, label: name } ) ),
		[ plans, selectedPlans ]
	);

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
				// Options come from the plans endpoint rather than the loaded rows:
				// this list is server-paginated, so the plans on the current page are
				// not the plans on the site. Filtering is server-side too — see
				// viewToParams, which sends this field's value as the `plan` param.
				elements: planElements,
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => visiblePlanEntries( planEntries( item, groupEntriesOf( item ) ) ).map( e => e.plan ),
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
				id: 'access',
				label: __( 'Access', 'newspack-plugin' ),
				// Hidden by default. Filtered server-side, like the plan filter: the
				// `access` param matches readers whose Subscription column shows a
				// complimentary subscription.
				elements: [ { value: 'complimentary', label: __( 'Complimentary', 'newspack-plugin' ) } ],
				filterBy: { operators: [ 'is' ] },
				enableSorting: false,
				getValue: ( { item } ) =>
					visiblePlanEntries( planEntries( item, groupEntriesOf( item ) ) ).some( e => e.isComplimentary ) ? 'complimentary' : '',
				render: ( { item } ) =>
					visiblePlanEntries( planEntries( item, groupEntriesOf( item ) ) ).some( e => e.isComplimentary ) ? (
						<span>{ __( 'Complimentary', 'newspack-plugin' ) }</span>
					) : (
						<span>—</span>
					),
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
				// The reader's most recent page view, from the activity record the site
				// keeps for them — reading, not signing in, so a reader on a long-lived
				// auth cookie who visits daily is last seen today and logging out does
				// not erase it. Hidden by default; not server-sortable in this slice.
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
				// The labels stored on the reader here on the site. Hidden by default;
				// display-only until there is a way to filter on them server-side.
				enableSorting: false,
				// The em-dash empty state matches every other column: an empty cell
				// reads as a rendering fault rather than as "nothing to show".
				render: ( { item } ) => {
					const tags = item.tags || [];
					if ( tags.length === 0 ) {
						return <span>—</span>;
					}
					return (
						<HStack spacing={ 1 } justify="flex-start" wrap>
							{ tags.map( t => (
								<Badge key={ t } intent="none">
									{ t }
								</Badge>
							) ) }
						</HStack>
					);
				},
			},
			{
				id: 'newsletters',
				label: __( 'Newsletters', 'newspack-plugin' ),
				// The lists the site records this reader as subscribed to. Each arrives
				// as `{ id, title }`, with a null title for a list the site holds no
				// definition for — routinely the ESP's own IDs, which are never
				// mirrored locally. The unresolved wording is composed here rather
				// than server-side so the ID stays machine-readable for a future
				// filter, and so this string sits in the same bundle as the column
				// heading above it. Showing the bare ID is not an option: `abc123def`
				// in a publisher-facing column reads as a newsletter name.
				// Hidden by default; display-only until there is a server-side filter.
				enableSorting: false,
				render: ( { item } ) => {
					const newsletters = item.newsletters || [];
					if ( newsletters.length === 0 ) {
						return <span>—</span>;
					}
					return (
						<div>
							{ newsletters
								.map(
									list =>
										// Nullish, not falsy: unresolved is the null the endpoint
										// sends, not every title JavaScript reads as empty.
										list.title ??
										/* translators: %s: the email service provider's identifier for a list the site has no local record of. */
										sprintf( __( 'Unknown list (%s)', 'newspack-plugin' ), list.id )
								)
								.join( ', ' ) }
						</div>
					);
				},
			},
		],
		[ avatars, planElements ]
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
	// Before paint: this carries the width override, and an error arrives outside a
	// React event, so a passive effect would paint full-bleed for one frame first.
	useLayoutEffect( () => {
		setHeaderData( {
			// Header data is merged, so the explicit `undefined` is what clears a
			// previous `false`; a retry never changes the route, so nothing else would.
			fullWidth: error ? false : undefined,
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

	const { retryRef, retry } = useRetryFocus( { settled: ! subscribersLoading, failed: Boolean( error ), reload } );

	// Only the load that has nothing to show blanks the screen. Filtering and
	// sorting are server-side, so every filter toggle and every debounced keystroke
	// is a refetch — unmounting DataViews for those would take the search box's
	// focus with it and flash the applied filter chips away mid-interaction. Once a
	// response has settled, a refetch keeps DataViews mounted and shows its loading
	// state in place (see isRefreshing below), for the same reason avatars fill in
	// progressively rather than holding the table back.
	if ( subscribersLoading && ! settled ) {
		return (
			<div className="newspack-subscribers__loading">
				<Waiting isCenter />
			</div>
		);
	}

	// A failed read must not read as "this site has no subscribers": say so, and
	// offer a retry.
	if ( error ) {
		const message = sprintf(
			/* translators: %s: the error message returned by the server. */
			__( 'Could not load subscribers: %s', 'newspack-plugin' ),
			error
		);
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

	// A refetch with rows showing covers the screen with a spinner and blocks input
	// until it settles: DataViews' own one renders below the table, out of sight on
	// a long page. With no rows to cover, DataViews' spinner stands in for the
	// empty state, so it keeps that case. A failed read lands on the error notice
	// above, which never renders the overlay, so a failure can't leave it stuck.
	const isRefreshing = subscribersLoading && items.length > 0;

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events
		<div className="newspack-subscribers__clickable-rows" onClick={ onRowClick } aria-busy={ isRefreshing || undefined }>
			{ /* The two reads fail independently, so a failed /plans is otherwise
			     silent: DataViews drops a filter whose `elements` is empty, so the
			     Subscription filter is simply absent (unless the view already
			     filters by a plan) and reads as a site that sells no plans. The
			     table itself is unaffected, hence a warning beside it
			     rather than the error notice that replaces the screen. */ }
			{ plansFailed && (
				<LoadFailureNotice
					status="warning"
					message={ __(
						'Could not load the plan list, so the Subscription filter is unavailable. Reload the page to try again.',
						'newspack-plugin'
					) }
				/>
			) }
			<DataViews
				data={ items }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				isLoading={ subscribersLoading && ! isRefreshing }
				paginationInfo={ { totalItems: total, totalPages: pages } }
				defaultLayouts={ { table: {} } }
				getItemId={ item => item.id }
				onClickItem={ openSubscriber }
				search
			/>
			{ /* In the document body, so no ancestor of the list can pin the fixed
			     overlay to itself or stack popovers above it. */ }
			{ isRefreshing &&
				createPortal(
					<div className="newspack-subscribers__loading-overlay">
						<Waiting />
					</div>,
					document.body
				) }
		</div>
	);
}
