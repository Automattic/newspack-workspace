/**
 * Brand menus, header, footer and sidebar on block themes.
 *
 * Brands use the main site's menus and template parts unless they pick another existing one
 * here. "Copy" makes a regular menu or template part named "… [Brand]" and selects it; saving
 * the brand applies the choice.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Fragment, createInterpolateElement, useState } from '@wordpress/element';
import {
	ColorIndicator,
	ColorPicker,
	Dropdown,
	Notice,
	SelectControl,
	__experimentalDropdownContentWrapper as DropdownContentWrapper, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalItemGroup as ItemGroup, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

import { Stack, Text } from '@wordpress/ui';
import { reset as resetIcon } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Button, Grid, SectionHeader, useConfirmDialog } from '../../../../../../packages/components/src';

const blockThemeData: BlockThemeData | null = window.newspackSettings[ 'additional-brands' ].sections.additionalBrands.blockTheme || null;

export const isBlockTheme = Boolean( blockThemeData );

type UpdateMeta = ( meta: Partial< Brand[ 'meta' ] > ) => void;

type SectionProps = {
	brand: Brand;
	brands: Brand[];
	updateMeta: UpdateMeta;
	wizardApiFetch: WizardApiFetch;
};

/**
 * "A", "A and B", "A, B and C", in the site language.
 *
 * @param names Names.
 */
const listNames = ( names: string[] ) => new Intl.ListFormat( document.documentElement.lang || 'en', { type: 'conjunction' } ).format( names );

/**
 * Block theme brand meta comes back from REST as an empty array when nothing is saved.
 *
 * @param value Saved value.
 */
const asRecord = < T, >( value: unknown ): Record< string, T > =>
	value && ! Array.isArray( value ) && 'object' === typeof value ? ( value as Record< string, T > ) : {};

const partChoices = ( brand: Brand ) => asRecord< string >( brand.meta._block_theme_parts );
const menuChoices = ( brand: Brand ) => asRecord< number >( brand.meta._block_theme_menus );

/**
 * The part a brand shows in a slot instead of the main one, or '' for the main one.
 *
 * @param brand Brand.
 * @param slot  Slot.
 */
const ownPart = ( brand: Brand, slot: BlockThemeSlot ) => {
	const chosen = partChoices( brand )[ slot.slot ];
	return chosen && chosen !== slot.main && blockThemeData?.parts[ chosen ] ? chosen : '';
};

/**
 * The other brands, with the brand being edited as it is on screen.
 *
 * @param brand  Brand being edited.
 * @param brands All saved brands.
 */
const withEdited = ( brand: Brand, brands: Brand[] ) => [ brand, ...brands.filter( b => b.id !== brand.id ) ];

/**
 * Who else shows a template part or menu, for the help text under its picker.
 *
 * @param main   Whether the main site shows it.
 * @param others Names of the other brands showing it.
 */
const usageText = ( main: boolean, others: string[] ) => {
	if ( others.length ) {
		/* translators: %s: list of brand names. */
		return sprintf( __( 'Also used by %s.', 'newspack-plugin' ), listNames( others ) );
	}
	return main ? '' : __( 'Only this brand uses it.', 'newspack-plugin' );
};

/**
 * Who else shows a template part.
 *
 * @param slug   Part slug.
 * @param brand  Brand being edited.
 * @param brands All saved brands.
 */
const partUsage = ( slug: string, brand: Brand, brands: Brand[] ) => {
	const slots = blockThemeData?.slots || [];
	const main = slots.some( slot => slot.main === slug );
	const others = withEdited( brand, brands )
		.filter( b => b.id !== brand.id )
		.filter( b => slots.some( slot => ( ownPart( b, slot ) || slot.main ) === slug ) )
		.map( b => b.name );
	return usageText( main, others );
};

/**
 * The menus a brand shows: the ones in the main parts it uses, with its picks applied, and
 * the ones in its own parts.
 *
 * @param brand Brand.
 */
