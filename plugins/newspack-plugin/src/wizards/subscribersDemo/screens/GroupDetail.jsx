/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * L1 — Group detail (admin management).
 *
 * The publisher-facing counterpart to the owner My Account group page. Reuses
 * the person-profile two-column layout. Members and Invitations are sortable
 * DataViews tables; admins can invite on behalf of the owner, remove members,
 * manage invites, adjust the seat limit and reassign ownership.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import { Dropdown, MenuGroup, MenuItem, __experimentalHStack as HStack, Notice, Snackbar } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Badge, Button, DataViews, Divider, Router } from '../../../../packages/components/src';
import './style.scss';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { getSubscriberById, getStoredSubscriber, setStoredSubscriber } from '../data/mock-subscribers';
import {
	getGroupById,
	setStoredGroup,
	seatsUsed,
	inviteCapacity,
	isGroupActive,
	isGroupManageable,
	isInviteExpired,
	hasActiveInviteLink,
	GROUP_STATUS_LABELS,
	GROUP_STATUS_BADGE_LEVEL,
} from '../data/mock-groups';
import { GROUP_LABEL, GROUP_LABEL_PLURAL } from '../labels';
import { fmtDate } from '../format';
import { useNoticesPortal } from '../use-portals';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { NoticesPanel, groupOnHoldNotice } from './SubscriberNotices';

import AcceptInviteFlow from '../flows/AcceptInviteFlow';
import AddMembersFlow from '../flows/AddMembersFlow';
import InviteMemberFlow from '../flows/InviteMemberFlow';
import RemoveMemberFlow from '../flows/RemoveMemberFlow';
import AdjustSeatsFlow from '../flows/AdjustSeatsFlow';
import MakeOwnerFlow from '../flows/MakeOwnerFlow';
import RegenerateLinkFlow from '../flows/RegenerateLinkFlow';
import DisableLinkFlow from '../flows/DisableLinkFlow';
import ResendInviteFlow from '../flows/ResendInviteFlow';
import CancelInviteFlow from '../flows/CancelInviteFlow';
import SubscriptionDetailsDrawer from '../flows/SubscriptionDetailsDrawer';
import RefundFlow from '../flows/RefundFlow';
import PlanChangeFlow from '../flows/PlanChangeFlow';
import GuidedFixFlow from '../flows/GuidedFixFlow';
import { hasUsableCard } from '../flows/free-access';
import { buildReactivation, buildFreeReactivation, buildPaymentLinkOrder, reactivatedMessage } from '../flows/subscription-actions';
import { latestOrderDate } from '../data/orders';

const { useParams, useHistory } = Router;

const today = () => new Date().toISOString().slice( 0, 10 );

const MEMBERS_VIEW = {
	type: 'table',
	page: 1,
	perPage: 10,
	// Owner first by default (role 'owner' sorts ahead of 'member' descending).
	sort: { field: 'role', direction: 'desc' },
	search: '',
	fields: [ 'role', 'joinedAt' ],
	filters: [],
	layout: {},
	titleField: 'name',
};

const INVITES_VIEW = {
	type: 'table',
	page: 1,
	perPage: 10,
	sort: { field: 'sentAt', direction: 'desc' },
	search: '',
	fields: [ 'status', 'sentAt' ],
	filters: [],
	layout: {},
	titleField: 'label',
};

