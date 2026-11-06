/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
// Not the Newspack wrapper: with-wizard-screen/style.scss gives `.newspack-dataviews`
// a page bleed that would hang this table past its card.
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { DASH, formatConfidence, formatLift, formatRate, getCountLabel } from './utils';

// The stylesheet sets column widths by position, which holds because columns
// can't be moved; reordering this list moves those widths with it.
const FIELD_IDS = [ 'version', 'prompt', 'readers', 'rate', 'lift', 'confidence' ];

/**
 * One row per version, with its results when there are any. Every card has
 * the same six columns, so a test with no results yet shows dashes.
 *
 * @param {Object}  props
 * @param {Object}  props.test   The test.
 * @param {string}  props.status From getTestStatus().
 * @param {?Object} props.stats  Its stats, or null.
 * @param {Object}  props.labels Confidence label key => text.
 */
const ResultsTable = ( { test, status, stats, labels } ) => {
	const rows = useMemo( () => {
		return Object.keys( test.variants ).map( letter => {
			const row = stats?.variants?.[ letter ] || null;
			return {
				letter,
				variant: test.variants[ letter ],
				row,
				lift: row?.lift ?? null,
				comparison: stats?.comparisons?.[ letter ] || null,
			};
		} );
	}, [ test, stats ] );

	const fields = useMemo(
		() => [
			{
				id: 'version',
				label: __( 'Version', 'newspack-plugin' ),
				enableHiding: false,
				filterBy: false,
				getValue: ( { item } ) => item.letter,
				render: ( { item } ) => (
					<span className="newspack-ab-tests__version">
						<span className={ `newspack-ab-tests__swatch is-variant-${ item.letter }` } aria-hidden="true" />
						{ item.letter.toUpperCase() }
					</span>
				),
			},
			{
				id: 'prompt',
				label: __( 'Prompt', 'newspack-plugin' ),
				enableHiding: false,
				filterBy: false,
				getValue: ( { item } ) => decodeEntities( item.variant.title ),
				render: ( { item } ) => {
					const title = decodeEntities( item.variant.title ) || __( '(no title)', 'newspack-plugin' );
					// The card's status says the rest; only an unpublished version on a
					// Draft card needs its own line.
					const showStatus = status === 'draft' && item.variant.status !== 'publish';
					return (
						<span className="newspack-ab-tests__prompt">
							{ item.variant.edit_link ? <a href={ item.variant.edit_link }>{ title }</a> : title }
							{ showStatus && <span className="newspack-ab-tests__muted">{ item.variant.status_label }</span> }
						</span>
					);
				},
			},
			{
				id: 'readers',
				label: getCountLabel( stats?.source?.unit ),
				enableHiding: false,
				filterBy: false,
				type: 'integer',
				getValue: ( { item } ) => item.row?.readers ?? -1,
				render: ( { item } ) => ( item.row ? Number( item.row.readers ).toLocaleString() : DASH ),
			},
			{
				id: 'rate',
				label: __( 'Conversion rate', 'newspack-plugin' ),
				enableHiding: false,
				filterBy: false,
				type: 'number',
				getValue: ( { item } ) => item.row?.rate ?? -1,
				render: ( { item } ) =>
					item.row && item.row.rate !== null && item.row.rate !== undefined ? (
						<span className="newspack-ab-tests__rate">
							{ formatRate( item.row.rate ) }
							<span className="newspack-ab-tests__muted">
								{ sprintf(
									// Translators: %1$s is a number of conversions, %2$s a number of readers, such as "87 of 2,071".
									__( '%1$s of %2$s', 'newspack-plugin' ),
									Number( item.row.conversions ).toLocaleString(),
									Number( item.row.readers ).toLocaleString()
								) }
							</span>
						</span>
					) : (
						DASH
					),
			},
			{
				id: 'lift',
				label: __( 'Lift vs. A', 'newspack-plugin' ),
				enableHiding: false,
				filterBy: false,
				type: 'number',
				getValue: ( { item } ) => item.lift ?? -Infinity,
				render: ( { item } ) => formatLift( item.lift ),
			},
			{
				id: 'confidence',
				label: __( 'Confidence', 'newspack-plugin' ),
				enableHiding: false,
				filterBy: false,
				getValue: ( { item } ) => item.comparison?.confidence ?? -1,
				render: ( { item } ) => formatConfidence( item.comparison, labels ),
			},
		],
		[ stats, status, labels ]
	);

	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 10,
		search: '',
		filters: [],
		fields: FIELD_IDS,
		layout: {
			enableMoving: false,
			styles: {
				readers: { align: 'end' },
				rate: { align: 'end' },
				lift: { align: 'end' },
			},
		},
	} );
	// The column set never changes, whatever the view asks for.
	const tableView = useMemo( () => ( { ...view, fields: FIELD_IDS, perPage: Math.max( rows.length, 1 ) } ), [ view, rows.length ] );
	const { data, paginationInfo } = useMemo( () => filterSortAndPaginate( rows, tableView, fields ), [ rows, tableView, fields ] );

	return (
		<div className="newspack-ab-tests__table">
			<DataViews
				data={ data }
				fields={ fields }
				view={ tableView }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ item => item.letter }
			>
				<DataViews.Layout />
			</DataViews>
		</div>
	);
};

export default ResultsTable;
