-- Migration 002: Add sample data for new tables
-- Run this AFTER 001_add_missing_schema.sql

USE airport_demo;

-- Sample airport_airline relationships (airlines operating at airports)
-- Adjust these based on your actual airport and airline data
INSERT IGNORE INTO airport_airline (ap_name, airlineid) 
SELECT DISTINCT a.airport_name, f.airline_id
FROM airport a
JOIN flight f ON (f.source_id = a.airport_id OR f.destination_id = a.airport_id)
WHERE f.airline_id IS NOT NULL
LIMIT 100;

-- Sample PMR data (passengers requiring assistance)
INSERT IGNORE INTO pmr (ssn, fname, lname, age, contact, assistance) VALUES
('PMR001', 'John', 'Smith', 75, '+91-9876543210', 'Wheelchair assistance required'),
('PMR002', 'Mary', 'Johnson', 68, '+91-9876543211', 'Oxygen support needed'),
('PMR003', 'David', 'Williams', 82, '+91-9876543212', 'Mobility assistance');

-- Update flight types for existing flights (non-stop by default)
UPDATE flight 
SET flight_type = 'NON-STOP',
    no_of_stops = 0
WHERE flight_type IS NULL;

-- Sample connecting flights (you can add specific ones)
-- INSERT INTO connecting (flight_id, layover_time, no_of_stops) VALUES
-- ('FL001', '02:30:00', 1),
-- ('FL002', '01:45:00', 1);

-- Migration complete
