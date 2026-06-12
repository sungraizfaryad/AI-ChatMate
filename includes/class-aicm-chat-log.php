<?php
/**
 * Chat Log — file-based conversation logging.
 *
 * Writes one JSON line per chat exchange to a daily .jsonl file so the site
 * owner can download and analyse how visitors use the assistant.
 *
 * ── Why files live in uploads/, NOT the plugin directory ─────────────────
 * WordPress REPLACES the whole plugin folder on every update, which would
 * destroy the logs; the plugin folder is also publicly URL-addressable.
 * wp-content/uploads/ survives updates, and our subdirectory is protected:
 *  - the directory name contains a random secret stored in an option
 *    (NOT derived from salts, so rotating security keys never orphans logs)
 *  - .htaccess denies direct access on Apache; the random name is the
 *    fallback barrier on nginx — protection files are re-asserted on every
 *    writable access, not just at creation
 *  - index.html prevents directory listing
 *  - downloads go through an admin-only, nonce-checked endpoint
 *
 * ── Privacy ───────────────────────────────────────────────────────────────
 * OFF by default (opt-in in Settings → Privacy). Entries contain the visitor
 * message and the assistant reply. IP addresses are never stored; session ids
 * are stored as short one-way hashes, only to group a conversation.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Chat_Log
 */
class AICM_Chat_Log {

	/** Days of log files kept before rotation deletes them. */
	private const KEEP_DAYS = 30;

	/** Option holding the random directory-name secret. */
	private const DIR_KEY_OPTION = 'aicm_log_dir_key';

	/** Filename pattern for daily logs (also the download whitelist). */
	private const FILE_PATTERN = '/^\d{4}-\d{2}-\d{2}\.jsonl$/';

