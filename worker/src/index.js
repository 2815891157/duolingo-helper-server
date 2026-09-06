// DuolingoHelper 服务器（Cloudflare Workers 版）
// 与原 PHP 后端保持相同协议：NDJSON 事件流、api.php/auth.php/static.php 端点

const SERVER_VERSION = '1.1.1';

// ============ Duolingo 常量 ============
const DUO_BASE = 'https://www.duolingo.com/2017-06-30';
const SESSIONS_URL = DUO_BASE + '/sessions';
const STORIES_URL = 'https://stories.duolingo.com/api2/stories';
const GOALS_API_URL = 'https://goals-api.duolingo.com';
const STORY_SLUG = 'fr-en-le-passeport';
const FROM_LANG = 'en';
const LEARN_LANG = 'fr';
const XP_PER_STORY = 499;
const XP_MIN = 30;
const XP_BONUS_MAX = 469;

const GEM_REWARDS = ['SKILL_COMPLETION_BALANCED-…-2-GEMS', 'SKILL_COMPLETION_BALANCED-…-2-GEMS'];

const CHALLENGE_TYPES = [
  'assist','characterIntro','characterMatch','characterPuzzle','characterSelect','characterTrace',
  'characterWrite','completeReverseTranslation','definition','dialogue','extendedMatch',
  'extendedListenMatch','form','freeResponse','gapFill','judge','listen','listenComplete',
  'listenMatch','match','name','listenComprehension','listenIsolation','listenSpeak','listenTap',
  'listenTap','orderTapComplete','partialListen','partialReverseTranslate','patternTapComplete',
  'radioBinary','radioImageSelect','radioListenMatch','radioListenRecognize','radioSelect',
  'readComprehension','reverseAssist','sameDifferent','select','selectPronunciation',
  'selectTranscription','svgPuzzle','syllableTap','syllableListenTap','speak','tapCloze',
  'tapClozeTable','tapComplete','tapCompleteTable','tapDescribe','translate','transliterate',
  'transliterationAssist','typeCloze','typeClozeTable','typeComplete','typeCompleteTable',
  'writeComprehension'
];

const UNITS = {
  xp: '经验', gem: '宝石', xp_boost: '经验翻倍', heart_refill: '红心补满',
  streak: '增加连胜', streak_freeze: '连胜保护', quest: '任务补全'
};

const TIERS = {
  free: {
    xp:            { max_single: 10000, max_total: 50000, cooldown: 7200 },
    gem:           { max_single: 100,   max_total: 500,   cooldown: 14400 },
    xp_boost:      { max_single: 1,     max_total: 1,     cooldown: 120 },
    heart_refill:  { max_single: 1,     max_total: 1,     cooldown: 120 },
    streak:        { max_single: 15,    max_total: 30,    cooldown: 7200 },
    streak_freeze: { max_single: 2,     max_total: 4,     cooldown: 86400 },
    quest:         { max_single: 1,     max_total: 5,     cooldown: 3600 },
  },
  mid: {
    xp:            { max_single: 20000, max_total: 80000, cooldown: 1800 },
    gem:           { max_single: 200,   max_total: 600,   cooldown: 1800 },
    xp_boost:      { max_single: 1,     max_total: 1,     cooldown: 30 },
    heart_refill:  { max_single: 1,     max_total: 1,     cooldown: 30 },
    streak:        { max_single: 30,    max_total: 60,    cooldown: 1800 },
    streak_freeze: { max_single: 4,     max_total: 8,     cooldown: 43200 },
    quest:         { max_single: 1,     max_total: 10,    cooldown: 1800 },
  },
  vip: {
    xp:            { max_single: 20000, max_total: 80000, cooldown: 1800 },
    gem:           { max_single: 200,   max_total: 600,   cooldown: 1800 },
    xp_boost:      { max_single: 1,     max_total: 1,     cooldown: 30 },
    heart_refill:  { max_single: 1,     max_total: 1,     cooldown: 30 },
    streak:        { max_single: 30,    max_total: 60,    cooldown: 1800 },
    streak_freeze: { max_single: 4,     max_total: 8,     cooldown: 43200 },
    quest:         { max_single: 1,     max_total: 10,    cooldown: 1800 },
  },
};

const SUPPORTED_TYPES = ['xp','gem','xp_boost','heart_refill','streak','streak_freeze','quest'];
const TIER_NAMES = { free: '免费', mid: '会员', vip: '超级会员' };
const MAX_RUNTIME_MS = 22000;

// ============ 工具 ============
function now() { return Math.floor(Date.now() / 1000); }

function jwtSub(jwt) {
  try {
    const parts = String(jwt).split('.');
    if (parts.length < 2) return null;
    let raw = parts[1].replace(/-/g, '+').replace(/_/g, '/');
    while (raw.length % 4) raw += '=';
    const data = JSON.parse(new TextDecoder().decode(base64ToBytes(raw)));
    return data && typeof data.sub !== 'undefined' ? data.sub : null;
  } catch (e) { return null; }
}

function base64ToBytes(b64) {
  const bin = atob(b64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return bytes;
}

function bytesToBase64(bytes) {
  let bin = '';
  for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
  return btoa(bin);
}

function randomToken() {
  const bytes = crypto.getRandomValues(new Uint8Array(24));
  return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

function randomSalt() {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

async function hashPassword(password, salt) {
  const enc = new TextEncoder();
  const km = await crypto.subtle.importKey('raw', enc.encode(password), 'PBKDF2', false, ['deriveBits']);
  const bits = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: enc.encode(salt), iterations: 100000, hash: 'SHA-256' },
    km, 256
  );
  return Array.from(new Uint8Array(bits)).map(b => b.toString(16).padStart(2, '0')).join('');
}

function fmtTime(ts) {
  const d = new Date(ts * 1000);
  const p = n => String(n).padStart(2, '0');
  return d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate()) + ' ' +
         p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds());
}

