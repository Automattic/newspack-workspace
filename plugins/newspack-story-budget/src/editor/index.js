/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';
import StoryFieldPanel from '../components/story-field-panel';
import { useFields } from '../hooks';
import '../style.scss';

const StoryBudgetPanel = () => {
	const [ editedStory, setEditedStory ] = useState( {} );

	const postId = useSelect( select => select( 'core/editor' ).getCurrentPostId() );

	const { storyError, isSavingPost, isDeletingPost, isEditedPostNew } = useSelect( select => ( {
		storyError: select( storeNamespace ).getStoryError( postId ),
		isSavingPost: select( 'core/editor' ).isSavingPost(),
		isDeletingPost: select( 'core/editor' ).isDeletingPost(),
		isEditedPostNew: select( 'core/editor' ).isEditedPostNew(),
	} ) );

	const story = useSelect(
		select => ( ! postId || isEditedPostNew ? {} : select( storeNamespace ).getStory( postId ) ),
		[ postId, isEditedPostNew ]
	);
	const fields = useFields();

	const editableFields = fields.filter( field => field.show_in_editor );

	const { createErrorNotice } = useDispatch( 'core/notices' );
	const { saveStory } = useDispatch( storeNamespace );

	useEffect( () => {
		if ( storyError ) {
			createErrorNotice( storyError, {
				id: 'newspack-story-budget-story-error',
				isDismissible: true,
			} );
		}
	}, [ storyError ] );

	useEffect( () => {
		setEditedStory( story );
	}, [ story ] );

	useEffect( () => {
		if ( isSavingPost && ! isDeletingPost && ! isEditedPostNew ) {
			// Save only the edited fields.
			const filteredStory = editableFields.reduce(
				( acc, field ) => {
					if ( editedStory[ field.slug ] !== story?.[ field.slug ] ) {
						acc[ field.slug ] = editedStory[ field.slug ];
					}
					return acc;
				},
				{ id: postId }
			);
			if ( Object.keys( filteredStory ).length > 1 ) {
				saveStory( postId, filteredStory );
			}
		}
	}, [ isSavingPost, isDeletingPost, isEditedPostNew ] );

	return (
		<PluginPostStatusInfo className="newspack-story-budget__post-status-info">
			{ editedStory?.id && fields?.length ? (
				<StoryFieldPanel
					fields={ editableFields.map( field => {
						// Change the field name to distinguish from WordPress post status
						if ( field.name === 'Status' ) {
							field.name = __( 'Story Status', 'newspack-story-budget' );
						}
						return field;
					} ) }
					story={ editedStory }
					onChange={ setEditedStory }
					rowAnchor="panel"
				/>
			) : null }
		</PluginPostStatusInfo>
	);
};

registerPlugin( 'newspack-story-budget-editor', {
	render: () => <StoryBudgetPanel />,
} );
