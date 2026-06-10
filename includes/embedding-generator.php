<?php
/**
 * Embedding & Chat Completion Generator (multi-provider)
 *
 * Embeddings  : OpenAI or Google Gemini.
 * Completions : OpenAI, Groq, Anthropic (Claude) or Google Gemini.
 *
 * The chat handler and PDF processor talk to this class through a stable
 * public API (generate_embedding / generate_embeddings_batch /
 * generate_chat_completion). Only the provider routing inside changed.
 *
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PDF_Chat_Support_Embedding_Generator {

    /** @var string Provider used to create embeddings ('openai' | 'gemini'). */
    private $embedding_provider;

    /** @var string Provider used to generate chat completions. */
    private $chat_provider;

    /** @var array<string,string> API keys keyed by provider. */
    private $keys;

    /**
     * Constructor
     */
    public function __construct() {
        $this->embedding_provider = PDF_Chat_Support_Settings::get_embedding_provider();
        $this->chat_provider      = PDF_Chat_Support_Settings::get_chat_provider();

        $this->keys = array(
            'openai'    => get_option('pdf_chat_support_openai_api_key', ''),
            'groq'      => get_option('pdf_chat_support_groq_api_key', ''),
            'anthropic' => get_option('pdf_chat_support_anthropic_api_key', ''),
            'gemini'    => get_option('pdf_chat_support_gemini_api_key', ''),
        );
    }

    /* ---------------------------------------------------------------------
     * Provider metadata
     * ------------------------------------------------------------------- */

    /**
     * Model id used for the active embedding provider.
     */
    private function embedding_model() {
        if ($this->embedding_provider === 'gemini') {
            return $this->opt('pdf_chat_support_gemini_embedding_model', 'text-embedding-004');
        }
        return $this->opt('pdf_chat_support_openai_embedding_model', 'text-embedding-ada-002');
    }

    /**
     * Model id used for the active chat provider.
     */
    private function chat_model() {
        switch ($this->chat_provider) {
            case 'groq':
                return $this->opt('pdf_chat_support_groq_model', 'llama-3.3-70b-versatile');
            case 'anthropic':
                return $this->opt('pdf_chat_support_anthropic_model', 'claude-opus-4-8');
            case 'gemini':
                return $this->opt('pdf_chat_support_gemini_model', 'gemini-2.0-flash');
            case 'openai':
            default:
                return $this->opt('pdf_chat_support_openai_model', 'gpt-4o-mini');
        }
    }

    /**
     * Read an option, falling back to $default when it is unset OR blank.
     * (get_option()'s own default does not apply to a saved empty string, so a
     * blank model field would otherwise produce a malformed request URL.)
     */
    private function opt($key, $default) {
        $value = trim((string) get_option($key, ''));
        return ($value === '') ? $default : $value;
    }

    /**
     * Vector dimension produced by the active embedding provider.
     * Pinecone indexes must be created with this dimension.
     */
    public function get_embedding_dimension() {
        if ($this->embedding_provider === 'gemini') {
            return 768;  // text-embedding-004
        }
        return 1536;     // text-embedding-ada-002 / text-embedding-3-small
    }

    /**
     * API key for a provider, or '' if not set.
     */
    private function key_for($provider) {
        return isset($this->keys[$provider]) ? trim($this->keys[$provider]) : '';
    }

    /* ---------------------------------------------------------------------
     * Connection test
     * ------------------------------------------------------------------- */

    /**
     * Test connectivity for a given provider (defaults to the chat provider).
     *
     * @param string|null $provider
     */
    public function test_connection($provider = null) {
        $provider = $provider ?: $this->chat_provider;
        $key      = $this->key_for($provider);

        if (empty($key)) {
            return array(
                'success' => false,
                'message' => sprintf(__('%s API key not configured', 'pdf-chat-support'), ucfirst($provider))
            );
        }

        switch ($provider) {
            case 'openai':
                return $this->http_test('https://api.openai.com/v1/models', array(
                    'Authorization' => 'Bearer ' . $key,
                ));
            case 'groq':
                return $this->http_test('https://api.groq.com/openai/v1/models', array(
                    'Authorization' => 'Bearer ' . $key,
                ));
            case 'gemini':
                return $this->http_test(
                    'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($key)
                );
            case 'anthropic':
                // Anthropic has no cheap GET ping; do a 1-token message.
                $result = $this->chat_anthropic(
                    array(array('role' => 'user', 'content' => 'ping')),
                    16,
                    null,
                    'anthropic'
                );
                return array(
                    'success' => $result['success'],
                    'message' => $result['success']
                        ? __('Connection successful', 'pdf-chat-support')
                        : $result['message'],
                );
        }

        return array(
            'success' => false,
            'message' => __('Unknown provider', 'pdf-chat-support'),
        );
    }

    /**
     * Simple GET-based connectivity check.
     */
    private function http_test($url, $headers = array()) {
        $headers['Content-Type'] = 'application/json';
        $response = wp_remote_get($url, array('headers' => $headers, 'timeout' => 30));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $msg  = $data['error']['message'] ?? ($data['error']['status'] ?? __('Connection failed', 'pdf-chat-support'));
            return array('success' => false, 'message' => $msg);
        }

        return array('success' => true, 'message' => __('Connection successful', 'pdf-chat-support'));
    }

    /* ---------------------------------------------------------------------
     * Embeddings
     * ------------------------------------------------------------------- */

    /**
     * Generate an embedding for a single piece of text.
     */
    public function generate_embedding($text) {
        $text = $this->prepare_text_for_embedding($text);
        if (empty($text)) {
            return array('success' => false, 'message' => __('Empty text provided', 'pdf-chat-support'));
        }

        $result = $this->generate_embeddings_batch(array($text));
        if (!$result['success']) {
            return $result;
        }

        return array(
            'success'   => true,
            'embedding' => $result['embeddings'][0],
            'usage'     => $result['usage'] ?? array(),
        );
    }

    /**
     * Generate embeddings for multiple texts.
     */
    public function generate_embeddings_batch($texts) {
        $key = $this->key_for($this->embedding_provider);
        if (empty($key)) {
            return array(
                'success' => false,
                'message' => sprintf(__('%s API key not configured', 'pdf-chat-support'), ucfirst($this->embedding_provider)),
            );
        }

        $prepared = array();
        foreach ((array) $texts as $t) {
            $t = $this->prepare_text_for_embedding($t);
            if (!empty($t)) {
                $prepared[] = $t;
            }
        }
        if (empty($prepared)) {
            return array('success' => false, 'message' => __('No valid texts provided', 'pdf-chat-support'));
        }

        if ($this->embedding_provider === 'gemini') {
            return $this->embed_gemini($prepared, $key);
        }
        return $this->embed_openai($prepared, $key);
    }

    /**
     * OpenAI embeddings (also used as the default 1536-dim provider).
     */
    private function embed_openai($texts, $key) {
        $response = wp_remote_post('https://api.openai.com/v1/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                'model' => $this->embedding_model(),
                'input' => $texts,
            )),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || !isset($data['data'])) {
            return array(
                'success' => false,
                'message' => $data['error']['message'] ?? __('Failed to generate embeddings', 'pdf-chat-support'),
            );
        }

        $embeddings = array();
        foreach ($data['data'] as $item) {
            $embeddings[] = $item['embedding'];
        }

        return array('success' => true, 'embeddings' => $embeddings, 'usage' => $data['usage'] ?? array());
    }

    /**
     * Google Gemini embeddings (text-embedding-004, 768 dims).
     */
    private function embed_gemini($texts, $key) {
        $model    = $this->embedding_model();
        $requests = array();
        foreach ($texts as $t) {
            $requests[] = array(
                'model'   => 'models/' . $model,
                'content' => array('parts' => array(array('text' => $t))),
            );
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
             . ':batchEmbedContents?key=' . rawurlencode($key);

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('requests' => $requests)),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || !isset($data['embeddings'])) {
            return array(
                'success' => false,
                'message' => $data['error']['message'] ?? __('Failed to generate embeddings', 'pdf-chat-support'),
            );
        }

        $embeddings = array();
        foreach ($data['embeddings'] as $item) {
            $embeddings[] = $item['values'];
        }

        return array('success' => true, 'embeddings' => $embeddings, 'usage' => array());
    }

    /* ---------------------------------------------------------------------
     * Chat completions
     * ------------------------------------------------------------------- */

    /**
     * Generate a chat completion via the configured provider.
     *
     * @param array $messages    OpenAI-style [{role, content}] message list.
     * @param int   $max_tokens
     * @param float $temperature
     */
    public function generate_chat_completion($messages, $max_tokens = 500, $temperature = 0.7) {
        $key = $this->key_for($this->chat_provider);
        if (empty($key)) {
            return array(
                'success' => false,
                'message' => sprintf(__('%s API key not configured', 'pdf-chat-support'), ucfirst($this->chat_provider)),
            );
        }

        switch ($this->chat_provider) {
            case 'groq':
                return $this->chat_openai_compatible(
                    'https://api.groq.com/openai/v1/chat/completions',
                    $key, $messages, $max_tokens, $temperature
                );
            case 'anthropic':
                return $this->chat_anthropic($messages, $max_tokens, $temperature);
            case 'gemini':
                return $this->chat_gemini($messages, $max_tokens, $temperature, $key);
            case 'openai':
            default:
                return $this->chat_openai_compatible(
                    'https://api.openai.com/v1/chat/completions',
                    $key, $messages, $max_tokens, $temperature
                );
        }
    }

    /**
     * OpenAI / Groq share the same Chat Completions wire format.
     */
    private function chat_openai_compatible($url, $key, $messages, $max_tokens, $temperature) {
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                'model'       => $this->chat_model(),
                'messages'    => array_values($messages),
                'max_tokens'  => intval($max_tokens),
                'temperature' => floatval($temperature),
                'stream'      => false,
            )),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || !isset($data['choices'][0]['message']['content'])) {
            return array(
                'success' => false,
                'message' => $data['error']['message'] ?? __('Failed to generate response', 'pdf-chat-support'),
            );
        }

        return array(
            'success'       => true,
            'content'       => $data['choices'][0]['message']['content'],
            'usage'         => $data['usage'] ?? array(),
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'unknown',
        );
    }

    /**
     * Anthropic (Claude) Messages API.
     *
     * Opus 4.x rejects sampling params (temperature/top_p), so $temperature is
     * intentionally ignored here; behaviour is steered through the prompt.
     */
    private function chat_anthropic($messages, $max_tokens, $temperature = null, $provider = 'anthropic') {
        $key = $this->key_for($provider);
        if (empty($key)) {
            return array('success' => false, 'message' => __('Anthropic API key not configured', 'pdf-chat-support'));
        }

        list($system, $chat) = $this->split_system_messages($messages);

        $body = array(
            'model'      => $this->chat_model(),
            'max_tokens' => intval($max_tokens),
            'messages'   => $chat,
        );
        if (!empty($system)) {
            $body['system'] = $system;
        }

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ),
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return array(
                'success' => false,
                'message' => $data['error']['message'] ?? __('Failed to generate response', 'pdf-chat-support'),
            );
        }

        // content is an array of blocks; collect text blocks.
        $text = '';
        if (!empty($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'];
                }
            }
        }

        if ($text === '') {
            return array('success' => false, 'message' => __('Invalid completion response', 'pdf-chat-support'));
        }

        return array(
            'success'       => true,
            'content'       => $text,
            'usage'         => $data['usage'] ?? array(),
            'finish_reason' => $data['stop_reason'] ?? 'unknown',
        );
    }

    /**
     * Google Gemini generateContent.
     */
    private function chat_gemini($messages, $max_tokens, $temperature, $key) {
        list($system, $chat) = $this->split_system_messages($messages);

        $contents = array();
        foreach ($chat as $m) {
            $contents[] = array(
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => array(array('text' => $m['content'])),
            );
        }

        $body = array(
            'contents'         => $contents,
            'generationConfig' => array(
                'maxOutputTokens' => intval($max_tokens),
                'temperature'     => floatval($temperature),
            ),
        );
        if (!empty($system)) {
            $body['systemInstruction'] = array('parts' => array(array('text' => $system)));
        }

        $model = $this->chat_model();
        $url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
               . ':generateContent?key=' . rawurlencode($key);

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return array(
                'success' => false,
                'message' => $data['error']['message'] ?? __('Failed to generate response', 'pdf-chat-support'),
            );
        }

        $text = '';
        if (!empty($data['candidates'][0]['content']['parts'])) {
            foreach ($data['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }

        if ($text === '') {
            return array('success' => false, 'message' => __('Invalid completion response', 'pdf-chat-support'));
        }

        return array(
            'success'       => true,
            'content'       => $text,
            'usage'         => $data['usageMetadata'] ?? array(),
            'finish_reason' => $data['candidates'][0]['finishReason'] ?? 'unknown',
        );
    }

    /**
     * Split OpenAI-style messages into a system string + a user/assistant list
     * that is safe for Anthropic and Gemini (must start with user, must
     * alternate roles). Consecutive same-role turns are merged.
     *
     * @return array{0:string,1:array}
     */
    private function split_system_messages($messages) {
        $system = array();
        $chat   = array();

        foreach ((array) $messages as $m) {
            $role    = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';

            if ($role === 'system') {
                $system[] = $content;
                continue;
            }

            $role = ($role === 'assistant') ? 'assistant' : 'user';

            // Drop leading assistant turns — the conversation must open with user.
            if (empty($chat) && $role === 'assistant') {
                continue;
            }

            // Merge consecutive same-role turns.
            $last = count($chat) - 1;
            if ($last >= 0 && $chat[$last]['role'] === $role) {
                $chat[$last]['content'] .= "\n\n" . $content;
            } else {
                $chat[] = array('role' => $role, 'content' => $content);
            }
        }

        return array(implode("\n\n", $system), $chat);
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    /**
     * Clean and length-limit text before embedding.
     */
    private function prepare_text_for_embedding($text) {
        $text = preg_replace('/\s+/', ' ', (string) $text);
        $text = trim($text);
        if (strlen($text) > 8000) {
            $text = substr($text, 0, 8000);
        }
        return $text;
    }

    /**
     * Rough token estimate (~4 chars per token).
     */
    public function estimate_tokens($text) {
        return intval(strlen($text) / 4);
    }
}
