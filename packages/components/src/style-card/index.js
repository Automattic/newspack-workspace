/**
 * Style Card
 */

/**
 * WordPress dependencies.
 */
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { WebPreview } from '../';
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';

class StyleCard extends Component {
	/**
	 * Render.
	 */
	render() {
		const { ariaLabel, className, cardTitle, url, image, imageType, isActive, onClick, id } = this.props;
		const classes = classnames( 'newspack-style-card', isActive && 'newspack-style-card__is-active', className );
		return (
			<div className={ classes } id={ id }>
				<div className="newspack-style-card__image">
					{ imageType === 'html' ? (
						<div className="newspack-style-card__image-html" dangerouslySetInnerHTML={ image } />
					) : (
						<img src={ image } alt={ cardTitle + ' ' + __( 'Thumbnail', 'newspack-plugin' ) } />
					) }
					<div className="newspack-style-card__actions">
						{ isActive ? (
							<span className="newspack-style-card__actions__badge">{ __( 'Selected', 'newspack-plugin' ) }</span>
						) : (
							<Button
								variant="link"
								onClick={ onClick }
								aria-label={ ariaLabel ? ariaLabel : __( 'Select', 'newspack-plugin' ) + ' ' + cardTitle }
								tabIndex="0"
							>
								{ __( 'Select', 'newspack-plugin' ) }
							</Button>
						) }
						{ url && <WebPreview url={ url } label={ __( 'View Demo', 'newspack-plugin' ) } variant="link" /> }
					</div>
				</div>
				{ cardTitle && <div className="newspack-style-card__title">{ cardTitle }</div> }
			</div>
		);
	}
}

export default StyleCard;
