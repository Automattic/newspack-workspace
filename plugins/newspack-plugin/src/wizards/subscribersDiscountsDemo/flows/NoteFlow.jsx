/**
 * Flow — Add or edit a private note.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TextareaControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Modal } from '../../../../packages/components/src';

export default function NoteFlow( { note, onClose, onComplete } ) {
	const isEdit = !! note;
	const [ text, setText ] = useState( note?.text || '' );
	const trimmed = text.trim();

	const submit = () => {
		if ( ! trimmed ) {
			return;
		}
		onComplete( {
			type: 'success',
			transient: true,
			message: isEdit ? __( 'Private note updated.', 'newspack-plugin' ) : __( 'Private note added.', 'newspack-plugin' ),
			mutate: subscriber => {
				const notes = subscriber.notes || [];
				if ( isEdit ) {
					return {
						...subscriber,
						notes: notes.map( n => ( n.id === note.id ? { ...n, text: trimmed } : n ) ),
					};
				}
				return {
					...subscriber,
					notes: [ ...notes, { id: `note_${ Date.now() }`, text: trimmed } ],
				};
			},
		} );
	};

	return (
		<Modal
			title={ isEdit ? __( 'Edit private note', 'newspack-plugin' ) : __( 'Add a private note', 'newspack-plugin' ) }
			onRequestClose={ onClose }
		>
			<VStack spacing={ 4 }>
				<TextareaControl
					label={ __( 'Note', 'newspack-plugin' ) }
					help={ __( 'This is only visible to admins.', 'newspack-plugin' ) }
					value={ text }
					onChange={ setText }
					rows={ 5 }
					__nextHasNoMarginBottom
				/>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" onClick={ submit } disabled={ ! trimmed }>
						{ isEdit ? __( 'Save changes', 'newspack-plugin' ) : __( 'Save note', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
