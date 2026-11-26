<?php
if (PHP_SAPI !== 'cli') { exit; } // Test code: never runs over the web.
/**
 * php -n test_nft.php <plugin dir>
 *
 * Exercises the NFT Checkout plugin's payment paths with WordPress and
 * WooCommerce stubbed out (dummy keys, no network).
 */
$GLOBALS['plugin_dir'] = rtrim($argv[1] ?? dirname(__DIR__, 2), '/\\');
require __DIR__ . '/stubs.php';

const PUB_LIVE  = 'pk_live_ACCOUNT_A';
const SEC_LIVE  = 'sk_live_ACCOUNT_A_SECRET';
const PUB_SB    = 'pk_test_ACCOUNT_A';
const SEC_SB    = 'sk_test_ACCOUNT_A_SECRET';
const PUB_OTHER = 'pk_live_ACCOUNT_B';
const SEC_OTHER = 'sk_live_ACCOUNT_B_SECRET';
const UUID      = '0b9f3c51-2f2e-4a43-9d38-5b7c1a1f0e11';

$results = [];
function check($name, $cond, $detail = '') { global $results; $results[] = [$name, (bool) $cond, $detail]; echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " ($detail)" : '') . "\n"; }

function reset_world() {
	$GLOBALS['options'] = [
		'admin_email' => 'owner@shop.test',
		RTNFCH_SETTINGS_OPTION => ['enabled' => 'yes', 'sandbox' => 'no', 'order_status' => 'processing'],
		RTNFCH_ACCOUNTS_OPTION => [
			['title' => 'A', 'unique_id' => 'acc_a', 'live_public_key' => PUB_LIVE, 'live_secret_key' => SEC_LIVE, 'sandbox_public_key' => PUB_SB, 'sandbox_secret_key' => SEC_SB, 'has_sandbox' => 'on', 'live_status' => 'active', 'sandbox_status' => 'active', 'priority' => 1],
			['title' => 'B', 'unique_id' => 'acc_b', 'live_public_key' => PUB_OTHER, 'live_secret_key' => SEC_OTHER, 'has_sandbox' => 'off', 'live_status' => 'active', 'priority' => 2],
		],
	];
	$GLOBALS['transients'] = [];
	$GLOBALS['cache'] = [];
	$GLOBALS['wpdb']->rows = [];
	$GLOBALS['wpdb']->lock_result = '1'; $GLOBALS['wpdb']->on_lock = null; $GLOBALS['wpdb']->lock_log = [];
	$GLOBALS['http_log'] = [];
	$GLOBALS['mails'] = [];
	$GLOBALS['notices'] = [];
	$GLOBALS['http'] = function ($url, $args) { return ['response' => ['code' => 200], 'body' => json_encode(['status' => false, 'message' => 'Transaction not found'])]; };
	$order = new WC_Order(123);
	$order->update_meta_data('_rtnfch_account_public_key', PUB_LIVE);
	$order->update_meta_data('_rtnfch_account_unique_id', 'acc_a');
	$order->update_meta_data('_rtnfch_voucher_emailed_at', time());
	$order->update_meta_data('_rtnfch_state', 'pending');
	$GLOBALS['orders'] = [123 => $order];
	return $order;
}

function rest() { return RTNFCH_GATEWAY_REST_API::get_instance(); }

function get_return($query) {
	$r = new WP_REST_Request('GET');
	$r->set_query_params($query);
	try { $resp = rest()->rtnfch_handle_api_request($r); return ['status' => $resp->get_status(), 'redirect' => null]; }
	catch (RedirectException $e) { return ['status' => 302, 'redirect' => $e->url]; }
}

function webhook(array $body, array $headers = [], $raw = null) {
	$raw = $raw ?? json_encode($body);
	$r = new WP_REST_Request('POST');
	$r->set_body($raw);
	foreach ($headers as $k => $v) $r->set_header($k, $v);
	$resp = rest()->rtnfch_handle_api_request($r);
	return [$resp->get_status(), $resp->get_data()];
}

function signed_headers($raw, $secret, $ts = null) {
	$ts = $ts ?? (string) time();
	return ['X-Timestamp' => $ts, 'X-Signature' => hash_hmac('sha256', $ts . '.' . $raw, $secret)];
}

$good = ['order_id' => 123, 'order_status' => 'success', 'payment_status' => 'success', 'pay_id' => base64_encode(UUID), 'amount' => '90.00', 'curr_code' => 'USD', 'mode' => 'live', 'source' => 'rt-payment-backend'];

