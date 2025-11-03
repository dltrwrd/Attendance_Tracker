<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isHR()) {
    redirect(BASE_URL);
}

updateLastActivity();

require_once '../components/layout.php';
renderHead('Notice to Explain - Review');
renderNavbar();
renderSidebar('nte');
?>

<div class="pt-2 min-h-screen">
    <main class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Notice to Explain - Review</h1>
            <div class="flex space-x-3">
                <a href="incident_report.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-list mr-2"></i> Incident Reports
                </a>
            </div>
        </div>

        <?php renderAlert(); ?>

        <!-- Status Filter -->
        <div class="flex space-x-2 mb-6">
            <?php
            $statuses = [
                'draft' => 'Drafts',
                'issued' => 'Issued', 
                'answered' => 'Answered',
                'for_decision' => 'For Decision',
                'closed' => 'Closed'
            ];
            
            $current_status = $_GET['status'] ?? 'draft';
            
            foreach ($statuses as $status => $label): 
                $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notice_to_explain WHERE nte_status = ?");
                $count_stmt->execute([$status]);
                $count = $count_stmt->fetchColumn();
            ?>
            <a href="?status=<?= $status ?>" 
               class="px-4 py-2 rounded-lg flex items-center transition-colors <?= $current_status == $status ? 
               'bg-primary-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600' ?>">
                <?= $label ?> 
                <span class="ml-2 bg-gray-800 px-2 py-1 rounded text-xs"><?= $count ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Search and Filters -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Search Input -->
            <div class="relative">
                <input type="text" id="searchInput" 
                    class="w-full pl-10 pr-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 shadow hover:border-blue-500 transition-colors duration-200 text-left"
                    placeholder="Search employee or violation...">
                <div class="absolute left-3 top-2.5 text-gray-400">
                    <i class="fas fa-search"></i>
                </div>
            </div>
            
            <!-- Date Range Filter -->
            <div class="flex gap-2">
                <div class="relative flex-grow">
                    <input type="date" id="dateFrom" 
                        class="w-full px-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 shadow hover:border-blue-500 transition-colors duration-200 text-left"
                        placeholder="From Date">
                </div>
                <div class="relative flex-grow">
                    <input type="date" id="dateTo" 
                        class="w-full px-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 shadow hover:border-blue-500 transition-colors duration-200 text-left"
                        placeholder="To Date">
                </div>
            </div>
            
            <!-- Department Filter -->
            <div class="relative">
                <select id="departmentFilter" 
                        class="w-full px-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 appearance-none shadow hover:border-blue-500 transition-colors duration-200 text-left">
                    <option value="">ALL DEPARTMENTS</option>
                    <?php
                    $stmt = $pdo->query("SELECT DISTINCT department FROM notice_to_explain ORDER BY department");
                    while ($row = $stmt->fetch()) {
                        echo '<option value="'.htmlspecialchars($row['department']).'">'.htmlspecialchars($row['department']).'</option>';
                    }
                    ?>
                </select>
                <div class="absolute right-3 top-2.5 text-gray-400 pointer-events-none">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
        </div>

        <!-- NTE List -->
        <div id="nteTableContainer">
            <?php include 'partials/nte_table.php'; ?>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    const departmentFilter = document.getElementById('departmentFilter');
    let searchTimeout;

    // Function to load data with current filters
    function loadNTETable(page = 1) {
        const urlParams = new URLSearchParams(window.location.search);
        const status = urlParams.get('status') || 'draft';
        
        const formData = new FormData();
        formData.append('status', status);
        formData.append('search', searchInput.value);
        formData.append('date_from', dateFrom.value);
        formData.append('date_to', dateTo.value);
        formData.append('department', departmentFilter.value);
        formData.append('page', page);

        fetch('partials/nte_table.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            document.getElementById('nteTableContainer').innerHTML = data;
            setupPaginationLinks();
        })
        .catch(error => {
            console.error('Error loading NTE table:', error);
        });
    }

    function setupPaginationLinks() {
        document.querySelectorAll('.pagination-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const page = parseInt(this.getAttribute('data-page'));
                loadNTETable(page);
                
                // Update URL with new page parameter
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('page', page);
                history.pushState(null, '', '?' + urlParams.toString());
            });
        });
    }

    // Function to update URL with current filters
    function updateURL() {
        const urlParams = new URLSearchParams(window.location.search);
        const status = urlParams.get('status') || 'draft';
        
        const newParams = new URLSearchParams();
        newParams.set('status', status);
        
        if (searchInput.value) {
            newParams.set('search', searchInput.value);
        }
        if (dateFrom.value) {
            newParams.set('from', dateFrom.value);
        }
        if (dateTo.value) {
            newParams.set('to', dateTo.value);
        }
        if (departmentFilter.value) {
            newParams.set('dept', departmentFilter.value);
        }
        
        const newURL = window.location.pathname + (newParams.toString() ? '?' + newParams.toString() : '');
        history.pushState(null, '', newURL);
    }

    // Event listeners for filters
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            updateURL();
            loadNTETable();
        }, 300);
    });

    dateFrom.addEventListener('change', () => {
        updateURL();
        loadNTETable();
    });

    dateTo.addEventListener('change', () => {
        updateURL();
        loadNTETable();
    });

    departmentFilter.addEventListener('change', () => {
        updateURL();
        loadNTETable();
    });

    // Handle browser back/forward buttons
    window.addEventListener('popstate', function() {
        const urlParams = new URLSearchParams(window.location.search);
        
        // Update filter values from URL
        searchInput.value = urlParams.get('search') || '';
        dateFrom.value = urlParams.get('from') || '';
        dateTo.value = urlParams.get('to') || '';
        departmentFilter.value = urlParams.get('dept') || '';
        
        // Load data
        const page = parseInt(urlParams.get('page')) || 1;
        loadNTETable(page);
    });

    // Initial load - check for URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    
    // Set initial filter values from URL
    searchInput.value = urlParams.get('search') || '';
    dateFrom.value = urlParams.get('from') || '';
    dateTo.value = urlParams.get('to') || '';
    departmentFilter.value = urlParams.get('dept') || '';
    
    const initialPage = parseInt(urlParams.get('page')) || 1;
    loadNTETable(initialPage);
});
</script>

<?php renderFooter(); ?>