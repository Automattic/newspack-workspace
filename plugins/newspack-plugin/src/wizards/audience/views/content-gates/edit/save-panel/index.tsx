/**
 * Content Gate pre-save checklist panel.
 */

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import {
	Modal,
	Button,
	CheckboxControl,
	RadioControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useState, Fragment } from '@wordpress/element';
import { useReducedMotion } from '@wordpress/compose';

/**
 * Internal dependencies.
 */
import { Divider } from '../../../../../../../packages/components/src';
import './style.scss';

// Matches the slide-out keyframe duration in style.scss.
const SLIDE_OUT_MS = 200;

type SavePanelProps = {
	initialStatus: GateStatus;
	presaveChecksEnabled: boolean;
	summary: { label: string; content: React.ReactNode }[];
	isSaving: boolean;
	onCancel: () => void;
	onConfirm: ( args: { status: GateStatus; presaveChecksEnabled: boolean } ) => void;
};

const SavePanel = ( { initialStatus, presaveChecksEnabled, summary, isSaving, onCancel, onConfirm }: SavePanelProps ) => {
	const [ status, setStatus ] = useState< GateStatus >( initialStatus );
	const [ keepShowingChecks, setKeepShowingChecks ] = useState< boolean >( presaveChecksEnabled );
	const [ isClosing, setIsClosing ] = useState< boolean >( false );
	const reducedMotion = useReducedMotion();

	// The Cancel button unmounts the panel directly, so play the slide-out
	// ourselves before closing. Esc / click-outside already animate via the
	// Modal's built-in exit animation, which shares the same keyframe.
	const handleCancel = () => {
		if ( isSaving ) {
			return;
		}
		if ( reducedMotion ) {
			onCancel();
			return;
		}
		setIsClosing( true );
		setTimeout( onCancel, SLIDE_OUT_MS );
	};

	const handleSubmit = ( event: React.FormEvent ) => {
		event.preventDefault();
		onConfirm( { status, presaveChecksEnabled: keepShowingChecks } );
	};

	return (
		<Modal
			__experimentalHideHeader
			className="newspack-content-gate-save-panel"
			overlayClassName={ classnames( 'newspack-content-gate-save-panel__overlay', { 'is-animating-out': isClosing } ) }
			onRequestClose={ onCancel }
			shouldCloseOnEsc={ ! isSaving }
			shouldCloseOnClickOutside={ ! isSaving }
		>
			<form className="newspack-content-gate-save-panel__form" onSubmit={ handleSubmit }>
				<div className="newspack-content-gate-save-panel__actions">
					<Button variant="secondary" onClick={ handleCancel } disabled={ isSaving }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" type="submit" isBusy={ isSaving } disabled={ isSaving }>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</div>
				<div className="newspack-content-gate-save-panel__content">
					<VStack className="newspack-content-gate-save-panel__body" spacing={ 4 }>
						<VStack className="newspack-content-gate-save-panel__intro" spacing={ 2 }>
							<span>
								<strong>{ __( 'Are you ready to save?', 'newspack-plugin' ) }</strong>
							</span>
							<span>{ __( 'Double-check your settings before saving.', 'newspack-plugin' ) }</span>
						</VStack>
						<Divider className="newspack-content-gate-save-panel__divider" marginBottom={ 0 } marginTop={ 0 } />
						<RadioControl
							className="newspack-content-gate-save-panel__status"
							label={ __( 'Status', 'newspack-plugin' ) }
							selected={ status }
							options={ [
								{ label: __( 'Active', 'newspack-plugin' ), value: 'publish' },
								{ label: __( 'Inactive', 'newspack-plugin' ), value: 'draft' },
							] }
							onChange={ value => setStatus( value as GateStatus ) }
						/>
						<div className="newspack-content-gate-save-panel__summary">
							{ summary.map( ( row, index ) => (
								<Fragment key={ index }>
									<Divider className="newspack-content-gate-save-panel__divider" marginBottom={ 0 } marginTop={ 0 } />
									<VStack className="newspack-content-gate-save-panel__summary-row" spacing={ 2 }>
										<h4 className="newspack-content-gate-save-panel__summary-label">{ row.label }</h4>
										<div className="newspack-content-gate-save-panel__summary-value">{ row.content }</div>
									</VStack>
								</Fragment>
							) ) }
							<Divider className="newspack-content-gate-save-panel__divider" marginBottom={ 0 } marginTop={ 0 } />
						</div>
					</VStack>
					<CheckboxControl
						__nextHasNoMarginBottom
						className="newspack-content-gate-save-panel__always-show"
						label={ __( 'Always show pre-save checks.', 'newspack-plugin' ) }
						checked={ keepShowingChecks }
						onChange={ setKeepShowingChecks }
					/>
				</div>
			</form>
		</Modal>
	);
};

export default SavePanel;