echo "== NFT-01: return link cannot mark the order paid\n";
foreach (['completed', 'paid', 'success'] as $status) {
	$order = reset_world();
	$out = get_return(['order_id' => 123, 'key' => $order->get_order_key(), 'status' => $status, 'pay_id' => base64_encode(UUID), 'order_status' => $status]);
	check("T1 GET status=$status leaves order pending", $order->get_status() === 'pending' && !$order->is_paid(), 'status=' . $order->get_status());
	check("T1 GET status=$status redirects to order-received", $out['status'] === 302 && str_contains((string) $out['redirect'], 'order-received'));
	check("T1 GET status=$status stores no browser pay_id", $order->get_meta('_rtnfch_pay_id') === '');
}
$order = reset_world();
$out = get_return(['order_id' => 123, 'status' => 'completed']);
check('T1b GET without order key is refused (403)', $out['status'] === 403 && $order->get_status() === 'pending');
$out = get_return(['order_id' => 123, 'key' => 'wrong', 'status' => 'completed']);
check('T1c GET with wrong order key is refused (403)', $out['status'] === 403);

echo "== NFT-02: no key / nonce / bearer fallback\n";
$order = reset_world();
[$code] = webhook(['order_id' => 123, 'status' => 'completed', 'nonce' => PUB_LIVE]);
check('T2 nonce = public key -> 401, unpaid', $code === 401 && $order->get_status() === 'pending', "http $code");
[$code] = webhook(['order_id' => 123, 'status' => 'completed', 'nonce' => base64_encode(PUB_LIVE)]);
check('T2b base64 nonce -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');
[$code] = webhook(['order_id' => 123, 'status' => 'completed', 'api_key' => SEC_LIVE]);
check('T2c api_key = secret key in body -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');
[$code] = webhook(['order_id' => 123, 'status' => 'completed'], ['Authorization' => 'Bearer ' . SEC_LIVE]);
check('T3 Bearer secret key -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');
[$code] = webhook(['order_id' => 123, 'status' => 'completed'], ['Authorization' => 'Bearer ' . PUB_LIVE]);
check('T3b Bearer public key -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');

echo "== NFT-08: signature must be the order's own account, in its mode\n";
$order = reset_world();
$raw = json_encode($good);
[$code] = webhook($good, signed_headers($raw, SEC_OTHER), $raw);
check('T4 signed with another configured account -> 401, unpaid', $code === 401 && $order->get_status() === 'pending', "http $code");
[$code] = webhook($good, signed_headers($raw, SEC_SB), $raw);
check('T4b signed with the sandbox secret for a live order -> 401, unpaid', $code === 401 && $order->get_status() === 'pending', "http $code");
[$code] = webhook($good, signed_headers($raw, SEC_LIVE, (string) (time() - 3600)), $raw);
check('T4c stale timestamp -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');
$order->update_meta_data('_rtnfch_account_public_key', 'pk_live_UNKNOWN');
[$code] = webhook($good, signed_headers($raw, SEC_LIVE), $raw);
check('T4d order naming no configured account -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');

echo "== NFT-02/03: amount, currency, order, pay_id must match\n";
$order = reset_world();
$bad = ['amount' => '1.00']; $b = json_encode(array_merge($good, $bad));
[$code, $data] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6 amount mismatch -> 400, unpaid', $code === 400 && $order->get_status() === 'pending', "http $code " . ($data['message'] ?? ''));
$b = json_encode(array_merge($good, ['curr_code' => 'EUR']));
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6b currency mismatch -> 400, unpaid', $code === 400 && $order->get_status() === 'pending');
$b = json_encode(array_diff_key($good, ['amount' => 1]));
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6c missing amount -> 400, unpaid (fail closed)', $code === 400 && $order->get_status() === 'pending');
$b = json_encode(array_diff_key($good, ['curr_code' => 1]));
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6d missing currency -> 400, unpaid (fail closed)', $code === 400 && $order->get_status() === 'pending');
$b = json_encode(array_diff_key($good, ['pay_id' => 1]));
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6e missing pay_id -> 400, unpaid', $code === 400 && $order->get_status() === 'pending');
$b = json_encode(array_merge($good, ['mode' => 'sandbox']));
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6f sandbox mode claimed for a live order -> 400, unpaid', $code === 400 && $order->get_status() === 'pending');
$order->update_meta_data('_rtnfch_pay_id', base64_encode('11111111-2222-4333-8444-555555555555'));
$b = json_encode($good);
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6g pay_id differs from the one the order was issued -> 400, unpaid', $code === 400 && $order->get_status() === 'pending');