function formatRemainingTime(seconds) {
  if (seconds <= 0) return '已过期';
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  if (days > 30) return Math.floor(days / 30) + '月';
  if (days > 0) return days + '天' + hours + '时';
  return hours + '时';
}

function tierLabel(tier, profile) {
  const name = TIER_NAMES[tier] || '免费';
  if (tier === 'free') return name + '[永久]';
  if (profile && profile.tier_expires_at) {
    const remaining = profile.tier_expires_at - now();
    if (remaining > 0) return name + '[' + formatRemainingTime(remaining) + ']';
    return name + '[已过期]';
  }
  return name + '[永久]';
}

function notify(icon, head, body, duration = 8) {
  return { icon, head, body, duration };
}

// ============ KV 数据层 ============
function profileKey(tier, id) { return 'profiles/' + tier + '/' + String(id); }
function accountKey(username) { return 'accounts/' + encodeURIComponent(String(username)); }
function tokenKey(token) { return 'tokens/' + String(token).replace(/[^a-zA-Z0-9_-]/g, ''); }

async function kvGet(env, key) {
  try { return await env.DATA.get(key, 'json'); } catch (e) { return null; }
}
async function kvSet(env, key, val) {
  await env.DATA.put(key, JSON.stringify(val));
}
async function kvDel(env, key) {
  await env.DATA.delete(key);
}

async function loadProfile(env, id) {
  for (const tier of ['free', 'mid', 'vip']) {
    const p = await kvGet(env, profileKey(tier, id));
    if (p) { p._tier = tier; return p; }
  }
  return null;
}
async function saveProfile(env, p) {
  await kvSet(env, profileKey(p._tier, p.id), p);
}
async function deleteProfile(env, id) {
  for (const tier of ['free', 'mid', 'vip']) {
    if (await kvGet(env, profileKey(tier, id))) await kvDel(env, profileKey(tier, id));
  }
}
async function loadToken(env, token) {
  return kvGet(env, tokenKey(token));
}
async function saveToken(env, token, t) {
  await kvSet(env, tokenKey(token), t);
}
async function loadAccount(env, username) {
  return kvGet(env, accountKey(username));
}
async function saveAccount(env, username, d) {
  await kvSet(env, accountKey(username), d);
}

// ============ Duolingo 请求 ============
function duoHeaders(jwt) {
  return {
    'authorization': 'Bearer ' + jwt,
    'cookie': 'jwt_token=' + jwt,
    'content-type': 'application/json',
    'user-agent': 'Duolingo-Storm/1.0',
    'device-platform': 'web',
    'x-duolingo-device-platform': 'web',
    'x-duolingo-app-version': '1.0.0',
    'x-duolingo-application': 'chrome',
    'x-duolingo-client-version': 'web',
    'accept': 'application/json',
  };
}

async function duoRequest(method, url, jwt, payload) {
  const opts = { method, headers: duoHeaders(jwt), redirect: 'follow' };
  if (payload !== undefined && payload !== null) {
    opts.body = JSON.stringify(payload);
  }
  const res = await fetch(url, opts);
  const body = await res.text();
  return [res.status, body];
}
const duoGet = (url, jwt) => duoRequest('GET', url, jwt);
const duoPost = (url, jwt, payload) => duoRequest('POST', url, jwt, payload);
const duoPatch = (url, jwt, payload) => duoRequest('PATCH', url, jwt, payload);
const duoPut = (url, jwt, payload) => duoRequest('PUT', url, jwt, payload);

// ============ 账号信息 ============
async function fetchDuoFullInfo(jwt, id) {
  const [code, body] = await duoGet(DUO_BASE + '/users/' + encodeURIComponent(String(id)), jwt);
  if (code !== 200) return null;
  let data;
  try { data = JSON.parse(body); } catch (e) { return null; }
  if (!data || typeof data !== 'object') return null;
  return {
    username: data.username ?? null,
    email: data.email ?? null,
    phone: data.phoneNumber ?? data.phone ?? null,
    totalXp: data.totalXp ?? 0,
    gems: data.gems ?? 0,
    streak: (data.streakData && data.streakData.currentStreak && data.streakData.currentStreak.length) || 0,
    fromLanguage: data.fromLanguage ?? FROM_LANG,
    learningLanguage: data.learningLanguage ?? LEARN_LANG,
  };
}

function emptyStats() {
  return { xp: 0, gem: 0, streak: 0, heart_refill: 0, streak_freeze: 0, double_xp_boost: 0, quest: 0 };
}

async function registerProfile(env, jwt, id, tier = 'free') {
  const info = await fetchDuoFullInfo(jwt, id);
  if (!info) return null;
  const p = {
    id, username: info.username ?? 'unknown', email: info.email ?? null, phone: info.phone ?? null,
    duolingo_password: '', totalXp: info.totalXp ?? 0, gems: info.gems ?? 0, streak: info.streak ?? 0,
    fromLanguage: info.fromLanguage ?? FROM_LANG, learningLanguage: info.learningLanguage ?? LEARN_LANG,
    created_at: now(), quota: {}, pending: null, history: [], stats: emptyStats(), _tier: tier,
  };
  await saveProfile(env, p);
  return p;
}

async function refreshSnapshot(env, jwt, p) {
  const info = await fetchDuoFullInfo(jwt, p.id);
  if (!info) return;
  for (const f of ['username','email','phone','totalXp','gems','streak','fromLanguage','learningLanguage']) {
    if (info[f] !== null && info[f] !== undefined) p[f] = info[f];
  }
}

