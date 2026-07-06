/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { PluginPostStatusInfo, store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';
import type { StoreSelectors } from '../store/store-shape';
import type { Field as ComponentField, Story as ComponentStory } from '../components/types';
import StoryFieldPanel from '../components/story-field-panel';
import { useFields } from '../hooks';
import '../style.scss';

const StoryBudgetPanel = () => {
	// A story draft: `{}` until the first `setEditedStory( story )` below populates it. Typed as a
	// loose bag (matching the original untyped JS) rather than `Story`, since `Story.id` is required
	// and this starts out empty; cast to `Story` at the `StoryFieldPanel` boundary once populated.
	const [ editedStory, setEditedStory ] = useState< Record< string, unknown > >( {} );

	const postId = useSelect( select => select( editorStore ).getCurrentPostId() );

	const { storyError, isSavingPost, isDeletingPost, isEditedPostNew } = useSelect( select => ( {
		// `postId` (from `@wordpress/editor`) is `number | string | null`, but this store's own
		// `getStoryError` expects the non-null `string | number` every other call site provides;
		// cast at this boundary rather than guarding (the original JS never guarded it either).
		storyError: ( select( storeNamespace ) as StoreSelectors ).getStoryError( postId as string | number ),
		isSavingPost: select( editorStore ).isSavingPost(),
		isDeletingPost: select( editorStore ).isDeletingPost(),
		isEditedPostNew: select( editorStore ).isEditedPostNew(),
	} ) );

	const story = useSelect(
		select =>
			! postId || isEditedPostNew ? ( {} as Record< string, unknown > ) : ( select( storeNamespace ) as StoreSelectors ).getStory( postId ),
		[ postId, isEditedPostNew ]
	);
	const fields = useFields();

	const editableFields = fields.filter( field => field.show_in_editor );

	const { createErrorNotice } = useDispatch( noticesStore );
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
			const filteredStory = editableFields.reduce< Record< string, unknown > >(
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
					// `editableFields` is this subtree's own `Field` (`store/types`); `StoryFieldPanel`
					// declares the looser `components/types` `Field` -- cast at this cross-module
					// boundary, as with the same mismatch in `hooks/index.tsx`'s `useStoryFields()`.
					fields={
						editableFields.map( field => {
							// Change the field name to distinguish from WordPress post status
							if ( field.name === 'Status' ) {
								field.name = __( 'Story Status', 'newspack-story-budget' );
							}
							return field;
						} ) as ComponentField[]
					}
					story={ editedStory as ComponentStory }
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
