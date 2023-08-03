#!/usr/bin/php
<?php
/*
Attention: No empty line before this!!!! The output must start with 'good' or 'nochg' in the 1st line for an success message 
https://community.synology.com/enu/forum/1/post/130109
https://www.computerbase.de/forum/threads/ddns-mit-ipv6.2057194/
https://www.programmierer-forum.de/ipv6-ddns-mit-synology-nas-evtl-auf-andere-nas-router-bertragbar-t319393.htm
https://gist.github.com/hwkr/906685a75af55714a2b696bc37a0830a

Remark: If the command "ip -6 addr" is reporting multiple IPv6 addresses, the 1st global one is used.
You may change the command and add the name the interface, to e.g. "ip -6 addr list ovs_eth1 ..." 
The interface names can be extracted from the result of the "ip -6 addr" command output
Alternative (see below commented out version): Ask Google for the IPv6, whith which the DSM is online

Parameters: 1=account (username, usually domain name), 2=pwd (use single quotes!) 3=hostname (incl. sub domain), 4=ip (IPv4)

This script checks the current A and AAAA records for "hostname" and only sends a request if the addresses have changed.
*/

$date = date('Y-m-d H:i:s');
$msg = "\n$date Start $argv[0]\n";
if ($argc !== 5) {
  $msg .= "  Error: Bad param count $argc instead of 5!\n $argv[0] <account> '<PW>' <host> <ipv4>\n";
  echo $msg;
  exit("Error: Bad param count $argc instead of 5!");
}
$account = (string)$argv[1]; // Account name
$pwd = (string)$argv[2];
$hostname = (string)$argv[3]; // sub.domain.com
$ip = (string)$argv[4];

// check that the hostname contains '.'
if (strpos($hostname, '.') === false) {
  // echo "Error: Badparam hostname $hostname\n";
  $msg .= "  Error: Badparam hostname $hostname\n";
  echo "$msg";
  exit("Error: Badparam hostname $hostname");
}

if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
  // echo ("Error Badparam: 1st IP is not IPv4 in '$ip'\n");
  $msg .= "  Error Badparam: 1st IP is not IPv4 in '$ip'\n";
  echo "$msg";
  exit("Error Badparam: 1st IP is not IPv4 in '$ip'");
}

// Solution without contacting Google:
// https://forum.feste-ip.net/viewtopic.php?t=468
// IPV6=$(ip addr list eth0 | grep "global" | cut -d ' ' -f6 | cut -d/ -f1)
// https://superuser.com/questions/468727/how-to-get-the-ipv6-ip-address-in-linux
$cmd = "ip -6 addr | grep inet6 | grep 'scope global' | awk -F '[ \t]+|/' '{print $3}' 2>&1";
// $msg .= "cmd: $cmd\n";
$ipv6multi = shell_exec($cmd);
// msg .= "ipv6multi $ipv6multi"; // if more than one LAN active: multiple adresses
$lines = explode("\n", $ipv6multi);
$ipv6 = $lines[0];
$msg .= "  used IPv6: $ipv6\n";

// Alternate Solution: Get the online IPv6 of the disk station:
// $ipv6 = get_data('https://domains.google.com/checkip');

if (!filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
  echo ("$ipv6 is not a valid IPv6 address\n");
  $msg .= "  $ipv6 is not a valid IPv6 address\n";
  $ipv6 = '';
}
if (!filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
  echo ("$ipv6 is not a valid global IPv6 address\n");
  $msg .= "  $ipv6 is not a valid global IPv6 address\n";
  $ipv6 = '';
}

$dnsipv6 = explode("\n", shell_exec("nslookup -type=aaaa $hostname | /bin/tail -n 4 | /bin/grep \"Address:\" | /bin/cut -d \" \" -f 2"))[0];
$dnsipv4 = explode("\n", shell_exec("nslookup -type=a $hostname | /bin/tail -n 4 | /bin/grep \"Address:\" | /bin/cut -d \" \" -f 2"))[0];

if ($ip == $dnsipv4 && ($ipv6 == '' || $ipv6 == $dnsipv6)) {
  $msg .= "IP addresses unchanged, no need to update.\n";
  echo "nochg $ip $ipv6\n";
  exit;
}

$msg .= "IP changed: v4: param=$ip DNS=$dnsipv4 v6: param=$ipv6 DNS=$dnsipv6\n";
$msg .= "preparing request(s)...\n";

// https://www.infomaniak.com/de/support/faq/2357/dyndns-einrichten-eines-ddns-mit-einer-bei-infomaniak-verwalteten-domain
// https://username:passwort@infomaniak.com/nic/update?hostname=subdomain.yourdomain.com&myip=12.34.56.78,2000:1200:3400:5600:abcd:ef01:2345:6789  

$url = 'https://infomaniak.com/nic/update?hostname=' . $hostname . '&myip=';

$result = '';
if($ip !== $dnsipv4) {
  $v4url = $url . $ip;
  $msg .= "  need to update ipv4. Used url: $v4url\n";
  $result = updateHost($v4url, $account, $pwd) . "\n";
}
if($ipv6 !== $dnsipv6) {
  $v6url = $url . $ipv6;
  $msg .= "  need to update ipv6. Used url: $v6url\n";
  $result = updateHost($v6url, $account, $pwd);
}

// DEBUG
//echo $msg;

echo $result; // The script output needs to start(!!) with "nochg" or "good" to avoid error messages in the synology protocol list.
if ((strpos($result, "good") !== 0) && (strpos($result, "nochg") !== 0)) {
  syslog(LOG_ERR, "$argv[0]: $result");
}

function updateHost($fullurl, $user, $pw)
{
  // Send now the actual IPs to the DDNS provider:
  $req = curl_init();
  curl_setopt($req, CURLOPT_URL, $fullurl);
  curl_setopt($req, CURLOPT_RETURNTRANSFER, 1); // https://stackoverflow.com/questions/6516902/how-to-get-response-using-curl-in-php
  curl_setopt($req, CURLOPT_CONNECTTIMEOUT, 25);
  // without an agent you will get "badagent 0.0.0.0"
  // https://www.linksysinfo.org/index.php?threads/ddns-custom-url-badagent-error.75520/
  $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36';
  curl_setopt($req, CURLOPT_USERAGENT, $agent);
  // CURLOPT_AUTOREFERER
  curl_setopt($req, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  curl_setopt($req, CURLOPT_USERPWD, "$user:$pw");

  // $res = 'nochg 9.99.99.99 2003:99:99:99:99:99:99:99'; // may be used for debugging
  // $res = 'badauth test';
  $res = curl_exec($req);
  curl_close($req);
  return $res;
  /*
    https://community.synology.com/enu/forum/17/post/57640, normal responses:
    good - Update successfully.
    nochg - Update successfully but the IP address have not changed.
    nohost - The hostname specified does not exist in this user account.
    abuse - The hostname specified is blocked for update abuse, too often requisted in short period
    notfqdn - The hostname specified is not a fully-qualified domain name.
    badauth - Authenticate failed.
    911 - There is a problem or scheduled maintenance on provider side
    badagent - The user agent sent bad request(like HTTP method/parameters is not permitted)
    badresolv - Failed to connect to because failed to resolve provider address.
    badconn - Failed to connect to provider because connection timeout.
  */
}
