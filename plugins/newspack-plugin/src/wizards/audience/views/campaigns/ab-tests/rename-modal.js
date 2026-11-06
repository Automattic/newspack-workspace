/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Modal, Notice, TextControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { REST_BASE } from './utils';

/**
 * Rename a test. Only the name shown changes: the test's ID, and so its
 * prompts and results, stay as they are.
 *
 * @param {Object}   props
 * @param {Object}   props.test      The test.
 * @param {Function} props.onRenamed Called with the saved name.
 * @param {Function} props.onClose   Called on Cancel or close.
 */
const RenameModal = ( { test, onRenamed, onClose } ) => {
	const [ name, setName ] = useState( test.name || test.test_id );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const canSave = '' !== name.trim() && ! isBusy;

	const save = async event => {
		event.preventDefault();
		if ( ! canSave ) {
			return;
		}
		setIsBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: `${ REST_BASE }/${ encodeURIComponent( test.test_id ) }/name`,
				method: 'POST',
				data: { name: name.trim() },
			} );
			onRenamed( result.name );
		} catch ( e ) {
			setError( e?.message || __( 'Could not rename the test. Try again.', 'newspack-plugin' ) );
			setIsBusy( false );
		}
	};

	return (
		<Modal title={ __( 'Rename test', 'newspack-plugin' ) } size="small" onRequestClose={ onClose }>
			<form onSubmit={ save }>
				<Stack direction="column" gap="xl">
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<TextControl
						label={ __( 'Test name', 'newspack-plugin' ) }
						help={ __(
							'Changes the name shown on this tab and in the prompt editor. The test and its results stay the same.',
							'newspack-plugin'
						) }
						value={ name }
						onChange={ setName }
						disabled={ isBusy }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Stack justify="flex-end" align="center" gap="lg">
						<Button variant="secondary" onClick={ onClose } disabled={ isBusy } __next40pxDefaultSize>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" type="submit" disabled={ ! canSave } isBusy={ isBusy } accessibleWhenDisabled __next40pxDefaultSize>
							{ __( 'Rename', 'newspack-plugin' ) }
						</Button>
					</Stack>
				</Stack>
			</form>
		</Modal>
	);
};

export default RenameModal;
