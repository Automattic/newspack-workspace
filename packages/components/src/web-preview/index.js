/**
 * WordPress dependencies.
 */
import { Component, createRef, Fragment, createPortal } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { close, desktop, mobile, tablet, check } from '@wordpress/icons';
import { Button, DropdownMenu, MenuItem } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Waiting } from '../';
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

const PORTAL_PARENT_ID = 'newspack-components-web-preview-wrapper';

/**
 * Web Preview. A component to preview a website in an iframe, with device sizing options.
 */
class WebPreview extends Component {
	state = {
		device: 'desktop',
		loaded: false,
		isPreviewVisible: false,
	};

	constructor( props ) {
		super( props );
		this.iframeRef = createRef();
	}

	/**
	 * If a div with id PORTAL_PARENT_ID exists, assign it to class field.
	 * If not, create it and append to the body.
	 */
	componentDidMount() {
		const existingDOMElement = document.getElementById( PORTAL_PARENT_ID );
		this.modalDOMElement = existingDOMElement || document.createElement( 'div' );
		if ( ! existingDOMElement ) {
			this.modalDOMElement.id = PORTAL_PARENT_ID;
			document.body.appendChild( this.modalDOMElement );
		}
	}

	/**
	 * Add or remove applicable body classes and keyboard event listeners
	 */
	componentDidUpdate( prevProps, prevState ) {
		if ( this.state.isPreviewVisible === true ) {
			document.body.classList.add( 'newspack-web-preview--open' );
			if ( prevState.isPreviewVisible === false ) {
				// Add event listener when modal opens
				document.addEventListener( 'keydown', this.handleKeyDown );
			}
		} else {
			document.body.classList.remove( 'newspack-web-preview--open' );
			if ( prevState.isPreviewVisible === true ) {
				// Remove event listener when modal closes
				document.removeEventListener( 'keydown', this.handleKeyDown );
			}
		}
	}

	/**
	 * Clean up event listeners on unmount
	 */
	componentWillUnmount() {
		document.removeEventListener( 'keydown', this.handleKeyDown );
		document.body.classList.remove( 'newspack-web-preview--open' );
	}

	/**
	 * Handle keyboard events
	 */
	handleKeyDown = event => {
		if ( event.key === 'Escape' && this.state.isPreviewVisible ) {
			this.closePreview();
		}
	};

	/**
	 * Close the preview modal
	 */
	closePreview = () => {
		const { onClose = () => {} } = this.props;
		onClose();
		this.setState( { isPreviewVisible: false, loaded: false } );
	};

	/**
	 * Create JSX for the modal
	 */
	getWebPreviewModal = () => {
		const { beforeLoad = () => {}, url, title } = this.props;
		const { device, loaded, isPreviewVisible } = this.state;

		if ( ! this.modalDOMElement || ! isPreviewVisible ) {
			return null;
		}

		const classes = classnames( 'newspack-web-preview', device, loaded && 'is-loaded' );
		let icon = mobile;
		if ( device === 'desktop' ) {
			icon = desktop;
		} else if ( device === 'tablet' ) {
			icon = tablet;
		}
		beforeLoad();
		return createPortal(
			<div className={ classes }>
				<div className="newspack-web-preview__interior">
					<div className="newspack-web-preview__toolbar">
						{ title && (
							<div className="newspack-web-preview__toolbar-left">
								<h3>{ title }</h3>
							</div>
						) }
						<div className="newspack-web-preview__toolbar-right">
							<DropdownMenu icon={ icon } label={ __( 'Preview Size', 'newspack-plugin' ) }>
								{ ( { onClose: onDropdownClose } ) => (
									<>
										<MenuItem
											icon={ device === 'desktop' ? check : null }
											role="menuitemradio"
											isSelected={ device === 'desktop' }
											onClick={ () => {
												this.setState( { device: 'desktop' } );
												onDropdownClose();
											} }
										>
											{ __( 'Desktop', 'newspack-plugin' ) }
										</MenuItem>
										<MenuItem
											icon={ device === 'tablet' ? check : null }
											role="menuitemradio"
											isSelected={ device === 'tablet' }
											onClick={ () => {
												this.setState( { device: 'tablet' } );
												onDropdownClose();
											} }
										>
											{ __( 'Tablet', 'newspack-plugin' ) }
										</MenuItem>
										<MenuItem
											icon={ device === 'phone' ? check : null }
											role="menuitemradio"
											isSelected={ device === 'phone' }
											onClick={ () => {
												this.setState( { device: 'phone' } );
												onDropdownClose();
											} }
										>
											{ __( 'Phone', 'newspack-plugin' ) }
										</MenuItem>
									</>
								) }
							</DropdownMenu>
							<Button onClick={ this.closePreview } icon={ close } label={ __( 'Close Preview', 'newspack-plugin' ) } />
						</div>
					</div>
					<div className="newspack-web-preview__content">
						{ ! loaded && (
							<div className="newspack-web-preview__is-waiting">
								<Waiting isLeft />
								{ __( 'Loading…', 'newspack-plugin' ) }
							</div>
						) }
						<iframe
							ref={ this.iframeRef }
							title="web-preview"
							src={ url }
							onLoad={ () => {
								this.setState( { loaded: true } );
								this.props.onLoad( this.iframeRef.current );
							} }
						/>
					</div>
				</div>
			</div>,
			this.modalDOMElement
		);
	};

	showPreview = () => {
		this.setState( { isPreviewVisible: true } );
	};

	/**
	 * Render.
	 */
	render() {
		const {
			label,
			variant,
			/**
			 * Inversion of control - let the caller render
			 * the button that will trigger the modal
			 */
			renderButton,
		} = this.props;

		return (
			<Fragment>
				{ renderButton ? (
					renderButton( { showPreview: this.showPreview } )
				) : (
					<Button variant={ variant } onClick={ this.showPreview } tabIndex="0">
						{ label }
					</Button>
				) }
				{ this.getWebPreviewModal() }
			</Fragment>
		);
	}
}

WebPreview.defaultProps = {
	url: '//newspack.com',
	label: __( 'Preview', 'newspack-plugin' ),
	onLoad: () => {},
};

export default WebPreview;
