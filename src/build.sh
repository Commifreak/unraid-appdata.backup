#!/bin/bash
SUFFIX=""
VERSION=$(date +"%Y.%m.%d")

if [ "$1" == "beta" ]
then
  SUFFIX=".beta"
fi

if [ ! -z $2 ]
then
  VERSION=$VERSION$1
fi



cd /usr/local/emhttp/plugins/appdata.backup
if [ $? -ne 0 ]
then
  exit
fi
tar --exclude="build.sh" -czvf /tmp/appdata.backup$SUFFIX-${VERSION}.tgz .

SUM=$(sha256sum /tmp/appdata.backup$SUFFIX-${VERSION}.tgz)
echo "SHA256: $SUM"