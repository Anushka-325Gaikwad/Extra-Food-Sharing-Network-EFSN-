-- Extra Food Sharing Network (EFSN) Database Schema
-- Place in C:\Users\Admin\Desktop\EXTRA FOO\database.sql

CREATE DATABASE IF NOT EXISTS efsn_db;
USE efsn_db;

-- 1. Users Table (Handles Donor, NGO, Volunteer roles)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('donor', 'ngo', 'volunteer') NOT NULL,
    location VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Food Listings Table (Donor's offered surplus food)
CREATE TABLE IF NOT EXISTS food_listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    food_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL,
    expiry_time DATETIME NOT NULL,
    location VARCHAR(255) NOT NULL,
    donor_id INT NOT NULL,
    status ENUM('available', 'claimed', 'picked_up', 'expired') DEFAULT 'available',
    expiry_notified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (food_name),
    INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. NGO Requirements/Requests Table (Demand posting when supplies aren't immediately found)
CREATE TABLE IF NOT EXISTS requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ngo_id INT NOT NULL,
    food_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL,
    status ENUM('open', 'matched', 'completed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ngo_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Claims & Deliveries Table (Links a claimed food listing to the claiming NGO and delivering Volunteer)
CREATE TABLE IF NOT EXISTS claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    food_listing_id INT NOT NULL,
    ngo_id INT NOT NULL,
    volunteer_id INT DEFAULT NULL,
    status ENUM('claimed', 'ready_for_pickup', 'delivering', 'delivered') DEFAULT 'claimed',
    quantity INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (food_listing_id) REFERENCES food_listings(id) ON DELETE CASCADE,
    FOREIGN KEY (ngo_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (volunteer_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Notifications Table (Real-time and historic dashboard notifications)
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (user_id),
    INDEX (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Rewards Table (Gamified points system for Donors and Volunteers)
CREATE TABLE IF NOT EXISTS rewards (
    user_id INT PRIMARY KEY,
    points INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
