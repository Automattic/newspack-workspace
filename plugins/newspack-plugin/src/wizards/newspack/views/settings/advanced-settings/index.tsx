/**
 * Newspack > Settings > Advanced Settings.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { Notice } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * External dependencies.
 */
import mergeWith from 'lodash/mergeWith';

/**
 * Internal dependencies.
 */
import { ADVANCED_SETTINGS_DEFAULTS } from '../constants';
import WizardsTab from '../../../../wizards-tab';
import { Divider, Grid, SectionHeader, useConfirmDialog, useUnsavedChangesDialog } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import Recirculation from './recirculation';
import AuthorBio from './author-bio';
import Images from './images';
import DefaultTemplates, { TemplateOptions } from './default-templates';
import MediaCredits from './media-credits';
import PostDate from './post-date';
import AccessibilityStatement from './accessibility-statement';
import PwaDisplayMode from './pwa-display-mode';
import PrivateTags from './private-tags';
import PrimaryCategory from './primary-category';

const THEME_PATH = '/newspack/v1/wizard/newspack-setup-wizard/theme';
const PRIMARY_CATEGORY_PATH = '/newspack/v1/wizard/newspack-settings/primary-category';

const DEFAULT_DATA = ADVANCED_SETTINGS_DEFAULTS as AdvancedSettings;

// lodash merges arrays by index, so a shorter array would keep the old trailing entries.
const merge = < T, >( current: T, changes: Partial< T > ): T =>
	mergeWith( {}, current, changes, ( objValue: unknown, srcValue: unknown ) => ( Array.isArray( objValue ) ? srcValue : undefined ) );

// The all-posts choices are one-off actions, not stored settings.
const withoutAllPostsActions = ( settings: AdvancedSettings ): AdvancedSettings => ( {
	...settings,
	featured_image_all_posts: 'none',
	post_template_all_posts: 'none',
} );

// Number fields return strings and the image credits flag is stored as "1" or "", so compare by value.
const comparable = ( settings: AdvancedSettings ) =>
	JSON.stringify( {
		...settings,
		author_bio_length: Number( settings.author_bio_length ),
		post_time_ago_cut_off: Number( settings.post_time_ago_cut_off ),
		post_updated_date_threshold: Number( settings.post_updated_date_threshold ),
		newspack_image_credits_auto_populate: Boolean( settings.newspack_image_credits_auto_populate ),
	} );

type Section =
	| {
			key: string;
			title: string;
			description?: string;
			content: React.ReactNode;
	  }
	| {
			key: string;
			element: React.ReactNode;
	  };

