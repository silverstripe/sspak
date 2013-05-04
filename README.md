SSPak
=====

SSPak is a SilverStripe tool for managing database and assets content, for back-up, restoration, or transfer between
environments.

The file format
---------------

An sspak file is an Phar file, containing the following files at the top level:

 * **database.sql.gz:** A gzipped SQL file that will re-create the entire database, including all content.  It will contain the 'drop' statements necessary to replace any existing content as needed.
 * **assets.tar.gz:** A gzipped tar file containing all assets.  The root directory within the tar file must be called "assets".
 * **git-remote:** A text file of the following form:

        remote = (url)
        branch = (name)
        sha = (sha-hash)

By convention, the file should have the extension `.sspak.phar`.

Use
---

All sspak commands take the following general form.

	$> sspak (command) (from) (to)

Create an sspak file and save to /tmp:

    $> sspak save /var/www /tmp/site.sspak

Create an sspak file based on a remote site:

    $> sspak save me@prodserver:/var/www prod-site.sspak.phar

Load an sspak file into a local instance:

    $> sspak load prod-site.sspak.phar ~/Sites/devsite

Transfer in one step: *(not implemented yet)*

    $> sspak transfer me@prodserver:/var/www ~/Sites/devsite

Sudo as www-data to perform the actions

    $> sspak save --sudo=www-data me@prodserver:/var/www prod-site.sspak.phar
    $> sspak load --sudo=www1 prod-site.sspak.phar ~/Sites/devsite
    $> sspak transfer --from-sudo=www-data --to-sudo=www1 me@prodserver:/var/www ~/Sites/devsite

Save only the database: 

    $> sspak save --db me@prodserver:/var/www dev.sspak.phar

Load only the assets:

    $> sspak load --assets dev.sspak.phar ~/Sites/devsite

Install a new site from an sspak (needs to contain a git-remote):

    $> sspak install newsite.sspak.phar ~/Sites/newsite

Bundle an sspak file into a self-extracting exectuable:

    $> sspak bundle site.sspak.phar site-installer
    $> ./site-installer install ~/Sites/newsite
    $> ./site-installer load ~/Sites/existingsite

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

It will also use the /tmp folder and it will need to have enough free space on there to create the .sspak.phar file.