echo "== Valid signed webhook\n";
$order = reset_world();
$raw = json_encode($good);
[$code, $data] = webhook([], signed_headers($raw, SEC_LIVE), $raw);
check('T7 valid signed success -> 200, order processing + paid', $code === 200 && $order->get_status() === 'processing' && $order->is_paid(), "http $code state=" . ($data['state'] ?? ''));
check('T7 pay_id recorded from the signed webhook', rtnfch_pay_ids_match($order->get_meta('_rtnfch_pay_id'), UUID));
check('T7 payment-link row written', count($GLOBALS['wpdb']->rows) === 1);
[$code, $data] = webhook([], signed_headers($raw, SEC_LIVE), $raw);
check('T8 exact replay -> ignored as duplicate', $code === 200 && str_contains($data['message'], 'Duplicate'), $data['message'] ?? '');
$raw2 = json_encode(array_merge($good, ['sent_at' => 'later']));
[$code, $data] = webhook([], signed_headers($raw2, SEC_LIVE), $raw2);
check('T8b re-delivery of the same event -> deduplicated, still paid once', $code === 200 && $order->get_status() === 'processing', ($data['engine_reason'] ?? ''));

echo "== NFT-07: a paid order is never moved down\n";
foreach (['failed', 'cancelled', 'expired'] as $down) {
	$raw3 = json_encode(array_merge($good, ['order_status' => $down, 'payment_status' => $down]));
	[$code, $data] = webhook([], signed_headers($raw3, SEC_LIVE), $raw3);
	check("T9 signed '$down' after payment leaves the order processing", $order->get_status() === 'processing' && $order->is_paid(), "http $code " . ($data['engine_reason'] ?? ''));
}
$raw4 = json_encode(array_merge($good, ['order_status' => 'returned', 'return_code' => 'R01']));
[$code, $data] = webhook([], signed_headers($raw4, SEC_LIVE), $raw4);
check('T9b signed "returned" records a note + mail, status unchanged', $order->get_status() === 'processing' && count($GLOBALS['mails']) === 1 && $order->get_meta('_rtnfch_payment_returned') === 'yes', "http $code");
$paid_other_way = reset_world();
$paid_other_way->update_status('completed');
$raw5 = json_encode(array_merge($good, ['order_status' => 'failed']));
[$code] = webhook([], signed_headers($raw5, SEC_LIVE), $raw5);
check('T9c order completed another way is not downgraded by a webhook', $paid_other_way->get_status() === 'completed');

echo "== NFT-03: status sync fails closed\n";
$loader = RTNFCH_GATEWAY_Loader::get_instance();
$answer = fn($over = []) => ['status' => true, 'redirect_url' => 'https://shop.test/wp-json/rt-nft-checkout/v1/data?order_id=123', 'transaction_status' => 'paid', 'payment_status' => 'success', 'settled' => true, 'pay_id' => base64_encode(UUID), 'order_id' => '123', 'amount' => '90.00', 'curr_code' => 'USD', 'mode' => 'live'] + $over;
$sync_http = function ($url, $args) use (&$answer, &$override) { $GLOBALS['last_req'] = $args; return ['response' => ['code' => 200], 'body' => json_encode(array_merge($answer(), $override))]; };
$GLOBALS["http"] = $sync_http;

$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = ['amount' => '1.00'];
$loader->sync_single_order_status($order);
check('T10 sync: amount mismatch -> order stays pending, mismatch note added', $order->get_status() === 'pending' && $order->get_meta('_rtnfch_sync_mismatch_noted') === 'yes');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = ['curr_code' => 'EUR'];
$loader->sync_single_order_status($order);
check('T10b sync: currency mismatch -> pending', $order->get_status() === 'pending');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = ['order_id' => '124'];
$loader->sync_single_order_status($order);
check('T10c sync: another order id -> pending', $order->get_status() === 'pending');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = ['redirect_url' => 'https://other-shop.test/wp-json/rt-nft-checkout/v1/data?order_id=123'];
$loader->sync_single_order_status($order);
check('T10d sync: link raised by another site -> pending', $order->get_status() === 'pending');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = ['mode' => 'sandbox'];
$loader->sync_single_order_status($order);
check('T10e sync: sandbox payment for a live order -> pending', $order->get_status() === 'pending');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = [];
$order->update_meta_data('_rtnfch_pay_id', base64_encode('11111111-2222-4333-8444-555555555555'));
$loader->sync_single_order_status($order);
check('T10f sync: paid under a different pay_id than issued -> pending', $order->get_status() === 'pending');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = [];
$state = $loader->sync_single_order_status($order);
check('T10g sync: exact match -> processing + paid', $state === 'success' && $order->get_status() === 'processing' && $order->is_paid());
$req = $GLOBALS['last_req'];
check('T10h sync request is signed, carries no secret, goes to update-txn-status', isset($req['headers']['X-Signature'], $req['headers']['X-Timestamp']) && !str_contains($req['body'], SEC_LIVE) && str_contains($GLOBALS['http_log'][0][1], '/payment-api/update-txn-status') && hash_hmac('sha256', $req['headers']['X-Timestamp'] . '.' . $req['body'], SEC_LIVE) === $req['headers']['X-Signature']);
check('T10i sync uses only one endpoint (no unauthenticated fallbacks)', count($GLOBALS['http_log']) === 1);
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = [];
$order->update_meta_data('_rtnfch_account_public_key', '');
$order->update_meta_data('_rtnfch_account_unique_id', '');
$loader->sync_single_order_status($order);
check('T10j sync: order without an account is never confirmed', $order->get_status() === 'pending' && count($GLOBALS['http_log']) === 0);

