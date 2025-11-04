<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/nte_functions.php';

if (!isLoggedIn() || !isHR()) {
    redirect(BASE_URL);
}

updateLastActivity();

// Handle NTE actions
if (isset($_POST['action'])) {
    $nte_id = (int)$_POST['nte_id'];
    
    try {
        switch ($_POST['action']) {
            case 'issue':
                $stmt = $pdo->prepare("UPDATE notice_to_explain 
                                      SET nte_status = 'issued', 
                                          date_issued = CURDATE(),
                                          violation_cited = ?,
                                          sanction_proposed = ?,
                                          hr_comments = ?
                                      WHERE id = ?");
                $stmt->execute([
                    $_POST['violation_cited'],
                    $_POST['sanction_proposed'],
                    $_POST['hr_comments'],
                    $nte_id
                ]);
                $_SESSION['success'] = "NTE issued successfully!";
                break;
                
            case 'save_draft':
                $stmt = $pdo->prepare("UPDATE notice_to_explain 
                                      SET violation_cited = ?, 
                                          sanction_proposed = ?, 
                                          hr_comments = ?
                                      WHERE id = ?");
                $stmt->execute([
                    $_POST['violation_cited'],
                    $_POST['sanction_proposed'],
                    $_POST['hr_comments'],
                    $nte_id
                ]);
                $_SESSION['success'] = "NTE draft updated successfully!";
                break;
                
            case 'mark_answered':
                $uploaded_file = null;
                
                // Handle file upload
                if (isset($_FILES['nte_file']) && $_FILES['nte_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../uploads/nte/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Validate file type
                    $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                    $file_extension = strtolower(pathinfo($_FILES['nte_file']['name'], PATHINFO_EXTENSION));
                    
                    if (in_array($file_extension, $allowed_types)) {
                        // Validate file size (10MB max)
                        if ($_FILES['nte_file']['size'] <= 10 * 1024 * 1024) {
                            $filename = 'nte_' . $nte_id . '_' . time() . '.' . $file_extension;
                            $file_path = $upload_dir . $filename;
                            
                            if (move_uploaded_file($_FILES['nte_file']['tmp_name'], $file_path)) {
                                $uploaded_file = $filename;
                            } else {
                                $_SESSION['error'] = "Failed to upload file. Please try again.";
                            }
                        } else {
                            $_SESSION['error'] = "File size too large. Maximum size is 10MB.";
                        }
                    } else {
                        $_SESSION['error'] = "Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX";
                    }
                }
                
                if (!isset($_SESSION['error'])) {
                    $stmt = $pdo->prepare("UPDATE notice_to_explain 
                                          SET nte_status = 'answered',
                                              employee_explanation = ?,
                                              uploaded_file = ?,
                                              file_uploaded_at = NOW(),
                                              date_answered = CURDATE()
                                          WHERE id = ?");
                    $stmt->execute([
                        $_POST['employee_explanation'],
                        $uploaded_file,
                        $nte_id
                    ]);
                    $_SESSION['success'] = "NTE marked as answered" . ($uploaded_file ? " with document upload!" : "!");
                }
                break;
                
            case 'mark_for_decision':
                $stmt = $pdo->prepare("UPDATE notice_to_explain 
                                      SET nte_status = 'for_decision',
                                          hr_recommendation = ?,
                                          date_reviewed = CURDATE()
                                      WHERE id = ?");
                $stmt->execute([
                    $_POST['hr_recommendation'],
                    $nte_id
                ]);
                $_SESSION['success'] = "NTE marked for decision!";
                break;
                
            case 'close_nte':
                $final_sanction = $_POST['final_sanction'] ?? $_POST['sanction_proposed'];
                $stmt = $pdo->prepare("UPDATE notice_to_explain 
                                      SET nte_status = 'closed',
                                          final_sanction = ?,
                                          closing_remarks = ?,
                                          date_closed = CURDATE()
                                      WHERE id = ?");
                $stmt->execute([
                    $final_sanction,
                    $_POST['closing_remarks'],
                    $nte_id
                ]);
                $_SESSION['success'] = "NTE closed successfully!";
                break;
        }
        
        // Redirect to refresh the page with updated data
        redirect('nte_review.php?id=' . $nte_id);
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
        redirect('nte_review.php?id=' . $nte_id);
    }
}

$nte_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$nte = null;

if ($nte_id > 0) {
    $stmt = $pdo->prepare("SELECT nte.*, ir.infraction as ir_infraction, ir.incident_details as original_incident
                          FROM notice_to_explain nte
                          JOIN incident_report ir ON nte.ir_id = ir.id
                          WHERE nte.id = ?");
    $stmt->execute([$nte_id]);
    $nte = $stmt->fetch();
    
    if (!$nte) {
        $_SESSION['error'] = "NTE not found";
        redirect('nte_list.php');
    }
} else {
    redirect('nte_list.php');
}

require_once '../components/layout.php';
renderHead('Review Notice to Explain');
renderNavbar();
renderSidebar('nte');
?>

<div class="pt-2 min-h-screen">
    <main class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Review Notice to Explain</h1>
            <div class="flex space-x-3">
                <a href="nte_list.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to List
                </a>
                <a href="employee_history.php?employee_id=<?= $nte['employee_id'] ?>" 
                   class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-history mr-2"></i> View History
                </a>
            </div>
        </div>

        <?php renderAlert(); ?>

        <!-- Status Progress Bar -->
        <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-6">
            <h3 class="text-lg font-bold text-white mb-4">NTE Status</h3>
            <div class="flex items-center justify-between mb-2">
                <?php
                $steps = [
                    'draft' => ['label' => 'Draft', 'color' => 'bg-yellow-500'],
                    'issued' => ['label' => 'Issued', 'color' => 'bg-blue-500'],
                    'answered' => ['label' => 'Answered', 'color' => 'bg-purple-500'],
                    'for_decision' => ['label' => 'For Decision', 'color' => 'bg-orange-500'],
                    'closed' => ['label' => 'Closed', 'color' => 'bg-green-500']
                ];
                
                $current_step = array_search($nte['nte_status'], array_keys($steps));
                ?>
                
                <?php foreach ($steps as $status => $step): ?>
                    <?php
                    $step_index = array_search($status, array_keys($steps));
                    $is_completed = $step_index <= $current_step;
                    $is_current = $status === $nte['nte_status'];
                    ?>
                    
                    <div class="flex flex-col items-center flex-1">
                        <div class="flex items-center w-full">
                            <?php if ($step_index > 0): ?>
                                <div class="flex-1 h-1 <?= $step_index <= $current_step ? 'bg-green-500' : 'bg-gray-600' ?>"></div>
                            <?php endif; ?>
                            
                            <div class="relative">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center 
                                    <?= $is_completed ? $step['color'] . ' text-white' : 'bg-gray-600 text-gray-300' ?>
                                    <?= $is_current ? ' ring-2 ring-offset-2 ring-offset-gray-800 ring-white' : '' ?>">
                                    <i class="fas fa-<?= 
                                        $status === 'draft' ? 'edit' : 
                                        ($status === 'issued' ? 'paper-plane' : 
                                        ($status === 'answered' ? 'reply' : 
                                        ($status === 'for_decision' ? 'gavel' : 'check'))) 
                                    ?> text-xs"></i>
                                </div>
                            </div>
                            
                            <?php if ($step_index < count($steps) - 1): ?>
                                <div class="flex-1 h-1 <?= $step_index < $current_step ? 'bg-green-500' : 'bg-gray-600' ?>"></div>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs mt-2 text-gray-300"><?= $step['label'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- NTE Details -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Employee & Incident Details -->
                <div class="bg-gray-800 rounded-xl border border-gray-700 p-6">
                    <h3 class="text-lg font-bold text-white mb-4">Employee & Incident Details</h3>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-400">Employee</label>
                            <p class="text-white font-semibold"><?= htmlspecialchars($nte['full_name']) ?> (<?= htmlspecialchars($nte['employee_id']) ?>)</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400">Department</label>
                            <p class="text-white"><?= htmlspecialchars($nte['department']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400">Supervisor</label>
                            <p class="text-white"><?= htmlspecialchars($nte['supervisor']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400">Operation Manager</label>
                            <p class="text-white"><?= htmlspecialchars($nte['operation_manager']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400">Incident Date</label>
                            <p class="text-white"><?= date('M d, Y', strtotime($nte['date_of_incident'])) ?></p>
                            
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400">Shift</label>
                            <p class="text-white"><?= htmlspecialchars($nte['shift']) ?></p>
                            
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-400">Incident Details</label>
                        <p class="text-white mt-1 bg-gray-700/50 p-3 rounded"><?= nl2br(htmlspecialchars($nte['original_incident'])) ?></p>
                    </div>
                </div>

                <!-- Violation Details -->
                 <!-- For autofill function! (Need to recode this for dynamic inputs but still has the automation functionality)-->
                <div class="bg-gray-800 rounded-xl border border-gray-700 p-6">
                    <h3 class="text-lg font-bold text-white mb-4">Violation Details</h3>
                    
                    <form method="POST" id="nteForm">
                        <input type="hidden" name="nte_id" value="<?= $nte['id'] ?>">
                        
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-400">Rule Section</label>
                                    <p class="text-white"><?= htmlspecialchars($nte['rule_section']) ?></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-400">Nature of Offense</label>
                                    <p class="text-white"><?= htmlspecialchars($nte['nature_of_offense']) ?></p>
                                </div>
                            </div>
                            
                            <div>
                                <label for="violation_cited" class="block text-sm font-medium text-gray-300">Violation Cited</label>
                                <textarea id="violation_cited" name="violation_cited" rows="3"
                                        class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200"
                                        <?= in_array($nte['nte_status'], ['answered', 'for_decision', 'closed']) ? 'readonly' : '' ?>><?= htmlspecialchars($nte['violation_cited'] ?? $nte['specific_offenses']) ?></textarea>
                            </div>
                            
                            <div>
                                <label for="sanction_proposed" class="block text-sm font-medium text-gray-300">Proposed Sanction</label>
                                <textarea id="sanction_proposed" name="sanction_proposed" rows="2"
                                          class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200"
                                          <?= in_array($nte['nte_status'], ['answered', 'for_decision', 'closed']) ? 'readonly' : '' ?>><?= htmlspecialchars($nte['sanction_proposed']) ?></textarea>
                            </div>
                            
                            <div>
                                <label for="hr_comments" class="block text-sm font-medium text-gray-300">HR Comments</label>
                                <textarea id="hr_comments" name="hr_comments" rows="2"
                                        class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200"
                                        placeholder="Additional comments or notes..."
                                        <?= in_array($nte['nte_status'], ['answered', 'for_decision', 'closed']) ? 'readonly' : '' ?>><?= htmlspecialchars($nte['hr_comments'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Employee Explanation (Visible when answered) -->
                <?php if (in_array($nte['nte_status'], ['answered', 'for_decision', 'closed'])): ?>
                <div class="bg-gray-800 rounded-xl border border-gray-700 p-6">
                    <h3 class="text-lg font-bold text-white mb-4">Employee Explanation</h3>
                    
                    <div class="bg-gray-700/50 p-4 rounded">
                        <?php if (!empty($nte['employee_explanation'])): ?>
                            <p class="text-white whitespace-pre-line"><?= htmlspecialchars($nte['employee_explanation']) ?></p>
                            <?php if ($nte['date_answered']): ?>
                                <p class="text-gray-400 text-sm mt-2">Submitted on: <?= date('M d, Y g:i A', strtotime($nte['date_answered'])) ?></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-gray-400">No explanation submitted yet.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- File Upload/Display Section -->
                    <?php if (!empty($nte['employee_explanation'])): ?>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Attached Documents</label>
                        <?php if (!empty($nte['uploaded_file'])): ?>
                            <div class="flex items-center justify-between bg-gray-700/50 p-3 rounded">
                                <div class="flex items-center">
                                    <i class="fas fa-file-pdf text-red-400 mr-3"></i>
                                    <div>
                                        <p class="text-white"><?= htmlspecialchars($nte['uploaded_file']) ?></p>
                                        <p class="text-gray-400 text-sm">
                                            Uploaded: <?= date('M d, Y g:i A', strtotime($nte['file_uploaded_at'])) ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex space-x-2">
                                    <a href="../uploads/nte/<?= $nte['uploaded_file'] ?>" 
                                       target="_blank" 
                                       class="text-blue-400 hover:text-blue-300">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="../uploads/nte/<?= $nte['uploaded_file'] ?>" 
                                       download 
                                       class="text-green-400 hover:text-green-300">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-400 bg-gray-700/50 p-3 rounded">No documents attached</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- HR Recommendation & Final Decision -->
                <?php if (in_array($nte['nte_status'], ['for_decision', 'closed'])): ?>
                <div class="bg-gray-800 rounded-xl border border-gray-700 p-6">
                    <h3 class="text-lg font-bold text-white mb-4">HR Recommendation & Final Decision</h3>
                    
                    <?php if (!empty($nte['hr_recommendation'])): ?>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">HR Recommendation</label>
                        <div class="bg-blue-500/10 border border-blue-500/30 p-4 rounded">
                            <p class="text-white whitespace-pre-line"><?= htmlspecialchars($nte['hr_recommendation']) ?></p>
                            <?php if ($nte['date_reviewed']): ?>
                                <p class="text-blue-400 text-sm mt-2">Reviewed on: <?= date('M d, Y g:i A', strtotime($nte['date_reviewed'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($nte['nte_status'] === 'closed'): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Final Sanction</label>
                        <p class="text-white font-semibold bg-gray-700/50 p-3 rounded"><?= htmlspecialchars($nte['final_sanction']) ?></p>
                        
                        <?php if ($nte['closing_remarks']): ?>
                        <label class="block text-sm font-medium text-gray-400 mt-4 mb-2">Closing Remarks</label>
                        <p class="text-white bg-gray-700/50 p-3 rounded whitespace-pre-line"><?= htmlspecialchars($nte['closing_remarks']) ?></p>
                        <?php endif; ?>
                        
                        <?php if ($nte['date_closed']): ?>
                            <p class="text-gray-400 text-sm mt-2">Closed on: <?= date('M d, Y g:i A', strtotime($nte['date_closed'])) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Action Panel -->
            <div class="space-y-6">
                <!-- Status & Info Panel -->
                <div class="bg-gray-800 rounded-xl border border-gray-700 p-6">
                    <h3 class="text-lg font-bold text-white mb-4">NTE Information</h3>
                    
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-400">Current Instance</label>
                            <p class="text-white font-semibold text-lg"><?= $nte['violation_instance'] ?> Instance</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-400">Status</label>
                            <span class="inline-block px-3 py-1 rounded-full text-sm font-medium 
                                <?= $nte['nte_status'] == 'draft' ? 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30' :
                                   ($nte['nte_status'] == 'issued' ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30' :
                                   ($nte['nte_status'] == 'answered' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' :
                                   ($nte['nte_status'] == 'for_decision' ? 'bg-orange-500/20 text-orange-300 border border-orange-500/30' :
                                   'bg-green-500/20 text-green-300 border border-green-500/30'))) ?>">
                                <?= strtoupper($nte['nte_status']) ?>
                            </span>
                        </div>
                        
                        <?php if ($nte['date_issued']): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-400">Date Issued</label>
                            <p class="text-white"><?= date('M d, Y', strtotime($nte['date_issued'])) ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($nte['cleansing_end_date']): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-400">Cleansing Period Ends</label>
                            <p class="text-white"><?= date('M d, Y', strtotime($nte['cleansing_end_date'])) ?></p>
                            
                            <?php
                            $days_remaining = floor((strtotime($nte['cleansing_end_date']) - time()) / (60 * 60 * 24));
                            if ($days_remaining > 0) {
                                echo "<p class='text-sm text-green-400'>$days_remaining days remaining</p>";
                            } else {
                                echo "<p class='text-sm text-green-400'>Cleansing period completed</p>";
                            }
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="bg-gray-800 rounded-xl border border-gray-700 p-6">
                    <h3 class="text-lg font-bold text-white mb-4">Actions</h3>
                    
                    <div class="space-y-3">
                        <?php if ($nte['nte_status'] === 'draft'): ?>
                            <button type="submit" form="nteForm" name="action" value="issue" 
                                    class="w-full bg-green-600 hover:bg-green-500 text-white px-4 py-3 rounded-lg flex items-center justify-center transition-colors">
                                <i class="fas fa-paper-plane mr-2"></i> Issue NTE
                            </button>
                            
                            <button type="submit" form="nteForm" name="action" value="save_draft" 
                                    class="w-full bg-blue-600 hover:bg-blue-500 text-white px-4 py-3 rounded-lg flex items-center justify-center transition-colors">
                                <i class="fas fa-save mr-2"></i> Save as Draft
                            </button>
                        <?php endif; ?>

                        <?php if ($nte['nte_status'] === 'issued'): ?>
                            <button type="button" onclick="showExplanationModal()" 
                                    class="w-full bg-purple-600 hover:bg-purple-500 text-white px-4 py-3 rounded-lg flex items-center justify-center transition-colors">
                                <i class="fas fa-reply mr-2"></i> Mark as Answered
                            </button>
                        <?php endif; ?>

                        <?php if ($nte['nte_status'] === 'answered'): ?>
                            <button type="button" onclick="showRecommendationModal()" 
                                    class="w-full bg-orange-600 hover:bg-orange-500 text-white px-4 py-3 rounded-lg flex items-center justify-center transition-colors">
                                <i class="fas fa-gavel mr-2"></i> Mark for Decision
                            </button>
                        <?php endif; ?>

                        <?php if ($nte['nte_status'] === 'for_decision'): ?>
                            <button type="button" onclick="showClosingModal()" 
                                    class="w-full bg-green-600 hover:bg-green-500 text-white px-4 py-3 rounded-lg flex items-center justify-center transition-colors">
                                <i class="fas fa-check mr-2"></i> Close NTE
                            </button>
                        <?php endif; ?>

                        <!-- Print Button -->
                        <a href="nte_printable.php?id=<?= $nte['id'] ?>" 
                           target="_blank"
                           class="w-full bg-red-600 hover:bg-red-500 text-white px-4 py-3 rounded-lg flex items-center justify-center transition-colors">
                            <i class="fas fa-print mr-2"></i> Print NTE
                        </a>

                        <a href="employee_history.php?employee_id=<?= $nte['employee_id'] ?>" 
                           class="w-full bg-gray-600 hover:bg-gray-500 text-white px-4 py-3 rounded-lg flex items-center justify-center transition-colors">
                            <i class="fas fa-history mr-2"></i> View History
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Modals for different actions -->
<?php if ($nte['nte_status'] === 'issued'): ?>
<!-- Explanation Modal -->
<div id="explanationModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-2xl mx-4">
        <div class="px-6 py-6">
            <h3 class="text-lg font-bold text-white mb-4">Record Employee Explanation</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="nte_id" value="<?= $nte['id'] ?>">
                <div class="mb-4">
                    <label for="employee_explanation" class="block text-sm font-medium text-gray-300 mb-2">Employee Explanation</label>
                    <textarea id="employee_explanation" name="employee_explanation" rows="6"
                              class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200"
                              placeholder="Enter the employee's written explanation..."
                              required></textarea>
                </div>
                <div class="mb-4">
                    <label for="nte_file" class="block text-sm font-medium text-gray-300 mb-2">Upload Signed NTE Document</label>
                    <input type="file" id="nte_file" name="nte_file" 
                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                           class="w-full px-4 py-2 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200">
                    <p class="text-xs text-gray-400 mt-1">Accepted formats: PDF, JPG, PNG, DOC, DOCX (Max: 10MB)</p>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeExplanationModal()" 
                            class="px-4 py-2 bg-gray-600 text-gray-100 rounded-md hover:bg-gray-500">
                        Cancel
                    </button>
                    <button type="submit" name="action" value="mark_answered" 
                            class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-500">
                        Mark as Answered
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($nte['nte_status'] === 'answered'): ?>
<!-- Recommendation Modal -->
<div id="recommendationModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-2xl mx-4">
        <div class="px-6 py-6">
            <h3 class="text-lg font-bold text-white mb-4">HR Recommendation</h3>
            <form method="POST">
                <input type="hidden" name="nte_id" value="<?= $nte['id'] ?>">
                <div class="mb-4">
                    <label for="hr_recommendation" class="block text-sm font-medium text-gray-300 mb-2">Recommendation</label>
                    <textarea id="hr_recommendation" name="hr_recommendation" rows="6"
                              class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200"
                              placeholder="Enter your recommendation based on the employee's explanation..."
                              required></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeRecommendationModal()" 
                            class="px-4 py-2 bg-gray-600 text-gray-100 rounded-md hover:bg-gray-500">
                        Cancel
                    </button>
                    <button type="submit" name="action" value="mark_for_decision" 
                            class="px-4 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-500">
                        Mark for Decision
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($nte['nte_status'] === 'for_decision'): ?>
<!-- Closing Modal -->
<div id="closingModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-2xl mx-4">
        <div class="px-6 py-6">
            <h3 class="text-lg font-bold text-white mb-4">Close NTE</h3>
            <form method="POST">
                <input type="hidden" name="nte_id" value="<?= $nte['id'] ?>">
                <div class="space-y-4">
                    <div>
                        <label for="final_sanction" class="block text-sm font-medium text-gray-300 mb-2">Final Sanction</label>
                        <input type="text" id="final_sanction" name="final_sanction" 
                               value="<?= htmlspecialchars($nte['sanction_proposed']) ?>"
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200"
                               required>
                    </div>
                    <div>
                        <label for="closing_remarks" class="block text-sm font-medium text-gray-300 mb-2">Closing Remarks</label>
                        <textarea id="closing_remarks" name="closing_remarks" rows="4"
                                  class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200"
                                  placeholder="Enter final remarks or notes..."></textarea>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-4">
                    <button type="button" onclick="closeClosingModal()" 
                            class="px-4 py-2 bg-gray-600 text-gray-100 rounded-md hover:bg-gray-500">
                        Cancel
                    </button>
                    <button type="submit" name="action" value="close_nte" 
                            class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-500">
                        Close NTE
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Modal functions
function showExplanationModal() {
    document.getElementById('explanationModal').classList.remove('hidden');
}

function closeExplanationModal() {
    document.getElementById('explanationModal').classList.add('hidden');
}

function showRecommendationModal() {
    document.getElementById('recommendationModal').classList.remove('hidden');
}

function closeRecommendationModal() {
    document.getElementById('recommendationModal').classList.add('hidden');
}

function showClosingModal() {
    document.getElementById('closingModal').classList.remove('hidden');
}

function closeClosingModal() {
    document.getElementById('closingModal').classList.add('hidden');
}

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.id === 'explanationModal') closeExplanationModal();
    if (e.target.id === 'recommendationModal') closeRecommendationModal();
    if (e.target.id === 'closingModal') closeClosingModal();
});

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeExplanationModal();
        closeRecommendationModal();
        closeClosingModal();
    }
});
</script>

<?php renderFooter(); ?>