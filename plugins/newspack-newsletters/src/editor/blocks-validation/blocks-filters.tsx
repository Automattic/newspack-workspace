/**
 * External dependencies
 */
import { every, some } from 'lodash';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { select, useSelect } from '@wordpress/data';

interface BlockAttributes {
	align?: string;
	verticalAlignment?: string;
	__nestedGroupWarning?: boolean;
	__nestedColumnWarning?: boolean;
	content: string;
	dropCap?: boolean;
	ref?: number;
	[ key: string ]: unknown;
}

interface EditorBlock {
	name: string;
	clientId: string;
	attributes: BlockAttributes;
	innerBlocks: EditorBlock[];
}

interface BlockValidationProps {
	name: string;
	attributes: BlockAttributes;
	block: { clientId: string };
}

const handleSideAlignment = ( warnings: string[], props: BlockValidationProps ) => {
	if ( props.attributes.align === 'left' || props.attributes.align === 'right' ) {
		warnings.push( __( 'Side alignment', 'newspack-newsletters' ) );
	}
	return warnings;
};

const isCenterAligned = ( block: EditorBlock ) => block.attributes.verticalAlignment === 'center';

const getWarnings = ( props: BlockValidationProps ) => {
	let warnings: string[] = [];
	switch ( props.name ) {
		case 'core/group':
			if ( props.attributes.__nestedGroupWarning ) {
				warnings.push( __( 'Nested group', 'newspack-newsletters' ) );
			}
			break;

		// `vertical-align='middle'` will only work if all columns are middle-aligned.
		// This is different in Gutenberg, because it uses flexbox layout (not available in email HTML).
		//
		// If a user chooses middle-alignment of a column, they will be prompted to
		// middle-align all of the columns.
		//
		// Middle alignment option should be removed from the UI for a single column, when that's
		// handled by the block editor filters.
		case 'core/columns':
			const getBlock = select( 'core/block-editor' ).getBlock as ( clientId: string ) => EditorBlock | null;
			const block = getBlock( props.block.clientId );
			if ( block ) {
				const { innerBlocks } = block;
				const isAnyColumnCenterAligned = some( innerBlocks, isCenterAligned );
				const areAllColumnsCenterAligned = every( innerBlocks, isCenterAligned );
				if ( isAnyColumnCenterAligned && ! areAllColumnsCenterAligned ) {
					warnings.push( __( 'Unequal middle alignment. All or none of the columns should be middle-aligned.', 'newspack-newsletters' ) );
				}
			}
			break;

		case 'core/column':
			if ( props.attributes.__nestedColumnWarning ) {
				warnings.push( __( 'Nested columns', 'newspack-newsletters' ) );
			}
			break;

		case 'core/image':
			warnings = handleSideAlignment( warnings, props );
			if ( props.attributes.align === 'full' ) {
				warnings.push( __( 'Full width', 'newspack-newsletters' ) );
			}
			break;

		case 'core/paragraph':
			if ( props.attributes.content.indexOf( '<img' ) >= 0 ) {
				warnings.push( __( 'Inline image', 'newspack-newsletters' ) );
			}
			if ( props.attributes.dropCap ) {
				warnings.push( __( 'Drop cap', 'newspack-newsletters' ) );
			}
			break;
	}
	return warnings;
};

/**
 * Reactive check for `core/block` (synced pattern) references whose target
 * `wp_block` post is not published. The server-side MJML renderer omits these
 * from the preview and sent email, so surface a warning in the editor to match.
 */
const useUnpublishedReusableBlockWarning = ( name: string, attributes: BlockAttributes ) => {
	return useSelect(
		selectData => {
			if ( 'core/block' !== name || ! attributes?.ref ) {
				return null;
			}
			const post = (
				selectData( 'core' ) as {
					getEntityRecord: ( kind: string, name: string, id: number ) => { status?: string } | null;
				}
			 ).getEntityRecord( 'postType', 'wp_block', attributes.ref );
			if ( post && 'publish' !== post.status ) {
				return __( 'Unpublished synced pattern', 'newspack-newsletters' );
			}
			return null;
		},
		[ name, attributes?.ref ]
	);
};

const withUnsupportedFeaturesNotices = createHigherOrderComponent( BlockListBlock => {
	return props => {
		const warnings = getWarnings( props );
		const unpublishedRefWarning = useUnpublishedReusableBlockWarning( props.name, props.attributes );
		if ( unpublishedRefWarning ) {
			warnings.push( unpublishedRefWarning );
		}
		return warnings.length ? (
			<div className="newspack-newsletters__editor-block">
				<div className="newspack-newsletters__editor-block__warning components-notice is-error">
					{ __( 'These features will not be displayed correctly in an email, please remove them:', 'newspack-newsletters' ) }
					{ warnings.map( ( warning, i ) => (
						<strong key={ i }>
							<br />
							{ warning }
						</strong>
					) ) }
				</div>
				<BlockListBlock { ...props } />
			</div>
		) : (
			<BlockListBlock { ...props } />
		);
	};
}, 'withUnsupportedFeaturesNotices' );

export const addBlocksValidationFilter = () => {
	addFilter( 'editor.BlockListBlock', 'newspack-newsletters/unsupported-features-notices', withUnsupportedFeaturesNotices );
};