echo "== NFT-04: default host\n";
check('T11 default API base is production app.rt.app', RTNFCH_API_BASE_URL === 'https://app.rt.app/payment-api' && RTNFCH_BASE_URL === 'https://app.rt.app');
check('T11b API base: dev address accepted', rtnfch_clean_api_base('https://dev.rt.app/payment-api/') === 'https://dev.rt.app/payment-api');
check('T11c API base: http is forced to https', rtnfch_clean_api_base('http://dev.rt.app/payment-api') === 'https://dev.rt.app/payment-api');
check('T11d API base: a host outside rt.app falls back to production', rtnfch_clean_api_base('https://evil.example/payment-api') === 'https://app.rt.app/payment-api' && rtnfch_clean_api_base('https://rt.app.evil.example') === 'https://app.rt.app/payment-api' && rtnfch_clean_api_base('https://notrt.app/x') === 'https://app.rt.app/payment-api');
check('T11e API base: empty falls back to production', rtnfch_clean_api_base('') === 'https://app.rt.app/payment-api');

echo "== NFT-05: account sync sends no secret\n";
reset_world(); $GLOBALS['http'] = function ($u, $a) { if (str_contains($u, 'sync-account-status')) $GLOBALS['sync_req'] = $a; return ['response' => ['code' => 200], 'body' => json_encode(['data' => [['mode' => 'live', 'public_key' => PUB_LIVE, 'status' => 'active']]])]; };
$loader->handle_cron_event();
$body = $GLOBALS['sync_req']['body'];
check('T12 sync-account-status body has no secret_key', !str_contains($body, 'secret_key') && !str_contains($body, SEC_LIVE) && str_contains($body, '"signature"'));
check('T12b telemetry not sent without consent', count(array_filter($GLOBALS['http_log'], fn($r) => str_contains($r[1], 'plugin/check'))) === 0);

echo "== pay id helpers\n";
check('pay ids match across base64/plain spellings', rtnfch_pay_ids_match(UUID, base64_encode(UUID)) && rtnfch_pay_ids_match(rtrim(base64_encode(UUID), '='), UUID) && !rtnfch_pay_ids_match(UUID, '11111111-2222-4333-8444-555555555555'));

echo "== Retry payment (review NFT-01): fail on link A, pay on link B\n";
const UUID_B = '7c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f';
$issue_voucher = function ($order, $reference, $voucher_id) {
	// What the gateway records when RT confirms a payment email was sent.
	$order->update_meta_data('_rtnfch_voucher_reference', $reference);
	$order->update_meta_data('_rtnfch_voucher_id', $voucher_id);
	rtnfch_remember_voucher($order, $reference, $voucher_id, PUB_LIVE);
	$order->update_meta_data('_rtnfch_state', 'pending');
	if ($order->get_status() !== 'pending') { $order->update_status('pending'); }
};
$send = function (array $over) use ($good) { $raw = json_encode(array_merge($good, $over)); return webhook([], signed_headers($raw, SEC_LIVE), $raw); };

$order = reset_world();
$issue_voucher($order, '123-1', 'v_1');
[$code] = $send(['order_status' => 'failed', 'payment_status' => 'failed']);
check('R1 first payment fails on link A -> order failed, A remembered', $code === 200 && $order->get_status() === 'failed' && rtnfch_pay_ids_match($order->get_meta('_rtnfch_pay_id'), UUID), "http $code status=" . $order->get_status());
$issue_voucher($order, '123-2', 'v_2');
[$code, $data] = $send(['pay_id' => base64_encode(UUID_B), 'sent_at' => 'b']);
check('R2 shopper pays on new link B -> order processing + paid', $code === 200 && $order->get_status() === 'processing' && $order->is_paid(), "http $code " . ($data['message'] ?? ''));
check('R2 link B is now the newest known payment', rtnfch_pay_ids_match($order->get_meta('_rtnfch_latest_pay_id'), UUID_B) && $order->get_meta('_rtnfch_voucher_awaiting_pay_id') === '');

