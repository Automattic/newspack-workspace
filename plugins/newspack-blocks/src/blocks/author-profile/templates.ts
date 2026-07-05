/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * A block's attributes within an InnerBlocks template.
 */
type BlockAttributes = Record< string, unknown >;

/**
 * An InnerBlocks template entry: [ blockName, attributes?, innerBlocks? ].
 * Frozen (readonly) shapes are composed into templates, so the tuple and its
 * nested block lists are readonly.
 */
type BlockTemplate = readonly [ string, BlockAttributes?, ( readonly BlockTemplate[] )? ];

/**
 * Helper to create a bound paragraph block with custom list view name.
 * If wrapInLink is true, the content will be wrapped in an anchor tag for editor preview.
 *
 * @param key         Author data key for binding.
 * @param className   CSS class for the paragraph.
 * @param name        Display name shown in list view.
 * @param placeholder Placeholder text (defaults to name).
 * @param wrapInLink  Whether to wrap content in a link.
 * @return InnerBlocks template entry for a bound paragraph.
 */
const createBoundParagraph = ( key: string, className: string, name: string, placeholder?: string, wrapInLink = false ): BlockTemplate => {
	const attributes: BlockAttributes = {
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
const HEADING_BLOCK: BlockTemplate = [
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
const JOB_TITLE_BLOCK: BlockTemplate = [
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
const SOCIAL_BLOCK: BlockTemplate = [
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
 * @param block InnerBlocks template entry [blockName, attributes].
 * @return New template entry with center alignment.
 */
const centered = ( block: BlockTemplate ): BlockTemplate => {
	const [ blockName, attrs ] = block;
	return [ blockName, { ...attrs, ...( blockName === 'core/heading' ? { textAlign: 'center' } : { align: 'center' } ) } ];
};

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
export const AVATAR_LEFT_TEMPLATE: BlockTemplate[] = [
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
export const AVATAR_RIGHT_TEMPLATE: BlockTemplate[] = [
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
export const CENTERED_TEMPLATE: BlockTemplate[] = [
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
export const COMPACT_TEMPLATE: BlockTemplate[] = [
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
