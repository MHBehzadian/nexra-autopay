<?php
require_once 'functions.php';

// This bot talks to TWO different servers for a Nexra-type panel row:
//   1) Nexra Panel itself (url_panel / username_panel / password_panel)
//      -> used ONLY for create / update / delete / reset-usage, so the
//         admin's traffic quota in Nexra is always enforced correctly.
//   2) The real Marzban instance behind it (marzban_url_direct /
//      marzban_username_direct / marzban_password_direct)
//      -> used ONLY for read-only lookups (user status, real subscription
//         link, raw config links), since Nexra doesn't expose those with
//         full fidelity and doing it live from Marzban is instant and exact.

function nexra_base_url($panel)
{
    return rtrim($panel['url_panel'], '/');
}

// ===================== Nexra side (writes) =====================

function nexra_login($code_panel)
{
    $panel = select("marzban_panel", "*", "id", $code_panel, "select");
    $cache = $panel['datelogin'] != null ? json_decode($panel['datelogin'], true) : array();
    if (!is_array($cache)) {
        $cache = array();
    }
    if (isset($cache['nexra']['time']) && isset($cache['nexra']['access_token'])) {
        if ((time() - strtotime($cache['nexra']['time'])) <= 600) {
            return $cache['nexra'];
        }
    }

    $ch = curl_init(nexra_base_url($panel) . '/login');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT_MS => 8000,
        CURLOPT_POSTFIELDS => http_build_query(array(
            'username' => $panel['username_panel'],
            'password' => $panel['password_panel'],
        )),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'accept: application/json',
        ),
    ));
    $response = curl_exec($ch);
    if (curl_error($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        return array('error' => $err);
    }
    curl_close($ch);

    $body = json_decode($response, true);
    if (isset($body['data']['access_token'])) {
        $data = array(
            'time' => date('Y/m/d H:i:s'),
            'access_token' => $body['data']['access_token'],
        );
        $cache['nexra'] = $data;
        update("marzban_panel", "datelogin", json_encode($cache), "name_panel", $panel['name_panel']);
        return $data;
    }
    return array('error' => nexra_error_message($body, 'Nexra login failed'));
}

function nexra_dashboard($location)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $auth = nexra_login($panel['id']);
    if (!isset($auth['access_token'])) {
        return array('detail' => isset($auth['error']) ? $auth['error'] : 'auth failed');
    }

    $ch = curl_init(nexra_base_url($panel) . '/dashboard');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_TIMEOUT_MS => 10000,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Authorization: Bearer ' . $auth['access_token'],
        ),
    ));
    $response = curl_exec($ch);
    if (curl_error($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        return array('detail' => $err);
    }
    curl_close($ch);

    $body = json_decode($response, true);
    if (!isset($body['success']) || $body['success'] !== true) {
        return array('detail' => nexra_error_message($body, 'Nexra dashboard request failed'));
    }
    return $body['data'];
}

function nexra_request($location, $method, $path, $payload = null)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $auth = nexra_login($panel['id']);
    if (!isset($auth['access_token'])) {
        return array('detail' => isset($auth['error']) ? $auth['error'] : 'auth failed');
    }

    $headers = array(
        'Accept: application/json',
        'Authorization: Bearer ' . $auth['access_token'],
    );
    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 10000,
        CURLOPT_CUSTOMREQUEST => $method,
    );
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init(nexra_base_url($panel) . $path);
    curl_setopt_array($ch, $options);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    if (curl_error($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        return array('detail' => $err);
    }
    curl_close($ch);

    return json_decode($response, true);
}