$order = reset_world();
$issue_voucher($order, '123-1', 'v_1');
$send(['order_status' => 'failed', 'payment_status' => 'failed']);
$issue_voucher($order, '123-2', 'v_2');
[$code, $data] = $send(['order_status' => 'expired', 'payment_status' => 'expired', 'sent_at' => 'late']);
check('R3 old link A expires after a new email was sent -> order stays pending', $code === 200 && $order->get_status() === 'pending' && ($data['engine_reason'] ?? '') === 'superseded_link_ignored', "http $code status=" . $order->get_status());
[$code] = $send(['pay_id' => base64_encode(UUID_B), 'amount' => '1.00']);
check('R4 new link with the wrong amount is still refused', $code === 400 && !$order->is_paid(), "http $code");
[$code] = $send(['pay_id' => base64_encode(UUID_B), 'reference' => '999-1']);
check('R4b new link naming a voucher this order never sent is refused', $code === 400 && !$order->is_paid(), "http $code");
$rawb = json_encode(array_merge($good, ['pay_id' => base64_encode(UUID_B)]));
[$code] = webhook([], signed_headers($rawb, SEC_OTHER), $rawb);
check('R4c new link signed by another account is refused', $code === 401 && !$order->is_paid(), "http $code");
[$code] = $send(['pay_id' => base64_encode(UUID_B), 'reference' => '123-2', 'sent_at' => 'ok']);
check('R5 new link naming this order\'s voucher is accepted', $code === 200 && $order->is_paid(), "http $code");
$mails_before = count($GLOBALS['mails']);
[$code, $data] = $send(['sent_at' => 'second']);
check('R6 old link A paid as well (known id) -> order unchanged, still paid once', $code === 200 && $order->get_status() === 'processing', ($data['engine_reason'] ?? ''));

$order = reset_world();
$issue_voucher($order, '123-1', 'v_1');
$send([]);
const UUID_C = '9d8c7b6a-5f4e-4d3c-8b2a-1f0e9d8c7b6a';
[$code, $data] = $send(['pay_id' => base64_encode(UUID_C), 'sent_at' => 'dup']);
check('R7 second payment under an unknown id on a paid order -> 200, note + email, paid once', $code === 200 && ($data['engine_reason'] ?? '') === 'duplicate_payment_flagged' && $order->get_status() === 'processing' && count($GLOBALS['mails']) === 1, "http $code mails=" . count($GLOBALS['mails']));

echo "== Retry payment: status lookup asks about the newest payment\n";
$order = reset_world(); $GLOBALS["http"] = $sync_http;
$issue_voucher($order, '123-1', 'v_1');
$override = ['payment_status' => 'failed', 'transaction_status' => 'failed', 'settled' => false];
$loader->sync_single_order_status($order);
check('R8 lookup learns link A and its failure', $order->get_status() === 'failed' && rtnfch_pay_ids_match($order->get_meta('_rtnfch_pay_id'), UUID));
$issue_voucher($order, '123-2', 'v_2');
$loader->sync_single_order_status($order);
check('R8b lookup right after the new email names no old id', !str_contains($GLOBALS['last_req']['body'], 'payment_token'), $GLOBALS['last_req']['body']);
check('R8c RT still answering about failed link A does not fail the order again', $order->get_status() === 'pending', 'status=' . $order->get_status());
$override = ['pay_id' => base64_encode(UUID_B)];
$state = $loader->sync_single_order_status($order);
check('R9 lookup finds link B paid -> processing + paid', $state === 'success' && $order->is_paid() && $order->get_status() === 'processing', 'state=' . var_export($state, true));

echo "== Card minimum answer (QA row 35)\n";
check('M1 403 "Card payments start at $5.00." is the minimum answer', rtnfch_is_card_minimum_answer(['code' => 403, 'status' => 'error', 'message' => 'Card payments start at $5.00.']));
check('M2 thousands separator accepted', rtnfch_is_card_minimum_answer(['code' => 403, 'status' => 'error', 'message' => 'Card payments start at $1,000.00.']));
check('M3 a refused key (401) is not the minimum answer', !rtnfch_is_card_minimum_answer(['code' => 401, 'status' => 'error', 'message' => 'Card payments start at $5.00.']));
check('M4 an inactive account (403, other text) is not the minimum answer', !rtnfch_is_card_minimum_answer(['code' => 403, 'status' => 'error', 'message' => 'Your account is not active.']));
check('M5 success and junk are not the minimum answer', !rtnfch_is_card_minimum_answer(['code' => 200, 'status' => 'success', 'message' => 'ok']) && !rtnfch_is_card_minimum_answer(null) && !rtnfch_is_card_minimum_answer('x'));

echo "== Order cancelled in WooCommerce -> RT is told (cancel-order-link)\n";
// The action lives in the plugin's main file, which boots the whole plugin;
// only the function itself is loaded here.
preg_match('/^function rtnfch_cancel_unpaid_order_action\(.*?^}/ms', file_get_contents($GLOBALS['plugin_dir'] . '/nft-checkout-payment-gateway.php'), $cancel_src);
eval($cancel_src[0]);
$cancel_calls = function () { return array_values(array_filter($GLOBALS['http_log'], fn($r) => str_contains($r[1], '/cancel-order-link'))); };
$cancel_http = function (array $answer) { return function ($url, $args) use ($answer) { return ['response' => ['code' => 200], 'body' => json_encode($answer)]; }; };
$cancelled_ok = ['status' => true, 'updated_status' => 'canceled', 'message' => 'Order payment link has been cancelled successfully'];

