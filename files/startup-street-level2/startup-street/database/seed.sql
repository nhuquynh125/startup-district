-- Startup Street: seed data (LEVEL 2).
--
-- Game rules and reference data only. There are no player accounts here.
-- Apply with:  php database/migrate.php --seed
--
-- Safe to run repeatedly: each table is keyed by a natural unique key (slug or
-- code) and uses ON DUPLICATE KEY UPDATE, so re-running refreshes the catalog
-- to match this file without creating duplicates or changing ids. Rows are
-- linked by slug/code lookups, never by hard-coded ids.
-- Money is in cents (450 = $4.50).


-- ---------------------------------------------------------------------------
-- Business types (same six shops as the landing page)
-- ---------------------------------------------------------------------------
INSERT INTO business_types
  (slug, name, description, tone_color, startup_cost_cents, base_capacity, base_staff_slots, sort_order)
VALUES
  ('bubble-tea',    'Bubble Tea',    'Fast, trendy and loved by students.',              '#ff6f59',  350000, 120, 2, 1),
  ('restaurant',    'Restaurant',    'Higher prices, bigger kitchens, longer queues.',   '#e6a82a', 1500000,  60, 4, 2),
  ('flower-shop',   'Flower Shop',   'Small space, big margins, busy holidays.',         '#e0508a',  300000,  40, 1, 3),
  ('cafe',          'Café',          'Steady regulars and a cozy corner to win.',        '#0e8a84',  600000,  90, 2, 4),
  ('bakery',        'Bakery',        'Early mornings and fresh batches every day.',      '#d98f00',  800000, 100, 3, 5),
  ('fashion-store', 'Fashion Store', 'Follow the seasons and stay ahead of trends.',     '#2f7fd8',  900000,  50, 2, 6)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), tone_color = VALUES(tone_color),
  startup_cost_cents = VALUES(startup_cost_cents), base_capacity = VALUES(base_capacity),
  base_staff_slots = VALUES(base_staff_slots), sort_order = VALUES(sort_order);


-- ---------------------------------------------------------------------------
-- Properties: eight lots along the street (rent is per in-game day)
-- ---------------------------------------------------------------------------
INSERT INTO properties
  (code, name, description, street_slot, size_sqm, rent_per_day_cents, foot_traffic, is_corner, min_player_level)
VALUES
  ('main-01', 'Quiet Lane Shop',   'Cheap and calm, but few people pass by.',            1, 30,  3000, 25, 0, 1),
  ('main-02', 'Old Town Alley',    'Tiny lot tucked behind the clock tower.',            2, 25,  3500, 35, 0, 1),
  ('main-03', 'Park Side',         'Corner lot next to the park entrance.',              3, 50,  6500, 60, 1, 1),
  ('main-04', 'Market Row 1',      'Steady crowds from the morning market.',             4, 35,  7500, 70, 0, 1),
  ('main-05', 'Market Row 2',      'Next door to Market Row 1.',                         5, 35,  8000, 75, 0, 1),
  ('main-06', 'School Gate',       'Packed at 8:00 and 15:30.',                          6, 38,  7000, 80, 0, 1),
  ('main-07', 'Plaza Front',       'Faces the main plaza. Everyone walks past.',         7, 60,  9500, 85, 0, 2),
  ('main-08', 'Station Corner',    'The best corner in town, right by the station.',     8, 40, 11000, 90, 1, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), street_slot = VALUES(street_slot),
  size_sqm = VALUES(size_sqm), rent_per_day_cents = VALUES(rent_per_day_cents),
  foot_traffic = VALUES(foot_traffic), is_corner = VALUES(is_corner),
  min_player_level = VALUES(min_player_level);


-- ---------------------------------------------------------------------------
-- Customer segments (population shares add up to 100)
-- ---------------------------------------------------------------------------
INSERT INTO customer_segments
  (slug, name, description, population_share_pct, price_sensitivity, quality_sensitivity, spending_power_pct, loyalty_rate)
