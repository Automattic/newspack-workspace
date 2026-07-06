/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __experimentalVStack as VStack } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import StoryFieldPanelRow from './story-field-panel-row';
import type { Field, Story } from './types';

export interface StoryFieldPanelProps {
	fields: Field[];
	story: Story | null | undefined;
	rowAnchor?: 'field' | 'panel';
	onChange?: ( story: Story ) => void;
}

export default ( { fields, story, rowAnchor = 'field', onChange = () => {} }: StoryFieldPanelProps ) => {
	const [ editedStory, setEditedStory ] = useState( story );

	useEffect( () => {
		setEditedStory( story );
	}, [ story ] );

	if ( ! story ) {
		return null;
	}

	return (
		<VStack style={ { width: '100%' } }>
			{ fields.map( field => (
				<StoryFieldPanelRow
					key={ field.slug }
					anchor={ rowAnchor }
					field={ field }
					// `editedStory` state can't be narrowed by the `!story` guard above (they're
					// separate bindings); it was seeded from `story` and is only ever reassigned
					// below with another spread of a Story, so it's safe by construction.
					story={ editedStory as Story }
					onChange={ value => {
						const newEditedStory = {
							...editedStory,
							[ field.slug ]: value,
						} as Story;
						setEditedStory( newEditedStory );
						onChange( newEditedStory );
					} }
				/>
			) ) }
		</VStack>
	);
};