$order = reset_world(); $GLOBALS['http'] = $cancel_http($cancelled_ok);
$order->update_meta_data('_rtnfch_voucher_id', 'c0ffee00-0000-4000-8000-000000000001');
$order->update_meta_data('_rtnfch_voucher_reference', '123-1');
$order->update_status('cancelled');
rtnfch_cancel_unpaid_order_action(123);
$calls = $cancel_calls(); $req = $calls[0][2] ?? ['body' => '{}', 'headers' => []]; $sent = json_decode($req['body'], true);
check('C1 voucher never opened: RT is told, naming the voucher', count($calls) === 1 && ($sent['status'] ?? '') === 'canceled' && ($sent['order_uuid'] ?? '') === 'c0ffee00-0000-4000-8000-000000000001' && ($sent['voucher_id'] ?? '') === 'c0ffee00-0000-4000-8000-000000000001' && ($sent['voucher_reference'] ?? '') === '123-1' && ($sent['order_id'] ?? 0) === 123 && ($sent['is_sandbox'] ?? 1) === 0, $req['body']);
check('C1b the call is signed with the order account and carries no secret', isset($req['headers']['X-Signature'], $req['headers']['X-Timestamp']) && !str_contains($req['body'], SEC_LIVE) && hash_hmac('sha256', $req['headers']['X-Timestamp'] . '.' . $req['body'], SEC_LIVE) === $req['headers']['X-Signature']);
check('C1c the order notes that RT cancelled the link', count(array_filter($order->get_notes(), fn($n) => str_contains($n, 'cancelled in your RT account'))) === 1);
rtnfch_cancel_unpaid_order_action(123);
check('C1d a repeat of the hook does not call RT again', count($cancel_calls()) === 1);

$order = reset_world(); $GLOBALS['http'] = $cancel_http($cancelled_ok);
$order->update_meta_data('_rtnfch_voucher_id', 'c0ffee00-0000-4000-8000-000000000001');
$order->update_meta_data('_rtnfch_pay_id', base64_encode(UUID));
$order->update_status('cancelled');
rtnfch_cancel_unpaid_order_action(123);
$sent = json_decode($cancel_calls()[0][2]['body'] ?? '{}', true);
check('C2 voucher opened: the payment id AND the voucher are named', ($sent['order_uuid'] ?? '') === base64_encode(UUID) && ($sent['voucher_id'] ?? '') === 'c0ffee00-0000-4000-8000-000000000001', json_encode($sent));

$order = reset_world(); $GLOBALS['http'] = $cancel_http(['status' => false, 'updated_status' => 'reconciliation_required', 'message' => 'Payment has started.']);
$order->update_meta_data('_rtnfch_pay_id', base64_encode(UUID));
$order->update_status('cancelled');
rtnfch_cancel_unpaid_order_action(123);
check('C3 payment already going through: the merchant is told on the order', count(array_filter($order->get_notes(), fn($n) => str_contains($n, 'could not be stopped'))) === 1 && $order->get_meta('_rtnfch_cancelled_link_uuid') === '');
rtnfch_cancel_unpaid_order_action(123);
check('C3b a refused cancellation is asked again next time, noted once', count($cancel_calls()) === 2 && count(array_filter($order->get_notes(), fn($n) => str_contains($n, 'could not be stopped'))) === 1);

$order = reset_world(); $GLOBALS['http'] = $cancel_http($cancelled_ok);
$order->update_meta_data('_rtnfch_pay_id', base64_encode(UUID));
$order->update_status('failed');
rtnfch_cancel_unpaid_order_action(123);
$sent = json_decode($cancel_calls()[0][2]['body'] ?? '{}', true);
check('C4 a failed order is reported as failed, never as cancelled', ($sent['status'] ?? '') === 'failed' && count(array_filter($order->get_notes(), fn($n) => str_contains($n, 'cancelled in your RT account'))) === 0);

$order = reset_world(); $GLOBALS['http'] = $cancel_http($cancelled_ok);
$order->update_status('cancelled');
rtnfch_cancel_unpaid_order_action(123);
check('C5 no voucher and no payment id: nothing to cancel, RT is not called', count($cancel_calls()) === 0);

$order = reset_world(); $GLOBALS['http'] = $cancel_http($cancelled_ok);
$order->update_meta_data('_rtnfch_voucher_id', 'c0ffee00-0000-4000-8000-000000000001');
$order->method = 'cod';
$order->update_status('cancelled');
rtnfch_cancel_unpaid_order_action(123);
check('C6 another gateway\'s order is left alone', count($cancel_calls()) === 0);


