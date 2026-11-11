-- Disable foreign key checks for bulk seeding
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- 1. SEED RBAC PERMISSIONS & ROLES
-- --------------------------------------------------------

INSERT IGNORE INTO permissions (permission_id, permission_code, module, description) VALUES
(1,  'all:manage', 'System', 'Full administrative access across all modules'),
(2,  'employee:read', 'Employee', 'View employee general profile'),
(3,  'employee:write', 'Employee', 'Create and edit employee profiles'),
(4,  'schedule:read', 'Scheduling', 'View rosters and shift assignments'),
(5,  'schedule:write', 'Scheduling', 'Create and edit employee schedules'),
(6,  'time:read', 'Attendance', 'View attendance logs'),
(7,  'time:approve', 'Attendance', 'Approve attendance logs'),
(8,  'leave:request', 'Leave', 'Submit self leave requests'),
(9,  'leave:approve', 'Leave', 'Approve direct report leave requests'),
(10, 'pay:view_sensitive', 'Compensation', 'View raw wage and compensation records'),
(11, 'citations:manage', 'Disciplinary', 'Issue and manage write-ups/citations'),
(12, 'citations:acknowledge', 'Disciplinary', 'Acknowledge personal write-ups'),
(13, 'certifications:upload', 'Certifications', 'Upload personal certificates'),
(14, 'certifications:verify', 'Certifications', 'Verify compliance certificates');

INSERT IGNORE INTO roles (role_id, role_name, description) VALUES
(1, 'admin', 'Administrator with full system control'),
(2, 'manager', 'Departmental Manager with staff supervision capability'),
(3, 'hr_specialist', 'HR Staff responsible for management and onboarding'),
(4, 'employee', 'Standard self-service employee access');

-- Map permissions to roles
-- Admin: all:manage
INSERT IGNORE INTO role_permissions (role_id, permission_id) 
SELECT 1, permission_id FROM permissions;

-- Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id) 
SELECT 2, permission_id FROM permissions WHERE permission_code IN (
    'employee:read', 'schedule:read', 'schedule:write', 'time:read', 
    'time:approve', 'leave:approve', 'citations:manage', 'certifications:upload'
);

-- HR Specialist
INSERT IGNORE INTO role_permissions (role_id, permission_id) 
SELECT 3, permission_id FROM permissions WHERE permission_code IN (
    'employee:read', 'employee:write', 'schedule:read', 'time:read', 
    'leave:approve', 'pay:view_sensitive', 'citations:manage', 'certifications:verify'
);

-- Employee Self-Service
INSERT IGNORE INTO role_permissions (role_id, permission_id) 
SELECT 4, permission_id FROM permissions WHERE permission_code IN (
    'employee:read', 'schedule:read', 'time:read', 'leave:request', 
    'citations:acknowledge', 'certifications:upload'
);

-- --------------------------------------------------------
-- 2. SEED CORE LOOKUPS & SYSTEM CATEGORIES
-- --------------------------------------------------------

INSERT IGNORE INTO pay_types (pay_type_id, pay_type_code, description) VALUES
(1, 'hourly', 'Hourly Wage Rate'),
(2, 'flat_rate', 'Salaried Flat Rate'),
(3, 'commission', 'Commission-Based Model'),
(4, 'piece_rate', 'Pay per Unit Produced');

INSERT IGNORE INTO leave_types (leave_type_id, type_name, is_paid) VALUES
(1, 'Paid Time Off (PTO)', TRUE),
(2, 'Sick Leave', TRUE),
(3, 'Unpaid Leave', FALSE),
(4, 'Bereavement', TRUE),
(5, 'Parental Leave', TRUE);

INSERT IGNORE INTO benefit_plans (plan_id, plan_name, provider_name, plan_type, description) VALUES
(1, 'Standard Health PPO', 'BlueCross', 'medical', 'Comprehensive standard medical coverage'),
(2, 'Delta Dental Premier', 'Delta', 'dental', 'Preventative and diagnostic dental care'),
(3, 'Corporate 401(k) Match', 'Fidelity', '401k', '401(k) plan with up to 4% matching');

