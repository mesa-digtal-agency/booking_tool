-- Beauty Booking System schema.
-- {{AUTO_PK}}  -> driver-specific auto-increment PK clause (SQLite vs MySQL)
-- {{NOW}}      -> driver-specific NOW() default for TIMESTAMP columns

CREATE TABLE services (
    id {{AUTO_PK}},
    name VARCHAR(120) NOT NULL,
    description TEXT,
    duration_minutes INTEGER NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    category VARCHAR(80),
    image VARCHAR(255),
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE staff (
    id {{AUTO_PK}},
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(40),
    role VARCHAR(16) NOT NULL DEFAULT 'staff',
    password_hash VARCHAR(255) NOT NULL,
    avatar VARCHAR(255),
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE staff_services (
    staff_id INTEGER NOT NULL,
    service_id INTEGER NOT NULL,
    PRIMARY KEY (staff_id, service_id),
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
);

CREATE TABLE working_hours (
    id {{AUTO_PK}},
    staff_id INTEGER NOT NULL,
    day_of_week INTEGER NOT NULL,
    start_time VARCHAR(5) NOT NULL DEFAULT '09:00',
    end_time VARCHAR(5) NOT NULL DEFAULT '17:00',
    is_off INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
);

CREATE TABLE bookings (
    id {{AUTO_PK}},
    customer_name VARCHAR(120) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_phone VARCHAR(40) NOT NULL,
    notes TEXT,
    service_id INTEGER NOT NULL,
    staff_id INTEGER NOT NULL,
    booking_date VARCHAR(10) NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    management_token VARCHAR(36) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT {{NOW}},
    FOREIGN KEY (service_id) REFERENCES services(id),
    FOREIGN KEY (staff_id) REFERENCES staff(id)
);

CREATE TABLE blocked_slots (
    id {{AUTO_PK}},
    staff_id INTEGER NOT NULL,
    date VARCHAR(10) NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    reason VARCHAR(255),
    repeat_until VARCHAR(10),
    repeat_mode VARCHAR(20),
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
);

CREATE TABLE password_resets (
    id {{AUTO_PK}},
    staff_id INTEGER NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT {{NOW}},
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
);

CREATE INDEX idx_bookings_date_staff ON bookings(booking_date, staff_id);
CREATE INDEX idx_bookings_token ON bookings(management_token);
CREATE INDEX idx_staff_services_staff ON staff_services(staff_id);
CREATE INDEX idx_staff_services_service ON staff_services(service_id);
CREATE INDEX idx_wh_staff_dow ON working_hours(staff_id, day_of_week);
CREATE INDEX idx_blocked_staff_date ON blocked_slots(staff_id, date);
CREATE INDEX idx_password_resets_staff ON password_resets(staff_id);
