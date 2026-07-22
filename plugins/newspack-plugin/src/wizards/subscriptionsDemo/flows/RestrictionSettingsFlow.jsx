/**
 * Flow — Global restriction settings. One setting for i1: whether
 * subscriber-only products are hidden from product lists for readers who can't
 * purchase them. This goes beyond NPPD-1899's purchase-only parity (it borders
 * WCM's separate view restriction) and is called out as such in the PR.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	ToggleControl,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Button, Modal } from '../../../../packages/components/src';
import { getRestrictionSettings, saveRestrictionSettings } from '../data/mock-restrictions';

export default function RestrictionSettingsFlow( { onClose, onSaved } ) {
	const [ draft, setDraft ] = useState( getRestrictionSettings );

	const onSave = () => {
		saveRestrictionSettings( draft );
		onSaved( __( 'Restriction settings saved.', 'newspack-plugin' ) );
	};

	return (
		<Modal title={ __( 'Restriction settings', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
			<VStack spacing={ 6 }>
				<ToggleControl
					label={ __( 'Hide from product lists', 'newspack-plugin' ) }
					help={ __(
						'Hide subscriber-only products from search results and product lists for readers who can’t purchase them. Direct links still work.',
						'newspack-plugin'
					) }
					checked={ draft.hideFromCatalog }
					onChange={ hideFromCatalog => setDraft( { ...draft, hideFromCatalog } ) }
					__nextHasNoMarginBottom
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
