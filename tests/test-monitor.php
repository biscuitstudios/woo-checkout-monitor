<?php
/**
 * Smoke tests for Woocm_Monitor, the only class in the plugin.
 *
 * Two failures are worth guarding against, and they fail in opposite
 * directions:
 *
 *   token detection  — an unrecognized captcha field makes every real
 *                      submission log token=no, which is the same reading an
 *                      attack produces. A monitor that lies is worse than none.
 *   the block branch — with WOOCM_BLOCK on, a wrong answer rejects a paying
 *                      customer at checkout with nothing in any log naming the
 *                      cause.
 *
 * Plain PHP rather than PHPUnit so this runs with no composer install, same as
 * the other Biscuit plugins. Run:
 *
 *   php tests/test-monitor.php
 *
 * WHY THIS FILE RE-EXECUTES ITSELF
 * WOOCM_BLOCK is a constant, so it can only hold one value per process, and
 * both of its values need covering. Pass 1 runs with it off, then shells out to
 * a second copy of this file with it on and adds the totals up. There is no
 * other way to test both branches without changing the plugin to suit the test.
 */

$block_mode = '1' === getenv( 'WOOCM_TEST_BLOCK' );
$is_child   = $block_mode;

define( 'ABSPATH', '/fake/' );
define( 'WOOCM_BLOCK', $block_mode );
define( 'WOOCM_LOG_SOURCE', 'woo-checkout-monitor' );

// --- WP stubs: only what the code under test actually touches ---------------

$GLOBALS['woocm_filters'] = [];
$GLOBALS['woocm_lines']   = [];

function add_action( ...$a ) {}
function add_filter( $tag, $cb ) { $GLOBALS['woocm_filters'][ $tag ] = $cb; }
function apply_filters( $tag, $value, ...$args ) {
	return isset( $GLOBALS['woocm_filters'][ $tag ] )
		? ( $GLOBALS['woocm_filters'][ $tag ] )( $value, ...$args )
		: $value;
}
function __( $s, $d = '' ) { return $s; }
function esc_url_raw( $u ) { return $u; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }

function wc_get_logger() {
	return new class {
		public function info( $msg, $ctx = [] ) {
			$GLOBALS['woocm_lines'][] = [ 'source' => $ctx['source'] ?? '', 'msg' => $msg ];
		}
	};
}

class WP_Error {
	public array $errors = [];
	public function add( $code, $message ) { $this->errors[ $code ] = $message; }
}

require_once __DIR__ . '/../includes/class-woocm-monitor.php';

// --- harness ---------------------------------------------------------------

$fails = 0;
$ran   = 0;

function it( string $label, bool $ok ): void {
	global $fails, $ran;
	$ran++;
	if ( ! $ok ) { $fails++; }
	printf( "%-6s %s\n", $ok ? '  ok' : 'FAIL', $label );
}

function reset_filters(): void { $GLOBALS['woocm_filters'] = []; }

/**
 * Run one checkout submission through the monitor.
 *
 * The posted data deliberately carries a name and an email, so every check can
 * also assert that nothing a customer typed reaches the log.
 *
 * @return array{line: string, source: string, errors: int}
 */
function submit( array $post, ?string $referer, ?string $uri = '/?wc-ajax=checkout' ): array {
	$_POST = $post;

	if ( null === $referer ) { unset( $_SERVER['HTTP_REFERER'] ); }
	else { $_SERVER['HTTP_REFERER'] = $referer; }

	if ( null === $uri ) { unset( $_SERVER['REQUEST_URI'] ); }
	else { $_SERVER['REQUEST_URI'] = $uri; }

	$GLOBALS['woocm_lines'] = [];

	$errors = new WP_Error();
	( new Woocm_Monitor() )->inspect(
		[ 'billing_first_name' => 'Wilhelmina', 'billing_email' => 'private@example.com' ] + $post,
		$errors
	);

	$last = end( $GLOBALS['woocm_lines'] );

	return [
		'line'   => $last ? $last['msg'] : '',
		'source' => $last ? $last['source'] : '',
		'errors' => count( $errors->errors ),
	];
}

function line( array $post, ?string $referer, ?string $uri = '/?wc-ajax=checkout' ): string {
	return submit( $post, $referer, $uri )['line'];
}

$TURNSTILE = [ 'cf-turnstile-response' => 'solved-token' ];

echo $is_child
	? "== pass 2: WOOCM_BLOCK = true ==\n"
	: "== pass 1: WOOCM_BLOCK = false (the default) ==\n";

