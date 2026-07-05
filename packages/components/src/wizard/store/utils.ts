/**
 * WordPress dependencies.
 */
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { WIZARD_STORE_NAMESPACE } from '.';
import type { WizardsStoreSelectors } from '.';

/**
 * Creates an action creator for the given action type. The action creator
 * wraps its single argument as the action's payload.
 *
 * @param type The action type.
 */
export const createAction =
	< TPayload = undefined, TType extends string = string >( type: TType ) =>
	( payload?: TPayload ) => ( { type, payload } );

/**
 * Reads a wizard's API data from the wizards store.
 *
 * The data's shape depends on the wizard, so callers provide it via the type
 * parameter.
 *
 * @param wizardName   The wizard's slug.
 * @param defaultValue Value returned while the store has no data for the wizard.
 */
export function useWizardData< TData extends object = object >( wizardName: string, defaultValue?: TData ): TData;
export function useWizardData( wizardName: string, defaultValue: object = {} ): object {
	return useSelect( select => ( select( WIZARD_STORE_NAMESPACE ) as WizardsStoreSelectors ).getWizardAPIData( wizardName ) ) || defaultValue;
}
