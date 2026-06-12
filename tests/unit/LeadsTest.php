<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once AICM_PLUGIN_DIR . 'includes/class-aicm-leads.php';

/**
 * Lead capture: validation, abuse rails, and the email hand-off.
 *
 * The model only structures data — every safety property (email validation,
 * per-session and daily limits, header hygiene) must hold in PHP regardless
 * of what the model sends.
 */
final class LeadsTest extends TestCase {

	/** Transients written/read during a test. */
	private array $transients = array();

	/** Captured wp_mail calls. */
	private array $mails = array();

	/** What wp_mail should return. */
	private bool $mail_ok = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients = array();
		$this->mails      = array();
		$this->mail_ok    = true;

		AI_ChatMate::$test_settings = array(
			'lead_capture' => true,
			'lead_email'   => 'owner@example.com',
		);

		Functions\when( 'sanitize_email' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'is_email' )->alias(
			static fn( $v ) => (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $v ) ? $v : false
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'admin_email' === $k ? 'admin@example.com' : $d );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'current_time' )->alias( static fn( $t ) => 'timestamp' === $t ? 1700000000 : '2026-06-12 10:00:00' );
		Functions\when( 'get_transient' )->alias( fn( $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $k, $v ) {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers ) {
				$this->mails[] = compact( 'to', 'subject', 'body', 'headers' );
				return $this->mail_ok;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_disabled_feature_rejects_capture(): void {
		AI_ChatMate::$test_settings['lead_capture'] = false;

		$r = AICM_Leads::capture( array( 'email' => 'visitor@example.com' ), 'sess1' );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'disabled', $r['error'] );
		$this->assertCount( 0, $this->mails, 'No email may be sent while disabled.' );
	}

	public function test_invalid_email_is_rejected_without_sending(): void {
		$r = AICM_Leads::capture( array( 'email' => 'not-an-email' ), 'sess1' );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'invalid_email', $r['error'] );
		$this->assertCount( 0, $this->mails );
	}

	public function test_valid_lead_sends_email_with_reply_to_and_sets_guards(): void {
		$r = AICM_Leads::capture(
			array(
				'email'          => 'visitor@example.com',
				'name'           => 'Maria',
				'phone'          => '+351 900 000 000',
				'preferred_time' => 'Friday after 5pm',
				'topic'          => '3-bed villa in Algarve under 2M',
			),
			'sess1'
		);

		$this->assertTrue( $r['success'] );
		$this->assertCount( 1, $this->mails );

		$mail = $this->mails[0];
		$this->assertSame( 'owner@example.com', $mail['to'] );
		$this->assertContains( 'Reply-To: visitor@example.com', $mail['headers'] );
		$this->assertStringContainsString( 'Maria', $mail['body'] );
		$this->assertStringContainsString( 'Friday after 5pm', $mail['body'] );
		$this->assertStringContainsString( '3-bed villa in Algarve under 2M', $mail['body'] );

		// Guards recorded: session lock + daily counter.
		$this->assertNotEmpty( $this->transients );
		$this->assertSame( 1, $this->transients['aicm_leads_today'] );
	}

	public function test_second_lead_in_same_session_is_blocked(): void {
		AICM_Leads::capture( array( 'email' => 'visitor@example.com' ), 'sess1' );
		$r = AICM_Leads::capture( array( 'email' => 'visitor@example.com' ), 'sess1' );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'already_captured', $r['error'] );
		$this->assertCount( 1, $this->mails, 'Only the first request may send an email.' );
	}

	public function test_daily_cap_blocks_further_leads(): void {
		$this->transients['aicm_leads_today'] = 20;

		$r = AICM_Leads::capture( array( 'email' => 'visitor@example.com' ), 'sess1' );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'daily_cap', $r['error'] );
		$this->assertCount( 0, $this->mails );
	}

	public function test_failed_send_reports_error_and_sets_no_guards(): void {
		$this->mail_ok = false;

		$r = AICM_Leads::capture( array( 'email' => 'visitor@example.com' ), 'sess1' );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'send_failed', $r['error'] );
		$this->assertArrayNotHasKey( 'aicm_leads_today', $this->transients, 'Failed send must not consume the daily quota.' );
	}

	public function test_falls_back_to_admin_email_when_unconfigured(): void {
		AI_ChatMate::$test_settings['lead_email'] = '';

		AICM_Leads::capture( array( 'email' => 'visitor@example.com' ), 'sess1' );

		$this->assertSame( 'admin@example.com', $this->mails[0]['to'] );
	}
}
