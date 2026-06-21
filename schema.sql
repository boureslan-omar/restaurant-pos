-- ============================================================
-- Restaurant POS System — Database Schema
-- MySQL 5.7+ / 8.x Compatible
-- Default admin password: admin123
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS purchase_log;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS reservations;
DROP TABLE IF EXISTS menu_item_ingredients;
DROP TABLE IF EXISTS menu_items;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS restaurant_tables;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE users (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100)  NOT NULL,
    email        VARCHAR(150)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role         ENUM('admin','cashier','waiter','kitchen') NOT NULL DEFAULT 'cashier',
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RESTAURANT TABLES (floor plan)
-- ============================================================
CREATE TABLE restaurant_tables (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_number VARCHAR(20)  NOT NULL UNIQUE,
    section      VARCHAR(50)  NOT NULL DEFAULT 'Main Hall',
    capacity     TINYINT UNSIGNED NOT NULL DEFAULT 4,
    status       ENUM('open','occupied','reserved','cleaning') NOT NULL DEFAULT 'open',
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MENU CATEGORIES
-- ============================================================
CREATE TABLE categories (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    description  TEXT,
    icon         VARCHAR(60)  DEFAULT 'utensils',
    sort_order   TINYINT UNSIGNED DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INVENTORY / STOCK
-- ============================================================
CREATE TABLE inventory (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    unit            VARCHAR(30)  NOT NULL DEFAULT 'pcs',
    current_stock   DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    min_alert_level DECIMAL(10,3) NOT NULL DEFAULT 10.000,
    cost_per_unit   DECIMAL(10,4) DEFAULT 0.0000,
    units_per_box   DECIMAL(10,3) DEFAULT NULL  COMMENT 'Set when unit=boxes',
    cost_per_box    DECIMAL(10,2) DEFAULT NULL,
    selling_price   DECIMAL(10,2) DEFAULT NULL  COMMENT 'Per unit selling price',
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MENU ITEMS
-- ============================================================
CREATE TABLE menu_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id   INT UNSIGNED NOT NULL,
    name          VARCHAR(150) NOT NULL,
    description   TEXT,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_available  TINYINT(1)   NOT NULL DEFAULT 1,
    track_stock   TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT '1 = deduct from stock_count each sale (no recipe needed)',
    stock_count   INT          DEFAULT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_item_category FOREIGN KEY (category_id)
        REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RECIPE / INGREDIENT MAPPING
-- links a menu item to one or more inventory items it consumes
-- ============================================================
CREATE TABLE menu_item_ingredients (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_item_id    INT UNSIGNED NOT NULL,
    inventory_id    INT UNSIGNED NOT NULL,
    quantity_needed DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    CONSTRAINT fk_recipe_item      FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id)  ON DELETE RESTRICT,
    UNIQUE KEY uq_item_ingredient (menu_item_id, inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ORDERS
-- ============================================================
CREATE TABLE orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        INT UNSIGNED DEFAULT NULL  COMMENT 'NULL for takeaway',
    user_id         INT UNSIGNED DEFAULT NULL,
    order_type      ENUM('dine_in','takeaway') NOT NULL DEFAULT 'dine_in',
    status          ENUM('pending','kitchen','served','paid','cancelled') NOT NULL DEFAULT 'pending',
    subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_rate        DECIMAL(5,2)  NOT NULL DEFAULT 10.00,
    tax_amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method  ENUM('cash','card','other') DEFAULT NULL,
    amount_tendered     DECIMAL(10,2) DEFAULT NULL,
    amount_tendered_lbp BIGINT        DEFAULT 0,
    change_due          DECIMAL(10,2) DEFAULT NULL,
    change_due_lbp      BIGINT        DEFAULT 0,
    customer_name   VARCHAR(100)  DEFAULT NULL,
    notes           TEXT,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_table FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_user  FOREIGN KEY (user_id)  REFERENCES users(id)             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RESERVATIONS
-- ============================================================
CREATE TABLE reservations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id      INT UNSIGNED NOT NULL,
    client_name   VARCHAR(150) NOT NULL,
    reserved_date DATE         NOT NULL,
    from_time     TIME         NOT NULL,
    to_time       TIME         NOT NULL,
    notes         TEXT,
    status        ENUM('confirmed','cancelled','completed') NOT NULL DEFAULT 'confirmed',
    created_by    INT UNSIGNED DEFAULT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_res_table FOREIGN KEY (table_id)
        REFERENCES restaurant_tables(id) ON DELETE CASCADE,
    CONSTRAINT fk_res_user FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_res_table_date ON reservations(table_id, reserved_date, status);

-- ============================================================
-- ORDER ITEMS
-- ============================================================
CREATE TABLE order_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id     INT UNSIGNED NOT NULL,
    menu_item_id INT UNSIGNED NOT NULL,
    item_name    VARCHAR(150) NOT NULL COMMENT 'Snapshot of name at order time',
    quantity     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    unit_price   DECIMAL(10,2) NOT NULL,
    subtotal     DECIMAL(10,2) NOT NULL,
    notes        TEXT,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_oi_order FOREIGN KEY (order_id)     REFERENCES orders(id)     ON DELETE CASCADE,
    CONSTRAINT fk_oi_item  FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SETTINGS (key-value store for runtime configuration)
-- ============================================================
CREATE TABLE settings (
    `key`       VARCHAR(100) NOT NULL PRIMARY KEY,
    value       TEXT         NOT NULL DEFAULT '',
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, value) VALUES
('tax_rate', '0'),
('auto_print_receipt', '0'),
('auto_open_drawer', '0'),
('show_numpad', '1'),
('exchange_rate_usd_lbp', '0'),
('drawer_port', '');

-- ============================================================
-- PURCHASE LOG (records every stock addition for reporting)
-- ============================================================
CREATE TABLE purchase_log (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_id  INT UNSIGNED NOT NULL,
    qty_boxes     DECIMAL(10,3) DEFAULT NULL  COMMENT 'NULL when not a box purchase',
    qty_units     DECIMAL(10,3) NOT NULL,
    cost_per_box  DECIMAL(10,2) DEFAULT NULL,
    cost_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    total_cost    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes         TEXT,
    user_id       INT UNSIGNED DEFAULT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pl_inv  FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE,
    CONSTRAINT fk_pl_user FOREIGN KEY (user_id)      REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_pl_inv_date ON purchase_log(inventory_id, created_at);

-- ============================================================
-- INDEXES
-- ============================================================
CREATE INDEX idx_orders_status  ON orders(status);
CREATE INDEX idx_orders_date    ON orders(created_at);
CREATE INDEX idx_oi_order       ON order_items(order_id);
CREATE INDEX idx_menu_category  ON menu_items(category_id);
CREATE INDEX idx_inv_stock      ON inventory(current_stock, min_alert_level);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Users  (password for all: admin123)
INSERT INTO users (name, email, password_hash, role) VALUES
('Admin',        'admin@pos.local', '$2y$10$YourHashHere.ReplaceWithPHPHashOf.admin123ForSecurity', 'admin'),
('Jane Smith',   'jane@pos.local',  '$2y$10$YourHashHere.ReplaceWithPHPHashOf.admin123ForSecurity', 'cashier'),
('Bob Waiter',   'bob@pos.local',   '$2y$10$YourHashHere.ReplaceWithPHPHashOf.admin123ForSecurity', 'waiter'),
('Chef Mario',   'mario@pos.local', '$2y$10$YourHashHere.ReplaceWithPHPHashOf.admin123ForSecurity', 'kitchen');

-- Restaurant Tables
INSERT INTO restaurant_tables (table_number, section, capacity, status) VALUES
('T1',  'Main Hall', 2, 'open'), ('T2',  'Main Hall', 4, 'open'),
('T3',  'Main Hall', 4, 'open'), ('T4',  'Main Hall', 6, 'open'),
('T5',  'Main Hall', 2, 'open'), ('T6',  'Main Hall', 4, 'open'),
('T7',  'Main Hall', 8, 'open'), ('T8',  'Main Hall', 4, 'open'),
('T9',  'Main Hall', 2, 'open'), ('T10', 'Main Hall', 6, 'open'),
('B1',  'Bar',       2, 'open'), ('B2',  'Bar',       2, 'open'),
('B3',  'Bar',       2, 'open'), ('B4',  'Bar',       4, 'open'),
('P1',  'Patio',     4, 'open'), ('P2',  'Patio',     4, 'open'),
('P3',  'Patio',     6, 'open'), ('P4',  'Patio',     2, 'open');

-- Categories
INSERT INTO categories (name, description, icon, sort_order) VALUES
('Appetizers',    'Starters and small bites',      'leaf',       1),
('Main Course',   'Hearty entrees and mains',      'fire',       2),
('Pasta & Pizza', 'Italian classics',              'pizza-slice',3),
('Grills & BBQ',  'Grilled meats and skewers',     'fire-flame', 4),
('Salads',        'Fresh garden salads',           'seedling',   5),
('Desserts',      'Sweet endings',                 'cake-candles',6),
('Drinks',        'Beverages and cocktails',       'wine-glass', 7),
('Kids Menu',     'Fun meals for little ones',     'star',       8);

-- Inventory
INSERT INTO inventory (name, unit, current_stock, min_alert_level, cost_per_unit) VALUES
('Chicken Breast',       'kg',     15.000,  5.000,  8.50),
('Beef Sirloin',         'kg',      8.000,  3.000, 22.00),
('Salmon Fillet',        'kg',      6.000,  2.000, 18.00),
('Pasta (Dry)',          'kg',     20.000,  5.000,  2.50),
('Pizza Dough',          'pcs',    30.000, 10.000,  1.20),
('Tomato Sauce',         'liters', 12.000,  4.000,  3.00),
('Mozzarella Cheese',    'kg',      7.000,  2.000,  9.00),
('Mixed Greens',         'kg',      4.000,  1.500,  4.50),
('Eggs',                 'pcs',   120.000, 24.000,  0.30),
('Flour',                'kg',     25.000,  8.000,  1.00),
('Olive Oil',            'liters',  5.000,  2.000, 12.00),
('Coffee Beans',         'kg',      3.000,  1.000, 15.00),
('Milk',                 'liters', 10.000,  3.000,  1.80),
('French Fries (Frozen)','kg',     12.000,  4.000,  2.20),
('Beef Mince',           'kg',      9.000,  3.000, 12.00);

-- Menu Items
INSERT INTO menu_items (category_id, name, description, price, is_available, track_stock, stock_count) VALUES
-- Appetizers (cat 1)
(1,'Garlic Bread',          'Toasted baguette with garlic butter and herbs',             4.50, 1, 0, NULL),
(1,'Calamari Rings',        'Crispy fried squid rings with marinara dip',                9.50, 1, 0, NULL),
(1,'Spring Rolls (4pc)',    'Crispy vegetable spring rolls with sweet chili',            7.00, 1, 0, NULL),
(1,'Bruschetta',            'Toasted ciabatta with tomato, basil & olive oil',           6.50, 1, 0, NULL),
(1,'Chicken Wings (6pc)',   'Spicy buffalo wings with ranch dip',                       10.50, 1, 0, NULL),
-- Main Course (cat 2)
(2,'Grilled Chicken',       'Herb-marinated chicken breast with seasonal vegetables',   16.90, 1, 1, 20),
(2,'Beef Sirloin Steak',    '200g sirloin, medium-rare, with fries & salad',           28.50, 1, 1, 15),
(2,'Pan-Seared Salmon',     'Salmon fillet, lemon butter sauce, seasonal veg',         24.00, 1, 1, 12),
(2,'Chicken Parmesan',      'Breaded chicken, tomato sauce, melted mozzarella',        18.50, 1, 0, NULL),
(2,'Fish & Chips',          'Beer-battered fish, crispy fries, tartare sauce',         17.50, 1, 0, NULL),
-- Pasta & Pizza (cat 3)
(3,'Spaghetti Bolognese',   'Classic beef ragù with fresh parmesan',                   14.50, 1, 0, NULL),
(3,'Fettuccine Alfredo',    'Creamy parmesan sauce with fettuccine and herbs',         13.50, 1, 0, NULL),
(3,'Margherita Pizza 12"',  'San Marzano tomato, fresh mozzarella, basil',             13.00, 1, 0, NULL),
(3,'Pepperoni Pizza 12"',   'Tomato sauce, mozzarella, premium pepperoni',             15.00, 1, 0, NULL),
(3,'BBQ Chicken Pizza 12"', 'BBQ sauce, grilled chicken, red onion, mozzarella',      16.00, 1, 0, NULL),
-- Grills & BBQ (cat 4)
(4,'Mixed Grill Platter',   'Chicken, beef & lamb skewers with rice and salad',        32.00, 1, 0, NULL),
(4,'BBQ Ribs (Half Rack)',  'Slow-cooked pork ribs with BBQ glaze and coleslaw',      24.50, 1, 0, NULL),
(4,'Lamb Chops (3pc)',      'Grilled lamb chops with roasted garlic and mint yogurt',  26.00, 1, 0, NULL),
-- Salads (cat 5)
(5,'Caesar Salad',          'Romaine, croutons, parmesan, house Caesar dressing',      10.50, 1, 0, NULL),
(5,'Greek Salad',           'Tomato, cucumber, olives, feta cheese, oregano',          9.50, 1, 0, NULL),
(5,'Grilled Chicken Salad', 'Mixed greens, grilled chicken strips, balsamic glaze',   12.50, 1, 0, NULL),
-- Desserts (cat 6)
(6,'Chocolate Lava Cake',   'Warm dark chocolate cake with vanilla ice cream',          8.50, 1, 0, NULL),
(6,'Tiramisu',              'Classic Italian espresso and mascarpone dessert',          7.50, 1, 0, NULL),
(6,'New York Cheesecake',   'Creamy cheesecake with seasonal berry compote',            7.00, 1, 0, NULL),
(6,'Ice Cream (3 Scoops)',  'Vanilla, chocolate, or strawberry — your choice',          6.00, 1, 0, NULL),
-- Drinks (cat 7)
(7,'Soft Drink',            'Coke, Diet Coke, Sprite, Fanta — 330ml',                  2.50, 1, 0, NULL),
(7,'Fresh Orange Juice',    'Freshly squeezed, served over ice',                        4.50, 1, 0, NULL),
(7,'Mineral Water',         'Still or sparkling 500ml',                                 2.00, 1, 0, NULL),
(7,'Coffee',                'Espresso, Americano, Latte, or Cappuccino',                3.50, 1, 0, NULL),
(7,'Tea',                   'English Breakfast, Green Tea, or Chamomile',               2.50, 1, 0, NULL),
(7,'Fresh Lemonade',        'House-made lemonade with mint and sugar syrup',            4.00, 1, 0, NULL),
-- Kids Menu (cat 8)
(8,'Kids Burger & Fries',   'Mini beef burger with small portion fries',                9.50, 1, 0, NULL),
(8,'Kids Pasta',            'Spaghetti with tomato or butter sauce',                    8.00, 1, 0, NULL),
(8,'Kids Ice Cream Cup',    '2 scoops with chocolate sprinkles',                        4.50, 1, 0, NULL);

-- Recipe ingredient mappings
INSERT INTO menu_item_ingredients (menu_item_id, inventory_id, quantity_needed) VALUES
-- Grilled Chicken (item 6) → Chicken Breast
(6,  1, 0.250),
-- Beef Sirloin Steak (item 7) → Beef Sirloin + Fries
(7,  2, 0.220), (7, 14, 0.150),
-- Pan-Seared Salmon (item 8) → Salmon Fillet
(8,  3, 0.200),
-- Spaghetti Bolognese (item 11) → Pasta + Beef Mince
(11, 4, 0.150), (11, 15, 0.120),
-- Fettuccine Alfredo (item 12) → Pasta
(12, 4, 0.150),
-- Margherita Pizza (item 13) → Dough + Sauce + Mozzarella
(13, 5, 1.000), (13, 6, 0.150), (13, 7, 0.120),
-- Pepperoni Pizza (item 14) → Dough + Sauce + Mozzarella
(14, 5, 1.000), (14, 6, 0.150), (14, 7, 0.150),
-- BBQ Chicken Pizza (item 15) → Dough + Mozzarella + Chicken
(15, 5, 1.000), (15, 7, 0.120), (15, 1, 0.100),
-- Coffee (item 29) → Coffee Beans + Milk
(29, 12, 0.018), (29, 13, 0.150);
