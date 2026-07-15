import { registerBlockType } from '@wordpress/blocks';
import { Edit } from './edit';
import { Save } from './save';

import metadata from './block.json';
import colors from '../../../../../packages/colors/colors.module.scss';

import './style.scss';

registerBlockType( metadata as any, {
	icon: {
		src: 'admin-customizer',
		foreground: colors[ 'primary-400' ],
	},
	edit: Edit,
	save: Save,
} );
