-- Предметная схема SQLite; применяется только одноимённой Laravel migration.
PRAGMA foreign_keys = ON;

CREATE TABLE wiki_versions (
    id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, patch TEXT NOT NULL,
    valid_from TEXT NOT NULL, archived INTEGER NOT NULL DEFAULT 0 CHECK (archived IN (0,1))
);
CREATE TABLE wiki_traders (
    id INTEGER PRIMARY KEY, external_id TEXT NOT NULL UNIQUE, slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL, image_url TEXT, active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1))
);
CREATE TABLE wiki_locations (
    id INTEGER PRIMARY KEY, external_id TEXT UNIQUE, slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1))
);
CREATE TABLE wiki_maps (
    id INTEGER PRIMARY KEY, location_id INTEGER NOT NULL REFERENCES wiki_locations(id),
    version_id INTEGER NOT NULL REFERENCES wiki_versions(id), slug TEXT NOT NULL, image_url TEXT NOT NULL,
    width INTEGER NOT NULL CHECK (width > 0), height INTEGER NOT NULL CHECK (height > 0),
    UNIQUE (location_id,version_id,slug), UNIQUE (id,location_id)
);
CREATE TABLE wiki_map_layers (
    id INTEGER PRIMARY KEY, map_id INTEGER NOT NULL REFERENCES wiki_maps(id) ON DELETE CASCADE,
    external_id TEXT NOT NULL, name TEXT NOT NULL, image_url TEXT, order_index INTEGER NOT NULL DEFAULT 0,
    UNIQUE (map_id,external_id), UNIQUE (id,map_id)
);
CREATE TABLE wiki_documents (
    id INTEGER PRIMARY KEY, external_id TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
    category TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', wiki_url TEXT
);
CREATE TABLE wiki_items (
    id INTEGER PRIMARY KEY, external_id TEXT NOT NULL UNIQUE, name TEXT NOT NULL, search_text TEXT NOT NULL,
    category TEXT NOT NULL, icon_url TEXT, wiki_url TEXT, weight REAL CHECK (weight >= 0),
    grid_width INTEGER CHECK (grid_width > 0), grid_height INTEGER CHECK (grid_height > 0),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1))
);
CREATE TABLE wiki_keys (
    item_id INTEGER PRIMARY KEY REFERENCES wiki_items(id), description TEXT NOT NULL DEFAULT '',
    max_uses INTEGER CHECK (max_uses > 0)
);
CREATE TABLE wiki_point_categories (code TEXT PRIMARY KEY, name TEXT NOT NULL);
CREATE TABLE wiki_map_points (
    id INTEGER PRIMARY KEY, external_id TEXT NOT NULL, mode TEXT NOT NULL DEFAULT 'all' CHECK (mode IN ('all','pve','regular')),
    location_id INTEGER NOT NULL REFERENCES wiki_locations(id),
    title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', search_text TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)), UNIQUE (id,location_id), UNIQUE (external_id,mode)
);
CREATE TABLE wiki_point_category_links (
    point_id INTEGER NOT NULL REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    category TEXT NOT NULL REFERENCES wiki_point_categories(code), PRIMARY KEY (point_id,category)
);
CREATE TABLE wiki_point_positions (
    id INTEGER PRIMARY KEY, point_id INTEGER NOT NULL, location_id INTEGER NOT NULL,
    map_id INTEGER NOT NULL, layer_id INTEGER, order_index INTEGER NOT NULL DEFAULT 0,
    x_percent REAL NOT NULL CHECK (x_percent BETWEEN 0 AND 100),
    y_percent REAL NOT NULL CHECK (y_percent BETWEEN 0 AND 100), world_x REAL, world_y REAL, world_z REAL,
    FOREIGN KEY (point_id,location_id) REFERENCES wiki_map_points(id,location_id) ON DELETE CASCADE,
    FOREIGN KEY (map_id,location_id) REFERENCES wiki_maps(id,location_id),
    FOREIGN KEY (layer_id,map_id) REFERENCES wiki_map_layers(id,map_id),
    UNIQUE (point_id,map_id,order_index)
);
CREATE TABLE wiki_point_documents (
    point_id INTEGER NOT NULL REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    document_id INTEGER NOT NULL REFERENCES wiki_documents(id), PRIMARY KEY (point_id,document_id)
);
CREATE TABLE wiki_item_spawns (
    point_id INTEGER NOT NULL REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    item_id INTEGER NOT NULL REFERENCES wiki_items(id), amount REAL NOT NULL DEFAULT 1 CHECK (amount > 0),
    spawn_chance REAL CHECK (spawn_chance BETWEEN 0 AND 1), notes TEXT NOT NULL DEFAULT '', PRIMARY KEY (point_id,item_id)
);
CREATE TABLE wiki_key_usages (
    point_id INTEGER NOT NULL REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    key_item_id INTEGER NOT NULL REFERENCES wiki_keys(item_id), usage_type TEXT NOT NULL CHECK (usage_type IN ('unlock','optional','bypass')),
    notes TEXT NOT NULL DEFAULT '', PRIMARY KEY (point_id,key_item_id,usage_type)
);
CREATE TABLE wiki_extractions (
    point_id INTEGER PRIMARY KEY REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    type TEXT NOT NULL CHECK (type IN ('pmc','scav','shared','coop','transit','secret')),
    requirements TEXT NOT NULL DEFAULT '', cost_rub INTEGER CHECK (cost_rub >= 0)
);
CREATE TABLE wiki_quests (
    id INTEGER PRIMARY KEY, external_id TEXT NOT NULL, version_id INTEGER NOT NULL REFERENCES wiki_versions(id),
    mode TEXT NOT NULL CHECK (mode IN ('pve','regular')), trader_id INTEGER REFERENCES wiki_traders(id),
    title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', wiki_url TEXT,
    level_req INTEGER NOT NULL DEFAULT 0 CHECK (level_req >= 0),
    is_story INTEGER NOT NULL DEFAULT 0 CHECK (is_story IN (0,1)), kappa_required INTEGER NOT NULL DEFAULT 0 CHECK (kappa_required IN (0,1)),
    published INTEGER NOT NULL DEFAULT 0 CHECK (published IN (0,1)), UNIQUE (external_id,mode,version_id)
);
CREATE TABLE wiki_objective_types (code TEXT PRIMARY KEY, name TEXT NOT NULL);
CREATE TABLE wiki_objectives (
    id INTEGER PRIMARY KEY, external_id TEXT NOT NULL, quest_id INTEGER NOT NULL REFERENCES wiki_quests(id) ON DELETE CASCADE,
    type TEXT NOT NULL REFERENCES wiki_objective_types(code), description TEXT NOT NULL, order_index INTEGER NOT NULL CHECK (order_index >= 0),
    optional INTEGER NOT NULL DEFAULT 0 CHECK (optional IN (0,1)), UNIQUE (quest_id,external_id), UNIQUE (id,quest_id)
);
CREATE TABLE wiki_objective_groups (
    id INTEGER PRIMARY KEY, objective_id INTEGER NOT NULL REFERENCES wiki_objectives(id) ON DELETE CASCADE,
    parent_id INTEGER, operator TEXT NOT NULL DEFAULT 'and' CHECK (operator IN ('and','or')),
    order_index INTEGER NOT NULL DEFAULT 0, UNIQUE (id,objective_id),
    FOREIGN KEY (parent_id,objective_id) REFERENCES wiki_objective_groups(id,objective_id), CHECK (parent_id IS NULL OR parent_id <> id)
);
CREATE TABLE wiki_objective_items (
    group_id INTEGER NOT NULL REFERENCES wiki_objective_groups(id) ON DELETE CASCADE,
    item_id INTEGER NOT NULL REFERENCES wiki_items(id), role TEXT NOT NULL CHECK (role IN ('obtain','handover','plant','mark','weapon','mod','wear','not_wear')),
    amount REAL NOT NULL DEFAULT 1 CHECK (amount > 0), found_in_raid INTEGER NOT NULL DEFAULT 0 CHECK (found_in_raid IN (0,1)),
    PRIMARY KEY (group_id,item_id,role)
);
CREATE TABLE wiki_objective_points (
    objective_id INTEGER NOT NULL REFERENCES wiki_objectives(id) ON DELETE CASCADE,
    point_id INTEGER NOT NULL REFERENCES wiki_map_points(id), PRIMARY KEY (objective_id,point_id)
);
CREATE TABLE wiki_objective_locations (
    objective_id INTEGER NOT NULL REFERENCES wiki_objectives(id) ON DELETE CASCADE,
    location_id INTEGER NOT NULL REFERENCES wiki_locations(id), PRIMARY KEY (objective_id,location_id)
);
CREATE TABLE wiki_objective_keys (
    objective_id INTEGER NOT NULL REFERENCES wiki_objectives(id) ON DELETE CASCADE,
    key_item_id INTEGER NOT NULL REFERENCES wiki_keys(item_id), optional INTEGER NOT NULL DEFAULT 0 CHECK (optional IN (0,1)),
    notes TEXT NOT NULL DEFAULT '', PRIMARY KEY (objective_id,key_item_id)
);
CREATE TABLE wiki_prerequisite_groups (
    id INTEGER PRIMARY KEY, quest_id INTEGER NOT NULL REFERENCES wiki_quests(id) ON DELETE CASCADE,
    parent_id INTEGER, operator TEXT NOT NULL DEFAULT 'and' CHECK (operator IN ('and','or')), UNIQUE (id,quest_id),
    FOREIGN KEY (parent_id,quest_id) REFERENCES wiki_prerequisite_groups(id,quest_id), CHECK (parent_id IS NULL OR parent_id <> id)
);
CREATE TABLE wiki_quest_prerequisites (
    group_id INTEGER NOT NULL REFERENCES wiki_prerequisite_groups(id) ON DELETE CASCADE,
    prerequisite_quest_id INTEGER NOT NULL REFERENCES wiki_quests(id),
    status TEXT NOT NULL DEFAULT 'complete' CHECK (status IN ('complete','active','failed','available')),
    hard_requirement INTEGER NOT NULL DEFAULT 1 CHECK (hard_requirement IN (0,1)), PRIMARY KEY (group_id,prerequisite_quest_id,status)
);
CREATE TABLE wiki_quest_trader_requirements (
    quest_id INTEGER NOT NULL REFERENCES wiki_quests(id) ON DELETE CASCADE,
    trader_id INTEGER NOT NULL REFERENCES wiki_traders(id), loyalty_level INTEGER NOT NULL CHECK (loyalty_level >= 0),
    standing REAL, PRIMARY KEY (quest_id,trader_id)
);
CREATE TABLE wiki_skills (id INTEGER PRIMARY KEY, external_id TEXT NOT NULL UNIQUE, name TEXT NOT NULL);
CREATE TABLE wiki_stations (id INTEGER PRIMARY KEY, external_id TEXT NOT NULL UNIQUE, name TEXT NOT NULL);
CREATE TABLE wiki_achievements (id INTEGER PRIMARY KEY, external_id TEXT NOT NULL UNIQUE, name TEXT NOT NULL);
CREATE TABLE wiki_crafts (
    id INTEGER PRIMARY KEY, external_id TEXT NOT NULL UNIQUE, station_id INTEGER NOT NULL REFERENCES wiki_stations(id),
    item_id INTEGER NOT NULL REFERENCES wiki_items(id), level INTEGER NOT NULL CHECK (level >= 0),
    amount REAL NOT NULL CHECK (amount > 0), duration_seconds INTEGER NOT NULL CHECK (duration_seconds >= 0)
);
CREATE TABLE wiki_craft_ingredients (
    craft_id INTEGER NOT NULL REFERENCES wiki_crafts(id) ON DELETE CASCADE,
    item_id INTEGER NOT NULL REFERENCES wiki_items(id), amount REAL NOT NULL CHECK (amount > 0),
    consumed INTEGER NOT NULL DEFAULT 1 CHECK (consumed IN (0,1)), PRIMARY KEY (craft_id,item_id)
);
CREATE TABLE wiki_offers (
    id INTEGER PRIMARY KEY, external_id TEXT NOT NULL UNIQUE, trader_id INTEGER NOT NULL REFERENCES wiki_traders(id),
    item_id INTEGER NOT NULL REFERENCES wiki_items(id), level INTEGER NOT NULL CHECK (level >= 0)
);
CREATE TABLE wiki_reward_sets (
    id INTEGER PRIMARY KEY, quest_id INTEGER NOT NULL REFERENCES wiki_quests(id) ON DELETE CASCADE,
    timing TEXT NOT NULL CHECK (timing IN ('start','finish')), experience INTEGER NOT NULL DEFAULT 0 CHECK (experience >= 0), UNIQUE (quest_id,timing)
);
CREATE TABLE wiki_reward_items (
    id INTEGER PRIMARY KEY, reward_set_id INTEGER NOT NULL REFERENCES wiki_reward_sets(id) ON DELETE CASCADE,
    item_id INTEGER NOT NULL REFERENCES wiki_items(id), amount REAL NOT NULL CHECK (amount > 0),
    found_in_raid INTEGER NOT NULL DEFAULT 0 CHECK (found_in_raid IN (0,1)), order_index INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE wiki_reward_standing (
    reward_set_id INTEGER NOT NULL REFERENCES wiki_reward_sets(id) ON DELETE CASCADE,
    trader_id INTEGER NOT NULL REFERENCES wiki_traders(id), standing REAL NOT NULL, PRIMARY KEY (reward_set_id,trader_id)
);
CREATE TABLE wiki_reward_skills (
    reward_set_id INTEGER NOT NULL REFERENCES wiki_reward_sets(id) ON DELETE CASCADE,
    skill_id INTEGER NOT NULL REFERENCES wiki_skills(id), level REAL NOT NULL CHECK (level >= 0), PRIMARY KEY (reward_set_id,skill_id)
);
CREATE TABLE wiki_reward_unlocks (
    id INTEGER PRIMARY KEY, reward_set_id INTEGER NOT NULL REFERENCES wiki_reward_sets(id) ON DELETE CASCADE,
    trader_id INTEGER REFERENCES wiki_traders(id), craft_id INTEGER REFERENCES wiki_crafts(id), offer_id INTEGER REFERENCES wiki_offers(id),
    location_id INTEGER REFERENCES wiki_locations(id), achievement_id INTEGER REFERENCES wiki_achievements(id),
    CHECK ((trader_id IS NOT NULL)+(craft_id IS NOT NULL)+(offer_id IS NOT NULL)+(location_id IS NOT NULL)+(achievement_id IS NOT NULL)=1)
);
CREATE TABLE wiki_quest_skill_requirements (
    quest_id INTEGER NOT NULL REFERENCES wiki_quests(id) ON DELETE CASCADE,
    skill_id INTEGER NOT NULL REFERENCES wiki_skills(id), level REAL NOT NULL CHECK (level >= 0), PRIMARY KEY (quest_id,skill_id)
);
CREATE TABLE wiki_quest_station_requirements (
    quest_id INTEGER NOT NULL REFERENCES wiki_quests(id) ON DELETE CASCADE,
    station_id INTEGER NOT NULL REFERENCES wiki_stations(id), level INTEGER NOT NULL CHECK (level >= 0), PRIMARY KEY (quest_id,station_id)
);
CREATE TABLE wiki_media (
    id INTEGER PRIMARY KEY, url TEXT NOT NULL, caption TEXT NOT NULL DEFAULT '', source_url TEXT, author TEXT
);
CREATE TABLE wiki_media_links (
    id INTEGER PRIMARY KEY, media_id INTEGER NOT NULL REFERENCES wiki_media(id), order_index INTEGER NOT NULL DEFAULT 0,
    point_id INTEGER REFERENCES wiki_map_points(id) ON DELETE CASCADE, quest_id INTEGER REFERENCES wiki_quests(id) ON DELETE CASCADE,
    objective_id INTEGER REFERENCES wiki_objectives(id) ON DELETE CASCADE,
    CHECK ((point_id IS NOT NULL)+(quest_id IS NOT NULL)+(objective_id IS NOT NULL)=1)
);
CREATE TABLE wiki_item_translations (
    item_id INTEGER NOT NULL REFERENCES wiki_items(id) ON DELETE CASCADE,
    locale TEXT NOT NULL CHECK (locale IN ('ru','en','ja')), name TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', search_text TEXT NOT NULL,
    PRIMARY KEY (item_id,locale)
);
CREATE TABLE wiki_point_translations (
    point_id INTEGER NOT NULL REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    locale TEXT NOT NULL CHECK (locale IN ('ru','en','ja')), title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', search_text TEXT NOT NULL,
    PRIMARY KEY (point_id,locale)
);
CREATE TABLE wiki_quest_translations (
    quest_id INTEGER NOT NULL REFERENCES wiki_quests(id) ON DELETE CASCADE,
    locale TEXT NOT NULL CHECK (locale IN ('ru','en','ja')), title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', search_text TEXT NOT NULL,
    PRIMARY KEY (quest_id,locale)
);
CREATE INDEX wiki_points_location ON wiki_map_points(location_id,active);
CREATE INDEX wiki_point_categories_filter ON wiki_point_category_links(category,point_id);
CREATE INDEX wiki_positions_layer ON wiki_point_positions(map_id,layer_id,point_id);
CREATE INDEX wiki_documents_points ON wiki_point_documents(document_id,point_id);
CREATE INDEX wiki_item_spawns_item ON wiki_item_spawns(item_id,point_id);
CREATE INDEX wiki_key_usages_key ON wiki_key_usages(key_item_id,point_id);
CREATE INDEX wiki_objectives_quest_order ON wiki_objectives(quest_id,order_index);
CREATE INDEX wiki_objective_items_item ON wiki_objective_items(item_id,group_id);
CREATE INDEX wiki_objective_points_point ON wiki_objective_points(point_id,objective_id);
CREATE INDEX wiki_objective_locations_location ON wiki_objective_locations(location_id,objective_id);
CREATE INDEX wiki_objective_keys_key ON wiki_objective_keys(key_item_id,objective_id);
CREATE INDEX wiki_prerequisites_reverse ON wiki_quest_prerequisites(prerequisite_quest_id,group_id);
CREATE INDEX wiki_media_point ON wiki_media_links(point_id,order_index);
CREATE INDEX wiki_media_quest ON wiki_media_links(quest_id,order_index);
CREATE INDEX wiki_media_objective ON wiki_media_links(objective_id,order_index);