const shownMenus = ( brand: Brand ) => {
	const shown = new Set< number >();
	( blockThemeData?.slots || [] ).forEach( slot => {
		const own = ownPart( brand, slot );
		( blockThemeData?.parts[ own || slot.main ]?.navs || [] ).forEach( ( { id } ) => {
			shown.add( own ? id : menuChoices( brand )[ id ] || id );
		} );
	} );
	return shown;
};

/**
 * Who else shows a menu.
 *
 * @param id     Menu ID.
 * @param brand  Brand being edited.
 * @param brands All saved brands.
 */
const menuUsage = ( id: number, brand: Brand, brands: Brand[] ) => {
	const main = ( blockThemeData?.slots || [] ).some( slot => blockThemeData?.parts[ slot.main ]?.navs.some( nav => nav.id === id ) );
	const others = withEdited( brand, brands )
		.filter( b => b.id !== brand.id && shownMenus( b ).has( id ) )
		.map( b => b.name );
	return usageText( main, others );
};

/**
 * One color in the brand palette list. A changed color gets a reset button.
 *
 * @param props           Props.
 * @param props.name      Color name.
 * @param props.color     Color the brand uses.
 * @param props.isChanged Whether the brand changed it from the starting color.
 * @param props.onChange  Sets the color; an empty value resets it.
 */
function BrandColorRow( {
	name,
	color,
	isChanged,
	onChange,
}: {
	name: string;
	color: string;
	isChanged: boolean;
	onChange: ( value: string ) => void;
} ) {
	return (
		<div className="newspack-brand__color">
			<Dropdown
				className="newspack-brand__color-dropdown"
				popoverProps={ { placement: 'left-start', offset: 36, shift: true } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button __next40pxDefaultSize className="newspack-brand__color-toggle" onClick={ onToggle } aria-expanded={ isOpen }>
						<Stack direction="row" gap="sm" align="center">
							<ColorIndicator colorValue={ color } />
							{ name }
						</Stack>
					</Button>
				) }
				renderContent={ () => (
					<DropdownContentWrapper paddingSize="medium">
						<ColorPicker color={ color } onChange={ onChange } />
					</DropdownContentWrapper>
				) }
			/>
			{ isChanged && (
				<Button
					className="newspack-brand__color-reset"
					size="small"
					icon={ resetIcon }
					onClick={ () => onChange( '' ) }
					/* translators: %s: color name. */
					label={ sprintf( __( 'Reset %s', 'newspack-plugin' ), name ) }
				/>
			) }
		</div>
	);
}

/**
 * Brand colors on block themes. Only colors that differ from the starting palette are saved.
 *
 * @param props             Props.
 * @param props.brand       Brand.
 * @param props.themeColors Colors registered for brands; on block themes, the palette.
 * @param props.updateMeta  Updates the brand meta.
 */
