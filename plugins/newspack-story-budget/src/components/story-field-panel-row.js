/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies
 */
import { useState, useMemo } from '@wordpress/element';
import { __experimentalHStack as HStack, __experimentalText as Text } from '@wordpress/components';

/**
 * Internal dependencies
 */
import StoryField from './story-field';

const EMPTY_STRING = '';

export default ( { field, story, anchor = 'field', onChange } ) => {
	const [ popoverAnchor, setPopoverAnchor ] = useState( null );

	let popoverProps = {};

	if ( anchor === 'field' ) {
		popoverProps = {
			placement: 'left-start',
		};
	} else if ( anchor === 'panel' ) {
		popoverProps = useMemo(
			() => ( {
				anchor: popoverAnchor,
				placement: 'left-start',
				shift: true,
				offset: 36,
			} ),
			[ popoverAnchor ]
		);
	}

	return (
		<HStack expanded key={ field.slug } className="newspack-story-budget__field-row" ref={ anchor === 'panel' ? setPopoverAnchor : null }>
			<Text>{ field.name }:</Text>
			<StoryField
				fieldId={ field.slug }
				storyId={ story.id }
				value={ story[ field.slug ] || EMPTY_STRING }
				onChange={ onChange }
				popoverProps={ popoverProps }
			/>
		</HStack>
	);
};
