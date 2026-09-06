<?php

require_once __DIR__ . '/config.php';

// ============================================================
// JWT 解析
// ============================================================

function jwt_sub($jwt)
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return null;
    }
    $raw = $parts[1];
    $pad = (4 - strlen($raw) % 4) % 4;
    $payload = base64_decode(strtr($raw, '-_', '+/') . str_repeat('=', $pad));
    $data = json_decode((string)$payload, true);
    return is_array($data) ? ($data['sub'] ?? null) : null;
}

// ============================================================
// Duolingo 请求封装
// ============================================================

function duo_headers($jwt)
{
    return [
        'authorization: Bearer ' . $jwt,
        'cookie: jwt_token=' . $jwt,
        'connection: Keep-Alive',
        'content-type: application/json',
        'user-agent: Duolingo-Storm/1.0',
        'device-platform: web',
        'x-duolingo-device-platform: web',
        'x-duolingo-app-version: 1.0.0',
        'x-duolingo-application: chrome',
        'x-duolingo-client-version: web',
        'accept: application/json',
    ];
}

function duo_request($method, $url, $jwt, $payload = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => duo_headers($jwt),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}

function duo_get($url, $jwt)  { return duo_request('GET',    $url, $jwt); }
function duo_post($url, $jwt, $payload) { return duo_request('POST',   $url, $jwt, $payload); }
function duo_patch($url, $jwt, $payload) { return duo_request('PATCH',  $url, $jwt, $payload); }
function duo_put($url, $jwt, $payload)  { return duo_request('PUT',    $url, $jwt, $payload); }

// ============================================================
// 流式输出（与作者格式一致）
// ============================================================

