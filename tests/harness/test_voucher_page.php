<?php
if (PHP_SAPI !== 'cli') { exit; } // Test code: never runs over the web.
/**
 * php -n test_voucher_page.php <plugin dir>
 *
 * The DPRV voucher page after checkout: its wording, the CLICK HERE link, and
 * the "Skip voucher page" setting. WordPress / WooCommerce stubbed, no network.
 */
$GLOBALS['plugin_dir'] = rtrim($argv[1] ?? dirname(__DIR__, 2), '/\\');
require __DIR__ . '/stubs.php';

// Just enough of WooCommerce's gateway base class to run process_payment().
class WC_Payment_Gateway_CC {
	public $id; public $icon; public $method_title; public $method_description; public $supports = []; public $settings = []; public $form_fields = []; public $title; public $description; public $enabled;
	public function init_settings() { $this->settings = rtnfch_get_settings(); }
	public function get_option($k, $d = null) { return $this->settings[$k] ?? ($this->form_fields[$k]['default'] ?? $d); }
	public function process_admin_options() {}
}
function is_checkout() { return true; }
function wc_clear_notices() {}
function wp_doing_ajax() { return false; }
function wp_create_nonce($a) { return 'nonce'; }
function plugins_url($p, $f) { return 'https://shop.test/' . $p; }
function wc_price($a) { return '$' . $a; }
function wp_kses($s, $a = []) { return $s; }
// WordPress ships its own mb_* fallbacks; CI runs PHP without mbstring.
if (!function_exists('mb_strlen')) { function mb_strlen($s) { return strlen((string) $s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $len = null) { return substr((string) $s, $start, $len); } }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
require_once $GLOBALS['plugin_dir'] . '/includes/class-nft-checkout-payment-gateway.php';

// Billing getters the voucher request reads; '' unless a test sets them.
class Voucher_Order extends WC_Order {
	public function __call($name, $args) { return str_starts_with($name, 'get_') ? '' : null; }
	public function get_items($type = '') { return []; }
	public function get_order_item_totals() { return []; }
}

$results = [];
function check($name, $cond, $detail = '') { global $results; $results[] = [$name, (bool) $cond, $detail]; echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " ($detail)" : '') . "\n"; }

// New references name the shop: "<shop>-<order>-<attempt>".
define('T', rtnfch_store_ref() . '-');
const LINK = 'https://app.rt.app/voucher/redeem/0b9f3c51-2f2e-4a43-9d38-5b7c1a1f0e11?signature=abc';

function world($skip) {
	$GLOBALS['options'] = [
		RTNFCH_SETTINGS_OPTION => ['enabled' => 'yes', 'sandbox' => 'no', 'skip_voucher_page' => $skip],
		RTNFCH_ACCOUNTS_OPTION => [['title' => 'A', 'unique_id' => 'acc_a', 'live_public_key' => 'pk_live_A', 'live_secret_key' => 'sk_live_A', 'live_status' => 'active', 'priority' => 1]],
	];
	$GLOBALS['transients'] = []; $GLOBALS['http_log'] = [];
	$GLOBALS['http'] = function ($url, $args) {
		if (str_contains($url, '/voucher/send')) {
			return ['response' => ['code' => 200], 'body' => json_encode(['status' => 'success', 'message' => 'Voucher sent', 'data' => ['voucher_id' => 'v1', 'reference' => json_decode($args['body'], true)['reference'] ?? '', 'purchase_url' => LINK, 'customer_email' => 'test1291@yopmail.com']])];
		}
		return ['response' => ['code' => 200], 'body' => json_encode(['status' => 'success'])];
	};
	$order = new Voucher_Order(123);
	$order->email = 'test1291@yopmail.com';
	$GLOBALS['orders'] = [123 => $order];
	return $order;
}
@WC()->countries = new class { public function get_country_calling_code($c) { return '+1'; } };
$sends = fn() => count(array_filter($GLOBALS['http_log'], fn($c) => str_contains($c[1], '/voucher/send')));