// ============ 额度与冷却 ============
function getTypeConfig(tier, type) {
  const t = TIERS[tier] || TIERS.free;
  return t[type] || null;
}

function resetIfCooled(p, type, tnow) {
  const cfg = getTypeConfig(p._tier, type);
  if (!cfg) return;
  const q = p.quota[type] || null;
  if (q === null) {
    p.quota[type] = { used: 0, last_reset: tnow };
  } else if (tnow - (q.last_reset || 0) >= cfg.cooldown) {
    p.quota[type] = { used: 0, last_reset: tnow };
  }
}

function quotaCheck(events, p, type, amount) {
  const cfg = getTypeConfig(p._tier, type);
  if (!cfg) return false;
  const q = p.quota[type] || { used: 0, last_reset: 0 };
  if (amount > cfg.max_single) {
    events.push({ status: 'rejected', max_amount: cfg.max_single, notification: notify('!', '超出单次上限', '该类型单次最多 ' + cfg.max_single, 8) });
    return false;
  }
  const remaining = cfg.max_total - (q.used || 0);
  if (remaining <= 0) {
    const coolLeft = cfg.cooldown - (now() - (q.last_reset || 0));
    const minutes = Math.max(1, Math.ceil(coolLeft / 60));
    events.push({ status: 'rejected', max_amount: 0, notification: notify('!', '额度已用完', '还需等待约 ' + minutes + ' 分钟后重置', 8) });
    return false;
  }
  if (amount > remaining) {
    events.push({ status: 'rejected', max_amount: remaining, notification: notify('!', '剩余额度不足', '该类型剩余 ' + remaining + '，请减少请求量', 8) });
    return false;
  }
  return true;
}

function quotaConsume(p, type, amount) {
  if (!p.quota[type]) p.quota[type] = { used: 0, last_reset: now() };
  p.quota[type].used += amount;
}

function appendLog(p, type, extra, status) {
  p.history.push({ at: fmtTime(now()), type, extra, status });
  if (p.history.length > 500) p.history = p.history.slice(-500);
}

function updateStats(p, type, amount) {
  if (!p.stats) p.stats = emptyStats();
  if (typeof p.stats[type] === 'number') p.stats[type] += amount;
}

// ============ 任务执行 ============
function jobDone(pending) { return pending.done >= pending.requested; }

async function initJob(type, amount, jwt, p) {
  if (type === 'streak') {
    const meta = await initStreakMeta(jwt, p);
    if (meta === false) return false;
    return { type, requested: amount, done: 0, meta };
  }
  return { type, requested: amount, done: 0, meta: null };
}

async function initStreakMeta(jwt, p) {
  const [code, body] = await duoGet(DUO_BASE + '/users/' + p.id, jwt);
  if (code !== 200) return false;
  let info;
  try { info = JSON.parse(body); } catch (e) { return false; }
  const startDate = (info.streakData && info.streakData.currentStreak && info.streakData.currentStreak.startDate) || null;
  let farmStart;
  if (startDate) {
    farmStart = Math.floor(Date.parse(startDate) / 1000) - 86400;
  } else {
    const d = new Date();
    farmStart = Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()) / 1000 - 86400;
  }
  return { sim_day_ts: farmStart };
}

async function stepXp(jwt, pending) {
  const remain = pending.requested - pending.done;
  let bonus;
  if (remain >= XP_PER_STORY) bonus = XP_BONUS_MAX;
  else if (remain >= XP_MIN) bonus = Math.min(remain - XP_MIN, XP_BONUS_MAX);
  else bonus = 0;
  const duration = 300 + Math.floor(Math.random() * 121);
  const tnow = now();
  const payload = {
    awardXp: true, completedBonusChallenge: true, fromLanguage: FROM_LANG, learningLanguage: LEARN_LANG,
    hasXpBoost: false, illustrationFormat: 'svg', isFeaturedStoryInPracticeHub: true,
    isLegendaryMode: true, isV2Redo: false, isV2Story: false, masterVersion: true,
    maxScore: 0, score: 0, happyHourBonusXp: bonus, startTime: tnow, endTime: tnow + duration,
  };
  const [code, body] = await duoPost(STORIES_URL + '/' + STORY_SLUG + '/complete', jwt, payload);
  if (code !== 200) return false;
  let data;
  try { data = JSON.parse(body); } catch (e) { return false; }
  const awarded = parseInt(data.awardedXp || 0, 10);
  return { granted: Math.max(0, awarded) };
}

async function stepGem(jwt, p) {
  const rewards = GEM_REWARDS.slice().sort(() => Math.random() - 0.5);
  for (const reward of rewards) {
    const payload = {
      consumed: true, fromLanguage: p.fromLanguage || FROM_LANG, learningLanguage: p.learningLanguage || LEARN_LANG,
    };
    const [code] = await duoPatch(DUO_BASE + '/users/' + p.id + '/rewards/' + reward, jwt, payload);
    if (code !== 200) return false;
  }
  return { granted: 1 };
}

async function stepShopItem(jwt, p, itemName) {
  const payload = {
    itemName, isFree: true, consumed: true,
    fromLanguage: p.fromLanguage || FROM_LANG, learningLanguage: p.learningLanguage || LEARN_LANG,
  };
  const [code] = await duoPost(DUO_BASE + '/users/' + p.id + '/shop-items', jwt, payload);
  return code === 200 ? { granted: 1 } : false;
}

