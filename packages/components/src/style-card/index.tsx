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

type StyleCardProps = {
	/** Accessible label for the select button. */
	ariaLabel?: string;
	/** Additional CSS class name. */
	className?: string;
	/** Title displayed under the card. */
	cardTitle?: string;
	/** Demo URL, previewed via WebPreview. */
	url?: string;
	/** Whether the card is the selected one. */
	isActive?: boolean;
	/** Called when the card is selected. */
	onClick?: () => void;
	/** ID for the card element. */
	id?: string;
} & (
	| {
			/** Renders `image` as raw HTML. */
			imageType: 'html';
			/** The card's image, as a raw-HTML object. */
			image: { __html: string };
	  }
	| {
			imageType?: undefined;
			/** The card's image URL. */
			image?: string;
	  }
);

class StyleCard extends Component< StyleCardProps > {
	/**
	 * Render.
	 */
	render() {
		const props = this.props;
		const { ariaLabel, className, cardTitle, url, isActive, onClick, id } = props;
		const classes = classnames( 'newspack-style-card', isActive && 'newspack-style-card__is-active', className );
		return (
			<div className={ classes } id={ id }>
				<div className="newspack-style-card__image">
					{ props.imageType === 'html' ? (
						<div className="newspack-style-card__image-html" dangerouslySetInnerHTML={ props.image } />
					) : (
						<img src={ props.image } alt={ cardTitle + ' ' + __( 'Thumbnail', 'newspack-plugin' ) } />
					) }
					<div className="newspack-style-card__actions">
						{ isActive ? (
							<span className="newspack-style-card__actions__badge">{ __( 'Selected', 'newspack-plugin' ) }</span>
						) : (
							<Button
								variant="link"
								onClick={ onClick }
								aria-label={ ariaLabel ? ariaLabel : __( 'Select', 'newspack-plugin' ) + ' ' + cardTitle }
								tabIndex={ 0 }
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
