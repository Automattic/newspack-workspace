/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { compose } from '@wordpress/compose';
import { withDispatch } from '@wordpress/data';
import { TextControl, ToggleControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import {
	connectWithSelect,
	LINK_FIELD_NAME,
	LINK_ORG_FIELD_NAME,
	LINK_SPONSORED_FIELD_NAME,
} from './utils';

const decorate = compose(
	connectWithSelect,
	withDispatch( ( dispatch ) => ( {
		saveLink: ( link ) => {
			console.log( 'saveLink', link );
			dispatch( 'core/editor' ).editPost( {
				meta: {
					[ LINK_FIELD_NAME ]: link,
				},
			} );
		},
		saveOrg: ( org ) => {
			console.log( 'saveOrg', org );
			dispatch( 'core/editor' ).editPost( {
				meta: {
					[ LINK_ORG_FIELD_NAME ]: org,
				},
			} );
		},
		saveSponsored: ( sponsored ) => {
			console.log( 'saveSponsored', sponsored );
			dispatch( 'core/editor' ).editPost( {
				meta: {
					[ LINK_SPONSORED_FIELD_NAME ]: sponsored ? 1 : 0,
				},
			} );
		},
	} ) )
);

const ReleaseEditor = ( {
	link,
	saveLink,
	saveSponsored,
	saveOrg,
	org,
	sponsored,
} ) => {
	console.log( { org, sponsored } );
	return (
		<>
			<TextControl
				label={ __( 'External Link', 'newspack-theme' ) }
				value={ link }
				onChange={ saveLink }
				type={ 'url' }
				style={ { marginTop: '10px', width: '100%' } }
			/>
			<TextControl
				label={ __( 'External Link Org', 'newspack-theme' ) }
				value={ org }
				onChange={ saveOrg }
				style={ { marginTop: '10px', width: '100%' } }
			/>
			<ToggleControl
				label={ __( 'External Link Sponsored', 'newspack-theme' ) }
				checked={ sponsored === 1 }
				onChange={ saveSponsored }
			/>
		</>
	);
};

export default decorate( ReleaseEditor );
