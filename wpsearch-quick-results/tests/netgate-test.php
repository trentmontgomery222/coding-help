<?php
/**
 * The IP gate — the code between the open internet and a settings editor.
 * Tested exhaustively because a wrong answer here is a security hole, not a
 * cosmetic bug.
 *
 *   php wpsearch-quick-results/tests/netgate-test.php
 */

define( 'ABSPATH', __DIR__ );
require_once __DIR__ . '/../includes/class-wpsqr-netgate.php';

$pass = 0; $fail = 0;
function check( $name, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) { $pass++; echo "  ok   {$name}\n"; }
	else { $fail++; echo "  FAIL {$name}\n       expected " . var_export( $expected, true ) . "\n       actual   " . var_export( $actual, true ) . "\n"; }
}

function allow( $ip, $rules ) { return WPSQR_NetGate::allows( $ip, $rules ); }
function rules( $text ) { return WPSQR_NetGate::parse_rules( $text ); }

$DEFAULT = array( array( 'action' => 'allow', 'value' => '167.102.110.1' ) );

echo "\nthe default: one address, everything else denied\n";
check( 'the allowed address gets in', allow( '167.102.110.1', $DEFAULT ), true );
check( 'a neighbour does not', allow( '167.102.110.2', $DEFAULT ), false );
check( 'an unrelated address does not', allow( '8.8.8.8', $DEFAULT ), false );
check( 'no rules at all denies everyone', allow( '167.102.110.1', array() ), false );

echo "\nfail closed on junk\n";
check( 'an empty ip is denied', allow( '', $DEFAULT ), false );
check( 'a non-ip is denied', allow( 'not-an-ip', $DEFAULT ), false );
check( 'a partial address as the client ip is denied', allow( '167.102', $DEFAULT ), false );

echo "\nprefix matching\n";
$PREFIX = array( array( 'action' => 'allow', 'value' => '196.168.' ) );
check( '196.168. lets a 196.168 address in', allow( '196.168.4.20', $PREFIX ), true );
check( 'and keeps 196.169 out', allow( '196.169.4.20', $PREFIX ), false );
check( 'a bare 196.168 works the same', allow( '196.168.4.20', array( array( 'action' => 'allow', 'value' => '196.168' ) ) ), true );
check( 'a prefix cannot leak across an octet boundary',
	allow( '196.1689.0.1', array( array( 'action' => 'allow', 'value' => '196.168' ) ) ), false );
check( 'a single-octet prefix is broad but bounded', allow( '10.1.2.3', array( array( 'action' => 'allow', 'value' => '10.' ) ) ), true );
check( 'and 100.x is not inside 10.', allow( '100.1.2.3', array( array( 'action' => 'allow', 'value' => '10.' ) ) ), false );

echo "\nallow a range, deny one address inside it\n";
$MIXED = array(
	array( 'action' => 'allow', 'value' => '10.0.' ),
	array( 'action' => 'deny',  'value' => '10.0.0.5' ),
);
check( 'the range is allowed', allow( '10.0.9.9', $MIXED ), true );
check( 'the specific denied address loses, despite the broad allow', allow( '10.0.0.5', $MIXED ), false );
check( 'a more specific allow beats a broad deny',
	allow( '10.0.0.5', array(
		array( 'action' => 'deny',  'value' => '10.' ),
		array( 'action' => 'allow', 'value' => '10.0.0.5' ),
	) ), true );

echo "\ndeny wins a tie\n";
check( 'same specificity, deny wins',
	allow( '10.0.0.5', array(
		array( 'action' => 'allow', 'value' => '10.0.0.5' ),
		array( 'action' => 'deny',  'value' => '10.0.0.5' ),
	) ), false );
check( 'order does not change that',
	allow( '10.0.0.5', array(
		array( 'action' => 'deny',  'value' => '10.0.0.5' ),
		array( 'action' => 'allow', 'value' => '10.0.0.5' ),
	) ), false );

echo "\nCIDR\n";
$CIDR = array( array( 'action' => 'allow', 'value' => '10.0.0.0/8' ) );
check( 'inside the block', allow( '10.255.1.1', $CIDR ), true );
check( 'outside it', allow( '11.0.0.1', $CIDR ), false );
check( '/24 is tighter', allow( '192.168.1.50', array( array( 'action' => 'allow', 'value' => '192.168.1.0/24' ) ) ), true );
check( 'and rejects the next subnet', allow( '192.168.2.50', array( array( 'action' => 'allow', 'value' => '192.168.1.0/24' ) ) ), false );
check( 'a /32 is a single host', allow( '5.5.5.5', array( array( 'action' => 'allow', 'value' => '5.5.5.5/32' ) ) ), true );

echo "\nIPv6\n";
check( 'a v6 loopback normalises and matches itself',
	allow( '0:0:0:0:0:0:0:1', array( array( 'action' => 'allow', 'value' => '::1' ) ) ), true );
check( 'a v6 address is not matched by a v4 rule', allow( '::1', $DEFAULT ), false );

echo "\nspecificity beats order\n";
check( 'a full-address deny beats an earlier broad allow whatever the order',
	allow( '167.102.110.1', array(
		array( 'action' => 'allow', 'value' => '167.' ),
		array( 'action' => 'deny',  'value' => '167.102.110.1' ),
	) ), false );

echo "\nparsing the textarea\n";
check( 'allow keyword', rules( "allow 167.102.110.1" ), array( array( 'action' => 'allow', 'value' => '167.102.110.1' ) ) );
check( 'deny keyword', rules( "deny 10." ), array( array( 'action' => 'deny', 'value' => '10.' ) ) );
check( 'a bare value defaults to allow', rules( "167.102.110.1" ), array( array( 'action' => 'allow', 'value' => '167.102.110.1' ) ) );
check( 'comments and blank lines are ignored', rules( "# my office\n\nallow 10.0.0.1  # the server\n" ), array( array( 'action' => 'allow', 'value' => '10.0.0.1' ) ) );
check( 'case-insensitive keywords', rules( "DENY 8.8.8.8" ), array( array( 'action' => 'deny', 'value' => '8.8.8.8' ) ) );
check( 'the default address round-trips',
	allow( '167.102.110.1', rules( "allow 167.102.110.1" ) ), true );

echo "\nclient ip never trusts a spoofable header unless told to\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '167.102.110.1';
check( 'X-Forwarded-For is ignored by default', WPSQR_NetGate::client_ip(), '203.0.113.9' );
check( 'and honoured only when the site opts in', WPSQR_NetGate::client_ip( true ), '167.102.110.1' );

echo "\n{$pass} passed, {$fail} failed\n\n";
exit( $fail ? 1 : 0 );
