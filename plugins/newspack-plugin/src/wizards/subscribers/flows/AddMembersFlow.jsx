/**
 * Flow — add people to a group directly, on the owner's behalf.
 *
 * The admin capability the owner does not have: unlike an invitation, an existing
 * reader is put into the group straight away with no acceptance step.
 *
 * People with no account yet cannot be added directly — there is nobody to add —
 * so they are invited instead, and the result says exactly which happened to whom.
 * That is not a workaround: an invitation is how this system onboards someone who
 * has no reader account, and it is the path that gets their consent rather than
 * minting an account for an address that never asked for one.
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

export default function AddMembersFlow( { group, actions, onClose, onDone } ) {
	const [ tokens, setTokens ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const remaining = seatsRemaining( group );
	// Someone already in the group cannot be added again; an address with an
	// outstanding invitation still can, and adding converts it.
	const memberEmails = new Set(
		( group.memberList || [] )
			.map( member => member.email )
			.filter( Boolean )
			.map( email => email.toLowerCase() )
	);
	const emails = normalizeEmails( tokens ).filter( email => ! memberEmails.has( email.toLowerCase() ) );
	const accepted = emails.slice( 0, remaining );
	const overCapacity = emails.length > accepted.length;

	const add = async () => {
		setBusy( true );
		setError( '' );
		const failures = [];
		const readerIds = [];
		const toInvite = [];
		let added = 0;
		let invited = 0;

		try {
			for ( const email of accepted ) {
				const readerId = await actions.resolveReaderId( email );
				if ( readerId ) {
					readerIds.push( readerId );
				} else {
					toInvite.push( email );
				}
			}
			if ( readerIds.length ) {
				// Count what the server stored, not what was sent: update_members()
				// silently drops IDs that are already members or aren't readers and
				// still answers 200, so `members_added` is the only honest total.
				const result = await actions.addMembers( readerIds );
				added = Object.keys( result?.members_added || {} ).length;
				if ( added < readerIds.length ) {
					failures.push(
						sprintf(
							/* translators: %d: number of addresses that were skipped. */
							_n(
								'%d address was already a member, or is not a reader account, and was skipped.',
								'%d addresses were already members, or are not reader accounts, and were skipped.',
								readerIds.length - added,
								'newspack-plugin'
							),
							readerIds.length - added
						)
					);
				}
			}
			for ( const email of toInvite ) {
				try {
					// A call that does not throw is not a delivered invitation:
					// generate_invite() writes the row first and reports the send in
					// `email_sent`. An undelivered invitation still holds a seat, so
					// counting it as sent would let the admin fill the group with
					// invitations nobody received and then meet the seat limit with
					// nothing pointing at mail delivery.
					const invite = await actions.invite( email );
					if ( invite?.email_sent ) {
						invited++;
					} else {
						failures.push(
							sprintf(
								/* translators: %s: email address the invitation was addressed to. */
								__(
									'%s: the invitation was created but the email could not be sent. It holds a seat until you cancel it.',
									'newspack-plugin'
								),
								email
							)
						);
					}
				} catch ( e ) {
					failures.push( `${ email }: ${ e?.message || __( 'Something went wrong.', 'newspack-plugin' ) }` );
				}
			}
		} catch ( e ) {
			setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
			setBusy( false );
			return;
		}

		const parts = [];
		if ( added ) {
			/* translators: %d: number of members added to the group. */
			parts.push( sprintf( _n( '%d member added.', '%d members added.', added, 'newspack-plugin' ), added ) );
		}
		if ( invited ) {
			parts.push(
				sprintf(
					/* translators: %d: number of invitations sent. */
					_n(
						'%d invitation sent — no account on this site yet.',
						'%d invitations sent — no accounts on this site yet.',
						invited,
						'newspack-plugin'
					),
					invited
				)
			);
		}
		if ( failures.length ) {
			setError( failures.join( ' ' ) );
			setBusy( false );
			if ( parts.length ) {
				onDone( parts.join( ' ' ), { keepOpen: true } );
			}
			return;
		}
		onDone( parts.join( ' ' ) || __( 'Nothing to add.', 'newspack-plugin' ) );
	};

	return (
		<Modal title={ __( 'Add members', 'newspack-plugin' ) } onRequestClose={ busy ? () => {} : onClose } size="small">
			<VStack spacing={ 4 }>
				{ error && <Notice isError noticeText={ error } /> }
				<p className="newspack-subscribers__modal-text">
					{ 0 === remaining
						? __(
								'No seats are available. Remove a member, cancel a pending invite, or raise the seat limit before adding.',
								'newspack-plugin'
						  )
						: __(
								'People who already have an account are added right away. Anyone who does not is sent an invitation instead.',
								'newspack-plugin'
						  ) }
				</p>
				<FormTokenField
					label={ __( 'Add by email', 'newspack-plugin' ) }
					value={ tokens }
					onChange={ setTokens }
					disabled={ 0 === remaining || busy }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ overCapacity && (
					<p className="newspack-subscribers__modal-text">
						{ sprintf(
							/* translators: %d: number of people that fit in the remaining seats. */
							_n(
								'Only %d person will be added, to match the available seats.',
								'Only %d people will be added, to match the available seats.',
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
					<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy || 0 === accepted.length } onClick={ add }>
						{ __( 'Add members', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
