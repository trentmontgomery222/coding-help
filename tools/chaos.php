<?php
require __DIR__.'/wp-stub.php';
function add_management_page(...$a){}
function get_transient($k){ return $GLOBALS['__t'][$k] ?? false; }
function set_transient($k,$v,$e=0){ $GLOBALS['__t'][$k]=$v; return true; }
function delete_transient($k){ unset($GLOBALS['__t'][$k]); return true; }
function add_query_arg($k,$v=null,$u=null){ return "?$k=$v"; }
function get_option($k,$d=false){ return array_key_exists($k,$GLOBALS['__o']??array()) ? $GLOBALS['__o'][$k] : $d; }
function update_option($k,$v,$a=null){ $GLOBALS['__o'][$k]=$v; return true; }
function delete_option($k){ unset($GLOBALS['__o'][$k]); return true; }
function is_admin(){ return false; } function is_user_logged_in(){ return true; }
function rest_url($p=''){ return 'https://x/'.$p; } function home_url($p='/'){ return 'https://x'.$p; }
function wp_generate_password($l=12,$s=true,$e=false){ return str_repeat('a',$l); }
function sanitize_title($x){ return strtolower(preg_replace('/[^A-Za-z0-9\-]/','-',(string)$x)); }
function esc_url_raw($x){ return $x; }
define('HOUR_IN_SECONDS',3600); define('MINUTE_IN_SECONDS',60); define('DAY_IN_SECONDS',86400);
$GLOBALS['__o']=array(); $GLOBALS['__extra_post_types']=array('wpcode');
$js = "var configurations=[{key:'eventColor',value:'blue'}];";
$GLOBALS['__posts']['wpcode']=array((object)array('ID'=>31,'post_title'=>'Cal','post_content'=>$js));
require '/home/user/coding-help/wpcode-bb-values/wpcode-bb-values.php';
do_action('init');

$m = $GLOBALS['__modules']['WPCodeBBV_Module']['instance'];
$m->settings = new stdClass(); $m->settings->wpcode_id='31';
$module = $m;
$tpl = '/home/user/coding-help/wpcode-bb-values/modules/wpcode-values/includes/frontend.php';

function render_once($tpl){ global $module; ob_start(); include $tpl; return ob_get_clean(); }

$cases = array(
  'well-behaved snippet' => function(){ return '<p>ok</p>'; },
  'snippet that throws'  => function(){ throw new RuntimeException('snippet exploded'); },
  'snippet that fatals'  => function(){ nonexistent_function_xyz(); },
  'eats our buffer'      => function(){ @ob_end_clean(); return '<p>ate a buffer</p>'; },
  'leaves one open'      => function(){ ob_start(); echo 'stray'; return '<p>left one open</p>'; },
  'renders itself again' => function(){ global $module, $tpl2; return render_once($tpl2); },
  'emits a PHP notice'   => function(){ echo $GLOBALS['undefined_thing_here'] ?? ''; trigger_error('notice from snippet', E_USER_WARNING); return '<p>noisy</p>'; },
);
$GLOBALS['tpl2'] = $tpl;

foreach ($cases as $label => $snippet) {
  $GLOBALS['__snippet_cb'] = $snippet;
  $before = ob_get_level();
  $out = '';
  $fatal = 'none';
  try { $out = render_once($tpl); }
  catch (\Throwable $e) { $fatal = 'ESCAPED: '.get_class($e).': '.$e->getMessage(); }
  $after = ob_get_level();
  printf("  %-22s survived=%-5s buffers %d->%d %s out=%s\n",
    $label, var_export($fatal==='none',true), $before, $after,
    $before===$after ? 'balanced' : '*** UNBALANCED ***',
    strlen(trim($out)) ? '"'.substr(preg_replace('/\s+/',' ',trim($out)),0,28).'"' : '(empty)');
  if ($fatal!=='none') echo "      $fatal\n";
}
echo "\n  recursion registry left clean: ".var_export(empty($GLOBALS['wpcodebbv_rendering']),true)."\n";
