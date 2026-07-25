/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — invite people to a group by email, on the owner's behalf.
 *
 * Each address gets an email with a join link. Several addresses can be entered at
 * once, which is where this diverges from the owner's single-address My Account
 * form: an admin setting a group up is usually working from a list.
 */

/**
 * WordPress dependencies.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { FormTokenField, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button, Modal, Notice } from '../../../../packages/components/src';
import { normalizeEmails, seatsRemaining } from './capacity';

export default function InviteMemberFlow( { group, actions, onClose, onDone } ) {
	const [ tokens, setTokens ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const remaining = seatsRemaining( group );
	// Addresses that already hold a seat or an outstanding invitation are dropped
	// rather than sent again: re-inviting a pending address just resets its clock,
	// and inviting a member is refused server-side.
	const taken = new Set(
		[ ...( group.memberList || [] ).map( member => member.email ), ...( group.invites || [] ).map( invite => invite.email ) ]
			.filter( Boolean )
			.map( email => email.toLowerCase() )
	);
	const emails = normalizeEmails( tokens ).filter( email => ! taken.has( email.toLowerCase() ) );
	const accepted = emails.slice( 0, remaining );
	const overCapacity = emails.length > accepted.length;

	const send = async () => {
		setBusy( true );
		setError( '' );
		const failures = [];
		for ( const email of accepted ) {
			try {
				// Sequential rather than parallel: each invitation re-checks the seat
				// limit server-side, and firing them at once would let a batch race
				// past a limit that only has room for some of them.
				await actions.invite( email );
			} catch ( e ) {
				failures.push( `${ email }: ${ e?.message || __( 'Something went wrong.', 'newspack-plugin' ) }` );
			}
		}
		const sent = accepted.length - failures.length;
		if ( failures.length ) {
			setError( failures.join( ' ' ) );
			setBusy( false );
			// A partial send still changed the group, so let the screen refresh
			// without closing the modal over the error.
			if ( sent > 0 ) {
				onDone( sprintf( _n( '%d invitation sent.', '%d invitations sent.', sent, 'newspack-plugin' ), sent ), { keepOpen: true } );
			}
			return;
		}
		onDone( sprintf( _n( '%d invitation sent.', '%d invitations sent.', sent, 'newspack-plugin' ), sent ) );
	};

	return (
		<Modal title={ __( 'Invite members', 'newspack-plugin' ) } onRequestClose={ busy ? () => {} : onClose } size="small">
			<VStack spacing={ 4 }>
				{ error && <Notice isError noticeText={ error } /> }
				<p className="newspack-subscribers__modal-text">
					{ 0 === remaining
						? __(
								'No seats are available. Remove a member, cancel a pending invite, or raise the seat limit before inviting.',
								'newspack-plugin'
						  )
						: __( 'Each address gets an email with a link to join.', 'newspack-plugin' ) }
				</p>
				<FormTokenField
					label={ __( 'Invite by email', 'newspack-plugin' ) }
					value={ tokens }
					onChange={ setTokens }
					disabled={ 0 === remaining || busy }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ overCapacity && (
					<p className="newspack-subscribers__modal-text">
						{ sprintf(
							_n(
								'Only %d invite will be sent, to match the available seats.',
								'Only %d invites will be sent, to match the available seats.',
								accepted.length,
								'newspack-plugin'
							),
							accepted.length
						) }
					</p>
				) }
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ busy } onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy || 0 === accepted.length } onClick={ send }>
						{ __( 'Send invites', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
