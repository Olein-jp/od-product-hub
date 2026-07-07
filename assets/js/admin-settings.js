( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		const button = event.target.closest( '.odph-copy-button' );
		if ( ! button ) {
			return;
		}
		const source = document.getElementById( button.dataset.copyTarget );
		const status = button.parentElement.querySelector( '.odph-copy-status' );
		if ( ! source || ! status || ! navigator.clipboard ) {
			status.textContent = odphSettingsCopy.error;
			return;
		}
		navigator.clipboard.writeText( source.textContent ).then(
			function () {
				status.textContent = odphSettingsCopy.success;
			},
			function () {
				status.textContent = odphSettingsCopy.error;
			}
		);
	} );
}() );
