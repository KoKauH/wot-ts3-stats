<?php
// functions.php
$config = require __DIR__ . '/config.php';

// Подключение к БД
function get_db_connection() {
    global $config;
    $dbConfig = $config['db'];
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        return new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], $options);
    } catch (PDOException $e) {
        error_log("DB Connection failed: " . $e->getMessage());
        return false;
    }
}

// Очистка старых записей кэша (по TTL и старше 180 дней)
function cleanup_old_cache() {
    $pdo = get_db_connection();
    if (!$pdo) return;
    
    $now = time();
    $sql = "DELETE FROM cache WHERE expires_at < ? OR created_at < ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$now, $now - (180 * 86400)]);
}

// Обертка для Wargaming API с кэшем в MySQL
function wg_api_request($path, $params = [], $force_refresh = false) {
    global $config;
    
    $base = 'https://' . $config['wot_server'] . '/' . ltrim($path, '/');
    $params['application_id'] = $config['wot_app_id'];
    $url = $base . '?' . http_build_query($params);

    $cache_key = md5($url);
    $pdo = get_db_connection();
    $now = time();
    
    // Периодическая очистка кэша (1% шанс)
    if (mt_rand(1, 100) === 1) {
        cleanup_old_cache();
    }
    
    // Получить из кэша, если не force_refresh
    if ($pdo && !$force_refresh) {
        $stmt = $pdo->prepare("SELECT data FROM cache WHERE cache_key = ? AND expires_at > ?");
        $stmt->execute([$cache_key, $now]);
        $row = $stmt->fetch();
        
        if ($row && !empty($row['data'])) {
            return json_decode($row['data'], true);
        }
    }

    // Запрос к API
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'WOT-Stats-Service/1.0',
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['status' => 'error', 'message' => 'Curl error: ' . $err];
    }

    // Сохранить в кэш
    if ($pdo) {
        $expires_at = $now + $config['cache_ttl'];
        $stmt = $pdo->prepare("REPLACE INTO cache (cache_key, data, created_at, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$cache_key, $resp, $now, $expires_at]);
    }

    return json_decode($resp, true);
}

// Формула WN8 для одного танка
function calc_wn8_for_tank($avgDamage, $avgFrag, $avgSpot, $avgDef, $avgWin, $exp) {
    // Защита от деления на ноль
    foreach (['expDamage','expFrag','expSpot','expDef','expWin'] as $k) {
        if (!isset($exp[$k]) || $exp[$k] == 0) {
            return 0.0;
        }
    }

    $rDAMAGE = $avgDamage / $exp['expDamage'];
    $rSPOT   = $avgSpot   / $exp['expSpot'];
    $rFRAG   = $avgFrag   / $exp['expFrag'];
    $rDEF    = $avgDef    / $exp['expDef'];
    $rWIN    = $avgWin    / $exp['expWin'];

    $rWINc    = max(0, ($rWIN - 0.71) / (1 - 0.71));
    $rDAMAGEc = max(0, ($rDAMAGE - 0.22) / (1 - 0.22));
    $rFRAGc   = max(0, min($rDAMAGEc + 0.2, ($rFRAG - 0.12) / (1 - 0.12)));
    $rSPOTc   = max(0, min($rDAMAGEc + 0.1, ($rSPOT - 0.38) / (1 - 0.38)));
    $rDEFc    = max(0, min($rDAMAGEc + 0.1, ($rDEF - 0.10) / (1 - 0.10)));

    $wn8 = 980 * $rDAMAGEc
        + 210 * $rDAMAGEc * $rFRAGc
        + 155 * $rFRAGc * $rSPOTc
        + 75  * $rDEFc * $rFRAGc
        + 145 * min(1.8, $rWINc);

    return $wn8;
}

// Обновление expected values с modxvm, если файл старше 3 часов
function update_expected_values() {
    global $config;
    $path = $config['expected_values_path'];
    $url = 'https://static.modxvm.com/wn8-data-exp/json/wg/wn8exp.json';

    if (file_exists($path) && (time() - filemtime($path) < 10800)) {
        return false;  // Файл свежий
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'WOT-Stats-Service/1.0',
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $err) {
        return false; // Ошибка, используем старый файл
    }

    file_put_contents($path, $resp);
    return true;
}

