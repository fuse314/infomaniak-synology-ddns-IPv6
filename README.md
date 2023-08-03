# Synology-DDNS-IPv6
Synology DSM has still very limitted support for DynDNS including IPv6. Except for synology.me, only IPv4 is supported for all the pre-configured providers. As long as the Internet provider assigns static IPv6 addresses, this is not a problem. In Germany and Switzerland, however, many providers are using dynamic IPv6 prefixes for private individuals, which change each time a new connection is established.

Your internet router may support DDNS including IPv6, but it will not be a solution to access your Synology over IPv6 from outside. There is a single IPv4 global address (common for the router and e.g. your Synology), but there are different global IPv6 addresses for the router and your Synology. Therefore an DynDNS update routine including IPv6 on the Synology is required to make services on the Synology via IPv6 available from outside and not only your router.

On your Synology device in the folder /usr/syno/bin/ddns/ there are scripts for some providers. If you are going to "Add" in the control panel under "External Access", "DDNS" you will find in the dropdown for "Service Providers" the entries from the file `/etc.defaults/ddns_provider.conf`.

With the following steps you can use a sub domain of your domain hosted at Infomaniak to access your Synology including IPv6:
1) Follow the instructions under [infomaniak.com FAQ 2357](https://www.infomaniak.com/de/support/faq/2357/dyndns-einrichten-eines-ddns-mit-einer-bei-infomaniak-verwalteten-domain) to setup a sub domain for the Synology on your domain.
2) Copy the php script from this repository to `/usr/syno/bin/ddns/infomaniak46.php`. And make it executable (`chmod 755 /usr/syno/bin/ddns/infomaniak46.php`)
3) Add to that configuration file `/etc.defaults/ddns_provider.conf` the lines
   ```
   [INFOMANIAK_4_6]
   modulepath=/usr/syno/bin/ddns/infomaniak46.php
   queryurl=https://infomaniak.com/nic/update
   ```
4) In the control panel you can now select INFOMANIAK_4_6 from the dropdown, enter your host name (subdomain.yourdomain.com), user name (yourdomain.com) and password.

DSM is executing the DDNS update normally once every 24 hours. But sometimes every few minutes and that causes "abuse " response from Infomaniak and a critical DSM Protocoll Center entry. To avoid that, a minimum interval $ageMin_h with preset to 2.0 hours was added.

**Important:** In the control panel in the column "External Address" only your IPv4 address will be displayed.

The last line in /tmp/ddns.log contains the response from Infomaniak, e.g.

`curl_exec result: nochg 12.34.56.78 2003:8106:1234:5678:abcd:ef01:2345:6789`

## Credits and References
- Changes from Strato to Infomaniak by [fuse314](https://github.com/fuse314/infomaniak-synology-ddns-IPv6)
- Original code by [schmidhorst](https://github.com/schmidhorst/synology-ddns-IPv6)
- Thanks to PhySix66 https://community.synology.com/enu/forum/1/post/130109
- Thanks to mgutt and mweigel https://www.programmierer-forum.de/ipv6-ddns-mit-synology-nas-evtl-auf-andere-nas-router-bertragbar-t319393.htm
- Thanks to hwkr https://gist.github.com/hwkr/906685a75af55714a2b696bc37a0830a
