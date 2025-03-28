<?php
/**
 * Plugin Name: WP RepoX
 * Plugin URI: https://oit.ncsu.edu/wp-repox
 * Description: Adds external/remote repository sources for WordPress plugins and themes.
 * Version: 1.0.0
 * Author: tporret
 * Author URI: https://oit.ncsu.edu
 * Text Domain: wp-repox
 * License: GPL-2.0+
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('WP_REPOX_VERSION', '1.0.0');
define('WP_REPOX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_REPOX_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class WP_RepoX {

    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Is multisite
     */
    private $is_multisite;

    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->is_multisite = is_multisite();
        
        // Get options from site or network options based on multisite status
        if ($this->is_multisite) {
            $this->options = get_site_option('wp_repox_options', [
                'repo_url' => '',
                'auth_username' => '',
                'auth_password' => '',
                'auth_method' => 'none'
            ]);
        } else {
            $this->options = get_option('wp_repox_options', [
                'repo_url' => '',
                'auth_username' => '',
                'auth_password' => '',
                'auth_method' => 'none'
            ]);
        }
        
        // Register hooks for single site or network
        if ($this->is_multisite) {
            add_action('network_admin_menu', [$this, 'add_network_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
        } else {
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_menu', [$this, 'add_admin_menu']);
        }
        
        // Common hooks for both setups
        add_filter('install_plugins_tabs', [$this, 'add_plugins_tab']);
        add_filter('install_themes_tabs', [$this, 'add_themes_tab']);
        add_action('install_plugins_repox', [$this, 'display_plugins_tab']);
        add_action('install_themes_repox', [$this, 'display_themes_tab']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_repox_search_plugins', [$this, 'ajax_search_plugins']);
        add_action('wp_ajax_repox_search_themes', [$this, 'ajax_search_themes']);
        add_action('wp_ajax_repox_install_item', [$this, 'ajax_install_item']);
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        if ($this->is_multisite) {
            // For multisite, we register the settings without the settings API
            // as it's not fully supported in network admin
            // We'll handle saving manually
        } else {
            register_setting(
                'wp_repox_options_group',
                'wp_repox_options',
                [$this, 'sanitize_options']
            );
        }

        // Add settings sections and fields regardless of multisite status
        add_settings_section(
            'wp_repox_main_section',
            __('Repository Settings', 'wp-repox'),
            [$this, 'settings_section_callback'],
            'wp-repox-settings'
        );

        // Repository URL field
        add_settings_field(
            'repo_url',
            __('Repository URL', 'wp-repox'),
            [$this, 'repo_url_field_callback'],
            'wp-repox-settings',
            'wp_repox_main_section'
        );

        // Authentication method field
        add_settings_field(
            'auth_method',
            __('Authentication Method', 'wp-repox'),
            [$this, 'auth_method_field_callback'],
            'wp-repox-settings',
            'wp_repox_main_section'
        );

        // Username field
        add_settings_field(
            'auth_username',
            __('Username', 'wp-repox'),
            [$this, 'auth_username_field_callback'],
            'wp-repox-settings',
            'wp_repox_main_section'
        );

        // Password field
        add_settings_field(
            'auth_password',
            __('Password/Token', 'wp-repox'),
            [$this, 'auth_password_field_callback'],
            'wp-repox-settings',
            'wp_repox_main_section'
        );
    }

    /**
     * Repository URL field callback
     */
    public function repo_url_field_callback() {
        $value = isset($this->options['repo_url']) ? esc_url($this->options['repo_url']) : '';
        echo '<input type="url" name="wp_repox_options[repo_url]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Enter the URL of your repository API (with trailing slash).', 'wp-repox') . '</p>';
    }

    /**
     * Authentication method field callback
     */
    public function auth_method_field_callback() {
        $value = isset($this->options['auth_method']) ? $this->options['auth_method'] : 'none';
        ?>
        <select name="wp_repox_options[auth_method]" id="auth_method">
            <option value="none" <?php selected($value, 'none'); ?>><?php _e('None', 'wp-repox'); ?></option>
            <option value="basic" <?php selected($value, 'basic'); ?>><?php _e('Basic Auth', 'wp-repox'); ?></option>
            <option value="token" <?php selected($value, 'token'); ?>><?php _e('API Token', 'wp-repox'); ?></option>
        </select>
        <?php
    }

    /**
     * Username field callback
     */
    public function auth_username_field_callback() {
        $value = isset($this->options['auth_username']) ? esc_attr($this->options['auth_username']) : '';
        echo '<input type="text" name="wp_repox_options[auth_username]" value="' . $value . '" class="regular-text" />';
    }

    /**
     * Password field callback
     */
    public function auth_password_field_callback() {
        $value = isset($this->options['auth_password']) ? esc_attr($this->options['auth_password']) : '';
        echo '<input type="password" name="wp_repox_options[auth_password]" value="' . $value . '" class="regular-text" />';
    }

    /**
     * Add network admin menu for multisite
     */
    public function add_network_admin_menu() {
        add_submenu_page(
            'settings.php',
            __('WP RepoX Settings', 'wp-repox'),
            __('WP RepoX', 'wp-repox'),
            'manage_network_options',
            'wp-repox-settings',
            [$this, 'display_network_options_page']
        );
    }

    /**
     * Display network options page
     */
    public function display_network_options_page() {
        // Check if form was submitted
        if (isset($_POST['submit'])) {
            check_admin_referer('wp-repox-network-settings');
            
            if (current_user_can('manage_network_options')) {
                $options = [
                    'repo_url' => isset($_POST['wp_repox_options']['repo_url']) ? 
                        esc_url_raw(trailingslashit($_POST['wp_repox_options']['repo_url'])) : '',
                    'auth_username' => isset($_POST['wp_repox_options']['auth_username']) ? 
                        sanitize_text_field($_POST['wp_repox_options']['auth_username']) : '',
                    'auth_password' => isset($_POST['wp_repox_options']['auth_password']) ? 
                        sanitize_text_field($_POST['wp_repox_options']['auth_password']) : '',
                    'auth_method' => isset($_POST['wp_repox_options']['auth_method']) ? 
                        sanitize_key($_POST['wp_repox_options']['auth_method']) : 'none',
                ];
                
                update_site_option('wp_repox_options', $options);
                $this->options = $options;
                
                echo '<div class="updated"><p>' . __('Settings saved.', 'wp-repox') . '</p></div>';
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="" method="post">
                <?php
                wp_nonce_field('wp-repox-network-settings');
                do_settings_sections('wp-repox-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Add admin menu for single site
     */
    public function add_admin_menu() {
        add_options_page(
            __('WP RepoX Settings', 'wp-repox'),
            __('WP RepoX', 'wp-repox'),
            'manage_options',
            'wp-repox-settings',
            [$this, 'display_options_page']
        );
    }
    /**
     * Display options page for single site
     */
    public function display_options_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wp_repox_options_group');
                do_settings_sections('wp-repox-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>' . __('Configure your external repository settings below.', 'wp-repox') . '</p>';
    }
    /**
     * Sanitize options
     */
    public function sanitize_options($options) {
        $options['repo_url'] = esc_url_raw(trailingslashit($options['repo_url']));
        $options['auth_username'] = sanitize_text_field($options['auth_username']);
        $options['auth_password'] = sanitize_text_field($options['auth_password']);
        $options['auth_method'] = sanitize_key($options['auth_method']);
        
        return $options;
    }
    /**
     * AJAX search plugins
     */
    public function ajax_search_plugins() {
        check_ajax_referer('wp-repox-ajax-nonce', 'nonce');
        
        // Check for proper permissions in multisite or single site
        $can_install = $this->is_multisite ? 
            current_user_can('manage_network_plugins') : 
            current_user_can('install_plugins');
            
        if (!$can_install) {
            wp_send_json_error(['message' => __('You do not have permission to install plugins.', 'wp-repox')]);
        }
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $results = $this->fetch_from_repo('plugins', $search);
        
        wp_send_json($results);
    }

    /**
     * AJAX search themes
     */
    public function ajax_search_themes() {
        check_ajax_referer('wp-repox-ajax-nonce', 'nonce');
        
        // Check for proper permissions in multisite or single site
        $can_install = $this->is_multisite ? 
            current_user_can('manage_network_themes') : 
            current_user_can('install_themes');
            
        if (!$can_install) {
            wp_send_json_error(['message' => __('You do not have permission to install themes.', 'wp-repox')]);
        }
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $results = $this->fetch_from_repo('themes', $search);
        
        wp_send_json($results);
    }

    /**
     * AJAX install item
     */
    public function ajax_install_item() {
        check_ajax_referer('wp-repox-ajax-nonce', 'nonce');
        
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $slug = isset($_POST['slug']) ? sanitize_text_field($_POST['slug']) : '';
        
        if ($type !== 'plugin' && $type !== 'theme') {
            wp_send_json_error(['message' => __('Invalid item type.', 'wp-repox')]);
        }
        
        if (empty($slug)) {
            wp_send_json_error(['message' => __('Invalid item slug.', 'wp-repox')]);
        }
        
        // Check for proper permissions in multisite or single site
        $can_install = false;
        if ($this->is_multisite) {
            $can_install = $type === 'plugin' ? 
                current_user_can('manage_network_plugins') : 
                current_user_can('manage_network_themes');
        } else {
            $can_install = $type === 'plugin' ? 
                current_user_can('install_plugins') : 
                current_user_can('install_themes');
        }
        
        if (!$can_install) {
            wp_send_json_error(['message' => sprintf(__('You do not have permission to install %s.', 'wp-repox'), $type . 's')]);
        }
        
        $download_url = $this->get_download_url($type, $slug);
        if (empty($download_url)) {
            wp_send_json_error(['message' => __('Could not determine download URL.', 'wp-repox')]);
        }
        
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        
        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = $type === 'plugin' ? new Plugin_Upgrader($skin) : new Theme_Upgrader($skin);
        
        $result = $upgrader->install($download_url);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } elseif (is_wp_error($skin->result)) {
            wp_send_json_error(['message' => $skin->result->get_error_message()]);
        } elseif ($skin->get_errors()->has_errors()) {
            wp_send_json_error(['message' => $skin->get_error_messages()]);
        } elseif (false === $result) {
            wp_send_json_error(['message' => __('Installation failed.', 'wp-repox')]);
        }
        
        // For multisite, we might need to network activate
        if ($this->is_multisite && $type === 'plugin') {
            // Get the plugin file path to activate it
            $plugin_data = get_plugins('/' . $slug);
            if (!empty($plugin_data)) {
                $plugin_file = $slug . '/' . key($plugin_data);
                if (current_user_can('manage_network_plugins')) {
                    activate_plugin($plugin_file, '', true); // Network activate
                }
            }
        }
        
        wp_send_json_success(['message' => sprintf(__('%s installed successfully.', 'wp-repox'), ucfirst($type))]);
    }

    /**
     * Add plugins tab
     */
    public function add_plugins_tab($tabs) {
        $tabs['repox'] = __('External Repository', 'wp-repox');
        return $tabs;
    }

    /**
     * Add themes tab
     */
    public function add_themes_tab($tabs) {
        $tabs['repox'] = __('External Repository', 'wp-repox');
        return $tabs;
    }

    /**
     * Display plugins tab
     */
    public function display_plugins_tab() {
        $this->display_repo_tab('plugin');
    }

    /**
     * Display themes tab
     */
    public function display_themes_tab() {
        $this->display_repo_tab('theme');
    }

    /**
     * Display repository tab content
     */
    private function display_repo_tab($type) {
        // Check if we have a configured repository based on multisite status
        if (empty($this->options['repo_url'])) {
            echo '<div class="notice notice-error"><p>';
            
            // Different URLs based on multisite status
            if ($this->is_multisite) {
                $settings_url = network_admin_url('settings.php?page=wp-repox-settings');
            } else {
                $settings_url = admin_url('options-general.php?page=wp-repox-settings');
            }
            
            echo sprintf(
                __('Repository URL is not configured. <a href="%s">Configure it now</a>.', 'wp-repox'),
                $settings_url
            );
            echo '</p></div>';
            return;
        }
        
        ?>
        <div class="wrap">
            <h2><?php echo sprintf(__('Install %s from External Repository', 'wp-repox'), $type === 'plugin' ? __('Plugins', 'wp-repox') : __('Themes', 'wp-repox')); ?></h2>
            
            <div class="wp-filter">
                <div class="search-form">
                    <input type="search" id="repox-search-input" placeholder="<?php esc_attr_e('Search items...', 'wp-repox'); ?>" class="wp-filter-search">
                    <input type="button" id="repox-search-submit" class="button" value="<?php esc_attr_e('Search', 'wp-repox'); ?>">
                </div>
            </div>
            
            <div class="repox-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <?php _e('Loading...', 'wp-repox'); ?>
            </div>
            
            <div class="repox-results" style="display: none;"></div>
            
            <div class="repox-no-results" style="display: none;">
                <?php _e('No items found.', 'wp-repox'); ?>
            </div>
            
            <div class="repox-error" style="display: none;"></div>
            
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                var itemType = '<?php echo esc_js($type); ?>';
                var searchTimeout;
                
                // Handle search
                $('#repox-search-submit').on('click', function() {
                    var searchTerm = $('#repox-search-input').val();
                    performSearch(searchTerm);
                });
                
                $('#repox-search-input').on('keyup', function(e) {
                    if (e.keyCode === 13) {
                        var searchTerm = $(this).val();
                        performSearch(searchTerm);
                    }
                });
                
                function performSearch(searchTerm) {
                    $('.repox-results, .repox-no-results, .repox-error').hide();
                    $('.repox-loading').show();
                    
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'repox_search_' + (itemType === 'plugin' ? 'plugins' : 'themes'),
                            nonce: '<?php echo wp_create_nonce('wp-repox-ajax-nonce'); ?>',
                            search: searchTerm
                        },
                        success: function(response) {
                            $('.repox-loading').hide();
                            
                            if (response.error) {
                                $('.repox-error').html(response.error).show();
                                return;
                            }
                            
                            if (response.items && response.items.length > 0) {
                                displayResults(response.items);
                            } else {
                                $('.repox-no-results').show();
                            }
                        },
                        error: function() {
                            $('.repox-loading').hide();
                            $('.repox-error').html('<?php _e('Error connecting to repository.', 'wp-repox'); ?>').show();
                        }
                    });
                }
                
                function displayResults(items) {
                    var resultsContainer = $('.repox-results');
                    resultsContainer.empty();
                    
                    $.each(items, function(index, item) {
                        var itemHtml = '<div class="repox-item">' +
                            '<h3>' + item.name + '</h3>' +
                            '<div class="repox-item-description">' + item.description + '</div>' +
                            '<div class="repox-item-meta">' +
                            '<span class="repox-item-version">Version: ' + item.version + '</span>' +
                            '</div>' +
                            '<div class="repox-item-actions">' +
                            '<button class="button button-primary repox-install-button" data-slug="' + item.slug + '">Install</button>' +
                            '</div>' +
                            '</div>';
                        
                        resultsContainer.append(itemHtml);
                    });
                    
                    resultsContainer.show();
                    
                    // Handle install buttons
                    $('.repox-install-button').on('click', function() {
                        var installButton = $(this);
                        var slug = installButton.data('slug');
                        
                        installButton.prop('disabled', true).text('<?php _e('Installing...', 'wp-repox'); ?>');
                        
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'repox_install_item',
                                nonce: '<?php echo wp_create_nonce('wp-repox-ajax-nonce'); ?>',
                                type: itemType,
                                slug: slug
                            },
                            success: function(response) {
                                if (response.success) {
                                    installButton.text('<?php _e('Installed', 'wp-repox'); ?>');
                                } else {
                                    installButton.prop('disabled', false).text('<?php _e('Error', 'wp-repox'); ?>');
                                    alert(response.data.message);
                                }
                            },
                            error: function() {
                                installButton.prop('disabled', false).text('<?php _e('Error', 'wp-repox'); ?>');
                                alert('<?php _e('Installation failed.', 'wp-repox'); ?>');
                            }
                        });
                    });
                }
            });
            </script>
        </div>
        <?php
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts($hook) {
        // Only load our scripts on our pages
        if (strpos($hook, 'plugin-install.php') !== false || strpos($hook, 'theme-install.php') !== false) {
            wp_enqueue_style('wp-repox-styles', WP_REPOX_PLUGIN_URL . 'assets/css/admin.css', array(), WP_REPOX_VERSION);
        }
    }

    /**
     * Get download URL for an item
     */
    private function get_download_url($type, $slug) {
        if (empty($this->options['repo_url'])) {
            return false;
        }
        
        $repo_url = trailingslashit($this->options['repo_url']);
        return $repo_url . $type . 's/download/' . $slug;
    }

    /**
     * Fetch items from repository
     */
    private function fetch_from_repo($endpoint, $search = '') {
        if (empty($this->options['repo_url'])) {
            return ['error' => __('Repository URL is not configured.', 'wp-repox')];
        }

        $repo_url = trailingslashit($this->options['repo_url']);
        $request_url = $repo_url . $endpoint . '/search';
        
        if (!empty($search)) {
            $request_url = add_query_arg('query', urlencode($search), $request_url);
        }
        
        $args = array(
            'timeout' => 30
        );
        
        // Add authentication if configured
        if ($this->options['auth_method'] !== 'none' && !empty($this->options['auth_username'])) {
            if ($this->options['auth_method'] === 'basic') {
                $args['headers'] = array(
                    'Authorization' => 'Basic ' . base64_encode($this->options['auth_username'] . ':' . $this->options['auth_password'])
                );
            } elseif ($this->options['auth_method'] === 'token') {
                $args['headers'] = array(
                    'Authorization' => 'Bearer ' . $this->options['auth_password']
                );
            }
        }
        
        $response = wp_remote_get($request_url, $args);
        
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 200) {
            return ['error' => sprintf(__('Repository returned error: %s', 'wp-repox'), $code)];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => __('Invalid response from repository', 'wp-repox')];
        }
        
        return $data;
    }
}

/**
 * Initialize the plugin
 */
function wp_repox_init() {
    return WP_RepoX::get_instance();
}
add_action('plugins_loaded', 'wp_repox_init');

/**
 * Network activation hook for multisite
 */
function wp_repox_network_activated($network_wide) {
    if ($network_wide && is_multisite()) {
        // Initialize default network options
        $default_options = [
            'repo_url' => '',
            'auth_username' => '',
            'auth_password' => '',
            'auth_method' => 'none'
        ];
        
        // Only add if the option doesn't exist
        if (get_site_option('wp_repox_options') === false) {
            add_site_option('wp_repox_options', $default_options);
        }
    }
}
register_activation_hook(__FILE__, 'wp_repox_network_activated');
