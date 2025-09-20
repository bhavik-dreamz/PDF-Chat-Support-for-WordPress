<?php
/**
 * Plugin Name: PDF Chat Support
 * Plugin URI: https://github.com/yourusername/pdf-chat-support-for-wordpress
 * Description: AI-powered chat support plugin that uses PDF documents stored in Pinecone vector database to provide intelligent responses to customer queries.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: pdf-chat-support
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PDF_CHAT_SUPPORT_VERSION', '1.0.0');
define('PDF_CHAT_SUPPORT_PLUGIN_FILE', __FILE__);
define('PDF_CHAT_SUPPORT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PDF_CHAT_SUPPORT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PDF_CHAT_SUPPORT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 */
class PDF_Chat_Support {
    
    /**
     * Single instance of the plugin
     * @var PDF_Chat_Support
     */
    private static $instance = null;
    
    /**
     * Get instance of the plugin
     * @return PDF_Chat_Support
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('PDF_Chat_Support', 'uninstall'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Load required files
        $this->load_dependencies();
        
        // Initialize admin interface
        if (is_admin()) {
            $this->init_admin();
        }
        
        // Initialize frontend
        $this->init_frontend();
        
        // Setup AJAX handlers
        $this->setup_ajax_handlers();
        
        // Add plugin action links
        add_filter('plugin_action_links_' . PDF_CHAT_SUPPORT_PLUGIN_BASENAME, array($this, 'plugin_action_links'));
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Admin files
        if (is_admin()) {
            require_once PDF_CHAT_SUPPORT_PLUGIN_DIR . 'admin/admin-page.php';
            require_once PDF_CHAT_SUPPORT_PLUGIN_DIR . 'admin/settings.php';
            require_once PDF_CHAT_SUPPORT_PLUGIN_DIR . 'admin/upload-handler.php';
        }
        
        // Core includes
        require_once PDF_CHAT_SUPPORT_PLUGIN_DIR . 'includes/pinecone-handler.php';
        require_once PDF_CHAT_SUPPORT_PLUGIN_DIR . 'includes/pdf-processor.php';
        require_once PDF_CHAT_SUPPORT_PLUGIN_DIR . 'includes/chat-handler.php';
        require_once PDF_CHAT_SUPPORT_PLUGIN_DIR . 'includes/embedding-generator.php';
        
        // Frontend files
        if (!is_admin()) {
            require_once PDF_CHAT_SUPPORT_PLUGIN_DIR . 'frontend/chat-widget.php';
        }
    }
    
    /**
     * Initialize admin interface
     */
    private function init_admin() {
        new PDF_Chat_Support_Admin();
        new PDF_Chat_Support_Settings();
        new PDF_Chat_Support_Upload_Handler();
    }
    
    /**
     * Initialize frontend
     */
    private function init_frontend() {
        if (!is_admin()) {
            new PDF_Chat_Support_Chat_Widget();
        }
    }
    
    /**
     * Setup AJAX handlers
     */
    private function setup_ajax_handlers() {
        // For logged-in users
        add_action('wp_ajax_pdf_chat_send_message', array('PDF_Chat_Support_Chat_Handler', 'handle_chat_message'));
        add_action('wp_ajax_pdf_chat_upload_pdf', array('PDF_Chat_Support_Upload_Handler', 'handle_pdf_upload'));
        add_action('wp_ajax_pdf_chat_delete_pdf', array('PDF_Chat_Support_Upload_Handler', 'handle_pdf_delete'));
        add_action('wp_ajax_pdf_chat_test_connection', array('PDF_Chat_Support_Settings', 'test_api_connection'));
        
        // For non-logged-in users (public chat)
        add_action('wp_ajax_nopriv_pdf_chat_send_message', array('PDF_Chat_Support_Chat_Handler', 'handle_chat_message'));
    }
    
    /**
     * Load plugin textdomain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'pdf-chat-support',
            false,
            dirname(PDF_CHAT_SUPPORT_PLUGIN_BASENAME) . '/languages/'
        );
    }
    
    /**
     * Plugin activation hook
     */
    public function activate() {
        $this->create_database_tables();
        $this->set_default_options();
        
        // Create upload directory
        $upload_dir = wp_upload_dir();
        $pdf_upload_dir = $upload_dir['basedir'] . '/pdf-chat-support/';
        if (!file_exists($pdf_upload_dir)) {
            wp_mkdir_p($pdf_upload_dir);
        }
        
        // Add .htaccess protection for uploaded PDFs
        $htaccess_file = $pdf_upload_dir . '.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "<Files *.pdf>\n";
            $htaccess_content .= "    Order deny,allow\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "</Files>\n";
            file_put_contents($htaccess_file, $htaccess_content);
        }
        
