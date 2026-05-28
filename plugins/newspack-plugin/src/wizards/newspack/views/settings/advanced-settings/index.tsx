/**
 * Newspack > Settings > Advanced Settings.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { ADVANCED_SETTINGS_DEFAULTS } from '../constants';
import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';
import { Button, Grid, hooks, ImageUpload, Notice, utils } from '../../../../../../packages/components/src';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import Recirculation from './recirculation';
import AuthorBio from './author-bio';
import FeaturedImagePostsAll from './featured-image-posts-all';
import FeaturedImagePostsNew from './featured-image-posts-new';
import MediaCredits from './media-credits';
import PostDate from './post-date';
import AccessibilityStatement from './accessibility-statement';
import PwaDisplayMode from './pwa-display-mode';
import PrivateTags from './private-tags';
import PrimaryCategory from './primary-category';

export default function AdvancedSettings() {
	const [ data, setData ] = hooks.useObjectState< AdvancedSettings >( {
		...ADVANCED_SETTINGS_DEFAULTS,
	} );
	const [ etc, setEtc ] = hooks.useObjectState< Etc >( {
		post_count: '0',
		has_pwa_plugin: false,
	} );

	const [ recirculationData, setRecirculationData ] = hooks.useObjectState< Recirculation >( {
		relatedPostsMaxAge: 0,
		relatedPostsEnabled: false,
		relatedPostsError: null,
		relatedPostsUpdated: false,
	} );

	const { wizardApiFetch, isFetching, errorMessage } = useWizardApiFetch( 'newspack-settings/theme-mods' );
	const { wizardApiFetch: wizardApiFetchRecirculation, isFetching: isFetchingRecirculation } = useWizardApiFetch(
		'newspack-settings/advanced-settings/recirculation'
	);
	const { wizardApiFetch: wizardApiFetchPrimaryCategory, isFetching: isFetchingPrimaryCategory } =
		useWizardApiFetch( 'newspack-settings/primary-category' );

	const [ primaryCategoryData, setPrimaryCategoryData ] = hooks.useObjectState< PrimaryCategoryData >( {
		enabled: true,
		yoast_active: false,
	} );

	const fetchThemeMods = () => {
		wizardApiFetch< ThemeData >(
			{
				path: '/newspack/v1/wizard/newspack-setup-wizard/theme',
			},
			{
				onSuccess( { theme_mods, etc: newEtc } ) {
					setData( theme_mods );
					setEtc( newEtc );
				},
			}
		);
	};

	useEffect( () => {
		fetchThemeMods();
		wizardApiFetchRecirculation< Recirculation >(
			{
				path: '/newspack/v1/wizard/newspack-settings/related-content',
			},
			{
				onSuccess: setRecirculationData,
			}
		);
		wizardApiFetchPrimaryCategory< PrimaryCategoryData >(
			{
				path: '/newspack/v1/wizard/newspack-settings/primary-category',
			},
			{
				onSuccess: setPrimaryCategoryData,
			}
		);
	}, [] );

	function save() {
		wizardApiFetchRecirculation(
			{
				path: '/newspack/v1/wizard/newspack-settings/related-posts-max-age',
				method: 'POST',
				updateCacheKey: {
					'/newspack/v1/wizard/newspack-settings/related-content': 'GET',
				},
				data: recirculationData,
			},
			{
				onSuccess: setRecirculationData,
			}
		);
		if ( data.featured_image_all_posts !== 'none' || data.post_template_all_posts !== 'none' ) {
			if (
				! utils.confirmAction(
					__( 'Saving will overwrite existing posts, this cannot be undone. Are you sure you want to proceed?', 'newspack-plugin' )
				)
			) {
				return;
			}
		}
		wizardApiFetch(
			{
				path: '/newspack/v1/wizard/newspack-setup-wizard/theme',
				method: 'POST',
				updateCacheMethods: [ 'GET' ],
				data: { theme_mods: data },
			},
			{
				onSuccess: savedData => {
					setData( {
						...savedData,
						// Strange UX behavior: if the user saves the settings with the "all posts" options selected, the settings are reset to "none".
						featured_image_all_posts: 'none',
						post_template_all_posts: 'none',
					} );
				},
			}
		);
		if ( primaryCategoryData.yoast_active ) {
			wizardApiFetchPrimaryCategory< PrimaryCategoryData >(
				{
					path: '/newspack/v1/wizard/newspack-settings/primary-category',
					method: 'POST',
					updateCacheMethods: [ 'GET' ],
					data: primaryCategoryData,
				},
				{
					onSuccess: setPrimaryCategoryData,
				}
			);
		}
	}

	return (
		<WizardsTab
			title={ __( 'Advanced Settings', 'newspack-plugin' ) }
			isFetching={ isFetching || isFetchingRecirculation || isFetchingPrimaryCategory }
		>
			<WizardSection title={ __( 'Recirculation', 'newspack-plugin' ) }>
				<Recirculation isFetching={ isFetchingRecirculation } update={ setRecirculationData } data={ recirculationData } />
			</WizardSection>

			<WizardSection title={ __( 'Author Bio', 'newspack-plugin' ) }>
				<AuthorBio update={ setData } data={ data } isFetching={ isFetching } />
			</WizardSection>
			<WizardSection
				title={ __( 'Default Featured Image Position and Post Template', 'newspack-plugin' ) }
				description={ __( 'Modify how the featured image and post template settings are applied to new posts.', 'newspack-plugin' ) }
			>
				<FeaturedImagePostsNew data={ data } update={ setData } />
			</WizardSection>
			<WizardSection
				title={ __( 'Featured Image Position And Post Template For All Posts', 'newspack-plugin' ) }
				description={ __(
					'Modify how the featured image and post template settings are applied to existing posts. Warning: saving these options will override all posts.',
					'newspack-plugin'
				) }
			>
				<FeaturedImagePostsAll data={ data } postCount={ etc.post_count } update={ setData } />
			</WizardSection>
			<WizardSection title={ __( 'Post Date', 'newspack-plugin' ) }>
				<PostDate update={ setData } data={ data } isFetching={ isFetching } />
			</WizardSection>
			{ primaryCategoryData.yoast_active ? (
				<WizardSection title={ __( 'Primary Category', 'newspack-plugin' ) }>
					<PrimaryCategory data={ primaryCategoryData } update={ setPrimaryCategoryData } isFetching={ isFetchingPrimaryCategory } />
				</WizardSection>
			) : null }
			<WizardSection title={ __( 'Media Credits', 'newspack-plugin' ) }>
				<MediaCredits data={ data } update={ setData } />
			</WizardSection>
			<WizardSection
				title={ __( 'Image fallback', 'newspack-plugin' ) }
				description={ __( 'Select a fallback image to display when an image inside post content cannot be found.', 'newspack-plugin' ) }
			>
				<Grid>
					<ImageUpload
						image={ data.post_content_fallback_image ? { url: data.post_content_fallback_image } : null }
						buttonLabel={ __( 'Select', 'newspack-plugin' ) }
						onChange={ ( image: null | PlaceholderImage ) => setData( { post_content_fallback_image: image?.url || null } ) }
					/>
				</Grid>
			</WizardSection>
			<WizardSection>
				<AccessibilityStatement isFetching={ isFetching } />
			</WizardSection>
			{ etc.has_pwa_plugin ? (
				<WizardSection title={ __( 'Progressive Web App', 'newspack-plugin' ) }>
					<PwaDisplayMode data={ data } update={ setData } isFetching={ isFetching } />
				</WizardSection>
			) : null }
			{ data.newspack_private_tags_settings ? (
				<WizardSection title={ __( 'Private Tags', 'newspack-plugin' ) }>
					<PrivateTags data={ data } update={ setData } isFetching={ isFetching } />
				</WizardSection>
			) : null }
			{ errorMessage && <Notice /> }
			<div className="newspack-buttons-card">
				<Button variant="primary" onClick={ save }>
					{ __( 'Save', 'newspack-plugin' ) }
				</Button>
				<Button variant="tertiary" href="/wp-admin/customize.php">
					{ __( 'Open Customizer', 'newspack-plugin' ) }
				</Button>
			</div>
		</WizardsTab>
	);
}
