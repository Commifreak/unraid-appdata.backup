#!/bin/bash
SUFFIX=""
VERSION=$(date +"%Y.%m.%d")
if [ ! -z $1 ]
then
  SUFFIX=".beta"
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