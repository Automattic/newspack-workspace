/**
 * Popup style options.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useEffect } from '@wordpress/element';

const StylesSidebar = props => {
	const { onMetaFieldChange, hide_border, large_border, no_padding, isOverlay } = props;

	useEffect( () => {
		if ( ! isOverlay && no_padding ) {
			onMetaFieldChange( { hide_border: false, large_border: false, no_padding: false } );
		}
	}, [ isOverlay ] );

	return (
		<div className="newspack-popups-style-selector">
			<Button
				variant={ ! hide_border && ! large_border && ! no_padding ? 'primary' : 'secondary' }
				isPressed={ ! hide_border && ! large_border && ! no_padding }
				onClick={ () => onMetaFieldChange( { hide_border: false, large_border: false, no_padding: false } ) }
				aria-current={ ! hide_border && ! large_border && ! no_padding }
			>
				{ __( 'Default', 'newspack-popups' ) }
			</Button>
			<Button
				variant={ hide_border ? 'primary' : 'secondary' }
				isPressed={ hide_border }
				onClick={ () => onMetaFieldChange( { hide_border: true, large_border: false, no_padding: false } ) }
				aria-current={ hide_border }
			>
				{ isOverlay ? __( 'Small Padding', 'newspack-popups' ) : __( 'Hide Border', 'newspack-popups' ) }
			</Button>
			<Button
				variant={ large_border ? 'primary' : 'secondary' }
				isPressed={ large_border }
				onClick={ () => onMetaFieldChange( { hide_border: false, large_border: true, no_padding: false } ) }
				aria-current={ large_border }
			>
				{ sprintf(
					// Translators: %s is "padding" if the prompt is inline, or "border" if not
					__( 'Large %s', 'newspack-popups' ),
					isOverlay ? __( 'Padding', 'newspack-popups' ) : __( 'Border', 'newspack-popups' )
				) }
			</Button>
			{ isOverlay && (
				<Button
					variant={ no_padding ? 'primary' : 'secondary' }
					isPressed={ no_padding }
					onClick={ () => onMetaFieldChange( { hide_border: false, large_border: false, no_padding: true } ) }
					aria-current={ no_padding }
				>
					{ __( 'No Padding', 'newspack-popups' ) }
				</Button>
			) }
		</div>
	);
};

export default StylesSidebar;
