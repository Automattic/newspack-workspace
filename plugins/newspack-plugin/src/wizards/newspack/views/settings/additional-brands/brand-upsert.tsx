/**
 * Additional Brands Brand page. Used to edit and create.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { addQueryArgs, cleanForSlug } from '@wordpress/url';
import { Fragment, useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import {
	Router,
	Card,
	Grid,
	Button,
	SectionHeader,
	TextControl,
	ImageUpload,
	ColorPicker,
	SelectControl,
	RadioControl,
	hooks,
	Notice,
} from '../../../../../../packages/components/src';

import './style.scss';
import { TAB_PATH } from './constants';

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
}: {
	brands: Brand[];
	editBrand?: number;
	upsertBrand: ( brandId: number, brand: Brand ) => void;
	wizardApiFetch: WizardApiFetch;
	fetchLogoAttachment: ( brandId: number, logoId: number ) => void;
	errorMessage?: string | null;
} ) {
	const { brandId = '0' } = useParams();
	const selectedBrand = brands.find( ( { id } ) => id === Number( brandId ) );

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

	const baseUrl = `${ window.newspack_urls.site }/${ brand.meta._custom_url === 'no' ? 'brand/' : '' }`;

	function findSelectedMenu( location: string ) {
		return brand.meta._menus.find( menu => menu.location === location )?.menu || 0;
	}

	function isFetchingLogo() {
		return typeof brand.meta._logo === 'number' && brand.meta._logo > 0;
	}

	return (
		<Fragment>
			<SectionHeader title={ __( 'Brand', 'newspack-plugin' ) } description={ __( 'Set your brand identity', 'newspack-plugin' ) } />
			<Grid gutter={ 32 }>
				<Grid columns={ 1 } gutter={ 16 }>
					<TextControl
						label={ __( 'Name', 'newspack-plugin' ) }
						value={ brand.name || '' }
						onChange={ updateBrand( 'name' ) }
						onBlur={ updateSlugFromName }
						placeholder={ __( 'Brand Name', 'newspack-plugin' ) }
					/>
				</Grid>
				<Grid columns={ 1 } gutter={ 16 }>
					<ImageUpload
						className="newspack-brand__header__logo"
						buttonLabel={ isFetchingLogo() ? __( 'Fetching logo…', 'newspack-plugin' ) : undefined }
						label={ __( 'Logo', 'newspack-plugin' ) }
						image={ isFetchingLogo() ? undefined : brand.meta._logo }
						onChange={ ( logoId: number ) =>
							updateBrand( {
								meta: { ...brand.meta, _logo: logoId },
							} )
						}
					/>
				</Grid>
			</Grid>

			{ /* Theme Colors Section */ }
			{ registeredThemeColors && (
				<Fragment>
					<SectionHeader
						title={ __( 'Colors', 'newspack-plugin' ) }
						description={ __( 'These are the colors you can customize for this brand in the active theme', 'newspack-plugin' ) }
					/>
					{ registeredThemeColors.map( color => (
						<Card noBorder key={ color.theme_mod_name }>
							<ColorPicker
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
						</Card>
					) ) }
				</Fragment>
			) }

			{ /* URL Settings */ }
			<SectionHeader title={ __( 'Settings', 'newspack-plugin' ) } />
			<Card noBorder>
				<RadioControl
					className="newspack-brand__base-url-radio-control"
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
				<div className="newspack-brand__base-url-component">
					<span>{ baseUrl }</span>
					<TextControl
						className="newspack-brand__base-url-component__text-control"
						label={ __( 'Slug', 'newspack-plugin' ) }
						hideLabelFromVision
						withMargin={ false }
						value={ brand.slug || '' }
						onChange={ ( slug: string ) => updateBrand( { slug } ) }
						placeholder="brand-slug"
					/>
				</div>
			</Card>

			{ /* Front Page Settings */ }
			<Card noBorder>
				<RadioControl
					className="newspack-brand__base-url-radio-control"
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
						label={ __( 'Homepage URL', 'newspack-plugin' ) }
						value={ brand.meta._show_page_on_front || 0 }
						options={ [
							{
								label: __( 'Select a Page', 'newspack-plugin' ),
								value: 0,
								disabled: true,
							},
							...publicPages.map( page => ( {
								label: page.title.rendered,
								value: Number( page.id ),
							} ) ),
						] }
						onChange={ ( _show_page_on_front: number ) =>
							updateBrand( {
								meta: {
									...brand.meta,
									_show_page_on_front: Number( _show_page_on_front ),
								},
							} )
						}
						required
					/>
				) }
			</Card>

			{ /* Menu Settings */ }
			<SectionHeader title={ __( 'Menus', 'newspack-plugin' ) } description={ __( 'Customize the menus for this brand', 'newspack-plugin' ) } />
			{ menuLocations &&
				Object.keys( menuLocations ).map( location => (
					<SelectControl
						key={ location }
						label={ menuLocations[ location ] }
						value={ findSelectedMenu( location ) }
						options={ [
							{
								label: __( 'Same as site', 'newspack-plugin' ),
								value: 0,
							},
							...availableMenus,
						] }
						onChange={ ( menuId: number ) => updateMenus( location, menuId ) }
					/>
				) ) }
			{ errorMessage && <Notice isError>{ errorMessage }</Notice> }
			{ /* Action Buttons */ }
			<div className="newspack-buttons-card">
				<Button disabled={ ! isBrandValid } variant="primary" onClick={ () => upsertBrand( Number( brandId ), brand ) }>
					{ __( 'Save', 'newspack-plugin' ) }
				</Button>
				<Button variant="secondary" href={ `#${ TAB_PATH }` }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
			</div>
		</Fragment>
	);
}