export function BlockThemeColors( {
	brand,
	themeColors,
	updateMeta,
}: Pick< SectionProps, 'brand' | 'updateMeta' > & { themeColors: Array< { theme_mod_name?: string; label: string; default?: string } > } ) {
	if ( ! blockThemeData ) {
		return null;
	}
	const { colorPrefix, palettes } = blockThemeData;
	const saved = brand.meta._theme_colors || [];
	const otherColors = saved.filter( color => ! color.name.startsWith( colorPrefix ) );
	const picks = Object.fromEntries(
		saved.filter( color => color.name.startsWith( colorPrefix ) ).map( color => [ color.name.slice( colorPrefix.length ), color.color ] )
	);
	const base = palettes.find( ( { title } ) => title === brand.meta._block_theme_palette )?.colors || {};
	const startingColors = themeColors
		.filter( color => color.theme_mod_name?.startsWith( colorPrefix ) )
		.map( color => {
			const slug = ( color.theme_mod_name as string ).slice( colorPrefix.length );
			return { slug, name: color.label || slug, color: base[ slug ] ?? color.default ?? '' };
		} );
	const startingColor = ( slug: string ) => startingColors.find( color => color.slug === slug )?.color || '';

	const setColor = ( slug: string, color: string ) => {
		const next = { ...picks, [ slug ]: color };
		if ( ! color || color.toLowerCase() === startingColor( slug ).toLowerCase() ) {
			delete next[ slug ];
		}
		updateMeta( {
			_theme_colors: [
				...otherColors,
				...Object.entries( next ).map( ( [ pickSlug, pickColor ] ) => ( { name: colorPrefix + pickSlug, color: pickColor } ) ),
			],
		} );
	};

	return (
		<Stack direction="column" gap="lg">
			{ palettes.length > 0 && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Start from', 'newspack-plugin' ) }
					help={ __( 'The main site’s colors, or one of the theme’s style variations.', 'newspack-plugin' ) }
					value={ brand.meta._block_theme_palette || '' }
					options={ [
						{ label: __( 'Main site colors', 'newspack-plugin' ), value: '' },
						...palettes.map( ( { title } ) => ( { label: title, value: title } ) ),
					] }
					onChange={ ( _block_theme_palette: string ) => updateMeta( { _block_theme_palette } ) }
				/>
			) }
			{ ! startingColors.length && (
				<Text variant="body-md" render={ <p /> }>
					{ __( 'The active theme has no palette colors.', 'newspack-plugin' ) }
				</Text>
			) }
			<ItemGroup isBordered className="newspack-brand__colors" aria-label={ __( 'Brand colors', 'newspack-plugin' ) }>
				{ startingColors.map( ( { slug, name, color: start } ) => (
					<BrandColorRow
						key={ slug }
						name={ name }
						color={ picks[ slug ] ?? start }
						isChanged={ Boolean( picks[ slug ] ) }
						onChange={ ( value: string ) => setColor( slug, value ) }
					/>
				) ) }
			</ItemGroup>
		</Stack>
	);
}

/**
 * Menus on a brand's pages.
 *
 * @param props                Props.
 * @param props.brand          Brand.
 * @param props.brands         All saved brands.
 * @param props.updateMeta     Updates the brand meta.
 * @param props.wizardApiFetch API fetch.
 */
