/**
 * Ad Unit Management Screens.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';
import { arrowLeft } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { ActionCard, Button, Card, Notice, SelectControl, TextControl, withWizardScreen } from '../../../../../packages/components/src';
import ServiceAccountConnection from './service-account-connection';
import OptionsPopover from './options-popover';

const CREATE_AD_ID_PARAM = 'create';

/**
 * Advertising management screen.
 */
const AdUnits = ( { adUnits, parentAdUnits, onDelete, wizardApiFetch, updateWithAPI, service, serviceData, fetchAdvertisingData } ) => {
	const gamErrorMessage = serviceData?.status?.error
		? `${ __( 'Google Ad Manager Error', 'newspack-plugin' ) }: ${ serviceData.status.error }`
		: false;

	const updateNetworkCode = async ( value, isGam ) => {
		await wizardApiFetch( {
			path: '/newspack/v1/wizard/billboard/network_code/',
			method: 'POST',
			data: { network_code: value, is_gam: isGam },
			quiet: true,
		} );
		fetchAdvertisingData( true );
	};

	const updateGAMConfiguration = async () => {
		await updateWithAPI( {
			path: '/newspack/v1/wizard/billboard/gam/',
			method: 'POST',
			data: { network_code: networkCode, parent_network_code: parentNetworkCode, parent_ad_unit_id: parentAdUnitId },
		} );
	};

	const [ networkCode, setNetworkCode ] = useState( serviceData.status.network_code );
	const [ parentNetworkCode, setParentNetworkCode ] = useState( serviceData.parent_network_code );
	const updateLegacyNetworkCode = async () => {
		updateNetworkCode( networkCode, false );
	};
	const [ parentAdUnitId, setParentAdUnitId ] = useState( serviceData.parent_ad_unit_id );

	useEffect( () => {
		setNetworkCode( serviceData.status.network_code );
	}, [ serviceData.status.network_code ] );

	useEffect( () => {
		setParentNetworkCode( serviceData.parent_network_code );
	}, [ serviceData.parent_network_code ] );

	useEffect( () => {
		setParentAdUnitId( serviceData.parent_ad_unit_id );
	}, [ serviceData.parent_ad_unit_id ] );

	const { connection_mode } = serviceData.status;
	const isLegacy = 'legacy' === connection_mode;

	const isDisconnectedGAM = adUnit => {
		return ! adUnit.is_default && ! adUnit.is_legacy && isLegacy;
	};

	const canEdit = adUnit => {
		return ! adUnit.is_default && ! isDisconnectedGAM( adUnit );
	};

	const getCodeValue = adUnit => {
		const { code, path } = adUnit;
		if ( isLegacy ) {
			return code;
		} else if ( ! path?.length ) {
			return code;
		}
		return `${ path.map( parent => parent.code ).join( '/' ) }/${ code }`;
	};

	const missingParentAdUnit = parentAdUnitId && ! parentAdUnits?.find( adUnit => adUnit.id === parentAdUnitId );

	const parentAdUnitOptions = [
		{ label: __( 'None (all ad units will be used)', 'newspack-plugin' ), value: '' },
		...( parentAdUnits || [] ).map( adUnit => ( {
			label: adUnit.name,
			value: adUnit.id,
		} ) ),
	];

	if ( missingParentAdUnit ) {
		parentAdUnitOptions.push( { label: '', value: parentAdUnitId } );
	}

	return (
		<>
			<Card noBorder>
				<Button isLink href="#/" icon={ arrowLeft }>
					{ __( 'Back', 'newspack-plugin' ) }
				</Button>
			</Card>

			{ ! isLegacy && networkCode && (
				<>
					<SelectControl
						label={ __( 'Connected GAM network code', 'newspack-plugin' ) }
						value={ networkCode }
						options={ serviceData.available_networks.map( network => ( {
							label: `${ network.name } (${ network.code })`,
							value: network.code,
						} ) ) }
						onChange={ setNetworkCode }
					/>
					<TextControl
						label={ __( 'Parent network code (optional)', 'newspack-plugin' ) }
						value={ parentNetworkCode }
						onChange={ setParentNetworkCode }
					/>
					{ parentAdUnits.length > 0 && (
						<SelectControl
							label={ __( 'Set parent ad unit for the site inventory', 'newspack-plugin' ) }
							value={ parentAdUnitId }
							options={ parentAdUnitOptions }
							onChange={ setParentAdUnitId }
						/>
					) }
					<Card headerActions noBorder className="mt16">
						<div className="flex justify-end w-100">
							<Button variant="primary" onClick={ updateGAMConfiguration }>
								{ __( 'Save', 'newspack-plugin' ) }
							</Button>
						</div>
					</Card>
					<hr />
				</>
			) }
			{ missingParentAdUnit && (
				<Notice
					noticeText={ __(
						'The current parent ad unit is inactive or archived. Please select a different parent ad unit.',
						'newspack-plugin'
					) }
					isError
				/>
			) }
			{ false === serviceData.status?.is_network_code_matched && (
				<Notice
					noticeText={ __(
						'Your GAM network code is different than the network code the site was configured with. Legacy ad units are likely to not load.',
						'newspack-plugin'
					) }
					isWarning
				/>
			) }
			{ gamErrorMessage && <Notice noticeText={ gamErrorMessage } isError /> }
			{ serviceData.created_targeting_keys?.length > 0 && (
				<Notice
					noticeText={ [
						__( 'Created custom targeting keys:', 'newspack-plugin' ) + '\u00A0',
						serviceData.created_targeting_keys.join( ', ' ) + '. \u00A0',
						<ExternalLink
							href={ `https://admanager.google.com/${ serviceData.network_code }#inventory/custom_targeting/list` }
							key="google-ad-manager-custom-targeting-link"
						>
							{ __( 'Visit your GAM dashboard', 'newspack-plugin' ) }
						</ExternalLink>,
					] }
					isSuccess
				/>
			) }
			{ isLegacy && serviceData.enabled && (
				<>
					<Notice noticeText={ __( 'Currently operating in legacy mode.', 'newspack-plugin' ) } isWarning />
					<div className="flex items-end">
						<TextControl
							label={ __( 'Network Code', 'newspack-plugin' ) }
							value={ networkCode }
							onChange={ setNetworkCode }
							withMargin={ false }
						/>
						<span className="pl3">
							<Button onClick={ updateLegacyNetworkCode } isPrimary>
								{ __( 'Save', 'newspack-plugin' ) }
							</Button>
						</span>
					</div>
				</>
			) }
			<p>
				{ __( 'Set up multiple ad units to use on your homepage, articles and other places throughout your site.', 'newspack-plugin' ) }
				<br />
				{ __(
					'You can place ads through our Newspack Ad Block in the Editor, Newspack Ad widget, and using the global placements.',
					'newspack-plugin'
				) }
			</p>
			<Card headerActions noBorder>
				<div className="flex justify-end w-100">
					<Button variant="primary" href={ `#/google_ad_manager/${ CREATE_AD_ID_PARAM }` }>
						{ __( 'Add New Ad Unit', 'newspack-plugin' ) }
					</Button>
				</div>
			</Card>
			<Card noBorder>
				{ Object.values( adUnits )
					.filter( adUnit => adUnit.id !== 0 )
					.sort( ( a, b ) => b.name.localeCompare( a.name ) )
					.sort( a => ( a.is_legacy ? 1 : -1 ) )
					.sort( a => ( a.is_default ? 1 : -1 ) )
					.map( adUnit => {
						const editLink = `#${ service }/${ adUnit.id }`;
						return (
							<ActionCard
								key={ adUnit.id }
								title={ adUnit.name }
								isSmall
								titleLink={ canEdit( adUnit ) && editLink }
								description={ () => (
									<span>
										{ adUnit.code ? (
											<>
												<i>{ __( 'Code:', 'newspack-plugin' ) }</i> <code>{ getCodeValue( adUnit ) }</code>
											</>
										) : null }
										{ adUnit.sizes?.length || adUnit.fluid ? (
											<>
												{ ' ' }
												| { __( 'Sizes:', 'newspack-plugin' ) }{ ' ' }
												{ adUnit.sizes.map( ( size, i ) => (
													<code key={ i }>{ Array.isArray( size ) ? size.join( 'x' ) : size }</code>
												) ) }
												{ adUnit.fluid && <code>{ __( 'Fluid', 'newspack-plugin' ) }</code> }
											</>
										) : null }
										{ adUnit.is_legacy ? (
											<>
												{ ' ' }
												| <i>{ __( 'Legacy ad unit.', 'newspack-plugin' ) }</i>
											</>
										) : null }
										{ adUnit.is_default ? (
											<>
												{ ' ' }
												| <i>{ __( 'Default ad unit.', 'newspack-plugin' ) }</i>
											</>
										) : null }
										{ isDisconnectedGAM( adUnit ) ? (
											<>
												{ ' ' }
												| <i>{ __( 'Disconnected from GAM.', 'newspack-plugin' ) }</i>
											</>
										) : null }
									</span>
								) }
								actionText={
									canEdit( adUnit ) && <OptionsPopover deleteLink={ () => onDelete( adUnit.id ) } editLink={ editLink } />
								}
							/>
						);
					} ) }
			</Card>
			{ ( connection_mode === 'service_account' || isLegacy ) && (
				<ServiceAccountConnection
					updateWithAPI={ updateWithAPI }
					isConnected={ connection_mode === 'service_account' && serviceData.status.connected }
				/>
			) }
		</>
	);
};

export default withWizardScreen( AdUnits );
