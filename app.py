# -*- coding: utf-8 -*-
# DuolingoHelper 服务器（PythonAnywhere Flask 版）
# 与原 PHP 后端协议一致：NDJSON 事件流、api.php/auth.php/static.php 端点
import hashlib, json, os, random, re, secrets, time

from flask import Flask, Response, request

SERVER_VERSION = "1.1.1"
DATA_DIR = os.path.join(os.path.dirname(__file__), "data")
STATIC_DIR = os.path.join(os.path.dirname(__file__), "static")

DUO_BASE = "https://www.duolingo.com/2017-06-30"
SESSIONS_URL = DUO_BASE + "/sessions"
STORIES_URL = "https://stories.duolingo.com/api2/stories"
GOALS_API_URL = "https://goals-api.duolingo.com"
STORY_SLUG = "fr-en-le-passeport"
FROM_LANG = "en"
LEARN_LANG = "fr"
XP_PER_STORY = 499
XP_MIN = 30
XP_BONUS_MAX = 469

GEM_REWARDS = ["SKILL_COMPLETION_BALANCED-…-2-GEMS", "SKILL_COMPLETION_BALANCED-…-2-GEMS"]

CHALLENGE_TYPES = [
    "assist","characterIntro","characterMatch","characterPuzzle","characterSelect","characterTrace",
    "characterWrite","completeReverseTranslation","definition","dialogue","extendedMatch",
    "extendedListenMatch","form","freeResponse","gapFill","judge","listen","listenComplete",
    "listenMatch","match","name","listenComprehension","listenIsolation","listenSpeak","listenTap",
    "orderTapComplete","partialListen","partialReverseTranslate","patternTapComplete","radioBinary",
    "radioImageSelect","radioListenMatch","radioListenRecognize","radioSelect","readComprehension",
    "reverseAssist","sameDifferent","select","selectPronunciation","selectTranscription","svgPuzzle",
    "syllableTap","syllableListenTap","speak","tapCloze","tapClozeTable","tapComplete",
    "tapCompleteTable","tapDescribe","translate","transliterate","transliterationAssist","typeCloze",
    "typeClozeTable","typeComplete","typeCompleteTable","writeComprehension",
]

UNITS = {"xp":"经验","gem":"宝石","xp_boost":"经验翻倍","heart_refill":"红心补满",
         "streak":"增加连胜","streak_freeze":"连胜保护","quest":"任务补全"}

TIERS = {
 "free": {"xp":{"max_single":10000,"max_total":50000,"cooldown":7200},
          "gem":{"max_single":100,"max_total":500,"cooldown":14400},
          "xp_boost":{"max_single":1,"max_total":1,"cooldown":120},
          "heart_refill":{"max_single":1,"max_total":1,"cooldown":120},
          "streak":{"max_single":15,"max_total":30,"cooldown":7200},
          "streak_freeze":{"max_single":2,"max_total":4,"cooldown":86400},
          "quest":{"max_single":1,"max_total":5,"cooldown":3600}},
 "mid": {"xp":{"max_single":20000,"max_total":80000,"cooldown":1800},
         "gem":{"max_single":200,"max_total":600,"cooldown":1800},
         "xp_boost":{"max_single":1,"max_total":1,"cooldown":30},
         "heart_refill":{"max_single":1,"max_total":1,"cooldown":30},
         "streak":{"max_single":30,"max_total":60,"cooldown":1800},
         "streak_freeze":{"max_single":4,"max_total":8,"cooldown":43200},
         "quest":{"max_single":1,"max_total":10,"cooldown":1800}},
 "vip": {"xp":{"max_single":20000,"max_total":80000,"cooldown":1800},
         "gem":{"max_single":200,"max_total":600,"cooldown":1800},
         "xp_boost":{"max_single":1,"max_total":1,"cooldown":30},
         "heart_refill":{"max_single":1,"max_total":1,"cooldown":30},
         "streak":{"max_single":30,"max_total":60,"cooldown":1800},
         "streak_freeze":{"max_single":4,"max_total":8,"cooldown":43200},
         "quest":{"max_single":1,"max_total":10,"cooldown":1800}},
}
SUPPORTED_TYPES = ["xp","gem","xp_boost","heart_refill","streak","streak_freeze","quest"]
TIER_NAMES = {"free":"免费","mid":"会员","vip":"超级会员"}
MAX_RUNTIME_MS = 22000

app = Flask(__name__)

# ---------- 工具 ----------
def now(): return int(time.time())

def jwt_sub(jwt):
    try:
        parts = str(jwt).split(".")
        if len(parts) < 2: return None
        raw = parts[1].replace("-", "+").replace("_", "/")
        raw += "=" * ((4 - len(raw) % 4) % 4)
        data = json.loads(__import__("base64").b64decode(raw))
        return data.get("sub")
    except Exception:
        return None

def random_token(): return secrets.token_hex(24)
def random_salt(): return secrets.token_hex(16)

def hash_password(password, salt):
    return hashlib.pbkdf2_hmac("sha256", password.encode(), salt.encode(), 100000).hex()

def fmt_time(ts):
    d = time.gmtime(ts)
    return time.strftime("%Y-%m-%d %H:%M:%S", d)

