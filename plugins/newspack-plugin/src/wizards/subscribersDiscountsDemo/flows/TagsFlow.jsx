/**
 * Flow — Manage tags.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { FormTokenField, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Modal } from '../../../../packages/components/src';
import { KNOWN_TAGS } from '../data/mock-subscribers';

const normalize = tokens => [ ...new Set( ( tokens || [] ).map( t => String( t ).trim().toLowerCase() ).filter( Boolean ) ) ];

export default function TagsFlow( { tags = [], onClose, onComplete } ) {
	const [ next, setNext ] = useState( tags );
	const finalTags = normalize( next );
	const dirty = JSON.stringify( finalTags ) !== JSON.stringify( normalize( tags ) );

	const submit = () => {
		onComplete( {
			type: 'success',
			transient: true,
			message: __( 'Tags updated.', 'newspack-plugin' ),
			mutate: subscriber => ( { ...subscriber, tags: finalTags } ),
		} );
	};

	return (
		<Modal title={ __( 'Manage tags', 'newspack-plugin' ) } onRequestClose={ onClose }>
			<VStack spacing={ 4 }>
				<FormTokenField
					label={ __( 'Tags', 'newspack-plugin' ) }
					value={ next }
					suggestions={ KNOWN_TAGS }
					onChange={ setNext }
					__experimentalExpandOnFocus
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" onClick={ submit } disabled={ ! dirty }>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