VALUES
  ('students',       'Students',       'Budget-conscious and always in a hurry.',          30.00, 85, 40,  70, 55),
  ('office-workers', 'Office Workers', 'Short breaks, steady routines, decent budgets.',   25.00, 45, 60, 110, 60),
  ('families',       'Families',       'Shop together and value variety and service.',     20.00, 65, 65, 100, 70),
  ('tourists',       'Tourists',       'Big spenders who rarely come back.',               10.00, 30, 55, 130, 10),
  ('seniors',        'Seniors',        'Loyal regulars who notice quality and price.',     15.00, 70, 70,  85, 80)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description),
  population_share_pct = VALUES(population_share_pct), price_sensitivity = VALUES(price_sensitivity),
  quality_sensitivity = VALUES(quality_sensitivity), spending_power_pct = VALUES(spending_power_pct),
  loyalty_rate = VALUES(loyalty_rate);

-- How much each segment likes each business (0-100)
INSERT INTO customer_segment_affinities (segment_id, business_type_id, affinity_pct)
SELECT s.id, b.id, a.affinity_pct
FROM (
            SELECT 'students' AS segment, 'bubble-tea' AS business_type, 95 AS affinity_pct
  UNION ALL SELECT 'students', 'restaurant',    40
  UNION ALL SELECT 'students', 'flower-shop',   15
  UNION ALL SELECT 'students', 'cafe',          70
  UNION ALL SELECT 'students', 'bakery',        60
  UNION ALL SELECT 'students', 'fashion-store', 55
  UNION ALL SELECT 'office-workers', 'bubble-tea',    55
  UNION ALL SELECT 'office-workers', 'restaurant',    80
  UNION ALL SELECT 'office-workers', 'flower-shop',   20
  UNION ALL SELECT 'office-workers', 'cafe',          95
  UNION ALL SELECT 'office-workers', 'bakery',        65
  UNION ALL SELECT 'office-workers', 'fashion-store', 45
  UNION ALL SELECT 'families', 'bubble-tea',    50
  UNION ALL SELECT 'families', 'restaurant',    90
  UNION ALL SELECT 'families', 'flower-shop',   45
  UNION ALL SELECT 'families', 'cafe',          40
  UNION ALL SELECT 'families', 'bakery',        85
  UNION ALL SELECT 'families', 'fashion-store', 60
  UNION ALL SELECT 'tourists', 'bubble-tea',    65
  UNION ALL SELECT 'tourists', 'restaurant',    85
  UNION ALL SELECT 'tourists', 'flower-shop',   25
  UNION ALL SELECT 'tourists', 'cafe',          70
  UNION ALL SELECT 'tourists', 'bakery',        60
  UNION ALL SELECT 'tourists', 'fashion-store', 70
  UNION ALL SELECT 'seniors', 'bubble-tea',    20
  UNION ALL SELECT 'seniors', 'restaurant',    60
  UNION ALL SELECT 'seniors', 'flower-shop',   75
  UNION ALL SELECT 'seniors', 'cafe',          55
  UNION ALL SELECT 'seniors', 'bakery',        80
  UNION ALL SELECT 'seniors', 'fashion-store', 40
) AS a
JOIN customer_segments s ON s.slug = a.segment
JOIN business_types    b ON b.slug = a.business_type
ON DUPLICATE KEY UPDATE affinity_pct = VALUES(affinity_pct);


-- ---------------------------------------------------------------------------
-- Products: base_cost_cents (restock price) and base_price_cents (suggested sale price)
-- ---------------------------------------------------------------------------
INSERT INTO products
  (business_type_id, slug, name, unit, base_cost_cents, base_price_cents, popularity)