// Copy: exact wording, merchant and email filled in and escaped.
$html = rtnfch_voucher_notice_html('Form & Function', 'test1291@yopmail.com', LINK);
foreach ([
	'As part of our secure checkout process, you will receive an email with a secure link to complete this transaction via the credit/debit card Digital Payment Redemption Voucher (DPRV) process along with an NFT confirmation. The voucher link will allow you to complete payment via a credit or debit card for your order.',
	"Didn&#039;t receive or prefer not to wait for the email?",
	'>CLICK HERE</a>',
	'Independent voucher &amp; payment partner</strong> — Your voucher and payment are completed via a separate, independent processing partner. That partner is neither hosted on, embedded in, nor otherwise associated with Form &amp; Function.',
	'No further action is needed here - check the inbox at <strong>test1291@yopmail.com</strong> for the DPRV email.',
] as $i => $needle) {
	check('V1.' . ($i + 1) . ' voucher text', str_contains($html, $needle), substr($needle, 0, 40));
}
check('V2 CLICK HERE is a real new-tab link to the email\'s own voucher link', str_contains($html, 'href="' . LINK . '" target="_blank" rel="noopener noreferrer"'));
check('V3 merchant / email are not hard-coded', str_contains(rtnfch_voucher_notice_html('Shop B', 'b@example.test', LINK), 'associated with Shop B.') && str_contains(rtnfch_voucher_notice_html('Shop B', 'b@example.test', LINK), 'b@example.test'));
check('V4 no link: no button', !str_contains(rtnfch_voucher_notice_html('S', 'e@x.test', ''), 'CLICK HERE'));
check('V5 an unsigned voucher link is never shown', rtnfch_voucher_link('https://app.rt.app/voucher/redeem/x') === '' && rtnfch_voucher_link(LINK) === LINK);
check('V7 only https links on RT\'s own host are shown', rtnfch_voucher_link(str_replace('https:', 'http:', LINK)) === '' && rtnfch_voucher_link(str_replace('app.rt.app', 'evil.test', LINK)) === '' && rtnfch_voucher_link(str_replace('app.rt.app', 'app.rt.app.evil.test', LINK)) === '' && rtnfch_voucher_link('javascript:alert(1)') === '');
check('V6 a script in the shop name is escaped', !str_contains(rtnfch_voucher_notice_html('<script>x</script>', 'e@x.test', LINK), '<script>'));

// Setting: default off (voucher page), on sends the shopper straight to the voucher link.
$gw = new RTNFCH_GATEWAY();
$fields = $gw->rtnfch_get_form_fields();
check('S1 "Skip voucher page" setting exists, off by default', ($fields['skip_voucher_page']['type'] ?? '') === 'checkbox' && ($fields['skip_voucher_page']['default'] ?? '') === 'no');

$order = world('no');
$res = (new RTNFCH_GATEWAY())->process_payment(123);
check('S2 off: voucher page (order-received + CLICK HERE link)', ($res['result'] ?? '') === 'success' && str_contains($res['redirect'] ?? '', 'order-received') && (($res['data']['order_received']['purchase_url'] ?? '') === LINK) && ($res['data']['order_received']['site_name'] ?? '') !== '', $res['redirect'] ?? json_encode($res));
check('S3 off: one voucher email asked for', $sends() === 1);

$order = world('yes');
$res = (new RTNFCH_GATEWAY())->process_payment(123);
check('S4 on: straight to the voucher link, no voucher page', ($res['result'] ?? '') === 'success' && ($res['redirect'] ?? '') === LINK && !isset($res['data']['order_received']), $res['redirect'] ?? json_encode($res));
check('S5 on: the voucher email is still asked for, once', $sends() === 1);
check('S6 on: order stays pending with the same voucher link saved', $order->get_status() === 'pending' && $order->get_meta('_rtnfch_voucher_purchase_url') === LINK);
check('S7 on: no extra call to RT (one voucher request, nothing else)', count($GLOBALS['http_log']) === count(array_filter($GLOBALS['http_log'], fn($c) => str_contains($c[1], '/voucher/send') || str_contains($c[1], '/dailylimit'))));

// Placing the same order again (refresh, back button, second click).
$refs = fn() => array_values(array_map(fn($c) => json_decode($c[2]['body'], true)['reference'] ?? '', array_filter($GLOBALS['http_log'], fn($c) => str_contains($c[1], '/voucher/send'))));
$notes = fn($o) => count(array_filter($o->get_notes(), fn($n) => str_contains($n, 'RT emailed the customer')));

