# wot-ts3-stats

## Описание проекта

### Русская версия:

**WOT Stats Service - Сервис статистики игроков World of Tanks**

Сервис для отображения и анализа статистики игроков World of Tanks с расчетом рейтинга WN8. Включает авторизацию через Wargaming OpenID, интеграцию с TeamSpeak и кэширование данных.

**Основной функционал:**
- 📊 Расчет и отображение WN8 рейтинга
- 👥 Информация о кланах и участниках
- 🔐 Авторизация через Wargaming OpenID
- 💬 Интеграция с TeamSpeak (обновление групп и описания)
- 🗄️ Кэширование API запросов в MySQL
- 📱 Адаптивный веб-интерфейс

**API endpoints:**
- `GET /api.php` - статистика игрока по нику или account_id
- `GET /auth.php` - авторизация через Wargaming OpenID
- `GET /index.php` - веб-интерфейс

**База данных:**
- **cache** - кэш API запросов (cache_key, data, created_at, expires_at)
- **user_wg** - данные пользователей (account_id, nickname, access_token, статистика)

**Технологии:** PHP, MySQL, Wargaming API, TeamSpeak SDK, Bootstrap 5

---

### English Version:

**WOT Stats Service - World of Tanks Player Statistics Service**

Service for displaying and analyzing World of Tanks player statistics with WN8 rating calculation. Includes Wargaming OpenID authentication, TeamSpeak integration and data caching.

**Core Features:**
- 📊 WN8 rating calculation and display
- 👥 Clan information and members
- 🔐 Wargaming OpenID authentication
- 💬 TeamSpeak integration (group and description updates)
- 🗄️ API request caching in MySQL
- 📱 Responsive web interface

**API endpoints:**
- `GET /api.php` - player statistics by nickname or account_id
- `GET /auth.php` - Wargaming OpenID authentication
- `GET /index.php` - web interface

**Database:**
- **cache** - API request cache (cache_key, data, created_at, expires_at)
- **user_wg** - user data (account_id, nickname, access_token, statistics)

**Technologies:** PHP, MySQL, Wargaming API, TeamSpeak SDK, Bootstrap 5

---

## Детальное описание файлов:

### 📁 **config.php**
- Конфигурация приложения
- Настройки API ключей (через переменные окружения)
- Параметры базы данных
- Настройки кэширования

### 📁 **functions.php**
- **get_db_connection()** - подключение к MySQL
- **wg_api_request()** - запросы к Wargaming API с кэшированием
- **calc_wn8_for_tank()** - расчет WN8 для танка
- **compute_two_wn8_methods()** - основные методы расчета WN8
- **get_player_clan_info()** - информация о клане игрока
- **is_player_data_fresh()** - проверка актуальности данных

### 📁 **api.php**
- REST API endpoint
- Принимает: `nick` или `account_id`
- Возвращает: статистику, WN8, информацию о клане
- Формат: JSON

### 📁 **auth.php**
- OAuth2 flow с Wargaming OpenID
- Обработка callback с access_token
- Интеграция с TeamSpeak через ref-коды
- Сохранение пользователей в БД

### 📁 **index.php**
- Веб-интерфейс для поиска игроков
- Отображение статистики в реальном времени
- Прогресс-бар WN8 рейтинга
- Список участников клана

---

## Структура базы данных:

### Таблица `cache`
```sql
CREATE TABLE cache (
    cache_key VARCHAR(32) PRIMARY KEY,
    data TEXT NOT NULL,
    created_at INT NOT NULL,
    expires_at INT NOT NULL
);
```

### Таблица `user_wg`
```sql
CREATE TABLE user_wg (
    account_id INT PRIMARY KEY,
    nickname VARCHAR(255),
    access_token VARCHAR(255),
    expires_at INT,
    ts_unique_id VARCHAR(255),
    COLWN8 INT,
    win_rate DECIMAL(5,2),
    battles INT,
    clan_tag VARCHAR(50),
    role_localized VARCHAR(100),
    current_rating_group VARCHAR(100),
    last_stats_update INT,
    created_at INT,
    updated_at INT
);
```

---

## Требования к окружению:

- **PHP** 7.4+ с расширениями: PDO, curl, json
- **MySQL** 5.7+ или MariaDB 10.2+
- **Доступ к API** Wargaming (application_id)
- **TeamSpeak Server** (опционально для интеграции)

---

## Особенности реализации:

1. **Кэширование** - автоматическая очистка старых записей
2. **WN8 расчет** - используется метод global_avg_expected
3. **Безопасность** - все ключи через переменные окружения
4. **Интеграция** - вебхуки для TeamSpeak бота
5. **Актуальность** - автоматическое обновление expected values

Этот проект идеально подходит для кланов World of Tanks, сообществ и серверов TeamSpeak, где требуется автоматическое отображение статистики игроков.
