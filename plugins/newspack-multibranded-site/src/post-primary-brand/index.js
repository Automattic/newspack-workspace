/* global newspackPostPrimaryBrandVars */

import { __ } from '@wordpress/i18n';
import { Button, Flex, FlexItem, SelectControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

import './index.scss';

/**
 * Module Constants
 */
const DEFAULT_QUERY = {
	per_page: -1,
	orderby: 'name',
	order: 'asc',
	_fields: 'id,name,parent',
	context: 'view',
};

const EMPTY_ARRAY = [];

const ZERO = 0;

const ADMIN_URL = newspackPostPrimaryBrandVars.adminURL;

const TAXONOMY_SLUG = newspackPostPrimaryBrandVars.taxonomySlug;

const META_KEY = newspackPostPrimaryBrandVars.metaKey;

const SHOW_PRIMARY_BRAND_FOR = newspackPostPrimaryBrandVars.postTypesWithPrimaryBrand;

/**
 * Adds a primary brand selector to the post editor.
 */
const NewspackPostPrimaryBrand = ( { slug } ) => {
	const { editPost } = useDispatch( 'core/editor' );

	const { terms, availableTerms, primaryBrand, postType } = useSelect(
		select => {
			const { getEditedPostAttribute, getCurrentPostType } = select( 'core/editor' );
			const { getTaxonomy, getEntityRecords } = select( coreStore );
			const _taxonomy = getTaxonomy( slug );
			const _meta = getEditedPostAttribute( 'meta' );
			const _postType = getCurrentPostType();

			return {
				terms: _taxonomy ? getEditedPostAttribute( _taxonomy.rest_base ) : EMPTY_ARRAY,
				availableTerms: getEntityRecords( 'taxonomy', slug, DEFAULT_QUERY ) || EMPTY_ARRAY,
				primaryBrand: _meta[ META_KEY ],
				postType: _postType,
			};
		},
		[ slug ]
	);

	const getTermSelectOptionFromId = id => {
		const term = availableTerms.find( t => t.id === id );
		return term ? { value: term.id, label: term.name } : null;
	};

	const onChangePrimaryBrand = termId => {
		editPost( { meta: { [ META_KEY ]: termId } } );
	};

	const shouldDisplayPrimaryBrand = SHOW_PRIMARY_BRAND_FOR.includes( postType );

	return (
		<Flex direction="column" gap="4">
			{ shouldDisplayPrimaryBrand && (
				<div className="editor-primary-brand-selector" tabIndex="0" role="group" aria-label={ __( 'Brands', 'newspack-multibranded-site' ) }>
					{ terms.length > 1 && (
						<SelectControl
							label={ __( 'Primary brand', 'newspack-multibranded-site' ) }
							value={ primaryBrand || ZERO }
							options={ [
								{
									label: __( 'None', 'newspack-multibranded-site' ),
									value: 0,
								},
								...terms.map( term => getTermSelectOptionFromId( term ) ).filter( term => term ),
							] }
							onChange={ onChangePrimaryBrand }
						/>
					) }
				</div>
			) }

			<FlexItem>
				<Button href={ ADMIN_URL } variant="link" target="blank">
					{ __( 'Manage Brands', 'newspack-multibranded-site' ) }
				</Button>
			</FlexItem>
		</Flex>
	);
};

function customizeSelector( OriginalComponent ) {
	return function ( props ) {
		if ( props.slug === TAXONOMY_SLUG ) {
			return (
				<div className="newspack-multibranded-site-brand-control">
					<OriginalComponent { ...props } />
					<NewspackPostPrimaryBrand { ...props } />
				</div>
			);
		}
		return <OriginalComponent { ...props } />;
	};
}

wp.hooks.addFilter( 'editor.PostTaxonomyType', 'newspack/multibranded-site/brand-selector-filter', customizeSelector );
