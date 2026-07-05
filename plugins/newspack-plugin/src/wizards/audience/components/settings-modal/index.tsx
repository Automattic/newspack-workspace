/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Button, Card, CategoryAutocomplete, Grid, Modal, SelectControl, Settings, hooks } from '../../../../../packages/components/src';
import { frequenciesForPopup, isOverlay, placementsForPopups, overlaySizesForPopups } from '../../views/campaigns/utils';

const { SettingsCard } = Settings;

/**
 * A term as provided by CategoryAutocomplete's onChange: REST terms carry
 * id/name, terms saved by the Campaigns API carry term_id/name.
 */
type AutocompleteTerm = {
	id?: number;
	term_id?: number;
	name?: string;
	value?: string;
};

/**
 * The edited prompt: like CampaignsPrompt, but with the term arrays widened
 * to the shapes CategoryAutocomplete emits.
 */
type PromptConfig = Omit< CampaignsPrompt, 'campaign_groups' | 'categories' | 'tags' | 'segments' > & {
	campaign_groups: AutocompleteTerm[] | null;
	categories: AutocompleteTerm[];
	tags: AutocompleteTerm[];
	segments: AutocompleteTerm[];
};

type PromptSettingsModalProps = {
	prompt: CampaignsPrompt;
	disabled?: boolean;
	onClose: () => void;
	/** Accepted for prop-parity with the caller; unused. */
	segments?: CampaignsSegment[];
	updatePopup: CampaignsPopupManagement[ 'updatePopup' ];
};

