/* eslint @wordpress/no-unsafe-wp-apis: 0 */

/**
 * WordPress dependencies.
 */
import { sprintf, __, _x } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import { __experimentalHStack as HStack, Button, SelectControl } from '@wordpress/components';
import { next, previous } from '@wordpress/icons';

const Pagination = ( { currentPage, totalPages, onPageChange } ) => {
	return (
		<HStack expanded={ false } className="newspack-story-budget__pagination" justify="end" spacing={ 6 }>
			<HStack justify="flex-start" expanded={ false } spacing={ 1 } className="newspack-story-budget__pagination__page-text">
				{ createInterpolateElement(
					sprintf(
						// translators: 1: Current page number, 2: Total number of pages.
						_x( '<div>Page</div>%1$s<div>of %2$s</div>', 'newspack-story-budget' ),
						'<CurrentPage />',
						totalPages
					),
					{
						div: <div aria-hidden />,
						CurrentPage: (
							<SelectControl
								className="newspack-story-budget__pagination__page-select"
								variant="minimal"
								aria-label={ __( 'Current page', 'newspack-story-budget' ) }
								value={ currentPage.toString() }
								options={ Array.from( { length: totalPages }, ( _, i ) => ( {
									label: ( i + 1 ).toString(),
									value: ( i + 1 ).toString(),
								} ) ) }
								onChange={ newValue => onPageChange( parseInt( newValue, 10 ) ) }
							/>
						),
					}
				) }
			</HStack>
			<HStack justify="flex-end" expanded={ false } spacing={ 1 } className="newspack-story-budget__pagination__page-buttons">
				<Button
					variant="secondary"
					disabled={ currentPage === 1 }
					onClick={ () => onPageChange( currentPage - 1 ) }
					icon={ previous }
					size="compact"
					showTooltip
					tooltipPosition="top"
					label={ __( 'Previous page', 'newspack-story-budget' ) }
				/>
				<Button
					variant="secondary"
					disabled={ currentPage === totalPages }
					onClick={ () => onPageChange( currentPage + 1 ) }
					icon={ next }
					showTooltip
					size="compact"
					tooltipPosition="top"
					label={ __( 'Next page', 'newspack-story-budget' ) }
				/>
			</HStack>
		</HStack>
	);
};

export default Pagination;
