<?php
// Simulates one full WordPress request against a (possibly damaged) copy
// of the plugin: load it, fire the hooks a page load fires, render a
// module on the front end, and open the admin help screen.
require __DIR__.'/wp-stub.php';
function add_management_page($pt,$mt,$cap,$slug,$cb=null){ $GLOBALS['__menus'][]=array('cb'=>$cb); }
function get_transient($k){ return $GLOBALS['__t'][$k] ?? false; }
function set_transient($k,$v,$e=0){ $GLOBALS['__t'][$k]=$v; return true; }
function delete_transient($k){ unset($GLOBALS['__t'][$k]); return true; }
function add_query_arg($k,$v=null,$u=null){ return "?$k=$v"; }
function get_option($k,$d=false){ return array_key_exists($k,$GLOBALS['__o']??array()) ? $GLOBALS['__o'][$k] : $d; }
function update_option($k,$v,$a=null){ $GLOBALS['__o'][$k]=$v; return true; }
function delete_option($k){ unset($GLOBALS['__o'][$k]); return true; }
function is_admin(){ return true; }
function is_user_logged_in(){ return true; }
function rest_url($p=''){ return 'https://x/'.$p; } function home_url($p='/'){ return 'https://x'.$p; }
function wp_generate_password($l=12,$s=true,$e=false){ return str_repeat('a',$l); }
function sanitize_title($x){ return strtolower(preg_replace('/[^A-Za-z0-9\-]/','-',(string)$x)); }
function esc_url_raw($x){ return $x; }
function wp_nonce_url($u,$a){ return $u; } function checked($a,$b,$e=true){}
function settings_errors(...$a){} function add_settings_error(...$a){} function esc_textarea($x){ return $x; }
define('HOUR_IN_SECONDS',3600); define('MINUTE_IN_SECONDS',60); define('DAY_IN_SECONDS',86400);
$GLOBALS['__o']=array(); $GLOBALS['__extra_post_types']=array('wpcode');
$dir = $argv[1];
$js  = file_get_contents(dirname(__DIR__).'/snippet.js');
$GLOBALS['__posts']['wpcode']=array((object)array('ID'=>31,'post_title'=>'ACPS Calendar','post_content'=>$js));
$GLOBALS['__snippet_js']=$js;

$main = $dir.'/wpcode-bb-values.php';
if ( ! file_exists($main) ) { echo "MAIN FILE GONE (WordPress would simply not load the plugin)\n"; exit(0); }
require $main;

do_action('plugins_loaded');
do_action('init');
do_action('admin_menu');
ob_start(); do_action('admin_notices'); $notices = trim(strip_tags(ob_get_clean()));

// Front end: render a module.
$front = 'no module registered';
if ( isset($GLOBALS['__modules']['WPCodeBBV_Module']) ) {
  $m = $GLOBALS['__modules']['WPCodeBBV_Module']['instance'];
  $m->settings = new stdClass(); $m->settings->wpcode_id='31';
  $module = $m;
  ob_start();
  $tpl = $dir.'/modules/wpcode-values/includes/frontend.php';
  if ( file_exists($tpl) ) { include $tpl; } else { echo '(template missing)'; }
  $front = trim(ob_get_clean());
}

// Admin: open the help screen.
$admin = 'no menu';
foreach ($GLOBALS['__menus'] as $mm) { if (is_callable($mm['cb'])) { ob_start(); call_user_func($mm['cb']); $admin = strlen(trim(ob_get_clean())).' bytes'; } }

echo "SURVIVED | front: ".(strlen($front)>40 ? substr(preg_replace('/\s+/',' ',$front),0,40).'…' : $front)
   ." | admin: $admin | notice: ".($notices ? substr(preg_replace('/\s+/',' ',$notices),0,60).'…' : 'none')."\n";
