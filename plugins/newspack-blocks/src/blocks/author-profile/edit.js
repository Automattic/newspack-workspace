/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	BlockControls,
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	store as blockEditorStore,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalBlockVariationPicker as BlockVariationPicker,
} from '@wordpress/block-editor';
import { createBlocksFromInnerBlocksTemplate, getBlockType, registerBlockBindingsSource, store as blocksStore } from '@wordpress/blocks';
import {
	Button,
	Card,
	CardBody,
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
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
import { store as coreStore } from '@wordpress/core-data';
import { useEffect, useState, useMemo } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { pencil, postAuthor, pullLeft, pullRight } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Register block bindings source for author data in the editor.
 * This enables core blocks to display author data via bindings.
 *
 * Note: The binding reads from a global author object that is set by the
 * AuthorContext.Provider. This is a workaround since bindings don't have
 * direct access to React context.
 */
// Per-instance author map for block bindings.
// Each Author Profile block registers its author here keyed by clientId,
// so multiple instances on the same page don't overwrite each other.
window.__newspackAuthorsByBlock = window.__newspackAuthorsByBlock || {};

if ( typeof registerBlockBindingsSource === 'function' ) {
	registerBlockBindingsSource( {
		name: 'newspack-blocks/author',
		label: __( 'Author Profile', 'newspack-blocks' ),
		getValues( { bindings, clientId, select } ) {
			// Find the parent Author Profile block and look up its author.
			const parents = select( 'core/block-editor' ).getBlockParents( clientId );
			const authorMap = window.__newspackAuthorsByBlock;
			const parentId = parents.find( id => authorMap[ id ] );
			const author = ( parentId && authorMap[ parentId ] ) || {};
			// Return empty for missing fields so WordPress core shows each block's
			// own `placeholder` attribute in its native greyed-out style.
			// Skip placeholder authors entirely (Site Editor template context).
			const isPlaceholder = author.id === 'placeholder';
			return Object.fromEntries(
				Object.entries( bindings ).map( ( [ attribute, { args } ] ) => {
					const key = args?.key;
					if ( ! key || isPlaceholder ) {
						return [ attribute, '' ];
					}
					// Handle special cases.
					if ( key === 'url' || key === 'archive_url' ) {
						return [ attribute, author.url || '' ];
					}
					// "More by Author Name" link text.
					if ( key === 'archive_link_text' ) {
						const linkText = author.name
							? sprintf(
									/* translators: %s: author name */
									__( 'More by %s', 'newspack-blocks' ),
									author.name
							  )
							: '';
						// Return HTML with link tag for editor preview.
						const linkUrl = author.url || '#';
						return [ attribute, linkText ? `<a href="${ linkUrl }" class="no-op">${ linkText }</a>` : '' ];
					}
					return [ attribute, author[ key ] || '' ];
				} )
			);
		},
	} );
}

/**
 * Internal dependencies
 */
import { SingleAuthor } from './single-author';
import { AuthorDisplaySettings } from '../shared/author';
import { AuthorContext } from './context';

/**
 * External dependencies
 */
import { AutocompleteWithSuggestions } from 'newspack-components';

// Available units for avatarBorderRadius option.
export const units = [
	{
		value: '%',
		label: '%',
	},
	{
		value: 'px',
		label: 'px',
	},
	{
		value: 'em',
		label: 'em',
	},
	{
		value: 'rem',
		label: 'rem',
	},
];

// Textsize options.
export const textSizeOptions = [
	{
		value: 'small',
		label: /* translators: label for small text size option */ __( 'Small', 'newspack-blocks' ),
		shortName: /* translators: abbreviation for small text size option */ __( 'S', 'newspack-blocks' ),
	},
	{
		value: 'medium',
		label: /* translators: label for medium text size option */ __( 'Medium', 'newspack-blocks' ),
		shortName: /* translators: abbreviation for medium text size option */ __( 'M', 'newspack-blocks' ),
	},
	{
		value: 'large',
		label: /* translators: label for small text size option */ __( 'Large', 'newspack-blocks' ),
		shortName: /* translators: abbreviation for large text size option */ __( 'L', 'newspack-blocks' ),
	},
	{
		value: 'extra-large',
		label: /* translators: label for extra-large text size option */ __( 'Extra Large', 'newspack-blocks' ),
		shortName: /* translators: abbreviation for small text size option */ __( 'XL', 'newspack-blocks' ),
	},
];

// Avatar size options.
export const avatarSizeOptions = [
	{
		value: 72,
		label: /* translators: label for small avatar size option */ __( 'Small', 'newspack-blocks' ),
		shortName: /* translators: abbreviation for small avatar size option */ __( 'S', 'newspack-blocks' ),
	},
	{
		value: 128,
		label: /* translators: label for medium avatar size option */ __( 'Medium', 'newspack-blocks' ),
		shortName: /* translators: abbreviation for medium avatar size option */ __( 'M', 'newspack-blocks' ),
	},
	{
		value: 192,
		label: /* translators: label for large avatar size option */ __( 'Large', 'newspack-blocks' ),
		shortName: /* translators: abbreviation for large avatar size option */ __( 'L', 'newspack-blocks' ),
	},
	{
		value: 256,
		label: /* translators: label for extra-large avatar size option */ __( 'Extra-large', 'newspack-blocks' ),
		shortName: /* translators: abbreviation for extra-large avatar size option  */ __( 'XL', 'newspack-blocks' ),
	},
];

/**
 * Variation picker shown when the block has no inner blocks.
 * Follows the same pattern as the core Columns block.
 */
function VariationPlaceholder( { clientId, name, setAttributes } ) {
	const { blockType, defaultVariation, variations } = useSelect(
		select => {
			const { getBlockVariations, getBlockType: _getBlockType, getDefaultBlockVariation } = select( blocksStore );
			return {
				blockType: _getBlockType( name ),
				defaultVariation: getDefaultBlockVariation( name, 'block' ),
				variations: getBlockVariations( name, 'block' ),
			};
		},
		[ name ]
	);
	const { replaceInnerBlocks } = useDispatch( blockEditorStore );
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<BlockVariationPicker
				icon={ blockType?.icon?.src }
				label={ blockType?.title }
				variations={ variations }
				instructions={ __( 'Select a layout to start with:', 'newspack-blocks' ) }
				onSelect={ ( nextVariation = defaultVariation ) => {
					setAttributes( { ...nextVariation.attributes, variation: nextVariation.name } );
					if ( nextVariation.innerBlocks ) {
						replaceInnerBlocks( clientId, createBlocksFromInnerBlocksTemplate( nextVariation.innerBlocks ), true );
					}
				} }
			/>
		</div>
	);
}

