/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n, getSettings } from '@wordpress/date';
import { Notice } from '@wordpress/components';
import { Badge } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { formatConfidence, formatLift, formatRate, getCountLabel, getLabelIntent, getVariantLabel } from './utils';

/**
 * A test's numbers: one row per variant, a confidence per challenger, and the
 * cautions that apply.
 *
 * @param {Object} props       Props.
 * @param {Object} props.stats The stats route's response.
 * @param {Object} props.data  Boot data (labels and copy).
 */
const StatsCard = ( { stats, data } ) => {
	const { source, variants, comparisons, flags } = stats;
	const letters = Object.keys( variants );
	const unavailable = source.state === 'unavailable';
	return (
		<div className="newspack-popups-ab-tests__stats">
			{ source.reason && (
				<Notice status={ unavailable ? 'warning' : 'info' } isDismissible={ false } spokenMessage="">
					{ source.reason }
				</Notice>
			) }
			{ ! unavailable && (
				<table className="newspack-popups-ab-tests__table widefat striped">
					<thead>
						<tr>
							<th scope="col">{ __( 'Variant', 'newspack-popups' ) }</th>
							<th scope="col">{ getCountLabel( source.unit ) }</th>
							<th scope="col">{ __( 'Conversions', 'newspack-popups' ) }</th>
							<th scope="col">{ __( 'Rate', 'newspack-popups' ) }</th>
							<th scope="col">{ __( 'Lift', 'newspack-popups' ) }</th>
							<th scope="col">{ __( 'Confidence', 'newspack-popups' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ letters.map( letter => {
							const variant = variants[ letter ];
							const comparison = comparisons[ letter ];
							return (
								<tr key={ letter }>
									<th scope="row">{ getVariantLabel( letter ) }</th>
									<td>{ variant.readers }</td>
									<td>{ variant.conversions }</td>
									<td>{ formatRate( variant.rate ) }</td>
									<td>{ letter === 'a' ? '—' : formatLift( variant.lift ) }</td>
									<td>
										{ comparison ? (
											<>
												{ formatConfidence( comparison.confidence ) }{ ' ' }
												<Badge intent={ getLabelIntent( comparison.label ) }>{ data.labels[ comparison.label ] }</Badge>
											</>
										) : (
											'—'
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }
			{ source.state === 'ok' && source.unit === 'events' && (
				<p className="description">
					{ __(
						'Counts are views rather than readers, because the stats source could not count distinct readers for this test.',
						'newspack-popups'
					) }
				</p>
			) }
			{ ! unavailable && flags.young_test && (
				<Notice status="info" isDismissible={ false } spokenMessage="">
					{ data.copy.young_test }
				</Notice>
			) }
			{ ! unavailable && flags.high_anonymous && (
				<Notice status="info" isDismissible={ false } spokenMessage="">
					{ data.copy.high_anonymous }
				</Notice>
			) }
			{ source.fetched_at && (
				<p className="description">
					{ sprintf(
						// Translators: %s is a date and time.
						__( 'Counts as of %s.', 'newspack-popups' ),
						dateI18n( getSettings().formats.datetime, source.fetched_at )
					) }
				</p>
			) }
		</div>
	);
};

export default StatsCard;
