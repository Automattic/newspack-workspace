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
 * Each individual subscription card carries its per-status money actions in
 * the "more" menu — change plan and change payment method for a live plan,
 * refund/cancel where there is a payment to give back — and the saved cards
 * are listed below the subscriptions with their Default/Expired state. Every
 * mutation is awaited and followed by a profile refetch (the wizard's write
 * convention, see data/use-group.js on the group-detail slice): the server
 * recalculates totals and can refuse, so the response is the truth. On-hold
 * recovery (reactivate) and resubscribe are separate workstreams and stay
 * absent rather than present-but-inert.
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

/**
 * Internal dependencies.
 */
import { Button, Card, Divider, Grid, Notice, Router, SectionHeader, Waiting } from '../../../../packages/components/src';
import './style.scss';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import SubscriptionCard from '../components/SubscriptionCard';
import PaymentMethodsList, { cardLabel } from '../components/PaymentMethodsList';
import ChangePaymentMethodFlow from '../flows/ChangePaymentMethodFlow';
import PlanChangeFlow from '../flows/PlanChangeFlow';
import RefundFlow from '../flows/RefundFlow';
import { useSubscriber } from '../data/use-subscriber';
import { usePaymentActions, canChangePaymentMethod } from '../data/use-payments';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { useWizardNode } from '../use-portals';
import { billingText, fmtDate, orDash, scheduleRow } from '../format';
import { GROUP_LABEL, GROUP_LABEL_PLURAL } from '../labels';
import { groupDetailHref, isInternalHashPath } from '../links';
import { STATUS_LABELS, STATUS_BADGE_LEVEL, statusRank, displayStatuses } from '../status';

const { useParams, useLocation } = Router;

// Cancelled subscriptions are folded away behind a "View more" toggle whenever
// the reader still holds a live plan — a past cancellation next to a current
// plan is history, not status. A fully churned reader keeps everything visible,
// since hiding it would leave the profile looking empty. This mirrors the
// Subscription column's rule in SubscriberList (see the SOURCE OF TRUTH note on
// displayStatuses in status.js).
const HIDE_CANCELLED_WHEN_LIVE = true;

/**
 * Row of a two-column section: header on the left, content on the right.
 *
 * @param {Object}  props               Component props.
 * @param {string}  props.title         Section title.
 * @param {string}  [props.description] Section description.
 * @param {*}       props.children      Section content.
 * @param {boolean} [props.showDivider] Whether to close the section with a rule.
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

/**
 * The seat line under a group's name, e.g. "3 of 5 seats".
 *
 * A seat limit of 0 means the group is uncapped, so there is no denominator to
 * count against — see GroupList, which words the same fact for its table.
 *
 * @param {Object} group A group entry.
 * @return {string} The seat subline.
 */
const seatsSubline = group => {
	const used = group.members || 0;
	if ( group.seatLimit > 0 ) {
		// translators: 1: seats in use, 2: the group's seat limit.
		return sprintf( __( '%1$d of %2$d seats', 'newspack-plugin' ), used, group.seatLimit );
	}
	// translators: %d is the number of seats in use in an uncapped group.
	return sprintf( _n( '%d of unlimited seats', '%d of unlimited seats', used, 'newspack-plugin' ), used );
};

/**
 * The status badge(s) for one subscription or group.
 *
 * @param {string} status The mapped status.
 * @return {Array} Badge descriptors for SubscriptionCard.
 */
const statusBadges = status => ( STATUS_LABELS[ status ] ? [ { label: STATUS_LABELS[ status ], level: STATUS_BADGE_LEVEL[ status ] } ] : [] );

