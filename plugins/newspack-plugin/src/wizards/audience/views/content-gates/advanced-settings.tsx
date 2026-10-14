/**
 * Content Gate component.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Button, Modal } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../hooks/use-wizard-api-fetch';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from './consts';

// Modes and their labels come from PHP, where the same list backs the REST
// schema's enum and the storage sanitizer.
const feedRestrictionModes = window.newspackAudienceContentGates?.feed_restriction_modes || [];
// Truthy check because wp_localize_script() delivers this as '1'/'' rather than a boolean.
const governedByMemberships = !! window.newspackAudienceContentGates?.feeds_governed_by_memberships;
const institutionalAccessDefaultText = window.newspackAudienceContentGates?.institutional_access_default_text || '';
const commentRestrictionDefaultMessage = window.newspackAudienceContentGates?.comment_restriction_default_message || '';

/**
 * Options for the gate whose readers may comment. A saved gate that is no longer
 * published still gets an option, so the select shows what is saved rather than
 * reading as "anyone"; until it is fixed, nobody but staff can comment.
 */
const getCommentGateOptions = ( gates: Gate[], selectedGateId: number ) => {
	const options = [
		{ value: '0', label: __( 'Anyone the Discussion Settings allow', 'newspack-plugin' ) },
		...gates
			// Only a published gate decides who comments, as only a published gate restricts content.
			.filter( gate => gate.status === 'publish' )
			.map( gate => ( {
				value: String( gate.id ),
				label: decodeEntities( gate.title ) || `#${ gate.id }`,
			} ) ),
	];
	if ( selectedGateId && ! options.some( option => option.value === String( selectedGateId ) ) ) {
		options.push( {
			value: String( selectedGateId ),
			/* translators: %d: gate ID. */
			label: sprintf( __( 'Unpublished or deleted gate #%d (no reader can comment)', 'newspack-plugin' ), selectedGateId ),
		} );
	}
	return options;
};

