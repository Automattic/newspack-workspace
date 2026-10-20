/**
 * Newspack > Settings > Collections
 */

/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import {
	ExternalLink,
	Notice,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { Fragment, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { cleanForSlug } from '@wordpress/url';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import WizardsTab from '../../../../wizards-tab';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';
import EmptyState from '../../../../../../packages/components/src/empty-state';
import {
	Button,
	Divider,
	Grid,
	SectionHeader,
	TextControl,
	Waiting,
	useConfirmDialog,
	useUnsavedChangesDialog,
} from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { collections } from '../../../../../../packages/icons';
import ChoiceToggle from '../advanced-settings/choice-toggle';

const COLLECTIONS_PER_PAGE_OPTIONS = [ 12, 18, 24, 30 ];
const DEFAULT_SLUG = 'collections';
const DISABLED_NOTICE_KEY = 'newspack-collections-disabled';
const DEFAULT_CARD_MESSAGE = __( "Keep reading. There's plenty more to discover.", 'newspack-plugin' );

const DEFAULT_SETTINGS: CollectionsSettingsData = {
	custom_naming_enabled: false,
	custom_name: '',
	custom_singular_name: '',
	custom_slug: '',
	subscribe_link: '',
	order_link: '',
	posts_per_page: 12,
	category_filter_label: '',
	highlight_latest: false,
	articles_block_attrs: {},
	show_cover_story_img: false,
	post_indicator_style: 'default',
	card_message: '',
};

// The response also carries every other optional module's flag, which is not ours to send back.
const toSettings = ( data: Partial< CollectionsSettingsData > ): CollectionsSettingsData =>
	Object.fromEntries(
		( Object.keys( DEFAULT_SETTINGS ) as ( keyof CollectionsSettingsData )[] ).map( key => [ key, data[ key ] ?? DEFAULT_SETTINGS[ key ] ] )
	) as CollectionsSettingsData;

const hiddenFields = ( settings: CollectionsSettingsData ): ( keyof CollectionsSettingsData )[] => [
	...( settings.custom_naming_enabled ? [] : ( [ 'custom_name', 'custom_singular_name', 'custom_slug' ] as const ) ),
	...( settings.post_indicator_style === 'card' ? [] : ( [ 'card_message' ] as const ) ),
];

// Edits to fields the current choices hide are not changes the publisher can see, so they are neither counted nor saved.
const withSavedHiddenFields = ( draft: CollectionsSettingsData, saved: CollectionsSettingsData ): CollectionsSettingsData => ( {
	...draft,
	...Object.fromEntries( hiddenFields( draft ).map( key => [ key, saved[ key ] ] ) ),
} );

const comparable = ( settings: CollectionsSettingsData ) =>
	JSON.stringify( {
		...settings,
		posts_per_page: Number( settings.posts_per_page ),
		articles_block_attrs: { showCategory: Boolean( settings.articles_block_attrs?.showCategory ) },
	} );

type Section = {
	key: string;
	title: string;
	description?: React.ReactNode;
	content: React.ReactNode;
};

function Collections() {
	const { apiData, hasLoaded, isFetching, apiFetchToggle, errorMessage, resetError } = useWizardApiFetchToggle<
		CollectionsSettingsData & { module_enabled_collections: boolean }
	>( {
		path: '/newspack/v1/wizard/newspack-settings/collections',
		apiNamespace: 'newspack-settings/collections',
		data: {
			...DEFAULT_SETTINGS,
			module_enabled_collections: false,
		},
	} );
	const { setHeaderData, addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	// Disabling reloads the page to update the admin menu, so its confirmation is raised on the next load.
	useEffect( () => {
		try {
			if ( ! window.sessionStorage.getItem( DISABLED_NOTICE_KEY ) ) {
				return;
			}
			window.sessionStorage.removeItem( DISABLED_NOTICE_KEY );
		} catch {
			return;
		}
		addNotice( {
			id: 'collections-disabled',
			type: 'success',
			message: __( 'Collections disabled.', 'newspack-plugin' ),
		} );
	}, [ addNotice ] );

	const isEnabled = apiData.module_enabled_collections;
	const savedSettings = useMemo( () => toSettings( apiData ), [ apiData ] );

	const [ settings, setSettings ] = useState< CollectionsSettingsData >( savedSettings );
	useEffect( () => {
		setSettings( savedSettings );
	}, [ savedSettings ] );

	const [ isReloading, setIsReloading ] = useState( false );
	const isBusy = isFetching || isReloading;

	const settingsToSave = withSavedHiddenFields( settings, savedSettings );
	const isDirty = comparable( settingsToSave ) !== comparable( savedSettings );

	const update = ( changes: Partial< CollectionsSettingsData > ) => setSettings( current => ( { ...current, ...changes } ) );

	const { confirmDialog: navBlockDialog, allowNextUnload } = useUnsavedChangesDialog( { when: isDirty && ! isBusy } );

	const reload = () => {
		setIsReloading( true );
		allowNextUnload();
		window.location.reload();
	};

	// Failures reach the publisher through `errorMessage`, so the rejection the
	// API layer re-throws has no second consumer here.
	const setModuleEnabled = ( value: boolean ) => {
		resetError();
		return apiFetchToggle( { module_enabled_collections: value }, true )
			.then( () => {
				if ( ! value ) {
					try {
						window.sessionStorage.setItem( DISABLED_NOTICE_KEY, '1' );
					} catch {}
				}
				reload();
			} )
			.catch( () => undefined );
	};

	const saveSettings = () => {
		resetError();
		return apiFetchToggle( { module_enabled_collections: true, ...settingsToSave }, true )
			.then( () => {
				removeNotice( 'collections-saved' );
				addNotice( {
					id: 'collections-saved',
					type: 'success',
					message: __( 'Settings saved.', 'newspack-plugin' ),
				} );
			} )
			.catch( () => undefined );
	};

	const { confirmDialog: disableDialog, requestConfirm: requestDisable } = useConfirmDialog( {
		title: __( 'Disable Collections?', 'newspack-plugin' ),
		confirmButtonText: __( 'Disable', 'newspack-plugin' ),
		message: isDirty
			? __(
					'Collection pages and the archive will no longer be available to readers. Your saved settings are kept, but unsaved changes will be lost.',
					'newspack-plugin'
			  )
			: __( 'Collection pages and the archive will no longer be available to readers. Your settings are kept.', 'newspack-plugin' ),
	} );

	// The header keeps whichever callbacks it was handed, so publishing these
	// directly would pin the state of the render that published them.
	const actionHandlers = useRef( { saveSettings, setModuleEnabled } );
	actionHandlers.current = { saveSettings, setModuleEnabled };

	useEffect( () => {
		if ( ! isEnabled ) {
			setHeaderData( { actions: [] } );
			return;
		}
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: () => actionHandlers.current.saveSettings(),
					disabled: isBusy || ! isDirty,
				},
				{
					type: 'more',
					label: __( 'Disable', 'newspack-plugin' ),
					/* translators: must contain the menu item's visible label, "Disable" (WCAG 2.5.3, Label in Name). */
					ariaLabel: __( 'Disable Collections', 'newspack-plugin' ),
					action: () => requestDisable( () => actionHandlers.current.setModuleEnabled( false ) ),
					disabled: isBusy,
				},
			],
		} );
	}, [ isEnabled, isDirty, isBusy, requestDisable, setHeaderData ] );

	const archiveUrl = useMemo( () => {
		const slug =
			cleanForSlug( savedSettings.custom_naming_enabled && savedSettings.custom_slug ? savedSettings.custom_slug : DEFAULT_SLUG ) ||
			DEFAULT_SLUG;
		const base = new URL( window.newspack_urls?.site || window.location.origin );
		base.pathname = base.pathname + ( ! base.pathname.endsWith( '/' ) ? '/' : '' );
		return new URL( slug, base ).toString();
	}, [ savedSettings.custom_naming_enabled, savedSettings.custom_slug ] );

	if ( ! hasLoaded ) {
		return (
			<WizardsTab>
				<Waiting isCenter />
			</WizardsTab>
		);
	}

	const errorNotice = errorMessage && (
		<Notice status="error" isDismissible={ false } politeness="polite">
			{ errorMessage }
		</Notice>
	);

	if ( ! isEnabled ) {
		return (
			<WizardsTab isFetching={ isBusy }>
				{ errorNotice }
				<EmptyState.Root>
					<EmptyState.Header
						icon={ collections }
						title={ __( 'Organize content into collections', 'newspack-plugin' ) }
						description={ __(
							'Publish print editions, magazines and other issues as collections, each with its own page and a browsable archive.',
							'newspack-plugin'
						) }
					/>
					<EmptyState.Actions>
						<Button
							variant="primary"
							accessibleWhenDisabled
							loading={ isBusy }
							disabled={ isBusy }
							onClick={ () => setModuleEnabled( true ) }
						>
							{ __( 'Enable', 'newspack-plugin' ) }
						</Button>
					</EmptyState.Actions>
				</EmptyState.Root>
			</WizardsTab>
		);
	}

	const sections: Section[] = [
		{
			key: 'naming',
			title: __( 'Naming', 'newspack-plugin' ),
			description: __(
				'Choose what readers see collections called on the site, such as issues or magazines. The dashboard keeps calling them Collections.',
				'newspack-plugin'
			),
			content: (
				<>
					<ToggleGroupControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						isBlock
						label={ __( 'Naming', 'newspack-plugin' ) }
						help={
							settings.custom_naming_enabled
								? __( 'Use your own names and URL slug, set below.', 'newspack-plugin' )
								: sprintf(
										/* translators: %s: the default URL slug, "collections". */
										__( 'Readers see "Collections" and "Collection", with URLs under /%s/.', 'newspack-plugin' ),
										DEFAULT_SLUG
								  )
						}
						value={ settings.custom_naming_enabled ? 'custom' : 'default' }
						onChange={ value => update( { custom_naming_enabled: value === 'custom' } ) }
					>
						<ToggleGroupControlOption value="default" label={ __( 'Default', 'newspack-plugin' ) } disabled={ isBusy } />
						<ToggleGroupControlOption value="custom" label={ __( 'Custom', 'newspack-plugin' ) } disabled={ isBusy } />
					</ToggleGroupControl>
					{ settings.custom_naming_enabled && (
						<>
							<TextControl
								withMargin={ false }
								label={ __( 'Plural name', 'newspack-plugin' ) }
								help={ __( 'Used instead of "Collections", for example "Issues" or "Magazines".', 'newspack-plugin' ) }
								value={ settings.custom_name }
								disabled={ isBusy }
								onChange={ ( custom_name: string ) => update( { custom_name } ) }
								placeholder={ _x( 'Collections', 'collections general label', 'newspack-plugin' ) }
							/>
							<TextControl
								withMargin={ false }
								label={ __( 'Singular name', 'newspack-plugin' ) }
								help={ __( 'Used instead of "Collection", for example "Issue" or "Magazine".', 'newspack-plugin' ) }
								value={ settings.custom_singular_name }
								disabled={ isBusy }
								onChange={ ( custom_singular_name: string ) => update( { custom_singular_name } ) }
								placeholder={ _x( 'Collection', 'collections singular label', 'newspack-plugin' ) }
							/>
							<TextControl
								withMargin={ false }
								label={ __( 'Permalink slug', 'newspack-plugin' ) }
								help={ __( 'Used in collection URLs, for example "issues". Defaults to "collections".', 'newspack-plugin' ) }
								value={ settings.custom_slug }
								disabled={ isBusy }
								onChange={ ( custom_slug: string ) => update( { custom_slug } ) }
								placeholder={ DEFAULT_SLUG }
							/>
						</>
					) }
				</>
			),
		},
		{
			key: 'calls-to-action',
			title: __( 'Calls to Action', 'newspack-plugin' ),
			description: __(
				'Set where the Subscribe and Order buttons on collection pages lead. A category or an individual collection can override these.',
				'newspack-plugin'
			),
			content: (
				<>
					<TextControl
						withMargin={ false }
						type="url"
						label={ __( 'Subscription URL', 'newspack-plugin' ) }
						value={ settings.subscribe_link }
						disabled={ isBusy }
						onChange={ ( subscribe_link: string ) => update( { subscribe_link } ) }
						placeholder={ `https://${ window.location.hostname }/subscribe` }
					/>
					<TextControl
						withMargin={ false }
						type="url"
						label={ __( 'Order URL', 'newspack-plugin' ) }
						value={ settings.order_link }
						disabled={ isBusy }
						onChange={ ( order_link: string ) => update( { order_link } ) }
						placeholder={ `https://${ window.location.hostname }/order` }
					/>
				</>
			),
		},
		{
			key: 'archive',
			title: __( 'Archive Page', 'newspack-plugin' ),
			description: (
				<>
					{ __( 'Customize the page that lists every collection.', 'newspack-plugin' ) }{ ' ' }
					<ExternalLink href={ archiveUrl }>{ __( 'Open archive page', 'newspack-plugin' ) }</ExternalLink>
				</>
			),
			content: (
				<>
					<ToggleGroupControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						isBlock
						label={ __( 'Collections per page', 'newspack-plugin' ) }
						value={ String( settings.posts_per_page ) }
						onChange={ value => update( { posts_per_page: Number( value ) } ) }
					>
						{ COLLECTIONS_PER_PAGE_OPTIONS.map( option => (
							<ToggleGroupControlOption key={ option } value={ String( option ) } label={ String( option ) } disabled={ isBusy } />
						) ) }
					</ToggleGroupControl>
					<TextControl
						withMargin={ false }
						label={ __( 'Category filter label', 'newspack-plugin' ) }
						help={ __( 'Label for the category filter, for example "Series:". Defaults to "Publication:".', 'newspack-plugin' ) }
						value={ settings.category_filter_label }
						disabled={ isBusy }
						onChange={ ( category_filter_label: string ) => update( { category_filter_label } ) }
						placeholder={ __( 'Publication:', 'newspack-plugin' ) }
					/>
					<ChoiceToggle
						label={ __( 'Latest collection', 'newspack-plugin' ) }
						help={ __(
							'Feature the most recent collection at the top of the archive, with its content and calls to action.',
							'newspack-plugin'
						) }
						onLabel={ __( 'Featured', 'newspack-plugin' ) }
						offLabel={ __( 'Standard', 'newspack-plugin' ) }
						checked={ settings.highlight_latest }
						disabled={ isBusy }
						onChange={ highlight_latest => update( { highlight_latest } ) }
					/>
				</>
			),
		},
		{
			key: 'collection-pages',
			title: __( 'Single Pages', 'newspack-plugin' ),
			description: __( 'Choose what each collection page shows.', 'newspack-plugin' ),
			content: (
				<>
					<ChoiceToggle
						label={ __( 'Post categories', 'newspack-plugin' ) }
						help={ __( 'Display the category of each post in the collection.', 'newspack-plugin' ) }
						checked={ Boolean( settings.articles_block_attrs?.showCategory ) }
						disabled={ isBusy }
						onChange={ showCategory => update( { articles_block_attrs: { ...settings.articles_block_attrs, showCategory } } ) }
					/>
					<ChoiceToggle
						label={ __( 'Cover story images', 'newspack-plugin' ) }
						help={ __( 'Display the featured image of cover stories. Individual collections can override this.', 'newspack-plugin' ) }
						checked={ settings.show_cover_story_img }
						disabled={ isBusy }
						onChange={ show_cover_story_img => update( { show_cover_story_img } ) }
					/>
				</>
			),
		},
		{
			key: 'posts',
			title: __( 'Posts', 'newspack-plugin' ),
			description: __( 'Choose how a post shows the collection it belongs to.', 'newspack-plugin' ),
			content: (
				<>
					<ToggleGroupControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						isBlock
						label={ __( 'Collection indicator', 'newspack-plugin' ) }
						help={
							settings.post_indicator_style === 'card'
								? __( 'A card with the collection cover, a message and a button to view the collection.', 'newspack-plugin' )
								: __( 'A link to the collection at the bottom of the post content.', 'newspack-plugin' )
						}
						value={ settings.post_indicator_style }
						onChange={ value => update( { post_indicator_style: value as CollectionsSettingsData[ 'post_indicator_style' ] } ) }
					>
						<ToggleGroupControlOption value="default" label={ __( 'Link', 'newspack-plugin' ) } disabled={ isBusy } />
						<ToggleGroupControlOption value="card" label={ __( 'Card', 'newspack-plugin' ) } disabled={ isBusy } />
					</ToggleGroupControl>
					{ settings.post_indicator_style === 'card' && (
						<TextControl
							withMargin={ false }
							label={ __( 'Card message', 'newspack-plugin' ) }
							value={ settings.card_message }
							disabled={ isBusy }
							onChange={ ( card_message: string ) => update( { card_message } ) }
							placeholder={ DEFAULT_CARD_MESSAGE }
						/>
					) }
				</>
			),
		},
	];

	return (
		<WizardsTab isFetching={ isBusy }>
			{ navBlockDialog }
			{ disableDialog }
			{ errorNotice }
			{ sections.map( ( section, index ) => (
				<Fragment key={ section.key }>
					{ index > 0 && <Divider alignment="full-width" variant="tertiary" /> }
					<Grid columns={ 2 } gutter={ 32 } noMargin>
						<SectionHeader noMargin heading={ 2 } title={ section.title } description={ section.description } />
						<Stack direction="column" gap="xl">
							{ section.content }
						</Stack>
					</Grid>
				</Fragment>
			) ) }
		</WizardsTab>
	);
}

export default Collections;
