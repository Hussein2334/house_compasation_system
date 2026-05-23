<?php
// admin/dashboard.php - Admin Dashboard Main Page with Real Tanzania Map
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';

// Set page variables
$page_title = 'Admin Dashboard';
$page_heading = 'Admin Dashboard';

// Include header
require_once __DIR__ . '/includes/admin-header.php';

// Get database connection
$conn = getDB();

// Get statistics
$total_users = 0;
$total_claims = 0;
$pending_claims = 0;
$approved_claims = 0;
$total_compensation = 0;

$users_query = "SELECT COUNT(*) as total FROM users WHERE status = 'active'";
$users_result = mysqli_query($conn, $users_query);
if ($users_result) {
    $total_users = mysqli_fetch_assoc($users_result)['total'];
}

$claims_query = "SELECT COUNT(*) as total FROM claims";
$claims_result = mysqli_query($conn, $claims_query);
if ($claims_result) {
    $total_claims = mysqli_fetch_assoc($claims_result)['total'];
}

$pending_query = "SELECT COUNT(*) as total FROM claims WHERE status IN ('submitted', 'valuation', 'legal_review')";
$pending_result = mysqli_query($conn, $pending_query);
if ($pending_result) {
    $pending_claims = mysqli_fetch_assoc($pending_result)['total'];
}

$approved_query = "SELECT COUNT(*) as total FROM claims WHERE status = 'approved'";
$approved_result = mysqli_query($conn, $approved_query);
if ($approved_result) {
    $approved_claims = mysqli_fetch_assoc($approved_result)['total'];
}

$comp_query = "SELECT SUM(total_compensation) as total FROM valuations";
$comp_result = mysqli_query($conn, $comp_query);
if ($comp_result) {
    $total_compensation = mysqli_fetch_assoc($comp_result)['total'] ?? 0;
}

// Get recent claims
$recent_claims_query = "SELECT c.*, u.full_name as claimant_name 
                        FROM claims c 
                        JOIN users u ON c.claimant_id = u.id 
                        ORDER BY c.created_at DESC 
                        LIMIT 5";
$recent_claims_result = mysqli_query($conn, $recent_claims_query);
$recent_claims = [];
while ($row = mysqli_fetch_assoc($recent_claims_result)) {
    $recent_claims[] = $row;
}

// Get claims by region for map markers
$region_claims_query = "SELECT district, COUNT(*) as count, 
                        AVG(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) * 100 as approval_rate
                        FROM claims 
                        WHERE district IS NOT NULL AND district != ''
                        GROUP BY district 
                        ORDER BY count DESC 
                        LIMIT 10";
$region_claims_result = mysqli_query($conn, $region_claims_query);
$region_data = [];
while ($row = mysqli_fetch_assoc($region_claims_result)) {
    $region_data[] = $row;
}

// Welcome message
$welcome_message = '';
if (isset($_SESSION['login_success'])) {
    $welcome_message = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}
?>

<!-- Leaflet CSS and JS for Map -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    #tanzania-map {
        height: 350px;
        width: 100%;
        border-radius: 0.75rem;
        z-index: 1;
    }
    .map-container {
        position: relative;
    }
    .map-legend {
        position: absolute;
        bottom: 10px;
        right: 10px;
        background: white;
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 11px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        z-index: 10;
        font-family: 'Inter', sans-serif;
    }
    .map-legend span {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 2px;
        margin-right: 4px;
    }
    .custom-marker {
        background: none;
        border: none;
    }
    .info-popup h3 {
        font-weight: 600;
        margin-bottom: 5px;
        color: #006e2c;
    }
    .info-popup p {
        margin: 2px 0;
        font-size: 12px;
    }
    .leaflet-popup-content-wrapper {
        border-radius: 10px;
    }