// Загрузка expected values
function load_expected_values() {
    global $config;
    update_expected_values();

    $path = $config['expected_values_path'];
    if (!file_exists($path)) {
        return [[], 'unknown'];
    }
    $json = file_get_contents($path);
    $rawData = json_decode($json, true);
    
    if (!isset($rawData['data']) || !is_array($rawData['data'])) {
        return [[], 'unknown'];
    }
    
    $expected = [];
    foreach ($rawData['data'] as $item) {
        if (!isset($item['IDNum'])) continue;
        $tank_id = (string)$item['IDNum'];
        $expected[$tank_id] = [
            'expDamage' => $item['expDamage'] ?? 0,
            'expFrag' => $item['expFrag'] ?? 0,
            'expSpot' => $item['expSpot'] ?? 0,
            'expDef' => $item['expDef'] ?? 0,
            'expWin' => ($item['expWinRate'] ?? 0) / 100
        ];
    }
    
    $version = $rawData['header']['version'] ?? 'unknown';
    return [$expected, $version];
}

// Расчет WN8 двумя методами (основной — global_avg_expected)
function compute_two_wn8_methods($globalStats, $tanks_stats) {
    list($expected, $expVersion) = load_expected_values();

    $globalBattles = (int)($globalStats['battles'] ?? 0);
    $globalAvgDamage = $globalBattles > 0 ? ($globalStats['damage_dealt'] ?? 0) / $globalBattles : 0;
    $globalAvgFrag   = $globalBattles > 0 ? ($globalStats['frags'] ?? 0) / $globalBattles : 0;
    $globalAvgSpot   = $globalBattles > 0 ? ($globalStats['spotted'] ?? 0) / $globalBattles : 0;
    $globalAvgDef    = $globalBattles > 0 ? ($globalStats['dropped_capture_points'] ?? 0) / $globalBattles : 0;
    $globalAvgWin    = $globalBattles > 0 ? ($globalStats['wins'] ?? 0) / $globalBattles : 0;

    $sumWeighted = 0.0; $sumBattlesForWN8 = 0;
    $expectedWeighted = ['expDamage'=>0,'expFrag'=>0,'expSpot'=>0,'expDef'=>0,'expWin'=>0];
    $battlesWithExpected = 0;

    foreach ($tanks_stats as $t) {
        $tid = (string)($t['tank_id'] ?? '');
        $all = $t['all'] ?? null;
        $battles = (int)($all['battles'] ?? 0);
        if ($battles <= 0) continue;
        if (!isset($expected[$tid])) continue;

        $avgDamage = ($all['damage_dealt'] ?? 0) / $battles;
        $avgFrag   = ($all['frags'] ?? 0) / $battles;
        $avgSpot   = ($all['spotted'] ?? 0) / $battles;
        $avgDef    = ($all['dropped_capture_points'] ?? 0) / $battles;
        $avgWin    = ($all['wins'] ?? 0) / $battles;

        $wn8_tank = calc_wn8_for_tank($avgDamage, $avgFrag, $avgSpot, $avgDef, $avgWin, $expected[$tid]);

        $sumWeighted += $wn8_tank * $battles;
        $sumBattlesForWN8 += $battles;

        $expectedWeighted['expDamage'] += $expected[$tid]['expDamage'] * $battles;
        $expectedWeighted['expFrag']   += $expected[$tid]['expFrag']   * $battles;
        $expectedWeighted['expSpot']   += $expected[$tid]['expSpot']   * $battles;
        $expectedWeighted['expDef']    += $expected[$tid]['expDef']    * $battles;
        $expectedWeighted['expWin']    += $expected[$tid]['expWin']    * $battles;
        $battlesWithExpected += $battles;
    }

    $by_tank_weighted = $sumBattlesForWN8 > 0 ? ($sumWeighted / $sumBattlesForWN8) : 0.0;

    if ($battlesWithExpected > 0) {
        $aggExp = [
            'expDamage' => $expectedWeighted['expDamage'] / $battlesWithExpected,
            'expFrag'   => $expectedWeighted['expFrag']   / $battlesWithExpected,
            'expSpot'   => $expectedWeighted['expSpot']   / $battlesWithExpected,
            'expDef'    => $expectedWeighted['expDef']    / $battlesWithExpected,
            'expWin'    => $expectedWeighted['expWin']    / $battlesWithExpected,
        ];
        $global_avg_expected_wn8 = calc_wn8_for_tank($globalAvgDamage, $globalAvgFrag, $globalAvgSpot, $globalAvgDef, $globalAvgWin, $aggExp);
    } else {
        $global_avg_expected_wn8 = 0.0;
        $aggExp = null;
    }

    return [
        'by_tank_weighted' => $by_tank_weighted,
        'global_avg_expected' => $global_avg_expected_wn8,
        'agg_expected' => $aggExp,
        'exp_version' => $expVersion,
        'sumBattlesForWN8' => $sumBattlesForWN8,
        'battlesWithExpected' => $battlesWithExpected,
    ];
}

