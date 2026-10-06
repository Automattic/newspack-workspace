/**
 * Flow — hand a group to another reader.
 *
 * The admin picks the new owner from the group's members or from any reader, then
 * confirms. The previous owner stays in the group as a plain member, so nobody
 * loses access, and picking a reader from outside the group then needs a free
 * seat. An owner who can't be a member (staff) leaves the group instead, and
 * their seat passes to the new owner (`ownerStaysAsMember`). A group with no
 * owner has no seat to pass on, so an outsider needs a free one there too.
 *
 * The screen offers this only for a group with no payment method renewing it
 * (`ownerChangeable`): the owner holds billing. The server enforces every rule
 * here independently (Group_Subscription::change_owner()).
 */

/**
 * WordPress dependencies.
 */
import { createInterpolateElement, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useDebounce } from '@wordpress/compose';
import { ComboboxControl, Notice, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button, Modal } from '../../../../packages/components/src';
import ConfirmFlow from './ConfirmFlow';
import { seatsRemaining } from './capacity';
import { GROUP_LABEL } from '../labels';

// The shortest term /search-users answers (Group_Subscription_API::SEARCH_USERS_MIN_LENGTH).
const MIN_SEARCH_LENGTH = 2;

/**
 * The confirmation sentence, by what happens to the current owner.
 *
 * @param {Object}  args            Arguments.
 * @param {string}  args.ownerName  The current owner's name; empty when the group has none.
 * @param {boolean} args.ownerStays Whether the current owner stays as a member.
 * @param {string}  args.groupLabel Lowercase singular group label.
 * @return {string} A template for createInterpolateElement(), with <new/> and <previous/> tokens.
 */
const confirmText = ( { ownerName, ownerStays, groupLabel } ) => {
	if ( ! ownerName ) {
		/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
		return sprintf( __( '<new/> becomes the owner of this %s. Billing details move to the new owner.', 'newspack-plugin' ), groupLabel );
	}
	if ( ownerStays ) {
		return sprintf(
			/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
			__(
				'<new/> becomes the owner of this %s. <previous/> becomes a regular member and keeps access. Billing details move to the new owner.',
				'newspack-plugin'
			),
			groupLabel
		);
	}
	return sprintf(
		/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
		__(
			"<new/> becomes the owner of this %s. <previous/> can't be a member, so they leave it. Billing details move to the new owner.",
			'newspack-plugin'
		),
		groupLabel
	);
};

