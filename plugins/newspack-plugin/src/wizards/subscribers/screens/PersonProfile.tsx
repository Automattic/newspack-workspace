/**
 * L1 — Person profile.
 *
 * Everything one reader holds, on one screen: their identity in the wizard
 * header (avatar, email, headline status badges) and every subscription they
 * have below it — their own plans and any group they own or belong to — each as
 * a card carrying its status, billing shape and per-status actions.
 *
 * The screen exists for the case the list row cannot express. A row reduces a
 * reader to one badge; a reader on a cancelled plan *and* a live one, or on an
 * individual plan *and* in an on-hold group, needs both shown side by side with
 * their own dates and their own actions. That is what the cards do.
 *
 * Layout is the two-column grid the design uses throughout (section header left,
 * content right), and every card is the shared SubscriptionCard so this screen
 * and the group detail cannot drift apart.
 *
 * Each individual subscription card carries its per-status actions in the
 * "more" menu — reactivate for an on-hold plan (charge now, send a payment
 * link, or reactivate for free), change plan and change payment method for a
 * live plan, refund/cancel where there is a payment to give back — and the
 * saved cards are listed below the subscriptions with their Default/Expired
 * state. Every mutation is awaited and followed by a profile refetch (the
 * wizard's write convention, see data/use-group.js on the group-detail slice):
 * the server recalculates totals and can refuse, so the response is the truth.
 * Resubscribe is a separate workstream and stays absent rather than
 * present-but-inert.
 */

/**
 * WordPress dependencies.
 */
