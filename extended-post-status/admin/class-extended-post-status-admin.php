<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       http://www.felixwelberg.de/
 * @since      1.0.0
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @author     Felix Welberg <felix@welberg.de>
 */
class Extended_Post_Status_Admin
{

    /**
     * The taxonomy used to store the custom statuses.
     *
     * @since    1.1.0
     * @var      string
     */
    const TAXONOMY = 'status';

    /**
     * The option prefix used to store the settings of a single status.
     *
     * @since    1.1.0
     * @var      string
     */
    const SETTINGS_OPTION_PREFIX = 'taxonomy_term_';

    /**
     * All available status settings and their default values.
     *
     * This is the single source of truth for the settings. It is used to render
     * the form, to sanitize the submitted values and to normalize the stored
     * values, so a status can never be missing a setting.
     *
     * @since    1.1.0
     * @var      array
     */
    private static $setting_defaults = [
        'public' => 0,
        'show_in_admin_all_list' => 0,
        'show_in_admin_status_list' => 0,
        'hide_in_drop_down' => 0,
    ];

    /**
     * Runtime cache for the custom status terms.
     *
     * @since    1.1.0
     * @var      array|null
     */
    private static $status_cache = null;

    /**
     * Runtime cache for the status settings, keyed by term id.
     *
     * @since    1.1.0
     * @var      array
     */
    private static $settings_cache = [];

    /**
     * Runtime cache for wp_count_posts() results, keyed by post type.
     *
     * @since    1.1.0
     * @var      array
     */
    private static $count_cache = [];

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string    $plugin_name       The name of this plugin.
     * @param      string    $version    The version of this plugin.
     */
    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * Returns all custom status terms
     *
     * The result is cached for the current request, because the statuses are
     * needed on every single request to register the post statuses.
     *
     * @return array
     * @since    1.0.0
     */
    public static function get_status()
    {
        if (null !== self::$status_cache) {
            return self::$status_cache;
        }

        // The taxonomy is not available before the 'init' action and it is gone
        // while the plugin is being uninstalled.
        if (!taxonomy_exists(self::TAXONOMY)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'update_term_meta_cache' => false,
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            $terms = [];
        }

        self::$status_cache = $terms;

        return self::$status_cache;
    }

    /**
     * Returns the settings of a single status
     *
     * Always returns an array containing every known setting normalized to 0 or
     * 1. The stored option can be missing entirely or can be missing single
     * keys when it was written by an older version of the plugin, so callers
     * must never access the raw option.
     *
     * @param int $term_id
     * @return array
     * @since    1.1.0
     */
    public static function get_status_settings($term_id)
    {
        $term_id = (int) $term_id;

        if (isset(self::$settings_cache[$term_id])) {
            return self::$settings_cache[$term_id];
        }

        $stored = get_option(self::SETTINGS_OPTION_PREFIX . $term_id);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = [];
        foreach (self::$setting_defaults as $key => $default) {
            $settings[$key] = (isset($stored[$key]) && $stored[$key]) ? 1 : 0;
        }

        self::$settings_cache[$term_id] = $settings;

        return $settings;
    }

    /**
     * Drop the runtime caches
     *
     * Called whenever a status is created, updated or deleted, so code running
     * later in the same request sees the new values.
     *
     * @since    1.1.0
     */
    public static function flush_status_cache()
    {
        self::$status_cache = null;
        self::$settings_cache = [];
        self::$count_cache = [];
    }

    /**
     * Returns the statuses that should be offered in the admin dropdowns
     *
     * A status flagged as hidden is only included when the current post already
     * has it, otherwise saving the post would silently change its status.
     *
     * @param string $current_status
     * @return array
     * @since    1.1.0
     */
    private static function get_selectable_status($current_status = '')
    {
        $selectable = [];
        foreach (self::get_status() as $single_status) {
            $settings = self::get_status_settings($single_status->term_id);
            if ($settings['hide_in_drop_down'] && $single_status->slug !== $current_status) {
                continue;
            }
            $selectable[] = [
                'slug' => $single_status->slug,
                'name' => $single_status->name,
            ];
        }
        return $selectable;
    }

