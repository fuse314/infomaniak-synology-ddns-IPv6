#!/usr/bin/php -d open_basedir=/usr/syno/bin/ddns
<?php
/*
 * script by fuse314: https://github.com/fuse314/infomaniak-synology-ddns-IPv6
 * credit: https://github.com/schmidhorst/synology-ddns-IPv6
 * Parameters: 1=account (username, usually domain name), 2=pwd (use single quotes) 3=hostname, 4=ip (IPv4)
 * This script checks the current A and AAAA records for "hostname" and only sends an update request if the IP address(es) have changed.
*/

// set this to true for debug output. - execute the script over SSH directly to see the debug output.
$debug = false;

$msg = '';
$msg .= $debug ? date('Y-m-d H:i:s') . " Start $argv[0]\n" : '';

if ($argc !== 5) {
  $msg .= "Error: Bad param count $argc instead of 5!\n $argv[0] <account> '<PW>' <host> <ipv4>\n";
  exit($msg);
}
$account = (string)$argv[1];
$pwd = (string)$argv[2];
$hostname = (string)$argv[3];
$ipv4 = (string)$argv[4];

// check that the hostname contains '.'
if (strpos($hostname, '.') === false) {
  $msg .= "Error: Badparam hostname must contain a dot .: '$hostname'\n";
  exit($msg);
}

if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
  $msg .= "Error Badparam: IP is not a valid IPv4 address: '$ipv4'\n";
  exit($msg);
}

// use api to get public ipv6 address, choose your preferred api provider...
//$ipv6 = getIpv6AddressIpify();
//$ipv6 = getIpv6AddressSeeip();
$ipv6 = getIpv6AddressGoogle();
$msg .= $debug ? "Public ipv6 from api: $ipv6\n" : '';

if (!filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE)) {
  $msg .= $debug ? "$ipv6 is not a valid global IPv6 address, ignoring.\n" : '';
  $ipv6 = '';
}

$dnsipv4 = getDnsRecord($hostname, true);
$dnsipv6 = getDnsRecord($hostname, false);

$msg .= $debug ? "IPs from dns records: $dnsipv4 / $dnsipv6\n" : '';
// use inet_pton() to compare ip addresses due to possible shorthand ipv6 addresses (2001::33:ff2a is the same as 2001:0:0:0:0:0:33:ff2a)
if (inet_pton($ipv4) == inet_pton($dnsipv4) && ($ipv6 == '' || inet_pton($ipv6) == inet_pton($dnsipv6))) {
  $msg .= $debug ? "IP addresses unchanged, no need to update.\n" : '';
  echo "nochg $ipv4 $ipv6\n$msg";
  exit;
}

$msg .= $debug ? "IP changed: v4: param=$ipv4 DNS=$dnsipv4 v6: param=$ipv6 DNS=$dnsipv6\n" : '';
$msg .= $debug ? "Preparing request(s)...\n" : '';

// https://www.infomaniak.com/de/support/faq/2357/dyndns-einrichten-eines-ddns-mit-einer-bei-infomaniak-verwalteten-domain

$url = "https://infomaniak.com/nic/update?hostname=$hostname&myip=";

$result = '';
if (inet_pton($ipv4) !== inet_pton($dnsipv4)) {
  $v4url = $url . $ipv4;
  $msg .= $debug ? "Update ipv4. Use url: $v4url\n" : '';
  $result = updateHost($v4url, $account, $pwd) . "\n";
}
if ($ipv6 !== '' && inet_pton($ipv6) !== inet_pton($dnsipv6)) {
  $v6url = $url . $ipv6;
  $msg .= $debug ? "Update ipv6. Use url: $v6url\n" : '';
  $result .= updateHost($v6url, $account, $pwd);
}

echo "$result\n$msg";
if ((strpos($result, "good") !== 0) && (strpos($result, "nochg") !== 0)) {
  syslog(LOG_ERR, "$argv[0]: $result");
}

/*
 * Init curl request object with common options
 */
function initCurl($url)
{
  $req = curl_init();
  curl_setopt($req, CURLOPT_URL, $url);
  curl_setopt($req, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($req, CURLOPT_TIMEOUT, 30);
  curl_setopt($req, CURLOPT_VERBOSE, false);
  curl_setopt($req, CURLOPT_RETURNTRANSFER, true);
  return $req;
}

/*
 * Get content from url using ipv6
 */
function getContentV6($url)
{
  try {
    $req = initCurl($url);
    curl_setopt($req, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V6);
    $result = curl_exec($req);
    curl_close($req);
    return $result;
  }
  catch(Exception $ex) {
    return '';
  }
}

/*
 * Get ipv6 address from ipify.org
 */
function getIpv6AddressIpify()
{
  return getContentV6("https://api64.ipify.org");
}

/*
 * Get ipv6 address from google
 */
function getIpv6AddressGoogle()
{
  return getContentV6("https://domains.google.com/checkip");
}

/*
 * Get ipv6 address from seeip
 */
function getIpv6AddressSeeip()
{
  return getContentV6("https://ipv6.seeip.org");
}

/*
 * Send update Request for hostname.
 * Only one ip address can be updated at a time.
 */
function updateHost($url, $user, $pw)
{
  $req = initCurl($url);
  // fake browser user agent
  $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36';
  curl_setopt($req, CURLOPT_USERAGENT, $agent);
  curl_setopt($req, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  curl_setopt($req, CURLOPT_USERPWD, "$user:$pw");
  $res = curl_exec($req);
  curl_close($req);
  return $res;
}

/*
 * Get DNS Record for hostname
 * Either A (ipv4) or AAAA (ipv6) records are queried.
 */
function getDnsRecord($hostname, $v4)
{
  $result = dns_get_record($hostname, $v4 ? DNS_A : DNS_AAAA);
  if ($result !== false && $result !== null && isset($result[0])) {
    $prop = $v4 ? "ip" : "ipv6";
    return $result[0][$prop];
  } else {
    return '';
  }
}
