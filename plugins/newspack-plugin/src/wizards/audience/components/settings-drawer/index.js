/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import { Icon, megaphone } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { CategoryAutocomplete, Drawer, SelectControl, hooks } from '../../../../../packages/components/src';
import { frequenciesForPopup, isOverlay, placementsForPopups, overlaySizesForPopups } from '../../views/campaigns/utils';
import './style.scss';

const SectionHeading = ( { title, children } ) => (
	<div className="newspack-campaigns__settings-heading">
		<h3>{ title }</h3>
		<p>{ children }</p>
	</div>
);

const PromptSettingsDrawer = ( { isOpen, prompt, disabled, onClose, updatePopup } ) => {
	const [ promptConfig, setPromptConfig ] = hooks.useObjectState( prompt );
	const { excluded_categories: excludedCategories = [], excluded_tags: excludedTags = [] } = promptConfig.options || {};

	const handleSave = () => {
		updatePopup( promptConfig ).then( onClose );
	};

	return (
		<Drawer.Root
			isOpen={ isOpen }
			size="large"
			isDirty={ isOpen && ( disabled || JSON.stringify( promptConfig ) !== JSON.stringify( prompt ) ) }
			onRequestClose={ onClose }
		>
			<Drawer.Header>
				<Icon className="newspack-drawer__icon" icon={ megaphone } size={ 24 } />
				<Drawer.Title>{ prompt.title.length ? decodeEntities( prompt.title ) : __( '(no title)', 'newspack-plugin' ) }</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				<SectionHeading title={ __( 'Campaigns', 'newspack-plugin' ) }>
					{ __( 'Assign a prompt to one or more campaigns for easier management', 'newspack-plugin' ) }
				</SectionHeading>
				<CategoryAutocomplete
					disabled={ disabled }
					value={ promptConfig.campaign_groups || [] }
					onChange={ tokens => setPromptConfig( { campaign_groups: tokens } ) }
					label={ __( 'Campaigns', 'newspack-plugin' ) }
					taxonomy="newspack_popups_taxonomy"
					hideLabelFromVision
				/>
			</Drawer.Content>
			<Drawer.Divider />
			<Drawer.Content>
				<SectionHeading title={ __( 'Settings', 'newspack-plugin' ) }>
					{ __( 'When and how should the prompt be displayed', 'newspack-plugin' ) }
				</SectionHeading>
				<SelectControl
					label={ __( 'Frequency', 'newspack-plugin' ) }
					disabled={ disabled }
					onChange={ value => {
						setPromptConfig( { options: { frequency: value } } );
					} }
					options={ frequenciesForPopup( prompt ) }
					value={ promptConfig.options.frequency }
				/>
				<SelectControl
					label={ isOverlay( prompt ) ? __( 'Position', 'newspack-plugin' ) : __( 'Placement', 'newspack-plugin' ) }
					disabled={ disabled }
					onChange={ value => {
						setPromptConfig( { options: { placement: value } } );
					} }
					options={ placementsForPopups( prompt ) }
					value={ promptConfig.options.placement }
				/>
				{ isOverlay( prompt ) && (
					<SelectControl
						label={ __( 'Size', 'newspack-plugin' ) }
						disabled={ disabled }
						onChange={ value => {
							setPromptConfig( { options: { overlay_size: value } } );
						} }
						options={ overlaySizesForPopups( prompt ) }
						value={ promptConfig.options.overlay_size }
					/>
				) }
			</Drawer.Content>
			<Drawer.Divider />
			<Drawer.Content>
				<SectionHeading title={ __( 'Targeting', 'newspack-plugin' ) }>
					{ __( 'Under which conditions should the prompt be displayed', 'newspack-plugin' ) }
					<br />
					{ __( 'If multiple conditions are set, all will have to be satisfied in order to display the prompt', 'newspack-plugin' ) }
				</SectionHeading>
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
				<CategoryAutocomplete
					label={ __( 'Category Exclusions', 'newspack-plugin' ) }
					disabled={ disabled }
					hideHelpFromVision
					value={ excludedCategories || [] }
					onChange={ tokens =>
						setPromptConfig( {
							options: {
								excluded_categories: tokens.map( token => token.id ),
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
								excluded_tags: tokens.map( token => token.id ),
							},
						} )
					}
					description={ __( 'Prompt will not appear on posts with the specified tags.', 'newspack-plugin' ) }
				/>
			</Drawer.Content>
			<Drawer.Footer>
				<Drawer.Action variant="secondary" closes>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Drawer.Action>
				<Drawer.Action variant="primary" disabled={ disabled } isBusy={ disabled } onClick={ handleSave }>
					{ __( 'Save', 'newspack-plugin' ) }
				</Drawer.Action>
			</Drawer.Footer>
		</Drawer.Root>
	);
};
export default PromptSettingsDrawer;