export function BlockThemeMenus( { brand, brands, updateMeta, wizardApiFetch }: SectionProps ) {
	const [ menus, setMenus ] = useState< BlockThemeMenu[] >( [ ...( blockThemeData?.menus || [] ) ] );
	const [ message, setMessage ] = useState( '' );
	if ( ! blockThemeData ) {
		return null;
	}
	const title = ( id: number ) =>
		menus.find( menu => menu.value === id )?.label || sprintf( /* translators: %d: menu ID. */ __( 'Menu %d', 'newspack-plugin' ), id );
	const editUrl = ( id: number ) => menus.find( menu => menu.value === id )?.editUrl;
	const choices = menuChoices( brand );

	// Menus in main parts the brand uses: picked here. Menus in its own parts: edited in the part.
	const shared = new Map< number, string[] >();
	const own: Array< { part: BlockThemePart; slot: BlockThemeSlot } > = [];
	blockThemeData.slots.forEach( slot => {
		const slug = ownPart( brand, slot );
		const part = blockThemeData.parts[ slug || slot.main ];
		if ( ! part?.navs.length ) {
			return;
		}
		if ( slug ) {
			own.push( { part, slot } );
			return;
		}
		part.navs.forEach( nav => {
			const where = nav.fallback
				? /* translators: %s: template part title. */ sprintf(
						__( '%s: no menu selected, so it shows the newest menu', 'newspack-plugin' ),
						part.title
				  )
				: part.title;
			shared.set( nav.id, [ ...( shared.get( nav.id ) || [] ), where ] );
		} );
	} );

	const setChoice = ( source: number, menu: number ) => {
		const next = { ...choices };
		delete next[ source ];
		if ( menu && menu !== source ) {
			next[ source ] = menu;
		}
		updateMeta( { _block_theme_menus: next } );
	};

	const copy = ( source: number, empty: boolean ) => {
		setMessage( '' );
		wizardApiFetch(
			{
				path: `/newspack-multibranded-site/v1/brands/${ brand.id }/copy-menu`,
				method: 'POST',
				data: { source, empty },
			},
			{
				onSuccess( menu: BlockThemeMenu ) {
					blockThemeData.menus.push( menu );
					setMenus( current => [ ...current, menu ] );
					setChoice( source, menu.value );
					setMessage(
						sprintf(
							/* translators: %s: menu name. */
							__( '“%s” was made and selected. Save the brand to use it, then edit it in the Site Editor.', 'newspack-plugin' ),
							menu.label
						)
					);
				},
			}
		);
	};

	return (
		<Grid columns={ 2 } gutter={ 32 } noMargin>
			<SectionHeader
				heading={ 2 }
				noMargin
				title={ __( 'Menus', 'newspack-plugin' ) }
				description={ __(
					'Brands use the main site’s menus unless you pick another one here. “Copy” (a copy of the main menu) or “Add new” (an empty menu) makes a menu named “… [Brand]” that you can edit in the Site Editor.',
					'newspack-plugin'
				) }
			/>
			<Stack direction="column" gap="lg">
				{ message && (
					<Notice status="success" isDismissible={ false }>
						{ message }
					</Notice>
				) }
				{ own.map( ( { part, slot } ) => (
					<Text variant="body-md" render={ <p /> } key={ slot.slot }>
						{ createInterpolateElement(
							sprintf(
								/* translators: 1: template part title, 2: list of menu names. */
								_n(
									'“%1$s” is this brand’s own template part and uses %2$s. To change it, <a>edit “%1$s” in the Site Editor</a>.',
									'“%1$s” is this brand’s own template part and uses %2$s. To change these menus, <a>edit “%1$s” in the Site Editor</a>.',
									part.navs.length,
									'newspack-plugin'
								),
								part.title,
								listNames(
									part.navs.map( nav =>
										nav.fallback
											? /* translators: %s: menu name. */ sprintf(
													__( '%s (no menu selected, so it shows the newest menu)', 'newspack-plugin' ),
													title( nav.id )
											  )
											: title( nav.id )
									)
								)
							),
							{ a: <Button variant="link" href={ part.editUrl } /> }
						) }
					</Text>
				) ) }
				{ [ ...shared.entries() ].map( ( [ id, where ] ) => {
					const current = choices[ id ] && menus.some( menu => menu.value === choices[ id ] ) ? choices[ id ] : 0;
					const shown = current || id;
					return (
						<Stack key={ id } direction="column" gap="sm">
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								id={ `newspack-brand-menu-${ id }` }
								label={ title( id ) }
								help={ [ `${ where.join( ', ' ) }.`, menuUsage( shown, brand, brands ) ].filter( Boolean ).join( ' ' ) }
								value={ String( current || id ) }
								onChange={ ( value: string ) => setChoice( id, Number( value ) ) }
							>
								<option value={ id }>
									{ /* translators: %s: menu name. */ sprintf( __( 'Main site: %s', 'newspack-plugin' ), title( id ) ) }
								</option>
								<optgroup label={ __( 'Other menus', 'newspack-plugin' ) }>
									{ menus
										.filter( menu => menu.value !== id )
										.map( menu => (
											<option key={ menu.value } value={ menu.value }>
												{ menu.label }
											</option>
										) ) }
								</optgroup>
							</SelectControl>
							<Stack direction="row" gap="md">
								{ editUrl( shown ) && (
									<Button
										variant="link"
										href={ editUrl( shown ) }
										/* translators: %s: menu name. */
										aria-label={ sprintf( __( 'Edit %s in the Site Editor', 'newspack-plugin' ), title( shown ) ) }
									>
										{ __( 'Edit', 'newspack-plugin' ) }
									</Button>
								) }
								{ ! current && brand.id > 0 && (
									<Fragment>
										<Button variant="link" onClick={ () => copy( id, false ) }>
											{ __( 'Copy', 'newspack-plugin' ) }
										</Button>
										<Button variant="link" onClick={ () => copy( id, true ) }>
											{ __( 'Add new', 'newspack-plugin' ) }
										</Button>
									</Fragment>
								) }
							</Stack>
						</Stack>
					);
				} ) }
				{ shared.size > 0 && ! brand.id && (
					<Text variant="body-md" render={ <p /> }>
						{ __( 'Save the brand to make menus for it.', 'newspack-plugin' ) }
					</Text>
				) }
				{ ! shared.size && ! own.length && (
					<Text variant="body-md" render={ <p /> }>
						{ __( 'This brand’s header, footer and sidebar have no menus.', 'newspack-plugin' ) }
					</Text>
				) }
			</Stack>
		</Grid>
	);
}

