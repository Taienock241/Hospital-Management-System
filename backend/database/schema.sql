-- Hopewell Hospital queue and patient management database
-- PostgreSQL schema for the current staff portal and a separate patient portal.
--
-- Usage:
--   1. Create a database named hopewell_hospital in PostgreSQL (or use an
--      existing database).
--   2. Connect to that database in pgAdmin/psql.
--   3. Run this file.
--
--   To create the database from psql before connecting, run this separately:
--     CREATE DATABASE hopewell_hospital;
--
-- This script creates tables in the current schema (normally "public").
-- It is intended to be run once on a new/empty database.

BEGIN;

CREATE TABLE roles (
    role_id       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    role_name     VARCHAR(40) NOT NULL UNIQUE,
    description   TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT roles_name_lowercase CHECK (role_name = LOWER(role_name))
);

CREATE TABLE users (
    user_id        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    role_id        BIGINT NOT NULL REFERENCES roles(role_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    email          VARCHAR(254) NOT NULL UNIQUE,
    password_hash  TEXT NOT NULL,
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at  TIMESTAMPTZ,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT users_email_not_blank CHECK (BTRIM(email) <> ''),
    CONSTRAINT users_password_hash_not_blank CHECK (BTRIM(password_hash) <> '')
);

CREATE TABLE departments (
    department_id  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    department_code VARCHAR(20) NOT NULL UNIQUE,
    department_name VARCHAR(100) NOT NULL UNIQUE,
    consultation_rooms INTEGER NOT NULL DEFAULT 1,
    status          VARCHAR(10) NOT NULL DEFAULT 'active',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT departments_rooms_positive CHECK (consultation_rooms > 0),
    CONSTRAINT departments_status_valid CHECK (status IN ('active', 'inactive')),
    CONSTRAINT departments_code_not_blank CHECK (BTRIM(department_code) <> ''),
    CONSTRAINT departments_name_not_blank CHECK (BTRIM(department_name) <> '')
);

CREATE TABLE staff_profiles (
    staff_id        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT NOT NULL UNIQUE REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    staff_number    VARCHAR(30) NOT NULL UNIQUE,
    full_name       VARCHAR(160) NOT NULL,
    department_id   BIGINT REFERENCES departments(department_id) ON UPDATE CASCADE ON DELETE SET NULL,
    job_title       VARCHAR(80) NOT NULL,
    specialty       VARCHAR(120),
    availability    VARCHAR(12) NOT NULL DEFAULT 'off_duty',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT staff_availability_valid CHECK (availability IN ('on_duty', 'off_duty')),
    CONSTRAINT staff_name_not_blank CHECK (BTRIM(full_name) <> ''),
    CONSTRAINT staff_number_not_blank CHECK (BTRIM(staff_number) <> '')
);

ALTER TABLE departments
    ADD COLUMN lead_staff_id BIGINT REFERENCES staff_profiles(staff_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE patients (
    patient_id       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    patient_number   VARCHAR(30) NOT NULL UNIQUE,
    user_id          BIGINT UNIQUE REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    full_name        VARCHAR(160) NOT NULL,
    phone            VARCHAR(30) NOT NULL,
    gender           VARCHAR(20),
    date_of_birth    DATE,
    blood_group      VARCHAR(3),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT patients_name_not_blank CHECK (BTRIM(full_name) <> ''),
    CONSTRAINT patients_phone_not_blank CHECK (BTRIM(phone) <> ''),
    CONSTRAINT patients_gender_valid CHECK (gender IS NULL OR gender IN ('female', 'male', 'other', 'unspecified')),
    CONSTRAINT patients_dob_not_future CHECK (date_of_birth IS NULL OR date_of_birth <= CURRENT_DATE),
    CONSTRAINT patients_blood_group_valid CHECK (
        blood_group IS NULL OR blood_group IN ('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-')
    )
);

CREATE TABLE appointments (
    appointment_id  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    appointment_number VARCHAR(30) NOT NULL UNIQUE,
    patient_id      BIGINT NOT NULL REFERENCES patients(patient_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    department_id   BIGINT NOT NULL REFERENCES departments(department_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    doctor_staff_id BIGINT REFERENCES staff_profiles(staff_id) ON UPDATE CASCADE ON DELETE SET NULL,
    scheduled_at    TIMESTAMPTZ NOT NULL,
    status          VARCHAR(12) NOT NULL DEFAULT 'pending',
    reason          TEXT,
    created_by       BIGINT REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT appointments_status_valid CHECK (
        status IN ('pending', 'confirmed', 'completed', 'cancelled', 'no_show')
    )
);

CREATE TABLE queue_tickets (
    queue_ticket_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    patient_id      BIGINT NOT NULL REFERENCES patients(patient_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    department_id   BIGINT NOT NULL REFERENCES departments(department_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    ticket_number   VARCHAR(20) NOT NULL,
    queue_date      DATE NOT NULL DEFAULT CURRENT_DATE,
    status          VARCHAR(20) NOT NULL DEFAULT 'waiting',
    arrived_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    called_at       TIMESTAMPTZ,
    service_started_at TIMESTAMPTZ,
    served_at       TIMESTAMPTZ,
    created_by      BIGINT REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT queue_ticket_status_valid CHECK (
        status IN ('waiting', 'called', 'in_consultation', 'served', 'cancelled', 'no_show')
    ),
    CONSTRAINT queue_ticket_number_not_blank CHECK (BTRIM(ticket_number) <> ''),
    CONSTRAINT queue_ticket_unique_per_department_day
        UNIQUE (department_id, queue_date, ticket_number)
);

CREATE TABLE queue_ticket_events (
    queue_event_id  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    queue_ticket_id BIGINT NOT NULL REFERENCES queue_tickets(queue_ticket_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    old_status      VARCHAR(20),
    new_status      VARCHAR(20) NOT NULL,
    changed_by      BIGINT REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    note            TEXT,
    changed_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT queue_event_new_status_valid CHECK (
        new_status IN ('waiting', 'called', 'in_consultation', 'served', 'cancelled', 'no_show')
    ),
    CONSTRAINT queue_event_old_status_valid CHECK (
        old_status IS NULL OR old_status IN ('waiting', 'called', 'in_consultation', 'served', 'cancelled', 'no_show')
    )
);

CREATE TABLE audit_logs (
    audit_log_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    actor_user_id BIGINT REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    action       VARCHAR(80) NOT NULL,
    entity_type  VARCHAR(80) NOT NULL,
    entity_id    BIGINT,
    old_values   JSONB,
    new_values   JSONB,
    occurred_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT audit_action_not_blank CHECK (BTRIM(action) <> ''),
    CONSTRAINT audit_entity_not_blank CHECK (BTRIM(entity_type) <> '')
);

-- Indexes for common staff and patient lookups.
CREATE INDEX idx_users_role_id ON users(role_id);
CREATE INDEX idx_staff_department_id ON staff_profiles(department_id);
CREATE INDEX idx_patients_name ON patients(full_name);
CREATE INDEX idx_patients_phone ON patients(phone);
CREATE INDEX idx_appointments_patient_schedule ON appointments(patient_id, scheduled_at DESC);
CREATE INDEX idx_appointments_department_schedule ON appointments(department_id, scheduled_at);
CREATE INDEX idx_appointments_doctor_schedule ON appointments(doctor_staff_id, scheduled_at)
    WHERE doctor_staff_id IS NOT NULL;
CREATE INDEX idx_queue_department_day_status ON queue_tickets(department_id, queue_date, status, arrived_at);
CREATE INDEX idx_queue_patient_day ON queue_tickets(patient_id, queue_date DESC);
CREATE INDEX idx_queue_events_ticket_time ON queue_ticket_events(queue_ticket_id, changed_at);
CREATE INDEX idx_audit_entity_time ON audit_logs(entity_type, entity_id, occurred_at DESC);
CREATE INDEX idx_audit_actor_time ON audit_logs(actor_user_id, occurred_at DESC)
    WHERE actor_user_id IS NOT NULL;

-- Seed the roles used by the current staff portal and patient portal.
INSERT INTO roles (role_name, description) VALUES
    ('admin', 'System administrator'),
    ('doctor', 'Doctor or clinician'),
    ('nurse', 'Nurse or triage staff'),
    ('receptionist', 'Reception and appointment staff'),
    ('patient', 'Patient portal account');

-- Initial departments shown in the current staff demo. These can be edited later.
INSERT INTO departments (department_code, department_name, consultation_rooms) VALUES
    ('GEN', 'General Medicine', 6),
    ('PED', 'Pediatrics', 4),
    ('CARD', 'Cardiology', 3);

COMMIT;

-- Example verification queries (run separately after the schema is created):
-- SELECT table_name FROM information_schema.tables
-- WHERE table_schema = 'public' ORDER BY table_name;
-- SELECT role_name FROM roles ORDER BY role_name;
-- SELECT department_code, department_name, consultation_rooms, status
-- FROM departments ORDER BY department_name;

-- First system administrator setup (run separately after this schema succeeds):
-- 1. Generate a password hash with the backend's password-hashing library.
--    Never store a plain-text password in this database.
-- 2. Replace the sample email, password hash, staff number, and name below.
-- 3. Remove the leading SQL comment markers and run the statement once.
-- This creates the administrator login and its staff profile together.
--
-- WITH admin_role AS (
--     SELECT role_id FROM roles WHERE role_name = 'admin'
-- ),
-- new_admin AS (
--     INSERT INTO users (role_id, email, password_hash)
--     SELECT role_id,
--            'admin@example.org',
--            'REPLACE_WITH_A_REAL_PASSWORD_HASH'
--     FROM admin_role
--     RETURNING user_id
-- )
-- INSERT INTO staff_profiles
--     (user_id, staff_number, full_name, job_title, availability)
-- SELECT user_id,
--        'STF-001',
--        'System Administrator',
--        'System Administrator',
--        'on_duty'
-- FROM new_admin;

-- Safe staff offboarding example (run as the authorized admin through the API):
-- Deactivation preserves the account and historical record links.
-- UPDATE users
-- SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP
-- WHERE user_id = 123;
