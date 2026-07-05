/**
 * Button
 */

/**
 * WordPress dependencies.
 */
import { Button as BaseComponent } from '@wordpress/components';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Router from '../proxied-imports/router';
import './style.scss';

const { useHistory } = Router;

type OriginalButtonProps = Partial< React.ComponentProps< typeof BaseComponent > >;
type Props = Omit< OriginalButtonProps, 'href' | 'onClick' > & {
	href?: string;
	loading?: boolean;
	onClick?: () => void;
};

const Button = ( { href, loading = undefined, onClick, ...otherProps }: Props ) => {
	const history = useHistory();
	const [ isAwaitingOnClick, setIsAwaitingOnClick ] = useState( false );

	// If both onClick and href are present, await the onClick action an then redirect.
	if ( href && onClick ) {
		( otherProps as Props ).onClick = async () => {
			setIsAwaitingOnClick( true );
			await onClick();
			setIsAwaitingOnClick( false );
			history.push( ( href || '' ).replace( '#', '' ) );
		};
	} else {
		( otherProps as Props ).href = href;
		( otherProps as Props ).onClick = onClick;
	}
	if ( isAwaitingOnClick ) {
		otherProps.disabled = true;
	}
	// `loading` isn't a typed @wordpress/components Button prop; forwarded via spread for prop-parity.
	// The cast crosses Button's button/anchor union: href and onClick are reassigned above, which
	// the flattened rest type can't express.
	return <BaseComponent { ...{ loading: loading ? true : undefined } } { ...( otherProps as React.ComponentProps< typeof BaseComponent > ) } />;
};

export default Button;
