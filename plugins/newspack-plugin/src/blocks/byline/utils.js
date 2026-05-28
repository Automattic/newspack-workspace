/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';
import { _x } from '@wordpress/i18n';

/**
 * Decode HTML entities in a string.
 *
 * Uses a textarea element to safely decode entities without executing scripts.
 * DOMParser is not used because it normalizes whitespace per HTML parsing rules.
 *
 * @param {string} text Text with HTML entities.
 * @return {string} Decoded text.
 */
export function decodeHtmlEntities( text ) {
	if ( ! text ) {
		return '';
	}
	const textarea = document.createElement( 'textarea' );
	textarea.innerHTML = text;
	return textarea.value;
}

/**
 * Parse byline shortcodes to extract display names and render as React elements.
 *
 * @param {string} bylineContent Raw byline content with shortcodes.
 * @return {Array} Array of React elements for display.
 */
export function parseBylineForDisplay( bylineContent ) {
	if ( ! bylineContent ) {
		return [];
	}

	const elements = [];
	let lastIndex = 0;
	const regex = /\[Author id=(\d+)\](.*?)\[\/Author\]/g;
	let match;

	while ( ( match = regex.exec( bylineContent ) ) !== null ) {
		// Add text before the match.
		if ( match.index > lastIndex ) {
			const textBefore = bylineContent.slice( lastIndex, match.index );
			elements.push( decodeHtmlEntities( textBefore ) );
		}

		// Add author link.
		const authorId = match[ 1 ];
		const authorName = match[ 2 ];
		elements.push(
			createElement(
				'a',
				{
					key: `author-${ authorId }-${ match.index }`,
					href: '#author-link',
					onClick: e => e.preventDefault(),
					className: 'url fn n',
				},
				decodeHtmlEntities( authorName )
			)
		);

		lastIndex = match.index + match[ 0 ].length;
	}

	// Add remaining text after last match.
	if ( lastIndex < bylineContent.length ) {
		const textAfter = bylineContent.slice( lastIndex );
		elements.push( decodeHtmlEntities( textAfter ) );
	}

	return elements;
}

/**
 * Create an author element for display.
 *
 * @param {Object}  author        Author object.
 * @param {number}  index         Author index for key generation.
 * @param {boolean} linkToArchive Whether to show as a link.
 * @return {Object} React element.
 */
function createAuthorElement( author, index, linkToArchive ) {
	const name = author.display_name || author.name;
	return createElement(
		'span',
		{ key: `author-wrapper-${ author.id || index }`, className: 'author vcard' },
		linkToArchive
			? createElement( 'a', { href: '#author-link', onClick: e => e.preventDefault(), className: 'url fn n' }, name )
			: createElement( 'span', { className: 'fn n' }, name )
	);
}

/**
 * Format authors list for display.
 *
 * @param {Array}   authors       Array of author objects.
 * @param {boolean} linkToArchive Whether to show as links.
 * @return {Array} Array of React elements.
 */
export function formatAuthorsList( authors, linkToArchive ) {
	if ( ! authors || authors.length === 0 ) {
		return [];
	}

	if ( authors.length === 1 ) {
		return [ createAuthorElement( authors[ 0 ], 0, linkToArchive ) ];
	}

	const result = [];
	authors.forEach( ( author, index ) => {
		result.push( createAuthorElement( author, index, linkToArchive ) );
		if ( index < authors.length - 2 ) {
			result.push( ', ' );
		} else if ( index === authors.length - 2 ) {
			result.push( _x( ' and ', 'post author separator', 'newspack-plugin' ) );
		}
	} );

	return result;
}
