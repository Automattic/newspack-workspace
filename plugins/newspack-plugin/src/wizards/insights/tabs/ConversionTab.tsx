/**
 * ConversionTab
 *
 * Stub. Accepts the standard TabSectionProps so TabContent can spread
 * range/previousRange without a TypeScript strict-mode mismatch.
 * Real content lands in a follow-up issue.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { TabSectionProps } from '../components/TabContent';

// eslint-disable-next-line @typescript-eslint/no-unused-vars
const ConversionTab = ( _props: TabSectionProps ) => (
	<div className="newspack-insights__tab-stub">
		<h2 className="newspack-insights__tab-stub-title">{ __( 'Conversion', 'newspack-plugin' ) }</h2>
		<p className="newspack-insights__tab-stub-message">{ __( 'Coming soon', 'newspack-plugin' ) }</p>
	</div>
);

export default ConversionTab;
