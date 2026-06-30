/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise */
/**
 * Flow C — Plan change.
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Modal, SelectControl } from '../../../../packages/components/src';
import { getPlanOptions, seatsUsed } from '../data/mock-groups';
import { fmtCurrency, fmtDate } from '../format';

export default function PlanChangeFlow( { subscription, group, onClose, onComplete } ) {
	// A group is billed like any subscription; it just switches between team
	// products rather than the individual digital/print plans.
	const item = subscription || group;
	const isGroup = !! group;
	const options = getPlanOptions( item, isGroup );
	const [ planName, setPlanName ] = useState( options[ 0 ]?.name || '' );
	const [ state, setState ] = useState( 'choose' );
	const plan = options.find( p => p.name === planName );
	// A group's seats come from its product, so a downgrade can't drop the cap
	// below the members already in the group.
	const membersUsed = isGroup ? seatsUsed( group ) : 0;
	const tooFewSeats = isGroup && plan && plan.seats < membersUsed;

	const submit = () => {
		setState( 'loading' );
		setTimeout( () => {
			const message = sprintf( __( 'Subscription changed to %s.', 'newspack-plugin' ), plan.name );
			if ( isGroup ) {
				onComplete( {
					type: 'success',
					message,
					groupChange: { ...group, plan: plan.name, cadence: plan.cadence, amount: plan.amount, seatLimit: plan.seats },
				} );
				return;
			}
			onComplete( {
				type: 'success',
				message,
				mutate: s => ( {
					...s,
					subscriptions: s.subscriptions.map( sub =>
						sub.id === subscription.id
							? { ...sub, plan: plan.name, access: plan.access, cadence: plan.cadence, amount: plan.amount }
							: sub
					),
				} ),
			} );
		}, 700 );
	};

	if ( ! plan ) {
		return (
			<Modal title={ __( 'Change subscription', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
				<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
					<Notice status="info" isDismissible={ false }>
						{ __( 'There are no other subscriptions to switch to.', 'newspack-plugin' ) }
					</Notice>
					<HStack spacing={ 2 } justify="flex-end">
						<Button variant="tertiary" size="compact" onClick={ onClose }>
							{ __( 'Close', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Modal>
		);
	}

	return (
		<Modal title={ __( 'Change subscription', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
			<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
				<p>
					<strong>{ sprintf( __( 'Currently on %s.', 'newspack-plugin' ), item.plan ) }</strong>
				</p>
				<SelectControl
					label={ __( 'New subscription', 'newspack-plugin' ) }
					value={ planName }
					options={ options.map( p => ( {
						label: isGroup
							? `${ p.name } — ${ fmtCurrency( p.amount ) }/${ p.cadence === 'Monthly' ? 'mo' : 'yr' } · ${ p.seats } seats`
							: `${ p.name } — ${ fmtCurrency( p.amount ) }/${ p.cadence === 'Monthly' ? 'mo' : 'yr' }`,
						value: p.name,
					} ) ) }
					onChange={ setPlanName }
				/>
				{ tooFewSeats ? (
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							__( '%1$s allows %2$d seats, but this group has %3$d members. Remove members before downgrading.', 'newspack-plugin' ),
							plan.name,
							plan.seats,
							membersUsed
						) }
					</Notice>
				) : (
					<p>
						{ sprintf(
							__(
								'Change takes effect at the next billing cycle on %1$s. New charge: %2$s. Proration will be applied to the first invoice.',
								'newspack-plugin'
							),
							fmtDate( item.nextBillingDate ) || __( 'next renewal', 'newspack-plugin' ),
							fmtCurrency( plan.amount )
						) }
					</p>
				) }
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ state === 'loading' } onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button
						variant="primary"
						size="compact"
						isBusy={ state === 'loading' }
						disabled={ state === 'loading' || tooFewSeats }
						onClick={ submit }
					>
						{ __( 'Confirm', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
