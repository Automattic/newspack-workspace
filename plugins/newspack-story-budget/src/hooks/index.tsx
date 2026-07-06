/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies.
 */
import { NAMESPACE } from '../store/constants';
import type { StoreSelectors } from '../store/store-shape';
import type { Story } from '../store/types';
import type { Field as ComponentField } from '../components/types';
import TableRowField from '../components/table-row-field';
import { getFieldElements, getFilterByOperators } from '../utils/fields';
import { isBudgetStories } from '../utils/budgets';
import StoriesEdit from '../components/stories-edit';

/**
 * Hook to get all fields
 *
 * @return {Array} Array of fields
 */
export const useFields = () => {
	return useSelect( select => ( select( NAMESPACE ) as StoreSelectors ).getFields(), [] );
};

/**
 * Hook to get a field.
 *
 * @param {string} fieldSlug The field slug.
 *
 * @return {Object} The field.
 */
export const useField = ( fieldSlug: string ) => {
	return useSelect( select => ( select( NAMESPACE ) as StoreSelectors ).getField( fieldSlug ), [ fieldSlug ] );
};

/**
 * Hook to get the field enhanced with props from the story metadata.
 *
 * @param {number} storyId   The story ID.
 * @param {string} fieldSlug The field slug.
 *
 * @return {Object} The field enhanced with props.
 */
export const useStoryField = ( storyId: string | number, fieldSlug: string ) => {
	const field = useSelect( select => ( select( NAMESPACE ) as StoreSelectors ).getField( fieldSlug ), [ fieldSlug ] );

	// `getStoryMeta( id, key )` is typed to return `unknown` (its shape varies by `key`); this call
	// site's `key` is `'fields_props'`, whose real shape is a map of field slug to field-prop overrides.
	const fieldsProps = useSelect( select => ( select( NAMESPACE ) as StoreSelectors ).getStoryMeta( storyId, 'fields_props' ), [ storyId ] ) as
		| Record< string, Record< string, unknown > >
		| undefined;

	return useMemo( () => {
		if ( ! field ) {
			return null;
		}

		const fieldProps = fieldsProps?.[ fieldSlug ];
		if ( ! fieldProps ) {
			return field;
		}

		return {
			...field,
			...fieldProps,
		};
	}, [ field, fieldsProps ] );
};

/**
 * Hook to get the fields for DataViews.
 *
 * @param {Object}  params           The hook parameters.
 * @param {boolean} params.allowEdit Whether to allow editing.
 *
 * @return {Array} The fields.
 */
// Return type widened to `unknown[]`: `components/stories.tsx` (this hook's only caller) casts its
// whole `<DataViews>` props bag as a unit at its own boundary (a pre-existing, documented gap --
// `Story['id']` doesn't satisfy DataViews' `ItemWithId`, so that cast never fully type-checks
// either way); a concretely-typed array here would only make that unrelated cast fail rather than
// making this integration any safer, so the boundary is kept exactly where it already was.
export const useStoryFields = ( { allowEdit }: { allowEdit: boolean } ): unknown[] => {
	const fields = useFields();

	return useMemo(
		() =>
			fields
				.filter( field => {
					// Skip the budgets field if we're viewing a budget's stories.
					if ( 'budgets' === field.slug && isBudgetStories() ) {
						return false;
					}
					return true;
				} )
				.map( field => ( {
					id: field.slug,
					label: field.name,
					isVisible: () => field.show_in_table || field.always_visible_in_table,
					type: field.type,
					enableHiding: ! field.always_visible_in_table,
					enableSorting: field.is_sortable,
					elements: getFieldElements( field ),
					filterBy:
						field.is_filterable && field.is_filterable !== 'no'
							? {
									operators: getFilterByOperators( field ),
									isPrimary: field.is_filterable === 'always',
							  }
							: undefined,
					// `field` is this subtree's own `Field` (`store/types`), whose `is_filterable` is
					// `'yes' | 'no' | 'always'` (matching the real REST payload); `TableRowField`'s prop
					// declares the looser `components/types` `Field` (`boolean | 'no' | 'always'`) --
					// cast at this cross-module boundary, as already done elsewhere in this subtree
					// (see `components/stories-edit.tsx`'s `getDisplayValue()` call).
					render: applyFilters(
						'newspack-story-budget.table-row-field',
						( value: { item: Story } ) => (
							<TableRowField story={ value.item } field={ field as ComponentField } allowEdit={ allowEdit } />
						),
						field,
						allowEdit
					),
				} ) ),
		[ fields, allowEdit ]
	);
};

/**
 * A DataViews-style bulk/row action, as consumed by `useStoryActions()`'s callers (this subtree's
 * own `<DataViews>` integration casts the whole props bag at its own boundary rather than
 * depending on `@wordpress/dataviews`' own `Action<Item>` type -- see `components/stories.tsx`).
 */
interface StoryAction {
	id: string;
	label: string;
	isPrimary: boolean;
	supportsBulk?: boolean;
	hideModalHeader?: boolean;
	callback?: ( items: Story[] ) => void;
	RenderModal?: typeof StoriesEdit;
	isEligible?: ( item: Story ) => boolean;
}

/**
 * Hook to get the actions for DataViews.
 *
 * @return {Array} The actions.
 */
// See the return-type comment on `useStoryFields()` above -- same reasoning applies here.
export const useStoryActions = (): unknown[] => {
	const canManage = useSelect( select => ( select( NAMESPACE ) as StoreSelectors ).canManage() );

	const { fetchStory, clearErrors } = useDispatch( NAMESPACE );

	return useMemo( () => {
		const defaultActions: StoryAction[] = [
			{
				id: 'view-story',
				label: __( 'View', 'newspack-story-budget' ),
				isPrimary: false,
				callback: items => {
					fetchStory( items[ 0 ].id );
					window.location.hash = '#/stories/' + items[ 0 ].id;
				},
			},
			{
				id: 'edit-fields',
				label: __( 'Edit Fields', 'newspack-story-budget' ),
				isPrimary: false,
				supportsBulk: true,
				hideModalHeader: true,
				RenderModal: StoriesEdit,
			},
			{
				id: 'refresh',
				label: __( 'Refresh', 'newspack-story-budget' ),
				isPrimary: false,
				supportsBulk: true,
				callback: items => {
					for ( const item of items ) {
						clearErrors( item.id );
						fetchStory( item.id );
					}
				},
			},
			{
				id: 'edit',
				label: __( 'Edit Post', 'newspack-story-budget' ),
				isEligible: item => canManage && !! item.metadata?.edit_url,
				isPrimary: false,
				callback: items => {
					if ( items[ 0 ].metadata?.edit_url ) {
						window.open( items[ 0 ].metadata.edit_url );
					}
				},
			},
		];

		return [ ...( applyFilters( 'newspack-story-budget.actions', defaultActions ) as StoryAction[] ) ];
	}, [ canManage ] );
};

/**
 * Hook to get the DataViews view.
 *
 * @return {Object} The view.
 */
export const useView = () => {
	return useSelect( select => ( select( NAMESPACE ) as StoreSelectors ).getView(), [] );
};

/**
 * Hook to get the story.
 */
export const useStory = ( storyId: string | number ) => {
	return useSelect( select => ( select( NAMESPACE ) as StoreSelectors ).getStory( storyId ), [ storyId ] );
};
