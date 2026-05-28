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

type OriginalButtonProps = typeof BaseComponent.defaultProps;
type Props = OriginalButtonProps & {
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
	// @ts-expect-error - @wordpress/components' Button can only have either href or onClick, not both.
	return <BaseComponent loading={ loading ? true : undefined } { ...otherProps } />;
};

export default Button;
