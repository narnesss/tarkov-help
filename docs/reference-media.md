# Изображения справочника и наград

`PublicMedia` заменяет только поля изображений в публичном JSON по точной карте
`config/public-media.php`. Происхождение (`originalUrl`, `sourceUrl`, `wikiLink`), описания,
ID, количества и связи не меняются; исходные SQL/ContentFiles сохраняются без записи.
Редакторские адреса, отсутствующие в карте, остаются приоритетными. Карта участвует
в ревизии API: серверный и браузерный кэш обновляются без ручной очистки.

Локальная копия значка «Посылка сотрудника TerraGroup»:

- источник: https://static.wikia.nocookie.net/escapefromtarkov_gamepedia/images/c/c9/TerraGroup_employee%27s_package_icon.png/revision/latest?cb=20261016185408;
- контекст и атрибуция: https://escapefromtarkov.fandom.com/wiki/Side_Hustle;
- файл: `public/assets/item-images/terragroup-employee-package-861832403ea0.webp`;
- получена 2026-10-17; исходные байты WebP, отданные CDN браузеру, не редактировались;
- игровые материалы — Battlestate Games, копия иллюстрации — Escape from Tarkov Wiki;
  сведения об исходных страницах и лицензии сохранены в контенте событийной цепочки.

Прямые запросы к внешнему CDN возвращали 403, в браузере изображение загружалось.
Локальная копия устраняет зависимость этого значка от проверки клиента внешним CDN.
Название содержит хеш файла; при замене изображения менять имя и карту одновременно.


Иконка ключ-карты «Полихим» (Ключ-карта от склада TerraGroup): [источник Wiki](https://escapefromtarkov.fandom.com/ru/wiki/Ключ-карта_от_склада_TerraGroup), изображение Battlestate Games. Локальная копия `public/assets/item-images/terragroup-storage-room-keycard-polykhim.webp` сохранена без изменения размера и прозрачности. PublicMedia заменяет только точные URL иконки в публичном ответе; исходные SQL/JSON и изображения редактора сохраняются. DocumentMapOverlay распознаёт короткое название «Полихим» и использует существующую запись каталога, без создания второго предмета.
