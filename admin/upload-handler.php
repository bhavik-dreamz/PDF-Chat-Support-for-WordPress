<?php
/**
 * Upload Handler
 * 
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDF_Chat_Support_Upload_Handler {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_pdf_chat_upload_pdf', array($this, 'handle_pdf_upload'));
        add_action('wp_ajax_pdf_chat_delete_pdf', array($this, 'handle_pdf_delete'));
        add_action('wp_ajax_pdf_chat_process_pdf', array($this, 'handle_pdf_processing'));
    }
    
    /**
     * Handle PDF upload
     */
    public function handle_pdf_upload() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pdf_chat_support_admin_nonce')) {
            wp_die(__('Security check failed', 'pdf-chat-support'));
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'pdf-chat-support'));
        }
        
        $response = array('success' => false, 'message' => '');
        
        // Check if file was uploaded
        if (!isset($_FILES['pdf_file'])) {
            $response['message'] = __('No file uploaded', 'pdf-chat-support');
            wp_send_json($response);
        }
        
        $file = $_FILES['pdf_file'];
        
        // Validate file
        $validation_result = $this->validate_pdf_file($file);
        if (!$validation_result['valid']) {
            $response['message'] = $validation_result['message'];
            wp_send_json($response);
        }
        
        // Handle file upload
        $upload_result = $this->upload_pdf_file($file);
        if (!$upload_result['success']) {
            $response['message'] = $upload_result['message'];
            wp_send_json($response);
        }
        
        // Store in database
        $db_result = $this->store_pdf_info($upload_result['file_info']);
        if (!$db_result['success']) {
            // Clean up uploaded file
            unlink($upload_result['file_info']['file_path']);
            $response['message'] = $db_result['message'];
            wp_send_json($response);
        }
        
        // Schedule processing
        $this->schedule_pdf_processing($db_result['document_id']);
        
        $response['success'] = true;
        $response['message'] = __('File uploaded successfully', 'pdf-chat-support');
        $response['document_id'] = $db_result['document_id'];
        
        wp_send_json($response);
    }
    
    /**
     * Handle PDF deletion
     */
    public function handle_pdf_delete() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pdf_chat_support_admin_nonce')) {
            wp_die(__('Security check failed', 'pdf-chat-support'));
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'pdf-chat-support'));
        }
        
        $document_id = intval($_POST['document_id']);
        $response = array('success' => false, 'message' => '');
        
        global $wpdb;
        
        // Get document info
        $document = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pdf_chat_documents WHERE id = %d",
            $document_id
        ));
        
        if (!$document) {
            $response['message'] = __('Document not found', 'pdf-chat-support');
            wp_send_json($response);
        }
        
        // Delete from Pinecone if processed
        if ($document->status === 'processed') {
            $pinecone_handler = new PDF_Chat_Support_Pinecone_Handler();
            $pinecone_handler->delete_document_vectors($document_id);
        }
        
        // Delete file from filesystem
        if (file_exists($document->file_path)) {
            unlink($document->file_path);
        }
        
        // Delete from database
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'pdf_chat_documents',
            array('id' => $document_id),
            array('%d')
        );
        
        if ($deleted === false) {
            $response['message'] = __('Failed to delete document from database', 'pdf-chat-support');
            wp_send_json($response);
        }
        
        $response['success'] = true;
        $response['message'] = __('Document deleted successfully', 'pdf-chat-support');
        
        wp_send_json($response);
    }
    
    /**
     * Handle PDF processing
     */
    public function handle_pdf_processing() {
        $document_id = intval($_POST['document_id']);
        
        global $wpdb;
        
        // Get document info
        $document = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pdf_chat_documents WHERE id = %d",
            $document_id
        ));
        
        if (!$document) {
            return;
        }
        
        // Update status to processing
        $wpdb->update(
            $wpdb->prefix . 'pdf_chat_documents',
            array('status' => 'processing'),
            array('id' => $document_id),
            array('%s'),
            array('%d')
        );
        
        try {
            // Process PDF
            $pdf_processor = new PDF_Chat_Support_PDF_Processor();
            $processing_result = $pdf_processor->process_pdf($document);
            
            if ($processing_result['success']) {
                // Update status to processed
                $wpdb->update(
                    $wpdb->prefix . 'pdf_chat_documents',
                    array(
                        'status' => 'processed',
                        'total_chunks' => $processing_result['total_chunks'],
                        'processed_chunks' => $processing_result['processed_chunks']
                    ),
                    array('id' => $document_id),
                    array('%s', '%d', '%d'),
                    array('%d')
                );
            } else {
                // Update status to failed
                $wpdb->update(
                    $wpdb->prefix . 'pdf_chat_documents',
                    array(
                        'status' => 'failed',
                        'error_message' => $processing_result['message']
                    ),
                    array('id' => $document_id),
                    array('%s', '%s'),
                    array('%d')
                );
            }
        } catch (Exception $e) {
            // Update status to failed
            $wpdb->update(
                $wpdb->prefix . 'pdf_chat_documents',
                array(
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ),
                array('id' => $document_id),
                array('%s', '%s'),
                array('%d')
            );
        }
    }
    
    /**
     * Validate PDF file
     */
    private function validate_pdf_file($file) {
        $settings = PDF_Chat_Support_Settings::get_settings();
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return array(
                'valid' => false,
                'message' => $this->get_upload_error_message($file['error'])
            );
        }
        
        // Check file size
        if ($file['size'] > $settings['max_file_size']) {
            return array(
                'valid' => false,
                'message' => sprintf(
                    __('File size exceeds maximum allowed size of %s', 'pdf-chat-support'),
                    size_format($settings['max_file_size'])
                )
            );
        }
        
        // Check file type
        $file_type = wp_check_filetype($file['name']);
        if (!in_array($file_type['ext'], $settings['allowed_file_types'])) {
            return array(
                'valid' => false,
                'message' => __('Invalid file type. Only PDF files are allowed.', 'pdf-chat-support')
            );
        }
        
        // Check MIME type
        if ($file_type['type'] !== 'application/pdf') {
            return array(
                'valid' => false,
                'message' => __('Invalid file type. Only PDF files are allowed.', 'pdf-chat-support')
            );
        }
        
        return array('valid' => true);
    }
    
    /**
     * Upload PDF file
     */
    private function upload_pdf_file($file) {
        $upload_dir = wp_upload_dir();
        $pdf_upload_dir = $upload_dir['basedir'] . '/pdf-chat-support/';
        
        // Create directory if it doesn't exist
        if (!file_exists($pdf_upload_dir)) {
            wp_mkdir_p($pdf_upload_dir);
        }
        
        // Generate unique filename
        $filename = wp_unique_filename($pdf_upload_dir, $file['name']);
        $file_path = $pdf_upload_dir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            return array(
                'success' => false,
                'message' => __('Failed to move uploaded file', 'pdf-chat-support')
            );
        }
        
        return array(
            'success' => true,
            'file_info' => array(
                'filename' => $filename,
                'original_filename' => $file['name'],
                'file_path' => $file_path,
                'file_size' => $file['size']
            )
        );
    }
    
    /**
     * Store PDF info in database
     */
    private function store_pdf_info($file_info) {
        global $wpdb;
        
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'pdf_chat_documents',
            array(
                'filename' => $file_info['filename'],
                'original_filename' => $file_info['original_filename'],
                'file_path' => $file_info['file_path'],
                'file_size' => $file_info['file_size'],
                'upload_date' => current_time('mysql'),
                'status' => 'uploaded'
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        if ($inserted === false) {
            return array(
                'success' => false,
                'message' => __('Failed to store file information in database', 'pdf-chat-support')
            );
        }
        
        return array(
            'success' => true,
            'document_id' => $wpdb->insert_id
        );
    }
    
    /**
     * Schedule PDF processing
     */
    private function schedule_pdf_processing($document_id) {
        // For now, we'll process immediately in background
        // In production, you might want to use wp_schedule_single_event()
        wp_schedule_single_event(time() + 10, 'pdf_chat_support_process_pdf', array($document_id));
    }
    
    /**
     * Get upload error message
     */
    private function get_upload_error_message($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return __('The uploaded file exceeds the upload_max_filesize directive in php.ini', 'pdf-chat-support');
            case UPLOAD_ERR_FORM_SIZE:
                return __('The uploaded file exceeds the MAX_FILE_SIZE directive', 'pdf-chat-support');
            case UPLOAD_ERR_PARTIAL:
                return __('The uploaded file was only partially uploaded', 'pdf-chat-support');
            case UPLOAD_ERR_NO_FILE:
                return __('No file was uploaded', 'pdf-chat-support');
            case UPLOAD_ERR_NO_TMP_DIR:
                return __('Missing a temporary folder', 'pdf-chat-support');
            case UPLOAD_ERR_CANT_WRITE:
                return __('Failed to write file to disk', 'pdf-chat-support');
            case UPLOAD_ERR_EXTENSION:
                return __('File upload stopped by extension', 'pdf-chat-support');
            default:
                return __('Unknown upload error', 'pdf-chat-support');
        }
    }
}