export default function ChangeOwnerFlow( { group, actions, onClose, onDone } ) {
	// The picked option itself, not its value: a reader found by search drops out of
	// the options as soon as the search changes, and must stay picked.
	const [ chosen, setChosen ] = useState( null );
	const [ confirming, setConfirming ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ readers, setReaders ] = useState( [] );
	const [ searchError, setSearchError ] = useState( '' );
	const [ searching, setSearching ] = useState( false );
	const setSearchDebounced = useDebounce( setSearch, 300 );

	const groupLabel = GROUP_LABEL.toLowerCase();
	const ownerStays = false !== group.ownerStaysAsMember;
	// Mirrors the server: only a previous owner who leaves frees a seat, and a
	// group with no owner ID has none to free.
	const ownerSeatFree = !! group.ownerId && ! ownerStays;
	const canTakeOutsider = ownerSeatFree || seatsRemaining( group ) > 0;
	// A pending invitation already holds a seat, so its invitee can take over a
	// full group: the server leaves that invitation out of the seat check.
	const invitedEmails = useMemo(
		() =>
			new Set( ( group.invites || [] ).filter( invite => 'pending' === invite.status ).map( invite => String( invite.email ).toLowerCase() ) ),
		[ group ]
	);
	const canSearch = canTakeOutsider || invitedEmails.size > 0;
	const members = useMemo(
		() =>
			( group.memberList || [] )
				.filter( member => 'owner' !== member.role )
				.map( member => ( { value: String( member.id ), label: `${ member.name } (${ member.email })`, name: member.name } ) ),
		[ group ]
	);

	useEffect( () => {
		if ( ! canSearch || search.trim().length < MIN_SEARCH_LENGTH ) {
			setReaders( [] );
			setSearchError( '' );
			return;
		}
		let cancelled = false;
		setSearching( true );
		actions
			.searchReaders( search.trim() )
			.then( matches => {
				if ( ! cancelled ) {
					setSearchError( '' );
					// The label carries the name as well as the email: the combobox
					// filters options by label, so a reader found by name needs it.
					setReaders(
						( matches || [] ).flatMap( match => {
							const email = String( match.text || '' ).replace( / \(#\d+\)$/, '' );
							if ( ! canTakeOutsider && ! invitedEmails.has( email.toLowerCase() ) ) {
								return [];
							}
							const name = match.name || email;
							return [ { value: String( match.id ), label: name === email ? email : `${ name } (${ email })`, name } ];
						} )
					);
				}
			} )
			.catch( e => {
				if ( ! cancelled ) {
					setSearchError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setSearching( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ search, canSearch, canTakeOutsider, invitedEmails, actions ] );

	// Members come first and are never repeated by a search: /search-users already
	// leaves out everyone in the group. The picked reader stays listed so the field
	// keeps showing them after the search moves on.
	const options = useMemo( () => {
		const listed = [ ...members, ...readers ];
		return chosen && ! listed.some( option => option.value === chosen.value ) ? [ ...listed, chosen ] : listed;
	}, [ members, readers, chosen ] );
	const ownerName = group.owner?.name;

	if ( confirming && chosen ) {
		const changeOwner = async () => {
			await actions.changeOwner( Number( chosen.value ) );
			/* translators: %s: name of the new group owner. */
			onDone( sprintf( __( '%s is now the owner.', 'newspack-plugin' ), chosen.name ) );
		};
		return (
			<ConfirmFlow
				title={ __( 'Change owner', 'newspack-plugin' ) }
				confirmLabel={ __( 'Change owner', 'newspack-plugin' ) }
				cancelLabel={ __( 'Back', 'newspack-plugin' ) }
				onCancel={ () => setConfirming( false ) }
				onRequestClose={ onClose }
				onConfirm={ changeOwner }
			>
				{ createInterpolateElement(
					confirmText( { ownerName, ownerStays, groupLabel } ),
					// Names are React tokens, never part of the format string: a "<" in a
					// display name would otherwise be parsed as markup.
					{ new: <strong>{ chosen.name }</strong>, previous: <strong>{ ownerName }</strong> }
				) }
			</ConfirmFlow>
		);
	}

	return (
		<Modal title={ __( 'Change owner', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
			<VStack spacing={ 4 }>
				{ /* This Notice announces itself, which matters while focus sits in the combobox. */ }
				{ searchError && (
					<Notice status="error" isDismissible={ false }>
						{ searchError }
					</Notice>
				) }
				<p className="newspack-subscribers__modal-text">
					{ canTakeOutsider
						? sprintf(
								/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
								__( 'Choose a member of this %s, or search for any reader by name or email.', 'newspack-plugin' ),
								groupLabel
						  )
						: sprintf(
								/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
								__(
									'This %s is full, so only a current member or someone with a pending invitation can take over. Raise the seat limit to choose someone else.',
									'newspack-plugin'
								),
								groupLabel
						  ) }
				</p>
				<ComboboxControl
					label={ __( 'New owner', 'newspack-plugin' ) }
					value={ chosen?.value ?? null }
					options={ options }
					onChange={ value => setChosen( options.find( option => option.value === value ) || null ) }
					onFilterValueChange={ setSearchDebounced }
					isLoading={ searching }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" disabled={ ! chosen } onClick={ () => setConfirming( true ) }>
						{ __( 'Continue', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