// Проверка свежести данных игрока в кэше (меньше 3 часов)
function is_player_data_fresh($account_id, $nickname = '') {
    global $config;
    $pdo = get_db_connection();
    if (!$pdo) return false;
    
    $now = time();
    $cache_ttl = $config['cache_ttl'];
    
    $requests = [
        [
            'path' => '/wot/account/list/',
            'params' => ['search' => $nickname, 'type' => 'exact', 'language' => 'en']
        ],
        [
            'path' => '/wot/account/info/',
            'params' => ['account_id' => $account_id, 'fields' => 'statistics.all']
        ],
        [
            'path' => '/wot/tanks/stats/',
            'params' => ['account_id' => $account_id, 'language' => 'en']
        ]
    ];
    
    foreach ($requests as $request) {
        $base = 'https://' . $config['wot_server'] . '/' . ltrim($request['path'], '/');
        $params = $request['params'];
        $params['application_id'] = $config['wot_app_id'];
        $url = $base . '?' . http_build_query($params);
        
        $cache_key = md5($url);
        $stmt = $pdo->prepare("SELECT created_at FROM cache WHERE cache_key = ?");
        $stmt->execute([$cache_key]);
        $row = $stmt->fetch();
        
        if (!$row || ($now - $row['created_at']) > $cache_ttl) {
            return false;
        }
    }
    
    return true;
}

// Получение информации о клане игрока
function get_player_clan_info($account_id) {
    global $config;

    // Получить clan_id
    $accountResp = wg_api_request('/wot/account/info/', [
        'account_id' => $account_id,
        'fields' => 'clan_id'
    ]);
    
    if (($accountResp['status'] ?? '') !== 'ok') {
        return null;
    }
    
    $accountData = reset($accountResp['data']);
    $clan_id = $accountData['clan_id'] ?? null;
    
    if (!$clan_id) {
        return null;
    }
    
    // Получить данные клана
    $clanResp = wg_api_request('/wot/clans/info/', [
        'clan_id' => $clan_id,
        'fields' => 'tag,name,members_count,created_at,description,members'
    ]);
    
    if (($clanResp['status'] ?? '') !== 'ok') {
        return null;
    }
    
    $clanData = $clanResp['data'][$clan_id] ?? null;
    if (!$clanData) {
        return null;
    }
    
    // Получить роль игрока
    $memberResp = wg_api_request('/wot/clans/accountinfo/', [
        'account_id' => $account_id,
        'fields' => 'role,joined_at'
    ]);
    
    $memberData = null;
    if (($memberResp['status'] ?? '') === 'ok') {
        $memberData = reset($memberResp['data']);
    }
    
    // Локализация ролей
    $roles = [
        'commander' => 'Командующий',
        'executive_officer' => 'Заместитель командующего',
        'personnel_officer' => 'Офицер штаба',
        'combat_officer' => 'Командир подразделения',
        'intelligence_officer' => 'Офицер разведки',
        'quartermaster' => 'Офицер снабжения',
        'junior_officer' => 'Младший офицер',
        'recruitment_officer' => 'Офицер по кадрам',
        'private' => 'Боец',
        'recruit' => 'Новобранец',
        'reservist' => 'Резервист'
    ];
    
    $role = $memberData['role'] ?? 'private';
    $role_localized = $roles[$role] ?? $role;

    // Собрать список членов клана
    $members = [];
    if (!empty($clanData['members']) && is_array($clanData['members'])) {
        foreach ($clanData['members'] as $m) {
            $members[] = [
                'account_id' => $m['account_id'],
                'nickname' => $m['account_name'],
                'role' => $roles[$m['role']] ?? $m['role_i18n'] ?? $m['role']
            ];
        }
    }

    return [
        'clan_id' => $clan_id,
        'tag' => $clanData['tag'] ?? '',
        'name' => $clanData['name'] ?? '',
        'members_count' => $clanData['members_count'] ?? 0,
        'created_at' => $clanData['created_at'] ?? null,
        'role' => $role,
        'role_localized' => $role_localized,
        'joined_at' => $memberData['joined_at'] ?? null,
        'members' => $members
    ];
}