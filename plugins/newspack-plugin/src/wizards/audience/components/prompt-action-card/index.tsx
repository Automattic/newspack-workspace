/* globals newspackAudienceCampaigns */
/**
 * Prompt Action Card
 */

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { sprintf, __ } from '@wordpress/i18n';
import { CardMedia } from '@wordpress/components';
import { useEffect, useState, Fragment } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { moreVertical, settings } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { ActionCard, Button, Card, Modal, Notice, TextControl } from '../../../../../packages/components/src';
import PrimaryPromptPopover from '../prompt-popovers/primary';
import PromptSettingsModal from '../settings-modal';
import { placementForPopup } from '../../views/campaigns/utils';
import './style.scss';

/**
 * External dependencies.
 */
import type { ReactNode } from 'react';

type PromptActionCardProps = CampaignsPopupManagement & {
	className?: string;
	description?: ReactNode;
	/** Id of the freshly duplicated prompt, if any. */
	duplicated?: number | null;
	inFlight: boolean;
	prompt?: CampaignsPrompt;
	segments: CampaignsSegment[];
	warning?: ReactNode;
};

const PromptActionCard = ( props: PromptActionCardProps ) => {
	const [ isSettingsModalVisible, setIsSettingsModalVisible ] = useState( false );
	const [ popoverVisibility, setPopoverVisibility ] = useState( false );
	const [ isDuplicatePromptModalVisible, setIsDuplicatePromptModalVisible ] = useState( false );
	const [ duplicateTitle, setDuplicateTitle ] = useState< string | null >( null );

	// The `prompt` prop is always passed by SegmentGroup; the `{}` default only
	// papers over a direct render without it (which would break on `prompt.id`
	// anyway), so it's asserted as a full prompt.
	const {
		className,
		description,
		duplicated,
		duplicatePopup,
		inFlight,
		resetDuplicated,
		prompt = {} as CampaignsPrompt,
		segments,
		warning,
	} = props;
	const { campaign_groups: campaignGroups, id, edit_link: editLink, title } = prompt;

	useEffect( () => {
		if ( isDuplicatePromptModalVisible && ! duplicateTitle ) {
			getDefaultDupicateTitle();
		}
	}, [ isDuplicatePromptModalVisible ] );

	const getDefaultDupicateTitle = async () => {
		const promptToDuplicate = parseInt( String( prompt?.duplicate_of || prompt.id ) );
		try {
			const defaultTitle = await apiFetch< string >( {
				path: `${ newspackAudienceCampaigns.api }/${ promptToDuplicate }/${ prompt.id }/duplicate`,
			} );

			setDuplicateTitle( defaultTitle );
		} catch ( e ) {
			setDuplicateTitle( title + __( ' copy', 'newspack-plugin' ) );
		}
	};

	return (
		<CardMedia>
			<ActionCard
				isSmall
				noBorder
				badge={ placementForPopup( prompt ) }
				className={ className }
				title={ title.length ? decodeEntities( title ) : __( '(no title)', 'newspack-plugin' ) }
				/* Prompts returned by the Campaigns API always carry an edit link. */
				titleLink={ decodeEntities( editLink! ) }
				key={ id }
				description={ description }
				notification={ warning }
				notificationLevel="error"
				actionText={
					<>
						<div className="newspack-audience-campaigns__buttons">
							<Button
								className={ isSettingsModalVisible ? 'popover-active' : undefined }
								onClick={ () => setIsSettingsModalVisible( ! isSettingsModalVisible ) }
								icon={ settings }
								label={ __( 'Prompt settings', 'newspack-plugin' ) }
								tooltipPosition="bottom center"
							/>
							<Button
								className={ popoverVisibility ? 'popover-active' : undefined }
								onClick={ () => setPopoverVisibility( ! popoverVisibility ) }
								icon={ moreVertical }
								label={ __( 'More options', 'newspack-plugin' ) }
								tooltipPosition="bottom center"
							/>
							{ popoverVisibility && (
								<PrimaryPromptPopover
									onFocusOutside={ () => setPopoverVisibility( false ) }
									prompt={ prompt }
									setIsDuplicatePromptModalVisible={ setIsDuplicatePromptModalVisible }
									{ ...props }
								/>
							) }
						</div>
					</>
				}
			/>
			{ isSettingsModalVisible && (
				<PromptSettingsModal
					prompt={ prompt }
					disabled={ inFlight }
					onClose={ () => setIsSettingsModalVisible( false ) }
					segments={ segments }
					updatePopup={ props.updatePopup }
				/>
			) }
			{ isDuplicatePromptModalVisible && (
				<Modal
					className="newspack-popups__duplicate-modal"
					title={
						// Translators: %s: The title of the item.
						sprintf( __( 'Duplicate “%s”', 'newspack-plugin' ), title )
					}
					onRequestClose={ () => {
						setIsDuplicatePromptModalVisible( false );
						setDuplicateTitle( null );
						resetDuplicated();
					} }
				>
					{ duplicated ? (
						<>
							<Notice
								isSuccess
								noticeText={ sprintf(
									// Translators: %s: The title of the item.
									__( 'Duplicate of “%s” created as a draft.', 'newspack-plugin' ),
									title
								) }
							/>
							{ ! campaignGroups && (
								<Notice isWarning noticeText={ __( 'This prompt is currently not assigned to any campaign.', 'newspack-plugin' ) } />
							) }
							<Card buttonsCard noBorder className="justify-end">
								<Button
									isSecondary
									onClick={ () => {
										setIsDuplicatePromptModalVisible( false );
										setDuplicateTitle( null );
										resetDuplicated();
									} }
								>
									{ __( 'Close', 'newspack-plugin' ) }
								</Button>
								<Button isPrimary href={ `/wp-admin/post.php?post=${ duplicated }&action=edit` }>
									{ __( 'Edit', 'newspack-plugin' ) }
								</Button>
							</Card>
						</>
					) : (
						<>
							{ ! campaignGroups && (
								<Notice isWarning noticeText={ __( 'This prompt will not be assigned to any campaign.', 'newspack-plugin' ) } />
							) }
							<TextControl
								disabled={ inFlight || null === duplicateTitle }
								label={ __( 'Title', 'newspack-plugin' ) }
								value={ duplicateTitle }
								onChange={ ( value: string ) => setDuplicateTitle( value ) }
							/>
							<Card buttonsCard noBorder className="justify-end">
								<Button
									disabled={ inFlight }
									isSecondary
									onClick={ () => {
										setIsDuplicatePromptModalVisible( false );
										setDuplicateTitle( null );
										resetDuplicated();
									} }
								>
									{ __( 'Cancel', 'newspack-plugin' ) }
								</Button>
								<Button
									disabled={ inFlight || null === duplicateTitle }
									isPrimary
									onClick={ () => {
										// The button is disabled until `duplicateTitle` is fetched, so it's non-null here.
										// Bug: when the trimmed title is empty, the fallback is the *Promise* returned
										// by the async getDefaultDupicateTitle(), so a Promise is sent as the duplicate
										// title. CampaignsPopupManagement.duplicatePopup types its title accordingly.
										const titleForDuplicate = duplicateTitle!.trim() || getDefaultDupicateTitle();
										duplicatePopup( id, titleForDuplicate );
									} }
								>
									{ __( 'Duplicate', 'newspack-plugin' ) }
								</Button>
							</Card>
						</>
					) }
				</Modal>
			) }
		</CardMedia>
	);
};
export default PromptActionCard;
