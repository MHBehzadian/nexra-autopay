<?php
/**
 * Auto-confirm of card-to-card payments.
 *
 * The bot asks the customer for an amount nobody else is currently waiting on
 * (base price + a few tomans). A phone running the companion app forwards every
 * bank SMS here; when the deposited amount equals a reserved one, the payment is
 * confirmed through the very same DirectPayment() the admin button calls.
 */

define('AUTOPAY_OFFSET_MIN', 1);    // tomans added to the price, lowest
define('AUTOPAY_OFFSET_MAX', 300);  // tomans added to the price, highest
define('AUTOPAY_HOLD_HOURS', 24);   // how long a reserved amount stays reserved
define('AUTOPAY_REUSE_HOURS', 24);  // do not hand out an amount used this recently

/** The single settings row, created on first use. */
function autopay_settings()
{
    global $pdo;
    $row = $pdo->query("SELECT * FROM autopay LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $key = bin2hex(random_bytes(24));
        $pdo->prepare("INSERT INTO autopay (id, status, device_key, last_seen, created_at) VALUES (1, 'off', ?, NULL, ?)")
            ->execute([$key, date('Y-m-d H:i:s')]);
        $row = $pdo->query("SELECT * FROM autopay LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    return $row;
}

function autopay_enabled()
{
    $s = autopay_settings();
    return $s['status'] === 'on';
}

function autopay_set($field, $value)
{
    global $pdo;
    autopay_settings();
    $allowed = array('status', 'device_key', 'last_seen', 'device_info');
    if (!in_array($field, $allowed, true)) {
        return false;
    }
    $pdo->prepare("UPDATE autopay SET $field = ? WHERE id = 1")->execute([$value]);
    return true;
}

/** Persian and Arabic digits -> ASCII, separators dropped. */
function autopay_digits($text)
{
    $fa = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    $ar = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');
    $en = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
    $text = str_replace($fa, $en, $text);
    $text = str_replace($ar, $en, $text);
    $text = str_replace(array('٫', '،', ','), array('.', '', ''), $text);
    return $text;
}

/**
 * Pulls direction, amount and the destination card out of a bank SMS.
 *
 * Returns amount in TOMANS. Iranian banks write rials, so a number that looks
 * like rials is divided by ten - a deposit is never a fraction of a toman.
 */
function autopay_parse_sms($raw)
{
    $text = autopay_digits($raw);
    $flat = preg_replace('/\s+/u', ' ', $text);

    $out = array(
        'direction' => 'unknown',
        'amount'    => 0,
        'card'      => '',
        'balance'   => 0,
    );

    $in_words  = array('واریز', 'وار?ز', 'بستانکار', 'افزايش', 'افزایش', 'deposit', 'credit');
    $out_words = array('برداشت', 'بدهکار', 'خريد', 'خرید', 'انتقال از', 'withdraw', 'debit', 'purchase');

    foreach ($out_words as $w) {
        if (mb_strpos($flat, $w) !== false) {
            $out['direction'] = 'out';
            break;
        }
    }
    if ($out['direction'] === 'unknown') {
        foreach ($in_words as $w) {
            if (mb_strpos($flat, $w) !== false) {
                $out['direction'] = 'in';
                break;
            }
        }
    }

    // amount: the number right after a deposit word, else the largest number
    // that is not the balance and not a card/account number
    $amount = 0;
    if (preg_match('/(?:واریز|وار.ز|بستانکار|افزایش|افزايش)[^\d]{0,20}(\d{3,})/u', $flat, $m)) {
        $amount = intval($m[1]);
    }
    if ($amount === 0) {
        preg_match_all('/\d{3,}/', $flat, $all);
        foreach ($all[0] as $n) {
            if (strlen($n) >= 14) {   // card / account / tracking numbers
                continue;
            }
            if (intval($n) > $amount) {
                $amount = intval($n);
            }
        }
    }

    // rial -> toman
    if ($amount > 0 && $amount % 10 === 0) {
        $amount = intval($amount / 10);
    }
    $out['amount'] = $amount;

    if (preg_match('/(\d{4})\s*\*{2,}|\*{2,}\s*(\d{4})/', $flat, $c)) {
        $out['card'] = $c[1] !== '' ? $c[1] : $c[2];
    }
    return $out;
}

/**
 * Reserves an amount that no other open order is waiting on.
 * Returns the full row, or false when every offset is taken.
 */
function autopay_reserve($id_user, $base_price)
{
    global $pdo;
    $base = intval($base_price);
    if ($base <= 0) {
        return false;
    }
    autopay_release_old();

    $taken = array();
    $stmt = $pdo->prepare("SELECT amount FROM autopay_order WHERE status = 'open'");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $a) {
        $taken[intval($a)] = true;
    }
    $recent = $pdo->prepare("SELECT amount FROM autopay_order WHERE created_at > ?");
    $recent->execute([date('Y-m-d H:i:s', time() - AUTOPAY_REUSE_HOURS * 3600)]);
    foreach ($recent->fetchAll(PDO::FETCH_COLUMN) as $a) {
        $taken[intval($a)] = true;
    }

    $offsets = range(AUTOPAY_OFFSET_MIN, AUTOPAY_OFFSET_MAX);
    shuffle($offsets);
    $chosen = 0;
    foreach ($offsets as $o) {
        if (!isset($taken[$base + $o])) {
            $chosen = $o;
            break;
        }
    }
    if ($chosen === 0) {
        // every offset is in use - fall back to one that is merely not open
        foreach ($offsets as $o) {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM autopay_order WHERE amount = ? AND status = 'open'");
            $exists->execute([$base + $o]);
            if ($exists->fetchColumn() == 0) {
                $chosen = $o;
                break;
            }
        }
    }
    if ($chosen === 0) {
        return false;
    }

    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO autopay_order (id_user, base_price, amount, status, id_order, created_at) VALUES (?, ?, ?, 'open', '', ?)")
        ->execute([$id_user, $base, $base + $chosen, $now]);
    $id = $pdo->lastInsertId();
    return $pdo->query("SELECT * FROM autopay_order WHERE id = " . intval($id))->fetch(PDO::FETCH_ASSOC);
}

/** The order this user is currently expected to pay for. */
function autopay_open_order($id_user)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM autopay_order WHERE id_user = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$id_user]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/** Ties a reservation to the Payment_report the customer's receipt created. */
function autopay_attach_order($autopay_id, $id_order)
{
    global $pdo;
    $pdo->prepare("UPDATE autopay_order SET id_order = ? WHERE id = ?")->execute([$id_order, $autopay_id]);
}