export default function PersonProfile() {
	const { id } = useParams();
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

	const { subscriber, loading, error, notFound, reload } = useSubscriber( id );

	// The money flows: which modal is open (null for none), the snackbar shown
	// after one completes, and the write calls the modals run. The snackbar's
	// `id` makes a repeated identical message a new render (so it re-announces
	// and its timer restarts), and `isError` styles a refusal apart from a
	// confirmation.
	const [ modal, setModal ] = useState( null );
	const [ snackbar, setSnackbar ] = useState( null );
	const paymentActions = usePaymentActions( id );

	const showSnackbar = ( message, isError = false ) => setSnackbar( { message, isError, id: Date.now() } );

	// A lone Snackbar (outside a SnackbarList) never dismisses itself, so a
	// success toast would sit there until clicked.
	useEffect( () => {
		if ( ! snackbar ) {
			return;
		}
		const timer = setTimeout( () => setSnackbar( null ), 10000 );
		return () => clearTimeout( timer );
	}, [ snackbar ] );

	// Every flow ends the same way: close the modal, tell the admin what
	// happened, and refetch the profile so the screen renders the server's
	// truth rather than the request's intent.
	const completeFlow = message => {
		setModal( null );
		showSnackbar( message );
		reload();
	};

	// Make-default is a single non-destructive click, so it runs without a
	// confirmation modal; a refusal (e.g. the server refusing an expired card)
	// surfaces in the snackbar with the server's own message.
	const makeDefault = async pm => {
		try {
			await paymentActions.setDefaultPaymentMethod( pm.id );
			// translators: %s is a card label (e.g. "Visa ending in 4242").
			completeFlow( sprintf( __( '%s is now the default payment method.', 'newspack-plugin' ), cardLabel( pm ) ) );
		} catch ( e ) {
			showSnackbar( e?.message || __( 'Something went wrong.', 'newspack-plugin' ), true );
		}
	};

	// A 128px source feeds the 64px header avatar (2x for high-DPR displays),
	// resolved through the same endpoint the lists use.
	const avatarEmails = useMemo( () => ( subscriber?.email ? [ subscriber.email ] : [] ), [ subscriber ] );
	const { avatars } = useAvatars( avatarEmails, { size: 128 } );
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
		// Subscribers. Both crumbs stay clickable so the trail is a way back.
		const originatedInGroups = backNav.startsWith( '#/groups' );
		setHeaderData( {
			backNav,
			sectionName: [
				{ label: originatedInGroups ? GROUP_LABEL_PLURAL : __( 'Subscribers', 'newspack-plugin' ), url: backNav },
				{ label: subscriber.name },
			],
			sectionTitle: subscriber.name,
			badges: headlineStatuses.map( status => ( { label: STATUS_LABELS[ status ], level: STATUS_BADGE_LEVEL[ status ] } ) ),
			// A newline-joined string, not markup: SectionHeader renders a
			// description inside a `<p>`, so a stack of divs would be invalid
			// nesting. The wizard stylesheet sets `white-space: pre-line` on that
			// paragraph for exactly this, so the lines break where they read.
			sectionDescription: [
				subscriber.email,
				subscriber.memberSince
					? // translators: %s is a date.
					  sprintf( __( 'Subscriber since %s', 'newspack-plugin' ), fmtDate( subscriber.memberSince ) )
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
						{ label: __( 'Last payment', 'newspack-plugin' ), value: orDash( fmtDate( group.lastPayment ) ) },
						scheduleRow( group ),
				  ]
				: [
						{ label: __( 'Joined', 'newspack-plugin' ), value: orDash( fmtDate( group.joinedAt ) ) },
						{
							label: __( 'Owner', 'newspack-plugin' ),
							value: group.owner?.name || __( 'Unknown', 'newspack-plugin' ),
						},
				  ];
			const href = groupDetailHref( group );
			return {
				key: `group-${ group.id }`,
				status: group.status,
				date: group.createdAt,
				node: (
					<SubscriptionCard
						key={ `group-${ group.id }` }
						title={ group.plan }
						titleSuffix={ `(${ GROUP_LABEL })` }
						titleHref={ href || undefined }
						// translators: 1: the group label, 2: the group name.
						titleLabel={ href ? sprintf( __( 'View %1$s: %2$s', 'newspack-plugin' ), GROUP_LABEL, group.plan ) : undefined }
						badges={ statusBadges( group.status ) }
						subline={ seatsSubline( group ) }
						rows={ rows }
						// No per-status "more" menu yet: the group card's title already
						// links to the group, and the money/management actions that would
						// fill this menu are Workstreams D/E/F. A menu whose only item
						// duplicates the title link would just add a redundant tab stop.
					/>
				),
			};
		} );

		const individualCards = ( subscriber.subscriptions || [] ).map( subscription => {
			const name = subscription.plan || __( '(Subscription)', 'newspack-plugin' );
			const isActive = 'active' === subscription.status;
			const isLive = isActive || 'on-hold' === subscription.status;
			const menuActions = [];
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
			// and there is another usable card to switch to.
			if ( isLive && canChangePaymentMethod( subscription, subscriber.paymentMethods ) ) {
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
							{ label: __( 'Last payment', 'newspack-plugin' ), value: orDash( fmtDate( subscription.lastPayment ) ) },
							scheduleRow( subscription ),
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
	const collapsedCards = HIDE_CANCELLED_WHEN_LIVE && liveCards.length > 0 ? liveCards : cards;
	const hiddenCount = cards.length - collapsedCards.length;
	const visibleCards = showCancelled ? cards : collapsedCards;

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
		return (
			<Notice isError noticeText={ __( 'This subscriber could not be found. They may have been deleted.', 'newspack-plugin' ) }>
				<Button variant="link" href={ backNav }>
					{ __( 'Back to the list', 'newspack-plugin' ) }
				</Button>
			</Notice>
		);
	}

	// A failed read must not read as "this person has no subscriptions".
	if ( error || ! subscriber ) {
		return (
			// translators: %s is an error message.
			<Notice isError noticeText={ sprintf( __( 'Could not load this subscriber: %s', 'newspack-plugin' ), error ) }>
				<Button variant="link" onClick={ reload }>
					{ __( 'Retry', 'newspack-plugin' ) }
				</Button>
			</Notice>
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

			<Row title={ __( 'Subscriptions', 'newspack-plugin' ) }>
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
								// "View N more" with what it reveals (WCAG 2.5.3 wants the
								// visible label to be a substring of the accessible name).
								// When expanded, the visible "View less" is unambiguous on
								// its own, so no override — the visible text is the name.
								aria-label={
									showCancelled
										? undefined
										: sprintf(
												// translators: %d is the number of hidden cancelled subscriptions.
												_n(
													'View %d more cancelled subscription',
													'View %d more cancelled subscriptions',
													hiddenCount,
													'newspack-plugin'
												),
												hiddenCount
										  )
								}
							>
								{ showCancelled
									? __( 'View less', 'newspack-plugin' )
									: // translators: %d is the number of hidden cancelled subscriptions.
									  sprintf( __( 'View %d more', 'newspack-plugin' ), hiddenCount ) }
							</Button>
						</HStack>
					) }
				</VStack>
			</Row>

			<Row
				title={ __( 'Payment methods', 'newspack-plugin' ) }
				description={ __( 'The cards on file for this subscriber. Renewals fall back to the default.', 'newspack-plugin' ) }
				showDivider={ false }
			>
				<PaymentMethodsList paymentMethods={ subscriber.paymentMethods || [] } onMakeDefault={ makeDefault } />
			</Row>

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
					actions={ paymentActions }
					onClose={ () => setModal( null ) }
					onDone={ completeFlow }
				/>
			) }

			{ snackbar && (
				<div className={ `newspack-subscribers__snackbar${ snackbar.isError ? ' newspack-subscribers__snackbar--error' : '' }` }>
					<Snackbar key={ snackbar.id } onRemove={ () => setSnackbar( null ) }>
						{ snackbar.message }
					</Snackbar>
				</div>
			) }
		</div>
	);
}
