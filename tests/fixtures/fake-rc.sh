#!/bin/sh
echo "fake rc: $1"
[ "$1" = "fail" ] && exit 1
exit 0
