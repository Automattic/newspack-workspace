/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Helper to create a bound paragraph block with custom list view name.
 * If wrapInLink is true, the content will be wrapped in an anchor tag for editor preview.
 *
 * @param {string}  key         Author data key for binding.
 * @param {string}  className   CSS class for the paragraph.
 * @param {string}  name        Display name shown in list view.
 * @param {string}  placeholder Placeholder text (defaults to name).
 * @param {boolean} wrapInLink  Whether to wrap content in a link.
 * @return {Array} InnerBlocks template entry for a bound paragraph.
 */
const createBoundParagraph = ( key, className, name, placeholder, wrapInLink = false ) => {
	const attributes = {
		metadata: {
			name, // Custom name shown in list view.
			bindings: {
				content: {
					source: 'newspack-blocks/author',
					args: { key },
				},
			},
		},
		className,
		placeholder: placeholder || name,
	};

	// If wrapInLink is true, set initial content with link wrapper for editor preview.
	if ( wrapInLink ) {
		const linkText = placeholder || name;
		attributes.content = `<a href="#" class="no-op">${ linkText }</a>`;
	}

	return [ 'core/paragraph', attributes ];
};

/**
 * Shared block definition constants.
 */

// Styles applied to the content column in column-based layouts.
const CONTENT_COLUMN_ATTRS = Object.freeze( {
	className: 'author-profile-content-column',
	templateLock: false,
	allowedBlocks: [ 'core/heading', 'core/paragraph', 'core/separator', 'core/spacer', 'newspack/author-profile-social' ],
	style: {
		spacing: {
			blockGap: 'var:preset|spacing|20',
		},
		elements: {
			link: {
				color: {
					text: 'var:preset|color|contrast-3',
				},
			},
		},
	},
	textColor: 'contrast-3',
	fontSize: 'small',
} );

// Author name heading block.
const HEADING_BLOCK = [
	'core/heading',
	{
		level: 3,
		metadata: {
			name: __( 'Author Name', 'newspack-blocks' ),
			bindings: {
				content: {
					source: 'newspack-blocks/author',
					args: { key: 'name' },
				},
			},
		},
		className: 'author-name',
		placeholder: __( 'Author Name', 'newspack-blocks' ),
		textColor: 'contrast',
		fontSize: 'large',
	},
];

// Job title paragraph block with bold styling.
const JOB_TITLE_BLOCK = [
	'core/paragraph',
	{
		metadata: {
			name: __( 'Job Title', 'newspack-blocks' ),
			bindings: {
				content: {
					source: 'newspack-blocks/author',
					args: { key: 'newspack_job_title' },
				},
			},
		},
		className: 'author-job-title',
		placeholder: __( 'Job Title', 'newspack-blocks' ),
		style: {
			typography: {
				fontStyle: 'normal',
				fontWeight: '600',
			},
			elements: {
				link: {
					color: {
						text: 'var:preset|color|contrast',
					},
				},
			},
		},
		textColor: 'contrast',
	},
];

// Bound paragraph blocks for author fields.
const ROLE_BLOCK = createBoundParagraph( 'newspack_role', 'author-role', __( 'Role', 'newspack-blocks' ) );
const EMPLOYER_BLOCK = createBoundParagraph( 'newspack_employer', 'author-employer', __( 'Employer', 'newspack-blocks' ) );
const BIO_BLOCK = createBoundParagraph( 'bio', 'author-bio', __( 'Biography', 'newspack-blocks' ) );

const archiveLinkLabel = sprintf(
	/* translators: %s: author name. */
	__( 'More by %s', 'newspack-blocks' ),
	__( 'Author Name', 'newspack-blocks' )
);
const ARCHIVE_LINK_BLOCK = createBoundParagraph( 'archive_link_text', 'author-archive-link', archiveLinkLabel, undefined, true );