// Module-level cache for social icon SVGs so multiple block instances share one fetch.
let socialIconSvgsCache = null;
const fetchSocialIconSvgs = () => {
	if ( ! socialIconSvgsCache ) {
		socialIconSvgsCache = apiFetch( { path: '/newspack/v1/social-icons' } ).catch( () => ( {} ) );
	}
	return socialIconSvgsCache;
};

// Placeholder author for Site Editor template context.
// Builds the social entries from the SVG map so every supported service gets an
// inner block in the template. Publishers can then remove the ones they don't need.
const DEFAULT_PLACEHOLDER_AUTHOR = Object.freeze( {
	id: 'placeholder',
	url: '#',
	avatar: '', // Empty triggers the avatar block's built-in placeholder rendering.
	social: Object.freeze( {} ),
	email: Object.freeze( { url: 'mailto:placeholder@example.com', svg: '' } ),
	newspack_phone_number: Object.freeze( { url: 'tel:0000000000', svg: '' } ),
} );

const getPlaceholderAuthor = ( socialIconSvgs = {} ) => {
	const hasSocialSvgs = Object.keys( socialIconSvgs ).length > 0;
	if ( ! hasSocialSvgs ) {
		return DEFAULT_PLACEHOLDER_AUTHOR;
	}

	const social = Object.fromEntries(
		Object.entries( socialIconSvgs )
			.filter( ( [ key ] ) => ! [ 'email', 'phone' ].includes( key ) ) // Exclude top-level properties on the author object.
			.map( ( [ service, svg ] ) => [ service, { url: '#', svg: svg || '' } ] )
	);

	return {
		...DEFAULT_PLACEHOLDER_AUTHOR,
		social,
		email: { url: 'mailto:placeholder@example.com', svg: socialIconSvgs.email || '' },
		newspack_phone_number: { url: 'tel:0000000000', svg: socialIconSvgs.phone || '' },
	};
};

