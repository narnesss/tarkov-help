<?php
declare(strict_types=1);

// Метаданные публичных Blade-страниц.
return json_decode(<<<'JSON'
{
  "account": {
    "title": "Личный кабинет — Escape from Tarkov Wiki",
    "description": "Твой прогресс квестов, убежища и достижений в PVE и PVP. Вход и регистрация.",
    "styles": ["style.css", "site-ui.css", "controls.css", "account.css"],
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
    "styles": ["style.css", "site-ui.css", "controls.css", "account.css"],
    "body_attributes": "class=\"account-page\" data-page=\"privacy\""
  },
  "index": {
    "title": "Escape from Tarkov Wiki — карты, квесты и справочник",
    "description": "Подготовьтесь к следующему рейду: интерактивные карты, квесты торговцев, основной сюжет, предметы и личный прогресс в PVE и PVP.",
    "styles": [
      "style.css",
      "site-ui.css",
      "controls.css",
      "home.css"
    ],
    "body_attributes": "class=\"home-page\" data-page=\"index\""
  },
  "documents": {
    "title": "Документы — Escape from Tarkov Wiki",
    "description": "Ваш атлас документов Kord Breach: 12 локаций, фотографии мест появления, поиск и личный прогресс.",
    "styles": [
      "style.css",
      "readability.css",
      "site-ui.css",
      "controls.css"
    ],
    "body_attributes": "class=\"\" data-page=\"documents\""
  },
  "raid": {
    "title": "Интерактивные карты — Escape from Tarkov Wiki",
    "description": "13 интерактивных карт Таркова: контейнеры, спавны ЧВК и Диких, выходы, боссы, ключи и квестовые места.",
    "styles": [
      "style.css",
      "vendor/leaflet.css",
      "raid.css",
      "guide.css",
      "readability.css",
      "quest-ui.css",
      "site-ui.css",
      "controls.css"
    ],
    "body_attributes": "class=\"raid-page\" data-page=\"raid\""
  },
  "quests": {
    "title": "Квесты и цепочки — Escape from Tarkov Wiki",
    "description": "Квесты Таркова по торговцам: условия открытия, цепочки заданий, цели на карте, награды и личный прогресс.",
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
      "controls.css"
    ],
    "body_attributes": "class=\"guide-page\" data-page=\"quests\""
  },
  "quest": {
    "title": "Прохождение квеста — Escape from Tarkov Wiki",
    "description": "Квесты Таркова по торговцам: условия открытия, цепочки заданий, цели на карте, награды и личный прогресс.",
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
      "controls.css"
    ],
    "body_attributes": "class=\"guide-page\" data-page=\"quests\""
  },
  "reference": {
    "title": "Справочник — Escape from Tarkov Wiki",
    "description": "Предметы, патроны, бартеры, крафты, убежище, боссы и предметы для квестов Таркова.",
    "styles": [
      "style.css",
      "guide.css",
      "readability.css",
      "quest-ui.css",
      "site-ui.css",
      "reference-ui.css",
      "controls.css"
    ],
    "body_attributes": "class=\"guide-page\" data-page=\"reference\""
  },
  "boss": {
    "title": "Боссы — Escape from Tarkov Wiki",
    "description": "Боссы Таркова: описание, здоровье по частям тела и места появления в PVP и PVE.",
    "styles": [
      "style.css",
      "guide.css",
      "readability.css",
      "quest-ui.css",
      "boss.css",
      "site-ui.css",
      "controls.css"
    ],
    "body_attributes": "class=\"guide-page\" data-page=\"boss\""
  },
  "quest-map-source": {
    "title": "Карта мест выполнения квеста",
    "description": "Карта мест выполнения квеста",
    "styles": [
      "vendor/leaflet.css",
      "quest-map-source.css",
      "controls.css"
    ],
    "body_attributes": ""
  },
  "not-found": {
    "title": "Страница не найдена — Escape from Tarkov Wiki",
    "description": "Запрошенная страница не найдена.",
    "styles": [
      "style.css",
      "guide.css",
      "site-ui.css",
      "controls.css"
    ],
    "body_attributes": "class=\"guide-page\""
  }
}
JSON, true, 32, JSON_THROW_ON_ERROR);
