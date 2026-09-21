#!/bin/bash
#
# Copyright Derek Macias (parts of code from NUT package)
# Copyright macester (parts of code from NUT package)
# Copyright gfjardim (parts of code from NUT package)
# Copyright SimonF (parts of code from NUT package)
# Copyright Lime Technology (any and all other parts of Unraid)
#
# Copyright desertwitch (as author and maintainer of this file)
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License 2
# as published by the Free Software Foundation.
#
# The above copyright notice and this permission notice shall be
# included in all copies or substantial portions of the Software.
#
BOOT="/boot/config/plugins/sesmon-ext"
DOCROOT="/usr/local/emhttp/plugins/sesmon-ext"

# the plugin installer creates this folder when it downloads the packages, but an
# install that does not go through it (e.g. upgradepkg by hand) must still work
mkdir -p $BOOT

# An upgrade only overwrites files: pages and scripts that an earlier version had and this one no longer ships
# would stay behind and keep being served. The package lists its own files in .manifest; remove any other file
# below the web folder (the json link is not a file, start-failed is a flag of the service script). This runs
# after the package is extracted, so a download that fails first leaves the installed version untouched.
if [ -f $DOCROOT/.manifest ]; then
    (cd $DOCROOT && find . -type f ! -name .manifest ! -name start-failed | LC_ALL=C sort | LC_ALL=C comm -23 - .manifest | while read -r f; do rm -f -- "$f"; done)
    find $DOCROOT -mindepth 1 -depth -type d -empty -delete
fi

chmod 755 /etc/rc.d/rc.sesmon-ext
chmod 755 $DOCROOT/scripts/*
chmod 644 /etc/logrotate.d/sesmon-ext

cp -n $DOCROOT/default.cfg $BOOT/sesmon-ext.cfg

mkdir -p $BOOT/config
mkdir -p /etc/sesmon-ext
mkdir -p /var/lib/sesmon-ext

ln -sfn /var/lib/sesmon-ext $DOCROOT/json   # -n: do not follow an existing link into the folder
cp -nr $DOCROOT/defaults/* $BOOT/config/
cp -rf $BOOT/config/* /etc/sesmon-ext/

chmod 644 /etc/sesmon-ext/*
chmod 755 /etc/sesmon-ext/*.sh