const AuthorProfile = ( { attributes, setAttributes, context, clientId } ) => {
	const { replaceInnerBlocks } = useDispatch( blockEditorStore );

	// ALL HOOKS MUST BE CALLED UNCONDITIONALLY (React rules of hooks)
	const [ author, setAuthor ] = useState( null );
	const [ contextualAuthors, setContextualAuthors ] = useState( [] );
	const [ suggestions, setSuggestions ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ maxItemsToSuggest, setMaxItemsToSuggest ] = useState( 0 );
	const [ showSpecificSelector, setShowSpecificSelector ] = useState( false );
	const [ previewAuthorIndex, setPreviewAuthorIndex ] = useState( 0 );
	const [ socialIconSvgs, setSocialIconSvgs ] = useState( {} );

	const {
		authorId,
		isGuestAuthor,
		isContextual,
		layoutVersion,
		showSocial,
		showEmail,
		textSize,
		showAvatar,
		avatarAlignment,
		avatarBorderRadius,
		avatarSize,
		avatarHideDefault,
		showEmptyBio,
		variation,
	} = attributes;
	const blockProps = useBlockProps();

	// Get post ID from block context or editor
	const editorPostId = useSelect( select => select( 'core/editor' )?.getCurrentPostId?.(), [] );
	const postId = context?.postId || editorPostId;

	// Check if custom byline is active and extract referenced author IDs.
	// Returns IDs as a comma-separated string to avoid new array references on each render.
	const { customBylineActive, bylineAuthorIdsStr } = useSelect(
		select => {
			if ( ! isContextual ) {
				return { customBylineActive: false, bylineAuthorIdsStr: '' };
			}
			const meta = select( 'core/editor' )?.getEditedPostAttribute?.( 'meta' );
			const isActive = meta?._newspack_byline_active ?? false;
			if ( ! isActive ) {
				return { customBylineActive: false, bylineAuthorIdsStr: '' };
			}
			const byline = meta?._newspack_byline ?? '';
			const ids = [ ...byline.matchAll( /\[Author\s+id\s*=\s*(\d+)\]/gi ) ].map( m => m[ 1 ] );
			return { customBylineActive: true, bylineAuthorIdsStr: ids.join( ',' ) };
		},
		[ isContextual ]
	);
	const bylineAuthorIds = bylineAuthorIdsStr ? bylineAuthorIdsStr.split( ',' ).map( Number ) : [];

	// Detect Site Editor template context where real author data is not meaningful.
	const isTemplateLikeContext = useSelect( select => {
		const postType = select( 'core/editor' )?.getCurrentPostType?.();
		return postType === 'wp_template' || postType === 'wp_template_part';
	}, [] );

	// Fetch social icon SVGs for the placeholder in template context.
	useEffect( () => {
		if ( ! isTemplateLikeContext ) {
			return;
		}
		fetchSocialIconSvgs().then( setSocialIconSvgs );
	}, [ isTemplateLikeContext ] );

	// Nested inner blocks mode is enabled automatically in block themes when
	// Newspack Plugin is active (provides the avatar and social links blocks).
	const isNestedMode = useSelect( select => {
		const theme = select( coreStore ).getCurrentTheme();
		return ( theme?.is_block_theme ?? false ) && !! getBlockType( 'newspack/avatar' ) && !! getBlockType( 'newspack/author-profile-social' );
	}, [] );

	// Check for inner blocks (pattern picker shows when empty).
	const hasInnerBlocks = useSelect( select => !! select( blockEditorStore ).getBlocks( clientId ).length, [ clientId ] );

	// Look up the active variation's template from the store.
	const blockVariations = useSelect( select => select( blocksStore ).getBlockVariations( 'newspack-blocks/author-profile', 'block' ), [] );
	const defaultVariation = useMemo( () => blockVariations?.find( v => v.isDefault ), [ blockVariations ] );
	const activeVariationTemplate = useMemo( () => {
		const match = blockVariations?.find( v => v.name === variation );
		return match?.innerBlocks || defaultVariation?.innerBlocks;
	}, [ blockVariations, variation, defaultVariation ] );

	// Set layoutVersion to 2 for brand new blocks in block themes.
	// This persists the mode choice and enables InnerBlocks-based layout.
	// Only converts unconfigured blocks to preserve existing blocks created in classic themes.
	const isUnconfiguredBlock = authorId === 0 && ! isContextual;
	useEffect( () => {
		if ( isNestedMode && layoutVersion === 1 && isUnconfiguredBlock ) {
			setAttributes( { layoutVersion: 2 } );
		}
	}, [ isNestedMode, layoutVersion, isUnconfiguredBlock, setAttributes ] );

	// Auto-populate inner blocks from variation attribute on mount (e.g., when inserted from a pattern).
	// Only runs once; subsequent empty states show the variation picker instead of auto-repopulating.
	// Initialized with hasInnerBlocks so existing blocks (after page reload) don't re-populate.
	const [ didAutoPopulate, setDidAutoPopulate ] = useState( hasInnerBlocks );
	useEffect( () => {
		if ( ! didAutoPopulate && variation && ! hasInnerBlocks && activeVariationTemplate ) {
			replaceInnerBlocks( clientId, createBlocksFromInnerBlocksTemplate( activeVariationTemplate ), true );
			setDidAutoPopulate( true );
		}
	}, [ didAutoPopulate, variation, hasInnerBlocks, activeVariationTemplate, clientId, replaceInnerBlocks ] );

	// Fetch author for specific mode
	useEffect( () => {
		if ( isContextual || 0 === authorId ) {
			return;
		}
		getAuthorById();
	}, [ authorId, avatarHideDefault, isGuestAuthor, isContextual ] );

	// Fetch authors for contextual mode
	useEffect( () => {
		if ( ! isContextual || isTemplateLikeContext ) {
			setContextualAuthors( [] );
			return;
		}
		// When custom byline is active, fetch the specific byline authors.
		if ( customBylineActive ) {
			if ( bylineAuthorIds.length ) {
				getBylineAuthors();
			} else {
				setContextualAuthors( [] );
			}
			return;
		}
		if ( ! postId ) {
			setContextualAuthors( [] );
			return;
		}
		getContextualAuthors();
	}, [ isContextual, postId, avatarHideDefault, showEmail, customBylineActive, bylineAuthorIdsStr, isTemplateLikeContext ] );

	const getAuthorById = async () => {
		setError( null );
		setIsLoading( true );
		try {
			const params = {
				author_id: authorId,
				is_guest_author: isGuestAuthor ? 1 : 0,
				fields: 'id,name,bio,email,social,avatar,url',
			};

			if ( avatarHideDefault ) {
				params.avatar_hide_default = 1;
			}

			const response = await apiFetch( {
				path: addQueryArgs( '/newspack-blocks/v1/authors', params ),
			} );

			const _author = response.pop();

			if ( ! _author ) {
				throw sprintf(
					/* translators: %s: error text for when no authors are found. */
					__( 'No authors or guest authors found for ID %s.', 'newspack-blocks' ),
					authorId
				);
			}
			setAuthor( _author );
		} catch ( e ) {
			setError(
				e.message ||
					e ||
					sprintf(
						/* translators: %s: error text for when no authors are found. */
						__( 'No authors or guest authors found for ID %s.', 'newspack-blocks' ),
						authorId
					)
			);
		}
		setIsLoading( false );
	};

	const getContextualAuthors = async () => {
		setError( null );
		setIsLoading( true );
		try {
			// Only fetch email if showEmail is enabled (privacy consideration)
			const fields = [ 'id', 'name', 'bio', 'social', 'avatar', 'url' ];
			if ( showEmail ) {
				fields.push( 'email' );
			}

			const params = {
				post_id: postId,
				fields: fields.join( ',' ),
			};

			if ( avatarHideDefault ) {
				params.avatar_hide_default = 1;
			}

			const response = await apiFetch( {
				path: addQueryArgs( '/newspack-blocks/v1/authors', params ),
			} );

			setContextualAuthors( response || [] );
		} catch ( e ) {
			setError( e.message || e || __( 'Error fetching authors for this post.', 'newspack-blocks' ) );
			setContextualAuthors( [] );
		}
		setIsLoading( false );
	};

	const getBylineAuthors = async () => {
		setError( null );
		setIsLoading( true );
		try {
			const fields = [ 'id', 'name', 'bio', 'social', 'avatar', 'url' ];
			if ( showEmail ) {
				fields.push( 'email' );
			}
			const results = await Promise.all(
				bylineAuthorIds.map( id => {
					const params = {
						author_id: id,
						is_guest_author: 0,
						fields: fields.join( ',' ),
					};
					if ( avatarHideDefault ) {
						params.avatar_hide_default = 1;
					}
					return apiFetch( {
						path: addQueryArgs( '/newspack-blocks/v1/authors', params ),
					} );
				} )
			);
			setContextualAuthors( results.flat().filter( Boolean ) );
		} catch ( e ) {
			setError( e.message || e || __( 'Error fetching byline authors.', 'newspack-blocks' ) );
			setContextualAuthors( [] );
		}
		setIsLoading( false );
	};

	// Memoize authors for rendering based on mode
	const authorsToRender = useMemo( () => {
		let authors;
		if ( isContextual ) {
			if ( isTemplateLikeContext ) {
				return [ getPlaceholderAuthor( socialIconSvgs ) ];
			}
			authors = contextualAuthors;
		} else {
			authors = author ? [ author ] : [];
		}
		if ( ! showEmptyBio ) {
			authors = authors.filter( a => a.bio );
		}
		return authors;
	}, [ isContextual, showEmptyBio, isTemplateLikeContext, socialIconSvgs, contextualAuthors, author ] );

	// Reset preview index when authors list changes (e.g., switching posts)
	useEffect( () => {
		setPreviewAuthorIndex( 0 );
	}, [ authorsToRender.length ] );

	// Register author in the per-instance map for block bindings (nested mode only).
	// Each Author Profile block stores its author keyed by clientId, so bindings
	// in child blocks can look up the correct author via getBlockParents().
	useEffect( () => {
		if ( layoutVersion !== 2 ) {
			return;
		}
		const safeIndex = Math.min( previewAuthorIndex, Math.max( 0, authorsToRender.length - 1 ) );
		const previewAuthor = authorsToRender[ safeIndex ] || null;
		window.__newspackAuthorsByBlock[ clientId ] = previewAuthor;
		return () => {
			delete window.__newspackAuthorsByBlock[ clientId ];
		};
	}, [ authorsToRender, previewAuthorIndex, layoutVersion, clientId ] );

	// Combine social links and email, which are shown together.
	const getSocialLinks = authorData => {
		const socialLinks = ( showSocial && authorData?.social ) || {};
		if ( showEmail && authorData?.email ) {
			socialLinks.email = authorData.email;
		} else {
			delete socialLinks.email;
		}
		return socialLinks;
	};

	// Determine if we're in nested layout mode (publisher-controlled composition).
	// In nested mode, hide field toggles since publishers control display by adding/removing blocks.
	const isNestedLayout = layoutVersion === 2;

	// Inspector controls for display settings
	const inspectorControls = (
		<InspectorControls>
			{ isNestedLayout && (
				<PanelBody title={ __( 'Display', 'newspack-blocks' ) }>
					<ToggleControl
						label={ __( 'Show authors without bio', 'newspack-blocks' ) }
						help={ __( 'Display author profiles even if their bio is empty.', 'newspack-blocks' ) }
						checked={ showEmptyBio }
						onChange={ () => setAttributes( { showEmptyBio: ! showEmptyBio } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			) }
			{ ! isNestedLayout && (
				<PanelBody title={ __( 'Settings', 'newspack-blocks' ) }>
					<ToggleGroupControl
						label={ __( 'Text size', 'newspack-blocks' ) }
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
			) }
			{ /* In nested mode, avatar is controlled via the inner newspack/avatar block */ }
			{ ! isNestedLayout && (
				<PanelBody title={ __( 'Avatar', 'newspack-blocks' ) }>
					<ToggleControl
						label={ __( 'Display avatar', 'newspack-blocks' ) }
						checked={ showAvatar }
						onChange={ () => setAttributes( { showAvatar: ! showAvatar } ) }
						__nextHasNoMarginBottom
					/>
					{ showAvatar && (
						<ToggleControl
							label={ __( 'Hide default avatar', 'newspack-blocks' ) }
							checked={ avatarHideDefault }
							onChange={ () => setAttributes( { avatarHideDefault: ! avatarHideDefault } ) }
							__nextHasNoMarginBottom
						/>
					) }
					{ showAvatar && (
						<ToggleGroupControl
							label={ __( 'Size', 'newspack-blocks' ) }
							aria-label={ __( 'Avatar size', 'newspack-blocks' ) }
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
							label={ __( 'Border radius', 'newspack-blocks' ) }
							aria-label={ __( 'Avatar border radius', 'newspack-blocks' ) }
							labelPosition="edge"
							__next40pxDefaultSize
							__unstableInputWidth="80px"
							units={ units }
							value={ avatarBorderRadius }
							onChange={ value => setAttributes( { avatarBorderRadius: 0 > parseFloat( value ) ? '0' : value } ) }
						/>
					) }
				</PanelBody>
			) }
		</InspectorControls>
	);

	// Loading placeholder shared between nested and flat mode.
	const loadingPlaceholder = (
		<div { ...blockProps }>
			{ inspectorControls }
			<Placeholder className="newspack-blocks-author-profile" icon={ postAuthor } label={ __( 'Author Profile', 'newspack-blocks' ) }>
				<VStack alignment="center" style={ { width: '100%' } }>
					<Spinner style={ { margin: '0' } } />
					<span style={ { fontWeight: '500' } }>{ __( 'Fetching authors…', 'newspack-blocks' ) }</span>
				</VStack>
			</Placeholder>
		</div>
	);

	// Block controls for avatar alignment and edit button
	const blockControls = authorsToRender.length > 0 && (
		<BlockControls>
			{ ! isNestedLayout && showAvatar && ! attributes.className?.includes( 'is-style-center' ) && (
				<Toolbar
					controls={ [
						{
							icon: pullLeft,
							title: __( 'Show avatar on left', 'newspack-blocks' ),
							isActive: avatarAlignment === 'left',
							onClick: () => setAttributes( { avatarAlignment: 'left' } ),
						},
						{
							icon: pullRight,
							title: __( 'Show avatar on right', 'newspack-blocks' ),
							isActive: avatarAlignment === 'right',
							onClick: () => setAttributes( { avatarAlignment: 'right' } ),
						},
					] }
				/>
			) }
			{ ! isContextual && (
				<Toolbar
					controls={ [
						{
							icon: pencil,
							title: __( 'Edit selection', 'newspack-blocks' ),
							onClick: () => {
								setAttributes( { authorId: 0 } );
								setAuthor( null );
							},
						},
					] }
				/>
			) }
		</BlockControls>
	);

	// Mode selection placeholder for new blocks (shared by nested and flat modes).
	const modeSelectionPlaceholder = (
		<div { ...blockProps }>
			{ inspectorControls }
			<Placeholder
				className="newspack-blocks-author-profile"
				icon={ postAuthor }
				label={ __( 'Author Profile', 'newspack-blocks' ) }
				instructions={ __( 'Select a type:', 'newspack-blocks' ) }
			>
				<Button variant="primary" onClick={ () => setAttributes( { isContextual: true } ) }>
					{ __( 'Contextual', 'newspack-blocks' ) }
				</Button>
				<Button variant="secondary" onClick={ () => setShowSpecificSelector( true ) }>
					{ __( 'Specific', 'newspack-blocks' ) }
				</Button>
			</Placeholder>
		</div>
	);

	// NESTED MODE: Use InnerBlocks for publisher-controlled layout (layoutVersion 2)
	// This respects the block's saved mode regardless of current theme
	if ( isNestedLayout ) {
		// A v2 block opened in a classic theme can't render its inner blocks properly.
		if ( ! isNestedMode ) {
			return (
				<div { ...blockProps }>
					{ inspectorControls }
					<Placeholder className="newspack-blocks-author-profile" icon={ postAuthor } label={ __( 'Author Profile', 'newspack-blocks' ) }>
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'This block was created with a block theme and is not supported in the current theme. It will render using the classic layout on the frontend.',
								'newspack-blocks'
							) }
						</Notice>
					</Placeholder>
				</div>
			);
		}

		// Variation picker: shown when block has no inner blocks.
		if ( ! hasInnerBlocks ) {
			return <VariationPlaceholder clientId={ clientId } name="newspack-blocks/author-profile" setAttributes={ setAttributes } />;
		}

		// Mode selection for new blocks in nested mode
		if ( ! authorId && ! isContextual && ! showSpecificSelector ) {
			return modeSelectionPlaceholder;
		}

		// Loading state
		if ( isLoading ) {
			return loadingPlaceholder;
		}

		// Custom byline with no real authors referenced
		if ( isContextual && customBylineActive && ! bylineAuthorIds.length ) {
			return (
				<div { ...blockProps }>
					{ inspectorControls }
					<div className="newspack-author-profile-disabled">
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Author bio is hidden because Custom Byline is active on this post.', 'newspack-blocks' ) }
						</Notice>
					</div>
				</div>
			);
		}

		// Specific mode: show author search when no author selected
		if ( ! isContextual && ! authorId ) {
			return (
				<div { ...blockProps }>
					{ inspectorControls }
					<Placeholder className="newspack-blocks-author-profile" icon={ postAuthor } label={ __( 'Author Profile', 'newspack-blocks' ) }>
						{ error && (
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						) }
						<AutocompleteWithSuggestions
							label={ __( 'Search for an author to display', 'newspack-blocks' ) }
							help={ __( 'Begin typing name, click autocomplete result to select.', 'newspack-blocks' ) }
							fetchSuggestions={ async ( search = null, offset = 0 ) => {
								setSuggestions( null );
								if ( authorId && ! error ) {
									return [];
								}
								const response = await apiFetch( {
									parse: false,
									path: addQueryArgs( '/newspack-blocks/v1/authors', {
										search,
										offset,
										fields: 'id,name',
									} ),
								} );
								const total = parseInt( response.headers.get( 'x-wp-total' ) || 0, 10 );
								const authors = await response.json();
								if ( ! maxItemsToSuggest && ! search ) {
									setMaxItemsToSuggest( total );
								}
								const _suggestions = authors.map( _author => ( {
									value: _author.id,
									label: decodeEntities( _author.name ) || __( '(no name)', 'newspack-blocks' ),
									isGuestAuthor: _author.is_guest,
								} ) );
								setSuggestions( _suggestions );
								return _suggestions;
							} }
							maxItemsToSuggest={ maxItemsToSuggest }
							onChange={ items => {
								let selectionIsGuest = false;
								const selection = items[ 0 ];
								if ( suggestions ) {
									suggestions.forEach( suggestion => {
										if ( parseInt( selection?.value, 10 ) === parseInt( suggestion?.value, 10 ) && suggestion?.isGuestAuthor ) {
											selectionIsGuest = true;
										}
									} );
								}
								setAttributes( {
									authorId: parseInt( selection?.value || 0, 10 ),
									isGuestAuthor: selectionIsGuest,
								} );
							} }
							postTypeLabel={ __( 'author', 'newspack-blocks' ) }
							postTypeLabelPlural={ __( 'authors', 'newspack-blocks' ) }
							selectedItems={ [] }
						/>
					</Placeholder>
				</div>
			);
		}

		// Contextual mode: no authors found
		if ( isContextual && ! authorsToRender.length ) {
			return (
				<div { ...blockProps }>
					{ inspectorControls }
					<Placeholder className="newspack-blocks-author-profile" icon={ postAuthor } label={ __( 'Author Profile', 'newspack-blocks' ) }>
						{ __( 'No authors found for this post.', 'newspack-blocks' ) }
					</Placeholder>
				</div>
			);
		}

		// Get preview author (bounds-checked)
		const safeIndex = Math.min( previewAuthorIndex, authorsToRender.length - 1 );
		const previewAuthor = authorsToRender[ safeIndex ];

		// Set in the per-instance map synchronously so bindings have access on first render.
		// The useEffect handles cleanup when component unmounts.
		window.__newspackAuthorsByBlock[ clientId ] = previewAuthor;

		const nestedBlockProps = {
			...blockProps,
			className: `${ blockProps.className } wp-block-newspack-blocks-author-profile is-nested-mode`,
		};

		return (
			<AuthorContext.Provider value={ previewAuthor }>
				<div { ...nestedBlockProps }>
					{ inspectorControls }
					{ blockControls }
					{ /* Author selector: only shown in contextual mode with multiple authors */ }
					{ isContextual && authorsToRender.length > 1 && (
						<Card isRounded={ false } size="small" style={ { marginBottom: '32px' } } variant="secondary">
							<CardBody>
								<SelectControl
									label={ __( 'Preview author', 'newspack-blocks' ) }
									value={ safeIndex }
									options={ authorsToRender.map( ( a, index ) => ( {
										label: a.name,
										value: index,
									} ) ) }
									onChange={ value => setPreviewAuthorIndex( parseInt( value, 10 ) ) }
									help={ sprintf(
										/* translators: %d: number of authors */
										__( 'Previewing 1 of %d authors. All authors display on frontend.', 'newspack-blocks' ),
										authorsToRender.length
									) }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
							</CardBody>
						</Card>
					) }
					{ /* Key forces re-render when author changes, which re-evaluates bindings */ }
					<InnerBlocks
						key={ `author-${ previewAuthor?.id || 'none' }` }
						template={ activeVariationTemplate }
						templateLock="insert"
						allowedBlocks={ [ 'core/columns', 'core/group' ] }
					/>
				</div>
			</AuthorContext.Provider>
		);
	}

	// MODE SELECTION: Show mode selector for NEW blocks (no authorId and not contextual)
	if ( ! authorId && ! isContextual && ! showSpecificSelector ) {
		return modeSelectionPlaceholder;
	}

	// CONTEXTUAL MODE
	if ( isContextual ) {
		// Loading state
		if ( isLoading ) {
			return loadingPlaceholder;
		}

		// Custom byline with no real authors referenced
		if ( customBylineActive && ! bylineAuthorIds.length ) {
			return (
				<div { ...blockProps }>
					{ inspectorControls }
					<div className="newspack-author-profile-disabled">
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Author bio is hidden because Custom Byline is active on this post.', 'newspack-blocks' ) }
						</Notice>
					</div>
				</div>
			);
		}

		// No authors found
		if ( ! authorsToRender.length ) {
			return (
				<div { ...blockProps }>
					{ inspectorControls }
					<Placeholder className="newspack-blocks-author-profile" icon={ postAuthor } label={ __( 'Author Profile', 'newspack-blocks' ) }>
						{ __( 'No authors found for this post.', 'newspack-blocks' ) }
					</Placeholder>
				</div>
			);
		}

		// Render contextual authors
		return (
			<div { ...blockProps }>
				{ inspectorControls }
				{ blockControls }
				{ authorsToRender.map( authorData => (
					<SingleAuthor
						key={ authorData.id }
						author={ { ...authorData, social: getSocialLinks( authorData ) } }
						attributes={ attributes }
					/>
				) ) }
			</div>
		);
	}

	// SPECIFIC MODE: Author selected - render it
	if ( author ) {
		return (
			<div { ...blockProps }>
				{ inspectorControls }
				{ blockControls }
				<SingleAuthor author={ { ...author, social: getSocialLinks( author ) } } attributes={ attributes } />
			</div>
		);
	}

	// SPECIFIC MODE: No author selected - show search
	return (
		<div { ...blockProps }>
			{ inspectorControls }
			<Placeholder className="newspack-blocks-author-profile" icon={ postAuthor } label={ __( 'Author Profile', 'newspack-blocks' ) }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ isLoading && (
					<VStack alignment="center" style={ { width: '100%' } }>
						<Spinner style={ { margin: '0' } } />
						<span style={ { fontWeight: '500' } }>{ __( 'Fetching authors…', 'newspack-blocks' ) }</span>
					</VStack>
				) }
				{ ! isLoading && (
					<AutocompleteWithSuggestions
						label={ __( 'Search for an author to display', 'newspack-blocks' ) }
						help={ __( 'Begin typing name, click autocomplete result to select.', 'newspack-blocks' ) }
						fetchSuggestions={ async ( search = null, offset = 0 ) => {
							// Reset suggestions in state.
							setSuggestions( null );

							// If we already have a selected author, no need to fetch suggestions.
							if ( authorId && ! error ) {
								return [];
							}

							const response = await apiFetch( {
								parse: false,
								path: addQueryArgs( '/newspack-blocks/v1/authors', {
									search,
									offset,
									fields: 'id,name',
								} ),
							} );

							const total = parseInt( response.headers.get( 'x-wp-total' ) || 0, 10 );
							const authors = await response.json();

							// Set max items for "load more" functionality in suggestions list.
							if ( ! maxItemsToSuggest && ! search ) {
								setMaxItemsToSuggest( total );
							}

							const _suggestions = authors.map( _author => ( {
								value: _author.id,
								label: decodeEntities( _author.name ) || __( '(no name)', 'newspack-blocks' ),
								isGuestAuthor: _author.is_guest,
							} ) );

							setSuggestions( _suggestions );

							return _suggestions;
						} }
						maxItemsToSuggest={ maxItemsToSuggest }
						onChange={ items => {
							let selectionIsGuest = false;
							const selection = items[ 0 ];

							// We need to check whether the selected author is a guest author or not.
							if ( suggestions ) {
								suggestions.forEach( suggestion => {
									if ( parseInt( selection?.value, 10 ) === parseInt( suggestion?.value, 10 ) && suggestion?.isGuestAuthor ) {
										selectionIsGuest = true;
									}
								} );
							}

							setAttributes( {
								authorId: parseInt( selection?.value || 0, 10 ),
								isGuestAuthor: selectionIsGuest,
							} );
						} }
						postTypeLabel={ __( 'author', 'newspack-blocks' ) }
						postTypeLabelPlural={ __( 'authors', 'newspack-blocks' ) }
						selectedItems={ [] }
					/>
				) }
			</Placeholder>
		</div>
	);
};

export default AuthorProfile;
