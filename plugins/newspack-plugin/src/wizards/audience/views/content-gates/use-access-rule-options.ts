/**
 * WordPress dependencies.
 */
import { useDispatch } from '@wordpress/data';
import { useEffect, useMemo, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { getAccessRuleOptionsFetchFailedNotice, type AccessRuleOption } from '../../../../content-gate/access-rule-options';
import { getAccessRuleOptionSource } from '../../../../content-gate/access-rule-option-sources';

/**
 * The options to name a rule's stored values with, keyed by rule slug: the list
 * localised with the page, replaced by a freshly fetched one for the rules whose options
 * are fetched.
 *
 * Every surface that names a stored value reads through this — the pickers and the
 * summary cards alike — so a gate reads the same way wherever it is inspected.
 * Institutions are created and deleted in this same app, so a summary built from the
 * localised snapshot would call an institution added a moment ago "not listed", which is
 * the wording that tells a publisher a value may be safe to remove.
 *
 * @return The options for each rule.
 */
export function useAccessRuleOptions(): Record< string, AccessRuleOption[] > {
	const rules = window.newspackAudienceContentGates?.available_access_rules ?? {};
	const { addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ fetchedOptions, setFetchedOptions ] = useState< Record< string, AccessRuleOption[] > >( {} );
	// Joined so the effect's dependency is a value, not a fresh array each render.
	const fetchedSlugs = Object.keys( rules )
		.filter( slug => getAccessRuleOptionSource( slug ) )
		.join( ',' );

	useEffect( () => {
		let cancelled = false;
		fetchedSlugs
			.split( ',' )
			.filter( Boolean )
			.forEach( slug => {
				getAccessRuleOptionSource( slug )?.()
					.then( options => {
						if ( ! cancelled ) {
							setFetchedOptions( current => ( { ...current, [ slug ]: options } ) );
						}
					} )
					.catch( () => {
						if ( ! cancelled ) {
							addNotice( {
								message: getAccessRuleOptionsFetchFailedNotice(),
								type: 'error',
								id: `rule-options-error-${ slug }`,
							} );
						}
					} );
			} );
		return () => {
			cancelled = true;
		};
	}, [ fetchedSlugs, addNotice ] );

	return useMemo(
		() =>
			Object.fromEntries(
				Object.entries( rules ).map( ( [ slug, rule ] ) => [
					slug,
					// An empty response leaves the localised list in place: it was
					// complete at page load, and dropping to no options at all would turn
					// still-granting IDs into entries a publisher can only remove.
					fetchedOptions[ slug ]?.length ? fetchedOptions[ slug ] : rule?.options ?? [],
				] )
			),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ fetchedOptions ]
	);
}