        // Set plugin version
        update_option('pdf_chat_support_version', PDF_CHAT_SUPPORT_VERSION);
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation hook
     */
    public function deactivate() {
        // Clear scheduled events if any
        wp_clear_scheduled_hook('pdf_chat_support_cleanup');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin uninstall hook
     */
    public static function uninstall() {
        global $wpdb;
        
        // Remove database tables
        $tables = array(
            $wpdb->prefix . 'pdf_chat_documents',
            $wpdb->prefix . 'pdf_chat_conversations',
            $wpdb->prefix . 'pdf_chat_messages',
            $wpdb->prefix . 'pdf_chat_settings'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        
        // Remove options
        delete_option('pdf_chat_support_version');
        delete_option('pdf_chat_support_settings');
        delete_option('pdf_chat_support_pinecone_api_key');
        delete_option('pdf_chat_support_openai_api_key');
        
        // Remove uploaded files
        $upload_dir = wp_upload_dir();
        $pdf_upload_dir = $upload_dir['basedir'] . '/pdf-chat-support/';
        if (file_exists($pdf_upload_dir)) {
            self::delete_directory($pdf_upload_dir);
        }
    }
    
    /**
     * Create database tables
     */
    private function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // PDF documents table
        $sql_documents = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pdf_chat_documents (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            filename varchar(255) NOT NULL,
            original_filename varchar(255) NOT NULL,
            file_path text NOT NULL,
            file_size bigint(20) unsigned NOT NULL,
            upload_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status enum('uploaded','processing','processed','failed') NOT NULL DEFAULT 'uploaded',
            total_chunks int(11) unsigned DEFAULT 0,
            processed_chunks int(11) unsigned DEFAULT 0,
            error_message text NULL,
            metadata longtext NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY upload_date (upload_date)
        ) $charset_collate;";
        
        // Conversations table
        $sql_conversations = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pdf_chat_conversations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(128) NOT NULL,
            user_id bigint(20) unsigned NULL,
            user_ip varchar(45) NOT NULL,
            user_agent text NULL,
            started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status enum('active','ended','archived') NOT NULL DEFAULT 'active',
            metadata longtext NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY user_id (user_id),
            KEY started_at (started_at),
            KEY status (status)
        ) $charset_collate;";
        
        // Messages table
        $sql_messages = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pdf_chat_messages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) unsigned NOT NULL,
            message_type enum('user','assistant','system') NOT NULL,
            content longtext NOT NULL,
            sources longtext NULL,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            metadata longtext NULL,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY timestamp (timestamp),
            FOREIGN KEY (conversation_id) REFERENCES {$wpdb->prefix}pdf_chat_conversations(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Settings table
        $sql_settings = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pdf_chat_settings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            setting_key varchar(128) NOT NULL,
            setting_value longtext NULL,
            autoload enum('yes','no') NOT NULL DEFAULT 'yes',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_documents);
        dbDelta($sql_conversations);
        dbDelta($sql_messages);
        dbDelta($sql_settings);
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $default_settings = array(
            'chat_widget_enabled' => true,
            'chat_widget_position' => 'bottom-right',
            'chat_widget_color' => '#0073aa',
            'chat_welcome_message' => __('Hi! How can I help you today?', 'pdf-chat-support'),
            'chat_offline_message' => __('We are currently offline. Please leave a message.', 'pdf-chat-support'),
            'max_file_size' => 10485760, // 10MB
            'allowed_file_types' => array('pdf'),
            'chunk_size' => 1000,
            'similarity_threshold' => 0.7,
            'max_response_tokens' => 500,
            'rate_limit_requests' => 60,
            'rate_limit_window' => 3600,
        );
        
        if (!get_option('pdf_chat_support_settings')) {
            update_option('pdf_chat_support_settings', $default_settings);
        }
    }
    
    /**
     * Add plugin action links
     */
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=pdf-chat-support') . '">' . __('Settings', 'pdf-chat-support') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Recursively delete directory
     */
    private static function delete_directory($dir) {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            if (!self::delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        
        return rmdir($dir);
    }
}

// Initialize the plugin
PDF_Chat_Support::get_instance();