    /**
     * Returns only the given status, if it is one of the custom ones
     *
     * Used to keep the current status available in a dropdown for users who are
     * not allowed to assign statuses.
     *
     * @param string $current_status
     * @return array
     * @since    1.1.0
     */
    private static function get_current_status_only($current_status)
    {
        foreach (self::get_status() as $single_status) {
            if ($single_status->slug === $current_status) {
                return [
                    [
                        'slug' => $single_status->slug,
                        'name' => $single_status->name,
                    ],
                ];
            }
        }

        return [];
    }

    /**
     * Checks whether the current user may assign a custom status
     *
     * Mirrors the capability check of the saving routine, so a status is never
     * offered to a user who is not allowed to apply it.
     *
     * @param string $post_type
     * @return bool
     * @since    1.1.0
     */
    private static function current_user_can_set_status($post_type = '')
    {
        $capability = 'publish_posts';

        if ($post_type) {
            $post_type_object = get_post_type_object($post_type);
            if ($post_type_object && isset($post_type_object->cap->publish_posts)) {
                $capability = $post_type_object->cap->publish_posts;
            }
        }

        return current_user_can($capability);
    }

    /**
     * Add the custom statuses to the classic editor status dropdown
     * The trac ticket is still open and there are no new changes until now, so
     * this is just a workaround :(
     * https://core.trac.wordpress.org/ticket/12706
     *
     * @global WP_Post $post
     * @param string $hook_suffix
     * @since    1.0.0
     */
    public function enqueue_classic_editor_script($hook_suffix)
    {
        if ('post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix) {
            return;
        }

        global $post;
        if (!$post instanceof WP_Post) {
            return;
        }

        if (self::current_user_can_set_status($post->post_type)) {
            $statuses = self::get_selectable_status($post->post_status);
        } else {
            /*
             * A user who may not assign a status still needs the current one in
             * the dropdown. Without it the browser would submit whatever option
             * happens to be selected and silently reset the post to a draft.
             */
            $statuses = self::get_current_status_only($post->post_status);
        }

        if (empty($statuses)) {
            return;
        }

        wp_enqueue_script(
            'extended-post-status-classic-editor',
            plugin_dir_url(__DIR__) . 'admin/js/classic-editor.js',
            ['jquery'],
            $this->version,
            true
        );

        // The data is passed as JSON and inserted through DOM methods in the
        // script, so status names can never break out of the markup.
        wp_localize_script(
            'extended-post-status-classic-editor',
            'extendedPostStatusClassicEditor',
            [
                'statuses' => $statuses,
                'currentStatus' => $post->post_status,
            ]
        );
    }

    /**
     * Add the custom statuses to the quick edit and bulk edit status dropdowns
     *
     * @param string $hook_suffix
     * @since    1.0.0
     */
    public function enqueue_quick_edit_script($hook_suffix)
    {
        if ('edit.php' !== $hook_suffix) {
            return;
        }

        $post_type = filter_input(INPUT_GET, 'post_type');
        if (!$post_type) {
            $post_type = 'post';
        }

        $can_set_status = self::current_user_can_set_status($post_type);

        $statuses = [];
        foreach (self::get_status() as $single_status) {
            $settings = self::get_status_settings($single_status->term_id);

            /*
             * A hidden status is only offered for the row that already has it.
             * Marking every status as hidden therefore keeps the current status
             * of a row intact for users who may not assign statuses, without
             * offering them any new one.
             */
            $hidden = ($can_set_status && !$settings['hide_in_drop_down']) ? 0 : 1;

            $statuses[] = [
                'slug' => $single_status->slug,
                'name' => $single_status->name,
                'hidden' => $hidden,
            ];
        }

        if (empty($statuses)) {
            return;
        }

        wp_enqueue_script(
            'extended-post-status-quick-edit',
            plugin_dir_url(__DIR__) . 'admin/js/quick-edit.js',
            ['jquery', 'inline-edit-post'],
            $this->version,
            true
        );

        wp_localize_script(
            'extended-post-status-quick-edit',
            'extendedPostStatusQuickEdit',
            ['statuses' => $statuses]
        );
    }

