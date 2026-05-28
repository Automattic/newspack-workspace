/**
 * WordPress dependencies.
 */
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import { WIZARD_STORE_NAMESPACE } from '../../packages/components/src/wizard/store';

/**
 * Wizards Tab component.
 */

function WizardsTab( {
	title,
	children,
	isFetching,
	description,
	...props
}: {
	title?: string;
	children: React.ReactNode;
	isFetching?: boolean;
	className?: string;
	description?: React.ReactNode;
} ) {
	const isWizardLoading = useSelect( ( select: ( namespace: string ) => WizardSelector ) => select( WIZARD_STORE_NAMESPACE ).isLoading(), [] );
	const className = props.className || '';
	return (
		<div className={ `${ isWizardLoading || isFetching ? 'is-fetching ' : '' }${ className } newspack-wizard__sections` }>
			{ title && <h1>{ title }</h1> }
			{ description && <p className="newspack-wizard__sections__description">{ description }</p> }
			{ children }
		</div>
	);
}

export default WizardsTab;
