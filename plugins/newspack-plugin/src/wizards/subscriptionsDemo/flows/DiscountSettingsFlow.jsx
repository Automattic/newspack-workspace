/**
 * Flow — Global discount settings: whether subscriber discounts stack on top of
 * sale prices, how overlapping discounts on the same product resolve, and
 * whether discounts kick in while the subscription is still in the cart.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	RadioControl,
	ToggleControl,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Button, Modal } from '../../../../packages/components/src';
import { getDiscountSettings, saveDiscountSettings } from '../data/mock-discounts';

export default function DiscountSettingsFlow( { onClose, onSaved } ) {
	const [ draft, setDraft ] = useState( getDiscountSettings );

	const onSave = () => {
		saveDiscountSettings( draft );
		onSaved( __( 'Discount settings saved.', 'newspack-plugin' ) );
	};

	return (
		<Modal title={ __( 'Discount settings', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
			<VStack spacing={ 6 }>
				<VStack spacing={ 4 }>
					<h3 className="newspack-subscribers-demo__settings-group-title">
						{ __( 'Combining discounts', 'newspack-plugin' ) }
					</h3>
					<RadioControl
						label={ __( 'Overlapping discounts', 'newspack-plugin' ) }
						help={ __( 'What happens when more than one discount applies to the same product.', 'newspack-plugin' ) }
						selected={ draft.overlap }
						options={ [
							{ label: __( 'Apply the best discount only', 'newspack-plugin' ), value: 'best' },
							{ label: __( 'Combine discounts', 'newspack-plugin' ), value: 'combine' },
						] }
						onChange={ overlap => setDraft( { ...draft, overlap } ) }
					/>
					<ToggleControl
						label={ __( 'Apply on top of sale prices', 'newspack-plugin' ) }
						help={ __( 'Subscribers get their discount even on products that are already on sale.', 'newspack-plugin' ) }
						checked={ draft.applyOnSale }
						onChange={ applyOnSale => setDraft( { ...draft, applyOnSale } ) }
						__nextHasNoMarginBottom
					/>
				</VStack>
				<VStack spacing={ 4 }>
					<h3 className="newspack-subscribers-demo__settings-group-title">
						{ __( 'Timing', 'newspack-plugin' ) }
					</h3>
					<ToggleControl
						label={ __( 'Apply discounts at checkout', 'newspack-plugin' ) }
						help={ __(
							'Give readers their subscriber prices as soon as a subscription is in their cart, before they’ve completed the purchase.',
							'newspack-plugin'
						) }
						checked={ draft.applyAtCheckout }
						onChange={ applyAtCheckout => setDraft( { ...draft, applyAtCheckout } ) }
						__nextHasNoMarginBottom
					/>
				</VStack>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" onClick={ onSave }>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
