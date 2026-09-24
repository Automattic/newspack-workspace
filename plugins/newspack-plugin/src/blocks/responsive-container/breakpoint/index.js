/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { desktop, mobile } from '@wordpress/icons';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../../packages/colors/colors.module.scss';

export const title = __( 'Breakpoint', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

const foreground = colors[ 'primary-400' ];

export const settings = {
	title,
	icon: {
		src: desktop,
		foreground,
	},
	// A single block type shares one static icon, so two variations — matched to
	// the `view` attribute via `isActive` — give the desktop and mobile breakpoints
	// their own icon and label in the List View and breadcrumb.
	variations: [
		{
			name: 'desktop',
			title: __( 'Desktop', 'newspack-plugin' ),
			icon: { src: desktop, foreground },
			attributes: { view: 'desktop' },
			isActive: [ 'view' ],
		},
		{
			name: 'mobile',
			title: __( 'Mobile', 'newspack-plugin' ),
			icon: { src: mobile, foreground },
			attributes: { view: 'mobile' },
			isActive: [ 'view' ],
		},
	],
	edit: Edit,
	save: ( { attributes } ) => {
		const blockProps = useBlockProps.save( {
			className: `newspack-responsive-container-breakpoint--${ attributes.view }`,
		} );
		return (
			<div { ...blockProps }>
				<InnerBlocks.Content />
			</div>
		);
	},
};
