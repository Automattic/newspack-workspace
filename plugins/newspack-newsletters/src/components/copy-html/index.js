/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { withSelect } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { TextareaControl, ClipboardButton } from '@wordpress/components';

/**
 * Internal dependencies
 */
import './style.scss';

export default compose( [
	withSelect( select => {
		const { getCurrentPostAttribute } = select( 'core/editor' );
		return {
			meta: getCurrentPostAttribute( 'meta' ),
		};
	} ),
] )( ( { meta } ) => {
	const { newspack_email_html: html } = meta;
	const [ hasCopied, setHasCopied ] = useState( false );
	return (
		<div className="newspack-newsletters__copy_html">
			<TextareaControl disabled value={ html } rows="10" />
			<ClipboardButton text={ html } onCopy={ () => setHasCopied( true ) } onFinishCopy={ () => setHasCopied( false ) }>
				{ hasCopied ? __( 'Copied!', 'newspack-newsletters' ) : __( 'Copy to clipboard', 'newspack-newsletters' ) }
			</ClipboardButton>
		</div>
	);
} );
