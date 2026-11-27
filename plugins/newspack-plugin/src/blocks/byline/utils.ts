/**
 * External dependencies
 */
import type { MouseEvent as ReactMouseEvent, ReactElement } from 'react';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';
import { _x } from '@wordpress/i18n';

/**
 * An author as rendered by the byline block (WP user or CoAuthors Plus
 * author).
 */
export type BylineAuthor = {
	id?: number | string;
	name?: string;
	display_name?: string;
};

/**
 * Decode HTML entities in a string.
 *
 * Uses a textarea element to safely decode entities without executing scripts.
 * DOMParser is not used because it normalizes whitespace per HTML parsing rules.
 *
 * @param text Text with HTML entities.
 * @return Decoded text.
 */
export function decodeHtmlEntities( text: string | null | undefined ): string {
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
 * @param bylineContent Raw byline content with shortcodes.
 * @return Array of React elements for display.
 */
export function parseBylineForDisplay( bylineContent: string | null | undefined ): Array< string | ReactElement > {
	if ( ! bylineContent ) {
		return [];
	}

	const elements: Array< string | ReactElement > = [];
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
					onClick: ( e: ReactMouseEvent ) => e.preventDefault(),
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
 * @param author        Author object.
 * @param index         Author index for key generation.
 * @param linkToArchive Whether to show as a link.
 * @return React element.
 */
function createAuthorElement( author: BylineAuthor, index: number, linkToArchive: boolean | undefined ): ReactElement {
	const name = author.display_name || author.name;
	return createElement(
		'span',
		{ key: `author-wrapper-${ author.id || index }`, className: 'author vcard' },
		linkToArchive
			? createElement( 'a', { href: '#author-link', onClick: ( e: ReactMouseEvent ) => e.preventDefault(), className: 'url fn n' }, name )
			: createElement( 'span', { className: 'fn n' }, name )
	);
}

/**
 * Format authors list for display.
 *
 * @param authors       Array of author objects.
 * @param linkToArchive Whether to show as links.
 * @return Array of React elements.
 */
export function formatAuthorsList( authors: BylineAuthor[] | null | undefined, linkToArchive: boolean | undefined ): Array< string | ReactElement > {
	if ( ! authors || authors.length === 0 ) {
		return [];
	}

	if ( authors.length === 1 ) {
		return [ createAuthorElement( authors[ 0 ], 0, linkToArchive ) ];
	}

	const result: Array< string | ReactElement > = [];
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
