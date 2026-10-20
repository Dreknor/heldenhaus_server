<?php

// Spielwerte – siehe docs/konzept/02-spielmechanik.md (§2.2)
return [
    'max_hp' => 50,
    'max_mp_base' => 10,

    'value_min' => -47.27,
    'value_max' => 21.27,
    'delta_base' => 0.9747,

    'difficulties' => [
        'trivial' => 0.1,
        'easy' => 1.0,
        'medium' => 1.5,
        'hard' => 2.0,
    ],

    'xp_factor' => 6,
    'damage_factor' => 2,
    'streak_bonus_cap' => 2.0,

    // Attributbonus pro Punkt
    'int_bonus_per_point' => 0.025,
    'per_bonus_per_point' => 0.02,
    'con_bonus_per_point' => 0.01,
    'con_bonus_max' => 0.5,
    'str_bonus_per_point' => 0.03,

    // Familien-Boss: Schaden je erledigter Aufgabe (× delta × Schwierigkeit), Heilung je verpasster Daily
    'boss_damage_factor' => 4,
    'boss_heal_per_missed_daily' => 3,

    // Dämpfung des Schadens nach Altersgruppe (Eltern können je Familie überschreiben)
    'age_damage_factor' => [
        'child' => 0.5,  // 9–12
        'teen' => 0.75,  // 13–16
        'adult' => 1.0,
    ],

    // K.O. bei 0 HP: kein Level- oder Ausrüstungsverlust, nur optional Gold
    'ko_gold_loss' => 0.0,
    'ko_revive_hp_ratio' => 1.0,

    // Mana-Regeneration beim Tageswechsel (Anteil vom Maximum, skaliert mit erledigten Dailies)
    'cron_mp_regen' => 0.3,

    // Familienaufgaben
    'auto_approve_hours' => 24,

    // Nach so vielen Stunden nach Tagesbeginn läuft der Tageswechsel auch ohne App-Aufruf
    'batch_cron_after_hours' => 12,

    // Zufallsfunde – im Admin-Backend unter „Items & Drops“ änderbar
    'drops' => [
        'base_chance' => 0.25,        // Grundchance pro erledigter Aufgabe (leicht)
        'daily_limit' => 5,           // max. Funde pro Person und Tag
        'per_bonus_per_point' => 0.02, // +2 % relative Chance je Spürsinn-Punkt
    ],

    // Klassen
    'class_change_days' => 30,
    'skill_casts_per_day' => 3,
    'smart_connect_daily_limit' => 10,
    'community_rhythm_gold' => 15,
    'focus_hour' => 12,

    'max_streak_shields' => 3,

    // Haustiere
    'pet_growth_per_food' => 10,
    'pet_growth_mount' => 50,

    // Fokus & Alltagshilfen (Konzept §11)
    'recovery' => ['needed' => 2, 'days' => 4],   // Erholungs-Quest: 2 der nächsten 4 Tage
    'low_energy_bonus_gold' => 2,                // „Trotzdem geschafft“ am Energiespar-Tag
    'surprise' => [
        'base_chance' => 0.04,
        'pity_step' => 0.02,                     // steigt mit jeder Erledigung ohne Überraschung
        'max_chance' => 0.35,
        'daily_limit' => 3,
        'gold' => [3, 12],
    ],
    'focus' => ['shared_bonus_gold' => 3, 'max_minutes' => 120],
    'ai' => ['monthly_budget_usd' => 1.0, 'requests_per_hour' => 10],

    // Crafting & Housing (Konzept §2.5, §2.6)
    'crafting' => [
        'max_jobs' => 3,                         // gleichzeitige Herstellungsaufträge je Person
        'xp_per_level' => 40,                    // nötige Handwerks-XP = Stufe × Wert
        'max_level' => 20,
        'good' => ['base' => 0.10, 'step' => 0.03, 'max' => 0.50],
        'master' => ['base' => 0.0, 'step' => 0.01, 'max' => 0.15],
    ],
    'housing' => [
        'room_size' => ['hut' => [10, 6], 'timber' => [12, 7], 'manor' => [14, 8], 'castle' => [16, 9]],
        'bedroom_size' => [8, 5],
        'max_items_per_room' => 60,
        'walls' => ['stone' => 'Stein', 'wood' => 'Holz', 'stars' => 'Sternentapete', 'stripes' => 'Streifentapete', 'pastel' => 'Pastell', 'castle' => 'Burgmauer'],
        'floors' => ['planks' => 'Dielen', 'stone' => 'Steinboden', 'carpet' => 'Teppich', 'grass' => 'Wiese', 'tiles' => 'Fliesen'],
        'max_active_projects' => 2,
        'garden_slots' => 4,
        'stable_feed_bonus' => 2,                // zusätzliches Wachstum je Futter, wenn es einen Stall gibt
        'bed_heal' => 3,                         // nächtliche HP-Regeneration mit Bett im eigenen Zimmer
        'inventory_slots' => 40,                 // Sorten im Inventar
        'storage_room_slots' => 20,              // zusätzlich mit Lagerraum
    ],

    // Dungeon-Quests (Konzept §2.7)
    'dungeon' => [
        'torches' => ['trivial' => 1, 'easy' => 1, 'medium' => 2, 'hard' => 3, 'all_dailies' => 3],
        'torch_daily_max' => 12,                 // höchstens so viele Fackeln pro Tag verdienen
        'torch_store_max' => 20,                 // Vorrat
        'cost' => ['move' => 1, 'interact' => 1, 'fight' => 1],
        'knockout_torch_loss' => 2,
        'hp' => 30,                              // Dungeon-HP (getrennt von den Spiel-HP), + 2 je Ausdauer
        'skill_mana' => 5,
        'boss_reward' => ['gold' => 20, 'xp' => 30],
        'solo_reward' => ['gold' => 5, 'xp' => 10],
    ],

    'categories' => [
        'household' => 'Haushalt',
        'school' => 'Schule & Lernen',
        'sport' => 'Sport & Draußen',
        'nature' => 'Tiere & Natur',
        'creative' => 'Kreativ',
        'others' => 'Für andere',
    ],

    /*
     * Darstellungs-Themes (Phase 8). „ages“: für welche Altersgruppen das Theme angeboten wird.
     * Teens wählen ihr Theme selbst; Eltern stellen es nur für Kinder unter 13 ein.
     */
    'themes' => [
        'classic' => ['name' => 'Heldenhaus', 'dark' => false, 'ages' => ['child', 'teen', 'adult']],
        'candy' => ['name' => 'Zuckerwatte', 'dark' => false, 'ages' => ['child', 'teen', 'adult']],
        'forest' => ['name' => 'Zauberwald', 'dark' => false, 'ages' => ['child', 'teen', 'adult']],
        'ocean' => ['name' => 'Meeresgrund', 'dark' => false, 'ages' => ['child', 'teen', 'adult']],
        'midnight' => ['name' => 'Mitternacht', 'dark' => true, 'ages' => ['teen', 'adult']],
        'neon' => ['name' => 'Neon-Arcade', 'dark' => true, 'ages' => ['teen', 'adult']],
        'minimal' => ['name' => 'Schlicht', 'dark' => false, 'ages' => ['teen', 'adult']],
        'retro' => ['name' => 'Retro-Pixel', 'dark' => true, 'ages' => ['teen', 'adult']],
        'graphite' => ['name' => 'Graphit', 'dark' => true, 'ages' => ['teen', 'adult']],
    ],
    'default_theme' => ['child' => 'classic', 'teen' => 'midnight', 'adult' => 'classic'],

    // Events (§12) und Zufallsereignisse (§12.5)
    'events' => [
        'plan_days' => 60,           // Kalender-Events so weit im Voraus planen
        'upcoming_days' => 7,        // „Demnächst“-Vorschau
        'drop_chance' => 0.3,        // Chance auf einen Event-Fund je Aufgabe
        'drop_daily_limit' => 3,
        'comeback_pause_days' => 5,  // „Willkommen zurück“ nach so vielen Tagen ohne Aktivität
        'comeback_inn_days' => 3,    // … oder nach so vielen Tagen im Gasthaus
        'gift_points_daily' => 3,    // Laternenfest: Geschenke mit Event-Punkten pro Tag
        'openholidays_url' => env('OPENHOLIDAYS_URL', 'https://openholidaysapi.org'),
        'open_meteo_url' => env('OPEN_METEO_URL', 'https://api.open-meteo.com/v1/forecast'),
    ],

    'fun_events' => [
        'enabled' => env('FUN_EVENTS', true),
        'per_user_daily' => 2,
        'per_family_daily' => 4,
        'reward_daily_limit' => 3,   // so oft am Tag gibt es eine winzige Belohnung
        'echo_minutes' => 10,
    ],

    // Freischaltung von Funktionen nach Level (§13)
    'features' => [
        'enforce' => env('FEATURE_UNLOCKS', true),
    ],
];
