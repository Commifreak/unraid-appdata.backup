#!/bin/bash
echo "Appdata.Backup: Hotfix: Fix max_input_vars size in PHP"
echo

INIPATH="/etc/php.d/ab_max_input_vars.ini"

if [ -f "$INIPATH" ]; then
  echo "Reverting fix..."
  rm $INIPATH
else
  echo "Applying fix..."
  echo "max_input_vars = 2000" > $INIPATH
fi
/etc/rc.d/rc.php-fpm restart
echo
echo "done!"
echo