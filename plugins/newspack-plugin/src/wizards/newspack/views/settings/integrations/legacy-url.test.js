/**
 * Internal dependencies
 */
import { rewriteLegacyIntegrationsUrl } from './legacy-url';

const PATH = '/wp-admin/admin.php';

describe( 'rewriteLegacyIntegrationsUrl', () => {
	it( 'maps an old integration route onto the Settings tab', () => {
		expect( rewriteLegacyIntegrationsUrl( PATH, '?page=newspack-settings&legacy-integrations=1', '#/settings/salesforce' ) ).toBe(
			`${ PATH }?page=newspack-settings#/integrations/salesforce`
		);
	} );

	it( 'keeps a query inside the fragment', () => {
		expect( rewriteLegacyIntegrationsUrl( PATH, '?page=newspack-settings&legacy-integrations=1', '#/settings/esp/logs?status=failed' ) ).toBe(
			`${ PATH }?page=newspack-settings#/integrations/esp/logs?status=failed`
		);
	} );

	it( 'opens the list when there is no fragment or it belongs to another route', () => {
		expect( rewriteLegacyIntegrationsUrl( PATH, '?page=newspack-settings&legacy-integrations=1', '' ) ).toBe(
			`${ PATH }?page=newspack-settings#/integrations`
		);
		expect( rewriteLegacyIntegrationsUrl( PATH, '?page=newspack-settings&legacy-integrations=1', '#/settingsfoo' ) ).toBe(
			`${ PATH }?page=newspack-settings#/integrations`
		);
	} );

	it( 'leaves a request that did not come from the old page alone', () => {
		expect( rewriteLegacyIntegrationsUrl( PATH, '?page=newspack-settings', '#/settings/salesforce' ) ).toBeNull();
	} );
} );