// --- always true, whichever pass this is -----------------------------------

echo "\n== a line is written for every submission ==\n";

it( 'a real customer produces one log line',
	'' !== line( $TURNSTILE, 'https://example.com/checkout/' ) );

it( 'a bare scripted POST produces one log line too',
	'' !== line( [], null ) );

it( 'the line goes to the WOOCM_LOG_SOURCE source',
	'woo-checkout-monitor' === submit( $TURNSTILE, 'https://example.com/checkout/' )['source'] );

echo "\n== token detection ==\n";

it( 'Cloudflare Turnstile is recognized',
	str_contains( line( [ 'cf-turnstile-response' => 'x' ], null ), 'token=yes' ) );

it( 'Google reCAPTCHA is recognized',
	str_contains( line( [ 'g-recaptcha-response' => 'x' ], null ), 'token=yes' ) );

it( 'hCaptcha is recognized',
	str_contains( line( [ 'h-captcha-response' => 'x' ], null ), 'token=yes' ) );

it( 'no captcha field at all reads token=no',
	str_contains( line( [], null ), 'token=no' ) );

// An empty field is what a captcha widget that failed to load leaves behind.
// Counting it as a token would hide exactly the traffic this plugin is for.
it( 'an empty captcha field reads token=no, not token=yes',
	str_contains( line( [ 'cf-turnstile-response' => '' ], null ), 'token=no' ) );

it( 'an unrelated posted field is not mistaken for a token',
	str_contains( line( [ 'billing_postcode' => '30309' ], null ), 'token=no' ) );

echo "\n== referer detection ==\n";

it( 'a referer reads referer=yes',
	str_contains( line( [], 'https://example.com/checkout/' ), 'referer=yes' ) );

it( 'no referer reads referer=no',
	str_contains( line( [], null ), 'referer=no' ) );

it( 'an empty referer reads referer=no',
	str_contains( line( [], '' ), 'referer=no' ) );

echo "\n== the woocm_token_fields filter ==\n";

reset_filters();
add_filter( 'woocm_token_fields', function ( $fields ) { $fields[] = 'my-captcha'; return $fields; } );

it( 'a filtered-in field is recognized',
	str_contains( line( [ 'my-captcha' => 'x' ], null ), 'token=yes' ) );

it( 'the built-in fields still work alongside it',
	str_contains( line( $TURNSTILE, null ), 'token=yes' ) );

reset_filters();
add_filter( 'woocm_token_fields', function ( $fields ) { return [ 'only-this' ]; } );

it( 'replacing the list drops the built-in fields',
	str_contains( line( $TURNSTILE, null ), 'token=no' ) );

reset_filters();

echo "\n== nothing a customer typed reaches the log ==\n";

$sensitive = submit(
	$TURNSTILE + [ 'billing_address_1' => '1516 Peachtree St NW' ],
	'https://example.com/checkout/'
);

it( 'no email in the log line', ! str_contains( $sensitive['line'], 'private@example.com' ) );
it( 'no name in the log line', ! str_contains( $sensitive['line'], 'Wilhelmina' ) );
it( 'no address in the log line', ! str_contains( $sensitive['line'], 'Peachtree' ) );
it( 'no captcha token value in the log line', ! str_contains( $sensitive['line'], 'solved-token' ) );

echo "\n== it does not fatal on a malformed request ==\n";

it( 'a missing REQUEST_URI still logs',
	str_contains( line( [], null, null ), 'token=no' ) );

$no_error_object = ( new Woocm_Monitor() );
$_POST           = [];
unset( $_SERVER['HTTP_REFERER'] );
$_SERVER['REQUEST_URI'] = '/?wc-ajax=checkout';
$GLOBALS['woocm_lines'] = [];
$no_error_object->inspect( [], null );
it( 'a null $errors argument does not fatal, and still logs',
	1 === count( $GLOBALS['woocm_lines'] ) );

echo "\n== the woocm_block_request filter overrides everything ==\n";

reset_filters();
add_filter( 'woocm_block_request', function ( $block, $has_token, $has_referer ) { return true; } );

$forced = submit( $TURNSTILE, 'https://example.com/checkout/' );
it( 'the filter can block a request that would otherwise pass',
	str_starts_with( $forced['line'], 'BLOCKED' ) && 1 === $forced['errors'] );

reset_filters();
add_filter( 'woocm_block_request', function ( $block, $has_token, $has_referer ) { return false; } );

