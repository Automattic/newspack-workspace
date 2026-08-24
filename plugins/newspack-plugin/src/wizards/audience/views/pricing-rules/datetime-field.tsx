/**
 * A date and time entered through a popover picker. The toggle carries the
 * resolved value, so a schedule reads without opening anything.
 */

/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { getSettings, gmdateI18n } from '@wordpress/date';
import {
	BaseControl,
	Button,
	DateTimePicker,
	Dropdown,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { calendar } from '@wordpress/icons';

type WeekStart = 0 | 1 | 2 | 3 | 4 | 5 | 6;

interface DateTimeFieldProps {
	id: string;
	label: string;
	hideLabelFromVision?: boolean;
	help?: string;
	describedBy?: string;
	value: string;
	placeholder: string;
	disabled?: boolean;
	onChange: ( next: string ) => void;
}

// The value is a wall clock with no offset, so format it in UTC to get the same
// digits back; dateI18n would resolve against the site timezone and shift the day.
function formatValue( value: string ): string {
	const { formats } = getSettings();
	return gmdateI18n( `${ formats.date } ${ formats.time }`, `${ value }Z` );
}

// An escaped `\a` is a literal, not a meridiem marker; reversing lets one lookahead
// reject every escaped match, as core's PostSchedule does.
function is12HourFormat( format: string ): boolean {
	return /a(?!\\)/i.test( format.replace( /\\\\/g, '' ).split( '' ).reverse().join( '' ) );
}

// Left unseeded the picker labels its cells in UTC while selecting browser-local
// dates, so east of UTC the first pick lands a day early.
function nowAsWallClock(): string {
	const now = new Date();
	return new Date( now.getTime() - now.getTimezoneOffset() * 60000 ).toISOString().slice( 0, 16 );
}

export default function DateTimeField( {
	id,
	label,
	hideLabelFromVision,
	help,
	describedBy,
	value,
	placeholder,
	disabled,
	onChange,
}: DateTimeFieldProps ) {
	const settings = getSettings();
	const display = value ? formatValue( value ) : placeholder;
	const startOfWeek = ( Number( settings.l10n.startOfWeek ) || 0 ) as WeekStart;
	const ariaDescribedBy = [ help ? `${ id }__help` : null, describedBy ].filter( Boolean ).join( ' ' ) || undefined;

	return (
		<BaseControl id={ id } help={ help } __nextHasNoMarginBottom>
			{ ! hideLabelFromVision && <BaseControl.VisualLabel>{ label }</BaseControl.VisualLabel> }
			<Dropdown
				className="newspack-pricing-rules__datetime"
				popoverProps={ { placement: 'bottom-start', offset: 4 } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button
						id={ id }
						variant="secondary"
						icon={ calendar }
						label={ sprintf(
							/* translators: 1: the field label, e.g. "Starts". 2: the resolved date, or a placeholder. */
							_x( '%1$s: %2$s', 'field label and its current value', 'newspack-plugin' ),
							label,
							display
						) }
						showTooltip={ false }
						aria-describedby={ ariaDescribedBy }
						aria-expanded={ isOpen }
						onClick={ onToggle }
						disabled={ disabled }
						__next40pxDefaultSize
					>
						{ display }
					</Button>
				) }
				renderContent={ ( { onClose } ) => (
					<VStack spacing={ 2 }>
						<DateTimePicker
							currentDate={ value || nowAsWallClock() }
							is12Hour={ is12HourFormat( settings.formats.time ) }
							startOfWeek={ startOfWeek }
							onChange={ next => onChange( next ? next.slice( 0, 16 ) : '' ) }
						/>
						{ value && (
							<HStack justify="flex-start">
								<Button
									variant="tertiary"
									onClick={ () => {
										onChange( '' );
										onClose();
									} }
								>
									{ __( 'Clear', 'newspack-plugin' ) }
								</Button>
							</HStack>
						) }
					</VStack>
				) }
			/>
		</BaseControl>
	);
}
