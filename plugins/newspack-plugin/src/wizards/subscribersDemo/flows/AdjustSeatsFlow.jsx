/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Adjust a group's seat limit (admin-only).
 *
 * The new limit cannot drop below the seats already committed (members plus
 * outstanding invites), and can only be raised while the group is active.
 */

import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { TextControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Modal } from '../../../../packages/components/src';
import { reservedSeats, isGroupActive } from '../data/mock-groups';
import { GROUP_LABEL_LOWER } from '../labels';

export default function AdjustSeatsFlow( { group, onClose, onComplete } ) {
	// Floor for the new limit: members plus pending invites and active link uses,
	// so a reduction can never strand obligations the group already made.
	const reserved = reservedSeats( group );
	const [ value, setValue ] = useState( String( group.seatLimit ) );
	const [ confirming, setConfirming ] = useState( false );

	// Seat increases require an active group; paused (on-hold) groups may only
	// hold or reduce the limit, never raise it.
	const canIncrease = isGroupActive( group );
	const limit = parseInt( value, 10 );
	const invalid = isNaN( limit ) || limit < reserved || ( limit > group.seatLimit && ! canIncrease );
	const unchanged = limit === group.seatLimit;

	const save = () => {
		onComplete( {
			type: 'success',
			transient: true,
			message: sprintf( __( 'Seat limit updated to %d.', 'newspack-plugin' ), limit ),
			mutate: g => ( { ...g, seatLimit: limit } ),
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
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" onClick={ () => setConfirming( true ) } disabled={ invalid || unchanged }>
						{ __( 'Adjust seats', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
