/* global newspackPostPrimaryBrandVars */

import { __ } from '@wordpress/i18n';
import { Button, Flex, FlexItem, SelectControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

import './index.scss';

/**
 * The `wp.hooks` global, as exposed by the `wp-hooks` script dependency
 * (loaded as a plain script, not an ES module, so this filter registration
 * uses the global rather than importing `@wordpress/hooks` directly).
 */
declare const wp: { hooks: { addFilter: typeof import('@wordpress/hooks').addFilter } };

/**
 * A brand taxonomy term, as returned by `getEntityRecords( 'taxonomy', slug, ... )`
 * with the `DEFAULT_QUERY`'s `_fields` restriction.
 */
interface BrandTerm {
	id: number;
	name: string;
	parent: number;
}

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

const EMPTY_ARRAY: never[] = [];

const ZERO = 0;

const ADMIN_URL = newspackPostPrimaryBrandVars.adminURL;

const TAXONOMY_SLUG = newspackPostPrimaryBrandVars.taxonomySlug;

const META_KEY = newspackPostPrimaryBrandVars.metaKey;

const SHOW_PRIMARY_BRAND_FOR = newspackPostPrimaryBrandVars.postTypesWithPrimaryBrand;

interface NewspackPostPrimaryBrandProps {
	slug: string;
}

/**
 * Adds a primary brand selector to the post editor.
 */
const NewspackPostPrimaryBrand = ( { slug }: NewspackPostPrimaryBrandProps ) => {
	const { editPost } = useDispatch( 'core/editor' );

	const { terms, availableTerms, primaryBrand, postType } = useSelect(
		select => {
			const { getEditedPostAttribute, getCurrentPostType } = select( 'core/editor' ) as {
				getEditedPostAttribute: ( attribute: string ) => unknown;
				getCurrentPostType: () => string;
			};
			const { getTaxonomy, getEntityRecords } = select( coreStore ) as {
				getTaxonomy: ( slug: string ) => { rest_base: string } | null;
				getEntityRecords: ( kind: string, name: string, query: Record< string, unknown > ) => BrandTerm[] | null;
			};
			const _taxonomy = getTaxonomy( slug );
			const _meta = getEditedPostAttribute( 'meta' ) as Record< string, unknown >;
			const _postType = getCurrentPostType();

			return {
				terms: ( _taxonomy ? getEditedPostAttribute( _taxonomy.rest_base ) : EMPTY_ARRAY ) as number[],
				availableTerms: getEntityRecords( 'taxonomy', slug, DEFAULT_QUERY ) || EMPTY_ARRAY,
				primaryBrand: _meta[ META_KEY ] as number | string | undefined,
				postType: _postType,
			};
		},
		[ slug ]
	);

	const getTermSelectOptionFromId = ( id: number ) => {
		const term = availableTerms.find( t => t.id === id );
		// `SelectControl`'s option `value`s (and the `onChange` value it receives back) are
		// always strings, being a native `<select>` under the hood; stringified here to match
		// (this doesn't change the rendered `<option>` markup, which was already stringified).
		return term ? { value: String( term.id ), label: term.name } : null;
	};

	const onChangePrimaryBrand = ( termId: string ) => {
		editPost( { meta: { [ META_KEY ]: termId } } );
	};

	const shouldDisplayPrimaryBrand = SHOW_PRIMARY_BRAND_FOR.includes( postType );

	return (
		<Flex direction="column" gap="4">
			{ shouldDisplayPrimaryBrand && (
				<div
					className="editor-primary-brand-selector"
					tabIndex={ 0 }
					role="group"
					aria-label={ __( 'Brands', 'newspack-multibranded-site' ) }
				>
					{ terms.length > 1 && (
						<SelectControl
							label={ __( 'Primary brand', 'newspack-multibranded-site' ) }
							value={ String( primaryBrand || ZERO ) }
							options={ [
								{
									label: __( 'None', 'newspack-multibranded-site' ),
									value: String( ZERO ),
								},
								...terms
									.map( term => getTermSelectOptionFromId( term ) )
									.filter( ( term ): term is { value: string; label: string } => !! term ),
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

interface PostTaxonomyTypeProps {
	slug: string;
	[ key: string ]: unknown;
}

function customizeSelector( OriginalComponent: import('react').ComponentType< PostTaxonomyTypeProps > ) {
	return function ( props: PostTaxonomyTypeProps ) {
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