echo "== N-1: one paid transition under concurrency (GET_LOCK, fresh read, dedupe after the lock)\n";
$lock_count = fn($kind) => count(array_filter($GLOBALS['wpdb']->lock_log, fn($q) => stripos($q, $kind) !== false && str_contains($q, 'rtnfch_lock_123')));

// D1: the status sync pays the order while the webhook waits for the lock.
$order = reset_world(); $synced = null;
$GLOBALS['wpdb']->on_lock = function () use ($order, &$synced) { $synced = clone $order; $synced->payment_complete(UUID); $GLOBALS['orders'][123] = $synced; };
$raw = json_encode($good);
[$code, $data] = webhook([], signed_headers($raw, SEC_LIVE), $raw);
check('D1 webhook + sync race: webhook sees the paid order after the lock, payment_complete runs once', $code === 200 && ($data['engine_reason'] ?? '') === 'final_success_locked' && $order->completes === 0 && $synced->completes === 1, "http $code " . ($data['engine_reason'] ?? '') . " stale={$order->completes} fresh={$synced->completes}");
check('D1b the order cache is dropped before the re-read', in_array(123, $GLOBALS['cleaned'] ?? [], true));

// D2: two workers deliver the same webhook event; the second waits and finds it applied.
$order = reset_world(); $first = null;
$event = ['status' => 'success', 'payment_token' => base64_encode(UUID), 'source' => 'Webhook', 'return_code' => '', 'failure_reason' => ''];
$GLOBALS['wpdb']->on_lock = function () use ($order, &$first, $event) { $first = clone $order; $GLOBALS['orders'][123] = $first; RTNFCH_State_Engine::handle_event(123, 'webhook_update', $event); };
$res = RTNFCH_State_Engine::handle_event(123, 'webhook_update', $event);
check('D2 double webhook, two workers: second is a duplicate after the lock, paid once', ($res['reason'] ?? '') === 'duplicate_event_ignored' && $order->completes === 0 && $first->completes === 1, ($res['reason'] ?? '') . " first={$first->completes} second={$order->completes}");

// D3: lock held past the wait -> 423, nothing applied, lock not released; the retry applies once.
$order = reset_world(); $GLOBALS['wpdb']->lock_result = '0';
$raw = json_encode($good);
[$code] = webhook([], signed_headers($raw, SEC_LIVE), $raw);
check('D3 lock timeout -> 423, order unpaid, no RELEASE_LOCK for a lock never held', $code === 423 && !$order->is_paid() && $order->completes === 0 && $lock_count('RELEASE_LOCK') === 0, "http $code");
$GLOBALS['wpdb']->lock_result = '1';
[$code, $data] = webhook([], signed_headers($raw, SEC_LIVE), $raw);
check('D3b retry after timeout (same signature) is processed once', $code === 200 && $order->is_paid() && $order->completes === 1, "http $code " . ($data['engine_reason'] ?? ''));
[$code, $data] = webhook([], signed_headers($raw, SEC_LIVE), $raw);
check('D3c a further replay changes nothing', $code === 200 && $order->completes === 1);
check('D3d every lock taken is released', $lock_count('GET_LOCK') >= 2 && $lock_count('GET_LOCK') - 1 === $lock_count('RELEASE_LOCK'), 'get=' . $lock_count('GET_LOCK') . ' release=' . $lock_count('RELEASE_LOCK'));

// D4: sync then webhook in sequence (different event types, same payment).
$order = reset_world();
RTNFCH_State_Engine::handle_event(123, 'api_sync', ['status' => 'success', 'payment_token' => base64_encode(UUID), 'source' => 'API Sync']);
$res = RTNFCH_State_Engine::handle_event(123, 'webhook_update', $event);
check('D4 sync then webhook: second is final_success_locked, paid once', ($res['reason'] ?? '') === 'final_success_locked' && $order->completes === 1, ($res['reason'] ?? ''));

// D5: lock released on an early return too.
$order = reset_world(); $GLOBALS['wpdb']->lock_log = [];
RTNFCH_State_Engine::handle_event(123, 'api_sync', ['status' => '', 'payment_token' => '']);
check('D5 early return (no state) still releases the lock', $lock_count('GET_LOCK') === 1 && $lock_count('RELEASE_LOCK') === 1);

