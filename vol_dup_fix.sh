#!/bin/bash
set -e
echo "Appdata.Backup: Duplicate volume detection test fix"
cd /usr/local/emhttp/plugins/appdata.backup/pages/content

if [ -f settings.php.bak ]
then
  echo "Fix already applied!"
  exit 1
fi

mv settings.php settings.php.bak
wget https://raw.githubusercontent.com/Commifreak/unraid-appdata.backup/volume_dup_check_fix/src/pages/content/settings.php

echo "Fix applied! Reload plugin settings page!"