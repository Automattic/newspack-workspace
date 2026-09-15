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
 * Requests whose failure has already been announced.
 *
 * The source hands every reader the same promise, and this hook has a reader per gate on
 * the landing page, so one rejection reaches all of them. The wizard store appends
 * notices unconditionally and `WizardSnackbar` announces an error assertively, so
 * without this a six-gate site raised six identical snackbars and a screen reader read
 * every one. Keyed on the promise rather than the slug because that is exactly the scope
 * wanted: the source drops a rejected entry, so a later retry is a different promise and
 * announces again.
 */
const announcedFailures = new WeakSet< Promise< AccessRuleOption[] > >();

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
				const request = getAccessRuleOptionSource( slug )?.();
				if ( ! request ) {
					return;
				}
				request
					.then( options => {
						if ( ! cancelled ) {
							setFetchedOptions( current => ( { ...current, [ slug ]: options } ) );
						}
					} )
					.catch( () => {
						// An unmounted reader announces nothing, and it must not claim the
						// announcement either – a reader still on screen makes it instead.
						if ( cancelled || announcedFailures.has( request ) ) {
							return;
						}
						announcedFailures.add( request );
						addNotice( {
							message: getAccessRuleOptionsFetchFailedNotice(),
							type: 'error',
							id: `rule-options-error-${ slug }`,
						} );
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
					// A response that came back empty is used as it is: the site really
					// does have no institutions left, and falling back to the page-load
					// snapshot would leave deleted ones named and selectable. Only a
					// failed fetch keeps the snapshot, and it says so in a notice.
					fetchedOptions[ slug ] ?? rule?.options ?? [],
				] )
			),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ fetchedOptions ]
	);
}