    /**
     * Add status to post list
     *
     * @global WP_Post $post
     * @param array $statuses
     * @return array
     * @since    1.0.0
     */
    public function append_post_status_post_overview($statuses)
    {
        global $post;

        if (!$post instanceof WP_Post) {
            return $statuses;
        }

        foreach (self::get_status() as $single_status) {
            if ($single_status->slug === $post->post_status) {
                // Core prints the post states unescaped, so the status name has
                // to be escaped here.
                return [esc_html($single_status->name)];
            }
        }

        return $statuses;
    }

    /**
     * Register the custom post statuses
     *
     * @since    1.0.0
     */
    public function register_post_status()
    {
        foreach (self::get_status() as $single_status) {
            $settings = self::get_status_settings($single_status->term_id);

            // The label count is printed unescaped by the list table, so the
            // status name has to be escaped here.
            $count_label = esc_html($single_status->name) . ' <span class="count">(%s)</span>';

            $args = [
                'label' => $single_status->name,
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingular,WordPress.WP.I18n.NonSingularStringLiteralPlural
                'label_count' => _n_noop($count_label, $count_label),
                'show_in_admin_all_list' => (bool) $settings['show_in_admin_all_list'],
                'show_in_admin_status_list' => (bool) $settings['show_in_admin_status_list'],
                'hide_in_drop_down' => (bool) $settings['hide_in_drop_down'],
            ];

            if ($settings['public']) {
                $args['public'] = true;
            } else {
                /*
                 * Register non public statuses as protected, exactly like core
                 * does for 'draft' and 'pending'.
                 *
                 * This keeps the posts out of every front end query while users
                 * with editing capabilities can still preview them. Deciding
                 * this per user - as previous versions did by checking
                 * current_user_can() here - made the registration depend on the
                 * current visitor, which leaked the posts into archives, feeds
                 * and search results for every logged in editor and produced
                 * inconsistent results behind a page cache.
                 */
                $args['public'] = false;
                $args['protected'] = true;
            }

            register_post_status($single_status->slug, $args);
        }
    }

    /**
     * Add custom taxonomy
     *
     * @since    1.0.0
     */
    public function register_status_taxonomy()
    {
        $labels = [
            'name' => _x('Status', 'taxonomy general name', 'extended-post-status'),
            'singular_name' => _x('Status', 'taxonomy singular name', 'extended-post-status'),
            'menu_name' => __('Statuses', 'extended-post-status'),
            'all_items' => __('All statuses', 'extended-post-status'),
            'edit_item' => __('Edit status', 'extended-post-status'),
            'view_item' => __('View status', 'extended-post-status'),
            'update_item' => __('Update status', 'extended-post-status'),
            'add_new_item' => __('Add new status', 'extended-post-status'),
            'parent_item' => __('Parent status', 'extended-post-status'),
            'parent_item_colon' => __('Parent status:', 'extended-post-status'),
            'new_item_name' => __('New status name', 'extended-post-status'),
            'search_items' => __('Search statuses', 'extended-post-status'),
            'popular_items' => __('Popular statuses', 'extended-post-status'),
            'separate_items_with_commas' => __('Separate status with commas', 'extended-post-status'),
            'add_or_remove_items' => __('Add or remove status', 'extended-post-status'),
            'choose_from_most_used' => __('Choose from most used statuses', 'extended-post-status'),
            'not_found' => __('No statuses found', 'extended-post-status'),
            'back_to_items' => __('← Back to status', 'extended-post-status'),
        ];
        $args = [
            'labels' => $labels,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'show_in_menu' => false,
            'meta_box_cb' => false,
        ];
        register_taxonomy(self::TAXONOMY, 'post', $args);
    }

