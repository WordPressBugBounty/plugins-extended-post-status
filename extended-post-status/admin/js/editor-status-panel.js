/**
 * Adds a status control to the block editor sidebar.
 *
 * Core builds its own status control from a hard coded list of statuses, so a
 * custom status is rendered without a label there. This control lists the core
 * statuses together with the custom ones.
 *
 * Written without JSX on purpose, so the plugin needs no build step, and with
 * the withSelect/withDispatch higher order components instead of the useSelect
 * hooks, so it also works on WordPress 5.0.
 */
( function ( wp ) {
	'use strict';

	var data = window.extendedPostStatusEditor || {};
	var statuses = data.statuses || [];

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.compose || ! wp.components ) {
		return;
	}

	if ( ! statuses.length ) {
		return;
	}

	// PluginPostStatusInfo moved from wp.editPost (WordPress 5.0) to
	// wp.editor (WordPress 6.6). The old location stays available as a
	// deprecated alias, so the new one is preferred.
	var PluginPostStatusInfo =
		( wp.editor && wp.editor.PluginPostStatusInfo ) ||
		( wp.editPost && wp.editPost.PluginPostStatusInfo );

	if ( ! PluginPostStatusInfo ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n ? wp.i18n.__ : function ( text ) {
		return text;
	};

	/**
	 * Renders the status dropdown.
	 *
	 * @param {Object} props Injected by withSelect and withDispatch.
	 * @return {Object} The element.
	 */
	function StatusControl( props ) {
		var options = statuses.map( function ( status ) {
			return { label: status.name, value: status.slug };
		} );

		// A new post starts as 'auto-draft' and a scheduled post uses 'future'.
		// Neither is offered as a choice, but the control must still show the
		// current value instead of silently falling back to the first option.
		var isKnown = options.some( function ( option ) {
			return option.value === props.status;
		} );

		if ( props.status && ! isKnown ) {
			options.unshift( { label: props.status, value: props.status } );
		}

		return el(
			PluginPostStatusInfo,
			{ className: 'extended-post-status-info' },
			el( wp.components.SelectControl, {
				label: __( 'Status', 'extended-post-status' ),
				value: props.status,
				options: options,
				onChange: props.setStatus,
				__nextHasNoMarginBottom: true
			} )
		);
	}

	var ConnectedStatusControl = wp.compose.compose(
		wp.data.withSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return {
				status: editor ? editor.getEditedPostAttribute( 'status' ) : ''
			};
		} ),
		wp.data.withDispatch( function ( dispatch ) {
			return {
				setStatus: function ( status ) {
					dispatch( 'core/editor' ).editPost( { status: status } );
				}
			};
		} )
	)( StatusControl );

	wp.plugins.registerPlugin( 'extended-post-status', {
		render: ConnectedStatusControl
	} );
} )( window.wp );
