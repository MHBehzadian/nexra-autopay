<?php
/**
 * Endpoint for the companion Android app.
 *
 * Every request carries an HMAC-SHA256 of the raw body, keyed with the pairing
 * key the bot shows its admin. Without a valid signature nothing is read, so a
 * stranger cannot invent a deposit.
 *
 *   POST /autopay.php
 *   X-Autopay-Signature: <hex hmac of the raw body>
 *   {"action":"sms","sender":"BANK","body":"...","sent_at":"2026-10-03 12:00:00","nonce":"..."}
 */

require_once 'config.php';
require_once 'jdf.php';
require_once 'functions.php';
require_once 'text.php';
require_once 'panels.php';
require_once 'keyboard.php';
require_once 'autopaylib.php';

header('Content-Type: application/json; charset=utf-8');

function autopay_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) {
    autopay_out(array('ok' => false, 'error' => 'empty body'), 400);
}

$settings = autopay_settings();
$given = isset($_SERVER['HTTP_X_AUTOPAY_SIGNATURE']) ? $_SERVER['HTTP_X_AUTOPAY_SIGNATURE'] : '';
$expected = hash_hmac('sha256', $raw, $settings['device_key']);
if (!hash_equals($expected, $given)) {
    autopay_out(array('ok' => false, 'error' => 'bad signature'), 401);
}

$in = json_decode($raw, true);
if (!is_array($in) || !isset($in['action'])) {
    autopay_out(array('ok' => false, 'error' => 'bad payload'), 400);
}

autopay_set('last_seen', date('Y-m-d H:i:s'));
if (isset($in['device'])) {
    autopay_set('device_info', mb_substr((string)$in['device'], 0, 190));
}

switch ($in['action']) {
    case 'ping':
    case 'heartbeat':
        autopay_out(array(
            'ok'      => true,
            'enabled' => autopay_enabled(),
            'server'  => date('Y-m-d H:i:s'),
        ));
        break;

    case 'sms':
        if (!autopay_enabled()) {
            // still answer 200 so the phone clears it from its queue
            autopay_out(array('ok' => true, 'result' => 'disabled'));
        }
        $body = isset($in['body']) ? (string)$in['body'] : '';
        $sender = isset($in['sender']) ? (string)$in['sender'] : '';
        $sent_at = isset($in['sent_at']) ? (string)$in['sent_at'] : date('Y-m-d H:i:s');
        if (trim($body) === '') {
            autopay_out(array('ok' => false, 'error' => 'empty sms'), 400);
        }
        $res = autopay_handle_sms($body, $sender, $sent_at);
        autopay_out(array('ok' => true) + $res);
        break;

    case 'batch':
        // the phone was offline and is catching up
        if (!autopay_enabled()) {
            autopay_out(array('ok' => true, 'result' => 'disabled'));
        }
        $items = isset($in['items']) && is_array($in['items']) ? $in['items'] : array();
        $results = array();
        foreach ($items as $item) {
            $body = isset($item['body']) ? (string)$item['body'] : '';
            if (trim($body) === '') {
                continue;
            }
            $results[] = autopay_handle_sms(
                $body,
                isset($item['sender']) ? (string)$item['sender'] : '',
                isset($item['sent_at']) ? (string)$item['sent_at'] : date('Y-m-d H:i:s')
            );
        }
        autopay_out(array('ok' => true, 'count' => count($results), 'results' => $results));
        break;

    default:
        autopay_out(array('ok' => false, 'error' => 'unknown action'), 400);
}
