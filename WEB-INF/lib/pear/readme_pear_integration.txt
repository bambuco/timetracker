PEAR integration notes.

These notes explain how PEAR and its modules were integrated in Anuko Time Tracker project.

PEAR packages can be downloaded from http://pear.php.net/packages.php
(click on the package group, then package name, then Download link).
For example, for PEAR it will be http://pear.php.net/package/PEAR/download


PEAR PACKAGE

- Download PEAR package from http://pear.php.net/package/PEAR/download
- Extract the files (what is in the deepest PEAR-1.9.1 folder) into WEB-INF/lib/pear/ folder in Time Tracker, so that you have something like:

folders:

OS
PEAR
scripts

and files

INSTALL
LICENSE
and others in your WEB-INF/lib/pear/ folder.


DATABASE ACCESS

As of the mysqli adapter migration, Time Tracker no longer uses PEAR MDB2.
Database access goes through WEB-INF/lib/TtDb.class.php (mysqli) via getConnection()
in WEB-INF/lib/common.lib.php. DSN format remains mysqli://user:pass@host/db?charset=utf8mb4.


Net_SMTP PACKAGE

- Download Net_SMTP module from http://pear.php.net/package/Net_SMTP/download
- From archive Net_SMTP-1.4.2.tgz take the "SMTP.php" file and put it into WEB-INF/lib/pear/Net(you will need to create the Net folder).


Net_Socket PACKAGE

- Download Net_Socket module (dependency of Net_SMTP) from http://pear.php.net/package/Net_Socket/download
- From archive Net_Socket-1.0.9.tgz take the "Socket.php" file and put it into WEB-INF/lib/pear/Net folder.


Mail PACKAGE

- Download Mail module from http://pear.php.net/package/Mail/download
- From archive Mail-1.2.0.tgz take "Mail.php" file and Mail folder. Put them in WEB-INF/lib/pear folder.

Now we have PEAR, PEAR Net_SMTP, and PEAR Mail modules installed (for email only).



Add this line to any place in config.php.dist to set PHP include path for PEAR and its modules:

set_include_path(realpath(dirname(__FILE__).'/lib/pear') . PATH_SEPARATOR . get_include_path());

Note: it is important to include realpath(dirname(__FILE__).'/lib/pear') first to eliminate any potential
PEAR compatibility issues for systems where another version of PEAR may be installed (like SME Server 8.0).
