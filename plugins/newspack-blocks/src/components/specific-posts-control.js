/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import AutocompleteTokenField from './autocomplete-tokenfield';
import ReorderModal from './reorder-modal';
import './specific-posts-control.scss';

const SpecificPostsControl = ( { postIds, onChange, fetchSuggestions, fetchSavedInfo } ) => {
	const [ isReordering, setIsReordering ] = useState( false );

	return (
		<>
			<VStack spacing={ 2 }>
				<AutocompleteTokenField
					tokens={ postIds }
					onChange={ onChange }
					fetchSuggestions={ fetchSuggestions }
					fetchSavedInfo={ fetchSavedInfo }
					label={ __( 'Content', 'newspack-blocks' ) }
					help={ __( 'Begin typing any word in a title. Click on an autocomplete result to select it.', 'newspack-blocks' ) }
				/>
				<Button
					className="newspack-blocks-specific-posts-control__reorder"
					variant="secondary"
					__next40pxDefaultSize
					disabled={ 2 > postIds.length }
					accessibleWhenDisabled
					onClick={ () => setIsReordering( true ) }
				>
					{ __( 'Reorder Content', 'newspack-blocks' ) }
				</Button>
			</VStack>
			{ isReordering && (
				<ReorderModal
					title={ __( 'Reorder Content', 'newspack-blocks' ) }
					ids={ postIds }
					fetchItems={ fetchSavedInfo }
					onSave={ ids => {
						onChange( ids );
						setIsReordering( false );
					} }
					onClose={ () => setIsReordering( false ) }
				/>
			) }
		</>
	);
};

export default SpecificPostsControl;
