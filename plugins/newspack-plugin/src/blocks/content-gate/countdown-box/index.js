/**
 * WordPress dependencies
 */
import { useInnerBlocksProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import { countdown as icon } from '../../../../packages/icons';
import colors from '../../../../packages/colors/colors.module.scss';

export const title = __( 'Content Gate Countdown', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [
		__( 'countdown', 'newspack-plugin' ),
		__( 'content gate', 'newspack-plugin' ),
		__( 'metered', 'newspack-plugin' ),
		__( 'paywall', 'newspack-plugin' ),
		__( 'tracking', 'newspack-plugin' ),
	],
	description: __(
		'Displays how many free articles remain before the paywall, helping users track access with a real-time countdown.',
		'newspack-plugin'
	),
	edit: Edit,
	save: () => (
		<div
			{ ...useInnerBlocksProps.save( {
				className: 'newspack-content-gate-countdown__content',
			} ) }
		/>
	),
};
