=== Woo Checkout Monitor ===
Contributors: biscuitstudios
Tags: woocommerce, card testing, checkout, logging, fraud
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.3
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

= 1.0.3 =
* No functional change. Released to verify the release pipeline end to end
  after actions/checkout moved from v4 to v7 and softprops/action-gh-release
  from v2 to v3. The release job only runs on a tag, so CI passing said
  nothing about whether a release would still build and publish. This plugin
  was chosen because it is installed on none of the hosted sites, so the
  check costs nothing.

= 1.0.2 =
* Security hardening: the updater now pins its download URL to this plugin's
  own GitHub repository. find_zip_asset() used to hand the WordPress upgrader
  whatever browser_download_url the GitHub API returned, and that URL becomes
  code the upgrader installs. Not a reachable bug, because GitHub only ever
  returns repo-hosted asset URLs, but it is the one path where a wrong
  assumption about a response would be arbitrary code. The fallback branch is
  pinned too, because it was a second way in.
* sslverify is now explicit on the GitHub API call. WordPress defaults it to
  true, so nothing changes today, but a filter on a site could flip it and
  nothing here would notice.

= 1.0.1 =
* New: the plugin now says when it is not recording anything. On a store whose
  checkout page uses the WooCommerce Checkout block it writes no log lines at
  all, because it watches the classic shortcode checkout only, and an empty log
  looks exactly like a quiet store. A notice on the Plugins screen and on
  WooCommerce > Status now says so.
* Note: this was already in the README from the first release. That was the
  wrong place for it. Nobody reads a README while looking at a quiet log and
  concluding the store is fine.
* Note: the check reads the checkout page content with core's has_block()
  rather than through WooCommerce's own Blocks utility class, which has moved
  namespace more than once. A fatal here would take down the admin of a store
  this plugin is only meant to be watching.

= 1.0.0 =
First release. Generalized from a single-site monitor written during a card
testing investigation in August 2026.
