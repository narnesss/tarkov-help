<?php
declare(strict_types=1);

// Метаданные публичных Blade-страниц.
$pages = json_decode(<<<'JSON'
{
  "important-items": {
    "module": "important-items.mjs?v=loading-20261018",
    "title": "Предметы для квестов и убежища Таркова | Tarkov Compass",
    "description": "Что сохранить для квестов и убежища: количества, поиск в рейде, крафты и условия использования предметов.",
    "styles": ["style.css", "site-ui.css", "controls.css", "important-items.css"],
    "body_attributes": "class=\"important-items-page\" data-page=\"important-items\""
  },
  "account": {
    "module": "account.mjs?v=2348bcf26f9bf4b9",
    "title": "Личный кабинет — Tarkov Compass",
    "description": "Твой прогресс квестов, убежища и достижений в PVE и PVP. Вход и регистрация.",
    "styles": ["style.css", "site-ui.css", "controls.css", "account.css", "account-responsive.css"],
    "body_attributes": "class=\"account-page\" data-page=\"account\""
  },
  "terms": {
    "title": "Условия использования — Tarkov Compass",
    "description": "Условия использования справочника и личного журнала Tarkov Compass.",
    "styles": ["style.css", "site-ui.css"],
    "body_attributes": "class=\"legal-page\" data-page=\"terms\""
  },
  "personal-data": {
    "title": "Согласие на обработку персональных данных — Tarkov Compass",
    "description": "Отдельное согласие, цели обработки данных аккаунта и порядок отзыва.",
    "styles": ["style.css", "site-ui.css"],
    "body_attributes": "class=\"legal-page\" data-page=\"personal-data\""
  },
  "cookies": {
    "title": "Использование cookie — Tarkov Compass",
    "description": "Служебные cookie, локальное хранение и управление данными браузера.",
    "styles": ["style.css", "site-ui.css"],
    "body_attributes": "class=\"legal-page\" data-page=\"cookies\""
  },
  "privacy": {
    "title": "Конфиденциальность — Tarkov Compass",
    "description": "Как Tarkov Compass использует данные аккаунтов и сохраняет личный прогресс.",
    "styles": ["style.css", "site-ui.css"],
    "body_attributes": "class=\"legal-page\" data-page=\"privacy\""
  },
  "index": {
    "module": "home.mjs?v=4db8f9bd69ba8aec",
    "title": "Квесты, карты и справочник Escape from Tarkov | Tarkov Compass",
    "description": "Tarkov Compass — справочник Escape from Tarkov на русском: квесты торговцев, сюжетные задания, интерактивные карты, предметы и прогресс в PVE и PVP.",
    "styles": [
      "style.css",
      "site-ui.css",
      "controls.css",
      "home.css",
      "home-responsive.css"
    ],
    "body_attributes": "class=\"home-page\" data-page=\"index\""
  },
  "documents": {
    "module": "app.mjs?v=2ef97cfa35520c43",
    "title": "Документы Kord Breach в Таркове — места на картах | Tarkov Compass",
    "description": "Где найти документы Kord Breach в Escape from Tarkov: атлас локаций, фотографии мест появления, поиск и отметки найденных документов.",
    "styles": [
      "style.css",
      "readability.css",
      "site-ui.css",
      "controls.css",
      "documents.css"
    ],
    "body_attributes": "class=\"\" data-page=\"documents\""
  },
  "raid": {
    "module": "raid.mjs?v=e7aa86940cf80981",
    "title": "Карты Escape from Tarkov — выходы и квестовые точки | Tarkov Compass",
    "description": "Интерактивные карты Escape from Tarkov: выходы, контейнеры, места появления ЧВК и Диких, боссы, ключи и квестовые точки с фильтрами объектов.",
    "styles": [
      "style.css",
      "vendor/leaflet.css",
      "raid.css",
      "guide.css",
      "readability.css",
      "quest-ui.css",
      "site-ui.css",
      "controls.css",
      "raid-responsive.css"
    ],
    "body_attributes": "class=\"raid-page\" data-page=\"raid\""
  },
  "quests": {
    "module": "quests.mjs?v=748793e60499e4db",
    "title": "Квесты Escape from Tarkov — задания торговцев и сюжет | Tarkov Compass",
    "description": "Квесты Escape from Tarkov по торговцам: цели, условия открытия, цепочки заданий и награды. Каталог заданий и отдельный прогресс в PVE и PVP.",
    "styles": [
      "style.css",
      "guide.css",
      "readability.css",
      "quest-ui.css",
      "roadmap.css",
      "gunsmith.css",
      "site-ui.css",
      "quest-workspace.css",
      "quest-catalog.css",
      "quest-card.css",
      "story-quests.css",
      "ticket-quest-map.css",
      "controls.css",
      "quests-responsive.css",
      "quest-filters.css"
    ],
    "body_attributes": "class=\"guide-page\" data-page=\"quests\""
  },
  "quest": {
    "module": "quests.mjs?v=748793e60499e4db",
    "title": "Квест Escape from Tarkov — цели и награды | Tarkov Compass",
    "description": "Карточка задания Escape from Tarkov: цели, требования, награды и связи с другими квестами торговцев.",
    "styles": [
      "style.css",
      "guide.css",
      "readability.css",
      "quest-ui.css",
      "roadmap.css",
      "gunsmith.css",
      "site-ui.css",
      "quest-workspace.css",
      "quest-catalog.css",
      "quest-card.css",
      "story-quests.css",
      "ticket-quest-map.css",
      "controls.css",
      "quests-responsive.css",
      "quest-filters.css"
    ],
    "body_attributes": "class=\"guide-page\" data-page=\"quests\""
  },
  "reference": {
    "module": "reference.mjs?v=df296d0d0db5d9a7",
    "title": "Справочник Escape from Tarkov — предметы, патроны, убежище | Tarkov Compass",
    "description": "Справочник Escape from Tarkov на русском: предметы, патроны, бартеры, крафты, модули убежища, боссы, торговцы и достижения.",
    "styles": [
      "style.css",
      "guide.css",
      "readability.css",
      "quest-ui.css",
      "site-ui.css",
      "reference-ui.css",
      "controls.css",
      "reference-responsive.css"
    ],
    "body_attributes": "class=\"guide-page\" data-page=\"reference\""
  },
  "boss": {
    "module": "boss.mjs?v=f7c09de938448f61",
    "title": "Боссы Escape from Tarkov — здоровье и места появления | Tarkov Compass",
    "description": "Боссы Таркова: описание, здоровье по частям тела и места появления в PVP и PVE.",
    "styles": [
      "style.css",
      "guide.css",
      "readability.css",
      "quest-ui.css",
      "boss.css",
      "site-ui.css",
      "controls.css",
      "boss-responsive.css"
    ],
    "body_attributes": "class=\"guide-page\" data-page=\"boss\""
  },
  "quest-map-source": {
    "module": "quest-map-source.mjs?v=58caf35df3733ae0",
    "title": "Карта мест выполнения квеста — Tarkov Compass",
    "description": "Схема локации и мест выполнения задания Escape from Tarkov.",
    "styles": [
      "vendor/leaflet.css",
      "quest-map-source.css",
      "controls.css"
    ],
    "body_attributes": ""
  },
  "not-found": {
    "title": "Страница не найдена — Tarkov Compass",
    "description": "Запрошенная страница не найдена.",
    "styles": [
      "style.css",
      "guide.css",
      "site-ui.css",
      "controls.css"
    ],
    "body_attributes": "class=\"guide-page not-found-page\""
  }
}
JSON, true, 32, JSON_THROW_ON_ERROR);

// Общие портреты главной страницы и раздела торговцев в справочнике.
$pages['index']['trader_portraits'] = $pages['reference']['trader_portraits'] = [
    '5935c25fb3acc3127c3d8cd9' => '/assets/home-traders/peacekeeper-2d937bf5798c.webp',
    '5a7c2eca46aef81a7ca2145d' => '/assets/home-traders/mechanic-fbe5747cf062.webp',
    '5c0647fdd443bc2504c2d371' => '/assets/home-traders/jaeger-aa8c24369abe.webp',
];

// Версия главной меняется вместе с модулем; переводы строк не зависят от ОС сборки.
$homeModule = public_path('home.mjs');
if (is_file($homeModule)) {
    $pages['index']['module'] = 'home.mjs?v='.substr(hash('sha256', str_replace("\r\n", "\n", file_get_contents($homeModule))), 0, 12);
}

// Правки отображения карты получают новую версию ресурса при каждом экспорте.
$pages['raid']['module'] = 'raid.mjs?v='.hash('sha256', str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__).'/public/raid.mjs')));
return $pages;
