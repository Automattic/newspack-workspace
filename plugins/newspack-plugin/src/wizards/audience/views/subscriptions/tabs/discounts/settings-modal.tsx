/**
 * Global subscriber-discount settings: how overlapping discounts combine, and
 * whether they apply to products already on sale.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import {
	Modal,
	RadioControl,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Notice } from '../../../../../../../packages/components/src';
import { DISCOUNT_SETTINGS_ENDPOINT } from './constants';
import type { DiscountSettings, DiscountsPayload } from './types';

interface SettingsModalProps {
	settings: DiscountSettings;
	onSaved: ( payload: DiscountsPayload ) => void;
	onClose: () => void;
}

export default function SettingsModal( { settings, onSaved, onClose }: SettingsModalProps ) {
	const [ draft, setDraft ] = useState< DiscountSettings >( settings );
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( '' );

	const save = () => {
		setInFlight( true );
		setError( '' );
		apiFetch< DiscountsPayload >( {
			path: DISCOUNT_SETTINGS_ENDPOINT,
			method: 'POST',
			data: draft,
		} )
			.then( onSaved )
			.catch( ( apiError: { message?: string } ) =>
				setError( apiError?.message || __( 'These settings could not be saved.', 'newspack-plugin' ) )
			)
			.finally( () => setInFlight( false ) );
	};

	return (
		<Modal title={ __( 'Discount settings', 'newspack-plugin' ) } onRequestClose={ onClose } className="newspack-subscriber-discounts__settings">
			<VStack spacing={ 4 }>
				{ error && <Notice isError noticeText={ error } /> }
				<h3>{ __( 'Combining discounts', 'newspack-plugin' ) }</h3>
				<RadioControl
					label={ __( 'Overlapping discounts', 'newspack-plugin' ) }
					help={ __( 'What happens when more than one discount applies to the same product.', 'newspack-plugin' ) }
					selected={ draft.overlap }
					onChange={ ( value: string ) => setDraft( { ...draft, overlap: value as DiscountSettings[ 'overlap' ] } ) }
					options={ [
						{ value: 'best', label: __( 'Apply the best discount only', 'newspack-plugin' ) },
						{ value: 'combine', label: __( 'Combine discounts', 'newspack-plugin' ) },
					] }
				/>
				<ToggleControl
					label={ __( 'Apply on top of sale prices', 'newspack-plugin' ) }
					help={ __( 'Subscribers get their discount even on products that are already on sale.', 'newspack-plugin' ) }
					checked={ draft.apply_on_sale }
					onChange={ value => setDraft( { ...draft, apply_on_sale: value } ) }
					__nextHasNoMarginBottom
				/>
				<h3>{ __( 'Timing', 'newspack-plugin' ) }</h3>
				<ToggleControl
					label={ __( 'Apply discounts at checkout', 'newspack-plugin' ) }
					help={ __(
						'Give readers their subscriber prices as soon as a subscription is in their cart, before they have completed the purchase.',
						'newspack-plugin'
					) }
					checked={ draft.apply_at_checkout }
					onChange={ value => setDraft( { ...draft, apply_at_checkout: value } ) }
					__nextHasNoMarginBottom
				/>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="secondary" disabled={ inFlight } onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" isBusy={ inFlight } disabled={ inFlight } onClick={ save }>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