// Extracts a human-readable message from any Nexra/FastAPI response shape:
//   {"message": "..."}                      -> our own application errors
//   {"detail": "..."}                        -> FastAPI HTTPException
//   {"detail": [{"msg": "...", ...}, ...]}    -> FastAPI pydantic validation errors
function nexra_error_message($body, $fallback)
{
    if (is_array($body)) {
        if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            $msg = $body['message'];
        } elseif (isset($body['detail']) && is_string($body['detail']) && $body['detail'] !== '') {
            $msg = $body['detail'];
        } elseif (isset($body['detail']) && is_array($body['detail'])) {
            $parts = array();
            foreach ($body['detail'] as $item) {
                if (is_array($item) && isset($item['msg'])) {
                    $parts[] = $item['msg'];
                }
            }
            $msg = count($parts) > 0 ? implode('; ', $parts) : $fallback;
        } else {
            $msg = $fallback;
        }
    } else {
        $msg = $fallback;
    }

    if (stripos($msg, 'insufficient traffic') !== false) {
        return "❌ حجم پنل شما در Nexra کافی نیست. لطفاً حجم پنل را شارژ کنید.\n(" . $msg . ")";
    }
    return $msg;
}

// ===================== Marzban side (reads) =====================

function nexra_direct_token($code_panel)
{
    $panel = select("marzban_panel", "*", "id", $code_panel, "select");
    $cache = $panel['datelogin'] != null ? json_decode($panel['datelogin'], true) : array();
    if (!is_array($cache)) {
        $cache = array();
    }
    if (isset($cache['marzban']['time']) && isset($cache['marzban']['access_token'])) {
        if ((time() - strtotime($cache['marzban']['time'])) <= 3600) {
            return $cache['marzban'];
        }
    }

    $ch = curl_init(rtrim($panel['marzban_url_direct'], '/') . '/api/admin/token');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT_MS => 8000,
        CURLOPT_POSTFIELDS => http_build_query(array(
            'username' => $panel['marzban_username_direct'],
            'password' => $panel['marzban_password_direct'],
        )),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'accept: application/json',
        ),
    ));
    $response = curl_exec($ch);
    if (curl_error($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        return array('error' => $err);
    }
    curl_close($ch);

    $body = json_decode($response, true);
    if (isset($body['access_token'])) {
        $data = array(
            'time' => date('Y/m/d H:i:s'),
            'access_token' => $body['access_token'],
        );
        $cache['marzban'] = $data;
        update("marzban_panel", "datelogin", json_encode($cache), "name_panel", $panel['name_panel']);
        return $data;
    }
    return array('error' => isset($body['detail']) ? $body['detail'] : 'Marzban direct login failed');
}

// Returns Marzban's own raw /api/user/{username} response, exactly like
// marzban.php's getuser() does for pure-marzban panels: real status,
// real subscription_url, real per-protocol links, no guessing.
function nexra_direct_getuser($username, $location)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $token = nexra_direct_token($panel['id']);
    if (!isset($token['access_token'])) {
        return array('detail' => isset($token['error']) ? $token['error'] : 'auth failed');
    }

    $ch = curl_init(rtrim($panel['marzban_url_direct'], '/') . '/api/user/' . $username);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_TIMEOUT_MS => 8000,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Authorization: Bearer ' . $token['access_token'],
        ),
    ));
    $output = curl_exec($ch);
    curl_close($ch);
    return json_decode($output, true);
}

// ===================== Panel operations =====================

function adduser_nexra($username, $expire, $data_limit, $location, $is_test = false)
{
    $uuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    $payload = array(
        'email'       => $username,
        'id'          => $uuid,
        'enable'      => true,
        'expiry_time' => ($expire == 0 || $expire == "0") ? 0 : (intval($expire) * 1000),
        'total'       => floatval($data_limit),
        'sub_id'      => $username,
        'flow'        => '',
    );

    $result = nexra_request($location, 'POST', '/admin/user', $payload);
    if (!isset($result['success']) || $result['success'] !== true) {
        return array('detail' => nexra_error_message($result, 'خطا در ساخت یوزر روی Nexra'));
    }

    // User is created on the real Marzban instance now -> read it back directly
    // for the real subscription_url + config links (Nexra's own list can lag).
    $created = nexra_direct_getuser($username, $location);
    if (isset($created['detail']) || !isset($created['username'])) {
        // Created successfully but the read-back failed; still report success,
        // the admin can fetch the link again from "manage service".
        return array(
            'username'         => $username,
            'subscription_url' => '',
            'links'            => array(),
        );
    }

    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $subscription_url = $created['subscription_url'];
    if (!preg_match('/^(https?:\/\/)?([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?((\/[^\s\/]+)+)?$/', $subscription_url)) {
        $subscription_url = rtrim($panel['marzban_url_direct'], '/') . "/" . ltrim($subscription_url, "/");
    }

    return array(
        'username'         => $username,
        'subscription_url' => $subscription_url,
        'links'            => isset($created['links']) ? $created['links'] : array(),
    );
}

