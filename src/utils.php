<?php
/**
 * Utility functions
 *
 * @package  wpsnapshots
 */

namespace AirSnapshots\Utils;

use Requests;

/**
 * Test MySQL connection
 *
 * @param  string $host     DB host
 * @param  string $database DB name
 * @param  string $user     User
 * @param  string $password Password
 * @return bool
 */
function test_mysql_connection( $host, $database, $user, $password ) {
	$mysqli = mysqli_init();
	return ( ! @$mysqli->real_connect( $host, $user, $password, $database ) ) ? mysqli_connect_error() : true;
}

/**
 * Add trailing slash to path
 *
 * @param  string $path Path
 * @return string
 */
function trailingslash( $path ) {
	return rtrim( $path, '/' ) . '/';
}

/**
 * Normalizes paths. Note that we DO always add a trailing slash here
 *
 * /
 * ./
 * ~/
 * ./test/
 * ~/test
 * test
 *
 * @param  string $path Path to normalize
 * @return string
 */
function normalize_path( $path ) {
	$path = trim( $path );
	if ( '/' === $path ) {
		return $path;
	}

	/**
	 * Prepend ./ to non absolute paths
	 */
	if ( preg_match( '#[^\./\\\~]#i', substr( $path, 0, 1 ) ) ) {
		$path = './' . $path;
	}

	/**
	 * Make non-absolute path absolute
	 */
	if ( './' === substr( $path, 0, 2 ) ) {
		$path = rtrim( getcwd(), '/' ) . '/' . substr( $path, 2 );
	}

	/**
	 * Replace ~ with home directory
	 */
	if ( '~' === substr( $path, 0, 1 ) ) {
		$path = ltrim( $path, '~' );
		$home = rtrim( $_SERVER['HOME'], '/' );
		$path = $home . $path;
	}
	return trailingslash( $path );
}

/**
 * Escape a path that will be passed to a shell
 *
 * @param  string $path Path to escape
 * @return string
 */
function escape_shell_path( $path ) {
	return str_replace( ' ', '\ ', $path );
}

/**
 * Validator for Symfony Question
 *
 * @param  string $answer Answer to check
 * @throws \RuntimeException Exception to throw if answer isn't valid.
 * @return string
 */
function not_empty_validator( $answer ) {
	if ( '' === trim( $answer ) ) {
		throw new \RuntimeException(
			'A valid answer is required.'
		);
	}
	return $answer;
}

/**
 * Validator for slugs
 *
 * @param  string $answer Answer to validate
 * @throws \RuntimeException Exception to throw if answer isn't valid.
 * @return string
 */
function slug_validator( $answer ) {
	if ( ! preg_match( '#^[a-z0-9\-_]+$#i', $answer ) ) {
		throw new \RuntimeException(
			'A valid non-empty slug is required (letters, numbers, -, and _).'
		);
	}
	return strtolower( $answer );
}

/**
 * Is WordPress in the directory?
 *
 * @param  string $path Path to WordPress directory
 * @return boolean
 */
function is_wp_present( $path ) {
	return ( file_exists( trailingslash( $path ) . 'wp-settings.php' ) );
}

/**
 * Find wp-config.php
 *
 * @param string $path Path to search for wp-config.php
 * @return string
 */
function locate_wp_config( $path ) {
	$path = trailingslash( $path );
	if ( file_exists( $path . 'wp-config.php' ) ) {
		$path = $path . 'wp-config.php';
	} elseif ( file_exists( $path . '../wp-config.php' ) ) {
		$path = $path . '../wp-config.php';
	} else {
		return false;
	}
	return realpath( $path );
}

/**
 * Create snapshots cache. Providing an id creates the subdirectory as well.
 *
 * @param  string $id Optional ID. Setting this will create the snapshot directory.
 * @return bool
 */
