/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies
 */
import { useState, useMemo } from '@wordpress/element';
import { __experimentalHStack as HStack, __experimentalText as Text, Dropdown } from '@wordpress/components';

/**
 * External dependencies
 */
import type { ComponentProps } from 'react';

/**
 * Internal dependencies
 */
import StoryField from './story-field';
import type { Field, FieldValue, Story } from './types';

type DropdownPopoverProps = ComponentProps< typeof Dropdown >[ 'popoverProps' ];

const EMPTY_STRING = '';

export interface StoryFieldPanelRowProps {
	field: Field;
	story: Story;
	anchor?: 'field' | 'panel';
	onChange: ( value: FieldValue ) => void;
}

export default ( { field, story, anchor = 'field', onChange }: StoryFieldPanelRowProps ) => {
	const [ popoverAnchor, setPopoverAnchor ] = useState< HTMLElement | null >( null );

	let popoverProps: DropdownPopoverProps = {};

	if ( anchor === 'field' ) {
		popoverProps = {
			placement: 'left-start',
		};
	} else if ( anchor === 'panel' ) {
		// eslint-disable-next-line react-hooks/rules-of-hooks -- pre-existing conditional hook call, not introduced here.
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
				value={ ( story[ field.slug ] as FieldValue ) || EMPTY_STRING }
				onChange={ onChange }
				popoverProps={ popoverProps }
			/>
		</HStack>
	);
};
