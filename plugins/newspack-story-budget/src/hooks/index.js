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
	return useSelect( select => select( NAMESPACE ).getFields(), [] );
};

/**
 * Hook to get a field.
 *
 * @param {string} fieldSlug The field slug.
 *
 * @return {Object} The field.
 */
export const useField = fieldSlug => {
	return useSelect( select => select( NAMESPACE ).getField( fieldSlug ), [ fieldSlug ] );
};

/**
 * Hook to get the field enhanced with props from the story metadata.
 *
 * @param {number} storyId   The story ID.
 * @param {string} fieldSlug The field slug.
 *
 * @return {Object} The field enhanced with props.
 */
export const useStoryField = ( storyId, fieldSlug ) => {
	const field = useSelect( select => select( NAMESPACE ).getField( fieldSlug ), [ fieldSlug ] );

	const fieldsProps = useSelect( select => select( NAMESPACE ).getStoryMeta( storyId, 'fields_props' ), [ storyId ] );

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
export const useStoryFields = ( { allowEdit } ) => {
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
					render: applyFilters(
						'newspack-story-budget.table-row-field',
						value => <TableRowField story={ value.item } field={ field } allowEdit={ allowEdit } />,
						field,
						allowEdit
					),
				} ) ),
		[ fields, allowEdit ]
	);
};

/**
 * Hook to get the actions for DataViews.
 *
 * @return {Array} The actions.
 */
export const useStoryActions = () => {
	const canManage = useSelect( select => select( NAMESPACE ).canManage() );

	const { fetchStory, clearErrors } = useDispatch( NAMESPACE );

	return useMemo(
		() => [
			...applyFilters( 'newspack-story-budget.actions', [
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
			] ),
		],
		[ canManage ]
	);
};

/**
 * Hook to get the DataViews view.
 *
 * @return {Object} The view.
 */
export const useView = () => {
	return useSelect( select => select( NAMESPACE ).getView(), [] );
};

/**
 * Hook to get the story.
 */
export const useStory = storyId => {
	return useSelect( select => select( NAMESPACE ).getStory( storyId ), [ storyId ] );
};
