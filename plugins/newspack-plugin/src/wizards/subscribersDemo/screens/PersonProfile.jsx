/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise */
/**
 * L1 — Person profile.
 *
 * Two-column grid layout (SectionHeader in the left column, content in
 * the right) modelled on Access Control > Add new content gate.
 * Alerts and Current Status are pinned above Identity when issues
 * exist, so the hierarchy concern from Katie (multiple subs + broken
 * membership) is handled.
 */

/**
 * WordPress dependencies.
 */
import { createPortal, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import {
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	Dropdown,
	FormToggle,
	MenuGroup,
	MenuItem,
	Notice,
	Snackbar,
} from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Badge, Button, Card, DataViews, Divider, Grid, Router, SectionHeader, Waiting } from '../../../../packages/components/src';
import './style.scss';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import {
	getSubscriberById,
	getStoredNotes,
	getStoredTags,
	getStoredNewsletters,
	getStoredSubscriber,
	setStoredSubscriber,
	NEWSLETTERS,
} from '../data/mock-subscribers';
import {
	getGroupsForSubscriber,
	setStoredGroup,
	seatsUsed,
	getGroupLabel,
	getPlanOptions,
	GROUP_STATUS_LABELS,
	GROUP_STATUS_BADGE_LEVEL,
} from '../data/mock-groups';
import { GROUP_LABEL, GROUP_LABEL_PLURAL, GROUP_LABEL_LOWER } from '../labels';
import { STATUS_LABELS, STATUS_BADGE_LEVEL, STATUS_RANK, displayStatuses } from '../status';
import { fmtCurrency, fmtRelative, fmtDate } from '../format';
import { useNoticesPortal, useWizardNode } from '../use-portals';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { NoticesPanel, onHoldSelfNotice, onHoldOwnedGroupNotice, onHoldMemberNotice } from './SubscriberNotices';

import visaIcon from '../assets/cards/visa.svg';
import mastercardIcon from '../assets/cards/mastercard.svg';
import amexIcon from '../assets/cards/amex.svg';
import discoverIcon from '../assets/cards/discover.svg';
import jcbIcon from '../assets/cards/jcb.svg';

const CARD_ICONS = {
	Visa: visaIcon,
	Mastercard: mastercardIcon,
	Amex: amexIcon,
	Discover: discoverIcon,
	JCB: jcbIcon,
};

import { hasUsableCard, isCardExpired } from '../flows/free-access';
import { buildReactivation, buildFreeReactivation, buildPaymentLinkOrder, reactivatedMessage } from '../flows/subscription-actions';
import { latestOrderDate, lastPaidValue } from '../data/orders';
import RefundFlow from '../flows/RefundFlow';
import AddSubscriptionFlow from '../flows/AddSubscriptionFlow';
import PlanChangeFlow from '../flows/PlanChangeFlow';
import ChangePaymentMethodFlow from '../flows/ChangePaymentMethodFlow';
import PaymentUpdateFlow from '../flows/PaymentUpdateFlow';
import RemovePaymentFlow from '../flows/RemovePaymentFlow';
import GuidedFixFlow from '../flows/GuidedFixFlow';
import NoteFlow from '../flows/NoteFlow';
import TagsFlow from '../flows/TagsFlow';

const { useParams, useLocation, useHistory } = Router;

// "$12.00 / month" — the billing rate shown on a subscription/group card, or a
// dash when the amount is missing (e.g. imported data with no price).
const billingText = ( amount, cadence ) =>
	Number.isFinite( amount )
		? sprintf(
				__( '%1$s / %2$s', 'newspack-plugin' ),
				fmtCurrency( amount ),
				cadence === 'Monthly' ? __( 'month', 'newspack-plugin' ) : __( 'year', 'newspack-plugin' )
		  )
		: '—';

// Fallback dash for an empty value, matching the list columns (e.g. "Last seen —").
const orDash = value => value || '—';

// A labeled label/value row on a card, matching the quick-edit drawer style.
const CardRow = ( { label, children } ) => (
	<div className="newspack-subscribers-demo__card-row">
		<span className="newspack-subscribers-demo__card-label">{ label }</span>
		<span className="newspack-subscribers-demo__card-value">{ children }</span>
	</div>
);

// "Visa ending in 4242" — the bold card heading, and the accessible name for
// the per-card payment actions (Edit, Remove) when several methods are listed.
const cardLabel = pm => sprintf( __( '%1$s ending in %2$s', 'newspack-plugin' ), pm.type, pm.last4 );

// A "more" kebab matching the payment-method card: a moreVertical toggle that
// opens a MenuGroup of card actions. `renderItems( onClose )` returns the items.
const CardActionsMenu = ( { toggleLabel, renderItems } ) => (
	<Dropdown
		className="newspack-subscribers-demo__card-menu"
		placement="bottom-end"
		renderToggle={ ( { isOpen, onToggle } ) => (
			<Button
				className="newspack-subscribers-demo__card-menu-toggle"
				icon={ moreVertical }
				size="compact"
				onClick={ onToggle }
				aria-expanded={ isOpen }
				label={ toggleLabel }
				showTooltip={ false }
			/>
		) }
		renderContent={ ( { onClose } ) => <MenuGroup>{ renderItems( onClose ) }</MenuGroup> }
	/>
);

const demoConfig = ( typeof window !== 'undefined' && window.newspackSubscribersDemo ) || {};

// Newsletters beyond this count collapse behind a "View more" toggle. Defaults
// to 4; a publisher can override it with the (hidden) wp-config constant
// NEWSPACK_SUBSCRIBERS_DEMO_NEWSLETTERS_LIMIT, surfaced via the localized data.
const NEWSLETTERS_COLLAPSED_COUNT = demoConfig.newslettersLimit || 4;

// Only collapse when it hides at least this many — a toggle that reveals a
// single extra newsletter isn't worth the click, so e.g. 5 (limit 4) shows all.
const NEWSLETTERS_COLLAPSE_MIN_HIDDEN = 2;
const NEWSLETTERS_COLLAPSIBLE = NEWSLETTERS.length - NEWSLETTERS_COLLAPSED_COUNT >= NEWSLETTERS_COLLAPSE_MIN_HIDDEN;

// Cancelled subscriptions are hidden behind a "View more" toggle: all of them
// when the reader still has a live (active/on-hold) plan, otherwise everything
// past this count. Defaults to 1; override via the wp-config constant.
const SUBSCRIPTIONS_CANCELLED_LIMIT = demoConfig.cancelledLimit || 1;
// Only collapse when it hides at least this many — matching the newsletters rule.
const SUBSCRIPTIONS_COLLAPSE_MIN_HIDDEN = 2;

function getStatusSummary( subscriber, isCoveredMember, hasActiveGroupSub ) {
	// On-hold/cancelled status is surfaced as a prominent notice (see
	// statusNotices), and plan/next-billing details live in the Subscriptions
	// section, so the header only summarizes active subscribers without a plan.
	if ( subscriber.status !== 'active' ) {
		return [];
	}
	const activeSubs = subscriber.subscriptions.filter( s => s.status === 'active' );
	if ( activeSubs.length > 0 ) {
		return [];
	}
	// An active group subscription (as owner) is a plan, and a member gets access
	// through a group they belong to, so neither is flagged as having no plan.
	if ( hasActiveGroupSub || isCoveredMember ) {
		return [];
	}
	return [ __( 'Active subscriber with no current subscription on file', 'newspack-plugin' ) ];
}

/**
 * Row of a two-column section: header on the left, children on the right.
 */
function Row( { title, description, children, showDivider = true } ) {
	return (
		<>
			<Grid columns={ 2 } gutter={ 32 }>
				<SectionHeader title={ title } description={ description } heading={ 2 } noMargin />
				<div>{ children }</div>
			</Grid>
			{ showDivider && <Divider alignment="full-width" variant="tertiary" /> }
		</>
	);
}

