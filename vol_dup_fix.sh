#!/bin/bash
set -e
echo "Appdata.Backup: Duplicate volume detection test fix"
cd /usr/local/emhttp/plugins/appdata.backup/pages/content

if [ -f settings.php.bak ]
then
  echo "Fix already applied! Undo changes..."
  rm settings.php
  mv settings.php.bak settings.php
  exit 1
fi

echo "Applying fix..."

mv settings.php settings.php.bak
wget -q https://raw.githubusercontent.com/Commifreak/unraid-appdata.backup/volume_dup_check_fix/src/pages/content/settings.php

echo "Fix applied! Reload plugin settings page!"