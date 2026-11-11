<?php
// public/company_details.php - Company Overview & Profile Configuration
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

if (!hasRole('admin')) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="alert-danger"><strong>Access Denied:</strong> Admin privileges required.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$configFile = __DIR__ . '/../config/company.php';

if (file_exists($configFile)) {
    $company = require $configFile;
} else {
    $company = [
        'name'      => 'ACME INC',
        'tax_id'    => '12-3456789',
        'email'     => 'admin@example.com',
        'phone'     => '(555) 019-2834',
        'website'   => 'https://www.example.com',
        'address'   => '100 Main Street, Suite 200',
        'time_zone' => 'America/Denver'
    ];
}

$error   = '';
$success = '';

// Handle Form Submissions (Update Company Details)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $taxId    = trim($_POST['tax_id'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $website  = trim($_POST['website'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $timeZone = trim($_POST['time_zone'] ?? 'America/Denver');

    if (!empty($name) && !empty($email)) {
        $updatedCompany = [
            'name'      => $name,
            'tax_id'    => $taxId,
            'email'     => $email,
            'phone'     => $phone,
            'website'   => $website,
            'address'   => $address,
            'time_zone' => $timeZone
        ];

        $exportCode = "<?php\n// config/company.php - Global Company Details\n\nreturn " . var_export($updatedCompany, true) . ";\n";

        if (@file_put_contents($configFile, $exportCode) !== false) {
            $company = $updatedCompany;
            $success = 'Company details updated successfully.';
        } else {
            $error = 'Failed to write to `config/company.php`. Please verify server permissions.';
        }
    } else {
        $error = 'Company name and primary email address are required.';
    }
}

$pageTitle = 'Company Details & Overview';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Company Overview & Details</h2>
            <p class="subtitle">Manage primary organizational information, contact info, and tax identifier defaults.</p>
        </div>
    </div>

    <!-- Two-column layout: Left sidebar + Right settings content -->
    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- LEFT COLUMN: Side Navigation Bar -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- RIGHT COLUMN: Company Details Settings Form -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?>
                <div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">General Corporate Information</h3>
                <form method="POST" action="company_details.php">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Legal Company Name *</label>
                            <input type="text" id="name" name="name" required value="<?= htmlspecialchars($company['name'] ?? '') ?>" placeholder="e.g. ACME LLC">
                        </div>
                        <div class="form-group">
                            <label for="tax_id">Federal Tax ID / EIN</label>
                            <input type="text" id="tax_id" name="tax_id" value="<?= htmlspecialchars($company['tax_id'] ?? '') ?>" placeholder="e.g. 12-3456789">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Primary Corporate Email *</label>
                            <input type="email" id="email" name="email" required value="<?= htmlspecialchars($company['email'] ?? '') ?>" placeholder="e.g. admin@company.com">
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($company['phone'] ?? '') ?>" placeholder="e.g. (555) 019-2834">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="website">Company Website</label>
                            <input type="url" id="website" name="website" value="<?= htmlspecialchars($company['website'] ?? '') ?>" placeholder="https://company.com">
                        </div>
                        <div class="form-group">
                            <label for="time_zone">Primary Timezone</label>
                            <select id="time_zone" name="time_zone">
                                <option value="America/Denver" <?= ($company['time_zone'] ?? '') === 'America/Denver' ? 'selected' : '' ?>>America/Denver (MT)</option>
                                <option value="America/New_York" <?= ($company['time_zone'] ?? '') === 'America/New_York' ? 'selected' : '' ?>>America/New_York (ET)</option>
                                <option value="America/Chicago" <?= ($company['time_zone'] ?? '') === 'America/Chicago' ? 'selected' : '' ?>>America/Chicago (CT)</option>
                                <option value="America/Los_Angeles" <?= ($company['time_zone'] ?? '') === 'America/Los_Angeles' ? 'selected' : '' ?>>America/Los_Angeles (PT)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address">Corporate Address</label>
                        <input type="text" id="address" name="address" value="<?= htmlspecialchars($company['address'] ?? '') ?>" placeholder="100 Main Street, Suite 200">
                    </div>

                    <button type="submit" class="btn-submit" style="margin-top: 1.5rem;">Save Company Details</button>
                </form>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
