<?php
/**
 * Chat Widget Frontend
 * 
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDF_Chat_Support_Chat_Widget {
    
    private $settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = PDF_Chat_Support_Settings::get_settings();
        
        if ($this->settings['chat_widget_enabled']) {
            add_action('wp_footer', array($this, 'render_chat_widget'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        }
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        // Only load on frontend pages where widget is enabled
        if (is_admin() || !$this->settings['chat_widget_enabled']) {
            return;
        }
        
        wp_enqueue_script(
            'pdf-chat-support-widget',
            PDF_CHAT_SUPPORT_PLUGIN_URL . 'assets/js/chat-widget.js',
            array('jquery'),
            PDF_CHAT_SUPPORT_VERSION,
            true
        );
        
        wp_enqueue_style(
            'pdf-chat-support-widget',
            PDF_CHAT_SUPPORT_PLUGIN_URL . 'assets/css/chat-widget.css',
            array(),
            PDF_CHAT_SUPPORT_VERSION
        );
        
        // Localize script
        wp_localize_script('pdf-chat-support-widget', 'pdfChatSupport', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pdf_chat_support_nonce'),
            'sessionId' => $this->get_session_id(),
            'settings' => array(
                'position' => $this->settings['chat_widget_position'],
                'color' => $this->settings['chat_widget_color'],
                'welcomeMessage' => $this->settings['chat_welcome_message'],
                'offlineMessage' => $this->settings['chat_offline_message'],
            ),
            'strings' => array(
                'placeholder' => __('Type your message...', 'pdf-chat-support'),
                'send' => __('Send', 'pdf-chat-support'),
                'minimize' => __('Minimize', 'pdf-chat-support'),
                'maximize' => __('Maximize', 'pdf-chat-support'),
                'close' => __('Close', 'pdf-chat-support'),
                'typing' => __('Assistant is typing...', 'pdf-chat-support'),
                'error' => __('Sorry, something went wrong. Please try again.', 'pdf-chat-support'),
                'rateLimitError' => __('Too many requests. Please wait before sending another message.', 'pdf-chat-support'),
                'connectionError' => __('Connection error. Please check your internet connection.', 'pdf-chat-support'),
                'poweredBy' => __('Powered by PDF Chat Support', 'pdf-chat-support'),
            )
        ));
    }
    
    /**
     * Render chat widget HTML
     */
    public function render_chat_widget() {
        if (!$this->settings['chat_widget_enabled']) {
            return;
        }
        
        $position_class = 'chat-widget-' . $this->settings['chat_widget_position'];
        $widget_color = $this->settings['chat_widget_color'];
        ?>
        <div id="pdf-chat-widget" class="pdf-chat-widget <?php echo esc_attr($position_class); ?>" style="--widget-color: <?php echo esc_attr($widget_color); ?>;">
            <!-- Chat Toggle Button -->
            <div id="chat-toggle-btn" class="chat-toggle-btn">
                <svg class="chat-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 2H4C2.9 2 2 2.9 2 4V16C2 17.1 2.9 18 4 18H6L10 22L14 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2Z" fill="currentColor"/>
                </svg>
                <svg class="close-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: none;">
                    <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            
            <!-- Chat Window -->
            <div id="chat-window" class="chat-window" style="display: none;">
                <!-- Header -->
                <div class="chat-header">
                    <div class="chat-title">
                        <span><?php _e('Support Chat', 'pdf-chat-support'); ?></span>
                    </div>
                    <div class="chat-controls">
                        <button id="chat-minimize" class="chat-control-btn" title="<?php esc_attr_e('Minimize', 'pdf-chat-support'); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6 12L18 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                        <button id="chat-close" class="chat-control-btn" title="<?php esc_attr_e('Close', 'pdf-chat-support'); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <!-- Messages Container -->
                <div id="chat-messages" class="chat-messages">
                    <div class="welcome-message">
                        <div class="message assistant-message">
                            <div class="message-content">
                                <?php echo esc_html($this->settings['chat_welcome_message']); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Typing Indicator -->
                <div id="typing-indicator" class="typing-indicator" style="display: none;">
                    <div class="typing-dots">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    <span class="typing-text"><?php _e('Assistant is typing...', 'pdf-chat-support'); ?></span>
                </div>
                
                <!-- Input Area -->
                <div class="chat-input-container">
                    <div class="chat-input-wrapper">
                        <textarea 
                            id="chat-input" 
                            class="chat-input" 
                            placeholder="<?php esc_attr_e('Type your message...', 'pdf-chat-support'); ?>"
                            rows="1"
                            maxlength="1000"
                        ></textarea>
                        <button id="chat-send" class="chat-send-btn" disabled>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M22 2L11 13M22 2L15 22L11 13M22 2L2 9L11 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>
                    <div class="chat-footer">
                        <small><?php _e('Powered by PDF Chat Support', 'pdf-chat-support'); ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Message Templates -->
        <script type="text/template" id="user-message-template">
            <div class="message user-message">
                <div class="message-content">{{content}}</div>
                <div class="message-time">{{time}}</div>
            </div>
        </script>
        
        <script type="text/template" id="assistant-message-template">
            <div class="message assistant-message">
                <div class="message-content">{{content}}</div>
                {{#if sources}}
                <div class="message-sources">
                    <details>
                        <summary><?php _e('Sources', 'pdf-chat-support'); ?></summary>
                        <ul>
                            {{#each sources}}
                            <li>{{filename}} (Page {{page}})</li>
                            {{/each}}
                        </ul>
                    </details>
                </div>
                {{/if}}
                <div class="message-time">{{time}}</div>
            </div>
        </script>
        
        <script type="text/template" id="error-message-template">
            <div class="message error-message">
                <div class="message-content">{{content}}</div>
                <div class="message-time">{{time}}</div>
            </div>
        </script>
        <?php
    }
    
    /**
     * Get or create session ID
     */
    private function get_session_id() {
        if (!session_id()) {
            session_start();
        }
        
        if (!isset($_SESSION['pdf_chat_session_id'])) {
            $_SESSION['pdf_chat_session_id'] = wp_generate_uuid4();
        }
        
        return $_SESSION['pdf_chat_session_id'];
    }
    
    /**
     * Check if widget should be displayed
     */
    private function should_display_widget() {
        // Add conditions for when to show/hide the widget
        // For example: specific pages, user roles, etc.
        
        return apply_filters('pdf_chat_support_display_widget', true);
    }
}