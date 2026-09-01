<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following flow
 * of control:
 *
 * - This method should be static
 * - Check if the $_REQUEST content actually is the plugin name
 * - Run an admin referrer check to make sure it goes through authentication
 * - Verify the output of $_GET makes sense
 * - Repeat with other user roles. Best directly by using the links/query string parameters.
 * - Repeat things for multisite. Once for a single site in the network, once sitewide.
 *
 * This file may be updated more in future version of the Boilerplate; however, this is the
 * general skeleton and outline for how the file should work.
 *
 * For more information, see the following discussion:
 * https://github.com/tommcfarlin/WordPress-Plugin-Boilerplate/pull/123#issuecomment-28541913
 *
 * @link       http://www.felixwelberg.de/
 * @since      1.0.0
 */
// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Reset all posts with a custom status to status draft, so the posts don't
 * get lost and still appear in the backend.
 *
 * The status definitions themselves are kept on purpose, so reinstalling the
 * plugin restores them.
 *
 * @since    1.0.16
 */
// reregister taxonomy (plugin is already deactivated, so the taxonomy is no
// longer available!)
register_taxonomy('status', 'post');
$args = [
    'taxonomy' => 'status',
    'hide_empty' => false,
];
$custom_status = get_terms($args);

if (!empty($custom_status) && !is_wp_error($custom_status)) {
    global $wpdb;

    /*
     * The post_status column holds the slug of a status, not its name. Earlier
     * versions compared against the name, which silently matched nothing as
     * soon as a status was named differently from its slug.
     */
    $status_list = wp_list_pluck($custom_status, 'slug');
    $placeholders = implode(', ', array_fill(0, count($status_list), '%s'));

    $post_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $status_list
        )
    );

    foreach ($post_ids as $post_id) {
        wp_update_post([
            'ID' => (int) $post_id,
            'post_status' => 'draft',
        ]);
    }
}

// Remove the settings of the plugin itself. Per status settings are kept
// together with the status terms.
delete_option('extended-post-status-add-extra-admin-menu-item');
delete_metadata('user', 0, '_extended_post_status_publish_sidebar_disabled', '', true);
