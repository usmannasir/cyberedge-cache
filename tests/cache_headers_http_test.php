<?php
/** Real PHP HTTP headers, isolated WordPress hooks, and loopback-only traffic. */
error_reporting( E_ALL );

if ( PHP_SAPI === 'cli-server' ) {
    define( 'ABSPATH', __DIR__ . '/' );
    define( 'CYBEREDGE_SITE_ID', 'header-test' );
    define( 'CYBEREDGE_CONTROLLER_URL', 'https://controller.example.test' );
    define( 'CYBEREDGE_PURGE_SECRET', str_repeat( 'test-only-', 5 ) );
    $hooks = array();
    function add_action( $hook, $callback, $priority = 10, $argc = 1 ) { $GLOBALS['hooks'][$hook][$priority][] = array( $callback, $argc ); }
    function add_filter( ...$args ) { add_action( ...$args ); }
    function do_action( $hook, ...$args ) {
        $groups = $GLOBALS['hooks'][$hook] ?? array(); ksort( $groups );
        foreach ( $groups as $group ) { foreach ( $group as $cb ) { call_user_func_array( $cb[0], array_slice( $args, 0, $cb[1] ) ); } }
    }
    function apply_filters( $hook, $value ) {
        $groups = $GLOBALS['hooks'][$hook] ?? array(); ksort( $groups );
        foreach ( $groups as $group ) { foreach ( $group as $cb ) { $value = call_user_func( $cb[0], $value ); } }
        return $value;
    }
    function register_activation_hook( ...$args ) {} function register_deactivation_hook( ...$args ) {}
    function is_multisite() { return false; } function is_user_logged_in() { return false; }
    function is_admin() { return false; } function is_preview() { return false; }
    function is_search() { return false; } function is_404() { return false; }
    function is_feed() { return false; } function is_trackback() { return false; }
    function post_password_required() { return false; } function wp_doing_ajax() { return false; }
    $wpdb = (object) array( 'prefix' => 'wp_' );
    require __DIR__ . '/../cyberedge-cache/cyberedge-cache.php';
    $mode = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    $late = array(
        'nocache' => 'X-LiteSpeed-Cache-Control: no-cache',
        'private' => 'X-LiteSpeed-Cache-Control: private,max-age=300',
        'esi' => 'X-LiteSpeed-Cache-Control: public,max-age=300,esi=on',
        'vary' => 'X-LiteSpeed-Vary: cookie=currency',
        'vary-cookie' => 'Vary: Cookie',
        'set-cookie' => 'Set-Cookie: session=private; HttpOnly',
        'application' => 'Cache-Control: private,no-store',
        'json' => 'Content-Type: application/json',
        'native-zero' => 'X-LiteSpeed-Cache-Control: public,max-age=0',
        'standard-zero' => 'Cache-Control: public,max-age=0',
        'shared-zero' => 'Cache-Control: public,max-age=300,s-maxage=0',
        'negative' => 'X-LiteSpeed-Cache-Control: public,max-age=-1',
        'invalid' => 'Cache-Control: public,max-age=invalid',
        'native-short' => 'X-LiteSpeed-Cache-Control: public,max-age=60',
        'standard-short' => 'Cache-Control: public,max-age=60',
        'shared-short' => 'Cache-Control: public,max-age=0,s-maxage=60',
        'long' => 'Cache-Control: public,max-age=0,s-maxage=900',
    );
    $shutdown_mode = strpos( $mode, 'shutdown-' ) === 0;
    if ( $shutdown_mode ) { $mode = substr( $mode, 9 ); }
    $early_mode = strpos( $mode, 'early-' ) === 0;
    if ( $early_mode ) { $mode = substr( $mode, 6 ); }
    // Simulate LSCWP registering its own final emitter before wp_loaded.
    $replace_headers = function () use ( $late, $mode ) {
        if ( isset( $late[$mode] ) ) { header( $late[$mode], true ); }
        if ( strpos( $mode, 'both-replaced-' ) === 0 ) {
            header( 'X-LiteSpeed-Cache-Control: public,max-age=900', true );
            header( 'Cache-Control: public,max-age=0,s-maxage=900', true );
        }
    };
    add_action( 'shutdown', $replace_headers, 0 );
    do_action( 'wp_loaded' );
    header( 'Content-Type: text/html' );
    if ( $early_mode && isset( $late[$mode] ) ) { header( $late[$mode], true ); }
    if ( $mode === 'both-replaced-short' ) { header( 'Cache-Control: public,max-age=60', true ); }
    if ( $mode === 'both-replaced-private' ) { header( 'Cache-Control: private,no-store', true ); }
    do_action( 'template_redirect' );
    $body = '<!doctype html><title>Public fixture</title>';
    if ( $shutdown_mode ) {
        do_action( 'shutdown' );
    } else {
        if ( ! $early_mode || strpos( $mode, 'both-replaced-' ) === 0 ) { $replace_headers(); }
        $body = apply_filters( 'litespeed_buffer_after', $body );
    }
    echo $body;
    return;
}

$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
if ( ! $socket ) { throw new RuntimeException( $error ); }
$address = stream_socket_get_name( $socket, false ); fclose( $socket );
$log = tempnam( sys_get_temp_dir(), 'cyberedge-http-test-' );
$process = proc_open( array( PHP_BINARY, '-S', $address, __FILE__ ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $log, 'a' ), 2 => array( 'file', $log, 'a' ) ), $pipes );
if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Unable to start the isolated PHP server.' ); }
fclose( $pipes[0] );
$checks = 0;
try {
    for ( $i = 0; $i < 50; $i++ ) {
        $probe = @stream_socket_client( 'tcp://' . $address, $errno, $error, 0.1 );
        if ( $probe ) { fclose( $probe ); break; }
        usleep( 20000 );
    }
    foreach ( array( '', 'shutdown-', 'early-' ) as $prefix ) {
        foreach ( array( 'public', 'nocache', 'private', 'esi', 'vary', 'vary-cookie', 'set-cookie', 'application', 'json', 'native-zero', 'standard-zero', 'shared-zero', 'negative', 'invalid', 'native-short', 'standard-short', 'shared-short', 'long', 'both-replaced-short', 'both-replaced-private' ) as $mode ) {
            $http_response_header = array();
            $body = file_get_contents( 'http://' . $address . '/' . $prefix . $mode, false, stream_context_create( array( 'http' => array( 'timeout' => 3 ) ) ) );
            $controls = array_values( preg_grep( '/^Cache-Control:/i', $http_response_header ) );
            $expected = in_array( $mode, array( 'public', 'long' ), true ) ? 'Cache-Control: public,max-age=0,s-maxage=300' : 'Cache-Control: private,no-cache,no-store';
            if ( strpos( $mode, '-short' ) !== false ) { $expected = 'Cache-Control: public,max-age=0,s-maxage=60'; }
            if ( $controls !== array( $expected ) || $body !== '<!doctype html><title>Public fixture</title>' ) {
                throw new RuntimeException( $prefix . $mode . ' failed: ' . json_encode( $controls ) );
            }
            $checks++;
        }
    }
    echo json_encode( array( 'passed' => $checks, 'transport' => 'PHP HTTP SAPI over loopback only' ) ) . "\n";
} finally {
    proc_terminate( $process ); proc_close( $process ); unlink( $log );
}
