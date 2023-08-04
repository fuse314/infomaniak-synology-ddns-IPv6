#!/bin/sh
if [ "${EUID:-$(id -u)}" -ne 0 ]; then
  echo "Error: script must be run as root!"
  exit 1
fi

phpfile="infomaniak46.php"
script=$(readlink -f "$0")
scriptdir=$(dirname "$script")
phpsrcfile="$scriptdir/$phpfile"
conffile="/etc.defaults/ddns_provider.conf"
phpdstfile="/usr/syno/bin/ddns/$phpfile"

if [ ! -f $phpdstfile ]; then
  echo "$phpdstfile does not exist, copying..."
  cp "$phpsrcfile" "$phpdstfile"
  chown root:root "$phpdstfile"
  chmod 755 "$phpdstfile"
fi

grep "\[INFOMANIAK_46\]" $conffile > /dev/null
if [ "$?" = "1" ]; then
  echo "updating $conffile ..."
  echo "[INFOMANIAK_46]" >> $conffile
  echo "\t\tmodulepath=$phpdstfile" >> $conffile
  echo "\t\tqueryurl=https://infomaniak.com/nic/update" >> $conffile
fi
