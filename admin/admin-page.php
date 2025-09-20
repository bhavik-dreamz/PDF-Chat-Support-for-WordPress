<?php
/**
 * Admin Page Handler
 * 
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDF_Chat_Support_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'init_settings'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('PDF Chat Support', 'pdf-chat-support'),
            __('PDF Chat Support', 'pdf-chat-support'),
            'manage_options',
            'pdf-chat-support',
            array($this, 'admin_page_callback')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_pdf-chat-support') {
            return;
        }
        
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-draggable');
        wp_enqueue_script('jquery-ui-droppable');
        
        wp_enqueue_script(
            'pdf-chat-support-admin',
            PDF_CHAT_SUPPORT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-draggable', 'jquery-ui-droppable'),
            PDF_CHAT_SUPPORT_VERSION,
            true
        );
        
        wp_enqueue_style(
            'pdf-chat-support-admin',
            PDF_CHAT_SUPPORT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            PDF_CHAT_SUPPORT_VERSION
        );
        
        // Localize script
        wp_localize_script('pdf-chat-support-admin', 'pdfChatSupportAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pdf_chat_support_admin_nonce'),
            'strings' => array(
                'uploadSuccess' => __('File uploaded successfully', 'pdf-chat-support'),
                'uploadError' => __('Upload failed', 'pdf-chat-support'),
                'deleteConfirm' => __('Are you sure you want to delete this file?', 'pdf-chat-support'),
                'connectionSuccess' => __('Connection successful', 'pdf-chat-support'),
                'connectionError' => __('Connection failed', 'pdf-chat-support'),
                'processing' => __('Processing...', 'pdf-chat-support'),
            )
        ));
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting('pdf_chat_support_settings', 'pdf_chat_support_settings');
        register_setting('pdf_chat_support_settings', 'pdf_chat_support_pinecone_api_key');
        register_setting('pdf_chat_support_settings', 'pdf_chat_support_openai_api_key');
    }
    
    /**
     * Admin page callback
     */
    public function admin_page_callback() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=pdf-chat-support&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General Settings', 'pdf-chat-support'); ?>
                </a>
                <a href="?page=pdf-chat-support&tab=api" class="nav-tab <?php echo $active_tab == 'api' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('API Configuration', 'pdf-chat-support'); ?>
                </a>
                <a href="?page=pdf-chat-support&tab=documents" class="nav-tab <?php echo $active_tab == 'documents' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('PDF Management', 'pdf-chat-support'); ?>
                </a>
                <a href="?page=pdf-chat-support&tab=chat" class="nav-tab <?php echo $active_tab == 'chat' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Chat Configuration', 'pdf-chat-support'); ?>
                </a>
                <a href="?page=pdf-chat-support&tab=analytics" class="nav-tab <?php echo $active_tab == 'analytics' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Analytics', 'pdf-chat-support'); ?>
                </a>
                <a href="?page=pdf-chat-support&tab=support" class="nav-tab <?php echo $active_tab == 'support' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Support & Services', 'pdf-chat-support'); ?>
                </a>
            </nav>
            
            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'api':
                        $this->render_api_tab();
                        break;
                    case 'documents':
                        $this->render_documents_tab();
                        break;
                    case 'chat':
                        $this->render_chat_tab();
                        break;
                    case 'analytics':
                        $this->render_analytics_tab();
                        break;
                    case 'support':
                        $this->render_support_tab();
                        break;
                    default:
                        $this->render_general_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render general settings tab
     */
    private function render_general_tab() {
        $settings = get_option('pdf_chat_support_settings', array());
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('pdf_chat_support_settings');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Chat Widget', 'pdf-chat-support'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="pdf_chat_support_settings[chat_widget_enabled]" value="1" <?php checked(isset($settings['chat_widget_enabled']) && $settings['chat_widget_enabled']); ?>>
                            <?php _e('Enable the chat widget on frontend', 'pdf-chat-support'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Widget Position', 'pdf-chat-support'); ?></th>
                    <td>
                        <select name="pdf_chat_support_settings[chat_widget_position]">
                            <option value="bottom-right" <?php selected(isset($settings['chat_widget_position']) ? $settings['chat_widget_position'] : 'bottom-right', 'bottom-right'); ?>><?php _e('Bottom Right', 'pdf-chat-support'); ?></option>
                            <option value="bottom-left" <?php selected(isset($settings['chat_widget_position']) ? $settings['chat_widget_position'] : '', 'bottom-left'); ?>><?php _e('Bottom Left', 'pdf-chat-support'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Widget Color', 'pdf-chat-support'); ?></th>
                    <td>
                        <input type="color" name="pdf_chat_support_settings[chat_widget_color]" value="<?php echo esc_attr(isset($settings['chat_widget_color']) ? $settings['chat_widget_color'] : '#0073aa'); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Welcome Message', 'pdf-chat-support'); ?></th>
                    <td>
                        <textarea name="pdf_chat_support_settings[chat_welcome_message]" rows="3" cols="50"><?php echo esc_textarea(isset($settings['chat_welcome_message']) ? $settings['chat_welcome_message'] : __('Hi! How can I help you today?', 'pdf-chat-support')); ?></textarea>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }
    
    /**
     * Render API configuration tab
     */
    private function render_api_tab() {
        $pinecone_api_key = get_option('pdf_chat_support_pinecone_api_key', '');
        $openai_api_key = get_option('pdf_chat_support_openai_api_key', '');
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('pdf_chat_support_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Pinecone API Key', 'pdf-chat-support'); ?></th>
                    <td>
                        <input type="password" name="pdf_chat_support_pinecone_api_key" value="<?php echo esc_attr($pinecone_api_key); ?>" class="regular-text">
                        <button type="button" class="button" id="test-pinecone-connection"><?php _e('Test Connection', 'pdf-chat-support'); ?></button>
                        <p class="description"><?php _e('Enter your Pinecone API key for vector storage.', 'pdf-chat-support'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('OpenAI API Key', 'pdf-chat-support'); ?></th>
                    <td>
                        <input type="password" name="pdf_chat_support_openai_api_key" value="<?php echo esc_attr($openai_api_key); ?>" class="regular-text">
                        <button type="button" class="button" id="test-openai-connection"><?php _e('Test Connection', 'pdf-chat-support'); ?></button>
                        <p class="description"><?php _e('Enter your OpenAI API key for embeddings and chat responses.', 'pdf-chat-support'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }
    
    /**
     * Render documents management tab
     */
    private function render_documents_tab() {
        global $wpdb;
        
        $documents = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pdf_chat_documents ORDER BY upload_date DESC");
        ?>
        <div id="pdf-upload-section">
            <h3><?php _e('Upload New PDF', 'pdf-chat-support'); ?></h3>
            <div id="pdf-upload-area" class="upload-area">
                <p><?php _e('Drag and drop PDF files here, or click to select files', 'pdf-chat-support'); ?></p>
                <input type="file" id="pdf-file-input" accept=".pdf" multiple style="display: none;">
                <button type="button" class="button" id="select-files-btn"><?php _e('Select Files', 'pdf-chat-support'); ?></button>
            </div>
            <div id="upload-progress" style="display: none;">
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <p class="progress-text"></p>
            </div>
        </div>
        
        <div id="documents-list">
            <h3><?php _e('Uploaded Documents', 'pdf-chat-support'); ?></h3>
            <?php if (empty($documents)): ?>
                <p><?php _e('No documents uploaded yet.', 'pdf-chat-support'); ?></p>
            <?php else: ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Filename', 'pdf-chat-support'); ?></th>
                            <th><?php _e('Size', 'pdf-chat-support'); ?></th>
                            <th><?php _e('Upload Date', 'pdf-chat-support'); ?></th>
                            <th><?php _e('Status', 'pdf-chat-support'); ?></th>
                            <th><?php _e('Progress', 'pdf-chat-support'); ?></th>
                            <th><?php _e('Actions', 'pdf-chat-support'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr>
                                <td><?php echo esc_html($document->original_filename); ?></td>
                                <td><?php echo size_format($document->file_size); ?></td>
                                <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($document->upload_date)); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($document->status); ?>">
                                        <?php echo esc_html(ucfirst($document->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($document->total_chunks > 0): ?>
                                        <?php echo esc_html($document->processed_chunks . '/' . $document->total_chunks); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button delete-document" data-document-id="<?php echo esc_attr($document->id); ?>">
                                        <?php _e('Delete', 'pdf-chat-support'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render chat configuration tab
     */
    private function render_chat_tab() {
        $settings = get_option('pdf_chat_support_settings', array());
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('pdf_chat_support_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Maximum Response Tokens', 'pdf-chat-support'); ?></th>
                    <td>
                        <input type="number" name="pdf_chat_support_settings[max_response_tokens]" value="<?php echo esc_attr(isset($settings['max_response_tokens']) ? $settings['max_response_tokens'] : 500); ?>" min="100" max="2000">
                        <p class="description"><?php _e('Maximum number of tokens for AI responses.', 'pdf-chat-support'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Similarity Threshold', 'pdf-chat-support'); ?></th>
                    <td>
                        <input type="number" name="pdf_chat_support_settings[similarity_threshold]" value="<?php echo esc_attr(isset($settings['similarity_threshold']) ? $settings['similarity_threshold'] : 0.7); ?>" min="0" max="1" step="0.1">
                        <p class="description"><?php _e('Minimum similarity score for document relevance (0-1).', 'pdf-chat-support'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Rate Limit', 'pdf-chat-support'); ?></th>
                    <td>
                        <input type="number" name="pdf_chat_support_settings[rate_limit_requests]" value="<?php echo esc_attr(isset($settings['rate_limit_requests']) ? $settings['rate_limit_requests'] : 60); ?>" min="1" max="1000">
                        <?php _e('requests per hour', 'pdf-chat-support'); ?>
                        <p class="description"><?php _e('Maximum number of chat requests per user per hour.', 'pdf-chat-support'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }
    
    /**
     * Render analytics tab
     */
    private function render_analytics_tab() {
        global $wpdb;
        
        // Get basic statistics
        $total_conversations = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pdf_chat_conversations");
        $total_messages = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pdf_chat_messages WHERE message_type = 'user'");
        $active_conversations = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pdf_chat_conversations WHERE status = 'active'");
        $total_documents = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pdf_chat_documents WHERE status = 'processed'");
        
        ?>
        <div class="analytics-dashboard">
            <div class="analytics-cards">
                <div class="analytics-card">
                    <h3><?php echo esc_html($total_conversations); ?></h3>
                    <p><?php _e('Total Conversations', 'pdf-chat-support'); ?></p>
                </div>
                <div class="analytics-card">
                    <h3><?php echo esc_html($total_messages); ?></h3>
                    <p><?php _e('Total Messages', 'pdf-chat-support'); ?></p>
                </div>
                <div class="analytics-card">
                    <h3><?php echo esc_html($active_conversations); ?></h3>
                    <p><?php _e('Active Conversations', 'pdf-chat-support'); ?></p>
                </div>
                <div class="analytics-card">
                    <h3><?php echo esc_html($total_documents); ?></h3>
                    <p><?php _e('Processed Documents', 'pdf-chat-support'); ?></p>
                </div>
            </div>
            
            <div class="recent-conversations">
                <h3><?php _e('Recent Conversations', 'pdf-chat-support'); ?></h3>
                <?php
                $recent_conversations = $wpdb->get_results("
                    SELECT c.*, COUNT(m.id) as message_count 
                    FROM {$wpdb->prefix}pdf_chat_conversations c 
                    LEFT JOIN {$wpdb->prefix}pdf_chat_messages m ON c.id = m.conversation_id 
                    GROUP BY c.id 
                    ORDER BY c.last_activity DESC 
                    LIMIT 10
                ");
                
                if ($recent_conversations): ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php _e('Session ID', 'pdf-chat-support'); ?></th>
                                <th><?php _e('Messages', 'pdf-chat-support'); ?></th>
                                <th><?php _e('Started', 'pdf-chat-support'); ?></th>
                                <th><?php _e('Last Activity', 'pdf-chat-support'); ?></th>
                                <th><?php _e('Status', 'pdf-chat-support'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_conversations as $conversation): ?>
                                <tr>
                                    <td><?php echo esc_html(substr($conversation->session_id, 0, 8) . '...'); ?></td>
                                    <td><?php echo esc_html($conversation->message_count); ?></td>
                                    <td><?php echo date_i18n(get_option('date_format'), strtotime($conversation->started_at)); ?></td>
                                    <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($conversation->last_activity)); ?></td>
                                    <td>
                                        <span class="status-<?php echo esc_attr($conversation->status); ?>">
                                            <?php echo esc_html(ucfirst($conversation->status)); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php _e('No conversations yet.', 'pdf-chat-support'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render support and services tab
     */
    private function render_support_tab() {
        ?>
        <div class="support-services-container">
            <!-- Loading Indicator -->
            <div id="support-loading" class="support-loading">
                <div class="loading-spinner"></div>
                <p><?php _e('Loading our services and support options...', 'pdf-chat-support'); ?></p>
            </div>
            
            <!-- Error Message -->
            <div id="support-error" class="support-error" style="display: none;">
                <div class="error-icon">⚠️</div>
                <h3><?php _e('Unable to Load Services', 'pdf-chat-support'); ?></h3>
                <p><?php _e('We couldn\'t fetch the latest information about our services and support options.', 'pdf-chat-support'); ?></p>
                <button type="button" id="retry-support-load" class="button button-primary">
                    <?php _e('Retry', 'pdf-chat-support'); ?>
                </button>
            </div>
            
            <!-- Support Content -->
            <div id="support-content" class="support-content" style="display: none;">
                
                <!-- Quick Contact Section -->
                <div class="support-section quick-contact">
                    <h2><?php _e('Need Help?', 'pdf-chat-support'); ?></h2>
                    <p><?php _e('Get direct support for PDF Chat Support and explore our other WordPress solutions.', 'pdf-chat-support'); ?></p>
                    
                    <div class="contact-options">
                        <div class="contact-card primary">
                            <div class="contact-icon">💬</div>
                            <h3><?php _e('Priority Support', 'pdf-chat-support'); ?></h3>
                            <p><?php _e('Get help with plugin configuration, troubleshooting, and customization.', 'pdf-chat-support'); ?></p>
                            <a href="#" class="button button-primary" id="contact-support-btn">
                                <?php _e('Contact Support', 'pdf-chat-support'); ?>
                            </a>
                        </div>
                        
                        <div class="contact-card">
                            <div class="contact-icon">📧</div>
                            <h3><?php _e('Email Support', 'pdf-chat-support'); ?></h3>
                            <p><?php _e('Send us detailed questions about features or integration help.', 'pdf-chat-support'); ?></p>
                            <a href="mailto:support@yourwebsite.com" class="button">
                                <?php _e('Send Email', 'pdf-chat-support'); ?>
                            </a>
                        </div>
                        
                        <div class="contact-card">
                            <div class="contact-icon">📚</div>
                            <h3><?php _e('Documentation', 'pdf-chat-support'); ?></h3>
                            <p><?php _e('Comprehensive guides, tutorials, and API documentation.', 'pdf-chat-support'); ?></p>
                            <a href="#" class="button" id="view-docs-btn">
                                <?php _e('View Docs', 'pdf-chat-support'); ?>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Other Services Section -->
                <div class="support-section our-services">
                    <h2><?php _e('Our Other WordPress Solutions', 'pdf-chat-support'); ?></h2>
                    <p><?php _e('Discover more premium WordPress plugins and services to enhance your website.', 'pdf-chat-support'); ?></p>
                    
                    <div id="services-grid" class="services-grid">
                        <!-- Services will be populated via AJAX -->
                        <div class="service-placeholder">
                            <div class="placeholder-shimmer"></div>
                        </div>
                        <div class="service-placeholder">
                            <div class="placeholder-shimmer"></div>
                        </div>
                        <div class="service-placeholder">
                            <div class="placeholder-shimmer"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Plugin Support Section -->
                <div class="support-section plugin-support">
                    <h2><?php _e('Plugin-Specific Support', 'pdf-chat-support'); ?></h2>
                    
                    <div class="support-tabs">
                        <div class="support-tab-nav">
                            <button class="support-tab-btn active" data-tab="troubleshooting">
                                <?php _e('Troubleshooting', 'pdf-chat-support'); ?>
                            </button>
                            <button class="support-tab-btn" data-tab="customization">
                                <?php _e('Customization', 'pdf-chat-support'); ?>
                            </button>
                            <button class="support-tab-btn" data-tab="integration">
                                <?php _e('Integration', 'pdf-chat-support'); ?>
                            </button>
                        </div>
                        
                        <div class="support-tab-content">
                            <div id="troubleshooting" class="support-tab-panel active">
                                <h3><?php _e('Common Issues & Solutions', 'pdf-chat-support'); ?></h3>
                                <div class="faq-list" id="troubleshooting-faq">
                                    <!-- FAQ items will be populated via AJAX -->
                                </div>
                                <div class="support-action">
                                    <p><?php _e('Can\'t find a solution? Our support team is here to help.', 'pdf-chat-support'); ?></p>
                                    <button class="button button-primary" id="submit-ticket-btn">
                                        <?php _e('Submit Support Ticket', 'pdf-chat-support'); ?>
                                    </button>
                                </div>
                            </div>
                            
                            <div id="customization" class="support-tab-panel">
                                <h3><?php _e('Customization Services', 'pdf-chat-support'); ?></h3>
                                <div class="customization-options" id="customization-services">
                                    <!-- Customization options will be populated via AJAX -->
                                </div>
                            </div>
                            
                            <div id="integration" class="support-tab-panel">
                                <h3><?php _e('Integration Support', 'pdf-chat-support'); ?></h3>
                                <div class="integration-help" id="integration-support">
                                    <!-- Integration help will be populated via AJAX -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- System Info Section -->
                <div class="support-section system-info">
                    <h2><?php _e('System Information', 'pdf-chat-support'); ?></h2>
                    <p><?php _e('Copy this information when contacting support to help us assist you better.', 'pdf-chat-support'); ?></p>
                    
                    <div class="system-info-box">
                        <div class="system-info-header">
                            <span><?php _e('System Information', 'pdf-chat-support'); ?></span>
                            <button type="button" class="button" id="copy-system-info">
                                <?php _e('Copy to Clipboard', 'pdf-chat-support'); ?>
                            </button>
                        </div>
                        <div class="system-info-content">
                            <pre id="system-info-data"><?php echo $this->get_system_info(); ?></pre>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
        
        <!-- Contact Modal -->
        <div id="contact-modal" class="contact-modal" style="display: none;">
            <div class="contact-modal-content">
                <div class="contact-modal-header">
                    <h3><?php _e('Contact Support', 'pdf-chat-support'); ?></h3>
                    <button type="button" class="contact-modal-close">&times;</button>
                </div>
                <div class="contact-modal-body">
                    <form id="support-contact-form">
                        <div class="form-row">
                            <label for="support-name"><?php _e('Your Name', 'pdf-chat-support'); ?> <span class="required">*</span></label>
                            <input type="text" id="support-name" name="name" required>
                        </div>
                        <div class="form-row">
                            <label for="support-email"><?php _e('Email Address', 'pdf-chat-support'); ?> <span class="required">*</span></label>
                            <input type="email" id="support-email" name="email" required>
                        </div>
                        <div class="form-row">
                            <label for="support-website"><?php _e('Website URL', 'pdf-chat-support'); ?></label>
                            <input type="url" id="support-website" name="website" value="<?php echo esc_url(home_url()); ?>">
                        </div>
                        <div class="form-row">
                            <label for="support-type"><?php _e('Support Type', 'pdf-chat-support'); ?> <span class="required">*</span></label>
                            <select id="support-type" name="support_type" required>
                                <option value=""><?php _e('Select support type', 'pdf-chat-support'); ?></option>
                                <option value="bug"><?php _e('Bug Report', 'pdf-chat-support'); ?></option>
                                <option value="feature"><?php _e('Feature Request', 'pdf-chat-support'); ?></option>
                                <option value="config"><?php _e('Configuration Help', 'pdf-chat-support'); ?></option>
                                <option value="custom"><?php _e('Customization', 'pdf-chat-support'); ?></option>
                                <option value="integration"><?php _e('Integration Support', 'pdf-chat-support'); ?></option>
                                <option value="other"><?php _e('Other', 'pdf-chat-support'); ?></option>
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="support-priority"><?php _e('Priority', 'pdf-chat-support'); ?></label>
                            <select id="support-priority" name="priority">
                                <option value="low"><?php _e('Low', 'pdf-chat-support'); ?></option>
                                <option value="medium" selected><?php _e('Medium', 'pdf-chat-support'); ?></option>
                                <option value="high"><?php _e('High', 'pdf-chat-support'); ?></option>
                                <option value="urgent"><?php _e('Urgent', 'pdf-chat-support'); ?></option>
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="support-message"><?php _e('Message', 'pdf-chat-support'); ?> <span class="required">*</span></label>
                            <textarea id="support-message" name="message" rows="6" required placeholder="<?php esc_attr_e('Please describe your issue or question in detail...', 'pdf-chat-support'); ?>"></textarea>
                        </div>
                        <div class="form-row">
                            <label>
                                <input type="checkbox" id="include-system-info" name="include_system_info" checked>
                                <?php _e('Include system information to help with troubleshooting', 'pdf-chat-support'); ?>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="button" id="cancel-contact">
                                <?php _e('Cancel', 'pdf-chat-support'); ?>
                            </button>
                            <button type="submit" class="button button-primary" id="send-contact">
                                <?php _e('Send Message', 'pdf-chat-support'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <?php
    }
    
    /**
     * Get system information for support
     */
    private function get_system_info() {
        global $wpdb;
        
        $system_info = array();
        
        // WordPress Info
        $system_info[] = "=== WordPress Information ===";
        $system_info[] = "WordPress Version: " . get_bloginfo('version');
        $system_info[] = "Site URL: " . home_url();
        $system_info[] = "WP Multisite: " . (is_multisite() ? 'Yes' : 'No');
        $system_info[] = "Active Theme: " . wp_get_theme()->get('Name') . ' ' . wp_get_theme()->get('Version');
        $system_info[] = "";
        
        // Plugin Info
        $system_info[] = "=== Plugin Information ===";
        $system_info[] = "PDF Chat Support Version: " . PDF_CHAT_SUPPORT_VERSION;
        $system_info[] = "Plugin Path: " . PDF_CHAT_SUPPORT_PLUGIN_DIR;
        
        // API Configuration
        $pinecone_key = get_option('pdf_chat_support_pinecone_api_key');
        $openai_key = get_option('pdf_chat_support_openai_api_key');
        $system_info[] = "Pinecone API Configured: " . (!empty($pinecone_key) ? 'Yes' : 'No');
        $system_info[] = "OpenAI API Configured: " . (!empty($openai_key) ? 'Yes' : 'No');
        
        // Document Stats
        $doc_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pdf_chat_documents");
        $processed_docs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pdf_chat_documents WHERE status = 'processed'");
        $system_info[] = "Total Documents: " . $doc_count;
        $system_info[] = "Processed Documents: " . $processed_docs;
        $system_info[] = "";
        
        // Server Info
        $system_info[] = "=== Server Information ===";
        $system_info[] = "PHP Version: " . PHP_VERSION;
        $system_info[] = "MySQL Version: " . $wpdb->db_version();
        $system_info[] = "Server Software: " . $_SERVER['SERVER_SOFTWARE'];
        $system_info[] = "Max Execution Time: " . ini_get('max_execution_time') . 's';
        $system_info[] = "Memory Limit: " . ini_get('memory_limit');
        $system_info[] = "Upload Max Filesize: " . ini_get('upload_max_filesize');
        $system_info[] = "Post Max Size: " . ini_get('post_max_size');
        $system_info[] = "";
        
        // Active Plugins
        $system_info[] = "=== Active Plugins ===";
        $active_plugins = get_option('active_plugins');
        foreach ($active_plugins as $plugin) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
            $system_info[] = $plugin_data['Name'] . ' ' . $plugin_data['Version'];
        }
        
        return implode("\n", $system_info);
    }
}
}