/**
 * Ghost preview of a Contextual Prompt.
 *
 * Approximates the default front-end treatment (a light card with the copy and
 * a call to action) so an editor can judge copy length and line balance without
 * leaving the story. Deliberately non-interactive — it is an illustration, not
 * the rendered prompt, and the real design is still in progress (NPPD-2101).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

const ContextualPromptPreview = ( { body, buttonLabel, donationsNative, narrow = false } ) => (
	<div className={ `newspack-contextual-prompt-preview${ narrow ? ' is-narrow' : '' }` } aria-hidden="true">
		<p className="newspack-contextual-prompt-preview__body">{ body || __( 'Your prompt copy will appear here.', 'newspack-popups' ) }</p>
		{ donationsNative ? (
			<span className="newspack-contextual-prompt-preview__native">{ __( 'Newspack donation form', 'newspack-popups' ) }</span>
		) : (
			<span className="newspack-contextual-prompt-preview__button">{ buttonLabel || __( 'Donate', 'newspack-popups' ) }</span>
		) }
	</div>
);

export default ContextualPromptPreview;
