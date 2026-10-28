import { parseTiersBasedConfig } from './utils';

describe( 'tiers-based config processing', () => {
	it( 'decodes a tiers-based config', () => {
		expect(
			parseTiersBasedConfig(
				'["donation_frequency","donation_value_","once",[{"heading":"Small"},{"heading":"Medium"},{"heading":"Large"}],{"once":[9,20,90,20],"month":[7,15,30,15],"year":[84,180,360,180]},"February 09, 2024","January 09, 2025","#dd3333"]'
			)
		).toEqual( {
			amounts: {
				month: [ 7, 15, 30, 15 ],
				once: [ 9, 20, 90, 20 ],
				year: [ 84, 180, 360, 180 ],
			},
			buttonColor: '#dd3333',
			initialFrequency: 'once',
			params: {
				frequency: 'donation_frequency',
				tierPrefix: 'donation_value_',
			},
			renewsAt: {
				month: 'February 09, 2024',
				year: 'January 09, 2025',
			},
			tiersBasedOptions: [
				{
					heading: 'Small',
				},
				{
					heading: 'Medium',
				},
				{
					heading: 'Large',
				},
			],
		} );
	} );
} );
