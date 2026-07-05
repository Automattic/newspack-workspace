/**
 * WordPress dependencies
 */
import { Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import { corrections as icon } from '../../../packages/icons';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

export const title = __( 'Correction Item', 'newspack-plugin' );

const EditComponent = ( { context: { postType } } ) => {
	if ( 'newspack_correction' !== postType ) {
		return (
			<Placeholder
				icon={ icon }
				label={ __( 'Corrections & Clarifications', 'newspack-plugin' ) }
				instructions={ __( 'Please select "Corrections" as the post type in the Query Loop to use this block.', 'newspack-plugin' ) }
			/>
		);
	}

	return (
		<>
			<div className="correction__item">
				<strong className="correction__item-title">{ __( 'Correction Type, Date, and Time: ', 'newspack-plugin' ) }</strong>
				<span className="correction__item-content">
					{ __(
						'This is where the content will appear, providing details about the update, whether correcting an error or offering additional context.',
						'newspack-plugin'
					) }
				</span>
			</div>
			<a className="correction__post-link" href="/#" style={ { pointerEvents: 'none' } }>
				{ __( 'Post Title', 'newspack-plugin' ) }
			</a>
		</>
	);
};

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	description: __( 'Display an archive of all the corrections and clarifications.', 'newspack-plugin' ),
	usesContext: [ 'postType' ],
	edit: EditComponent,
};
