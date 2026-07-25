/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * L1 — Group detail (admin management).
 *
 * The publisher-facing counterpart to the owner's My Account group page. Members
 * and Invitations are sortable DataViews tables; an admin can invite on behalf of
 * the owner, add people directly, remove members, manage invitations, adjust the
 * seat limit and change who manages the group.
 *
 * THE ROLE MODEL IS ENFORCED SERVER-SIDE, NOT HERE. Owner-only may promote or
 * demote a manager (Group_Subscription::user_can_manage_roles()), and a manager
 * may never remove a peer manager (::can_actor_remove_member()). This screen
 * mirrors those rules so it does not offer what would be refused, but an admin
 * reaching this screen has already passed `manage_options`, and every write is
 * re-authorised by the endpoint. Nothing here is a security boundary.
 */

/**
 * WordPress dependencies.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import {
	Dropdown,
	MenuGroup,
	MenuItem,
	Snackbar,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Badge, Button, DataViews, Divider, Notice, Router, Waiting } from '../../../../packages/components/src';
import './style.scss';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { useGroup, useGroupActions } from '../data/use-group';
import { SHOW_AVATARS, useAvatars } from '../data/use-avatars';
import { fmtDate } from '../format';
import { GROUP_LABEL, GROUP_LABEL_PLURAL, ROLE_LABELS, ROLE_RANK } from '../labels';
import { STATUS_LABELS, STATUS_BADGE_LEVEL } from '../status';
import { seatCountText, seatsRemaining } from '../flows/capacity';

import AddMembersFlow from '../flows/AddMembersFlow';
import AdjustSeatsFlow from '../flows/AdjustSeatsFlow';
import CancelInviteFlow from '../flows/CancelInviteFlow';
import DisableLinkFlow from '../flows/DisableLinkFlow';
import InviteMemberFlow from '../flows/InviteMemberFlow';
import RegenerateLinkFlow from '../flows/RegenerateLinkFlow';
import RemoveMemberFlow from '../flows/RemoveMemberFlow';
import ResendInviteFlow from '../flows/ResendInviteFlow';
import SubscriptionDetailsDrawer from '../flows/SubscriptionDetailsDrawer';

const { useParams } = Router;

const MEMBERS_VIEW = {
	type: 'table',
	page: 1,
	perPage: 10,
	// Owner first, then managers, then members (ROLE_RANK ascending) — the order
	// the endpoint already returns them in.
	sort: { field: 'role', direction: 'asc' },
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
	titleField: 'email',
};

// Whether the group is live enough to accept member/invite changes, and whether it
// can take on new people. These gate the UI only — every write is re-authorised and
// state-gated server-side (409/404), so these never need to be a security boundary.
//
// They read the four-value status the endpoint already maps WCS onto
// (map_subscription_status), which is why they line up with the server despite
// looking simpler than is_subscription_manageable()/is_subscription_active():
//   - 'cancelled' collapses WCS cancelled + expired → both non-manageable. ✔
//   - 'active'    collapses WCS active + pending-cancel → both invite-active. ✔
//   - 'pending' / 'on-hold' are manageable but not active, matching the server. ✔
// The one state the client cannot distinguish is WCS `trash`: it maps to 'on-hold',
// so isManageable() reads true while the server refuses it. That is inert — the
// endpoint 409s — and unfixable here without a richer status field, since the map
// has already collapsed trash into on-hold before the client sees it.
const isManageable = group => group && 'cancelled' !== group.status;
const isActive = group => group && 'active' === group.status;

function GroupDetailView() {
	const { id } = useParams();
	const { group, loading, error, reload } = useGroup( id );
	const actions = useGroupActions( id );
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ modal, setModal ] = useState( null );
	const [ snackbar, setSnackbar ] = useState( null );
	const [ membersView, setMembersView ] = useState( MEMBERS_VIEW );
	const [ invitesView, setInvitesView ] = useState( INVITES_VIEW );

	const closeModal = () => setModal( null );

	// Every flow reports back through here. Mutations are awaited rather than
	// rendered optimistically (see data/use-group.js), so completing one refetches
	// the group and the screen re-renders from what the server actually stored.
	const onDone = useCallback(
		( message, options = {} ) => {
			if ( message ) {
				setSnackbar( { message } );
			}
			reload();
			if ( ! options.keepOpen ) {
				setModal( null );
			}
		},
		[ reload ]
	);

	const goToOwner = useCallback( () => {
		if ( group?.owner?.editUrl ) {
			window.location.href = group.owner.editUrl;
		}
	}, [ group ] );

	const memberRows = useMemo( () => group?.memberList || [], [ group ] );
	const memberEmails = useMemo( () => memberRows.map( member => member.email ).filter( Boolean ), [ memberRows ] );
	const { avatars: avatarsByEmail } = useAvatars( memberEmails );

	// Header: the subscription leads, with the owner trailing in parentheses, so
	// this view reads as being about the group rather than about a person.
	useEffect( () => {
		if ( ! group ) {
			return;
		}
		const ownerName = group.owner?.name;
		const withOwner = content => (
			<>
				{ content }
				{ ownerName && (
					<>
						{ ' ' }
						<span
							className="newspack-subscribers__header-count"
							aria-label={ sprintf( __( 'Owned by %s', 'newspack-plugin' ), ownerName ) }
						>
							{ `(${ ownerName })` }
						</span>
					</>
				) }
			</>
		);
		setHeaderData( {
			backNav: '#/groups',
			sectionName: withOwner( `${ GROUP_LABEL_PLURAL } / ${ group.plan }` ),
			// A function title renders its own badge: SectionHeader only auto-renders
			// the `badges` array for string titles.
			sectionTitle: () => (
				<>
					<span>{ withOwner( group.plan ) }</span>
					<Badge text={ STATUS_LABELS[ group.status ] } level={ STATUS_BADGE_LEVEL[ group.status ] } />
				</>
			),
			actions: [
				{ type: 'more', label: __( 'View subscription', 'newspack-plugin' ), action: () => setModal( { kind: 'view-subscription' } ) },
			],
		} );
	}, [ group, setHeaderData ] );

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
							<span>{ item.name }</span>
							<div className="newspack-subscribers__email">{ item.email }</div>
						</div>
					);
					if ( ! SHOW_AVATARS ) {
						return details;
					}
					const avatar = item.email ? avatarsByEmail[ item.email ] : undefined;
					return (
						<HStack spacing={ 3 } justify="flex-start" alignment="center">
							{ avatar ? (
								<img className="newspack-subscribers__avatar" src={ avatar } alt="" width={ 32 } height={ 32 } />
							) : (
								<span className="newspack-subscribers__avatar" aria-hidden="true" />
							) }
							{ details }
						</HStack>
					);
				},
			},
			{
				id: 'role',
				label: __( 'Role', 'newspack-plugin' ),
				// Rank, not the label: sorting the strings would slot Manager below
				// Member and put the owner in the middle.
				getValue: ( { item } ) => ROLE_RANK[ item.role ] ?? ROLE_RANK.member,
				render: ( { item } ) =>
					'member' === item.role ? (
						<span>{ ROLE_LABELS.member }</span>
					) : (
						<Badge level={ 'owner' === item.role ? 'success' : 'info' } text={ ROLE_LABELS[ item.role ] } />
					),
				enableSorting: true,
			},
			{
				id: 'joinedAt',
				label: __( 'Member since', 'newspack-plugin' ),
				getValue: ( { item } ) => item.joinedAt || '',
				// The owner holds no membership record, so they have no join date.
				render: ( { item } ) => <span>{ item.joinedAt ? fmtDate( item.joinedAt ) : '—' }</span>,
				enableSorting: true,
			},
		],
		[ avatarsByEmail ]
	);

	const memberActions = useMemo(
		() => [
			{
				id: 'view-profile',
				label: __( 'View profile', 'newspack-plugin' ),
				isEligible: item => !! item.editUrl,
				callback: items => {
					window.location.href = items[ 0 ].editUrl;
				},
			},
			{
				id: 'make-manager',
				label: __( 'Make manager', 'newspack-plugin' ),
				isEligible: item => 'member' === item.role && isManageable( group ),
				callback: async items => {
					try {
						await actions.setManagerRole( items[ 0 ].id, 'manager' );
						onDone( sprintf( __( '%s is now a manager.', 'newspack-plugin' ), items[ 0 ].name ) );
					} catch ( e ) {
						setSnackbar( { message: e?.message || __( 'Something went wrong.', 'newspack-plugin' ) } );
					}
				},
			},
			{
				id: 'remove-manager',
				label: __( 'Remove manager', 'newspack-plugin' ),
				isEligible: item => 'manager' === item.role && isManageable( group ),
				callback: async items => {
					try {
						await actions.setManagerRole( items[ 0 ].id, 'member' );
						onDone( sprintf( __( '%s is no longer a manager.', 'newspack-plugin' ), items[ 0 ].name ) );
					} catch ( e ) {
						setSnackbar( { message: e?.message || __( 'Something went wrong.', 'newspack-plugin' ) } );
					}
				},
			},
			{
				id: 'remove-member',
				label: items => _n( 'Remove member', 'Remove members', items.length, 'newspack-plugin' ),
				isDestructive: true,
				supportsBulk: true,
				// The owner can never be removed from their own group.
				isEligible: item => 'owner' !== item.role && isManageable( group ),
				callback: items => setModal( { kind: 'remove', members: items } ),
			},
		],
		[ group, actions, onDone ]
	);

	const { data: processedMembers, paginationInfo: membersPagination } = useMemo(
		() => filterSortAndPaginate( memberRows, membersView, memberFields ),
		[ memberRows, membersView, memberFields ]
	);

	const inviteRows = useMemo( () => group?.invites || [], [ group ] );

	const inviteFields = useMemo(
		() => [
			{
				id: 'email',
				label: __( 'Sent to', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.email,
				render: ( { item } ) => <span>{ item.email }</span>,
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				getValue: ( { item } ) => item.status,
				render: ( { item } ) =>
					'expired' === item.status ? (
						<Badge level="error" text={ __( 'Expired', 'newspack-plugin' ) } />
					) : (
						<Badge level="warning" text={ __( 'Pending', 'newspack-plugin' ) } />
					),
				enableSorting: false,
			},
			{
				id: 'sentAt',
				label: __( 'Sent', 'newspack-plugin' ),
				getValue: ( { item } ) => item.sentAt || '',
				render: ( { item } ) => <span>{ item.sentAt ? fmtDate( item.sentAt ) : '—' }</span>,
				enableSorting: true,
			},
		],
		[]
	);

	const inviteActions = useMemo(
		() => [
			{
				id: 'resend-invite',
				label: __( 'Resend invite', 'newspack-plugin' ),
				// Re-issuing an invitation is a new invitation, so it needs an active
				// group — the same gate the endpoint applies.
				isEligible: () => isActive( group ),
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

	if ( loading ) {
		return (
			<div className="newspack-subscribers__loading">
				<Waiting isCenter />
			</div>
		);
	}

	if ( error || ! group ) {
		return (
			<Notice isError noticeText={ error || sprintf( __( '%s not found.', 'newspack-plugin' ), GROUP_LABEL ) }>
				<Button variant="link" onClick={ reload }>
					{ __( 'Retry', 'newspack-plugin' ) }
				</Button>
			</Notice>
		);
	}

	const hasSeats = seatsRemaining( group ) > 0;
	const canInvite = isActive( group ) && hasSeats;
	const hasLink = !! group.inviteLink?.active;

	const copyInviteLink = async () => {
		try {
			// An existing link is copied as-is; if there is none, creating it is
			// implicit in the first copy, mirroring the owner's My Account flow.
			const url = hasLink ? group.inviteLink.url : ( await actions.generateInviteLink() )?.url;
			await window.navigator?.clipboard?.writeText( url );
			onDone( __( 'Invite link copied to clipboard.', 'newspack-plugin' ) );
		} catch ( e ) {
			setSnackbar( { message: e?.message || __( 'Could not copy the invite link.', 'newspack-plugin' ) } );
		}
	};

	return (
		<div className="newspack-subscribers__profile">
			<HStack className="newspack-subscribers__section-head" justify="space-between" alignment="center">
				<HStack spacing={ 2 } justify="flex-start" alignment="baseline" expanded={ false }>
					<h2 className="newspack-subscribers__section-title">{ __( 'Members', 'newspack-plugin' ) }</h2>
					<span className="newspack-subscribers__seat-count">{ `(${ seatCountText( group ) })` }</span>
				</HStack>
				<HStack spacing={ 2 } justify="flex-end" expanded={ false }>
					<Button variant="tertiary" size="compact" onClick={ () => setModal( { kind: 'seats' } ) } disabled={ ! isManageable( group ) }>
						{ __( 'Adjust seats', 'newspack-plugin' ) }
					</Button>
					<Dropdown
						placement="bottom-end"
						renderToggle={ ( { isOpen, onToggle } ) => (
							<Button variant="secondary" size="compact" onClick={ onToggle } aria-expanded={ isOpen } disabled={ ! isActive( group ) }>
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
								{ hasLink && (
									<MenuItem
										onClick={ () => {
											onClose();
											setModal( { kind: 'regenerate-link' } );
										} }
									>
										{ __( 'Regenerate invite link', 'newspack-plugin' ) }
									</MenuItem>
								) }
								{ hasLink && (
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
					<h2 className="newspack-subscribers__section-head newspack-subscribers__section-title">
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

			{ 'add-members' === modal?.kind && <AddMembersFlow group={ group } actions={ actions } onClose={ closeModal } onDone={ onDone } /> }
			{ 'invite' === modal?.kind && <InviteMemberFlow group={ group } actions={ actions } onClose={ closeModal } onDone={ onDone } /> }
			{ 'remove' === modal?.kind && (
				<RemoveMemberFlow members={ modal.members } actions={ actions } onClose={ closeModal } onDone={ onDone } />
			) }
			{ 'seats' === modal?.kind && <AdjustSeatsFlow group={ group } actions={ actions } onClose={ closeModal } onDone={ onDone } /> }
			{ 'regenerate-link' === modal?.kind && <RegenerateLinkFlow actions={ actions } onClose={ closeModal } onDone={ onDone } /> }
			{ 'disable-link' === modal?.kind && <DisableLinkFlow actions={ actions } onClose={ closeModal } onDone={ onDone } /> }
			{ 'resend-invite' === modal?.kind && (
				<ResendInviteFlow invite={ modal.invite } actions={ actions } onClose={ closeModal } onDone={ onDone } />
			) }
			{ 'cancel-invite' === modal?.kind && (
				<CancelInviteFlow invite={ modal.invite } actions={ actions } onClose={ closeModal } onDone={ onDone } />
			) }
			{ 'view-subscription' === modal?.kind && <SubscriptionDetailsDrawer group={ group } onViewOwner={ goToOwner } onClose={ closeModal } /> }

			{ snackbar && (
				<div className="newspack-subscribers__snackbar">
					<Snackbar onRemove={ () => setSnackbar( null ) }>{ snackbar.message }</Snackbar>
				</div>
			) }
		</div>
	);
}

// Remount on id change (key) so per-id state — modal, snackbar, table views —
// resets cleanly rather than briefly showing the previous group's state.
export default function GroupDetail() {
	const { id } = useParams();
	return <GroupDetailView key={ id } />;
}