echo "== Customer chose another payment method -> the card e-mail is cancelled at RT\n";
preg_match('/^function rtnfch_release_on_switch\(.*?^}/ms', file_get_contents($GLOBALS['plugin_dir'] . '/nft-checkout-payment-gateway.php'), $release_src);
eval($release_src[0]);
$order = reset_world(); $GLOBALS['http'] = $cancel_http($cancelled_ok);
$order->update_meta_data('_rtnfch_voucher_id', 'c0ffee00-0000-4000-8000-000000000001');
$order->update_meta_data('_rtnfch_voucher_reference', '123-1');
$order->changes = ['payment_method' => 'rt_bank_transfer'];
rtnfch_release_on_switch($order);
$calls = $cancel_calls(); $req = $calls[0][2] ?? ['body' => '{}', 'headers' => []]; $sent = json_decode($req['body'], true);
check('R1 switch away: RT cancels the voucher (signed), order unchanged', count($calls) === 1 && ($sent['status'] ?? '') === 'canceled' && ($sent['voucher_id'] ?? '') === 'c0ffee00-0000-4000-8000-000000000001' && hash_hmac('sha256', $req['headers']['X-Timestamp'] . '.' . $req['body'], SEC_LIVE) === $req['headers']['X-Signature'] && $order->get_status() === 'pending' && !$order->is_paid(), $req['body']);
check('R1b one note, reference remembered', count(array_filter($order->get_notes(), fn($n) => str_contains($n, 'chose another payment method, so the card payment e-mail'))) === 1 && $order->get_meta('_rtnfch_released_ref') === 'c0ffee00-0000-4000-8000-000000000001');
rtnfch_release_on_switch($order);
check('R2 a second save does not call RT again', count($cancel_calls()) === 1);

$order = reset_world(); $GLOBALS['http'] = $cancel_http(['status' => false, 'updated_status' => 'reconciliation_required', 'message' => 'Payment has started.']);
$order->update_meta_data('_rtnfch_pay_id', base64_encode(UUID));
$order->changes = ['payment_method' => 'rt_card'];
rtnfch_release_on_switch($order);
check('R3 RT refuses (payment going through): the merchant is told, nothing remembered', count(array_filter($order->get_notes(), fn($n) => str_contains($n, 'could not cancel their card payment e-mail'))) === 1 && $order->get_meta('_rtnfch_released_ref') === '');

$order = reset_world(); $GLOBALS['http'] = $cancel_http($cancelled_ok);
$order->update_meta_data('_rtnfch_voucher_id', 'c0ffee00-0000-4000-8000-000000000001');
$order->changes = ['payment_method' => 'nft_checkout']; rtnfch_release_on_switch($order);
$order->changes = ['billing_email' => 'x@example.test']; rtnfch_release_on_switch($order);
$order->method = 'rt_usdc'; $order->changes = ['payment_method' => 'rt_card']; rtnfch_release_on_switch($order);
check('R4 no call: its own legacy id, no payment-method change, or another plugin\'s order', count($cancel_calls()) === 0);

$order = reset_world(); $GLOBALS['http'] = $cancel_http($cancelled_ok);
$order->update_meta_data('_rtnfch_voucher_id', 'c0ffee00-0000-4000-8000-000000000001');
$order->payment_complete('x'); $order->changes = ['payment_method' => 'rt_card'];
rtnfch_release_on_switch($order);
check('R5 a paid order is never released', count($cancel_calls()) === 0);

echo "== Late card payment after the switch -> note only\n";
$order = reset_world(); $order->method = 'rt_bank_transfer';
$late = ['order_id' => 123, 'order_status' => 'success', 'pay_id' => base64_encode(UUID), 'amount' => '90.00', 'curr_code' => 'USD'];
$raw = json_encode($late);
[$code, $data] = webhook([], signed_headers($raw, SEC_LIVE), $raw);
check('L1 signed late payment for a moved order: 200, order unchanged, one note', $code === 200 && ($data['engine_reason'] ?? '') === 'other_payment_method' && !$order->is_paid() && $order->get_status() === 'pending' && count(array_filter($order->get_notes(), fn($n) => str_contains($n, 'also paid this order'))) === 1, "http $code");
$raw2 = json_encode($late + ['x' => 1]);
[$code] = webhook([], signed_headers($raw2, 'wrong-secret'), $raw2);
check('L2 an unsigned late report is refused', $code === 401);

// Phones go to RT as E.164 from the billing country (UK 07700 900123 -> +447700900123).
foreach ([['07700 900123','+44','+447700900123'],['+44 7700 900123','+44','+447700900123'],['0044 7700 900123','+44','+447700900123'],['9123456789','+91','+919123456789'],['3912345678','+39','+393912345678'],['7012345678','+7','+77012345678'],['+91 91234 56789','+91','+919123456789'],['(415) 555-0132','+1','+14155550132'],['1 415 555 0132','+1','+14155550132'],['','+1',''],['030 1234567','+49','+49301234567']] as [$in, $cc, $want]) { $got = rtnfch_e164_phone($in, $cc); check('PH ' . $in . ' [' . $cc . '] -> ' . $want, $got === $want, $got); }
check('PH payload sends the E.164 phone', str_contains(file_get_contents($GLOBALS['plugin_dir'] . '/includes/class-nft-checkout-payment-gateway.php'), '$phone        = rtnfch_e164_phone($phone, $country_code);'));

$failed = array_filter($results, fn($r) => !$r[1]);
echo "\n" . count($results) . " checks, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
