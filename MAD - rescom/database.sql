-- RESCOM Database Schema
-- Create database
CREATE DATABASE IF NOT EXISTS rescom_database;
USE rescom_database;

-- Users table (for reporters)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    mobile VARCHAR(15) NOT NULL,
    credit_points INT DEFAULT 0,
    demerit_points INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Mapping of reports to notified organizations, with computed distance
CREATE TABLE report_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_id INT NOT NULL,
    organization_id INT NOT NULL,
    distance_km DECIMAL(8,3) NULL,
    notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('queued','sent','delivered') DEFAULT 'queued',
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

-- Volunteers table
CREATE TABLE volunteers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    organization VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Organizations table
CREATE TABLE organizations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    address TEXT,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    contact_info JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Reports table
CREATE TABLE reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    type ENUM('human', 'animal') NOT NULL,
    category ENUM('abandoned', 'injured', 'mentally_ill', 'others') NOT NULL,
    location TEXT NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    description TEXT NOT NULL,
    incident_datetime DATETIME NOT NULL,
    status ENUM('pending', 'accepted', 'processing', 'completed', 'rejected') DEFAULT 'pending',
    image_path VARCHAR(500),
    assigned_volunteer_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_volunteer_id) REFERENCES volunteers(id) ON DELETE SET NULL
);

-- Donations table
CREATE TABLE donations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    mobile VARCHAR(15) NOT NULL,
    email VARCHAR(255) NOT NULL,
    blood_group ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    address TEXT NOT NULL,
    aadhar_files JSON,
    medical_files JSON,
    status ENUM('pending_review', 'approved', 'rejected') DEFAULT 'pending_review',
    reviewed_by INT,
    reviewed_at TIMESTAMP NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewed_by) REFERENCES volunteers(id) ON DELETE SET NULL
);

-- Messages table
CREATE TABLE messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    from_volunteer_id INT NOT NULL,
    to_organization_id INT NOT NULL,
    message TEXT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_read BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (from_volunteer_id) REFERENCES volunteers(id) ON DELETE CASCADE,
    FOREIGN KEY (to_organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

-- Report status history table
CREATE TABLE report_status_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_id INT NOT NULL,
    old_status ENUM('pending', 'accepted', 'processing', 'completed', 'rejected'),
    new_status ENUM('pending', 'accepted', 'processing', 'completed', 'rejected') NOT NULL,
    changed_by INT NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES volunteers(id) ON DELETE CASCADE
);

-- Insert sample organizations
INSERT INTO organizations (name, description, address, contact_info) VALUES
('Animal Rescue Foundation', 'Dedicated to rescuing and rehabilitating animals in distress', '12 Lake View Road, Central Park Area, City', '{"phone": "+91-9876543210", "email": "contact@arf.org"}'),
('Humanitarian Aid Society', 'Providing aid and support to humans in crisis situations', '44 Relief Avenue, Downtown, City', '{"phone": "+91-9876543211", "email": "help@has.org"}'),
('Community Support Network', 'Building stronger communities through support and care', '8 Community Lane, North District, City', '{"phone": "+91-9876543212", "email": "support@csn.org"}'),
('Emergency Response Team', 'Rapid response team for emergency situations', '99 Response Street, East Side, City', '{"phone": "+91-9876543213", "email": "response@ert.org"}');

-- Insert sample volunteers
INSERT INTO volunteers (username, password, organization) VALUES
('volunteer1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Animal Rescue Foundation'), -- password: password
('volunteer2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Humanitarian Aid Society'), -- password: password
('admin_volunteer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Emergency Response Team'); -- password: password

-- Insert sample users
INSERT INTO users (email, username, mobile, credit_points, demerit_points) VALUES
('john@example.com', 'john_doe', '9876543210', 350, 0),
('jane@example.com', 'jane_smith', '9876543211', 450, 0),
('mike@example.com', 'mike_wilson', '9876543212', 200, 0),
('sarah@example.com', 'sarah_jones', '9876543213', 600, 0);

-- Insert sample reports
INSERT INTO reports (user_id, type, category, location, description, incident_datetime, status) VALUES
(1, 'animal', 'injured', 'Central Park', 'Found injured dog near the lake', '2023-06-22 14:30:00', 'pending'),
(2, 'human', 'mentally_ill', 'Main Street', 'Person showing signs of mental distress', '2023-06-21 16:45:00', 'accepted'),
(3, 'animal', 'abandoned', 'North District', 'Abandoned kittens found in alley', '2023-06-20 10:15:00', 'completed'),
(4, 'human', 'others', 'East Side', 'Elderly person needs assistance', '2023-06-19 09:20:00', 'pending');

-- Insert sample donations
INSERT INTO donations (name, mobile, email, blood_group, address, status) VALUES
('Rajesh Kumar', '9876543214', 'rajesh@example.com', 'B+', '123 Main Street, City', 'approved'),
('Priya Sharma', '9876543215', 'priya@example.com', 'O+', '456 Oak Avenue, City', 'pending_review'),
('Amit Patel', '9876543216', 'amit@example.com', 'AB-', '789 Pine Road, City', 'rejected');

-- Insert sample messages
INSERT INTO messages (from_volunteer_id, to_organization_id, message, is_read) VALUES
(1, 1, 'Hello, we have a new animal rescue case in your area.', TRUE),
(1, 1, 'Thanks for letting us know. We can dispatch a team by noon.', TRUE),
(2, 2, 'We need assistance with a homeless shelter project.', TRUE),
(2, 2, 'We can provide volunteers for next weekend.', TRUE);
