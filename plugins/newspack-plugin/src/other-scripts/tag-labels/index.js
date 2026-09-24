/* globals jQuery */

( function ( $ ) {
	function toggleLabelSetting() {
		const checkbox = $( '.newspack-label-enable input[type="checkbox"]' );
		const labelSettingRow = $( '.newspack-label-setting' );

		if ( checkbox.is( ':checked' ) ) {
			labelSettingRow.show();
			labelSettingRow.find( 'input' ).prop( 'disabled', false );
		} else {
			labelSettingRow.hide();
			labelSettingRow.find( 'input' ).prop( 'disabled', true );
		}
	}

	// Set initial state on page load.
	toggleLabelSetting();

	// Update on checkbox change.
	$( '.newspack-label-enable input[type="checkbox"]' ).on( 'change', toggleLabelSetting );
} )( jQuery );