function PersonProfileView() {
	const { id } = useParams();
	const location = useLocation();
	const history = useHistory();
	// Return to wherever we came from (HashRouter drops location.state, so the
	// origin travels as a `from` query param), else the subscriber list.
	const backNav = new URLSearchParams( location.search ).get( 'from' ) || '#/';
	// Navigate to another profile while preserving the current origin, so the
	// breadcrumb section and back-nav stay consistent across owner/member hops.
	const openProfile = targetId => history.push( `/profile/${ targetId }?from=${ encodeURIComponent( backNav ) }` );
	const [ subscriber, setSubscriber ] = useState( () => {
		// A prior session's full-subscriber override wins, so mutations survive reload.
		const stored = getStoredSubscriber( id );
		if ( stored ) {
			return stored;
		}
		const found = getSubscriberById( id );
		if ( ! found ) {
			return found;
		}
		const storedTags = getStoredTags( id );
		const storedNewsletters = getStoredNewsletters( id );
		return {
			...found,
			notes: getStoredNotes( id ),
			tags: storedTags !== null ? storedTags : found.tags || [],
			newsletters: storedNewsletters !== null ? storedNewsletters : found.newsletters || [],
		};
	} );

	// Persist every mutation (notes, tags, newsletters, subscriptions, payment
	// methods, status) so it survives a reload or navigating away and back.
	useEffect( () => {
		if ( subscriber ) {
			setStoredSubscriber( subscriber.id, subscriber );
		}
	}, [ subscriber ] );
	const [ snackbar, setSnackbar ] = useState( null );
	const [ modal, setModal ] = useState( null );
	const [ groupsRefresh, setGroupsRefresh ] = useState( 0 );
	const [ ordersView, setOrdersView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 10,
		sort: { field: 'date', direction: 'desc' },
		search: '',
		fields: [ 'date', 'type', 'amount' ],
		filters: [],
		layout: {},
		titleField: 'subscription',
	} );
	const noticesNode = useNoticesPortal();
	// The wizard renders the section header from store data; portal the avatar into
	// it once it appears (watched, not raced on a single frame).
	const headerNode = useWizardNode( '.newspack-wizard__section-header', 'newspack-subscribers-demo__has-avatar', SHOW_AVATARS );
	const [ newslettersExpanded, setNewslettersExpanded ] = useState( false );
	const newslettersListRef = useRef();
	// Set when the user expands, so focus moves to the first newly revealed
	// toggle (which renders above the trigger) instead of being stranded on it.
	const focusRevealedRef = useRef( false );
	const [ subscriptionsExpanded, setSubscriptionsExpanded ] = useState( false );
	const subscriptionsListRef = useRef();
	// Index of the first hidden subscription card; the focus effect targets it.
	const subsRevealBoundaryRef = useRef( 0 );
	const subsFocusRevealedRef = useRef( false );

	// Resolve this subscriber's avatar via the same endpoint the list uses. A
	// 128px source feeds the 64px profile avatar (2x for high-DPR displays).
	const { avatars: profileAvatars, loading: avatarLoading } = useAvatars( subscriber?.email ? [ subscriber.email ] : [], { size: 128 } );
	const avatarUrl = ( subscriber?.email && profileAvatars[ subscriber.email ] ) || '';

	// On expand, move focus to the first newly revealed newsletter toggle.
	useEffect( () => {
		if ( ! focusRevealedRef.current ) {
			return;
		}
		focusRevealedRef.current = false;
		const toggles = newslettersListRef.current?.querySelectorAll( 'input[type="checkbox"]' );
		toggles?.[ NEWSLETTERS_COLLAPSED_COUNT ]?.focus();
	}, [ newslettersExpanded ] );

	// On expand, move focus to the first newly revealed (cancelled) subscription's
	// action button so keyboard users land on the freshly shown content.
	useEffect( () => {
		if ( ! subsFocusRevealedRef.current ) {
			return;
		}
		subsFocusRevealedRef.current = false;
		const cards = subscriptionsListRef.current?.querySelectorAll( '.components-card' );
		cards?.[ subsRevealBoundaryRef.current ]?.querySelector( 'button' )?.focus();
	}, [ subscriptionsExpanded ] );

	const groupMemberships = useMemo( () => getGroupsForSubscriber( id ), [ id, groupsRefresh ] );

	// A subscriber whose access comes from a group they belong to (not own) and
	// who has no individual plan is "covered" by the owner's subscription, so the
	// header shouldn't flag them as having no plan (per PR #148).
	const isCoveredMember = useMemo( () => {
		const memberEntry = groupMemberships.find( entry => ! entry.isOwner );
		return !! memberEntry && ( subscriber?.subscriptions || [] ).length === 0;
	}, [ groupMemberships, subscriber ] );

	// Whether this subscriber owns an active group, which is a plan in its own right.
	const hasActiveOwnedGroup = useMemo(
		() => groupMemberships.some( entry => entry.isOwner && entry.group.status === 'active' ),
		[ groupMemberships ]
	);

	// Subscription instances an order can be billed against: the reader's
	// individual subscriptions plus any group they own. Keyed by id so an order's
	// subscriptionId resolves to a plan (and start date) for the history table.
	const subInstances = useMemo( () => {
		const map = {};
		( subscriber.subscriptions || [] ).forEach( s => {
			map[ s.id ] = { plan: s.plan, startDate: s.startDate };
		} );
		groupMemberships.forEach( ( { group, isOwner } ) => {
			if ( isOwner ) {
				map[ group.id ] = { plan: group.plan, startDate: group.createdAt };
			}
		} );
		return map;
	}, [ subscriber, groupMemberships ] );

	// Plans the reader holds more than once (e.g. resubscribed on the same plan),
	// so the order history can fold in the start date to disambiguate instances.
	const duplicatePlans = useMemo( () => {
		const counts = {};
		Object.values( subInstances ).forEach( inst => {
			counts[ inst.plan ] = ( counts[ inst.plan ] || 0 ) + 1;
		} );
		return new Set( Object.keys( counts ).filter( plan => counts[ plan ] > 1 ) );
	}, [ subInstances ] );

	const orderRows = useMemo( () => {
		const rows = ( subscriber.orders || [] ).map( order => {
			const inst = subInstances[ order.subscriptionId ];
			const plan = inst?.plan || '';
			const subscription =
				inst && duplicatePlans.has( inst.plan )
					? sprintf(
							// translators: 1: plan name, 2: subscription start date.
							__( '%1$s · since %2$s', 'newspack-plugin' ),
							inst.plan,
							fmtDate( inst.startDate )
					  )
					: plan;
			return { id: order.id, date: order.date, subscription, type: order.type, amount: order.amount };
		} );
		// A membership (not ownership) carries no payments of its own, so surface the
		// join as a non-financial event marking when the reader's access began.
		groupMemberships.forEach( ( { group, isOwner } ) => {
			if ( isOwner ) {
				return;
			}
			const membership = ( group.members || [] ).find( m => m.subscriberId === subscriber.id );
			rows.push( {
				id: `join_${ group.id }`,
				date: membership?.joinedAt || group.createdAt,
				subscription: group.plan,
				type: sprintf( __( 'Joined %s', 'newspack-plugin' ), GROUP_LABEL_LOWER ),
				amount: null,
			} );
		} );
		return rows;
	}, [ subscriber, subInstances, duplicatePlans, groupMemberships ] );

	const orderFields = useMemo(
		() => [
			{
				id: 'subscription',
				label: __( 'Subscription', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.subscription,
				render: ( { item } ) => <span>{ item.subscription || '—' }</span>,
				enableSorting: false,
			},
			{
				id: 'date',
				label: __( 'Date', 'newspack-plugin' ),
				getValue: ( { item } ) => item.date,
				render: ( { item } ) => <span>{ fmtDate( item.date ) }</span>,
				enableSorting: true,
			},
			{
				id: 'type',
				label: __( 'Type', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.type,
				render: ( { item } ) => <span>{ item.type }</span>,
				enableSorting: false,
			},
			{
				id: 'amount',
				label: __( 'Amount', 'newspack-plugin' ),
				getValue: ( { item } ) => item.amount,
				render: ( { item } ) => <span>{ item.amount === null ? '—' : fmtCurrency( item.amount ) }</span>,
				enableSorting: true,
			},
		],
		[]
	);

	const { data: processedOrders, paginationInfo: ordersPagination } = useMemo(
		() => filterSortAndPaginate( orderRows, ordersView, orderFields ),
		[ orderRows, ordersView, orderFields ]
	);

	// Headline status shown by the name: every distinct status across all of the
	// subscriber's subscriptions, active-first, so an active group and an on-hold
	// individual plan both surface (Active · On hold) rather than the on-hold one
	// being masked. Cancelled is hidden while any live plan remains. Per-
	// subscription status still shows on each card.
	const headlineStatuses = useMemo(
		() =>
			displayStatuses(
				[ ...groupMemberships.map( entry => entry.group.status ), ...( subscriber?.subscriptions || [] ).map( s => s.status ) ],
				subscriber?.status
			),
		[ groupMemberships, subscriber ]
	);

	// Prominent notices: a status notice when a subscription or group the reader
	// owns or belongs to is on hold. A failed renewal and the resulting on-hold
	// state read as one actionable notice, not two. A cancellation is intentional
	// and reversible from the subscription card, so it gets no prominent notice —
	// only an at-risk renewal warrants one. Copy lives in SubscriberNotices; each
	// entry carries a `cta` descriptor the render maps to an onClick (action
	// behavior stays local to the screen).
	const statusNotices = useMemo( () => {
		const list = [];
		if ( subscriber?.status === 'on-hold' ) {
			const onHoldSub = ( subscriber?.subscriptions || [] ).find( s => s.status === 'on-hold' );
			list.push( {
				...onHoldSelfNotice( { plan: onHoldSub?.plan, lastCharged: subscriber.lastPayment ? fmtDate( subscriber.lastPayment ) : '' } ),
				cta: onHoldSub ? { kind: 'reactivate', reactivate: { sub: onHoldSub } } : null,
			} );
		}
		// A group the reader owns can be on hold without their individual status
		// being on hold; the owner pays, so this is actionable like an individual
		// failed renewal.
		const ownedOnHold = groupMemberships.find( entry => entry.isOwner && entry.group.status === 'on-hold' );
		if ( ownedOnHold ) {
			list.push( {
				...onHoldOwnedGroupNotice( {
					plan: ownedOnHold.group.plan,
					lastCharged: fmtDate( latestOrderDate( subscriber.orders, ownedOnHold.group.id, 'Subscription payment' ) ),
				} ),
				cta: { kind: 'reactivate', reactivate: { group: ownedOnHold.group } },
			} );
		}
		// A member of an on-hold group has their access at risk, but only the owner
		// can resolve the payment, so point to the owner rather than offer a fix.
		const memberOnHold = groupMemberships.find( entry => ! entry.isOwner && entry.group.status === 'on-hold' );
		if ( memberOnHold ) {
			const groupOwner = getSubscriberById( memberOnHold.group.ownerId );
			list.push( {
				...onHoldMemberNotice( { plan: memberOnHold.group.plan, ownerName: groupOwner?.name } ),
				cta: { kind: 'viewOwner', ownerId: memberOnHold.group.ownerId },
			} );
		}
		return list;
	}, [ subscriber, groupMemberships ] );

	const removeFromGroup = group => {
		setStoredGroup( group.id, { ...group, members: ( group.members || [] ).filter( m => m.subscriberId !== id ) } );
		setSnackbar( { message: sprintf( __( 'Removed %1$s from %2$s.', 'newspack-plugin' ), subscriber.name, getGroupLabel( group ) ) } );
		setGroupsRefresh( n => n + 1 );
	};

	// Resume an on-hold group: back to active with a fresh billing date. Mirrors
	// reactivateSubscription so a group behaves like any other subscription.
	const reactivateGroup = ( group, charged, notify = false ) => {
		const { fields, order } = buildReactivation( group, charged );
		setStoredGroup( group.id, { ...group, ...fields } );
		setGroupsRefresh( n => n + 1 );
		// The owner pays for the group, so the order belongs on the owner's history.
		setSubscriber( prev => ( { ...prev, orders: [ order, ...( prev.orders || [] ) ] } ) );
		// A cancelled group is resubscribed, an on-hold one reactivated.
		setSnackbar( { message: reactivatedMessage( group.plan, notify, group.status === 'cancelled' ? 'resubscribe' : 'reactivate' ) } );
	};

	// Resume an on-hold personal subscription: back to active with a fresh billing
	// date, and the subscriber is active again since they have a live plan.
	const reactivateSubscription = ( sub, charged, notify = false ) => {
		const { fields, order } = buildReactivation( sub, charged );
		setSubscriber( prev => ( {
			...prev,
			status: 'active',
			// The renewal succeeded, so clear the payment alert rather than leave it
			// stranded on a now-active subscriber.
			alerts: ( prev.alerts || [] ).filter( a => a.id !== 'alert_pay' ),
			subscriptions: prev.subscriptions.map( s => ( s.id === sub.id ? { ...s, ...fields } : s ) ),
			orders: [ order, ...( prev.orders || [] ) ],
		} ) );
		setSnackbar( { message: reactivatedMessage( sub.plan, notify ) } );
	};

	// Reactivate an on-hold subscription or owned group for free, with a chosen
	// duration: indefinitely (a comp with no future billing) or free for a number
	// of cycles, then it bills at the catalog price. No payment is taken now.
	const reactivateFree = ( target, isGroup, freeCyclesRemaining, notify ) => {
		const { fields, order } = buildFreeReactivation( target, freeCyclesRemaining );
		if ( isGroup ) {
			setStoredGroup( target.id, { ...target, ...fields } );
			setGroupsRefresh( n => n + 1 );
			setSubscriber( prev => ( { ...prev, orders: [ order, ...( prev.orders || [] ) ] } ) );
		} else {
			setSubscriber( prev => ( {
				...prev,
				status: 'active',
				alerts: ( prev.alerts || [] ).filter( a => a.id !== 'alert_pay' ),
				subscriptions: prev.subscriptions.map( s => ( s.id === target.id ? { ...s, ...fields } : s ) ),
				orders: [ order, ...( prev.orders || [] ) ],
			} ) );
		}
		setSnackbar( { message: reactivatedMessage( target.plan, notify, target.status === 'cancelled' ? 'resubscribe' : 'reactivate' ) } );
	};

	// After a card is entered on behalf of a reader who had none, complete the
	// charge-now add: create the subscription and log the payment.
	const completePaidAddWithCard = ( draft, notify ) => {
		const order = {
			id: 'ord_' + draft.id + '_charge',
			date: new Date().toISOString().slice( 0, 10 ),
			amount: draft.amount,
			type: __( 'Subscription payment', 'newspack-plugin' ),
			subscriptionId: draft.id,
		};
		setSubscriber( prev => ( {
			...prev,
			status: 'active',
			subscriptions: [ ...( prev.subscriptions || [] ), draft ],
			orders: [ order, ...( prev.orders || [] ) ],
		} ) );
		const base = sprintf( __( 'Added %1$s for %2$s.', 'newspack-plugin' ), draft.plan, subscriber.name );
		setSnackbar( { message: notify ? sprintf( __( '%s A confirmation email was sent.', 'newspack-plugin' ), base ) : base } );
	};

	const markPendingPaid = sub => {
		const date = new Date().toISOString().slice( 0, 10 );
		const nextBillingDate = new Date( Date.now() + 30 * 86400000 ).toISOString().slice( 0, 10 );
		const order = {
			id: `ord_${ sub.id }_paid`,
			date,
			amount: sub.amount,
			type: __( 'Subscription payment', 'newspack-plugin' ),
			subscriptionId: sub.id,
		};
		setSubscriber( prev => ( {
			...prev,
			status: 'active',
			subscriptions: prev.subscriptions.map( s => ( s.id === sub.id ? { ...s, status: 'active', nextBillingDate } : s ) ),
			orders: [ order, ...( prev.orders || [] ) ],
		} ) );
		setSnackbar( { message: sprintf( __( '%s activated.', 'newspack-plugin' ), sub.plan ) } );
	};

	const resendPendingLink = sub => {
		setSubscriber( prev => ( {
			...prev,
			subscriptions: prev.subscriptions.map( s => ( s.id === sub.id ? { ...s, linkSentAt: new Date().toISOString().slice( 0, 10 ) } : s ) ),
		} ) );
		setSnackbar( { message: sprintf( __( 'Payment link resent to %s.', 'newspack-plugin' ), subscriber.email ) } );
	};

	const cancelPending = sub => {
		setSubscriber( prev => ( { ...prev, subscriptions: prev.subscriptions.filter( s => s.id !== sub.id ) } ) );
		setSnackbar( { message: __( 'Pending subscription cancelled.', 'newspack-plugin' ) } );
	};

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	useEffect( () => {
		if ( ! subscriber ) {
			return;
		}
		// Breadcrumb reflects where the profile was opened from: a person reached
		// from a cohort sits under Cohorts, otherwise under Subscribers.
		const breadcrumbSection = backNav.startsWith( '#/group' ) ? GROUP_LABEL_PLURAL : __( 'Subscribers', 'newspack-plugin' );
		setHeaderData( {
			backNav,
			sectionName: `${ breadcrumbSection } / ${ subscriber.name }`,
			sectionTitle: subscriber.name,
			badges: headlineStatuses.map( status => ( { label: STATUS_LABELS[ status ], level: STATUS_BADGE_LEVEL[ status ] } ) ),
			sectionDescription: (
				<VStack spacing={ 1 }>
					<span>{ subscriber.email }</span>
					<span aria-label={ subscriber.lastSeen ? undefined : __( 'Last seen: never', 'newspack-plugin' ) }>
						{ subscriber.lastSeen
							? sprintf(
									// translators: %s is a date.
									__( 'Last seen on %s', 'newspack-plugin' ),
									fmtDate( subscriber.lastSeen )
							  )
							: __( 'Last seen —', 'newspack-plugin' ) }
					</span>
					{ getStatusSummary( subscriber, isCoveredMember, hasActiveOwnedGroup ).map( ( line, i ) => (
						<span key={ i }>{ line }</span>
					) ) }
					{ ( subscriber.tags || [] ).length > 0 && (
						<HStack spacing={ 1 } justify="flex-start" wrap>
							{ subscriber.tags.map( t => (
								<Badge key={ t } text={ t } />
							) ) }
						</HStack>
					) }
				</VStack>
			),
			actions: [
				{ type: 'more', label: __( 'View in WooCommerce', 'newspack-plugin' ), action: () => {} },
				{ type: 'more', label: __( 'Edit WordPress user', 'newspack-plugin' ), action: () => {} },
				{ type: 'more', label: __( 'Manage tags', 'newspack-plugin' ), action: () => setModal( { kind: 'tags' } ) },
				{ type: 'more', label: __( 'Add private note', 'newspack-plugin' ), action: () => setModal( { kind: 'note' } ) },
				{ type: 'more', label: __( 'View raw subscription data', 'newspack-plugin' ), action: () => {} },
			],
		} );
	}, [ subscriber, isCoveredMember, hasActiveOwnedGroup, headlineStatuses, backNav, setHeaderData ] );

	if ( ! subscriber ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Subscriber not found.', 'newspack-plugin' ) }
			</Notice>
		);
	}

	const closeModal = () => setModal( null );
	// Every flow ends in a brief confirmation, so always surface it as a snackbar.
	// Persistent on-hold status stays a notice.
	const completeFlow = ( { message, mutate, groupCancel, groupChange, groupCreate } ) => {
		if ( mutate ) {
			setSubscriber( prev => mutate( prev ) );
		}
		// Group flows mutate the shared group record, not the subscriber, so the
		// change persists for everyone viewing the group.
		if ( groupCancel ) {
			setStoredGroup( groupCancel.id, { ...groupCancel, status: 'cancelled', nextBillingDate: null } );
			setGroupsRefresh( n => n + 1 );
		}
		if ( groupChange ) {
			setStoredGroup( groupChange.id, groupChange );
			setGroupsRefresh( n => n + 1 );
		}
		if ( groupCreate ) {
			setStoredGroup( groupCreate.id, groupCreate );
			setGroupsRefresh( n => n + 1 );
		}
		setSnackbar( { message } );
		setModal( null );
	};

	const subscriptionsEmptyState = (
		<Card __experimentalCoreCard>
			<p>{ __( 'No subscriptions on file.', 'newspack-plugin' ) }</p>
			<Button variant="primary" size="compact" onClick={ () => setModal( { kind: 'resubscribe' } ) }>
				{ __( 'Resubscribe', 'newspack-plugin' ) }
			</Button>
		</Card>
	);

	// "Change payment method" is only offered when there's another card to switch to.
	const hasMultipleCards = subscriber.paymentMethods.length > 1;

	// A group subscription rendered inside the Subscriptions section. For an owner
	// it's the plan that grants the group, with seat usage and a manage action;
	// for a member it's their access through the owner, with a link to the owner
	// and a remove action. The "Group" badge is colored to match owner/member.
	const renderGroupSubscription = ( group, isOwner ) => {
		const groupOwner = getSubscriberById( group.ownerId );
		const isActiveGroup = group.status === 'active';
		// A free-forever (zero-amount) group was never charged, so there is nothing
		// to refund — the action is a plain cancel.
		const isFreeForeverGroup = group.amount === 0;
		return (
			<Card
				key={ group.id }
				__experimentalCoreCard
				__experimentalCoreProps={ {
					header: (
						<HStack justify="space-between" alignment="center">
							<VStack spacing={ 1 } expanded={ false }>
								<HStack spacing={ 2 } justify="flex-start" expanded={ false }>
									<h4>
										<Button
											variant="link"
											className="newspack-subscribers-demo__card-title-link"
											onClick={ () => history.push( `/group/${ group.id }` ) }
											aria-label={ sprintf( __( 'View %1$s: %2$s', 'newspack-plugin' ), GROUP_LABEL_LOWER, group.plan ) }
										>
											{ group.plan }
										</Button>
									</h4>
									<Badge level={ isOwner ? 'info' : 'default' } text={ GROUP_LABEL } />
									<Badge level={ GROUP_STATUS_BADGE_LEVEL[ group.status ] } text={ GROUP_STATUS_LABELS[ group.status ] } />
								</HStack>
								<span className="newspack-subscribers-demo__muted">
									{ sprintf(
										// translators: 1: seats used, 2: seat limit.
										__( '%1$d of %2$d seats', 'newspack-plugin' ),
										seatsUsed( group ),
										group.seatLimit
									) }
								</span>
							</VStack>
							<CardActionsMenu
								toggleLabel={ sprintf( __( 'Subscription actions: %s', 'newspack-plugin' ), group.plan ) }
								renderItems={ onClose => (
									<>
										<MenuItem
											aria-label={ sprintf( __( 'Manage %1$s: %2$s', 'newspack-plugin' ), GROUP_LABEL_LOWER, group.plan ) }
											onClick={ () => {
												onClose();
												history.push( `/group/${ group.id }` );
											} }
										>
											{ sprintf( __( 'Manage %s', 'newspack-plugin' ), GROUP_LABEL_LOWER ) }
										</MenuItem>
										{ isOwner && isActiveGroup && getPlanOptions( group, true ).length > 0 && (
											<MenuItem
												aria-label={ sprintf( __( 'Change subscription: %s', 'newspack-plugin' ), group.plan ) }
												onClick={ () => {
													onClose();
													setModal( { kind: 'plan', group } );
												} }
											>
												{ __( 'Change subscription', 'newspack-plugin' ) }
											</MenuItem>
										) }
										{ isOwner && isActiveGroup && (
											<MenuItem
												isDestructive
												aria-label={
													isFreeForeverGroup
														? sprintf( __( 'Cancel %1$s: %2$s', 'newspack-plugin' ), GROUP_LABEL_LOWER, group.plan )
														: sprintf( __( 'Refund or cancel: %s', 'newspack-plugin' ), group.plan )
												}
												onClick={ () => {
													onClose();
													setModal( { kind: 'refund', group } );
												} }
											>
												{ isFreeForeverGroup
													? __( 'Cancel', 'newspack-plugin' )
													: __( 'Refund or cancel', 'newspack-plugin' ) }
											</MenuItem>
										) }
										{ isOwner && group.status === 'on-hold' && (
											<>
												<MenuItem
													aria-label={ sprintf(
														__( 'Reactivate %1$s: %2$s', 'newspack-plugin' ),
														GROUP_LABEL_LOWER,
														group.plan
													) }
													onClick={ () => {
														onClose();
														setModal( {
															kind: 'guided',
															alert: { modalTitle: __( 'Reactivate subscription', 'newspack-plugin' ) },
															reactivate: { group },
														} );
													} }
												>
													{ __( 'Reactivate', 'newspack-plugin' ) }
												</MenuItem>
												<MenuItem
													isDestructive
													aria-label={ sprintf(
														__( 'Cancel %1$s: %2$s', 'newspack-plugin' ),
														GROUP_LABEL_LOWER,
														group.plan
													) }
													onClick={ () => {
														onClose();
														setModal( { kind: 'refund', group } );
													} }
												>
													{ __( 'Cancel', 'newspack-plugin' ) }
												</MenuItem>
											</>
										) }
										{ isOwner && group.status === 'cancelled' && (
											<MenuItem
												aria-label={ sprintf(
													__( 'Resubscribe %1$s: %2$s', 'newspack-plugin' ),
													GROUP_LABEL_LOWER,
													group.plan
												) }
												onClick={ () => {
													onClose();
													setModal( {
														kind: 'guided',
														alert: { modalTitle: __( 'Resubscribe', 'newspack-plugin' ) },
														reactivate: { group },
													} );
												} }
											>
												{ __( 'Resubscribe', 'newspack-plugin' ) }
											</MenuItem>
										) }
										{ ! isOwner && (
											<MenuItem
												isDestructive
												aria-label={ sprintf(
													__( 'Remove from %1$s: %2$s', 'newspack-plugin' ),
													GROUP_LABEL_LOWER,
													group.plan
												) }
												onClick={ () => {
													onClose();
													removeFromGroup( group );
												} }
											>
												{ sprintf( __( 'Remove from %s', 'newspack-plugin' ), GROUP_LABEL_LOWER ) }
											</MenuItem>
										) }
									</>
								) }
							/>
						</HStack>
					),
				} }
			>
				<Grid columns={ 2 } gutter={ 16 } noMargin>
					{ isOwner ? (
						<>
							<CardRow label={ __( 'First subscribed', 'newspack-plugin' ) }>{ orDash( fmtDate( group.createdAt ) ) }</CardRow>
							<CardRow label={ __( 'Billing', 'newspack-plugin' ) }>{ billingText( group.amount, group.cadence ) }</CardRow>
							<CardRow label={ __( 'Last payment', 'newspack-plugin' ) }>{ lastPaidValue( groupOwner?.orders, group.id ) }</CardRow>
							{ group.status === 'cancelled' ? (
								<CardRow label={ __( 'Cancelled', 'newspack-plugin' ) }>
									{ orDash( fmtDate( latestOrderDate( groupOwner?.orders, group.id, 'Cancellation' ) ) ) }
								</CardRow>
							) : (
								<CardRow label={ __( 'Next billing', 'newspack-plugin' ) }>{ orDash( fmtDate( group.nextBillingDate ) ) }</CardRow>
							) }
						</>
					) : (
						<>
							<CardRow label={ __( 'Joined', 'newspack-plugin' ) }>
								{ orDash( fmtDate( group.members?.find( m => m.subscriberId === subscriber.id )?.joinedAt ) ) }
							</CardRow>
							<CardRow label={ __( 'Owner', 'newspack-plugin' ) }>
								<Button variant="link" onClick={ () => openProfile( group.ownerId ) }>
									{ groupOwner?.name || __( 'Unknown', 'newspack-plugin' ) }
								</Button>
							</CardRow>
						</>
					) }
				</Grid>
			</Card>
		);
	};

	return (
		<div className="newspack-subscribers-demo__profile">
			{ SHOW_AVATARS &&
				headerNode &&
				( avatarLoading || avatarUrl ) &&
				createPortal(
					avatarUrl ? (
						<img className="newspack-subscribers-demo__profile-avatar" src={ avatarUrl } alt="" width={ 64 } height={ 64 } />
					) : (
						<div className="newspack-subscribers-demo__profile-avatar newspack-subscribers-demo__profile-avatar--loading">
							<Waiting />
						</div>
					),
					headerNode
				) }

			<NoticesPanel
				noticesNode={ noticesNode }
				notices={ statusNotices.map( notice => {
					let onClick;
					if ( notice.cta?.kind === 'reactivate' ) {
						onClick = () =>
							setModal( {
								kind: 'guided',
								alert: { modalTitle: __( 'Reactivate subscription', 'newspack-plugin' ) },
								reactivate: notice.cta.reactivate,
							} );
					} else if ( notice.cta?.kind === 'viewOwner' ) {
						onClick = () => openProfile( notice.cta.ownerId );
					}
					return { ...notice, action: onClick ? { label: notice.actionLabel, onClick } : null };
				} ) }
			/>

			{ ( subscriber.notes || [] ).length > 0 && (
				<VStack spacing={ 2 }>
					{ subscriber.notes.map( note => (
						<Card key={ note.id } __experimentalCoreCard className="newspack-subscribers-demo__note-card">
							<VStack spacing={ 4 }>
								<div>{ note.text }</div>
								<HStack spacing={ 2 } justify="flex-start">
									<Button
										variant="tertiary"
										size="compact"
										aria-label={ __( 'Edit note', 'newspack-plugin' ) }
										onClick={ () => setModal( { kind: 'note', note } ) }
									>
										{ __( 'Edit', 'newspack-plugin' ) }
									</Button>
									<Button
										variant="tertiary"
										size="compact"
										isDestructive
										aria-label={ __( 'Delete note', 'newspack-plugin' ) }
										onClick={ () => {
											setSubscriber( prev => ( {
												...prev,
												notes: ( prev.notes || [] ).filter( n => n.id !== note.id ),
											} ) );
											setSnackbar( { message: __( 'Private note deleted.', 'newspack-plugin' ) } );
										} }
									>
										{ __( 'Delete', 'newspack-plugin' ) }
									</Button>
								</HStack>
							</VStack>
						</Card>
					) ) }
				</VStack>
			) }

			<Row title={ __( 'Newsletters', 'newspack-plugin' ) }>
				<VStack spacing={ 4 }>
					<Card __experimentalCoreCard>
						<VStack spacing={ 4 } ref={ newslettersListRef }>
							{ ( NEWSLETTERS_COLLAPSIBLE && ! newslettersExpanded
								? NEWSLETTERS.slice( 0, NEWSLETTERS_COLLAPSED_COUNT )
								: NEWSLETTERS
							).map( newsletter => {
								const isSubscribed = ( subscriber.newsletters || [] ).includes( newsletter.id );
								return (
									<HStack key={ newsletter.id } justify="space-between" alignment="center">
										<VStack spacing={ 1 }>
											<strong>{ newsletter.name }</strong>
											<span
												id={ `newspack-subscribers-demo__newsletter-desc--${ newsletter.id }` }
												className="newspack-subscribers-demo__muted"
											>
												{ newsletter.description }
											</span>
										</VStack>
										<FormToggle
											checked={ isSubscribed }
											aria-label={ sprintf( __( '%s newsletter subscription', 'newspack-plugin' ), newsletter.name ) }
											aria-describedby={ `newspack-subscribers-demo__newsletter-desc--${ newsletter.id }` }
											onChange={ () => {
												const nextList = isSubscribed
													? ( subscriber.newsletters || [] ).filter( i => i !== newsletter.id )
													: [ ...( subscriber.newsletters || [] ), newsletter.id ];
												setSubscriber( prev => ( { ...prev, newsletters: nextList } ) );
												setSnackbar( {
													message: isSubscribed
														? sprintf( __( 'Unsubscribed from %s.', 'newspack-plugin' ), newsletter.name )
														: sprintf( __( 'Subscribed to %s.', 'newspack-plugin' ), newsletter.name ),
												} );
											} }
										/>
									</HStack>
								);
							} ) }
							{ NEWSLETTERS_COLLAPSIBLE && (
								<HStack justify="flex-start">
									<Button
										variant="link"
										onClick={ () => {
											if ( ! newslettersExpanded ) {
												focusRevealedRef.current = true;
											}
											setNewslettersExpanded( value => ! value );
										} }
										aria-label={
											newslettersExpanded
												? __( 'View less newsletters', 'newspack-plugin' )
												: sprintf(
														// translators: %d is the number of additional newsletters.
														_n(
															'View %d more newsletter',
															'View %d more newsletters',
															NEWSLETTERS.length - NEWSLETTERS_COLLAPSED_COUNT,
															'newspack-plugin'
														),
														NEWSLETTERS.length - NEWSLETTERS_COLLAPSED_COUNT
												  )
										}
									>
										{ newslettersExpanded
											? __( 'View less', 'newspack-plugin' )
											: sprintf(
													// translators: %d is the number of additional newsletters.
													__( 'View %d more', 'newspack-plugin' ),
													NEWSLETTERS.length - NEWSLETTERS_COLLAPSED_COUNT
											  ) }
									</Button>
								</HStack>
							) }
						</VStack>
					</Card>
					<HStack justify="flex-start">
						<Button
							variant="secondary"
							size="compact"
							disabled={ ( subscriber.newsletters || [] ).length === 0 }
							onClick={ () => {
								setSubscriber( prev => ( { ...prev, newsletters: [] } ) );
								setSnackbar( { message: __( 'Unsubscribed from all newsletters.', 'newspack-plugin' ) } );
							} }
						>
							{ __( 'Unsubscribe from all newsletters', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Row>

			<Row title={ __( 'Subscriptions', 'newspack-plugin' ) }>
				<VStack spacing={ 4 } ref={ subscriptionsListRef }>
					{ subscriber.subscriptions.length === 0 && groupMemberships.length === 0 && subscriptionsEmptyState }
					{ ( () => {
						const items = [
							...groupMemberships.map( ( { group, isOwner } ) => ( {
								status: group.status,
								date: group.createdAt,
								node: renderGroupSubscription( group, isOwner ),
							} ) ),
							...subscriber.subscriptions.map( sub => {
								if ( sub.status === 'pending' ) {
									return {
										status: sub.status,
										date: sub.linkSentAt || sub.startDate,
										node: (
											<Card
												key={ sub.id }
												__experimentalCoreCard
												__experimentalCoreProps={ {
													header: (
														<HStack justify="space-between" alignment="center">
															<HStack spacing={ 2 } justify="flex-start" expanded={ false }>
																<h4>{ sub.plan }</h4>
																<Badge level={ STATUS_BADGE_LEVEL.pending } text={ STATUS_LABELS.pending } />
															</HStack>
															<CardActionsMenu
																toggleLabel={ sprintf(
																	__( 'Subscription actions: %s', 'newspack-plugin' ),
																	sub.plan
																) }
																renderItems={ onClose => (
																	<>
																		<MenuItem
																			aria-label={ sprintf(
																				__( 'Mark as paid: %s', 'newspack-plugin' ),
																				sub.plan
																			) }
																			onClick={ () => {
																				onClose();
																				markPendingPaid( sub );
																			} }
																		>
																			{ __( 'Mark as paid', 'newspack-plugin' ) }
																		</MenuItem>
																		<MenuItem
																			aria-label={ sprintf(
																				__( 'Resend link: %s', 'newspack-plugin' ),
																				sub.plan
																			) }
																			onClick={ () => {
																				onClose();
																				resendPendingLink( sub );
																			} }
																		>
																			{ __( 'Resend link', 'newspack-plugin' ) }
																		</MenuItem>
																		<MenuItem
																			isDestructive
																			aria-label={ sprintf( __( 'Cancel: %s', 'newspack-plugin' ), sub.plan ) }
																			onClick={ () => {
																				onClose();
																				cancelPending( sub );
																			} }
																		>
																			{ __( 'Cancel', 'newspack-plugin' ) }
																		</MenuItem>
																	</>
																) }
															/>
														</HStack>
													),
												} }
											>
												<div>
													{ sprintf(
														__( 'Payment link sent %s. Awaiting payment.', 'newspack-plugin' ),
														fmtRelative( sub.linkSentAt )
													) }
												</div>
											</Card>
										),
									};
								}
								const isActive = sub.status === 'active';
								// A free-forever (zero-amount) sub was never charged, so there is
								// nothing to refund — the action is a plain cancel.
								const isFreeForever = sub.amount === 0;
								return {
									status: sub.status,
									date: sub.startDate,
									node: (
										<Card
											key={ sub.id }
											__experimentalCoreCard
											__experimentalCoreProps={ {
												header: (
													<HStack justify="space-between" alignment="center">
														<HStack spacing={ 2 } justify="flex-start" expanded={ false }>
															<h4>{ sub.plan }</h4>
															<Badge level={ STATUS_BADGE_LEVEL[ sub.status ] } text={ STATUS_LABELS[ sub.status ] } />
														</HStack>
														<CardActionsMenu
															toggleLabel={ sprintf( __( 'Subscription actions: %s', 'newspack-plugin' ), sub.plan ) }
															renderItems={ onClose => (
																<>
																	{ isActive && getPlanOptions( sub, false ).length > 0 && (
																		<MenuItem
																			aria-label={ sprintf(
																				__( 'Change subscription: %s', 'newspack-plugin' ),
																				sub.plan
																			) }
																			onClick={ () => {
																				onClose();
																				setModal( { kind: 'plan', subscription: sub } );
																			} }
																		>
																			{ __( 'Change subscription', 'newspack-plugin' ) }
																		</MenuItem>
																	) }
																	{ isActive && ! isFreeForever && hasMultipleCards && (
																		<MenuItem
																			aria-label={ sprintf(
																				__( 'Change payment method: %s', 'newspack-plugin' ),
																				sub.plan
																			) }
																			onClick={ () => {
																				onClose();
																				setModal( { kind: 'changePayment', subscription: sub } );
																			} }
																		>
																			{ __( 'Change payment method', 'newspack-plugin' ) }
																		</MenuItem>
																	) }
																	{ isActive && (
																		<MenuItem
																			isDestructive
																			aria-label={
																				isFreeForever
																					? sprintf( __( 'Cancel: %s', 'newspack-plugin' ), sub.plan )
																					: sprintf(
																							__( 'Refund or cancel: %s', 'newspack-plugin' ),
																							sub.plan
																					  )
																			}
																			onClick={ () => {
																				onClose();
																				setModal( { kind: 'refund', subscription: sub } );
																			} }
																		>
																			{ isFreeForever
																				? __( 'Cancel', 'newspack-plugin' )
																				: __( 'Refund or cancel', 'newspack-plugin' ) }
																		</MenuItem>
																	) }
																	{ sub.status === 'on-hold' && (
																		<>
																			<MenuItem
																				aria-label={ sprintf(
																					__( 'Reactivate: %s', 'newspack-plugin' ),
																					sub.plan
																				) }
																				onClick={ () => {
																					onClose();
																					setModal( {
																						kind: 'guided',
																						alert: {
																							modalTitle: __(
																								'Reactivate subscription',
																								'newspack-plugin'
																							),
																						},
																						reactivate: { sub },
																					} );
																				} }
																			>
																				{ __( 'Reactivate', 'newspack-plugin' ) }
																			</MenuItem>
																			{ ! isFreeForever && hasMultipleCards && (
																				<MenuItem
																					aria-label={ sprintf(
																						__( 'Change payment method: %s', 'newspack-plugin' ),
																						sub.plan
																					) }
																					onClick={ () => {
																						onClose();
																						setModal( { kind: 'changePayment', subscription: sub } );
																					} }
																				>
																					{ __( 'Change payment method', 'newspack-plugin' ) }
																				</MenuItem>
																			) }
																			<MenuItem
																				isDestructive
																				aria-label={ sprintf(
																					__( 'Cancel: %s', 'newspack-plugin' ),
																					sub.plan
																				) }
																				onClick={ () => {
																					onClose();
																					setModal( { kind: 'refund', subscription: sub } );
																				} }
																			>
																				{ __( 'Cancel', 'newspack-plugin' ) }
																			</MenuItem>
																		</>
																	) }
																	{ sub.status === 'cancelled' && (
																		<MenuItem
																			aria-label={ sprintf(
																				__( 'Resubscribe: %s', 'newspack-plugin' ),
																				sub.plan
																			) }
																			onClick={ () => {
																				onClose();
																				setModal( { kind: 'resubscribe' } );
																			} }
																		>
																			{ __( 'Resubscribe', 'newspack-plugin' ) }
																		</MenuItem>
																	) }
																</>
															) }
														/>
													</HStack>
												),
											} }
										>
											<Grid columns={ 2 } gutter={ 16 } noMargin>
												<CardRow label={ __( 'First subscribed', 'newspack-plugin' ) }>
													{ orDash( fmtDate( sub.startDate ) ) }
												</CardRow>
												<CardRow label={ __( 'Billing', 'newspack-plugin' ) }>
													{ billingText( sub.amount, sub.cadence ) }
												</CardRow>
												<CardRow label={ __( 'Last payment', 'newspack-plugin' ) }>
													{ lastPaidValue( subscriber.orders, sub.id ) }
												</CardRow>
												{ sub.status === 'cancelled' ? (
													<CardRow label={ __( 'Cancelled', 'newspack-plugin' ) }>
														{ orDash( fmtDate( latestOrderDate( subscriber.orders, sub.id, 'Cancellation' ) ) ) }
													</CardRow>
												) : (
													<CardRow label={ __( 'Next billing', 'newspack-plugin' ) }>
														{ orDash( fmtDate( sub.nextBillingDate ) ) }
													</CardRow>
												) }
											</Grid>
										</Card>
									),
								};
							} ),
						].sort( ( a, b ) => STATUS_RANK[ a.status ] - STATUS_RANK[ b.status ] || ( b.date || '' ).localeCompare( a.date || '' ) );

						// Cancelled plans are hidden by default: all of them when a live
						// plan remains, otherwise everything past the cancelled limit.
						const liveCount = items.filter( i => i.status !== 'cancelled' ).length;
						const cancelledCount = items.length - liveCount;
						let visibleCount;
						if ( liveCount > 0 ) {
							visibleCount = liveCount;
						} else if ( cancelledCount - SUBSCRIPTIONS_CANCELLED_LIMIT >= SUBSCRIPTIONS_COLLAPSE_MIN_HIDDEN ) {
							visibleCount = SUBSCRIPTIONS_CANCELLED_LIMIT;
						} else {
							visibleCount = items.length;
						}
						const hiddenCount = items.length - visibleCount;
						subsRevealBoundaryRef.current = visibleCount;
						const shown = subscriptionsExpanded ? items : items.slice( 0, visibleCount );

						return (
							<>
								{ shown.map( item => item.node ) }
								{ hiddenCount > 0 && (
									<HStack justify="flex-start">
										<Button
											variant="link"
											onClick={ () => {
												if ( ! subscriptionsExpanded ) {
													subsFocusRevealedRef.current = true;
												}
												setSubscriptionsExpanded( value => ! value );
											} }
											aria-label={
												subscriptionsExpanded
													? __( 'Hide cancelled subscriptions', 'newspack-plugin' )
													: sprintf(
															// translators: %d is the number of hidden cancelled subscriptions.
															_n(
																'View %d cancelled subscription',
																'View %d cancelled subscriptions',
																hiddenCount,
																'newspack-plugin'
															),
															hiddenCount
													  )
											}
										>
											{ subscriptionsExpanded
												? __( 'View less', 'newspack-plugin' )
												: sprintf(
														// translators: %d is the number of hidden cancelled subscriptions.
														__( 'View %d more', 'newspack-plugin' ),
														hiddenCount
												  ) }
										</Button>
									</HStack>
								) }
							</>
						);
					} )() }
					<HStack justify="flex-start">
						<Button variant="secondary" size="compact" onClick={ () => setModal( { kind: 'addSubscription' } ) }>
							{ __( 'Add subscription', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Row>

			<Row title={ __( 'Payment methods', 'newspack-plugin' ) } showDivider={ orderRows.length > 0 }>
				<VStack spacing={ 4 }>
					{ subscriber.paymentMethods.length === 0 ? (
						<HStack justify="flex-start">
							<Button variant="secondary" size="compact" onClick={ () => setModal( { kind: 'payment' } ) }>
								{ __( 'Add payment method', 'newspack-plugin' ) }
							</Button>
						</HStack>
					) : (
						<>
							{ subscriber.paymentMethods.map( pm => (
								<Card key={ pm.id } __experimentalCoreCard>
									<HStack spacing={ 3 } justify="space-between" alignment="center">
										<HStack spacing={ 3 } justify="flex-start" alignment="center">
											{ CARD_ICONS[ pm.type ] && (
												<img src={ CARD_ICONS[ pm.type ] } alt={ pm.type } className="newspack-subscribers-demo__card-icon" />
											) }
											<VStack spacing={ 1 }>
												<HStack spacing={ 2 } justify="flex-start">
													<strong>{ cardLabel( pm ) }</strong>
													{ pm.isDefault && <Badge level="default" text={ __( 'Default', 'newspack-plugin' ) } /> }
													{ isCardExpired( pm.expiry ) && (
														<Badge level="error" text={ __( 'Expired', 'newspack-plugin' ) } />
													) }
													{ ! pm.isDefault && ! isCardExpired( pm.expiry ) && (
														// An active, non-default card has no badge to show, so render an invisible
														// one (aria-hidden) that just reserves the badge height, keeping rows aligned.
														<span className="newspack-subscribers-demo__badge-placeholder" aria-hidden="true">
															<Badge level="success" text={ __( 'Active', 'newspack-plugin' ) } />
														</span>
													) }
												</HStack>
												<span className="newspack-subscribers-demo__muted">
													{ sprintf( __( 'Expiry %s', 'newspack-plugin' ), pm.expiry ) }
												</span>
											</VStack>
										</HStack>
										<Dropdown
											className="newspack-subscribers-demo__card-menu"
											placement="bottom-end"
											renderToggle={ ( { isOpen, onToggle } ) => (
												<Button
													icon={ moreVertical }
													size="compact"
													onClick={ onToggle }
													aria-expanded={ isOpen }
													label={ sprintf( __( 'Payment method actions: %s', 'newspack-plugin' ), cardLabel( pm ) ) }
													showTooltip={ false }
												/>
											) }
											renderContent={ ( { onClose } ) => (
												<MenuGroup>
													<MenuItem
														aria-label={ sprintf( __( 'Edit %s', 'newspack-plugin' ), cardLabel( pm ) ) }
														onClick={ () => {
															onClose();
															setModal( { kind: 'payment', paymentMethod: pm } );
														} }
													>
														{ __( 'Edit', 'newspack-plugin' ) }
													</MenuItem>
													{ ! pm.isDefault && (
														<>
															{ /* An expired card can't be charged, so it can't be made the default. */ }
															{ ! isCardExpired( pm.expiry ) && (
																<MenuItem
																	aria-label={ sprintf(
																		__( 'Make default: %s', 'newspack-plugin' ),
																		cardLabel( pm )
																	) }
																	onClick={ () => {
																		onClose();
																		completeFlow( {
																			message: sprintf(
																				__( '%s set as default.', 'newspack-plugin' ),
																				cardLabel( pm )
																			),
																			mutate: s => ( {
																				...s,
																				paymentMethods: s.paymentMethods.map( m => ( {
																					...m,
																					isDefault: m.id === pm.id,
																				} ) ),
																			} ),
																		} );
																	} }
																>
																	{ __( 'Make default', 'newspack-plugin' ) }
																</MenuItem>
															) }
															<MenuItem
																aria-label={ sprintf( __( 'Remove %s', 'newspack-plugin' ), cardLabel( pm ) ) }
																isDestructive
																onClick={ () => {
																	onClose();
																	setModal( { kind: 'remove-payment', paymentMethod: pm } );
																} }
															>
																{ __( 'Remove', 'newspack-plugin' ) }
															</MenuItem>
														</>
													) }
												</MenuGroup>
											) }
										/>
									</HStack>
								</Card>
							) ) }
							<HStack justify="flex-start">
								<Button variant="secondary" size="compact" onClick={ () => setModal( { kind: 'payment' } ) }>
									{ __( 'Add payment method', 'newspack-plugin' ) }
								</Button>
							</HStack>
						</>
					) }
				</VStack>
			</Row>

			{ orderRows.length > 0 && (
				<>
					<SectionHeader heading={ 2 } title={ __( 'Billing history', 'newspack-plugin' ) } />
					<DataViews
						className="newspack-subscribers-demo__orders-dataviews"
						data={ processedOrders }
						fields={ orderFields }
						view={ ordersView }
						onChangeView={ setOrdersView }
						paginationInfo={ ordersPagination }
						defaultLayouts={ { table: {} } }
						getItemId={ item => item.id }
						search
					/>
				</>
			) }

			{ modal?.kind === 'refund' && (
				<RefundFlow
					subscription={ modal.subscription }
					group={ modal.group }
					subscriber={ subscriber }
					onClose={ closeModal }
					onComplete={ completeFlow }
				/>
			) }
			{ modal?.kind === 'plan' && (
				<PlanChangeFlow subscription={ modal.subscription } group={ modal.group } onClose={ closeModal } onComplete={ completeFlow } />
			) }
			{ modal?.kind === 'changePayment' && (
				<ChangePaymentMethodFlow
					subscription={ modal.subscription }
					subscriber={ subscriber }
					onClose={ closeModal }
					onComplete={ completeFlow }
				/>
			) }
			{ modal?.kind === 'resubscribe' && (
				<AddSubscriptionFlow
					subscriber={ subscriber }
					onClose={ closeModal }
					onComplete={ completeFlow }
					onOpenPaymentUpdate={ ( draft, notify ) => setModal( { kind: 'payment', addDraft: draft, addNotify: notify } ) }
				/>
			) }
			{ modal?.kind === 'addSubscription' && (
				<AddSubscriptionFlow
					subscriber={ subscriber }
					addMode
					onClose={ closeModal }
					onComplete={ completeFlow }
					onOpenPaymentUpdate={ ( draft, notify ) => setModal( { kind: 'payment', addDraft: draft, addNotify: notify } ) }
				/>
			) }
			{ modal?.kind === 'payment' && (
				<PaymentUpdateFlow
					paymentMethod={ modal.paymentMethod }
					onClose={ closeModal }
					onComplete={ result => {
						completeFlow( result );
						if ( modal.reactivate?.sub ) {
							reactivateSubscription( modal.reactivate.sub, true );
						} else if ( modal.reactivate?.group ) {
							reactivateGroup( modal.reactivate.group, true );
						} else if ( modal.addDraft ) {
							completePaidAddWithCard( modal.addDraft, modal.addNotify );
						}
					} }
				/>
			) }
			{ snackbar && (
				<div className="newspack-subscribers-demo__snackbar">
					<Snackbar onRemove={ () => setSnackbar( null ) }>{ snackbar.message }</Snackbar>
				</div>
			) }

			{ modal?.kind === 'remove-payment' && (
				<RemovePaymentFlow paymentMethod={ modal.paymentMethod } onClose={ closeModal } onComplete={ completeFlow } />
			) }
			{ modal?.kind === 'note' && <NoteFlow note={ modal.note } onClose={ closeModal } onComplete={ completeFlow } /> }
			{ modal?.kind === 'tags' && <TagsFlow tags={ subscriber.tags || [] } onClose={ closeModal } onComplete={ completeFlow } /> }
			{ modal?.kind === 'guided' && (
				<GuidedFixFlow
					modalTitle={ modal.alert.modalTitle || modal.alert.title }
					target={ modal.reactivate?.sub || modal.reactivate?.group }
					verb={ ( modal.reactivate?.sub || modal.reactivate?.group )?.status === 'cancelled' ? 'resubscribe' : 'reactivate' }
					email={ subscriber.email }
					canCharge={ hasUsableCard( subscriber ) }
					onClose={ closeModal }
					onReactivateCharge={ notify => {
						if ( modal.reactivate?.sub ) {
							reactivateSubscription( modal.reactivate.sub, true, notify );
						} else if ( modal.reactivate?.group ) {
							reactivateGroup( modal.reactivate.group, true, notify );
						}
						closeModal();
					} }
					onSendPaymentLink={ () =>
						completeFlow( {
							message: sprintf( __( 'Payment link sent to %s.', 'newspack-plugin' ), subscriber.email ),
							mutate: s => ( {
								...s,
								orders: [ buildPaymentLinkOrder( modal.reactivate?.sub || modal.reactivate?.group ), ...( s.orders || [] ) ],
							} ),
						} )
					}
					onReactivateFree={ ( freeCyclesRemaining, notify ) => {
						if ( modal.reactivate?.sub ) {
							reactivateFree( modal.reactivate.sub, false, freeCyclesRemaining, notify );
						} else if ( modal.reactivate?.group ) {
							reactivateFree( modal.reactivate.group, true, freeCyclesRemaining, notify );
						}
						closeModal();
					} }
				/>
			) }
		</div>
	);
}

// Remount on id change (key) so all per-id state — subscriber, modal, snackbar —
// resets cleanly instead of relying on a reset effect that flashed the previous
// person's data for a frame before clearing.
export default function PersonProfile() {
	const { id } = useParams();
	return <PersonProfileView key={ id } />;
}
