/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { withSelect } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { TextareaControl, ClipboardButton } from '@wordpress/components';

/**
 * External dependencies
 */
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import type { NewsletterMeta } from '../../service-providers/types';
import './style.scss';

interface CopyHTMLProps {
	meta: NewsletterMeta;
}

const CopyHTML = ( { meta }: CopyHTMLProps ) => {
	const { newspack_email_html: html } = meta;
	const [ hasCopied, setHasCopied ] = useState( false );
	return (
		<div className="newspack-newsletters__copy_html">
			{ /* Disabled control never fires a change event; onChange is required by the type but is a no-op here. */ }
			<TextareaControl disabled value={ html as string } rows={ 10 } onChange={ () => {} } />
			<ClipboardButton text={ html as string } onCopy={ () => setHasCopied( true ) } onFinishCopy={ () => setHasCopied( false ) }>
				{ hasCopied ? __( 'Copied!', 'newspack-newsletters' ) : __( 'Copy to clipboard', 'newspack-newsletters' ) }
			</ClipboardButton>
		</div>
	);
};

// `compose` erases the injected-prop wiring to `unknown`; the cast restores a
// usable component type for consumers (which render `<CopyHTML />`).
export default compose(
	withSelect( select => {
		const { getCurrentPostAttribute } = select( 'core/editor' ) as {
			getCurrentPostAttribute: ( attribute: string ) => NewsletterMeta;
		};
		return {
			meta: getCurrentPostAttribute( 'meta' ),
		};
	} )
)( CopyHTML ) as ComponentType;
