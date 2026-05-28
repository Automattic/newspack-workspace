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

export default ( { fields, story, rowAnchor = 'field', onChange = () => {} } ) => {
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
					story={ editedStory }
					onChange={ value => {
						const newEditedStory = {
							...editedStory,
							[ field.slug ]: value,
						};
						setEditedStory( newEditedStory );
						onChange( newEditedStory );
					} }
				/>
			) ) }
		</VStack>
	);
};
