/* eslint-disable @wordpress/i18n-translator-comments */
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
				await actions.addMembers( readerIds );
			}
			for ( const email of toInvite ) {
				try {
					await actions.invite( email );
				} catch ( e ) {
					failures.push( `${ email }: ${ e?.message || __( 'Something went wrong.', 'newspack-plugin' ) }` );
				}
			}
		} catch ( e ) {
			setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
			setBusy( false );
			return;
		}

		const added = readerIds.length;
		const invited = toInvite.length - failures.length;
		const parts = [];
		if ( added ) {
			parts.push( sprintf( _n( '%d member added.', '%d members added.', added, 'newspack-plugin' ), added ) );
		}
		if ( invited ) {
			parts.push(
				sprintf(
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
