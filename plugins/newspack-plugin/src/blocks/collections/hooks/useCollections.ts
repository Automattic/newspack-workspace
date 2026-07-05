import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';

/**
 * Custom hook to fetch collections using WordPress core data.
 *
 * @param {Object} attributes Block attributes.
 * @return {Object} Object containing collections data and loading state.
 */
export const useCollections = attributes => {
	const { queryType, numberOfItems, offset = 0, selectedCollections = [], includeCategories = [], excludeCategories = [] } = attributes;

	// Normalize and guard common inputs.
	const isSpecific = queryType === 'specific';
	const perPage = Number( numberOfItems );
	const hasPerPage = Number.isFinite( perPage ) && perPage > 0;
	const isDisabledQuery = ( isSpecific && selectedCollections.length === 0 ) || ! hasPerPage;

	// Memoize query object so selectors aren't re-run due to new object identities.
	const query = useMemo( () => {
		if ( isDisabledQuery ) {
			return null;
		}

		const q = {
			per_page: perPage,
			orderby: 'date',
			order: 'desc',
			_embed: true,
			status: 'publish',
		};

		// Add offset for recent collections only.
		if ( ! isSpecific && offset > 0 ) {
			q.offset = offset;
		}

		if ( isSpecific ) {
			// Specific collections mode ignores category filters.
			q.include = selectedCollections;
		} else {
			// Category filtering for recent collections.
			if ( includeCategories.length ) {
				q.newspack_collection_category = includeCategories;
			}
			if ( excludeCategories.length ) {
				q.newspack_collection_category_exclude = excludeCategories;
			}
		}

		return q;
	}, [ isDisabledQuery, isSpecific, perPage, offset, selectedCollections, includeCategories, excludeCategories ] );

	return useSelect(
		select => {
			// If query is disabled (e.g., columns = 0 or specific with no selection), don't hit the store.
			if ( ! query ) {
				return { collections: [], isLoading: false, hasCollections: false };
			}

			const { getEntityRecords, isResolving } = select( 'core' );
			const collections = getEntityRecords( 'postType', 'newspack_collection', query );
			const isLoading = isResolving( 'getEntityRecords', [ 'postType', 'newspack_collection', query ] );

			return {
				collections: collections || [],
				isLoading,
				hasCollections: Array.isArray( collections ) && collections.length > 0,
			};
		},
		[ query ]
	);
};
