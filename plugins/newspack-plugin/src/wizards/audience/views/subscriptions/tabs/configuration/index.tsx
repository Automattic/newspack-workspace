/**
 * The Subscriptions wizard's Configuration tab: site-wide subscription
 * settings.
 */

/**
 * WordPress dependencies.
 */
import { sprintf, __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect } from '@wordpress/element';
import { ExternalLink, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button, Card, SelectControl, Notice } from '../../../../../../../packages/components/src';
import WizardsTab from '../../../../../wizards-tab';
import WizardSection from '../../../../../wizards-section';
import { registerTab } from '../registry';
import { WIZARD_ENDPOINT } from '../../constants';

function Configuration() {
	const [ inFlight, setInFlight ] = useState( false );
	const [ primaryProduct, setPrimaryProduct ] = useState( window.newspackAudienceSubscriptions.primary_product );

	useEffect( () => {
		setPrimaryProduct( window.newspackAudienceSubscriptions.primary_product );
	}, [ window.newspackAudienceSubscriptions.primary_product ] );

	const handlePrimaryProductChange = ( value: string ) => {
		setInFlight( true );
		apiFetch( {
			path: `${ WIZARD_ENDPOINT }/primary-product`,
			method: 'POST',
			data: { primary_product: value },
		} )
			.then( () => {
				setPrimaryProduct( value );
			} )
			.finally( () => {
				setInFlight( false );
			} );
	};

	return (
		<WizardsTab title={ __( 'Configuration', 'newspack-plugin' ) }>
			<WizardSection>
				<Card>
					<h2>{ __( 'Subscription Upgrade Link', 'newspack-plugin' ) }</h2>
					{ primaryProduct && (
						<Notice isDismissible={ false }>
							{ __( 'Share the following URL to trigger the subscription upgrade:', 'newspack-plugin' ) }{ ' ' }
							<a href={ window.newspackAudienceSubscriptions.upgrade_subscription_url } target="_blank" rel="noreferrer noopener">
								{ window.newspackAudienceSubscriptions.upgrade_subscription_url }
							</a>
						</Notice>
					) }
					<SelectControl
						label={ __( 'Primary Subscription Product', 'newspack-plugin' ) }
						help={ __(
							'Select a grouped or variable subscription product to allow readers to change their active subscriptions amongst all of its linked products and variations.',
							'newspack-plugin'
						) }
						options={ [
							{
								value: '',
								label: __( 'Select a product…', 'newspack-plugin' ),
							},
							...window.newspackAudienceSubscriptions.eligible_products.map( product => ( {
								value: product.id,
								label: product.title,
							} ) ),
						] }
						value={ primaryProduct }
						onChange={ handlePrimaryProductChange }
						disabled={ inFlight }
					/>
					{ primaryProduct ? (
						<HStack>
							<p>
								<Button variant="link" onClick={ () => handlePrimaryProductChange( '' ) }>
									{ __( 'Reset primary product', 'newspack-plugin' ) }
								</Button>{ ' ' }
							</p>
							<p>
								<ExternalLink href={ `/wp-admin/post.php?post=${ primaryProduct }&action=edit` }>
									{ sprintf(
										/* translators: %s: product title */
										__( 'Edit %s', 'newspack-plugin' ),
										window.newspackAudienceSubscriptions.eligible_products.find(
											product => parseInt( product.id ) === parseInt( primaryProduct )
										)?.title || __( 'the product', 'newspack-plugin' )
									) }
								</ExternalLink>
							</p>
						</HStack>
					) : null }
				</Card>
				{ /* Only meaningful while Memberships is still installed; a migrated site has no such screen. */ }
				{ window.newspackAudienceSubscriptions.memberships_active && (
					<Card>
						<h2>{ __( 'Manage Subscriptions settings in Woo Memberships', 'newspack-plugin' ) }</h2>
						<p>{ __( 'You can manage the details of your subscription offerings in the Woo Memberships plugin.', 'newspack-plugin' ) }</p>
						<Button variant="primary" href={ window.newspackAudienceSubscriptions.memberships_url }>
							{ __( 'Manage Subscriptions', 'newspack-plugin' ) }
						</Button>
					</Card>
				) }
			</WizardSection>
		</WizardsTab>
	);
}

registerTab( 'configuration', { render: () => <Configuration /> } );
