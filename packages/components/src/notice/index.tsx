/**
 * Notice
 */

/**
 * WordPress dependencies.
 */
import { Component, RawHTML } from '@wordpress/element';
import { Icon, bug, check, help, info } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

type NoticeProps = {
	/** Additional CSS class name. */
	className?: string;
	/** Whether the notice is a debug-mode notice. */
	debugMode?: boolean;
	/** Whether the notice is an error notice. */
	isError?: boolean;
	/** Whether the notice is a handoff notice. */
	isHandoff?: boolean;
	/** Whether the notice is a help notice. */
	isHelp?: boolean;
	/** Whether the notice is a success notice. */
	isSuccess?: boolean;
	/** Whether the notice is a warning notice. */
	isWarning?: boolean;
	/** The notice content. A plain-HTML string when `rawHTML` is set. */
	noticeText?: React.ReactNode;
	/** Whether to render `noticeText` as raw HTML. */
	rawHTML?: boolean;
	/** Inline styles for the notice element. */
	style?: React.CSSProperties;
	children?: React.ReactNode;
};

class Notice extends Component< NoticeProps > {
	/**
	 * Render
	 */
	render() {
		const {
			className,
			debugMode,
			isError,
			isHandoff,
			isHelp,
			isSuccess,
			isWarning,
			noticeText,
			rawHTML,
			style = {},
			children = null,
		} = this.props;
		const classes = classnames(
			'newspack-notice',
			className,
			debugMode && 'newspack-notice__is-debug',
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
		} else if ( debugMode ) {
			noticeIcon = bug;
		} else {
			noticeIcon = info;
		}
		return (
			<div className={ classes } style={ style }>
				{ <Icon icon={ noticeIcon } /> }
				<div className="newspack-notice__content">
					{ rawHTML ? <RawHTML>{ noticeText as string }</RawHTML> : noticeText }
					{ children || null }
				</div>
			</div>
		);
	}
}

export default Notice;
