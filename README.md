# Extra-Food-Sharing-Network-EFSN-
A Smart Platform for Surplus Food Donation, NGO Coordination, Volunteer Management and Food Logistics
# 🍲 Extra Food Sharing Network (EFSN)


>Minimize Food Waste. Maximize Human Support. 
>Extra Food Sharing Network (EFSN)** is a full-stack web application designed to rescue surplus food from restaurants, event caterers, and households, connecting donors in real-time with local NGOs, shelters, volunteer distributors, and logistics management networks. Featuring **Bhandi Reusable Container Management**, **Real-Time Expiry Engine**, **Interactive Map Tracking**, and a **Gamified Community Rewards System**.



✨ Key Features

👥 5-Tier Role-Based Access Control
- Donors (Restaurants, Caterers, Individuals): Post surplus food listings, manage container preferences, track donations, and earn reward points.
- NGOs & Shelters: Browse available food listings, filter by location/quantity, post specific food demand requests, and submit feedback/ratings.
- Volunteers: View nearby delivery jobs, accept pick-up & delivery tasks, earn points, and generate downloadable certificates.
- Logistics Partners: Manage reusable food container ("Bhandi") inventories, assign containers, track cleaning cycles, and manage returns.
- System Administrators: Centralized control panel to view platform metrics, manage users, audit container lifecycles, run database diagnostics, and resolve issues.

♻️ "Bhandi" Reusable Container Management System
- Eco-friendly container dispatching system (Small, Medium, Large containers) to eliminate single-use plastic waste.
- Real-time status tracking (`Available`, `In Use`, `Cleaning`, `Overdue`).
- Container usage history, cleaning cycle maintenance, and automated return countdown timers.

⏱️ Automated Real-Time Expiry Engine
- Automatic background inspection (`runExpiryCheck()` & `cron_expiry.php`).
- Sends instant notifications to donors 2 hours prior to food expiration.
- Automatically marks unclaimed expired food listings as `expired` to maintain safety and compliance.

🗺️ Interactive Live Map
- Visualized map interface (`bhandi_map.php`) rendering active food listings, claim statuses, delivery routes, and container hubs in real time.

🏆 Gamification & Recognition
- **Leaderboard**: Global community leaderboard displaying top food donors and active volunteers.
- **Reward Points**: Donors and volunteers earn points upon successful delivery completions.
- **Downloadable Certificates**: Automated generation of official impact certificates (`certificate.php`) for volunteers and donors.

🔔 Real-Time Notification & Feedback System
- In-app notification center for instant alerts regarding claim updates, delivery statuses, container assignments, and expiry warnings.
- Two-way rating & review system (1 to 5 stars) between NGOs, donors, and volunteers.

---

🏗️ System Architecture & User Roles

mermaid
graph TD
    Donor[🍲 Donor] -->|Posts Surplus Food| Listings[Food Listings]
    NGO[🏠 NGO / Shelter] -->|Claims Food Listing| Claims[Claims & Logistics]
    NGO -->|Posts Food Need| Requests[Food Requests]
    Volunteer[🚴 Volunteer] -->|Delivers Food| Claims
    Logistics[📦 Logistics Partner] -->|Assigns Containers| Bhandi[Bhandi Container System]
    Bhandi -->|Tracks Usage| Claims
    Admin[👑 Administrator] -->|Monitors & Audits| System[EFSN Platform & Database]


🛠️ Tech Stack

- Backend**: PHP 7.4+ / 8.x 
- Database: MySQL 5.7+ / MariaDB 
- Frontend: HTML5, Vanilla JavaScript (ES6+), Modern CSS3 
- Web Server: Apache Web Server 
- Data Interchange: JSON via RESTful AJAX endpoints (`api.php`)



 📁 Directory & File Structure