async function stepStreak(jwt, p, pending) {
  const session = {
    challengeTypes: CHALLENGE_TYPES, fromLanguage: p.fromLanguage || FROM_LANG, isFinalLevel: false,
    isV2: true, juicy: true, learningLanguage: p.learningLanguage || LEARN_LANG,
    smartTipsVersion: 2, type: 'GLOBAL_PRACTICE',
  };
  const [code, body] = await duoPost(SESSIONS_URL, jwt, session);
  if (code !== 200) return false;
  let sess;
  try { sess = JSON.parse(body); } catch (e) { return false; }
  const sid = sess.id || null;
  if (!sid) return false;
  const end = parseInt(pending.meta.sim_day_ts, 10);
  const start = end - 1;
  const update = Object.assign({}, sess, {
    heartsLeft: 5, startTime: start, endTime: end, enableBonusPoints: false,
    failed: false, maxInLessonStreak: 9, shouldLearnThings: true,
  });
  const [code2] = await duoPut(SESSIONS_URL + '/' + sid, jwt, update);
  if (code2 !== 200) return false;
  pending.meta.sim_day_ts = end - 86400;
  return { granted: 1 };
}

async function runStep(jwt, p, pending) {
  switch (pending.type) {
    case 'xp': return await stepXp(jwt, pending);
    case 'gem': return await stepGem(jwt, p);
    case 'xp_boost': return await stepShopItem(jwt, p, 'xp_boost_15');
    case 'heart_refill': return await stepShopItem(jwt, p, 'health_refill');
    case 'streak': return await stepStreak(jwt, p, pending);
    case 'streak_freeze': return await stepShopItem(jwt, p, 'streak_freeze');
  }
  return false;
}

// ============ 任务补全 ============
async function executeQuest(events, jwt, p, dateStr) {
  if (!/^\d{4}-\d{2}$/.test(dateStr)) {
    events.push({ status: 'failed', notification: notify('!', '日期格式错误', '请使用 YYYY-MM 格式（如 2026-09）', 8) });
    return false;
  }
  const [year, month] = dateStr.split('-').map(Number);

  const [code, body] = await duoGet(DUO_BASE + '/users/' + p.id, jwt);
  if (code !== 200) {
    events.push({ status: 'failed', notification: notify('!', '查询失败', '无法读取用户信息', 8) });
    return false;
  }
  let info;
  try { info = JSON.parse(body); } catch (e) { info = {}; }
  let tzName = (info.timezone || info.tz || 'UTC') || 'UTC';
  let tzOK = true;
  try { new Intl.DateTimeFormat('en-US', { timeZone: tzName }); } catch (e) { tzOK = false; }
  if (!tzOK) tzName = 'UTC';

  const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
  const day = Math.min(15, lastDay);
  const ts = Date.UTC(year, month - 1, day);
  const iso = new Date(ts).toISOString().replace(/\.\d{3}Z$/, '.000Z');

  const [sc, sb] = await duoGet(GOALS_API_URL + '/schema?ui_language=en', jwt);
  if (sc !== 200) {
    events.push({ status: 'failed', notification: notify('!', '查询失败', '无法获取任务指标', 8) });
    return false;
  }
  let schema;
  try { schema = JSON.parse(sb); } catch (e) { schema = null; }
  if (!schema || typeof schema !== 'object') {
    events.push({ status: 'failed', notification: notify('!', '查询失败', '任务数据异常', 8) });
    return false;
  }
  const metrics = [];
  for (const goal of (schema.goals || [])) {
    const m = goal.metric || null;
    if (m && metrics.indexOf(m) === -1) metrics.push(m);
  }
  if (!metrics.length) {
    events.push({ status: 'failed', notification: notify('!', '查询失败', '无可用任务指标', 8) });
    return false;
  }
  if (metrics.indexOf('QUESTS') === -1) metrics.push('QUESTS');
  const metricUpdates = metrics.map(m => ({ metric: m, quantity: m === 'QUESTS' ? 1 : 2000 }));

  const checkPayload = {
    metric_updates: [{ metric: 'QUESTS', quantity: 0 }],
    timestamp: iso, timezone: tzName,
  };
  await duoPost(GOALS_API_URL + '/users/' + p.id + '/progress/batch', jwt, checkPayload);

  const payload = { metric_updates: metricUpdates, timestamp: iso, timezone: tzName };
  const [execCode] = await duoPost(GOALS_API_URL + '/users/' + p.id + '/progress/batch', jwt, payload);
  if (execCode === 200) {
    events.push({ status: 'completed', date: dateStr, notification: notify('✓', '任务补全成功', year + '年' + month + '月任务进度已灌满', 8) });
    return true;
  }
  events.push({ status: 'rejected', date: dateStr, notification: notify('!', '该月徽章已获取', year + '年' + month + '月任务无需重复补全', 8) });
  return false;
}

// ============ 响应工具 ============
function corsHeaders() {
  return {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Credentials': 'true',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization',
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
  };
}

function jsonResponse(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: Object.assign({ 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' }, corsHeaders()),
  });
}

