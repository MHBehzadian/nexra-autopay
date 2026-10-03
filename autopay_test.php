<?php
/**
 * Command line helper. Run it on the server, never over the web.
 *
 *   php autopay_test.php parse          # check the bank SMS parser
 *   php autopay_test.php status         # settings, open orders, last SMS
 *   php autopay_test.php send 125300    # pretend a deposit of this amount arrived
 *   php autopay_test.php send-raw "متن کامل پیامک"
 */

if (PHP_SAPI !== 'cli') {
    die("cli only\n");
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jdf.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/text.php';
require_once __DIR__ . '/panels.php';
require_once __DIR__ . '/keyboard.php';
require_once __DIR__ . '/autopaylib.php';

$cmd = isset($argv[1]) ? $argv[1] : 'status';

$samples = array(
    'بانک ملت
واریز:۱۲۵,۳۰۰ ریال
۱۴۰۵/۰۷/۱۱-۱۲:۳۳
مانده:۵,۴۳۲,۱۰۰ ریال',
    'ملی
*1234
مبلغ واریز 1253000 ریال
مانده 9870000',
    'بانک سامان
برداشت 500,000 ریال
مانده 1,000,000 ریال',
    'انتقال از 6219-****-1234
بستانکار 1,253,000 ریال
ساعت 14:02',
);

switch ($cmd) {
    case 'parse':
        foreach ($samples as $i => $s) {
            $p = autopay_parse_sms($s);
            echo "--- sample " . ($i + 1) . "\n";
            echo "   direction : " . $p['direction'] . "\n";
            echo "   amount    : " . number_format($p['amount']) . " toman\n";
            echo "   card      : " . ($p['card'] ?: '-') . "\n";
        }
        break;

    case 'status':
        $s = autopay_settings();
        echo "status      : " . $s['status'] . "\n";
        echo "device key  : " . substr($s['device_key'], 0, 8) . "... (" . strlen($s['device_key']) . " chars)\n";
        echo "last seen   : " . ($s['last_seen'] ?: '-') . "\n";
        echo "device      : " . ($s['device_info'] ?: '-') . "\n\n";
        echo "open orders:\n";
        foreach ($pdo->query("SELECT * FROM autopay_order WHERE status='open' ORDER BY id DESC LIMIT 10") as $r) {
            echo "   user " . $r['id_user'] . " owes " . number_format($r['amount'])
                . " (price " . number_format($r['base_price']) . ") since " . $r['created_at'] . "\n";
        }
        echo "\nlast sms:\n";
        foreach ($pdo->query("SELECT * FROM autopay_sms ORDER BY id DESC LIMIT 10") as $r) {
            echo "   " . $r['received_at'] . "  " . str_pad($r['direction'], 8)
                . str_pad(number_format($r['amount']), 14) . $r['status'] . "\n";
        }
        break;

    case 'send':
        $amount = isset($argv[2]) ? intval($argv[2]) : 0;
        if ($amount <= 0) {
            die("usage: php autopay_test.php send <amount in toman>\n");
        }
        $body = "بانک تست\nواریز " . number_format($amount * 10) . " ریال\nمانده 1,000,000 ریال";
        $res = autopay_handle_sms($body, 'TEST', date('Y-m-d H:i:s'));
        print_r($res);
        break;

    case 'send-raw':
        if (!isset($argv[2])) {
            die("usage: php autopay_test.php send-raw \"<sms text>\"\n");
        }
        $res = autopay_handle_sms($argv[2], 'TEST', date('Y-m-d H:i:s'));
        print_r($res);
        break;

    default:
        echo "commands: parse | status | send <amount> | send-raw <text>\n";
}
