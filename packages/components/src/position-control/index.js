/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ButtonGroup, Button } from '@wordpress/components';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import './style.scss';

export default function PositionControl( { allowedPositions, value, label, help, onChange, size, ...props } ) {
	/**
	 * Set layout options
	 */
	const options =
		size === 'full-width'
			? [
					{
						value: 'top',
						/* translators: Overlay Position */
						label: __( 'Top', 'newspack-plugin' ),
					},
					{
						value: 'center',
						/* translators: Overlay Position */
						label: __( 'Center', 'newspack-plugin' ),
					},
					{
						value: 'bottom',
						/* translators: Overlay Position */
						label: __( 'Bottom', 'newspack-plugin' ),
					},
			  ]
			: [
					{
						value: 'top_left',
						/* translators: Overlay Position */
						label: __( 'Top Left', 'newspack-plugin' ),
					},
					{
						value: 'top',
						/* translators: Overlay Position */
						label: __( 'Top Center', 'newspack-plugin' ),
					},
					{
						value: 'top_right',
						/* translators: Overlay Position */
						label: __( 'Top Right', 'newspack-plugin' ),
					},
					{
						value: 'center_left',
						/* translators: Overlay Position */
						label: __( 'Center Left', 'newspack-plugin' ),
					},
					{
						value: 'center',
						/* translators: Overlay Position */
						label: __( 'Center', 'newspack-plugin' ),
					},
					{
						value: 'center_right',
						/* translators: Overlay Position */
						label: __( 'Center Right', 'newspack-plugin' ),
					},
					{
						value: 'bottom_left',
						/* translators: Overlay Position */
						label: __( 'Bottom Left', 'newspack-plugin' ),
					},
					{
						value: 'bottom',
						/* translators: Overlay Position */
						label: __( 'Bottom Center', 'newspack-plugin' ),
					},
					{
						value: 'bottom_right',
						/* translators: Overlay Position */
						label: __( 'Bottom Right', 'newspack-plugin' ),
					},
			  ];
	return (
		<div className={ classnames( 'newspack-position-placement-control', 'size-' + size ) }>
			<p className="components-base-control__label">{ label }</p>
			<ButtonGroup aria-label={ __( 'Select Position', 'newspack-plugin' ) } { ...props }>
				{ options.map( ( option, index ) => {
					return (
						<div key={ `newspack-position-placement-item-${ index }` } className={ option.value === value ? 'is-selected' : null }>
							<Button
								isSmall
								title={ option.label }
								aria-label={ option.label }
								isPrimary={ option.value === value }
								onClick={ () => {
									onChange( option.value );
								} }
								disabled={ allowedPositions?.length && ! allowedPositions.includes( option.value ) }
							/>
						</div>
					);
				} ) }
			</ButtonGroup>
			<p className="components-base-control__help">{ help }</p>
		</div>
	);
}
