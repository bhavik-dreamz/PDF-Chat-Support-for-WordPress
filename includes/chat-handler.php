<?php
/**
 * Chat Handler
 * 
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDF_Chat_Support_Chat_Handler {
    
    private $settings;
    private $rate_limiter;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = PDF_Chat_Support_Settings::get_settings();
        $this->init_rate_limiter();
    }
    
    /**
     * Handle chat message AJAX request
     */
    public static function handle_chat_message() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pdf_chat_support_nonce')) {
            wp_die(__('Security check failed', 'pdf-chat-support'));
        }
        
        $handler = new self();
        $handler->process_chat_message();
    }
    
    /**
     * Process chat message
     */
    public function process_chat_message() {
        $message = sanitize_textarea_field($_POST['message']);
        $session_id = sanitize_text_field($_POST['session_id']);
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : null;
        
        $response = array('success' => false, 'message' => '');
        
        // Rate limiting check
        if (!$this->check_rate_limit()) {
            $response['message'] = __('Too many requests. Please wait before sending another message.', 'pdf-chat-support');
            wp_send_json($response);
        }
        
        // Validate message
        if (empty($message)) {
            $response['message'] = __('Message cannot be empty', 'pdf-chat-support');
            wp_send_json($response);
        }
        
        try {
            // Get or create conversation
            if (!$conversation_id) {
                $conversation_id = $this->create_conversation($session_id);
            }
            
            // Store user message
            $user_message_id = $this->store_message($conversation_id, 'user', $message);
            
            // Generate AI response
            $ai_response_result = $this->generate_ai_response($message, $conversation_id);
            
            if ($ai_response_result['success']) {
                // Store AI response
                $ai_message_id = $this->store_message(
                    $conversation_id, 
                    'assistant', 
                    $ai_response_result['content'],
                    $ai_response_result['sources'] ?? null
                );
                
                $response = array(
                    'success' => true,
                    'conversation_id' => $conversation_id,
                    'user_message_id' => $user_message_id,
                    'ai_message_id' => $ai_message_id,
                    'ai_response' => $ai_response_result['content'],
                    'sources' => $ai_response_result['sources'] ?? array(),
                    'timestamp' => current_time('mysql')
                );
            } else {
                $response['message'] = $ai_response_result['message'];
            }
            
        } catch (Exception $e) {
            error_log('PDF Chat Support: Chat processing error: ' . $e->getMessage());
            $response['message'] = __('Sorry, something went wrong. Please try again.', 'pdf-chat-support');
        }
        
        wp_send_json($response);
    }
    
    /**
     * Generate AI response
     */
    private function generate_ai_response($user_message, $conversation_id) {
        try {
            // Generate embedding for user query
            $embedding_generator = new PDF_Chat_Support_Embedding_Generator();
            $embedding_result = $embedding_generator->generate_embedding($user_message);
            
            if (!$embedding_result['success']) {
                return array(
                    'success' => false,
                    'message' => __('Failed to process your question', 'pdf-chat-support')
                );
            }
            
            // Search for relevant documents in Pinecone
            $pinecone_handler = new PDF_Chat_Support_Pinecone_Handler();
            $search_result = $pinecone_handler->query_vectors($embedding_result['embedding'], 5);
            
            if (!$search_result['success']) {
                return array(
                    'success' => false,
                    'message' => __('Failed to search documents', 'pdf-chat-support')
                );
            }
            
            // Filter results by similarity threshold
            $relevant_chunks = array();
            $sources = array();
            
            foreach ($search_result['matches'] as $match) {
                if ($match['score'] >= $this->settings['similarity_threshold']) {
                    $relevant_chunks[] = $match['metadata'];
                    
                    // Collect unique sources
                    $source_key = $match['metadata']['filename'] . '_' . $match['metadata']['page_number'];
                    if (!isset($sources[$source_key])) {
                        $sources[$source_key] = array(
                            'filename' => $match['metadata']['filename'],
                            'page' => $match['metadata']['page_number'],
                            'relevance' => $match['score']
                        );
                    }
                }
            }
            
            // Prepare context for AI
            $context = '';
            if (!empty($relevant_chunks)) {
                $context = "Based on the following information from the uploaded documents:\n\n";
                foreach ($relevant_chunks as $chunk) {
                    $context .= "From {$chunk['filename']} (Page {$chunk['page_number']}):\n";
                    $context .= $chunk['text'] . "\n\n";
                }
            }
            
            // Get conversation history for context
            $conversation_history = $this->get_conversation_history($conversation_id, 5);
            
            // Prepare messages for AI
            $messages = array(
                array(
                    'role' => 'system',
                    'content' => $this->get_system_prompt($context)
                )
            );
            
            // Add conversation history
            foreach ($conversation_history as $hist_message) {
                $role = $hist_message->message_type === 'user' ? 'user' : 'assistant';
                $messages[] = array(
                    'role' => $role,
                    'content' => $hist_message->content
                );
            }
            
            // Add current user message
            $messages[] = array(
                'role' => 'user',
                'content' => $user_message
            );
            
            // Generate response
            $completion_result = $embedding_generator->generate_chat_completion(
                $messages,
                $this->settings['max_response_tokens']
            );
            
            if (!$completion_result['success']) {
                return array(
                    'success' => false,
                    'message' => __('Failed to generate response', 'pdf-chat-support')
                );
            }
            
            return array(
                'success' => true,
                'content' => $completion_result['content'],
                'sources' => array_values($sources),
                'usage' => $completion_result['usage'] ?? array()
            );
            
        } catch (Exception $e) {
            error_log('PDF Chat Support: AI response generation error: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => __('Failed to generate response', 'pdf-chat-support')
            );
        }
    }
    
    /**
     * Get system prompt for AI
     */
    private function get_system_prompt($context) {
        $prompt = "You are a helpful customer support assistant for a website. Your role is to answer questions based on the provided documentation.\n\n";
        
        if (!empty($context)) {
            $prompt .= "Use the following context from the uploaded documents to answer questions:\n\n";
            $prompt .= $context . "\n";
            $prompt .= "Instructions:\n";
            $prompt .= "1. Answer questions based primarily on the provided context\n";
            $prompt .= "2. If the answer isn't in the context, politely say you don't have that information in the available documents\n";
            $prompt .= "3. Be helpful, concise, and professional\n";
            $prompt .= "4. When referencing information, mention which document and page it comes from\n";
            $prompt .= "5. If asked about topics not covered in the documents, suggest contacting support for more help\n\n";
        } else {
            $prompt .= "I don't have any specific document context for this conversation. ";
            $prompt .= "Please let the user know that you don't have access to relevant documentation for their question and suggest they contact support directly.\n\n";
        }
        
        return $prompt;
    }
    
    /**
     * Create new conversation
     */
    private function create_conversation($session_id) {
        global $wpdb;
        
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $user_ip = $this->get_user_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'pdf_chat_conversations',
            array(
                'session_id' => $session_id,
                'user_id' => $user_id,
                'user_ip' => $user_ip,
                'user_agent' => $user_agent,
                'started_at' => current_time('mysql'),
                'last_activity' => current_time('mysql'),
                'status' => 'active'
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($inserted === false) {
            throw new Exception('Failed to create conversation');
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Store message
     */
    private function store_message($conversation_id, $message_type, $content, $sources = null) {
        global $wpdb;
        
        // Update conversation last activity
        $wpdb->update(
            $wpdb->prefix . 'pdf_chat_conversations',
            array('last_activity' => current_time('mysql')),
            array('id' => $conversation_id),
            array('%s'),
            array('%d')
        );
        
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'pdf_chat_messages',
            array(
                'conversation_id' => $conversation_id,
                'message_type' => $message_type,
                'content' => $content,
                'sources' => $sources ? json_encode($sources) : null,
                'timestamp' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );
        
        if ($inserted === false) {
            throw new Exception('Failed to store message');
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get conversation history
     */
    private function get_conversation_history($conversation_id, $limit = 10) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pdf_chat_messages 
             WHERE conversation_id = %d 
             ORDER BY timestamp DESC 
             LIMIT %d",
            $conversation_id,
            $limit
        ));
    }
    
    /**
     * Initialize rate limiter
     */
    private function init_rate_limiter() {
        $this->rate_limiter = array(
            'requests' => $this->settings['rate_limit_requests'],
            'window' => $this->settings['rate_limit_window']
        );
    }
    
    /**
     * Check rate limit
     */
    private function check_rate_limit() {
        $user_key = $this->get_user_rate_limit_key();
        $cache_key = 'pdf_chat_rate_limit_' . md5($user_key);
        
        $requests = get_transient($cache_key);
        if ($requests === false) {
            $requests = 0;
        }
        
        if ($requests >= $this->rate_limiter['requests']) {
            return false;
        }
        
        set_transient($cache_key, $requests + 1, $this->rate_limiter['window']);
        return true;
    }
    
    /**
     * Get user rate limit key
     */
    private function get_user_rate_limit_key() {
        if (is_user_logged_in()) {
            return 'user_' . get_current_user_id();
        }
        
        return 'ip_' . $this->get_user_ip();
    }
    
    /**
     * Get user IP address
     */
    private function get_user_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }
    
    /**
     * Get conversation by session ID
     */
    public static function get_conversation_by_session($session_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pdf_chat_conversations WHERE session_id = %s",
            $session_id
        ));
    }
    
    /**
     * End conversation
     */
    public static function end_conversation($conversation_id) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'pdf_chat_conversations',
            array('status' => 'ended'),
            array('id' => $conversation_id),
            array('%s'),
            array('%d')
        );
    }
}