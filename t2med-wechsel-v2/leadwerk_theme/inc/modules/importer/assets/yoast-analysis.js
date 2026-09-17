( function( $ ) {
	'use strict';

	const pluginName = 'LeadwerkT2medAnalysis';
	let registered = false;

	function registerAnalysis() {
		if (
			registered ||
			! window.leadwerkYoastAnalysis ||
			! window.leadwerkYoastAnalysis.content ||
			typeof window.YoastSEO === 'undefined' ||
			! window.YoastSEO.app
		) {
			return;
		}

		registered = true;
		window.YoastSEO.app.registerPlugin( pluginName, { status: 'ready' } );
		window.YoastSEO.app.registerModification(
			'content',
			function( content ) {
				if ( typeof content !== 'string' ) {
					return content;
				}
				if (
					window.leadwerkYoastAnalysis.marker &&
					content.indexOf( window.leadwerkYoastAnalysis.marker ) !== -1
				) {
					return content;
				}
				return content
					? content + '\n' + window.leadwerkYoastAnalysis.content
					: window.leadwerkYoastAnalysis.content;
			},
			pluginName,
			10
		);
	}

	if ( typeof window.YoastSEO !== 'undefined' && window.YoastSEO.app ) {
		registerAnalysis();
	} else {
		$( window ).on( 'YoastSEO:ready', registerAnalysis );
	}
}( window.jQuery ) );
