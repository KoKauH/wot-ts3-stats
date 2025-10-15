<?php
// index.php — фронт для анализа WN8
require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

$searchName = $_GET['nick'] ?? '';
$refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$errors = [];
$results = null;

// Форматтеры
function fmt_int($n) { return number_format((int)$n, 0, '.', ' '); }
function fmt_dec_ru($v, $decimals = 2) { return number_format((float)$v, $decimals, ',', ' '); }
function fmt_date($timestamp) {
    if (!$timestamp) return 'Неизвестно';
    return date('d.m.Y', $timestamp);
}

// Цвет WN8
function wn8_color($value) {
    if ($value < 300) return '#DD0000';
    if ($value < 600) return '#FF4000';
    if ($value < 900) return '#FFA500';
    if ($value < 1250) return '#FFFF00';
    if ($value < 1600) return '#00FF00';
    if ($value < 1900) return '#00C0DD';
    if ($value < 2350) return '#007FFF';
    if ($value < 2900) return '#AA00FF';
    return '#FF00FF';
}

// Название ранга WN8
function wn8_rank_name($value) {
    return match(true) {
        $value < 300 => 'Очень плохо',
        $value < 600 => 'Плохо',
        $value < 900 => 'Ниже среднего',
        $value < 1250 => 'Средний',
        $value < 1600 => 'Хороший',
        $value < 1900 => 'Очень хороший',
        $value < 2350 => 'Отличный',
        $value < 2900 => 'Уникум',
        default => 'Супер-Уникум'
    };
}

// Прогресс до следующего ранга
function wn8_progress($value) {
    $thresholds = [300, 600, 900, 1250, 1600, 1900, 2350, 2900];
    
    if ($value >= 2900) {
        return ['current' => 2900, 'next' => null, 'progress' => 100, 'nextRank' => 'Максимум'];
    }
    
    $current = 0;
    foreach ($thresholds as $threshold) {
        if ($value < $threshold) {
            $rangeSize = $threshold - $current;
            $progress = (($value - $current) / $rangeSize) * 100;
            return [
                'current' => $current,
                'next' => $threshold,
                'progress' => max(0, min(100, $progress)),
                'nextRank' => wn8_rank_name($threshold),
                'needed' => $threshold - $value
            ];
        }
        $current = $threshold;
    }
    
    return ['current' => 0, 'next' => 300, 'progress' => 0, 'nextRank' => 'Очень плохо'];
}

