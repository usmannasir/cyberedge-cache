<?php
/** Isolated pairing and encrypted-option checks; no WordPress install or network. */
error_reporting( E_ALL );
$root = sys_get_temp_dir() . '/cyberedge-connect-' . bin2hex( random_bytes( 8 ) );
mkdir( $root, 0700, true );
define( 'ABSPATH', $root . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$options = array(); $transients = array(); $redirect = null; $salt = 'site-auth-salt'; $checks = 0;
function check_connection( $condition, $message ) { global $checks; if ( ! $condition ) { throw new RuntimeException( $message ); } $checks++; }
function add_action( ...$args ) {} function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {} function register_deactivation_hook( ...$args ) {}
function is_multisite() { return false; } function get_current_blog_id() { return 1; }
function get_option( $name ) { return $GLOBALS['options'][$name] ?? false; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][$name] = $value; return true; }
function wp_salt( $scheme ) { return $GLOBALS['salt']; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_user_can( $capability ) { return true; }
function check_admin_referer( $action ) { return true; }
function home_url( $path = '' ) { return 'https://www.example.test/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return 'https://www.example.test/wp-admin/' . ltrim( $path, '/' ); }
function get_current_user_id() { return 7; }
function set_transient( $name, $value, $expiry ) { $GLOBALS['transients'][$name] = $value; return true; }
function add_query_arg( $args, $value = null, $url = null ) {
    if ( ! is_array( $args ) ) { $args = array( $args => $value ); }
    else { $url = $value; }
    $separator = strpos( $url, '?' ) === false ? '?' : '&';
    return $url . $separator . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
}
class PairingRedirect extends RuntimeException {}
function wp_redirect( $url ) { $GLOBALS['redirect'] = $url; throw new PairingRedirect(); }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function wp_unslash( $value ) { return $value; }
class PairingLogin extends RuntimeException {}
function auth_redirect() { throw new PairingLogin(); }
$wpdb = (object) array( 'prefix' => 'wp_' );
require __DIR__ . '/../cyberedge-cache/cyberedge-cache.php';

$secret = str_repeat( 'private-secret-', 4 );
$config = CyberEdge_Config::store( 'site-one', 'https://controller.example.test', $secret );
check_connection( $config->site_id === 'site-one', 'Validated configuration is returned' );
$serialized = serialize( $options[CyberEdge_Config::OPTION] );
check_connection( strpos( $serialized, $secret ) === false && strpos( $serialized, 'controller.example.test' ) === false, 'Connection payload is encrypted at rest' );
$loaded = CyberEdge_Config::load();
check_connection( $loaded->site_id === 'site-one' && $loaded->endpoint() === 'https://controller.example.test/v1/sites/site-one/purges', 'Encrypted connection round trips' );
$salt = 'different-site-salt';
$rejected = false;
try { CyberEdge_Config::load(); } catch ( InvalidArgumentException $error ) { $rejected = true; }
check_connection( $rejected, 'Connection cannot be decrypted with a different WordPress salt' );
$salt = 'site-auth-salt';

$_SERVER['REQUEST_METHOD'] = 'POST';
$cache = $GLOBALS['cyberedge_cache'];
try { $cache->connect_start(); } catch ( PairingRedirect $redirected ) {}
$parts = parse_url( $redirect ); parse_str( $parts['query'], $query );
$pending = $transients['cyberedge_connect_pending_v1_7'];
check_connection( $parts['scheme'] === 'https' && $parts['host'] === 'platform.cyberpersons.com' && $parts['path'] === '/edge/wordpress/connect/', 'Pairing uses the fixed HTTPS platform endpoint' );
check_connection( $query['state'] === $pending['state'] && $query['code_challenge_method'] === 'S256', 'Pairing binds state and PKCE S256' );
$expected = rtrim( strtr( base64_encode( hash( 'sha256', $pending['verifier'], true ) ), '+/', '-_' ), '=' );
check_connection( hash_equals( $expected, $query['code_challenge'] ) && strpos( $redirect, $pending['verifier'] ) === false, 'Verifier stays inside WordPress' );
check_connection( $query['redirect_uri'] === 'https://www.example.test/wp-admin/admin-post.php?action=cyberedge_connect_callback', 'Exact WordPress callback is sent' );
$_GET = array( 'code' => str_repeat( 'c', 43 ), 'state' => str_repeat( 'd', 43 ), 'iss' => CyberEdge_Cache::PLATFORM );
$login_started = false;
try { $cache->connect_login(); } catch ( PairingLogin $login ) { $login_started = true; }
check_connection( $login_started, 'A logged-out callback resumes through WordPress login' );
$_GET['iss'] = 'https://attacker.example'; $rejected = false;
try { $cache->connect_login(); } catch ( RuntimeException $error ) { $rejected = ! ( $error instanceof PairingLogin ); }
check_connection( $rejected, 'A foreign callback issuer cannot start WordPress login' );
echo json_encode( array( 'passed' => $checks, 'network_requests' => 0 ) ) . "\n";
@rmdir( $root );
