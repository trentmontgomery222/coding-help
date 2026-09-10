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
$js = file_get_contents(dirname(__DIR__).'/sitewide.js');
$GLOBALS['__posts']['wpcode']=array((object)array('ID'=>9,'post_title'=>'Cal','post_content'=>$js));
$GLOBALS['__snippet_js']=$js;
require '/home/user/coding-help/wpcode-bb-values/wpcode-bb-values.php';
do_action('init');

$form = $GLOBALS['__modules']['WPCodeBBV_Module']['form'];
echo "=== TABS ===\n";
foreach ($form as $slug=>$tab) printf("  %-9s \"%s\": %s\n", $slug, $tab['title'], implode(', ', array_keys($tab['sections'])));

// Where each kind of setting got its control.
echo "\n=== CONTROLS PER SETTING ===\n";
$page=array(); $wide=array();
foreach ($form['general']['sections'] as $sec) foreach ($sec['fields'] as $k=>$f) $page[$k]=1;
if (isset($form['sitewide'])) foreach ($form['sitewide']['sections'] as $sec) foreach ($sec['fields'] as $k=>$f) $wide[$k]=1;
foreach (wpcodebbv_snippets()[9]['settings'] as $path=>$l) {
  $k = wpcodebbv_field_key(9,$path);
  printf("  %-32s %-9s page=%-5s sitewide=%s\n", $path, !empty($l['php'])?'php':(!empty($l['global'])?'siteWide':'per-page'),
    var_export(isset($page[$k]),true), var_export(isset($wide[$k.'__wide']),true));
}

// Opening the module is a fresh request: Beaver Builder rebuilds the form,
// so the boxes are filled from whatever is in force right then.
$open = function() {
  $live = wpcodebbv_form();
  $m = new WPCodeBBV_Module(); $m->settings = new stdClass();
  foreach ($live as $tab) foreach ($tab['sections'] as $sec) foreach ($sec['fields'] as $k=>$f) $m->settings->{$k}=$f['default'];
  $m->settings->wpcode_id='9';   // chosen in the picker, after the defaults
  return $m;
};
$save = function($m){ $m->settings = $m->update($m->settings); return $m; };
$K = function($p){ return wpcodebbv_field_key(9,$p); };

echo "\n=== THREE LAYERS ===\n";
$A = $save($open()); $B = $save($open());
echo "a) two fresh pages                 A:".json_encode($A->get_overrides())." B:".json_encode($B->get_overrides())."\n";

// Site-wide edit from page A.
$A2 = $open(); $A2->settings->{$K('noSchoolEvent.badgeText').'__wide'} = 'CLOSED';
$save($A2);
echo "b) A sets site-wide badge=CLOSED   store:".json_encode(wpcodebbv_globals())."\n";
echo "   B (untouched) now renders       ".json_encode($save($open())->get_overrides())."\n";

// Page B overrides it for itself only.
$B2 = $open();
echo "   B's page box opens showing      ".json_encode($B2->settings->{$K('noSchoolEvent.badgeText')})."\n";
$B2->settings->{$K('noSchoolEvent.badgeText')} = 'SNOW DAY';
$save($B2);
echo "c) B overrides just its own page   B:".json_encode($B2->get_overrides())."\n";
echo "   store unchanged                 ".json_encode(wpcodebbv_globals())."\n";
echo "   A still gets the site-wide one  ".json_encode($save($open())->get_overrides())."\n";

// Change the site-wide value again; B keeps its override, A follows.
$A3 = $open(); $A3->settings->{$K('noSchoolEvent.badgeText').'__wide'} = 'NO SCHOOL';
$save($A3);
echo "d) site-wide changed to NO SCHOOL  A:".json_encode($save($open())->get_overrides())."\n";
echo "   B keeps its page override       B:".json_encode($B2->get_overrides())."\n";

echo "\n=== RESET ===\n";
$B3 = $open(); foreach ((array)$B2->settings as $k=>$v) $B3->settings->{$k}=$v;
$B3->settings->reset_action = 'page'; $save($B3);
echo "e) B resets this page              B:".json_encode($B3->get_overrides())." (back to the site-wide value)\n";
echo "   reset_action cleared after save ".json_encode($B3->settings->reset_action)."\n";
$A4 = $open(); $A4->settings->reset_action='wide'; $save($A4);
echo "f) reset site-wide                 store:".json_encode(wpcodebbv_globals())."  A:".json_encode($save($open())->get_overrides())."\n";
