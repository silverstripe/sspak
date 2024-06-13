# SSPak

[![Build Status](https://api.travis-ci.com/silverstripe/sspak.svg?branch=master)](https://travis-ci.com/silverstripe/sspak)
[![Code Quality](http://img.shields.io/scrutinizer/g/silverstripe/sspak.svg?style=flat-square)](https://scrutinizer-ci.com/g/silverstripe/sspak)

SSPak is a SilverStripe tool for managing database and assets content, for back-up, restoration, or transfer between
environments.

## The file format

An sspak file is either a Phar (executable) file or a Tar (non-executable) file, containing the following files at the top level:

 * **database.sql.gz:** A gzipped SQL file that will re-create the entire database, including all content.  It will contain the 'drop' statements necessary to replace any existing content as needed.
 * **assets.tar.gz:** A gzipped tar file containing all assets.  The root directory within the tar file must be called "assets".
 * **git-remote:** A text file of the following form:

	remote = (url)
	branch = (name)
	sha = (sha-hash)

By convention, the file should have the extension `.sspak` for non-executable versions, and `.sspak.phar` for executable versions.

## Installation

You can run the installation script one of three ways.

### Composer (recommended)

You can install this package globally with Composer (ensure your composer bin is in your system path):

    $> composer global require silverstripe/sspak:dev-master

You can also require it directly in your project

    $> composer require --dev silverstripe/sspak
    $> vendor/bin/sspak <commands>

### cURL

**Note: the downloaded sspak.phar may be out of date**

If you have cURL, run this command (everything except for the `$>` part):

	$> curl -sS https://silverstripe.github.io/sspak/install | php -- /usr/local/bin

The final argument is the directory that the script will be loaded into.  If omitted, the script will be installed into the current directory.  If you don't have permission to write to the directory, "sudo" will be used to escalate permissions.

For example, this would also work:

	$> cd /usr/local/bin
	$> curl -sS https://silverstripe.github.io/sspak/install | sudo php

### Manually

**Note: the downloaded sspak.phar may be out of date**

If you prefer not to use the installer, you can download the script and copy it to your executable path as follows:

	$> wget https://silverstripe.github.io/sspak/sspak.phar
	$> chmod +x sspak.phar
	$> sudo mv sspak.phar /usr/local/bin/sspak


## Common Issues

	Creating archive disabled by the php.ini setting phar.readonly

Set your phar.readonly setting to false in your php.ini (and php-cli.ini) files.


##  Use

All sspak commands take the following general form.

	$> sspak (command) (from) (to)

Create an sspak file and save to /tmp:

	$> sspak save /var/www /tmp/site.sspak

Create an sspak file based on a remote site:

	$> sspak save me@prodserver:/var/www prod-site.sspak

Create an sspak file based on a remote site using a specific private key to connect:

	$> sspak save --identity=prodserver.key me@prodserver:/var/www prod-site.sspak

Create an executable sspak file by adding a phar extension:

	$> sspak save me@prodserver:/var/www prod-site.sspak.phar

Create an sspak from existing files:

	$> sspak saveexisting --db=/path/to/database.sql --assets=/path/to/assets /tmp/site.sspak

Extract files from an existing sspak into the specified directory:

	$> sspak extract /tmp/site.sspak /destination/path

Load an sspak file into a local instance:

	$> sspak load prod-site.sspak ~/Sites/devsite

Load an sspak file into a local instance, dropping the existing DB first (mysql only):

	$> sspak load prod-site.sspak ~/Sites/devsite --drop-db

Load an sspak file into a remote instance using a specific private key to connect:

	$> sspak save --identity=backupserver.key prod-site.sspak me@backupserver:/var/www

Transfer in one step: *(not implemented yet)*

	$> sspak transfer me@prodserver:/var/www ~/Sites/devsite

Sudo as www-data to perform the actions

	$> sspak save --sudo=www-data me@prodserver:/var/www prod-site.sspak
	$> sspak load --sudo=www1 prod-site.sspak ~/Sites/devsite
	$> sspak transfer --from-sudo=www-data --to-sudo=www1 me@prodserver:/var/www ~/Sites/devsite

Save only the database:

	$> sspak save --db me@prodserver:/var/www dev.sspak

Load only the assets:

	$> sspak load --assets dev.sspak ~/Sites/devsite

Install a new site from an sspak (needs to contain a git-remote):

	$> sspak install newsite.sspak ~/Sites/newsite

Save all while using a custom TMP folder (make sure the folder exists and is writable):

	$> TMPDIR="/tmp/my_custom_tmp" sspak save /var/www /tmp/site.sspak

## Caveats

If you don't have PKI passwordless log-in into remote servers, you will be asked for your log-in a few times.

## Notes

When using sspak with some versions of mysql you may see the output `mysqldump: unknown variable 'column-statistics=0'`. It is safe to just ignore this.

## How it works

sspak relies on the SilverStripe executable code to determine database credentials.  It does this by using a small script, sspak-sniffer.php, which it uploads to the /tmp folder of any remote servers.

This script returns database credentials and the location of the assets path.  Once it has that, it will remotely execute mysql, mysqldump and tar commands to archive or restore the content.

It expects the following commands to be available on any remote servers:

 * php
 * mysql
 * mysqldump
 * tar
 * gzip
 * sudo

It will also use the /tmp folder on the machine that you are running from, and it will need to have enough free space on there to create temporary copies of the individual files within the .sspak file, if you are using the non-executable version.  .sspak.phar files can be populated without needing a tmp file in between.
