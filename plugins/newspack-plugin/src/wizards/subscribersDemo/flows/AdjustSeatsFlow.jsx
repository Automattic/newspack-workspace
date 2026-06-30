/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Adjust a group's seat limit (admin-only).
 *
 * The new limit cannot drop below the seats already committed (members plus
 * outstanding invites), and can only be raised while the group is active.
 */

import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { RadioControl, TextControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Modal } from '../../../../packages/components/src';
import { reservedSeats, isGroupActive, applySeatIncrease, sendSeatUpgradeLink } from '../data/mock-groups';
import { getSubscriberById } from '../data/mock-subscribers';
import { buildSeatIncreaseOrder, buildPaymentLinkOrder } from './subscription-actions';
import { fmtCurrency } from '../format';
import { GROUP_LABEL_LOWER } from '../labels';

export default function AdjustSeatsFlow( { group, onClose, onComplete } ) {
	// Floor for the new limit: members plus pending invites and active link uses,
	// so a reduction can never strand obligations the group already made.
	const reserved = reservedSeats( group );
	const ownerEmail = getSubscriberById( group.ownerId )?.email;
	const initial = group.seatRequest?.target && group.seatRequest.target > group.seatLimit ? group.seatRequest.target : group.seatLimit;
	const [ value, setValue ] = useState( String( initial ) );
	const [ confirming, setConfirming ] = useState( false );
	const [ mode, setMode ] = useState( 'free' ); // 'free' | 'link'
	const [ amountValue, setAmountValue ] = useState( '' );

	// Seat increases require an active group; paused (on-hold) groups may only
	// hold or reduce the limit, never raise it.
	const canIncrease = isGroupActive( group );
	const limit = parseInt( value, 10 );
	const invalid = isNaN( limit ) || limit < reserved || ( limit > group.seatLimit && ! canIncrease );
	const unchanged = limit === group.seatLimit;
	const isIncrease = ! isNaN( limit ) && limit > group.seatLimit;
	const amount = parseFloat( amountValue );
	const amountInvalid = mode === 'link' && ( isNaN( amount ) || amount <= 0 );
	const cannotConfirm = invalid || unchanged || ( isIncrease && amountInvalid );

	const save = () => {
		if ( isIncrease && mode === 'link' ) {
			onComplete( {
				type: 'success',
				transient: true,
				message: sprintf( __( 'Payment link sent for %d seats.', 'newspack-plugin' ), limit ),
				mutate: g => sendSeatUpgradeLink( g, limit, amount ),
				ownerOrder: buildPaymentLinkOrder( group ),
			} );
			return;
		}
		onComplete( {
			type: 'success',
			transient: true,
			message: sprintf( __( 'Seat limit updated to %d.', 'newspack-plugin' ), limit ),
			mutate: g => applySeatIncrease( g, limit ),
			ownerOrder: isIncrease ? buildSeatIncreaseOrder( group ) : undefined,
		} );
	};

	if ( confirming ) {
		return (
			<Modal title={ __( 'Adjust seat limit', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
				<VStack spacing={ 4 }>
					<VStack spacing={ 1 }>
						<span>{ sprintf( __( 'Current seat limit: %d', 'newspack-plugin' ), group.seatLimit ) }</span>
						<span>
							{ createInterpolateElement( sprintf( __( 'New seat limit: <strong>%d</strong>', 'newspack-plugin' ), limit ), {
								strong: <strong />,
							} ) }
						</span>
						{ isIncrease && mode === 'link' && (
							<span>
								{ sprintf(
									__( 'A payment link for %1$s will be emailed to %2$s. Seats apply once paid.', 'newspack-plugin' ),
									fmtCurrency( amount ),
									ownerEmail
								) }
							</span>
						) }
						{ isIncrease && mode === 'free' && <span>{ __( 'Seats are granted now at no charge.', 'newspack-plugin' ) }</span> }
					</VStack>
					<HStack spacing={ 2 } justify="flex-end">
						<Button variant="tertiary" size="compact" onClick={ onClose }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button variant="secondary" size="compact" onClick={ () => setConfirming( false ) }>
							{ __( 'Back', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" size="compact" onClick={ save }>
							{ __( 'Confirm new limit', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Modal>
		);
	}

	return (
		<Modal title={ __( 'Adjust seat limit', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
			<VStack spacing={ 4 }>
				<TextControl
					type="number"
					label={ __( 'Seat limit', 'newspack-plugin' ) }
					value={ value }
					min={ reserved }
					max={ canIncrease ? undefined : group.seatLimit }
					onChange={ setValue }
					help={
						canIncrease
							? sprintf(
									__( '%d seats committed to members and pending invites. The limit cannot be set below this.', 'newspack-plugin' ),
									reserved
							  )
							: sprintf(
									__(
										'%1$d seats committed. This %2$s is on hold, so the limit can only be held or reduced, not increased.',
										'newspack-plugin'
									),
									reserved,
									GROUP_LABEL_LOWER
							  )
					}
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ isIncrease && (
					<RadioControl
						label={ __( 'How to apply this increase', 'newspack-plugin' ) }
						selected={ mode }
						options={ [
							{ label: __( 'Increase for free', 'newspack-plugin' ), value: 'free' },
							{ label: __( 'Increase & send a payment link', 'newspack-plugin' ), value: 'link' },
						] }
						onChange={ setMode }
					/>
				) }
				{ isIncrease && mode === 'link' && (
					<TextControl
						type="number"
						label={ __( 'Upgrade charge amount', 'newspack-plugin' ) }
						value={ amountValue }
						min={ 0 }
						onChange={ setAmountValue }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				) }
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" onClick={ () => setConfirming( true ) } disabled={ cannotConfirm }>
						{ __( 'Adjust seats', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
