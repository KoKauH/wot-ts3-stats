<?php
require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$nick = $_GET['nick'] ?? null;
$account_id = $_GET['account_id'] ?? null;

if (!$nick && !$account_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Укажите nick или account_id'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

// Получить account_id по нику, если нужно
if ($nick && !$account_id) {
    $resp = wg_api_request('/wot/account/list/', [
        'search' => $nick,
        'type' => 'exact',
        'language' => 'en'
    ]);
    if (($resp['status'] ?? '') !== 'ok' || empty($resp['data'])) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Аккаунт не найден', 'details' => $resp], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        exit;
    }
    $account_id = $resp['data'][0]['account_id'];
}

// Получить статистику
$infoResp = wg_api_request('/wot/account/info/', [
    'account_id' => $account_id,
    'fields' => 'statistics.all',
]);
$tanksResp = wg_api_request('/wot/tanks/stats/', [
    'account_id' => $account_id,
    'language' => 'en'
]);

if (($infoResp['status'] ?? '') !== 'ok') {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Ошибка account/info', 'details' => $infoResp], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}
if (($tanksResp['status'] ?? '') !== 'ok') {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Ошибка tanks/stats', 'details' => $tanksResp], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

$accountInfo = reset($infoResp['data']);
$globalStats = $accountInfo['statistics']['all'] ?? [];
$tanks = $tanksResp['data'][$account_id] ?? [];

// Рассчитать WN8
$two = compute_two_wn8_methods($globalStats, $tanks);
$wn8_value = (int) round($two['global_avg_expected']);

$result = [
    'status' => 'ok',
    'account' => [
        'account_id' => $account_id,
        'nickname' => $accountInfo['nickname'] ?? $nick,
    ],
    'statistics' => [
        'battles' => (int)($globalStats['battles'] ?? 0),
        'wins' => (int)($globalStats['wins'] ?? 0),
        'frags' => (int)($globalStats['frags'] ?? 0),
        'damage_dealt' => (int)($globalStats['damage_dealt'] ?? 0),
    ],
    'wn8' => [
        'value' => $wn8_value,
        'battles_used_for_calc' => (int)$two['battlesWithExpected'],
        'expected_version' => $two['exp_version'],
    ],
];

// Добавить клан, если есть
$clanInfo = get_player_clan_info($account_id);
if ($clanInfo) {
    $result['clan'] = $clanInfo;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);