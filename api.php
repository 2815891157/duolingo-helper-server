<?php

require_once __DIR__ . '/lib.php';

// ============================================================
// CORS 与输出
// ============================================================

header('Content-Type: application/json; charset=utf-8');

function cors_origin() {
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o !== '' && preg_match('#^https?://[a-zA-Z0-9.-]+(?::\\d+)?$#', $o)) {
        return $o;
    }
    return '*';
}

header('Access-Control-Allow-Origin: ' . cors_origin());
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

while (ob_get_level()) {
    ob_end_clean();
}
ob_implicit_flush(true);

set_time_limit(MAX_RUNTIME_SECONDS + 10);
$deadline = microtime(true) + MAX_RUNTIME_SECONDS;

// ============================================================
// 解析请求
// ============================================================

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$action = (string)($_GET['action'] ?? '');

// JWT：优先 Authorization Bearer，其次 body
$jwt = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $jwt = trim($m[1]);
}
if ($jwt === '') {
    $jwt = trim((string)($input['jwt'] ?? ''));
}

// 客户端 IP
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);

// 客户端脚本版本号
$client_version = trim((string)($input['version'] ?? ''));
$server_version = get_local_js_version();

// 版本匹配时返回 null（不通知），不匹配时返回更新提示
$version_info = null;
if ($client_version !== '' && $server_version !== null && $client_version !== $server_version) {
    $version_info = [
        'current' => $client_version,
        'latest'  => $server_version,
        'url'     => 'https://duolingo-helper.onrender.com/DuolingoHelper.user.js',
    ];
}

// ============================================================
// 令牌管理
// ============================================================

function token_path($token) {
    return DATA_DIR . '/tokens/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) . '.json';
}

