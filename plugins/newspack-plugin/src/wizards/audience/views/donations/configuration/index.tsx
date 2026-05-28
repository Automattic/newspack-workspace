/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { ToggleControl, ExternalLink } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import MoneyInput from '../../../components/money-input';
import { Button, Card, Grid, Notice, SectionHeader, SelectControl, TextControl } from '../../../../../../packages/components/src';
import { useWizardData } from '../../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import WizardsTab from '../../../../wizards-tab';
import { AUDIENCE_DONATIONS_WIZARD_SLUG } from '../../../constants';

type FrequencySlug = 'once' | 'month' | 'year';

const FREQUENCIES: Record<
	FrequencySlug,
	{
		tieredLabel: string;
		staticLabel: string;
	}
> = {
	once: {
		tieredLabel: __( 'One-time donations' ),
		staticLabel: __( 'Suggested one-time donation amount' ),
	},
	month: {
		tieredLabel: __( 'Monthly donations' ),
		staticLabel: __( 'Suggested donation amount per month' ),
	},
	year: {
		tieredLabel: __( 'Annual donations' ),
		staticLabel: __( 'Suggested donation amount per year' ),
	},
};
const FREQUENCY_SLUGS: FrequencySlug[] = Object.keys( FREQUENCIES ) as FrequencySlug[];