import { createPortal, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import {
	Snackbar,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, Card, Divider, Router, SplitSection, StatusBadge, TableCard, Waiting } from '../../../../packages/components/src';
import './style.scss';
import LoadFailureNotice from '../components/LoadFailureNotice';
import { useRetryFocus } from '../use-retry-focus';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { worthCollapsing } from '../../../../packages/components/src/cell-list/collapse';
import SubscriptionCard from '../components/SubscriptionCard';
import PaymentMethodsList, { cardLabel } from '../components/PaymentMethodsList';
import ChangePaymentMethodFlow from '../flows/ChangePaymentMethodFlow';
import PlanChangeFlow from '../flows/PlanChangeFlow';
import RefundFlow from '../flows/RefundFlow';
import ReactivateFlow from '../flows/ReactivateFlow';
import { useSubscriber } from '../data/use-subscriber';
import { usePaymentActions, canChangePaymentMethod } from '../data/use-payments';
import { useSubscriptionActions } from '../data/use-subscription-actions';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { useWizardNode } from '../use-portals';
import { billingText, fmtCurrency, fmtDate, orDash, paymentRows } from '../format';
import { GROUP_LABEL, GROUP_LABEL_PLURAL, groupViewLabel } from '../labels';
import { groupDetailHref, isInternalHashPath } from '../links';
import { STATUS_LABELS, STATUS_BADGE_INTENT, statusRank, displayStatuses } from '../status';
import { isNetworkSubscription, shouldShowPaymentMethods } from '../network';
import type { ReactNode } from 'react';
import type { StatusName, StatusTone } from '../../../../packages/components/src/status-indicator';
import type {
	ApiError,
	Donation,
	NetworkRef,
	OnDone,
	OrderRef,
	PaymentActions,
	PaymentMethod,
	ProfileGroupEntry,
	Subscriber,
	SubscriptionActions,
	SubscriptionEntry,
} from '../types';

const { useParams, useLocation } = Router;

// Cancelled subscriptions are folded away behind a "Show N more" toggle whenever
// the reader still holds a live plan — a past cancellation next to a current
// plan is history, not status. A fully churned reader keeps everything visible,
// since hiding it would leave the profile looking empty. This mirrors the
// Subscription column's rule in SubscriberList (see the SOURCE OF TRUTH note on
// displayStatuses in status.js).
const HIDE_CANCELLED_WHEN_LIVE = true;

/**
 * The name of whoever bought a gift, or undefined when the subscription isn't one.
 * A buyer with no name on file reads "Unknown".
 *
 * @param subscription The profile subscription entry.
 * @return The buyer's name.
 */
const giftPayerName = ( subscription: SubscriptionEntry ): string | undefined =>
	subscription.giftedBy ? subscription.giftedBy.name || __( 'Unknown', 'newspack-plugin' ) : undefined;

/**
 * Row of a two-column section: header on the left, content on the right.
 *
 * `title` and `description` head the section, `children` is its content, and
 * `showDivider` closes it with a rule.
 */
function Row( {
	title,
	description,
	children,
	showDivider = true,
}: {
	title: string;
	description?: string;
	children: ReactNode;
	showDivider?: boolean;
} ) {
	return (
		<>
			<SplitSection.Root>
				<SplitSection.Header title={ title } description={ description } />
				<SplitSection.Content>{ children }</SplitSection.Content>
			</SplitSection.Root>
			{ showDivider && <Divider alignment="full-width" variant="tertiary" /> }
		</>
	);
}

/**
 * The seat line under a group's name, e.g. "3 of 5 seats".
 *
 * A seat limit of 0 means the group is uncapped, so there is no denominator to
 * count against — see GroupList, which words the same fact for its table.
 *
 * @param group A group entry.
 * @return The seat subline.
 */
const seatsSubline = ( group: ProfileGroupEntry ): string => {
	const used = group.members || 0;
	if ( ( group.seatLimit as number ) > 0 ) {
		// translators: 1: seats in use, 2: the group's seat limit.
		return sprintf( __( '%1$d of %2$d seats', 'newspack-plugin' ), used, group.seatLimit as number );
	}
	// translators: %d is the number of seats in use in an uncapped group.
	return sprintf( _n( '%d of unlimited seats', '%d of unlimited seats', used, 'newspack-plugin' ), used );
};

/**
 * The status badge(s) for one subscription or group.
 *
 * @param status The mapped status.
 * @return Badge descriptors for SubscriptionCard.
 */
const statusBadges = ( status?: string ) =>
	STATUS_LABELS[ status as string ] ? [ { label: STATUS_LABELS[ status as string ], intent: STATUS_BADGE_INTENT[ status as string ] } ] : [];

/**
 * An order's number as WooCommerce shows it, e.g. "#1234", linked to the order
 * when its edit screen is known.
 *
 * @return The number, linked or plain.
 */
const orderNumber = ( { number, editUrl }: OrderRef ) => ( editUrl ? <a href={ editUrl }>{ `#${ number }` }</a> : `#${ number }` );

/**
 * The rows that name a subscription by number, so the admin can follow a case
 * across WooCommerce, the payment processor and email: the subscription's own
 * number, and its latest order. The server sends the latest order only on the
 * card of the reader who pays, never on a gift's or a group member's.
 *
 * @return Card rows.
 */
const numberRows = ( entry: { number?: string | number; lastOrder?: OrderRef; editUrl?: string } ) => [
	...( entry.number
		? [ { label: __( 'Subscription number', 'newspack-plugin' ), value: orderNumber( { number: entry.number, editUrl: entry.editUrl } ) } ]
		: [] ),
	...( entry.lastOrder ? [ { label: __( 'Latest order', 'newspack-plugin' ), value: orderNumber( entry.lastOrder ) } ] : [] ),
];

/**
 * The order statuses a listed donation can be in besides paid, on the shared
 * status vocabulary. A paid order reads as the plain fact of the donation, so
 * only these carry a badge. A refund is finished, with nothing left to act on,
 * so it takes a neutral tone.
 */
const DONATION_STATUS_BADGES: Record< string, { status: StatusName; tone?: StatusTone; label: string } > = {
	processing: { status: 'progress', label: __( 'Processing', 'newspack-plugin' ) },
	refunded: { status: 'ended', tone: 'neutral', label: __( 'Refunded', 'newspack-plugin' ) },
};

/**
 * A donation's amount, with what was refunded on a line below when a partial
 * refund left the order paid. A fully refunded order says so in its badge instead.
 *
 * @return The amount.
 */
const donationAmount = ( { amount, refunded, currency, status }: Donation ) => {
	const total = orDash( fmtCurrency( amount, currency ) );
	if ( 'refunded' === status || ! ( ( refunded as number ) > 0 ) ) {
		return total;
	}
	return (
		<>
			{ total }
			<div className="newspack-subscribers__muted">
				{
					// translators: %s: the part of the donation that was refunded.
					sprintf( __( '%s refunded', 'newspack-plugin' ), fmtCurrency( refunded, currency ) )
				}
			</div>
		</>
	);
};

/**
 * The "View subscription" item that leads a card's "more" menu: a link to the
 * subscription's own edit screen, for the admin who wants the order behind a card.
 *
 * @param name The subscription or group name, to qualify the link.
 * @param href The subscription's edit URL.
 * @return An action descriptor for SubscriptionCard.
 */
const viewSubscriptionAction = ( name: string, href?: string ) => ( {
	key: 'view',
	label: __( 'View subscription', 'newspack-plugin' ),
	// translators: %s is a subscription/plan name.
	ariaLabel: sprintf( __( 'View subscription: %s', 'newspack-plugin' ), name ),
	href,
} );

export default function PersonProfile() {
	const { id } = useParams< { id: string } >();
	const location = useLocation();
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ showCancelled, setShowCancelled ] = useState( false );

	// Return to wherever the profile was opened from. HashRouter drops
	// location.state across a reload, so the origin travels as a `from` query
	// param — which is attacker-controllable, so it is accepted only when it is an
	// in-wizard hash path. This closes off a `from=javascript:…` that would put a
	// live javascript: URL on the back chevron in an authenticated admin origin,
	// and a `from=https://evil.example` off-site redirect. Anything else falls
	// back to the subscriber list.
	const rawFrom = new URLSearchParams( location.search ).get( 'from' );
	const backNav = isInternalHashPath( rawFrom ) ? rawFrom : '#/';

	// The data hook's documented contract; typed here at the boundary until it is typed.
	const { subscriber, loading, error, notFound, reload } = useSubscriber( id ) as {
		subscriber: Subscriber | null;
		loading: boolean;
		error: string;
		notFound: boolean;
		reload: () => void;
	};

	// A missing person counts as failed here too: that read nulls the subscriber.
	const { retryRef, retry } = useRetryFocus( { settled: ! loading, failed: Boolean( error || ! subscriber ), reload } );

	// The money flows: which modal is open (null for none), the snackbar shown
	// after one completes, and the write calls the modals run. The snackbar's
	// `id` makes a repeated identical message a new render (so it re-announces
	// and its dismiss timer restarts), and `isError` styles a refusal apart from
	// a confirmation and raises how insistently it is announced.
	const [ modal, setModal ] = useState< { kind: string; subscription: SubscriptionEntry } | null >( null );
	const [ snackbar, setSnackbar ] = useState< { message?: string; isError: boolean; id: number } | null >( null );
	const [ promotedCardId, setPromotedCardId ] = useState( 0 );
	const paymentActions = usePaymentActions( id ) as PaymentActions;
	const subscriptionActions = useSubscriptionActions() as SubscriptionActions;

	const showSnackbar = ( message?: string, isError = false ) => setSnackbar( { message, isError, id: Date.now() } );

	// Every flow ends the same way: close the modal, tell the admin what
	// happened, and refetch the profile so the screen renders the server's
	// truth rather than the request's intent.
	const completeFlow: OnDone = message => {
		setModal( null );
		showSnackbar( message );
		reload();
	};

	// Make-default is a single non-destructive click, so it runs without a
	// confirmation modal; a refusal (e.g. the server refusing an expired card)
	// surfaces in the snackbar with the server's own message.
	const makeDefault = async ( pm: PaymentMethod ) => {
		try {
			await paymentActions.setDefaultPaymentMethod( pm.id );
			// The card's kebab disappears once it is the default, so name the card
			// the list should hand focus to (see PaymentMethodsList).
			setPromotedCardId( pm.id );
			// translators: %s is a card label (e.g. "Visa ending in 4242").
			completeFlow( sprintf( __( '%s is now the default payment method.', 'newspack-plugin' ), cardLabel( pm ) ) );
		} catch ( e ) {
			showSnackbar( ( e as ApiError )?.message || __( 'Something went wrong.', 'newspack-plugin' ), true );
		}
	};

	// A 128px source feeds the 64px header avatar (2x for high-DPR displays),
	// resolved through the same endpoint the lists use.
	const avatarEmails = useMemo( () => ( subscriber?.email ? [ subscriber.email ] : [] ), [ subscriber ] );
	const { avatars } = useAvatars( avatarEmails, { size: 128 } ) as { avatars: Record< string, string > };
	const avatarUrl = ( subscriber?.email && avatars[ subscriber.email ] ) || '';

	// The wizard renders its section header from store data, so the avatar is
	// portaled into it once it appears — watched rather than raced on one frame.
	const headerNode = useWizardNode( '.newspack-wizard__section-header', 'newspack-subscribers__has-avatar', SHOW_AVATARS && !! subscriber );

	// Headline status: every distinct status across everything the reader holds,
	// active-first, so an active group alongside an on-hold plan surfaces as
	// "Active · On hold" rather than one masking the other. Cancelled is dropped
	// while any live plan remains. Per-subscription status still shows on each card.
	const headlineStatuses = useMemo( () => {
		if ( ! subscriber ) {
			return [];
		}
		const statuses = [
			...( subscriber.groups || [] ).map( group => group.status ),
			...( subscriber.subscriptions || [] ).map( subscription => subscription.status ),
		];
		return displayStatuses( statuses, subscriber.status );
	}, [ subscriber ] );

	useEffect( () => {
		if ( ! subscriber ) {
			return;
		}
		// The breadcrumb reflects where the profile was opened from: a person
		// reached from a group sits under the groups tab, otherwise under
		// Readers. Both crumbs stay clickable so the trail is a way back.
		const originatedInGroups = backNav.startsWith( '#/groups' );
		setHeaderData( {
			backNav,
			sectionName: [
				{ label: originatedInGroups ? GROUP_LABEL_PLURAL : __( 'Readers', 'newspack-plugin' ), url: backNav },
				{ label: subscriber.name },
			],
			sectionTitle: subscriber.name,
			badges: headlineStatuses.map( status => ( { label: STATUS_LABELS[ status ], intent: STATUS_BADGE_INTENT[ status ] } ) ),
			// A newline-joined string, not markup: SectionHeader renders a
			// description inside a `<p>`, so a stack of divs would be invalid
			// nesting. The wizard stylesheet sets `white-space: pre-line` on that
			// paragraph for exactly this, so the lines break where they read.
			sectionDescription: [
				subscriber.email,
				subscriber.memberSince
					? // translators: %s is a date.
					  sprintf( __( 'Member since %s', 'newspack-plugin' ), fmtDate( subscriber.memberSince ) )
					: '',
				subscriber.signedUpOn?.name
					? // translators: %s is the name of the network site where the reader created their account.
					  sprintf( __( 'Signed up on %s', 'newspack-plugin' ), subscriber.signedUpOn.name )
					: '',
			]
				.filter( Boolean )
				.join( '\n' ),
			// The native user-edit screen stays one click away: this profile does not
			// yet cover editing the WordPress user, so removing the way there would
			// strand anyone who needs it.
			actions: subscriber.editUrl ? [ { type: 'more', label: __( 'Edit WordPress user', 'newspack-plugin' ), href: subscriber.editUrl } ] : [],
		} );
	}, [ subscriber, headlineStatuses, backNav, setHeaderData ] );

	// Every subscription the reader holds, group and individual alike, as cards.
	// Ordered active-first then newest-first within a status, so what the reader
	// is paying for now leads and history trails.
	const cards = useMemo( () => {
		if ( ! subscriber ) {
			return [];
		}
		const groupCards = ( subscriber.groups || [] ).map( group => {
			const isOwner = 'owner' === group.role;
			const rows = isOwner
				? [
						{ label: __( 'First subscribed', 'newspack-plugin' ), value: orDash( fmtDate( group.createdAt ) ) },
						{ label: __( 'Billing', 'newspack-plugin' ), value: billingText( group ) },
						...paymentRows( group ),
						...numberRows( group ),
				  ]
				: [
						{ label: __( 'Joined', 'newspack-plugin' ), value: orDash( fmtDate( group.joinedAt ) ) },
						{
							label: __( 'Owner', 'newspack-plugin' ),
							value: group.owner?.name || __( 'Unknown', 'newspack-plugin' ),
						},
						...numberRows( group ),
				  ];
			const href = groupDetailHref( group );
			return {
				key: `group-${ group.id }`,
				status: group.status,
				date: group.createdAt,
				node: (
					<SubscriptionCard
						key={ `group-${ group.id }` }
						title={ group.plan as string }
						titleSuffix={ `(${ GROUP_LABEL })` }
						titleHref={ href || undefined }
						titleLabel={ href ? groupViewLabel( group.plan as string ) : undefined }
						badges={ statusBadges( group.status ) }
						subline={ seatsSubline( group ) }
						rows={ rows }
						// The title opens the group; this item opens its subscription.
						actions={ group.editUrl ? [ viewSubscriptionAction( group.plan as string, group.editUrl ) ] : [] }
						// translators: %s is a group's plan name.
						actionsLabel={ sprintf( __( 'Subscription actions: %s', 'newspack-plugin' ), group.plan as string ) }
					/>
				),
			};
		} );

		const individualCards = ( subscriber.subscriptions || [] ).map( subscription => {
			const name = subscription.plan || __( '(Subscription)', 'newspack-plugin' );

			// A subscription another network site manages is read-only here, and this
			// branch returns before any local menu or flow is built. Its ID belongs to
			// the other site; the write endpoints resolve an ID against this site's own
			// subscriptions, so a remote ID must never reach `setModal` or a request.
			// Nothing below runs for it. The key carries the site as well as the ID:
			// an ID is unique within a site, so a local #500 and a remote #500 coexist.
			if ( isNetworkSubscription( subscription ) ) {
				const { editUrl } = subscription;
				const managedOn = subscription.managedOn as NetworkRef;
				const cardKey = `subscription-${ managedOn.url || managedOn.name }-${ subscription.id }`;
				// The site is named once, under the title, so the title is the plan alone.
				const title = subscription.planName || name;
				// Subscriptions the network recorded before it kept billing details have
				// none, so their cards say where they are managed and nothing more.
				const hasBilling = Number.isFinite( subscription.amount ) || Boolean( subscription.startDate );
				return {
					key: cardKey,
					status: subscription.status,
					date: subscription.startDate,
					node: (
						<SubscriptionCard
							key={ cardKey }
							title={ title }
							titleHref={ editUrl || undefined }
							// translators: %s is a subscription/plan name.
							titleLabel={ editUrl ? sprintf( __( 'View subscription: %s', 'newspack-plugin' ), name ) : undefined }
							badges={ statusBadges( subscription.status ) }
							// translators: %s is the name of the network site that manages the subscription.
							subline={ sprintf( __( 'Managed on %s', 'newspack-plugin' ), managedOn.name ) }
							rows={
								hasBilling
									? [
											{
												label: __( 'First subscribed', 'newspack-plugin' ),
												value: orDash( fmtDate( subscription.startDate ) ),
											},
											{ label: __( 'Billing', 'newspack-plugin' ), value: billingText( subscription ) },
											...paymentRows( subscription ),
									  ]
									: []
							}
							// A link out, so with no URL there is nothing to put in the menu.
							actions={
								editUrl
									? [
											{
												key: 'view',
												// translators: %s is the name of the network site that manages the subscription.
												label: sprintf( __( 'View on %s', 'newspack-plugin' ), managedOn.name ),
												// translators: 1: the name of the network site that manages the subscription, 2: a subscription/plan name.
												ariaLabel: sprintf( __( 'View on %1$s: %2$s', 'newspack-plugin' ), managedOn.name, title ),
												href: editUrl,
											},
									  ]
									: []
							}
							// translators: %s is a subscription/plan name.
							actionsLabel={ sprintf( __( 'Subscription actions: %s', 'newspack-plugin' ), name ) }
						/>
					),
				};
			}

			const isActive = 'active' === subscription.status;
			const isLive = isActive || 'on-hold' === subscription.status;
			const giftedByName = giftPayerName( subscription );
			const menuActions = [];
			if ( subscription.editUrl ) {
				menuActions.push( viewSubscriptionAction( name, subscription.editUrl ) );
			}
			// Reactivate is offered when the server says so. `canReactivate`
			// reflects the write endpoint's own raw-status rule — the mapped status
			// can't be trusted here, because unknown WCS statuses map into the
			// "on-hold" bucket the endpoint would refuse.
			if ( subscription.canReactivate ) {
				menuActions.push( {
					key: 'reactivate',
					label: __( 'Reactivate', 'newspack-plugin' ),
					// translators: %s is a subscription/plan name.
					ariaLabel: sprintf( __( 'Reactivate: %s', 'newspack-plugin' ), name ),
					onClick: () => setModal( { kind: 'reactivate', subscription } ),
				} );
			}
			// canChangePlan is resolved server-side with the same rule the endpoint
			// enforces (strictly active — the wizard's "Active" badge also covers
			// WCS pending-cancel — and no coupon/fee/shipping items), so the menu
			// never offers a plan change the server would refuse.
			if ( subscription.canChangePlan ) {
				menuActions.push( {
					key: 'plan',
					label: __( 'Change subscription', 'newspack-plugin' ),
					// translators: %s is a subscription/plan name.
					ariaLabel: sprintf( __( 'Change subscription: %s', 'newspack-plugin' ), name ),
					onClick: () => setModal( { kind: 'plan', subscription } ),
				} );
			}
			// Offered only when the subscription charges a resolvable saved card
			// and there is another usable card to switch to. Never on a gift: the
			// saved cards listed here are the recipient's, but the card the gift
			// charges belongs to the person who bought it.
			if ( isLive && ! subscription.giftedBy && canChangePaymentMethod( subscription, subscriber.paymentMethods || [] ) ) {
				menuActions.push( {
					key: 'payment',
					label: __( 'Change payment method', 'newspack-plugin' ),
					// translators: %s is a subscription/plan name.
					ariaLabel: sprintf( __( 'Change payment method: %s', 'newspack-plugin' ), name ),
					onClick: () => setModal( { kind: 'payment', subscription } ),
				} );
			}
			// The refund choice is offered only when there is actually money to give
			// back (the server's refundableAmount); on hold, free, or fully
			// refunded, the action is a plain cancel — same rule RefundFlow applies.
			if ( isLive ) {
				const refundable = isActive && !! subscription.refundableAmount;
				menuActions.push( {
					key: 'refund',
					label: refundable ? __( 'Refund or cancel', 'newspack-plugin' ) : __( 'Cancel', 'newspack-plugin' ),
					ariaLabel: refundable
						? // translators: %s is a subscription/plan name.
						  sprintf( __( 'Refund or cancel: %s', 'newspack-plugin' ), name )
						: // translators: %s is a subscription/plan name.
						  sprintf( __( 'Cancel: %s', 'newspack-plugin' ), name ),
					isDestructive: true,
					onClick: () => setModal( { kind: 'refund', subscription } ),
				} );
			}
			return {
				key: `subscription-${ subscription.id }`,
				status: subscription.status,
				date: subscription.startDate,
				node: (
					<SubscriptionCard
						key={ `subscription-${ subscription.id }` }
						title={ name }
						titleHref={ subscription.editUrl || undefined }
						// translators: %s is a subscription/plan name.
						titleLabel={ subscription.editUrl ? sprintf( __( 'View subscription: %s', 'newspack-plugin' ), name ) : undefined }
						badges={ statusBadges( subscription.status ) }
						rows={ [
							{ label: __( 'First subscribed', 'newspack-plugin' ), value: orDash( fmtDate( subscription.startDate ) ) },
							{ label: __( 'Billing', 'newspack-plugin' ), value: billingText( subscription ) },
							...paymentRows( subscription ),
							...( subscription.giftedBy
								? [
										{
											label: __( 'Gifted by', 'newspack-plugin' ),
											value: subscription.giftedBy.editUrl ? (
												<a href={ subscription.giftedBy.editUrl }>{ giftedByName }</a>
											) : (
												giftedByName
											),
										},
								  ]
								: [] ),
							...numberRows( subscription ),
						] }
						actions={ menuActions }
						// translators: %s is a subscription/plan name.
						actionsLabel={ sprintf( __( 'Subscription actions: %s', 'newspack-plugin' ), name ) }
					/>
				),
			};
		} );

		return [ ...groupCards, ...individualCards ].sort(
			( a, b ) => statusRank( a.status ) - statusRank( b.status ) || ( b.date || '' ).localeCompare( a.date || '' )
		);
	}, [ subscriber ] );

	const liveCards = useMemo( () => cards.filter( card => 'cancelled' !== card.status ), [ cards ] );
	const foldCancelled = HIDE_CANCELLED_WHEN_LIVE && liveCards.length > 0 && worthCollapsing( cards.length - liveCards.length );
	const collapsedCards = foldCancelled ? liveCards : cards;
	const hiddenCount = cards.length - collapsedCards.length;
	const visibleCards = showCancelled ? cards : collapsedCards;
	// Readers who never gave a one-time donation see no Donations section at all.
	const hasDonations = ( subscriber?.donations || [] ).length > 0;

	if ( loading ) {
		return (
			<div className="newspack-subscribers__loading">
				<Waiting isCenter />
			</div>
		);
	}

	// A person who does not exist is a dead end, so it gets a plain statement and
	// a way back — not a Retry button that can never succeed.
	if ( notFound ) {
		const message = __( 'This reader could not be found. They may have been deleted.', 'newspack-plugin' );
		return (
			<LoadFailureNotice
				message={ message }
				action={
					<Button variant="link" ref={ retryRef } href={ backNav }>
						{ __( 'Back to the list', 'newspack-plugin' ) }
					</Button>
				}
			/>
		);
	}

	// A failed read must not read as "this person has no subscriptions".
	if ( error || ! subscriber ) {
		// An empty read reports no error, so there is no server detail to quote.
		let message: string = __( 'This reader could not be loaded.', 'newspack-plugin' );
		if ( error ) {
			message = sprintf(
				/* translators: %s is an error message. */
				__( 'Could not load this reader: %s', 'newspack-plugin' ),
				error
			);
		}
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
		<div className="newspack-subscribers__profile">
			{ SHOW_AVATARS &&
				headerNode &&
				avatarUrl &&
				createPortal(
					<img className="newspack-subscribers__profile-avatar" src={ avatarUrl } alt="" width={ 64 } height={ 64 } />,
					headerNode
				) }

			<Row title={ __( 'Subscriptions', 'newspack-plugin' ) } showDivider={ hasDonations || shouldShowPaymentMethods( subscriber ) }>
				<VStack spacing={ 4 }>
					{ 0 === cards.length && (
						<Card __experimentalCoreCard className="newspack-subscribers__card">
							<p>{ __( 'No subscriptions on file.', 'newspack-plugin' ) }</p>
						</Card>
					) }
					{ visibleCards.map( card => card.node ) }
					{ hiddenCount > 0 && (
						<HStack justify="flex-start">
							<Button
								variant="link"
								onClick={ () => setShowCancelled( value => ! value ) }
								aria-expanded={ showCancelled }
								// When collapsed, the accessible name extends the visible
								// "Show N more" with what it reveals (WCAG 2.5.3 wants the
								// visible label to be a substring of the accessible name).
								// When expanded, the visible "Show less" is unambiguous on
								// its own, so no override — the visible text is the name.
								aria-label={
									showCancelled
										? undefined
										: sprintf(
												// translators: %d is the number of hidden cancelled subscriptions.
												_n(
													'Show %d more cancelled subscription',
													'Show %d more cancelled subscriptions',
													hiddenCount,
													'newspack-plugin'
												),
												hiddenCount
										  )
								}
							>
								{ showCancelled
									? __( 'Show less', 'newspack-plugin' )
									: sprintf(
											// translators: %d is the number of hidden cancelled subscriptions.
											_n( 'Show %d more', 'Show %d more', hiddenCount, 'newspack-plugin' ),
											hiddenCount
									  ) }
							</Button>
						</HStack>
					) }
				</VStack>
			</Row>

			{ hasDonations && (
				<Row
					title={ __( 'Donations', 'newspack-plugin' ) }
					description={ __( 'One-time donations. Recurring donations are subscriptions, listed above.', 'newspack-plugin' ) }
					showDivider={ shouldShowPaymentMethods( subscriber ) }
				>
					<TableCard
						className="newspack-subscribers__donations-card"
						after={
							subscriber.donationsMore && (
								<p className="newspack-subscribers__muted">
									{ sprintf(
										// translators: %d: the number of donations listed.
										_n(
											'Showing the %d most recent one-time donation.',
											'Showing the %d most recent one-time donations.',
											( subscriber.donations as Donation[] ).length,
											'newspack-plugin'
										),
										( subscriber.donations as Donation[] ).length
									) }
								</p>
							)
						}
					>
						<table className="newspack-subscribers__donations">
							<thead>
								<tr>
									<th scope="col">{ __( 'Date', 'newspack-plugin' ) }</th>
									<th scope="col">{ __( 'Amount', 'newspack-plugin' ) }</th>
									<th scope="col">{ __( 'Order', 'newspack-plugin' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ ( subscriber.donations as Donation[] ).map( donation => {
									const badge = DONATION_STATUS_BADGES[ donation.status as string ];
									return (
										<tr key={ donation.id }>
											<td>{ orDash( fmtDate( donation.date ) ) }</td>
											<td>{ donationAmount( donation ) }</td>
											<td>
												<Stack direction="row" gap="sm" align="center">
													<span>{ orderNumber( donation ) }</span>
													{ badge && (
														<StatusBadge status={ badge.status } tone={ badge.tone }>
															{ badge.label }
														</StatusBadge>
													) }
												</Stack>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</TableCard>
				</Row>
			) }

			{ shouldShowPaymentMethods( subscriber ) && (
				<Row
					title={ __( 'Payment methods', 'newspack-plugin' ) }
					description={ __(
						'The cards on file for this reader. Each subscription charges the card set on it; the default applies to new purchases.',
						'newspack-plugin'
					) }
					showDivider={ false }
				>
					<PaymentMethodsList
						paymentMethods={ subscriber.paymentMethods || [] }
						onMakeDefault={ makeDefault }
						focusCardId={ promotedCardId }
					/>
				</Row>
			) }

			{ 'payment' === modal?.kind && (
				<ChangePaymentMethodFlow
					subscription={ modal.subscription }
					paymentMethods={ subscriber.paymentMethods || [] }
					actions={ paymentActions }
					onClose={ () => setModal( null ) }
					onDone={ completeFlow }
				/>
			) }
			{ 'plan' === modal?.kind && (
				<PlanChangeFlow
					subscription={ modal.subscription }
					actions={ paymentActions }
					onClose={ () => setModal( null ) }
					onDone={ completeFlow }
				/>
			) }
			{ 'refund' === modal?.kind && (
				<RefundFlow
					subscription={ modal.subscription }
					subscriberName={ subscriber.name }
					// A gift's refund goes back to whoever bought it, not to the reader;
					// passing a payer switches RefundFlow to the gift wording.
					payerName={ giftPayerName( modal.subscription ) }
					actions={ paymentActions }
					onClose={ () => setModal( null ) }
					onDone={ completeFlow }
				/>
			) }
			{ 'reactivate' === modal?.kind && (
				<ReactivateFlow
					subscription={ modal.subscription }
					// The payment link goes to the subscription's billing email, which
					// for a gift is the purchaser's, not the reader's. With none on
					// file the server sends nothing, so there's no address to name.
					email={ modal.subscription.billingEmail }
					// The same buyer RefundFlow names: a gift's payment link is theirs.
					payerName={ giftPayerName( modal.subscription ) }
					actions={ subscriptionActions }
					onClose={ () => setModal( null ) }
					onDone={ completeFlow }
				/>
			) }

			{ snackbar && (
				<div className={ `newspack-subscribers__snackbar${ snackbar.isError ? ' newspack-subscribers__snackbar--error' : '' }` }>
					{ /* A refusal is announced assertively: it interrupts, because the
					     admin's action did not happen and the message self-dismisses. */ }
					<Snackbar key={ snackbar.id } politeness={ snackbar.isError ? 'assertive' : 'polite' } onRemove={ () => setSnackbar( null ) }>
						{ snackbar.message }
					</Snackbar>
				</div>
			) }
		</div>
	);
}
