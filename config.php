<?php

// ============================================================
// 服务器通用常量
// ============================================================

// Duolingo 官方接口
const DUO_BASE      = 'https://www.duolingo.com/2017-06-30';
const SESSIONS_URL  = DUO_BASE . '/sessions';
const STORIES_URL   = 'https://stories.duolingo.com/api2/stories';
const GOALS_API_URL = 'https://goals-api.duolingo.com';

// XP 农场固定参数（英→法故事）
const STORY_SLUG   = 'fr-en-le-passeport';
const FROM_LANG    = 'en';
const LEARN_LANG   = 'fr';
const XP_PER_STORY = 499;
const XP_MIN       = 30;
const XP_BONUS_MAX = 469;

// 宝石农场奖励名
const GEM_REWARDS = [
    'SKILL_COMPLETION_BALANCED-…-2-GEMS',
    'SKILL_COMPLETION_BALANCED-…-2-GEMS',
];

// 连击农场题目类型
const CHALLENGE_TYPES = [
    'assist', 'characterIntro', 'characterMatch', 'characterPuzzle', 'characterSelect',
    'characterTrace', 'characterWrite', 'completeReverseTranslation', 'definition', 'dialogue',
    'extendedMatch', 'extendedListenMatch', 'form', 'freeResponse', 'gapFill', 'judge', 'listen',
    'listenComplete', 'listenMatch', 'match', 'name', 'listenComprehension', 'listenIsolation',
    'listenSpeak', 'listenTap', 'orderTapComplete', 'partialListen', 'partialReverseTranslate',
    'patternTapComplete', 'radioBinary', 'radioImageSelect', 'radioListenMatch',
    'radioListenRecognize', 'radioSelect', 'readComprehension', 'reverseAssist', 'sameDifferent',
    'select', 'selectPronunciation', 'selectTranscription', 'svgPuzzle', 'syllableTap',
    'syllableListenTap', 'speak', 'tapCloze', 'tapClozeTable', 'tapComplete', 'tapCompleteTable',
    'tapDescribe', 'translate', 'transliterate', 'transliterationAssist', 'typeCloze',
    'typeClozeTable', 'typeComplete', 'typeCompleteTable', 'writeComprehension',
];

// 领取 Super 参数
const SUPER_ITEM_NAME  = 'immersive_subscription';
const SUPER_PRODUCT_ID = 'com.duolingo.immersive_free_trial_subscription';

// 单位名称
const UNITS = [
    'xp'            => '经验',
    'gem'           => '宝石',
    'xp_boost'      => '经验翻倍',
    'heart_refill'  => '红心补满',
    'streak'        => '增加连胜',
    'streak_freeze' => '连胜保护',
    'quest'         => '任务补全',
];

// 单次 PHP 执行的工作预算（秒）
const MAX_RUNTIME_SECONDS = 22;

// 用户档案根目录
const DATA_DIR = __DIR__ . '/data';

// ============================================================
// 三档额度/冷却配置
// 每个类型：max_single（一次最多）、max_total（周期总额度）、cooldown（冷却秒数）
// ============================================================

const TIERS = [
    'free' => [
        'xp'            => ['max_single' => 10000, 'max_total' => 50000, 'cooldown' => 7200],
        'gem'           => ['max_single' => 100,   'max_total' => 500,   'cooldown' => 14400],
        'xp_boost'      => ['max_single' => 1,     'max_total' => 1,     'cooldown' => 120],
        'heart_refill'  => ['max_single' => 1,     'max_total' => 1,     'cooldown' => 120],
        'streak'        => ['max_single' => 15,    'max_total' => 30,    'cooldown' => 7200],
        'streak_freeze' => ['max_single' => 2,     'max_total' => 4,     'cooldown' => 86400],
        'quest'         => ['max_single' => 1,     'max_total' => 5,     'cooldown' => 3600],
    ],
    'mid' => [
        'xp'            => ['max_single' => 20000, 'max_total' => 80000, 'cooldown' => 1800],
        'gem'           => ['max_single' => 200,   'max_total' => 600,   'cooldown' => 1800],
        'xp_boost'      => ['max_single' => 1,     'max_total' => 1,     'cooldown' => 30],
        'heart_refill'  => ['max_single' => 1,     'max_total' => 1,     'cooldown' => 30],
        'streak'        => ['max_single' => 30,    'max_total' => 60,    'cooldown' => 1800],
        'streak_freeze' => ['max_single' => 4,     'max_total' => 8,     'cooldown' => 43200],
        'quest'         => ['max_single' => 1,     'max_total' => 10,    'cooldown' => 1800],
    ],
    'vip' => [
        // 高级档：待你给数值后填入，现在先放占位（和中等一样）
        'xp'            => ['max_single' => 20000, 'max_total' => 80000, 'cooldown' => 1800],
        'gem'           => ['max_single' => 200,   'max_total' => 600,   'cooldown' => 1800],
        'xp_boost'      => ['max_single' => 1,     'max_total' => 1,     'cooldown' => 30],
        'heart_refill'  => ['max_single' => 1,     'max_total' => 1,     'cooldown' => 30],
        'streak'        => ['max_single' => 30,    'max_total' => 60,    'cooldown' => 1800],
        'streak_freeze' => ['max_single' => 4,     'max_total' => 8,     'cooldown' => 43200],
        'quest'         => ['max_single' => 1,     'max_total' => 10,    'cooldown' => 1800],
    ],
];

// ============================================================
// 支持的类型列表（普通 + 中等都支持的类型）
// ============================================================
const SUPPORTED_TYPES = ['xp', 'gem', 'xp_boost', 'heart_refill', 'streak', 'streak_freeze', 'quest'];