EXTRA FOO/
│
├── 📄 config.php              # Core DB PDO connection, global helpers, notification & expiry logic
├── 📄 header.php              # Shared responsive header, navigation bar, & notification dropdown
├── 📄 style.css               # Global glassmorphic CSS stylesheet & UI design system
├── 📄 index.php               # Landing page with live impact counter & community leaderboard
├── 📄 login.php               # User registration & login portal (Password hashing & role selection)
│
├── 📄 dashboard.php           # Role-based multi-dashboard (Donor, NGO, Volunteer, Logistics, Admin)
├── 📄 add_food.php            # Form interface for donors to list surplus food & container needs
├── 📄 search.php             # Search & filter catalog for available surplus food items
├── 📄 bhandi_map.php          # Live map visualization of food locations & container routes
├── 📄 bhandi_profile.php      # User profile page, avatar upload, & personal metrics
├── 📄 bhandi_logistics.php    # Container inventory management & assignment panel for logistics
├── 📄 bhandi_admin.php        # System administrator dashboard & user management hub
├── 📄 bhandi_feedback.php     # Two-way rating & feedback submission interface
├── 📄 bhandi_donor_ratings.php# Donor ratings & reputation overview
├── 📄 certificate.php         # Automated printable certificate generator
│
├── 📄 api.php                 # AJAX API endpoint for live notifications, map data & status updates
├── 📄 bhandi_prepend.php      # Container management middleware & countdown helpers
├── 📄 cron_expiry.php         # CLI / Scheduled cron task script for expiry monitoring
├── 📄 demo_notify.php         # Notification simulation script for testing
├── 📄 repair_db.php           # Database auto-repair & schema check utility
├── 📄 super_sync.php          # Platform data sync utility
├── 📄 test_db.php             # Database connectivity test script
│
├── 🗄️ database.sql            # Primary database tables schema (users, food_listings, claims, etc.)
└── 🗄️ bhandi_schema.sql       # Bhandi container management schema extension & sample data




🗄️ Database Schema

The platform runs on MySQL database `efsn_db`. Core tables include:


| `users` | User accounts storing credentials, role (`donor`, `ngo`, `volunteer`, `logistics`, `admin`), profile pic, and donor type. |
| `food_listings` | Surplus food posted by donors with quantity, location, expiry time, status, and container requirements. |
| `requests` | Food demand listings posted by NGOs when supplies are needed. |
| `claims` | Tracks food claims connecting listings, claiming NGOs, and delivering volunteers. |
| `bhandi_containers` | Reusable container inventory (`container_id`, `size`, `status`, `times_used`). |
| `bhandi_assignments` | Active container assignments linking containers, food listings, volunteers, and expiry tracking. |
| `bhandi_food_requirements` | Container requirements specified by donors per food listing. |
| `bhandi_ratings` | 5-star ratings and text feedback between users for completed deliveries. |
| `notifications` | User notifications with read/unread statuses. |
| `rewards` | Gamification point tracking per user. |


⚙️ Installation & Local Setup Guide

Prerequisites
- Install [XAMPP](https://www.apachefriends.org/)
 Step-by-Step Instructions

1. Clone or Copy Repository:

2.Start Web Server & Database**:
   - Open XAMPP Control Panel
   - Click **Start** for **Apache** and **MySQL**.

3. Import Database Schema:
   - Open phpMyAdmin in your browser: `http://localhost/phpmyadmin/`
   - Create a database named `efsn_db` (or allow `config.php` to automatically create it upon first request).
   - Import `database.sql` first.
   - Import `bhandi_schema.sql` second to apply container management tables & sample data.
   
   Or using MySQL Command Line:
   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS efsn_db;"
   mysql -u root -p efsn_db < "C:\xampp\htdocs\EXTRA FOO\database.sql"
   mysql -u root -p efsn_db < "C:\xampp\htdocs\EXTRA FOO\bhandi_schema.sql"
   ```

4. Verify Database Configuration:
   Check `config.php` to match your local MySQL credentials if non-default:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_PORT', '3306');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('DB_NAME', 'efsn_db');
   ```

5. Launch Application:
   Navigate to the following URL in your web browser:
   
   http://localhost/EXTRA%20FOO/


🌐 REST / AJAX API Documentation

The platform provides JSON endpoints via `api.php` for dynamic frontend interactions:

| Action Parameter | Method | Description |
| :--- | :--- | :--- |
| `?action=get_notifications` | `GET` | Fetch unread notifications for the logged-in user |
| `?action=mark_notifications_read` | `POST` | Mark notifications as read |
| `?action=get_map_data` | `GET` | Fetch active food listings, claim statuses, and container map coordinates |
| `?action=update_claim_status` | `POST` | Update status of a food delivery claim (`claimed`, `delivering`, `delivered`) |
| `?action=get_container_status` | `GET` | Fetch real-time Bhandi container inventory statuses |


 🔒 Security Implementation

- Prepared SQL Statements: All database operations utilize PDO prepared statements with parameterized queries to prevent SQL Injection (SQLi).
- Password Security: Passwords are securely hashed using PHP `password_hash()` (Bcrypt) and validated via `password_verify()`.
- XSS Protection: Output escaping using `htmlspecialchars()` across templates.
- Session Security: Session verification and role checking implemented across dashboard pages to block unauthorized access.




<p center="align">
  <b>Extra Food Sharing Network (EFSN)</b> — Connecting Hearts, Rescuing Food, Sustaining Communities.
</p>
