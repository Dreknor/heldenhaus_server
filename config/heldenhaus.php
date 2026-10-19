<?php

return [
    // Öffentliche Registrierung ist aus – Beitritt nur per Einladungscode.
    // Zum Anlegen der ersten Familie: php artisan heldenhaus:create-family
    'registration_open' => (bool) env('HELDENHAUS_REGISTRATION_OPEN', false),

    'invite_valid_days' => 14,
];
