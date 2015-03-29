# Update source code #

  * if you checked out the source via SVN, go to application directory and do
```
svn up 
```

  * If you installed from the archive, simply download the RC3 archive and replace all files with new ones (except etc folder)

# Update database #
  * Import sql/upgrade\_1.0RC2-1.0RC3.sql into your database.
  * execute bin/upgrade\_from\_RC2\_to\_RC3.php in shell.

```
 php ./bin/upgrade_from_RC2_to_RC3.php
```