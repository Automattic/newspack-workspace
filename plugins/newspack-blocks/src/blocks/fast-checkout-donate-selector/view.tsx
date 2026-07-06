/**
 * Fast Checkout Donate Selector — frontend.
 *
 * Hydrates the SSR shell. Radio change swaps the cart line to the picked
 * child while preserving the current input amount (clamped to the new
 * child's range). The NYP input applies on blur via the WC Store API
 * cart store. The suffix updates to reflect the selected child's
 * subscription period (e.g. "/ monthly", "/ yearly").
 */

import { createRoot, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { dispatch, select } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import './view.scss';

const STORE = 'wc/store/cart' as const;
const DEBOUNCE_MS = 300;

interface ChildData {
	id: number;
	name: string;
	period: string;
	min: string;
	max: string;
	suggested: string;
}

interface RootProps {
	host: HTMLElement;
	productId: number;
	children: ChildData[];
	currentChildId: number;
}

function suffixForPeriod( period: string ): string {
	switch ( period ) {
		case 'day':
			return ' / ' + __( 'daily', 'newspack-blocks' );
		case 'week':
			return ' / ' + __( 'weekly', 'newspack-blocks' );
		case 'month':
			return ' / ' + __( 'monthly', 'newspack-blocks' );
		case 'year':
			return ' / ' + __( 'yearly', 'newspack-blocks' );
		default:
			return '';
	}
}

function DonateSelector( { host, children, currentChildId }: RootProps ) {
	const [ pendingId, setPendingId ] = useState< number >( currentChildId );
	const [ inFlight, setInFlight ] = useState( false );
	const inFlightRef = useRef< boolean >( false );
	const [ error, setError ] = useState< string >( '' );

	const input = useMemo< HTMLInputElement | null >( () => host.querySelector< HTMLInputElement >( 'input[type="number"]' ), [ host ] );
	const suffixNode = useMemo< HTMLElement | null >(
		() => host.querySelector( '.wp-block-newspack-blocks-fast-checkout-donate-selector__suffix' ),
		[ host ]
	);
	const noticeNode = useMemo< HTMLElement | null >(
		() => host.querySelector( '.wp-block-newspack-blocks-fast-checkout-donate-selector__notice' ),
		[ host ]
	);

	const lastApplied = useRef< { childId: number; amount: number } >( {
		childId: currentChildId,
		amount: parseFloat( input?.value || '0' ) || 0,
	} );
	const blurTimer = useRef< ReturnType< typeof setTimeout > | null >( null );

	const findChild = ( id: number ) => children.find( c => c.id === id );

	useEffect( () => {
		if ( noticeNode ) {
			if ( error ) {
				noticeNode.textContent = error;
				noticeNode.removeAttribute( 'hidden' );
			} else {
				noticeNode.textContent = '';
				noticeNode.setAttribute( 'hidden', '' );
			}
		}
	}, [ error, noticeNode ] );

	useEffect( () => {
		host.dataset.status = inFlight ? 'busy' : 'idle';
		const fastCheckout = host.closest< HTMLElement >( '.wp-block-newspack-blocks-fast-checkout' );
		if ( fastCheckout ) {
			if ( inFlight ) {
				fastCheckout.dataset.fcSwapping = 'true';
			} else {
				delete fastCheckout.dataset.fcSwapping;
			}
		}
	}, [ host, inFlight ] );

	// Apply the cart change for the currently-selected child + amount.
	const applySelection = async ( nextChildId: number, nextAmount: number ) => {
		if ( inFlightRef.current ) {
			return;
		}
		inFlightRef.current = true;
		setInFlight( true );
		try {
			const cartActions = dispatch( STORE );
			const cartSelectors = select( STORE );
			const items = cartSelectors.getCartData()?.items || [];
			// Remove ALL cart items matching any of this donate group's children
			// (not just the one we think is there) so stale duplicates from
			// earlier swaps or partial failures don't accumulate.
			const childIds = new Set( children.map( c => c.id ) );
			const toRemove: string[] = [];
			items.forEach( ( item: { id?: number; key?: string } ) => {
				if ( item.id && item.key && childIds.has( item.id ) ) {
					toRemove.push( item.key );
				}
			} );
			for ( const key of toRemove ) {
				await ( cartActions as { removeItemFromCart: ( key: string ) => Promise< unknown > } ).removeItemFromCart( key );
			}
			const sourcePost = parseInt( host.dataset.sourcePost || '0', 10 );
			const cartItemData: Record< string, unknown > = { nyp: nextAmount };
			if ( sourcePost ) {
				cartItemData._newspack_fast_checkout_source_post = sourcePost;
			}
			await (
				cartActions as {
					addItemToCart: ( id: number, qty: number, variation?: unknown[], cartItemData?: Record< string, unknown > ) => Promise< unknown >;
				}
			 ).addItemToCart( nextChildId, 1, [], cartItemData );
			lastApplied.current = { childId: nextChildId, amount: nextAmount };
			updateUrlParam( 'fc_grouped_child', String( nextChildId ) );
			updateUrlParam( 'fc_price', String( nextAmount ) );
			setError( '' );
		} catch ( ex: unknown ) {
			setError( ( ex as Error )?.message || __( 'Could not update selection.', 'newspack-blocks' ) );
		} finally {
			inFlightRef.current = false;
			setInFlight( false );
		}
	};

	// Radio change → swap to the new child, preserving the current amount
	// (clamped to the new child's min/max). Update suffix and input bounds.
	useEffect( () => {
		const onRadioChange = async ( e: Event ) => {
			const target = e.target as HTMLInputElement;
			if ( target?.type !== 'radio' || target.name !== 'fc_donate_child' ) {
				return;
			}
			const nextId = parseInt( target.value, 10 );
			if ( ! nextId || nextId === pendingId ) {
				return;
			}
			const child = findChild( nextId );
			if ( ! child ) {
				return;
			}
			const minNum = parseFloat( child.min ) || 0;
			const maxNum = parseFloat( child.max ) || 0;
			const currentAmount = parseFloat( input?.value || '0' ) || 0;
			let nextAmount = currentAmount > 0 ? currentAmount : parseFloat( child.suggested ) || 0;
			if ( maxNum > 0 ) {
				nextAmount = Math.min( nextAmount, maxNum );
			}
			if ( minNum > 0 ) {
				nextAmount = Math.max( nextAmount, minNum );
			}
			if ( input ) {
				input.value = String( nextAmount );
				if ( minNum > 0 ) {
					input.min = String( minNum );
				} else {
					input.removeAttribute( 'min' );
				}
				if ( maxNum > 0 ) {
					input.max = String( maxNum );
				} else {
					input.removeAttribute( 'max' );
				}
			}
			if ( suffixNode ) {
				suffixNode.textContent = suffixForPeriod( child.period );
			}
			host.dataset.currentChild = String( nextId );
			setPendingId( nextId );
			await applySelection( nextId, nextAmount );
		};
		host.addEventListener( 'change', onRadioChange );
		return () => host.removeEventListener( 'change', onRadioChange );
	}, [ host, pendingId, input, suffixNode, children ] );

	// Input blur (debounced) → apply NYP price for the currently-selected child.
	useEffect( () => {
		if ( ! input ) {
			return;
		}
		const onBlur = () => {
			if ( blurTimer.current ) {
				clearTimeout( blurTimer.current );
			}
			blurTimer.current = setTimeout( apply, DEBOUNCE_MS );
		};

		const apply = async () => {
			if ( inFlightRef.current ) {
				return;
			}
			const child = findChild( pendingId );
			if ( ! child ) {
				return;
			}
			const raw = parseFloat( input.value );
			if ( ! isFinite( raw ) || raw <= 0 ) {
				setError( __( 'Enter a valid amount.', 'newspack-blocks' ) );
				input.value = String( lastApplied.current.amount );
				return;
			}
			let clamped = raw;
			const maxNum = parseFloat( child.max ) || 0;
			const minNum = parseFloat( child.min ) || 0;
			if ( maxNum > 0 ) {
				clamped = Math.min( clamped, maxNum );
			}
			if ( minNum > 0 ) {
				clamped = Math.max( clamped, minNum );
			}
			if ( clamped !== raw ) {
				input.value = String( clamped );
				setError(
					sprintf(
						/* translators: %s is the price actually applied. */
						__( 'Adjusted to %s to meet limits.', 'newspack-blocks' ),
						String( clamped )
					)
				);
			} else {
				setError( '' );
			}
			if ( clamped === lastApplied.current.amount && pendingId === lastApplied.current.childId ) {
				return;
			}
			await applySelection( pendingId, clamped );
		};

		input.addEventListener( 'blur', onBlur );
		return () => {
			input.removeEventListener( 'blur', onBlur );
			if ( blurTimer.current ) {
				clearTimeout( blurTimer.current );
			}
		};
	}, [ input, pendingId, children ] );

	// popstate: re-sync from URL params on back/forward navigation.
	useEffect( () => {
		const onPopstate = () => {
			const params = new URLSearchParams( window.location.search );
			const nextId = parseInt( params.get( 'fc_grouped_child' ) || '', 10 );
			const nextPrice = parseFloat( params.get( 'fc_price' ) || '' );
			if ( nextId && nextId !== pendingId ) {
				const radio = host.querySelector< HTMLInputElement >( `input[type="radio"][value="${ nextId }"]` );
				if ( radio ) {
					radio.checked = true;
					setPendingId( nextId );
					const child = findChild( nextId );
					if ( child && suffixNode ) {
						suffixNode.textContent = suffixForPeriod( child.period );
					}
				}
			}
			if ( isFinite( nextPrice ) && nextPrice > 0 && input ) {
				input.value = String( nextPrice );
			}
		};
		window.addEventListener( 'popstate', onPopstate );
		return () => window.removeEventListener( 'popstate', onPopstate );
	}, [ host, pendingId, input, suffixNode ] );

	return null;
}

function updateUrlParam( key: string, value: string ) {
	const url = new URL( window.location.href );
	url.searchParams.set( key, value );
	window.history.replaceState( {}, '', url.toString() );
}

function init() {
	document.querySelectorAll< HTMLElement >( '.wp-block-newspack-blocks-fast-checkout-donate-selector' ).forEach( host => {
		const productId = parseInt( host.dataset.productId || '0', 10 );
		const currentChildId = parseInt( host.dataset.currentChild || '0', 10 );
		let children: ChildData[] = [];
		try {
			children = JSON.parse( host.dataset.children || '[]' );
		} catch {
			children = [];
		}
		if ( ! productId || ! children.length || ! currentChildId ) {
			return;
		}
		const sentinel = document.createElement( 'span' );
		sentinel.style.display = 'none';
		host.appendChild( sentinel );
		const root = createRoot( sentinel );
		root.render( <DonateSelector host={ host } productId={ productId } children={ children } currentChildId={ currentChildId } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