VALUES
  -- Bubble tea
  ((SELECT id FROM business_types WHERE slug = 'bubble-tea'), 'classic-milk-tea',  'Classic Milk Tea',  'cup',   120,  450, 90),
  ((SELECT id FROM business_types WHERE slug = 'bubble-tea'), 'brown-sugar-boba',  'Brown Sugar Boba',  'cup',   170,  550, 80),
  ((SELECT id FROM business_types WHERE slug = 'bubble-tea'), 'taro-milk-tea',     'Taro Milk Tea',     'cup',   150,  500, 60),
  ((SELECT id FROM business_types WHERE slug = 'bubble-tea'), 'fruit-tea',         'Fruit Tea',         'cup',   140,  480, 55),
  ((SELECT id FROM business_types WHERE slug = 'bubble-tea'), 'matcha-latte',      'Matcha Latte',      'cup',   160,  520, 45),
  -- Restaurant
  ((SELECT id FROM business_types WHERE slug = 'restaurant'), 'grilled-chicken-plate', 'Grilled Chicken Plate', 'plate', 520, 1450, 85),
  ((SELECT id FROM business_types WHERE slug = 'restaurant'), 'pasta-carbonara',       'Pasta Carbonara',       'plate', 420, 1300, 75),
  ((SELECT id FROM business_types WHERE slug = 'restaurant'), 'veggie-burger',         'Veggie Burger',         'piece', 380, 1100, 60),
  ((SELECT id FROM business_types WHERE slug = 'restaurant'), 'house-salad',           'House Salad',           'bowl',  280,  900, 50),
  ((SELECT id FROM business_types WHERE slug = 'restaurant'), 'tomato-soup',           'Tomato Soup',           'bowl',  200,  700, 40),
  ((SELECT id FROM business_types WHERE slug = 'restaurant'), 'chocolate-cake-slice',  'Chocolate Cake Slice',  'slice', 220,  650, 45),
  -- Flower shop
  ((SELECT id FROM business_types WHERE slug = 'flower-shop'), 'single-rose',     'Single Rose',     'stem',     150,  400, 80),
  ((SELECT id FROM business_types WHERE slug = 'flower-shop'), 'tulip-bunch',     'Tulip Bunch',     'bunch',    480, 1200, 65),
  ((SELECT id FROM business_types WHERE slug = 'flower-shop'), 'sunflower-bunch', 'Sunflower Bunch', 'bunch',    600, 1500, 50),
  ((SELECT id FROM business_types WHERE slug = 'flower-shop'), 'mixed-bouquet',   'Mixed Bouquet',   'bouquet', 1100, 2800, 75),
  ((SELECT id FROM business_types WHERE slug = 'flower-shop'), 'potted-orchid',   'Potted Orchid',   'pot',     1500, 3500, 35),
  -- Café
  ((SELECT id FROM business_types WHERE slug = 'cafe'), 'espresso',        'Espresso',        'cup',    70,  300, 80),
  ((SELECT id FROM business_types WHERE slug = 'cafe'), 'cappuccino',      'Cappuccino',      'cup',   110,  420, 90),
  ((SELECT id FROM business_types WHERE slug = 'cafe'), 'iced-latte',      'Iced Latte',      'cup',   130,  480, 70),
  ((SELECT id FROM business_types WHERE slug = 'cafe'), 'blueberry-muffin','Blueberry Muffin','piece', 120,  380, 55),
  ((SELECT id FROM business_types WHERE slug = 'cafe'), 'avocado-toast',   'Avocado Toast',   'plate', 320,  950, 45),
  -- Bakery
  ((SELECT id FROM business_types WHERE slug = 'bakery'), 'croissant',       'Croissant',       'piece',  90,  350, 90),
  ((SELECT id FROM business_types WHERE slug = 'bakery'), 'baguette',        'Baguette',        'piece', 100,  400, 70),
  ((SELECT id FROM business_types WHERE slug = 'bakery'), 'cinnamon-roll',   'Cinnamon Roll',   'piece', 110,  420, 65),
  ((SELECT id FROM business_types WHERE slug = 'bakery'), 'sourdough-loaf',  'Sourdough Loaf',  'loaf',  180,  700, 60),
  ((SELECT id FROM business_types WHERE slug = 'bakery'), 'birthday-cake',   'Birthday Cake',   'cake',  800, 2400, 25),
  -- Fashion store
  ((SELECT id FROM business_types WHERE slug = 'fashion-store'), 'cotton-t-shirt',  'Cotton T-Shirt',  'piece',  700, 1800, 85),
  ((SELECT id FROM business_types WHERE slug = 'fashion-store'), 'wool-scarf',      'Wool Scarf',      'piece',  800, 2200, 45),
  ((SELECT id FROM business_types WHERE slug = 'fashion-store'), 'denim-jeans',     'Denim Jeans',     'piece', 2000, 4800, 70),
  ((SELECT id FROM business_types WHERE slug = 'fashion-store'), 'summer-dress',    'Summer Dress',    'piece', 2300, 5500, 55),
  ((SELECT id FROM business_types WHERE slug = 'fashion-store'), 'canvas-sneakers', 'Canvas Sneakers', 'pair',  2900, 6500, 60)