/** A manually approved (or rejected) payment must never be matched again. */
function autopay_close_by_order($id_order, $status = 'manual')
{
    global $pdo;
    $pdo->prepare("UPDATE autopay_order SET status = ?, closed_at = ? WHERE id_order = ? AND status = 'open'")
        ->execute([$status, date('Y-m-d H:i:s'), $id_order]);
}

function autopay_release_old()
{
    global $pdo;
    $pdo->prepare("UPDATE autopay_order SET status = 'expired', closed_at = ? WHERE status = 'open' AND created_at < ?")
        ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() - AUTOPAY_HOLD_HOURS * 3600)]);
}

/**
 * Finds the open order an incoming deposit belongs to.
 * Only an exact amount counts - that is the whole point of the offset.
 */
function autopay_find_order($amount)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM autopay_order WHERE amount = ? AND status = 'open' ORDER BY id ASC LIMIT 1");
    $stmt->execute([intval($amount)]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Confirms a matched order. Works both when the customer already sent a receipt
 * (a waiting Payment_report exists) and when they only paid.
 */
function autopay_confirm($order, $sms_id)
{
    global $pdo, $from_id;

    $id_order = $order['id_order'];
    if ($id_order === '' || $id_order === null) {
        // paid without sending a receipt - create the payment record ourselves
        $id_order = bin2hex(random_bytes(5));
        $pdo->prepare("INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, invoice) VALUES (?, ?, ?, ?, 'waiting', 'cart to cart', '0|0')")
            ->execute([$order['id_user'], $id_order, date('Y/m/d H:i:s'), $order['amount']]);
        $pdo->prepare("UPDATE autopay_order SET id_order = ? WHERE id = ?")->execute([$id_order, $order['id']]);
    }

    $report = select("Payment_report", "*", "id_order", $id_order, "select");
    if (!$report || $report['payment_Status'] === 'paid' || $report['payment_Status'] === 'reject') {
        $pdo->prepare("UPDATE autopay_order SET status = 'manual', closed_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $order['id']]);
        return array('ok' => false, 'reason' => 'already handled');
    }

    // DirectPayment() reads these globals when it talks back to the customer
    $from_id = $order['id_user'];
    DirectPayment($id_order);

    $pdo->prepare("UPDATE autopay_order SET status = 'paid', closed_at = ?, sms_id = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $sms_id, $order['id']]);
    $pdo->prepare("UPDATE autopay_sms SET status = 'matched', id_order = ? WHERE id = ?")
        ->execute([$id_order, $sms_id]);

    return array('ok' => true, 'id_order' => $id_order, 'id_user' => $order['id_user'], 'amount' => $order['amount']);
}

/**
 * Stores one forwarded SMS and tries to match it.
 * Re-sending the same SMS is harmless: the hash column is unique.
 */
function autopay_handle_sms($raw, $sender, $sent_at)
{
    global $pdo;

    $hash = hash('sha256', trim($sender) . '|' . trim($raw) . '|' . trim($sent_at));
    $seen = $pdo->prepare("SELECT * FROM autopay_sms WHERE hash = ? LIMIT 1");
    $seen->execute([$hash]);
    if ($row = $seen->fetch(PDO::FETCH_ASSOC)) {
        return array('result' => 'duplicate', 'status' => $row['status']);
    }

    $p = autopay_parse_sms($raw);
    $pdo->prepare("INSERT INTO autopay_sms (hash, sender, body, amount, direction, card, sent_at, received_at, status, id_order) VALUES (?,?,?,?,?,?,?,?,?,'')")
        ->execute([
            $hash, $sender, mb_substr($raw, 0, 900), $p['amount'], $p['direction'], $p['card'],
            $sent_at, date('Y-m-d H:i:s'), 'new',
        ]);
    $sms_id = $pdo->lastInsertId();

    if ($p['direction'] !== 'in') {
        $pdo->prepare("UPDATE autopay_sms SET status = 'ignored' WHERE id = ?")->execute([$sms_id]);
        return array('result' => 'ignored', 'reason' => 'not a deposit', 'amount' => $p['amount']);
    }
    if ($p['amount'] <= 0) {
        $pdo->prepare("UPDATE autopay_sms SET status = 'unreadable' WHERE id = ?")->execute([$sms_id]);
        return array('result' => 'unreadable');
    }

    autopay_release_old();
    $order = autopay_find_order($p['amount']);
    if (!$order) {
        $pdo->prepare("UPDATE autopay_sms SET status = 'unmatched' WHERE id = ?")->execute([$sms_id]);
        autopay_tell_admins_unmatched($p['amount']);
        return array('result' => 'unmatched', 'amount' => $p['amount']);
    }

    $done = autopay_confirm($order, $sms_id);
    if (!$done['ok']) {
        $pdo->prepare("UPDATE autopay_sms SET status = 'skipped' WHERE id = ?")->execute([$sms_id]);
        return array('result' => 'skipped', 'reason' => $done['reason']);
    }
    autopay_tell_admins_paid($done);
    return array('result' => 'paid', 'id_order' => $done['id_order'], 'amount' => $done['amount']);
}

function autopay_tell_admins_paid($done)
{
    $admins = select("admin", "id_admin", null, null, "FETCH_COLUMN");
    $text = "✅ <b>پرداخت خودکار تأیید شد</b>\n\n"
        . "کاربر: <code>" . $done['id_user'] . "</code>\n"
        . "مبلغ: " . number_format($done['amount']) . " تومان\n"
        . "سفارش: <code>" . $done['id_order'] . "</code>";
    foreach ($admins as $admin) {
        sendmessage($admin, $text, null, 'HTML');
    }
}

function autopay_tell_admins_unmatched($amount)
{
    $admins = select("admin", "id_admin", null, null, "FETCH_COLUMN");
    $text = "⚠️ <b>واریز بدون سفارش متناظر</b>\n\n"
        . "مبلغ: " . number_format($amount) . " تومان\n"
        . "هیچ سفارشی منتظر این مبلغ دقیق نبود، پس دستی بررسی کن.";
    foreach ($admins as $admin) {
        sendmessage($admin, $text, null, 'HTML');
    }
}

/** What the customer should be told to pay. */
function autopay_price_note($amount)
{
    return "\n\n⚠️ مبلغ را <b>دقیقاً</b> " . number_format($amount) . " تومان واریز کنید."
        . "\nاین عدد مخصوص سفارش شماست و با همین، پرداختتان خودکار و فوری تأیید می‌شود.";
}
