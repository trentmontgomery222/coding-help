<?php
require __DIR__.'/wp-stub.php';
function add_management_page(...$a){}
function get_transient($k){ $v=$GLOBALS['__t'][$k]??false; return $v; }
function set_transient($k,$v,$e=0){ $GLOBALS['__t'][$k]=$v; return true; }
function delete_transient($k){ unset($GLOBALS['__t'][$k]); return true; }
function add_query_arg($a,$b=null,$c=null){ if(is_array($a)){ $u=$b; foreach($a as $k=>$v) $u.=(strpos($u,'?')===false?'?':'&')."$k=$v"; return $u; } $u=$c; return $u.(strpos($u,'?')===false?'?':'&')."$a=$b"; }
function get_option($k,$d=false){ return array_key_exists($k,$GLOBALS['__o']??array()) ? $GLOBALS['__o'][$k] : $d; }
function update_option($k,$v,$a=null){ $GLOBALS['__o'][$k]=$v; return true; }
function delete_option($k){ unset($GLOBALS['__o'][$k]); return true; }
function is_admin(){ return false; } function is_user_logged_in(){ return false; }
function rest_url($p=''){ return 'https://x/'.$p; } function home_url($p='/'){ return 'https://example.test'.$p; }
function wp_generate_password($l=12,$s=true,$e=false){ return str_repeat('a',$l); }
function sanitize_title($x){ return strtolower(preg_replace('/[^A-Za-z0-9\-]/','-',(string)$x)); }
function esc_url_raw($x){ return $x; }
function wp_hash_password($p){ return 'HASH:'.md5($p); }
function wp_check_password($p,$h){ return hash_equals($h, 'HASH:'.md5($p)); }
function wp_strip_all_tags($s){ return strip_tags((string)$s); }
function size_format($b,$d=0){ return round($b/1048576,1).' MB'; }
function get_bloginfo($k=''){ return $k==='version' ? '6.7' : 'Test Site'; }
function status_header($c){ $GLOBALS['__status']=$c; }
function nocache_headers(){}
function esc_textarea($s){ return htmlspecialchars((string)$s); }
define('HOUR_IN_SECONDS',3600); define('MINUTE_IN_SECONDS',60); define('DAY_IN_SECONDS',86400);
$GLOBALS['__o']=array(); $GLOBALS['__t']=array(); $GLOBALS['__extra_post_types']=array();
require '/home/user/coding-help/wpcode-bb-values/wpcode-bb-values.php';

echo "=== IP RULES ===\n";
$cases = array(
  array('167.102.110.1', '167.102.110.1',              true,  'the default, exact match'),
  array('167.102.110.2', '167.102.110.1',              false, 'a neighbour is not allowed'),
  array('196.168.4.9',   "167.102.110.1\n196.168.",    true,  'prefix rule'),
  array('196.169.4.9',   "167.102.110.1\n196.168.",    false, 'prefix that does not match'),
  array('203.0.113.7',   "196.168.\n!203.0.113.7",     false, 'explicit deny'),
  array('196.168.0.5',   "196.168.\n!196.168.0.",      false, 'deny beats a matching allow'),
  array('1.2.3.4',       '',                            false, 'empty rules allow nobody'),
  array('',              '167.102.110.1',              false, 'no address at all'),
  array('10.0.0.1',      "# a comment\n10.",           true,  'comments ignored'),
);
foreach ($cases as $c) {
  $got = WPCodeBBV_Panel::ip_allowed($c[0], $c[1]);
  printf("  %-6s %-16s %-28s %s\n", $got===$c[2]?'ok':'FAIL', $c[0]===''?'(none)':$c[0], str_replace("\n",' | ',$c[1]), $c[3]);
}

echo "\n=== RATE LIMIT ===\n";
WPCodeBBV_Settings::save(array('panel_rate_limit'=>3,'panel_rate_window'=>300));
for ($i=1;$i<=5;$i++){ $r = WPCodeBBV_Panel::rate_check('9.9.9.9'); printf("  hit %d: %s\n", $i, $r['ok']?'allowed':'BLOCKED (429)'); }

echo "\n=== PASSWORD ===\n";
echo "  no password set -> writes refused: ".var_export(!WPCodeBBV_Panel::password_ok('anything'),true)."\n";
WPCodeBBV_Settings::save(array('panel_password_hash'=>wp_hash_password('correct horse')));
echo "  right password: ".var_export(WPCodeBBV_Panel::password_ok('correct horse'),true)."\n";
echo "  wrong password: ".var_export(WPCodeBBV_Panel::password_ok('nope'),true)."\n";
echo "  blank password: ".var_export(WPCodeBBV_Panel::password_ok(''),true)."\n";

echo "\n=== ONCE A DAY ===\n";
$e = WPCodeBBV_Panel::edit_allowed(); echo "  first change allowed: ".var_export($e['ok'],true)."\n";
update_option(WPCodeBBV_Panel::EDIT_OPT, time());
$e = WPCodeBBV_Panel::edit_allowed(); echo "  straight after: ".var_export($e['ok'],true)." (next at ".gmdate('H:i',$e['next'])."Z)\n";
update_option(WPCodeBBV_Panel::EDIT_OPT, time()-86401);
$e = WPCodeBBV_Panel::edit_allowed(); echo "  a day later: ".var_export($e['ok'],true)."\n";

echo "\n=== DIAGNOSTICS ===\n";
wpcodebbv_log('something went wrong in a snippet');
foreach (WPCodeBBV_Panel::diagnostics() as $sec=>$rows) { echo "  $sec: ".implode(', ', array_keys($rows))."\n"; }
$iss = get_option(WPCodeBBV_Panel::LOG_OPT, array());
echo "  recent problems recorded: ".count($iss)." (\"".$iss[0]['msg']."\")\n";

echo "\n=== UPDATE REQUEST URL ===\n";
WPCodeBBV_Settings::save(array('update_manifest'=>'https://acps.example/manifest.json','update_manifest_key'=>'MYKEY','update_source'=>'url'));
$GLOBALS['__http']=array();
$r = new ReflectionMethod('WPCodeBBV_Updater','fetch_from_url'); $r->setAccessible(true);
function wp_remote_get($url,$a=array()){ $GLOBALS['__asked']=$url; return new WP_Error('x','stub'); }
function wp_remote_retrieve_body($r){ return ''; } function wp_remote_retrieve_response_code($r){ return 0; }
function is_wp_error($t){ return $t instanceof WP_Error; }
class WP_Error { function __construct($c='',$m=''){} function get_error_message(){ return 'stub'; } }
$r->invoke(new WPCodeBBV_Updater());
echo "  ".$GLOBALS['__asked']."\n";