function ndjsonResponse(events) {
  const body = events.map(e => JSON.stringify(e)).join('\n') + '\n';
  return new Response(body, {
    status: 200,
    headers: Object.assign({ 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' }, corsHeaders()),
  });
}

async function readJsonBody(request) {
  try {
    const text = await request.text();
    const data = JSON.parse(text);
    return data && typeof data === 'object' ? data : {};
  } catch (e) { return {}; }
}

function getClientIp(request) {
  return request.headers.get('CF-Connecting-IP') || request.headers.get('x-forwarded-for')?.split(',')[0].trim() || 'unknown';
}

// ============ 主入口 ============
export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const path = url.pathname.replace(/\/+$/, '') || '/';
    const method = request.method;

    if (method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders() });
    }

    try {
      if (path === '/' || path === '/index.php' || path === '/index.html') {
        return loginPage(url);
      }
      if (path === '/api.php' || path === '/alpha/api.php' || path === '/alpha/api') {
        return handleApi(request, env, url);
      }
      if (path === '/auth.php' || path === '/alpha/auth.php') {
        return handleAuth(request, env);
      }
      if (path === '/static.php') {
        return handleStatic(request, env, url);
      }
      if (path === '/DuolingoHelper.user.js' || path === '/duolingohelper.user.js') {
        return handleScript(env);
      }
      if (path === '/legacy/chess/move' || path === '/chess/move') {
        return jsonResponse({ status: 'failed', error: 'automatic chess is not supported by this server' }, 404);
      }
      if (path === '/analytics/legacy' || path === '/analytics') {
        return jsonResponse({ ok: true }, 200);
      }
      // /alpha/xxx → action=xxx
      if (path.startsWith('/alpha/')) {
        const clone = new URL(url);
        clone.searchParams.set('action', path.slice('/alpha/'.length).split('/')[0]);
        return handleApi(request, env, clone);
      }
      return jsonResponse({ status: 'failed', error: 'not found' }, 404);
    } catch (e) {
      return jsonResponse({
        status: 'failed',
        notification: notify('!', '服务器错误', '处理请求时发生异常，请稍后再试', 8),
      }, 500);
    }
  },
};

// ============ 登录页 ============
function loginPage(url) {
  const token = url.searchParams.get('token') || '';
  const jwt = url.searchParams.get('jwt') || '';
  const html = LOGIN_HTML
    .replace('__TOKEN__', JSON.stringify(token))
    .replace('__JWT__', JSON.stringify(jwt));
  return new Response(html, {
    status: 200,
    headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' },
  });
}

const LOGIN_HTML = `<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DuolingoHelper</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
html,body{background:#fff}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;color:#333;-webkit-font-smoothing:antialiased}
.wrap{max-width:640px;margin:0 auto;padding:96px 24px}
.head{margin-bottom:64px}
.head h1{font-size:26px;font-weight:400;color:#333;letter-spacing:.5px}
.head p{margin-top:12px;font-size:14px;color:#666;line-height:1.8}
.panel{margin-bottom:64px}
.panel h2{font-size:18px;font-weight:400;color:#333;margin-bottom:24px}
.field{margin-bottom:20px}
.field label{display:block;font-size:12px;color:#666;margin-bottom:6px}
.field input{width:100%;padding:11px 12px;font-size:14px;color:#333;background:#fff;border:1px solid #e8e8e8;border-radius:4px;outline:none}
.field input:focus{border-color:#000}
.account-line{width:100%;padding:11px 12px;font-size:14px;color:#333;background:#f5f5f5;border:1px solid #e8e8e8;border-radius:4px}
button{width:100%;margin-top:8px;padding:11px 16px;font-size:14px;color:#fff;background:#000;border:1px solid #000;border-radius:4px;cursor:pointer}
button:disabled{opacity:.5;cursor:not-allowed}
.msg{margin-top:16px;font-size:13px;line-height:1.8;min-height:20px}
.msg.ok{color:#333}
.msg.err{color:#666}
.notice{border:1px solid #e8e8e8;border-radius:4px;padding:24px;background:#f5f5f5}
.notice p{font-size:14px;color:#333;line-height:1.8}
.hidden{display:none}
.foot{margin-top:96px;font-size:12px;color:#666}
</style>
</head>
<body>
<div class="wrap" id="app">
    <div class="head">
        <h1>DuolingoHelper</h1>
        <p>请通过 DuolingoHelper 脚本内的登录入口打开本页面。</p>
    </div>
    <div class="panel" id="loading-panel">
        <p style="color:#666;font-size:14px">正在验证身份...</p>
    </div>
    <div class="panel hidden" id="error-panel">
        <div class="notice"><p id="error-msg">无法加载</p></div>
    </div>
    <div class="panel hidden" id="setup-panel">
        <h2>设置密码</h2>
        <div class="field">
            <label>多邻国账号</label>
            <div class="account-line" id="display-username">-</div>
        </div>
        <div class="field">
            <label>密码</label>
            <input type="password" id="setup-password" autocomplete="new-password">
        </div>
        <button id="setup-btn">确认设置</button>
        <div class="msg" id="setup-msg"></div>
    </div>
    <div class="panel hidden" id="login-panel">
        <h2>登录</h2>
        <div class="field">
            <label>多邻国账号</label>
            <div class="account-line" id="display-username-2">-</div>
        </div>
        <div class="field">
            <label>密码</label>
            <input type="password" id="login-password" autocomplete="current-password">
        </div>
        <button id="login-btn">登录</button>
        <div class="msg" id="login-msg"></div>
    </div>
    <div class="foot">DuolingoHelper</div>
</div>
<script>
(function() {
    var token = __TOKEN__;
    var jwt    = __JWT__;
    function showPanel(id) {
        ['loading-panel','error-panel','setup-panel','login-panel'].forEach(function(p) {
            document.getElementById(p).classList.add('hidden');
        });
        document.getElementById(id).classList.remove('hidden');
    }
    if (!token || !jwt) {
        showPanel('error-panel');
        document.getElementById('error-msg').textContent = '缺少必要的登录参数，请通过脚本入口打开此页面。';
        return;
    }
    fetch('auth.php?action=check', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'include',
        body: JSON.stringify({ token: token, jwt: jwt })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status !== 'completed') {
            showPanel('error-panel');
            document.getElementById('error-msg').textContent = data.error || '验证失败';
            return;
        }
        document.getElementById('display-username').textContent = data.username;
        document.getElementById('display-username-2').textContent = data.username;
        if (data.registered) {
            showPanel('login-panel');
            document.getElementById('login-password').focus();
        } else {
            showPanel('setup-panel');
            document.getElementById('setup-password').focus();
        }
    })
    .catch(function() {
        showPanel('error-panel');
        document.getElementById('error-msg').textContent = '网络错误，请重试。';
    });
    function submit(url, body, msgEl) {
        msgEl.textContent = '';
        msgEl.className = 'msg';
        fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'include',
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 'completed') {
                msgEl.textContent = data.message || '成功，请返回 Duolingo 页面。';
                msgEl.className = 'msg ok';
                try { window.dispatchEvent(new CustomEvent('DLP_Login_Done', { detail: data })); } catch(e) {}
            } else {
                msgEl.textContent = data.error || '操作失败';
                msgEl.className = 'msg err';
            }
        })
        .catch(function() {
            msgEl.textContent = '网络错误';
            msgEl.className = 'msg err';
        });
    }
    document.getElementById('setup-btn').addEventListener('click', function() {
        var pw = document.getElementById('setup-password').value;
        var msg = document.getElementById('setup-msg');
        if (pw.length < 6) { msg.textContent = '密码至少 6 位'; msg.className = 'msg err'; return; }
        submit('auth.php?action=setup', { token: token, jwt: jwt, password: pw }, msg);
    });
    document.getElementById('login-btn').addEventListener('click', function() {
        var pw = document.getElementById('login-password').value;
        var msg = document.getElementById('login-msg');
        if (!pw) { msg.textContent = '请填写密码'; msg.className = 'msg err'; return; }
        submit('auth.php?action=login', { token: token, jwt: jwt, password: pw }, msg);
    });
})();
</script>
</body>
</html>
`;

