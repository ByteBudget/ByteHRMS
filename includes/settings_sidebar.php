<?php
// includes/settings_sidebar.php - Global Reusable Settings Sidebar Navigation
require_once __DIR__ . '/auth.php';

$currentTab  = $activeTab ?? $_GET['tab'] ?? 'account';
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<style>
.settings-sidebar {
    width: 260px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1rem 0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    flex-shrink: 0;
}

.sidebar-section-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #a0aec0;
    padding: 0.75rem 1.25rem 0.25rem;
    letter-spacing: 0.05em;
}

.sidebar-nav-item {
    display: flex;
    align-items: center;
    padding: 0.65rem 1.25rem;
    color: #4a5568;
    text-decoration: none;
    font-size: 0.95rem;
    font-weight: 500;
    border-left: 3px solid transparent;
    transition: all 0.2s ease;
}

.sidebar-nav-item:hover {
    background-color: #f7fafc;
    color: #2b6cb0;
}

.sidebar-nav-item.active {
    background-color: #ebf8ff;
    color: #2b6cb0;
    border-left-color: #3182ce;
    font-weight: 600;
}
</style>

<aside class="settings-sidebar">
    <div class="sidebar-section-title">Personal</div>
    <a href="settings.php?tab=account" class="sidebar-nav-item <?= ($currentPage === 'settings.php' && $currentTab === 'account') ? 'active' : '' ?>">
        My Account
    </a>

    <?php if (hasRole('admin')): ?>
        <div class="sidebar-section-title" style="margin-top: 1rem;">Organization & Roles</div>
        <a href="positions.php" class="sidebar-nav-item <?= $currentPage === 'positions.php' ? 'active' : '' ?>">Job Positions</a>
        <a href="departments.php" class="sidebar-nav-item <?= $currentPage === 'departments.php' ? 'active' : '' ?>">Departments</a>
        <a href="shift_templates.php" class="sidebar-nav-item <?= $currentPage === 'shift_templates.php' ? 'active' : '' ?>">Shift Templates</a>

        <div class="sidebar-section-title" style="margin-top: 1rem;">Company & Payroll</div>
        <a href="company_details.php" class="sidebar-nav-item <?= $currentPage === 'company_details.php' ? 'active' : '' ?>">Company Overview</a>
        <a href="business_hours.php" class="sidebar-nav-item <?= $currentPage === 'business_hours.php' ? 'active' : '' ?>">Business Hours</a>
        <a href="payroll_settings.php" class="sidebar-nav-item <?= $currentPage === 'payroll_settings.php' ? 'active' : '' ?>">Payroll Settings</a>
        <a href="locations.php" class="sidebar-nav-item <?= $currentPage === 'locations.php' ? 'active' : '' ?>">Locations</a>

        <div class="sidebar-section-title" style="margin-top: 1rem;">Benefits & Leave</div>
        <a href="leave_types.php" class="sidebar-nav-item <?= $currentPage === 'leave_types.php' ? 'active' : '' ?>">PTO Categories</a>
        <a href="leave_balances.php" class="sidebar-nav-item <?= $currentPage === 'leave_balances.php' ? 'active' : '' ?>">Employee PTO Balances</a>
        <a href="benefit_plans.php" class="sidebar-nav-item <?= $currentPage === 'benefit_plans.php' ? 'active' : '' ?>">Benefit Plans</a>
        <a href="benefit_enrollments.php" class="sidebar-nav-item <?= $currentPage === 'benefit_enrollments.php' ? 'active' : '' ?>">Benefit Enrollments</a>

        <div class="sidebar-section-title" style="margin-top: 1rem;">System & Security</div>
        <a href="ldap_connect.php" class="sidebar-nav-item <?= $currentPage === 'ldap_connect.php' ? 'active' : '' ?>">LDAP Integration</a>
        <a href="sync_ldap.php" class="sidebar-nav-item <?= $currentPage === 'sync_ldap.php' ? 'active' : '' ?>">LDAP Directory Sync</a>
    <?php endif; ?>
</aside>
