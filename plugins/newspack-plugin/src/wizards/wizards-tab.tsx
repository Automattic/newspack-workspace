/**
 * WordPress dependencies.
 */
import { useSelect } from '@wordpress/data';
import { forwardRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { WIZARD_STORE_NAMESPACE } from '../../packages/components/src/wizard/store';

/**
 * Wizards Tab component.
 *
 * The ref reaches the section wrapper, so a view that swaps its whole body can
 * move focus to the body it just rendered.
 */
const WizardsTab = forwardRef<
	HTMLDivElement,
	{
		title?: string;
		children: React.ReactNode;
		isFetching?: boolean;
		className?: string;
		description?: React.ReactNode;
		tabIndex?: number;
	}
>( ( { title, children, isFetching, description, className = '', ...props }, ref ) => {
	const isWizardLoading = useSelect( ( select: ( namespace: string ) => WizardSelector ) => select( WIZARD_STORE_NAMESPACE ).isLoading(), [] );
	return (
		<div
			{ ...props }
			ref={ ref }
			className={ `${ isWizardLoading || isFetching ? 'is-fetching ' : '' }${ className } newspack-wizard__sections` }
		>
			{ title && <h2 className="newspack-wizard__heading">{ title }</h2> }
			{ description && <p className="newspack-wizard__sections__description">{ description }</p> }
			{ children }
		</div>
	);
} );

WizardsTab.displayName = 'WizardsTab';

export default WizardsTab;