export const DonationAmounts = () => {
	const wizardData = useWizardData( AUDIENCE_DONATIONS_WIZARD_SLUG ) as AudienceDonationsWizardData;
	const { updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );

	if ( ! wizardData.donation_data || 'errors' in wizardData.donation_data ) {
		return null;
	}

	const { amounts, currencySymbol, tiered, disabledFrequencies, minimumDonation, trashed } = wizardData.donation_data;

	const changeHandler = ( path: ( string | number )[] ) => ( value: any ) =>
		updateWizardSettings( {
			slug: AUDIENCE_DONATIONS_WIZARD_SLUG,
			path: [ 'donation_data', ...path ],
			value,
		} );

	const availableFrequencies = FREQUENCY_SLUGS.map( slug => ( {
		key: slug,
		...FREQUENCIES[ slug ],
	} ) );

	// Minimum donation is returned by the REST API as a string.
	const minimumDonationFloat = parseFloat( minimumDonation );

	// Whether we can use the Name Your Price extension. If not, layout is forced to Tiered.
	const canUseNameYourPrice = window.newspackAudienceDonations?.can_use_name_your_price;

	return (
		<>
			<Card headerActions noBorder>
				<SectionHeader
					title={ __( 'Suggested Donations', 'newspack-plugin' ) }
					description={ __(
						'Set suggested donation amounts. These will be the default settings for the Donate block.',
						'newspack-plugin'
					) }
					noMargin
				/>
				{ canUseNameYourPrice && (
					<SelectControl
						label={ __( 'Donation Type', 'newspack-plugin' ) }
						onChange={ () => changeHandler( [ 'tiered' ] )( ! tiered ) }
						buttonOptions={ [
							{
								value: true,
								label: __( 'Tiered', 'newspack-plugin' ),
							},
							{
								value: false,
								label: __( 'Untiered', 'newspack-plugin' ),
							},
						] }
						buttonSmall
						value={ tiered }
						hideLabelFromVision
					/>
				) }
			</Card>
			{ Array.isArray( trashed ) && 0 < trashed.length && (
				<Notice isError>
					{
						<span
							dangerouslySetInnerHTML={ {
								__html: sprintf(
									// Translators: %1$s is a link to the trashed products. %2$s is a comma-separated list of trashed product names.
									__(
										'One or more donation products is in trash. Please <a href="%1$s">restore the product(s)</a> to continue using donation features: %2$s',
										'newspack-plugin'
									),
									'/wp-admin/edit.php?post_status=trash&post_type=product',
									trashed.join( ', ' )
								),
							} }
						/>
					}
				</Notice>
			) }
			{ tiered ? (
				<Grid columns={ 1 }>
					{ availableFrequencies.map( section => {
						const isFrequencyDisabled = disabledFrequencies[ section.key ];
						const isOneFrequencyActive = Object.values( disabledFrequencies ).filter( Boolean ).length === FREQUENCY_SLUGS.length - 1;
						return (
							<Card noBorder key={ section.key }>
								<Grid columns={ 1 } gutter={ 8 }>
									<ToggleControl
										checked={ ! isFrequencyDisabled }
										onChange={ () => changeHandler( [ 'disabledFrequencies', section.key ] )( ! isFrequencyDisabled ) }
										label={ section.tieredLabel }
										disabled={ ! isFrequencyDisabled && isOneFrequencyActive }
									/>
									{ ! isFrequencyDisabled && (
										<Grid columns={ 3 } rowGap={ 16 }>
											<MoneyInput
												currencySymbol={ currencySymbol }
												label={ __( 'Low-tier' ) }
												error={
													amounts[ section.key ][ 0 ] < minimumDonationFloat
														? __(
																'Warning: suggested donations should be at least the minimum donation amount.',
																'newspack-plugin'
														  )
														: null
												}
												value={ amounts[ section.key ][ 0 ] }
												min={ minimumDonationFloat }
												onChange={ changeHandler( [ 'amounts', section.key, 0 ] ) }
											/>
											<MoneyInput
												currencySymbol={ currencySymbol }
												label={ __( 'Mid-tier' ) }
												error={
													amounts[ section.key ][ 1 ] < minimumDonationFloat
														? __(
																'Warning: suggested donations should be at least the minimum donation amount.',
																'newspack-plugin'
														  )
														: null
												}
												value={ amounts[ section.key ][ 1 ] }
												min={ minimumDonationFloat }
												onChange={ changeHandler( [ 'amounts', section.key, 1 ] ) }
											/>
											<MoneyInput
												currencySymbol={ currencySymbol }
												label={ __( 'High-tier' ) }
												error={
													amounts[ section.key ][ 2 ] < minimumDonationFloat
														? __(
																'Warning: suggested donations should be at least the minimum donation amount.',
																'newspack-plugin'
														  )
														: null
												}
												value={ amounts[ section.key ][ 2 ] }
												min={ minimumDonationFloat }
												onChange={ changeHandler( [ 'amounts', section.key, 2 ] ) }
											/>
										</Grid>
									) }
								</Grid>
							</Card>
						);
					} ) }
				</Grid>
			) : (
				<Grid columns={ 1 }>
					<Card noBorder>
						<Grid columns={ 3 } rowGap={ 16 }>
							{ availableFrequencies.map( section => {
								const isFrequencyDisabled = disabledFrequencies[ section.key ];
								const isOneFrequencyActive =
									Object.values( disabledFrequencies ).filter( Boolean ).length === FREQUENCY_SLUGS.length - 1;
								return (
									<Grid columns={ 1 } gutter={ 16 } key={ section.key }>
										<ToggleControl
											checked={ ! isFrequencyDisabled }
											onChange={ () => changeHandler( [ 'disabledFrequencies', section.key ] )( ! isFrequencyDisabled ) }
											label={ section.tieredLabel }
											disabled={ ! isFrequencyDisabled && isOneFrequencyActive }
										/>
										{ ! isFrequencyDisabled && (
											<MoneyInput
												currencySymbol={ currencySymbol }
												label={ section.staticLabel }
												value={ amounts[ section.key ][ 3 ] }
												min={ minimumDonationFloat }
												error={
													amounts[ section.key ][ 3 ] < minimumDonationFloat
														? __(
																'Warning: suggested donations should be at least the minimum donation amount.',
																'newspack-plugin'
														  )
														: null
												}
												onChange={ changeHandler( [ 'amounts', section.key, 3 ] ) }
												key={ section.key }
											/>
										) }
									</Grid>
								);
							} ) }
						</Grid>
					</Card>
				</Grid>
			) }
			<Grid columns={ 3 }>
				<TextControl
					label={ __( 'Minimum donation', 'newspack-plugin' ) }
					help={ __(
						'Set minimum donation amount. Setting a reasonable minimum donation amount can help protect your site from bot attacks.',
						'newspack-plugin'
					) }
					type="number"
					min={ 1 }
					value={ minimumDonationFloat }
					onChange={ ( value: string ) => changeHandler( [ 'minimumDonation' ] )( value ) }
				/>
			</Grid>
		</>
	);
};

