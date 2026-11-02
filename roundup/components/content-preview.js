/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { RawHTML } from '@wordpress/element';

/**
 * Content preview component for displaying generated roundup content.
 *
 * Shows the AI-generated content in a locked state (not directly editable).
 *
 * @param {Object}  props          Component props.
 * @param {string}  props.content  The generated HTML content to display.
 * @param {boolean} props.isLocked Whether the content is locked from editing.
 * @return {JSX.Element} The content preview component.
 */
export default function ContentPreview( { content, isLocked = true } ) {
	/**
	 * Handle click events to prevent navigation.
	 *
	 * @param {Event} e Click event.
	 */
	const handleClick = e => {
		// Prevent link clicks from navigating.
		if ( e.target.tagName === 'A' ) {
			e.preventDefault();
		}
	};

	return (
		<div
			className="roundup-block-preview"
			onClick={ handleClick }
			role="button"
			tabIndex={ 0 }
			onKeyDown={ handleClick }
		>
			{ isLocked && (
				<div className="roundup-block-preview__locked-overlay">
					<div className="roundup-block-preview__locked-message">
						<p>{ __( 'Detach the block to edit this content', 'newspack-manager' ) }</p>
					</div>
				</div>
			) }
			<div className="roundup-block-preview__content">
				<RawHTML>{ content }</RawHTML>
			</div>
		</div>
	);
}
