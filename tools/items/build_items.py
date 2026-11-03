#!/usr/bin/env python3
"""Item-Bilder aus Microsoft Fluent Emoji (3D, MIT) erzeugen – im selben Stil wie die Haustiere.

    git clone --depth 1 --filter=blob:none --sparse https://github.com/microsoft/fluentui-emoji.git
    python3 tools/items/build_items.py <fluentui-emoji-klon>

Schreibt public/images/items/<key>.webp (128 px) und resources/js/lib/item-art.json (Liste der Keys).
Items ohne Zuordnung zeichnet ItemIcon.vue weiter selbst. Eier und Schlüpfpulver setzt das Frontend
aus einem Grundbild zusammen (Ei + Tier, Röhrchen + Farbe), sie brauchen hier keinen eigenen Eintrag.
"""
import json
import subprocess
import sys
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'public/images/items'
MANIFEST = ROOT / 'resources/js/lib/item-art.json'
SIZE = 128

# Grundbilder für ganze Item-Gruppen und neue (im Admin angelegte) Items ohne eigenes Bild
BASE = {
    '_egg': 'Egg', '_hatching_potion': 'Test tube', '_food': 'Fork and knife with plate', '_material': 'Package',
    '_potion': 'Bubbles', '_furniture': 'Couch and lamp', '_equipment': 'Crossed swords', '_head': 'Billed cap',
    '_weapon': 'Crossed swords', '_armor': 'Coat', '_shield': 'Shield', '_accessory': 'Ring',
}

# Quest-Bosse: je Körperform ein 3D-Grundbild, das MonsterArt.vue nach der Boss-Farbe tönt (public/images/monsters)
MONSTERS = {
    'blob': 'Alien monster', 'round': 'Alien', 'golem': 'Moai', 'ghost': 'Ghost', 'serpent': 'Snake', 'octopus': 'Octopus',
    'dragon': 'Dragon', 'troll': 'Troll', 'king': 'Ogre', 'crown': 'Crown', 'dust': 'Cloud', 'heads': 'Dragon face',
}
MONSTER_OUT = ROOT / 'public/images/monsters'
MONSTER_SIZE = 256

