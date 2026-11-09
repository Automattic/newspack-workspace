/**
 * WordPress dependencies.
 */
import { useSelect } from '@wordpress/data';
import { Spinner, Tooltip, Icon } from '@wordpress/components';
import { error } from '@wordpress/icons';
import { useMemo } from '@wordpress/element';

/**
 * External dependencies.
 */
import classnames from 'classnames';

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

	const isPrimary = useMemo(
		() => ( view.titleField ? field.slug === view.titleField : view.fields.findIndex( f => f === field.slug ) === 0 ),
		[ view.titleField, view.fields, field.slug ]
	);
	const isDescription = field.slug === view.descriptionField;

	return (
		<div
			className={ classnames( 'newspack-story-budget__table-row-field', {
				'newspack-story-budget__table-row-field--description': isDescription,
			} ) }
		>
			{ isPrimary && isLoadingStory ? (
				<Spinner
					style={ {
						width: '12px',
						height: '12px',
					} }
				/>
			) : (
				<StoryField
					fieldId={ field.slug }
					storyId={ story.id }
					allowEdit={ allowEdit }
					saveInPlace
					showPostLinks
					truncate={ allowEdit || ! isDescription }
					emptyValue={ isDescription ? null : undefined }
				/>
			) }
			{ isPrimary && ! isLoadingStory && storyError && (
				<Tooltip text={ storyError }>
					<span className="newspack-story-budget__table-row-field-error">
						<Icon icon={ error } />
					</span>
				</Tooltip>
			) }
		</div>
	);
}
