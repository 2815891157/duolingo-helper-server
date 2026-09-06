<?php

// ============================================================
// 账号系统接口
// 登录页使用：验证令牌 + 向 Duolingo 查询真实用户名 + 设置/验证密码
// ============================================================

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . cors_origin());
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

const AUTH_DATA_DIR = __DIR__ . '/data/accounts';

function cors_origin() {
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o !== '' && preg_match('#^https?://[a-zA-Z0-9.-]+(?::\\d+)?$#', $o)) {
        return $o;
    }
    return '*';
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$action = (string)($_GET['action'] ?? '');

function auth_reply($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function auth_fail($error, $code = 400) {
    http_response_code($code);
    auth_reply(['status' => 'failed', 'error' => $error]);
}

function account_path($account) {
    return AUTH_DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $account) . '.json';
}

function account_load($account) {
    $path = account_path($account);
    if (!is_file($path)) return null;
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

function account_save($account, $d) {
    if (!is_dir(AUTH_DATA_DIR)) @mkdir(AUTH_DATA_DIR, 0777, true);
    file_put_contents(account_path($account), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ============================================================
// 操作分发
// ============================================================

switch ($action) {

    // ----------------------------------------------------------
    // 查询账户状态（登录页加载时调用）
    // 用 JWT 向 Duolingo 查询真实用户名（防伪造）
    // ----------------------------------------------------------
    case 'check':
        $token = trim((string)($input['token'] ?? ''));
        $jwt   = trim((string)($input['jwt'] ?? ''));

        if ($token === '') auth_fail('缺少令牌');
        if ($jwt === '')   auth_fail('缺少 JWT');

        // 验证令牌
        $tPath = DATA_DIR . '/tokens/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) . '.json';
        if (!is_file($tPath)) auth_fail('令牌无效');
        $tData = json_decode((string)file_get_contents($tPath), true);
        if (!is_array($tData)) auth_fail('令牌无效');

        // 用 JWT 向 Duolingo 查询真实用户名（服务器端，防伪造）
        $id = jwt_sub($jwt);
        if (!$id) auth_fail('JWT 无法解析');

        [$code, $body] = duo_get(DUO_BASE . '/users/' . rawurlencode((string)$id), $jwt);
        if ($code !== 200) auth_fail('无法查询 Duolingo 账号');
        $duoData = json_decode((string)$body, true);
        if (!is_array($duoData) || empty($duoData['username'])) auth_fail('Duolingo 账号数据异常');

        $realUsername = $duoData['username'];

        // 检查是否已注册
        $existing = account_load($realUsername);
        $registered = ($existing !== null);

        auth_reply([
            'status'     => 'completed',
            'username'   => $realUsername,
            'registered' => $registered,
            'token'      => $token,
        ]);
        break;

    // ----------------------------------------------------------
    // 设置密码（首次，该多邻国账号第一次使用）
    // ----------------------------------------------------------
    case 'setup':
        $token   = trim((string)($input['token'] ?? ''));
        $jwt     = trim((string)($input['jwt'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if ($token === '') auth_fail('缺少令牌');
        if ($jwt === '')   auth_fail('缺少 JWT');
        if (strlen($password) < 6) auth_fail('密码至少 6 位');

        // 验证令牌
        $tPath = DATA_DIR . '/tokens/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) . '.json';
        if (!is_file($tPath)) auth_fail('令牌无效');
        $tData = json_decode((string)file_get_contents($tPath), true);

        // 向 Duolingo 查询真实用户名
        $id = jwt_sub($jwt);
        if (!$id) auth_fail('JWT 无法解析');

        [$code, $body] = duo_get(DUO_BASE . '/users/' . rawurlencode((string)$id), $jwt);
        if ($code !== 200) auth_fail('无法查询 Duolingo 账号');
        $duoData = json_decode((string)$body, true);
        if (!is_array($duoData) || empty($duoData['username'])) auth_fail('Duolingo 账号数据异常');

        $realUsername = $duoData['username'];

        // 确认未注册
        if (account_load($realUsername) !== null) {
            auth_fail('该账号已注册，请直接登录', 409);
        }

        // 创建账号
        $d = [
            'username'      => $realUsername,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'duolingo_id'   => $id,
            'tier'          => 'free',
            'created_at'    => time(),
        ];
        account_save($realUsername, $d);

        // 标记令牌为已授权
        $tData['authorized'] = true;
        $tData['account']    = $realUsername;
        $tData['username']   = $realUsername;
        $tData['duolingo_id'] = $id;
        file_put_contents($tPath, json_encode($tData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        auth_reply([
            'status'   => 'completed',
            'username' => $realUsername,
            'message'  => '注册成功，请返回 Duolingo 页面。',
        ]);
        break;

    // ----------------------------------------------------------
    // 输入密码登录（第二次及以后）
    // ----------------------------------------------------------
    case 'login':
        $token   = trim((string)($input['token'] ?? ''));
        $jwt     = trim((string)($input['jwt'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if ($token === '') auth_fail('缺少令牌');
        if ($jwt === '')   auth_fail('缺少 JWT');
        if ($password === '') auth_fail('请填写密码');

        // 验证令牌
        $tPath = DATA_DIR . '/tokens/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) . '.json';
        if (!is_file($tPath)) auth_fail('令牌无效');
        $tData = json_decode((string)file_get_contents($tPath), true);

        // 向 Duolingo 查询真实用户名
        $id = jwt_sub($jwt);
        if (!$id) auth_fail('JWT 无法解析');

        [$code, $body] = duo_get(DUO_BASE . '/users/' . rawurlencode((string)$id), $jwt);
        if ($code !== 200) auth_fail('无法查询 Duolingo 账号');
        $duoData = json_decode((string)$body, true);
        if (!is_array($duoData) || empty($duoData['username'])) auth_fail('Duolingo 账号数据异常');

        $realUsername = $duoData['username'];

        // 查找账号
        $existing = account_load($realUsername);
        if (!$existing) auth_fail('该账号不存在，请先注册');

        // 验证密码
        if (!password_verify($password, $existing['password_hash'])) {
            auth_fail('密码错误', 401);
        }

        // 标记令牌为已授权
        $tData['authorized'] = true;
        $tData['account']    = $realUsername;
        $tData['username']   = $realUsername;
        $tData['duolingo_id'] = $id;
        file_put_contents($tPath, json_encode($tData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        auth_reply([
            'status'   => 'completed',
            'username' => $realUsername,
            'message'  => '登录成功，请返回 Duolingo 页面。',
        ]);
        break;

    default:
        auth_fail('未知操作，支持: check / setup / login');
}
