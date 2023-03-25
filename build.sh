#!/bin/bash
SUFFIX=""
VERSION=$(date +"%Y.%m.%d")
if [ ! -z $1 ] && [ "$1" == "beta" ]
then
  SUFFIX=".beta"
fi

cd /usr/local/emhttp/plugins/appdata.backup
if [ $? -ne 0 ]
then
  exit
fi
makepkg -l y -c n /tmp/appdata.backup$SUFFIX-${VERSION}-x86_64-1.txz

SUM=$(sha256sum /tmp/appdata.backup$SUFFIX-${VERSION}-x86_64-1.txz)
echo "SHA256: $SUM"