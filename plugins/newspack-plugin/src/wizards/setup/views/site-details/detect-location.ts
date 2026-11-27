import zoneCountries from './zone-countries.json';

const ZONE_COUNTRY: Record< string, string > = zoneCountries;

export type DetectedCountry = { country: string; source: 'zone' | 'locale' };

export const detectCountrySource = ( timeZone: string, siteLocale: string, countries: string[] ): DetectedCountry | null => {
	const fromZone = ZONE_COUNTRY[ timeZone ];
	if ( fromZone && countries.includes( fromZone ) ) {
		return { country: fromZone, source: 'zone' };
	}
	const region = siteLocale.split( '_' )[ 1 ]?.toUpperCase();
	return region && countries.includes( region ) ? { country: region, source: 'locale' } : null;
};

export const currencyFor = ( country: string | null, map: Record< string, string > ): string | null => ( country && map[ country ] ) || null;
