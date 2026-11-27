/**
 * Additional Brands Brand page. Used to edit and create.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs, cleanForSlug } from '@wordpress/url';
import { Fragment, useState, useEffect, useRef } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { Notice, RadioControl, SelectControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Router, Divider, SplitSection, Button, TextControl, ImageUpload, ColorPicker, hooks } from '../../../../../../packages/components/src';

import { useErrorNoticeFocus } from '../../../../hooks/use-error-notice-focus';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

import './style.scss';
import { BlockThemeColors, BlockThemeMenus, BlockThemeParts, isBlockTheme } from './block-theme';

const { useParams } = Router;

const {
	themeColors: registeredThemeColors,
	menuLocations,
	menus: availableMenus,
} = window.newspackSettings[ 'additional-brands' ].sections.additionalBrands;

export default function Brand( {
	brands = [],
	upsertBrand,
	wizardApiFetch,
	fetchLogoAttachment,
	errorMessage,
	isFetching = false,
}: {
	brands: Brand[];
	editBrand?: number;
	upsertBrand: ( brandId: number, brand: Brand ) => void;
	wizardApiFetch: WizardApiFetch;
	fetchLogoAttachment: ( brandId: number, logoId: number ) => void;
	errorMessage?: string | null;
	isFetching?: boolean;
} ) {
	const { brandId = '0' } = useParams< { brandId?: string } >();
	const selectedBrand = brands.find( ( { id } ) => id === Number( brandId ) );

	const {
		wrapperProps: noticeWrapperProps,
		registerSubmit,
		spokenMessage,
	} = useErrorNoticeFocus( errorMessage, __( 'Brand error', 'newspack-plugin' ) );

	const [ brand, updateBrand ] = hooks.useObjectState< Brand >( {
		id: 0,
		name: '',
		slug: '',
		meta: {
			_show_page_on_front: 0,
			_custom_url: 'yes',
			_logo: 0,
			_theme_colors: [],
			_menus: [],
			_block_theme_menus: {},
			_block_theme_parts: {},
			_block_theme_palette: '',
		},
		count: 0,
		description: '',
		link: '',
		taxonomy: '',
		parent: 0,
	} );
	const [ publicPages, setPublicPages ] = useState< PublicPage[] >( [] );
	const [ showOnFrontSelect, setShowOnFrontSelect ] = useState< string >( 'no' );

	useEffect( () => {
		if ( selectedBrand && typeof selectedBrand.meta._logo === 'number' ) {
			// Only fetch the logo if _logo is a number (ID) and not an `Attachment` object.
			fetchLogoAttachment( Number( brandId ), selectedBrand.meta._logo );
		}
	}, [ selectedBrand?.meta._logo ] );

	useEffect( () => {
		if ( selectedBrand ) {
			updateBrand( selectedBrand );
			setShowOnFrontSelect( selectedBrand.meta._show_page_on_front ? 'yes' : 'no' );
		}
	}, [ selectedBrand ] );

	useEffect( () => {
		wizardApiFetch(
			{
				path: addQueryArgs( '/wp/v2/pages', {
					per_page: 100,
					orderby: 'title',
					order: 'asc',
				} ),
			},
			{
				onSuccess: setPublicPages,
			}
		);
	}, [] );

	const brandThemeColors = brand.meta._theme_colors;

	const isBrandValid =
		brand.name?.length > 0 && ( showOnFrontSelect === 'no' || ( showOnFrontSelect === 'yes' && brand.meta._show_page_on_front > 0 ) );

	// Utility functions for brand updates
	function updateThemeColor( name: string | undefined, color: string | undefined ) {
		if ( ! name ) {
			return;
		}

		const existingColor = brandThemeColors.find( c => c.name === name );
		let updatedThemeColors = [ ...( brandThemeColors || [] ) ];

		if ( color ) {
			if ( existingColor ) {
				// Update existing color
				updatedThemeColors = updatedThemeColors.map( _color => ( _color.name === name ? { ..._color, color } : _color ) );
			} else {
				// Add new color
				updatedThemeColors.push( { name, color } );
			}
		} else {
			// Reset to default
			updatedThemeColors = updatedThemeColors.filter( _color => _color.name !== name );
		}

		updateBrand( {
			meta: { ...brand.meta, _theme_colors: updatedThemeColors },
		} );
	}

	function updateSlugFromName( e: React.ChangeEvent< HTMLInputElement > ) {
		if ( ! brand.slug ) {
			updateBrand( { slug: cleanForSlug( e.target.value ) } );
		}
	}

	function updateShowOnFront( value: string ) {
		setShowOnFrontSelect( value );
		updateBrand( {
			meta: {
				...brand.meta,
				_show_page_on_front: value === 'yes' ? 1 : 0,
			},
		} );
	}

	function updateMenus( location: string, menu: number ) {
		const updatedMenus = brand.meta._menus.map( _menu => ( _menu.location === location ? { ..._menu, menu } : _menu ) ) || [];

		updateBrand( {
			meta: {
				...brand.meta,
				_menus: [ ...updatedMenus, { location, menu } ],
			},
		} );
	}

	function updateMeta( meta: Partial< Brand[ 'meta' ] > ) {
		updateBrand( { meta: { ...brand.meta, ...meta } } );
	}

	function landingPageOwner( pageId: number ) {
		return brands.find( other => other.id !== brand.id && Number( other.meta._show_page_on_front ) === pageId );
	}

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	// The header holds on to the Save function it was given. Pointing it at this ref means
	// 'Save' always uses the latest edits, not the brand as it was when the header was set.
	const save = useRef( () => {} );
	save.current = () => {
		registerSubmit();
		upsertBrand( Number( brandId ), brand );
	};

	useEffect( () => {
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: () => save.current(),
					disabled: isFetching || ! isBrandValid,
				},
			],
		} );
	}, [ isFetching, isBrandValid, setHeaderData ] );

	const baseUrl = `${ window.newspack_urls?.site }/${ brand.meta._custom_url === 'no' ? 'brand/' : '' }`;

	function findSelectedMenu( location: string ) {
		return brand.meta._menus.find( menu => menu.location === location )?.menu || 0;
	}

	function isFetchingLogo() {
		return typeof brand.meta._logo === 'number' && brand.meta._logo > 0;
	}

	return (
		<Fragment>
			{ errorMessage && (
				<div { ...noticeWrapperProps } className="newspack-brand__notice">
					<Notice status="error" isDismissible={ false } politeness="polite" spokenMessage={ spokenMessage }>
						{ errorMessage }
					</Notice>
				</div>
			) }
			<SplitSection.Root>
				<SplitSection.Header title={ __( 'Brand', 'newspack-plugin' ) } description={ __( 'Set your brand identity', 'newspack-plugin' ) } />
				<SplitSection.Content>
					<TextControl
						label={ __( 'Name', 'newspack-plugin' ) }
						value={ brand.name || '' }
						onChange={ updateBrand( 'name' ) }
						onBlur={ updateSlugFromName }
						placeholder={ __( 'Brand Name', 'newspack-plugin' ) }
						withMargin={ false }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<ImageUpload
						className="newspack-brand__header__logo"
						buttonLabel={ isFetchingLogo() ? __( 'Fetching logo…', 'newspack-plugin' ) : undefined }
						label={ __( 'Logo', 'newspack-plugin' ) }
						image={ isFetchingLogo() ? undefined : brand.meta._logo }
						withMargin={ false }
						onChange={ attachment =>
							updateBrand( {
								meta: { ...brand.meta, _logo: attachment },
							} )
						}
					/>
				</SplitSection.Content>
			</SplitSection.Root>

			{ /* Theme Colors Section */ }
			{ registeredThemeColors && (
				<Fragment>
					<Divider alignment="full-width" variant="tertiary" />
					<SplitSection.Root>
						<SplitSection.Header
							title={ __( 'Colors', 'newspack-plugin' ) }
							description={
								isBlockTheme
									? __( 'Choose a palette for this brand, then adjust any of its colors.', 'newspack-plugin' )
									: __( 'Change any of the theme’s colors for this brand.', 'newspack-plugin' )
							}
						/>
						<SplitSection.Content>
							{ isBlockTheme ? (
								<BlockThemeColors brand={ brand } themeColors={ registeredThemeColors } updateMeta={ updateMeta } />
							) : (
								<>
									{ registeredThemeColors.map( color => (
										<ColorPicker
											key={ color.theme_mod_name }
											className="newspack-brand__theme-mod-color-picker"
											label={
												<Fragment>
													<span>{ color.label }</span>
													{ brandThemeColors.find( c => c.name === color.theme_mod_name )?.color && (
														<Button variant="link" onClick={ () => updateThemeColor( color.theme_mod_name, '' ) }>
															{ __( 'Reset default color', 'newspack-plugin' ) }
														</Button>
													) }
												</Fragment>
											}
											color={ brandThemeColors.find( c => c.name === color.theme_mod_name )?.color ?? color.default }
											onChange={ ( newColor: string ) => updateThemeColor( color.theme_mod_name, newColor ) }
										/>
									) ) }
								</>
							) }
						</SplitSection.Content>
					</SplitSection.Root>
				</Fragment>
			) }

			{ /* URL and front page settings */ }
			<Divider alignment="full-width" variant="tertiary" />
			<SplitSection.Root>
				<SplitSection.Header
					title={ __( 'Settings', 'newspack-plugin' ) }
					description={ __( 'Where the brand lives on the site, and what its URL shows.', 'newspack-plugin' ) }
				/>
				<SplitSection.Content>
					<Stack direction="column" gap="md">
						<RadioControl
							label={ __( 'URL Base', 'newspack-plugin' ) }
							selected={ brand.meta._custom_url || 'yes' }
							options={ [
								{
									label: __( 'Homepage', 'newspack-plugin' ),
									value: 'yes',
								},
								{
									label: __( 'Default', 'newspack-plugin' ),
									value: 'no',
								},
							] }
							onChange={ ( _custom_url: string ) => updateBrand( { meta: { ...brand.meta, _custom_url } } ) }
						/>
						<Stack direction="row" align="baseline">
							<span>{ baseUrl }</span>
							<TextControl
								className="newspack-brand__base-url-component__text-control"
								label={ __( 'Slug', 'newspack-plugin' ) }
								hideLabelFromVision
								withMargin={ false }
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								value={ brand.slug || '' }
								onChange={ ( slug: string ) => updateBrand( { slug } ) }
								placeholder="brand-slug"
							/>
						</Stack>
					</Stack>
					<Stack direction="column" gap="md">
						<RadioControl
							label={ __( 'Show on Front', 'newspack-plugin' ) }
							selected={ showOnFrontSelect }
							options={ [
								{
									label: __( 'Latest posts', 'newspack-plugin' ),
									value: 'no',
								},
								{
									label: __( 'A page', 'newspack-plugin' ),
									value: 'yes',
								},
							] }
							onChange={ updateShowOnFront }
						/>
						{ showOnFrontSelect === 'yes' && (
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Page to show', 'newspack-plugin' ) }
								hideLabelFromVision
								value={ String( brand.meta._show_page_on_front || 0 ) }
								options={ [
									{
										label: __( 'Select a Page', 'newspack-plugin' ),
										value: '0',
										disabled: true,
									},
									...publicPages.map( page => {
										const owner = landingPageOwner( Number( page.id ) );
										return {
											label: owner
												? sprintf(
														/* translators: 1: page title, 2: brand name. */
														__( '%1$s (landing page for %2$s)', 'newspack-plugin' ),
														page.title.rendered,
														owner.name
												  )
												: page.title.rendered,
											value: String( page.id ),
											disabled: Boolean( owner ),
										};
									} ),
								] }
								onChange={ ( value: string ) =>
									updateBrand( {
										meta: {
											...brand.meta,
											_show_page_on_front: Number( value ),
										},
									} )
								}
								required
							/>
						) }
					</Stack>
				</SplitSection.Content>
			</SplitSection.Root>

			{ /* Menu Settings */ }
			<Divider alignment="full-width" variant="tertiary" />
			{ isBlockTheme ? (
				<Fragment>
					<BlockThemeMenus brand={ brand } brands={ brands } updateMeta={ updateMeta } wizardApiFetch={ wizardApiFetch } />
					<Divider alignment="full-width" variant="tertiary" />
					<BlockThemeParts brand={ brand } brands={ brands } updateMeta={ updateMeta } wizardApiFetch={ wizardApiFetch } />
				</Fragment>
			) : (
				<SplitSection.Root>
					<SplitSection.Header
						title={ __( 'Menus', 'newspack-plugin' ) }
						description={ __( 'Customize the menus for this brand', 'newspack-plugin' ) }
					/>
					<SplitSection.Content>
						{ menuLocations &&
							Object.keys( menuLocations ).map( location => (
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									key={ location }
									label={ menuLocations[ location ] }
									value={ String( findSelectedMenu( location ) ) }
									options={ [
										{
											label: __( 'Same as site', 'newspack-plugin' ),
											value: '0',
										},
										...availableMenus.map( ( menu: { value: number; label: string } ) => ( {
											...menu,
											value: String( menu.value ),
										} ) ),
									] }
									onChange={ ( menuId: string ) => updateMenus( location, Number( menuId ) ) }
								/>
							) ) }
					</SplitSection.Content>
				</SplitSection.Root>
			) }
		</Fragment>
	);
}
