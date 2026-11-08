/* globals newspackAudienceCampaigns */
/**
 * Prompt Action Card
 */

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { sprintf, __ } from '@wordpress/i18n';
import {
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Notice as CoreNotice,
	Tooltip,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useContext, useEffect, useState, Fragment } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { moreVertical, settings } from '@wordpress/icons';
import { Badge, Card, Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, EditTitleLink, Modal, Notice, TextControl } from '../../../../../packages/components/src';
import PromptSettingsDrawer from '../settings-drawer';
import { abTestBadge, promptDetails, promptStatus } from '../../views/campaigns/utils';
import ABTestsContext from '../../views/campaigns/ab-tests/context';
import StartTestModal from '../../views/campaigns/ab-tests/start-test-modal';
import { openVersionB, testCardHash } from '../../views/campaigns/ab-tests/utils';
import './style.scss';

const PromptActionCard = props => {
	const [ isSettingsOpen, setIsSettingsOpen ] = useState( false );
	const [ settingsSession, setSettingsSession ] = useState( 0 );
	const [ isDuplicatePromptModalVisible, setIsDuplicatePromptModalVisible ] = useState( false );
	const [ duplicateTitle, setDuplicateTitle ] = useState( null );
	const [ isStartTestModalVisible, setIsStartTestModalVisible ] = useState( false );
	const abTests = useContext( ABTestsContext );

	const {
		deletePopup,
		duplicated,
		duplicatePopup,
		inFlight,
		previewPopup,
		prompt = {},
		publishPopup,
		resetDuplicated,
		restorePopup,
		segmentDisabled,
		unpublishPopup,
		warning,
	} = props;
	const { campaign_groups: campaignGroups, id, edit_link: editLink, status, title } = prompt;
	const isPublished = 'publish' === status;
	const isTrash = 'trash' === status;
	const displayTitle = title.length ? decodeEntities( title ) : __( '(no title)', 'newspack-plugin' );
	const { label: statusLabel, intent: statusIntent } = promptStatus( prompt, segmentDisabled );
	const abBadge = abTestBadge( prompt );
	// Null while A/B testing is off, or for a prompt the list route didn't return.
	const abState = abTests?.prompts?.find( item => item.id === id ) || null;

	const renderABTestItem = onClose => {
		if ( ! abState ) {
			return null;
		}
		if ( abState.test_id ) {
			return (
				<MenuItem
					onClick={ () => {
						onClose();
						window.location.hash = testCardHash( abState.test_id );
					} }
				>
					{ __( 'View A/B Test', 'newspack-plugin' ) }
				</MenuItem>
			);
		}
		if ( abState.ineligible_reason ) {
			return (
				<Tooltip text={ abState.ineligible_reason } placement="left">
					<MenuItem disabled accessibleWhenDisabled describedBy={ abState.ineligible_reason }>
						{ __( 'Start A/B Test', 'newspack-plugin' ) }
					</MenuItem>
				</Tooltip>
			);
		}
		return (
			<MenuItem
				onClick={ () => {
					onClose();
					setIsStartTestModalVisible( true );
				} }
			>
				{ __( 'Start A/B Test', 'newspack-plugin' ) }
			</MenuItem>
		);
	};

	useEffect( () => {
		if ( isDuplicatePromptModalVisible && ! duplicateTitle ) {
			getDefaultDupicateTitle();
		}
	}, [ isDuplicatePromptModalVisible ] );

	const getDefaultDupicateTitle = async () => {
		const promptToDuplicate = parseInt( prompt?.duplicate_of || prompt.id );
		try {
			const defaultTitle = await apiFetch( {
				path: `${ newspackAudienceCampaigns.api }/${ promptToDuplicate }/${ prompt.id }/duplicate`,
			} );

			setDuplicateTitle( defaultTitle );
		} catch ( e ) {
			setDuplicateTitle( title + __( ' copy', 'newspack-plugin' ) );
		}
	};

	return (
		<Card.Content className="newspack-campaigns__segment-group__content newspack-campaigns__prompt">
			<Stack direction="column" gap="md">
				<Stack direction="row" gap="md" wrap="wrap" align="center" justify="space-between">
					<Stack direction="column" gap="xs" className="newspack-campaigns__prompt-summary">
						<Stack direction="row" gap="sm" wrap="wrap" align="center" className="newspack-edit-title-link-row">
							<h3 className="newspack-campaigns__prompt-title">
								{ isTrash ? displayTitle : <EditTitleLink href={ decodeEntities( editLink ) }>{ displayTitle }</EditTitleLink> }
							</h3>
							<Badge intent={ statusIntent }>{ statusLabel }</Badge>
							{ abBadge && <Badge intent={ abBadge.intent }>{ abBadge.label }</Badge> }
						</Stack>
						<div className="newspack-campaigns__details">
							{ promptDetails( prompt ).map( ( { label, value } ) => (
								<span key={ label }>
									<span className="newspack-campaigns__details-key">{ label }</span>{ ' ' }
									<span className="newspack-campaigns__details-value">{ value }</span>
								</span>
							) ) }
						</div>
					</Stack>
					<Stack direction="row" gap="sm" align="center">
						{ ! isTrash && (
							<Button
								icon={ settings }
								size="compact"
								label={ sprintf(
									// Translators: %s: prompt title.
									__( 'Prompt settings: %s', 'newspack-plugin' ),
									displayTitle
								) }
								onClick={ () => {
									setSettingsSession( session => session + 1 );
									setIsSettingsOpen( true );
								} }
							/>
						) }
						<DropdownMenu
							icon={ moreVertical }
							toggleProps={ { size: 'compact' } }
							label={ sprintf(
								// Translators: %s: prompt title.
								__( 'More options: %s', 'newspack-plugin' ),
								displayTitle
							) }
						>
							{ ( { onClose } ) =>
								isTrash ? (
									<MenuGroup
										label={
											// Translators: %d: prompt ID.
											sprintf( __( 'ID: %d', 'newspack-plugin' ), id )
										}
									>
										<MenuItem
											onClick={ () => {
												onClose();
												restorePopup( id );
											} }
										>
											{ __( 'Restore', 'newspack-plugin' ) }
										</MenuItem>
										<MenuItem
											isDestructive
											onClick={ () => {
												onClose();
												deletePopup( id );
											} }
										>
											{ __( 'Delete Permanently', 'newspack-plugin' ) }
										</MenuItem>
									</MenuGroup>
								) : (
									<>
										<MenuGroup
											label={
												// Translators: %d: prompt ID.
												sprintf( __( 'ID: %d', 'newspack-plugin' ), id )
											}
										>
											<MenuItem
												onClick={ () => {
													onClose();
													previewPopup( prompt );
												} }
											>
												{ __( 'Preview', 'newspack-plugin' ) }
											</MenuItem>
											<MenuItem href={ decodeEntities( editLink ) }>{ __( 'Edit', 'newspack-plugin' ) }</MenuItem>
											<MenuItem
												onClick={ () => {
													onClose();
													setIsDuplicatePromptModalVisible( true );
												} }
											>
												{ __( 'Duplicate', 'newspack-plugin' ) }
											</MenuItem>
											{ renderABTestItem( onClose ) }
											<MenuItem
												onClick={ () => {
													onClose();
													( isPublished ? unpublishPopup : publishPopup )( id );
												} }
											>
												{ isPublished ? __( 'Deactivate', 'newspack-plugin' ) : __( 'Activate', 'newspack-plugin' ) }
											</MenuItem>
										</MenuGroup>
										<MenuGroup>
											<MenuItem
												isDestructive
												onClick={ () => {
													onClose();
													deletePopup( id );
												} }
											>
												{ __( 'Delete', 'newspack-plugin' ) }
											</MenuItem>
										</MenuGroup>
									</>
								)
							}
						</DropdownMenu>
					</Stack>
				</Stack>
				{ warning && (
					<CoreNotice status="warning" isDismissible={ false } spokenMessage="" className="newspack-campaigns__prompt-warning">
						{ warning }
					</CoreNotice>
				) }
			</Stack>
			<PromptSettingsDrawer
				key={ settingsSession }
				isOpen={ isSettingsOpen }
				prompt={ prompt }
				disabled={ inFlight }
				onClose={ () => setIsSettingsOpen( false ) }
				updatePopup={ props.updatePopup }
			/>
			{ isStartTestModalVisible && abState && (
				<StartTestModal
					promptId={ id }
					testName={ title.length ? decodeEntities( title ) : '' }
					goals={ abTests?.goals || {} }
					suggestedGoal={ abState.suggested_goal }
					intro={ sprintf(
						// Translators: %s is the title of the prompt that becomes version A.
						__(
							'Version B starts as a draft copy of “%s” with the same placement. Change what you want to compare, then publish it to start the test.',
							'newspack-plugin'
						),
						displayTitle
					) }
					onCreated={ openVersionB }
					onClose={ () => setIsStartTestModalVisible( false ) }
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
							<HStack justify="flex-end" spacing={ 4 } wrap className="newspack-modal__footer">
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
							</HStack>
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
								onChange={ value => setDuplicateTitle( value ) }
							/>
							<HStack justify="flex-end" spacing={ 4 } wrap className="newspack-modal__footer">
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
										const titleForDuplicate = duplicateTitle.trim() || getDefaultDupicateTitle();
										duplicatePopup( id, titleForDuplicate );
									} }
								>
									{ __( 'Duplicate', 'newspack-plugin' ) }
								</Button>
							</HStack>
						</>
					) }
				</Modal>
			) }
		</Card.Content>
	);
};
export default PromptActionCard;
