<?php
// auth.php — обработка авторизации через Wargaming OpenID
require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

// Параметры callback от Wargaming
$status = $_GET['status'] ?? null;
$access_token = $_GET['access_token'] ?? null;
$account_id = $_GET['account_id'] ?? null;
$nickname = $_GET['nickname'] ?? null;
$expires_at = $_GET['expires_at'] ?? null;
$ref_code = $_GET['ref'] ?? null;

if ($status === null) {
    // Если ref из TS бота, редирект с сохранением ref
    if (!empty($ref_code)) {
        $params = [
            'application_id' => $config['wot_openid_app_id'],
            'redirect_uri' => $config['redirect_uri'] . '?ref=' . urlencode($ref_code),
            'response_type' => 'code',
        ];
        $auth_url = $config['wot_openid_url'] . '?' . http_build_query($params);
        header("Location: " . $auth_url);
        exit;
    }
    
    // Генерация ссылки для авторизации (JSON для API)
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    
    $params = [
        'application_id' => $config['wot_openid_app_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
    ];
    $auth_url = $config['wot_openid_url'] . '?' . http_build_query($params);
    
    echo json_encode([
        'status' => 'ok',
        'message' => 'Please use this URL to authenticate',
        'auth_url' => $auth_url
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Обработка callback
if ($status !== 'ok' || !$access_token || !$account_id || !$nickname || !$expires_at) {
    show_error_page('Ошибка авторизации: Неверные или отсутствующие параметры');
    exit;
}

// Сохранение в БД
$pdo = get_db_connection();
if (!$pdo) {
    show_error_page('Ошибка: Не удалось подключиться к базе данных');
    exit;
}

$now = time();
try {
    $stmt = $pdo->prepare("
        INSERT INTO user_wg (account_id, nickname, access_token, expires_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            nickname = VALUES(nickname),
            access_token = VALUES(access_token),
            expires_at = VALUES(expires_at),
            updated_at = VALUES(updated_at)
    ");
    $stmt->execute([$account_id, $nickname, $access_token, $expires_at, $now, $now]);
    
    // Обработка ref из TS бота
    $ts_unique_id = null;
    if ($ref_code) {
        try {
            // Получить TS UID от бота
            $tsUidUrl = "http://127.0.0.1:5000/get_ts_uid?ref=" . urlencode($ref_code);
            $ch_ts_uid = curl_init($tsUidUrl);
            curl_setopt($ch_ts_uid, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_ts_uid, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch_ts_uid, CURLOPT_CONNECTTIMEOUT, 3);
            $tsUidResponse = curl_exec($ch_ts_uid);
            $httpCode = curl_getinfo($ch_ts_uid, CURLINFO_HTTP_CODE);
            curl_close($ch_ts_uid);

            if ($tsUidResponse && $httpCode === 200) {
                $tsUidData = json_decode($tsUidResponse, true);
                
                if (isset($tsUidData['status']) && $tsUidData['status'] === 'ok' && !empty($tsUidData['ts_unique_id'])) {
                    $ts_unique_id = $tsUidData['ts_unique_id'];
                    
                    // Сохранить TS UID
                    $stmt = $pdo->prepare("UPDATE user_wg SET ts_unique_id = ? WHERE account_id = ?");
                    $stmt->execute([$ts_unique_id, $account_id]);
                    
                    // Получить статистику для описания и групп
                    $statsUrl = "https://supports.kz/wg/api.php?account_id=" . urlencode($account_id);
                    $ch_stats = curl_init($statsUrl);
                    curl_setopt($ch_stats, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch_stats, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch_stats, CURLOPT_CONNECTTIMEOUT, 5);
                    $statsResponse = curl_exec($ch_stats);
                    curl_close($ch_stats);
                    
                    if ($statsResponse) {
                        $statsData = json_decode($statsResponse, true);
                        
                        if (isset($statsData['status']) && $statsData['status'] === 'ok') {
                            $wn8 = $statsData['wn8']['value'] ?? 0;
                            $battles = $statsData['statistics']['battles'] ?? 0;
                            $wins = $statsData['statistics']['wins'] ?? 0;
                            $clanTag = $statsData['clan']['tag'] ?? '';
                            $roleLocalized = $statsData['clan']['role_localized'] ?? 'Без клана';
                            
                            $winRate = ($battles > 0) ? round(($wins / $battles) * 100, 2) : 0;
                            
                            // Определить группу по WN8
                            $ratingGroup = '╠• Твинк либо плохой игрок';
                            if ($wn8 >= 2800) $ratingGroup = '╠• Уникальный игрок';
                            elseif ($wn8 >= 1900) $ratingGroup = '╠• Отличный игрок';
                            elseif ($wn8 >= 1600) $ratingGroup = '╠• Хороший игрок';
                            elseif ($wn8 >= 1250) $ratingGroup = '╠• Нормальный игрок';
                            elseif ($wn8 >= 900) $ratingGroup = '╠• Игрок ниже среднего';
                            
                            $description = "[$clanTag] ● $nickname ● WN8: $wn8 ● {$winRate}% ● $roleLocalized";
                            
                            // Сохранить статистику в БД
                            $stmt = $pdo->prepare("
                                INSERT INTO user_wg (
                                    account_id, nickname, access_token, expires_at, created_at, updated_at,
                                    COLWN8, win_rate, battles, clan_tag, role_localized, current_rating_group, last_stats_update
                                )
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    nickname = VALUES(nickname),
                                    access_token = VALUES(access_token),
                                    expires_at = VALUES(expires_at),
                                    updated_at = VALUES(updated_at),
                                    COLWN8 = VALUES(COLWN8),
                                    win_rate = VALUES(win_rate),
                                    battles = VALUES(battles),
                                    clan_tag = VALUES(clan_tag),
                                    role_localized = VALUES(role_localized),
                                    current_rating_group = VALUES(current_rating_group),
                                    last_stats_update = VALUES(last_stats_update)
                            ");
                            $stmt->execute([
                                $account_id, $nickname, $access_token, $expires_at, $now, $now,
                                $wn8, $winRate, $battles, $clanTag, $roleLocalized, $ratingGroup, $now
                            ]);
                            
                            if ($ts_unique_id) {
                                $stmt = $pdo->prepare("UPDATE user_wg SET ts_unique_id = ? WHERE account_id = ?");
                                $stmt->execute([$ts_unique_id, $account_id]);
                            }
                            
                            // Отправить webhook для обновления TS
                            $webhookData = [
                                'ts_unique_id' => $ts_unique_id,
                                'nickname' => $nickname,
                                'description' => $description,
                                'account_id' => $account_id
                            ];
                            
                            $ch_webhook = curl_init('http://127.0.0.1:5000/update_description');
                            curl_setopt($ch_webhook, CURLOPT_POST, 1);
                            curl_setopt($ch_webhook, CURLOPT_POSTFIELDS, json_encode($webhookData));
                            curl_setopt($ch_webhook, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                                'Authorization: Bearer WG_TS_SECRET_7k9mP2xQ5nL8vR3w' // Заменить на реальный секрет, если нужно
                            ]);
                            curl_setopt($ch_webhook, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch_webhook, CURLOPT_TIMEOUT, 10);
                            curl_setopt($ch_webhook, CURLOPT_CONNECTTIMEOUT, 5);
                            
                            $webhookResponse = curl_exec($ch_webhook);
                            $webhookHttpCode = curl_getinfo($ch_webhook, CURLINFO_HTTP_CODE);
                            curl_close($ch_webhook);
                            
                            if ($webhookResponse && $webhookHttpCode === 200) {
                                error_log("Webhook sent for $nickname");
                            } else {
                                error_log("Webhook error for $nickname: HTTP $webhookHttpCode");
                            }
                        } else {
                            error_log("Stats API error for $nickname");
                        }
                    }
                } else {
                    error_log("Failed to get ts_unique_id for ref $ref_code");
                }
            } else {
                error_log("TS bot connection error: HTTP $httpCode");
            }
        } catch (Exception $e) {
            error_log("TS update error for $nickname: " . $e->getMessage());
        }
    }

} catch (PDOException $e) {
    show_error_page('Ошибка: Не удалось сохранить данные авторизации');
    exit;
}

// Успех: показать страницу
show_success_page($nickname, $ts_unique_id !== null);
exit;

// Функции для страниц
function show_error_page($message) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Ошибка авторизации — WOT Stats</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        <link rel="icon" href="https://supports.kz/template/img/favicon.ico" type="image/x-icon">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            :root {
                --accent: #FF9A3D;
                --bg: #0f1419;
                --bg-card: #1a1f29;
                --text: #e4e6eb;
                --text-secondary: #9ca3af;
                --text-muted: #6b7280;
                --border: #2d3748;
                --shadow: 0 8px 32px rgba(0,0,0,0.4);
                --radius: 16px;
                --radius-sm: 12px;
            }
            body {
                background: var(--bg);
                color: var(--text);
                font-family: 'Inter', sans-serif;
                line-height: 1.6;
                min-height: 100vh;
                padding: 1.5rem;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            .container {
                max-width: 600px;
                width: 100%;
            }
            .card {
                background: var(--bg-card);
                border-radius: var(--radius);
                padding: 2rem;
                border: 1px solid var(--border);
                box-shadow: var(--shadow);
                text-align: center;
            }
            .alert {
                background: rgba(239,68,68,0.1);
                padding: 1rem;
                border-radius: var(--radius-sm);
                border: 1px solid rgba(239,68,68,0.3);
                color: #f87171;
                margin-bottom: 1rem;
            }
            .alert i {
                margin-right: 0.5rem;
            }
            .btn-home {
                display: inline-block;
                padding: 0.75rem 1.5rem;
                background: linear-gradient(135deg, var(--accent), #E07A2F);
                border-radius: var(--radius-sm);
                color: #fff;
                font-weight: 600;
                text-decoration: none;
                transition: all 0.2s;
            }
            .btn-home:hover {
                background: linear-gradient(135deg, #E07A2F, var(--accent));
                transform: scale(1.05);
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card">
                <div class="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <p>Что-то пошло не так. Пожалуйста, попробуйте снова.</p>
                <a href="https://supports.kz/wg/" class="btn-home">Вернуться на главную</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function show_success_page($nickname, $ts_linked = false) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Успешная авторизация — WOT Stats</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        <link rel="icon" href="https://supports.kz/template/img/favicon.ico" type="image/x-icon">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            :root {
                --accent: #FF9A3D;
                --bg: #0f1419;
                --bg-card: #1a1f29;
                --text: #e4e6eb;
                --text-secondary: #9ca3af;
                --text-muted: #6b7280;
                --border: #2d3748;
                --shadow: 0 8px 32px rgba(0,0,0,0.4);
                --radius: 16px;
                --radius-sm: 12px;
                --ok: #2bd518;
            }
            body {
                background: var(--bg);
                color: var(--text);
                font-family: 'Inter', sans-serif;
                line-height: 1.6;
                min-height: 100vh;
                padding: 1.5rem;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            .container {
                max-width: 600px;
                width: 100%;
            }
            .card {
                background: var(--bg-card);
                border-radius: var(--radius);
                padding: 2rem;
                border: 1px solid var(--border);
                box-shadow: var(--shadow);
                text-align: center;
            }
            .success {
                background: rgba(16,185,129,0.1);
                padding: 1rem;
                border-radius: var(--radius-sm);
                border: 1px solid rgba(16,185,129,0.3);
                color: #6ee7b7;
                margin-bottom: 1rem;
            }
            .success i {
                margin-right: 0.5rem;
            }
            .ts-success {
                background: rgba(59,130,246,0.1);
                padding: 0.75rem;
                border-radius: var(--radius-sm);
                border: 1px solid rgba(59,130,246,0.3);
                color: #93c5fd;
                margin-bottom: 1rem;
                font-size: 0.9rem;
            }
            .btn-home {
                display: inline-block;
                padding: 0.75rem 1.5rem;
                background: linear-gradient(135deg, var(--accent), #E07A2F);
                border-radius: var(--radius-sm);
                color: #fff;
                font-weight: 600;
                text-decoration: none;
                transition: all 0.2s;
                margin: 0.25rem;
            }
            .btn-ts {
                display: inline-block;
                padding: 0.75rem 1.5rem;
                background: linear-gradient(135deg, #3B82F6, #1D4ED8);
                border-radius: var(--radius-sm);
                color: #fff;
                font-weight: 600;
                text-decoration: none;
                transition: all 0.2s;
                margin: 0.25rem;
            }
            .btn-home:hover {
                background: linear-gradient(135deg, #E07A2F, var(--accent));
                transform: scale(1.05);
            }
            .btn-ts:hover {
                background: linear-gradient(135deg, #1D4ED8, #3B82F6);
                transform: scale(1.05);
            }
            .nickname {
                color: var(--accent);
                font-weight: 600;
            }
            p {color: white;}
            .buttons {
                margin-top: 1.5rem;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card">
                <div class="success">
                    <i class="bi bi-check-circle-fill"></i>
                    Спасибо за авторизацию, <span class="nickname"><?php echo htmlspecialchars($nickname); ?>!</span>
                </div>
                
                <?php if ($ts_linked): ?>
                <div class="ts-success">
                    <i class="bi bi-shield-check"></i>
                    Ваш TeamSpeak аккаунт успешно привязан! Группы и описание обновлены.
                </div>
                <?php endif; ?>
                
                <p>Теперь вы успешно авторизованы<?php echo $ts_linked ? ' и можете получить доступ к каналам TeamSpeak' : ''; ?>.</p>
                
                <div class="buttons">
                    <a href="https://supports.kz/wg/" class="btn-home">
                        <i class="bi bi-graph-up"></i> Просмотр статистики
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}