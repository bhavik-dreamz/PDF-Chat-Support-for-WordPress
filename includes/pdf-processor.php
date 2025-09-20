<?php
/**
 * PDF Processor
 * 
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDF_Chat_Support_PDF_Processor {
    
    private $settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = PDF_Chat_Support_Settings::get_settings();
    }
    
    /**
     * Process PDF file
     */
    public function process_pdf($document) {
        try {
            // Extract text from PDF
            $text_extraction_result = $this->extract_text_from_pdf($document->file_path);
            if (!$text_extraction_result['success']) {
                return $text_extraction_result;
            }
            
            $full_text = $text_extraction_result['text'];
            $pages = $text_extraction_result['pages'];
            
            // Chunk the text
            $chunks = $this->chunk_text($full_text, $pages);
            
            if (empty($chunks)) {
                return array(
                    'success' => false,
                    'message' => __('No text content found in PDF', 'pdf-chat-support')
                );
            }
            
            // Generate embeddings and store in Pinecone
            $embedding_generator = new PDF_Chat_Support_Embedding_Generator();
            $pinecone_handler = new PDF_Chat_Support_Pinecone_Handler();
            
            $vectors = array();
            $processed_chunks = 0;
            $total_chunks = count($chunks);
            
            foreach ($chunks as $index => $chunk) {
                // Generate embedding for chunk
                $embedding_result = $embedding_generator->generate_embedding($chunk['text']);
                if (!$embedding_result['success']) {
                    continue; // Skip this chunk
                }
                
                // Prepare vector for Pinecone
                $vector_id = $document->id . '_chunk_' . $index;
                $vectors[] = array(
                    'id' => $vector_id,
                    'values' => $embedding_result['embedding'],
                    'metadata' => array(
                        'document_id' => intval($document->id),
                        'filename' => $document->original_filename,
                        'chunk_index' => $index,
                        'page_number' => $chunk['page'],
                        'text' => $chunk['text'],
                        'created_at' => current_time('mysql')
                    )
                );
                
                $processed_chunks++;
                
                // Batch upsert every 100 vectors
                if (count($vectors) >= 100) {
                    $upsert_result = $pinecone_handler->upsert_vectors($vectors);
                    if (!$upsert_result['success']) {
                        error_log('PDF Chat Support: Failed to upsert vectors: ' . $upsert_result['message']);
                    }
                    $vectors = array(); // Reset for next batch
                }
            }
            
            // Upsert remaining vectors
            if (!empty($vectors)) {
                $upsert_result = $pinecone_handler->upsert_vectors($vectors);
                if (!$upsert_result['success']) {
                    error_log('PDF Chat Support: Failed to upsert remaining vectors: ' . $upsert_result['message']);
                }
            }
            
            return array(
                'success' => true,
                'total_chunks' => $total_chunks,
                'processed_chunks' => $processed_chunks,
                'message' => __('PDF processed successfully', 'pdf-chat-support')
            );
            
        } catch (Exception $e) {
            error_log('PDF Chat Support: PDF processing error: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Extract text from PDF
     */
    private function extract_text_from_pdf($file_path) {
        // For now, we'll use a simple approach with pdf2txt or pdftotext
        // In production, you might want to use a PHP PDF library like TCPDF or Smalot\PdfParser
        
        // Method 1: Try using Smalot\PdfParser (if available)
        if (class_exists('Smalot\PdfParser\Parser')) {
            return $this->extract_with_smalot_parser($file_path);
        }
        
        // Method 2: Try using command line tools
        $command_result = $this->extract_with_command_line($file_path);
        if ($command_result['success']) {
            return $command_result;
        }
        
        // Method 3: Fallback - basic text extraction
        return $this->extract_basic_text($file_path);
    }
    
    /**
     * Extract text using Smalot PDF Parser
     */
    private function extract_with_smalot_parser($file_path) {
        try {
            require_once PDF_CHAT_SUPPORT_PLUGIN_DIR . 'vendor/smalot/pdfparser/src/Smalot/PdfParser/Parser.php';
            
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file_path);
            
            $pages = $pdf->getPages();
            $extracted_pages = array();
            $full_text = '';
            
            foreach ($pages as $page_num => $page) {
                $page_text = $page->getText();
                $extracted_pages[$page_num + 1] = $page_text;
                $full_text .= $page_text . "\n\n";
            }
            
            return array(
                'success' => true,
                'text' => trim($full_text),
                'pages' => $extracted_pages
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Smalot parser error: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Extract text using command line tools
     */
    private function extract_with_command_line($file_path) {
        // Try pdftotext first
        $output = array();
        $return_code = 0;
        
        $command = sprintf('pdftotext "%s" -', escapeshellarg($file_path));
        exec($command, $output, $return_code);
        
        if ($return_code === 0 && !empty($output)) {
            $text = implode("\n", $output);
            return array(
                'success' => true,
                'text' => $text,
                'pages' => array(1 => $text) // Simple single page representation
            );
        }
        
        return array(
            'success' => false,
            'message' => 'Command line extraction failed'
        );
    }
    
    /**
     * Basic text extraction (fallback)
     */
    private function extract_basic_text($file_path) {
        // This is a very basic fallback that won't work well with complex PDFs
        // It's mainly for demonstration purposes
        $content = file_get_contents($file_path);
        
        // Very basic PDF text extraction (not recommended for production)
        if (strpos($content, '%PDF') !== 0) {
            return array(
                'success' => false,
                'message' => 'Invalid PDF file'
            );
        }
        
        // Extract readable text using regex (very basic)
        preg_match_all('/\(([^)]+)\)/', $content, $matches);
        $text = implode(' ', $matches[1] ?? array());
        
        if (empty($text)) {
            return array(
                'success' => false,
                'message' => 'No extractable text found'
            );
        }
        
        return array(
            'success' => true,
            'text' => $text,
            'pages' => array(1 => $text)
        );
    }
    
    /**
     * Chunk text into smaller pieces
     */
    private function chunk_text($full_text, $pages) {
        $chunks = array();
        $chunk_size = $this->settings['chunk_size'];
        $overlap = intval($chunk_size * 0.1); // 10% overlap
        
        // Method 1: Chunk by pages first
        foreach ($pages as $page_num => $page_text) {
            if (empty(trim($page_text))) {
                continue;
            }
            
            // If page is smaller than chunk size, use as single chunk
            if (strlen($page_text) <= $chunk_size) {
                $chunks[] = array(
                    'text' => trim($page_text),
                    'page' => $page_num
                );
                continue;
            }
            
            // Split large pages into chunks
            $page_chunks = $this->split_text_into_chunks($page_text, $chunk_size, $overlap);
            foreach ($page_chunks as $chunk_text) {
                $chunks[] = array(
                    'text' => $chunk_text,
                    'page' => $page_num
                );
            }
        }
        
        return $chunks;
    }
    
    /**
     * Split text into chunks
     */
    private function split_text_into_chunks($text, $chunk_size, $overlap = 0) {
        $chunks = array();
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        $current_chunk = '';
        
        foreach ($sentences as $sentence) {
            // If adding this sentence would exceed chunk size
            if (strlen($current_chunk . ' ' . $sentence) > $chunk_size) {
                if (!empty($current_chunk)) {
                    $chunks[] = trim($current_chunk);
                    
                    // Start new chunk with overlap
                    if ($overlap > 0) {
                        $words = explode(' ', $current_chunk);
                        $overlap_words = array_slice($words, -$overlap);
                        $current_chunk = implode(' ', $overlap_words);
                    } else {
                        $current_chunk = '';
                    }
                }
            }
            
            $current_chunk .= ($current_chunk ? ' ' : '') . $sentence;
        }
        
        // Add the final chunk
        if (!empty($current_chunk)) {
            $chunks[] = trim($current_chunk);
        }
        
        return $chunks;
    }
    
    /**
     * Clean and normalize text
     */
    private function clean_text($text) {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove special characters that might interfere with processing
        $text = preg_replace('/[^\w\s\p{P}]/u', '', $text);
        
        // Trim and normalize
        return trim($text);
    }
    
    /**
     * Get PDF metadata
     */
    public function get_pdf_metadata($file_path) {
        $metadata = array();
        
        try {
            if (class_exists('Smalot\PdfParser\Parser')) {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($file_path);
                $details = $pdf->getDetails();
                
                $metadata = array(
                    'title' => $details['Title'] ?? '',
                    'author' => $details['Author'] ?? '',
                    'subject' => $details['Subject'] ?? '',
                    'creator' => $details['Creator'] ?? '',
                    'producer' => $details['Producer'] ?? '',
                    'creation_date' => $details['CreationDate'] ?? '',
                    'modification_date' => $details['ModDate'] ?? '',
                    'pages' => count($pdf->getPages())
                );
            }
        } catch (Exception $e) {
            error_log('PDF Chat Support: Failed to extract PDF metadata: ' . $e->getMessage());
        }
        
        return $metadata;
    }
}