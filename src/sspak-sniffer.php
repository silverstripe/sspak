#!/usr/bin/env php
<?php

/**
 * SSPak Sniffer
 * Extract database and assets information from a SilverStripe site.
 */

// Argument parsing
if(empty($_SERVER['argv'][1])) {
	echo "Usage: {$_SERVER['argv'][0]} (site-docroot)\n";
	exit(1);
}

$basePath = $_SERVER['argv'][1];
if($basePath[0] != '/') $basePath = getcwd() . '/' . $basePath;

// SilverStripe bootstrap
define('BASE_PATH', $basePath);
define('BASE_URL', '/');
$_SERVER['HTTP_HOST'] = 'localhost';
chdir(BASE_PATH);

if(file_exists(BASE_PATH.'/framework/core/Core.php')) {
	require_once(BASE_PATH. '/framework/core/Core.php');
} else if(file_exists(BASE_PATH.'/sapphire/core/Core.php')) {
	require_once(BASE_PATH. '/sapphire/core/Core.php');
} else {
	echo "No framework/core/Core.php or sapphire/core/Core.php included in project.  Perhaps " . BASE_PATH . " is not a SilverStripe project?\n";
	exit(2);
}

$output = array();
foreach($databaseConfig as $k => $v) {
	$output['db_' . $k] = $v;
}
$output['assets_path'] = ASSETS_PATH;

echo serialize($output);
echo "\n";