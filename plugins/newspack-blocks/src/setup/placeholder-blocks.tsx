/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { getBlockType, registerBlockType } from '@wordpress/blocks';
import { Button } from '@wordpress/components';
import { withDispatch } from '@wordpress/data';
import { Icon, pullquote } from '@wordpress/icons';

/**
 * External dependencies.
 */
import type { ComponentType, ReactElement } from 'react';

type PlaceholderBlockConfig = {
	title: string;
	description: string;
	icon: ReactElement;
	message: string;
	url: string;
};

const placeholderBlocks: Record< string, PlaceholderBlockConfig > = {
	'newspack-ads/ad-unit': {
		title: __( 'Ad Unit', 'newspack-blocks' ),
		description: __( 'Render an ad unit from your inventory.', 'newspack-blocks' ),
		icon: pullquote,
		message: __( 'Place ad units inside your page by installing Newspack Ads.', 'newspack-blocks' ),
		url: 'https://help.newspack.com/revenue/advertising/',
	},
};

function registerPlaceholderBlock( blockName: string, { title, description, icon, message, url }: PlaceholderBlockConfig ) {
	if ( getBlockType( blockName ) ) {
		return;
	}
	title = title || blockName.split( '/' ).pop() || blockName;
	const edit = ( { clientId, removeBlocks }: { clientId: string; removeBlocks: ( clientId: string ) => void } ) => {
		const blockProps = useBlockProps(); // eslint-disable-line
		return (
			<div { ...blockProps }>
				<div className="newspack-blocks-placeholder-block">
					<div className="newspack-blocks-placeholder-block__label">
						{ icon && <Icon icon={ icon } /> }
						{ title }
					</div>
					<p>
						<strong>
							{ sprintf(
								// translators: %s is the block name.
								__( 'The "%s" block is currently not available.', 'newspack-blocks' ),
								title
							) }
						</strong>
					</p>
					{ message && <p>{ message }</p> }
					<div className="newspack-blocks-placeholder-block__buttons">
						{ url && (
							<Button variant="primary" target="_blank" rel="external" href={ url }>
								{ __( 'Visit Plugin Page', 'newspack-blocks' ) }
							</Button>
						) }
						<Button variant="secondary" isDestructive onClick={ () => removeBlocks( clientId ) }>
							{ __( 'Remove Block', 'newspack-blocks' ) }
						</Button>
					</div>
				</div>
			</div>
		);
	};
	// `registerBlockType`'s settings type requires `attributes` and `category`, but WP allows
	// registering a block without either (this block is never shown in the inserter, per
	// `supports.inserter: false` below, and has no attributes of its own). Typing `settings` as
	// `Partial<...>` lets the object omit them like the original JS did; the final cast back to
	// the real settings type narrows from that (verified) supertype rather than fabricating
	// values the runtime never uses.
	const settings: Partial< Parameters< typeof registerBlockType >[ 1 ] > = {
		apiVersion: 3,
		title,
		description,
		icon,
		// `withDispatch` itself types the component it wraps as `ComponentType< any >` (see its
		// own declaration) because a HOC-wrapped edit component's props aren't statically known
		// to the `@wordpress/data` types. Follow the same convention at this boundary between
		// `@wordpress/data` and `registerBlockType`'s `BlockEditProps` expectation; the wrapped
		// component's actual runtime props are unaffected.
		edit: withDispatch( dispatch => ( {
			removeBlocks: ( dispatch( 'core/block-editor' ) as { removeBlocks: ( ...args: unknown[] ) => unknown } ).removeBlocks,
		} ) )( edit ) as ComponentType< any >, // eslint-disable-line @typescript-eslint/no-explicit-any
		supports: {
			html: false,
			lock: false,
			reusable: false,
			inserter: false,
			defaultStylePicker: false,
			customClassName: false,
			className: false,
			alignWide: false,
			align: false,
			anchor: false,
		},
	};
	registerBlockType( blockName, settings as Parameters< typeof registerBlockType >[ 1 ] );
}

for ( const blockName in placeholderBlocks ) {
	registerPlaceholderBlock( blockName, placeholderBlocks[ blockName ] );
}
