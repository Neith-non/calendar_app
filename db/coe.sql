CREATE DATABASE calendar_of_event;
use calendar_of_event;
-- 1. Create independent tables


CREATE TABLE roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL
);

CREATE TABLE venues (
    venue_id INT AUTO_INCREMENT PRIMARY KEY,
    venue_name VARCHAR(100) NOT NULL,
    is_off_campus BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE event_categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    category_type VARCHAR(50) NOT NULL
);

-- 2. Create users table

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    username VARCHAR(50) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
);

-- 3. Create the event_publish table (For events requiring approval)

CREATE TABLE event_publish (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    status ENUM('Pending', 'Approved', 'Rejected', 'Canceled') NOT NULL DEFAULT 'Pending',
    approved_by INT NULL, 
    approved_date DATETIME NULL, 
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (approved_by) REFERENCES users(user_id),
    FOREIGN KEY (venue_id) REFERENCES venues(venue_id)
);

-- 4. Create the events table (The specific schedules & standalone holidays)

CREATE TABLE events (
    event_id INT AUTO_INCREMENT PRIMARY KEY,
    publish_id INT NULL,          -- Changed to NULL for Holidays/Standalone events!
    category_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    start_date DATE NOT NULL,
    start_time TIME NOT NULL,
    published_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES event_categories(category_id),
    FOREIGN KEY (publish_id) REFERENCES event_publish(id) ON DELETE CASCADE
);


ALTER TABLE events ADD UNIQUE INDEX idx_unique_event (title, start_date);

INSERT INTO event_categories (category_id, category_name, category_type) VALUES 
(1, 'Curricular', 'Academic'),
(2, 'Extra-Curricular', 'Activities'),
(3, 'Mass', 'Religious'),
(4, 'Staff Meetings', 'Administrative'),
(5, 'Holidays', 'General');

ALTER TABLE events 
ADD COLUMN end_date DATE NULL AFTER start_date,
ADD COLUMN end_time TIME NULL AFTER start_time;

ALTER TABLE event_publish ADD COLUMN description TEXT NULL AFTER title;
ALTER TABLE events ADD COLUMN description TEXT NULL AFTER title;

-- 1. Add the missing password column to your users table
ALTER TABLE users ADD COLUMN password VARCHAR(255) NOT NULL AFTER username;

-- 2. Create the Admin role (Assuming your roles table has a role_name column)
INSERT IGNORE INTO roles (role_id, role_name) VALUES (1, 'Admin');

-- 3. Create our first actual user (Ma'am Reyes) linked to Role #1
INSERT IGNORE INTO users (user_id, role_id, username, password, full_name) 
VALUES (1, 1, 'admin', 'password123', 'Ma''am Reyes');

-- 1. Add the Head Scheduler and Viewer (Principal) roles
INSERT IGNORE INTO roles (role_id, role_name) VALUES 
(2, 'Head Scheduler'),
(3, 'Viewer');

-- 2. Create a test user for the Head Scheduler (Linked to role_id 2)
INSERT IGNORE INTO users (role_id, username, password, full_name) 
VALUES (2, 'scheduler', 'password123', 'Mr. Head Scheduler');

-- 3. Create a test user for the Principal / Viewer (Linked to role_id 3)
INSERT IGNORE INTO users (role_id, username, password, full_name) 
VALUES (3, 'principal', 'password123', 'Principal Viewer');