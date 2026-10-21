/**
 * Newspack > Settings > Advanced Settings > Recirculation
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { Notice } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Button, TextControl } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

type RecirculationProps = ThemeModComponentProps< Recirculation > & {
	requestLeave: ( callback: () => void ) => void;
	allowNextUnload: () => void;
};

export default function Recirculation( { data, update, isFetching, requestLeave, allowNextUnload }: RecirculationProps ) {
	const { addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ isLeaving, setIsLeaving ] = useState( false );

	// Copies the Handoff component's request so it can wait for the unsaved-changes dialog.
	const goToJetpack = () => {
		setIsLeaving( true );
		// Cleared up front so a repeat failure mounts a fresh snackbar that is announced again.
		removeNotice( 'advanced-settings-jetpack-handoff-error' );
		apiFetch< { HandoffLink: string } >( {
			path: '/newspack/v1/handoff',
			method: 'POST',
			data: {
				destinationUrl: 'admin.php?page=jetpack#/traffic',
				handoffReturnUrl: window.location.href,
				bannerText: __( 'Configure related posts, then return to Newspack.', 'newspack-plugin' ),
			},
		} )
			.then( response => {
				allowNextUnload();
				window.location.href = response.HandoffLink;
			} )
			.catch( ( error: { message?: string } ) => {
				setIsLeaving( false );
				addNotice( {
					id: 'advanced-settings-jetpack-handoff-error',
					type: 'error',
					message: decodeEntities( error?.message || __( 'Could not open Jetpack. Please try again.', 'newspack-plugin' ) ),
				} );
			} );
	};

	return (
		<>
			{ data.relatedPostsEnabled ? (
				<TextControl
					withMargin={ false }
					help={ __(
						'If set, posts will be shown as related content only if published within the past number of months. If 0, any published post can be shown, regardless of publish date.',
						'newspack-plugin'
					) }
					label={ __( 'Maximum age of related content, in months', 'newspack-plugin' ) }
					onChange={ ( relatedPostsMaxAge: number ) => update( { relatedPostsMaxAge } ) }
					type="number"
					min={ 0 }
					disabled={ isFetching }
					value={ data.relatedPostsMaxAge || 0 }
				/>
			) : (
				! isFetching && (
					<Notice status="warning" isDismissible={ false } spokenMessage="">
						{ __( 'Related posts are turned off. Turn them on in Jetpack to show related content under each post.', 'newspack-plugin' ) }
					</Notice>
				)
			) }
			<Stack direction="row">
				<Button
					variant="secondary"
					loading={ isLeaving }
					disabled={ isLeaving }
					accessibleWhenDisabled
					onClick={ () => requestLeave( goToJetpack ) }
				>
					{ __( 'Configure in Jetpack', 'newspack-plugin' ) }
				</Button>
			</Stack>
		</>
	);
}