// ============ 静态资源 ============
const MIME_MAP = {
  woff2: 'font/woff2', woff: 'font/woff', ttf: 'font/ttf', otf: 'font/otf',
  png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif',
  svg: 'image/svg+xml', ico: 'image/x-icon', css: 'text/css', js: 'application/javascript',
};

async function handleStatic(request, env, url) {
  const rawPath = url.searchParams.get('path') || '';
  const safe = rawPath.replace(/[^a-zA-Z0-9/._-]/g, '');
  if (!safe) return jsonResponse({ error: 'not found' }, 404);
  let data;
  try { data = await env.DATA.get('static/' + safe, 'arrayBuffer'); } catch (e) { data = null; }
  if (!data) return jsonResponse({ error: 'not found' }, 404);
  const ext = safe.split('.').pop().toLowerCase();
  const mime = MIME_MAP[ext] || 'application/octet-stream';
  return new Response(data, {
    status: 200,
    headers: {
      'Content-Type': mime,
      'Access-Control-Allow-Origin': '*',
      'Cache-Control': 'public, max-age=86400',
    },
  });
}

async function handleScript(env) {
  let text;
  try { text = await env.DATA.get('script'); } catch (e) { text = null; }
  if (!text) return jsonResponse({ error: 'script not found' }, 404);
  return new Response(text, {
    status: 200,
    headers: {
      'Content-Type': 'text/javascript; charset=utf-8',
      'Access-Control-Allow-Origin': '*',
      'Cache-Control': 'no-cache',
    },
  });
}

// ============ api.php ============
async function handleApi(request, env, url) {
  const action = url.searchParams.get('action') || '';
  const input = await readJsonBody(request);
  const jwt = extractJwt(request, input);
  const ip = getClientIp(request);
  const clientVersion = String(input.version || '').trim();
  const events = [];

  if (action === 'server') {
    const serverVer = SERVER_VERSION;
    const versions = {};
    versions[serverVer] = { status: 'latest', warnings: [] };
    if (clientVersion !== '' && clientVersion !== serverVer) {
      versions[clientVersion] = { status: 'outdated', warnings: [] };
    }
    events.push({
      status: 'completed',
      global: {
        terms: { version: '1', content: '<p>欢迎使用 DuolingoHelper。使用即表示你同意相关条款与隐私政策。</p>' },
      },
      versions,
      script: {
        ping: 4000, youtube: null, discord: null, settings: {},
        modern: {
          xp: true, gems: true, streak: true, streak_freeze: true,
          heart_refill: true, quests: true, double_xp_boost: true,
        },
      },
    });
    return ndjsonResponse(events);
  }

  if (action === 'token') {
    const token = randomToken();
    const t = { token, ip, username: null, account: null, authorized: false, created_at: now(), duolingo_id: null };
    await saveToken(env, token, t);
    events.push({ status: 'completed', token });
    return ndjsonResponse(events);
  }

  if (action === 'check') {
    const token = String(input.token || '').trim();
    const t = await loadToken(env, token);
    if (!t || t.ip !== ip || !t.authorized) {
      events.push({ status: 'pending' });
      return ndjsonResponse(events);
    }
    let profile = null;
    if (t.duolingo_id) profile = await loadProfile(env, t.duolingo_id);
    if (!profile) {
      t.authorized = false;
      await saveToken(env, token, t);
      events.push({ status: 'failed', notification: notify('!', '账号校验失败', '我们已将它移除，请重新登录！', 8) });
      return ndjsonResponse(events);
    }
    if (clientVersion !== '' && (profile.client_version || '') !== clientVersion) {
      profile.client_version = clientVersion;
      await saveProfile(env, profile);
    }
    const versionInfo = versionUpdate(clientVersion);
    const resp = {
      status: 'completed',
      username: t.username || profile.username || '',
      account: t.account,
      tier: tierLabel(profile._tier, profile),
      tier_raw: profile._tier,
      gems: profile.gems || 0,
      totalXp: profile.totalXp || 0,
      streak: profile.streak || 0,
      email: profile.email || '',
      phone: profile.phone || '',
      stats: profile.stats || emptyStats(),
    };
    if (versionInfo) resp.version_update = versionInfo;
    events.push(resp);
    return ndjsonResponse(events);
  }

  if (action === 'request') {
    return handleRequest(request, env, input, jwt, ip, clientVersion);
  }

  if (action === 'status') {
    const token = String(input.token || '').trim();
    const t = await loadToken(env, token);
    if (!t || t.ip !== ip || !t.authorized) {
      events.push({ status: 'failed', notification: notify('!', '账号校验失败', '令牌无效或未授权', 8) });
      return ndjsonResponse(events);
    }
    const id = jwtSub(jwt);
    const p = id ? await loadProfile(env, id) : null;
    if (!p) {
      events.push({ status: 'completed', data: { tier: 'free', quota: {} } });
      return ndjsonResponse(events);
    }
    const tnow = now();
    const data = { tier: p._tier, quota: {} };
    for (const type of SUPPORTED_TYPES) {
      const cfg = getTypeConfig(p._tier, type);
      if (!cfg) continue;
      const used = (p.quota[type] && p.quota[type].used) || 0;
      const last = (p.quota[type] && p.quota[type].last_reset) || 0;
      if (tnow - last >= cfg.cooldown) {
        data.quota[type] = { remaining: cfg.max_total, cooldown_remaining: 0 };
      } else {
        data.quota[type] = {
          remaining: Math.max(0, cfg.max_total - used),
          cooldown_remaining: cfg.cooldown - (tnow - last),
        };
      }
    }
    events.push({ status: 'completed', data });
    return ndjsonResponse(events);
  }

  events.push({ status: 'failed', notification: notify('!', '未知操作', 'action 支持: server / token / check / request / status', 8) });
  return ndjsonResponse(events);
}

