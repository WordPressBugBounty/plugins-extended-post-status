<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       http://www.felixwelberg.de/
 * @since      1.0.0
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @author     Felix Welberg <felix@welberg.de>
 */
class Extended_Post_Status
{

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    1.0.0
     * @var      Extended_Post_Status_Loader    $loader    Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * The unique identifier of this plugin.
     *
     * @since    1.0.0
     * @var      string    $plugin_name    The string used to uniquely identify this plugin.
     */
    protected $plugin_name;

    /**
     * The current version of the plugin.
     *
     * @since    1.0.0
     * @var      string    $version    The current version of the plugin.
     */
    protected $version;

    /**
     * Define the core functionality of the plugin.
     *
     * Set the plugin name and the plugin version that can be used throughout the plugin.
     * Load the dependencies, define the locale, and set the hooks for the admin area and
     * the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function __construct()
    {
        if (defined('EXTENDED_POST_STATUS_VERSION')) {
            $this->version = EXTENDED_POST_STATUS_VERSION;
        } else {
            $this->version = '1.1.0';
        }
        $this->plugin_name = 'extended-post-status';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Include the following files that make up the plugin:
     *
     * - Extended_Post_Status_Loader. Orchestrates the hooks of the plugin.
     * - Extended_Post_Status_i18n. Defines internationalization functionality.
     * - Extended_Post_Status_Admin. Defines all hooks for the admin area.
     *
     * Create an instance of the loader which will be used to register the hooks
     * with WordPress.
     *
     * @since    1.0.0
     */
    private function load_dependencies()
    {
        require_once plugin_dir_path(__DIR__) . 'includes/class-extended-post-status-loader.php';
        require_once plugin_dir_path(__DIR__) . 'includes/class-extended-post-status-i18n.php';
        require_once plugin_dir_path(__DIR__) . 'admin/class-extended-post-status-admin.php';

        $this->loader = new Extended_Post_Status_Loader();
    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * Uses the Extended_Post_Status_i18n class in order to set the domain and to register the hook
     * with WordPress.
     *
     * @since    1.0.0
     */
    private function set_locale()
    {
        $plugin_i18n = new Extended_Post_Status_i18n();

        // Translations must not be loaded before 'init', otherwise WordPress
        // 6.7 and later report a _doing_it_wrong() notice.
        $this->loader->add_action('init', $plugin_i18n, 'load_plugin_textdomain');
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    1.0.0
     */
    private function define_admin_hooks()
    {
        $plugin_admin = new Extended_Post_Status_Admin($this->get_plugin_name(), $this->get_version());

        /*
         * These hooks are required in every context. The statuses have to be
         * registered on the front end and during REST requests as well,
         * otherwise the posts would be treated as having an unknown status.
         */
        $this->loader->add_action('init', $plugin_admin, 'register_status_taxonomy');
        $this->loader->add_action('init', $plugin_admin, 'register_post_status');
        $this->loader->add_action('rest_api_init', $plugin_admin, 'register_rest_status_validation');
        $this->loader->add_filter('wp_insert_post_data', $plugin_admin, 'wp_insert_post_data', 99, 2);

        // Drop the runtime caches whenever a status changes.
        $this->loader->add_action('created_status', $plugin_admin, 'flush_status_cache');
        $this->loader->add_action('edited_status', $plugin_admin, 'flush_status_cache');
        $this->loader->add_action('delete_status', $plugin_admin, 'delete_status_taxonomy_custom_fields');

        // Everything below only ever runs inside the admin. Registering it
        // unconditionally would run the gettext filter on the front end too.
        if (!is_admin()) {
            return;
        }

        $this->loader->add_action('admin_init', $plugin_admin, 'override_admin_post_list_init');
        $this->loader->add_action('admin_init', $plugin_admin, 'settings_init');
        $this->loader->add_action('admin_init', $plugin_admin, 'admin_redirects');
        $this->loader->add_action('admin_menu', $plugin_admin, 'admin_menu');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_classic_editor_script');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_quick_edit_script');
        $this->loader->add_action('display_post_states', $plugin_admin, 'append_post_status_post_overview');
        $this->loader->add_action('status_add_form_fields', $plugin_admin, 'status_taxonomy_custom_fields', 10, 2);
        $this->loader->add_action('created_status', $plugin_admin, 'save_status_taxonomy_custom_fields', 10, 2);
        $this->loader->add_action('status_edit_form_fields', $plugin_admin, 'status_taxonomy_custom_fields', 10, 2);
        $this->loader->add_action('edited_status', $plugin_admin, 'save_status_taxonomy_custom_fields', 10, 2);
        $this->loader->add_action('manage_edit-status_columns', $plugin_admin, 'edit_status_taxonomy_columns');
        $this->loader->add_action('enqueue_block_editor_assets', $plugin_admin, 'enqueue_block_editor_assets');

        // The gettext filter is expensive, so the string overrides are only
        // added on the screens that actually show the publish button.
        $this->loader->add_action('load-post.php', $plugin_admin, 'register_editor_string_overrides');
        $this->loader->add_action('load-post-new.php', $plugin_admin, 'register_editor_string_overrides');

        $this->loader->add_filter('parent_file', $plugin_admin, 'parent_file');
        $this->loader->add_filter('submenu_file', $plugin_admin, 'submenu_file');
        $this->loader->add_filter('wp_update_term_data', $plugin_admin, 'override_status_taxonomy_on_save', 10, 4);
        $this->loader->add_filter('manage_status_custom_column', $plugin_admin, 'add_status_taxonomy_columns_content', 10, 3);
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since    1.0.0
     */
    public function run()
    {
        $this->loader->run();
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     *
     * @since     1.0.0
     * @return    string    The name of the plugin.
     */
    public function get_plugin_name()
    {
        return $this->plugin_name;
    }

    /**
     * The reference to the class that orchestrates the hooks with the plugin.
     *
     * @since     1.0.0
     * @return    Extended_Post_Status_Loader    Orchestrates the hooks of the plugin.
     */
    public function get_loader()
    {
        return $this->loader;
    }

    /**
     * Retrieve the version number of the plugin.
     *
     * @since     1.0.0
     * @return    string    The version number of the plugin.
     */
    public function get_version()
    {
        return $this->version;
    }
}
