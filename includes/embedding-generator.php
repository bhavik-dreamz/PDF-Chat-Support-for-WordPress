<?php
/**
 * Embedding Generator
 * 
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDF_Chat_Support_Embedding_Generator {
    
    private $api_key;
    private $api_base_url;
    private $model;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_key = get_option('pdf_chat_support_openai_api_key');
        $this->api_base_url = 'https://api.openai.com/v1';
        $this->model = 'text-embedding-ada-002';
    }
    
    /**
     * Test OpenAI connection
     */
    public function test_connection() {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => __('API key not configured', 'pdf-chat-support')
            );
        }
        
        $response = wp_remote_get($this->api_base_url . '/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            
            return array(
                'success' => false,
                'message' => isset($error_data['error']['message']) ? $error_data['error']['message'] : __('Connection failed', 'pdf-chat-support')
            );
        }
        
        return array(
            'success' => true,
            'message' => __('Connection successful', 'pdf-chat-support')
        );
    }
    
    /**
     * Generate embedding for text
     */
    public function generate_embedding($text) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => __('OpenAI API key not configured', 'pdf-chat-support')
            );
        }
        
        // Clean and prepare text
        $text = $this->prepare_text_for_embedding($text);
        
        if (empty($text)) {
            return array(
                'success' => false,
                'message' => __('Empty text provided', 'pdf-chat-support')
            );
        }
        
        $body = json_encode(array(
            'model' => $this->model,
            'input' => $text
        ));
        
        $response = wp_remote_post($this->api_base_url . '/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => $body,
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            
            return array(
                'success' => false,
                'message' => isset($error_data['error']['message']) ? $error_data['error']['message'] : __('Failed to generate embedding', 'pdf-chat-support')
            );
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (!isset($data['data'][0]['embedding'])) {
            return array(
                'success' => false,
                'message' => __('Invalid embedding response', 'pdf-chat-support')
            );
        }
        
        return array(
            'success' => true,
            'embedding' => $data['data'][0]['embedding'],
            'usage' => $data['usage'] ?? array()
        );
    }
    
    /**
     * Generate embeddings for multiple texts (batch)
     */
    public function generate_embeddings_batch($texts) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => __('OpenAI API key not configured', 'pdf-chat-support')
            );
        }
        
        // Prepare texts
        $prepared_texts = array();
        foreach ($texts as $text) {
            $prepared_text = $this->prepare_text_for_embedding($text);
            if (!empty($prepared_text)) {
                $prepared_texts[] = $prepared_text;
            }
        }
        
        if (empty($prepared_texts)) {
            return array(
                'success' => false,
                'message' => __('No valid texts provided', 'pdf-chat-support')
            );
        }
        
        $body = json_encode(array(
            'model' => $this->model,
            'input' => $prepared_texts
        ));
        
        $response = wp_remote_post($this->api_base_url . '/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => $body,
            'timeout' => 120
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            
            return array(
                'success' => false,
                'message' => isset($error_data['error']['message']) ? $error_data['error']['message'] : __('Failed to generate embeddings', 'pdf-chat-support')
            );
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (!isset($data['data']) || !is_array($data['data'])) {
            return array(
                'success' => false,
                'message' => __('Invalid embeddings response', 'pdf-chat-support')
            );
        }
        
        $embeddings = array();
        foreach ($data['data'] as $item) {
            $embeddings[] = $item['embedding'];
        }
        
        return array(
            'success' => true,
            'embeddings' => $embeddings,
            'usage' => $data['usage'] ?? array()
        );
    }
    
    /**
     * Generate chat completion
     */
    public function generate_chat_completion($messages, $max_tokens = 500, $temperature = 0.7) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => __('OpenAI API key not configured', 'pdf-chat-support')
            );
        }
        
        $body = json_encode(array(
            'model' => 'gpt-3.5-turbo',
            'messages' => $messages,
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
            'stream' => false
        ));
        
        $response = wp_remote_post($this->api_base_url . '/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => $body,
            'timeout' => 120
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            
            return array(
                'success' => false,
                'message' => isset($error_data['error']['message']) ? $error_data['error']['message'] : __('Failed to generate response', 'pdf-chat-support')
            );
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (!isset($data['choices'][0]['message']['content'])) {
            return array(
                'success' => false,
                'message' => __('Invalid completion response', 'pdf-chat-support')
            );
        }
        
        return array(
            'success' => true,
            'content' => $data['choices'][0]['message']['content'],
            'usage' => $data['usage'] ?? array(),
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'unknown'
        );
    }
    
    /**
     * Prepare text for embedding
     */
    private function prepare_text_for_embedding($text) {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Trim
        $text = trim($text);
        
        // Limit length (OpenAI has token limits)
        if (strlen($text) > 8000) {
            $text = substr($text, 0, 8000);
        }
        
        return $text;
    }
    
    /**
     * Calculate approximate token count
     */
    public function estimate_tokens($text) {
        // Rough estimation: 1 token ≈ 4 characters in English
        return intval(strlen($text) / 4);
    }
    
    /**
     * Get available models
     */
    public function get_available_models() {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => __('OpenAI API key not configured', 'pdf-chat-support')
            );
        }
        
        $response = wp_remote_get($this->api_base_url . '/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return array(
                'success' => false,
                'message' => __('Failed to fetch models', 'pdf-chat-support')
            );
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        return array(
            'success' => true,
            'models' => $data['data'] ?? array()
        );
    }
}