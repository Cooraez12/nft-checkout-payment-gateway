<?php
if (PHP_SAPI !== 'cli') { exit; } // Test code: never runs over the web.
/**
 * php -n test_order_received_page.php <plugin dir>
 *
 * BANK-1165: the "Almost done - check your email" order-received page
 * (block checkout and classic) and its Resend voucher button.
 * WordPress / WooCommerce stubbed, no network.
 */
$GLOBALS['plugin_dir'] = rtrim($argv[1] ?? dirname(__DIR__, 2), '/\\');
require __DIR__ . '/stubs.php';

class WC_Payment_Gateway_CC {
	public $id; public $icon; public $method_title; public $method_description; public $supports = []; public $settings = []; public $form_fields = []; public $title; public $description; public $enabled;
	public function init_settings() { $this->settings = rtnfch_get_settings(); }
	public function get_option($k, $d = null) { return $this->settings[$k] ?? ($this->form_fields[$k]['default'] ?? $d); }
	public function process_admin_options() {}
}
function is_checkout() { return true; }
function wc_clear_notices() {}
function wp_doing_ajax() { return false; }
function wp_create_nonce($a) { return 'nonce:' . $a; }
function wp_verify_nonce($n, $a) { return $n === 'nonce:' . $a ? 1 : false; }
function plugins_url($p, $f) { return 'https://shop.test/' . $p; }
function wc_price($a) { return '$' . $a; }
function wp_kses($s, $a = []) { return $s; }
if (!function_exists('mb_strlen')) { function mb_strlen($s) { return strlen((string) $s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $len = null) { return substr((string) $s, $start, $len); } }
function add_query_arg($args, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
require_once $GLOBALS['plugin_dir'] . '/includes/class-nft-checkout-payment-gateway.php';

class Voucher_Order extends WC_Order {
	public function __call($name, $args) { return str_starts_with($name, 'get_') ? '' : null; }
	public function get_items($type = '') { return []; }
	public function get_order_item_totals() { return []; }
}

$results = [];
function check($name, $cond, $detail = '') { global $results; $results[] = [$name, (bool) $cond, $detail]; echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " ($detail)" : '') . "\n"; }

const LINK  = 'https://app.rt.app/voucher/redeem/0b9f3c51-2f2e-4a43-9d38-5b7c1a1f0e11?signature=abc';
const LINK2 = 'https://app.rt.app/voucher/redeem/0b9f3c51-2f2e-4a43-9d38-5b7c1a1f0e11?signature=fresh';

function world() {
	$GLOBALS['options'] = [
		RTNFCH_SETTINGS_OPTION => ['enabled' => 'yes', 'sandbox' => 'no', 'skip_voucher_page' => 'no'],
		RTNFCH_ACCOUNTS_OPTION => [['title' => 'A', 'unique_id' => 'acc_a', 'live_public_key' => 'pk_live_A', 'live_secret_key' => 'sk_live_A', 'live_status' => 'active', 'priority' => 1]],
	];
	$GLOBALS['transients'] = []; $GLOBALS['http_log'] = []; $GLOBALS['hooks'] = [];
	$GLOBALS['sends'] = 0;
	$GLOBALS['http'] = function ($url, $args) {
		if (str_contains($url, '/voucher/send')) {
			$GLOBALS['sends']++;
			return ['response' => ['code' => 200], 'body' => json_encode(['status' => 'success', 'message' => 'Voucher sent', 'data' => ['voucher_id' => 'v1', 'reference' => json_decode($args['body'], true)['reference'] ?? '', 'purchase_url' => $GLOBALS['sends'] > 1 ? LINK2 : LINK, 'customer_email' => 'test1291@yopmail.com']])];
		}
		return ['response' => ['code' => 200], 'body' => json_encode(['status' => 'success'])];
	};
	$order = new Voucher_Order(123);
	$order->email = 'test1291@yopmail.com';
	$GLOBALS['orders'] = [123 => $order];
	$gw = new RTNFCH_GATEWAY();
	$GLOBALS['gateways'] = [RTNFCH_GATEWAY_ID => $gw];
	return [$order, $gw];
}
@WC()->countries = new class { public function get_country_calling_code($c) { return '+1'; } };
$calls = fn($part) => count(array_filter($GLOBALS['http_log'], fn($c) => str_contains($c[1], $part)));
$page  = function ($gw, $id = 123) { ob_start(); $gw->rtnfch_thankyou_payment_link_notice($id); return ob_get_clean(); };
$post  = function ($fields) {
	$_POST = $fields;
	try { rtnfch_handle_resend_voucher_post(); return null; } catch (RedirectException $e) { return $e->url; } finally { $_POST = []; }
};

// ---- Hook: one hook that both the classic template and the block theme fire.
[$order, $gw] = world();
$on = fn($hook) => count(array_filter($GLOBALS['hooks'][$hook] ?? [], fn($cb) => is_array($cb) && $cb[1] === 'rtnfch_thankyou_payment_link_notice'));
check('H1 page hooked on woocommerce_before_thankyou (classic thankyou.php and block Order confirmation status both fire it)', $on('woocommerce_before_thankyou') === 1);
check('H2 no longer under woocommerce_thankyou_{gateway} (block themes print that at the bottom)', $on('woocommerce_thankyou_' . RTNFCH_GATEWAY_ID) === 0);

// ---- Block checkout: process_payment then the order-received page.
$res  = $gw->process_payment(123);
$sent = count($GLOBALS["http_log"]);
$html = $page($gw);
check('P1 block checkout lands on order-received', str_contains($res['redirect'] ?? '', 'order-received'));
check('P2 title "Almost done – check your email"', str_contains($html, 'Almost done – check your email'));
check('P3 order number, email and total shown', str_contains($html, '<span>Order</span><span>#123</span>') && str_contains($html, '<span>Email</span><span>test1291@yopmail.com</span>') && str_contains($html, '<span>Total</span><span>$90.00</span>'));
check('P4 voucher instructions (DPRV text, check inbox) and CLICK HERE link', str_contains($html, 'DPRV') && str_contains($html, 'check the inbox at') && str_contains($html, 'href="' . LINK . '"'));
check('P5 Resend voucher form: POST to this page with order id, key and nonce', str_contains($html, 'method="post" action="https://shop.test/checkout/order-received/123/?key=wc_order_key_123"') && str_contains($html, 'name="order_id" value="123"') && str_contains($html, 'name="key" value="wc_order_key_123"') && str_contains($html, 'value="nonce:rtnfch_resend_voucher_123"') && str_contains($html, '>Resend voucher</button>'));
check('P6 showing the page calls RT for nothing', $calls('') === $sent);

$other = new Voucher_Order(124); $other->method = 'bacs'; $GLOBALS['orders'][124] = $other;
$other->update_meta_data('_rtnfch_voucher_emailed_at', time());
check('P7 other gateways: nothing printed', $page($gw, 124) === '');
$order->update_status('processing');
check('P8 paid order: nothing printed (WooCommerce page as usual)', $page($gw) === '');
$order->update_status('pending');

// ---- Classic checkout: in-place panel gets the same Resend form.
check('C1 classic in-place panel data carries the resend form', ($res['data']['order_received']['resend']['action'] ?? '') === $order->get_checkout_order_received_url() && ($res['data']['order_received']['resend']['nonce'] ?? '') === 'nonce:rtnfch_resend_voucher_123');
$js = file_get_contents($GLOBALS['plugin_dir'] . '/assets/js/rt-nft-checkout.js');
check('C2 classic panel title and resend button in JS', str_contains($js, "'Almost done – check your email'") && str_contains($js, "buildResendForm(details.resend)") && str_contains($js, "hidden('rtnfch_resend_nonce', resend.nonce)"));

// ---- Resend voucher.
$good = ['rtnfch_resend_voucher' => '1', 'order_id' => '123', 'key' => 'wc_order_key_123', 'rtnfch_resend_nonce' => 'nonce:rtnfch_resend_voucher_123'];
$before = $calls('/voucher/send');
$url = $post($good);
$last = json_decode(end($GLOBALS['http_log'])[2]['body'] ?? '{}', true);
check('R1 resend: back to the same page, "sent"', $url === $order->get_checkout_order_received_url() . '&rtnfch_resent=sent', (string) $url);
check('R2 resend: exactly one /voucher/send, same reference (RT hands back the same open voucher)', $calls('/voucher/send') === $before + 1 && ($last['reference'] ?? '') === '123-1');
check('R3 resend: no /dailylimit, no request-payment, nothing else', $calls('/dailylimit') === 1 && $calls('request-payment') === 0 && count($GLOBALS['http_log']) === 3);
check('R4 resend: no new reference or order, fresh link saved', $order->get_meta('_rtnfch_voucher_reference') === '123-1' && count($GLOBALS['orders']) === 2 && $order->get_meta('_rtnfch_voucher_purchase_url') === LINK2);
check('R5 resend: signed with the account that raised the voucher', (end($GLOBALS['http_log'])[2]['headers']['Authorization'] ?? '') === 'Bearer pk_live_A');

$n = count($GLOBALS['http_log']);
$url = $post($good);
check('L1 second click within 60 s: "wait", no call to RT', str_ends_with((string) $url, 'rtnfch_resent=wait') && count($GLOBALS['http_log']) === $n);
$GLOBALS['transients']['rtnfch_resend_123'][1] = time() - 1;
$post($good);
check('L2 after 60 s: allowed again', count($GLOBALS['http_log']) === $n + 1);

$GLOBALS['transients'] = []; $n = count($GLOBALS['http_log']);
$url = $post(['rtnfch_resend_nonce' => 'bad'] + $good);
check('N1 bad nonce: "failed", no call to RT', str_ends_with((string) $url, 'rtnfch_resent=failed') && count($GLOBALS['http_log']) === $n);
check('N2 wrong order key: ignored, no redirect, no call', $post(['key' => 'nope'] + $good) === null && count($GLOBALS['http_log']) === $n);
check('N3 other gateway order: ignored', $post(['order_id' => '124'] + $good) === null && count($GLOBALS['http_log']) === $n);
check('N4 no resend field: ignored', $post([]) === null);

$order->total = '120.00';
$url = $post($good);
check('E1 total changed since the voucher: "expired", no call (shopper checks out again)', str_ends_with((string) $url, 'rtnfch_resent=expired') && count($GLOBALS['http_log']) === $n);
$order->total = '90.00';
$order->update_status('processing');
check('E2 paid order: "expired", no call', str_ends_with((string) $post($good), 'rtnfch_resent=expired') && count($GLOBALS['http_log']) === $n);
$order->update_status('pending');

$_GET['rtnfch_resent'] = 'sent';
check('M1 result shown on the page', str_contains($page($gw), 'We sent the email again.'));
$_GET['rtnfch_resent'] = '<script>';
check('M2 unknown result: no message, nothing echoed', !str_contains($page($gw), 'script'));
unset($_GET['rtnfch_resent']);

check('X1 shop name and email escaped', !str_contains(rtnfch_order_received_html(['email' => '<b>x</b>@e.test', 'site_name' => '<script>x</script>', 'order_number' => '1']), '<script>') && !str_contains(rtnfch_order_received_html(['email' => '<b>x</b>@e.test']), '<b>x</b>'));

$failed = array_filter($results, fn($r) => !$r[1]);
echo "\n" . count($results) . " checks, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
