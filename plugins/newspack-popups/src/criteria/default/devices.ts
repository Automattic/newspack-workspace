import { setMatchingFunction } from '../utils';

setMatchingFunction( 'devices', ( config, ras, criteria ) => {
	// `config.value` is authored via the segment builder's device checkboxes,
	// which always store an array of device type names for the 'devices'
	// criteria -- not otherwise representable in `SegmentConfig[ 'value' ]`'s
	// general `unknown` shape.
	const selectedDevices = Array.isArray( config.value ) ? ( config.value as string[] ) : [];
	if ( selectedDevices.length === 0 ) {
		return false;
	}

	const width = window.innerWidth;

	// `optionParams` is always populated for the 'devices' criteria (each device
	// option's `{ min_width, max_width }`, from `Newspack_Popups_Model`), though
	// the shared `Criteria` type declares it as a generic, optional record since
	// not every criteria carries option params.
	const deviceOptionParams = criteria?.optionParams as Record< string, { min_width: number; max_width: number } >;

	return selectedDevices.some( deviceType => {
		const device = deviceOptionParams[ deviceType ];
		if ( isNaN( device?.min_width ) || isNaN( device?.max_width ) ) {
			return false;
		}

		return width >= device.min_width && width < device.max_width;
	} );
} );
