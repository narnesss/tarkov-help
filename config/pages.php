<?php
declare(strict_types=1);
return json_decode(<<<'JSON'
{
  "index": {
    "title": "Escape from Tarkov Wiki — карты, квесты и справочник",
    "description": "Подготовьтесь к следующему рейду: интерактивные карты, квесты торговцев, основной сюжет, предметы и личный прогресс в PVE и PVP.",
    "styles": [
      "style.css",
      "site-ui.css",
      "controls.css",
      "home.css"
    ]
  },
  "documents": {
    "title": "Документы — Escape from Tarkov Wiki",
    "description": "Ваш атлас документов Kord Breach: 12 локаций, фотографии мест появления, поиск и личный прогресс.",
    "styles": [
      "style.css",
      "readability.css",
      "site-ui.css",
      "controls.css"
    ]
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
    ]
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
    ]
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
    ]
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
    ]
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
    ]
  },
  "quest-map-source": {
    "title": "Карта мест выполнения квеста",
    "description": "Карта мест выполнения квеста",
    "styles": [
      "vendor/leaflet.css",
      "quest-map-source.css",
      "controls.css"
    ]
  }
}
JSON, true, 32, JSON_THROW_ON_ERROR);
