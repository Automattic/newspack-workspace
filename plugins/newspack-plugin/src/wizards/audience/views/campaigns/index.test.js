/**
 * Campaigns view: a refused prompt save rejects to the settings drawer, which
 * shows the error itself, and releases the busy state so Save works again.
 */

// Exercise the class itself rather than the wizard shell around it.
jest.mock( '../../../../../packages/components/src/with-wizard', () => ( {
	__esModule: true,
	default: Component => Component,
} ) );

import AudienceCampaigns from './index';

describe( 'AudienceCampaigns updatePopup', () => {
	beforeAll( () => {
		global.newspackAudienceCampaigns = { api: '/newspack/v1/wizard/newspack-audience-campaigns' };
	} );
	afterAll( () => {
		delete global.newspackAudienceCampaigns;
	} );

	const setUp = wizardApiFetch => {
		const setError = jest.fn();
		const view = new AudienceCampaigns( { wizardApiFetch, setError } );
		view.setState = jest.fn();
		return { view, setError };
	};

	it( 'rejects with the server error and clears the busy state when the save is refused', async () => {
		const refusal = { code: 'refused', message: 'Not allowed.' };
		const { view, setError } = setUp( jest.fn().mockRejectedValue( refusal ) );

		await expect( view.updatePopup( { id: 7, options: { placement: 'manual' } } ) ).rejects.toBe( refusal );

		expect( view.setState ).toHaveBeenNthCalledWith( 1, { inFlight: true } );
		expect( view.setState ).toHaveBeenLastCalledWith( { inFlight: false } );
		expect( setError ).not.toHaveBeenCalled();
	} );
} );