    /**
     * Manipulate the taxonomy form fields
     *
     * Called for the "add new" form, where the first argument is the taxonomy
     * name, and for the "edit" form, where it is the term object.
     *
     * @param string|WP_Term $tag
     * @since    1.0.0
     */
    public function status_taxonomy_custom_fields($tag)
    {
        $is_edit_form = is_object($tag);
        $settings = $is_edit_form ? self::get_status_settings($tag->term_id) : self::$setting_defaults;

        $fields = [
            'public' => [
                'label' => __('Public', 'extended-post-status'),
                'desc' => __('Posts/Pages with this status are public.', 'extended-post-status'),
            ],
            'show_in_admin_all_list' => [
                'label' => __('Show posts in admin "All" list', 'extended-post-status'),
                'desc' => __('Posts/Pages with this status will be listed in all posts/pages overview.', 'extended-post-status'),
            ],
            'show_in_admin_status_list' => [
                'label' => __('Show status in admin status list', 'extended-post-status'),
                'desc' => __('Status appears in status list.', 'extended-post-status'),
            ],
            'hide_in_drop_down' => [
                'label' => __('Hide status in admin drop downs', 'extended-post-status'),
                'desc' => __('Status is not selectable in the admin dropdowns.', 'extended-post-status'),
            ],
        ];

        foreach ($fields as $key => $value) {
            $field_id = 'extended-post-status-' . $key;
            $checkbox = sprintf(
                '<input type="checkbox" name="term_meta[%1$s]" id="%2$s" value="1"%3$s /> %4$s',
                esc_attr($key),
                esc_attr($field_id),
                checked(!empty($settings[$key]), true, false),
                esc_html($value['label'])
            );

            /*
             * The "add new" form is built from divs while the "edit" form is a
             * table, so the wrapping markup has to differ.
             */
            if ($is_edit_form) {
                printf(
                    '<tr class="form-field"><th scope="row"><label for="%1$s">%2$s</label></th><td><p class="description">%3$s</p></td></tr>',
                    esc_attr($field_id),
                    $checkbox,
                    esc_html($value['desc'])
                );
            } else {
                printf(
                    '<div class="form-field"><label for="%1$s">%2$s</label><p class="description">%3$s</p></div>',
                    esc_attr($field_id),
                    $checkbox,
                    esc_html($value['desc'])
                );
            }
        }
    }

    /**
     * Save the manipulated taxonomy form fields
     *
     * Only the known settings are stored and every value is cast to 0 or 1, so
     * no arbitrary data can be written into the option.
     *
     * @param int $term_id
     * @since    1.0.0
     */
    public function save_status_taxonomy_custom_fields($term_id)
    {
        if (!current_user_can('manage_categories')) {
            return;
        }

        $term_id = (int) $term_id;

        /*
         * Quick editing a status submits only the name and the slug. Keeping the
         * stored settings in that case prevents them from being reset.
         */
        $is_inline_edit = (bool) filter_input(INPUT_POST, '_inline_edit');
        if ($is_inline_edit) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified by core before 'created_status'/'edited_status' fire.
        $submitted = isset($_POST['term_meta']) && is_array($_POST['term_meta']) ? $_POST['term_meta'] : [];

        $settings = [];
        foreach (self::$setting_defaults as $key => $default) {
            $settings[$key] = (isset($submitted[$key]) && $submitted[$key]) ? 1 : 0;
        }

        // These options are only read in the admin, so they do not need to be
        // autoloaded on every single request.
        update_option(self::SETTINGS_OPTION_PREFIX . $term_id, $settings, false);

        self::flush_status_cache();
    }

    /**
     * Remove the settings of a deleted status
     *
     * @param int $term_id
     * @since    1.1.0
     */
    public function delete_status_taxonomy_custom_fields($term_id)
    {
        delete_option(self::SETTINGS_OPTION_PREFIX . (int) $term_id);
        self::flush_status_cache();
    }

    /**
     * Override core field after the update of a status taxonomy
     * Used to check if the slug is longer than 20 chars, because the database
     * field for statuses is limited to 20 chars
     *
     *
     * @param array $data
     * @param int $term_id
     * @param string $taxonomy
     * @param array $args
     * @return array
     * @since    1.0.2
     */
    public function override_status_taxonomy_on_save($data, $term_id, $taxonomy, $args)
    {
        if (self::TAXONOMY !== $taxonomy || !isset($data['slug'])) {
            return $data;
        }

        $slug = $data['slug'];

        // The post_status column holds 20 characters. Cutting the slug with a
        // multibyte aware function avoids splitting a character in half.
        if (strlen($slug) > 20) {
            if (function_exists('mb_strcut')) {
                $slug = mb_strcut($slug, 0, 20, 'UTF-8');
            } else {
                $slug = substr($slug, 0, 20);
            }
            $data['slug'] = sanitize_key(rtrim($slug, '-_'));
        }

        return $data;
    }

