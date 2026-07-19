/**
 * Add Campaign header action: primary button plus its create-campaign modal.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ENTER } from '@wordpress/keycodes';

/**
 * Internal dependencies.
 */
import { Button, Card, Modal, TextControl } from '../../../../../../packages/components/src';

const AddCampaignAction = ( { createCampaignGroup } ) => {
	const [ modalVisible, setModalVisible ] = useState( false );
	const [ campaignName, setCampaignName ] = useState( '' );

	useEffect( () => {
		if ( modalVisible ) {
			document.getElementById( 'newspack-add-campaign-header-input' )?.focus();
		}
	}, [ modalVisible ] );

	const submit = () => {
		createCampaignGroup( campaignName );
		setCampaignName( '' );
		setModalVisible( false );
	};

	return (
		<>
			<Button
				variant="primary"
				onClick={ () => {
					setCampaignName( '' );
					setModalVisible( true );
				} }
			>
				{ __( 'Add Campaign', 'newspack-plugin' ) }
			</Button>
			{ modalVisible && (
				<Modal title={ __( 'Add Campaign', 'newspack-plugin' ) } onRequestClose={ () => setModalVisible( false ) }>
					<TextControl
						id="newspack-add-campaign-header-input"
						placeholder={ __( 'Campaign Name', 'newspack-plugin' ) }
						label={ __( 'Campaign Name', 'newspack-plugin' ) }
						hideLabelFromVision={ true }
						value={ campaignName }
						onChange={ setCampaignName }
						onKeyDown={ event => {
							if ( ENTER === event.keyCode && '' !== campaignName ) {
								event.preventDefault();
								submit();
							}
						} }
					/>
					<Card buttonsCard noBorder className="justify-end">
						<Button variant="secondary" onClick={ () => setModalVisible( false ) }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" disabled={ ! campaignName } onClick={ submit }>
							{ __( 'Add', 'newspack-plugin' ) }
						</Button>
					</Card>
				</Modal>
			) }
		</>
	);
};

export default AddCampaignAction;