ITEMS = {
    # Futter
    'food_apple': 'Red apple', 'food_cake': 'Shortcake', 'food_carrot': 'Carrot', 'food_honey': 'Honey pot', 'food_fish': 'Fish',
    'food_meat': 'Poultry leg', 'food_berries': 'Blueberries', 'food_fresh_egg': 'Egg', 'food_lettuce': 'Leafy green',
    'food_stardust_cookie': 'Cookie', 'food_pancake': 'Pancakes', 'food_candy': 'Candy', 'food_gingerbread': 'Cookie',
    'food_chocolate_egg': 'Chocolate bar', 'food_ice_cream': 'Ice cream', 'food_pumpkin_soup': 'Pot of food',
    'food_apple_pie': 'Pie', 'food_cookies': 'Cookie', 'food_dog_biscuit': 'Bone', 'food_nuts': 'Chestnut',
    'food_cheese': 'Cheese wedge', 'food_seed_mix': 'Peanuts', 'food_hay': 'Sheaf of rice', 'food_banana': 'Banana',
    'food_corn': 'Ear of corn', 'food_bugs': 'Cricket', 'food_bamboo': 'Pine decoration', 'food_eucalyptus': 'Herb',
    'food_nectar': 'Hibiscus', 'food_shrimp': 'Shrimp', 'food_watermelon': 'Watermelon', 'food_acacia': 'Leaf fluttering in wind',
    'food_twigs': 'Wood', 'food_steak': 'Cut of meat', 'food_fern': 'Potted plant', 'food_snow_cone': 'Shaved ice',
    'food_fish_flakes': 'Tropical fish', 'food_pancake_stack': 'Pancakes', 'food_bread': 'Bread', 'food_soup': 'Pot of food',
    'food_pizza': 'Pizza', 'food_sandwich': 'Sandwich', 'food_muesli': 'Bowl with spoon', 'food_pretzel': 'Pretzel',
    'food_strawberries': 'Strawberry', 'food_cocoa': 'Hot beverage', 'food_waffle': 'Waffle', 'food_sushi': 'Sushi',
    # Materialien
    'wood': 'Wood', 'stone': 'Rock', 'ink': 'Fountain pen', 'crystal_shard': 'Gem stone', 'herbs': 'Herb', 'feather': 'Feather',
    'paint': 'Artist palette', 'stardust': 'Sparkles', 'nails': 'Nut and bolt', 'parchment': 'Scroll', 'leather': 'Briefcase',
    'flint': 'Fire', 'wool': 'Yarn', 'seeds': 'Seedling', 'cloth': 'Thread', 'yarn': 'Yarn', 'dragon_scale': 'Dragon face',
    'flower': 'Blossom', 'dungeon_key': 'Old key', 'iron': 'Magnet', 'gold_nugget': 'Coin', 'glass': 'Test tube',
    'clay': 'Amphora', 'silk': 'Ribbon', 'wax': 'Candle', 'shell': 'Spiral shell', 'gears': 'Gear', 'moonstone': 'Crescent moon',
    'sugar': 'Lollipop', 'wheat': 'Sheaf of rice', 'pumpkin': 'Jack-o-lantern', 'spider_silk': 'Spider web', 'bone': 'Bone',
    'candy_corn': 'Candy', 'snowflake': 'Snowflake', 'fir_branch': 'Evergreen tree', 'easter_egg_paint': 'Paintbrush',
    'sand': 'Desert',
    # Tränke und Verbrauchsgüter
    'potion_health': 'Red heart', 'potion_mana': 'Crystal ball', 'boss_trap': 'Mouse trap', 'fireworks': 'Fireworks',
    'amulet_streak': 'Nazar amulet', 'potion_health_big': 'Sparkling heart', 'potion_mana_big': 'Crystal ball',
    'potion_full': 'Glowing star', 'elixir_wisdom': 'Brain', 'elixir_wisdom_big': 'Books', 'elixir_gold': 'Money bag',
    'elixir_courage': 'Flexed biceps', 'elixir_luck': 'Four leaf clover', 'boss_trap_big': 'Mouse trap',
    'confetti_cannon': 'Confetti ball',
    # Ausrüstung
    'head_cap': 'Billed cap', 'weapon_wooden_sword': 'Dagger', 'shield_lid': 'Shield', 'armor_hoodie': 'Coat',
    'accessory_glasses': 'Glasses', 'weapon_flint_axe': 'Axe', 'armor_leather': 'Coat', 'head_feather_hat': 'Womans hat',
    'accessory_wool_scarf': 'Scarf', 'accessory_dragon_amulet': 'Dragon', 'head_colander': 'Cooking', 'armor_apron': 'Womans clothes',
    'weapon_feather_duster': 'Broom', 'shield_baking_tray': 'Shield', 'weapon_mop_spear': 'Broom',
    'accessory_rubber_duck': 'Duck', 'accessory_sneakers': 'Running shoe', 'head_headphones': 'Headphone', 'shield_book': 'Closed book',
    'head_knight_helm': 'Military helmet', 'armor_chainmail': 'T-shirt', 'weapon_iron_sword': 'Crossed swords', 'shield_kite': 'Shield',
    'head_wizard_hat': 'Top hat', 'armor_star_robe': 'Kimono', 'weapon_oak_staff': 'Magic wand', 'accessory_moon_pendant': 'Crescent moon',
    'head_shadow_hood': 'Ninja', 'armor_shadow_cloak': 'Coat', 'weapon_twin_daggers': 'Dagger', 'accessory_lucky_coin': 'Coin',
    'head_flower_crown': 'Bouquet', 'armor_healer_robe': 'Lab coat', 'weapon_healing_wand': 'Magic wand', 'shield_herb_buckler': 'Herb',
    'head_goggles': 'Goggles', 'armor_tool_apron': 'Toolbox', 'weapon_wrench': 'Wrench', 'accessory_gear_watch': 'Watch',
    'head_bard_cap': 'Womans hat', 'armor_minstrel_vest': 'Kimono', 'weapon_lute': 'Guitar', 'accessory_golden_flute': 'Flute',
    'weapon_dragon_blade': 'Crossed swords', 'shield_aegis': 'Shield', 'accessory_star_ring': 'Ring', 'armor_dragon_scale': 'Dragon',
    'head_phoenix_crown': 'Crown', 'weapon_iron_hammer': 'Hammer', 'armor_silk_robe': 'Kimono', 'head_pumpkin': 'Jack-o-lantern',
    'armor_ghost_sheet': 'Ghost', 'accessory_bat_wings': 'Bat', 'head_santa_hat': 'Santa claus', 'accessory_red_scarf': 'Scarf',
    'weapon_candy_cane': 'Lollipop', 'head_bunny_ears': 'Rabbit face', 'accessory_egg_basket': 'Basket', 'head_sun_hat': 'Womans hat',
    'accessory_sunglasses': 'Sunglasses', 'weapon_water_blaster': 'Water pistol', 'head_jester_cap': 'Clown face',
    'accessory_sparkler': 'Sparkler', 'accessory_lantern': 'Red paper lantern', 'head_straw_hat': 'Womans hat',
    'weapon_pitchfork': 'Pick', 'weapon_spring_broom': 'Broom', 'head_birthday_crown': 'Crown', 'armor_costume_witch': 'Person mage',
    'armor_costume_knight': 'Shield', 'head_bandana': 'Billed cap', 'head_helmet_leather': 'Rescue workers helmet',
    'head_pointed_hat': 'Person mage', 'head_feather_cap': 'Feather', 'head_visor_helm': 'Military helmet', 'head_star_circlet': 'Crown',
    'head_earmuffs': 'Headphone', 'weapon_slingshot': 'Boomerang', 'weapon_magic_wand': 'Magic wand', 'weapon_short_bow': 'Bow and arrow',
    'weapon_steel_saber': 'Crossed swords', 'weapon_crystal_staff': 'Magic wand', 'weapon_dragon_hammer': 'Hammer',
    'weapon_paintbrush': 'Paintbrush', 'armor_raincoat': 'Coat', 'armor_leather_vest': 'Coat', 'armor_mage_robe': 'Kimono',
    'armor_scale_mail': 'T-shirt', 'armor_ranger_cloak': 'Coat', 'armor_dragon_plate': 'Dragon', 'shield_wooden': 'Shield',
    'shield_round_iron': 'Shield', 'shield_star': 'Shield', 'shield_atlas': 'World map', 'accessory_compass': 'Compass',
    'accessory_scarf': 'Scarf', 'accessory_backpack': 'Backpack', 'accessory_magnifier': 'Magnifying glass tilted left',
    'accessory_friendship_band': 'Ribbon', 'accessory_medal': 'Sports medal', 'accessory_moon_amulet': 'Crescent moon',
    # Bauprojekte des Familienhauses (Key mit Präfix proj_)
    'proj_room_workshop': 'Hammer and wrench',
    'proj_station_forge_1': 'Hammer and pick',
    'proj_room_garden': 'Sunflower',
    'proj_room_library': 'Books',
    'proj_room_stable': 'Horse',
    'proj_room_storage': 'Package',
    'proj_stage_timber': 'House with garden',
    'proj_station_workbench_2': 'Carpentry saw',
    'proj_station_alchemy_2': 'Alembic',
    'proj_station_sewing_2': 'Sewing needle',
    'proj_station_forge_2': 'Fire',
    'proj_stage_manor': 'Houses',
    'proj_room_trophy': 'Trophy',
    'proj_stage_castle': 'Castle',
    'proj_room_kitchen': 'Cooking',
    'proj_room_music': 'Musical notes',
    'proj_room_observatory': 'Telescope',
    'proj_garden_greenhouse': 'Potted plant',
    'proj_event_harvest_barn': 'Hut',
    'proj_event_snowman': 'Snowman',
    # Möbel (Listen und Handwerk; im Zimmer selbst zeichnet FurnitureArt Pixel-Sprites)
    'furn_bed': 'Bed', 'furn_chair': 'Chair', 'furn_table': 'Fork and knife with plate', 'furn_chest': 'Toolbox', 'furn_bookshelf': 'Books',
    'furn_plant': 'Potted plant', 'furn_teddy': 'Teddy bear', 'furn_candle': 'Candle', 'furn_lamp': 'Diya lamp',
    'furn_painting': 'Framed picture', 'furn_window': 'Window', 'furn_banner': 'Triangular flag', 'furn_trophy': 'Trophy',
    'furn_dragon_statue': 'Dragon', 'furn_rug': 'Yarn', 'furn_sofa': 'Couch and lamp', 'furn_desk': 'Desktop computer',
    'furn_wardrobe': 'Door', 'furn_aquarium': 'Tropical fish', 'furn_fireplace': 'Fire', 'furn_clock': 'Mantelpiece clock',
    'furn_mirror': 'Mirror', 'furn_wall_shelf': 'Card file box', 'furn_beanbag': 'Couch and lamp', 'furn_gaming_corner': 'Video game',
    'furn_easel': 'Artist palette', 'furn_piano': 'Musical keyboard', 'furn_bunk_bed': 'Bed', 'furn_hammock': 'Beach with umbrella',
    'furn_fairy_lights': 'Sparkles', 'furn_star_projector': 'Milky way', 'furn_chandelier': 'Light bulb', 'furn_globe': 'Globe showing europe-africa',
    'furn_cat_tree': 'Cat', 'furn_pet_bed': 'Basket', 'furn_fountain': 'Fountain', 'furn_vase': 'Amphora', 'furn_map': 'World map',
    'furn_armor_stand': 'Shield', 'furn_pumpkin_lantern': 'Jack-o-lantern', 'furn_cobweb': 'Spider web', 'furn_christmas_tree': 'Christmas tree',
    'furn_advent_wreath': 'Candle', 'furn_easter_basket': 'Basket', 'furn_beach_chair': 'Beach with umbrella', 'furn_harvest_basket': 'Basket',
    'furn_paper_lantern': 'Red paper lantern', 'furn_party_garland': 'Party popper', 'furn_carnival_mask': 'Performing arts',
    'furn_spring_flowers': 'Tulip', 'furn_flower_box': 'Sunflower', 'furn_snowman_deco': 'Snowman', 'furn_golden_garland': 'Ribbon',
    'furn_dragon_trophy': 'Trophy', 'furn_housewarming_sticker': 'House with garden', 'furn_anniversary_badge': 'Reminder ribbon',
    'furn_postcard_beach': 'Beach with umbrella', 'furn_postcard_mountains': 'Snow-capped mountain', 'furn_postcard_castle': 'Castle',
    'furn_postcard_forest': 'Deciduous tree', 'furn_postcard_lake': 'Camping', 'furn_postcard_city': 'Cityscape',
    'furn_stove': 'Cooking', 'furn_fridge': 'Ice', 'furn_kitchen_counter': 'Fork and knife with plate', 'furn_fruit_bowl': 'Green apple',
    'furn_spice_rack': 'Salt', 'furn_dining_table': 'Fork and knife with plate', 'furn_cookbook': 'Green book', 'furn_guitar': 'Guitar',
    'furn_drums': 'Drum', 'furn_violin': 'Violin', 'furn_music_stand': 'Musical score', 'furn_record_player': 'Radio',
    'furn_speaker': 'Speaker high volume', 'furn_trumpet': 'Trumpet', 'furn_telescope': 'Telescope', 'furn_star_chart': 'Ringed planet',
    'furn_planet_mobile': 'Ringed planet', 'furn_moon_lamp': 'Full moon', 'furn_rocket_model': 'Rocket', 'furn_comet_poster': 'Comet',
    'furn_bean_lamp': 'Light bulb', 'furn_cushion': 'Couch and lamp', 'furn_toy_box': 'Teddy bear', 'furn_lego_castle': 'Castle',
    'furn_poster_dino': 'Sauropod', 'furn_poster_space': 'Astronaut', 'furn_photo_wall': 'Camera with flash', 'furn_pinboard': 'Pushpin',
    'furn_cactus': 'Cactus', 'furn_bonsai': 'Evergreen tree', 'furn_tent': 'Tent', 'furn_swing_chair': 'Chair', 'furn_disco_ball': 'Mirror ball',
    'furn_paper_crane': 'Bird', 'furn_wind_chime': 'Wind chime', 'furn_treasure_map': 'World map', 'furn_crystal_ball': 'Crystal ball',
    'furn_knight_shield': 'Shield', 'furn_aquarium_big': 'Spouting whale', 'furn_throne': 'Crown',
}


