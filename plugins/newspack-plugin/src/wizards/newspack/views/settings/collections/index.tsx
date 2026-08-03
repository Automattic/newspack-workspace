/**
 * Settings Collections: Global settings for Collections module.
 */

import { __ } from '@wordpress/i18n';
import { useState, useEffect, useMemo } from '@wordpress/element';
import { ExternalLink, ToggleControl } from '@wordpress/components';
import { cleanForSlug } from '@wordpress/url';
import WizardSection from '../../../../wizards-section';
import WizardsActionCard from '../../../../wizards-action-card';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';
import { TextControl, SelectControl, Button, Grid } from '../../../../../../packages/components/src';
import CustomNamingCard from './custom-naming-card';

// Collections per page options.
const COLLECTIONS_PER_PAGE_OPTIONS = [ 12, 18, 24 ];

// Default values for collections settings.
const DEFAULT_COLLECTIONS_SETTINGS: CollectionsSettingsData = {
	// Custom Naming section.
	custom_naming_enabled: false,
	custom_name: '',
	custom_singular_name: '',
	custom_slug: '',
	// Global CTAs section.
	subscribe_link: '',
	order_link: '',
	// Collections Archive section.
	posts_per_page: 12,
	category_filter_label: '',
	highlight_latest: false,
	// Collection Single section.
	articles_block_attrs: {},
	show_cover_story_img: false,
	// Collection Posts section.
	post_indicator_style: 'default',
	card_message: __( "Keep reading. There's plenty more to discover.", 'newspack-plugin' ),
};

// Helper function to extract collection settings from API data with defaults.
const extractCollectionSettings = ( apiData: Partial< CollectionsSettingsData > ): CollectionsSettingsData => ( {
	...DEFAULT_COLLECTIONS_SETTINGS,
	...apiData,
} );

/**
 * Collections settings component.
 */
