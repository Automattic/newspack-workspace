/**
 * Route wrapper for the pricing-rule editor. Loads the rule (edit) + the vocab,
 * then renders the form once ready.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Router } from '../../../../../packages/components/src';
import RuleForm from './rule-form';

const { useHistory } = Router;
const API_PATH = '/wc-dynamic-pricing/v1/rules';

export default function RuleEdit( { match }: { match: { params: { id?: string } } } ) {
	const history = useHistory();
	const id = match.params.id;
	const isNew = ! id;
	const [ vocab, setVocab ] = useState< PricingRulesResponse | null >( null );
	const [ rule, setRule ] = useState< PricingRuleRow | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );

	useEffect( () => {
		// The list endpoint also returns the vocab (strategies/scopes/calc_types/currency).
		Promise.all< any >( [
			apiFetch< PricingRulesResponse >( { path: API_PATH } ),
			isNew ? Promise.resolve( null ) : apiFetch< PricingRuleRow >( { path: `${ API_PATH }/${ id }` } ),
		] )
			.then( ( [ vocabResp, ruleResp ] ) => {
				setVocab( vocabResp );
				setRule( ruleResp );
			} )
			.catch( () => history.push( '/' ) )
			.finally( () => setIsLoading( false ) );
	}, [ id, isNew, history ] );

	// Memoized so it keeps a stable identity across the wizard's re-renders —
	// otherwise RuleForm's submit (and its setHeaderData effect) recreate every
	// render and loop on the header store. (Matches product-edit.tsx.)
	const onDone = useCallback( () => history.push( '/' ), [ history ] );

	if ( isLoading || ! vocab ) {
		return (
			<div style={ { display: 'flex', justifyContent: 'center', padding: '48px' } }>
				<Spinner />
			</div>
		);
	}
	if ( ! isNew && ! rule ) {
		return <p>{ __( 'Rule not found.', 'newspack-plugin' ) }</p>;
	}

	return <RuleForm isNew={ isNew } rule={ rule } vocab={ vocab } onDone={ onDone } />;
}