function token_load($token) {
    $path = token_path($token);
    if (!is_file($path)) return null;
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

function token_save($token, $d) {
    $dir = DATA_DIR . '/tokens';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    file_put_contents(token_path($token), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function generate_token() {
    return bin2hex(random_bytes(24));
}

// ============================================================
// 操作分发
// ============================================================

switch ($action) {

    // ----------------------------------------------------------
    // 心跳（无需认证）
    // ----------------------------------------------------------
    case 'server':
        $now = time();
        $modern = [
            'xp'            => true,
            'gems'          => true,
            'streak'        => true,
            'streak_freeze' => true,
            'heart_refill'  => true,
            'quests'        => true,
            'double_xp_boost' => true,
        ];
        // 构造版本信息：始终包含服务器最新版本，客户端版本匹配则标 latest
        $server_ver = $server_version !== null ? $server_version : '1.1.1';
        $versions_resp = [
            $server_ver => ['status' => 'latest', 'warnings' => []],
        ];
        if ($client_version !== '' && $client_version !== $server_ver) {
            $versions_resp[$client_version] = ['status' => 'outdated', 'warnings' => []];
        }
        $resp = [
            'status'   => 'completed',
            'global'   => [
                'terms' => [
                    'version' => '1',
                    'content' => '<p>欢迎使用 DuolingoHelper。使用即表示你同意相关条款与隐私政策。</p>',
                ],
            ],
            'versions' => $versions_resp,
            'script'   => [
                'ping'     => 4000,
                'youtube'  => null,
                'discord'  => null,
                'settings' => [],
                'modern'   => $modern,
            ],
        ];
        push_event($resp);
        break;

    // ----------------------------------------------------------
    // 获取令牌（绑定 IP）
    // ----------------------------------------------------------
    case 'token':
        $token = generate_token();
        $t = [
            'token'      => $token,
            'ip'         => $ip,
            'username'   => null,
            'account'    => null,
            'authorized' => false,
            'created_at' => time(),
        ];
        token_save($token, $t);
        push_event(['status' => 'completed', 'token' => $token]);
        break;

    // ----------------------------------------------------------
    // 检查令牌授权状态（脚本轮询）
    // ----------------------------------------------------------
    case 'check':
        $token = trim((string)($input['token'] ?? ''));
        $t = token_load($token);
        if (!$t || $t['ip'] !== $ip || !$t['authorized']) {
            push_event(['status' => 'pending']);
            break;
        }
        $profile = null;
        if (!empty($t['duolingo_id'])) {
            $profile = load_profile($t['duolingo_id']);
        }
        // 令牌已授权但账号档案不存在（被管理员删除），取消授权
        if (!$profile) {
            $t['authorized'] = false;
            token_save($token, $t);
            push_event([
                'status'       => 'failed',
                'notification' => notify('!', '账号校验失败', '我们已将它移除，请重新登录！', 8),
            ]);
            break;
        }
        if ($client_version !== '' && ($profile['client_version'] ?? '') !== $client_version) {
            $profile['client_version'] = $client_version;
            save_profile($profile);
        }
        $resp = [
            'status'   => 'completed',
            'username' => $t['username'] ?? ($profile['username'] ?? ''),
            'account'  => $t['account'],
            'tier'     => tier_label($profile['_tier'], $profile),
            'tier_raw' => $profile['_tier'],
            'gems'     => $profile['gems'] ?? 0,
            'totalXp'  => $profile['totalXp'] ?? 0,
            'streak'   => $profile['streak'] ?? 0,
            'email'    => $profile['email'] ?? '',
            'phone'    => $profile['phone'] ?? '',
            'stats'    => get_profile_stats($profile),
        ];
        // 版本检查：版本一致则不返回，不一致则带上更新提示
        if ($version_info) {
            $resp['version_update'] = $version_info;
        }
        push_event($resp);
        break;

    // ----------------------------------------------------------
    // 刷取请求（需要令牌授权 + Duolingo JWT）
    // ----------------------------------------------------------
    case 'request':
        $token     = trim((string)($input['token'] ?? ''));
        $type      = (string)($input['type'] ?? '');
        $amount    = max(1, (int)($input['amount'] ?? 1));
        $date      = trim((string)($input['date'] ?? ''));

        $TYPE_MAP = [
            'xp'               => 'xp',
            'gem'              => 'gem',
            'double_xp_boost'  => 'xp_boost',
            'heart_refill'     => 'heart_refill',
            'streak'           => 'streak',
            'streak_freeze'    => 'streak_freeze',
            'quest'            => 'quest',
            'badge'            => 'quest',
        ];
        $type = $TYPE_MAP[$type] ?? $type;

        // 验证令牌
        $t = token_load($token);
        if (!$t || $t['ip'] !== $ip || !$t['authorized']) {
            fail('账号校验失败', '令牌无效或未授权，请刷新页面后重新登录');
            break;
        }

        $id = jwt_sub($jwt);
        if (!$id) {
            fail('无效的登录信息', 'JWT 无法解析，请刷新页面后重新登录');
            break;
        }
        if (!in_array($type, SUPPORTED_TYPES, true)) {
            fail('不支持的类型', '支持的类型: ' . implode(', ', SUPPORTED_TYPES));
            break;
        }

        $p = load_profile($id);
        if (!$p) {
            $p = register_profile($jwt, $id);
            if (!$p) {
                fail('账号登记失败', '无法读取 Duolingo 账号信息');
                break;
            }
        }

        // 绑定令牌到账号
        if (empty($t['duolingo_id'])) {
            $t['duolingo_id'] = $id;
            $t['username'] = $p['username'] ?? $t['username'];
            token_save($token, $t);
        }

        // 记录客户端脚本版本号
        if ($client_version !== '' && ($p['client_version'] ?? '') !== $client_version) {
            $p['client_version'] = $client_version;
        }

        $cfg = get_type_config($p['_tier'], $type);
        if (!$cfg) {
            fail('配置错误', '未找到该类型的额度配置');
            break;
        }

        // 任务补全
        if ($type === 'quest') {
            if ($date === '' && preg_match('/^(\d{2})-(\d{4})$/', (string)($input['amount'] ?? ''), $m)) {
                $date = $m[2] . '-' . $m[1];
            }
            if ($date === '' || !preg_match('/^\d{4}-\d{2}$/', $date)) {
                $date = date('Y-m');
            }
            reset_if_cooled($p, $type, time());
            if (!quota_check($p, $type, 1)) { save_profile($p); break; }
            quota_consume($p, $type, 1);
            append_log($p, $type, ['date' => $date], 'requested');
            save_profile($p);
            $ok = execute_quest($jwt, $p, $date);
            append_log($p, $type, ['date' => $date], $ok ? 'completed' : 'failed');
            if ($ok) update_stats($p, $type, 1);
            save_profile($p);
            break;
        }

        // 其他类型
        reset_if_cooled($p, $type, time());
        if (!quota_check($p, $type, $amount)) { save_profile($p); break; }

        if ($p['pending'] && $p['pending']['type'] === $type) {
            $pending = &$p['pending'];
        } else {
            $p['pending'] = init_job($type, $amount, $jwt, $p);
            if ($p['pending'] === false) {
                fail('任务初始化失败', 'Duolingo 拒绝了初始化请求');
                $p['pending'] = null;
                save_profile($p);
                break;
            }
            $pending = &$p['pending'];
        }

        append_log($p, $type, ['amount' => $amount], 'requested');
        save_profile($p);

        while (time_left($deadline) && !job_done($pending)) {
            $res = run_step($jwt, $p, $pending);
            if ($res === false) {
                fail('执行失败', 'Duolingo 拒绝了该操作，请稍后再试');
                append_log($p, $type, ['amount' => $amount], 'failed');
                $p['pending'] = null;
                save_profile($p);
                break 2;
            }
            $pending['done'] = min($pending['requested'], $pending['done'] + $res['granted']);
            quota_consume($p, $type, $res['granted']);
            save_profile($p);
            $pct = (int)floor($pending['done'] / max(1, $pending['requested']) * 100);
            push_event(['status' => 'loading', 'percentage' => min(100, $pct)]);
        }

        if (job_done($pending)) {
            $got = $pending['done'];
            push_event([
                'status'       => 'completed',
                'amount'       => $got,
                'notification' => notify('✓', '完成', '已到账 ' . $got . ' ' . (UNITS[$type] ?? ''), 8),
            ]);
            append_log($p, $type, ['amount' => $amount, 'got' => $got], 'completed');
            update_stats($p, $type, $got);
            $p['pending'] = null;
            save_profile($p);
        } else {
            $pct = (int)floor($pending['done'] / max(1, $pending['requested']) * 100);
            push_event([
                'status'       => 'loading',
                'percentage'   => min(100, $pct),
                'notification' => notify('⏳', '进行中', '服务器执行时间已到，请再次请求继续', 8),
            ]);
            save_profile($p);
        }
        break;

    // ----------------------------------------------------------
    // 查看额度
    // ----------------------------------------------------------
    case 'status':
        $token = trim((string)($input['token'] ?? ''));
        $t = token_load($token);
        if (!$t || $t['ip'] !== $ip || !$t['authorized']) {
            fail('账号校验失败', '令牌无效或未授权');
            break;
        }
        $id = jwt_sub($jwt);
        $p = $id ? load_profile($id) : null;
        if (!$p) {
            push_event(['status' => 'completed', 'data' => ['tier' => 'free', 'quota' => []]]);
            break;
        }
        $now = time();
        $status = ['tier' => $p['_tier'], 'quota' => []];
        foreach (SUPPORTED_TYPES as $t_name) {
            $cfg = get_type_config($p['_tier'], $t_name);
            if (!$cfg) continue;
            $used = $p['quota'][$t_name]['used'] ?? 0;
            $last = $p['quota'][$t_name]['last_reset'] ?? 0;
            if ($now - $last >= $cfg['cooldown']) {
                $status['quota'][$t_name] = ['remaining' => $cfg['max_total'], 'cooldown_remaining' => 0];
            } else {
                $status['quota'][$t_name] = ['remaining' => max(0, $cfg['max_total'] - $used), 'cooldown_remaining' => $cfg['cooldown'] - ($now - $last)];
            }
        }
        push_event(['status' => 'completed', 'data' => $status]);
        break;

    default:
        fail('未知操作', 'action 支持: server / token / check / request / status');
}
