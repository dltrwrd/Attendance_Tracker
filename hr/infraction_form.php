<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isHR()) {
    redirect(BASE_URL);
}

updateLastActivity();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$infractionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $infractionId = (int)$_POST['infraction_id'];
    
    try {
        $pdo->beginTransaction();
        
        $rule_section = sanitizeInput($_POST['rule_section']);
        $nature_of_offense = sanitizeInput($_POST['nature_of_offense']);
        $stipulation = sanitizeInput($_POST['stipulation']);
        $specific_offenses = sanitizeInput($_POST['specific_offenses']);
        $first_instance = sanitizeInput($_POST['first_instance']);
        $second_instance = sanitizeInput($_POST['second_instance']);
        $third_instance = sanitizeInput($_POST['third_instance']);
        $fourth_instance = sanitizeInput($_POST['fourth_instance']);

        // Check if infraction exists
        $stmt = $pdo->prepare("SELECT id FROM infractions WHERE id = ?");
        $stmt->execute([$infractionId]);

        if ($stmt->rowCount() > 0) {
            // Update existing infraction
            $stmt = $pdo->prepare("UPDATE infractions SET rule_section = ?, nature_of_offense = ?, stipulation = ?, specific_offenses = ?, first_instance = ?, second_instance = ?, third_instance = ?, fourth_instance = ? WHERE id = ?");
            $stmt->execute([$rule_section, $nature_of_offense, $stipulation, $specific_offenses, $first_instance, $second_instance, $third_instance, $fourth_instance, $infractionId]);
        } else {
            // Insert new infraction
            $stmt = $pdo->prepare("INSERT INTO infractions (rule_section, nature_of_offense, stipulation, specific_offenses, first_instance, second_instance, third_instance, fourth_instance) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$rule_section, $nature_of_offense, $stipulation, $specific_offenses, $first_instance, $second_instance, $third_instance, $fourth_instance]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Infraction saved successfully!";
        redirect('infractions.php');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error saving infraction: " . $e->getMessage();
        redirect('infraction_form.php?id=' . $infractionId);
    }
}

// Get infraction data
$infraction = null;
if ($infractionId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM infractions WHERE id = ?");
        $stmt->execute([$infractionId]);
        $infraction = $stmt->fetch();
        
        if (!$infraction) {
            $_SESSION['error'] = "Infraction not found";
            redirect('infractions.php');
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching data: " . $e->getMessage();
        redirect('infractions.php');
    }
} elseif ($action !== 'create') {
    redirect('infractions.php');
}

require_once '../components/layout.php';
renderHead($infractionId ? 'Edit Infraction' : 'Add Infraction');
renderNavbar();
renderSidebar('infractions');
?>

<div class="pt-2 min-h-screen">
    <main class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold"><?= $infractionId ? 'Edit Infraction' : 'Add New Infraction' ?></h1>
            <a href="infractions.php" class="text-gray-400 hover:text-white">
                <i class="fas fa-times fa-lg"></i>
            </a>
        </div>

        <?php renderAlert(); ?>

        <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 shadow">
            <form method="POST">
                <input type="hidden" name="infraction_id" value="<?= $infractionId ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <div>
                            <label for="rule_section" class="block text-sm font-medium text-gray-300 mb-2">Rule Section</label>
                            <input type="text" class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   id="rule_section" name="rule_section" 
                                   value="<?= $infraction ? htmlspecialchars($infraction['rule_section']) : '' ?>" required>
                        </div>
                        
                        <div>
                            <label for="nature_of_offense" class="block text-sm font-medium text-gray-300 mb-2">Nature of Offense</label>
                            <textarea class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                      id="nature_of_offense" name="nature_of_offense" 
                                      rows="3" required><?= $infraction ? htmlspecialchars($infraction['nature_of_offense']) : '' ?></textarea>
                        </div>
                        
                        <div>
                            <label for="stipulation" class="block text-sm font-medium text-gray-300 mb-2">Stipulation</label>
                            <textarea class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                      id="stipulation" name="stipulation" 
                                      rows="3" required><?= $infraction ? htmlspecialchars($infraction['stipulation']) : '' ?></textarea>
                        </div>
                        
                        <div>
                            <label for="specific_offenses" class="block text-sm font-medium text-gray-300 mb-2">Specific Offenses</label>
                            <textarea class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                      id="specific_offenses" name="specific_offenses" 
                                      rows="3" required><?= $infraction ? htmlspecialchars($infraction['specific_offenses']) : '' ?></textarea>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <div>
                            <label for="first_instance" class="block text-sm font-medium text-gray-300 mb-2">1st Instance</label>
                            <input type="text" class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   id="first_instance" name="first_instance" 
                                   value="<?= $infraction ? htmlspecialchars($infraction['first_instance']) : '' ?>" required>
                        </div>
                        
                        <div>
                            <label for="second_instance" class="block text-sm font-medium text-gray-300 mb-2">2nd Instance</label>
                            <input type="text" class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   id="second_instance" name="second_instance" 
                                   value="<?= $infraction ? htmlspecialchars($infraction['second_instance']) : '' ?>" required>
                        </div>
                        
                        <div>
                            <label for="third_instance" class="block text-sm font-medium text-gray-300 mb-2">3rd Instance</label>
                            <input type="text" class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   id="third_instance" name="third_instance" 
                                   value="<?= $infraction ? htmlspecialchars($infraction['third_instance']) : '' ?>" required>
                        </div>
                        
                        <div>
                            <label for="fourth_instance" class="block text-sm font-medium text-gray-300 mb-2">4th Instance</label>
                            <input type="text" class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   id="fourth_instance" name="fourth_instance" 
                                   value="<?= $infraction ? htmlspecialchars($infraction['fourth_instance']) : '' ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="flex space-x-3 pt-6">
                    <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg flex items-center">
                        <i class="fas fa-save mr-2"></i> Save
                    </button>
                    <a href="infractions.php" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg flex items-center">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </main>
</div>

<?php renderFooter(); ?>