function push_event($evt)
{
    echo json_encode($evt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @ob_flush();
    flush();
}

function notify($icon, $head, $body, $duration = 8)
{
    return ['icon' => $icon, 'head' => $head, 'body' => $body, 'duration' => $duration];
}

function fail($head, $body)
{
    push_event(['status' => 'failed', 'notification' => notify('!', $head, $body, 8)]);
}

// ============================================================
// 等级显示格式化
// ============================================================

$TIER_NAMES = [
    'free' => '免费',
    'mid'  => '会员',
    'vip'  => '超级会员',
];

function format_remaining_time($seconds)
{
    if ($seconds <= 0) return '已过期';
    $days = (int)floor($seconds / 86400);
    $hours = (int)floor(($seconds % 86400) / 3600);
    if ($days > 30) {
        $months = (int)floor($days / 30);
        return $months . '月';
    }
    if ($days > 0) {
        return $days . '天' . $hours . '时';
    }
    return $hours . '时';
}

function tier_label($tier, $profile = null)
{
    global $TIER_NAMES;
    $name = $TIER_NAMES[$tier] ?? '免费';
    if ($tier === 'free') {
        return $name . '[永久]';
    }
    // 中等/高级档根据档案里的过期时间计算
    if ($profile && !empty($profile['tier_expires_at'])) {
        $remaining = $profile['tier_expires_at'] - time();
        if ($remaining > 0) {
            return $name . '[' . format_remaining_time($remaining) . ']';
        }
        return $name . '[已过期]';
    }
    // 没有过期时间则按永久显示
    return $name . '[永久]';
}

// ============================================================
// 用户档案管理
// ============================================================

function profile_path($tier, $id)
{
    return DATA_DIR . '/' . $tier . '/' . $id . '.json';
}

function load_profile($id)
{
    // 扫描三个文件夹找这个用户
    foreach (['free', 'mid', 'vip'] as $tier) {
        $path = profile_path($tier, $id);
        if (is_file($path)) {
            $p = json_decode((string)file_get_contents($path), true);
            if (is_array($p)) {
                $p['_tier'] = $tier;
                return $p;
            }
        }
    }
    return null;
}

function save_profile($p)
{
    $tier = $p['_tier'];
    $path = profile_path($tier, $p['id']);
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents($path, json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ============================================================
// 从 Duolingo 获取完整账号信息
// ============================================================

function fetch_duo_full_info($jwt, $id)
{
    [$code, $body] = duo_get(DUO_BASE . '/users/' . rawurlencode((string)$id), $jwt);
    if ($code !== 200) return null;
    $data = json_decode($body, true);
    if (!is_array($data)) return null;
    return [
        'username'     => $data['username'] ?? null,
        'email'        => $data['email'] ?? null,
        'phone'        => $data['phoneNumber'] ?? $data['phone'] ?? null,
        'totalXp'      => $data['totalXp'] ?? 0,
        'gems'         => $data['gems'] ?? 0,
        'streak'       => $data['streakData']['currentStreak']['length'] ?? 0,
        'fromLanguage' => $data['fromLanguage'] ?? FROM_LANG,
        'learningLanguage' => $data['learningLanguage'] ?? LEARN_LANG,
    ];
}

function register_profile($jwt, $id, $tier = 'free')
{
    $info = fetch_duo_full_info($jwt, $id);
    if (!$info) {
        return null;
    }
    $p = [
        'id'               => $id,
        'username'         => $info['username'] ?? 'unknown',
        'email'            => $info['email'] ?? null,
        'phone'            => $info['phone'] ?? null,
        'duolingo_password' => '',
        'totalXp'          => $info['totalXp'] ?? 0,
        'gems'             => $info['gems'] ?? 0,
        'streak'           => $info['streak'] ?? 0,
        'fromLanguage'     => $info['fromLanguage'] ?? FROM_LANG,
        'learningLanguage' => $info['learningLanguage'] ?? LEARN_LANG,
        'created_at'       => time(),
        'quota'            => [],
        'pending'          => null,
        'history'          => [],
        'stats'            => ['xp' => 0, 'gem' => 0, 'streak' => 0, 'heart_refill' => 0, 'streak_freeze' => 0, 'double_xp_boost' => 0, 'quest' => 0],
        '_tier'            => $tier,
    ];
    save_profile($p);
    return $p;
}

function refresh_snapshot($jwt, &$p)
{
    $info = fetch_duo_full_info($jwt, $p['id']);
    if (!$info) return;
    foreach (['username', 'email', 'phone', 'totalXp', 'gems', 'streak', 'fromLanguage', 'learningLanguage'] as $f) {
        if ($info[$f] !== null) {
            $p[$f] = $info[$f];
        }
    }
}

// ============================================================
// 读取服务器本地 JS 脚本版本号
// ============================================================

function get_local_js_version()
{
    $jsPath = __DIR__ . '/DuolingoHelper.user.js';
    if (!is_file($jsPath)) return null;
    $fp = fopen($jsPath, 'r');
    if (!$fp) return null;
    $lines = 0;
    while ($lines < 20 && !feof($fp)) {
        $line = fgets($fp);
        if ($line === false) break;
        $lines++;
        if (preg_match('/@version\s+(\S+)/', $line, $m)) {
            fclose($fp);
            return trim($m[1]);
        }
    }
    fclose($fp);
    return null;
}

// ============================================================
// 额度与冷却管理（每个类型独立）
// ============================================================

function get_type_config($tier, $type)
{
    return TIERS[$tier][$type] ?? null;
}

function reset_if_cooled(&$p, $type, $now)
{
    $cfg = get_type_config($p['_tier'], $type);
    if (!$cfg) return;
    $q = $p['quota'][$type] ?? null;
    if ($q === null) {
        // 首次请求该类型，初始化额度池
        $p['quota'][$type] = ['used' => 0, 'last_reset' => $now];
    } elseif ($now - (int)$q['last_reset'] >= $cfg['cooldown']) {
        // 冷却到了，额度重置
        $p['quota'][$type] = ['used' => 0, 'last_reset' => $now];
    }
}

function quota_check(&$p, $type, $amount)
{
    $cfg = get_type_config($p['_tier'], $type);
    if (!$cfg) return false;
    $q = $p['quota'][$type] ?? ['used' => 0, 'last_reset' => 0];

    // 单次上限
    if ($amount > $cfg['max_single']) {
        push_event([
            'status'       => 'rejected',
            'max_amount'   => $cfg['max_single'],
            'notification' => notify('!', '超出单次上限', '该类型单次最多 ' . $cfg['max_single'], 8),
        ]);
        return false;
    }

    // 周期总额度
    $remaining = $cfg['max_total'] - $q['used'];
    if ($remaining <= 0) {
        $cooldown_left = $cfg['cooldown'] - (time() - (int)$q['last_reset']);
        $minutes = max(1, (int)ceil($cooldown_left / 60));
        push_event([
            'status'       => 'rejected',
            'max_amount'   => 0,
            'notification' => notify('!', '额度已用完', '还需等待约 ' . $minutes . ' 分钟后重置', 8),
        ]);
        return false;
    }
    if ($amount > $remaining) {
        push_event([
            'status'       => 'rejected',
            'max_amount'   => $remaining,
            'notification' => notify('!', '剩余额度不足', '该类型剩余 ' . $remaining . '，请减少请求量', 8),
        ]);
        return false;
    }

    return true;
}

function quota_consume(&$p, $type, $amount)
{
    if (!isset($p['quota'][$type])) {
        $p['quota'][$type] = ['used' => 0, 'last_reset' => time()];
    }
    $p['quota'][$type]['used'] += $amount;
}

function append_log(&$p, $type, $extra, $status)
{
    $p['history'][] = [
        'at'     => date('Y-m-d H:i:s'),
        'type'   => $type,
        'extra'  => $extra,
        'status' => $status,
    ];
    if (count($p['history']) > 500) {
        $p['history'] = array_slice($p['history'], -500);
    }
}

function update_stats(&$p, $type, $amount)
{
    if (!isset($p['stats'])) {
        $p['stats'] = ['xp' => 0, 'gem' => 0, 'streak' => 0, 'heart_refill' => 0, 'streak_freeze' => 0, 'double_xp_boost' => 0, 'quest' => 0];
    }
    if (isset($p['stats'][$type])) {
        $p['stats'][$type] += $amount;
    }
}

function get_profile_stats($p)
{
    return $p['stats'] ?? ['xp' => 0, 'gem' => 0, 'streak' => 0, 'heart_refill' => 0, 'streak_freeze' => 0, 'double_xp_boost' => 0, 'quest' => 0];
}

// ============================================================
// 任务执行（断点续刷）
// ============================================================

function job_done($pending)
{
    return $pending['done'] >= $pending['requested'];
}

function time_left($deadline)
{
    return microtime(true) < $deadline;
}

function init_job($type, $amount, $jwt, &$p)
{
    if ($type === 'streak') {
        $meta = init_streak_meta($jwt, $p);
        if ($meta === false) return false;
        return ['type' => $type, 'requested' => $amount, 'done' => 0, 'meta' => $meta];
    }
    return ['type' => $type, 'requested' => $amount, 'done' => 0, 'meta' => null];
}

function init_streak_meta($jwt, &$p)
{
    [$code, $body] = duo_get(DUO_BASE . '/users/' . $p['id'], $jwt);
    if ($code !== 200) return false;
    $info = json_decode($body, true);
    $startDate = $info['streakData']['currentStreak']['startDate'] ?? null;
    if ($startDate) {
        $farmStart = strtotime($startDate) - 86400;
    } else {
        $farmStart = strtotime(date('Y-m-d')) - 86400;
    }
    return ['sim_day_ts' => $farmStart];
}

function run_step($jwt, &$p, &$pending)
{
    switch ($pending['type']) {
        case 'xp':            return step_xp($jwt, $pending);
        case 'gem':           return step_gem($jwt, $p);
        case 'xp_boost':      return step_xp_boost($jwt, $p);
        case 'heart_refill':  return step_heart_refill($jwt, $p);
        case 'streak':        return step_streak($jwt, $p, $pending);
        case 'streak_freeze': return step_streak_freeze($jwt, $p);
    }
    return false;
}

// ============================================================
// 各类型执行步骤
// ============================================================

function step_xp($jwt, &$pending)
{
    $remain = $pending['requested'] - $pending['done'];
    if ($remain >= XP_PER_STORY) {
        $bonus = XP_BONUS_MAX;
    } elseif ($remain >= XP_MIN) {
        $bonus = min($remain - XP_MIN, XP_BONUS_MAX);
    } else {
        $bonus = 0;
    }
    $duration = random_int(300, 420);
    $now = time();
    $payload = [
        'awardXp'                      => true,
        'completedBonusChallenge'      => true,
        'fromLanguage'                 => FROM_LANG,
        'learningLanguage'             => LEARN_LANG,
        'hasXpBoost'                   => false,
        'illustrationFormat'           => 'svg',
        'isFeaturedStoryInPracticeHub' => true,
        'isLegendaryMode'              => true,
        'isV2Redo'                     => false,
        'isV2Story'                    => false,
        'masterVersion'                => true,
        'maxScore'                     => 0,
        'score'                        => 0,
        'happyHourBonusXp'             => $bonus,
        'startTime'                    => $now,
        'endTime'                      => $now + $duration,
    ];
    [$code, $body] = duo_post(STORIES_URL . '/' . STORY_SLUG . '/complete', $jwt, $payload);
    if ($code !== 200) return false;
    $data = json_decode($body, true);
    $awarded = (int)($data['awardedXp'] ?? 0);
    return ['granted' => max(0, $awarded)];
}

function step_gem($jwt, &$p)
{
    $rewards = GEM_REWARDS;
    shuffle($rewards);
    foreach ($rewards as $reward) {
        $payload = [
            'consumed'         => true,
            'fromLanguage'     => $p['fromLanguage'] ?? FROM_LANG,
            'learningLanguage' => $p['learningLanguage'] ?? LEARN_LANG,
        ];
        [$code] = duo_patch(DUO_BASE . '/users/' . $p['id'] . '/rewards/' . $reward, $jwt, $payload);
        if ($code !== 200) return false;
    }
    return ['granted' => 1];
}

function step_xp_boost($jwt, &$p)
{
    $payload = [
        'itemName' => 'xp_boost_15',
        'isFree'   => true,
        'consumed' => true,
        'fromLanguage'     => $p['fromLanguage'] ?? FROM_LANG,
        'learningLanguage' => $p['learningLanguage'] ?? LEARN_LANG,
    ];
    [$code] = duo_post(DUO_BASE . '/users/' . $p['id'] . '/shop-items', $jwt, $payload);
    return $code === 200 ? ['granted' => 1] : false;
}

function step_heart_refill($jwt, &$p)
{
    $payload = [
        'itemName' => 'health_refill',
        'isFree'   => true,
        'consumed' => true,
        'fromLanguage'     => $p['fromLanguage'] ?? FROM_LANG,
        'learningLanguage' => $p['learningLanguage'] ?? LEARN_LANG,
    ];
    [$code] = duo_post(DUO_BASE . '/users/' . $p['id'] . '/shop-items', $jwt, $payload);
    return $code === 200 ? ['granted' => 1] : false;
}

function step_streak($jwt, &$p, &$pending)
{
    $session = [
        'challengeTypes'   => CHALLENGE_TYPES,
        'fromLanguage'     => $p['fromLanguage'] ?? FROM_LANG,
        'isFinalLevel'     => false,
        'isV2'             => true,
        'juicy'            => true,
        'learningLanguage' => $p['learningLanguage'] ?? LEARN_LANG,
        'smartTipsVersion' => 2,
        'type'             => 'GLOBAL_PRACTICE',
    ];
    [$code, $body] = duo_post(SESSIONS_URL, $jwt, $session);
    if ($code !== 200) return false;
    $sess = json_decode($body, true);
    $sid = $sess['id'] ?? null;
    if (!$sid) return false;
    $end   = (int)$pending['meta']['sim_day_ts'];
    $start = $end - 1;
    $update = array_merge($sess, [
        'heartsLeft'        => 5,
        'startTime'         => $start,
        'endTime'           => $end,
        'enableBonusPoints' => false,
        'failed'            => false,
        'maxInLessonStreak' => 9,
        'shouldLearnThings' => true,
    ]);
    [$code2] = duo_put(SESSIONS_URL . '/' . $sid, $jwt, $update);
    if ($code2 !== 200) return false;
    $pending['meta']['sim_day_ts'] = $end - 86400;
    return ['granted' => 1];
}

function step_streak_freeze($jwt, &$p)
{
    $payload = [
        'itemName' => 'streak_freeze',
        'isFree'   => true,
        'consumed' => true,
        'fromLanguage'     => $p['fromLanguage'] ?? FROM_LANG,
        'learningLanguage' => $p['learningLanguage'] ?? LEARN_LANG,
    ];
    [$code] = duo_post(DUO_BASE . '/users/' . $p['id'] . '/shop-items', $jwt, $payload);
    return $code === 200 ? ['granted' => 1] : false;
}

// ============================================================
// 任务补全（输入日期，查了再领）
// ============================================================

function execute_quest($jwt, &$p, $date_str)
{
    // 解析日期，格式 "2026-09"
    if (!preg_match('/^\d{4}-\d{2}$/', $date_str)) {
        push_event([
            'status'       => 'failed',
            'notification' => notify('!', '日期格式错误', '请使用 YYYY-MM 格式（如 2026-09）', 8),
        ]);
        return false;
    }

    list($year, $month) = explode('-', $date_str);
    $year  = (int)$year;
    $month = (int)$month;

    // 获取用户时区
    [$code, $body] = duo_get(DUO_BASE . '/users/' . $p['id'], $jwt);
    if ($code !== 200) {
        push_event(['status' => 'failed', 'notification' => notify('!', '查询失败', '无法读取用户信息', 8)]);
        return false;
    }
    $info = json_decode($body, true);
    $tzName = ($info['timezone'] ?? $info['tz'] ?? 'UTC') ?: 'UTC';
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
        $tzName = 'UTC';
    }

    // 构造该月的日期（该月当天，如果该月没有该天则取最后一天）
    $lastDay = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $day = min(15, $lastDay); // 取15号，足够代表该月
    $ts = mktime(0, 0, 0, $month, $day, $year);
    $dt = new DateTime('@' . $ts);
    $dt->setTimezone($tz);

    // 拉取任务指标
    [$schema_code, $schema_body] = duo_get(GOALS_API_URL . '/schema?ui_language=en', $jwt);
    if ($schema_code !== 200) {
        push_event(['status' => 'failed', 'notification' => notify('!', '查询失败', '无法获取任务指标', 8)]);
        return false;
    }
    $schema = json_decode($schema_body, true);
    if (!is_array($schema)) {
        push_event(['status' => 'failed', 'notification' => notify('!', '查询失败', '任务数据异常', 8)]);
        return false;
    }

    $metrics = [];
    foreach (($schema['goals'] ?? []) as $goal) {
        $m = $goal['metric'] ?? null;
        if ($m && !in_array($m, $metrics, true)) {
            $metrics[] = $m;
        }
    }
    if (!$metrics) {
        push_event(['status' => 'failed', 'notification' => notify('!', '查询失败', '无可用任务指标', 8)]);
        return false;
    }
    if (!in_array('QUESTS', $metrics, true)) {
        $metrics[] = 'QUESTS';
    }

    $metricUpdates = [];
    foreach ($metrics as $m) {
        $metricUpdates[] = ['metric' => $m, 'quantity' => $m === 'QUESTS' ? 1 : 2000];
    }

    // 检查该月任务是否已完成（查询该月进度）
    $checkPayload = [
        'metric_updates' => [['metric' => 'QUESTS', 'quantity' => 0]],
        'timestamp'      => $dt->format('Y-m-d\TH:i:s.000\Z'),
        'timezone'       => $tzName,
    ];
    [$checkCode] = duo_post(GOALS_API_URL . '/users/' . $p['id'] . '/progress/batch', $jwt, $checkPayload);

    // 直接执行灌进度
    $payload = [
        'metric_updates' => $metricUpdates,
        'timestamp'      => $dt->format('Y-m-d\TH:i:s.000\Z'),
        'timezone'       => $tzName,
    ];

    [$exec_code, $exec_body] = duo_post(GOALS_API_URL . '/users/' . $p['id'] . '/progress/batch', $jwt, $payload);

    if ($exec_code === 200) {
        push_event([
            'status'       => 'completed',
            'date'         => $date_str,
            'notification' => notify('✓', '任务补全成功', $year . '年' . $month . '月任务进度已灌满', 8),
        ]);
        return true;
    } else {
        // 可能该月徽章已获取
        push_event([
            'status'       => 'rejected',
            'date'         => $date_str,
            'notification' => notify('!', '该月徽章已获取', $year . '年' . $month . '月任务无需重复补全', 8),
        ]);
        return false;
    }
}