    /**
     * Edit the status taxonomy table
     *
     * @param array $columns
     * @return array
     * @since    1.0.0
     */
    public function edit_status_taxonomy_columns($columns)
    {
        if (isset($columns['posts'])) {
            unset($columns['posts']);
        }
        $columns['settings'] = __('Settings', 'extended-post-status');
        $columns['count_posts'] = __('Posts', 'extended-post-status');
        $columns['count_pages'] = __('Pages', 'extended-post-status');
        return $columns;
    }

    /**
     * Counts the posts of a post type
     *
     * Cached for the current request, because the status table calls this once
     * per row and column.
     *
     * @param string $post_type
     * @param string $status_slug
     * @return int
     * @since    1.1.0
     */
    private static function count_posts_with_status($post_type, $status_slug)
    {
        if (!isset(self::$count_cache[$post_type])) {
            self::$count_cache[$post_type] = wp_count_posts($post_type);
        }

        $counts = self::$count_cache[$post_type];

        return isset($counts->$status_slug) ? (int) $counts->$status_slug : 0;
    }

    /**
     * Add content to new created custom column in taxonomy table
     *
     * @param string $content
     * @param string $column_name
     * @param int $term_id
     * @return string
     * @since    1.0.0
     */
    public function add_status_taxonomy_columns_content($content, $column_name, $term_id)
    {
        $term = get_term($term_id);
        if (!$term instanceof WP_Term) {
            return '';
        }

        $settings = self::get_status_settings($term_id);

        if ('settings' === $column_name) {
            $labels = [
                'public' => __('Public', 'extended-post-status'),
                'show_in_admin_all_list' => __('Show in admin "All" list', 'extended-post-status'),
                'show_in_admin_status_list' => __('Show in admin status list', 'extended-post-status'),
                'hide_in_drop_down' => __('Hide in admin drop downs', 'extended-post-status'),
            ];

            $active = [];
            foreach ($labels as $key => $label) {
                if (!empty($settings[$key])) {
                    $active[] = $label;
                }
            }

            return esc_html(implode(', ', $active));
        }

        if ('count_posts' === $column_name || 'count_pages' === $column_name) {
            $post_type = ('count_posts' === $column_name) ? 'post' : 'page';
            $count = self::count_posts_with_status($post_type, $term->slug);
            $url = add_query_arg(
                [
                    'post_status' => $term->slug,
                    'post_type' => $post_type,
                ],
                admin_url('edit.php')
            );

            return '<a href="' . esc_url($url) . '">' . esc_html(number_format_i18n($count)) . '</a>';
        }

        return $content;
    }

    /**
     * Get array of all statuses
     *
     * Contains the core statuses as well as the custom ones.
     *
     * @return array
     * @since    1.0.0
     */
    public static function get_all_status_array()
    {
        $statuses = get_post_statuses();
        foreach (self::get_status() as $single_status) {
            $statuses[$single_status->slug] = $single_status->name;
        }
        return $statuses;
    }

    /**
     * Initialize the view for the overridden query
     *
     * @global string $pagenow
     * @since    1.0.1
     */
    public function override_admin_post_list_init()
    {
        global $pagenow;
        if ('edit.php' === $pagenow) {
            add_action('parse_query', ['Extended_Post_Status_Admin', 'override_admin_post_list']);
        }
    }

    /**
     * Override the post query
     *
     * Core already builds the "All" list from the public statuses plus every
     * protected status that opted into it, so the only case left to handle is a
     * public status that asked to be hidden from that list.
     *
     * @param WP_Query $query
     * @since    1.0.1
     */
    public static function override_admin_post_list($query)
    {
        // Only act on the unfiltered "All" view.
        if (!array_key_exists('post_status', $query->query) || !empty($query->query['post_status'])) {
            return;
        }

        $needs_override = false;
        foreach (self::get_status() as $single_status) {
            $settings = self::get_status_settings($single_status->term_id);
            if ($settings['public'] && !$settings['show_in_admin_all_list']) {
                $needs_override = true;
                break;
            }
        }

        if (!$needs_override) {
            return;
        }

        $statuses = get_post_stati(['show_in_admin_all_list' => true]);
        $query->set('post_status', array_values($statuses));
    }

