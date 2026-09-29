/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link, Stack, Text } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { formatCount } from './format-count';
import './style.scss';

/**
 * A crumb's count, shown in parentheses and spoken as a phrase.
 *
 * @param {Object} props
 * @param {number} [props.count]      Item count.
 * @param {string} [props.countLabel] Translated accessible phrasing for the count.
 * @return {JSX.Element|null} The count, or null when the crumb carries none.
 */
const CrumbCount = ( { count, countLabel } ) => {
	if ( ! Number.isFinite( count ) ) {
		return null;
	}
	const formatted = formatCount( count );
	return (
		<span className="newspack-breadcrumbs__count">
			<span aria-hidden="true">{ `(${ formatted })` }</span>
			<span className="screen-reader-text">
				{ countLabel ||
					sprintf(
						/* translators: %s: number of items in the current view. */
						_n( '%s item', '%s items', count, 'newspack-plugin' ),
						formatted
					) }
			</span>
		</span>
	);
};

/**
 * A crumb's label, with its count appended when the item carries one.
 *
 * @param {Object} props
 * @param {string} props.label        Crumb label.
 * @param {number} [props.count]      Item count to annotate the label with.
 * @param {string} [props.countLabel] Translated accessible phrasing for the count.
 * @return {JSX.Element|string} The label, annotated when a count is present.
 */
const CrumbLabel = ( { label, count, countLabel } ) => {
	if ( ! Number.isFinite( count ) ) {
		return label;
	}
	return (
		<>
			{ label } <CrumbCount count={ count } countLabel={ countLabel } />
		</>
	);
};

/**
 * Newspack breadcrumb trail.
 *
 * Data-driven: the last item is the current page (the single h1, never a link);
 * every other item renders as a link when it has a `url`, otherwise plain text.
 * A plain `<a href>` drives both full admin URLs and hash routes, so the
 * component needs no router context.
 *
 * @param {Object} props
 * @param {Array}  props.items Trail items: `{ label, url?, count?, countLabel? }`.
 * @return {JSX.Element|null} Breadcrumbs component.
 */
const Breadcrumbs = ( { items = [] } ) => {
	if ( ! items.length ) {
		return null;
	}

	const preceding = items.slice( 0, -1 );
	const last = items[ items.length - 1 ];

	return (
		<nav aria-label={ __( 'Breadcrumbs', 'newspack-plugin' ) }>
			<Stack render={ <ul /> } direction="row" align="center" className="newspack-breadcrumbs__list">
				{ preceding.map( ( item, index ) => (
					<li key={ item.url || index }>
						{ item.url ? (
							<>
								{ /* eslint-disable-next-line jsx-a11y/anchor-has-content -- content is supplied via the Text children through @wordpress/ui's render prop. */ }
								<Text variant="body-lg" render={ <Link tone="neutral" render={ <a href={ item.url } /> } /> }>
									{ item.label }
								</Text>
								{ /* Outside the link: the count describes the view being left, not the destination. */ }
								{ Number.isFinite( item.count ) && (
									<Text variant="body-lg">
										{ ' ' }
										<CrumbCount count={ item.count } countLabel={ item.countLabel } />
									</Text>
								) }
							</>
						) : (
							<Text variant="body-lg">
								<CrumbLabel { ...item } />
							</Text>
						) }
						<Text variant="body-lg" aria-hidden="true" className="newspack-breadcrumbs__separator">
							/
						</Text>
					</li>
				) ) }
				{ last && last.label && (
					<li>
						{ /* eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop. */ }
						<Text variant="heading-lg" render={ <h1 /> } className="newspack-breadcrumbs__current">
							<CrumbLabel { ...last } />
						</Text>
					</li>
				) }
			</Stack>
		</nav>
	);
};

export default Breadcrumbs;
