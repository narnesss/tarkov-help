-- Предметная схема MariaDB 10.11+/MySQL-протокол; JSON контента остаётся в файлах.

CREATE TABLE wiki_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(191) NOT NULL UNIQUE,
    patch LONGTEXT NOT NULL,
    valid_from LONGTEXT NOT NULL,
    archived BIGINT NOT NULL DEFAULT 0 CHECK (archived IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_traders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL UNIQUE,
    slug VARCHAR(191) NOT NULL UNIQUE,
    name LONGTEXT NOT NULL,
    image_url LONGTEXT,
    active BIGINT NOT NULL DEFAULT 1 CHECK (active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) UNIQUE,
    slug VARCHAR(191) NOT NULL UNIQUE,
    name LONGTEXT NOT NULL,
    description LONGTEXT NOT NULL DEFAULT '',
    active BIGINT NOT NULL DEFAULT 1 CHECK (active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_maps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(191) NOT NULL,
    image_url LONGTEXT NOT NULL,
    width BIGINT NOT NULL CHECK (width > 0),
    height BIGINT NOT NULL CHECK (height > 0),
    UNIQUE (location_id,version_id,slug),
    UNIQUE (id,location_id),
    FOREIGN KEY (location_id) REFERENCES wiki_locations(id),
    FOREIGN KEY (version_id) REFERENCES wiki_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_map_layers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    map_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(191) NOT NULL,
    name LONGTEXT NOT NULL,
    image_url LONGTEXT,
    order_index BIGINT NOT NULL DEFAULT 0,
    UNIQUE (map_id,external_id),
    UNIQUE (id,map_id),
    FOREIGN KEY (map_id) REFERENCES wiki_maps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL UNIQUE,
    name LONGTEXT NOT NULL,
    category VARCHAR(191) NOT NULL,
    description LONGTEXT NOT NULL DEFAULT '',
    wiki_url LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL UNIQUE,
    name LONGTEXT NOT NULL,
    search_text LONGTEXT NOT NULL,
    category VARCHAR(191) NOT NULL,
    icon_url LONGTEXT,
    wiki_url LONGTEXT,
    weight DOUBLE CHECK (weight >= 0),
    grid_width BIGINT CHECK (grid_width > 0),
    grid_height BIGINT CHECK (grid_height > 0),
    active BIGINT NOT NULL DEFAULT 1 CHECK (active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_keys (
    item_id BIGINT UNSIGNED PRIMARY KEY,
    description LONGTEXT NOT NULL DEFAULT '',
    max_uses BIGINT CHECK (max_uses > 0),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (item_id) REFERENCES wiki_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_point_categories (
    code VARCHAR(191) PRIMARY KEY,
    name LONGTEXT NOT NULL,
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_map_points (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL,
    mode VARCHAR(191) NOT NULL DEFAULT 'all' CHECK (mode IN ('all','pve','regular')),
    location_id BIGINT UNSIGNED NOT NULL,
    title LONGTEXT NOT NULL,
    description LONGTEXT NOT NULL DEFAULT '',
    search_text LONGTEXT NOT NULL,
    active BIGINT NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    UNIQUE (id,location_id),
    UNIQUE (external_id,mode),
    FOREIGN KEY (location_id) REFERENCES wiki_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_point_category_links (
    point_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(191) NOT NULL,
    PRIMARY KEY (point_id,category),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (point_id) REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    FOREIGN KEY (category) REFERENCES wiki_point_categories(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_point_positions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    point_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    map_id BIGINT UNSIGNED NOT NULL,
    layer_id BIGINT UNSIGNED,
    order_index BIGINT NOT NULL DEFAULT 0,
    x_percent DOUBLE NOT NULL CHECK (x_percent BETWEEN 0 AND 100),
    y_percent DOUBLE NOT NULL CHECK (y_percent BETWEEN 0 AND 100),
    world_x DOUBLE,
    world_y DOUBLE,
    world_z DOUBLE,
    FOREIGN KEY (point_id,location_id) REFERENCES wiki_map_points(id,location_id) ON DELETE CASCADE,
    FOREIGN KEY (map_id,location_id) REFERENCES wiki_maps(id,location_id),
    FOREIGN KEY (layer_id,map_id) REFERENCES wiki_map_layers(id,map_id),
    UNIQUE (point_id,map_id,order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_point_documents (
    point_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (point_id,document_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (point_id) REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES wiki_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_item_spawns (
    point_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    amount DOUBLE NOT NULL DEFAULT 1 CHECK (amount > 0),
    spawn_chance DOUBLE CHECK (spawn_chance BETWEEN 0 AND 1),
    notes LONGTEXT NOT NULL DEFAULT '',
    PRIMARY KEY (point_id,item_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (point_id) REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES wiki_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_key_usages (
    point_id BIGINT UNSIGNED NOT NULL,
    key_item_id BIGINT UNSIGNED NOT NULL,
    usage_type VARCHAR(191) NOT NULL CHECK (usage_type IN ('unlock','optional','bypass')),
    notes LONGTEXT NOT NULL DEFAULT '',
    PRIMARY KEY (point_id,key_item_id,usage_type),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (point_id) REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    FOREIGN KEY (key_item_id) REFERENCES wiki_keys(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_extractions (
    point_id BIGINT UNSIGNED PRIMARY KEY,
    type VARCHAR(191) NOT NULL CHECK (type IN ('pmc','scav','shared','coop','transit','secret')),
    requirements LONGTEXT NOT NULL DEFAULT '',
    cost_rub BIGINT CHECK (cost_rub >= 0),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (point_id) REFERENCES wiki_map_points(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_quests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    mode VARCHAR(191) NOT NULL CHECK (mode IN ('pve','regular')),
    trader_id BIGINT UNSIGNED,
    title LONGTEXT NOT NULL,
    description LONGTEXT NOT NULL DEFAULT '',
    wiki_url LONGTEXT,
    level_req BIGINT NOT NULL DEFAULT 0 CHECK (level_req >= 0),
    is_story BIGINT NOT NULL DEFAULT 0 CHECK (is_story IN (0,1)),
    kappa_required BIGINT NOT NULL DEFAULT 0 CHECK (kappa_required IN (0,1)),
    published BIGINT NOT NULL DEFAULT 0 CHECK (published IN (0,1)),
    UNIQUE (external_id,mode,version_id),
    FOREIGN KEY (version_id) REFERENCES wiki_versions(id),
    FOREIGN KEY (trader_id) REFERENCES wiki_traders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_objective_types (
    code VARCHAR(191) PRIMARY KEY,
    name LONGTEXT NOT NULL,
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_objectives (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL,
    quest_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(191) NOT NULL,
    description LONGTEXT NOT NULL,
    order_index BIGINT NOT NULL CHECK (order_index >= 0),
    optional BIGINT NOT NULL DEFAULT 0 CHECK (optional IN (0,1)),
    UNIQUE (quest_id,external_id),
    UNIQUE (id,quest_id),
    FOREIGN KEY (quest_id) REFERENCES wiki_quests(id) ON DELETE CASCADE,
    FOREIGN KEY (type) REFERENCES wiki_objective_types(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_objective_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    objective_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED,
    operator VARCHAR(191) NOT NULL DEFAULT 'and' CHECK (operator IN ('and','or')),
    order_index BIGINT NOT NULL DEFAULT 0,
    UNIQUE (id,objective_id),
    FOREIGN KEY (parent_id,objective_id) REFERENCES wiki_objective_groups(id,objective_id),
    FOREIGN KEY (objective_id) REFERENCES wiki_objectives(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_objective_items (
    group_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(191) NOT NULL CHECK (role IN ('obtain','handover','plant','mark','weapon','mod','wear','not_wear')),
    amount DOUBLE NOT NULL DEFAULT 1 CHECK (amount > 0),
    found_in_raid BIGINT NOT NULL DEFAULT 0 CHECK (found_in_raid IN (0,1)),
    PRIMARY KEY (group_id,item_id,role),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (group_id) REFERENCES wiki_objective_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES wiki_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_objective_points (
    objective_id BIGINT UNSIGNED NOT NULL,
    point_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (objective_id,point_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (objective_id) REFERENCES wiki_objectives(id) ON DELETE CASCADE,
    FOREIGN KEY (point_id) REFERENCES wiki_map_points(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_objective_locations (
    objective_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (objective_id,location_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (objective_id) REFERENCES wiki_objectives(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES wiki_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_objective_keys (
    objective_id BIGINT UNSIGNED NOT NULL,
    key_item_id BIGINT UNSIGNED NOT NULL,
    optional BIGINT NOT NULL DEFAULT 0 CHECK (optional IN (0,1)),
    notes LONGTEXT NOT NULL DEFAULT '',
    PRIMARY KEY (objective_id,key_item_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (objective_id) REFERENCES wiki_objectives(id) ON DELETE CASCADE,
    FOREIGN KEY (key_item_id) REFERENCES wiki_keys(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_prerequisite_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quest_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED,
    operator VARCHAR(191) NOT NULL DEFAULT 'and' CHECK (operator IN ('and','or')),
    UNIQUE (id,quest_id),
    FOREIGN KEY (parent_id,quest_id) REFERENCES wiki_prerequisite_groups(id,quest_id),
    FOREIGN KEY (quest_id) REFERENCES wiki_quests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_quest_prerequisites (
    group_id BIGINT UNSIGNED NOT NULL,
    prerequisite_quest_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(191) NOT NULL DEFAULT 'complete' CHECK (status IN ('complete','active','failed','available')),
    hard_requirement BIGINT NOT NULL DEFAULT 1 CHECK (hard_requirement IN (0,1)),
    PRIMARY KEY (group_id,prerequisite_quest_id,status),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (group_id) REFERENCES wiki_prerequisite_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (prerequisite_quest_id) REFERENCES wiki_quests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_quest_trader_requirements (
    quest_id BIGINT UNSIGNED NOT NULL,
    trader_id BIGINT UNSIGNED NOT NULL,
    loyalty_level BIGINT NOT NULL CHECK (loyalty_level >= 0),
    standing DOUBLE,
    PRIMARY KEY (quest_id,trader_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (quest_id) REFERENCES wiki_quests(id) ON DELETE CASCADE,
    FOREIGN KEY (trader_id) REFERENCES wiki_traders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_skills (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL UNIQUE,
    name LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_stations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL UNIQUE,
    name LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_achievements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL UNIQUE,
    name LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_crafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL UNIQUE,
    station_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    level BIGINT NOT NULL CHECK (level >= 0),
    amount DOUBLE NOT NULL CHECK (amount > 0),
    duration_seconds BIGINT NOT NULL CHECK (duration_seconds >= 0),
    FOREIGN KEY (station_id) REFERENCES wiki_stations(id),
    FOREIGN KEY (item_id) REFERENCES wiki_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_craft_ingredients (
    craft_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    amount DOUBLE NOT NULL CHECK (amount > 0),
    consumed BIGINT NOT NULL DEFAULT 1 CHECK (consumed IN (0,1)),
    PRIMARY KEY (craft_id,item_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (craft_id) REFERENCES wiki_crafts(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES wiki_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL UNIQUE,
    trader_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    level BIGINT NOT NULL CHECK (level >= 0),
    FOREIGN KEY (trader_id) REFERENCES wiki_traders(id),
    FOREIGN KEY (item_id) REFERENCES wiki_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_reward_sets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quest_id BIGINT UNSIGNED NOT NULL,
    timing VARCHAR(191) NOT NULL CHECK (timing IN ('start','finish')),
    experience BIGINT NOT NULL DEFAULT 0 CHECK (experience >= 0),
    UNIQUE (quest_id,timing),
    FOREIGN KEY (quest_id) REFERENCES wiki_quests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_reward_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reward_set_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    amount DOUBLE NOT NULL CHECK (amount > 0),
    found_in_raid BIGINT NOT NULL DEFAULT 0 CHECK (found_in_raid IN (0,1)),
    order_index BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (reward_set_id) REFERENCES wiki_reward_sets(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES wiki_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_reward_standing (
    reward_set_id BIGINT UNSIGNED NOT NULL,
    trader_id BIGINT UNSIGNED NOT NULL,
    standing DOUBLE NOT NULL,
    PRIMARY KEY (reward_set_id,trader_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (reward_set_id) REFERENCES wiki_reward_sets(id) ON DELETE CASCADE,
    FOREIGN KEY (trader_id) REFERENCES wiki_traders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_reward_skills (
    reward_set_id BIGINT UNSIGNED NOT NULL,
    skill_id BIGINT UNSIGNED NOT NULL,
    level DOUBLE NOT NULL CHECK (level >= 0),
    PRIMARY KEY (reward_set_id,skill_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (reward_set_id) REFERENCES wiki_reward_sets(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES wiki_skills(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_reward_unlocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reward_set_id BIGINT UNSIGNED NOT NULL,
    trader_id BIGINT UNSIGNED,
    craft_id BIGINT UNSIGNED,
    offer_id BIGINT UNSIGNED,
    location_id BIGINT UNSIGNED,
    achievement_id BIGINT UNSIGNED,
    CHECK ((trader_id IS NOT NULL)+(craft_id IS NOT NULL)+(offer_id IS NOT NULL)+(location_id IS NOT NULL)+(achievement_id IS NOT NULL)=1),
    FOREIGN KEY (reward_set_id) REFERENCES wiki_reward_sets(id) ON DELETE CASCADE,
    FOREIGN KEY (trader_id) REFERENCES wiki_traders(id),
    FOREIGN KEY (craft_id) REFERENCES wiki_crafts(id),
    FOREIGN KEY (offer_id) REFERENCES wiki_offers(id),
    FOREIGN KEY (location_id) REFERENCES wiki_locations(id),
    FOREIGN KEY (achievement_id) REFERENCES wiki_achievements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_quest_skill_requirements (
    quest_id BIGINT UNSIGNED NOT NULL,
    skill_id BIGINT UNSIGNED NOT NULL,
    level DOUBLE NOT NULL CHECK (level >= 0),
    PRIMARY KEY (quest_id,skill_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (quest_id) REFERENCES wiki_quests(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES wiki_skills(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_quest_station_requirements (
    quest_id BIGINT UNSIGNED NOT NULL,
    station_id BIGINT UNSIGNED NOT NULL,
    level BIGINT NOT NULL CHECK (level >= 0),
    PRIMARY KEY (quest_id,station_id),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (quest_id) REFERENCES wiki_quests(id) ON DELETE CASCADE,
    FOREIGN KEY (station_id) REFERENCES wiki_stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    url LONGTEXT NOT NULL,
    caption LONGTEXT NOT NULL DEFAULT '',
    source_url LONGTEXT,
    author LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_media_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    media_id BIGINT UNSIGNED NOT NULL,
    order_index BIGINT NOT NULL DEFAULT 0,
    point_id BIGINT UNSIGNED,
    quest_id BIGINT UNSIGNED,
    objective_id BIGINT UNSIGNED,
    CHECK ((point_id IS NOT NULL)+(quest_id IS NOT NULL)+(objective_id IS NOT NULL)=1),
    FOREIGN KEY (media_id) REFERENCES wiki_media(id),
    FOREIGN KEY (point_id) REFERENCES wiki_map_points(id) ON DELETE CASCADE,
    FOREIGN KEY (quest_id) REFERENCES wiki_quests(id) ON DELETE CASCADE,
    FOREIGN KEY (objective_id) REFERENCES wiki_objectives(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_item_translations (
    item_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(191) NOT NULL CHECK (locale IN ('ru','en','ja')),
    name LONGTEXT NOT NULL,
    description LONGTEXT NOT NULL DEFAULT '',
    search_text LONGTEXT NOT NULL,
    PRIMARY KEY (item_id,locale),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (item_id) REFERENCES wiki_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_point_translations (
    point_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(191) NOT NULL CHECK (locale IN ('ru','en','ja')),
    title LONGTEXT NOT NULL,
    description LONGTEXT NOT NULL DEFAULT '',
    search_text LONGTEXT NOT NULL,
    PRIMARY KEY (point_id,locale),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (point_id) REFERENCES wiki_map_points(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE wiki_quest_translations (
    quest_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(191) NOT NULL CHECK (locale IN ('ru','en','ja')),
    title LONGTEXT NOT NULL,
    description LONGTEXT NOT NULL DEFAULT '',
    search_text LONGTEXT NOT NULL,
    PRIMARY KEY (quest_id,locale),
    rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    FOREIGN KEY (quest_id) REFERENCES wiki_quests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

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
