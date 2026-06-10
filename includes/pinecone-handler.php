<?php
/**
 * Pinecone Handler (serverless + integrated embedding / "Path A")
 *
 * This index uses an integrated embedding model (e.g. llama-text-embed-v2), so
 * we send TEXT records and let Pinecone embed them server-side — we do NOT push
 * our own OpenAI/Gemini vectors. All calls go to the index host directly
 * (e.g. https://my-index-abc123.svc.aped-4627-b74a.pinecone.io), configured in
 * the API settings, instead of the legacy controller.{env}.pinecone.io API.
 *
 * Docs: https://docs.pinecone.io/guides/index-data/upsert-data
 *       https://docs.pinecone.io/guides/search/semantic-search
 *
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDF_Chat_Support_Pinecone_Handler {

    /** Pinecone REST API version supporting the integrated /records endpoints. */
    const API_VERSION = '2025-10';

    /** Max records per integrated upsert request (hosted-embedding limit). */
    const UPSERT_BATCH = 96;

    /** @var string */
    private $api_key;

    /** @var string Index host, e.g. https://idx-abc.svc.aped-xxxx.pinecone.io */
    private $host;

    /** @var string */
    private $namespace;

    /** @var string Text field that matches the index field_map (default chunk_text). */
    private $text_field;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_key    = trim((string) get_option('pdf_chat_support_pinecone_api_key', ''));
        $this->host       = $this->normalize_host(get_option('pdf_chat_support_pinecone_host', ''));
        $this->text_field = get_option('pdf_chat_support_pinecone_text_field', 'chunk_text');

        $namespace = trim((string) get_option('pdf_chat_support_pinecone_namespace', ''));
        $this->namespace = ($namespace === '') ? '__default__' : $namespace;
    }

    /* ---------------------------------------------------------------------
     * Connection test
     * ------------------------------------------------------------------- */

    /**
     * Verify the index host is reachable and report the record count.
     */
    public function test_connection() {
        $missing = $this->config_error();
        if ($missing) {
            return $missing;
        }

        $result = $this->request('POST', '/describe_index_stats', array());
        if (!$result['success']) {
            return $result;
        }

        $count = isset($result['data']['totalVectorCount'])
            ? intval($result['data']['totalVectorCount'])
            : (isset($result['data']['total_vector_count']) ? intval($result['data']['total_vector_count']) : null);

        $message = ($count === null)
            ? __('Connection successful', 'pdf-chat-support')
            : sprintf(__('Connection successful (records in index: %d)', 'pdf-chat-support'), $count);

        return array('success' => true, 'message' => $message);
    }

    /* ---------------------------------------------------------------------
     * Upsert (text records — integrated embedding)
     * ------------------------------------------------------------------- */

    /**
     * Upsert text records. Pinecone embeds the text with the index's model.
     *
     * Each record must contain '_id' and the configured text field; any extra
     * keys are stored as metadata and can be returned in search results.
     *
     * @param array $records
     */
    public function upsert_text_records($records) {
        $missing = $this->config_error();
        if ($missing) {
            return $missing;
        }
        if (empty($records)) {
            return array('success' => true, 'message' => __('Nothing to upsert', 'pdf-chat-support'));
        }

        $path = '/records/namespaces/' . rawurlencode($this->namespace) . '/upsert';

        // Chunk to the hosted-embedding batch limit.
        foreach (array_chunk($records, self::UPSERT_BATCH) as $batch) {
            $ndjson_lines = array();
            foreach ($batch as $record) {
                $ndjson_lines[] = wp_json_encode($record);
            }
            $ndjson = implode("\n", $ndjson_lines);

            $result = $this->request('POST', $path, $ndjson, 'application/x-ndjson');
            if (!$result['success']) {
                return $result;
            }
        }

        return array('success' => true, 'message' => __('Records upserted successfully', 'pdf-chat-support'));
    }

    /* ---------------------------------------------------------------------
     * Search (by text — integrated embedding)
     * ------------------------------------------------------------------- */

    /**
     * Search the index using text. Returns matches normalised to the shape the
     * chat handler expects: [ ['score' => float, 'metadata' => [...] ], ... ].
     *
     * @param string $query_text
     * @param int    $top_k
     */
    public function search_text($query_text, $top_k = 5) {
        $missing = $this->config_error();
        if ($missing) {
            return $missing;
        }

        $path = '/records/namespaces/' . rawurlencode($this->namespace) . '/search';
        $body = array(
            'query'  => array(
                'inputs' => array('text' => $query_text),
                'top_k'  => intval($top_k),
            ),
            // Ask Pinecone to return the fields we stored as metadata.
            'fields' => array($this->text_field, 'filename', 'page_number', 'document_id'),
        );

        $result = $this->request('POST', $path, $body);
        if (!$result['success']) {
            return $result;
        }

        $hits    = $result['data']['result']['hits'] ?? array();
        $matches = array();
        foreach ($hits as $hit) {
            $fields    = $hit['fields'] ?? array();
            $matches[] = array(
                'id'       => $hit['_id'] ?? '',
                'score'    => isset($hit['_score']) ? floatval($hit['_score']) : 0,
                'metadata' => array(
                    'text'        => $fields[$this->text_field] ?? '',
                    'filename'    => $fields['filename'] ?? '',
                    'page_number' => $fields['page_number'] ?? '',
                    'document_id' => $fields['document_id'] ?? '',
                ),
            );
        }

        return array('success' => true, 'matches' => $matches);
    }

    /* ---------------------------------------------------------------------
     * Delete (by document — serverless: list ids by prefix, then delete)
     * ------------------------------------------------------------------- */

    /**
     * Delete every vector belonging to a document. Serverless indexes cannot
     * delete by metadata filter, so we list IDs by the document's id prefix and
     * delete those. Requires the "{document_id}#" id convention used on upsert.
     *
     * @param int $document_id
     */
    public function delete_document_vectors($document_id) {
        $missing = $this->config_error();
        if ($missing) {
            return $missing;
        }

        $prefix           = $document_id . '#';
        $namespace        = rawurlencode($this->namespace);
        $ids              = array();
        $pagination_token = null;

        do {
            $query = 'prefix=' . rawurlencode($prefix) . '&namespace=' . $namespace . '&limit=100';
            if ($pagination_token) {
                $query .= '&paginationToken=' . rawurlencode($pagination_token);
            }

            $result = $this->request('GET', '/vectors/list?' . $query);
            if (!$result['success']) {
                return $result;
            }

            foreach (($result['data']['vectors'] ?? array()) as $vector) {
                if (isset($vector['id'])) {
                    $ids[] = $vector['id'];
                }
            }

            $pagination_token = $result['data']['pagination']['next'] ?? null;
        } while ($pagination_token);

        if (empty($ids)) {
            return array('success' => true, 'message' => __('No vectors to delete', 'pdf-chat-support'));
        }

        // Delete in batches of 1000 (delete-by-id limit).
        foreach (array_chunk($ids, 1000) as $batch) {
            $result = $this->request('POST', '/vectors/delete', array(
                'ids'       => $batch,
                'namespace' => $this->namespace,
            ));
            if (!$result['success']) {
                return $result;
            }
        }

        return array('success' => true, 'message' => __('Document vectors deleted successfully', 'pdf-chat-support'));
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    /**
     * Build the id used for a document chunk. The "{document_id}#" prefix lets
     * us list/delete all chunks of a document on a serverless index.
     */
    public static function chunk_id($document_id, $chunk_index) {
        return $document_id . '#chunk_' . $chunk_index;
    }

    /**
     * Return an error array if Pinecone isn't configured, else null.
     */
    private function config_error() {
        if (empty($this->api_key)) {
            return array('success' => false, 'message' => __('Pinecone API key not configured', 'pdf-chat-support'));
        }
        if (empty($this->host)) {
            return array(
                'success' => false,
                'message' => __('Pinecone index host not configured. Copy the "Host" URL from your index in the Pinecone console into the API settings.', 'pdf-chat-support'),
            );
        }
        return null;
    }

    /**
     * Normalise a host value into a scheme-qualified URL with no trailing slash.
     */
    private function normalize_host($host) {
        $host = trim((string) $host);
        if ($host === '') {
            return '';
        }
        if (strpos($host, 'http://') !== 0 && strpos($host, 'https://') !== 0) {
            $host = 'https://' . $host;
        }
        return rtrim($host, '/');
    }

    /**
     * Perform a Pinecone REST request and decode the JSON response.
     *
     * @param string       $method
     * @param string       $path          Path beginning with '/'.
     * @param array|string $body          Array (JSON-encoded) or raw string body.
     * @param string       $content_type  Request content type.
     * @return array{success:bool,data?:array,message?:string}
     */
    private function request($method, $path, $body = null, $content_type = 'application/json') {
        $args = array(
            'method'  => $method,
            'headers' => array(
                'Api-Key'                => $this->api_key,
                'X-Pinecone-Api-Version' => self::API_VERSION,
                'Accept'                 => 'application/json',
                'Content-Type'           => $content_type,
            ),
            'timeout' => 60,
        );

        if ($body !== null && $method !== 'GET') {
            if (is_string($body)) {
                $args['body'] = $body;
            } else {
                // An empty PHP array encodes to "[]" (a JSON array), but Pinecone
                // endpoints like describe_index_stats expect "{}" (an object).
                $args['body'] = wp_json_encode((is_array($body) && empty($body)) ? (object) $body : $body);
            }
        }

        $response = wp_remote_request($this->host . $path, $args);

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = $data['message'] ?? ($data['error']['message'] ?? sprintf(__('Pinecone request failed (HTTP %d)', 'pdf-chat-support'), $code));
            return array('success' => false, 'message' => $message);
        }

        return array('success' => true, 'data' => is_array($data) ? $data : array());
    }
}
