/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	Notice as CoreNotice,
	ToggleControl,
	ExternalLink,
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import MoneyInput from '../../../components/money-input';
import { Button, Divider, Grid, Notice, SectionHeader, TextControl } from '../../../../../../packages/components/src';
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

export const DonationAmounts = ( { hideHeader = false }: { hideHeader?: boolean } = {} ) => {
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
			{ ! hideHeader && (
				<SectionHeader
					title={ __( 'Suggested Donations', 'newspack-plugin' ) }
					description={ __(
						'Set suggested donation amounts. These will be the default settings for the Donate block.',
						'newspack-plugin'
					) }
					noMargin
				/>
			) }
			{ canUseNameYourPrice && (
				<ToggleGroupControl
					label={ __( 'Donation Type', 'newspack-plugin' ) }
					value={ tiered ? 'tiered' : 'untiered' }
					onChange={ value => changeHandler( [ 'tiered' ] )( 'tiered' === value ) }
					hideLabelFromVision
					isBlock
					__next40pxDefaultSize
				>
					<ToggleGroupControlOption label={ __( 'Tiered', 'newspack-plugin' ) } value="tiered" />
					<ToggleGroupControlOption label={ __( 'Untiered', 'newspack-plugin' ) } value="untiered" />
				</ToggleGroupControl>
			) }
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
			<VStack spacing={ 6 }>
				{ availableFrequencies.map( section => {
					const isFrequencyDisabled = disabledFrequencies[ section.key ];
					const isOneFrequencyActive = Object.values( disabledFrequencies ).filter( Boolean ).length === FREQUENCY_SLUGS.length - 1;
					const renderAmountInput = ( index: number, label: string ) => (
						<MoneyInput
							currencySymbol={ currencySymbol }
							label={ label }
							error={
								amounts[ section.key ][ index ] < minimumDonationFloat
									? __( 'Warning: suggested donations should be at least the minimum donation amount.', 'newspack-plugin' )
									: null
							}
							value={ amounts[ section.key ][ index ] }
							min={ minimumDonationFloat }
							onChange={ changeHandler( [ 'amounts', section.key, index ] ) }
							key={ `${ section.key }-${ index }` }
						/>
					);
					return (
						<VStack spacing={ 4 } key={ section.key }>
							<ToggleControl
								checked={ ! isFrequencyDisabled }
								onChange={ () => changeHandler( [ 'disabledFrequencies', section.key ] )( ! isFrequencyDisabled ) }
								label={ section.tieredLabel }
								disabled={ ! isFrequencyDisabled && isOneFrequencyActive }
							/>
							{ ! isFrequencyDisabled &&
								( tiered ? (
									<Grid columns={ 3 } gutter={ 16 } noMargin>
										{ renderAmountInput( 0, __( 'Low-tier' ) ) }
										{ renderAmountInput( 1, __( 'Mid-tier' ) ) }
										{ renderAmountInput( 2, __( 'High-tier' ) ) }
									</Grid>
								) : (
									renderAmountInput( 3, section.staticLabel )
								) ) }
						</VStack>
					);
				} ) }
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
					withMargin={ false }
				/>
			</VStack>
		</>
	);
};

const Donation = () => {
	const wizardData = useWizardData( AUDIENCE_DONATIONS_WIZARD_SLUG ) as AudienceDonationsWizardData;
	const { saveWizardSettings, setHeaderData, resetHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );
	const onSaveDonationSettings = () =>
		saveWizardSettings( {
			slug: AUDIENCE_DONATIONS_WIZARD_SLUG,
			payloadPath: [ 'donation_data' ],
			auxData: { saveDonationProduct: true },
		} );

	useEffect( () => {
		setHeaderData( { actions: [ { type: 'primary', label: __( 'Save', 'newspack-plugin' ), action: onSaveDonationSettings } ] } );
		return () => resetHeaderData();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Check for product validation errors.
	const validationResults = Object.values( wizardData.product_validation || {} );
	const hasInvalidProducts = validationResults.some( product => product.issues.length > 0 );

	const hasDonationData = wizardData.donation_data && ! ( 'errors' in wizardData.donation_data );

	return (
		<WizardsTab>
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
					<Grid columns={ 2 } gutter={ 32 } noMargin>
						<SectionHeader
							heading={ 2 }
							title={ __( 'Donations Landing Page', 'newspack-plugin' ) }
							description={ __( 'Manage the landing page for your donations.', 'newspack-plugin' ) }
							noMargin
						/>
						<VStack spacing={ 6 }>
							{ 'publish' === wizardData.donation_page.status ? (
								<CoreNotice status="success" isDismissible={ false }>
									{ __( 'Your donations landing page is published.', 'newspack-plugin' ) }
								</CoreNotice>
							) : (
								<CoreNotice status="warning" isDismissible={ false }>
									{ __( 'Your donations landing page is not yet published.', 'newspack-plugin' ) }
								</CoreNotice>
							) }
							<div>
								<Button
									variant="secondary"
									size="compact"
									href={ wizardData.donation_page.editUrl }
									aria-label={ __( 'Edit donations landing page', 'newspack-plugin' ) }
								>
									{ __( 'Edit Page' ) }
								</Button>
							</div>
						</VStack>
					</Grid>
					{ hasDonationData && <Divider alignment="full-width" variant="tertiary" /> }
				</>
			) }

			{ hasDonationData && (
				<Grid columns={ 2 } gutter={ 32 } noMargin>
					<SectionHeader
						heading={ 2 }
						title={ __( 'Suggested Donations', 'newspack-plugin' ) }
						description={ __(
							'Set suggested donation amounts. These will be the default settings for the Donate block.',
							'newspack-plugin'
						) }
						noMargin
					/>
					<VStack spacing={ 6 }>
						<DonationAmounts hideHeader />
					</VStack>
				</Grid>
			) }
		</WizardsTab>
	);
};

export default Donation;