// Social icons block with top padding.
const SOCIAL_BLOCK = [
	'newspack/author-profile-social',
	{
		style: {
			spacing: {
				padding: {
					top: 'var:preset|spacing|20',
				},
			},
		},
	},
];

/**
 * Returns a copy of a block template entry with center alignment added.
 * Uses `textAlign` for headings, `align` for paragraphs.
 *
 * @param {Array}  block   InnerBlocks template entry [blockName, attributes].
 * @param {string} block.0 Block name.
 * @param {Object} block.1 Block attributes.
 * @return {Array} New template entry with center alignment.
 */
const centered = ( [ blockName, attrs ] ) => [
	blockName,
	{ ...attrs, ...( blockName === 'core/heading' ? { textAlign: 'center' } : { align: 'center' } ) },
];

// Shared group styles for centered and compact layouts.
const GROUP_STYLES = Object.freeze( {
	spacing: {
		blockGap: 'var:preset|spacing|20',
	},
	elements: {
		link: {
			color: {
				text: 'var:preset|color|contrast-3',
			},
		},
	},
} );

// Content blocks shared across all layouts.
// Shallow-frozen to prevent push/splice; nested block definitions are safe because templates compose via spread.
const CONTENT_BLOCKS = Object.freeze( [ HEADING_BLOCK, JOB_TITLE_BLOCK, ROLE_BLOCK, EMPLOYER_BLOCK, BIO_BLOCK, ARCHIVE_LINK_BLOCK, SOCIAL_BLOCK ] );

// -- Layout Templates --------------------------------------------------------

/**
 * Avatar left layout: avatar on the left, content on the right.
 */
export const AVATAR_LEFT_TEMPLATE = [
	[
		'core/columns',
		{ isStackedOnMobile: true, className: 'author-profile-columns', templateLock: 'insert' },
		[
			[
				'core/column',
				{
					className: 'author-profile-avatar-column',
					templateLock: 'insert',
					allowedBlocks: [ 'newspack/avatar' ],
				},
				[ [ 'newspack/avatar', { size: 128 } ] ],
			],
			[ 'core/column', CONTENT_COLUMN_ATTRS, CONTENT_BLOCKS ],
		],
	],
];

/**
 * Avatar right layout: avatar first in DOM for correct mobile stacking,
 * CSS reorders content to the left on desktop.
 */
export const AVATAR_RIGHT_TEMPLATE = [
	[
		'core/columns',
		{ isStackedOnMobile: true, className: 'author-profile-columns is-style-first-col-to-second', templateLock: 'insert' },
		[
			[
				'core/column',
				{
					className: 'author-profile-avatar-column',
					templateLock: 'insert',
					allowedBlocks: [ 'newspack/avatar' ],
				},
				[ [ 'newspack/avatar', { size: 128 } ] ],
			],
			[ 'core/column', CONTENT_COLUMN_ATTRS, CONTENT_BLOCKS ],
		],
	],
];

/**
 * Centered layout: large centered avatar with center-aligned text.
 */
export const CENTERED_TEMPLATE = [
	[
		'core/group',
		{
			layout: { type: 'flex', orientation: 'vertical', justifyContent: 'center' },
			style: GROUP_STYLES,
			textColor: 'contrast-3',
			fontSize: 'small',
		},
		[
			[ 'newspack/avatar', { size: 200 } ],
			centered( HEADING_BLOCK ),
			centered( JOB_TITLE_BLOCK ),
			centered( ROLE_BLOCK ),
			centered( EMPLOYER_BLOCK ),
			centered( BIO_BLOCK ),
			centered( ARCHIVE_LINK_BLOCK ),
			SOCIAL_BLOCK,
		],
	],
];

/**
 * Compact layout: no avatar.
 */
export const COMPACT_TEMPLATE = [
	[
		'core/group',
		{
			layout: { type: 'flex', orientation: 'vertical' },
			style: GROUP_STYLES,
			textColor: 'contrast-3',
			fontSize: 'small',
		},
		CONTENT_BLOCKS,
	],
];