/**
 * Header, footer and sidebar parts on a brand's pages.
 *
 * @param props                Props.
 * @param props.brand          Brand.
 * @param props.brands         All saved brands.
 * @param props.updateMeta     Updates the brand meta.
 * @param props.wizardApiFetch API fetch.
 */
export function BlockThemeParts( { brand, brands, updateMeta, wizardApiFetch }: SectionProps ) {
	const [ parts, setParts ] = useState< Record< string, BlockThemePart > >( blockThemeData?.parts || {} );
	const [ message, setMessage ] = useState( '' );
	const [ pendingReplace, setPendingReplace ] = useState< { slot: BlockThemeSlot; copy: BlockThemePart } | null >( null );
	const { confirmDialog, requestConfirm } = useConfirmDialog( {
		title: __( 'Replace the copy?', 'newspack-plugin' ),
		message: pendingReplace
			? sprintf(
					/* translators: 1: copy's title, 2: main template part title. */
					__( 'Replace “%1$s” with a fresh copy of “%2$s”? Changes made to it will be lost.', 'newspack-plugin' ),
					pendingReplace.copy.title,
					parts[ pendingReplace.slot.main ]?.title || pendingReplace.slot.main
			  ) +
			  ' ' +
			  partUsage( pendingReplace.copy.slug, brand, brands )
			: '',
		confirmButtonText: __( 'Replace', 'newspack-plugin' ),
		isDestructive: true,
	} );
	if ( ! blockThemeData ) {
		return null;
	}
	const choices = partChoices( brand );

	const setChoice = ( slot: BlockThemeSlot, slug: string ) => {
		const next = { ...choices };
		delete next[ slot.slot ];
		if ( slug && slug !== slot.main ) {
			next[ slot.slot ] = slug;
		}
		updateMeta( { _block_theme_parts: next } );
	};

	const earlierCopy = ( slot: BlockThemeSlot ) =>
		Object.values( parts ).find( part => part.slug !== slot.main && part.copyOf === `${ brand.id }:${ slot.slot }` );

	const copy = ( slot: BlockThemeSlot, replace: boolean ) => {
		setMessage( '' );
		wizardApiFetch(
			{
				path: `/newspack-multibranded-site/v1/brands/${ brand.id }/copy-part`,
				method: 'POST',
				data: { slot: slot.slot, replace },
			},
			{
				onSuccess( part: BlockThemePart ) {
					blockThemeData.parts[ part.slug ] = part;
					setParts( current => ( { ...current, [ part.slug ]: part } ) );
					setChoice( slot, part.slug );
					setMessage(
						replace
							? /* translators: %s: template part title. */ sprintf(
									__( '“%s” was replaced with a fresh copy and selected. Save the brand to use it.', 'newspack-plugin' ),
									part.title
							  )
							: /* translators: %s: template part title. */ sprintf(
									__( '“%s” was made and selected. Save the brand to use it, then edit it in the Site Editor.', 'newspack-plugin' ),
									part.title
							  )
					);
				},
			}
		);
	};

	const owned: string[] = [];
	blockThemeData.slots.forEach( slot => {
		const slug = ownPart( brand, slot );
		if ( slug ) {
			/* translators: 1: slot name, e.g. "Desktop header", 2: template part title. */
			owned.push( sprintf( __( '%1$s (%2$s)', 'newspack-plugin' ), slot.label, parts[ slug ]?.title || slug ) );
		}
	} );
	let status = __( 'Uses the main site’s header, footer and sidebar.', 'newspack-plugin' );
	if ( owned.length === blockThemeData.slots.length ) {
		/* translators: %s: list of template parts. */
		status = sprintf( __( 'This brand’s own parts: %s.', 'newspack-plugin' ), listNames( owned ) );
	} else if ( owned.length ) {
		/* translators: %s: list of template parts. */
		status = sprintf( __( 'This brand’s own parts: %s. Everything else uses the main site’s parts.', 'newspack-plugin' ), listNames( owned ) );
	}

	return (
		<Fragment>
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					heading={ 2 }
					noMargin
					title={ __( 'Header, footer and sidebar', 'newspack-plugin' ) }
					description={ __(
						'Leave these on the main header, footer and sidebar unless this brand needs a different layout. “Copy” makes a template part named “… [Brand]” that you can edit in the Site Editor. A copy is separate from the main one: later changes to the main part don’t appear in it.',
						'newspack-plugin'
					) }
				/>
				<Stack direction="column" gap="lg">
					<Text variant="body-md" render={ <p /> }>
						{ status }
					</Text>
					{ message && (
						<Notice status="success" isDismissible={ false }>
							{ message }
						</Notice>
					) }
					{ ! brand.id && (
						<Text variant="body-md" render={ <p /> }>
							{ __( 'Save the brand to make copies for it.', 'newspack-plugin' ) }
						</Text>
					) }
					{ blockThemeData.slots.map( slot => {
						const current = ownPart( brand, slot );
						const shown = current || slot.main;
						const mainTitle = parts[ slot.main ]?.title || slot.main;
						const saved = choices[ slot.slot ];
						const earlier = current ? null : earlierCopy( slot );
						const help = [ partUsage( shown, brand, brands ) ];
						if ( saved && saved !== slot.main && ! current ) {
							help.push(
								sprintf(
									/* translators: %s: template part slug. */
									__(
										'The chosen part (%s) isn’t available in the current theme, so this brand shows the main one.',
										'newspack-plugin'
									),
									saved
								)
							);
						}
						return (
							<Stack key={ slot.slot } direction="column" gap="sm">
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									id={ `newspack-brand-part-${ slot.slot }` }
									label={ slot.label }
									help={ help.filter( Boolean ).join( ' ' ) }
									value={ current }
									onChange={ ( value: string ) => setChoice( slot, value ) }
								>
									<option value="">
										{ /* translators: %s: template part title. */ sprintf( __( 'Main site: %s', 'newspack-plugin' ), mainTitle ) }
									</option>
									<optgroup label={ __( 'Other template parts', 'newspack-plugin' ) }>
										{ Object.values( parts )
											.filter( part => part.area === slot.area && part.slug !== slot.main )
											.map( part => (
												<option key={ part.slug } value={ part.slug }>
													{ part.title }
												</option>
											) ) }
									</optgroup>
								</SelectControl>
								<Stack direction="row" gap="md">
									{ parts[ shown ] && (
										<Button
											variant="link"
											href={ parts[ shown ].editUrl }
											/* translators: %s: template part title. */
											aria-label={ sprintf( __( 'Edit %s in the Site Editor', 'newspack-plugin' ), parts[ shown ].title ) }
										>
											{ __( 'Edit', 'newspack-plugin' ) }
										</Button>
									) }
									{ brand.id > 0 && ! current && ! earlier && (
										<Button variant="link" onClick={ () => copy( slot, false ) }>
											{ __( 'Copy', 'newspack-plugin' ) }
										</Button>
									) }
								</Stack>
								{ brand.id > 0 && earlier && (
									<Text variant="body-md" render={ <p /> }>
										{
											/* translators: %s: template part title. */
											sprintf( __( 'This brand already has a copy, “%s”:', 'newspack-plugin' ), earlier.title )
										}{ ' ' }
										<Button variant="link" onClick={ () => setChoice( slot, earlier.slug ) }>
											{ __( 'Use it again', 'newspack-plugin' ) }
										</Button>{ ' ' }
										<Button
											variant="link"
											isDestructive
											onClick={ () => {
												setPendingReplace( { slot, copy: earlier } );
												requestConfirm( () => copy( slot, true ) );
											} }
										>
											{ __( 'Replace it with a fresh copy', 'newspack-plugin' ) }
										</Button>
									</Text>
								) }
							</Stack>
						);
					} ) }
				</Stack>
			</Grid>
			{ confirmDialog }
		</Fragment>
	);
}
