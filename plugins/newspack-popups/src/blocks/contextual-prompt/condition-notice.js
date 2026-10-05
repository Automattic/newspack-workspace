/**
 * The standing notice that a story's prompt is showing the control-test copy.
 *
 * The block editor draws the card from its stored copy, so a story selected for
 * the control test still shows its own copy on the canvas — the swap happens at
 * render time and never touches what is saved. This notice closes that gap in
 * words: it names the copy readers actually get, wherever the editor surfaces
 * the post's prompt. Both the post-scoped document panel and the selected
 * instance's block inspector render it, reading the one server-computed
 * condition so the two can never disagree.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import { Notice } from '@wordpress/components';

/**
 * The info notice for the copy a story's reader is getting in place of its own.
 * Two conditions swap that copy: the control test, which shows generic control
 * copy on selected stories, and the site-wide override, which replaces every
 * prompt for a fund drive. The notice names whichever is active. It renders
 * nothing under any other condition (the story's own copy, or the feature off),
 * so a caller can drop it in unconditionally.
 *
 * @return {JSX.Element|null} The notice, or null when the story is showing its own copy.
 */
export const ControlConditionNotice = () => {
	const { condition, controlSettingsUrl } = window.newspackPopupsContextualPrompt || {};

	let message;
	if ( 'generic_control' === condition ) {
		message = __(
			'This prompt is currently showing control test copy to readers. Configure test settings in <a>Contextual Prompts</a>.',
			'newspack-popups'
		);
	} else if ( 'override' === condition ) {
		message = __(
			'The site-wide override is currently replacing this prompt for all readers. Manage it in <a>Contextual Prompts</a>.',
			'newspack-popups'
		);
	} else {
		return null;
	}

	return (
		<Notice status="info" isDismissible={ false }>
			{ createInterpolateElement( message, {
				// eslint-disable-next-line jsx-a11y/anchor-has-content
				a: <a href={ controlSettingsUrl || '#' } />,
			} ) }
		</Notice>
	);
};
