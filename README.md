# Woo Checkout Monitor

Records what every classic WooCommerce checkout submission carried, so scripted
card testing can be told apart from real customers.

Built and maintained by [Biscuit Studios](https://biscuitstudios.com/) for our
own client sites. Published because it may be useful to others, not because it
is a supported product. See [Support](#support).

## Read this before you install it

**This plugin does not stop card testing.** It came out of an attack that solved
the site's captcha, submitted valid tokens, and created 346 orders. This code
ignores any submission carrying a token, so it never touched that traffic. The
attack ended when the attacker's token supply failed, not because of anything
here.

What it does is give you a per-request record, which captcha plugins only report
as aggregate counters. When a store is being hit, the question is always the same
one: are these requests carrying a solved token or not? The answer decides
whether the captcha is your problem, and nothing else on the site will tell you.

If a store is being card tested right now, the controls that hold sit at the
payment gateway, not in WordPress: velocity filtering, and CVV and AVS
rejection. Install this to understand what is happening. Fix it there.

## What it does

- **One log line per classic checkout submission,** blocked or not. Written to
  WooCommerce > Status > Logs, source `woo-checkout-monitor`.
- **Records captcha token presence and referer presence.** Nothing a customer
  typed. No name, email, address, IP or card detail, so the log carries no
  personal data.
- **Logs the allowed requests too,** which is the whole point. A log that only
  records what it blocked cannot tell you what it missed.
- **Optional failsafe rejection** of submissions carrying neither a token nor a
  referer. Off by default.

A line looks like this:

```
allowed token=yes referer=yes uri=/?wc-ajax=checkout
BLOCKED token=no referer=no uri=/?wc-ajax=checkout
```

`token=no` in bulk means submissions are arriving without a captcha token, and
the failsafe below will stop them. `token=yes` in bulk means the captcha is being
solved, and nothing in this plugin will help.

## What it does not cover

**Blocks checkout.** The plugin hooks
`woocommerce_after_checkout_validation`, which the blocks-based checkout does not
fire. On a blocks checkout it logs nothing at all, and an empty log reads exactly
like a quiet store.

Since 1.0.1 you do not have to remember this. If the checkout page uses the
WooCommerce Checkout block, the plugin says so in a notice on the Plugins screen
and on WooCommerce > Status, because a line in a README is no use to someone
looking at a quiet log and concluding the store is fine.

## Configuration

There is no settings screen. Two constants and two filters are the whole surface.

### Failsafe rejection

Off unless you turn it on:

```php
define( 'WOOCM_BLOCK', true );
```

With it on, a submission carrying **neither** a captcha token **nor** a referer
is rejected with a checkout error.

**Only turn this on where a captcha plugin is active on the checkout.** Without
one, every legitimate submission is `token=no`, and the rejection then rests on
the referer alone. Privacy extensions and some browser and proxy settings strip
referers, and the result is a real customer who cannot check out, with nothing in
any error log naming the cause.

Where a captcha is active, the rejection is redundant with it and adds no false
positive risk. It exists for the case where the captcha plugin is deactivated or
breaks.

### Captcha field names

Cloudflare Turnstile, Google reCAPTCHA and hCaptcha are recognized out of the
box. Anything else:

```php
add_filter( 'woocm_token_fields', function ( $fields ) {
	$fields[] = 'my-captcha-response';
	return $fields;
} );
```

**Get this right before you read the log.** An unrecognized field name makes
every real submission log as `token=no`, which is the same reading an attack
produces. Place a real test order and confirm the line says `token=yes`.

### Full control over the rejection

```php
add_filter( 'woocm_block_request', function ( $block, $has_token, $has_referer ) {
	return $block;
}, 10, 3 );
```

### Log source

The log filename follows the source. Override it if a site already has history
under another name:

```php
define( 'WOOCM_LOG_SOURCE', 'my-existing-source' );
```

## Requirements

- WordPress 6.3 or later
- PHP 8.2 or later
- WooCommerce, classic (shortcode) checkout

## Installation

1. Download the zip from the Releases page.
2. Plugins > Add New > Upload Plugin.
3. Activate.
4. Place one real test order and confirm the log line reads `token=yes`.

## Support

None. This is a small studio's internal tool, published as-is. Issues and forks
are welcome, but there is no response commitment. Security reports are the
exception: see [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
