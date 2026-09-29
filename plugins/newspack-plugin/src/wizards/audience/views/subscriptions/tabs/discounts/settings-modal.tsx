/**
 * Global subscriber-discount settings: whether discounts apply to products
 * already on sale, and when they start counting.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import {
	Modal,
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
		<Modal
			title={ __( 'Discount Settings', 'newspack-plugin' ) }
			size="small"
			onRequestClose={ onClose }
			className="newspack-subscriber-discounts__settings"
		>
			<VStack spacing={ 6 }>
				{ error && <Notice isError noticeText={ error } /> }
				<VStack spacing={ 4 }>
					<h3>{ __( 'Sale Prices', 'newspack-plugin' ) }</h3>
					<ToggleControl
						label={ __( 'Apply on top of sale prices', 'newspack-plugin' ) }
						help={ __( 'Subscribers get their discount even on products that are already on sale.', 'newspack-plugin' ) }
						checked={ draft.apply_on_sale }
						onChange={ value => setDraft( { ...draft, apply_on_sale: value } ) }
						__nextHasNoMarginBottom
					/>
				</VStack>
				<VStack spacing={ 4 }>
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
				</VStack>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" disabled={ inFlight } onClick={ onClose }>
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
