/**
 * External dependencies
 */
import { debounce } from 'lodash';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { BaseControl, TextControl, FormTokenField, Button, Spinner } from '@wordpress/components';
import { useState, useEffect, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Searchable WooCommerce coupon picker for the Checkout Button block.
 *
 * Type to search existing coupons; selecting one stores its code in the block's
 * `coupon` attribute, clearing it removes the coupon. Mirrors the product picker.
 *
 * @param {Object}   props
 * @param {string}   props.value    Currently selected coupon code.
 * @param {Function} props.onChange Called with the selected coupon code (or '').
 * @return {JSX.Element} The control.
 */
export default function CouponControl( { value, onChange } ) {
	const [ inFlight, setInFlight ] = useState( false );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ selected, setSelected ] = useState( false );
	const [ isChanging, setIsChanging ] = useState( false );

	function fetchSuggestions( search ) {
		setInFlight( true );
		return apiFetch( {
			path: `/wc/v3/coupons?search=${ encodeURIComponent( search ) }`,
		} )
			.then( coupons => {
				setSuggestions( coupons.map( coupon => coupon.code ) );
			} )
			.finally( () => setInFlight( false ) );
	}

	function fetchSaved() {
		setInFlight( true );
		return apiFetch( {
			path: `/wc/v3/coupons?code=${ encodeURIComponent( value ) }`,
		} )
			.then( coupons => {
				// Keep the stored value even if the lookup returns nothing.
				setSelected( coupons.length ? coupons[ 0 ].code : value );
			} )
			.catch( () => setSelected( value ) )
			.finally( () => setInFlight( false ) );
	}

	useEffect( () => {
		setIsChanging( false );
		if ( value ) {
			fetchSaved();
		} else {
			setSelected( false );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value ] );

	function onTokenChange( tokens ) {
		setIsChanging( false );
		onChange( tokens[ 0 ] || '' );
	}

	const debouncedFetch = useMemo(
		() => debounce( fetchSuggestions, 200 ),
		// fetchSuggestions only closes over stable state setters, so an empty
		// dependency array is intentional.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[]
	);

	// Cancel a pending debounced fetch if the control unmounts, so it can't
	// fire and update state after the component is gone.
	useEffect( () => () => debouncedFetch.cancel(), [ debouncedFetch ] );

	function handleInputChange( search ) {
		if ( search.length > 2 ) {
			setInFlight( true );
			debouncedFetch( search );
		} else {
			// Cancel any fetch queued for a longer previous query so it can't
			// fire after the input was shortened or cleared.
			debouncedFetch.cancel();
			setInFlight( false );
		}
	}

	if ( value && ! selected && inFlight ) {
		return <Spinner />;
	}

	if ( selected && ! isChanging ) {
		return (
			<div className="newspack-checkout-button__coupon-field">
				<BaseControl label={ __( 'Coupon', 'newspack-blocks' ) } id="newspack-checkout-button-coupon">
					<TextControl value={ selected } __next40pxDefaultSize disabled />
					<Button variant="link" onClick={ () => setIsChanging( true ) }>
						{ __( 'Edit', 'newspack-blocks' ) }
					</Button>{ ' ' }
					<Button
						variant="link"
						isDestructive
						onClick={ () => {
							setSelected( false );
							onChange( '' );
						} }
					>
						{ __( 'Remove', 'newspack-blocks' ) }
					</Button>
				</BaseControl>
			</div>
		);
	}

	return (
		<div className="newspack-checkout-button__coupon-field">
			<FormTokenField
				placeholder={ __( 'Type to search for a coupon…', 'newspack-blocks' ) }
				label={ __( 'Coupon', 'newspack-blocks' ) }
				maxLength={ 1 }
				value={ [] }
				onChange={ onTokenChange }
				onInputChange={ handleInputChange }
				suggestions={ suggestions }
				__next40pxDefaultSize
			/>
			{ inFlight && <Spinner /> }
			{ selected && (
				<Button variant="link" onClick={ () => setIsChanging( false ) }>
					{ __( 'Cancel', 'newspack-blocks' ) }
				</Button>
			) }
		</div>
	);
}