$spared = submit( [], null );
it( 'the filter can spare a request that would otherwise be blocked',
	str_starts_with( $spared['line'], 'allowed' ) && 0 === $spared['errors'] );

// The filter receives the facts, not just the decision. Without these a site
// cannot write a policy of its own.
reset_filters();
$seen = [];
add_filter( 'woocm_block_request', function ( $block, $has_token, $has_referer ) use ( &$seen ) {
	$seen = [ $block, $has_token, $has_referer ];
	return $block;
} );
submit( $TURNSTILE, 'https://example.com/checkout/' );
it( 'the filter is passed the token and referer state',
	[ false, true, true ] === $seen );

reset_filters();

// --- the half that depends on which pass this is ---------------------------

if ( ! WOOCM_BLOCK ) {

	echo "\n== with blocking off, nothing is ever rejected ==\n";

	// This is the default, and it is the whole safety argument for it. On a
	// store with no captcha, every real submission is token=no.
	$cases = [
		'a real customer with a captcha'  => [ $TURNSTILE, 'https://example.com/checkout/' ],
		'a real customer, no captcha'     => [ [], 'https://example.com/checkout/' ],
		'a customer with referers stripped' => [ $TURNSTILE, null ],
		'no captcha and no referer'       => [ [], null ],
		'a bare scripted POST'            => [ [], null ],
	];

	foreach ( $cases as $label => $args ) {
		$r = submit( $args[0], $args[1] );
		it( "$label is allowed", str_starts_with( $r['line'], 'allowed' ) && 0 === $r['errors'] );
	}

} else {

	echo "\n== with blocking on, only token-less AND referer-less is rejected ==\n";

	$r = submit( [], null );
	it( 'a bare scripted POST is blocked',
		str_starts_with( $r['line'], 'BLOCKED' ) && 1 === $r['errors'] );

	// The one that matters most. This is the traffic that beat Turnstile in
	// August 2026, and this plugin does not touch it. If this check ever goes
	// green the other way, someone has been told the plugin is a fix.
	$r = submit( $TURNSTILE, null );
	it( 'a solved-token attack is NOT blocked, and that is the known gap',
		str_starts_with( $r['line'], 'allowed' ) && 0 === $r['errors'] );

	$r = submit( $TURNSTILE, 'https://example.com/checkout/' );
	it( 'a real customer with a captcha is not blocked',
		str_starts_with( $r['line'], 'allowed' ) && 0 === $r['errors'] );

	// A store with no captcha on the checkout. The referer is the only thing
	// standing between a real customer and a rejection, which is why blocking
	// is off by default. See docs/NOTES.md.
	$r = submit( [], 'https://example.com/checkout/' );
	it( 'no captcha but a referer present is not blocked',
		str_starts_with( $r['line'], 'allowed' ) && 0 === $r['errors'] );

	$r = submit( [], null );
	it( 'a blocked request carries exactly one error',
		1 === $r['errors'] );

	$errors = new WP_Error();
	$_POST  = [];
	unset( $_SERVER['HTTP_REFERER'] );
	( new Woocm_Monitor() )->inspect( [], $errors );
	it( 'the error is keyed woocm_blocked',
		array_key_exists( 'woocm_blocked', $errors->errors ) );
	it( 'the error message does not tell the caller why it failed',
		! str_contains( strtolower( $errors->errors['woocm_blocked'] ), 'referer' )
		&& ! str_contains( strtolower( $errors->errors['woocm_blocked'] ), 'token' ) );
}

// --- totals ----------------------------------------------------------------

if ( $is_child ) {
	echo "\n##TOTALS ran=$ran fails=$fails\n";
	exit( $fails ? 1 : 0 );
}

echo "\n";

$child_out = [];
$child_ran = 0;
exec(
	'WOOCM_TEST_BLOCK=1 ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1',
	$child_out
);

foreach ( $child_out as $l ) {
	if ( preg_match( '/^##TOTALS ran=(\d+) fails=(\d+)$/', $l, $m ) ) {
		$child_ran = (int) $m[1];
		$fails    += (int) $m[2];
		continue;
	}
	echo "$l\n";
}

if ( ! $child_ran ) {
	echo "FAIL   pass 2 did not run, so the blocking branch is untested\n";
	$fails++;
}

$ran += $child_ran;

echo "\n" . ( $fails ? "$fails of $ran checks FAILED\n" : "all $ran checks passed\n" );
exit( $fails ? 1 : 0 );
