/**
 * Disables the "two click" publishing sidebar of the block editor.
 *
 * See https://github.com/WordPress/gutenberg/issues/9077#issuecomment-458309231
 *
 * Since WordPress 7.0 this action writes into the core/preferences store, which
 * persists the choice in the user meta. The script is therefore only enqueued
 * once per user, so the pre-publish checks can be turned back on afterwards.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.domReady || ! wp.data ) {
		return;
	}

	wp.domReady( function () {
		var editor = wp.data.dispatch( 'core/editor' );

		if ( ! editor || typeof editor.disablePublishSidebar !== 'function' ) {
			return;
		}

		editor.disablePublishSidebar();
	} );
} )( window.wp );
