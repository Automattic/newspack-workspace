/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Add member(s) directly to a group, on behalf of the owner.
 *
 * Admin superpower beyond owner parity (#148): unlike an invite, people are added
 * immediately. Existing accounts resolve by email; unknown emails mint a stub
 * account. Capacity-aware (trims to available seats) and additive (never touches
 * anyone's individual subscription).
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { FormTokenField, CheckboxControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Modal } from '../../../../packages/components/src';
import { addMembersByEmail, inviteCapacity, getMemberSubscriber } from '../data/mock-groups';

const today = () => new Date().toISOString().slice( 0, 10 );
const normalize = tokens => [ ...new Set( ( tokens || [] ).map( t => String( t ).trim() ).filter( Boolean ) ) ];

export default function AddMembersFlow( { group, onClose, onComplete } ) {
	const [ tokens, setTokens ] = useState( [] );
	const [ notify, setNotify ] = useState( true );

	// Emails already attached to a member, so the same person isn't added twice.
	const memberEmails = new Set(
		( group.members || [] ).map( m => ( getMemberSubscriber( m.subscriberId )?.email || m.email || '' ).toLowerCase() ).filter( Boolean )
	);
	const remaining = inviteCapacity( group );

	// Drop blanks, duplicates, and current members. A pending invite address is
	// allowed — adding converts it.
	const emails = normalize( tokens ).filter( e => ! memberEmails.has( e.toLowerCase() ) );
	const acceptedEmails = emails.slice( 0, remaining );
	const overCapacity = emails.length > remaining;

	const addMembers = () => {
		const count = acceptedEmails.length;
		onComplete( {
			type: 'success',
			transient: true,
			message: notify
				? sprintf( _n( '%d member added and notified.', '%d members added and notified.', count, 'newspack-plugin' ), count )
				: sprintf( _n( '%d member added.', '%d members added.', count, 'newspack-plugin' ), count ),
			mutate: g => addMembersByEmail( g, acceptedEmails, today() ),
		} );
	};

	return (
		<Modal title={ __( 'Add members', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
			<VStack spacing={ 4 }>
				<p className="newspack-subscribers-demo__modal-text">
					{ remaining === 0
						? __(
								'No seats are available. Remove a member, cancel a pending invite, or raise the seat limit before adding.',
								'newspack-plugin'
						  )
						: __( 'These people are added to the group right away. Anyone without an account gets one created.', 'newspack-plugin' ) }
				</p>
				<FormTokenField
					label={ __( 'Add by email', 'newspack-plugin' ) }
					value={ tokens }
					onChange={ setTokens }
					disabled={ remaining === 0 }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ overCapacity && (
					<p className="newspack-subscribers-demo__modal-text">
						{ sprintf(
							_n(
								'Only %d member will be added to match the available seats.',
								'Only %d members will be added to match the available seats.',
								remaining,
								'newspack-plugin'
							),
							remaining
						) }
					</p>
				) }
				<CheckboxControl
					label={ __( 'Send a welcome email', 'newspack-plugin' ) }
					help={ __( 'New accounts get a setup email; existing subscribers a notification.', 'newspack-plugin' ) }
					checked={ notify }
					onChange={ setNotify }
					__nextHasNoMarginBottom
				/>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" onClick={ addMembers } disabled={ acceptedEmails.length === 0 }>
						{ __( 'Add members', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
