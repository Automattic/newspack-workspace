/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useBlockProps } from '@wordpress/block-editor';
import { Button, Placeholder, Spinner } from '@wordpress/components';
import { withSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { Listing } from './listing';
import { AutocompleteWithSuggestions } from 'newspack-components';
import { capitalize, getIcon } from '../../editor/utils';

const ListingEditorComponent = ( { attributes, clientId, getBlock, getBlockParents, name, setAttributes } ) => {
	const [ post, setPost ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isEditingPost, setIsEditingPost ] = useState( false );
	const blockProps = useBlockProps();
	const { listing } = attributes;

	// Get the parent List Container block.
	const parents = getBlockParents( clientId );
	const parent = parents.reduce( ( acc, outerBlock ) => {
		const blockInfo = getBlock( outerBlock );

		if ( 'newspack-listings/list-container' === blockInfo.name ) {
			acc.listContainer = blockInfo;
		}

		return acc;
	}, {} );

	// Build an array of just the listing post IDs that exist in the parent Curated List block.
	const listItems = parent.listContainer.innerBlocks.reduce( ( acc, innerBlock ) => {
		if ( innerBlock.attributes.listing ) {
			acc.push( innerBlock.attributes.listing );
		}

		return acc;
	}, [] );

	const { post_types } = window.newspack_listings_data;
	const listingTypeSlug = name.split( '/' ).slice( -1 )[ 0 ];
	const listingType = post_types[ listingTypeSlug ];

	// Fetch listing post data if we have a listing post ID.
	useEffect( () => {
		if ( ! post && listing ) {
			fetchPost( listing );
		}
	}, [ listing ] );

	// Fetch listing post by listingId.
	const fetchPost = async listingId => {
		try {
			setError( null );
			const posts = await apiFetch( {
				path: addQueryArgs( '/newspack-listings/v1/listings', {
					per_page: 100,
					id: listingId,
					_fields: 'id,title,author,category,tags,excerpt,media,meta,type,sponsors,classes',
				} ),
			} );

			if ( 0 === posts.length ) {
				throw `No posts found for ID ${ listingId }. Try refreshing or selecting a new post.`;
			}

			const foundPost = posts[ 0 ];

			if ( foundPost.meta && foundPost.meta.newspack_listings_locations ) {
				setAttributes( { locations: foundPost.meta.newspack_listings_locations } );
			}

			setPost( foundPost );
		} catch ( e ) {
			setError( e );
		}
	};

	// Renders the autocomplete search field to select listings. Will only show listings of the type that matches the block.
	const renderSearch = () => {
		return (
			<Placeholder className="newspack-listings__listing-search" label={ capitalize( listingTypeSlug ) } icon={ getIcon( listingTypeSlug ) }>
				<AutocompleteWithSuggestions
					label={ __( 'Search for a', 'newspack-listings' ) + listingTypeSlug + __( 'listing to display.', 'newspack-listings' ) }
					fetchSavedPosts={ async postIDs => {
						const posts = await apiFetch( {
							path: addQueryArgs( 'newspack-listings/v1/listings', {
								per_page: 100,
								include: postIDs.join( ',' ),
								_fields: 'id,title',
							} ),
						} );

						return posts.map( _post => ( {
							value: _post.id,
							label: decodeEntities( _post.title ) || __( '(no title)', 'newspack-listings' ),
						} ) );
					} }
					fetchSuggestions={ async search => {
						const posts = await apiFetch( {
							path: addQueryArgs( '/newspack-listings/v1/listings', {
								search,
								per_page: 10,
								_fields: 'id,title',
								type: listingType,
							} ),
						} );

						// Only show suggestions if they aren't already in the list.
						const result = posts.reduce( ( acc, _post ) => {
							if ( listItems.indexOf( _post.id ) < 0 && listItems.indexOf( _post.id.toString() ) < 0 ) {
								acc.push( {
									value: _post.id,
									label: decodeEntities( _post.title ) || __( '(no title)', 'newspack-listings' ),
								} );
							}

							return acc;
						}, [] );
						return result;
					} }
					postType={ listingType }
					postTypeSlug={ listingTypeSlug }
					maxLength={ 1 }
					onChange={ _listing => {
						if ( _listing.length ) {
							setIsEditingPost( false );
							setPost( null );
							setAttributes( { listing: _listing.shift().value.toString() } );
						}
					} }
					selectedPost={ isEditingPost ? null : listing }
					listItems={ listItems }
				/>
				{ listing && (
					<Button isPrimary onClick={ () => setIsEditingPost( false ) }>
						{ __( 'Cancel', 'newspack-listings' ) }
					</Button>
				) }
			</Placeholder>
		);
	};

	// Renders selected listing post, or a placeholder if still fetching.
	const renderPost = () => {
		if ( ! post && ! error ) {
			return (
				<Placeholder
					className="newspack-listings__listing-search is-loading"
					label={ listingTypeSlug[ 0 ].toUpperCase() + listingTypeSlug.slice( 1 ) }
					icon={ getIcon( listingTypeSlug ) }
				>
					<Spinner />
				</Placeholder>
			);
		}

		return (
			<div className="newspack-listings__listing-editor newspack-listings__listing">
				<Listing attributes={ attributes } error={ error } post={ post } />
				{ post && (
					<Button isLink href={ `/wp-admin/post.php?post=${ post.id }&action=edit` } target="_blank">
						{ __( 'Edit this listing', 'newspack-listings' ) }
					</Button>
				) }
			</div>
		);
	};

	return <div { ...blockProps }>{ ! listing || isEditingPost ? renderSearch() : renderPost() }</div>;
};

const mapStateToProps = select => {
	const { getBlock, getBlockParents } = select( 'core/block-editor' );

	return {
		getBlock,
		getBlockParents,
	};
};

export const ListingEditor = withSelect( mapStateToProps )( ListingEditorComponent );
