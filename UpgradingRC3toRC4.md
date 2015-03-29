# Update source code #

  * if you checked out the source via SVN, go to application directory and do
```
svn up 
```

  * If you installed from the archive, simply download the RC4 archive and replace all files with new ones (except etc folder)

# Update database #
  * Import sql/upgrade\_1.0RC3-1.0RC4.sql into your database.
  * execute bin/upgrade\_from\_RC3\_to\_RC4.php in shell.

```
 php ./bin/upgrade_from_RC3_to_RC4.php
```