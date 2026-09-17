( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var enabled = document.getElementById( 'leadwerk-automation-enabled' );
		if ( ! enabled ) {
			return;
		}

		var form = enabled.closest( 'form' );
		var syncState = function () {
			form.classList.toggle( 'is-disabled', ! enabled.checked );
		};

		enabled.addEventListener( 'change', syncState );
		syncState();
	} );
}() );