const AdvancedSettings = ( { closeModal, showModal }: { closeModal: () => void; showModal: boolean } ) => {
	const wizardData = useWizardData( AUDIENCE_CONTENT_GATES_WIZARD_SLUG ) as ContentGatesWizardData;
	const initialConfig = {
		...( wizardData?.config?.advanced_settings || {} ),
	};
	const { wizardApiFetch, isFetching, resetError } = useWizardApiFetch( AUDIENCE_CONTENT_GATES_WIZARD_SLUG );
	const { addNotice, resetNotices, updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ config, setConfig ] = useState< Partial< AdvancedSettingsConfig > >( initialConfig );

	useEffect( () => {
		if ( showModal ) {
			setConfig( initialConfig );
		}
	}, [ showModal ] );

	const updateConfig = useRef< ( _config: Partial< AdvancedSettingsConfig > ) => void >( undefined );
	const handleUpdateConfig = ( _config: Partial< AdvancedSettingsConfig > ) => {
		if ( isFetching ) {
			return;
		}
		resetError();
		resetNotices();
		wizardApiFetch< AdvancedSettingsConfig >(
			{
				path: `/newspack/v1/wizard/${ AUDIENCE_CONTENT_GATES_WIZARD_SLUG }/settings`,
				method: 'POST',
				data: {
					advanced_settings: _config,
				},
			},
			{
				onSuccess: ( data: AdvancedSettingsConfig ) => {
					setConfig( _config );
					updateWizardSettings( {
						slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
						path: [ 'config', 'advanced_settings' ],
						value: data,
					} );
					addNotice( {
						message: __( 'Settings updated.', 'newspack-plugin' ),
						type: 'success',
						id: 'content-gates-advanced-settings-updated',
						actions: [
							{
								label: __( 'Undo', 'newspack-plugin' ),
								onClick: () => updateConfig.current?.( initialConfig ),
							},
						],
					} );
				},
				onError: ( fetchError: WpFetchError ) => {
					addNotice( {
						message: decodeEntities( fetchError.message ),
						type: 'error',
						id: 'content-gates-advanced-settings-error',
					} );
				},
				onFinally: () => {
					closeModal();
				},
			}
		);
	};

	updateConfig.current = handleUpdateConfig;
	return (
		showModal && (
			<Modal onClose={ closeModal } size="medium" title={ __( 'Advanced Settings', 'newspack-plugin' ) } onRequestClose={ closeModal }>
				<VStack>
					{ governedByMemberships && (
						<Notice status="warning" isDismissible={ false } spokenMessage="">
							{ __(
								'WooCommerce Memberships is active on this site, so the feed and commenting settings have no effect yet. What is saved here applies once Memberships is deactivated.',
								'newspack-plugin'
							) }
						</Notice>
					) }
					<VStack>
						<ToggleControl
							label={ __( 'Restrict content in feeds', 'newspack-plugin' ) }
							help={ __( 'Apply gate restrictions to articles in RSS feeds.', 'newspack-plugin' ) }
							checked={ config?.restrict_feeds }
							onChange={ value => setConfig( { ...config, restrict_feeds: value } ) }
						/>
						{ config?.restrict_feeds && feedRestrictionModes.length > 0 && (
							<SelectControl
								label={ __( 'Restricted articles in feeds', 'newspack-plugin' ) }
								help={ __( 'The teaser is the same free preview readers see on the site.', 'newspack-plugin' ) }
								value={ config?.feed_restriction_mode || feedRestrictionModes[ 0 ].value }
								options={ feedRestrictionModes }
								onChange={ ( value: string ) => setConfig( { ...config, feed_restriction_mode: value as FeedRestrictionMode } ) }
							/>
						) }
					</VStack>
					{ wizardData?.config?.has_newsletters && (
						<ToggleControl
							label={ __( 'Bypass restrictions for newsletter links', 'newspack-plugin' ) }
							help={ __(
								'Inbound traffic from newsletters sent via Newspack Newsletters in the past 30 days can bypass Access Control restrictions for one hour.',
								'newspack-plugin'
							) }
							checked={ config?.newsletter_link_bypass_enabled }
							onChange={ value =>
								setConfig( {
									...config,
									newsletter_link_bypass_enabled: value,
								} )
							}
						/>
					) }
					{ wizardData?.config?.has_institutions && (
						<TextControl
							label={ __( 'Institutional access link text', 'newspack-plugin' ) }
							help={ __(
								'Shown to logged-out readers below the gate when an institution with an IP range can unlock it. The link checks whether the reader is on that network. Leave empty to use the default.',
								'newspack-plugin'
							) }
							placeholder={ institutionalAccessDefaultText }
							maxLength={ 200 }
							value={ config?.institutional_access_text || '' }
							onChange={ ( value: string ) => setConfig( { ...config, institutional_access_text: value } ) }
						/>
					) }
					<VStack>
						<SelectControl
							label={ __( 'Who can comment', 'newspack-plugin' ) }
							help={ __(
								'Limit commenting on every post to readers with access through a gate. Everyone else sees the message below instead of the comment form.',
								'newspack-plugin'
							) }
							value={ String( config?.comment_restriction_gate_id || 0 ) }
							options={ getCommentGateOptions( wizardData?.gates || [], config?.comment_restriction_gate_id || 0 ) }
							onChange={ ( value: string ) => setConfig( { ...config, comment_restriction_gate_id: parseInt( value, 10 ) || 0 } ) }
						/>
						{ !! config?.comment_restriction_gate_id && (
							<>
								<TextControl
									label={ __( 'Message for readers who cannot comment', 'newspack-plugin' ) }
									help={ __( 'Leave empty to use the default.', 'newspack-plugin' ) }
									placeholder={ commentRestrictionDefaultMessage }
									maxLength={ 200 }
									value={ config?.comment_restriction_message || '' }
									onChange={ ( value: string ) => setConfig( { ...config, comment_restriction_message: value } ) }
								/>
								<TextControl
									label={ __( 'Subscribe link', 'newspack-plugin' ) }
									help={ __( 'Shown after the message as "Subscribe now". Leave empty to show no link.', 'newspack-plugin' ) }
									type="url"
									value={ config?.comment_restriction_purchase_url || '' }
									onChange={ ( value: string ) => setConfig( { ...config, comment_restriction_purchase_url: value } ) }
								/>
							</>
						) }
					</VStack>
					<HStack justify="end">
						<Button variant="tertiary" disabled={ isFetching } onClick={ closeModal }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button
							variant="primary"
							disabled={ isFetching || JSON.stringify( wizardData?.config?.advanced_settings || {} ) === JSON.stringify( config ) }
							loading={ isFetching }
							onClick={ () => updateConfig.current?.( config ) }
						>
							{ __( 'Save', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Modal>
		)
	);
};

export default AdvancedSettings;
