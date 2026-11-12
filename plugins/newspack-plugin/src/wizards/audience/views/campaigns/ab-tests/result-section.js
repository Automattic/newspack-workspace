/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';

/**
 * The answer at the top of a card: a verdict, the rate bars and a detail line.
 *
 * @param {Object} props
 * @param {Object} props.verdict From getVerdict().
 */
const ResultSection = ( { verdict } ) => (
	<Stack direction="column" gap="sm" className="newspack-ab-tests__result">
		<span className="newspack-ab-tests__result-label">{ __( 'Result', 'newspack-plugin' ) }</span>
		<Text variant="body-md" render={ <p /> } className="newspack-ab-tests__answer">
			{ verdict.answer }
		</Text>
		{ verdict.bars && (
			// The verdict says the numbers; the bars only show their proportions.
			<div className={ `newspack-ab-tests__bars${ verdict.bars.placeholder ? ' is-placeholder' : '' }` } aria-hidden="true">
				{ verdict.bars.rows.map( row => (
					<div key={ row.letter } className="newspack-ab-tests__bars-row">
						<span className="newspack-ab-tests__bars-letter">{ row.letter.toUpperCase() }</span>
						<span className="newspack-ab-tests__bars-track">
							<span className={ `newspack-ab-tests__bar is-variant-${ row.letter }` } style={ { width: `${ row.width }%` } } />
						</span>
					</div>
				) ) }
			</div>
		) }
		{ verdict.detail && (
			<Text variant="body-md" render={ <p /> } className="newspack-ab-tests__detail">
				{ verdict.detail }
			</Text>
		) }
	</Stack>
);

export default ResultSection;
