<?php
// public/locations.php - Company Locations Management
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

$error = '';
$success = '';

// Handle Form Submissions (Create / Update / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? 'create';
    $locationId = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);

    try {
        if ($action === 'delete' && $locationId) {
            $stmt = $pdo->prepare('DELETE FROM locations WHERE location_id = :id');
            $stmt->execute(['id' => $locationId]);
            $success = 'Location deleted successfully.';
        } else {
            $locationName = trim($_POST['location_name'] ?? '');
            $address1     = trim($_POST['address_line1'] ?? '');
            $address2     = trim($_POST['address_line2'] ?? '');
            $city         = trim($_POST['city'] ?? '');
            $state        = trim($_POST['state_province'] ?? '');
            $postalCode   = trim($_POST['postal_code'] ?? '');
            $country      = trim($_POST['country'] ?? 'USA');
            $phoneNumber  = trim($_POST['phone_number'] ?? '');
            $isActive     = isset($_POST['is_active']) ? 1 : 0;

            if (empty($locationName) || empty($address1) || empty($city) || empty($state) || empty($postalCode)) {
                $error = 'Location Name, Address Line 1, City, State/Province, and Postal Code are required.';
            } elseif ($action === 'update' && $locationId) {
                $stmt = $pdo->prepare('
                    UPDATE locations 
                    SET location_name = :name,
                        address_line1 = :addr1,
                        address_line2 = :addr2,
                        city = :city,
                        state_province = :state,
                        postal_code = :zip,
                        country = :country,
                        phone_number = :phone,
                        is_active = :active
                    WHERE location_id = :id
                ');
                $stmt->execute([
                    'name'    => $locationName,
                    'addr1'   => $address1,
                    'addr2'   => $address2 !== '' ? $address2 : null,
                    'city'    => $city,
                    'state'   => $state,
                    'zip'     => $postalCode,
                    'country' => $country !== '' ? $country : 'USA',
                    'phone'   => $phoneNumber !== '' ? $phoneNumber : null,
                    'active'  => $isActive,
                    'id'      => $locationId
                ]);
                $success = 'Location updated successfully.';
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO locations (location_name, address_line1, address_line2, city, state_province, postal_code, country, phone_number, is_active) 
                    VALUES (:name, :addr1, :addr2, :city, :state, :zip, :country, :phone, :active)
                ');
                $stmt->execute([
                    'name'    => $locationName,
                    'addr1'   => $address1,
                    'addr2'   => $address2 !== '' ? $address2 : null,
                    'city'    => $city,
                    'state'   => $state,
                    'zip'     => $postalCode,
                    'country' => $country !== '' ? $country : 'USA',
                    'phone'   => $phoneNumber !== '' ? $phoneNumber : null,
                    'active'  => $isActive
                ]);
                $success = 'Location created successfully.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Fetch record for inline editing if edit_id is passed
$editLoc = null;
$editId  = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($editId) {
    $editStmt = $pdo->prepare('SELECT * FROM locations WHERE location_id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editLoc = $editStmt->fetch();
}

$locations = $pdo->query('SELECT * FROM locations ORDER BY location_name ASC')->fetchAll();

$pageTitle = 'Locations Management';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .selection-card {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.85rem 1rem;
        border: 1px solid #cbd5e0;
        border-radius: 6px;
        background: #f8fafc;
        cursor: pointer;
        transition: all 0.15s ease;
        margin-top: 0.5rem;
    }
    .selection-card:hover {
        border-color: #2b6cb0;
        background: #ebf8ff;
    }
    .selection-card input[type="checkbox"] {
        margin-top: 0.2rem;
        width: 16px;
        height: 16px;
        accent-color: #2b6cb0;
    }
    .selection-card-title {
        font-weight: 700;
        color: #1a202c;
        display: block;
        font-size: 0.92rem;
    }
    .selection-card-desc {
        font-size: 0.8rem;
        color: #4a5568;
        display: block;
        margin-top: 0.15rem;
    }
</style>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Locations Management</h2>
            <p class="subtitle">Configure corporate offices, branches, and work locations.</p>
        </div>
    </div>

    <div class="settings-layout">
        <!-- Reusable Side Navigation -->
        <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>

        <!-- Main Settings Content Area -->
        <main class="settings-content">
            <?php if ($error): ?>
                <div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <!-- Add / Edit Location Form Card -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;">
                    <?= $editLoc ? 'Edit Location' : 'Add New Location' ?>
                </h3>
                <form method="POST" action="locations.php">
                    <input type="hidden" name="action" value="<?= $editLoc ? 'update' : 'create' ?>">
                    <?php if ($editLoc): ?>
                        <input type="hidden" name="location_id" value="<?= $editLoc['location_id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="location_name">Location Name *</label>
                            <input type="text" id="location_name" name="location_name" required placeholder="e.g. Corporate Headquarters" value="<?= htmlspecialchars($editLoc['location_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="phone_number">Phone Number</label>
                            <input type="text" id="phone_number" name="phone_number" placeholder="e.g. +1 (555) 019-2834" value="<?= htmlspecialchars($editLoc['phone_number'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="address_line1">Address Line 1 *</label>
                            <input type="text" id="address_line1" name="address_line1" required placeholder="e.g. 100 Enterprise Way" value="<?= htmlspecialchars($editLoc['address_line1'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="address_line2">Address Line 2</label>
                            <input type="text" id="address_line2" name="address_line2" placeholder="Suite, Floor, Building (Optional)" value="<?= htmlspecialchars($editLoc['address_line2'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="city">City *</label>
                            <input type="text" id="city" name="city" required placeholder="e.g. Salt Lake City" value="<?= htmlspecialchars($editLoc['city'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="state_province">State / Province *</label>
                            <input type="text" id="state_province" name="state_province" required placeholder="e.g. UT" value="<?= htmlspecialchars($editLoc['state_province'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="postal_code">Postal / ZIP Code *</label>
                            <input type="text" id="postal_code" name="postal_code" required placeholder="e.g. 84101" value="<?= htmlspecialchars($editLoc['postal_code'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="country">Country *</label>
                            <input type="text" id="country" name="country" required value="<?= htmlspecialchars($editLoc['country'] ?? 'USA') ?>">
                        </div>
                    </div>

                    <label class="selection-card">
                        <input type="checkbox" name="is_active" value="1" <?= ($editLoc['is_active'] ?? 1) ? 'checked' : '' ?>>
                        <div>
                            <span class="selection-card-title">Active Location</span>
                            <span class="selection-card-desc">Enable this site for department assignments, employee profiles, and shift scheduling.</span>
                        </div>
                    </label>

                    <div style="display: flex; gap: 0.75rem; margin-top: 1.25rem;">
                        <button type="submit" class="btn-submit">
                            <?= $editLoc ? 'Update Location' : 'Save Location' ?>
                        </button>
                        <?php if ($editLoc): ?>
                            <a href="locations.php" class="btn-reset" style="text-decoration: none;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Existing Locations List Card -->
            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Existing Locations</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Location Name</th>
                                <th>Address</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($locations)): ?>
                                <?php foreach ($locations as $loc): ?>
                                    <tr>
                                        <td><?= $loc['location_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($loc['location_name']) ?></strong></td>
                                        <td>
                                            <?= htmlspecialchars($loc['address_line1']) ?><?= !empty($loc['address_line2']) ? ', ' . htmlspecialchars($loc['address_line2']) : '' ?><br>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($loc['city'] . ', ' . $loc['state_province'] . ' ' . $loc['postal_code'] . ' (' . ($loc['country'] ?? 'USA') . ')') ?>
                                            </small>
                                        </td>
                                        <td><?= htmlspecialchars($loc['phone_number'] ?? 'N/A') ?></td>
                                        <td>
                                            <span class="badge <?= !empty($loc['is_active']) ? 'badge-active' : 'badge-terminated' ?>">
                                                <?= !empty($loc['is_active']) ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="locations.php?edit_id=<?= $loc['location_id'] ?>" class="btn-edit">Edit</a>
                                                <form method="POST" action="locations.php" onsubmit="return confirm('Are you sure you want to delete this location?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="location_id" value="<?= $loc['location_id'] ?>">
                                                    <button type="submit" class="btn-delete">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #a0aec0;">No locations configured yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
