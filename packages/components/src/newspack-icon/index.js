/**
 * Newspack Icon.
 */

/**
 * WordPress dependencies.
 */
import { Component } from '@wordpress/element';
import { Path, SVG } from '@wordpress/components';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import './style.scss';

class NewspackIcon extends Component {
	/**
	 * Render
	 */
	render() {
		const { className, simple, size, white } = this.props;
		const classes = classnames( 'newspack-icon', simple && 'newspack-icon--simple', white && 'newspack-icon--white', className );
		return (
			<SVG xmlns="http://www.w3.org/2000/svg" height={ size } width={ size } viewBox="0 0 24 24" className={ classes }>
				<Path
					fillRule="evenodd"
					clipRule="evenodd"
					d="M24 12C24 18.6271 18.6271 24 12 24C5.37213 24 0 18.6271 0 12C0 5.3729 5.3729 0 12 0C18.6271 0 24 5.3729 24 12ZM17.4545 17.4546L6.54545 6.54545V17.4545H8.72727V11.8182L14.3636 17.4546H17.4545ZM11.2727 8.18182H17.4545V6.54545H9.63636L11.2727 8.18182ZM17.4545 11.2727H14.3636L12.7273 9.63636H17.4545V11.2727ZM17.4545 12.7273V14.3636L15.8182 12.7273H17.4545Z"
				/>
			</SVG>
		);
	}
}

NewspackIcon.defaultProps = {
	size: 32,
};

export default NewspackIcon;