ON DUPLICATE KEY UPDATE
  business_type_id = VALUES(business_type_id), name = VALUES(name), unit = VALUES(unit),
  base_cost_cents = VALUES(base_cost_cents), base_price_cents = VALUES(base_price_cents),
  popularity = VALUES(popularity);


-- ---------------------------------------------------------------------------
-- Employees available for hire (templates; a hire becomes a shop_employees row)
-- ---------------------------------------------------------------------------
INSERT INTO employees
  (code, business_type_id, name, role, skill_level, wage_per_day_cents)
VALUES
  ('mia-tran',     NULL, 'Mia Tran',     'cashier',   40, 3500),
  ('leo-santos',   NULL, 'Leo Santos',   'cashier',   65, 4800),
  ('omar-haddad',  NULL, 'Omar Haddad',  'manager',   75, 7000),
  ('linh-pham',    (SELECT id FROM business_types WHERE slug = 'bubble-tea'),    'Linh Pham',    'tea maker',  52, 3900),
  ('zoe-nguyen',   (SELECT id FROM business_types WHERE slug = 'restaurant'),    'Zoe Nguyen',   'cook',       70, 5500),
  ('chloe-martin', (SELECT id FROM business_types WHERE slug = 'flower-shop'),   'Chloe Martin', 'florist',    58, 4300),
  ('ava-kim',      (SELECT id FROM business_types WHERE slug = 'cafe'),          'Ava Kim',      'barista',    55, 4200),
  ('noah-becker',  (SELECT id FROM business_types WHERE slug = 'cafe'),          'Noah Becker',  'barista',    78, 5800),
  ('sofia-rossi',  (SELECT id FROM business_types WHERE slug = 'bakery'),        'Sofia Rossi',  'baker',      60, 4600),
  ('ethan-park',   (SELECT id FROM business_types WHERE slug = 'bakery'),        'Ethan Park',   'baker',      82, 6500),
  ('lucas-meyer',  (SELECT id FROM business_types WHERE slug = 'fashion-store'), 'Lucas Meyer',  'stylist',    62, 4700)
ON DUPLICATE KEY UPDATE
  business_type_id = VALUES(business_type_id), name = VALUES(name), role = VALUES(role),
  skill_level = VALUES(skill_level), wage_per_day_cents = VALUES(wage_per_day_cents);


-- ---------------------------------------------------------------------------
-- Upgrades (NULL business type = available to every shop)
-- ---------------------------------------------------------------------------
INSERT INTO upgrades
  (code, business_type_id, name, description, effect_type, effect_value, base_cost_cents, cost_growth_pct, max_level)
