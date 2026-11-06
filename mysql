CREATE DATABASE IF NOT EXISTS apex_marine;

USE apex_marine;

-- =========================================================
-- 1. INDEPENDENT / PARENT TABLES
-- =========================================================

CREATE TABLE users (
    user_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('client', 'engineer', 'admin') DEFAULT 'client',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


CREATE TABLE operational_hubs (
    hub_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    hub_name VARCHAR(100) NOT NULL,
    hub_type VARCHAR(50) DEFAULT 'Standard'
) ENGINE=InnoDB;


-- =========================================================
-- 2. PROFILE TABLES
-- =========================================================

CREATE TABLE client_profiles (
    client_id INT NOT NULL PRIMARY KEY,
    company_name VARCHAR(150) DEFAULT NULL,
    contact_number VARCHAR(50) DEFAULT NULL
) ENGINE=InnoDB;


CREATE TABLE engineer_profiles (
    engineer_id INT NOT NULL PRIMARY KEY,
    specialty VARCHAR(100) NOT NULL,
    current_status ENUM('available', 'deployed', 'on_leave')
        DEFAULT 'available'
) ENGINE=InnoDB;


-- =========================================================
-- 3. SERVICE LOCATIONS
-- =========================================================

CREATE TABLE service_locations (
    location_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    hub_id INT NOT NULL,
    port_name VARCHAR(100) NOT NULL,

    CONSTRAINT fk_service_location_hub
        FOREIGN KEY (hub_id)
        REFERENCES operational_hubs(hub_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;


-- =========================================================
-- 4. DISPATCH REQUESTS
-- =========================================================

CREATE TABLE dispatch_requests (
    request_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    vessel_name VARCHAR(100) NOT NULL,
    imo_number VARCHAR(50) NOT NULL,
    vessel_type VARCHAR(100) NOT NULL,
    company VARCHAR(100) DEFAULT NULL,
    contact_person VARCHAR(100) NOT NULL,
    contact_phone VARCHAR(50) NOT NULL,
    eta_date DATE NOT NULL,
    service_type VARCHAR(100) NOT NULL,
    is_urgent TINYINT(1) DEFAULT 0,
    location_id INT NOT NULL,
    description TEXT NOT NULL,
    attachment_path VARCHAR(255) DEFAULT NULL,
    status ENUM(
        'pending',
        'acknowledged',
        'deployed',
        'in_progress',
        'resolved',
        'cancelled'
    ) DEFAULT 'pending',
    is_active TINYINT(1) DEFAULT 1,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_dispatch_client
        FOREIGN KEY (client_id)
        REFERENCES client_profiles(client_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_dispatch_location
        FOREIGN KEY (location_id)
        REFERENCES service_locations(location_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;


-- =========================================================
-- 5. DEPLOYMENTS
-- =========================================================

CREATE TABLE deployments (
    deployment_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL UNIQUE,
    admin_id INT NOT NULL,
    engineer_id INT NOT NULL,
    deployment_status ENUM(
        'en_route',
        'on_site',
        'completed'
    ) DEFAULT 'en_route',
    deployed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_deployment_request
        FOREIGN KEY (request_id)
        REFERENCES dispatch_requests(request_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_deployment_admin
        FOREIGN KEY (admin_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_deployment_engineer
        FOREIGN KEY (engineer_id)
        REFERENCES engineer_profiles(engineer_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;


-- =========================================================
-- 6. INVOICES
-- =========================================================

CREATE TABLE invoices (
    invoice_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    client_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    payment_method VARCHAR(50) DEFAULT NULL,
    reference_number VARCHAR(100) DEFAULT NULL,
    payment_status ENUM(
        'unpaid',
        'paid',
        'failed'
    ) DEFAULT 'unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_invoice_request
        FOREIGN KEY (request_id)
        REFERENCES dispatch_requests(request_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_invoice_client
        FOREIGN KEY (client_id)
        REFERENCES client_profiles(client_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;


-- =========================================================
-- 7. OPERATION UPDATES
-- =========================================================

CREATE TABLE operation_updates (
    update_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    updater_id INT NOT NULL,
    status_milestone VARCHAR(100) NOT NULL,
    detailed_message TEXT NOT NULL,
    attachment_path VARCHAR(255) DEFAULT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_update_request
        FOREIGN KEY (request_id)
        REFERENCES dispatch_requests(request_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_update_user
        FOREIGN KEY (updater_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;