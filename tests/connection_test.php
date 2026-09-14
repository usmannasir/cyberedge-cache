<?php
/** Isolated pairing and encrypted-option checks; no WordPress install or network. */
error_reporting( E_ALL );
$root = sys_get_temp_dir() . '/cyberedge-connect-' . bin2hex( random_bytes( 8 ) );
mkdir( $root, 0700, true );
define( 'ABSPATH', $root . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$options = array(); $transients = array(); $redirect = null; $salt = 'site-auth-salt'; $checks = 0;
$is_admin_user = true; $exchange_calls = 0; $exchange_response = null; $last_failure = null;
$nonce_allowed = true;
$transient_save_allowed = true;
$origin_purges = array();
$worker_scheduled = 123; $schedule_save_allowed = true; $history_write_allowed = true;
function do_action( $name, ...$args ) { $GLOBALS['origin_purges'][] = array( $name, $args ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['worker_scheduled']; }
function wp_schedule_event( $time, $schedule, $hook ) {
    if ( ! $GLOBALS['schedule_save_allowed'] ) { return false; }
    $GLOBALS['worker_scheduled'] = $time; return true;
}
function wp_schedule_single_event( ...$args ) { return true; }
function wp_safe_redirect( $url ) { wp_redirect( $url ); }
function check_connection( $condition, $message ) { global $checks; if ( ! $condition ) { throw new RuntimeException( $message ); } $checks++; }
function add_action( ...$args ) {} function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {} function register_deactivation_hook( ...$args ) {}
function is_multisite() { return false; } function get_current_blog_id() { return 1; }
function get_option( $name ) { return $GLOBALS['options'][$name] ?? false; }
function update_option( $name, $value, $autoload = null ) {
    if ( $name === 'cyberedge_connection_history_v1' && ! $GLOBALS['history_write_allowed'] ) { return false; }
    $GLOBALS['options'][$name] = $value; return true;
}
function wp_salt( $scheme ) { return $GLOBALS['salt']; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_user_can( $capability ) { return $GLOBALS['is_admin_user']; }
function check_admin_referer( $action ) {
    check_connection( $action === 'cyberedge_connect_start', 'Every connection POST verifies its own action nonce' );
    if ( ! $GLOBALS['nonce_allowed'] ) { throw new PairingFailure( 'Nonce verification failed' ); }
    return true;
}
function home_url( $path = '' ) { return 'https://www.example.test/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return 'https://www.example.test/wp-admin/' . ltrim( $path, '/' ); }
function get_current_user_id() { return 7; }
function set_transient( $name, $value, $expiry ) {
    if ( ! $GLOBALS['transient_save_allowed'] ) { return false; }
    $GLOBALS['transients'][$name] = $value; return true;
}
function get_transient( $name ) { return $GLOBALS['transients'][$name] ?? false; }
function delete_transient( $name ) { unset( $GLOBALS['transients'][$name] ); return true; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function wp_nonce_field( $action, $name = '_wpnonce', $referer = true, $display = true ) {
    check_connection( $action === 'cyberedge_connect_start' && $referer === false && $display === false, 'Retry uses a fresh POST nonce without exposing the callback URL' );
    return '<input type="hidden" name="_wpnonce" value="fresh-retry-nonce">';
}
class PairingFailure extends RuntimeException {}
function add_query_arg( $args, $value = null, $url = null ) {
    if ( ! is_array( $args ) ) { $args = array( $args => $value ); }
    else { $url = $value; }
    $separator = strpos( $url, '?' ) === false ? '?' : '&';
    return $url . $separator . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
}
class PairingRedirect extends RuntimeException {}
function wp_redirect( $url ) { $GLOBALS['redirect'] = $url; throw new PairingRedirect(); }
function wp_die( $message, $title = '', $args = array() ) { $GLOBALS['last_failure'] = array( $message, $title, $args ); throw new PairingFailure( $message ); }
function wp_unslash( $value ) { return $value; }
class PairingLogin extends RuntimeException {}
function auth_redirect() { throw new PairingLogin(); }
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_post( $url, $args ) {
    $GLOBALS['exchange_calls']++;
    check_connection( $url === CyberEdge_Cache::PLATFORM . '/edge/wordpress/exchange/' && $args['timeout'] === 10 && $args['redirection'] === 0 && $args['sslverify'] === true, 'Retry UI does not weaken the authenticated exchange transport' );
    return $GLOBALS['exchange_response'];
}
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_body( $response ) { return json_encode( $response['body'] ); }
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
$_SERVER['SERVER_ADDR'] = '10.0.0.20';
$cache = $GLOBALS['cyberedge_cache'];
try { $cache->connect_start(); } catch ( PairingRedirect $redirected ) {}
$parts = parse_url( $redirect ); parse_str( $parts['query'], $query );
$pending = $transients['cyberedge_connect_pending_v1_7'];
check_connection( $parts['scheme'] === 'https' && $parts['host'] === 'platform.cyberpersons.com' && $parts['path'] === '/edge/wordpress/connect/', 'Pairing uses the fixed HTTPS platform endpoint' );
check_connection( $query['state'] === $pending['state'] && $query['code_challenge_method'] === 'S256', 'Pairing binds state and PKCE S256' );
$expected = rtrim( strtr( base64_encode( hash( 'sha256', $pending['verifier'], true ) ), '+/', '-_' ), '=' );
check_connection( hash_equals( $expected, $query['code_challenge'] ) && strpos( $redirect, $pending['verifier'] ) === false, 'Verifier stays inside WordPress' );
check_connection( $query['redirect_uri'] === 'https://www.example.test/wp-admin/admin-post.php?action=cyberedge_connect_callback', 'Exact WordPress callback is sent' );
check_connection( ! isset( $query['origin_ip'] ), 'Reserved server addresses are not sent as an origin hint' );
$_SERVER['SERVER_ADDR'] = '8.8.8.8';
try { $cache->connect_start(); } catch ( PairingRedirect $redirected ) {}
$parts = parse_url( $redirect ); parse_str( $parts['query'], $query );
check_connection( $query['origin_ip'] === '8.8.8.8', 'A public WordPress server address is sent as a validated setup hint' );
$pending = $transients['cyberedge_connect_pending_v1_7'];
$_GET = array( 'code' => str_repeat( 'c', 43 ), 'state' => str_repeat( 'd', 43 ), 'iss' => CyberEdge_Cache::PLATFORM );
$login_started = false;
try { $cache->connect_login(); } catch ( PairingLogin $login ) { $login_started = true; }
check_connection( $login_started, 'A logged-out callback resumes through WordPress login' );
$_GET['iss'] = 'https://attacker.example'; $rejected = false;
try { $cache->connect_login(); } catch ( RuntimeException $error ) { $rejected = ! ( $error instanceof PairingLogin ); }
check_connection( $rejected, 'A foreign callback issuer cannot start WordPress login' );
check_connection( strpos( $last_failure[0], 'Back to CyberEdge Cache' ) !== false && strpos( $last_failure[0], 'cyberedge_connect_start' ) !== false, 'Invalid returns have an actionable back link and fresh POST retry' );
check_connection( strpos( $last_failure[0], $_GET['code'] ) === false && strpos( $last_failure[0], $_GET['state'] ) === false && strpos( $last_failure[0], 'attacker.example' ) === false, 'Failure pages do not echo callback credentials or untrusted issuer URLs' );

$valid_return = array( 'code' => str_repeat( 'c', 43 ), 'state' => $pending['state'], 'iss' => CyberEdge_Cache::PLATFORM );
foreach ( array(
    array( 'code' => array( 'bad' ) ), array( 'state' => array( 'bad' ) ), array( 'iss' => array( 'bad' ) ),
    array( 'state' => str_repeat( 'x', 43 ) ), array( 'iss' => 'https://attacker.example' ),
) as $invalid ) {
    $_GET = array_merge( $valid_return, $invalid ); $rejected = false;
    try { $cache->connect_callback(); } catch ( PairingFailure $error ) { $rejected = true; }
    check_connection( $rejected && $exchange_calls === 0, 'Malformed or mismatched proof is rejected before any exchange' );
}
$_GET = $valid_return;
unset( $transients['cyberedge_connect_pending_v1_7'] );
$rejected = false;
try { $cache->connect_callback(); } catch ( PairingFailure $error ) { $rejected = true; }
check_connection( $rejected && $exchange_calls === 0, 'An expired local proof cannot be replayed' );
$transients['cyberedge_connect_pending_v1_7'] = $pending;
$before = serialize( $options );
foreach ( array( new WP_Error(), array( 'status' => 503, 'body' => array() ),
    array( 'status' => 200, 'body' => array( 'domain' => 'different.example', 'dashboard_url' => CyberEdge_Cache::PLATFORM . '/edge/domains/other/' ) ),
    array( 'status' => 200, 'body' => array( 'domain' => 'www.example.test', 'dashboard_url' => CyberEdge_Cache::PLATFORM . '/edge/domains/site-one/', 'site_id' => 'bad/site', 'controller_url' => 'https://controller.example.test', 'purge_secret' => str_repeat( 's', 40 ) ) ),
) as $exchange_response ) {
    $rejected = false;
    try { $cache->connect_callback(); } catch ( PairingFailure $error ) { $rejected = true; }
    check_connection( $rejected && serialize( $options ) === $before, 'Exchange failures retain the prior protected connection' );
    check_connection( strpos( $last_failure[0], 'Retry connection' ) !== false && strpos( $last_failure[0], $pending['verifier'] ) === false, 'Exchange failures offer a new attempt without exposing the PKCE verifier' );
}
$is_admin_user = false; $calls_before = $exchange_calls; $rejected = false;
try { $cache->connect_callback(); } catch ( PairingFailure $error ) { $rejected = true; }
check_connection( $rejected && $exchange_calls === $calls_before && $last_failure[2]['response'] === 403 && strpos( $last_failure[0], '<form' ) === false, 'Non-administrators cannot receive a retry nonce or perform an exchange' );
$is_admin_user = true;
try { $cache->connect_start(); } catch ( PairingRedirect $redirected ) {}
check_connection( $transients['cyberedge_connect_pending_v1_7']['state'] !== $pending['state'] && $transients['cyberedge_connect_pending_v1_7']['verifier'] !== $pending['verifier'], 'A retry creates new state and PKCE instead of replaying a stale code' );
$fresh_pending = $transients['cyberedge_connect_pending_v1_7'];
$nonce_allowed = false; $rejected = false;
try { $cache->connect_start(); } catch ( PairingFailure $error ) { $rejected = true; }
check_connection( $rejected && $transients['cyberedge_connect_pending_v1_7'] === $fresh_pending, 'A failed retry nonce cannot replace the pending security proof' );
$nonce_allowed = true;
$_SERVER['REQUEST_METHOD'] = 'GET'; $rejected = false;
try { $cache->connect_start(); } catch ( PairingFailure $error ) { $rejected = true; }
check_connection( $rejected && $transients['cyberedge_connect_pending_v1_7'] === $fresh_pending, 'A GET cannot start or replace a connection attempt' );
$_SERVER['REQUEST_METHOD'] = 'POST'; $transient_save_allowed = false; $rejected = false;
try { $cache->connect_start(); } catch ( PairingFailure $error ) { $rejected = true; }
check_connection( $rejected && $transients['cyberedge_connect_pending_v1_7'] === $fresh_pending && serialize( $options ) === $before && strpos( $last_failure[0], 'platform handoff has not started' ) !== false, 'A failed proof write stays local and retains the prior protected connection' );
$transient_save_allowed = true;
check_connection( $origin_purges === array(), 'Rejected or incomplete pairing never purges an origin cache' );
class PairingOutbox {
    public $installed = false;
    public $events = array();
    public $fail_install = false;
    public $fail_enqueue = false;
    public $fail_status = false;
    public function install() {
        if ( $this->fail_install ) { throw new RuntimeException( 'injected outbox schema failure' ); }
        $this->installed = true;
    }
    public function status() {
        if ( $this->fail_status ) { throw new RuntimeException( 'injected outbox read failure' ); }
        return array( 'pending' => count( $this->events ), 'oldest' => $this->events ? time() : null );
    }
    public function enqueue( $config, $reason, $time ) {
        check_connection( CyberEdge_Config::load()->site_id === 'paired-site', 'Connection is protected before a purge can be queued' );
        if ( $this->fail_enqueue ) { throw new RuntimeException( 'injected outbox insert failure' ); }
        $this->events[] = $reason;
        return 'queued';
    }
}
$pairing_outbox = new PairingOutbox();
$success_cache = new CyberEdge_Cache( $pairing_outbox );
$_GET = array( 'code' => str_repeat( 'c', 43 ), 'state' => $fresh_pending['state'], 'iss' => CyberEdge_Cache::PLATFORM );
$exchange_response = array( 'status' => 200, 'body' => array(
    'domain' => 'www.example.test', 'dashboard_url' => CyberEdge_Cache::PLATFORM . '/edge/domains/paired-site/',
    'site_id' => 'paired-site', 'controller_url' => 'https://controller.example.test', 'purge_secret' => str_repeat( 's', 40 ),
) );
unset( $options[CyberEdge_Config::HISTORY_OPTION] );
$history_write_allowed = false;
$protected_before_marker_failure = $options[CyberEdge_Config::OPTION];
$calls_before = $exchange_calls; $rejected = false;
try { $success_cache->connect_callback(); } catch ( PairingFailure $error ) { $rejected = true; }
check_connection( $rejected && $exchange_calls === $calls_before + 1 && $options[CyberEdge_Config::OPTION] === $protected_before_marker_failure, 'History persistence failure cannot replace protected credentials or retry an exchange' );
check_connection( get_option( 'cyberedge_purge_enqueue_failed' ) === 1 && isset( $transients['cyberedge_connect_pending_v1_7'] ) && $origin_purges === array(), 'History failure retains the alarm and starts no origin purge' );
$history_write_allowed = true;
unset( $options['cyberedge_purge_enqueue_failed'] );
// A separate simulated approval has a fresh proof and code after local failure.
$fresh_pending['state'] = str_repeat( 'r', 43 );
$transients['cyberedge_connect_pending_v1_7'] = $fresh_pending;
$_GET['state'] = $fresh_pending['state']; $_GET['code'] = str_repeat( 'r', 43 );
try { $success_cache->connect_callback(); } catch ( PairingRedirect $redirected ) {}
check_connection( $origin_purges === array( array( 'litespeed_purge', array( '' ) ) ), 'Successful pairing invalidates only the site-prefixed public root tag, not opcode or unrelated caches' );
check_connection( $pairing_outbox->installed && $pairing_outbox->events === array( 'connected' ) && ! isset( $transients['cyberedge_connect_pending_v1_7'] ), 'Pairing retains normal durable edge purge and consumes its proof' );
check_connection( $redirect === 'https://www.example.test/wp-admin/tools.php?page=cyberedge-cache&cyberedge_connected=yes', 'Successful pairing returns to the real plugin dashboard' );
foreach ( array( 'insert', 'schema', 'queue_read', 'schedule', 'retained_alarm' ) as $index => $case ) {
    $worker_scheduled = 123; $schedule_save_allowed = true;
    unset( $options['cyberedge_purge_enqueue_failed'] );
    $local_outbox = new PairingOutbox();
    if ( $case === 'insert' ) { $local_outbox->fail_enqueue = true; }
    if ( $case === 'schema' ) { $local_outbox->fail_install = true; }
    if ( $case === 'queue_read' ) { $local_outbox->fail_status = true; }
    if ( $case === 'schedule' ) { $worker_scheduled = false; $schedule_save_allowed = false; }
    if ( $case === 'retained_alarm' ) { $options['cyberedge_purge_enqueue_failed'] = 1; }
    $local_cache = new CyberEdge_Cache( $local_outbox );
    $fresh_pending['state'] = str_repeat( chr( 97 + $index ), 43 );
    $transients['cyberedge_connect_pending_v1_7'] = $fresh_pending;
    $_GET = array( 'code' => str_repeat( chr( 107 + $index ), 43 ), 'state' => $fresh_pending['state'], 'iss' => CyberEdge_Cache::PLATFORM );
    $calls_before = $exchange_calls;
    try { $local_cache->connect_callback(); } catch ( PairingRedirect $redirected ) {}
    check_connection( $redirect === 'https://www.example.test/wp-admin/tools.php?page=cyberedge-cache&cyberedge_connected=partial', 'Local setup failure returns partial completion: ' . $case );
    check_connection( CyberEdge_Config::load()->site_id === 'paired-site' && ! isset( $transients['cyberedge_connect_pending_v1_7'] ) && $exchange_calls === $calls_before + 1, 'Partial setup retains credentials, consumes proof, and never replays authorization: ' . $case );
    check_connection( $local_cache->site_health()['status'] === 'critical', 'Partial setup still reports the local failure: ' . $case );
    if ( $case === 'insert' || $case === 'schema' || $case === 'retained_alarm' ) {
        check_connection( get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Real persistent alarm is retained: ' . $case );
    }
}
$worker_scheduled = 123; $schedule_save_allowed = true;
$transients['cyberedge_connect_pending_v1_7'] = $fresh_pending;
define( 'CYBEREDGE_SITE_ID', 'server-managed' );
$_SERVER['REQUEST_METHOD'] = 'POST'; $rejected = false;
try { $cache->connect_start(); } catch ( PairingFailure $error ) { $rejected = true; }
check_connection( $rejected && strpos( $last_failure[0], '<form' ) === false && $transients['cyberedge_connect_pending_v1_7'] === $fresh_pending, 'Managed server configuration has no browser retry form and cannot be replaced' );
echo json_encode( array( 'passed' => $checks, 'network_requests' => 0 ) ) . "\n";
@rmdir( $root );
