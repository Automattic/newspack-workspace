/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — adjust a group's seat limit.
 *
 * DELIBERATELY NOT A BILLING ACTION. Moving the limit changes capacity and nothing
 * else: it takes no payment, sends no payment link and does not alter what the
 * group is charged. An admin acting on an owner's behalf is doing maintenance, not
 * spending the owner's money — so selling extra seats stays a separate, deliberate
 * step. The prototype's "increase & send a payment link" branch belongs with the
 * rest of the money surfaces and is not part of this screen.
 *
 * The new limit cannot fall below what the group has already committed — the seats
 * held by members plus outstanding invitations — and the server enforces that
 * independently, so a forged request cannot strand anyone.
 */

/**
 * WordPress dependencies.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { TextControl, ToggleControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button, Modal, Notice } from '../../../../packages/components/src';
import { GROUP_LABEL } from '../labels';

// Any capped group holds at least two seats: the owner and somebody to share
// with. The server clamps to this too (Group_Subscription_Settings::normalize_limit),
// so the field agrees with what will actually be stored.
const MIN_CAPPED_LIMIT = 2;

export default function AdjustSeatsFlow( { group, actions, onClose, onDone } ) {
	const reserved = Number( group.seatsReserved ) || 0;
	const currentLimit = Number( group.seatLimit ) || 0;
	const [ unlimited, setUnlimited ] = useState( 0 === currentLimit );
	const [ value, setValue ] = useState( String( currentLimit || Math.max( reserved, MIN_CAPPED_LIMIT ) ) );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const limit = unlimited ? 0 : parseInt( value, 10 );
	const floor = Math.max( reserved, MIN_CAPPED_LIMIT );
	const invalid = ! unlimited && ( isNaN( limit ) || limit < floor );
	const unchanged = limit === currentLimit;

	const save = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await actions.setSeatLimit( limit );
			const saved = Number( result?.seatLimit ) || 0;
			onDone(
				0 === saved
					? sprintf( __( 'Seat limit removed — this %s is now unlimited.', 'newspack-plugin' ), GROUP_LABEL.toLowerCase() )
					: sprintf( __( 'Seat limit updated to %d.', 'newspack-plugin' ), saved )
			);
		} catch ( e ) {
			setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
			setBusy( false );
		}
	};

	return (
		<Modal title={ __( 'Adjust seat limit', 'newspack-plugin' ) } onRequestClose={ busy ? () => {} : onClose } size="small">
			<VStack spacing={ 4 }>
				{ error && <Notice isError noticeText={ error } /> }
				<ToggleControl
					label={ __( 'Unlimited seats', 'newspack-plugin' ) }
					help={ sprintf( __( 'Anyone invited can join this %s, with no cap.', 'newspack-plugin' ), GROUP_LABEL.toLowerCase() ) }
					checked={ unlimited }
					disabled={ busy }
					onChange={ setUnlimited }
					__nextHasNoMarginBottom
				/>
				{ ! unlimited && (
					<TextControl
						type="number"
						label={ __( 'Seat limit', 'newspack-plugin' ) }
						value={ value }
						min={ floor }
						disabled={ busy }
						onChange={ setValue }
						help={ sprintf(
							__( '%d seats are committed to members and pending invitations. The limit cannot be set below this.', 'newspack-plugin' ),
							reserved
						) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				) }
				<p className="newspack-subscribers__modal-text">
					{ __(
						'This changes how many people the group can hold. It does not charge anything or change what the group is billed.',
						'newspack-plugin'
					) }
				</p>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ busy } onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy || invalid || unchanged } onClick={ save }>
						{ __( 'Save seat limit', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
