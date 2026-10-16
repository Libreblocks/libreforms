<?php
/**
 * Spam protection without captchas: a hidden "honeypot" field, a signed timer
 * (bots submit instantly) and a limit of submissions per hour per visitor.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

defined( 'ABSPATH' ) || exit;

final class Protection {

	/**
	 * Signed timestamp printed in the form.
	 */
	public static function timer(): string {
		$time = (string) time();

		return $time . '.' . substr( hash_hmac( 'sha256', $time, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * Seconds since the form was printed, or null when the value was tampered with.
	 */
	public static function elapsed( string $timer ): ?int {
		[ $time, $signature ] = array_pad( explode( '.', $timer, 2 ), 2, '' );

		if ( ! ctype_digit( $time ) || ! hash_equals( substr( hash_hmac( 'sha256', $time, wp_salt( 'nonce' ) ), 0, 20 ), $signature ) ) {
			return null;
		}

		return time() - (int) $time;
	}

	/**
	 * Anonymous, salted fingerprint of the visitor's IP (no raw IPs are stored).
	 */
	public static function visitor(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	/**
	 * Counts one submission; false when the visitor is over the hourly limit.
	 */
	public static function within_limit( int $form_id, int $limit ): bool {
		if ( $limit <= 0 ) {
			return true;
		}

		$key   = 'lbforms_rate_' . $form_id . '_' . substr( self::visitor(), 0, 16 );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}
}