function getuser_nexra($usernameac, $location)
{
    $u = nexra_direct_getuser($usernameac, $location);
    if (isset($u['detail']) || !isset($u['username'])) {
        return array('detail' => isset($u['detail']) ? $u['detail'] : 'User not found');
    }

    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $subscription_url = $u['subscription_url'];
    if (!preg_match('/^(https?:\/\/)?([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?((\/[^\s\/]+)+)?$/', $subscription_url)) {
        $subscription_url = rtrim($panel['marzban_url_direct'], '/') . "/" . ltrim($subscription_url, "/");
    }

    return array(
        'status'           => $u['status'],
        'username'         => $u['username'],
        'data_limit'       => $u['data_limit'],
        'expire'           => $u['status'] == 'on_hold' ? 0 : $u['expire'],
        'online_at'        => $u['online_at'],
        'used_traffic'     => $u['used_traffic'],
        'links'            => isset($u['links']) ? $u['links'] : array(),
        'subscription_url' => $subscription_url,
    );
}

function removeuser_nexra($location, $username)
{
    $result = nexra_request($location, 'DELETE', '/admin/user/' . rawurlencode($username));
    if (!isset($result['success']) || $result['success'] !== true) {
        return array('detail' => nexra_error_message($result, 'خطا در حذف یوزر روی Nexra'));
    }
    return array('username' => $username, 'success' => true);
}

function ResetUserDataUsage_nexra($usernameac, $location)
{
    return nexra_request($location, 'PUT', '/admin/user/' . rawurlencode($usernameac) . '/reset');
}

// Called as Modifyuser_nexra($location, $username, $data) to mirror marzban.php's own
// Modifyuser($location, $username, $data) signature exactly.
function Modifyuser_nexra($location, $username, array $data)
{
    // Read current state directly from Marzban for full fidelity (used to
    // fill in any field the caller didn't specify).
    $current = nexra_direct_getuser($username, $location);
    if (isset($current['detail']) || !isset($current['username'])) {
        return array('detail' => isset($current['detail']) ? $current['detail'] : 'User not found');
    }

    $expire = array_key_exists('expire', $data)
        ? (($data['expire'] == 0) ? 0 : (intval($data['expire']) * 1000))
        : (intval($current['expire']) * 1000);
    $total = array_key_exists('data_limit', $data)
        ? floatval($data['data_limit'])
        : floatval($current['data_limit']);
    $enable = array_key_exists('status', $data) ? ($data['status'] !== 'disabled') : ($current['status'] !== 'disabled');

    $payload = array(
        'email'       => $username,
        'enable'      => $enable,
        'expiry_time' => $expire,
        'total'       => $total,
        'sub_id'      => $username,
        'flow'        => '',
    );

    $result = nexra_request($location, 'PUT', '/admin/user/' . rawurlencode($username), $payload);
    if (!isset($result['success']) || $result['success'] !== true) {
        return array('detail' => nexra_error_message($result, 'خطا در ویرایش یوزر روی Nexra'));
    }
    return array('username' => $username, 'success' => true);
}

// Nexra's API has no "regenerate UUID / revoke old links" operation for marzban-backed
// users (its own update call never touches proxies/UUIDs) - so this is a known unsupported action.
function revoke_sub_nexra($username, $location)
{
    return array('detail' => 'قابلیت تمدید لینک (Revoke Sub) برای پنل Nexra پشتیبانی نمی‌شود');
}
