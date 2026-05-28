import { setMatchingFunction } from '../utils';

setMatchingFunction( 'devices', ( config, ras, { optionParams } ) => {
	const selectedDevices = Array.isArray( config.value ) ? config.value : [];
	if ( selectedDevices.length === 0 ) {
		return false;
	}

	const width = window.innerWidth;

	return selectedDevices.some( deviceType => {
		const device = optionParams[ deviceType ];
		if ( isNaN( device?.min_width ) || isNaN( device?.max_width ) ) {
			return false;
		}

		return width >= device.min_width && width < device.max_width;
	} );
} );