$order = world('no');
$first = (new RTNFCH_GATEWAY())->process_payment(123);
$calls = count($GLOBALS['http_log']);
$again = (new RTNFCH_GATEWAY())->process_payment(123);
check('R1 same total again: same voucher page and link', ($again['result'] ?? '') === 'success' && ($again['data']['order_received']['purchase_url'] ?? '') === LINK && ($again['data']['order_received']['reference'] ?? '') === T.'123-1');
$sent_body = json_decode(array_values(array_filter($GLOBALS['http_log'], fn($c) => str_contains($c[1], '/voucher/send')))[0][2]['body'] ?? '{}', true);
check('SH1 the voucher names this shop (meta_data.store_ref) and its reference starts with it', ($sent_body['meta_data']['store_ref'] ?? '') === rtnfch_store_ref() && str_starts_with((string) ($sent_body['reference'] ?? ''), rtnfch_store_ref() . '-123-'));
check('SH2 another shop (other home URL) gets another tag, so its order #123 never meets this one', substr(hash('sha256', 'https://second-shop.test'), 0, 8) !== rtnfch_store_ref() && rtnfch_store_ref() === substr(hash('sha256', 'https://shop.test'), 0, 8));
check('R2 same total again: no call to RT, no new reference, no second email', count($GLOBALS['http_log']) === $calls && $refs() === [T.'123-1'] && $notes($order) === 1, implode(',', $refs()));

$order = world('yes');
(new RTNFCH_GATEWAY())->process_payment(123);
$again = (new RTNFCH_GATEWAY())->process_payment(123);
check('R3 skip on, same total again: straight to the same link, one voucher', ($again['redirect'] ?? '') === LINK && $refs() === [T.'123-1']);

$order = world('no');
(new RTNFCH_GATEWAY())->process_payment(123);
$order->total = '120.00';
(new RTNFCH_GATEWAY())->process_payment(123);
check('R4 changed total: a new voucher with a new reference', $refs() === [T.'123-1', T.'123-2'] && $order->get_meta('_rtnfch_voucher_total') === '120.00 USD test1291@yopmail.com', implode(',', $refs()));

$order = world('no');
(new RTNFCH_GATEWAY())->process_payment(123);
$order->update_meta_data('_rtnfch_timeline', [['type' => 'failed', 'payment_token' => 'tok1']]);
(new RTNFCH_GATEWAY())->process_payment(123);
check('R5 after a failed payment: a fresh voucher, as before', $refs() === [T.'123-1', T.'123-2'], implode(',', $refs()));

$order = world('no');
$order->update_meta_data('_rtnfch_voucher_reference', '123-1');
$order->update_meta_data('_rtnfch_voucher_purchase_url', LINK);
(new RTNFCH_GATEWAY())->process_payment(123);
check('R6 voucher from before 1.0.8 (no total saved): a new one, as before', $refs() === [T.'123-2'], implode(',', $refs()));

$order = world('no');
(new RTNFCH_GATEWAY())->process_payment(123);
$order->email = 'Fixed.Typo@Example.test';
(new RTNFCH_GATEWAY())->process_payment(123);
$to = json_decode(end($GLOBALS['http_log'])[2]['body'] ?? '{}', true)['email'] ?? '';
check('R8 email corrected, same total: a new voucher to the new address', $refs() === [T.'123-1', T.'123-2'] && $to === 'Fixed.Typo@Example.test', implode(',', $refs()) . ' ' . $to);
$order->email = 'fixed.typo@example.test';
(new RTNFCH_GATEWAY())->process_payment(123);
check('R8b same email in another case: reused', $refs() === [T.'123-1', T.'123-2']);

// RT voucher links have no deadline (backend purchase_url()), so age alone never forces a new one.
$order = world('no');
(new RTNFCH_GATEWAY())->process_payment(123);
$order->update_meta_data('_rtnfch_voucher_emailed_at', time() - 60 * DAY_IN_SECONDS);
(new RTNFCH_GATEWAY())->process_payment(123);
check('R9 a 60-day-old voucher is still reused (RT links do not expire)', $refs() === [T.'123-1']);

// Double click: the second request arrives while the first is still running.
$order = world('no');
set_transient('rtnfch_payment_active_123', ['amount' => 90.0, 'time' => time()], 60);
$dbl = (new RTNFCH_GATEWAY())->process_payment(123);
check('R7 double click while the first is running: no voucher request', $sends() === 0 && ($dbl['result'] ?? '') !== 'success');

// Order-received page (block checkout) shows the same voucher page.
$order = world('no'); (new RTNFCH_GATEWAY())->process_payment(123);
ob_start(); (new RTNFCH_GATEWAY())->rtnfch_thankyou_payment_link_notice(123); $page = ob_get_clean();
check('T1 order-received page: DPRV text, real email, CLICK HERE link', str_contains($page, 'DPRV') && str_contains($page, 'test1291@yopmail.com') && str_contains($page, 'href="' . LINK . '"'));

$failed = array_filter($results, fn($r) => !$r[1]);
echo "\n" . count($results) . " checks, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