VALUES
  ('extra-counter',       NULL, 'Extra Counter Space',  'Serve more customers every day.',          'capacity',       15.00,  50000, 45.00, 5),
  ('better-signage',      NULL, 'Better Signage',       'A brighter sign pulls in more passers-by.','appeal',          3.00,  30000, 40.00, 5),
  ('bulk-supplier-deal',  NULL, 'Bulk Supplier Deal',   'Cheaper stock on every order.',            'cost_reduction',  2.50,  80000, 60.00, 4),
  ('staff-room',          NULL, 'Staff Room',           'Room for one more team member.',           'staff_slots',     1.00, 120000, 80.00, 3),
  ('premium-ingredients', NULL, 'Premium Ingredients',  'Better quality gets noticed.',             'quality',         5.00,  60000, 50.00, 5),
  ('pearl-cooker',        (SELECT id FROM business_types WHERE slug = 'bubble-tea'),    'Pearl Cooker',        'Cook boba in bigger batches.',     'capacity', 20.00,  45000, 50.00, 3),
  ('open-kitchen',        (SELECT id FROM business_types WHERE slug = 'restaurant'),    'Open Kitchen',        'Guests love watching the chefs.',  'appeal',    5.00, 150000, 55.00, 3),
  ('cold-display-case',   (SELECT id FROM business_types WHERE slug = 'flower-shop'),   'Cold Display Case',   'Flowers stay fresh and fragrant.', 'quality',   6.00,  70000, 50.00, 3),
  ('pro-espresso-machine',(SELECT id FROM business_types WHERE slug = 'cafe'),          'Pro Espresso Machine','Better coffee, faster.',           'quality',   8.00,  90000, 60.00, 3),
  ('stone-oven',          (SELECT id FROM business_types WHERE slug = 'bakery'),        'Stone Oven',          'Bake bigger batches each morning.','capacity', 18.00, 110000, 55.00, 4),
  ('fitting-rooms',       (SELECT id FROM business_types WHERE slug = 'fashion-store'), 'Fitting Rooms',       'Shoppers try more and buy more.',  'appeal',    4.00, 100000, 50.00, 3)
ON DUPLICATE KEY UPDATE
  business_type_id = VALUES(business_type_id), name = VALUES(name), description = VALUES(description),
  effect_type = VALUES(effect_type), effect_value = VALUES(effect_value),
  base_cost_cents = VALUES(base_cost_cents), cost_growth_pct = VALUES(cost_growth_pct),
  max_level = VALUES(max_level);


-- ---------------------------------------------------------------------------
-- Marketing campaigns
-- ---------------------------------------------------------------------------
INSERT INTO marketing_campaigns
  (code, name, description, cost_cents, duration_days, demand_boost_pct, target_segment_id, min_player_level)
VALUES
  ('street-flyers',  'Street Flyers',          'Hand out flyers around the block.',       5000,  3,  5.00, NULL, 1),
  ('social-ads',     'Social Media Ads',       'Local ads on every phone in town.',      20000,  7, 12.00, NULL, 1),
  ('student-week',   'Student Discount Week',  'Cheap deals aimed at the school crowd.', 15000,  5, 15.00, (SELECT id FROM customer_segments WHERE slug = 'students'), 1),
  ('local-radio',    'Local Radio Spot',       'Your name on the morning show.',         60000,  7, 20.00, NULL, 2),
  ('billboard',      'Billboard on Main Road', 'Impossible to miss.',                   150000, 14, 25.00, NULL, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), cost_cents = VALUES(cost_cents),
  duration_days = VALUES(duration_days), demand_boost_pct = VALUES(demand_boost_pct),
  target_segment_id = VALUES(target_segment_id), min_player_level = VALUES(min_player_level);


-- ---------------------------------------------------------------------------
-- Events and what they do
-- ---------------------------------------------------------------------------
INSERT INTO events (code, name, description, category, duration_days, weight)
VALUES
  ('rainy-day',           'Rainy Day',               'Fewer people walk the street today.',            'weather',     1, 30),
  ('heatwave',            'Heatwave',                'Cold drinks fly off the shelves.',               'weather',     3, 12),
  ('valentines-day',      'Valentine''s Day',        'Flowers and dinners are in demand.',             'holiday',     1,  5),
  ('mothers-day',         'Mother''s Day',           'Bouquets and cakes are in demand.',              'holiday',     1,  5),
  ('street-festival',     'Street Festival',         'Music and crowds fill the street.',              'local',       2,  8),
  ('supplier-price-hike', 'Supplier Price Hike',     'Restocking costs more for a while.',             'economy',     5, 10),
  ('economic-slowdown',   'Economic Slowdown',       'People spend less and compare prices.',          'economy',    10,  6),
  ('rival-opening',       'Rival Opens Nearby',      'A new competitor takes some of your customers.', 'competition', 7,  8),
  ('back-to-school',      'Back to School',          'Students are back in town.',                     'local',       7,  6),
  ('tourist-season',      'Tourist Season',          'Visitors flood the neighborhood.',               'local',      14,  6),
  ('viral-post',          'Viral Social Media Post', 'Someone posted about your street.',              'local',       3,  4),
  ('rent-review',         'Rent Review',             'Landlords raise the rent.',                      'economy',    30,  3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), category = VALUES(category),
  duration_days = VALUES(duration_days), weight = VALUES(weight);

