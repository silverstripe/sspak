SSPak
=====

SSPak is a SilverStripe tool for managing database and assets content, for back-up, restoration, or transfer between
environments.

The file format
---------------

It's really simple, just an ungzipped tar, containing the following files at the top level:

 * database.sql.gz
 * asssets.sql.gz

Use the extension `.sspak`.

Use
---

All sspak commands take the following general form.

	$> sspak (command) (from) (to)

Create an sspak file and save to /tmp:

    $> sspak save /var/www /tmp/site.sspak

Create an sspak file based on a remote site:

    $> sspak save me@prodserver:/var/www prod-site.sspak

Load an sspak file into a local instance:

    $> sspak load prod-site.sspak ~/Sites/devsite

Transfer in one step: *(not implemented yet)*

    $> sspak transfer me@prodserver:/var/www ~/Sites/devsite

Sudo as www-data to perform the actions

    $> sspak save --sudo=www-data me@prodserver:/var/www prod-site.sspak
    $> sspak load --sudo=www1 prod-site.sspak ~/Sites/devsite
    $> sspak transfer --from-sudo=www-data --to-sudo=www1 me@prodserver:/var/www ~/Sites/devsite

Transfer only the database: *(not implemented yet)*

    $> sspak transfer --db me@prodserver:/var/www ~/Sites/devsite

Or only the assets: *(not implemented yet)*

    $> sspak transfer --assets me@prodserver:/var/www ~/Sites/devsite

Caveats
-------

If you don't have PKI passwordless log-in into remote servers, you will be asked for your log-in a few times.

How it works
------------

sspak relies on the SilverStripe executable code to determine database credentials.  It does this by using a small script, sspak-sniffer.php, which it uploads to the /tmp folder of any remote servers.

This script returns database credentials and the location of the assets path.  Once it has that, it will remotely execute mysql, mysqldump and tar commands to archive or restore the content.

It expects the following commands to be available on any remote servers:

 * php
 * mysql
 * mysqldump
 * tar
 * gzip
 * sudo

It will also use the /tmp folder and it will need to have enough free space on there to create the .sspak file.