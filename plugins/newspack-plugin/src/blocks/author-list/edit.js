/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { BlockControls, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	BaseControl,
	CheckboxControl,
	Notice,
	PanelBody,
	PanelRow,
	Placeholder,
	RadioControl,
	RangeControl,
	Spinner,
	ToggleControl,
	Toolbar,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalUnitControl as UnitControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { columns as columnsIcon, pencil, listView, pullLeft, pullRight } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { avatarSizeOptions, textSizeOptions, units } from '../author-profile/edit';

/**
 * Internal dependencies
 */
import { SingleAuthor } from '../author-profile/single-author';
import { AuthorDisplaySettings } from '../shared/author';

/**
 * External dependencies
 */
import { AutocompleteWithSuggestions } from 'newspack-components';
import classnames from 'classnames';

const AuthorList = ( { attributes, clientId, setAttributes } ) => {
	const [ authors, setAuthors ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ maxItemsToSuggest, setMaxItemsToSuggest ] = useState( 0 );
	const canUseCAP = Boolean( window?.newspack_blocks_data?.can_use_cap );
	const editableRoles = window?.newspack_blocks_data?.editable_roles || [];

	const separators = [];
	const {
		authorRoles,
		authorType,
		columns,
		exclude,
		excludeEmpty,
		layout,
		showAvatar,
		showSeparators,
		separatorSections,
		textSize,
		avatarAlignment,
		avatarBorderRadius,
		avatarSize,
		avatarHideDefault,
	} = attributes;
	const isColumns = 'columns' === layout;

	useEffect( () => {
		getAuthors();
	}, [ authorRoles, authorType, avatarHideDefault, exclude, excludeEmpty ] );

	const blockProps = useBlockProps( {
		className: classnames( attributes.className, 'wp-block-newspack-blocks-author-list' ),
	} );

	const getAuthors = async () => {
		setError( null );
		setIsLoading( true );
		try {
			const params = {
				author_type: authorType,
				author_roles: authorRoles,
				exclude: exclude.map( exclusion => ( {
					value: exclusion.value,
					isGuest: exclusion.isGuest,
				} ) ),
			};

			if ( excludeEmpty ) {
				params.exclude_empty = 1;
			}

			if ( avatarHideDefault ) {
				params.avatar_hide_default = 1;
			}

			const response = await apiFetch( {
				path: addQueryArgs( '/newspack-blocks/v1/author-list', params ),
			} );

			if ( ! response ) {
				throw __( 'Error fetching authors.', 'newspack-plugin' );
			}
			setAuthors( response );
		} catch ( e ) {
			setError( e.message || e || __( 'Error fetching authors.', 'newspack-plugin' ) );
		}
		setIsLoading( false );
	};

	// Authors grouped by alphabet.
	const chunkedAuthors = {};
	if ( Array.isArray( authors ) && separatorSections ) {
		authors.forEach( author => {
			const { last_name: lastName } = author;
			const firstLetter = lastName.charAt( 0 ).toUpperCase();
			if ( ! chunkedAuthors.hasOwnProperty( firstLetter ) ) {
				chunkedAuthors[ firstLetter ] = [];
			}
			chunkedAuthors[ firstLetter ].push( author );
		} );
	}

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Author List Settings', 'newspack-plugin' ) }>
					{ canUseCAP && (
						<PanelRow>
							<RadioControl
								label={ __( 'Author Type', 'newspack-plugin' ) }
								help={ sprintf(
									// translators: %s: help text for author type selection.
									__( '%s will be displayed.', 'newspack-plugin' ),
									'all' === authorType
										? __( 'Both guest authors and WP users', 'newspack-plugin' )
										: sprintf(
												// translators: %s: currently selected author type option.
												__( '%s only', 'newspack-plugin' ),
												'guest-authors' === authorType
													? __( 'Guest authors', 'newspack-plugin' )
													: __( 'WP users', 'newspack-plugin' )
										  )
								) }
								selected={ authorType || 'all' }
								options={ [
									{ label: __( 'All authors', 'newspack-plugin' ), value: 'all' },
									{ label: __( 'Guest authors', 'newspack-plugin' ), value: 'guest-authors' },
									{ label: __( 'WP users', 'newspack-plugin' ), value: 'users' },
								] }
								onChange={ value => setAttributes( { authorType: value } ) }
							/>
						</PanelRow>
					) }
					{ isColumns && (
						<PanelRow>
							<RangeControl
								label={ __( 'Columns', 'newspack-plugin' ) }
								value={ columns }
								onChange={ _columns => setAttributes( { columns: _columns } ) }
								min={ 2 }
								max={ 6 }
								required
							/>
						</PanelRow>
					) }
					{ 'guest-authors' !== authorType && (
						<PanelRow>
							<BaseControl id="newspack-blocks__author-list-roles" label={ __( 'WP User Roles', 'newspack-plugin' ) }>
								{ editableRoles.map( ( role, index ) => (
									<CheckboxControl
										checked={ -1 < authorRoles.indexOf( role.slug ) }
										key={ index }
										label={ role.label }
										value={ role.slug }
										onChange={ check => {
											const selectedRoles = check
												? [ ...authorRoles, role.slug ]
												: authorRoles.filter( _role => _role !== role.slug );

											setAttributes( { authorRoles: selectedRoles } );
										} }
									/>
								) ) }
							</BaseControl>
						</PanelRow>
					) }
					<PanelRow>
						<ToggleControl
							label={ __( 'Display alphabetical separators', 'newspack-plugin' ) }
							help={ __( 'Chunk authors alphabetically.', 'newspack-plugin' ) }
							checked={ showSeparators }
							onChange={ () => setAttributes( { showSeparators: ! showSeparators } ) }
						/>
					</PanelRow>
					{ isColumns && showSeparators && (
						<PanelRow>
							<ToggleControl
								label={ __( 'Group authors by alphabet', 'newspack-plugin' ) }
								help={ __( 'Display each alphabetical chunk as a discrete section.', 'newspack-plugin' ) }
								checked={ separatorSections }
								onChange={ () => setAttributes( { separatorSections: ! separatorSections } ) }
							/>
						</PanelRow>
					) }
				</PanelBody>
				<PanelBody title={ __( 'Author List Exclusions', 'newspack-block' ) }>
					<PanelRow>
						<ToggleControl
							label={ __( 'Exclude authors with 0 posts', 'newspack-plugin' ) }
							help={ sprintf(
								// translators: %s: help message for "include empty authors" toggle.
								__( 'Authors with no published posts will be %s.', 'newspack-plugin' ),
								excludeEmpty ? __( 'hidden', 'newspack-plugin' ) : __( 'displayed', 'newspack-plugin' )
							) }
							checked={ excludeEmpty }
							onChange={ () => setAttributes( { excludeEmpty: ! excludeEmpty } ) }
						/>
					</PanelRow>
					<PanelRow>
						<AutocompleteWithSuggestions
							label={ __( 'Search by author name', 'newspack-plugin' ) }
							help={ __( 'Authors selected here will not be displayed.', 'newspack-plugin' ) }
							fetchSuggestions={ async ( search = null, offset = 0 ) => {
								const response = await apiFetch( {
									parse: false,
									path: addQueryArgs( '/newspack-blocks/v1/authors', {
										search,
										offset,
										fields: 'id,name',
									} ),
								} );

								const total = parseInt( response.headers.get( 'x-wp-total' ) || 0 );
								const suggestions = await response.json();

								// Set max items for "load more" functionality in suggestions list.
								if ( ! maxItemsToSuggest && ! search ) {
									setMaxItemsToSuggest( total );
								}

								return suggestions.map( _author => ( {
									value: _author.id,
									label: decodeEntities( _author.name ) || __( '(no name)', 'newspack-plugin' ),
									isGuest: _author.is_guest,
								} ) );
							} }
							maxItemsToSuggest={ maxItemsToSuggest }
							multiSelect={ true }
							onChange={ items => setAttributes( { exclude: items } ) }
							postTypeLabel={ __( 'author', 'newspack-plugin' ) }
							postTypeLabelPlural={ __( 'authors', 'newspack-plugin' ) }
							selectedItems={ exclude }
							__next40pxDefaultSize
						/>
					</PanelRow>
				</PanelBody>
				<PanelBody title={ __( 'Author Profile Settings', 'newspack-plugin' ) }>
					<ToggleGroupControl
						label={ __( 'Text Size', 'newspack-plugin' ) }
						value={ textSize }
						onChange={ value => setAttributes( { textSize: value } ) }
						isBlock
						__next40pxDefaultSize
					>
						{ textSizeOptions.map( option => (
							<ToggleGroupControlOption key={ option.value } label={ option.shortName } value={ option.value } />
						) ) }
					</ToggleGroupControl>
					<AuthorDisplaySettings attributes={ attributes } setAttributes={ setAttributes } />
				</PanelBody>
				<PanelBody title={ __( 'Avatar', 'newspack-plugin' ) }>
					<ToggleControl
						label={ __( 'Display avatar', 'newspack-plugin' ) }
						checked={ showAvatar }
						onChange={ () => setAttributes( { showAvatar: ! showAvatar } ) }
					/>
					{ showAvatar && (
						<ToggleControl
							label={ __( 'Hide default avatar', 'newspack-plugin' ) }
							checked={ avatarHideDefault }
							onChange={ () => setAttributes( { avatarHideDefault: ! avatarHideDefault } ) }
						/>
					) }
					{ showAvatar && (
						<ToggleGroupControl
							label={ __( 'Size', 'newspack-plugin' ) }
							aria-label={ __( 'Avatar size', 'newspack-plugin' ) }
							value={ avatarSize }
							onChange={ value => setAttributes( { avatarSize: value } ) }
							isBlock
							__next40pxDefaultSize
						>
							{ avatarSizeOptions.map( option => (
								<ToggleGroupControlOption key={ option.value } label={ option.shortName } value={ option.value } />
							) ) }
						</ToggleGroupControl>
					) }
					{ showAvatar && (
						<UnitControl
							label={ __( 'Border radius', 'newspack-plugin' ) }
							aria-label={ __( 'Avatar border radius', 'newspack-plugin' ) }
							labelPosition="edge"
							units={ units }
							value={ avatarBorderRadius }
							onChange={ value => setAttributes( { avatarBorderRadius: 0 > parseFloat( value ) ? '0' : value } ) }
							__next40pxDefaultSize
							__unstableInputWidth="80px"
						/>
					) }
				</PanelBody>
			</InspectorControls>
			{ authors && ! isLoading && (
				<BlockControls>
					<Toolbar
						controls={ [
							{
								icon: listView,
								title: __( 'List', 'newspack-plugin' ),
								isActive: 'list' === layout,
								onClick: () => {
									setAttributes( { layout: 'list' } );
								},
							},
							{
								icon: columnsIcon,
								title: __( 'Columns', 'newspack-plugin' ),
								isActive: isColumns,
								onClick: () => {
									setAttributes( { layout: 'columns' } );
								},
							},
						] }
					/>
					{ showAvatar && 'is-style-center' !== attributes.className && (
						<Toolbar
							controls={ [
								{
									icon: pullLeft,
									title: __( 'Show avatar on left', 'newspack-plugin' ),
									isActive: avatarAlignment === 'left',
									onClick: () => setAttributes( { avatarAlignment: 'left' } ),
								},
								{
									icon: pullRight,
									title: __( 'Show avatar on right', 'newspack-plugin' ),
									isActive: avatarAlignment === 'right',
									onClick: () => setAttributes( { avatarAlignment: 'right' } ),
								},
							] }
						/>
					) }
					<Toolbar
						controls={ [
							{
								icon: pencil,
								title: __( 'Edit selection', 'newspack-plugin' ),
								onClick: () => {
									setAttributes( { authorId: 0 } );
								},
							},
						] }
					/>
				</BlockControls>
			) }
			<div { ...blockProps }>
				{ ! isLoading && ! error && authors && Array.isArray( authors ) && (
					<>
						{ isColumns && showSeparators && separatorSections ? (
							Object.keys( chunkedAuthors ).map( ( firstLetter, index ) => (
								<Fragment key={ index }>
									<h2
										className="newspack-blocks__author-list-separator"
										id={ `newspack-blocks__author-list-separator__${ clientId }__${ firstLetter }` }
									>
										{ firstLetter }
									</h2>
									<ul
										className={ classnames( 'newspack-blocks__author-list-container', {
											'is-columns': isColumns,
											[ `columns-${ columns }` ]: isColumns,
										} ) }
									>
										{ chunkedAuthors[ firstLetter ].map( ( author, i ) => (
											<li key={ i } className="newspack-blocks__author-list-item">
												<SingleAuthor author={ author } attributes={ attributes } />
											</li>
										) ) }
									</ul>
								</Fragment>
							) )
						) : (
							<ul
								className={ classnames( 'newspack-blocks__author-list-container', {
									'is-columns': isColumns,
									[ `columns-${ columns }` ]: isColumns,
								} ) }
							>
								{ authors.map( ( author, index ) => {
									const { last_name: lastName } = author;
									const firstLetter = lastName.charAt( 0 ).toUpperCase();
									const showSeparator = 0 > separators.indexOf( firstLetter );

									if ( showSeparator ) {
										separators.push( firstLetter );
									}

									return (
										<Fragment key={ index }>
											{ showSeparators && showSeparator && (
												<li className="newspack-blocks__author-list-item">
													<h2
														className="newspack-blocks__author-list-separator"
														id={ `newspack-blocks__author-list-separator__${ clientId }__${ firstLetter }` }
													>
														{ firstLetter }
													</h2>
												</li>
											) }

											<li className="newspack-blocks__author-list-item">
												<SingleAuthor author={ author } attributes={ attributes } />
											</li>
										</Fragment>
									);
								} ) }
							</ul>
						) }
					</>
				) }
				{ ( ! authors || isLoading ) && (
					<Placeholder icon={ listView } label={ __( 'Author List', 'newspack-plugin' ) }>
						{ error && (
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						) }
						{ isLoading && (
							<VStack alignment="center" style={ { width: '100%' } }>
								<Spinner style={ { margin: '0' } } />
								<span style={ { fontWeight: '500' } }>{ __( 'Fetching authors…', 'newspack-plugin' ) }</span>
							</VStack>
						) }
					</Placeholder>
				) }
			</div>
		</>
	);
};

export default AuthorList;