const PromptSettingsModal = ( { prompt, disabled, onClose, updatePopup }: PromptSettingsModalProps ) => {
	const [ promptConfig, setPromptConfig ] = hooks.useObjectState< PromptConfig >( prompt );
	const { excluded_categories: excludedCategories = [], excluded_tags: excludedTags = [] }: Partial< CampaignsPrompt[ 'options' ] > =
		promptConfig.options || {};
	const [ showAdvanced, setShowAdvanced ] = useState( 0 < excludedCategories.length || 0 < excludedTags.length || false );

	const handleSave = () => {
		// The widened term arrays are what the Campaigns API accepts for an update.
		updatePopup( promptConfig as CampaignsPrompt ).then( onClose );
	};

	return (
		<Modal title={ prompt.title } onRequestClose={ onClose } size="x-large">
			<Button onClick={ () => onClose() } className="screen-reader-text">
				{ __( 'Close Modal', 'newspack-plugin' ) }
			</Button>
			<Grid gutter={ 64 } columns={ 1 }>
				<SettingsCard
					title={ __( 'Campaigns', 'newspack-plugin' ) }
					description={ __( 'Assign a prompt to one or more campaigns for easier management', 'newspack-plugin' ) }
					columns={ 1 }
					className="newspack-settings__campaigns"
					noBorder
				>
					<CategoryAutocomplete
						disabled={ disabled }
						value={ promptConfig.campaign_groups || [] }
						onChange={ tokens => setPromptConfig( { campaign_groups: tokens } ) }
						label={ __( 'Campaigns', 'newspack-plugin' ) }
						taxonomy="newspack_popups_taxonomy"
						hideLabelFromVision
					/>
				</SettingsCard>

				<SettingsCard
					title={ __( 'Settings', 'newspack-plugin' ) }
					description={ __( 'When and how should the prompt be displayed', 'newspack-plugin' ) }
					columns={ isOverlay( prompt ) ? 3 : 2 }
					className="newspack-settings__settings"
					rowGap={ 16 }
					noBorder
				>
					<SelectControl
						label={ __( 'Frequency', 'newspack-plugin' ) }
						disabled={ disabled }
						onChange={ ( value: string ) => {
							setPromptConfig( { options: { frequency: value } } );
						} }
						options={ frequenciesForPopup() }
						value={ promptConfig.options.frequency }
					/>
					<SelectControl
						label={ isOverlay( prompt ) ? __( 'Position', 'newspack-plugin' ) : __( 'Placement', 'newspack-plugin' ) }
						disabled={ disabled }
						onChange={ ( value: string ) => {
							setPromptConfig( { options: { placement: value } } );
						} }
						options={ placementsForPopups( prompt ) }
						value={ promptConfig.options.placement }
					/>
					{ isOverlay( prompt ) && (
						<SelectControl
							label={ __( 'Size', 'newspack-plugin' ) }
							disabled={ disabled }
							onChange={ ( value: string ) => {
								setPromptConfig( { options: { overlay_size: value } } );
							} }
							options={ overlaySizesForPopups() }
							value={ promptConfig.options.overlay_size }
						/>
					) }
				</SettingsCard>

				<SettingsCard
					title={ __( 'Targeting', 'newspack-plugin' ) }
					description={ () => (
						<>
							{ __( 'Under which conditions should the prompt be displayed', 'newspack-plugin' ) }
							<br />
							{ __(
								'If multiple conditions are set, all will have to be satisfied in order to display the prompt',
								'newspack-plugin'
							) }
						</>
					) }
					className="newspack-settings__targeting"
					columns={ 1 }
					rowGap={ 16 }
					noBorder
				>
					<Grid columns={ 3 } rowGap={ 16 }>
						<CategoryAutocomplete
							disabled={ disabled }
							value={ promptConfig.segments || [] }
							onChange={ tokens => setPromptConfig( { segments: tokens } ) }
							label={ __( 'Segments', 'newspack-plugin' ) }
							taxonomy="popup_segment"
						/>
						<CategoryAutocomplete
							label={ __( 'Post categories', 'newspack-plugin' ) }
							disabled={ disabled }
							hideHelpFromVision
							value={ promptConfig.categories || [] }
							onChange={ tokens => setPromptConfig( { categories: tokens } ) }
							description={ __( 'Prompt will only appear on posts with the specified categories.', 'newspack-plugin' ) }
						/>
						<CategoryAutocomplete
							label={ __( 'Post tags', 'newspack-plugin' ) }
							disabled={ disabled }
							hideHelpFromVision
							taxonomy="tags"
							value={ promptConfig.tags || [] }
							onChange={ tokens => setPromptConfig( { tags: tokens } ) }
							description={ __( 'Prompt will only appear on posts with the specified tags.', 'newspack-plugin' ) }
						/>
					</Grid>
					<div>
						<Button isLink onClick={ () => setShowAdvanced( ! showAdvanced ) }>
							{ sprintf(
								// translators: %s: whether to show or hide advanced settings fields.
								__( '%s Advanced Settings', 'newspack-plugin' ),
								showAdvanced ? __( 'Hide', 'newspack-plugin' ) : __( 'Show', 'newspack-plugin' )
							) }
						</Button>
					</div>
					{ showAdvanced && (
						<Grid columns={ 3 } rowGap={ 16 }>
							<CategoryAutocomplete
								label={ __( 'Category Exclusions', 'newspack-plugin' ) }
								disabled={ disabled }
								hideHelpFromVision
								value={ excludedCategories || [] }
								onChange={ tokens =>
									setPromptConfig( {
										options: {
											// Terms come from the categories REST endpoint, which always returns ids.
											excluded_categories: tokens.map( token => token.id! ),
										},
									} )
								}
								description={ __( 'Prompt will not appear on posts with the specified categories.', 'newspack-plugin' ) }
							/>
							<CategoryAutocomplete
								label={ __( 'Tag Exclusions', 'newspack-plugin' ) }
								disabled={ disabled }
								hideHelpFromVision
								taxonomy="tags"
								value={ excludedTags || [] }
								onChange={ tokens =>
									setPromptConfig( {
										options: {
											// Terms come from the tags REST endpoint, which always returns ids.
											excluded_tags: tokens.map( token => token.id! ),
										},
									} )
								}
								description={ __( 'Prompt will not appear on posts with the specified tags.', 'newspack-plugin' ) }
							/>
						</Grid>
					) }
				</SettingsCard>
			</Grid>

			<Card buttonsCard noBorder className="justify-end">
				<Button onClick={ onClose } variant="secondary">
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				<Button onClick={ handleSave } variant="primary">
					{ __( 'Save', 'newspack-plugin' ) }
				</Button>
			</Card>
		</Modal>
	);
};
export default PromptSettingsModal;