def format_remaining_time(seconds):
    if seconds <= 0: return "已过期"
    days = int(seconds // 86400); hours = int((seconds % 86400) // 3600)
    if days > 30: return str(days // 30) + "月"
    if days > 0: return str(days) + "天" + str(hours) + "时"
    return str(hours) + "时"

def tier_label(tier, profile):
    name = TIER_NAMES.get(tier, "免费")
    if tier == "free": return name + "[永久]"
    if profile and profile.get("tier_expires_at"):
        remaining = profile["tier_expires_at"] - now()
        if remaining > 0: return name + "[" + format_remaining_time(remaining) + "]"
        return name + "[已过期]"
    return name + "[永久]"

def notify(icon, head, body, duration=8):
    return {"icon": icon, "head": head, "body": body, "duration": duration}

# ---------- 数据层（文件存储） ----------
def _ensure():
    for sub in ["tokens", "accounts", "free", "mid", "vip"]:
        d = os.path.join(DATA_DIR, sub)
        if not os.path.isdir(d): os.makedirs(d)

def _read(path):
    try:
        with open(path, "r", encoding="utf-8") as f: return json.load(f)
    except Exception: return None

def _write(path, data):
    _ensure()
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False)

def profile_path(tier, sid):
    sid = re.sub(r"[^a-zA-Z0-9_-]", "", str(sid))
    return os.path.join(DATA_DIR, tier, sid + ".json")

def load_profile(sid):
    for tier in ["free", "mid", "vip"]:
        p = _read(profile_path(tier, sid))
        if p: p["_tier"] = tier; return p
    return None

def save_profile(p):
    os.makedirs(os.path.join(DATA_DIR, p["_tier"]), exist_ok=True)
    with open(profile_path(p["_tier"], p["id"]), "w", encoding="utf-8") as f:
        json.dump(p, f, ensure_ascii=False)

def delete_profile(sid):
    for tier in ["free", "mid", "vip"]:
        try: os.remove(profile_path(tier, sid))
        except OSError: pass

def token_path(token):
    token = re.sub(r"[^a-zA-Z0-9_-]", "", str(token))
    return os.path.join(DATA_DIR, "tokens", token + ".json")

def load_token(token): return _read(token_path(token))
def save_token(token, t): _write(token_path(token), t)

def account_path(username):
    username = re.sub(r"[^a-zA-Z0-9_@.]", "", str(username))
    return os.path.join(DATA_DIR, "accounts", username + ".json")

def load_account(username): return _read(account_path(username))
def save_account(username, d): _write(account_path(username), d)

# ---------- Duolingo 请求 ----------
def duo_headers(jwt):
    return {
        "authorization": "Bearer " + jwt,
        "cookie": "jwt_token=" + jwt,
        "content-type": "application/json",
        "user-agent": "Duolingo-Storm/1.0",
        "device-platform": "web",
        "x-duolingo-device-platform": "web",
        "x-duolingo-app-version": "1.0.0",
        "x-duolingo-application": "chrome",
        "x-duolingo-client-version": "web",
        "accept": "application/json",
    }

def duo_request(method, url, jwt, payload=None):
    import urllib.request
    req = urllib.request.Request(url, method=method, headers=duo_headers(jwt))
    body = None
    if payload is not None:
        body = json.dumps(payload).encode()
    try:
        with urllib.request.urlopen(req, data=body, timeout=15) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")
    except Exception:
        return 0, ""

def duo_get(url, jwt): return duo_request("GET", url, jwt)
def duo_post(url, jwt, payload): return duo_request("POST", url, jwt, payload)
def duo_patch(url, jwt, payload): return duo_request("PATCH", url, jwt, payload)
def duo_put(url, jwt, payload): return duo_request("PUT", url, jwt, payload)

# ---------- 账号信息 ----------
def fetch_duo_full_info(jwt, sid):
    code, body = duo_get(DUO_BASE + "/users/" + __import__("urllib.parse").quote(str(sid)), jwt)
    if code != 200: return None
    try: d = json.loads(body)
    except Exception: return None
    if not isinstance(d, dict): return None
    return {
        "username": d.get("username"), "email": d.get("email"),
        "phone": d.get("phoneNumber") or d.get("phone"),
        "totalXp": d.get("totalXp", 0), "gems": d.get("gems", 0),
        "streak": (d.get("streakData") or {}).get("currentStreak", {}).get("length", 0),
        "fromLanguage": d.get("fromLanguage", FROM_LANG),
        "learningLanguage": d.get("learningLanguage", LEARN_LANG),
    }

def empty_stats():
    return {"xp":0,"gem":0,"streak":0,"heart_refill":0,"streak_freeze":0,"double_xp_boost":0,"quest":0}

def register_profile(jwt, sid, tier="free"):
    info = fetch_duo_full_info(jwt, sid)
    if not info: return None
    p = {"id": sid, "username": info["username"] or "unknown", "email": info["email"],
         "phone": info["phone"], "duolingo_password": "", "totalXp": info["totalXp"],
         "gems": info["gems"], "streak": info["streak"], "fromLanguage": info["fromLanguage"],
         "learningLanguage": info["learningLanguage"], "created_at": now(), "quota": {},
         "pending": None, "history": [], "stats": empty_stats(), "_tier": tier}
    save_profile(p)
    return p

# ---------- 额度与冷却 ----------
def get_type_config(tier, type_):
    return TIERS.get(tier, TIERS["free"]).get(type_)

def reset_if_cooled(p, type_, tnow):
    cfg = get_type_config(p["_tier"], type_)
    if not cfg: return
    q = p["quota"].get(type_)
    if q is None:
        p["quota"][type_] = {"used": 0, "last_reset": tnow}
    elif tnow - int(q.get("last_reset", 0)) >= cfg["cooldown"]:
        p["quota"][type_] = {"used": 0, "last_reset": tnow}

def quota_check(events, p, type_, amount):
    cfg = get_type_config(p["_tier"], type_)
    if not cfg: return False
    q = p["quota"].get(type_) or {"used": 0, "last_reset": 0}
    if amount > cfg["max_single"]:
        events.append({"status":"rejected","max_amount":cfg["max_single"],
                       "notification":notify("!","超出单次上限","该类型单次最多 "+str(cfg["max_single"]),8)})
        return False
    remaining = cfg["max_total"] - q.get("used", 0)
    if remaining <= 0:
        cool_left = cfg["cooldown"] - (now() - int(q.get("last_reset", 0)))
        minutes = max(1, int((cool_left + 59) // 60))
        events.append({"status":"rejected","max_amount":0,
                       "notification":notify("!","额度已用完","还需等待约 "+str(minutes)+" 分钟后重置",8)})
        return False
    if amount > remaining:
        events.append({"status":"rejected","max_amount":remaining,
                       "notification":notify("!","剩余额度不足","该类型剩余 "+str(remaining)+"，请减少请求量",8)})
        return False
    return True

def quota_consume(p, type_, amount):
    if type_ not in p["quota"]: p["quota"][type_] = {"used": 0, "last_reset": now()}
    p["quota"][type_]["used"] += amount

def append_log(p, type_, extra, status):
    p["history"].append({"at": fmt_time(now()), "type": type_, "extra": extra, "status": status})
    if len(p["history"]) > 500: p["history"] = p["history"][-500:]

def update_stats(p, type_, amount):
    if not p.get("stats"): p["stats"] = empty_stats()
    if type_ in p["stats"]: p["stats"][type_] += amount

# ---------- 任务执行 ----------
def job_done(pending): return pending["done"] >= pending["requested"]

def init_job(type_, amount, jwt, p):
    if type_ == "streak":
        meta = init_streak_meta(jwt, p)
        if meta is False: return False
        return {"type": type_, "requested": amount, "done": 0, "meta": meta}
    return {"type": type_, "requested": amount, "done": 0, "meta": None}

def init_streak_meta(jwt, p):
    code, body = duo_get(DUO_BASE + "/users/" + p["id"], jwt)
    if code != 200: return False
    try: info = json.loads(body)
    except Exception: return False
    sd = (info.get("streakData") or {}).get("currentStreak") or {}
    start_date = sd.get("startDate")
    if start_date:
        try:
            from datetime import datetime
            farm_start = int(datetime.fromisoformat(start_date.replace("Z", "+00:00")).timestamp()) - 86400
        except Exception:
            farm_start = int(time.mktime(time.strptime(time.strftime("%Y-%m-%d"), "%Y-%m-%d"))) - 86400
    else:
        farm_start = int(time.mktime(time.strptime(time.strftime("%Y-%m-%d"), "%Y-%m-%d"))) - 86400
    return {"sim_day_ts": farm_start}

def run_step(jwt, p, pending):
    t = pending["type"]
    if t == "xp": return step_xp(jwt, pending)
    if t == "gem": return step_gem(jwt, p)
    if t == "xp_boost": return step_shop(jwt, p, "xp_boost_15")
    if t == "heart_refill": return step_shop(jwt, p, "health_refill")
    if t == "streak": return step_streak(jwt, p, pending)
    if t == "streak_freeze": return step_shop(jwt, p, "streak_freeze")
    return False

def step_xp(jwt, pending):
    remain = pending["requested"] - pending["done"]
    if remain >= XP_PER_STORY: bonus = XP_BONUS_MAX
    elif remain >= XP_MIN: bonus = min(remain - XP_MIN, XP_BONUS_MAX)
    else: bonus = 0
    duration = random.randint(300, 420)
    tnow = now()
    payload = {"awardXp": True, "completedBonusChallenge": True, "fromLanguage": FROM_LANG,
               "learningLanguage": LEARN_LANG, "hasXpBoost": False, "illustrationFormat": "svg",
               "isFeaturedStoryInPracticeHub": True, "isLegendaryMode": True, "isV2Redo": False,
               "isV2Story": False, "masterVersion": True, "maxScore": 0, "score": 0,
               "happyHourBonusXp": bonus, "startTime": tnow, "endTime": tnow + duration}
    code, body = duo_post(STORIES_URL + "/" + STORY_SLUG + "/complete", jwt, payload)
    if code != 200: return False
    try: awarded = int(json.loads(body).get("awardedXp", 0))
    except Exception: awarded = 0
    return {"granted": max(0, awarded)}

def step_gem(jwt, p):
    rewards = list(GEM_REWARDS); random.shuffle(rewards)
    for reward in rewards:
        payload = {"consumed": True, "fromLanguage": p.get("fromLanguage", FROM_LANG),
                   "learningLanguage": p.get("learningLanguage", LEARN_LANG)}
        code, _ = duo_patch(DUO_BASE + "/users/" + p["id"] + "/rewards/" + reward, jwt, payload)
        if code != 200: return False
    return {"granted": 1}

def step_shop(jwt, p, item):
    payload = {"itemName": item, "isFree": True, "consumed": True,
               "fromLanguage": p.get("fromLanguage", FROM_LANG),
               "learningLanguage": p.get("learningLanguage", LEARN_LANG)}
    code, _ = duo_post(DUO_BASE + "/users/" + p["id"] + "/shop-items", jwt, payload)
    return {"granted": 1} if code == 200 else False

def step_streak(jwt, p, pending):
    session = {"challengeTypes": CHALLENGE_TYPES, "fromLanguage": p.get("fromLanguage", FROM_LANG),
               "isFinalLevel": False, "isV2": True, "juicy": True,
               "learningLanguage": p.get("learningLanguage", LEARN_LANG), "smartTipsVersion": 2,
               "type": "GLOBAL_PRACTICE"}
    code, body = duo_post(SESSIONS_URL, jwt, session)
    if code != 200: return False
    try: sess = json.loads(body)
    except Exception: return False
    sid = sess.get("id")
    if not sid: return False
    end = int(pending["meta"]["sim_day_ts"]); start = end - 1
    update = dict(sess, **{"heartsLeft": 5, "startTime": start, "endTime": end,
                           "enableBonusPoints": False, "failed": False,
                           "maxInLessonStreak": 9, "shouldLearnThings": True})
    code2, _ = duo_put(SESSIONS_URL + "/" + str(sid), jwt, update)
    if code2 != 200: return False
    pending["meta"]["sim_day_ts"] = end - 86400
    return {"granted": 1}

# ---------- 任务补全 ----------
def execute_quest(events, jwt, p, date_str):
    if not re.match(r"^\d{4}-\d{2}$", date_str):
        events.append({"status":"failed","notification":notify("!","日期格式错误","请使用 YYYY-MM 格式（如 2026-09）",8)})
        return False
    year, month = int(date_str[:4]), int(date_str[5:7])
    code, body = duo_get(DUO_BASE + "/users/" + p["id"], jwt)
    if code != 200:
        events.append({"status":"failed","notification":notify("!","查询失败","无法读取用户信息",8)})
        return False
    try: info = json.loads(body)
    except Exception: info = {}
    tz = info.get("timezone") or info.get("tz") or "UTC"
    if not tz: tz = "UTC"

    last_day = 31
    while last_day > 28:
        try:
            import datetime
            datetime.datetime(year, month, last_day); break
        except ValueError:
            last_day -= 1
    day = min(15, last_day)
    iso = "%04d-%02d-%02dT00:00:00.000Z" % (year, month, day)

    sc, sb = duo_get(GOALS_API_URL + "/schema?ui_language=en", jwt)
    if sc != 200:
        events.append({"status":"failed","notification":notify("!","查询失败","无法获取任务指标",8)})
        return False
    try: schema = json.loads(sb)
    except Exception: schema = None
    if not isinstance(schema, dict):
        events.append({"status":"failed","notification":notify("!","查询失败","任务数据异常",8)})
        return False
    metrics = []
    for goal in (schema.get("goals") or []):
        m = goal.get("metric")
        if m and m not in metrics: metrics.append(m)
    if not metrics:
        events.append({"status":"failed","notification":notify("!","查询失败","无可用任务指标",8)})
        return False
    if "QUESTS" not in metrics: metrics.append("QUESTS")
    metric_updates = [{"metric": m, "quantity": 1 if m == "QUESTS" else 2000} for m in metrics]

    check = {"metric_updates": [{"metric": "QUESTS", "quantity": 0}],
             "timestamp": iso, "timezone": tz}
    duo_post(GOALS_API_URL + "/users/" + p["id"] + "/progress/batch", jwt, check)
    payload = {"metric_updates": metric_updates, "timestamp": iso, "timezone": tz}
    ec, _ = duo_post(GOALS_API_URL + "/users/" + p["id"] + "/progress/batch", jwt, payload)
    if ec == 200:
        events.append({"status":"completed","date":date_str,
                       "notification":notify("✓","任务补全成功",str(year)+"年"+str(month)+"月任务进度已灌满",8)})
        return True
    events.append({"status":"rejected","date":date_str,
                   "notification":notify("!","该月徽章已获取",str(year)+"年"+str(month)+"月任务无需重复补全",8)})
    return False

# ---------- 响应 ----------
def json_response(data, status=200):
    js = json.dumps(data, ensure_ascii=False)
    return Response(js, status=status, mimetype="application/json")

def ndjson(events):
    body = "".join(json.dumps(e, ensure_ascii=False) + "\n" for e in events)
    return Response(body, mimetype="application/json")

def client_ip():
    return request.headers.get("CF-Connecting-IP") or \
           (request.headers.get("x-forwarded-for") or "").split(",")[0].strip() or "unknown"

def version_update(client_version):
    if client_version in ("", SERVER_VERSION): return None
    return {"current": client_version, "latest": SERVER_VERSION,
            "url": "https://Ai9288.pythonanywhere.com/DuolingoHelper.user.js"}

# ============ 路由 ============
@app.after_request
def cors(resp):
    resp.headers["Access-Control-Allow-Origin"] = "*"
    resp.headers["Access-Control-Allow-Credentials"] = "true"
    resp.headers["Access-Control-Allow-Headers"] = "Content-Type, Authorization"
    resp.headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
    return resp

@app.route("/", methods=["GET", "OPTIONS"])
def root():
    if request.method == "OPTIONS": return ("", 204)
    return login_page()

@app.route("/index.php", methods=["GET"])
def index_php(): return login_page()

def login_page():
    token = request.args.get("token", "")
    jwt = request.args.get("jwt", "")
    html = LOGIN_HTML.replace("__TOKEN__", json.dumps(token)).replace("__JWT__", json.dumps(jwt))
    return Response(html, mimetype="text/html; charset=utf-8")

LOGIN_HTML = r"""<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>DuolingoHelper</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}html,body{background:#fff}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;color:#333;-webkit-font-smoothing:antialiased}
.wrap{max-width:640px;margin:0 auto;padding:96px 24px}.head{margin-bottom:64px}
.head h1{font-size:26px;font-weight:400;color:#333;letter-spacing:.5px}.head p{margin-top:12px;font-size:14px;color:#666;line-height:1.8}
.panel{margin-bottom:64px}.panel h2{font-size:18px;font-weight:400;color:#333;margin-bottom:24px}
.field{margin-bottom:20px}.field label{display:block;font-size:12px;color:#666;margin-bottom:6px}
.field input{width:100%;padding:11px 12px;font-size:14px;color:#333;background:#fff;border:1px solid #e8e8e8;border-radius:4px;outline:none}
.field input:focus{border-color:#000}.account-line{width:100%;padding:11px 12px;font-size:14px;color:#333;background:#f5f5f5;border:1px solid #e8e8e8;border-radius:4px}
button{width:100%;margin-top:8px;padding:11px 16px;font-size:14px;color:#fff;background:#000;border:1px solid #000;border-radius:4px;cursor:pointer}
button:disabled{opacity:.5;cursor:not-allowed}.msg{margin-top:16px;font-size:13px;line-height:1.8;min-height:20px}.msg.ok{color:#333}.msg.err{color:#666}
.notice{border:1px solid #e8e8e8;border-radius:4px;padding:24px;background:#f5f5f5}.notice p{font-size:14px;color:#333;line-height:1.8}
.hidden{display:none}.foot{margin-top:96px;font-size:12px;color:#666}
</style></head><body>
<div class="wrap"><div class="head"><h1>DuolingoHelper</h1><p>请通过 DuolingoHelper 脚本内的登录入口打开本页面。</p></div>
<div class="panel" id="loading-panel"><p style="color:#666;font-size:14px">正在验证身份...</p></div>
<div class="panel hidden" id="error-panel"><div class="notice"><p id="error-msg">无法加载</p></div></div>
<div class="panel hidden" id="setup-panel"><h2>设置密码</h2>
<div class="field"><label>多邻国账号</label><div class="account-line" id="display-username">-</div></div>
<div class="field"><label>密码</label><input type="password" id="setup-password" autocomplete="new-password"></div>
<button id="setup-btn">确认设置</button><div class="msg" id="setup-msg"></div></div>
<div class="panel hidden" id="login-panel"><h2>登录</h2>
<div class="field"><label>多邻国账号</label><div class="account-line" id="display-username-2">-</div></div>
<div class="field"><label>密码</label><input type="password" id="login-password" autocomplete="current-password"></div>
<button id="login-btn">登录</button><div class="msg" id="login-msg"></div></div>
<div class="foot">DuolingoHelper</div></div>
<script>
(function(){var token=__TOKEN__,jwt=__JWT__;function showPanel(id){['loading-panel','error-panel','setup-panel','login-panel'].forEach(function(p){document.getElementById(p).classList.add('hidden')});document.getElementById(id).classList.remove('hidden')}
if(!token||!jwt){showPanel('error-panel');document.getElementById('error-msg').textContent='缺少必要的登录参数，请通过脚本入口打开此页面。';return}
fetch('auth.php?action=check',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({token:token,jwt:jwt})})
.then(function(r){return r.json()}).then(function(data){if(data.status!=='completed'){showPanel('error-panel');document.getElementById('error-msg').textContent=data.error||'验证失败';return}
document.getElementById('display-username').textContent=data.username;document.getElementById('display-username-2').textContent=data.username
if(data.registered){showPanel('login-panel');document.getElementById('login-password').focus()}else{showPanel('setup-panel');document.getElementById('setup-password').focus()}})
.catch(function(){showPanel('error-panel');document.getElementById('error-msg').textContent='网络错误，请重试。'})
function submit(url,body,msgEl){msgEl.textContent='';msgEl.className='msg'
fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify(body)})
.then(function(r){return r.json()}).then(function(data){if(data.status==='completed'){msgEl.textContent=data.message||'成功，请返回 Duolingo 页面。';msgEl.className='msg ok';try{window.dispatchEvent(new CustomEvent('DLP_Login_Done',{detail:data}))}catch(e){}}
else{msgEl.textContent=data.error||'操作失败';msgEl.className='msg err'}}).catch(function(){msgEl.textContent='网络错误';msgEl.className='msg err'})}
document.getElementById('setup-btn').addEventListener('click',function(){var pw=document.getElementById('setup-password').value,msg=document.getElementById('setup-msg')
if(pw.length<6){msg.textContent='密码至少 6 位';msg.className='msg err';return}submit('auth.php?action=setup',{token:token,jwt:jwt,password:pw},msg)})
document.getElementById('login-btn').addEventListener('click',function(){var pw=document.getElementById('login-password').value,msg=document.getElementById('login-msg')
if(!pw){msg.textContent='请填写密码';msg.className='msg err';return}submit('auth.php?action=login',{token:token,jwt:jwt,password:pw},msg)})})()
</script></body></html>
"""

@app.route("/api.php", methods=["GET", "POST", "OPTIONS"])
def api_php():
    if request.method == "OPTIONS": return ("", 204)
    return handle_api()

@app.route("/alpha/api.php", methods=["GET", "POST", "OPTIONS"])
def alpha_api():
    if request.method == "OPTIONS": return ("", 204)
    return handle_api()

def handle_api():
    action = request.args.get("action", "")
    try: data = request.get_json(silent=True) or {}
    except Exception: data = {}
    auth = request.headers.get("Authorization", "")
    jwt = ""
    if auth.lower().startswith("bearer "): jwt = auth[7:].strip()
    if not jwt: jwt = str(data.get("jwt", "")).strip()
    ip = client_ip()
    client_version = str(data.get("version", "")).strip()
    events = []

    if action == "server":
        versions = {SERVER_VERSION: {"status": "latest", "warnings": []}}
        if client_version and client_version != SERVER_VERSION:
            versions[client_version] = {"status": "outdated", "warnings": []}
        events.append({"status": "completed", "global": {"terms": {"version": "1",
            "content": "<p>欢迎使用 DuolingoHelper。使用即表示你同意相关条款与隐私政策。</p>"}},
            "versions": versions, "script": {"ping": 4000, "youtube": None, "discord": None,
            "settings": {}, "modern": {"xp": True, "gems": True, "streak": True,
            "streak_freeze": True, "heart_refill": True, "quests": True, "double_xp_boost": True}}})
        return ndjson(events)

    if action == "token":
        token = random_token()
        save_token(token, {"token": token, "ip": ip, "username": None, "account": None,
                           "authorized": False, "created_at": now(), "duolingo_id": None})
        events.append({"status": "completed", "token": token})
        return ndjson(events)

    if action == "check":
        token = str(data.get("token", "")).strip()
        t = load_token(token)
        if not t or t.get("ip") != ip or not t.get("authorized"):
            events.append({"status": "pending"}); return ndjson(events)
        profile = load_profile(t.get("duolingo_id")) if t.get("duolingo_id") else None
        if not profile:
            t["authorized"] = False; save_token(token, t)
            events.append({"status": "failed", "notification": notify("!", "账号校验失败", "我们已将它移除，请重新登录！", 8)})
            return ndjson(events)
        if client_version and profile.get("client_version") != client_version:
            profile["client_version"] = client_version; save_profile(profile)
        vu = version_update(client_version)
        resp = {"status": "completed", "username": t.get("username") or profile.get("username", ""),
                "account": t.get("account"), "tier": tier_label(profile["_tier"], profile),
                "tier_raw": profile["_tier"], "gems": profile.get("gems", 0),
                "totalXp": profile.get("totalXp", 0), "streak": profile.get("streak", 0),
                "email": profile.get("email", ""), "phone": profile.get("phone", ""),
                "stats": profile.get("stats") or empty_stats()}
        if vu: resp["version_update"] = vu
        events.append(resp); return ndjson(events)

    if action == "request":
        return handle_request(data, jwt, ip, client_version)

    if action == "status":
        token = str(data.get("token", "")).strip()
        t = load_token(token)
        if not t or t.get("ip") != ip or not t.get("authorized"):
            events.append({"status": "failed", "notification": notify("!", "账号校验失败", "令牌无效或未授权", 8)})
            return ndjson(events)
        sid = jwt_sub(jwt)
        p = load_profile(sid) if sid else None
        if not p:
            events.append({"status": "completed", "data": {"tier": "free", "quota": {}}})
            return ndjson(events)
        tnow = now(); quota = {}
        for type_ in SUPPORTED_TYPES:
            cfg = get_type_config(p["_tier"], type_)
            if not cfg: continue
            q = p["quota"].get(type_) or {}
            used = q.get("used", 0); last = q.get("last_reset", 0)
            if tnow - last >= cfg["cooldown"]:
                quota[type_] = {"remaining": cfg["max_total"], "cooldown_remaining": 0}
            else:
                quota[type_] = {"remaining": max(0, cfg["max_total"] - used),
                                "cooldown_remaining": cfg["cooldown"] - (tnow - last)}
        events.append({"status": "completed", "data": {"tier": p["_tier"], "quota": quota}})
        return ndjson(events)

    events.append({"status": "failed", "notification": notify("!", "未知操作", "action 支持: server / token / check / request / status", 8)})
    return ndjson(events)

def handle_request(data, jwt, ip, client_version):
    events = []
    token = str(data.get("token", "")).strip()
    type_ = str(data.get("type", ""))
    raw_amount = data.get("amount")
    try: amount = max(1, int(raw_amount))
    except Exception: amount = 1
    date = str(data.get("date", "")).strip()

    type_map = {"xp":"xp","gem":"gem","double_xp_boost":"xp_boost","heart_refill":"heart_refill",
                "streak":"streak","streak_freeze":"streak_freeze","quest":"quest","badge":"quest"}
    type_ = type_map.get(type_, type_)

    t = load_token(token)
    if not t or t.get("ip") != ip or not t.get("authorized"):
        events.append({"status":"failed","notification":notify("!","账号校验失败","令牌无效或未授权，请刷新页面后重新登录",8)})
        return ndjson(events)
    sid = jwt_sub(jwt)
    if not sid:
        events.append({"status":"failed","notification":notify("!","无效的登录信息","JWT 无法解析，请刷新页面后重新登录",8)})
        return ndjson(events)
    if type_ not in SUPPORTED_TYPES:
        events.append({"status":"failed","notification":notify("!","不支持的类型","支持的类型: "+", ".join(SUPPORTED_TYPES),8)})
        return ndjson(events)

    p = load_profile(sid)
    if not p:
        p = register_profile(jwt, sid)
        if not p:
            events.append({"status":"failed","notification":notify("!","账号登记失败","无法读取 Duolingo 账号信息",8)})
            return ndjson(events)
    if not t.get("duolingo_id"):
        t["duolingo_id"] = sid; t["username"] = p.get("username", t.get("username"))
        save_token(token, t)
    if client_version and p.get("client_version") != client_version:
        p["client_version"] = client_version
    cfg = get_type_config(p["_tier"], type_)
    if not cfg:
        events.append({"status":"failed","notification":notify("!","配置错误","未找到该类型的额度配置",8)})
        save_profile(p); return ndjson(events)

    if type_ == "quest":
        if date == "" and isinstance(raw_amount, str) and re.match(r"^\d{2}-\d{4}$", raw_amount):
            date = raw_amount[3:7] + "-" + raw_amount[:2]
        if date == "" or not re.match(r"^\d{4}-\d{2}$", date):
            date = time.strftime("%Y-%m")
        reset_if_cooled(p, type_, now())
        if not quota_check(events, p, type_, 1): save_profile(p); return ndjson(events)
        quota_consume(p, type_, 1); append_log(p, type_, {"date": date}, "requested")
        save_profile(p)
        ok = execute_quest(events, jwt, p, date)
        append_log(p, type_, {"date": date}, "completed" if ok else "failed")
        if ok: update_stats(p, type_, 1)
        save_profile(p); return ndjson(events)

    reset_if_cooled(p, type_, now())
    if not quota_check(events, p, type_, amount): save_profile(p); return ndjson(events)

    if p.get("pending") and p["pending"].get("type") == type_:
        pending = p["pending"]
    else:
        p["pending"] = init_job(type_, amount, jwt, p)
        if p["pending"] is False:
            p["pending"] = None
            events.append({"status":"failed","notification":notify("!","任务初始化失败","Duolingo 拒绝了初始化请求",8)})
            save_profile(p); return ndjson(events)
        pending = p["pending"]
    append_log(p, type_, {"amount": amount}, "requested")
    save_profile(p)

    deadline = int(time.time() * 1000) + MAX_RUNTIME_MS
    while int(time.time() * 1000) < deadline and not job_done(pending):
        res = run_step(jwt, p, pending)
        if res is False:
            events.append({"status":"failed","notification":notify("!","执行失败","Duolingo 拒绝了该操作，请稍后再试",8)})
            append_log(p, type_, {"amount": amount}, "failed")
            p["pending"] = None; save_profile(p); return ndjson(events)
        pending["done"] = min(pending["requested"], pending["done"] + res["granted"])
        quota_consume(p, type_, res["granted"]); save_profile(p)
        pct = int(pending["done"] / max(1, pending["requested"]) * 100)
        events.append({"status":"loading","percentage":min(100, pct)})

    if job_done(pending):
        got = pending["done"]
        events.append({"status":"completed","amount":got,
                       "notification":notify("✓","完成","已到账 "+str(got)+" "+(UNITS.get(type_,"")),8)})
        append_log(p, type_, {"amount": amount, "got": got}, "completed")
        update_stats(p, type_, got); p["pending"] = None; save_profile(p)
    else:
        pct = int(pending["done"] / max(1, pending["requested"]) * 100)
        events.append({"status":"loading","percentage":min(100, pct),
                       "notification":notify("⏳","进行中","服务器执行时间已到，请再次请求继续",8)})
        save_profile(p)
    return ndjson(events)

# ---------- auth.php ----------
def auth_fail(error, code=400):
    return json_response({"status": "failed", "error": error}, code)

@app.route("/auth.php", methods=["GET", "POST", "OPTIONS"])
def auth_php():
    if request.method == "OPTIONS": return ("", 204)
    action = request.args.get("action", "")
    try: data = request.get_json(silent=True) or {}
    except Exception: data = {}
    token = str(data.get("token", "")).strip()
    jwt = str(data.get("jwt", "")).strip()
    password = str(data.get("password", ""))

    if action == "check":
        if not token: return auth_fail("缺少令牌")
        if not jwt: return auth_fail("缺少 JWT")
        t = load_token(token)
        if not t: return auth_fail("令牌无效")
        sid = jwt_sub(jwt)
        if not sid: return auth_fail("JWT 无法解析")
        info = fetch_duo_full_info(jwt, sid)
        if not info or not info.get("username"): return auth_fail("无法查询 Duolingo 账号")
        u = info["username"]
        return json_response({"status": "completed", "username": u,
                              "registered": load_account(u) is not None, "token": token})

    if action == "setup":
        if not token: return auth_fail("缺少令牌")
        if not jwt: return auth_fail("缺少 JWT")
        if len(password) < 6: return auth_fail("密码至少 6 位")
        t = load_token(token)
        if not t: return auth_fail("令牌无效")
        sid = jwt_sub(jwt)
        if not sid: return auth_fail("JWT 无法解析")
        info = fetch_duo_full_info(jwt, sid)
        if not info or not info.get("username"): return auth_fail("无法查询 Duolingo 账号")
        u = info["username"]
        if load_account(u): return auth_fail("该账号已注册，请直接登录", 409)
        salt = random_salt()
        d = {"username": u, "password_hash": salt + ":" + hash_password(password, salt),
             "duolingo_id": sid, "tier": "free", "created_at": now()}
        save_account(u, d)
        t["authorized"] = True; t["account"] = u; t["username"] = u; t["duolingo_id"] = sid
        save_token(token, t)
        return json_response({"status": "completed", "username": u, "message": "注册成功，请返回 Duolingo 页面。"})

    if action == "login":
        if not token: return auth_fail("缺少令牌")
        if not jwt: return auth_fail("缺少 JWT")
        if not password: return auth_fail("请填写密码")
        t = load_token(token)
        if not t: return auth_fail("令牌无效")
        sid = jwt_sub(jwt)
        if not sid: return auth_fail("JWT 无法解析")
        info = fetch_duo_full_info(jwt, sid)
        if not info or not info.get("username"): return auth_fail("无法查询 Duolingo 账号")
        u = info["username"]
        existing = load_account(u)
        if not existing: return auth_fail("该账号不存在，请先注册")
        stored = str(existing.get("password_hash", ""))
        if ":" not in stored: return auth_fail("密码错误", 401)
        salt, expected = stored.split(":", 1)
        if hash_password(password, salt) != expected: return auth_fail("密码错误", 401)
        t["authorized"] = True; t["account"] = u; t["username"] = u; t["duolingo_id"] = sid
        save_token(token, t)
        return json_response({"status": "completed", "username": u, "message": "登录成功，请返回 Duolingo 页面。"})

    if action == "logout":
        return json_response({"status": "completed"})
    return auth_fail("未知操作，支持: check / setup / login / logout")

# ---------- static.php ----------
MIME = {"woff2":"font/woff2","woff":"font/woff","ttf":"font/ttf","otf":"font/otf",
        "png":"image/png","jpg":"image/jpeg","jpeg":"image/jpeg","gif":"image/gif",
        "svg":"image/svg+xml","ico":"image/x-icon","css":"text/css","js":"application/javascript"}

@app.route("/static.php", methods=["GET"])
def static_php():
    path = request.args.get("path", "")
    path = re.sub(r"[^a-zA-Z0-9/._-]", "", path)
    full = os.path.realpath(os.path.join(STATIC_DIR, path))
    base = os.path.realpath(STATIC_DIR)
    if not full.startswith(base) and not os.path.isfile(full):
        return json_response({"error": "not found"}, 404)
    if not os.path.isfile(full):
        return json_response({"error": "not found"}, 404)
    ext = path.rsplit(".", 1)[-1].lower() if "." in path else ""
    resp = Response(open(full, "rb").read(), mimetype=MIME.get(ext, "application/octet-stream"))
    resp.headers["Access-Control-Allow-Origin"] = "*"
    resp.headers["Cache-Control"] = "public, max-age=86400"
    return resp

@app.route("/DuolingoHelper.user.js", methods=["GET"])
def script_js():
    js_path = os.path.join(os.path.dirname(__file__), "DuolingoHelper.user.js")
    if not os.path.isfile(js_path): return json_response({"error": "script not found"}, 404)
    return Response(open(js_path, encoding="utf-8").read(), mimetype="text/javascript; charset=utf-8")

@app.route("/legacy/chess/move", methods=["POST"])
def chess(): return json_response({"status": "failed", "error": "not supported"}, 404)

@app.route("/analytics/legacy", methods=["POST"])
def analytics(): return json_response({"ok": True})

# 静态代理（PHP 版本号比较靠本地 JS @version，此处读取脚本文件）
def server_version():
    js_path = os.path.join(os.path.dirname(__file__), "DuolingoHelper.user.js")
    try:
        with open(js_path, encoding="utf-8") as f:
            for _ in range(20):
                line = f.readline()
                m = re.search(r"@version\s+(\S+)", line)
                if m: return m.group(1).strip()
    except Exception:
        pass
    return SERVER_VERSION
