/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { close as closeIcon } from '@wordpress/icons';
import { useLayoutEffect, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalColorGradientSettingsDropdown as ColorGradientSettingsDropdown,
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	useSettings,
} from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import PanelPreviewToggle from '../panel-preview-toggle';
import { panelToggles, notifySubscribers } from '../preview-refs';

const PANEL_CLASSES = 'comments-panel__panel is-layout-constrained comments-panel__panel--right';

const INNER_BLOCKS_TEMPLATE = [
	[
		'core/comments',
		{ className: 'wp-block-comments-query-loop' },
		[
			[ 'core/post-comments-form' ],
			[ 'core/comments-title', { showPostTitle: false, level: 3 } ],
			[
				'core/comment-template',
				{},
				[
					[
						'core/group',
						{ layout: { type: 'flex', orientation: 'vertical' }, style: { spacing: { blockGap: '0' } } },
						[
							[
								'core/group',
								{ layout: { type: 'flex', flexWrap: 'wrap' }, style: { spacing: { blockGap: 'var:preset|spacing|20' } } },
								[
									[ 'core/avatar', { size: 24 } ],
									[ 'core/comment-author-name' ],
									[ 'core/comment-date', { format: 'M j, Y g:i A' } ],
									[ 'core/comment-edit-link' ],
								],
							],
							[ 'core/comment-content' ],
							[ 'core/comment-reply-link' ],
						],
					],
				],
			],
			[
				'core/comments-pagination',
				{ layout: { type: 'flex', justifyContent: 'center' } },
				[ [ 'core/comments-pagination-previous' ], [ 'core/comments-pagination-numbers' ], [ 'core/comments-pagination-next' ] ],
			],
		],
	],
];

const ALLOWED_BLOCKS = [ 'core/comments' ];

/**
 * Edit component for the Comments Panel Content block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {string}   props.clientId      Block client ID.
 * @param {Function} props.setAttributes Attribute setter.
 *
 * @return {JSX.Element} The block editor UI.
 */
export default function CommentsPanelContentEdit( { attributes, clientId, setAttributes } ) {
	const { overlayColor } = attributes;

	// Only the first content block in document order renders a real panel — mirrors
	// the PHP `static $rendered` guard so the editor matches the frontend.
	const isFirstInstance = useSelect(
		select => {
			const ids = select( 'core/block-editor' ).getBlocksByName( 'newspack/comments-panel-content' );
			return ! ids.length || ids[ 0 ] === clientId;
		},
		[ clientId ]
	);

	const [ isPreviewOpen, setIsPreviewOpen ] = useState( false );

	const isOpenRef = useRef( false );
	isOpenRef.current = isPreviewOpen;

	const parentClientId = useSelect( select => select( 'core/block-editor' ).getBlockRootClientId( clientId ), [ clientId ] );

	const toggleFnRef = useRef( null );
	toggleFnRef.current = () => {
		const next = ! isOpenRef.current;
		setIsPreviewOpen( next );
		if ( parentClientId ) {
			notifySubscribers( parentClientId, next );
		}
	};

	// Register the toggle (and clean it up) in the commit phase.
	useLayoutEffect( () => {
		if ( ! parentClientId ) {
			return;
		}
		panelToggles.set( parentClientId, () => toggleFnRef.current?.() );
		return () => panelToggles.delete( parentClientId );
	}, [ parentClientId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const togglePreview = open => {
		setIsPreviewOpen( open );
		if ( parentClientId ) {
			notifySubscribers( parentClientId, open );
		}
	};

	const [ colorSettings ] = useSettings( 'color.palette' );

	// Hooks must run unconditionally and useBlockProps exactly once — so the
	// first-instance vs. placeholder choice is computed here and branched only in JSX.
	const panelClassName = isPreviewOpen ? `${ PANEL_CLASSES } comments-panel__panel--open` : 'comments-panel__editor-panel-hidden';
	const panelStyle = isPreviewOpen
		? {
				// Force fixed positioning — Gutenberg can override class-based position on
				// block root elements, so an inline style guarantees it takes effect.
				position: 'fixed',
		  }
		: {};

	const blockProps = useBlockProps( isFirstInstance ? { className: panelClassName, style: panelStyle } : {} );

	return (
		<>
			{ isFirstInstance && (
				<>
					<PanelPreviewToggle isOpen={ isPreviewOpen } onToggle={ () => togglePreview( ! isPreviewOpen ) } />

					<InspectorControls group="color">
						<ColorGradientSettingsDropdown
							settings={ [
								{
									colorValue: overlayColor,
									label: __( 'Overlay', 'newspack-plugin' ),
									onColorChange: value => setAttributes( { overlayColor: value || '' } ),
									hasValue: () => !! overlayColor,
									onDeselect: () => setAttributes( { overlayColor: '' } ),
									isShownByDefault: true,
									resetAllFilter: () => ( { overlayColor: '' } ),
								},
							] }
							panelId={ clientId }
							colors={ colorSettings }
							gradients={ [] }
							enableAlpha
							disableCustomGradients
							__experimentalIsRenderedInSidebar
						/>
					</InspectorControls>

					{ /* Scrim — outside block wrapper so it covers the full editor canvas. */ }
					{ isPreviewOpen && (
						<div
							className="comments-panel__scrim alignfull"
							style={ overlayColor ? { background: overlayColor } : {} }
							onClick={ () => togglePreview( false ) }
							aria-hidden="true"
						/>
					) }
				</>
			) }

			<div { ...blockProps }>
				{ isFirstInstance && (
					<>
						<div className="comments-panel__close-wrapper">
							<button type="button" className="comments-panel__close" onClick={ () => togglePreview( false ) }>
								<span className="comments-panel__icon" aria-hidden="true">
									{ closeIcon }
								</span>
								<span className="screen-reader-text">{ __( 'Close', 'newspack-plugin' ) }</span>
							</button>
						</div>
						<div className="comments-panel__content">
							<InnerBlocks template={ INNER_BLOCKS_TEMPLATE } templateLock={ false } allowedBlocks={ ALLOWED_BLOCKS } />
						</div>
					</>
				) }
			</div>
		</>
	);
}
