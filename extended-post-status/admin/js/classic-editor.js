/**
 * Adds the custom statuses to the status dropdown of the classic editor.
 *
 * There is no core hook to add entries to that dropdown, see
 * https://core.trac.wordpress.org/ticket/12706
 *
 * All values are inserted through DOM methods instead of HTML strings, so a
 * status name can never break out of the markup.
 */
( function ( $ ) {
	'use strict';

	var data = window.extendedPostStatusClassicEditor || {};
	var statuses = data.statuses || [];
	var currentStatus = data.currentStatus || '';

	if ( ! statuses.length ) {
		return;
	}

	$( function () {
		var $select = $( 'select#post_status' );
		var $display = $( '#post-status-display' );
		var currentName = '';

		statuses.forEach( function ( status ) {
			var isCurrent = status.slug === currentStatus;

			if ( isCurrent ) {
				currentName = status.name;
			}

			if ( ! $select.length ) {
				return;
			}

			var $option = $( '<option></option>' )
				.attr( 'value', status.slug )
				.text( status.name );

			if ( isCurrent ) {
				$option.prop( 'selected', true );
			}

			$select.append( $option );
		} );

		// Core falls back to "Draft" for statuses it does not know, so the
		// label next to "Status:" has to be replaced with the real name.
		if ( currentName && $display.length ) {
			$display.text( currentName );
		}
	} );
} )( jQuery );