INSERT IGNORE INTO shift_templates (shift_template_id, template_name, default_start_time, default_end_time, unpaid_break_minutes) VALUES
(1, 'Morning Shift', '08:00:00', '16:30:00', 30),
(2, 'Swing Shift', '16:00:00', '00:30:00', 30),
(3, 'Overnight Shift', '00:00:00', '08:30:00', 30);

-- --------------------------------------------------------
-- 3. SEED ORGANIZATIONAL STRUCTURE
-- --------------------------------------------------------

INSERT IGNORE INTO departments (department_id, department_name) VALUES 
(1, 'Executive Leadership'), 
(2, 'Software Engineering'), 
(3, 'Human Resources');

INSERT IGNORE INTO job_positions (position_id, job_title, department_id, min_salary, max_salary) VALUES
(1, 'VP of Engineering', 2, 130000.00, 200000.00),
(2, 'Engineering Manager', 2, 100000.00, 150000.00),
(3, 'Software Engineer', 2, 70000.00, 120000.00),
(4, 'HR Generalist', 3, 55000.00, 85000.00);

-- --------------------------------------------------------
-- 4. SEED SAMPLE USERS & EMPLOYEES
-- Password for all seed accounts below is: Password123!
-- --------------------------------------------------------

INSERT IGNORE INTO users (user_id, username, email, password_hash, is_active, is_ldap_user) VALUES
(1, 'sys_admin', 'admin@company.com', '$2y$10$wE23b20mY6.u5q8Y1O/0uuE25vL3A5o4g.B2s4N.u9mE.m8E5c22K', 1, 0),
(2, 'mgr_smith', 'jsmith@company.com', '$2y$10$wE23b20mY6.u5q8Y1O/0uuE25vL3A5o4g.B2s4N.u9mE.m8E5c22K', 1, 0),
(3, 'emp_doe', 'jdoe@company.com', '$2y$10$wE23b20mY6.u5q8Y1O/0uuE25vL3A5o4g.B2s4N.u9mE.m8E5c22K', 1, 0);

-- User Role Assignments
INSERT IGNORE INTO user_account_roles (user_id, role_id) VALUES
(1, 1), -- sys_admin -> Admin
(2, 2), -- mgr_smith -> Manager
(3, 4); -- emp_doe   -> Employee

-- Employee Profiles
INSERT IGNORE INTO employees (employee_id, user_id, first_name, last_name, work_email, hire_date, employment_status, employment_type, department_id, position_id, manager_id) VALUES
(1, 1, 'System', 'Admin', 'admin@company.com', '2020-01-01', 'active', 'full_time', 1, 1, NULL),
(2, 2, 'Jane', 'Smith', 'jsmith@company.com', '2021-03-15', 'active', 'full_time', 2, 2, 1),
(3, 3, 'John', 'Doe', 'jdoe@company.com', '2022-06-01', 'active', 'full_time', 2, 3, 2);

-- Update Department Head Link
UPDATE departments SET manager_employee_id = 2 WHERE department_id = 2;

-- --------------------------------------------------------
-- 5. SEED COMPENSATION & INITIAL LEAVE BALANCES
-- --------------------------------------------------------

INSERT IGNORE INTO compensation_history (employee_id, pay_type_id, pay_rate, pay_frequency, effective_start_date) VALUES
(1, 2, 150000.00, 'monthly', '2020-01-01'),
(2, 2, 110000.00, 'bi_weekly', '2021-03-15'),
(3, 1, 45.00, 'bi_weekly', '2022-06-01');

INSERT IGNORE INTO leave_balances (employee_id, leave_type_id, fiscal_year, total_allocated, used_hours, pending_hours) VALUES
(1, 1, YEAR(CURRENT_DATE()), 160.00, 0.00, 0.00),
(2, 1, YEAR(CURRENT_DATE()), 140.00, 16.00, 0.00),
(3, 1, YEAR(CURRENT_DATE()), 120.00, 8.00, 0.00);

-- Enable foreign key checks back on
SET FOREIGN_KEY_CHECKS = 1;
