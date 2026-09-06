<?php
// 登录页：接收 token + jwt，向服务器查询状态，显示设置密码或输入密码
$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$jwt    = isset($_GET['jwt'])  ? trim((string)$_GET['jwt'])  : '';
?>
<!DOCTYPE html>
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

    <!-- 加载中 -->
    <div class="panel" id="loading-panel">
        <p style="color:#666;font-size:14px">正在验证身份...</p>
    </div>

    <!-- 错误 -->
    <div class="panel hidden" id="error-panel">
        <div class="notice"><p id="error-msg">无法加载</p></div>
    </div>

    <!-- 设置密码（首次） -->
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

    <!-- 输入密码（已注册） -->
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
    var token = <?php echo json_encode($token); ?>;
    var jwt    = <?php echo json_encode($jwt); ?>;

    if (!token || !jwt) {
        showPanel('error-panel');
        document.getElementById('error-msg').textContent = '缺少必要的登录参数，请通过脚本入口打开此页面。';
        return;
    }

    // 用 jwt 查询服务器获取真实用户名 + 注册状态
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

    function showPanel(id) {
        ['loading-panel','error-panel','setup-panel','login-panel'].forEach(function(p) {
            document.getElementById(p).classList.add('hidden');
        });
        document.getElementById(id).classList.remove('hidden');
    }

    // 设置密码提交
    document.getElementById('setup-btn').addEventListener('click', function() {
        var pw = document.getElementById('setup-password').value;
        var msg = document.getElementById('setup-msg');
        if (pw.length < 6) { msg.textContent = '密码至少 6 位'; msg.className = 'msg err'; return; }
        submit('auth.php?action=setup', { token: token, jwt: jwt, password: pw }, msg);
    });

    // 登录提交
    document.getElementById('login-btn').addEventListener('click', function() {
        var pw = document.getElementById('login-password').value;
        var msg = document.getElementById('login-msg');
        if (!pw) { msg.textContent = '请填写密码'; msg.className = 'msg err'; return; }
        submit('auth.php?action=login', { token: token, jwt: jwt, password: pw }, msg);
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
                // 通知脚本
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
})();
</script>
</body>
</html>
