<?php
/**
 * Bootstrap Air DB Snapshots
 *
 * @package airsnapshots
 */

namespace AirSnapshots;

use Symfony\Component\Console\Application;

define( 'AIRSNAPSHOTS_VERSION', '0.0.1' );

require_once __DIR__ . '/utils.php';

$app = new Application( 'Air DB Snapshots', AIRSNAPSHOTS_VERSION );

/**
 * Attempt to set this as Air DB Snapahots can consume a lot of memory.
 */
ini_set( 'memory_limit', '-1' );

/**
 * Register commands
 */
$app->add( new Command\Configure() );
$app->add( new Command\Create() );
$app->add( new Command\CreateRepository() );
$app->add( new Command\Push() );
$app->add( new Command\Pull() );
$app->add( new Command\Search() );
$app->add( new Command\Delete() );
$app->add( new Command\Download() );
$app->run();
