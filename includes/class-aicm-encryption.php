<?php
/**
 * API Key Encryption
 *
 * Encrypts and decrypts sensitive values (API keys) using AES-256-CBC.
 *
 * WHY this approach:
 *  - API keys stored in wp_options are visible to any code with DB access
 *    (other plugins, themes, compromised files). Encrypting them means a
 *    database dump alone is not enough to steal the keys.
 *  - The encryption key is derived from WordPress's own security salts
 *    (wp-config.php). An attacker needs BOTH the database AND wp-config.php.
 *
 * LIMITATIONS (documented honestly):
 *  - If the WordPress salts are regenerated (e.g. via a security plugin),
 *    all encrypted keys become unreadable and must be re-entered.
 *  - This is symmetric encryption — the encrypted value is still decryptable
 *    by any PHP code running on the same server. It is not a substitute for
 *    server-level secrets management, but is the best practical approach
 *    for a WordPress plugin.
 *
 * REQUIRES: PHP OpenSSL extension (enabled by default in PHP 8.0+).
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Encryption
 */
class AICM_Encryption {

	/**
	 * Cipher algorithm.
	 *
	 * AES-256-CBC requires a 32-byte key and a 16-byte IV.
	 */
	private const CIPHER = 'AES-256-CBC';

	/**
	 * Encrypt a plaintext value.
	 *
	 * Returns an empty string on failure rather than throwing, so calling
	 * code can treat an empty result as "no key stored".
	 *
	 * @param string $value The plaintext value to encrypt (e.g. an API key).
	 * @return string Base64-encoded ciphertext, or '' on failure.
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( ! self::openssl_available() ) {
			return '';
		}

		[ $key, $iv ] = self::derive_key_and_iv();

		$encrypted = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return '';
		}

		// Base64-encode so the result is safe to store in wp_options (text field).
		return base64_encode( $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storing encrypted binary safely.
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * Returns an empty string when:
	 *  - The input is empty (nothing stored yet).
	 *  - OpenSSL is unavailable.
	 *  - Decryption fails (e.g. salts have changed, data is corrupt).
	 *
	 * @param string $encrypted_value Base64-encoded ciphertext from encrypt().
	 * @return string Plaintext value, or '' on failure.
	 */
	public static function decrypt( string $encrypted_value ): string {
		if ( '' === $encrypted_value ) {
			return '';
		}

		if ( ! self::openssl_available() ) {
			return '';
		}

		[ $key, $iv ] = self::derive_key_and_iv();

		// Decode from Base64 back to raw binary.
		$binary = base64_decode( $encrypted_value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		// base64_decode() with strict=true returns false for invalid input.
		if ( false === $binary ) {
			return '';
		}

		$decrypted = openssl_decrypt( $binary, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Check whether OpenSSL is available.
	 *
	 * Logs a warning (when WP_DEBUG is on) so developers know why encryption
	 * is silently failing rather than being left confused.
	 *
	 * @return bool
	 */
	public static function openssl_available(): bool {
		if ( ! extension_loaded( 'openssl' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'AI ChatMate: OpenSSL extension is not loaded. API key encryption is unavailable.' );
			}
			return false;
		}

		// Also confirm that the cipher we use is actually supported on this build.
		if ( ! in_array( self::CIPHER, openssl_get_cipher_methods(), true ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'AI ChatMate: AES-256-CBC cipher is not available in this OpenSSL build.' );
			}
			return false;
		}

		return true;
	}

	/**
	 * Derive the encryption key and IV from WordPress security salts.
	 *
	 * We use SHA-256 to condense wp_salt() output into the exact byte lengths
	 * required by AES-256-CBC:
	 *  - Key: 32 bytes (256 bits)
	 *  - IV:  16 bytes (128 bits)
	 *
	 * Two different salts are used for key vs IV to ensure they are
	 * cryptographically independent.
	 *
	 * @return array{0: string, 1: string} [ $key_bytes, $iv_bytes ]
	 */
	private static function derive_key_and_iv(): array {
		// Use raw binary output (true) for maximum entropy.
		$key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );

		return array( $key, $iv );
	}

	/**
	 * Check whether a stored option value is encrypted.
	 *
	 * This is a heuristic: valid Base64 strings that are a reasonable length
	 * for AES-256-CBC output. Used in the settings page to decide whether to
	 * show a masked placeholder or an empty field.
	 *
	 * @param string $value The option value to check.
	 * @return bool
	 */
	public static function is_encrypted( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}

		// AES-256-CBC with OPENSSL_RAW_DATA output is always a multiple of
		// 16 bytes. Base64 of 16+ bytes is always 24+ chars.
		if ( strlen( $value ) < 24 ) {
			return false;
		}

		// Confirm it is valid Base64.
		return base64_encode( base64_decode( $value, true ) ) === $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}