function GroupDetailView() {
	const { id } = useParams();
	const history = useHistory();
	const [ group, setGroup ] = useState( () => getGroupById( id ) );
	const [ snackbar, setSnackbar ] = useState( null );
	const [ modal, setModal ] = useState( null );
	const [ membersView, setMembersView ] = useState( MEMBERS_VIEW );
	const [ invitesView, setInvitesView ] = useState( INVITES_VIEW );
	const noticesNode = useNoticesPortal();

	useEffect( () => {
		if ( group ) {
			setStoredGroup( group.id, group );
		}
	}, [ group ] );

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const owner = group ? getSubscriberById( group.ownerId ) : null;
	const canInvite = group && isGroupActive( group ) && inviteCapacity( group ) > 0;

	useEffect( () => {
		if ( ! group ) {
			return;
		}
		setHeaderData( {
			backNav: '#/groups',
			// Lead with the subscription, not the owner, so this view reads
			// differently from the person-centric subscriber profile. The breadcrumb
			// leaf matches the title for a consistent page identity; the owner name
			// trails it in parentheses.
			sectionName: (
				<>
					{ `${ GROUP_LABEL_PLURAL } / ${ group.plan }` }
					{ owner?.name && (
						<>
							{ ' ' }
							<span
								className="newspack-subscribers-demo__header-count"
								aria-label={ sprintf( __( 'Owned by %s', 'newspack-plugin' ), owner.name ) }
							>
								{ `(${ owner.name })` }
							</span>
						</>
					) }
				</>
			),
			// Mirror the breadcrumb: fold the owner name into the title in muted
			// parentheses. A function title renders its own badge (SectionHeader
			// only auto-renders the `badges` array for string titles).
			sectionTitle: () => (
				<>
					<span>
						{ group.plan }
						{ owner?.name && (
							<>
								{ ' ' }
								<span
									className="newspack-subscribers-demo__header-count"
									aria-label={ sprintf( __( 'Owned by %s', 'newspack-plugin' ), owner.name ) }
								>
									{ `(${ owner.name })` }
								</span>
							</>
						) }
					</span>
					<Badge text={ GROUP_STATUS_LABELS[ group.status ] } level={ GROUP_STATUS_BADGE_LEVEL[ group.status ] } />
				</>
			),
			actions: [
				{ type: 'more', label: __( 'View subscription', 'newspack-plugin' ), action: () => setModal( { kind: 'view-subscription' } ) },
				{ type: 'more', label: __( 'View in WooCommerce', 'newspack-plugin' ), action: () => {} },
			],
		} );
	}, [ group, owner, setHeaderData, history ] );

	const closeModal = () => setModal( null );

	const applyMutation = ( { message, mutate } ) => {
		if ( mutate ) {
			setGroup( prev => mutate( prev ) );
		}
		if ( message ) {
			setSnackbar( { message } );
		}
	};

	// Subscription flows (refund/cancel, plan change) return the same group
	// descriptors the person profile handles; here the group on screen is the
	// target, so they fold straight into setGroup.
	const completeFlow = ( { message, mutate, groupCancel, groupChange } ) => {
		if ( groupCancel ) {
			setGroup( prev => ( { ...prev, status: 'cancelled', nextBillingDate: null } ) );
		}
		if ( groupChange ) {
			setGroup( prev => ( {
				...prev,
				plan: groupChange.plan,
				cadence: groupChange.cadence,
				amount: groupChange.amount,
				seatLimit: groupChange.seatLimit,
			} ) );
		}
		applyMutation( { message, mutate } );
		setModal( null );
	};

	// The group is billed to its owner, so reactivation/payment-link events are
	// logged onto the owner's stored record (where their billing history reads).
	const addOwnerOrder = order => {
		const base = getStoredSubscriber( group.ownerId ) || getSubscriberById( group.ownerId );
		if ( ! base ) {
			return;
		}
		setStoredSubscriber( group.ownerId, { ...base, orders: [ order, ...( base.orders || [] ) ] } );
	};

	// Apply a shared reactivation outcome to the group on screen: fold the fresh
	// status/billing fields into setGroup and log the order on the owner. A
	// cancelled group is being resubscribed; an on-hold one reactivated — the
	// snackbar verb follows the group's prior status.
	const applyReactivation = ( { fields, order }, notify ) => {
		const verb = group.status === 'cancelled' ? 'resubscribe' : 'reactivate';
		addOwnerOrder( order );
		setGroup( prev => ( { ...prev, ...fields } ) );
		setSnackbar( { message: reactivatedMessage( group.plan, notify, verb ) } );
		setModal( null );
	};

	const reactivateGroupCharge = notify => applyReactivation( buildReactivation( group, true ), notify );
	const reactivateGroupFree = ( freeCyclesRemaining, notify ) => applyReactivation( buildFreeReactivation( group, freeCyclesRemaining ), notify );

	// Email the owner a link to update their card; the group stays on hold until
	// they pay.
	const sendGroupPaymentLink = () => {
		addOwnerOrder( buildPaymentLinkOrder( group ) );
		setSnackbar( { message: sprintf( __( 'Payment link sent to %s.', 'newspack-plugin' ), owner?.email ) } );
		setModal( null );
	};

	// Copy the group's shareable join link. Offered as a header button beside
	// Invite member. The link is a single persistent entry; if none exists yet,
	// generating it is implicit in the first copy (mirroring the owner flow).
	const copyInviteLink = () => {
		try {
			window.navigator?.clipboard?.writeText( `https://example.com/join/${ group.id }` );
		} catch ( e ) {
			// Clipboard unavailable in the prototype — ignore.
		}
		const exists = hasActiveInviteLink( group );
		applyMutation( {
			message: __( 'Invite link copied to clipboard.', 'newspack-plugin' ),
			mutate: exists
				? undefined
				: g => ( {
						...g,
						invites: [
							...( g.invites || [] ),
							{ id: `inv_${ g.id }_link_${ Date.now() }`, type: 'link', status: 'active', createdAt: today() },
						],
				  } ),
		} );
	};

	// Members table rows + fields.
	const memberRows = useMemo( () => {
		if ( ! group ) {
			return [];
		}
		return ( group.members || [] ).map( m => {
			const sub = getSubscriberById( m.subscriberId );
			return {
				id: m.subscriberId,
				name: sub?.name || m.name || __( 'Unknown subscriber', 'newspack-plugin' ),
				email: sub?.email || m.email || '',
				role: m.role,
				joinedAt: m.joinedAt,
			};
		} );
	}, [ group ] );

	// Resolve member avatars by email, keyed by subscriber id, for the table.
	const memberEmails = useMemo( () => memberRows.map( m => m.email ).filter( Boolean ), [ memberRows ] );
	const { avatars: memberAvatarsByEmail } = useAvatars( memberEmails );
	const memberAvatars = useMemo( () => {
		const byId = {};
		memberRows.forEach( m => {
			byId[ m.id ] = m.email ? memberAvatarsByEmail[ m.email ] : undefined;
		} );
		return byId;
	}, [ memberRows, memberAvatarsByEmail ] );

	const memberFields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Member', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => `${ item.name } ${ item.email }`,
				render: ( { item } ) => {
					const details = (
						<div>
							<button
								type="button"
								className="newspack-subscribers-demo__rowlink"
								onClick={ () => history.push( `/profile/${ item.id }?from=${ encodeURIComponent( `#/group/${ group.id }` ) }` ) }
							>
								{ item.name }
							</button>
							<div className="newspack-subscribers-demo__email">{ item.email }</div>
						</div>
					);
					if ( ! SHOW_AVATARS || ! memberAvatars[ item.id ] ) {
						return details;
					}
					return (
						<HStack spacing={ 3 } justify="flex-start" alignment="center">
							<img className="newspack-subscribers-demo__avatar" src={ memberAvatars[ item.id ] } alt="" width={ 32 } height={ 32 } />
							{ details }
						</HStack>
					);
				},
			},
			{
				id: 'role',
				label: __( 'Role', 'newspack-plugin' ),
				getValue: ( { item } ) => item.role,
				render: ( { item } ) =>
					item.role === 'owner' ? (
						<Badge level="info" text={ __( 'Owner', 'newspack-plugin' ) } />
					) : (
						<Badge text={ __( 'Member', 'newspack-plugin' ) } />
					),
				enableSorting: true,
			},
			{
				id: 'joinedAt',
				label: __( 'Member since', 'newspack-plugin' ),
				getValue: ( { item } ) => item.joinedAt,
				render: ( { item } ) => <span>{ fmtDate( item.joinedAt ) }</span>,
			},
		],
		[ history, group, memberAvatars ]
	);

	const memberActions = useMemo(
		() => [
			{
				id: 'view-profile',
				label: __( 'View profile', 'newspack-plugin' ),
				callback: items => history.push( `/profile/${ items[ 0 ].id }?from=${ encodeURIComponent( `#/group/${ group.id }` ) }` ),
			},
			{
				id: 'make-owner',
				label: __( 'Make owner', 'newspack-plugin' ),
				isEligible: item => item.role !== 'owner' && isGroupManageable( group ),
				callback: items => setModal( { kind: 'make-owner', member: { subscriberId: items[ 0 ].id }, memberName: items[ 0 ].name } ),
			},
			{
				id: 'remove-member',
				label: items => _n( 'Remove member', 'Remove members', items.length, 'newspack-plugin' ),
				isDestructive: true,
				supportsBulk: true,
				isEligible: item => item.role !== 'owner' && isGroupManageable( group ),
				callback: items => setModal( { kind: 'remove', members: items.map( i => ( { subscriberId: i.id, name: i.name } ) ) } ),
			},
		],
		[ group, history ]
	);

	const { data: processedMembers, paginationInfo: membersPagination } = useMemo(
		() => filterSortAndPaginate( memberRows, membersView, memberFields ),
		[ memberRows, membersView, memberFields ]
	);

	// Invitations table rows + fields. Email invites only — the shareable link is
	// never a row; it's managed from the section header.
	const inviteRows = useMemo( () => {
		if ( ! group ) {
			return [];
		}
		return ( group.invites || [] )
			.filter( inv => inv.type === 'email' )
			.map( inv => ( {
				id: inv.id,
				label: inv.email,
				status: isInviteExpired( inv ) ? 'expired' : inv.status,
				sentAt: inv.sentAt,
			} ) );
	}, [ group ] );

	const inviteFields = useMemo(
		() => [
			{
				id: 'label',
				label: __( 'Sent to', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.label,
				render: ( { item } ) => <span>{ item.label }</span>,
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				getValue: ( { item } ) => item.status,
				render: ( { item } ) =>
					// Email invites stay pending until accepted, then lapse to expired
					// 30 days after they were sent.
					item.status === 'expired' ? (
						<Badge level="error" text={ __( 'Expired', 'newspack-plugin' ) } />
					) : (
						<Badge level="warning" text={ __( 'Pending', 'newspack-plugin' ) } />
					),
				enableSorting: false,
			},
			{
				id: 'sentAt',
				label: __( 'Sent', 'newspack-plugin' ),
				getValue: ( { item } ) => item.sentAt,
				render: ( { item } ) => <span>{ fmtDate( item.sentAt ) }</span>,
			},
		],
		[]
	);

	const inviteActions = useMemo(
		() => [
			{
				id: 'accept-on-behalf',
				label: __( 'Accept on behalf', 'newspack-plugin' ),
				supportsBulk: true,
				// Pending invites only: an expired invite holds no seat, so accepting
				// it would need a fresh capacity check — resend it first instead.
				isEligible: item => item.status === 'pending' && isGroupActive( group ),
				callback: items => setModal( { kind: 'accept-invites', invites: items } ),
			},
			{
				id: 'resend-invite',
				label: __( 'Resend invite', 'newspack-plugin' ),
				// Resending is only offered on an active group; it refreshes the sent
				// date, which clears an expired invite back to pending.
				isEligible: () => isGroupActive( group ),
				callback: items => setModal( { kind: 'resend-invite', invite: items[ 0 ] } ),
			},
			{
				id: 'cancel-invite',
				label: __( 'Cancel invite', 'newspack-plugin' ),
				isDestructive: true,
				callback: items => setModal( { kind: 'cancel-invite', invite: items[ 0 ] } ),
			},
		],
		[ group ]
	);

	const { data: processedInvites, paginationInfo: invitesPagination } = useMemo(
		() => filterSortAndPaginate( inviteRows, invitesView, inviteFields ),
		[ inviteRows, invitesView, inviteFields ]
	);

	if ( ! group ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ sprintf( __( '%s not found.', 'newspack-plugin' ), GROUP_LABEL ) }
			</Notice>
		);
	}

	// Owner-paid last successful charge for the group, shown in the on-hold notice.
	const ownerLastCharge = latestOrderDate( owner?.orders, group.id, 'Subscription payment' );
	const goToOwner = () => history.push( `/profile/${ group.ownerId }?from=${ encodeURIComponent( `#/group/${ group.id }` ) }` );
	// On-hold defers to the owner's profile, where Reactivate lives. A cancelled
	// group shows no notice: the header badge and disabled actions already say so.
	const groupNotices = [ group.status === 'on-hold' && groupOnHoldNotice( { plan: group.plan, lastCharged: fmtDate( ownerLastCharge ) } ) ]
		.filter( Boolean )
		.map( notice => ( { ...notice, action: { label: notice.actionLabel, onClick: goToOwner } } ) );

	return (
		<div className="newspack-subscribers-demo__profile">
			<NoticesPanel noticesNode={ noticesNode } notices={ groupNotices } />

			<HStack className="newspack-subscribers-demo__section-head" justify="space-between" alignment="center">
				<HStack spacing={ 2 } justify="flex-start" alignment="baseline" expanded={ false }>
					<h2 className="newspack-subscribers-demo__section-title">{ __( 'Members', 'newspack-plugin' ) }</h2>
					<span
						className="newspack-subscribers-demo__seat-count"
						aria-label={ sprintf( __( '%1$d of %2$d seats used', 'newspack-plugin' ), seatsUsed( group ), group.seatLimit ) }
					>
						{ sprintf( __( '(%1$d of %2$d)', 'newspack-plugin' ), seatsUsed( group ), group.seatLimit ) }
					</span>
				</HStack>
				<HStack spacing={ 2 } justify="flex-end" expanded={ false }>
					<Button variant="tertiary" size="compact" onClick={ () => setModal( { kind: 'seats' } ) } disabled={ ! isGroupActive( group ) }>
						{ __( 'Adjust seats', 'newspack-plugin' ) }
					</Button>
					<Dropdown
						placement="bottom-end"
						renderToggle={ ( { isOpen, onToggle } ) => (
							<Button
								variant="secondary"
								size="compact"
								onClick={ onToggle }
								aria-expanded={ isOpen }
								disabled={ ! isGroupActive( group ) }
							>
								{ __( 'Add members', 'newspack-plugin' ) }
							</Button>
						) }
						renderContent={ ( { onClose } ) => (
							<MenuGroup>
								<MenuItem
									disabled={ ! canInvite }
									onClick={ () => {
										onClose();
										setModal( { kind: 'add-members' } );
									} }
								>
									{ __( 'Add directly', 'newspack-plugin' ) }
								</MenuItem>
								<MenuItem
									disabled={ ! canInvite }
									onClick={ () => {
										onClose();
										setModal( { kind: 'invite' } );
									} }
								>
									{ __( 'Invite by email', 'newspack-plugin' ) }
								</MenuItem>
								<MenuItem
									onClick={ () => {
										onClose();
										copyInviteLink();
									} }
								>
									{ __( 'Copy invite link', 'newspack-plugin' ) }
								</MenuItem>
								{ hasActiveInviteLink( group ) && (
									<MenuItem
										onClick={ () => {
											onClose();
											setModal( { kind: 'regenerate-link' } );
										} }
									>
										{ __( 'Regenerate invite link', 'newspack-plugin' ) }
									</MenuItem>
								) }
								{ hasActiveInviteLink( group ) && (
									<MenuItem
										isDestructive
										onClick={ () => {
											onClose();
											setModal( { kind: 'disable-link' } );
										} }
									>
										{ __( 'Disable invite link', 'newspack-plugin' ) }
									</MenuItem>
								) }
							</MenuGroup>
						) }
					/>
				</HStack>
			</HStack>
			<DataViews
				className="newspack-subscribers-demo__members-dataviews"
				data={ processedMembers }
				fields={ memberFields }
				view={ membersView }
				onChangeView={ setMembersView }
				actions={ memberActions }
				paginationInfo={ membersPagination }
				defaultLayouts={ { table: {} } }
				getItemId={ item => item.id }
				search
			/>

			{ inviteRows.length > 0 && (
				<>
					<Divider alignment="full-width" variant="tertiary" />

					<h2 className="newspack-subscribers-demo__section-head newspack-subscribers-demo__section-title">
						{ __( 'Invitations', 'newspack-plugin' ) }
					</h2>
					<DataViews
						data={ processedInvites }
						fields={ inviteFields }
						view={ invitesView }
						onChangeView={ setInvitesView }
						actions={ inviteActions }
						paginationInfo={ invitesPagination }
						defaultLayouts={ { table: {} } }
						getItemId={ item => item.id }
						search={ false }
					/>
				</>
			) }

			{ modal?.kind === 'add-members' && <AddMembersFlow group={ group } onClose={ closeModal } onComplete={ completeFlow } /> }
			{ modal?.kind === 'invite' && <InviteMemberFlow group={ group } onClose={ closeModal } onComplete={ completeFlow } /> }
			{ modal?.kind === 'remove' && (
				<RemoveMemberFlow group={ group } members={ modal.members } onClose={ closeModal } onComplete={ completeFlow } />
			) }
			{ modal?.kind === 'seats' && <AdjustSeatsFlow group={ group } onClose={ closeModal } onComplete={ completeFlow } /> }
			{ modal?.kind === 'make-owner' && (
				<MakeOwnerFlow
					group={ group }
					member={ modal.member }
					memberName={ modal.memberName }
					onClose={ closeModal }
					onComplete={ completeFlow }
				/>
			) }
			{ modal?.kind === 'regenerate-link' && <RegenerateLinkFlow group={ group } onClose={ closeModal } onComplete={ completeFlow } /> }
			{ modal?.kind === 'disable-link' && <DisableLinkFlow group={ group } onClose={ closeModal } onComplete={ completeFlow } /> }
			{ modal?.kind === 'resend-invite' && (
				<ResendInviteFlow group={ group } invite={ modal.invite } onClose={ closeModal } onComplete={ completeFlow } />
			) }
			{ modal?.kind === 'cancel-invite' && (
				<CancelInviteFlow group={ group } invite={ modal.invite } onClose={ closeModal } onComplete={ completeFlow } />
			) }
			{ modal?.kind === 'accept-invites' && <AcceptInviteFlow invites={ modal.invites } onClose={ closeModal } onComplete={ completeFlow } /> }
			{ modal?.kind === 'view-subscription' && (
				<SubscriptionDetailsDrawer
					group={ group }
					owner={ owner }
					onViewOwner={ goToOwner }
					onClose={ closeModal }
					onChangePlan={ () => setModal( { kind: 'plan' } ) }
					onRefundCancel={ () => setModal( { kind: 'refund' } ) }
					onReactivate={ () => setModal( { kind: 'guided' } ) }
					onResubscribe={ () => setModal( { kind: 'guided' } ) }
				/>
			) }
			{ modal?.kind === 'plan' && <PlanChangeFlow group={ group } onClose={ closeModal } onComplete={ completeFlow } /> }
			{ modal?.kind === 'refund' && <RefundFlow group={ group } subscriber={ owner } onClose={ closeModal } onComplete={ completeFlow } /> }
			{ modal?.kind === 'guided' && (
				<GuidedFixFlow
					modalTitle={
						group.status === 'cancelled' ? __( 'Resubscribe', 'newspack-plugin' ) : __( 'Reactivate subscription', 'newspack-plugin' )
					}
					verb={ group.status === 'cancelled' ? 'resubscribe' : 'reactivate' }
					target={ group }
					email={ owner?.email }
					canCharge={ hasUsableCard( owner ) }
					onClose={ closeModal }
					onReactivateCharge={ reactivateGroupCharge }
					onSendPaymentLink={ sendGroupPaymentLink }
					onReactivateFree={ reactivateGroupFree }
				/>
			) }

			{ snackbar && (
				<div className="newspack-subscribers-demo__snackbar">
					<Snackbar onRemove={ () => setSnackbar( null ) }>{ snackbar.message }</Snackbar>
				</div>
			) }
		</div>
	);
}

// Remount on id change (key) so per-id state — group, modal, snackbar — resets
// cleanly instead of relying on a reset effect that flashed the previous group's
// data for a frame before clearing.
export default function GroupDetail() {
	const { id } = useParams();
	return <GroupDetailView key={ id } />;
}