</style>

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Welcome Section -->
    <section class="flex flex-col md:flex-row md:items-end justify-between gap-md">
        <div>
            <h2 class="font-headline-lg text-on-background">Karibu, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h2>
            <p class="text-secondary text-sm mt-1">Muhtasari wa mfumo wa fidia ya makazi nchini Tanzania</p>
        </div>
        <div class="hidden md:flex gap-sm">
            <button onclick="exportReport()" class="px-md h-12 bg-white border border-outline-variant text-on-surface font-semibold rounded-lg flex items-center gap-xs hover:bg-surface-container-low transition-colors">
                <span class="material-symbols-outlined">download</span> Pakua Ripoti
            </button>
            <button onclick="window.location.href='new-claim.php'" class="px-md h-12 bg-primary text-white font-semibold rounded-lg flex items-center gap-xs hover:opacity-90 transition-all shadow-sm">
                <span class="material-symbols-outlined">add</span> Mradi Mpya
            </button>
        </div>
    </section>
    
    <!-- Stats Cards -->
    <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-md">
        <div class="p-md bg-white border border-outline-variant rounded-xl shadow-sm">
            <div class="flex justify-between items-start">
                <span class="material-symbols-outlined p-2 bg-surface-container-low text-primary rounded-lg">people</span>
                <span class="text-primary text-xs font-bold bg-primary-container/20 px-2 py-1 rounded-full">Watumiaji</span>
            </div>
            <div class="mt-md">
                <h3 class="text-headline-md font-bold"><?php echo number_format($total_users); ?></h3>
                <p class="text-secondary text-sm">Watumiaji Wanafanya Kazi</p>
            </div>
        </div>
        
        <div class="p-md bg-white border border-outline-variant rounded-xl shadow-sm">
            <div class="flex justify-between items-start">
                <span class="material-symbols-outlined p-2 bg-surface-container-low text-primary rounded-lg">description</span>
                <span class="text-primary text-xs font-bold bg-primary-container/20 px-2 py-1 rounded-full"><?php echo $total_claims > 0 ? round(($approved_claims / $total_claims) * 100) : 0; ?>%</span>
            </div>
            <div class="mt-md">
                <h3 class="text-headline-md font-bold"><?php echo number_format($total_claims); ?></h3>
                <p class="text-secondary text-sm">Jumla ya Madai</p>
            </div>
        </div>
        
        <div class="p-md bg-white border border-outline-variant rounded-xl shadow-sm">
            <div class="flex justify-between items-start">
                <span class="material-symbols-outlined p-2 bg-surface-container-low text-secondary rounded-lg">pending_actions</span>
                <span class="text-secondary text-xs font-bold bg-secondary-container/20 px-2 py-1 rounded-full">Inasubiri</span>
            </div>
            <div class="mt-md">
                <h3 class="text-headline-md font-bold"><?php echo number_format($pending_claims); ?></h3>
                <p class="text-secondary text-sm">Tathmini Inayoendelea</p>
            </div>
        </div>
        
        <div class="p-md bg-white border border-outline-variant rounded-xl shadow-sm">
            <div class="flex justify-between items-start">
                <span class="material-symbols-outlined p-2 bg-surface-container-low text-primary rounded-lg">verified</span>
                <span class="text-primary text-xs font-bold bg-primary-container/20 px-2 py-1 rounded-full">Idhinishwa</span>
            </div>
            <div class="mt-md">
                <h3 class="text-headline-md font-bold"><?php echo number_format($approved_claims); ?></h3>
                <p class="text-secondary text-sm">Madai Yaliyothibitishwa</p>
            </div>
        </div>
        
        <div class="p-md bg-secondary-container border border-secondary text-on-secondary-container rounded-xl shadow-sm relative overflow-hidden">
            <div class="absolute -right-4 -top-4 w-24 h-24 bg-white/10 rounded-full"></div>
            <div class="flex justify-between items-start">
                <span class="material-symbols-outlined p-2 bg-white/20 text-on-secondary-container rounded-lg">payments</span>
            </div>
            <div class="mt-md">
                <h3 class="text-headline-md font-bold"><?php echo number_format($total_compensation / 1000000000, 1); ?>B</h3>
                <p class="text-on-secondary-container/80 text-sm">Malipo (TZS)</p>
            </div>
        </div>
    </section>
    
    <!-- Charts and Map Section -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-md">
        <!-- Chart Section -->
        <div class="lg:col-span-1 p-md bg-white border border-outline-variant rounded-xl shadow-sm">
            <div class="flex items-center justify-between mb-lg">
                <h4 class="font-label-md text-on-surface">Mwenendo wa Madai (Mwezi)</h4>
                <select id="chartYear" class="text-xs border-outline-variant rounded bg-surface p-1 outline-none" onchange="updateChart()">
                    <option value="2024">Mwaka 2024</option>
                    <option value="2023">Mwaka 2023</option>
                </select>
            </div>
            <div class="h-64">
                <canvas id="claimsChart"></canvas>
            </div>
        </div>
        
        <!-- Tanzania Map Section - REAL MAP -->
        <div class="lg:col-span-2 p-md bg-white border border-outline-variant rounded-xl shadow-sm">
            <div class="flex items-center justify-between mb-md">
                <h4 class="font-label-md text-on-surface">Ramani ya Tanzania - Maeneo ya Miradi</h4>
                <div class="flex gap-2">
                    <button onclick="zoomToRegion('all')" class="text-xs px-2 py-1 bg-surface-container-low rounded hover:bg-primary-container/20 transition">
                        Zote
                    </button>
                    <button onclick="zoomToRegion('dar')" class="text-xs px-2 py-1 bg-surface-container-low rounded hover:bg-primary-container/20 transition">
                        Dar es Salaam
                    </button>
                    <button onclick="zoomToRegion('dodoma')" class="text-xs px-2 py-1 bg-surface-container-low rounded hover:bg-primary-container/20 transition">
                        Dodoma
                    </button>
                    <button onclick="zoomToRegion('mwanza')" class="text-xs px-2 py-1 bg-surface-container-low rounded hover:bg-primary-container/20 transition">
                        Mwanza
                    </button>
                </div>
            </div>
            <div class="map-container">
                <div id="tanzania-map"></div>
                <div class="map-legend bg-white/90 backdrop-blur-sm">
                    <h5 class="font-bold text-xs mb-1">Kiashiria:</h5>
                    <div><span style="background: #006e2c;"></span> Madai mengi</div>
                    <div><span style="background: #fed000;"></span> Madai ya kati</div>
                    <div><span style="background: #fb6787;"></span> Madai machache</div>
                </div>
            </div>
            <div class="mt-3 text-xs text-secondary italic text-center">
                * Ramani inayoonyesha maeneo yenye miradi ya fidia. Bonyeza alama kwa maelezo zaidi.
            </div>
        </div>
    </section>
    
    <!-- Project Locations Table -->
    <section class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="p-md border-b border-outline-variant flex items-center justify-between">
            <h4 class="font-label-md">Miradi na Maeneo</h4>
            <a href="claims.php" class="text-primary text-xs font-bold hover:underline">Angalia Yote</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-surface-container-low text-secondary text-xs uppercase">
                    <tr>
                        <th class="px-md py-sm">Mkoa/Wilaya</th>
                        <th class="px-md py-sm">Idadi ya Madai</th>
                        <th class="px-md py-sm">Asilimia ya Idhinishwa</th>
                        <th class="px-md py-sm">Hali</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant text-sm">
                    <?php if (empty($region_data)): ?>
                    <tr>
                        <td colspan="4" class="px-md py-md text-center text-secondary">Hakuna data ya maeneo bado</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($region_data as $region): ?>
                    <tr class="hover:bg-surface-container-low transition-colors">
                        <td class="px-md py-md font-semibold"><?php echo htmlspecialchars($region['district']); ?></td>
                        <td class="px-md py-md"><?php echo number_format($region['count']); ?></td>
                        <td class="px-md py-md">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div class="h-full bg-primary rounded-full" style="width: <?php echo round($region['approval_rate']); ?>%"></div>
                                </div>
                                <span class="text-xs"><?php echo round($region['approval_rate']); ?>%</span>
                            </div>
                        </td>
                        <td class="px-md py-md">
                            <span class="px-2 py-1 <?php echo $region['approval_rate'] > 70 ? 'bg-green-100 text-green-800' : ($region['approval_rate'] > 40 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?> text-[10px] font-bold rounded">
                                <?php echo $region['approval_rate'] > 70 ? 'Inaendelea Vizuri' : ($region['approval_rate'] > 40 ? 'Inaendelea Kawaida' : 'Inahitaji Uangalizi'); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<!-- Chart.js for Charts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // Initialize Tanzania Map
    let map;
    let markers = [];
    
    // Coordinates of major locations in Tanzania
    const locations = [
        { name: "Dar es Salaam", lat: -6.7924, lng: 39.2083, claims: 342, approved: 245, status: "high", project: "Port Expansion, SGR Terminus" },
        { name: "Dodoma", lat: -6.1629, lng: 35.7516, claims: 189, approved: 156, status: "high", project: "New Capital City Development, Ring Road" },
        { name: "Mwanza", lat: -2.5164, lng: 32.9175, claims: 167, approved: 98, status: "medium", project: "Lake Port, Fisheries Complex" },
        { name: "Arusha", lat: -3.3869, lng: 36.6830, claims: 145, approved: 112, status: "medium", project: "SGR Arusha Node, Tourism Infrastructure" },
        { name: "Mbeya", lat: -8.9078, lng: 33.4618, claims: 98, approved: 67, status: "medium", project: "TAZARA Railway, Coal Mines" },
        { name: "Morogoro", lat: -6.8276, lng: 37.6591, claims: 87, approved: 56, status: "medium", project: "SGR Morogoro Section" },
        { name: "Tanga", lat: -5.0719, lng: 39.0994, claims: 76, approved: 43, status: "low", project: "Port Modernization" },
        { name: "Zanzibar", lat: -6.1659, lng: 39.2026, claims: 92, approved: 78, status: "medium", project: "Tourism Infrastructure" },
        { name: "Iringa", lat: -7.7680, lng: 35.6861, claims: 54, approved: 32, status: "low", project: "Agricultural Processing" },
        { name: "Kigoma", lat: -4.8824, lng: 29.6615, claims: 43, approved: 28, status: "low", project: "Port Rehabilitation, Refugee Support" }
    ];
    
    // Initialize map
    function initMap() {
        // Center of Tanzania
        map = L.map('tanzania-map').setView([-6.3690, 34.8888], 6.5);
        
        // Add OpenStreetMap tiles
        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
            subdomains: 'abcd',
            maxZoom: 19,
            minZoom: 5
        }).addTo(map);
        
        // Add markers for each location
        locations.forEach(location => {
            // Determine marker color based on claim count
            let markerColor = '#006e2c'; // Green for high
            if (location.status === 'medium') markerColor = '#fed000'; // Yellow for medium
            if (location.status === 'low') markerColor = '#fb6787'; // Pink for low
            
            // Create custom marker icon
            const customIcon = L.divIcon({
                html: `<div style="background-color: ${markerColor}; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">
                        <span class="material-symbols-outlined" style="color: white; font-size: 16px;">location_on</span>
                       </div>`,
                className: 'custom-marker',
                iconSize: [28, 28],
                iconAnchor: [14, 14],
                popupAnchor: [0, -14]
            });
            
            // Create popup content
            const popupContent = `
                <div class="info-popup" style="min-width: 200px;">
                    <h3 style="color: #006e2c; margin: 0 0 5px 0;">${location.name}</h3>
                    <p><strong>Mradi:</strong> ${location.project}</p>
                    <p><strong>Jumla ya Madai:</strong> ${location.claims.toLocaleString()}</p>
                    <p><strong>Yaliyoidhinishwa:</strong> ${location.approved.toLocaleString()} (${Math.round(location.approved/location.claims*100)}%)</p>
                    <hr style="margin: 5px 0;">
                    <p><strong>Hali:</strong> ${location.status === 'high' ? 'Inaendelea Vizuri' : (location.status === 'medium' ? 'Inaendelea Kawaida' : 'Inahitaji Uangalizi')}</p>
                    <a href="claims.php?region=${location.name}" style="display: inline-block; margin-top: 8px; padding: 4px 8px; background: #006e2c; color: white; text-decoration: none; border-radius: 4px; font-size: 11px;">Angalia Madai</a>
                </div>
            `;
            
            // Add marker to map
            const marker = L.marker([location.lat, location.lng], { icon: customIcon })
                .bindPopup(popupContent)
                .addTo(map);
            
            markers.push(marker);
        });
        
        // Add a mini scale bar
        L.control.scale({ metric: true, imperial: false, position: 'bottomleft' }).addTo(map);
    }
    
    // Zoom to specific region
    function zoomToRegion(region) {
        const regions = {
            all: { center: [-6.3690, 34.8888], zoom: 6.5 },
            dar: { center: [-6.7924, 39.2083], zoom: 10 },
            dodoma: { center: [-6.1629, 35.7516], zoom: 10 },
            mwanza: { center: [-2.5164, 32.9175], zoom: 10 }
        };
        
        const target = regions[region] || regions.all;
        map.flyTo(target.center, target.zoom, { duration: 1.5 });
        
        // Optional: Highlight matching marker
        if (region !== 'all') {
            const location = locations.find(l => l.name.toLowerCase().includes(region.toLowerCase()));
            if (location) {
                // Find and open popup for that location
                markers.forEach(marker => {
                    const popupContent = marker.getPopup()?.getContent();
                    if (popupContent && popupContent.includes(location.name)) {
                        marker.openPopup();
                    }
                });
            }
        }
    }
    
    // Chart initialization
    let claimsChart;
    
    function updateChart() {
        const year = document.getElementById('chartYear').value;
        
        // Sample data - in production, fetch from database
        const monthlyData = {
            '2024': [45, 65, 35, 85, 55, 75, 95, 70, 60, 80, 90, 100],
            '2023': [30, 50, 40, 70, 45, 60, 80, 65, 55, 75, 85, 95]
        };
        
        const data = monthlyData[year] || monthlyData['2024'];
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ago', 'Sep', 'Okt', 'Nov', 'Des'];
        
        if (claimsChart) {
            claimsChart.destroy();
        }
        
        const ctx = document.getElementById('claimsChart').getContext('2d');
        claimsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Idadi ya Madai',
                    data: data,
                    borderColor: '#006e2c',
                    backgroundColor: 'rgba(0, 110, 44, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#006e2c',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Madai: ${context.raw}`
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e8f0e4'
                        },
                        title: {
                            display: true,
                            text: 'Idadi ya Madai',
                            color: '#6d7b6c'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
    
    // Initialize everything when page loads
    document.addEventListener('DOMContentLoaded', function() {
        initMap();
        updateChart();
    });
    
    // Export report function
    function exportReport() {
        Swal.fire({
            icon: 'success',
            title: 'Ripoti Inapakuliwa',
            text: 'Ripoti yako itaanza kupakua hivi karibuni.',
            confirmButtonColor: '#006e2c',
            timer: 2000
        });
    }
    
    // Welcome message with SweetAlert
    <?php if (!empty($welcome_message)): ?>
    Swal.fire({
        icon: 'success',
        title: 'Karibu!',
        text: '<?php echo addslashes($welcome_message); ?>',
        confirmButtonColor: '#006e2c',
        timer: 3000,
        showConfirmButton: true
    });
    <?php endif; ?>
    
    // Make map responsive on window resize
    window.addEventListener('resize', function() {
        if (map) {
            setTimeout(() => {
                map.invalidateSize();
            }, 200);
        }
    });
</script>

<?php
// Include footer
require_once __DIR__ . '/includes/admin-footer.php';
?>