	/**
	 * Whether file logging is enabled by the admin (off by default).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) AI_ChatMate::get_setting( 'file_logging', false );
	}

	/**
	 * Absolute path to the protected log directory.
	 *
	 * @param bool $create Create the directory (and protection files) if it
	 *                     does not exist. Read-only paths (listing, download,
	 *                     uninstall) pass false so they never write anything.
	 * @return string Path with trailing slash, or '' when unavailable.
	 */
	public static function get_dir( bool $create = true ): string {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		// Random, persisted secret — unguessable from outside and stable
		// across salt rotations and migrations. Only minted when a writable
		// caller actually needs the directory.
		$key = (string) get_option( self::DIR_KEY_OPTION, '' );

		if ( '' === $key ) {
			if ( ! $create ) {
				return '';
			}
			$key = wp_generate_password( 16, false, false );
			update_option( self::DIR_KEY_OPTION, $key, false );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'conciera-logs-' . $key . '/';

		if ( ! is_dir( $dir ) ) {
			if ( ! $create || ! wp_mkdir_p( $dir ) ) {
				return $create ? '' : ( is_dir( $dir ) ? $dir : '' );
			}
		}

		if ( $create ) {
			self::assert_protection( $dir );
		}

		return $dir;
	}

	/**
	 * (Re-)write the protection files if missing.
	 *
	 * Done on every writable access — not just first creation — so a
	 * directory that lost its guards (restore from backup, manual cleanup)
	 * heals itself.
	 *
	 * @param string $dir Log directory with trailing slash.
	 */
	private static function assert_protection( string $dir ): void {
		if ( ! file_exists( $dir . 'index.html' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard file; WP_Filesystem would require credentials in a frontend request context.
			file_put_contents( $dir . 'index.html', '' );
		}

		if ( ! file_exists( $dir . '.htaccess' ) ) {
			// Apache 2.4 ("Require all denied") + 2.2 ("Deny from all").
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard file; WP_Filesystem would require credentials in a frontend request context.
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\nOptions -Indexes\n" );
		}
	}

	/**
	 * Append one chat exchange to today's log file.
	 *
	 * No-op unless the admin enabled file logging. Failures are silent —
	 * logging must never break the chat itself.
	 *
	 * @param string $session_id Session id the exchange BELONGS to — pass the
	 *                           one returned in the response, so the first
	 *                           message of a conversation (whose request id is
	 *                           empty) groups with the rest.
	 * @param string $message    The visitor's message.
	 * @param array  $response   Conversation handler response (reply, sources…).
	 */
	public static function record( string $session_id, string $message, array $response ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$dir = self::get_dir( true );
		if ( '' === $dir ) {
			return;
		}

		$entry = array(
			'time'    => current_time( 'mysql' ),
			// One-way hash: enough to group a conversation, useless to track a person.
			'session' => substr( md5( $session_id . wp_salt( 'nonce' ) ), 0, 10 ),
			'message' => $message,
			'reply'   => (string) ( $response['reply'] ?? '' ),
			'sources' => count( (array) ( $response['sources'] ?? array() ) ),
		);

		$file = $dir . gmdate( 'Y-m-d' ) . '.jsonl';

		$is_new_file = ! file_exists( $file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- atomic append with LOCK_EX is not expressible via WP_Filesystem.
		file_put_contents( $file, wp_json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );

		// Rotate once per day (when a new daily file appears), not per write.
		if ( $is_new_file ) {
			self::rotate( $dir );
		}
	}

	/**
	 * List available log files, newest first. Never creates the directory.
	 *
	 * @return array<int, array{file: string, size: int, modified: int}>
	 */
	public static function list_files(): array {
		$dir = self::get_dir( false );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) glob( $dir . '*.jsonl' ) as $path ) {
			$name = basename( (string) $path );
			if ( ! preg_match( self::FILE_PATTERN, $name ) ) {
				continue;
			}
			$out[] = array(
				'file'     => $name,
				'size'     => (int) filesize( $path ),
				'modified' => (int) filemtime( $path ),
			);
		}

		usort( $out, static fn( array $a, array $b ): int => $b['modified'] <=> $a['modified'] );

		return $out;
	}

	/**
	 * Resolve a requested filename to a safe absolute path for download.
	 *
	 * Only bare daily filenames matching FILE_PATTERN are accepted — no
	 * slashes, no traversal, nothing outside the log directory.
	 *
	 * @param string $name Requested file name (e.g. "2026-06-11.jsonl").
	 * @return string Absolute path, or '' if invalid/missing.
	 */
	public static function resolve_download( string $name ): string {
		$name = basename( $name );

		if ( ! preg_match( self::FILE_PATTERN, $name ) ) {
			return '';
		}

		$dir  = self::get_dir( false );
		$path = $dir . $name;

		return ( '' !== $dir && file_exists( $path ) ) ? $path : '';
	}

	/**
	 * Delete log files older than KEEP_DAYS.
	 *
	 * @param string $dir Log directory with trailing slash.
	 */
	private static function rotate( string $dir ): void {
		$cutoff = time() - ( self::KEEP_DAYS * DAY_IN_SECONDS );

		foreach ( (array) glob( $dir . '*.jsonl' ) as $path ) {
			if ( preg_match( self::FILE_PATTERN, basename( (string) $path ) ) && (int) filemtime( $path ) < $cutoff ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Remove the entire log directory and its option (used by uninstall).
	 *
	 * Runs per site (call inside the multisite loop). No GLOB_BRACE — the
	 * constant is undefined on musl-based PHP builds (Alpine) and would
	 * fatal mid-uninstall.
	 */
	public static function delete_all(): void {
		$dir = self::get_dir( false );

		if ( '' !== $dir && is_dir( $dir ) ) {
			// Visible files (the daily logs + index.html)…
			foreach ( (array) glob( $dir . '*' ) as $path ) {
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
			// …and the dot-file glob('*') does not match.
			if ( file_exists( $dir . '.htaccess' ) ) {
				wp_delete_file( $dir . '.htaccess' );
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort removal of our own empty log dir during uninstall; WP_Filesystem may not be initialisable in that context.
			@rmdir( $dir );
		}

		delete_option( self::DIR_KEY_OPTION );
	}
}
