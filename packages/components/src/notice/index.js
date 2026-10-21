/**
 * Notice
 */

/**
 * WordPress dependencies.
 */
import { Component, RawHTML } from '@wordpress/element';
import { Icon, check, help, info } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

class Notice extends Component {
	/**
	 * Render
	 */
	render() {
		const { className, isError, isHandoff, isHelp, isSuccess, isWarning, noticeText, rawHTML, style = {}, children = null } = this.props;
		const classes = classnames(
			'newspack-notice',
			className,
			isError && 'newspack-notice__is-error',
			isHandoff && 'newspack-notice__is-handoff',
			isHelp && 'newspack-notice__is-help',
			isSuccess && 'newspack-notice__is-success',
			isWarning && 'newspack-notice__is-warning'
		);
		let noticeIcon;
		if ( isHelp ) {
			noticeIcon = help;
		} else if ( isSuccess ) {
			noticeIcon = check;
		} else {
			noticeIcon = info;
		}
		return (
			<div className={ classes } style={ style }>
				{ <Icon icon={ noticeIcon } /> }
				<div className="newspack-notice__content">
					{ rawHTML ? <RawHTML>{ noticeText }</RawHTML> : noticeText }
					{ children || null }
				</div>
			</div>
		);
	}
}

export default Notice;
