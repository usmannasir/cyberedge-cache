<?php
/** Static contract test for the official LiteSpeed Cache for WordPress source tree. */
error_reporting( E_ALL );

$root = getenv( 'LSCACHE_WP_ROOT' );
if ( ! is_string( $root ) || $root === '' ) {
    fwrite( STDERR, "Set LSCACHE_WP_ROOT to an litespeedtech/lscache_wp checkout.\n" );
    exit( 2 );
}
$root = rtrim( $root, DIRECTORY_SEPARATOR );
$checks = 0;

function contract_file( $root, $relative ) {
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    $contents = is_file( $path ) ? file_get_contents( $path ) : false;
    if ( $contents === false ) {
        throw new RuntimeException( 'Missing LiteSpeed Cache source file: ' . $relative );
    }
    return $contents;
}

function contract_check( $condition, $message ) {
    global $checks;
    if ( ! $condition ) {
        throw new RuntimeException( 'LiteSpeed Cache contract changed: ' . $message );
    }
    $checks++;
}

try {
    $bootstrap = contract_file( $root, 'litespeed-cache.php' );
    $api = contract_file( $root, 'src/api.cls.php' );
    $conf = contract_file( $root, 'src/conf.cls.php' );
    $core = contract_file( $root, 'src/core.cls.php' );
    $purge = contract_file( $root, 'src/purge.cls.php' );
    $control = contract_file( $root, 'src/control.cls.php' );
    $vary = contract_file( $root, 'src/vary.cls.php' );

    contract_check( preg_match( '/^[ \t*#\/]*Version:\s*([0-9.]+)/mi', $bootstrap, $match ) === 1, 'plugin version header is readable' );
    $version = $match[1];
    contract_check( version_compare( $version, '7.9.1', '>=' ), 'tested version must be at least 7.9.1' );
    contract_check(
        strpos( $api, "add_action( 'litespeed_control_set_nocache', __NAMESPACE__ . '\\Control::set_nocache' )" ) !== false,
        'official no-cache action is registered'
    );
    contract_check(
        preg_match( "/apply_filters\(\s*'litespeed_purge_tags'\s*,\s*\\\$purge_tags\s*,\s*\\\$is_private\s*\)/", $purge ) === 1,
        'final purge-tag filter still supplies public/private context'
    );
    contract_check(
        strpos( $conf, "defined( 'LITESPEED_ALLOWED' ) && ! defined( 'LITESPEED_ON' )" ) !== false &&
        strpos( $conf, "define( 'LITESPEED_ON', true )" ) !== false,
        'LITESPEED_ON still means the server allows page caching'
    );
    contract_check(
        strpos( $core, "defined( 'LITESPEED_DISABLE_ALL' ) && LITESPEED_DISABLE_ALL" ) !== false,
        'global disable flag remains authoritative'
    );
    contract_check(
        strpos( $core, "add_action( 'shutdown', [ \$this, 'send_headers' ], 0 )" ) !== false &&
        strpos( $core, 'Purge::output()' ) !== false,
        'final purge output is assembled during the response lifecycle'
    );
    contract_check(
        strpos( $control, 'Base::O_CACHE_EXC_COOKIES' ) !== false &&
        strpos( $control, 'array_intersect( array_keys( $_COOKIE ), $excludes )' ) !== false,
        'cookie exclusions are configured names, not a blanket cookie veto'
    );
    contract_check(
        strpos( $vary, "apply_filters( 'litespeed_vary_curr_cookies', \$cookies )" ) !== false &&
        strpos( $vary, "'cookie=' . \$v" ) !== false,
        'custom cookie variants must remain separate from a plain shared page'
    );
    contract_check(
        strpos( $vary, "Control::set_nocache( 'password protected vary' )" ) !== false,
        'password-protected content retains a no-cache veto'
    );
    contract_check(
        strpos( $core, '$this->send_headers( true );' ) !== false &&
        strpos( $core, '$this->send_headers( true );' ) < strpos( $core, "apply_filters( 'litespeed_buffer_after', \$buffer )" ) &&
        strpos( $core, "apply_filters( 'litespeed_buffer_after', \$buffer )" ) !== false,
        'the final buffer filter follows LiteSpeed final response headers'
    );

    echo json_encode( array( 'passed' => $checks, 'lscache_version' => $version ) ) . "\n";
} catch ( Throwable $error ) {
    fwrite( STDERR, $error->getMessage() . "\n" );
    exit( 1 );
}
