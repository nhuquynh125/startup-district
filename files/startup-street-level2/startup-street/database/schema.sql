-- Startup Street: database schema (LEVEL 2 baseline).
--
-- Applied by `php database/migrate.php`, which first creates the database named
-- by DB_NAME in .env, so this file contains no CREATE DATABASE / USE. To load it
-- by hand (phpMyAdmin, mysql CLI) select the target database first.
--
-- Every statement is CREATE TABLE IF NOT EXISTS, so running it again is safe.
-- It never alters a table that already exists: schema changes made after the
-- first release go into numbered files in database/migrations/.
--
-- Conventions
--   * Money is stored as whole cents in BIGINT/INT columns named *_cents.
--     Integer math is exact; floating point is never used for money.
--   * Percentages and modifiers are DECIMAL(x,2) in percent units: 12.50 = 12.5 %.
--   * Ratings and scores are TINYINT UNSIGNED between 0 and 100.
--   * "day" columns are in-game days (the player's clock), not calendar dates.
--   * created_at / updated_at are UTC DATETIMEs.
--   * Catalog tables (business_types, products, upgrades, ...) hold rules and
--     are filled by seed.sql. Player tables hold state and always lead with user_id.
--   * Deleting a user cascades to everything that user owns. Catalog rows are
--     protected with ON DELETE RESTRICT so rules can never vanish under a player.
--   * CHECK constraints are enforced by MySQL 8.0.16+ and MariaDB 10.2+.

CREATE TABLE IF NOT EXISTS schema_migrations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  filename    VARCHAR(255) NOT NULL UNIQUE,
  executed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 1. ACCOUNTS AND PLAYER STATE
-- ============================================================================

-- Login identity only. Authentication arrives in a later level; nothing here
-- is game state, so the account can change without touching gameplay.
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  username      VARCHAR(40)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  email_verified_at DATETIME NULL,
  last_login_at     DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Game state of one player: 1:1 with users, so user_id is the primary key.
-- cash_cents can never go below zero; services must check funds first
-- (inside a transaction, after SELECT ... FOR UPDATE on this row).
CREATE TABLE IF NOT EXISTS player_profiles (
  user_id      INT UNSIGNED NOT NULL,
  display_name VARCHAR(60)  NOT NULL,
  avatar       VARCHAR(60)  NULL,
  cash_cents   BIGINT       NOT NULL DEFAULT 0,
  level        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  xp           INT UNSIGNED NOT NULL DEFAULT 0,
  reputation   TINYINT UNSIGNED NOT NULL DEFAULT 50,
  current_day  INT UNSIGNED NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_player_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_player_profiles_cash CHECK (cash_cents >= 0),
  CONSTRAINT chk_player_profiles_level CHECK (level >= 1),
  CONSTRAINT chk_player_profiles_reputation CHECK (reputation <= 100),
  CONSTRAINT chk_player_profiles_day CHECK (current_day >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 2. CATALOG: WHAT EXISTS IN THE WORLD (filled by seed.sql)
-- ============================================================================

CREATE TABLE IF NOT EXISTS business_types (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug               VARCHAR(40)  NOT NULL,
  name               VARCHAR(80)  NOT NULL,
  description        VARCHAR(255) NULL,
  tone_color         CHAR(7)      NOT NULL DEFAULT '#0e8a84',
  startup_cost_cents INT UNSIGNED NOT NULL,
  base_capacity      SMALLINT UNSIGNED NOT NULL,     -- customers served per day with no upgrades or staff
  base_staff_slots   TINYINT UNSIGNED  NOT NULL DEFAULT 2,
  min_player_level   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  sort_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_business_types_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A rentable lot on the street. This is a template: every player gets their own
-- copy of the street, so two players never compete for the same lot. Who rents
-- which lot is recorded in shops.
CREATE TABLE IF NOT EXISTS properties (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code               VARCHAR(40)  NOT NULL,
  name               VARCHAR(80)  NOT NULL,
  description        VARCHAR(255) NULL,
  street_slot        TINYINT UNSIGNED NOT NULL,      -- position along the street; neighbors have adjacent slots
  size_sqm           SMALLINT UNSIGNED NOT NULL,
  rent_per_day_cents INT UNSIGNED NOT NULL,
  foot_traffic       TINYINT UNSIGNED NOT NULL,      -- 0-100, how many people pass by
  is_corner          TINYINT(1)   NOT NULL DEFAULT 0,
  min_player_level   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_properties_code (code),
  UNIQUE KEY uq_properties_street_slot (street_slot),
  CONSTRAINT chk_properties_traffic CHECK (foot_traffic <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Goods a business type sells. A product belongs to exactly one business type
-- (a croissant is a bakery product); the set a shop may stock is therefore its
-- business type's products. Prices here are defaults; shops set their own.
CREATE TABLE IF NOT EXISTS products (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_type_id INT UNSIGNED NOT NULL,
  slug             VARCHAR(60)  NOT NULL,
  name             VARCHAR(80)  NOT NULL,
  unit             VARCHAR(20)  NOT NULL DEFAULT 'piece',
  base_cost_cents  INT UNSIGNED NOT NULL,            -- what the shop pays per unit to restock
  base_price_cents INT UNSIGNED NOT NULL,            -- suggested selling price
  popularity       TINYINT UNSIGNED NOT NULL DEFAULT 50, -- relative chance a customer picks it
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_type (business_type_id, is_active),
  CONSTRAINT fk_products_business_type FOREIGN KEY (business_type_id) REFERENCES business_types (id),
  CONSTRAINT chk_products_price CHECK (base_price_cents >= base_cost_cents),
  CONSTRAINT chk_products_popularity CHECK (popularity <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kinds of shoppers (students, tourists, ...). The simulation never stores
-- individual customers; it works with these groups and their share of the town.
CREATE TABLE IF NOT EXISTS customer_segments (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug                 VARCHAR(40)  NOT NULL,
  name                 VARCHAR(80)  NOT NULL,
  description          VARCHAR(255) NULL,
  population_share_pct DECIMAL(5,2) NOT NULL,        -- share of all foot traffic; all segments sum to 100
  price_sensitivity    TINYINT UNSIGNED NOT NULL,    -- 0-100, high = walks away from high prices
  quality_sensitivity  TINYINT UNSIGNED NOT NULL,    -- 0-100, high = pays more for quality
  spending_power_pct   SMALLINT UNSIGNED NOT NULL DEFAULT 100, -- 100 = average basket size
  loyalty_rate         TINYINT UNSIGNED NOT NULL,    -- 0-100, how easily visitors become regulars
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_segments_slug (slug),
  CONSTRAINT chk_customer_segments_share CHECK (population_share_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How strongly each segment likes each kind of business (many-to-many).
-- Demand for a shop is built from foot traffic x segment share x this affinity.
CREATE TABLE IF NOT EXISTS customer_segment_affinities (
  segment_id       INT UNSIGNED NOT NULL,
  business_type_id INT UNSIGNED NOT NULL,
  affinity_pct     TINYINT UNSIGNED NOT NULL,        -- 0-100
  PRIMARY KEY (segment_id, business_type_id),
  KEY idx_affinities_business_type (business_type_id),
  CONSTRAINT fk_affinities_segment FOREIGN KEY (segment_id) REFERENCES customer_segments (id),
  CONSTRAINT fk_affinities_business_type FOREIGN KEY (business_type_id) REFERENCES business_types (id),
  CONSTRAINT chk_affinities_pct CHECK (affinity_pct <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff who can be hired. Rows are templates, not people owned by a player:
-- hiring one creates a shop_employees row with the agreed wage.
CREATE TABLE IF NOT EXISTS employees (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(60)  NOT NULL,
  business_type_id  INT UNSIGNED NULL,               -- NULL = can work in any business
  name              VARCHAR(80)  NOT NULL,
  role              VARCHAR(40)  NOT NULL,
  skill_level       TINYINT UNSIGNED NOT NULL,       -- 0-100
  wage_per_day_cents INT UNSIGNED NOT NULL,          -- asking wage
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employees_code (code),
  KEY idx_employees_type_role (business_type_id, role),
  CONSTRAINT fk_employees_business_type FOREIGN KEY (business_type_id) REFERENCES business_types (id),
  CONSTRAINT chk_employees_skill CHECK (skill_level <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrades a shop can buy. effect_value is the gain PER LEVEL, read according to effect_type:
--   capacity       + customers served per day
--   quality        + quality points (0-100 scale)
--   appeal         + percentage points of walk-in conversion
--   cost_reduction - percent off restocking costs
--   staff_slots    + employee slots
-- Price of level n = base_cost_cents x (1 + cost_growth_pct/100)^(n-1), calculated by the service.
CREATE TABLE IF NOT EXISTS upgrades (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(60)  NOT NULL,
  business_type_id  INT UNSIGNED NULL,               -- NULL = available to every business
  name              VARCHAR(80)  NOT NULL,
  description       VARCHAR(255) NULL,
  effect_type       ENUM('capacity','quality','appeal','cost_reduction','staff_slots') NOT NULL,
  effect_value      DECIMAL(8,2) NOT NULL,
  base_cost_cents   INT UNSIGNED NOT NULL,
  cost_growth_pct   DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  max_level         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  min_player_level  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_upgrades_code (code),
  KEY idx_upgrades_business_type (business_type_id),
  CONSTRAINT fk_upgrades_business_type FOREIGN KEY (business_type_id) REFERENCES business_types (id),
  CONSTRAINT chk_upgrades_max_level CHECK (max_level >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marketing options a shop can run. A purchase is recorded in shop_campaigns.
CREATE TABLE IF NOT EXISTS marketing_campaigns (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(60)  NOT NULL,
  name              VARCHAR(80)  NOT NULL,
  description       VARCHAR(255) NULL,
  cost_cents        INT UNSIGNED NOT NULL,
  duration_days     SMALLINT UNSIGNED NOT NULL,
  demand_boost_pct  DECIMAL(5,2) NOT NULL,
  target_segment_id INT UNSIGNED NULL,               -- NULL = reaches every segment
  min_player_level  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_marketing_campaigns_code (code),
  KEY idx_marketing_campaigns_segment (target_segment_id),
  CONSTRAINT fk_marketing_campaigns_segment FOREIGN KEY (target_segment_id) REFERENCES customer_segments (id),
  CONSTRAINT chk_marketing_campaigns_duration CHECK (duration_days >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Random or scheduled happenings (rain, festivals, price hikes).
-- weight is the relative chance of being drawn; what an event does lives in event_effects.
CREATE TABLE IF NOT EXISTS events (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code             VARCHAR(60)  NOT NULL,
  name             VARCHAR(80)  NOT NULL,
  description      VARCHAR(255) NULL,
  category         ENUM('weather','economy','competition','holiday','local') NOT NULL,
  duration_days    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  weight           SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  min_player_level SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_events_code (code),
  CONSTRAINT chk_events_duration CHECK (duration_days >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One event can change several numbers. modifier_pct is signed: -25.00 = 25 % lower.
-- business_type_id / customer_segment_id narrow the effect; NULL means "everyone".
CREATE TABLE IF NOT EXISTS event_effects (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id            INT UNSIGNED NOT NULL,
  stat                ENUM('demand','foot_traffic','ingredient_cost','rent','price_sensitivity','satisfaction') NOT NULL,
  modifier_pct        DECIMAL(6,2) NOT NULL,
  business_type_id    INT UNSIGNED NULL,
  customer_segment_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_event_effects_event (event_id),
  KEY idx_event_effects_business_type (business_type_id),
  KEY idx_event_effects_segment (customer_segment_id),
  CONSTRAINT fk_event_effects_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_event_effects_business_type FOREIGN KEY (business_type_id) REFERENCES business_types (id),
  CONSTRAINT fk_event_effects_segment FOREIGN KEY (customer_segment_id) REFERENCES customer_segments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rival businesses, as designed characters. Their per-player behavior over time
-- will be added when competition is built; these rows define who they are.
CREATE TABLE IF NOT EXISTS competitors (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug               VARCHAR(60)  NOT NULL,
  name               VARCHAR(80)  NOT NULL,
  business_type_id   INT UNSIGNED NOT NULL,
  description        VARCHAR(255) NULL,
  quality_rating     TINYINT UNSIGNED NOT NULL,      -- 0-100
  price_level_pct    SMALLINT UNSIGNED NOT NULL DEFAULT 100, -- 100 = market average, 130 = 30 % pricier
  marketing_strength TINYINT UNSIGNED NOT NULL,      -- 0-100
  aggressiveness     TINYINT UNSIGNED NOT NULL,      -- 0-100, how fast they react to the player
  appears_on_day     INT UNSIGNED NOT NULL DEFAULT 0, -- 0 = present from the start
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_competitors_slug (slug),
  KEY idx_competitors_type (business_type_id, is_active),
  CONSTRAINT fk_competitors_business_type FOREIGN KEY (business_type_id) REFERENCES business_types (id),
  CONSTRAINT chk_competitors_quality CHECK (quality_rating <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What can be unlocked, and the rule for unlocking it. The metric is measured
-- from live game data (shops, transactions, ...), never stored twice.
CREATE TABLE IF NOT EXISTS achievements (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code             VARCHAR(60)  NOT NULL,
  name             VARCHAR(80)  NOT NULL,
  description      VARCHAR(255) NULL,
  metric           ENUM('shops_owned','shops_opened','employees_hired','upgrades_bought','cash_reached','days_played','customers_served') NOT NULL,
  target_value     BIGINT UNSIGNED NOT NULL,         -- cash_reached is in cents
  reward_cash_cents INT UNSIGNED NOT NULL DEFAULT 0,
  reward_xp        INT UNSIGNED NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_achievements_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 3. PLAYER STATE: SHOPS AND EVERYTHING THAT HANGS OFF THEM
-- ============================================================================

-- A shop = one lease on one lot + the business running in it.
-- rent_per_day_cents is a snapshot taken when the lease starts, so later changes
-- to the catalog or rent events never rewrite what the player agreed to.
-- Closed shops stay in the table (the ledger points at them); closing frees the lot.
CREATE TABLE IF NOT EXISTS shops (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            INT UNSIGNED NOT NULL,
  property_id        INT UNSIGNED NOT NULL,
  business_type_id   INT UNSIGNED NOT NULL,
  name               VARCHAR(80)  NOT NULL,
  status             ENUM('open','paused','closed') NOT NULL DEFAULT 'open',
  reputation         TINYINT UNSIGNED NOT NULL DEFAULT 50,
  rent_per_day_cents INT UNSIGNED NOT NULL,
  opened_day         INT UNSIGNED NOT NULL,
  closed_day         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- The lot of every shop that is not closed, NULL once closed. Combined with the
  -- unique key below, a player can hold each lot at most once at a time but may
  -- rent it again after closing (NULLs never collide in a unique index).
  active_property_id INT UNSIGNED GENERATED ALWAYS AS (IF(status = 'closed', NULL, property_id)) VIRTUAL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shops_active_lot (user_id, active_property_id),
  UNIQUE KEY uq_shops_id_user (id, user_id),         -- lets other tables prove "this shop belongs to this user"
  KEY idx_shops_user_status (user_id, status),
  KEY idx_shops_property (property_id),
  KEY idx_shops_business_type (business_type_id),
  CONSTRAINT fk_shops_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_shops_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_shops_business_type FOREIGN KEY (business_type_id) REFERENCES business_types (id),
  CONSTRAINT chk_shops_reputation CHECK (reputation <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a shop sells, at what price, and how much stock it holds. This one table
-- is both the "shop products" list and the inventory: they are 1:1 and change
-- together, so splitting them would only add a join.
-- stock_quantity is UNSIGNED: in strict mode, selling more than is in stock
-- raises "out of range" instead of going negative. avg_cost_cents is the moving
-- average purchase cost per unit, used for profit calculations.
CREATE TABLE IF NOT EXISTS shop_products (
  shop_id          INT UNSIGNED NOT NULL,
  product_id       INT UNSIGNED NOT NULL,
  sell_price_cents INT UNSIGNED NOT NULL,
  stock_quantity   INT UNSIGNED NOT NULL DEFAULT 0,
  avg_cost_cents   INT UNSIGNED NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 = on the shelf list but not for sale
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (shop_id, product_id),
  KEY idx_shop_products_product (product_id),
  CONSTRAINT fk_shop_products_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
  CONSTRAINT fk_shop_products_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hires. Firing deletes the row; wages paid are in the transactions ledger.
-- wage_per_day_cents is the agreed wage (a snapshot of the contract).
CREATE TABLE IF NOT EXISTS shop_employees (
  shop_id            INT UNSIGNED NOT NULL,
  employee_id        INT UNSIGNED NOT NULL,
  wage_per_day_cents INT UNSIGNED NOT NULL,
  hired_day          INT UNSIGNED NOT NULL,
  morale             TINYINT UNSIGNED NOT NULL DEFAULT 70,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (shop_id, employee_id),
  KEY idx_shop_employees_employee (employee_id),
  CONSTRAINT fk_shop_employees_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
  CONSTRAINT fk_shop_employees_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT chk_shop_employees_morale CHECK (morale <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrades a shop owns; level can never exceed upgrades.max_level (checked by the service).
CREATE TABLE IF NOT EXISTS shop_upgrades (
  shop_id           INT UNSIGNED NOT NULL,
  upgrade_id        INT UNSIGNED NOT NULL,
  level             TINYINT UNSIGNED NOT NULL DEFAULT 1,
  last_upgraded_day INT UNSIGNED NOT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (shop_id, upgrade_id),
  KEY idx_shop_upgrades_upgrade (upgrade_id),
  CONSTRAINT fk_shop_upgrades_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
  CONSTRAINT fk_shop_upgrades_upgrade FOREIGN KEY (upgrade_id) REFERENCES upgrades (id),
  CONSTRAINT chk_shop_upgrades_level CHECK (level >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campaign runs. Many runs of the same campaign are allowed (over time), so this has its own id.
-- cost_cents is what was actually paid.
CREATE TABLE IF NOT EXISTS shop_campaigns (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_id     INT UNSIGNED NOT NULL,
  campaign_id INT UNSIGNED NOT NULL,
  start_day   INT UNSIGNED NOT NULL,
  end_day     INT UNSIGNED NOT NULL,
  cost_cents  INT UNSIGNED NOT NULL,
  status      ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_shop_campaigns_shop (shop_id, status, end_day),
  KEY idx_shop_campaigns_campaign (campaign_id),
  CONSTRAINT fk_shop_campaigns_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
  CONSTRAINT fk_shop_campaigns_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns (id),
  CONSTRAINT chk_shop_campaigns_days CHECK (end_day >= start_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A shop's customer base, aggregated per segment (regulars and how they feel).
-- One row per shop and segment, not one per person: individual customers would
-- be millions of rows that nothing ever reads.
CREATE TABLE IF NOT EXISTS shop_customers (
  shop_id           INT UNSIGNED NOT NULL,
  segment_id        INT UNSIGNED NOT NULL,
  regular_customers INT UNSIGNED NOT NULL DEFAULT 0,
  loyalty           TINYINT UNSIGNED NOT NULL DEFAULT 50,
  satisfaction      TINYINT UNSIGNED NOT NULL DEFAULT 50,
  last_visit_day    INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (shop_id, segment_id),
  KEY idx_shop_customers_segment (segment_id),
  CONSTRAINT fk_shop_customers_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
  CONSTRAINT fk_shop_customers_segment FOREIGN KEY (segment_id) REFERENCES customer_segments (id),
  CONSTRAINT chk_shop_customers_scores CHECK (loyalty <= 100 AND satisfaction <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events currently or previously affecting a player. "Active" = end_day >= current_day.
CREATE TABLE IF NOT EXISTS player_events (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  event_id   INT UNSIGNED NOT NULL,
  start_day  INT UNSIGNED NOT NULL,
  end_day    INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_player_events_user_end (user_id, end_day),
  KEY idx_player_events_event (event_id),
  CONSTRAINT fk_player_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_player_events_event FOREIGN KEY (event_id) REFERENCES events (id),
  CONSTRAINT chk_player_events_days CHECK (end_day >= start_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_achievements (
  user_id        INT UNSIGNED NOT NULL,
  achievement_id INT UNSIGNED NOT NULL,
  unlocked_day   INT UNSIGNED NULL,
  unlocked_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, achievement_id),
  KEY idx_user_achievements_achievement (achievement_id),
  CONSTRAINT fk_user_achievements_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_user_achievements_achievement FOREIGN KEY (achievement_id) REFERENCES achievements (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 4. LEDGER, MESSAGES AND ADVICE
-- ============================================================================

-- The money ledger: every cash change is one row, never edited or deleted.
-- amount_cents is signed (income > 0, expense < 0); balance_after_cents is the
-- player's cash right after, so the history can be audited and replayed.
-- Insert the row and update player_profiles.cash_cents in the SAME transaction.
-- (shop_id, user_id) must match a shop owned by that user, so a ledger row can
-- never point at somebody else's shop; both are enforced by one composite key.
CREATE TABLE IF NOT EXISTS transactions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             INT UNSIGNED NOT NULL,
  shop_id             INT UNSIGNED NULL,             -- NULL for player-level entries (starting funds, rewards)
  product_id          INT UNSIGNED NULL,
  type                ENUM('initial_funds','rent','stock_purchase','sale','wage','upgrade','marketing','event','reward','refund','other') NOT NULL,
  amount_cents        BIGINT NOT NULL,
  balance_after_cents BIGINT NOT NULL,
  quantity            INT UNSIGNED NULL,             -- units bought or sold, when relevant
  game_day            INT UNSIGNED NOT NULL,
  description         VARCHAR(255) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transactions_user_day (user_id, game_day),
  KEY idx_transactions_shop_day (shop_id, game_day, type),
  KEY idx_transactions_shop_owner (shop_id, user_id),
  KEY idx_transactions_product (product_id),
  CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_transactions_shop_owner FOREIGN KEY (shop_id, user_id) REFERENCES shops (id, user_id) ON DELETE CASCADE,
  CONSTRAINT fk_transactions_product FOREIGN KEY (product_id) REFERENCES products (id),
  CONSTRAINT chk_transactions_amount CHECK (amount_cents <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-game inbox. data holds structured details for the UI (JSON).
CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  severity   ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
  category   VARCHAR(40)  NOT NULL DEFAULT 'general',
  title      VARCHAR(120) NOT NULL,
  message    VARCHAR(500) NULL,
  data       JSON NULL,
  game_day   INT UNSIGNED NULL,
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read (user_id, read_at, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Advice from the AI business advisor. context stores the numbers the advisor
-- was shown (JSON), so any answer can be explained and reproduced later.
CREATE TABLE IF NOT EXISTS ai_advice (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  shop_id    INT UNSIGNED NULL,
  category   ENUM('general','pricing','stock','staffing','marketing','expansion') NOT NULL DEFAULT 'general',
  question   VARCHAR(500) NULL,
  advice     TEXT NOT NULL,
  context    JSON NULL,
  model      VARCHAR(80) NULL,
  game_day   INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_advice_user_created (user_id, created_at),
  KEY idx_ai_advice_shop_owner (shop_id, user_id),
  CONSTRAINT fk_ai_advice_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_advice_shop_owner FOREIGN KEY (shop_id, user_id) REFERENCES shops (id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
