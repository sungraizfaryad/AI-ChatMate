<?php
/**
 * Billing / spend tracking + kill-switch.
 *
 * Records per-day and per-month API spend and answers "has today's budget been
 * reached?" so the public chat endpoint can shut itself off before running up
 * the site owner's bill. Daily history is pruned to recent days to stay small.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Billing
 */
class AICM_Billing {

	/** wp_options key for the per-day spend map (YYYY-MM-DD => float). */
	private const DAILY_OPTION = 'aicm_daily_usage';

	/** wp_options key for the per-month spend map (YYYY-MM => float). */
	private const MONTHLY_OPTION = 'aicm_monthly_usage';

	/** Days of daily history to keep. */
	private const KEEP_DAYS = 40;

	/**
	 * Is a spend amount at or over a budget? A budget of 0 means unlimited.
	 *
	 * @param float $spent  Amount spent.
	 * @param float $budget Budget ceiling (0 = unlimited).
	 * @return bool
	 */
	public static function over_budget( float $spent, float $budget ): bool {
		return $budget > 0.0 && $spent >= $budget;
	}

	/**
	 * Record an API cost into the daily and monthly spend maps.
	 *
	 * @param float  $cost  Cost in USD (ignored when <= 0).
	 * @param string $day   Day key (YYYY-MM-DD); defaults to today (UTC).
	 * @param string $month Month key (YYYY-MM); defaults to this month (UTC).
	 */
	public static function record( float $cost, string $day = '', string $month = '' ): void {
		if ( $cost <= 0.0 ) {
			return;
		}

		$day   = '' !== $day ? $day : gmdate( 'Y-m-d' );
		$month = '' !== $month ? $month : gmdate( 'Y-m' );

		$daily         = get_option( self::DAILY_OPTION, array() );
		$daily         = is_array( $daily ) ? $daily : array();
		$daily[ $day ] = round( (float) ( $daily[ $day ] ?? 0.0 ) + $cost, 6 );

		// Prune old days so the option never grows without bound.
		if ( count( $daily ) > self::KEEP_DAYS ) {
			ksort( $daily );
			$daily = array_slice( $daily, -self::KEEP_DAYS, null, true );
		}
		update_option( self::DAILY_OPTION, $daily, false );

		$monthly           = get_option( self::MONTHLY_OPTION, array() );
		$monthly           = is_array( $monthly ) ? $monthly : array();
		$monthly[ $month ] = round( (float) ( $monthly[ $month ] ?? 0.0 ) + $cost, 6 );
		update_option( self::MONTHLY_OPTION, $monthly, false );
	}

	/**
	 * Spend recorded for a given day (defaults to today, UTC).
	 *
	 * @param string $day Day key (YYYY-MM-DD).
	 * @return float
	 */
	public static function today_spend( string $day = '' ): float {
		$day   = '' !== $day ? $day : gmdate( 'Y-m-d' );
		$daily = get_option( self::DAILY_OPTION, array() );
		return is_array( $daily ) ? (float) ( $daily[ $day ] ?? 0.0 ) : 0.0;
	}

	/**
	 * Has today's spend reached the configured daily budget?
	 *
	 * @return bool True when the kill-switch should fire.
	 */
	public static function daily_budget_reached(): bool {
		$budget = (float) AI_ChatMate::get_setting( 'daily_budget', 0 );
		return self::over_budget( self::today_spend(), $budget );
	}
}
