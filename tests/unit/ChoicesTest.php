<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once AICM_PLUGIN_DIR . 'includes/class-aicm-conversation-handler.php';

/**
 * Tests for the two public sanitisers on AICM_Conversation_Handler:
 *   - sanitize_choices( array ): array
 *   - sanitize_choice_question( string ): string
 *
 * Brain Monkey stubs wp_strip_all_tags so these run without WordPress loaded.
 */
final class ChoicesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Replicate WP's wp_strip_all_tags: strip_tags then trim.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $v ) => trim( strip_tags( (string) $v ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── sanitize_choices ─────────────────────────────────────────────────────

	/**
	 * Test 1 — Happy path: clean strings pass through unchanged.
	 */
	public function test_clean_strings_pass_through_unchanged(): void {
		$input    = array( 'Lisbon', 'Porto', 'Algarve' );
		$result   = AICM_Conversation_Handler::sanitize_choices( $input );

		$this->assertSame( array( 'Lisbon', 'Porto', 'Algarve' ), $result );
	}

	/**
	 * Test 2 — Array is capped at 6 entries regardless of how many are passed.
	 */
	public function test_caps_at_six_entries(): void {
		$input = array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J' );

		$result = AICM_Conversation_Handler::sanitize_choices( $input );

		$this->assertCount( 6, $result );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E', 'F' ), $result );
	}

	/**
	 * Test 3 — Labels are truncated to 40 characters, multibyte-safe.
	 *
	 * The long label below is 50 ASCII chars followed by a 2-byte Portuguese
	 * character (ã) to verify that mb_substr is used rather than substr.
	 */
	public function test_labels_truncated_to_40_chars_multibyte_safe(): void {
		// 40 ASCII chars + extra content that must be cut off.
		$exactly_40   = '1234567890123456789012345678901234567890'; // 40 chars
		$too_long     = $exactly_40 . 'XYZ';                        // 43 chars → cut to 40
		$mb_long      = str_repeat( 'ã', 41 );                      // 41 × 2-byte char → cut to 40

		$result = AICM_Conversation_Handler::sanitize_choices( array( $too_long, $mb_long ) );

		$this->assertSame( $exactly_40, $result[0], 'ASCII label not truncated to 40 chars.' );
		$this->assertSame( str_repeat( 'ã', 40 ), $result[1], 'Multibyte label not truncated to 40 chars.' );
	}

	/**
	 * Test 4 — Duplicate labels are de-duplicated (only the first occurrence kept).
	 */
	public function test_deduplication_keeps_first_occurrence(): void {
		$input  = array( 'Lisbon', 'Porto', 'Lisbon', 'Algarve', 'Porto' );
		$result = AICM_Conversation_Handler::sanitize_choices( $input );

		$this->assertSame( array( 'Lisbon', 'Porto', 'Algarve' ), $result );
	}

	/**
	 * Test 5 — Non-string, non-numeric values are dropped; numerics are kept as strings.
	 *
	 * array()  — not string, not numeric → dropped
	 * null     — not string, not numeric → dropped
	 * true     — not string, not numeric (is_numeric(true) === false in PHP 8) → dropped
	 * 'valid'  — string → kept
	 * 42       — numeric → kept as '42'
	 */
	public function test_non_string_junk_dropped_numerics_kept_as_strings(): void {
		$input  = array( array(), null, true, 'valid', 42 );
		$result = AICM_Conversation_Handler::sanitize_choices( $input );

		$this->assertSame( array( 'valid', '42' ), $result );
	}

	/**
	 * Test 6 — HTML tags are stripped from labels.
	 */
	public function test_html_stripped_from_labels(): void {
		$input = array( '<b>Lisbon</b>', '<script>x</script>' );

		$result = AICM_Conversation_Handler::sanitize_choices( $input );

		$this->assertSame( 'Lisbon', $result[0] );
		// strip_tags('<script>x</script>') → 'x' (content inside script is preserved by strip_tags).
		$this->assertSame( 'x', $result[1] );
	}

	/**
	 * Test 7 — Empty and whitespace-only entries are dropped.
	 */
	public function test_empty_and_whitespace_only_entries_dropped(): void {
		$input  = array( '', '   ', "\t", 'Valid', '  ', 'Also valid' );
		$result = AICM_Conversation_Handler::sanitize_choices( $input );

		$this->assertSame( array( 'Valid', 'Also valid' ), $result );
	}

	// ── sanitize_choice_question ─────────────────────────────────────────────

	/**
	 * Test 8 — Question is trimmed, tags are stripped, and truncated to 300 chars.
	 */
	public function test_question_trimmed_tags_stripped_and_truncated_to_300_chars(): void {
		// Part A: basic trim + tag strip.
		$with_padding_and_tags = '  <em>What type of property?</em>  ';
		$result_basic          = AICM_Conversation_Handler::sanitize_choice_question( $with_padding_and_tags );
		$this->assertSame( 'What type of property?', $result_basic );

		// Part B: truncation to 300 chars.
		// Build a 350-char plain string; after stripping/trimming it stays 350 chars.
		// sanitize_choice_question should return exactly the first 300 chars.
		$long_question = str_repeat( 'a', 350 );
		$result_long   = AICM_Conversation_Handler::sanitize_choice_question( $long_question );
		$this->assertSame( 300, mb_strlen( $result_long ), 'Question must be capped at 300 chars.' );
		$this->assertSame( str_repeat( 'a', 300 ), $result_long );

		// Part C: leading/trailing whitespace trimmed BEFORE the 300-char limit,
		// so a 300-char string with surrounding spaces still yields 300 clean chars.
		$padded_300 = '   ' . str_repeat( 'b', 300 ) . '   ';
		$result_pad = AICM_Conversation_Handler::sanitize_choice_question( $padded_300 );
		$this->assertSame( str_repeat( 'b', 300 ), $result_pad );
	}

	// ── extract_text_choices ─────────────────────────────────────────────────

	/**
	 * The exact shape from the live FLP transcript: intro question + colon +
	 * bullet options MUST convert to chips.
	 */
	public function test_text_bullet_choice_list_converts_to_chips(): void {
		$reply = "Great! Finally, what time would you prefer to be contacted? You can choose from the following options:\n\n- Morning\n- Afternoon\n- Evening\n- Any time";

		$r = AICM_Conversation_Handler::extract_text_choices( $reply );

		$this->assertSame( array( 'Morning', 'Afternoon', 'Evening', 'Any time' ), $r['options'] );
		$this->assertStringNotContainsString( 'Morning', $r['reply'] );
		$this->assertStringContainsString( 'what time would you prefer', $r['reply'] );
	}

	/**
	 * Bold markdown is stripped from converted labels.
	 */
	public function test_bold_bullet_labels_are_unwrapped(): void {
		$reply = "How would you like to continue?\n- **Adjust my search**\n- **Request a callback**";

		$r = AICM_Conversation_Handler::extract_text_choices( $reply );

		$this->assertSame( array( 'Adjust my search', 'Request a callback' ), $r['options'] );
	}

	/**
	 * Informational bullets (long, fact-like) must NOT become chips.
	 */
	public function test_informational_bullets_are_left_as_text(): void {
		$reply = "Here is what I found about the villa:\n- It has a heated swimming pool facing the famous golf course\n- The plot measures over three thousand square metres in total\nWould you like more details?";

		$r = AICM_Conversation_Handler::extract_text_choices( $reply );

		$this->assertSame( array(), $r['options'] );
		$this->assertSame( $reply, $r['reply'] );
	}

	/**
	 * No question in the surrounding text → not a choice prompt → unchanged.
	 */
	public function test_bullets_without_a_question_are_left_as_text(): void {
		$reply = "Key facts.\n- Five bedrooms\n- Sea views";

		$r = AICM_Conversation_Handler::extract_text_choices( $reply );

		$this->assertSame( array(), $r['options'] );
	}

	/**
	 * A single bullet is never a choice list.
	 */
	public function test_single_bullet_is_left_as_text(): void {
		$reply = "Shall we continue?\n- Yes";

		$r = AICM_Conversation_Handler::extract_text_choices( $reply );

		$this->assertSame( array(), $r['options'] );
	}
}
