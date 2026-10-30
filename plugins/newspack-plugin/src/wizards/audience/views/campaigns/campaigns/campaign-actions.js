/**
 * Header menu for the campaign selected in the Campaign filter.
 */

/**
 * WordPress dependencies.
 */
import { DropdownMenu, MenuGroup, MenuItem, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { ENTER } from '@wordpress/keycodes';
import { moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Button, Modal, TextControl } from '../../../../../../packages/components/src';
import { dataForCampaignId } from '../utils';

const CampaignActions = ( {
	archiveCampaignGroup,
	campaignId,
	campaigns = [],
	deleteCampaignGroup,
	duplicateCampaignGroup,
	manageCampaignGroup,
	prompts = [],
	renameCampaignGroup,
} ) => {
	const [ modalType, setModalType ] = useState( null );
	const [ campaignName, setCampaignName ] = useState( '' );
	const isRename = 'rename' === modalType;

	useEffect( () => {
		if ( modalType ) {
			document.getElementById( 'newspack-campaign-actions-modal-input' )?.focus();
		}
	}, [ modalType ] );

	const campaignData = dataForCampaignId( campaignId, campaigns );
	if ( ! campaignData ) {
		return null;
	}

	const isArchived = 'archive' === campaignData.status;
	const campaignPrompts = prompts.filter(
		( { status, campaign_groups: groups } ) =>
			'trash' !== status && Array.isArray( groups ) && groups.some( term => +term.term_id === +campaignId )
	);

	const submit = () => {
		( isRename ? renameCampaignGroup : duplicateCampaignGroup )( campaignId, campaignName );
		setModalType( null );
	};

	return (
		<>
			<DropdownMenu
				icon={ moreVertical }
				label={ sprintf(
					// Translators: %s: campaign name.
					__( 'Campaign actions: %s', 'newspack-plugin' ),
					campaignData.name
				) }
			>
				{ ( { onClose } ) => (
					<>
						{ campaignPrompts.length > 0 && (
							<MenuGroup>
								<MenuItem
									onClick={ () => {
										onClose();
										manageCampaignGroup( campaignPrompts );
									} }
								>
									{ __( 'Activate All Prompts', 'newspack-plugin' ) }
								</MenuItem>
								{ campaignPrompts.some( ( { status } ) => 'publish' === status ) && (
									<MenuItem
										onClick={ () => {
											onClose();
											manageCampaignGroup( campaignPrompts, 'DELETE' );
										} }
									>
										{ __( 'Deactivate All Prompts', 'newspack-plugin' ) }
									</MenuItem>
								) }
							</MenuGroup>
						) }
						<MenuGroup>
							<MenuItem
								onClick={ () => {
									onClose();
									setCampaignName( '' );
									setModalType( 'duplicate' );
								} }
							>
								{ __( 'Duplicate', 'newspack-plugin' ) }
							</MenuItem>
							<MenuItem
								onClick={ () => {
									onClose();
									setCampaignName( campaignData.name );
									setModalType( 'rename' );
								} }
							>
								{ __( 'Rename', 'newspack-plugin' ) }
							</MenuItem>
							<MenuItem
								onClick={ () => {
									onClose();
									archiveCampaignGroup( campaignId, ! isArchived );
								} }
							>
								{ isArchived ? __( 'Unarchive', 'newspack-plugin' ) : __( 'Archive', 'newspack-plugin' ) }
							</MenuItem>
						</MenuGroup>
						<MenuGroup>
							<MenuItem
								isDestructive
								onClick={ () => {
									onClose();
									deleteCampaignGroup( campaignId );
								} }
							>
								{ __( 'Delete', 'newspack-plugin' ) }
							</MenuItem>
						</MenuGroup>
					</>
				) }
			</DropdownMenu>
			{ modalType && (
				<Modal
					title={ isRename ? __( 'Rename Campaign', 'newspack-plugin' ) : __( 'Duplicate Campaign', 'newspack-plugin' ) }
					onRequestClose={ () => setModalType( null ) }
				>
					<TextControl
						id="newspack-campaign-actions-modal-input"
						placeholder={ __( 'Campaign Name', 'newspack-plugin' ) }
						onChange={ setCampaignName }
						label={ __( 'Campaign Name', 'newspack-plugin' ) }
						hideLabelFromVision={ true }
						value={ campaignName }
						onKeyDown={ event => {
							if ( ENTER === event.keyCode && '' !== campaignName ) {
								event.preventDefault();
								submit();
							}
						} }
					/>
					<HStack justify="flex-end" spacing={ 4 } wrap className="newspack-modal__footer">
						<Button variant="secondary" onClick={ () => setModalType( null ) }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" disabled={ ! campaignName } onClick={ submit }>
							{ isRename ? __( 'Rename', 'newspack-plugin' ) : __( 'Duplicate', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</Modal>
			) }
		</>
	);
};

export default CampaignActions;
