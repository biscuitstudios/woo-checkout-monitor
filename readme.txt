=== Woo Checkout Monitor ===
Contributors: biscuitstudios
Tags: woocommerce, card testing, checkout, logging, fraud
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Logs captcha token and referer state for every classic WooCommerce checkout submission, so card testing can be told apart from real traffic.

== Description ==

This plugin is a monitor, not a fix. It does not stop card testing. It tells you
whether the requests hitting your checkout are carrying a solved captcha token,
which is the question that decides what to do next.

See the README on GitHub for the full description, requirements, and known
limitations: https://github.com/biscuitstudios/woo-checkout-monitor

Built and maintained by Biscuit Studios for our own client sites. Published
as-is, with no support. Forks welcome.

== Installation ==

1. Download the zip from the Releases page on GitHub.
2. Plugins > Add New > Upload Plugin.
3. Activate.
4. Place one real test order and confirm the log line reads token=yes.

== Changelog ==

= 1.0.0 =
First release. Generalized from a single-site monitor written during a card
testing investigation in August 2026.
