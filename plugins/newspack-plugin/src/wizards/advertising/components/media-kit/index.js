/* globals newspack_ads_wizard */

/**
 * Ad Add-ons component
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { ActionCard } from '../../../../../packages/components/src';
import { useState } from 'react';

const MediaKitToggle = () => {
	const [ isInFlight, setInFlight ] = useState( false );
	const [ editURL, setEditURL ] = useState( newspack_ads_wizard.media_kit_page_edit_url );
	const [ pageStatus, setPageStatus ] = useState( newspack_ads_wizard.media_kit_page_status );

	if ( ! newspack_ads_wizard.media_kit_page_status && ! newspack_ads_wizard.media_kit_page_edit_url ) {
		return (
			<Notice isDismissible={ false } status="error">
				{ __( 'Something went wrong, the Media Kit feature is unavailable.', 'newspack-plugin' ) }
			</Notice>
		);
	}

	const isPagePublished = pageStatus === 'publish';

	const toggleMediaKit = () => {
		setInFlight( true );
		apiFetch( {
			path: '/newspack/v1/wizard/billboard/media-kit',
			method: isPagePublished ? 'DELETE' : 'POST',
		} )
			.then( ( { edit_url, page_status } ) => {
				setEditURL( edit_url );
				setPageStatus( page_status );
			} )
			.finally( () => setInFlight( false ) );
	};

	let description = __( 'Media kit page is created but unpublished. Click the link to review and publish.', 'newspack-plugin' );
	let actionText = __( 'Edit Media Kit page', 'newspack-plugin' );
	let toggleEnabled = false;
	switch ( pageStatus ) {
		case 'publish':
			description = __( 'Media Kit page is published. Click the link to edit it, or toggle this card to unpublish.', 'newspack-plugin' );
			toggleEnabled = true;
			break;
		case 'draft':
			actionText = __( 'Review draft page', 'newspack-plugin' );
			break;
		case 'trash':
		case 'non-existent':
			description = __( 'Media Kit page has not been created. Toggle this card to create it.', 'newspack-plugin' );
			toggleEnabled = true;
			break;
	}

	const props = { description, actionText };

	return (
		<ActionCard
			disabled={ isInFlight || ! toggleEnabled }
			isButtonEnabled={ true }
			isMedium
			href={ editURL || null }
			title={ __( 'Media Kit', 'newspack-plugin' ) }
			toggle
			toggleChecked={ Boolean( editURL ) && isPagePublished }
			toggleOnChange={ toggleMediaKit }
			{ ...props }
		/>
	);
};

export default MediaKitToggle;
