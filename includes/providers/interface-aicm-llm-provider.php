<?php
/**
 * LLM Provider Interface
 *
 * Defines the contract that every AI provider class must fulfil.
 * This abstraction means the rest of the plugin (chat handler, embedder,
 * REST controller) never depends on OpenAI-specific code — swapping in
 * Anthropic or Gemini requires only a new provider class.
 *
 * Design notes:
 *  - Methods return structured arrays (not objects) to keep the interface
 *    PHP 7.x-friendly and avoid coupling to any DTO library.
 *  - All methods are expected to throw AICM_Provider_Exception on
 *    unrecoverable errors (invalid API key, quota exceeded, network failure).
 *    Callers should catch this and display a user-friendly message.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface AICM_LLM_Provider
 */
interface AICM_LLM_Provider {

	/**
	 * Send a chat completion request with optional function/tool calling.
	 *
	 * @param array $messages   Conversation history.
	 *                          Each item: [ 'role' => 'user'|'assistant'|'function', 'content' => string ]
	 *                          Function result items also include 'name' => string.
	 * @param array $functions  Function/tool definitions in OpenAI tools format.
	 *                          Pass an empty array when function calling is not needed.
	 * @param array $options    Optional overrides:
	 *                          'model'       => string  (defaults to provider's chat model)
	 *                          'max_tokens'  => int     (defaults to 1024)
	 *                          'temperature' => float   (defaults to 0.7)
	 *
	 * @return array {
	 *   'content'       => string|null   Text reply when the AI responds directly.
	 *   'function_call' => array|null {
	 *       'name'      => string        Name of the function the AI wants to call.
	 *       'arguments' => string        JSON-encoded argument object.
	 *   }
	 *   'usage' => array {
	 *       'input_tokens'  => int
	 *       'output_tokens' => int
	 *   }
	 * }
	 */
	public function chat_completion( array $messages, array $functions = array(), array $options = array() ): array;

	/**
	 * Generate an embedding vector for a single text string.
	 *
	 * @param string $text Text to embed. Should be under ~8,000 tokens
	 *                     for text-embedding-3-small.
	 * @return float[]     Dense float array (1536 dimensions for OpenAI small model).
	 */
	public function generate_embedding( string $text ): array;

	/**
	 * Generate embeddings for multiple texts in a single API call.
	 *
	 * More efficient than calling generate_embedding() in a loop — providers
	 * typically accept up to 2048 texts per batch request.
	 *
	 * @param string[] $texts Array of strings to embed.
	 * @return float[][]      Array of float arrays, in the same order as $texts.
	 */
	public function generate_embeddings_batch( array $texts ): array;

	/**
	 * Test whether the stored API key is valid.
	 *
	 * Makes the cheapest possible API call (models list or a single-token
	 * chat request) to verify the key without incurring meaningful cost.
	 *
	 * @return array {
	 *   'success' => bool
	 *   'message' => string  Human-readable result or error description.
	 *   'model'   => string  Confirmed model name, or '' on failure.
	 * }
	 */
	public function test_connection(): array;

	/**
	 * Return the provider's machine-readable identifier.
	 *
	 * @return string 'openai' | 'anthropic' | 'google'
	 */
	public function get_provider_name(): string;

	/**
	 * Return the active chat model identifier.
	 *
	 * @return string e.g. 'gpt-4o-mini'
	 */
	public function get_chat_model(): string;

	/**
	 * Return the active embedding model identifier.
	 *
	 * @return string e.g. 'text-embedding-3-small'
	 */
	public function get_embedding_model(): string;

	/**
	 * Estimate the USD cost for the given token usage.
	 *
	 * Used to track monthly spend. Prices are approximate and should be
	 * updated when provider pricing changes.
	 *
	 * @param int $input_tokens  Number of input (prompt) tokens.
	 * @param int $output_tokens Number of output (completion) tokens.
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( int $input_tokens, int $output_tokens ): float;
}