-- Effects have no natural key, so they are rebuilt each time. Nothing points at
-- event_effects rows, so replacing them is safe.
DELETE FROM event_effects;

INSERT INTO event_effects (event_id, stat, modifier_pct, business_type_id, customer_segment_id)
VALUES
  ((SELECT id FROM events WHERE code = 'rainy-day'),           'foot_traffic',      -25.00, NULL, NULL),
  ((SELECT id FROM events WHERE code = 'rainy-day'),           'demand',             10.00, (SELECT id FROM business_types WHERE slug = 'cafe'), NULL),
  ((SELECT id FROM events WHERE code = 'heatwave'),            'demand',             30.00, (SELECT id FROM business_types WHERE slug = 'bubble-tea'), NULL),
  ((SELECT id FROM events WHERE code = 'valentines-day'),      'demand',             80.00, (SELECT id FROM business_types WHERE slug = 'flower-shop'), NULL),
  ((SELECT id FROM events WHERE code = 'valentines-day'),      'demand',             35.00, (SELECT id FROM business_types WHERE slug = 'restaurant'), NULL),
  ((SELECT id FROM events WHERE code = 'mothers-day'),         'demand',             60.00, (SELECT id FROM business_types WHERE slug = 'flower-shop'), NULL),
  ((SELECT id FROM events WHERE code = 'mothers-day'),         'demand',             25.00, (SELECT id FROM business_types WHERE slug = 'bakery'), NULL),
  ((SELECT id FROM events WHERE code = 'street-festival'),     'foot_traffic',       40.00, NULL, NULL),
  ((SELECT id FROM events WHERE code = 'supplier-price-hike'), 'ingredient_cost',    15.00, NULL, NULL),
  ((SELECT id FROM events WHERE code = 'economic-slowdown'),   'demand',            -10.00, NULL, NULL),
  ((SELECT id FROM events WHERE code = 'economic-slowdown'),   'price_sensitivity',  15.00, NULL, NULL),
  ((SELECT id FROM events WHERE code = 'rival-opening'),       'demand',            -12.00, NULL, NULL),
  ((SELECT id FROM events WHERE code = 'back-to-school'),      'demand',             25.00, NULL, (SELECT id FROM customer_segments WHERE slug = 'students')),
  ((SELECT id FROM events WHERE code = 'tourist-season'),      'demand',             30.00, NULL, (SELECT id FROM customer_segments WHERE slug = 'tourists')),
  ((SELECT id FROM events WHERE code = 'tourist-season'),      'foot_traffic',       10.00, NULL, NULL),
  ((SELECT id FROM events WHERE code = 'viral-post'),          'demand',             20.00, NULL, NULL),
  ((SELECT id FROM events WHERE code = 'viral-post'),          'satisfaction',        5.00, NULL, NULL),
  ((SELECT id FROM events WHERE code = 'rent-review'),         'rent',               10.00, NULL, NULL);


-- ---------------------------------------------------------------------------
-- Competitors: two per business type, one cheap and one premium
-- ---------------------------------------------------------------------------
INSERT INTO competitors
  (slug, name, business_type_id, description, quality_rating, price_level_pct, marketing_strength, aggressiveness, appears_on_day)
