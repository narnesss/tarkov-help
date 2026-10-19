<?php

return [
    'previews' => require __DIR__.'/media-previews.php',
    // Точные копии нестабильных внешних изображений; редакторские URL не подменяются.
    'aliases' => [
        'https://assets.tarkov.dev/66acd6702b17692df20144c0-icon.webp'
            => '/assets/item-images/terragroup-storage-room-keycard-polykhim.webp',
        'https://static.wikia.nocookie.net/escapefromtarkov_ru_gamepedia/images/f/fd/TerraGroup_storage_room_keycard_icon.png/revision/latest?cb=20251228220855'
            => '/assets/item-images/terragroup-storage-room-keycard-polykhim.webp',
        'https://static.wikia.nocookie.net/escapefromtarkov_gamepedia/images/c/c9/TerraGroup_employee%27s_package_icon.png/revision/latest?cb=20261016185408'
            => '/assets/item-images/terragroup-employee-package-861832403ea0.webp',
    ],
];
