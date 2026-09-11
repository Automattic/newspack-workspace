/**
 * Button
 */

/**
 * WordPress dependencies.
 */
import { Button as BaseComponent } from '@wordpress/components';
import { forwardRef, useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Router from '../proxied-imports/router';
import './style.scss';

const { useHistory } = Router;

type OriginalButtonProps = typeof BaseComponent.defaultProps;
type Props = OriginalButtonProps & {
	href?: string;
	loading?: boolean;
	onClick?: ( event?: React.MouseEvent< HTMLElement > ) => void;
};

// Control characters are stripped first, as browsers do, so `java\nscript:`
// cannot slip past.
const isJavascriptHref = ( href?: string ) =>
	'string' === typeof href &&
	href
		.replace( /[\u0000-\u0020]/g, '' )
		.toLowerCase()
		.startsWith( 'javascript:' );

const Button = forwardRef< HTMLElement, Props >( ( { href, loading = undefined, onClick, ...otherProps }: Props, ref ) => {
	const history = useHistory();
	const [ isAwaitingOnClick, setIsAwaitingOnClick ] = useState( false );

	const isUnsafeHref = isJavascriptHref( href );
	const safeHref = isUnsafeHref ? undefined : href;

	useEffect( () => {
		if ( 'production' === process.env.NODE_ENV || ! isUnsafeHref ) {
			return;
		}
		// eslint-disable-next-line no-console
		console.warn( 'Button: a `javascript:` href is not a link and has been dropped.' );
	}, [ isUnsafeHref ] );

	// If both onClick and href are present, await the onClick action an then redirect.
	if ( safeHref && onClick ) {
		( otherProps as Props ).onClick = async ( event?: React.MouseEvent< HTMLElement > ) => {
			setIsAwaitingOnClick( true );
			await onClick( event );
			setIsAwaitingOnClick( false );
			// Outside a Router the href can only mean a real URL.
			if ( history ) {
				history.push( safeHref.replace( '#', '' ) );
			} else {
				window.location.href = safeHref;
			}
		};
	} else {
		( otherProps as Props ).href = safeHref;
		( otherProps as Props ).onClick = onClick;
	}
	if ( isAwaitingOnClick ) {
		otherProps.disabled = true;
	}
	// `loading` is this component's own prop name; @wordpress/components' Button
	// expresses the same state as `isBusy`. Forwarding `loading` verbatim would
	// land it on the DOM node, which React rejects as a non-boolean attribute.
	// @ts-expect-error - @wordpress/components' Button can only have either href or onClick, not both.
	return <BaseComponent ref={ ref } isBusy={ loading ? true : undefined } { ...otherProps } />;
} );

Button.displayName = 'Button';

export default Button;
