/**
 * WordPress dependencies
 */
import { createBlock } from '@wordpress/blocks';
import { dispatch } from '@wordpress/data';

/* global Node */

/**
 * Detach the Roundup block and convert it to Core blocks.
 *
 * Takes the generated HTML content, converts it into native Gutenberg blocks,
 * and replaces the Roundup block with the converted blocks.
 *
 * @param {string} clientId         The block client ID.
 * @param {string} generatedContent The generated HTML content.
 */
export function detachRoundupBlock( clientId, generatedContent ) {
	// Convert HTML to Gutenberg blocks.
	const blocks = convertHTMLToBlocks( generatedContent );

	// Get the replaceBlocks function from the block editor store.
	const { replaceBlocks } = dispatch( 'core/block-editor' );

	// Replace the Roundup block with the converted core blocks.
	replaceBlocks( clientId, blocks );
}

/**
 * Convert HTML content to an array of Gutenberg blocks.
 *
 * @param {string} html HTML content to convert.
 * @return {Array} Array of block objects.
 */
function convertHTMLToBlocks( html ) {
	const blocks = [];
	const tempDiv = document.createElement( 'div' );
	tempDiv.innerHTML = html;

	// Process each child node.
	Array.from( tempDiv.childNodes ).forEach( node => {
		const block = nodeToBlock( node );
		if ( block ) {
			blocks.push( block );
		}
	} );

	// Flatten in case any nodes returned arrays (like container elements).
	return flattenBlocks( blocks );
}

/**
 * Convert a DOM node to a Gutenberg block.
 *
 * @param {Node} node DOM node to convert.
 * @return {Object|null} Block object or null if node should be skipped.
 */
function nodeToBlock( node ) {
	// Skip empty text nodes.
	if ( node.nodeType === Node.TEXT_NODE ) {
		const text = node.textContent.trim();
		if ( ! text ) {
			return null;
		}
		// Standalone text becomes a paragraph.
		return createBlock( 'core/paragraph', {
			content: text,
		} );
	}

	// Skip non-element nodes.
	if ( node.nodeType !== Node.ELEMENT_NODE ) {
		return null;
	}

	const tagName = node.tagName.toLowerCase();

	// Handle headings (h1-h6).
	if ( /^h[1-6]$/.test( tagName ) ) {
		const level = parseInt( tagName.charAt( 1 ), 10 );
		return createBlock( 'core/heading', {
			content: node.innerHTML,
			level,
		} );
	}

	// Handle paragraphs.
	if ( tagName === 'p' ) {
		const content = node.innerHTML.trim();
		if ( ! content ) {
			return null;
		}
		return createBlock( 'core/paragraph', {
			content,
		} );
	}

	// Handle images.
	if ( tagName === 'img' ) {
		return createBlock( 'core/image', {
			url: node.getAttribute( 'src' ) || '',
			alt: node.getAttribute( 'alt' ) || '',
		} );
	}

	// Handle lists (ul/ol).
	if ( tagName === 'ul' || tagName === 'ol' ) {
		const values = Array.from( node.querySelectorAll( 'li' ) )
			.map( li => li.innerHTML )
			.join( '' );

		return createBlock( 'core/list', {
			ordered: tagName === 'ol',
			values,
		} );
	}

	// Handle blockquotes.
	if ( tagName === 'blockquote' ) {
		return createBlock( 'core/quote', {
			value: node.innerHTML,
		} );
	}

	// Handle divs and other containers - process children.
	if ( tagName === 'div' || tagName === 'section' || tagName === 'article' ) {
		const childBlocks = [];
		Array.from( node.childNodes ).forEach( childNode => {
			const block = nodeToBlock( childNode );
			if ( block ) {
				childBlocks.push( block );
			}
		} );
		return childBlocks;
	}

	// For other elements, convert to paragraph with inner HTML.
	const content = node.innerHTML.trim();
	if ( content ) {
		return createBlock( 'core/paragraph', {
			content,
		} );
	}

	return null;
}

/**
 * Flatten nested arrays of blocks.
 * Needed because container elements might return arrays of blocks.
 *
 * @param {Array} blocks Array that might contain nested arrays.
 * @return {Array} Flattened array of blocks.
 */
function flattenBlocks( blocks ) {
	return blocks.reduce( ( acc, block ) => {
		if ( Array.isArray( block ) ) {
			return acc.concat( flattenBlocks( block ) );
		}
		return acc.concat( block );
	}, [] );
}
