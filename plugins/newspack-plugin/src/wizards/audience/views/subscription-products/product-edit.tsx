/**
 * Full-page Add/Edit route for a plan, mirroring the institutions editor: a routed wizard
 * section (its own URL + back-nav + header Save action). This wrapper loads the context
 * (the product to edit, available categories, bundle candidates, currency) by reusing the
 * list endpoint, then renders the full-page ProductForm once ready.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Router } from '../../../../../packages/components/src';
import ProductForm from './product-form';

const API_PATH = '/newspack/v1/wizard/newspack-audience-subscription-products/products';
const { useHistory } = Router;

export default function ProductEdit( { match }: { match: { params: { id?: string } } } ) {
	const history = useHistory();
	const id = match.params.id;
	const isNew = ! id;

	const [ context, setContext ] = useState< SubscriptionProductsResponse | null >( null );

	useEffect( () => {
		apiFetch< SubscriptionProductsResponse >( { path: API_PATH } )
			.then( setContext )
			.catch( () => history.push( '/' ) );
	}, [ history ] );

	// Memoize the props passed to ProductForm so they keep a stable identity across the
	// wizard's re-renders — otherwise ProductForm's submit (and its header effect) would
	// be recreated every render and loop on setHeaderData.
	const onDone = useCallback( () => history.push( '/' ), [ history ] );
	const categories = useMemo( () => ( context?.available_categories || [] ).map( cat => ( { id: cat.id, label: cat.name } ) ), [ context ] );
	const bundleOptions = useMemo(
		() => ( context?.products || [] ).filter( item => item.type !== 'grouped' ).map( item => ( { id: item.id, label: item.name } ) ),
		[ context ]
	);

	const product = useMemo(
		() => ( isNew || ! context ? undefined : context.products.find( item => String( item.id ) === id ) ),
		[ isNew, context, id ]
	);
	// The edit target no longer exists — redirect to the list from an effect so render stays
	// pure (a redirect during render warns / double-invokes under StrictMode).
	const isMissingTarget = !! context && ! isNew && ! product;
	useEffect( () => {
		if ( isMissingTarget ) {
			history.push( '/' );
		}
	}, [ isMissingTarget, history ] );

	if ( ! context || isMissingTarget ) {
		return (
			<div style={ { display: 'flex', justifyContent: 'center', alignItems: 'center', padding: '48px' } }>
				<Spinner />
			</div>
		);
	}

	return (
		<ProductForm
			mode={ isNew ? 'create' : 'edit' }
			initial={ product }
			categories={ categories }
			bundleOptions={ bundleOptions }
			currency={ context.currency }
			groupSubscriptionsEnabled={ context.group_subscriptions_enabled ?? false }
			onDone={ onDone }
		/>
	);
}
