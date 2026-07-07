/**
 * Flow — Global discount settings: whether member discounts stack on top of
 * sale prices, and how overlapping discounts on the same product resolve.
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
			<VStack spacing={ 4 }>
				<ToggleControl
					label={ __( 'Apply member discounts to products already on sale', 'newspack-plugin' ) }
					help={ __( 'When off, a product with a sale price never gets a member discount on top.', 'newspack-plugin' ) }
					checked={ draft.applyOnSale }
					onChange={ applyOnSale => setDraft( { ...draft, applyOnSale } ) }
					__nextHasNoMarginBottom
				/>
				<RadioControl
					label={ __( 'When multiple discounts apply to the same product', 'newspack-plugin' ) }
					selected={ draft.overlap }
					options={ [
						{ label: __( 'Apply the best discount only', 'newspack-plugin' ), value: 'best' },
						{ label: __( 'Combine discounts', 'newspack-plugin' ), value: 'combine' },
					] }
					onChange={ overlap => setDraft( { ...draft, overlap } ) }
				/>
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
