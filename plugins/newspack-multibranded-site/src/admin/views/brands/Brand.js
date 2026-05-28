/* global newspack_aux_data */

import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs, cleanForSlug } from '@wordpress/url';
import { Fragment, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useParams } from 'react-router-dom';

import {
	Card,
	Grid,
	Button,
	SectionHeader,
	TextControl,
	ImageUpload,
	ColorPicker,
	SelectControl,
	RadioControl,
	withWizardScreen,
	hooks,
} from 'newspack-components';

import './style.scss';

const Brand = ( { brands = [], saveBrand, fetchLogoAttachment } ) => {
	const [ brand, updateBrand ] = hooks.useObjectState( { slug: '', meta: { _custom_url: 'yes' } } );
	const [ publicPages, setPublicPages ] = useState( [] );
	const [ showOnFrontSelect, setShowOnFrontSelect ] = useState( 'no' );

	const { brandId } = useParams();
	const selectedBrand = brands.find( ( { id } ) => id === Number( brandId ) );

	const registeredThemeColors = newspack_aux_data.theme_colors;
	const menuLocations = newspack_aux_data.menu_locations;
	const availableMenus = newspack_aux_data.menus;

	useEffect( () => {
		if ( selectedBrand ) {
			updateBrand( selectedBrand );
			if ( ! isNaN( selectedBrand.meta._logo ) ) {
				fetchLogoAttachment( Number( brandId ), selectedBrand.meta._logo );
			}
			setShowOnFrontSelect( selectedBrand.meta._show_page_on_front ? 'yes' : 'no' );
		}
	}, [ selectedBrand ] );

	const getThemeColor = colorName => {
		const color = brand.meta._theme_colors?.find( c => colorName === c.name )?.color;
		return color ? color : registeredThemeColors.find( c => colorName === c.theme_mod_name )?.default;
	};

	const hasCustomThemeColor = colorName => {
		const color = brand.meta._theme_colors?.find( c => colorName === c.name )?.color;
		return color ? true : false;
	};

	const setThemeColor = ( name, color ) => {
		const themeColors = brand?.meta._theme_colors ? brand?.meta._theme_colors : [];
		const colorIndex = themeColors.findIndex( _color => name === _color.name );
		let updatedThemeColors = [];

		if ( ! color && colorIndex > -1 ) {
			// Resetting default color.
			themeColors.splice( colorIndex, 1 );
			updatedThemeColors = themeColors;
		} else if ( color && colorIndex > -1 ) {
			// Updating color.
			updatedThemeColors = themeColors.map( _color => ( name === _color.name ? { ..._color, color } : _color ) );
		} else if ( color && colorIndex === -1 ) {
			// Adding color.
			updatedThemeColors = [ ...themeColors, { name, color } ];
		} else if ( ! color && colorIndex === -1 ) {
			// should not happen.
			return;
		}

		return updateBrand( {
			meta: {
				_theme_colors: updatedThemeColors,
			},
		} );
	};

	const updateSlugFromName = e => {
		if ( '' === brand.slug ) {
			updateBrand( { slug: cleanForSlug( e.target.value ) } );
		}
	};

	const updateShowOnFront = value => {
		if ( 'no' === value ) {
			updateBrand( { meta: { ...brand.meta, _show_page_on_front: 0 } } );
		}
		setShowOnFrontSelect( value );
	};

	const updateMenus = ( location, menu ) => {
		const menus = brand.meta._menus ? brand.meta._menus : [];
		const menuIndex = menus.findIndex( _menu => location === _menu.location );

		const updatedMenus =
			menuIndex > -1 ? menus.map( _menu => ( location === _menu.location ? { ..._menu, menu } : _menu ) ) : [ ...menus, { location, menu } ];

		return updateBrand( {
			meta: {
				_menus: updatedMenus,
			},
		} );
	};

	const baseUrl = `${ newspack_aux_data.site }/${ 'no' === brand.meta._custom_url ? 'brand/' : '' }`;

	const fetchPublicPages = () => {
		// Limiting to 100 pages, just in case.
		apiFetch( {
			path: addQueryArgs( '/wp/v2/pages', { per_page: 100, orderby: 'title', order: 'asc' } ),
		} ).then( setPublicPages );
	};

	useEffect( fetchPublicPages, [] );

	// Brand is valid when it has a name, and if a page is selected to be shown in front, the page should be selected.
	const isBrandValid =
		0 < brand.name?.length && ( 'no' === showOnFrontSelect || ( 'yes' === showOnFrontSelect && 0 < brand.meta._show_page_on_front ) );

	const findSelectedMenu = location => {
		if ( ! brand.meta._menus ) {
			return 0;
		}
		const selectedMenu = brand.meta._menus.find( menu => menu.location === location );
		return selectedMenu ? selectedMenu.menu : 0;
	};

	return (
		<Fragment>
			<SectionHeader
				title={ __( 'Brand', 'newspack-multibranded-site' ) }
				description={ __( 'Set your brand identity', 'newspack-multibranded-site' ) }
			/>
			<Grid gutter={ 32 }>
				<Grid columns={ 1 } gutter={ 16 }>
					<TextControl
						label={ __( 'Name', 'newspack-multibranded-site' ) }
						value={ brand.name || '' }
						onChange={ updateBrand( 'name' ) }
						onBlur={ updateSlugFromName }
					/>
				</Grid>
				<Grid columns={ 1 } gutter={ 16 }>
					<ImageUpload
						className="newspack-brand__header__logo"
						label={ __( 'Logo', 'newspack-multibranded-site' ) }
						image={ brand.meta._logo }
						onChange={ _logo => updateBrand( { meta: { _logo } } ) }
					/>
				</Grid>
			</Grid>

			{ registeredThemeColors && (
				<SectionHeader
					title={ __( 'Colors', 'newspack-multibranded-site' ) }
					description={ __( 'These are the colors you can customize for this brand in the active theme', 'newspack-multibranded-site' ) }
				/>
			) }

			{ registeredThemeColors &&
				registeredThemeColors.map( color => {
					return (
						<Card noBorder key={ color.theme_mod_name }>
							<ColorPicker
								className="newspack-brand__theme-mod-color-picker"
								label={
									<Fragment>
										<span>{ color.label }</span>
										{ hasCustomThemeColor( color.theme_mod_name ) && (
											<Button isLink onClick={ () => setThemeColor( color.theme_mod_name, '' ) }>
												{ __( 'Reset default color', 'newspack-multibranded-site' ) }
											</Button>
										) }
									</Fragment>
								}
								color={ getThemeColor( color.theme_mod_name ) }
								onChange={ newColor => setThemeColor( color.theme_mod_name, newColor ) }
							/>
						</Card>
					);
				} ) }

			<SectionHeader title={ __( 'Settings', 'newspack-multibranded-site' ) } />
			<Card noBorder>
				<RadioControl
					className="newspack-brand__base-url-radio-control"
					label={ __( 'URL Base', 'newspack-multibranded-site' ) }
					selected={ brand?.meta._custom_url || 'yes' }
					options={ [
						{ label: __( 'Homepage', 'newspack-multibranded-site' ), value: 'yes' },
						{ label: __( 'Default', 'newspack-multibranded-site' ), value: 'no' },
					] }
					onChange={ _custom_url => updateBrand( { meta: { _custom_url } } ) }
				/>
				<div className="newspack-brand__base-url-component">
					<span>{ baseUrl }</span>
					<TextControl
						className="newspack-brand__base-url-component__text-control"
						label={ __( 'Slug', 'newspack-multibranded-site' ) }
						hideLabelFromVision
						withMargin={ false }
						value={ brand.slug || '' }
						onChange={ updateBrand( 'slug' ) }
					/>
				</div>
			</Card>

			<Card noBorder>
				<RadioControl
					className="newspack-brand__base-url-radio-control"
					label={ __( 'Show on Front', 'newspack-multibranded-site' ) }
					selected={ showOnFrontSelect }
					options={ [
						{ label: __( 'Latest posts', 'newspack-multibranded-site' ), value: 'no' },
						{ label: __( 'A page', 'newspack-multibranded-site' ), value: 'yes' },
					] }
					onChange={ value => updateShowOnFront( value ) }
				/>
				{ 'yes' === showOnFrontSelect && (
					<SelectControl
						label={ __( 'Homepage URL', 'newspack-multibranded-site' ) }
						value={ brand.meta._show_page_on_front || 0 }
						options={ [
							{
								label: __( 'Select a Page', 'newspack-multibranded-site' ),
								value: 0,
								disabled: true,
							},
							...publicPages.map( page => ( {
								label: page.title.rendered,
								value: Number( page.id ),
							} ) ),
						] }
						onChange={ _show_page_on_front => updateBrand( { meta: { _show_page_on_front } } ) }
						required
					/>
				) }
			</Card>

			<SectionHeader
				title={ __( 'Menus', 'newspack-multibranded-site' ) }
				description={ __( 'Customize the menus for this brand', 'newspack-multibranded-site' ) }
			/>

			{ Object.keys( menuLocations ).map( location => (
				<SelectControl
					key={ location }
					label={ menuLocations[ location ] }
					value={ findSelectedMenu( location ) }
					options={ [
						{
							label: __( 'Same as site', 'newspack-multibranded-site' ),
							value: 0,
							disabled: false,
						},
						...availableMenus,
					] }
					onChange={ menuId => updateMenus( location, menuId ) }
				/>
			) ) }

			<div className="newspack-buttons-card">
				<Button disabled={ ! isBrandValid } isPrimary onClick={ () => saveBrand( Number( brandId ), brand ) }>
					{ __( 'Save', 'newspack-multibranded-site' ) }
				</Button>
				<Button isSecondary href="#/">
					{ __( 'Cancel', 'newspack-multibranded-site' ) }
				</Button>
			</div>
		</Fragment>
	);
};

export default withWizardScreen( Brand );
