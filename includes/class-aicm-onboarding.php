<?php
/**
 * Onboarding state.
 *
 * A single boolean flag recording whether the admin completed the setup wizard.
 * Until completed, the admin menu lands on the wizard (Phase 3b).
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Onboarding
 */
class AICM_Onboarding {

	/** wp_options key. */
	private const OPTION_KEY = 'aicm_onboarded';

	/**
	 * @return bool True once the wizard has been completed.
	 */
	public static function is_complete(): bool {
		return (bool) get_option( self::OPTION_KEY, false );
	}

	/**
	 * Mark onboarding complete.
	 */
	public static function mark_complete(): void {
		update_option( self::OPTION_KEY, true, false );
	}

	/**
	 * Reset onboarding (used by "Re-run setup").
	 */
	public static function reset(): void {
		update_option( self::OPTION_KEY, false, false );
	}
}
