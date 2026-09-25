/**
 * Content Gate component.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import {
	ToggleControl,
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { useDispatch } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Button, Modal, Notice } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../hooks/use-wizard-api-fetch';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from './consts';

// Modes and their labels come from PHP, where the same list backs the REST
// schema's enum and the storage sanitizer.
const feedRestrictionModes = window.newspackAudienceContentGates?.feed_restriction_modes || [];
// Truthy check because wp_localize_script() delivers this as '1'/'' rather than a boolean.
const feedsGovernedByMemberships = !! window.newspackAudienceContentGates?.feeds_governed_by_memberships;

// Not a stored mode: it stands for `restrict_feeds` being off, so one control covers both settings.
const FEED_MODE_OFF = 'off';

const getFeedModeLabel = ( mode: string ) =>
	( {
		[ FEED_MODE_OFF ]: __( 'Full article', 'newspack-plugin' ),
		truncate: __( 'Teaser only', 'newspack-plugin' ),
		exclude: __( 'Remove', 'newspack-plugin' ),
	} )[ mode ];

const getFeedModeHelp = ( mode: string ) =>
	( {
		[ FEED_MODE_OFF ]: __( 'Feeds show restricted articles in full, ignoring your gates.', 'newspack-plugin' ),
		truncate: __( 'Feeds keep restricted articles but show only the teaser, the same free preview readers see on the site.', 'newspack-plugin' ),
		exclude: __( 'Feeds leave restricted articles out.', 'newspack-plugin' ),
	} )[ mode ];

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

	const updateConfig = useRef< ( _config: Partial< AdvancedSettingsConfig > ) => void >();
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
	const feedMode = config?.restrict_feeds ? config?.feed_restriction_mode || feedRestrictionModes[ 0 ]?.value : FEED_MODE_OFF;
	return (
		showModal && (
			<Modal onClose={ closeModal } size="medium" title={ __( 'Advanced Settings', 'newspack-plugin' ) } onRequestClose={ closeModal }>
				<Stack direction="column" gap="xl" className="newspack-content-gates__stack">
					{ /* Grouped so the Memberships notice reads as covering the feed settings only, not the newsletter toggle below. */ }
					<Stack direction="column" gap="xl" className="newspack-content-gates__stack">
						{ feedsGovernedByMemberships && (
							<Notice
								isWarning
								noticeText={ __(
									'WooCommerce Memberships controls RSS feeds on this site, so these feed settings have no effect yet. What is saved here applies once Memberships is deactivated.',
									'newspack-plugin'
								) }
							/>
						) }
						{ feedRestrictionModes.length > 0 && (
							<ToggleGroupControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								isBlock
								label={ __( 'Restricted articles in feeds', 'newspack-plugin' ) }
								help={ getFeedModeHelp( feedMode ) }
								value={ feedMode }
								onChange={ value =>
									setConfig(
										FEED_MODE_OFF === value
											? { ...config, restrict_feeds: false }
											: { ...config, restrict_feeds: true, feed_restriction_mode: value as FeedRestrictionMode }
									)
								}
							>
								{ [ FEED_MODE_OFF, ...feedRestrictionModes.map( ( { value }: { value: string } ) => value ) ].map( mode => (
									<ToggleGroupControlOption key={ mode } value={ mode } label={ getFeedModeLabel( mode ) ?? mode } />
								) ) }
							</ToggleGroupControl>
						) }
					</Stack>
					{ wizardData?.config?.has_newsletters && (
						<ToggleControl
							__nextHasNoMarginBottom
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
					<Stack direction="row" gap="sm" justify="end">
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
					</Stack>
				</Stack>
			</Modal>
		)
	);
};

export default AdvancedSettings;
