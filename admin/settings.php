<?php
/**
 * Settings Handler
 * 
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDF_Chat_Support_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_pdf_chat_test_connection', array($this, 'test_api_connection'));
        add_action('wp_ajax_pdf_chat_fetch_services', array($this, 'fetch_services_data'));
        add_action('wp_ajax_pdf_chat_submit_support_ticket', array($this, 'submit_support_ticket'));
    }
    
    /**
     * Test API connection
     */
    public function test_api_connection() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pdf_chat_support_admin_nonce')) {
            wp_die(__('Security check failed', 'pdf-chat-support'));
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'pdf-chat-support'));
        }
        
        $api_type = sanitize_text_field($_POST['api_type']);
        $response = array('success' => false, 'message' => '');
        
        switch ($api_type) {
            case 'pinecone':
                $response = $this->test_pinecone_connection();
                break;
            case 'openai':
                $response = $this->test_openai_connection();
                break;
            default:
                $response['message'] = __('Invalid API type', 'pdf-chat-support');
        }
        
        wp_send_json($response);
    }
    
    /**
     * Test Pinecone connection
     */
    private function test_pinecone_connection() {
        $api_key = get_option('pdf_chat_support_pinecone_api_key');
        
        if (empty($api_key)) {
            return array(
                'success' => false,
                'message' => __('Pinecone API key not configured', 'pdf-chat-support')
            );
        }
        
        // Test Pinecone connection
        $pinecone_handler = new PDF_Chat_Support_Pinecone_Handler();
        $test_result = $pinecone_handler->test_connection();
        
        return array(
            'success' => $test_result['success'],
            'message' => $test_result['message']
        );
    }
    
    /**
     * Test OpenAI connection
     */
    private function test_openai_connection() {
        $api_key = get_option('pdf_chat_support_openai_api_key');
        
        if (empty($api_key)) {
            return array(
                'success' => false,
                'message' => __('OpenAI API key not configured', 'pdf-chat-support')
            );
        }
        
        // Test OpenAI connection
        $embedding_generator = new PDF_Chat_Support_Embedding_Generator();
        $test_result = $embedding_generator->test_connection();
        
        return array(
            'success' => $test_result['success'],
            'message' => $test_result['message']
        );
    }
    
    /**
     * Get plugin settings
     */
    public static function get_settings() {
        $defaults = array(
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
        
        $settings = get_option('pdf_chat_support_settings', array());
        return wp_parse_args($settings, $defaults);
    }
    
    /**
     * Update plugin settings
     */
    public static function update_settings($new_settings) {
        $current_settings = self::get_settings();
        $updated_settings = wp_parse_args($new_settings, $current_settings);
        
        return update_option('pdf_chat_support_settings', $updated_settings);
    }
    
    /**
     * Get API keys
     */
    public static function get_api_keys() {
        return array(
            'pinecone' => get_option('pdf_chat_support_pinecone_api_key', ''),
            'openai' => get_option('pdf_chat_support_openai_api_key', '')
        );
    }
    
    /**
     * Fetch services data from main website
     */
    public function fetch_services_data() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pdf_chat_support_admin_nonce')) {
            wp_die(__('Security check failed', 'pdf-chat-support'));
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'pdf-chat-support'));
        }
        
        $response = array('success' => false, 'data' => array());
        
        try {
            // Your main website API endpoint
            $api_url = 'https://your-main-website.com/api/services-support';
            
            // Make API request
            $api_response = wp_remote_get($api_url, array(
                'timeout' => 15,
                'headers' => array(
                    'User-Agent' => 'PDF Chat Support Plugin/' . PDF_CHAT_SUPPORT_VERSION,
                    'Accept' => 'application/json'
                )
            ));
            
            if (is_wp_error($api_response)) {
                throw new Exception($api_response->get_error_message());
            }
            
            $response_code = wp_remote_retrieve_response_code($api_response);
            if ($response_code !== 200) {
                throw new Exception('API request failed with status: ' . $response_code);
            }
            
            $body = wp_remote_retrieve_body($api_response);
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON response from API');
            }
            
            // Structure the response data
            $response['success'] = true;
            $response['data'] = array(
                'services' => $data['services'] ?? $this->get_fallback_services(),
                'faq' => $data['faq'] ?? $this->get_fallback_faq(),
                'customization_options' => $data['customization'] ?? $this->get_fallback_customization(),
                'integration_help' => $data['integration'] ?? $this->get_fallback_integration(),
                'contact_info' => $data['contact'] ?? $this->get_fallback_contact(),
                'documentation_links' => $data['documentation'] ?? $this->get_fallback_documentation()
            );
            
        } catch (Exception $e) {
            error_log('PDF Chat Support: Services API error: ' . $e->getMessage());
            
            // Provide fallback data
            $response['success'] = true; // Still return success but with fallback data
            $response['data'] = array(
                'services' => $this->get_fallback_services(),
                'faq' => $this->get_fallback_faq(),
                'customization_options' => $this->get_fallback_customization(),
                'integration_help' => $this->get_fallback_integration(),
                'contact_info' => $this->get_fallback_contact(),
                'documentation_links' => $this->get_fallback_documentation()
            );
        }
        
        wp_send_json($response);
    }
    
    /**
     * Submit support ticket
     */
    public function submit_support_ticket() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pdf_chat_support_admin_nonce')) {
            wp_die(__('Security check failed', 'pdf-chat-support'));
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'pdf-chat-support'));
        }
        
        $response = array('success' => false, 'message' => '');
        
        // Validate required fields
        $required_fields = array('name', 'email', 'support_type', 'message');
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $response['message'] = sprintf(__('Field "%s" is required', 'pdf-chat-support'), $field);
                wp_send_json($response);
            }
        }
        
        // Sanitize input data
        $ticket_data = array(
            'name' => sanitize_text_field($_POST['name']),
            'email' => sanitize_email($_POST['email']),
            'website' => sanitize_url($_POST['website'] ?? ''),
            'support_type' => sanitize_text_field($_POST['support_type']),
            'priority' => sanitize_text_field($_POST['priority'] ?? 'medium'),
            'message' => sanitize_textarea_field($_POST['message']),
            'include_system_info' => !empty($_POST['include_system_info']),
            'plugin_version' => PDF_CHAT_SUPPORT_VERSION,
            'wp_version' => get_bloginfo('version'),
            'submitted_at' => current_time('mysql')
        );
        
        // Add system info if requested
        if ($ticket_data['include_system_info']) {
            $admin_page = new PDF_Chat_Support_Admin();
            $ticket_data['system_info'] = $admin_page->get_system_info();
        }
        
        try {
            // Send to your support system API
            $api_url = 'https://your-main-website.com/api/support-tickets';
            
            $api_response = wp_remote_post($api_url, array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'PDF Chat Support Plugin/' . PDF_CHAT_SUPPORT_VERSION,
                ),
                'body' => json_encode($ticket_data)
            ));
            
            if (is_wp_error($api_response)) {
                throw new Exception($api_response->get_error_message());
            }
            
            $response_code = wp_remote_retrieve_response_code($api_response);
            if ($response_code !== 200 && $response_code !== 201) {
                throw new Exception('Support API request failed with status: ' . $response_code);
            }
            
            $body = wp_remote_retrieve_body($api_response);
            $api_data = json_decode($body, true);
            
            $response['success'] = true;
            $response['message'] = __('Support ticket submitted successfully! We\'ll get back to you soon.', 'pdf-chat-support');
            $response['ticket_id'] = $api_data['ticket_id'] ?? null;
            
        } catch (Exception $e) {
            error_log('PDF Chat Support: Support ticket submission error: ' . $e->getMessage());
            
            // Fallback: Send via email
            $this->send_support_email($ticket_data);
            
            $response['success'] = true;
            $response['message'] = __('Support request sent via email. We\'ll get back to you soon.', 'pdf-chat-support');
        }
        
        wp_send_json($response);
    }
    
    /**
     * Send support ticket via email as fallback
     */
    private function send_support_email($ticket_data) {
        $to = 'support@yourwebsite.com'; // Change this to your support email
        $subject = 'PDF Chat Support - ' . ucfirst($ticket_data['support_type']) . ' Request';
        
        $message = "New support request from PDF Chat Support plugin:\n\n";
        $message .= "Name: " . $ticket_data['name'] . "\n";
        $message .= "Email: " . $ticket_data['email'] . "\n";
        $message .= "Website: " . $ticket_data['website'] . "\n";
        $message .= "Support Type: " . $ticket_data['support_type'] . "\n";
        $message .= "Priority: " . $ticket_data['priority'] . "\n";
        $message .= "Plugin Version: " . $ticket_data['plugin_version'] . "\n";
        $message .= "WordPress Version: " . $ticket_data['wp_version'] . "\n\n";
        $message .= "Message:\n" . $ticket_data['message'] . "\n\n";
        
        if ($ticket_data['include_system_info']) {
            $message .= "System Information:\n" . $ticket_data['system_info'] . "\n";
        }
        
        $headers = array(
            'Reply-To: ' . $ticket_data['name'] . ' <' . $ticket_data['email'] . '>',
            'Content-Type: text/plain; charset=UTF-8'
        );
        
        wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Get fallback services data
     */
    private function get_fallback_services() {
        return array(
            array(
                'name' => 'WordPress Development',
                'description' => 'Custom WordPress theme and plugin development services.',
                'icon' => '🔧',
                'link' => 'https://your-website.com/wordpress-development',
                'price' => 'Starting at $500'
            ),
            array(
                'name' => 'WooCommerce Solutions',
                'description' => 'Complete eCommerce solutions with WooCommerce.',
                'icon' => '🛒',
                'link' => 'https://your-website.com/woocommerce-solutions',
                'price' => 'Starting at $800'
            ),
            array(
                'name' => 'Plugin Customization',
                'description' => 'Customize existing plugins to fit your specific needs.',
                'icon' => '⚙️',
                'link' => 'https://your-website.com/plugin-customization',
                'price' => 'Starting at $200'
            )
        );
    }
    
    /**
     * Get fallback FAQ data
     */
    private function get_fallback_faq() {
        return array(
            array(
                'question' => 'Why is my PDF not processing?',
                'answer' => 'Check if your PDF contains extractable text and isn\'t password-protected. Also verify your API keys are configured correctly.'
            ),
            array(
                'question' => 'Chat widget not appearing on frontend?',
                'answer' => 'Make sure the chat widget is enabled in General Settings and check for JavaScript errors in browser console.'
            ),
            array(
                'question' => 'API connection test failing?',
                'answer' => 'Verify your API keys are correct and your server can make outbound HTTPS requests.'
            )
        );
    }
    
    /**
     * Get fallback customization options
     */
    private function get_fallback_customization() {
        return array(
            array(
                'service' => 'Custom Widget Design',
                'description' => 'Customize the chat widget to match your brand perfectly.',
                'price' => '$150'
            ),
            array(
                'service' => 'Advanced Integration',
                'description' => 'Integrate with your CRM, helpdesk, or other systems.',
                'price' => '$300'
            ),
            array(
                'service' => 'Multi-language Setup',
                'description' => 'Configure multi-language support with WPML or Polylang.',
                'price' => '$200'
            )
        );
    }
    
    /**
     * Get fallback integration help
     */
    private function get_fallback_integration() {
        return array(
            'popular_integrations' => array(
                'Zapier', 'WooCommerce', 'Contact Form 7', 'Gravity Forms', 'HubSpot', 'Mailchimp'
            ),
            'documentation' => 'https://your-website.com/docs/integrations',
            'contact_support' => 'Need help with a specific integration? Contact our support team.'
        );
    }
    
    /**
     * Get fallback contact info
     */
    private function get_fallback_contact() {
        return array(
            'email' => 'support@yourwebsite.com',
            'response_time' => '24-48 hours',
            'support_hours' => 'Monday - Friday, 9AM - 6PM EST'
        );
    }
    
    /**
     * Get fallback documentation links
     */
    private function get_fallback_documentation() {
        return array(
            array(
                'title' => 'Getting Started Guide',
                'url' => 'https://your-website.com/docs/getting-started'
            ),
            array(
                'title' => 'API Configuration',
                'url' => 'https://your-website.com/docs/api-setup'
            ),
            array(
                'title' => 'Troubleshooting',
                'url' => 'https://your-website.com/docs/troubleshooting'
            )
        );
    }
}