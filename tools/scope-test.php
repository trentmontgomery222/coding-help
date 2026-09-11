<?php
define('ABSPATH','/x/'); define('WPCODEBBV_VERSION','7');
function __($s,$d=null){return $s;} function esc_html($s){return $s;}
function apply_filters($h,$v){ return $v; }
$src = file_get_contents('/home/user/coding-help/wpcode-bb-values/includes/functions-core.php');
preg_match('/function wpcodebbv_scope_scripts.*?\n}\n/s', $src, $m); eval($m[0]);

$cases = array(
  'inline js'        => "<script>\nconst A=1;\nlet B=2;\nconsole.log('hi');\n</script>",
  'with attributes'  => '<script type="text/javascript" defer>const C=1;</script>',
  'external src'     => '<script src="https://x/app.js"></script>',
  'json-ld'          => '<script type="application/ld+json">{"a":1}</script>',
  'template block'   => '<script type="text/template"><div>{{x}}</div></script>',
  'es module'        => '<script type="module">const D=1;</script>',
  'empty'            => '<script></script>',
  'html around it'   => '<div class="x">keep</div><script>const E=1;</script><p>keep</p>',
);
foreach ($cases as $label=>$html) {
  $out = wpcodebbv_scope_scripts($html);
  printf("  %-17s %s\n", $label, ($out===$html ? 'left alone' : 'scoped') );
}
// the real thing
$real = file_get_contents('/tmp/claude-0/-home-user-coding-help/082331c1-6daa-54d2-a0e9-ce835ff3acad/scratchpad/calendar-like.html');
$out  = wpcodebbv_scope_scripts($real);
file_put_contents('/tmp/claude-0/-home-user-coding-help/082331c1-6daa-54d2-a0e9-ce835ff3acad/scratchpad/scoped.html', $out);
echo "\n  real snippet: ".( $out !== $real ? 'scoped' : 'NOT SCOPED')."; length ".strlen($real)." -> ".strlen($out)."\n";
echo "  non-script markup untouched: ".var_export(strpos($out,'renderCalendar(configurations);')!==false,true)."\n";