function extractJwt(request, input) {
  const auth = request.headers.get('authorization') || '';
  const m = auth.match(/^Bearer\s+(.+)$/i);
  if (m) return m[1].trim();
  return String(input.jwt || '').trim();
}

function versionUpdate(clientVersion) {
  if (clientVersion === '' || clientVersion === SERVER_VERSION) return null;
  return {
    current: clientVersion,
    latest: SERVER_VERSION,
    url: 'https://duolingo-helper.getpan.workers.dev/DuolingoHelper.user.js',
  };
}

async function handleRequest(request, env, input, jwt, ip, clientVersion) {
  const events = [];
  const token = String(input.token || '').trim();
  let type = String(input.type || '');
  const amountRaw = input.amount;
  let amount = Math.max(1, parseInt(amountRaw, 10) || 1);
  let date = String(input.date || '').trim();

  const TYPE_MAP = {
    xp: 'xp', gem: 'gem', double_xp_boost: 'xp_boost', heart_refill: 'heart_refill',
    streak: 'streak', streak_freeze: 'streak_freeze', quest: 'quest', badge: 'quest',
  };
  type = TYPE_MAP[type] || type;

  const t = await loadToken(env, token);
  if (!t || t.ip !== ip || !t.authorized) {
    events.push({ status: 'failed', notification: notify('!', '账号校验失败', '令牌无效或未授权，请刷新页面后重新登录', 8) });
    return ndjsonResponse(events);
  }
  const id = jwtSub(jwt);
  if (!id) {
    events.push({ status: 'failed', notification: notify('!', '无效的登录信息', 'JWT 无法解析，请刷新页面后重新登录', 8) });
    return ndjsonResponse(events);
  }
  if (SUPPORTED_TYPES.indexOf(type) === -1) {
    events.push({ status: 'failed', notification: notify('!', '不支持的类型', '支持的类型: ' + SUPPORTED_TYPES.join(', '), 8) });
    return ndjsonResponse(events);
  }

  let p = await loadProfile(env, id);
  if (!p) {
    p = await registerProfile(env, jwt, id);
    if (!p) {
      events.push({ status: 'failed', notification: notify('!', '账号登记失败', '无法读取 Duolingo 账号信息', 8) });
      return ndjsonResponse(events);
    }
  }

  if (!t.duolingo_id) {
    t.duolingo_id = id;
    t.username = p.username || t.username;
    await saveToken(env, token, t);
  }
  if (clientVersion !== '' && (p.client_version || '') !== clientVersion) {
    p.client_version = clientVersion;
  }

  const cfg = getTypeConfig(p._tier, type);
  if (!cfg) {
    events.push({ status: 'failed', notification: notify('!', '配置错误', '未找到该类型的额度配置', 8) });
    await saveProfile(env, p);
    return ndjsonResponse(events);
  }

  // 任务补全
  if (type === 'quest') {
    if (date === '' && typeof amountRaw === 'string' && /^\d{2}-\d{4}$/.test(amountRaw)) {
      date = amountRaw.slice(3, 7) + '-' + amountRaw.slice(0, 2);
    }
    if (date === '' || !/^\d{4}-\d{2}$/.test(date)) {
      const d = now();
      const dt = new Date(d * 1000);
      date = dt.getUTCFullYear() + '-' + String(dt.getUTCMonth() + 1).padStart(2, '0');
    }
    resetIfCooled(p, type, now());
    if (!quotaCheck(events, p, type, 1)) { await saveProfile(env, p); return ndjsonResponse(events); }
    quotaConsume(p, type, 1);
    appendLog(p, type, { date }, 'requested');
    await saveProfile(env, p);
    const ok = await executeQuest(events, jwt, p, date);
    appendLog(p, type, { date }, ok ? 'completed' : 'failed');
    if (ok) updateStats(p, type, 1);
    await saveProfile(env, p);
    return ndjsonResponse(events);
  }

  // 其他类型
  resetIfCooled(p, type, now());
  if (!quotaCheck(events, p, type, amount)) { await saveProfile(env, p); return ndjsonResponse(events); }

  let pending;
  if (p.pending && p.pending.type === type) {
    pending = p.pending;
  } else {
    p.pending = await initJob(type, amount, jwt, p);
    if (p.pending === false) {
      p.pending = null;
      events.push({ status: 'failed', notification: notify('!', '任务初始化失败', 'Duolingo 拒绝了初始化请求', 8) });
      await saveProfile(env, p);
      return ndjsonResponse(events);
    }
    pending = p.pending;
  }

  appendLog(p, type, { amount }, 'requested');
  await saveProfile(env, p);

  const deadline = Date.now() + MAX_RUNTIME_MS;
  while (Date.now() < deadline && !jobDone(pending)) {
    const res = await runStep(jwt, p, pending);
    if (res === false) {
      events.push({ status: 'failed', notification: notify('!', '执行失败', 'Duolingo 拒绝了该操作，请稍后再试', 8) });
      appendLog(p, type, { amount }, 'failed');
      p.pending = null;
      await saveProfile(env, p);
      return ndjsonResponse(events);
    }
    pending.done = Math.min(pending.requested, pending.done + res.granted);
    quotaConsume(p, type, res.granted);
    await saveProfile(env, p);
    const pct = Math.floor(pending.done / Math.max(1, pending.requested) * 100);
    events.push({ status: 'loading', percentage: Math.min(100, pct) });
  }

  if (jobDone(pending)) {
    const got = pending.done;
    events.push({
      status: 'completed', amount: got,
      notification: notify('✓', '完成', '已到账 ' + got + ' ' + (UNITS[type] || ''), 8),
    });
    appendLog(p, type, { amount, got }, 'completed');
    updateStats(p, type, got);
    p.pending = null;
    await saveProfile(env, p);
  } else {
    const pct = Math.floor(pending.done / Math.max(1, pending.requested) * 100);
    events.push({
      status: 'loading', percentage: Math.min(100, pct),
      notification: notify('⏳', '进行中', '服务器执行时间已到，请再次请求继续', 8),
    });
    await saveProfile(env, p);
  }
  return ndjsonResponse(events);
}

