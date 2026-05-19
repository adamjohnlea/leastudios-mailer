<?php
/**
 * PHPUnit bootstrap for leaStudios Mailer.
 *
 * @package LEAStudios\Mailer
 */

declare(strict_types=1);

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php\n";
	echo "Run: bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '' localhost latest\n";
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

// Options_Encryptor derives its key from AUTH_KEY and SECURE_AUTH_SALT and
// will wp_die() if either is missing. wp-tests-config.php doesn't define
// them by default, so set fixed test values here before WP boots and
// before the plugin file is loaded.
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'lsm-tests-auth-key-do-not-use-in-prod' );
}
if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
	define( 'SECURE_AUTH_SALT', 'lsm-tests-secure-auth-salt-do-not-use-in-prod' );
}

tests_add_filter(
	'muplugins_loaded',
	function () {
		require __DIR__ . '/../leastudios-mailer.php';
	}
);

require "{$_tests_dir}/includes/bootstrap.php";

require_once __DIR__ . '/TestCase.php';
