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

    'categories' => [
        'household' => 'Haushalt',
        'school' => 'Schule & Lernen',
        'sport' => 'Sport & Draußen',
        'nature' => 'Tiere & Natur',
        'creative' => 'Kreativ',
        'others' => 'Für andere',
    ],
];
