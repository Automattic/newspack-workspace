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

export default function TableRowField( { story, field, allowEdit = false } ) {
	const { isLoadingStory, storyError } = useSelect(
		select => ( {
			isLoadingStory: select( storeNamespace ).isLoadingStory( story.id ),
			storyError: select( storeNamespace ).getStoryError( story.id ),
		} ),
		[ story.id, field.slug ]
	);

	const view = useView();

	const fieldIdx = useMemo( () => view.fields.findIndex( f => f === field.slug ), [ view.fields, field.slug ] );

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
