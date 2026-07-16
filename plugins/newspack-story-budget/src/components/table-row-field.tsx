/**
 * WordPress dependencies.
 */
import { useSelect } from '@wordpress/data';
import { Spinner, Tooltip, Icon } from '@wordpress/components';
import { error } from '@wordpress/icons';
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';
import StoryField from './story-field';
import { useView } from '../hooks';
import type { Field, Story, StoryBudgetSelectors, StoryBudgetView } from './types';

export interface TableRowFieldProps {
	story: Story;
	field: Field;
	allowEdit?: boolean;
}

export default function TableRowField( { story, field, allowEdit = false }: TableRowFieldProps ) {
	const { isLoadingStory, storyError } = useSelect(
		select => {
			const selectors = select( storeNamespace ) as StoryBudgetSelectors;
			return {
				isLoadingStory: selectors.isLoadingStory( story.id ),
				storyError: selectors.getStoryError( story.id ),
			};
		},
		[ story.id, field.slug ]
	);

	const view = useView() as StoryBudgetView;

	const fieldIdx = useMemo( () => ( view.fields as string[] ).findIndex( f => f === field.slug ), [ view.fields, field.slug ] );

	return (
		<div className="newspack-story-budget__table-row-field">
			{ fieldIdx === 0 && isLoadingStory ? (
				<Spinner
					style={ {
						width: '12px',
						height: '12px',
					} }
				/>
			) : (
				<StoryField fieldId={ field.slug } storyId={ story.id } allowEdit={ allowEdit } saveInPlace showPostLinks />
			) }
			{ fieldIdx === 0 && ! isLoadingStory && storyError && (
				<Tooltip text={ storyError }>
					<span className="newspack-story-budget__table-row-field-error">
						<Icon icon={ error } />
					</span>
				</Tooltip>
			) }
		</div>
	);
}
