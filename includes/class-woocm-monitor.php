<?php
/**
 * Classic checkout monitor.
 *
 * @package WooCheckoutMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records token and referer state for every classic checkout submission, and
 * optionally rejects the ones carrying neither.
 */
class Woocm_Monitor {

	/**
	 * Request fields that carry a solved captcha token.
	 *
	 * Cloudflare Turnstile, Google reCAPTCHA and hCaptcha respectively. A site
	 * using something else adds its field name through the woocm_token_fields
	 * filter. Getting this wrong is not a small error: an unknown field name
	 * makes every real submission log as token=no, which is the same reading
	 * an attack produces.
	 *
	 * @var string[]
	 */
	private const TOKEN_FIELDS = [
		'cf-turnstile-response',
		'g-recaptcha-response',
		'h-captcha-response',
	];

	/**
	 * Hook in. Priority 5 so the line is written before other validation runs.
	 */
	public function init(): void {
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'inspect' ], 5, 2 );
	}

	/**
	 * Every classic checkout submission is recorded, blocked or not, so it is
	 * possible to tell "the monitor rejected it" from "the monitor never ran".
	 *
	 * @param array    $data   Posted checkout data. Unused, and deliberately so:
	 *                         nothing a customer typed is written to the log.
	 * @param WP_Error $errors Validation errors, added to when blocking.
	 */
	public function inspect( $data, $errors ): void {
		$has_token   = $this->has_token();
		$has_referer = ! empty( $_SERVER['HTTP_REFERER'] );

		/**
		 * Whether to reject this submission.
		 *
		 * Neither a token nor a referer is the signature of a scripted POST
		 * straight at the endpoint. A real browser submitting the checkout form
		 * sends a referer even with no captcha on the page.
		 *
		 * @param bool $block       Default: reject only when both are missing, and
		 *                          only when WOOCM_BLOCK is on.
		 * @param bool $has_token   Whether a captcha token was posted.
		 * @param bool $has_referer Whether the request carried a referer.
		 */
		$blocked = (bool) apply_filters(
			'woocm_block_request',
			( WOOCM_BLOCK && ! $has_token && ! $has_referer ),
			$has_token,
			$has_referer
		);

		if ( $blocked && $errors instanceof WP_Error ) {
			$errors->add(
				'woocm_blocked',
				__( 'Security check failed. Please reload the checkout page and try again.', 'woo-checkout-monitor' )
			);
		}

		$this->log( $blocked, $has_token, $has_referer );
	}

	/**
	 * Is a captcha token present on this request?
	 */
	private function has_token(): bool {
		/**
		 * Request fields that count as a captcha token.
		 *
		 * @param string[] $fields Field names.
		 */
		$fields = (array) apply_filters( 'woocm_token_fields', self::TOKEN_FIELDS );

		foreach ( $fields as $field ) {
			if ( ! empty( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return true;
			}
		}

		return false;
	}

	/**
	 * Write one line. Log lives at WooCommerce > Status > Logs.
	 *
	 * Nothing customer-entered goes in here. Name, email, address and card
	 * detail are all absent by design, so the log carries no personal data and
	 * needs no retention policy beyond WooCommerce's own.
	 */
	private function log( bool $blocked, bool $has_token, bool $has_referer ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->info(
			sprintf(
				'%s token=%s referer=%s uri=%s',
				$blocked ? 'BLOCKED' : 'allowed',
				$has_token ? 'yes' : 'no',
				$has_referer ? 'yes' : 'no',
				isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''
			),
			[ 'source' => WOOCM_LOG_SOURCE ]
		);
	}
}
