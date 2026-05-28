/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { link } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import './style.scss';
import edit from './edit';
import metadata from './block.json';
import { getServiceLabel } from './utils';

const { name } = metadata;

export { name };

export const settings = {
	...metadata,
	title: __( 'Link', 'newspack-plugin' ),
	__experimentalLabel: ( { service } ) => ( service ? getServiceLabel( service ) : __( 'Link', 'newspack-plugin' ) ),
	icon: link,
	edit,
	save: () => null,
};
