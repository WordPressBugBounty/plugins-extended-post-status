/**
 * Adds the custom statuses to the quick edit and bulk edit status dropdowns.
 *
 * Earlier versions listened to the DOMSubtreeModified mutation event. Those
 * events were removed from Chrome 127 and from current Firefox versions, which
 * broke the dropdowns. Wrapping inlineEditPost.edit() and inlineEditPost.setBulk()
 * is the documented way to extend the inline editor and works in WordPress 5, 6
 * and 7 alike.
 */
( function ( $ ) {
	'use strict';

	var data = window.extendedPostStatusQuickEdit || {};
	var statuses = data.statuses || [];

	if ( ! statuses.length || typeof window.inlineEditPost === 'undefined' ) {
		return;
	}

	var OPTION_CLASS = 'extended-post-status-option';

	/**
	 * Appends the custom statuses to a status dropdown.
	 *
	 * A status flagged as hidden is only offered when the edited post already
	 * has it, otherwise saving would silently change the status.
	 *
	 * @param {Object} $select      The status select element.
	 * @param {string} visibleSlug  Slug that stays selectable even when hidden.
	 */
	function appendStatuses( $select, visibleSlug ) {
		if ( ! $select.length ) {
			return;
		}

		// The edit row is cloned from a template that may already carry the
		// options of a previous run.
		$select.find( 'option.' + OPTION_CLASS ).remove();

		statuses.forEach( function ( status ) {
			if ( status.hidden && status.slug !== visibleSlug ) {
				return;
			}

			$( '<option></option>' )
				.addClass( OPTION_CLASS )
				.attr( 'value', status.slug )
				.text( status.name )
				.appendTo( $select );
		} );
	}

	var wpInlineEdit = window.inlineEditPost.edit;

	window.inlineEditPost.edit = function ( id ) {
		wpInlineEdit.apply( this, arguments );

		var postId = typeof id === 'object' ? this.getId( id ) : id;
		postId = parseInt( postId, 10 );

		if ( ! postId ) {
			return;
		}

		// Core stores the current status in the hidden inline data of the row.
		var currentStatus = $.trim( $( '#inline_' + postId + ' ._status' ).text() );
		var $select = $( '#edit-' + postId ).find( 'select[name="_status"]' );

		appendStatuses( $select, currentStatus );

		// Core already tried to preselect the status before the options
		// existed, so it has to be applied again.
		if ( currentStatus && $select.find( 'option[value="' + currentStatus + '"]' ).length ) {
			$select.val( currentStatus );
		}
	};

	var wpSetBulk = window.inlineEditPost.setBulk;

	window.inlineEditPost.setBulk = function () {
		wpSetBulk.apply( this, arguments );

		// Bulk editing applies one status to many posts, so a hidden status
		// must never be offered here.
		appendStatuses( $( '#bulk-edit' ).find( 'select[name="_status"]' ), '' );
	};
} )( jQuery );
