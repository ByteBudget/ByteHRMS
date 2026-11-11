<?php
// install/install.php - Step 1: Database Setup & Master Schema Creation
session_start();

$configFile = __DIR__ . '/../config/db.php';
if (file_exists($configFile) && !isset($_GET['reinstall'])) {
    header('Location: install_admin.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost      = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort      = (int)($_POST['db_port'] ?? 3306);
    $dbName      = trim($_POST['db_name'] ?? 'hrms_db');
    $dbUser      = trim($_POST['db_user'] ?? 'root');
    $dbPass      = $_POST['db_pass'] ?? '';
    $includeSeed = isset($_POST['include_seed_data']);

    if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
        $error = 'Database host, name, and user are required.';
    } else {
        try {
            // 1. Connect to MySQL/MariaDB server instance
            $dsnNoDb = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdoNoDb = new PDO($dsnNoDb, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Create target database
            $pdoNoDb->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

            // 2. Connect to the created database
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // 3. Import Master Schema DDL across all 26 tables
            $sql = "
            SET FOREIGN_KEY_CHECKS = 0;

            DROP TABLE IF EXISTS employee_certifications;
            DROP TABLE IF EXISTS certifications;
            DROP TABLE IF EXISTS citations;
            DROP TABLE IF EXISTS benefit_enrollments;
            DROP TABLE IF EXISTS benefit_eligibility_rules;
            DROP TABLE IF EXISTS benefit_plans;
            DROP TABLE IF EXISTS time_attendance_logs;
            DROP TABLE IF EXISTS shift_assignments;
            DROP TABLE IF EXISTS shift_templates;
            DROP TABLE IF EXISTS business_hours;
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
            DROP TABLE IF EXISTS locations;
            DROP TABLE IF EXISTS user_account_roles;
            DROP TABLE IF EXISTS role_permissions;
            DROP TABLE IF EXISTS permissions;
            DROP TABLE IF EXISTS roles;
            DROP TABLE IF EXISTS users;

            -- MODULE 1: AUTHENTICATION & RBAC
            CREATE TABLE users (
                user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(100) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                pin_code VARCHAR(255) NULL,
                is_pin_set BOOLEAN NOT NULL DEFAULT FALSE,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                is_ldap_user BOOLEAN NOT NULL DEFAULT FALSE,
                ldap_dn VARCHAR(255) NULL,
                failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                locked_until DATETIME NULL,
                last_login_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_users_username (username),
                INDEX idx_users_email (email)
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
                description VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE role_permissions (
                role_id INT UNSIGNED NOT NULL,
                permission_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (role_id, permission_id),
                CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
                CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE user_account_roles (
                user_id INT UNSIGNED NOT NULL,
                role_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (user_id, role_id),
                CONSTRAINT fk_uar_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                CONSTRAINT fk_uar_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- MODULE 2: DIRECTORY & LOCATIONS
            CREATE TABLE locations (
                location_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                location_name VARCHAR(100) NOT NULL UNIQUE,
                address_line1 VARCHAR(150) NOT NULL,
                address_line2 VARCHAR(150) NULL,
                city VARCHAR(50) NOT NULL,
                state_province VARCHAR(50) NOT NULL,
                postal_code VARCHAR(20) NOT NULL,
                country VARCHAR(50) NOT NULL DEFAULT 'USA',
                phone_number VARCHAR(20) NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE departments (
                department_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                department_name VARCHAR(100) NOT NULL,
                parent_department_id INT UNSIGNED NULL,
                location_id INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_dept_parent FOREIGN KEY (parent_department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
                CONSTRAINT fk_dept_location FOREIGN KEY (location_id) REFERENCES locations(location_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE job_positions (
                position_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_title VARCHAR(100) NOT NULL,
                min_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                max_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT chk_salary_range CHECK (max_salary >= min_salary)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE employees (
                employee_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED UNIQUE NULL,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                work_email VARCHAR(100) NOT NULL UNIQUE,
                phone_number VARCHAR(20) NULL,
                department_id INT UNSIGNED NULL,
                position_id INT UNSIGNED NULL,
                manager_id INT UNSIGNED NULL,
                location_id INT UNSIGNED NULL,
                hire_date DATE NOT NULL,
                termination_date DATE NULL,
                employment_status ENUM('active', 'on_leave', 'terminated') NOT NULL DEFAULT 'active',
                employment_type ENUM('full_time', 'part_time', 'contractor') NOT NULL DEFAULT 'full_time',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_emp_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                CONSTRAINT fk_emp_dept FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
                CONSTRAINT fk_emp_pos FOREIGN KEY (position_id) REFERENCES job_positions(position_id) ON DELETE SET NULL,
                CONSTRAINT fk_emp_mgr FOREIGN KEY (manager_id) REFERENCES employees(employee_id) ON DELETE SET NULL,
                CONSTRAINT fk_emp_loc FOREIGN KEY (location_id) REFERENCES locations(location_id) ON DELETE SET NULL,
                INDEX idx_emp_name (last_name, first_name),
                INDEX idx_emp_status (employment_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE emergency_contacts (
                contact_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NOT NULL,
                contact_name VARCHAR(100) NOT NULL,
                relationship VARCHAR(50) NOT NULL,
                phone_number VARCHAR(20) NOT NULL,
                alternate_phone VARCHAR(20) NULL,
                is_primary BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_ec_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- MODULE 3: PAYROLL & COMPENSATION
            CREATE TABLE pay_types (
                pay_type_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type_name VARCHAR(50) NOT NULL UNIQUE,
                description VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE compensation_history (
                comp_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NOT NULL,
                pay_type_id INT UNSIGNED NOT NULL,
                pay_rate DECIMAL(10,2) NOT NULL,
                pay_frequency ENUM('weekly', 'bi_weekly', 'semi_monthly', 'monthly') NOT NULL DEFAULT 'bi_weekly',
                commission_percentage DECIMAL(5,2) NULL DEFAULT 0.00,
                effective_date DATE NOT NULL,
                end_date DATE NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_ch_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
                CONSTRAINT fk_ch_paytype FOREIGN KEY (pay_type_id) REFERENCES pay_types(pay_type_id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- MODULE 4: LEAVE & ABSENCES
            CREATE TABLE leave_types (
                leave_type_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type_name VARCHAR(50) NOT NULL UNIQUE,
                is_paid BOOLEAN NOT NULL DEFAULT TRUE
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
                CONSTRAINT fk_lb_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
                CONSTRAINT fk_lb_leavetype FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id) ON DELETE CASCADE,
                UNIQUE KEY uq_emp_leave_year (employee_id, leave_type_id, fiscal_year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE leave_requests (
                request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NOT NULL,
                leave_type_id INT UNSIGNED NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                total_hours DECIMAL(6,2) NOT NULL,
                reason TEXT NULL,
                status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
                approver_id INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_lr_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
                CONSTRAINT fk_lr_leavetype FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id) ON DELETE CASCADE,
                CONSTRAINT fk_lr_approver FOREIGN KEY (approver_id) REFERENCES employees(employee_id) ON DELETE SET NULL,
                CONSTRAINT chk_leave_dates CHECK (end_date >= start_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE absences (
                absence_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NOT NULL,
                absence_date DATE NOT NULL,
                reason_code ENUM('unexcused', 'tardy', 'no_call_no_show', 'other') NOT NULL DEFAULT 'unexcused',
                notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_abs_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- MODULE 5: OPERATING HOURS, SHIFTS & ATTENDANCE
            CREATE TABLE business_hours (
                day_id TINYINT UNSIGNED PRIMARY KEY,
                day_name VARCHAR(10) NOT NULL UNIQUE,
                is_open BOOLEAN NOT NULL DEFAULT TRUE,
                open_time TIME NULL DEFAULT '08:00:00',
                close_time TIME NULL DEFAULT '17:00:00',
                break_duration INT UNSIGNED NOT NULL DEFAULT 60,
                break_start TIME NULL DEFAULT '12:00:00',
                break_end TIME NULL DEFAULT '13:00:00',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE shift_templates (
                shift_template_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                template_name VARCHAR(50) NOT NULL,
                default_start_time TIME NOT NULL,
                default_end_time TIME NOT NULL,
                unpaid_break_minutes INT UNSIGNED NOT NULL DEFAULT 30,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE shift_assignments (
                shift_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NOT NULL,
                shift_template_id INT UNSIGNED NULL,
                shift_date DATE NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                location VARCHAR(100) NULL DEFAULT 'Headquarters',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_sa_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
                CONSTRAINT fk_sa_template FOREIGN KEY (shift_template_id) REFERENCES shift_templates(shift_template_id) ON DELETE SET NULL,
                INDEX idx_shift_date (shift_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE time_attendance_logs (
                log_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NOT NULL,
                shift_id INT UNSIGNED NULL,
                clock_in DATETIME NOT NULL,
                break_start DATETIME NULL,
                break_end DATETIME NULL,
                clock_out DATETIME NULL,
                break_duration_minutes INT UNSIGNED DEFAULT 0,
                log_status ENUM('clocked_in', 'on_break', 'clocked_out') NOT NULL DEFAULT 'clocked_in',
                total_hours_worked DECIMAL(5,2) NULL,
                overtime_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_tal_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
                CONSTRAINT fk_tal_shift FOREIGN KEY (shift_id) REFERENCES shift_assignments(shift_id) ON DELETE SET NULL,
                CONSTRAINT chk_clock_times CHECK (clock_out IS NULL OR clock_out >= clock_in)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- MODULE 6: BENEFITS
            CREATE TABLE benefit_plans (
                plan_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                plan_name VARCHAR(100) NOT NULL,
                provider_name VARCHAR(100) NOT NULL,
                plan_type ENUM('medical', 'dental', 'vision', '401k', 'life', 'disability') NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE benefit_eligibility_rules (
                rule_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                plan_id INT UNSIGNED NOT NULL,
                waiting_period_days INT UNSIGNED NOT NULL DEFAULT 30,
                min_weekly_hours DECIMAL(4,2) NOT NULL DEFAULT 30.00,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_ber_plan FOREIGN KEY (plan_id) REFERENCES benefit_plans(plan_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE benefit_enrollments (
                enrollment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NOT NULL,
                plan_id INT UNSIGNED NOT NULL,
                coverage_tier ENUM('individual', 'spouse', 'family') NOT NULL DEFAULT 'individual',
                employee_contribution DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                employer_contribution DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                start_date DATE NOT NULL,
                end_date DATE NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_be_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
                CONSTRAINT fk_be_plan FOREIGN KEY (plan_id) REFERENCES benefit_plans(plan_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- MODULE 7: DISCIPLINARY & CERTIFICATIONS
            CREATE TABLE citations (
                citation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NOT NULL,
                issued_by INT UNSIGNED NOT NULL,
                citation_type ENUM('verbal_warning', 'written_warning', 'final_warning', 'suspension') NOT NULL,
                incident_date DATE NOT NULL,
                description TEXT NOT NULL,
                action_plan TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_cit_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
                CONSTRAINT fk_cit_issuer FOREIGN KEY (issued_by) REFERENCES employees(employee_id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE certifications (
                certification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cert_name VARCHAR(100) NOT NULL UNIQUE,
                issuing_organization VARCHAR(100) NOT NULL,
                renewal_period_months INT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE employee_certifications (
                emp_cert_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NOT NULL,
                certification_id INT UNSIGNED NOT NULL,
                issue_date DATE NOT NULL,
                expiration_date DATE NULL,
                credential_id VARCHAR(100) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_ecert_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
                CONSTRAINT fk_ecert_cert FOREIGN KEY (certification_id) REFERENCES certifications(certification_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            SET FOREIGN_KEY_CHECKS = 1;
            ";

            $pdo->exec($sql);

            // 4. Populate Core System Lookups & Default Operating Schedule
            $pdo->exec("
                INSERT IGNORE INTO roles (role_id, role_name, description) VALUES
                (1, 'admin', 'System Administrator with full access'),
                (2, 'manager', 'Department Manager'),
                (3, 'employee', 'Standard Employee Account'),
                (4, 'hr_specialist', 'HR Personnel');

                INSERT IGNORE INTO pay_types (pay_type_id, type_name, description) VALUES
                (1, 'Hourly', 'Paid by total logged working hours'),
                (2, 'Salary', 'Fixed annual or monthly compensation'),
                (3, 'Commission', 'Paid based on percentage of sales volume'),
                (4, 'Hybrid', 'Base salary plus commission structure');

                INSERT IGNORE INTO leave_types (leave_type_id, type_name, is_paid) VALUES
                (1, 'Paid Time Off (PTO)', TRUE),
                (2, 'Sick Leave', TRUE),
                (3, 'Unpaid Leave', FALSE),
                (4, 'Bereavement', TRUE),
                (5, 'Parental Leave', TRUE);

                INSERT IGNORE INTO business_hours (day_id, day_name, is_open, open_time, close_time, break_duration, break_start, break_end) VALUES
                (1, 'Monday', TRUE, '08:00:00', '17:00:00', 60, '12:00:00', '13:00:00'),
                (2, 'Tuesday', TRUE, '08:00:00', '17:00:00', 60, '12:00:00', '13:00:00'),
                (3, 'Wednesday', TRUE, '08:00:00', '17:00:00', 60, '12:00:00', '13:00:00'),
                (4, 'Thursday', TRUE, '08:00:00', '17:00:00', 60, '12:00:00', '13:00:00'),
                (5, 'Friday', TRUE, '08:00:00', '17:00:00', 60, '12:00:00', '13:00:00'),
                (6, 'Saturday', FALSE, '09:00:00', '14:00:00', 0, NULL, NULL),
                (7, 'Sunday', FALSE, '09:00:00', '14:00:00', 0, NULL, NULL);
            ");

            // 5. Seed Optional Demo Baseline Data
            if ($includeSeed) {
                $pdo->exec("
                    INSERT IGNORE INTO locations (location_id, location_name, address_line1, city, state_province, postal_code, country) VALUES
                    (1, 'Corporate HQ', '100 Corporate Parkway', 'Denver', 'CO', '80202', 'USA');

                    INSERT IGNORE INTO departments (department_id, department_name, location_id) VALUES
                    (1, 'Executive Leadership', 1),
                    (2, 'Software Engineering', 1),
                    (3, 'Human Resources', 1);

                    INSERT IGNORE INTO job_positions (position_id, job_title, min_salary, max_salary) VALUES
                    (1, 'VP of Engineering', 130000.00, 200000.00),
                    (2, 'Engineering Manager', 100000.00, 150000.00),
                    (3, 'Software Engineer', 70000.00, 120000.00);

                    INSERT IGNORE INTO shift_templates (template_name, default_start_time, default_end_time, unpaid_break_minutes) VALUES
                    ('Standard Day Shift', '08:00:00', '17:00:00', 60),
                    ('Morning Shift', '08:00:00', '16:30:00', 30),
                    ('Swing Shift', '16:00:00', '00:30:00', 30);
                ");
            }

            // 6. Generate config/db.php Configuration File (with America/Denver timezone default)
            $configDir = __DIR__ . '/../config';
            if (!is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }

            $dbConfigCode = "<?php\n"
                          . "// config/db.php - Database Connection & Session Initialization\n\n"
                          . "if (session_status() === PHP_SESSION_NONE) {\n"
                          . "    session_start();\n"
                          . "}\n\n"
                          . "date_default_timezone_set('America/Denver');\n\n"
                          . "\$dbHost = " . var_export($dbHost, true) . ";\n"
                          . "\$dbPort = " . var_export($dbPort, true) . ";\n"
                          . "\$dbName = " . var_export($dbName, true) . ";\n"
                          . "\$dbUser = " . var_export($dbUser, true) . ";\n"
                          . "\$dbPass = " . var_export($dbPass, true) . ";\n\n"
                          . "try {\n"
                          . "    \$pdo = new PDO(\"mysql:host=\$dbHost;port=\$dbPort;dbname=\$dbName;charset=utf8mb4\", \$dbUser, \$dbPass, [\n"
                          . "        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n"
                          . "        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
                          . "        PDO::ATTR_EMULATE_PREPARES   => false,\n"
                          . "    ]);\n"
                          . "    \$offset = date('P');\n"
                          . "    \$pdo->exec(\"SET time_zone = '\$offset'\");\n"
                          . "} catch (PDOException \$e) {\n"
                          . "    die('Database Connection Error: ' . \$e->getMessage());\n"
                          . "}\n";

            file_put_contents($configFile, $dbConfigCode);

            // Create initial config/business_hours.php array file
            $bizConfigFile = $configDir . '/business_hours.php';
            if (!file_exists($bizConfigFile)) {
                $bizHoursArray = [
                    'Monday'    => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60, 'break_start' => '12:00', 'break_end' => '13:00'],
                    'Tuesday'   => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60, 'break_start' => '12:00', 'break_end' => '13:00'],
                    'Wednesday' => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60, 'break_start' => '12:00', 'break_end' => '13:00'],
                    'Thursday'  => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60, 'break_start' => '12:00', 'break_end' => '13:00'],
                    'Friday'    => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60, 'break_start' => '12:00', 'break_end' => '13:00'],
                    'Saturday'  => ['is_open' => false, 'open_time' => '09:00', 'close_time' => '14:00', 'break_duration' => 0,  'break_start' => '',      'break_end' => ''],
                    'Sunday'    => ['is_open' => false, 'open_time' => '09:00', 'close_time' => '14:00', 'break_duration' => 0,  'break_start' => '',      'break_end' => ''],
                ];
                $bizCode = "<?php\n// config/business_hours.php - Operating Business Hours & Break Schedules\n\nreturn " . var_export($bizHoursArray, true) . ";\n";
                file_put_contents($bizConfigFile, $bizCode);
            }

            header('Location: install_admin.php');
            exit;

        } catch (Exception $ex) {
            $error = 'Setup Error: ' . $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HRMS Setup - Step 1: Database Setup</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #edf2f7; color: #2d3748; padding: 2rem 1rem; margin: 0; }
        .box { max-width: 550px; margin: 0 auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        h1 { margin-top: 0; color: #2b6cb0; font-size: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.35rem; color: #4a5568; }
        input[type="text"], input[type="password"], input[type="number"] { width: 100%; padding: 0.6rem; border: 1px solid #cbd5e0; border-radius: 4px; box-sizing: border-box; }
        .btn { background: #3182ce; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; font-weight: 700; cursor: pointer; width: 100%; font-size: 1rem; margin-top: 1rem; }
        .btn:hover { background: #2b6cb0; }
        .alert-danger { background: #fed7d7; color: #9b2c2c; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="box">
    <h1>Step 1: Database Setup & Master Schema Creation</h1>
    <p style="color: #718096; font-size: 0.9rem;">Enter your database connection credentials to initialize the schema tables, seed baseline lookups, and configure local settings.</p>

    <?php if ($error): ?><div class="alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" action="install.php">
        <div class="form-group">
            <label>Database Host *</label>
            <input type="text" name="db_host" required value="127.0.0.1">
        </div>
        <div class="form-group">
            <label>Port *</label>
            <input type="number" name="db_port" required value="3306">
        </div>
        <div class="form-group">
            <label>Database Name *</label>
            <input type="text" name="db_name" required value="hrms_db">
        </div>
        <div class="form-group">
            <label>Database User *</label>
            <input type="text" name="db_user" required value="root">
        </div>
        <div class="form-group">
            <label>Database Password</label>
            <input type="password" name="db_pass" placeholder="Leave blank if none">
        </div>
        <div class="form-group">
            <label style="font-weight: normal; cursor: pointer;">
                <input type="checkbox" name="include_seed_data" value="1" checked> Include sample seed data (Departments, Job Positions, Shift Templates, Locations)
            </label>
        </div>
        <button type="submit" class="btn">Connect, Build Master Schema & Continue →</button>
    </form>
</div>
</body>
</html>
