-- Disable foreign key checks during initialization
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing tables to allow clean re-execution
DROP TABLE IF EXISTS employee_certifications;
DROP TABLE IF EXISTS certifications;
DROP TABLE IF EXISTS citations;
DROP TABLE IF EXISTS benefit_enrollments;
DROP TABLE IF EXISTS benefit_eligibility_rules;
DROP TABLE IF EXISTS benefit_plans;
DROP TABLE IF EXISTS time_attendance_logs;
DROP TABLE IF EXISTS shift_assignments;
DROP TABLE IF EXISTS shift_templates;
DROP TABLE IF EXISTS absences;
DROP TABLE IF EXISTS leave_requests;
DROP TABLE IF EXISTS leave_balances;
DROP TABLE IF EXISTS leave_types;
DROP TABLE IF EXISTS compensation_history;
DROP TABLE IF EXISTS pay_types;
DROP TABLE IF EXISTS emergency_contacts;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS job_positions;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS user_account_roles;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================
-- MODULE 1: USER AUTHENTICATION & RBAC
-- ==========================================

CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_ldap_user BOOLEAN NOT NULL DEFAULT FALSE,
    ldap_dn VARCHAR(255) NULL UNIQUE,
    failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    mfa_secret VARCHAR(64) NULL,
    mfa_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    role_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    permission_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_code VARCHAR(100) NOT NULL UNIQUE,
    module VARCHAR(50) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (role_id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (permission_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_account_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_uar_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_uar_role FOREIGN KEY (role_id) REFERENCES roles (role_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- MODULE 2: CORE EMPLOYEE & ORGANIZATION DIRECTORY
-- ==========================================

CREATE TABLE departments (
    department_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(100) NOT NULL UNIQUE,
    parent_department_id INT UNSIGNED NULL,
    manager_employee_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dept_parent FOREIGN KEY (parent_department_id) REFERENCES departments (department_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_positions (
    position_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_title VARCHAR(100) NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    min_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_jp_department FOREIGN KEY (department_id) REFERENCES departments (department_id) ON DELETE RESTRICT,
    CONSTRAINT chk_salary_range CHECK (max_salary >= min_salary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employees (
    employee_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED UNIQUE NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    work_email VARCHAR(100) NOT NULL UNIQUE,
    phone_number VARCHAR(20) NULL,
    hire_date DATE NOT NULL,
    termination_date DATE NULL,
    employment_status ENUM('active', 'terminated', 'on_leave') NOT NULL DEFAULT 'active',
    employment_type ENUM('full_time', 'part_time', 'contractor') NOT NULL DEFAULT 'full_time',
    department_id INT UNSIGNED NULL,
    position_id INT UNSIGNED NULL,
    manager_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_emp_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_emp_dept FOREIGN KEY (department_id) REFERENCES departments (department_id) ON DELETE SET NULL,
    CONSTRAINT fk_emp_pos FOREIGN KEY (position_id) REFERENCES job_positions (position_id) ON DELETE SET NULL,
    CONSTRAINT fk_emp_manager FOREIGN KEY (manager_id) REFERENCES employees (employee_id) ON DELETE SET NULL,
    CONSTRAINT chk_term_date CHECK (termination_date IS NULL OR termination_date >= hire_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE departments
ADD CONSTRAINT fk_dept_manager FOREIGN KEY (manager_employee_id) REFERENCES employees (employee_id) ON DELETE SET NULL;

CREATE TABLE emergency_contacts (
    contact_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    contact_name VARCHAR(100) NOT NULL,
    relationship VARCHAR(50) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ec_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- MODULE 3: COMPENSATION & PAY TYPES
-- ==========================================

CREATE TABLE pay_types (
    pay_type_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pay_type_code VARCHAR(30) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compensation_history (
    compensation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    pay_type_id INT UNSIGNED NOT NULL,
    pay_rate DECIMAL(10,2) NOT NULL,
    pay_frequency ENUM('weekly', 'bi_weekly', 'semi_monthly', 'monthly') NOT NULL,
    commission_rate_pct DECIMAL(5,2) DEFAULT 0.00,
    effective_start_date DATE NOT NULL,
    effective_end_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_comp_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_comp_pay_type FOREIGN KEY (pay_type_id) REFERENCES pay_types (pay_type_id) ON DELETE RESTRICT,
    CONSTRAINT chk_comp_dates CHECK (effective_end_date IS NULL OR effective_end_date >= effective_start_date),
    CONSTRAINT chk_positive_rate CHECK (pay_rate >= 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- MODULE 4: TIME-OFF, LEAVE & ABSENCE TRACKING
-- ==========================================

CREATE TABLE leave_types (
    leave_type_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) NOT NULL UNIQUE,
    is_paid BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_balances (
    balance_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    leave_type_id INT UNSIGNED NOT NULL,
    fiscal_year SMALLINT UNSIGNED NOT NULL,
    total_allocated DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    used_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    pending_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    carryover_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_leave_year (employee_id, leave_type_id, fiscal_year),
    CONSTRAINT fk_lb_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_lb_leave_type FOREIGN KEY (leave_type_id) REFERENCES leave_types (leave_type_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_requests (
    request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    leave_type_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_hours DECIMAL(5,2) NOT NULL,
    reason TEXT NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    approver_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lr_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_lr_leave_type FOREIGN KEY (leave_type_id) REFERENCES leave_types (leave_type_id) ON DELETE RESTRICT,
    CONSTRAINT fk_lr_approver FOREIGN KEY (approver_id) REFERENCES employees (employee_id) ON DELETE SET NULL,
    CONSTRAINT chk_lr_dates CHECK (end_date >= start_date),
    CONSTRAINT chk_lr_hours CHECK (total_hours > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE absences (
    absence_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    absence_date DATE NOT NULL,
    absence_reason ENUM('unplanned_absence', 'unexcused_tardiness', 'missed_clock_in') NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_abs_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- MODULE 5: SCHEDULING & TIMEKEEPING
-- ==========================================

CREATE TABLE shift_templates (
    shift_template_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(50) NOT NULL,
    default_start_time TIME NOT NULL,
    default_end_time TIME NOT NULL,
    unpaid_break_minutes INT UNSIGNED NOT NULL DEFAULT 30,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shift_assignments (
    shift_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    shift_template_id INT UNSIGNED NULL,
    shift_date DATE NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    location VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sa_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_sa_template FOREIGN KEY (shift_template_id) REFERENCES shift_templates (shift_template_id) ON DELETE SET NULL,
    CONSTRAINT chk_shift_times CHECK (end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE time_attendance_logs (
    log_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    shift_id INT UNSIGNED NULL,
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,
    break_duration_minutes INT UNSIGNED DEFAULT 0,
    total_hours_worked DECIMAL(5,2) NULL,
    overtime_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tal_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_tal_shift FOREIGN KEY (shift_id) REFERENCES shift_assignments (shift_id) ON DELETE SET NULL,
    CONSTRAINT chk_clock_times CHECK (clock_out IS NULL OR clock_out >= clock_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- MODULE 6: BENEFITS TRACKING
-- ==========================================

CREATE TABLE benefit_plans (
    plan_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_name VARCHAR(100) NOT NULL,
    provider_name VARCHAR(100) NOT NULL,
    plan_type ENUM('medical', 'dental', 'vision', '401k', 'life_insurance', 'disability') NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE benefit_eligibility_rules (
    rule_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id INT UNSIGNED NOT NULL,
    waiting_period_days INT UNSIGNED NOT NULL DEFAULT 30,
    min_weekly_hours DECIMAL(4,2) NOT NULL DEFAULT 30.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ber_plan FOREIGN KEY (plan_id) REFERENCES benefit_plans (plan_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE benefit_enrollments (
    enrollment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    coverage_tier ENUM('individual', 'spouse', 'family') NOT NULL DEFAULT 'individual',
    employee_contribution DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    employer_contribution DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_be_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_be_plan FOREIGN KEY (plan_id) REFERENCES benefit_plans (plan_id) ON DELETE RESTRICT,
    CONSTRAINT chk_enrollment_dates CHECK (end_date IS NULL OR end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- MODULE 7: DISCIPLINARY ACTIONS & CITATIONS
-- ==========================================

CREATE TABLE citations (
    citation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    issuer_id INT UNSIGNED NOT NULL,
    incident_date DATE NOT NULL,
    infraction_type ENUM('tardiness', 'conduct', 'safety_violation', 'policy_breach') NOT NULL,
    severity_level ENUM('verbal_warning', 'written_warning', 'final_warning', 'suspension') NOT NULL,
    description TEXT NOT NULL,
    remedial_action_plan TEXT NULL,
    employee_acknowledged BOOLEAN NOT NULL DEFAULT FALSE,
    acknowledged_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cite_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_cite_issuer FOREIGN KEY (issuer_id) REFERENCES employees (employee_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- MODULE 8: CERTIFICATIONS & SKILL MANAGEMENT
-- ==========================================

CREATE TABLE certifications (
    certification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    issuing_authority VARCHAR(100) NOT NULL,
    validity_months INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_certifications (
    emp_cert_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    certification_id INT UNSIGNED NOT NULL,
    certificate_number VARCHAR(100) NULL,
    issue_date DATE NOT NULL,
    expiration_date DATE NULL,
    verification_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    document_url VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ecert_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_ecert_cert FOREIGN KEY (certification_id) REFERENCES certifications (certification_id) ON DELETE RESTRICT,
/    CONSTRAINT chk_cert_dates CHECK (expiration_date IS NULL OR expiration_date >= issue_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
