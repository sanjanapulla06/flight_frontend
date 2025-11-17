-- Migration 001: Add missing tables and columns to match ER diagram
-- This is BACKWARD COMPATIBLE - adds new tables/columns without breaking existing code
-- Run this AFTER backing up your database

USE airport_demo;

-- 1. Add airport_airline (CONTAINS relationship)
CREATE TABLE IF NOT EXISTS airport_airline (
    ap_name VARCHAR(100) NOT NULL,
    airlineid INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ap_name, airlineid),
    FOREIGN KEY (airlineid) REFERENCES airline(airline_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Add missing PASSENGER columns (keeps existing 'name' for compatibility)
ALTER TABLE passenger 
    ADD COLUMN IF NOT EXISTS pid INT AUTO_INCREMENT UNIQUE AFTER passport_no,
    ADD COLUMN IF NOT EXISTS fname VARCHAR(50) AFTER pid,
    ADD COLUMN IF NOT EXISTS mname VARCHAR(50) AFTER fname,
    ADD COLUMN IF NOT EXISTS lname VARCHAR(50) AFTER mname,
    ADD COLUMN IF NOT EXISTS age INT AFTER address,
    ADD COLUMN IF NOT EXISTS sex CHAR(1) AFTER age;

-- Populate fname/lname from existing 'name' column if present
UPDATE passenger 
SET fname = SUBSTRING_INDEX(name, ' ', 1),
    lname = SUBSTRING_INDEX(name, ' ', -1)
WHERE fname IS NULL AND name IS NOT NULL;

-- 3. Add TICKET missing columns (keeps existing structure)
ALTER TABLE ticket
    ADD COLUMN IF NOT EXISTS surcharge DECIMAL(10,2) DEFAULT 0.00 AFTER price,
    ADD COLUMN IF NOT EXISTS date_of_booking DATE AFTER booking_date,
    ADD COLUMN IF NOT EXISTS date_of_travel DATE AFTER date_of_booking;

-- Populate date fields from existing data
UPDATE ticket t
LEFT JOIN bookings b ON t.booking_id = b.booking_id
SET t.date_of_booking = DATE(b.booking_date)
WHERE t.date_of_booking IS NULL AND b.booking_date IS NOT NULL;

UPDATE ticket t
LEFT JOIN flight f ON t.flight_id = f.flight_id
SET t.date_of_travel = DATE(f.departure_time)
WHERE t.date_of_travel IS NULL AND f.departure_time IS NOT NULL;

-- 4. Add PMR table (Passengers requiring special assistance)
CREATE TABLE IF NOT EXISTS pmr (
    ssn VARCHAR(20) PRIMARY KEY,
    fname VARCHAR(50),
    mname VARCHAR(50),
    lname VARCHAR(50),
    age INT,
    contact VARCHAR(15),
    assistance TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Add SERVES relationship table (Employee serves Passenger)
CREATE TABLE IF NOT EXISTS serves (
    ssn VARCHAR(20) NOT NULL,
    pid INT,
    passportno VARCHAR(20),
    service_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    service_type VARCHAR(50),
    PRIMARY KEY (ssn, passportno),
    FOREIGN KEY (passportno) REFERENCES passenger(passport_no) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Add MONITORS relationship table (ATC monitors flight)
CREATE TABLE IF NOT EXISTS monitors (
    ssn VARCHAR(20) NOT NULL,
    flight_id VARCHAR(20) NOT NULL,
    monitor_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(50),
    PRIMARY KEY (ssn, flight_id),
    FOREIGN KEY (flight_id) REFERENCES flight(flight_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Add FLIGHT columns for NON-STOP vs CONNECTING distinction
ALTER TABLE flight
    ADD COLUMN IF NOT EXISTS no_of_stops INT DEFAULT 0 AFTER duration,
    ADD COLUMN IF NOT EXISTS layover_time TIME AFTER no_of_stops,
    ADD COLUMN IF NOT EXISTS flight_type ENUM('NON-STOP', 'CONNECTING') DEFAULT 'NON-STOP' AFTER layover_time;

-- Set default flight type based on stops
UPDATE flight 
SET flight_type = IF(no_of_stops = 0 OR no_of_stops IS NULL, 'NON-STOP', 'CONNECTING')
WHERE flight_type IS NULL;

-- 8. Add CONNECTING table for multi-leg flights
CREATE TABLE IF NOT EXISTS connecting (
    flight_id VARCHAR(20) NOT NULL,
    layover_time TIME,
    no_of_stops INT DEFAULT 1,
    PRIMARY KEY (flight_id),
    FOREIGN KEY (flight_id) REFERENCES flight(flight_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Ensure PERSON-like relationship columns exist in reschedule/cancellation
ALTER TABLE reschedule_tx
    ADD COLUMN IF NOT EXISTS date_of_reschedule DATETIME DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE cancellation_tx
    ADD COLUMN IF NOT EXISTS date_of_cancellation DATETIME DEFAULT CURRENT_TIMESTAMP;

-- 10. Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_passenger_fname ON passenger(fname);
CREATE INDEX IF NOT EXISTS idx_passenger_lname ON passenger(lname);
CREATE INDEX IF NOT EXISTS idx_flight_type ON flight(flight_type);
CREATE INDEX IF NOT EXISTS idx_airport_airline_ap ON airport_airline(ap_name);
CREATE INDEX IF NOT EXISTS idx_serves_passport ON serves(passportno);
CREATE INDEX IF NOT EXISTS idx_monitors_flight ON monitors(flight_id);

-- Migration complete
-- Your existing code will continue to work with these additions
