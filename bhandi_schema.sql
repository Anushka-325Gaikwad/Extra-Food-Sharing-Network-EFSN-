-- Database Schema Extension for Container (Bhandi) Management, Time Tracking & Ratings
-- Compatible with EFSN efsn_db

USE efsn_db;

-- 1. Modify users table role enum to allow logistics and admin
ALTER TABLE users MODIFY COLUMN role ENUM('donor', 'ngo', 'volunteer', 'logistics', 'admin') NOT NULL;

-- 1.1 Add donor_type column to users table and initialize defaults
ALTER TABLE users ADD COLUMN donor_type VARCHAR(50) DEFAULT NULL;
UPDATE users SET donor_type = 'Restaurant' WHERE role = 'donor' AND donor_type IS NULL;

-- 1.2 Add profile_pic column to users table for avatar picture uploads
ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) DEFAULT NULL;


-- 2. Container Inventory Table
CREATE TABLE IF NOT EXISTS bhandi_containers (
    container_id VARCHAR(50) PRIMARY KEY,
    size ENUM('Small', 'Medium', 'Large') NOT NULL,
    status ENUM('Available', 'In Use', 'Cleaning', 'Overdue') DEFAULT 'Available',
    times_used INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Container Assignments Table (tracks start, expiry, and countdowns)
CREATE TABLE IF NOT EXISTS bhandi_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    container_id VARCHAR(50) NOT NULL,
    food_listing_id INT NOT NULL,
    volunteer_id INT DEFAULT NULL,
    logistics_partner_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    expiry_time DATETIME NOT NULL,
    status ENUM('Active', 'Returned', 'Overdue') DEFAULT 'Active',
    FOREIGN KEY (container_id) REFERENCES bhandi_containers(container_id) ON DELETE CASCADE,
    FOREIGN KEY (food_listing_id) REFERENCES food_listings(id) ON DELETE CASCADE,
    FOREIGN KEY (volunteer_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (logistics_partner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Food Listing Container Requirements Table
CREATE TABLE IF NOT EXISTS bhandi_food_requirements (
    food_listing_id INT PRIMARY KEY,
    needs_containers TINYINT(1) DEFAULT 0,
    container_size ENUM('Small', 'Medium', 'Large') DEFAULT 'Medium',
    assigned_partner_id INT DEFAULT NULL,
    FOREIGN KEY (food_listing_id) REFERENCES food_listings(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_partner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Two-way Feedback & Rating Table
CREATE TABLE IF NOT EXISTS bhandi_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT NOT NULL,
    from_user_id INT NOT NULL,
    to_user_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    feedback TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Insert some sample mock containers if empty
INSERT IGNORE INTO bhandi_containers (container_id, size, status, times_used) VALUES
('C-SMALL-101', 'Small', 'Available', 4),
('C-SMALL-102', 'Small', 'Available', 2),
('C-MED-201', 'Medium', 'Available', 8),
('C-MED-202', 'Medium', 'Available', 5),
('C-MED-203', 'Medium', 'Cleaning', 12),
('C-LARGE-301', 'Large', 'Available', 15),
('C-LARGE-302', 'Large', 'Available', 3);
