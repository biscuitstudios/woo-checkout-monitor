<?php
/**
 * Admin notice for the case where this plugin is silently doing nothing.
 *
 * @package WooCheckoutMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Says so when the store uses the blocks checkout.
 *
 * The monitor hooks woocommerce_after_checkout_validation, which the
 * blocks-based checkout never fires. On such a store this plugin writes no
 * lines at all, and an empty log reads exactly like a quiet store. That is the
 * worst failure available to a monitoring tool: it reports success by reporting
 * nothing, and the reading you take from it is the opposite of the truth.
 *
 * It was documented in README.md and in the bootstrap from the beginning, which
 * is the wrong place for it. Nobody reads either while looking at a quiet log
 * and concluding the store is fine.
 */
class Woocm_Notice {

	/**
	 * Who sees it.
	 *
	 * manage_woocommerce rather than manage_options: on a store this is the
	 * capability that means "responsible for the shop", and the person who
	 * needs to know the monitor is blind is whoever reads the logs.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Where it appears, by screen id.
	 *
	 * Deliberately not every admin page. These are the two places someone
	 * arrives at while forming a view about this plugin: the list where they
	 * see it is active, and the screen holding the log they are about to
	 * misread.
	 *
	 * @var string[]
	 */
	private const SCREENS = [
		'plugins',
		'woocommerce_page_wc-status',
	];

	/**
	 * Hook in.
	 */
	public function init(): void {
		add_action( 'admin_notices', [ $this, 'maybe_render' ] );
	}

	/**
	 * Render the notice when, and only when, the plugin is blind.
	 */
	public function maybe_render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}

		// Strict true. null means the question could not be answered, and a
		// warning fired on a guess would be worse than no warning at all.
		if ( true !== self::checkout_uses_blocks() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
			esc_html__( 'Woo Checkout Monitor is not recording anything on this store.', 'woo-checkout-monitor' ),
			esc_html__( 'Its checkout page uses the WooCommerce Checkout block, and this plugin watches the classic (shortcode) checkout only. It writes no log lines here at all.', 'woo-checkout-monitor' ),
			esc_html__( 'That matters because an empty log looks exactly like a quiet store. Either switch the checkout page back to the shortcode, or do not read this plugin\'s silence as evidence of anything.', 'woo-checkout-monitor' )
		);
	}

	/**
	 * Does the checkout page use the WooCommerce Checkout block?
	 *
	 * Read from the page content with core's has_block() rather than through
	 * WooCommerce's own CartCheckoutUtils. The utility answers the same
	 * question, but it lives in a Blocks namespace that has moved more than
	 * once, and a fatal here would take down the admin of a store this plugin
	 * is only meant to be watching.
	 *
	 * @return bool|null True for blocks, false for the classic shortcode, null
	 *                   when there is no checkout page to inspect. Null is not
	 *                   an error: a store can render checkout from a template
	 *                   with no page behind it, and that is unanswerable here.
	 */
	public static function checkout_uses_blocks(): ?bool {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return null;
		}

		$page_id = (int) wc_get_page_id( 'checkout' );
		if ( $page_id <= 0 ) {
			return null;
		}

		$page = get_post( $page_id );
		if ( ! $page ) {
			return null;
		}

		return has_block( 'woocommerce/checkout', $page );
	}
}