    /**
     * Init additional settings page params
     *
     * @since    1.0.4
     */
    public function settings_init()
    {
        // Add general plugin name and desc translations
        __('Extended Post Status', 'extended-post-status');
        __('Add new post status types.', 'extended-post-status');

        register_setting(
            'writing',
            'extended-post-status-add-extra-admin-menu-item',
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => ['Extended_Post_Status_Admin', 'settings_sanitize'],
            ]
        );
        add_settings_section(
            'extended-post-status-settings',
            __('Extended Post Status', 'extended-post-status'),
            ['Extended_Post_Status_Admin', 'settings_section_description'],
            'writing'
        );
        add_settings_field(
            'extended-post-status-add-extra-admin-menu-item',
            '<label for="extended-post-status-add-extra-admin-menu-item">' . esc_html__('Move status to main admin menu.', 'extended-post-status') . '</label>',
            ['Extended_Post_Status_Admin', 'settings_extra_admin_menu_item_field'],
            'writing',
            'extended-post-status-settings'
        );
    }

    /**
     * Sanitize setting page input
     *
     * @param mixed $input
     * @return bool
     * @since    1.0.4
     */
    public static function settings_sanitize($input)
    {
        return !empty($input);
    }

    /**
     * Add description to settings page section
     *
     * @since    1.0.4
     */
    public static function settings_section_description()
    {
        echo '<p>' . esc_html__('Settings for post status handling.', 'extended-post-status') . '</p>';
    }

    /**
     * Add settings section fields
     *
     * @since    1.0.4
     */
    public static function settings_extra_admin_menu_item_field()
    {
        printf(
            '<input id="extended-post-status-add-extra-admin-menu-item" type="checkbox" value="1" name="extended-post-status-add-extra-admin-menu-item"%s>',
            checked(get_option('extended-post-status-add-extra-admin-menu-item', false), true, false)
        );
    }

    /**
     * Add admin menu items
     *
     * @since    1.0.4
     */
    public function admin_menu()
    {
        if (get_option('extended-post-status-add-extra-admin-menu-item', false)) {
            add_menu_page(__('Extended Post Status', 'extended-post-status'), __('Extended Post Status', 'extended-post-status'), 'publish_posts', 'extended-post-status-taxonomy', ['Extended_Post_Status_Admin', 'admin_menu_link_extended_post_status_taxonomy'], 'dashicons-post-status');
        } else {
            add_submenu_page('options-general.php', __('Extended Post Status', 'extended-post-status'), __('Extended Post Status', 'extended-post-status'), 'publish_posts', 'extended-post-status-taxonomy', ['Extended_Post_Status_Admin', 'admin_menu_link_extended_post_status_taxonomy']);
        }
    }

    /**
     * Render the faked status admin menu page
     *
     * The menu item is redirected to the taxonomy screen by admin_redirects(),
     * so this is only a fallback for the case the redirect did not happen.
     *
     * @since    1.1.0
     */
    public static function admin_menu_link_extended_post_status_taxonomy()
    {
        printf(
            '<div class="wrap"><h1>%1$s</h1><p><a href="%2$s">%3$s</a></p></div>',
            esc_html__('Extended Post Status', 'extended-post-status'),
            esc_url(admin_url('edit-tags.php?taxonomy=' . self::TAXONOMY)),
            esc_html__('All statuses', 'extended-post-status')
        );
    }

    /**
     * Redirects in admin context
     * - Redirect the main admin menu status page to original taxonomy page
     *
     * @global string $pagenow
     * @since    1.0.4
     */
    public function admin_redirects()
    {
        global $pagenow;
        if (('admin.php' === $pagenow || 'options-general.php' === $pagenow) && 'extended-post-status-taxonomy' === filter_input(INPUT_GET, 'page')) {
            wp_safe_redirect(admin_url('edit-tags.php?taxonomy=' . self::TAXONOMY), 302);
            exit;
        }
    }

    /**
     * Parent file settings
     * - Used to fake the status page in main admin menu
     *
     * @param string $parent_file
     * @return string
     * @since    1.0.4
     */
    public function parent_file($parent_file)
    {
        $screen = get_current_screen();
        if (!$screen || self::TAXONOMY !== $screen->taxonomy) {
            return $parent_file;
        }

        if (get_option('extended-post-status-add-extra-admin-menu-item', false)) {
            return 'extended-post-status-taxonomy';
        }

        return 'options-general.php';
    }

    /**
     * Submenu file settings
     * - Used to fake the status page in admin submenu
     *
     * @param string $submenu_file
     * @return string
     * @since    1.0.8
     */
    public function submenu_file($submenu_file)
    {
        $screen = get_current_screen();
        if ($screen && self::TAXONOMY === $screen->taxonomy) {
            return 'extended-post-status-taxonomy';
        }
        return $submenu_file;
    }

    /**
     * Override the core status field with the custom status field
     * - If the post is getting trashed, don't do this!
     * - If the post is a planned post for the future, don't do this!
     * - If no custom status is set (equals 'none'), set post status to draft
     *
     * The submitted status is validated against the known statuses, so an
     * arbitrary value can never end up in the database.
     *
     * @param array $data
     * @param array $postarr
     * @return array
     * @since    1.0.13
     */
    public function wp_insert_post_data($data, $postarr)
    {
        if (!array_key_exists('post_status_', $postarr)) {
            return $data;
        }

        $post_type = isset($data['post_type']) ? $data['post_type'] : '';
        if (!self::current_user_can_set_status($post_type)) {
            return $data;
        }

        // Never touch a post on its way to the trash or a scheduled post. This
        // has to be checked before the 'none' fallback, otherwise trashing a
        // post would turn it into a draft.
        if ('trash' === $data['post_status'] || 'future' === $data['post_status']) {
            return $data;
        }

        $requested = $postarr['post_status_'];

        if ('none' === $requested) {
            $data['post_status'] = 'draft';
            return $data;
        }

        if (array_key_exists($requested, self::get_all_status_array())) {
            $data['post_status'] = $requested;
        }

        return $data;
    }

    /**
     * Hook the status validation into every post type handled by the REST API
     *
     * @since    1.1.0
     */
    public function register_rest_status_validation()
    {
        // The filter is built from the post type name, not from the rest base.
        foreach (get_post_types(['show_in_rest' => true]) as $post_type) {
            add_filter('rest_pre_insert_' . $post_type, ['Extended_Post_Status_Admin', 'rest_pre_insert_post'], 10, 2);
        }
    }

    /**
     * Reject custom statuses for users who may not publish
     *
     * The REST API lets every registered status pass as long as the user can
     * edit the post, so the capability check has to be repeated here.
     *
     * @param stdClass $prepared_post
     * @param WP_REST_Request $request
     * @return stdClass|WP_Error
     * @since    1.1.0
     */
    public static function rest_pre_insert_post($prepared_post, $request)
    {
        if (empty($prepared_post->post_status)) {
            return $prepared_post;
        }

        $custom_slugs = wp_list_pluck(self::get_status(), 'slug');
        if (!in_array($prepared_post->post_status, $custom_slugs, true)) {
            return $prepared_post;
        }

        /*
         * Only assigning a new custom status requires the capability. Saving a
         * post that already has it must keep working, because the block editor
         * sends the unchanged status along with every update.
         */
        if (!empty($prepared_post->ID) && get_post_status($prepared_post->ID) === $prepared_post->post_status) {
            return $prepared_post;
        }

        $post_type = isset($prepared_post->post_type) ? $prepared_post->post_type : $request->get_param('type');
        if (!$post_type && !empty($prepared_post->ID)) {
            $post_type = get_post_type($prepared_post->ID);
        }

        if (!self::current_user_can_set_status($post_type)) {
            return new WP_Error(
                'extended_post_status_cannot_assign',
                __('Sorry, you are not allowed to assign this status.', 'extended-post-status'),
                ['status' => rest_authorization_required_code()]
            );
        }

        return $prepared_post;
    }

    /**
     * Register the block editor assets
     *
     * Adds the status control to the editor sidebar. Core builds its own status
     * control from a hard coded list, so a custom status would show up without
     * a label there.
     *
     * @since    1.0.18
     */
    public function enqueue_block_editor_assets()
    {
        $post = get_post();
        $post_type = $post instanceof WP_Post ? $post->post_type : '';

        if (!self::current_user_can_set_status($post_type)) {
            return;
        }

        wp_enqueue_script(
            'extended-post-status-editor',
            plugin_dir_url(__DIR__) . 'admin/js/editor-status-panel.js',
            ['wp-plugins', 'wp-element', 'wp-components', 'wp-compose', 'wp-data', 'wp-i18n'],
            $this->version,
            true
        );

        // The core statuses come first, followed by the selectable custom ones,
        // so the control can replace the core status control entirely.
        $current_status = $post instanceof WP_Post ? $post->post_status : '';
        $statuses = [];
        foreach (get_post_statuses() as $slug => $name) {
            $statuses[] = [
                'slug' => $slug,
                'name' => $name,
            ];
        }
        $statuses = array_merge($statuses, self::get_selectable_status($current_status));

        wp_localize_script(
            'extended-post-status-editor',
            'extendedPostStatusEditor',
            ['statuses' => $statuses]
        );

        wp_set_script_translations('extended-post-status-editor', 'extended-post-status');

        $this->enqueue_publishing_sidebar_script();
    }

    /**
     * Remove the "two click" publishing sidebar
     * - See: https://github.com/WordPress/gutenberg/issues/9077#issuecomment-458309231
     *
     * The preference is stored per user since WordPress 7.0, so it is only set
     * once instead of on every editor load. Otherwise the user could never turn
     * the pre-publish checks back on.
     *
     * @since    1.0.18
     */
    private function enqueue_publishing_sidebar_script()
    {
        $user_id = get_current_user_id();

        // Without a user the preference cannot be remembered, which would
        // enqueue the script on every single load.
        if (!$user_id) {
            return;
        }

        if (get_user_meta($user_id, '_extended_post_status_publish_sidebar_disabled', true)) {
            return;
        }

        update_user_meta($user_id, '_extended_post_status_publish_sidebar_disabled', 1);

        wp_enqueue_script(
            'extended-post-status-publish-sidebar',
            plugin_dir_url(__DIR__) . 'admin/js/disable-publish-sidebar.js',
            ['wp-data', 'wp-dom-ready'],
            $this->version,
            true
        );
    }

    /**
     * Register the string overrides on the editor screens only
     *
     * The gettext filter runs for every single translated string, so it must
     * not be registered on screens that do not need it.
     *
     * @since    1.1.0
     */
    public function register_editor_string_overrides()
    {
        add_filter('gettext', ['Extended_Post_Status_Admin', 'gettext_override'], 10, 3);
        add_action('admin_print_footer_scripts', [$this, 'change_publish_button_gutenberg'], 11);
    }

    /**
     * Override the text on the publish button
     * - This is done to prevent confusion while publishing or saving a post
     *
     * @since    1.0.18
     */
    public function change_publish_button_gutenberg()
    {
        if (wp_script_is('wp-i18n') && self::current_user_can_set_status(get_post_type())) {
            printf(
                '<script type="text/javascript">wp.i18n.setLocaleData(%s);</script>',
                wp_json_encode(['Publish' => [__('Save')]])
            );
        }
    }

    /**
     * Override gettext snippets
     *
     * Only core strings are considered and the capability is checked after the
     * string matched, because this filter runs thousands of times per request.
     *
     * @param string $translated
     * @param string $original
     * @param string $domain
     * @return string
     * @since    1.0.18
     */
    public static function gettext_override($translated, $original, $domain)
    {
        if ('default' !== $domain) {
            return $translated;
        }

        switch ($original) {
            case 'Publish':
                $replacement = 'Save';
                break;
            case 'Post published.':
            case 'Post reverted to draft.':
                $replacement = 'Post saved.';
                break;
            case 'Page published.':
            case 'Page reverted to draft.':
                $replacement = 'Page saved.';
                break;
            default:
                return $translated;
        }

        if (!self::current_user_can_set_status(get_post_type())) {
            return $translated;
        }

        return __($replacement);
    }
}
