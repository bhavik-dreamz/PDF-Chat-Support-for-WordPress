<?php
/**
 * Pinecone Handler
 * 
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDF_Chat_Support_Pinecone_Handler {
    
    private $api_key;
    private $environment;
    private $index_name;
    private $api_base_url;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_key = get_option('pdf_chat_support_pinecone_api_key');
        $this->environment = get_option('pdf_chat_support_pinecone_environment', 'us-west1-gcp');
        $this->index_name = get_option('pdf_chat_support_pinecone_index', 'pdf-chat-support');
        $this->api_base_url = "https://controller.{$this->environment}.pinecone.io";
    }
    
    /**
     * Test Pinecone connection
     */
    public function test_connection() {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => __('API key not configured', 'pdf-chat-support')
            );
        }
        
        $response = wp_remote_get($this->api_base_url . '/databases', array(
            'headers' => array(
                'Api-Key' => $this->api_key,
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
                'message' => isset($error_data['message']) ? $error_data['message'] : __('Connection failed', 'pdf-chat-support')
            );
        }
        
        return array(
            'success' => true,
            'message' => __('Connection successful', 'pdf-chat-support')
        );
    }
    
    /**
     * Create index if it doesn't exist
     */
    public function create_index() {
        $index_url = $this->api_base_url . '/databases';
        
        $body = json_encode(array(
            'name' => $this->index_name,
            'dimension' => 1536, // OpenAI embedding dimension
            'metric' => 'cosine'
        ));
        
        $response = wp_remote_post($index_url, array(
            'headers' => array(
                'Api-Key' => $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => $body,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        // 201 = created, 409 = already exists
        if ($response_code === 201 || $response_code === 409) {
            return array(
                'success' => true,
                'message' => __('Index ready', 'pdf-chat-support')
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $error_data = json_decode($body, true);
        
        return array(
            'success' => false,
            'message' => isset($error_data['message']) ? $error_data['message'] : __('Failed to create index', 'pdf-chat-support')
        );
    }
    
    /**
     * Upsert vectors to Pinecone
     */
    public function upsert_vectors($vectors) {
        $index_url = "https://{$this->index_name}-{$this->environment}.svc.{$this->get_cloud_region()}.pinecone.io/vectors/upsert";
        
        $body = json_encode(array(
            'vectors' => $vectors
        ));
        
        $response = wp_remote_post($index_url, array(
            'headers' => array(
                'Api-Key' => $this->api_key,
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
                'message' => isset($error_data['message']) ? $error_data['message'] : __('Failed to upsert vectors', 'pdf-chat-support')
            );
        }
        
        return array(
            'success' => true,
            'message' => __('Vectors upserted successfully', 'pdf-chat-support')
        );
    }
    
    /**
     * Query vectors from Pinecone
     */
    public function query_vectors($query_vector, $top_k = 5, $filter = null) {
        $index_url = "https://{$this->index_name}-{$this->environment}.svc.{$this->get_cloud_region()}.pinecone.io/query";
        
        $query_data = array(
            'vector' => $query_vector,
            'topK' => $top_k,
            'includeMetadata' => true,
            'includeValues' => false
        );
        
        if ($filter) {
            $query_data['filter'] = $filter;
        }
        
        $body = json_encode($query_data);
        
        $response = wp_remote_post($index_url, array(
            'headers' => array(
                'Api-Key' => $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => $body,
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
                'message' => isset($error_data['message']) ? $error_data['message'] : __('Failed to query vectors', 'pdf-chat-support')
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return array(
            'success' => true,
            'matches' => $data['matches'] ?? array()
        );
    }
    
    /**
     * Delete vectors by document ID
     */
    public function delete_document_vectors($document_id) {
        $index_url = "https://{$this->index_name}-{$this->environment}.svc.{$this->get_cloud_region()}.pinecone.io/vectors/delete";
        
        $body = json_encode(array(
            'filter' => array(
                'document_id' => array('$eq' => $document_id)
            )
        ));
        
        $response = wp_remote_request($index_url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Api-Key' => $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => $body,
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
                'message' => isset($error_data['message']) ? $error_data['message'] : __('Failed to delete vectors', 'pdf-chat-support')
            );
        }
        
        return array(
            'success' => true,
            'message' => __('Document vectors deleted successfully', 'pdf-chat-support')
        );
    }
    
    /**
     * Get cloud region for the environment
     */
    private function get_cloud_region() {
        // Default regions for common environments
        $region_map = array(
            'us-west1-gcp' => 'gcp-starter',
            'us-east1-gcp' => 'gcp-starter',
            'asia-northeast1-gcp' => 'gcp-starter',
            'us-west2-aws' => 'aws',
            'us-east-1-aws' => 'aws',
            'eu-west1-aws' => 'aws'
        );
        
        return isset($region_map[$this->environment]) ? $region_map[$this->environment] : 'gcp-starter';
    }
    
    /**
     * Get index stats
     */
    public function get_index_stats() {
        $index_url = "https://{$this->index_name}-{$this->environment}.svc.{$this->get_cloud_region()}.pinecone.io/describe_index_stats";
        
        $response = wp_remote_get($index_url, array(
            'headers' => array(
                'Api-Key' => $this->api_key,
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
                'message' => isset($error_data['message']) ? $error_data['message'] : __('Failed to get index stats', 'pdf-chat-support')
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return array(
            'success' => true,
            'stats' => $data
        );
    }
}