// ============ auth.php ============
async function handleAuth(request, env) {
  const url = new URL(request.url);
  const action = url.searchParams.get('action') || '';
  const input = await readJsonBody(request);
  const token = String(input.token || '').trim();
  const jwt = String(input.jwt || '').trim();
  const password = String(input.password || '');

  if (action === 'check') {
    if (!token) return authFail('缺少令牌');
    if (!jwt) return authFail('缺少 JWT');
    const t = await loadToken(env, token);
    if (!t) return authFail('令牌无效');
    const id = jwtSub(jwt);
    if (!id) return authFail('JWT 无法解析');
    const duoInfo = await fetchDuoFullInfo(jwt, id);
    if (!duoInfo || !duoInfo.username) return authFail('无法查询 Duolingo 账号');
    const realUsername = duoInfo.username;
    const existing = await loadAccount(env, realUsername);
    return jsonResponse({
      status: 'completed', username: realUsername, registered: !!existing, token,
    });
  }

  if (action === 'setup') {
    if (!token) return authFail('缺少令牌');
    if (!jwt) return authFail('缺少 JWT');
    if (password.length < 6) return authFail('密码至少 6 位');
    const t = await loadToken(env, token);
    if (!t) return authFail('令牌无效');
    const id = jwtSub(jwt);
    if (!id) return authFail('JWT 无法解析');
    const duoInfo = await fetchDuoFullInfo(jwt, id);
    if (!duoInfo || !duoInfo.username) return authFail('无法查询 Duolingo 账号');
    const realUsername = duoInfo.username;
    if (await loadAccount(env, realUsername)) return authFail('该账号已注册，请直接登录', 409);
    const salt = randomSalt();
    const hash = await hashPassword(password, salt);
    const d = {
      username: realUsername, password_hash: salt + ':' + hash,
      duolingo_id: id, tier: 'free', created_at: now(),
    };
    await saveAccount(env, realUsername, d);
    t.authorized = true;
    t.account = realUsername;
    t.username = realUsername;
    t.duolingo_id = id;
    await saveToken(env, token, t);
    return jsonResponse({ status: 'completed', username: realUsername, message: '注册成功，请返回 Duolingo 页面。' });
  }

  if (action === 'login') {
    if (!token) return authFail('缺少令牌');
    if (!jwt) return authFail('缺少 JWT');
    if (!password) return authFail('请填写密码');
    const t = await loadToken(env, token);
    if (!t) return authFail('令牌无效');
    const id = jwtSub(jwt);
    if (!id) return authFail('JWT 无法解析');
    const duoInfo = await fetchDuoFullInfo(jwt, id);
    if (!duoInfo || !duoInfo.username) return authFail('无法查询 Duolingo 账号');
    const realUsername = duoInfo.username;
    const existing = await loadAccount(env, realUsername);
    if (!existing) return authFail('该账号不存在，请先注册');
    const stored = String(existing.password_hash || '');
    const sep = stored.indexOf(':');
    if (sep <= 0) return authFail('密码错误', 401);
    const salt = stored.slice(0, sep);
    const expected = stored.slice(sep + 1);
    const hash = await hashPassword(password, salt);
    if (hash !== expected) return authFail('密码错误', 401);
    t.authorized = true;
    t.account = realUsername;
    t.username = realUsername;
    t.duolingo_id = id;
    await saveToken(env, token, t);
    return jsonResponse({ status: 'completed', username: realUsername, message: '登录成功，请返回 Duolingo 页面。' });
  }

  if (action === 'logout') {
    return jsonResponse({ status: 'completed' });
  }

  return authFail('未知操作，支持: check / setup / login / logout');
}

function authFail(error, code = 400) {
  return jsonResponse({ status: 'failed', error }, code);
}