function Collections() {
	const { description, apiData, isFetching, actionText, apiFetchToggle, errorMessage } = useWizardApiFetchToggle<
		CollectionsSettingsData & { module_enabled_collections: boolean }
	>( {
		path: '/newspack/v1/wizard/newspack-settings/collections',
		apiNamespace: 'newspack-settings/collections',
		refreshOn: [ 'POST' ],
		data: {
			...DEFAULT_COLLECTIONS_SETTINGS,
			module_enabled_collections: false,
		},
		description: __( 'Manage print editions and other collections of content with custom ordering and organization.', 'newspack-plugin' ),
	} );

	const [ settings, setSettings ] = useState< Partial< CollectionsSettingsData > >( DEFAULT_COLLECTIONS_SETTINGS );

	// Sync local state from apiData when it changes.
	useEffect( () => {
		setSettings( extractCollectionSettings( apiData ) );
	}, [ apiData ] );

	const [ isSavingSettings, setIsSavingSettings ] = useState( false );

	// Set isSavingSettings to false when isFetching transitions to false after a save.
	useEffect( () => {
		if ( ! isFetching ) {
			setIsSavingSettings( false );
		}
	}, [ isFetching ] );

	const handleSaveSettings = () => {
		setIsSavingSettings( true );
		apiFetchToggle( { ...apiData, ...settings }, true );
	};

	const updateSetting: FieldChangeHandler< CollectionsSettingsData > = ( key, value ) => {
		setSettings( prev => ( { ...prev, [ key ]: value } ) );
	};

	const updateNestedSetting = < T extends keyof CollectionsSettingsData >(
		parentKey: T,
		childKey: keyof CollectionsSettingsData[ T ],
		value: CollectionsSettingsData[ T ][ keyof CollectionsSettingsData[ T ] ]
	) => {
		setSettings( prev => {
			const parentValue = prev[ parentKey ] || {};
			return {
				...prev,
				[ parentKey ]: { ...( parentValue as object ), [ childKey ]: value },
			};
		} );
	};

	const DEFAULT_SLUG = 'collections';
	const collectionsArchiveUrl = useMemo( () => {
		const slug = cleanForSlug( settings.custom_naming_enabled && settings.custom_slug ? settings.custom_slug : DEFAULT_SLUG ) || DEFAULT_SLUG;
		const base = new URL( window.newspack_urls?.site || window.location.origin );
		base.pathname = base.pathname + ( ! base.pathname.endsWith( '/' ) ? '/' : '' );
		return new URL( slug, base ).toString();
	}, [ settings.custom_naming_enabled, settings.custom_slug ] );

	return (
		<div className="newspack-wizard__sections">
			<h1>{ __( 'Collections Settings', 'newspack-plugin' ) }</h1>

			<WizardsActionCard
				isMedium
				title={ __( 'Collections Module', 'newspack-plugin' ) }
				description={ description }
				disabled={ isFetching }
				actionText={ actionText }
				error={ errorMessage }
				toggleChecked={ apiData.module_enabled_collections }
				toggleOnChange={ ( value: boolean ) => apiFetchToggle( { ...apiData, module_enabled_collections: value }, true ) }
			/>

			{ apiData.module_enabled_collections && (
				<>
					<CustomNamingCard settings={ settings } isSaving={ isSavingSettings } onChange={ updateSetting } />

					<WizardSection
						title={ __( 'Global CTAs', 'newspack-plugin' ) }
						description={ __(
							'Renderd in Collections-related pages. Can be overridden on a per-category or per-collection basis.',
							'newspack-plugin'
						) }
					>
						<Grid columns={ 2 } gutter={ 32 }>
							<TextControl
								label={ __( 'Subscription URL', 'newspack-plugin' ) }
								help={ __(
									'URL for the "Subscribe" button that will be displayed in the Collections archive page when no subscription URL is set for the Collection or its parent category.',
									'newspack-plugin'
								) }
								value={ settings.subscribe_link }
								onChange={ ( value: string ) => updateSetting( 'subscribe_link', value ) }
								placeholder={ `e.g., https://${ window.location.hostname }/subscribe` }
							/>
							<TextControl
								label={ __( 'Order URL', 'newspack-plugin' ) }
								help={ __(
									'URL for the "Order" button that will be displayed in the Collections archive page when no order URL is set for the Collection or its parent category.',
									'newspack-plugin'
								) }
								value={ settings.order_link }
								onChange={ ( value: string ) => updateSetting( 'order_link', value ) }
								placeholder={ `e.g., https://${ window.location.hostname }/order` }
							/>
						</Grid>
					</WizardSection>

					<WizardSection
						title={ __( 'Collections Archive', 'newspack-plugin' ) }
						description={
							<>
								{ __( 'Customize the Collections archive page.', 'newspack-plugin' ) }{ ' ' }
								<ExternalLink href={ collectionsArchiveUrl }>{ __( 'Open archive page', 'newspack-plugin' ) }</ExternalLink>
							</>
						}
					>
						<Grid columns={ 2 } gutter={ 32 }>
							<SelectControl
								label={ __( 'Collections per page', 'newspack-plugin' ) }
								help={ __( 'Number of collections to display per page in the Collections archive page.', 'newspack-plugin' ) }
								value={ settings.posts_per_page }
								onChange={ ( value: number ) => updateSetting( 'posts_per_page', value ) }
								buttonOptions={ COLLECTIONS_PER_PAGE_OPTIONS.map( option => ( {
									label: option.toString(),
									value: option,
								} ) ) }
							/>
							<TextControl
								label={ __( 'Category Filter Label', 'newspack-plugin' ) }
								help={ __(
									'Custom label for the category filter dropdown (e.g., "Collection:", "Type:", "Series:"). Leave empty to use the default "Publication:".',
									'newspack-plugin'
								) }
								value={ settings.category_filter_label }
								onChange={ ( value: string ) => updateSetting( 'category_filter_label', value ) }
								placeholder={ __( 'Publication:', 'newspack-plugin' ) }
							/>
							<ToggleControl
								label={ __( 'Highlight Most Recent Collection', 'newspack-plugin' ) }
								help={ __(
									'Feature the latest Collection prominently at the top of the archive page, showcasing its content and any associated CTAs.',
									'newspack-plugin'
								) }
								checked={ settings.highlight_latest }
								onChange={ ( value: boolean ) => updateSetting( 'highlight_latest', value ) }
							/>
						</Grid>
					</WizardSection>

					<WizardSection
						title={ __( 'Collection Single', 'newspack-plugin' ) }
						description={ __( 'Customize individual collection pages.', 'newspack-plugin' ) }
					>
						<Grid columns={ 2 } gutter={ 32 }>
							<ToggleControl
								label={ __( 'Show Category', 'newspack-plugin' ) }
								help={ __(
									'Display the category information for posts when rendering them on collection pages.',
									'newspack-plugin'
								) }
								checked={ settings.articles_block_attrs?.showCategory || false }
								onChange={ ( value: boolean ) => updateNestedSetting( 'articles_block_attrs', 'showCategory', value ) }
							/>
							<ToggleControl
								label={ __( 'Show Cover Story Images', 'newspack-plugin' ) }
								help={ __(
									'Display featured images for cover stories on collection pages. Individual collections can override this setting.',
									'newspack-plugin'
								) }
								checked={ settings.show_cover_story_img }
								onChange={ ( value: boolean ) => updateSetting( 'show_cover_story_img', value ) }
							/>
						</Grid>
					</WizardSection>

					<WizardSection
						title={ __( 'Collection Posts', 'newspack-plugin' ) }
						description={ __( 'Customize post single pages when they belong to a collection.', 'newspack-plugin' ) }
					>
						<Grid columns={ 2 } gutter={ 32 }>
							<SelectControl
								label={ __( 'Collection Indicator Style', 'newspack-plugin' ) }
								help={ __(
									'How collection indicators should be displayed on posts. When choosing the default style, an indicator with a link will be displayed at the bottom of the post content.',
									'newspack-plugin'
								) }
								value={ settings.post_indicator_style }
								onChange={ ( value: 'default' | 'card' ) => updateSetting( 'post_indicator_style', value ) }
								buttonOptions={ [
									{ label: __( 'Default', 'newspack-plugin' ), value: 'default' },
									{ label: __( 'Card', 'newspack-plugin' ), value: 'card' },
								] }
							/>
							{ settings.post_indicator_style === 'card' && (
								<TextControl
									label={ __( 'Card Message', 'newspack-plugin' ) }
									help={ __(
										'Custom message displayed in the card style indicator, along with the featured image and a button to view the collection.',
										'newspack-plugin'
									) }
									value={ settings.card_message }
									onChange={ ( value: string ) => updateSetting( 'card_message', value ) }
									placeholder={ DEFAULT_COLLECTIONS_SETTINGS.card_message }
								/>
							) }
						</Grid>
					</WizardSection>

					<div className="newspack-buttons-card">
						<Button variant="primary" onClick={ handleSaveSettings } disabled={ isSavingSettings }>
							{ isSavingSettings ? __( 'Saving…', 'newspack-plugin' ) : __( 'Save Settings', 'newspack-plugin' ) }
						</Button>
					</div>
				</>
			) }
		</div>
	);
}

export default Collections;
