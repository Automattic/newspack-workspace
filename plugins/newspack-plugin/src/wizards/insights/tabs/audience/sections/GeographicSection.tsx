/**
 * Audience › Geographic (NPPD-1649, Section 5).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Card, Grid } from '../../../../../../packages/components/src';
import type { InsightsWindow } from '../../../api/audience';
import type { MetricPayload } from '../../components/metrics';
import { uniformValue } from '../../components/metrics';
import MetricTable from '../../components/MetricTable';
import ScopePill from '../../components/ScopePill';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const ROW_LIMIT = 10;
const READERS_COL = { key: 'readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number' as const, align: 'right' as const };

// The uniform country (if any) across the rows MetricTable will display — drives
// both the hidden Country column and the inline scope pill, kept in sync.
const countryScope = ( payload?: MetricPayload ): string | null => uniformValue( ( payload?.rows ?? [] ).slice( 0, ROW_LIMIT ), 'country' );

const GeographicSection = ( { current }: SectionProps ) => {
	const regionsScope = countryScope( current.top_regions );
	const citiesScope = countryScope( current.top_cities );

	return (
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-geo">
			<SectionHeading
				id="newspack-insights-audience-geo"
				title={ __( 'Geographic', 'newspack-plugin' ) }
				description={ __( 'Where your readers are.', 'newspack-plugin' ) }
			/>
			<Grid columns={ 2 } gutter={ 16 } noMargin>
				<Card __experimentalCoreCard className="newspack-insights__chart-card">
					<VStack spacing={ 6 }>
						<h3 className="newspack-insights__chart-card-title">
							{ __( 'Top regions / states', 'newspack-plugin' ) }
							{ regionsScope && <ScopePill label={ regionsScope } /> }
						</h3>
						<MetricTable
							payload={ current.top_regions }
							emptyMessage={ __( 'No data in this timeframe.', 'newspack-plugin' ) }
							collapseColumn="country"
							columns={ [
								{ key: 'country', label: __( 'Country', 'newspack-plugin' ) },
								{ key: 'region', label: __( 'Region', 'newspack-plugin' ) },
								READERS_COL,
							] }
						/>
					</VStack>
				</Card>
				<Card __experimentalCoreCard className="newspack-insights__chart-card">
					<VStack spacing={ 6 }>
						<h3 className="newspack-insights__chart-card-title">
							{ __( 'Top cities', 'newspack-plugin' ) }
							{ citiesScope && <ScopePill label={ citiesScope } /> }
						</h3>
						<MetricTable
							payload={ current.top_cities }
							emptyMessage={ __( 'No data in this timeframe.', 'newspack-plugin' ) }
							collapseColumn="country"
							columns={ [
								{ key: 'country', label: __( 'Country', 'newspack-plugin' ) },
								{ key: 'region', label: __( 'Region', 'newspack-plugin' ) },
								{ key: 'city', label: __( 'City', 'newspack-plugin' ) },
								READERS_COL,
							] }
						/>
					</VStack>
				</Card>
			</Grid>
		</Section>
	);
};

export default GeographicSection;