VALUES
  ('boba-bros',      'Boba Bros',      (SELECT id FROM business_types WHERE slug = 'bubble-tea'),    'Cheap and cheerful with a loyal student crowd.', 55,  90, 60, 50,  0),
  ('sip-society',    'Sip Society',    (SELECT id FROM business_types WHERE slug = 'bubble-tea'),    'Premium teas at premium prices.',               80, 125, 45, 35, 20),
  ('golden-wok',     'Golden Wok',     (SELECT id FROM business_types WHERE slug = 'restaurant'),    'Big portions and fast service.',                60,  95, 40, 40,  0),
  ('trattoria-luna', 'Trattoria Luna', (SELECT id FROM business_types WHERE slug = 'restaurant'),    'Candle-lit Italian favorite.',                  85, 130, 50, 30, 15),
  ('petal-pushers',  'Petal Pushers',  (SELECT id FROM business_types WHERE slug = 'flower-shop'),   'Corner florist with a cheap bouquet line.',     55,  85, 35, 45,  0),
  ('bloom-and-co',   'Bloom & Co',     (SELECT id FROM business_types WHERE slug = 'flower-shop'),   'Boutique florist with luxury arrangements.',    90, 140, 55, 30, 25),
  ('daily-grind',    'The Daily Grind',(SELECT id FROM business_types WHERE slug = 'cafe'),          'Commuter coffee on every corner.',              65, 100, 70, 60,  0),
  ('bean-there',     'Bean There',     (SELECT id FROM business_types WHERE slug = 'cafe'),          'Specialty roaster with a reading nook.',        85, 120, 40, 25, 30),
  ('crumb-and-co',   'Crumb & Co',     (SELECT id FROM business_types WHERE slug = 'bakery'),        'Famous for its sourdough.',                     75, 100, 45, 35,  0),
  ('flour-power',    'Flour Power',    (SELECT id FROM business_types WHERE slug = 'bakery'),        'Discount bakery chain.',                        50,  80, 65, 55, 10),
  ('threadline',     'Threadline',     (SELECT id FROM business_types WHERE slug = 'fashion-store'), 'Fast fashion that follows every trend.',       55,  85, 75, 65,  0),
  ('north-and-oak',  'North & Oak',    (SELECT id FROM business_types WHERE slug = 'fashion-store'), 'Quality basics made to last.',                  85, 135, 35, 20, 30)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), business_type_id = VALUES(business_type_id), description = VALUES(description),
  quality_rating = VALUES(quality_rating), price_level_pct = VALUES(price_level_pct),
  marketing_strength = VALUES(marketing_strength), aggressiveness = VALUES(aggressiveness),
  appears_on_day = VALUES(appears_on_day);


-- ---------------------------------------------------------------------------
-- Achievements (cash_reached targets are in cents)
-- ---------------------------------------------------------------------------
INSERT INTO achievements (code, name, description, metric, target_value, reward_cash_cents, reward_xp)
VALUES
  ('first-shop',    'First Door Open',     'Open your first shop.',           'shops_opened',     1,        0,  50),
  ('first-hire',    'Help Wanted',         'Hire your first employee.',       'employees_hired',  1,        0,  30),
  ('first-upgrade', 'Level Up the Shop',   'Buy your first upgrade.',         'upgrades_bought',  1,        0,  40),
  ('week-one',      'First Week',          'Play for 7 game days.',           'days_played',      7,        0,  60),
  ('cash-10k',      'Five Figures',        'Hold $10,000 in cash.',           'cash_reached',     1000000,  10000, 100),
  ('serve-1000',    'Thousand Smiles',     'Serve 1,000 customers.',          'customers_served', 1000,     20000, 150),
  ('three-shops',   'Street Mogul',        'Run three shops at once.',        'shops_owned',      3,        50000, 200),
  ('cash-100k',     'Six Figures',         'Hold $100,000 in cash.',          'cash_reached',     10000000, 100000, 300)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), metric = VALUES(metric),
  target_value = VALUES(target_value), reward_cash_cents = VALUES(reward_cash_cents),
  reward_xp = VALUES(reward_xp);
