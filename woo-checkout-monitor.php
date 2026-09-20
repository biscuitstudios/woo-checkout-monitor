<?php
/**
 * Plugin Name:       Woo Checkout Monitor
 * Plugin URI:        https://github.com/biscuitstudios/woo-checkout-monitor
 * Description:       Logs captcha token and referer state for every classic WooCommerce checkout submission, so card testing can be told apart from real traffic. Optional failsafe rejection of submissions carrying neither.
 * Version:           1.0.1
 * Requires at least: 6.3
 * Requires PHP:      8.2
 * Requires Plugins:  woocommerce
 * Author:            Biscuit Studios
 * Author URI:        https://biscuitstudios.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-checkout-monitor
 * Update URI:        https://github.com/biscuitstudios/woo-checkout-monitor
 *
 * WHY THIS EXISTS
 * A store was card tested through /?wc-ajax=checkout for two weeks in August
 * 2026, peaking at about 200 attempts a day. The first version of this file was
 * written on August 19, 2026 during that investigation, as a single-site
 * mu-plugin. It was generalized into a plugin on August 29, 2026.
 *
 * THIS IS NOT A FIX. Say so plainly, because it looks like one.
 * That attack carried solved Turnstile tokens, and this code returns early
 * whenever a token is present, so it never touched the traffic that was
 * creating orders. The attack ended on August 20, 2026 when their token supply
 * failed. The 27 requests the rejection blocked that morning were already being
 * rejected by the Turnstile plugin on missing-input-response, which runs first.
 *
 * WHAT IT IS ACTUALLY FOR
 * A per-request record of token and referer state, which captcha plugins only
 * report as aggregate counters. It logs allowed lines as well as blocked ones,
 * and that is the point: the allowed lines are what proved the token-carrying
 * traffic was never being stopped. A fix deployed just before a problem stops
 * is the most tempting false positive available, and only a log of the requests
 * that did not match can separate "we stopped it" from "it stopped".
 *
 * The rejection is a failsafe for the case where the captcha plugin is
 * deactivated or broken. It is off by default. See docs/NOTES.md for why.
 *
 * HOW TO READ IT
 * WooCommerce > Status > Logs, source woo-checkout-monitor.
 *   token=no   submissions arriving without a captcha token
 *   token=yes  the captcha is being solved, and nothing here will stop that
 *
 * The mitigations that do hold sit at the payment gateway, not in WordPress:
 * velocity filtering, and CVV and AVS rejection. See README.md, and docs/NOTES.md
 * where that folder is present.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOOCM_VERSION', '1.0.1' );
define( 'WOOCM_FILE', __FILE__ );
define( 'WOOCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOOCM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Log source, and therefore the filename under WooCommerce > Status > Logs.
 * Overridable so a site already reading an older log can keep its source name.
 */
if ( ! defined( 'WOOCM_LOG_SOURCE' ) ) {
	define( 'WOOCM_LOG_SOURCE', 'woo-checkout-monitor' );
}

/**
 * Failsafe rejection. Off unless the site defines this as true in wp-config.php:
 *
 *   define( 'WOOCM_BLOCK', true );
 *
 * Only turn it on where a captcha plugin is active on the checkout. Without one,
 * every legitimate submission is token=no, and the rejection then rests on the
 * referer alone, which some privacy tools and browser settings strip.
 */
if ( ! defined( 'WOOCM_BLOCK' ) ) {
	define( 'WOOCM_BLOCK', false );
}

require_once WOOCM_DIR . 'includes/class-woocm-monitor.php';
require_once WOOCM_DIR . 'includes/class-woocm-notice.php';
require_once WOOCM_DIR . 'includes/class-woocm-updater.php';

/**
 * Updates are served from the repo's GitHub Releases.
 *
 * WordPress only checks wordpress.org for plugins whose Update URI header
 * points there. This one points at GitHub, so core hands it to the
 * update_plugins_github.com filter instead and offers nothing at all unless
 * something answers. That filter defaults to false and core continues on a
 * falsy return, so an unhooked filter is silent: no notice, no error, no log.
 *
 * Deliberately outside the WooCommerce gate below. A store that has
 * deactivated WooCommerce while debugging still needs to be offered updates to
 * this plugin, and gating the updater on class_exists( 'WooCommerce' ) would
 * quietly strand it on whatever version it was on.
 */
( new Woocm_Updater( WOOCM_FILE ) )->init();

/**
 * Compatibility is declared for both, but only the classic checkout is watched.
 * On a blocks checkout this plugin logs nothing at all, and an empty log reads
 * exactly like a quiet store. Check which checkout the site uses before
 * trusting silence. See README.md, "What it does not cover".
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOOCM_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WOOCM_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		( new Woocm_Monitor() )->init();

		// Only in the admin, and only to say when the monitor is blind. See
		// Woocm_Notice for why this is a screen and not just a README line.
		if ( is_admin() ) {
			( new Woocm_Notice() )->init();
		}
	}
);
