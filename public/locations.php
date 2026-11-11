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
    $action  = $_POST['action'] ?? '';
    $locId   = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
    $locName = trim($_POST['location_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city    = trim($_POST['city'] ?? '');
    $state   = trim($_POST['state'] ?? '');
    $zip     = trim($_POST['zip_code'] ?? '');
    $country = trim($_POST['country'] ?? 'USA');

    if ($action === 'delete' && $locId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM locations WHERE location_id = :id');
            $stmt->execute(['id' => $locId]);
            $success = 'Location deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete location: ' . $e->getMessage();
        }
    } elseif ($action === 'update' && $locId) {
        if (!empty($locName) && !empty($address)) {
            $stmt = $pdo->prepare('
                UPDATE locations 
                SET location_name = :name, address = :address, city = :city, state = :state, zip_code = :zip, country = :country 
                WHERE location_id = :id
            ');
            $stmt->execute([
                'name'    => $locName,
                'address' => $address,
                'city'    => $city,
                'state'   => $state,
                'zip'     => $zip,
                'country' => $country,
                'id'      => $locId
            ]);
            $success = 'Location updated successfully.';
        } else {
            $error = 'Location name and street address are required.';
        }
    } elseif ($action === 'create') {
        if (!empty($locName) && !empty($address)) {
            $stmt = $pdo->prepare('
                INSERT INTO locations (location_name, address, city, state, zip_code, country) 
                VALUES (:name, :address, :city, :state, :zip, :country)
            ');
            $stmt->execute([
                'name'    => $locName,
                'address' => $address,
                'city'    => $city,
                'state'   => $state,
                'zip'     => $zip,
                'country' => $country
            ]);
            $success = 'Location created successfully.';
        } else {
            $error = 'Location name and street address are required.';
        }
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

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Locations Management</h2>
            <p class="subtitle">Configure corporate offices, branches, and work locations.</p>
        </div>
    </div>

    <!-- Two-column layout: Left sidebar + Right settings content -->
    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- LEFT COLUMN: Side Navigation Bar -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- RIGHT COLUMN: Locations Settings & Table -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <!-- Add / Edit Location Form Card -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;"><?= $editLoc ? 'Edit Location' : 'Add New Location' ?></h3>
                <form method="POST" action="locations.php">
                    <input type="hidden" name="action" value="<?= $editLoc ? 'update' : 'create' ?>">
                    <?php if ($editLoc): ?>
                        <input type="hidden" name="location_id" value="<?= $editLoc['location_id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="location_name">Location Name *</label>
                            <input type="text" id="location_name" name="location_name" required value="<?= htmlspecialchars($editLoc['location_name'] ?? '') ?>" placeholder="e.g. Headquarters / West Campus">
                        </div>
                        <div class="form-group">
                            <label for="address">Street Address *</label>
                            <input type="text" id="address" name="address" required value="<?= htmlspecialchars($editLoc['address'] ?? '') ?>" placeholder="e.g. 123 Business Rd">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city" value="<?= htmlspecialchars($editLoc['city'] ?? '') ?>" placeholder="e.g. Salt Lake City">
                        </div>
                        <div class="form-group">
                            <label for="state">State / Province</label>
                            <input type="text" id="state" name="state" value="<?= htmlspecialchars($editLoc['state'] ?? '') ?>" placeholder="e.g. UT">
                        </div>
                        <div class="form-group">
                            <label for="zip_code">ZIP / Postal Code</label>
                            <input type="text" id="zip_code" name="zip_code" value="<?= htmlspecialchars($editLoc['zip_code'] ?? '') ?>" placeholder="e.g. 84101">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country" value="<?= htmlspecialchars($editLoc['country'] ?? 'USA') ?>">
                    </div>

                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn-submit"><?= $editLoc ? 'Save Changes' : 'Save Location' ?></button>
                        <?php if ($editLoc): ?>
                            <a href="locations.php" class="btn-reset" style="text-align: center; text-decoration: none;">Cancel</a>
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
                                <th>Name</th>
                                <th>Address</th>
                                <th>City / State</th>
                                <th>Country</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($locations)): ?>
                                <?php foreach ($locations as $loc): ?>
                                    <tr>
                                        <td><?= $loc['location_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($loc['location_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($loc['address']) ?></td>
                                        <td><?= htmlspecialchars($loc['city'] . ($loc['state'] ? ', ' . $loc['state'] : '')) ?></td>
                                        <td><?= htmlspecialchars($loc['country'] ?? 'USA') ?></td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <a href="locations.php?edit_id=<?= $loc['location_id'] ?>" class="btn-reset" style="font-size: 0.8rem; padding: 0.25rem 0.6rem; text-decoration: none;">Edit</a>
                                                <form method="POST" action="locations.php" onsubmit="return confirm('Are you sure you want to delete this location?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="location_id" value="<?= $loc['location_id'] ?>">
                                                    <button type="submit" style="background: #e53e3e; color: white; border: none; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">Delete</button>
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
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