export default function AdvancedSettings() {
	const { setHeaderData, addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ data, setData ] = useState< AdvancedSettings >( DEFAULT_DATA );
	const [ savedData, setSavedData ] = useState< AdvancedSettings >( DEFAULT_DATA );
	const [ etc, setEtc ] = useState< Etc >( { post_count: '0', has_pwa_plugin: false } );

	const [ recirculation, setRecirculation ] = useState< Recirculation >( {
		relatedPostsMaxAge: 0,
		relatedPostsEnabled: false,
		relatedPostsError: null,
		relatedPostsUpdated: false,
	} );
	const [ savedMaxAge, setSavedMaxAge ] = useState( 0 );

	const [ primaryCategory, setPrimaryCategory ] = useState< PrimaryCategoryData >( { enabled: true, yoast_active: false } );
	const [ savedPrimaryCategory, setSavedPrimaryCategory ] = useState( true );

	const [ templateOptions, setTemplateOptions ] = useState< TemplateOptions >( {
		post: [ { label: __( 'Default', 'newspack-plugin' ), value: 'default' } ],
		page: [ { label: __( 'Default', 'newspack-plugin' ), value: 'default' } ],
	} );

	const { wizardApiFetch, isFetching, errorMessage, resetError } = useWizardApiFetch( 'newspack-settings/theme-mods' );
	const {
		wizardApiFetch: wizardApiFetchRecirculation,
		isFetching: isFetchingRecirculation,
		errorMessage: recirculationErrorMessage,
		resetError: resetRecirculationError,
	} = useWizardApiFetch( 'newspack-settings/advanced-settings/recirculation' );
	const {
		wizardApiFetch: wizardApiFetchPrimaryCategory,
		isFetching: isFetchingPrimaryCategory,
		errorMessage: primaryCategoryErrorMessage,
		resetError: resetPrimaryCategoryError,
	} = useWizardApiFetch( 'newspack-settings/primary-category' );
	const { wizardApiFetch: wizardApiFetchTemplateOptions } = useWizardApiFetch( 'newspack-settings/default-templates' );

	const saveErrorMessage = errorMessage || recirculationErrorMessage || primaryCategoryErrorMessage;
	const isAnyFetching = isFetching || isFetchingRecirculation || isFetchingPrimaryCategory;

	const applyThemeMods = ( themeMods: Partial< AdvancedSettings > ) => {
		const next = withoutAllPostsActions( merge( DEFAULT_DATA, themeMods ) );
		setSavedData( next );
		return next;
	};

	useEffect( () => {
		wizardApiFetch< ThemeData >(
			{ path: THEME_PATH },
			{
				onSuccess( { theme_mods, etc: newEtc } ) {
					setData( applyThemeMods( theme_mods ) );
					setEtc( newEtc );
				},
			}
		).catch( () => {} );
		wizardApiFetchRecirculation< Recirculation >(
			{ path: '/newspack/v1/wizard/newspack-settings/related-content' },
			{
				onSuccess( response ) {
					setRecirculation( current => ( { ...current, ...response } ) );
					setSavedMaxAge( Number( response.relatedPostsMaxAge ) || 0 );
				},
			}
		).catch( () => {} );
		wizardApiFetchPrimaryCategory< PrimaryCategoryData >(
			{ path: PRIMARY_CATEGORY_PATH },
			{
				onSuccess( response ) {
					setPrimaryCategory( response );
					setSavedPrimaryCategory( response.enabled );
				},
			}
		).catch( () => {} );
		wizardApiFetchTemplateOptions< TemplateOptions >(
			{ path: '/newspack/v1/wizard/newspack-settings/default-templates' },
			{ onSuccess: setTemplateOptions }
		).catch( () => {} );
	}, [] );

	const update = ( changes: Partial< AdvancedSettings > ) => setData( current => merge( current, changes ) );

	const isThemeModsDirty = comparable( data ) !== comparable( savedData );
	const isRecirculationDirty = recirculation.relatedPostsEnabled && ( Number( recirculation.relatedPostsMaxAge ) || 0 ) !== savedMaxAge;
	const isPrimaryCategoryDirty = primaryCategory.yoast_active && primaryCategory.enabled !== savedPrimaryCategory;
	const isDirty = isThemeModsDirty || isRecirculationDirty || isPrimaryCategoryDirty;
	const isOverwritingPosts = data.featured_image_all_posts !== 'none' || data.post_template_all_posts !== 'none';

	function save() {
		// The fetch hook leaves the error in place on success, so a retry that works would
		// otherwise keep the failed attempt's notice on screen.
		resetError();
		resetRecirculationError();
		resetPrimaryCategoryError();

		const requests: Promise< unknown >[] = [];

		if ( isThemeModsDirty ) {
			const requestData = data;
			requests.push(
				wizardApiFetch< ThemeData >(
					{
						path: THEME_PATH,
						method: 'POST',
						updateCacheMethods: [ 'GET' ],
						data: { theme_mods: requestData },
					},
					{
						onSuccess( { theme_mods } ) {
							const saved = applyThemeMods( theme_mods );
							// Some controls stay editable mid-request; keep any edit made meanwhile.
							setData( current => ( current === requestData ? saved : withoutAllPostsActions( current ) ) );
						},
					}
				)
			);
		}

		if ( isRecirculationDirty ) {
			requests.push(
				wizardApiFetchRecirculation< Pick< Recirculation, 'relatedPostsMaxAge' > >(
					{
						path: '/newspack/v1/wizard/newspack-settings/related-posts-max-age',
						method: 'POST',
						updateCacheKey: {
							'/newspack/v1/wizard/newspack-settings/related-content': 'GET',
						},
						data: { relatedPostsMaxAge: Number( recirculation.relatedPostsMaxAge ) || 0 },
					},
					{
						onSuccess( { relatedPostsMaxAge } ) {
							setSavedMaxAge( Number( relatedPostsMaxAge ) || 0 );
						},
					}
				)
			);
		}

		if ( isPrimaryCategoryDirty ) {
			requests.push(
				wizardApiFetchPrimaryCategory< PrimaryCategoryData >(
					{
						path: PRIMARY_CATEGORY_PATH,
						method: 'POST',
						updateCacheMethods: [ 'GET' ],
						data: primaryCategory,
					},
					{
						onSuccess( response ) {
							setSavedPrimaryCategory( response.enabled );
						},
					}
				)
			);
		}

		// Failures surface through the error notice.
		Promise.all( requests )
			.then( () => {
				removeNotice( 'advanced-settings-saved' );
				addNotice( {
					id: 'advanced-settings-saved',
					type: 'success',
					message: __( 'Settings saved.', 'newspack-plugin' ),
				} );
			} )
			.catch( () => {} );
	}

	// No `when`: ConfirmDialog treats it as a navigation block, hijacking the unsaved-changes dialog.
	const { confirmDialog: overwriteDialog, requestConfirm: requestOverwrite } = useConfirmDialog( {
		title: __( 'Update all posts?', 'newspack-plugin' ),
		confirmButtonText: __( 'Update all posts', 'newspack-plugin' ),
		isDestructive: true,
		message: __(
			'Saving will overwrite the featured image position or template of every existing post. This cannot be undone.',
			'newspack-plugin'
		),
	} );

	const requestSave = () => ( isOverwritingPosts ? requestOverwrite( save ) : save() );

	// The header keeps the callback it was handed, so read the latest render's through a ref.
	const submit = useRef( requestSave );
	submit.current = requestSave;

	useEffect( () => {
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: () => submit.current(),
					disabled: isAnyFetching || ! isDirty,
				},
			],
		} );
	}, [ isAnyFetching, isDirty, setHeaderData ] );

	const {
		confirmDialog: navBlockDialog,
		requestConfirm: requestLeave,
		allowNextUnload,
	} = useUnsavedChangesDialog( {
		when: isDirty && ! isAnyFetching,
	} );

	const sections: ( Section | false )[] = [
		{
			key: 'templates',
			title: __( 'Templates', 'newspack-plugin' ),
			description: __(
				'Choose the templates applied to new posts and pages, or apply one to every existing post. The available templates depend on the active theme.',
				'newspack-plugin'
			),
			content: (
				<DefaultTemplates
					data={ data }
					update={ update }
					options={ templateOptions }
					isFetching={ isFetching }
					postCount={ etc.post_count }
				/>
			),
		},
		primaryCategory.yoast_active && {
			key: 'primary-category',
			title: __( 'Primary Category', 'newspack-plugin' ),
			description: __( 'Choose which categories display on posts.', 'newspack-plugin' ),
			content: (
				<PrimaryCategory
					data={ primaryCategory }
					update={ changes => setPrimaryCategory( current => ( { ...current, ...changes } ) ) }
					isFetching={ isFetchingPrimaryCategory }
				/>
			),
		},
		{
			key: 'post-date',
			title: __( 'Post Date', 'newspack-plugin' ),
			description: __( 'Choose which dates display on posts, and how.', 'newspack-plugin' ),
			content: <PostDate update={ update } data={ data } isFetching={ isFetching } />,
		},
		{
			key: 'images',
			title: __( 'Images', 'newspack-plugin' ),
			description: __( 'Set where featured images appear on posts, and choose an image to show when one cannot be found.', 'newspack-plugin' ),
			content: <Images data={ data } update={ update } isFetching={ isFetching } postCount={ etc.post_count } />,
		},
		{
			key: 'media-credits',
			title: __( 'Media Credits', 'newspack-plugin' ),
			description: __( 'Set how credits display on images across your site.', 'newspack-plugin' ),
			content: <MediaCredits data={ data } update={ update } isFetching={ isFetching } />,
		},
		{
			key: 'author-bio',
			title: __( 'Author Bio', 'newspack-plugin' ),
			description: __( 'Show details about the author below each post.', 'newspack-plugin' ),
			content: <AuthorBio update={ update } data={ data } isFetching={ isFetching } />,
		},
		{
			key: 'recirculation',
			title: __( 'Recirculation', 'newspack-plugin' ),
			description: __( 'Automatically add related content from Jetpack at the bottom of each post.', 'newspack-plugin' ),
			content: (
				<Recirculation
					isFetching={ isFetchingRecirculation }
					update={ changes => setRecirculation( current => ( { ...current, ...changes } ) ) }
					data={ recirculation }
					requestLeave={ requestLeave }
					allowNextUnload={ allowNextUnload }
				/>
			),
		},
		!! data.newspack_private_tags_settings && {
			key: 'private-tags',
			title: __( 'Private Tags', 'newspack-plugin' ),
			description: __( 'Choose where private tags are hidden on your site and in integrations.', 'newspack-plugin' ),
			content: <PrivateTags data={ data } update={ update } isFetching={ isFetching } />,
		},
		{
			key: 'accessibility-statement',
			element: <AccessibilityStatement isFetching={ isFetching } />,
		},
		!! etc.has_pwa_plugin && {
			key: 'pwa',
			title: __( 'Progressive Web App', 'newspack-plugin' ),
			description: __( 'Choose how your site appears when readers install it as a Progressive Web App on their devices.', 'newspack-plugin' ),
			content: <PwaDisplayMode data={ data } update={ update } isFetching={ isFetching } />,
		},
	];

	return (
		<WizardsTab isFetching={ isAnyFetching }>
			{ navBlockDialog }
			{ overwriteDialog }
			{ saveErrorMessage && (
				<Notice status="error" isDismissible={ false } politeness="polite">
					{ saveErrorMessage }
				</Notice>
			) }
			{ sections
				.filter( ( section ): section is Section => Boolean( section ) )
				.map( ( section, index ) => (
					<Fragment key={ section.key }>
						{ index > 0 && <Divider alignment="full-width" variant="tertiary" /> }
						{ 'element' in section ? (
							section.element
						) : (
							<Grid columns={ 2 } gutter={ 32 } noMargin>
								<SectionHeader noMargin heading={ 2 } title={ section.title } description={ section.description } />
								<Stack direction="column" gap="xl">
									{ section.content }
								</Stack>
							</Grid>
						) }
					</Fragment>
				) ) }
		</WizardsTab>
	);
}