def png(clone: Path, folder: str) -> Path:
    base = clone / 'assets' / folder
    hits = sorted((base / '3D').glob('*.png')) or sorted(base.glob('Default/3D/*.png'))
    if not hits:
        sys.exit(f'Kein 3D-Bild in {folder}')
    return hits[0]


def main(clone: Path) -> None:
    folders = sorted(set(ITEMS.values()) | set(BASE.values()) | set(MONSTERS.values()))
    subprocess.run(['git', '-C', str(clone), 'sparse-checkout', 'set', *[f'assets/{f}' for f in folders]], check=True)
    OUT.mkdir(parents=True, exist_ok=True)

    done = []
    for key, folder in {**BASE, **ITEMS}.items():
        img = Image.open(png(clone, folder)).convert('RGBA')
        img.thumbnail((SIZE, SIZE), Image.LANCZOS)
        img.save(OUT / f'{key}.webp', 'WEBP', quality=86, method=6)
        done.append(key)
    MANIFEST.write_text(json.dumps(sorted(done)) + '\n')
    print(f'{len(done)} Item-Bilder nach {OUT.relative_to(ROOT)}')

    MONSTER_OUT.mkdir(parents=True, exist_ok=True)
    for key, folder in MONSTERS.items():
        img = Image.open(png(clone, folder)).convert('RGBA')
        img.thumbnail((MONSTER_SIZE, MONSTER_SIZE), Image.LANCZOS)
        img.save(MONSTER_OUT / f'{key}.webp', 'WEBP', quality=88, method=6)
    print(f'{len(MONSTERS)} Monster-Bilder nach {MONSTER_OUT.relative_to(ROOT)}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(Path(sys.argv[1]))
