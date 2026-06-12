<?php
/**
 * Leads — callback-request capture from the chat assistant.
 *
 * When a visitor cannot find what they need, the AI offers a callback and
 * collects contact details conversationally. The model then calls the
 * `capture_lead` function; this class validates the data server-side and
 * emails it to the configured recipient.
 *
 * ── Why the AI never "sends email" itself ────────────────────────────────
 * The model only structures the data. Validation (is_email), sanitisation,
 * rate limits, and the actual wp_mail() all happen here in PHP — so a
 * prompt-injected or hallucinating model cannot send arbitrary mail, flood
 * the admin, or inject headers.
 *
 * ── Abuse rails ───────────────────────────────────────────────────────────
 *  - feature is opt-in (Settings → Chat Widget), default OFF
 *  - one lead per chat session (transient guard)
 *  - hard daily ceiling across all sessions
 *  - recipient comes ONLY from settings; visitor input never reaches headers
 *    unsanitised (Reply-To uses the validated email)
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Leads
 */
class AICM_Leads {

	/** Hard ceiling on lead emails per day (all visitors combined). */
	private const DAILY_CAP = 20;

	/** Transient tracking today's lead count. */
	private const DAILY_TRANSIENT = 'aicm_leads_today';

	/**
	 * Whether lead capture is enabled by the admin (off by default).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) AI_ChatMate::get_setting( 'lead_capture', false );
	}

	/**
	 * Recipient for lead notification emails.
	 *
	 * @return string Valid email address ('' disables sending).
	 */
	public static function recipient(): string {
		$configured = sanitize_email( (string) AI_ChatMate::get_setting( 'lead_email', '' ) );

		if ( '' !== $configured && is_email( $configured ) ) {
			return $configured;
		}

		$admin = (string) get_option( 'admin_email', '' );

		return is_email( $admin ) ? $admin : '';
	}

	/**
	 * Validate a lead from the AI function call and email it to the admin.
	 *
	 * Returns a structured result the model can relay to the visitor —
	 * success, or a machine-readable reason it could not be accepted (the
	 * model then asks the visitor to correct the email, etc.).
	 *
	 * @param array  $args       Function-call arguments: email (required),
	 *                           name, phone, preferred_time, topic (optional).
	 * @param string $session_id Chat session id (for the one-lead-per-session guard).
	 * @return array{success: bool, error?: string, message: string}
	 */
	public static function capture( array $args, string $session_id ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'success' => false,
				'error'   => 'disabled',
				'message' => 'Lead capture is not enabled on this site. Do not offer callbacks.',
			);
		}

		// ── Validate email (the one required field) ───────────────────────
		$email = sanitize_email( (string) ( $args['email'] ?? '' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_email',
				'message' => 'The email address is missing or invalid. Politely ask the visitor for a valid email address.',
			);
		}

		// ── One lead per chat session ─────────────────────────────────────
		$session_key = 'aicm_lead_' . md5( $session_id . $email );

		if ( get_transient( $session_key ) ) {
			return array(
				'success' => false,
				'error'   => 'already_captured',
				'message' => 'A callback request was already recorded for this conversation. Tell the visitor their request is already in and the team will be in touch.',
			);
		}

		// ── Daily ceiling across all visitors ─────────────────────────────
		$today = (int) get_transient( self::DAILY_TRANSIENT );

		if ( $today >= self::DAILY_CAP ) {
			return array(
				'success' => false,
				'error'   => 'daily_cap',
				'message' => 'The callback request limit for today has been reached. Apologise and suggest the visitor uses the site contact page instead.',
			);
		}

		$recipient = self::recipient();

		if ( '' === $recipient ) {
			return array(
				'success' => false,
				'error'   => 'no_recipient',
				'message' => 'No valid notification address is configured. Apologise and suggest the site contact page.',
			);
		}

		// ── Sanitise optional fields (body-only — never headers) ──────────
		$name  = mb_substr( sanitize_text_field( (string) ( $args['name'] ?? '' ) ), 0, 100 );
		$phone = mb_substr( sanitize_text_field( (string) ( $args['phone'] ?? '' ) ), 0, 40 );
		$time  = mb_substr( sanitize_text_field( (string) ( $args['preferred_time'] ?? '' ) ), 0, 200 );
		$topic = mb_substr( sanitize_text_field( (string) ( $args['topic'] ?? '' ) ), 0, 500 );

		$site = get_bloginfo( 'name' );

		/* translators: %s: site name */
		$subject = sprintf( __( 'New callback request from the chat assistant — %s', 'ai-chatmate' ), $site );

		$lines   = array();
		$lines[] = __( 'A visitor asked for a callback via the chat assistant.', 'ai-chatmate' );
		$lines[] = '';
		if ( '' !== $name ) {
			$lines[] = __( 'Name:', 'ai-chatmate' ) . ' ' . $name;
		}
		$lines[] = __( 'Email:', 'ai-chatmate' ) . ' ' . $email;
		if ( '' !== $phone ) {
			$lines[] = __( 'Phone:', 'ai-chatmate' ) . ' ' . $phone;
		}
		if ( '' !== $time ) {
			$lines[] = __( 'Preferred time:', 'ai-chatmate' ) . ' ' . $time;
		}
		if ( '' !== $topic ) {
			$lines[] = __( 'They were looking for:', 'ai-chatmate' ) . ' ' . $topic;
		}
		$lines[] = '';
		$lines[] = __( 'Sent automatically by Conciera. Reply to this email to contact the visitor directly.', 'ai-chatmate' );

		// Reply-To is the VALIDATED visitor email — is_email() passed above,
		// so header injection via line breaks is impossible.
		$headers = array( 'Reply-To: ' . $email );

		$sent = wp_mail( $recipient, $subject, implode( "\n", $lines ), $headers );

		if ( ! $sent ) {
			return array(
				'success' => false,
				'error'   => 'send_failed',
				'message' => 'The notification could not be sent. Apologise and suggest the site contact page.',
			);
		}

		// Record the guards only after a successful send.
		set_transient( $session_key, 1, DAY_IN_SECONDS );
		set_transient( self::DAILY_TRANSIENT, $today + 1, self::seconds_until_midnight() );

		return array(
			'success' => true,
			'message' => 'Callback request recorded and sent to the team. Confirm to the visitor that they will be contacted' . ( '' !== $time ? ' at their preferred time.' : ' soon.' ),
		);
	}

	/**
	 * Seconds until local midnight (daily-cap transient lifetime).
	 *
	 * @return int At least 60 seconds.
	 */
	private static function seconds_until_midnight(): int {
		$now      = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- local-day boundary intended.
		$midnight = strtotime( 'tomorrow midnight', $now );

		return max( 60, (int) $midnight - $now );
	}
}
