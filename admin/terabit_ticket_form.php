<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) { redirect(BASE_URL); }
updateLastActivity();

$action = isset($_GET['action']) ? $_GET['action'] : 'create';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmtUser = $pdo->prepare("SELECT sub_name FROM users WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user = $stmtUser->fetch();
$loggedInSLT = $user ? $user['sub_name'] : "Unknown";

$record = null;
$displayTicketId = "NEW"; 

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM network_tickets WHERE id = ?");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    $displayTicketId = "SLT" . str_pad($record['id'], 4, '0', STR_PAD_LEFT);
}

$defaultSltOnDuty = ($id > 0) ? $record['slt_on_duty'] : $loggedInSLT;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $isNew = ($id === 0);
        $timestampReported = $isNew ? date('Y-m-d H:i:s') : $record['date_reported'];

        $data = [
            'date_received' => $_POST['date_received'],
            'date_reported' => $timestampReported,
            'subject'       => sanitizeInput($_POST['subject']),
            'type'          => sanitizeInput($_POST['type']),
            'status'        => sanitizeInput($_POST['status']),
            'slt_on_duty'   => $defaultSltOnDuty,
            'email_link'    => sanitizeInput($_POST['email_link']),
            'is_email'      => (int)$_POST['is_email'] 
        ];

        if ($isNew) {
            $stmt = $pdo->prepare("INSERT INTO network_tickets (".implode(',', array_keys($data)).") VALUES (:".implode(',:', array_keys($data)).")");
            $stmt->execute($data);
            $newId = $pdo->lastInsertId();
            
            $generatedCustomId = "SLT" . str_pad($newId, 4, '0', STR_PAD_LEFT);
            $updateIdStmt = $pdo->prepare("UPDATE network_tickets SET SLT_TICKET_ID = ? WHERE id = ?");
            $updateIdStmt->execute([$generatedCustomId, $newId]);

            logActivity("Created Ticket $generatedCustomId: " . strtoupper($data['subject']), $newId, 'network');
        } else {
            $fields = []; foreach ($data as $k => $v) { $fields[] = "$k = :$k"; }
            $stmt = $pdo->prepare("UPDATE network_tickets SET " . implode(', ', $fields) . " WHERE id = :id");
            $data['id'] = $id;
            $stmt->execute($data);
            logActivity("Updated Ticket $displayTicketId. Status: " . strtoupper($data['status']), $id, 'network');
        }

        $pdo->commit();
        $_SESSION['success'] = "Log entry successfully saved.";
        redirect('terabit_tracker.php');
    } catch (Exception $e) { $pdo->rollBack(); $_SESSION['error'] = "Error: " . $e->getMessage(); }
}

require_once '../components/layout.php';
renderHead($id ? 'Edit Entry' : 'Add Entry');
renderNavbar();
renderSidebar('network');
?>

<div class="pt-2 min-h-screen bg-gray-900 text-white font-sans">
    <main class="p-6 max-w-4xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold"><?= $id ? 'Edit Details' : 'Add New Entry' ?></h1>
                <p class="text-primary-400 text-sm font-mono mt-1">Ticket ID: <span class="bg-primary-500/10 px-2 py-0.5 rounded"><?= $displayTicketId ?></span></p>
            </div>
            <a href="terabit_tracker.php" class="text-gray-400 hover:text-white transition-all"><i class="fas fa-times fa-lg"></i></a>
        </div>
        
        <div class="bg-gray-800 rounded-2xl border border-gray-700 p-8 shadow-2xl">
            <form method="POST" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Date & Time Received</label>
                        <input type="datetime-local" name="date_received" class="w-full bg-gray-900 border border-gray-700 rounded-xl text-white px-4 py-3 focus:ring-2 focus:ring-primary-500 outline-none transition-all" value="<?= $record ? date('Y-m-d\TH:i', strtotime($record['date_received'])) : date('Y-m-d\TH:i') ?>" required>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">SLT on Duty</label>
                        <input type="text" class="w-full bg-gray-900/50 border border-gray-700 rounded-xl text-gray-400 font-bold px-4 py-3 cursor-not-allowed" value="<?= htmlspecialchars($defaultSltOnDuty) ?>" readonly>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Subject / Request Summary</label>
                        <input type="text" name="subject" class="w-full bg-gray-900 border border-gray-700 rounded-xl text-white px-4 py-3 uppercase focus:ring-2 focus:ring-primary-500 outline-none transition-all" value="<?= $record ? htmlspecialchars($record['subject']) : '' ?>" placeholder="e.g., VPN Access Request" required>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Status</label>
                        <select name="status" class="w-full bg-gray-900 border border-gray-700 rounded-xl text-white px-4 py-3 uppercase focus:ring-2 focus:ring-primary-500 outline-none cursor-pointer">
                            <option value="pending" <?= ($record && $record['status'] == 'pending') ? 'selected' : '' ?>>PENDING</option>
                            <option value="close" <?= ($record && $record['status'] == 'close') ? 'selected' : '' ?>>CLOSED</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Inquiry Category</label>
                        <select name="type" class="w-full bg-gray-900 border border-gray-700 rounded-xl text-white px-4 py-3 uppercase focus:ring-2 focus:ring-primary-500 outline-none cursor-pointer">
                            <?php foreach(['Technical', 'Billing', 'Hardware', 'Software', 'Access', 'Other'] as $opt): ?>
                                <option value="<?= strtolower($opt) ?>" <?= ($record && strtolower($record['type']) == strtolower($opt)) ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="border-t border-gray-700 pt-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                        <div class="md:col-span-1">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Inquiry Source</label>
                            <select name="is_email" class="w-full bg-gray-900 border border-gray-700 rounded-xl text-white px-4 py-3 focus:ring-2 focus:ring-primary-500 outline-none cursor-pointer transition-all">
                                <option value="1" <?= (!$record || $record['is_email']) ? 'selected' : '' ?>>Email</option>
                                <option value="0" <?= ($record && !$record['is_email']) ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Reference Link</label>
                            <input type="text" name="email_link" placeholder="Paste Reference Link..." class="w-full bg-gray-900 border border-gray-700 rounded-xl px-4 py-3 text-white focus:ring-2 focus:ring-primary-500 outline-none transition-all" value="<?= $record ? $record['email_link'] : '' ?>">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-4 pt-4">
                    <a href="terabit_tracker.php" class="bg-gray-600 hover:bg-gray-700 px-4 py-2 rounded-lg transition-all"><i class="fa-solid fa-x mr-2"></i>Cancel</a>
                    <button type="submit" class="bg-primary-600 hover:bg-primary-700 px-4 py-2 rounded-lg transition-all"><i class="fa-solid fa-floppy-disk mr-2"></i>Save Record</button>
                </div>
            </form>
        </div>
    </main>
</div>
<?php renderFooter(); ?>