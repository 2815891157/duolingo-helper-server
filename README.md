# 服务器（PHP）

三档会员系统，按用户档案所在文件夹区分等级：

- data/free/ = 普通档（未充值）
- data/mid/  = 中等档
- data/vip/  = 高级档

升级/降级只需把用户档案文件从一个文件夹移到另一个。

## 接口

### 1. 注册 / 更新档案

```
POST api.php?action=register
```

请求体：`{"jwt": "duolingo的jwt_token"}`

首次接入会创建用户档案（默认放到 free 文件夹），用 JWT 查 Duolingo 拿用户名、邮箱、经验、宝石登记进去。

返回：`{"status":"completed", "tier":"free", "profile":{...}}`

### 2. 刷新用户信息

```
POST api.php?action=refresh
```

请求体：`{"jwt": "..."}`

不刷取任何东西，只用 JWT 重新拉取 Duolingo 账号信息更新档案里的快照。

### 3. 刷取请求（核心）

```
POST api.php?action=request
```

请求体：

```json
{
  "jwt": "...",
  "type": "xp",
  "amount": 1000
}
```

任务补全额外需要 date 参数：

```json
{
  "jwt": "...",
  "type": "quest",
  "date": "2026-09"
}
```

流程：
1. 查用户在哪个文件夹 → 加载对应档位的额度配置
2. 冷却检查：该类型上次请求距今超过冷却时间 → 重置额度
3. 额度检查：单次上限 + 周期总额度 → 超了就拒绝
4. 执行刷取，流式返回进度
5. 客户端再次请求可续刷（断点续传）

支持的类型：

| 类型 | 说明 | 参数 |
|---|---|---|
| xp | 故事经验农场（英→法） | amount = 目标经验 |
| gem | 宝石农场 | amount = 循环数（每循环约60宝石） |
| xp_boost | 经验翻倍（15分钟3倍） | amount = 1 |
| heart_refill | 红心补满 | amount = 1 |
| streak | 增加连胜天数 | amount = 天数 |
| streak_freeze | 连胜保护道具 | amount = 数量 |
| quest | 任务补全/领月度徽章 | date = "YYYY-MM" |

### 4. 查看额度

```
POST api.php?action=status
```

请求体：`{"jwt": "..."}`

返回当前用户的档位、各类型的剩余额度和冷却倒计时。

## 额度表

### 普通档（data/free/）

| 类型 | 一次最多 | 周期总额度 | 冷却 |
|---|---|---|---|
| 经验 | 10,000 | 50,000 | 2小时 |
| 宝石 | 100 | 500 | 4小时 |
| 经验翻倍 | 1 | 1 | 2分钟 |
| 红心补满 | 1 | 1 | 2分钟 |
| 增加连胜 | 15 | 30 | 2小时 |
| 连胜保护 | 2 | 4 | 24小时 |
| 任务补全 | 1次 | 5次 | 1小时 |

### 中等档（data/mid/）

| 类型 | 一次最多 | 周期总额度 | 冷却 |
|---|---|---|---|
| 经验 | 20,000 | 80,000 | 30分钟 |
| 宝石 | 200 | 600 | 30分钟 |
| 经验翻倍 | 1 | 1 | 30秒 |
| 红心补满 | 1 | 1 | 30秒 |
| 增加连胜 | 30 | 60 | 30分钟 |
| 连胜保护 | 4 | 8 | 12小时 |
| 任务补全 | 1次 | 10次 | 30分钟 |

### 高级档（data/vip/）

待定。

## 事件流（每条 JSON 一行）

- 进度：`{"status":"loading","percentage":42}`
- 完成：`{"status":"completed","amount":499,"notification":{...}}`
- 拒绝：`{"status":"rejected","max_amount":0,"notification":{...}}`
- 失败：`{"status":"failed","notification":{...}}`

## 部署

1. 上传 config.php、lib.php、api.php、data/ 到 InfinityFree 的 htdocs
2. data/ 下的 free/ mid/ vip/ 三个文件夹已建好，带 .htaccess 禁止外部访问
3. 额度/冷却在 config.php 的 TIERS 数组里改
4. 高级档待填入数值
