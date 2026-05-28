/**
 * WordPress dependencies
 */
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { useContext, useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { getSharedAuthorContext } from '../../shared/author-context';
import { useUserAvatar, usePostAuthors, useDefaultAvatar } from './hooks';
import { useCustomByline, extractAuthorIdsFromByline } from '../../shared/hooks/use-custom-byline';
import { getOverlapMaskStyle } from './utils';
import AvatarWrapper from './avatar-wrapper';
import AvatarInspectorControls from './inspector';

/**
 * Edit component for the Avatar block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Object}   props.context       Block context.
 * @param {Function} props.setAttributes Function to update block attributes.
 * @return {JSX.Element} The edit component.
 */
const Edit = ( { attributes, context, setAttributes } ) => {
	const overlapMaskStyle = useMemo(
		() => getOverlapMaskStyle( attributes ),
		[ attributes.className, attributes?.style?.border?.radius, attributes.size ]
	);
	const blockProps = useBlockProps();

	// Check for parent block context first (nested mode - single author).
	const authorFromBlockContext = context[ 'newspack-blocks/author' ];
	const ResolvedAuthorContext = getSharedAuthorContext();
	const authorFromReactContext = useContext( ResolvedAuthorContext );
	const authorFromParent = authorFromBlockContext || authorFromReactContext;

	// Hooks must be called unconditionally per React rules.
	const defaultAvatarUrl = useDefaultAvatar();
	const { postId, postType } = context;
	const avatar = useUserAvatar( { userId: attributes?.userId, postId, postType } );
	const allAuthors = usePostAuthors( { postId, postType } );
	const { bylineActive, bylineContent } = useCustomByline( postId, postType );

	// Memoize author ID extraction to avoid running regex on every render.
	const authorIds = useMemo( () => extractAuthorIdsFromByline( bylineContent ), [ bylineContent ] );

	const renderAvatar = ( currentAvatar, key ) => (
		<AvatarWrapper
			key={ key }
			avatar={ currentAvatar }
			size={ attributes.size }
			attributes={ attributes }
			overlapMaskStyle={ overlapMaskStyle }
		/>
	);

	// Nested mode: render single author from parent context.
	if ( authorFromParent ) {
		let avatarUrl = '';
		if ( authorFromParent.avatar ) {
			if ( authorFromParent.avatar.includes( '<img' ) ) {
				const match = authorFromParent.avatar.match( /src=["']([^"']+)["']/ );
				avatarUrl = match?.[ 1 ] || '';
			} else {
				avatarUrl = authorFromParent.avatar;
			}
		}

		if ( ! avatarUrl ) {
			// Use the site's default avatar (gravatar silhouette) as fallback.
			const fallbackAvatar = {
				src: defaultAvatarUrl || '',
				alt: authorFromParent.name || '',
				minSize: 16,
				maxSize: 128,
			};
			return (
				<>
					<AvatarInspectorControls attributes={ attributes } setAttributes={ setAttributes } />
					<div { ...blockProps }>
						{ fallbackAvatar.src ? (
							renderAvatar( fallbackAvatar, 'nested-default' )
						) : (
							<AvatarWrapper size={ attributes.size } attributes={ attributes } placeholder />
						) }
					</div>
				</>
			);
		}

		const parentAvatar = {
			src: avatarUrl,
			alt: authorFromParent.name || '',
			minSize: 16,
			maxSize: 128,
		};

		return (
			<>
				<AvatarInspectorControls attributes={ attributes } setAttributes={ setAttributes } />
				<div { ...blockProps }>{ renderAvatar( parentAvatar, 'nested-author' ) }</div>
			</>
		);
	}

	// Text-only custom byline (no [Author] shortcodes) — show placeholder.
	const isTextOnlyByline = bylineActive && ( ! bylineContent || authorIds.length === 0 );
	if ( isTextOnlyByline ) {
		return (
			<>
				<AvatarInspectorControls attributes={ attributes } setAttributes={ setAttributes } />
				<AvatarWrapper size={ attributes.size } attributes={ attributes } placeholder />
			</>
		);
	}

	// Standalone mode: get authors from post context.
	const authors = allAuthors?.length ? allAuthors : null;

	// Wait until we have something to render
	if ( ! avatar?.src && ! authors?.length ) {
		return <div { ...blockProps }>{ __( 'Loading avatar…', 'newspack-plugin' ) }</div>;
	}

	return (
		<>
			<AvatarInspectorControls attributes={ attributes } setAttributes={ setAttributes } />
			<div { ...blockProps }>
				{ authors?.length
					? authors.map( ( author, index ) => {
							const currentAvatar = {
								src: author.avatarSrc,
								alt: author?.name || author?.display_name || '',
							};
							return renderAvatar( currentAvatar, author.id || index );
					  } )
					: renderAvatar( avatar, 'single-author' ) }
			</div>
		</>
	);
};

export default Edit;