function create_snapshot_directory( $id = null ) {
	if ( ! file_exists( get_snapshot_directory() ) ) {
		$dir_result = mkdir( get_snapshot_directory(), 0755 );
		if ( ! $dir_result ) {
			return false;
		}
	}
	if ( ! is_writable( get_snapshot_directory() ) ) {
		return false;
	}
	if ( ! empty( $id ) ) {
		if ( ! file_exists( get_snapshot_directory() . $id . '/' ) ) {
			$dir_result = mkdir( get_snapshot_directory() . $id . '/', 0755 );
			if ( ! $dir_result ) {
				return false;
			}
		}
		if ( ! is_writable( get_snapshot_directory() . $id . '/' ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Get path to snapshot cache directory with trailing slash. If env variable AIRSNAPSHOTS_DIR is
 * set, then use that.
 *
 * @return string
 */
function get_snapshot_directory() {
	$env_dir = getenv( 'AIRSNAPSHOTS_DIR' );
	return ( ! empty( $env_dir ) ) ? rtrim( $env_dir, '/' ) . '/' : rtrim( $_SERVER['HOME'], '/' ) . '/.airsnapshots/';
}

/**
 * Generate unique snapshot ID
 *
 * @return string
 */
function generate_snapshot_id() {
	return md5( time() . '' . rand() );
}

/**
 * Check if snapshot is in cache
 *
 * @param  string $id Snapshot id
 * @return boolean
 */
function is_snapshot_cached( $id ) {
	if ( ! file_exists( get_snapshot_directory() . $id . '/data.sql.gz' ) ) {
		return false;
	}
	return true;
}

/**
 * Run MySQL command via proc given associative command line args
 *
 * @param  string $cmd MySQL command
 * @param  array  $assoc_args Args to pass to MySQL
 * @param  string $append String to append to command
 * @param  bool   $exit_on_error Whether to exit on error or not.
 * @return string
 */
function run_mysql_command( $cmd, $assoc_args, $append = '', $exit_on_error = true ) {
	check_proc_available( 'run_mysql_command' );
	if ( isset( $assoc_args['host'] ) ) {
		$assoc_args = array_merge( $assoc_args, mysql_host_to_cli_args( $assoc_args['host'] ) );
	}
	$pass = $assoc_args['pass'];
	unset( $assoc_args['pass'] );
	$old_pass = getenv( 'MYSQL_PWD' );
	putenv( 'MYSQL_PWD=' . $pass );
	$final_cmd = force_env_on_nix_systems( $cmd ) . assoc_args_to_str( $assoc_args ) . $append;
	$proc      = proc_open( $final_cmd, [ STDIN, STDOUT, STDERR ], $pipes );
	if ( $exit_on_error && ! $proc ) {
		exit( 1 );
	}
	$r = proc_close( $proc );
	putenv( 'MYSQL_PWD=' . $old_pass );
	if ( $exit_on_error ) {
		if ( $r ) {
			exit( $r );
		}
	} else {
		return $r;
	}
}

/**
 * Returns tables
 *
 * @param  bool $wp Whether to only return WP tables
 * @return array
 */
function get_tables( $wp = true ) {
	global $wpdb;
	$tables  = [];
	$results = $wpdb->get_results( 'SHOW TABLES', ARRAY_A );
	foreach ( $results as $table_info ) {
		$table_info = array_values( $table_info );
		$table      = $table_info[0];
		if ( $wp ) {
			if ( 0 === strpos( $table, $GLOBALS['table_prefix'] ) ) {
				$tables[] = $table;
			}
		} else {
			$tables[] = $table;
		}
	}
	return $tables;
}

/**
 * Translate mysql host to cli args
 *
 * @param  string $raw_host Host string
 * @return array
 */
function mysql_host_to_cli_args( $raw_host ) {
	$assoc_args = array();
	$host_parts = explode( ':', $raw_host );
	if ( count( $host_parts ) == 2 ) {
		list( $assoc_args['host'], $extra ) = $host_parts;
		$extra                              = trim( $extra );
		if ( is_numeric( $extra ) ) {
			$assoc_args['port']     = intval( $extra );
			$assoc_args['protocol'] = 'tcp';
		} elseif ( '' !== $extra ) {
			$assoc_args['socket'] = $extra;
		}
	} else {
		$assoc_args['host'] = $raw_host;
	}
	return $assoc_args;
}

/**
 * Shell escape command as an array
 *
 * @param  array $cmd Shell command
 * @return array
 */
function esc_cmd( $cmd ) {
	if ( func_num_args() < 2 ) {
		trigger_error( 'esc_cmd() requires at least two arguments.', E_USER_WARNING );
	}
	$args = func_get_args();
	$cmd  = array_shift( $args );
	return vsprintf( $cmd, array_map( 'escapeshellarg', $args ) );
}

/**
 * Make sure env path is used on *nix
 *
 * @param  string $command Command string.
 * @return string
 */
function force_env_on_nix_systems( $command ) {
	$env_prefix     = '/usr/bin/env ';
	$env_prefix_len = strlen( $env_prefix );
	if ( is_windows() ) {
		if ( 0 === strncmp( $command, $env_prefix, $env_prefix_len ) ) {
			$command = substr( $command, $env_prefix_len );
		}
	} else {
		if ( 0 !== strncmp( $command, $env_prefix, $env_prefix_len ) ) {
			$command = $env_prefix . $command;
		}
	}
	return $command;
}

/**
 * Determine if we are on windows
 *
 * @return bool
 */
function is_windows() {
	return strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN';
}

/**
 * Convert assoc array to string to command
 *
 * @param  array $assoc_args Associative args
 * @return string
 */
function assoc_args_to_str( $assoc_args ) {
	$str = '';
	foreach ( $assoc_args as $key => $value ) {
		if ( true === $value ) {
			$str .= " --$key";
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $_ => $v ) {
				$str .= assoc_args_to_str( array( $key => $v ) );
			}
		} else {
			$str .= " --$key=" . escapeshellarg( $value );
		}
	}
	return $str;
}

/**
 * Escape sql name e.g. table name
 *
 * @param  string $name Name to escape
 * @return string
 */
function esc_sql_name( $name ) {
	return preg_replace( '#["\'`]#', '', $name );
}

/**
 * Format bytes to pretty file size
 *
 * @param  int $size     Number of bytes
 * @param  int $precision Decimal precision
 * @return string
 */
function format_bytes( $size, $precision = 2 ) {
	$base     = log( $size, 1024 );
	$suffixes = [ '', 'KB', 'MB', 'GB', 'TB' ];
	return round( pow( 1024, $base - floor( $base ) ), $precision ) . ' ' . $suffixes[ floor( $base ) ];
}

/**
 * Determine if proc is available
 *
 * @return bool
 */
function check_proc_available() {
	if ( ! function_exists( 'proc_open' ) || ! function_exists( 'proc_close' ) ) {
		return false;
	}
	return true;
}
