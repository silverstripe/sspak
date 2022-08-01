#!/usr/bin/env php
<?php

/**
 * SSPak Sniffer
 * Extract database and assets information from a SilverStripe site.
 */

// Argument parsing
if (empty($_SERVER['argv'][1])) {
    echo "Usage: {$_SERVER['argv'][0]} (site-docroot)\n";
    exit(1);
}

$basePath = $_SERVER['argv'][1];
if ($basePath[0] != '/') {
    $basePath = getcwd() . '/' . $basePath;
}

// SilverStripe bootstrap
define('BASE_PATH', realpath($basePath));
if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}
$_SERVER['HTTP_HOST'] = 'localhost';
chdir(BASE_PATH);

if (file_exists(BASE_PATH.'/sapphire/core/Core.php')) {
    //SS 2.x
    require_once(BASE_PATH . '/sapphire/core/Core.php');
} elseif (file_exists(BASE_PATH.'/framework/core/Core.php')) {
    //SS 3.x
    require_once(BASE_PATH. '/framework/core/Core.php');
} elseif (file_exists(BASE_PATH.'/vendor/silverstripe/framework')) {
    //SS 4.x
    require_once(BASE_PATH. '/vendor/autoload.php');
    $kernel = new SilverStripe\Core\CoreKernel(BASE_PATH);
    //boot the parts of the kernel to populate the DB config
    foreach (array('bootDatabaseEnvVars', 'bootDatabaseGlobals') as $bootMethod) {
        $reflectedBootMethod = new ReflectionMethod($kernel, $bootMethod);
        $reflectedBootMethod->setAccessible(true);
        $reflectedBootMethod->invoke($kernel);
    }
    $databaseConfig = SilverStripe\ORM\DB::getConfig();
} else {
    echo "Couldn't locate framework's Core.php. Perhaps " . BASE_PATH . " is not a SilverStripe project?\n";
    exit(2);
}

$output = array();
foreach ($databaseConfig as $k => $v) {
    $output['db_' . $k] = $v;
}
$output['assets_path'] = ASSETS_PATH;

echo serialize($output);
echo "\n";