const Donation = () => {
	const wizardData = useWizardData( AUDIENCE_DONATIONS_WIZARD_SLUG ) as AudienceDonationsWizardData;
	const { saveWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const onSaveDonationSettings = () =>
		saveWizardSettings( {
			slug: AUDIENCE_DONATIONS_WIZARD_SLUG,
			payloadPath: [ 'donation_data' ],
			auxData: { saveDonationProduct: true },
		} );

	// Check for product validation errors.
	const validationResults = Object.values( wizardData.product_validation || {} );
	const hasInvalidProducts = validationResults.some( product => product.issues.length > 0 );

	return (
		<WizardsTab title={ __( 'Configuration', 'newspack-plugin' ) }>
			{ /* Display product validation issues */ }
			{ hasInvalidProducts ? (
				<Notice
					isWarning
					noticeText={ __( 'Some donation products are invalid. Please correct the following issues:', 'newspack-plugin' ) }
					style={ { marginBottom: '16px' } }
				>
					<ul style={ { marginTop: '8px', marginBottom: '0' } }>
						{ validationResults.map( ( product: ProductValidation ) => {
							if ( product.issues && product.issues.length > 0 ) {
								return (
									<li key={ product.product_id } style={ { marginBottom: '8px' } }>
										<strong>
											{ product.product_name ||
												sprintf(
													// translators: %d: Product ID.
													__( 'Product ID %d', 'newspack-plugin' ),
													product.product_id
												) }
											{ product.frequency && ` (${ product.frequency })` }:
										</strong>{ ' ' }
										<ExternalLink href={ `/wp-admin/post.php?post=${ product.product_id }&action=edit` }>
											{ __( 'edit', 'newspack-plugin' ) }
										</ExternalLink>
										<ul style={ { marginTop: '4px', marginLeft: '20px' } }>
											{ product.issues.map( ( warning: string, index: number ) => (
												<li key={ index }>{ warning }</li>
											) ) }
										</ul>
									</li>
								);
							}
							return null;
						} ) }
					</ul>
				</Notice>
			) : null }

			{ wizardData.donation_page && (
				<>
					<Card noBorder headerActions>
						<SectionHeader title={ __( 'Donations Landing Page', 'newspack-plugin' ) } noMargin />
						<Button variant="secondary" isSmall href={ wizardData.donation_page.editUrl } onClick={ undefined }>
							{ __( 'Edit Page' ) }
						</Button>
					</Card>
					{ 'publish' === wizardData.donation_page.status ? (
						<Notice
							isSuccess
							noticeText={ __( 'Your donations landing page is published.', 'newspack-plugin' ) }
							style={ { marginBottom: '64px' } }
						/>
					) : (
						<Notice
							isWarning
							noticeText={ __( 'Your donations landing page is not yet published.', 'newspack-plugin' ) }
							style={ { marginBottom: '64px' } }
						/>
					) }
				</>
			) }
			<DonationAmounts />
			<div className="newspack-buttons-card">
				<Button variant="primary" onClick={ onSaveDonationSettings }>
					{ __( 'Save Settings', 'newspack-plugin' ) }
				</Button>
			</div>
		</WizardsTab>
	);
};

export default Donation;
