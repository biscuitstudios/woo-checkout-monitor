<?php
/**
 * Tests for the blocks-checkout warning.
 *
 * The thing under test is a warning that fires only when the plugin is blind,
 * so both halves matter equally. A warning that never fires is useless; a
 * warning that fires on a working classic store is worse, because it tells
 * someone their monitoring is broken when it is not.
 *
 * Plain PHP with hand-rolled stubs, so there is nothing to install. Run:
 *
 *   php tests/test-notice.php
 *
 * @package WooCheckoutMonitor
 */

define( 'ABSPATH', '/fake/' );

// --- WP / Woo stubs ---------------------------------------------------------

function __( $t, $d = '' )         { return $t; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function add_action( ...$a )       {}

$GLOBALS['cap']     = true;
$GLOBALS['screen']  = 'plugins';
$GLOBALS['page_id'] = 42;
$GLOBALS['post']    = (object) [ 'ID' => 42, 'post_content' => '[woocommerce_checkout]' ];

function current_user_can( $c )    { return (bool) $GLOBALS['cap']; }
function get_current_screen()      { return null === $GLOBALS['screen'] ? null : (object) [ 'id' => $GLOBALS['screen'] ]; }
function wc_get_page_id( $p )      { return $GLOBALS['page_id']; }
function get_post( $id )           { return $GLOBALS['post']; }

/** Core's real has_block() semantics, close enough for a content string. */
function has_block( $block, $post = null ) {
	$content = is_object( $post ) ? $post->post_content : (string) $post;
	return false !== strpos( $content, '<!-- wp:' . $block );
}

require_once __DIR__ . '/../includes/class-woocm-notice.php';

// --- harness ----------------------------------------------------------------

$fails = 0;
$ran   = 0;

function it( string $label, bool $ok ): void {
	global $fails, $ran;
	$ran++;
	if ( ! $ok ) {
		$fails++;
	}
	printf( "%-6s %s\n", $ok ? '  ok' : 'FAIL', $label );
}

/** Capture whatever the notice prints. */
function render(): string {
	ob_start();
	( new Woocm_Notice() )->maybe_render();
	return (string) ob_get_clean();
}

function classic(): void { $GLOBALS['post'] = (object) [ 'post_content' => '[woocommerce_checkout]' ]; }
function blocks(): void  { $GLOBALS['post'] = (object) [ 'post_content' => '<!-- wp:woocommerce/checkout /-->' ]; }

function reset_state(): void {
	$GLOBALS['cap']     = true;
	$GLOBALS['screen']  = 'plugins';
	$GLOBALS['page_id'] = 42;
	blocks();
}

// --- detection --------------------------------------------------------------

echo "\n== checkout_uses_blocks() ==\n";

blocks();
it( 'the checkout block is detected', true === Woocm_Notice::checkout_uses_blocks() );

classic();
it( 'the classic shortcode is not blocks', false === Woocm_Notice::checkout_uses_blocks() );

$GLOBALS['post'] = (object) [ 'post_content' => '<!-- wp:woocommerce/cart /--><p>hi</p>' ];
it( 'the cart block alone is not the checkout block', false === Woocm_Notice::checkout_uses_blocks() );

$GLOBALS['post'] = (object) [ 'post_content' => 'Talking about woocommerce/checkout in prose' ];
it( 'the block name mentioned in prose does not count', false === Woocm_Notice::checkout_uses_blocks() );

blocks();
$GLOBALS['page_id'] = -1;
it( 'no checkout page gives null, not false', null === Woocm_Notice::checkout_uses_blocks() );
$GLOBALS['page_id'] = 0;
it( 'page id zero gives null too', null === Woocm_Notice::checkout_uses_blocks() );

$GLOBALS['page_id'] = 42;
$GLOBALS['post']    = null;
it( 'a missing post gives null', null === Woocm_Notice::checkout_uses_blocks() );

// --- when the notice fires --------------------------------------------------

echo "\n== the notice fires only when the plugin is actually blind ==\n";

reset_state();
$html = render();
it( 'blocks checkout on the plugins screen: fires', '' !== $html );
it( 'it names the plugin',      false !== strpos( $html, 'Woo Checkout Monitor is not recording' ) );
it( 'it says why',              false !== strpos( $html, 'WooCommerce Checkout block' ) );
it( 'it warns about the silence', false !== strpos( $html, 'empty log looks exactly like a quiet store' ) );
it( 'it is a warning notice',   false !== strpos( $html, 'notice-warning' ) );

reset_state();
$GLOBALS['screen'] = 'woocommerce_page_wc-status';
it( 'fires on the WooCommerce Status screen', '' !== render() );

echo "\n== and stays quiet otherwise ==\n";

reset_state();
classic();
it( 'silent on a classic checkout, which is the working case', '' === render() );

reset_state();
$GLOBALS['page_id'] = -1;
it( 'silent when the answer is unknown, rather than guessing', '' === render() );

reset_state();
$GLOBALS['cap'] = false;
it( 'silent for a user without manage_woocommerce', '' === render() );

reset_state();
$GLOBALS['screen'] = 'dashboard';
it( 'silent on the dashboard', '' === render() );

reset_state();
$GLOBALS['screen'] = 'edit-post';
it( 'silent on an unrelated screen', '' === render() );

reset_state();
$GLOBALS['screen'] = null;
it( 'silent when there is no screen object', '' === render() );

// --- the promise this plugin ships with -------------------------------------

echo "\n== the standing rule about how this plugin is described ==\n";

reset_state();
$html = render();
it( 'the notice never claims the plugin stops anything',
	! preg_match( '/\b(protect|block(s|ing)? attacks|prevent|secure|stops card testing)\b/i', $html ) );

echo "\n" . ( $fails
	? "$fails of $ran checks FAILED\n"
	: "all $ran checks passed\n" );
exit( $fails ? 1 : 0 );
