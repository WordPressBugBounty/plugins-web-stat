<?php
/*
Plugin Name: Web-Stat
Plugin URI: https://www.web-stat.com/
Description: Free, real-time stats for your website with full visitor details and traffic analytics.
Version: 2.5.7
Author: <a href="https://www.web-stat.com" target="_new">Web-Stat</a>
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: web-stat
Domain Path: /languages
*/

// Exit if accessed directly.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class WebStatPlugin {
    const VERSION = '2.5.7';
    private $site_id = null;
    private $alias = null;
    private $db = null;
    private $language = null;
    private $old_uid = null;
    private $supported_languages = [];
    private $has_openssl = false;
    private $has_json = false;
    private $oc_a2 = null;
    private $is_admin_user = 0;
    private $is_admin_page = 0;
        
    public function __construct() {
        // Initialize plugin options
	    add_action('init', [$this, 'init_options'], 5);
        // Hook into WordPress actions and filters
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_handle_ajax_data', [$this, 'handle_ajax_data']); // recover fetched data to save it
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        add_action('wp_dashboard_setup', [$this, 'reorder_dashboard_widgets'], 1000);
        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
        add_filter('plugin_action_links', [$this, 'add_plugin_action_links'], 10, 2);
        add_action('admin_head-plugins.php', [$this, 'add_custom_css']);
    }
    
    // load translations
    public function load_textdomain() {
        load_plugin_textdomain('web-stat', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    // Reset alias if plugin is activated or re-activated
    public static function reset_wts_data() {
		delete_option('wts_alias');
		delete_option('wts_db');
		delete_option('wts_oc_a2');
    }
    
    // Get stored data if any and create a site_id if none
    public function init_options() {
        // Initialize plugin options
        $this->supported_languages = ['de', 'es', 'fr', 'it', 'ja', 'pt', 'ru', 'tr'];
        $this->site_id = get_option('wts_site_id');
        if (!$this->site_id) {
            $this->site_id = wp_generate_uuid4();
            update_option('wts_site_id', $this->site_id);
        }
        $this->alias = get_option('wts_alias');
        $this->db = get_option('wts_db');
        $this->oc_a2 = current_user_can('install_plugins') ? (get_option('wts_oc_a2')) : null;
        $this->language = substr(get_bloginfo('language'), 0, 2);
        if (!preg_match('/^[a-z]{2}$/', $this->language)) {
            $this->language = 'en';
        }
        $this->old_uid = get_option('wts_web_stat_uid');
        $this->has_json = extension_loaded('json');
        $this->has_openssl = extension_loaded('openssl');
        if (current_user_can('install_plugins')) {
           $this->is_admin_user = 1;
        }
        if (is_admin()){
           $this->is_admin_page = 1;
        }
    }
    

    // Fetch data if needed then load log7 or admin options
    public function enqueue_scripts() {
		$script_path = plugin_dir_path(__FILE__) . 'js/wts_script.js';
        $script_url  = plugin_dir_url(__FILE__) . 'js/wts_script.js';
        $script_ver  = file_exists($script_path) ? filemtime($script_path) : '1.0.0';
		wp_enqueue_script('wts_init_js', $script_url, array(), $script_ver, true);
        $wts_data = array('ajax_url' => 'https://app.ardalio.com/ajax.pl', 'action' => 'get_wp_data', 'version' => self::VERSION, 'alias' => $this->alias, 'db' => $this->db, 'site_id' => $this->site_id, 'old_uid' => $this->old_uid, 'url' => get_bloginfo('url'), 'language' => get_bloginfo('language'), 'time_zone' => get_option('timezone_string'), 'gmt_offset' => get_option('gmt_offset'), 'email' => get_option('admin_email') );
        if ($this->is_admin_user) {
            $nonce = wp_create_nonce('wts_ajax_nonce');
            if ($this->has_openssl) {
                $publicKey = file_get_contents(__DIR__ . '/includes/public_key.pem');
                openssl_public_encrypt($nonce, $encryptedData, $publicKey);
                $encryptedData = base64_encode($encryptedData);
            } else {
                $encryptedData = $this->stx(time());
            }
            $wts_data['php_ajax_url'] = admin_url('admin-ajax.php');
            $wts_data['oc_a2'] = $this->oc_a2;
            $wts_data['is_admin_user'] = 1;
            $wts_data['is_admin_page'] = $this->is_admin_page ;
            $wts_data['nonce'] = $nonce;
            $wts_data['enc'] = $encryptedData;
            $wts_data['has_openssl'] = $this->has_openssl;
            $current_user = wp_get_current_user();
            $user_info = json_encode(['id' => $current_user->ID, 'date_registered' => $current_user->user_registered, 'email' => $current_user->user_email, 'name' => $current_user->display_name, 'pic' => get_avatar_url($current_user), ]);
            $wts_data['user_info'] = $user_info;
            $wts_data['user_id'] = $current_user->ID;              
        } else {
            if (is_user_logged_in() && $this->has_json) {
                $current_user = wp_get_current_user();
                $user_info = json_encode(['id' => $current_user->ID, 'date_registered' => $current_user->user_registered, 'email' => $current_user->user_email, 'name' => $current_user->display_name, 'pic' => get_avatar_url($current_user), ]);
                $wts_data['user_info'] = $user_info;
                $wts_data['user_id'] = $current_user->ID;
            }
        }
        // Pass PHP data to JavaScript
        wp_localize_script('wts_init_js', 'wts_data', $wts_data);
    }
    
    // If data was fetched by JS, recover it and save it
    public function handle_ajax_data() {
        if (!$this->has_json) {
            // send error back to wts_init_js
            wp_send_json_error('JSON not available');
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wts_ajax_nonce')) {
            // send error back to wts_init_js
            wp_send_json_error('Invalid nonce');
            return;
        }
        $data = isset($_POST['data']) ? $_POST['data'] : '';
        if (!empty($data)) {
            $data = json_decode(stripslashes($data), true);
			if (isset($data['alias'], $data['db']) &&
   			 preg_match('/^\d+$/', $data['alias']) &&
   			 preg_match('/^\d{1,2}$/', $data['db'])) {
                $this->alias = $data['alias'];
                $this->db = $data['db'];
                update_option('wts_alias', $this->alias);
                update_option('wts_db', $this->db);
                if (isset($data['oc_a2'])) {
                    update_option('wts_oc_a2', $data['oc_a2']);
                }
                wp_send_json_success();
            }
            else{
				$aliasValue = $data['alias'] ?? 'not set';
				$dbValue = $data['db'] ?? 'not set';
				wp_send_json_error('wts_init_js sent invalid alias (' . $aliasValue . ') or invalid db (' . $dbValue . ')');
            	return;
            }
        }
        else{
			wp_send_json_error(' wts_init_js sent back empty data');
            return;
        }
    }
    
    private function stx($t) {
        $tr = strrev($t);
        $hex = bin2hex($tr);
        return $hex;
    }
    
    public function add_admin_menu() {
    	// Add the main Web-Stat menu
    	add_menu_page(
        	__('Web-Stat Traffic Analytics', 'web-stat'), // Page title
        	__('Web-Stat', 'web-stat'), // Menu title
        	'install_plugins', // Capability
        	'webstat-stats', // Menu slug (changed to avoid conflicts)
        	[$this, 'show_stats_page'], // Function to display the page
        	'dashicons-chart-bar' // Icon
    	);
    	// Add a submenu under the Web-Stat menu for stats
    	add_submenu_page(
        	'webstat-stats', // Parent slug (should match the menu slug of the parent item)
   			__('View Stats', 'web-stat'), // Page title
			__('View Stats', 'web-stat'), // Submenu title (this will show in the submenu)
			'install_plugins', // Capability
			'webstat-stats', // Menu slug
			[$this, 'show_stats_page'] // Function to display the page
    	);
        // Add a submenu under the Web-Stat menu for settings
    	add_submenu_page(
        	'webstat-stats', // Parent slug (use the slug of the top-level menu)
        	__('Configure', 'web-stat'), // Page title
        	__('Configure', 'web-stat'), // Submenu title
        	'install_plugins', // Capability
        	'webstat-settings', // Menu slug
        	[$this, 'show_settings_page'] // Function to display the page
    	);
          
    	// Add a submenu under the Web-Stat menu for support
    	add_submenu_page(
        	'webstat-stats', // Parent slug (use the slug of the top-level menu)
        	__('Get Support', 'web-stat'), // Page title
        	__('Get Support', 'web-stat'), // Menu title
        	'install_plugins', // Capability
        	'webstat-contact', // Menu slug
        	[$this, 'show_contact_page'] // Function to display the page
    	);
      
    	// Add a submenu under the Web-Stat menu for the Plans Comparison page
    	add_submenu_page(
        	'webstat-stats', // Parent slug (use the slug of the top-level menu)
        	__('Upgrade','web-stat'), // Page title
        	__('Upgrade', 'web-stat'), // Submenu title
        	'install_plugins', // Capability
        	'webstat-plans', // Menu slug
        	[$this, 'show_plans_page'] // Function to display the iframe page
    	);
    }
    public function show_stats_page() {
        $this->show_page('checkstats.htm');
    }
    public function show_settings_page() {
        $this->show_page('settings.htm');
    }
    public function show_contact_page() {
        $this->show_page('contact_us.htm');
    }
    public function show_plans_page() {
        $this->show_page('plans_comparison.htm');
    }
    private function show_page($page) {
        $host = $this->get_host();
        $url = $host . '/' . $page . '?oc_a2=' . $this->oc_a2 . '&is_admin=' . $this->is_admin_user . '&version=' . self::VERSION . '&source=WordPress';
        if (!$host || !$page || !$this->oc_a2){
           self::send_php_error('Could not display dashboard / host = ' . $host . ' / page = ' . $page . ' / oc_a2 = ' . $this->oc_a2);
        }
        echo '
        <style>
        #wpcontent {
            padding-left: 0px !important;
        }
        #wpbody-content {
            margin-bottom: 0px !important;
        }
        #wts_iframe{
            font-size:0.9em;
            margin: 0px !important;
  			overflow: hidden !important;
  			height: 100vh !important;
  			width: 100% !important;
  			border: 0px;
        }
        .notice {
            display: none !important;
        }
        </style>
        <iframe src="' . $url . '" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border:0;" id="wts_iframe"></iframe>';
    }
    private function get_host() {
        if (in_array($this->language, $this->supported_languages)) {
            return 'https://' . $this->language . '.ardalio.net';
        } else {
            return 'https://www.ardalio.net';
        }
    }
    
    public function add_dashboard_widget() {
        if ( ! current_user_can('install_plugins') ) {
			return;
    	}
        wp_add_dashboard_widget('wts_dashboard_widget', // Widget slug
        __('Web-Stat', 'web-stat'), // Title
        [$this, 'render_dashboard_widget'] // Display function
        );
    }
    public function reorder_dashboard_widgets() {
        if ( ! current_user_can('install_plugins') ) {
			return;
    	}
        global $wp_meta_boxes;
        if (isset($wp_meta_boxes['dashboard']['normal']['core']['wts_dashboard_widget'])) {
            $widget = $wp_meta_boxes['dashboard']['normal']['core']['wts_dashboard_widget'];
            unset($wp_meta_boxes['dashboard']['normal']['core']['wts_dashboard_widget']);
            $wp_meta_boxes['dashboard']['normal']['high']['wts_dashboard_widget'] = $widget;
        }
    }
    public function render_dashboard_widget() {
        $dashboard_url = urlencode(admin_url());
        $host = $this->get_host();
        $url = $host . '/wpFrame.htm?&oc_a2=' . $this->oc_a2 . '&version=' . self::VERSION . '&dashboard_url=' . $dashboard_url;
        if (!$host || !$this->oc_a2){
           self::send_php_error('Could not display dashboard widget / host = ' . $host . ' / oc_a2 = ' . $this->oc_a2 . ' / dashboard_url = '.$dashboard_url);
        }
        echo '<iframe src="' . $url . '" style="width:100%; height:500px;" id="wts_iframe"></iframe>';
    }
    
    public function add_plugin_row_meta($links, $plugin_file) {
        // Check if this is the plugin we want to modify
        if (plugin_basename(__FILE__) === $plugin_file) {
            // Get the current items
            $version = array_shift($links);
            $author = array_shift($links);
            $details_link = array_shift($links);
            $links[] = '<a href="admin.php?page=webstat-stats" title="' . __('View my stats', 'web-stat') . '">' . __('View my stats', 'web-stat') . '</a>';
            $links[] = '<a href="admin.php?page=webstat-settings" title="' . __('Configure Web-Stat', 'web-stat') . '">' . __('Configure', 'web-stat') . '</a>';
            $links[] = '<a href="admin.php?page=webstat-contact" title="' . __('Get 24/7 support', 'web-stat') . '">' . __('Get support', 'web-stat') . '</a>';
            $links[] = '<a href="admin.php?page=webstat-plans" title="' . __('Upgrade', 'web-stat') . '">' . __('Change plan', 'web-stat') . '</a>';
            // Reorder items
            array_unshift($links, $version, $author);
            $links[] = $details_link;
        }
        return $links;
    }
    
    public function add_plugin_action_links($links, $file) {
        if (plugin_basename(__FILE__) === $file) {
            $plugin_slug = plugin_basename(__FILE__);
            $nonce = wp_create_nonce('deactivate-plugin_' . $plugin_slug);
            $deactivate_link = admin_url('plugins.php?action=deactivate&plugin=' . $plugin_slug . '&_wpnonce=' . $nonce);
            $links['deactivate'] = '<a href="' . esc_url($deactivate_link) . '" id="deactivate-web-stat" class="wts-icon-links" title="' . __('Deactivate') . '"><span class="dashicons dashicons-dismiss wts-icon"></span></a>';
            $new_links = ['<a href="admin.php?page=webstat-stats" class="wts-icon-links" title="' . __('View my stats', 'web-stat') . '"><span class="dashicons dashicons-chart-bar wts-icon"></span></a>', '<a href="admin.php?page=webstat-settings" class="wts-icon-links" title="' . __('Configure Web-Stat', 'web-stat') . '"><span class="dashicons dashicons-admin-tools wts-icon"></span></a>', '<a href="admin.php?page=webstat-contact" class="wts-icon-links" title="' . __('Get 24/7 support', 'web-stat') . '"><span class="dashicons dashicons-email-alt wts-icon"></span></a>', '<a href="admin.php?page=webstat-plans" class="wts-icon-links" title="' . __('Upgrade', 'web-stat') . '"><span class="dashicons dashicons-star-filled wts-icon"></span></a>'];
            return array_merge($new_links, $links);
        }
        return $links;
    }
    
    public function add_custom_css() {
        echo "
        <style>
            .wts-icon {
                width:23px ! important;
                float:none ! important;
            }
            .wts-icon::before {
                background: transparent ! important;
                font-size: 23px ! important; 
                color: #2774B2 ! important;
                color: initial;
                transition: color 0.3s ease;
            }
            .wts-row-actions{
                padding-top:4px ! important;
            }
            .wts-icon:hover::before {
                color: orange ! important;
            }
            .wts-icon-links{
                height: 25px;
                display: inline-block;
                width: 27px;
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var pluginRow = document.querySelector('tr[data-slug=\"web-stat\"]');
                var rowActionsVisible = pluginRow.querySelector('.row-actions');
                if (rowActionsVisible) {
                    rowActionsVisible.classList.add('wts-row-actions');
                }
            });
        </script>";
    }
    
	public static function send_php_error($e_text, $e_object = '') {
		// Use the plugin version if available
		$version = defined('self::VERSION') ? self::VERSION : 'unknown';
     
		// Build the error data array
		$errData = array(
			'origin'   => 'WP Plugin v.' . $version,
       		'e_text'   => $e_text,
        	// If $e_object is not a string, encode it as JSON
        	'e_object' => is_string($e_object) ? $e_object : json_encode($e_object),
        	'url'      => home_url()
		);
    
    	// Send the data using wp_remote_post()
    	wp_remote_post('https://app.ardalio.com/print.pl', array(
        	'method' => 'POST',
        	'body'   => $errData,
    	));
	}


}




register_activation_hook(__FILE__, ['WebStatPlugin', 'reset_wts_data']);
register_deactivation_hook(__FILE__, ['WebStatPlugin', 'reset_wts_data']);

new WebStatPlugin();
