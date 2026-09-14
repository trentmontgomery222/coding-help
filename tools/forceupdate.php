<?php
// Reproduces the force-update run: does WordPress' update_plugins transient
// end up carrying an entry for this plugin? That entry is the only thing
// Plugin_Upgrader::upgrade() uses to find the package.
require __DIR__.'/wp-stub.php';
function add_management_page(...$a){}
function get_transient($k){ return $GLOBALS['__t'][$k] ?? false; }
function set_transient($k,$v,$e=0){ $GLOBALS['__t'][$k]=$v; return true; }
function delete_transient($k){ unset($GLOBALS['__t'][$k]); return true; }
function get_site_transient($k){ return $GLOBALS['__st'][$k] ?? false; }
function set_site_transient($k,$v,$e=0){ $GLOBALS['__st'][$k]=$v; return true; }
function delete_site_transient($k){ unset($GLOBALS['__st'][$k]); return true; }
function add_query_arg($k,$v=null,$u=null){ return "?$k=$v"; }
function get_option($k,$d=false){ return array_key_exists($k,$GLOBALS['__o']??array()) ? $GLOBALS['__o'][$k] : $d; }
function update_option($k,$v,$a=null){ $GLOBALS['__o'][$k]=$v; return true; }
function delete_option($k){ unset($GLOBALS['__o'][$k]); return true; }
function is_admin(){ return true; } function is_user_logged_in(){ return true; }
function rest_url($p=''){ return 'https://x/'.$p; } function home_url($p='/'){ return 'https://x'.$p; }
function wp_generate_password($l=12,$s=true,$e=false){ return str_repeat('a',$l); }
function sanitize_title($x){ return strtolower(preg_replace('/[^A-Za-z0-9\-]/','-',(string)$x)); }
function esc_url_raw($x){ return $x; }
function has_filter($h,$cb=false){ if(empty($GLOBALS['__hooks'][$h])) return false; foreach($GLOBALS['__hooks'][$h] as $p=>$cbs) foreach($cbs as $c) if($c===$cb) return true; return false; }
function wp_remote_get($url,$a=array()){ return $GLOBALS['__http'][$url] ?? new WP_Error('http','no stub'); }
function wp_remote_retrieve_body($r){ return is_array($r)?($r['body']??''):''; }
function wp_remote_retrieve_response_code($r){ return is_array($r)?($r['code']??200):0; }
function is_wp_error($t){ return $t instanceof WP_Error; }
class WP_Error { public $m; function __construct($c='',$m=''){$this->m=$m;} function get_error_message(){return $this->m;} }
// WordPress rebuilding its own plugin-update transient.
function wp_update_plugins(){
  $t = new stdClass(); $t->response = array(); $t->no_update = array();
  $t = apply_filters('pre_set_site_transient_update_plugins', $t);
  set_site_transient('update_plugins', $t);
}
define('HOUR_IN_SECONDS',3600); define('MINUTE_IN_SECONDS',60); define('DAY_IN_SECONDS',86400);
$GLOBALS['__o']=array(); $GLOBALS['__st']=array(); $GLOBALS['__extra_post_types']=array();
require '/home/user/coding-help/wpcode-bb-values/wpcode-bb-values.php';

WPCodeBBV_Settings::save(array('update_enabled'=>1,'update_source'=>'url','update_manifest'=>'https://x/m.json'));
$GLOBALS['__http']['https://x/m.json'] = array('code'=>200,'body'=>json_encode(array(
  'version'=>'9.9.9','download_url'=>'https://x/wpcode-bb-values.zip')));

$u = new WPCodeBBV_Updater();
$u->register();                       // as a normal request would
echo "installed: ".WPCODEBBV_VERSION."   remote: 9.9.9 (newer, as in the report)\n\n";

// 1. What the Plugins screen sees (must stay empty - that was the request).
wp_update_plugins();
$t = get_site_transient('update_plugins');
echo "Plugins screen entry: ".(empty($t->response[WPCODEBBV_BASENAME]) ? "none (correct - no 'Update now' row)" : "PRESENT")."\n";

// 2. What the force-update run sees, after its temporary injection.
if ( ! has_filter('pre_set_site_transient_update_plugins', array($u,'inject_update')) ) {
    add_filter('pre_set_site_transient_update_plugins', array($u,'inject_update'));
}
delete_site_transient('update_plugins');
wp_update_plugins();
$t = get_site_transient('update_plugins');
$entry = $t->response[WPCODEBBV_BASENAME] ?? null;
echo "force-update entry:   ".($entry ? "present -> ".$entry->new_version." from ".$entry->package : "MISSING -> WordPress would say 'The plugin is at the latest version.' and FAIL")."\n";
