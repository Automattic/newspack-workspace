/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { BaseControl, Button, DateTimePicker, PanelBody, PanelRow, ToggleControl } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';

/**
 * Matches `blocks/event-dates/block.json`'s `attributes`. `startDate`/`endDate`
 * are declared `type: "string"` there but default to `false` (and the
 * `DateTimePicker` below can also hand back `null`), so both fields are
 * widened to reflect the real runtime possibilities rather than just `string`.
 */
export type EventDatesAttributes = {
	startDate: string | false | null;
	endDate: string | false | null;
	showTime: boolean;
	showEnd: boolean;
};

type EventDatesEditorProps = {
	attributes: EventDatesAttributes;
	clientId: string;
	setAttributes: ( attributes: Partial< EventDatesAttributes > ) => void;
};

export const EventDatesEditor = ( { attributes, clientId, setAttributes }: EventDatesEditorProps ) => {
	const { endDate, showEnd, showTime, startDate } = attributes;
	const { createNotice } = useDispatch( 'core/notices' );
	const classes = [ 'newspack-listings__event-dates' ];

	if ( ! showTime ) {
		classes.push( 'hide-time' );
	}

	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Event Dates Settings' ) }>
					<PanelRow>
						<ToggleControl
							className="newspack-listings__event-time-toggle"
							label={ __( 'Show Times', 'newspack-listings' ) }
							checked={ showTime }
							onChange={ () => setAttributes( { showTime: ! showTime } ) }
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							className="newspack-listings__event-time-toggle"
							label={ sprintf(
								// translators: %s: end date/time label.
								__( 'Show End %s', 'newspack-listings' ),
								showTime ? __( 'Time', 'newspack-listings' ) : __( 'Date', 'newspack-listings' )
							) }
							checked={ showEnd }
							onChange={ () => {
								setAttributes( { showEnd: ! showEnd } );
								setAttributes( { endDate: '' } );
							} }
						/>
					</PanelRow>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className={ classes.join( ' ' ) }>
					<div className="newspack-listings__event-dates-controls">
						<BaseControl
							id={ `event-start-date-${ clientId }` }
							label={ sprintf(
								// translators: %1$s: start date/time label, %2$s: start date/time label.
								__( 'Event %1$s %2$s', 'newspack-listings' ),
								showEnd ? __( 'Start', 'newspack-listings' ) : '',
								showTime ? __( 'Time', 'newspack-listings' ) : __( 'Date', 'newspack-listings' )
							) }
						>
							<DateTimePicker
								currentDate={ startDate ? new Date( startDate ) : null }
								is12Hour={ true }
								onChange={ value => {
									if (
										! value || // If clearing the value.
										! endDate || // If there isn't an end date to compare with.
										// If there is an end date, and it's after the selected start date. `Date - Date`
										// isn't a TS-supported arithmetic operation (unlike plain JS); `getTime()` makes
										// the same numeric comparison explicit without changing the result.
										( endDate && 0 <= new Date( endDate ).getTime() - new Date( value ).getTime() )
									) {
										return setAttributes( { startDate: value } );
									}

									createNotice( 'warning', __( 'Event end must be after event start.', 'newspack-listings' ), {
										id: 'newspack-listings__date-error',
										isDismissible: true,
										type: 'default',
									} );
								} }
							/>
							{ ! showTime && startDate && (
								<Button isLink onClick={ () => setAttributes( { startDate: '' } ) }>
									{ __( 'Reset', 'newspack-listings' ) }
								</Button>
							) }
						</BaseControl>
						{ showEnd && (
							<BaseControl
								id={ `event-end-date-${ clientId }` }
								label={ sprintf(
									// translators: %s: end date/time label.
									__( 'Event End %s', 'newspack-listings' ),
									showTime ? __( 'Time', 'newspack-listings' ) : __( 'Date', 'newspack-listings' )
								) }
							>
								<DateTimePicker
									currentDate={ endDate ? new Date( endDate ) : null }
									is12Hour={ true }
									onChange={ value => {
										if (
											! value ||
											! startDate ||
											( startDate && 0 <= new Date( value ).getTime() - new Date( startDate ).getTime() )
										) {
											return setAttributes( { endDate: value } );
										}

										createNotice( 'warning', __( 'Event end must be after event start.', 'newspack-listings' ), {
											id: 'newspack-listings__date-error',
											isDismissible: true,
											type: 'default',
										} );
									} }
								/>
								{ ! showTime && endDate && (
									<Button isLink onClick={ () => setAttributes( { endDate: '' } ) }>
										{ __( 'Reset', 'newspack-listings' ) }
									</Button>
								) }
							</BaseControl>
						) }
					</div>
				</div>
			</div>
		</>
	);
};