if ($searchName !== '') {
    $listResp = wg_api_request('/wot/account/list/', [
        'search' => $searchName, 'type' => 'exact', 'language' => 'en'
    ], $refresh);
    
    if (($listResp['status'] ?? '') !== 'ok') $errors[] = 'Ошибка при поиске аккаунта';
    else {
        $data = $listResp['data'] ?? [];
        if (!$data) $errors[] = 'Ничего не найдено по нику: '.htmlspecialchars($searchName);
        else {
            $acc = reset($data); $account_id = $acc['account_id'];
            $infoResp = wg_api_request('/wot/account/info/', ['account_id'=>$account_id,'fields'=>'statistics.all'], $refresh);
            $tanksResp = wg_api_request('/wot/tanks/stats/', ['account_id'=>$account_id,'language'=>'en'], $refresh);
            
            if (($infoResp['status']??'')!=='ok'||($tanksResp['status']??'')!=='ok') $errors[]='Ошибка при получении данных';
            else {
                $accountInfo = reset($infoResp['data']);
                $globalStats = $accountInfo['statistics']['all'] ?? [];
                $tanks = $tanksResp['data'][$account_id] ?? [];
                $two = compute_two_wn8_methods($globalStats,$tanks);
                
                $clanInfo = get_player_clan_info($account_id);
                
                $results = [
                    'account'=>$acc,'global'=>$globalStats,
                    'clan'=>$clanInfo,
                    'wn8'=>[
                        'value'=>(int)round($two['global_avg_expected']),
                        'battles_total'=>(int)($globalStats['battles']??0),
                        'battles_used_for_calc'=>(int)$two['battlesWithExpected'],
                        'expected_version'=>$two['exp_version']
                    ]
                ];
            }
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>WOT Stats — Анализ WN8</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<link rel="icon" href="https://supports.kz/template/img/favicon.ico" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --accent:#FF9A3D;--bg:#0f1419;--bg-card:#1a1f29;--bg-card-hover:#232936;
  --text:#e4e6eb;--text-secondary:#9ca3af;--text-muted:#6b7280;
  --border:#2d3748;--shadow:0 8px 32px rgba(0,0,0,0.4);
  --radius:16px;--radius-sm:12px;
  --ok:#2bd518;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;
line-height:1.6;min-height:100vh;padding:1.5rem;}
.container{max-width:1600px;margin:0 auto;}
.header{background:var(--bg-card);border-radius:var(--radius);padding:1.5rem;
margin-bottom:1.5rem;border:1px solid var(--border);box-shadow:var(--shadow);}
.header-top{display:flex;align-items:center;gap:1rem;margin-bottom:1rem;}
.logo{width:48px;height:48px;border-radius:var(--radius-sm);
background:linear-gradient(135deg,var(--accent),#E07A2F);
display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;
box-shadow:0 4px 12px rgba(255,154,61,0.3);}
.brand h1{font-size:1.5rem;font-weight:700;margin:0;color:var(--text);}
.brand p{font-size:0.85rem;color:var(--text-muted);margin:0;}
.search-form{position:relative;}
.search-form input{width:100%;padding:0.75rem 3rem 0.75rem 1rem;
background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);
color:var(--text);font-size:0.95rem;transition:all 0.2s;}
.search-form input:focus{outline:none;border-color:var(--accent);
box-shadow:0 0 0 3px rgba(255,154,61,0.1);}
.search-form button{position:absolute;right:0.5rem;top:50%;transform:translateY(-50%);
padding:0.5rem 1rem;background:linear-gradient(135deg,var(--accent),#E07A2F);
border:none;border-radius:var(--radius-sm);color:#fff;font-weight:600;
cursor:pointer;transition:all 0.2s;box-shadow:0 2px 8px rgba(255,154,61,0.3);}
.search-form button:hover{transform:translateY(-50%) scale(1.05);box-shadow:0 4px 12px rgba(255,154,61,0.4);}
.card{background:var(--bg-card);border-radius:var(--radius);padding:1.5rem;
border:1px solid var(--border);box-shadow:var(--shadow);transition:all 0.3s;}
.card:hover{background:var(--bg-card-hover);border-color:#3d4556;}
.main-grid{display:grid;grid-template-columns:1fr 40%;gap:1.5rem;align-items:start;}
@media(max-width:900px){.main-grid{grid-template-columns:1fr;}}
.wn8-card{text-align:center;padding:2rem 1.5rem;position:sticky;top:1.5rem;}
.wn8-label{font-size:0.85rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-bottom:0.5rem;}
.wn8-value{font-size:4.5rem;font-weight:800;line-height:1;
background:linear-gradient(135deg,var(--wn8-color),var(--wn8-next-color));
-webkit-background-clip:text;-webkit-text-fill-color:transparent;
background-clip:text;margin:0.5rem 0;}
.wn8-badge{
  display:inline-flex;align-items:center;gap:0.5rem;
  padding:0.6rem 1.5rem;border-radius:999px;font-size:0.9rem;font-weight:700;
  background:linear-gradient(135deg,var(--wn8-color),var(--wn8-next-color));
  color:#fff;text-transform:uppercase;letter-spacing:0.5px;
  text-shadow: 
    -1px -1px 0 rgba(0,0,0,0.5),  
    1px -1px 0 rgba(0,0,0,0.5),
    -1px 1px 0 rgba(0,0,0,0.5),
    1px 1px 0 rgba(0,0,0,0.5),
    0 2px 4px rgba(0,0,0,0.4);
}
.wn8-info{margin-top:1rem;font-size:0.85rem;color:var(--text-muted);}
.progress-wrap{margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--border);}
.progress-header{display:flex;justify-content:space-between;margin-bottom:0.5rem;font-size:0.85rem;color:var(--text-secondary);}
.progress-bar-custom{height:8px;background:rgba(255,255,255,0.1);border-radius:999px;overflow:hidden;}
.progress-fill{height:100%;background:linear-gradient(90deg,var(--wn8-color),var(--wn8-next-color));transition:all 0.3s;}
.progress-footer{margin-top:0.75rem;font-size:0.85rem;color:var(--text-muted);display:flex;align-items:center;gap:0.5rem;}
.progress-footer i{color:var(--ok);}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:1rem;margin-top:1.5rem;}
.stat-box{padding:1rem;background:var(--bg-card-hover);border-radius:var(--radius-sm);text-align:center;border:1px solid var(--border);}
.stat-value{font-size:1.5rem;font-weight:700;color:var(--accent);}
.stat-label{font-size:0.85rem;color:var(--text-secondary);}
.player-info{margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid var(--border);}
.player-name{font-size:1.5rem;font-weight:700;color:var(--accent);}
.player-id{font-size:0.85rem;color:var(--text-muted);}
.clan-info{margin-top:1.5rem;padding:1.5rem;background:var(--bg-card-hover);border-radius:var(--radius-sm);border:1px solid var(--border);}
.clan-tag{font-size:1.2rem;font-weight:700;color:var(--accent);margin-right:0.5rem;}
.clan-name{font-size:1.2rem;font-weight:600;}
.clan-details{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-top:1rem;}
.clan-detail-item{display:flex;flex-direction:column;}
.clan-detail-label{font-size:0.85rem;color:var(--text-secondary);display:flex;align-items:center;gap:0.5rem;}
.clan-detail-value{font-size:0.95rem;font-weight:500;}
.clan-members{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:1rem;margin-top:1.5rem;}
.member-card{padding:0.75rem;background:var(--bg-card-hover);border-radius:var(--radius-sm);text-align:center;cursor:pointer;transition:all 0.2s;border:1px solid var(--border);text-decoration:none;color:var(--text);}
.member-card:hover{background:var(--bg-card);transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,0.2);}
.member-card i{margin-right:0.5rem;color:var(--text-secondary);}
.empty-state{text-align:center;padding:3rem 0;color:var(--text-muted);}
.refresh-btn-sm{display:inline-flex;align-items:center;gap:0.5rem;padding:0.4rem 1rem;border-radius:999px;font-size:0.8rem;font-weight:600;background:linear-gradient(135deg,var(--accent),#E07A2F);color:#fff;text-decoration:none;transition:all 0.2s;}
.refresh-btn-sm:hover{transform:scale(1.05);box-shadow:0 4px 12px rgba(255,154,61,0.4);}
.refresh-btn-disabled{opacity:0.6;cursor:not-allowed;background:linear-gradient(135deg,#6b7280,#4b5563);}
.refresh-btn-disabled:hover{transform:none;box-shadow:none;}
.refresh-text{margin-left:0.25rem;}
.wn8-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;}
.footer{text-align:center;margin-top:2rem;color:var(--text-muted);font-size:0.85rem;}
#loadingOverlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);display:none;justify-content:center;align-items:center;z-index:9999;}
.loading-spinner{width:50px;height:50px;border:5px solid #f3f3f3;border-top:5px solid var(--accent);border-radius:50%;animation:spin 1s linear infinite;}
@keyframes spin{0%{transform:rotate(0deg);}100%{transform:rotate(360deg);}}
body.loading{overflow:hidden;}
</style>
</head>
<body>
<div id="loadingOverlay"><div class="loading-spinner"></div></div>
<div class="container">
  <div class="header">
    <div class="header-top">
      <div class="logo">W</div>
      <div class="brand">
        <h1>WOT Stats</h1>
        <p>Анализ WN8 и статистики</p>
      </div>
    </div>
    <form class="search-form" action="" method="get">
      <input type="text" name="nick" placeholder="Введите ник игрока..." value="<?=htmlspecialchars($searchName)?>">
      <button type="submit">Поиск</button>
    </form>
  </div>

  <?php if($errors): ?>
    <div class="card" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#f87171;">
      <?php foreach($errors as $e):?>
        <div><i class="bi bi-exclamation-triangle me-2"></i><?=htmlspecialchars($e)?></div>
      <?php endforeach;?>
    </div>
  <?php endif; ?>

  <?php if($results):
    $b=(int)($results['global']['battles']??0);
    $w=(int)($results['global']['wins']??0);
    $d=(int)($results['global']['damage_dealt']??0);
    $k=(int)($results['global']['frags']??0);
    $avg=$b>0?$d/$b:0; 
    $wr=$b>0?$w/$b*100:0;
    $wn8=$results['wn8']['value']; 
    $color=wn8_color($wn8); 
    $rank=wn8_rank_name($wn8);
    $progress=wn8_progress($wn8);
    $nextColor=$progress['next']?wn8_color($progress['next']):$color;
    $clan=$results['clan'];
  ?>

  <div class="main-grid">
    <div class="left-side">
      <div class="card player-info">
        <div class="player-name"><?=htmlspecialchars($results['account']['nickname'])?></div>
        <div class="player-id">ID: <?=$results['account']['account_id']?></div>
      </div>

      <?php if($clan): ?>
      <div class="clan-info">
        <div>
          <span class="clan-tag">[<?=htmlspecialchars($clan['tag'])?>]</span>
          <span class="clan-name"><?=htmlspecialchars($clan['name'])?></span>
        </div>
        <div class="clan-details">
          <div class="clan-detail-item">
            <span class="clan-detail-label"><i class="bi bi-person-badge"></i> Должность</span>
            <span class="clan-detail-value"><?=htmlspecialchars($clan['role_localized'])?></span>
          </div>
          <div class="clan-detail-item">
            <span class="clan-detail-label"><i class="bi bi-calendar-check"></i> Дата вступления</span>
            <span class="clan-detail-value"><?=fmt_date($clan['joined_at'])?></span>
          </div>
          <div class="clan-detail-item">
            <span class="clan-detail-label"><i class="bi bi-people"></i> Игроков в клане</span>
            <span class="clan-detail-value"><?=fmt_int($clan['members_count'])?></span>
          </div>
          <div class="clan-detail-item">
            <span class="clan-detail-label"><i class="bi bi-calendar-plus"></i> Клан создан</span>
            <span class="clan-detail-value"><?=fmt_date($clan['created_at'])?></span>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if(!empty($clan['members'])): ?>
        <div class="clan-members">
          <?php foreach ($clan['members'] as $m): ?>
            <a href="?nick=<?=urlencode($m['nickname'])?>" class="member-card" title="<?=htmlspecialchars($m['role'])?>">
              <i class="bi bi-person"></i>
              <?=htmlspecialchars($m['nickname'])?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="stats-grid">
        <div class="stat-box">
          <span class="stat-value"><?=fmt_int($b)?></span>
          <span class="stat-label">Боёв</span>
        </div>
        <div class="stat-box">
          <span class="stat-value"><?=fmt_dec_ru($wr,1)?>%</span>
          <span class="stat-label">Побед</span>
        </div>
        <div class="stat-box">
          <span class="stat-value"><?=fmt_dec_ru($avg,0)?></span>
          <span class="stat-label">Средний урон</span>
        </div>
        <div class="stat-box">
          <span class="stat-value"><?=fmt_dec_ru($b>0?$k/$b:0,2)?></span>
          <span class="stat-label">Фраги/бой</span>
        </div>
      </div>
    </div><!-- /left-side -->

    <div class="right-side">
	<div class="card wn8-card" style="--wn8-color:<?=$color?>;--wn8-next-color:<?=$nextColor?>;">
		<div class="wn8-header">
		<div class="wn8-label">Рейтинг WN8</div>
		<?php
		$is_fresh = is_player_data_fresh($results['account']['account_id'], $results['account']['nickname']);
		if ($is_fresh): ?>
			<span class="refresh-btn-sm refresh-btn-disabled" title="Данные актуальны на текущий момент">
				<i class="bi bi-check-circle"></i>
				<span class="refresh-text">Актуально</span>
			</span>
		<?php else: ?>
			<a href="?nick=<?=urlencode($searchName)?>&refresh=1" class="refresh-btn-sm" title="Обновить статистику">
				<i class="bi bi-arrow-clockwise"></i>
				<span class="refresh-text">Обновить</span>
			</a>
		<?php endif; ?>
		</div>
		
		<div class="wn8-value"><?=$wn8?></div>
		<div class="wn8-badge">
		<i class="bi bi-trophy-fill"></i>
		<span><?=$rank?></span>
		</div>
		<div class="wn8-info">
		Расчёт: <?=fmt_int($results['wn8']['battles_used_for_calc'])?> /
		<?=fmt_int($results['wn8']['battles_total'])?> боёв
		</div>

        <?php if($progress['next']): ?>
          <div class="progress-wrap">
            <div class="progress-header">
              <span><?=$rank?></span>
              <span><?=$progress['nextRank']?></span>
            </div>
            <div class="progress-bar-custom">
              <div class="progress-fill" style="width:<?=number_format($progress['progress'],1)?>%;"></div>
            </div>
            <div class="progress-footer">
              <i class="bi bi-arrow-up-circle-fill"></i>
              <span>Ещё <?=fmt_int($progress['needed'])?> до "<?=$progress['nextRank']?>"</span>
            </div>
          </div>
        <?php else: ?>
          <div class="progress-wrap">
            <div style="padding:1rem;
                        background:rgba(16,185,129,0.1);
                        border-radius:var(--radius-sm);
                        border:1px solid rgba(16,185,129,0.3);
                        color:#6ee7b7;">
              <i class="bi bi-trophy-fill me-2"></i>Максимальный ранг достигнут!
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div><!-- /right-side -->
  </div><!-- /main-grid -->

  <?php else: ?>
    <div class="card">
      <div class="empty-state">
        <i class="bi bi-search d-block mb-2" style="font-size:2rem;"></i>
        <p>Введите ник игрока для просмотра статистики</p>
      </div>
    </div>
  <?php endif; ?>

  <div class="footer">
    © <?=date('Y')?> WOT Stats. Неофициальный анализатор. Все данные — из API Wargaming.net.
  </div>
</div>
<script>
// JS для индикатора загрузки
document.addEventListener('DOMContentLoaded', function() {
  const loadingOverlay = document.getElementById('loadingOverlay');
  const searchForm = document.querySelector('.search-form');
  const refreshLinks = document.querySelectorAll('a.refresh-btn-sm');
  const memberCards = document.querySelectorAll('.member-card');
  
  if (searchForm) {
    searchForm.addEventListener('submit', function(e) {
      const searchInput = this.querySelector('input[name="nick"]');
      if (searchInput && searchInput.value.trim() !== '') {
        showLoading();
      }
    });
  }
  
  refreshLinks.forEach(link => {
    link.addEventListener('click', function(e) {
      if (!this.classList.contains('refresh-btn-disabled')) {
        showLoading();
      }
    });
  });
  
  memberCards.forEach(card => {
    card.addEventListener('click', function(e) {
      showLoading();
    });
  });
  
  document.addEventListener('click', function(e) {
    const link = e.target.closest('a');
    if (link && link.href && link.href.includes('nick=')) {
      showLoading();
    }
  });
  
  function showLoading() {
    loadingOverlay.style.display = 'flex';
    document.body.classList.add('loading');
    
    setTimeout(() => {
      if (loadingOverlay.style.display === 'flex') {
        hideLoading();
      }
    }, 30000);
  }
  
  function hideLoading() {
    loadingOverlay.style.display = 'none';
    document.body.classList.remove('loading');
  }
  
  window.addEventListener('load', hideLoading);
});
</script>
</body>
</html>