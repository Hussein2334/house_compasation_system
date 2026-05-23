<?php
// claimant/help.php - Help and Support Page
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is claimant
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'claimant') {
    if ($_SESSION['role'] === 'super_admin') {
        header("Location: ../admin/dashboard.php");
    } else {
        header("Location: ../dashboard.php");
    }
    exit();
}

// Set page variables
$page_title = 'Help & Support';
$page_heading = 'Msaada na Usaidizi';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

// Handle help request submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_help'])) {
    $subject = trim($_POST['subject'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    $errors = [];
    
    if (empty($subject)) {
        $errors[] = "Tafadhali jaza mada ya msaada";
    }
    
    if (empty($category)) {
        $errors[] = "Tafadhali chagua aina ya msaada";
    }
    
    if (empty($message)) {
        $errors[] = "Tafadhali jaza ujumbe wako";
    }
    
    if (strlen($message) < 10) {
        $errors[] = "Ujumbe lazima uwe na angalau herufi 10";
    }
    
    if (empty($errors)) {
        $full_name = $_SESSION['full_name'];
        $email = $_SESSION['email'];
        
        $insert_query = "INSERT INTO help_requests (user_id, full_name, email, phone, subject, category, message, status, priority, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'medium', NOW())";
        $stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($stmt, "issssss", $user_id, $full_name, $email, $phone, $subject, $category, $message);
        
        if (mysqli_stmt_execute($stmt)) {
            $help_id = mysqli_insert_id($conn);
            
            // Create notification for admin
            $admin_query = "SELECT id FROM users WHERE role IN ('super_admin', 'commissioner') LIMIT 1";
            $admin_result = mysqli_query($conn, $admin_query);
            $admin = mysqli_fetch_assoc($admin_result);
            
            if ($admin) {
                $notif_title = "Ombi Jipya la Msaada";
                $notif_message = "Mwombaji $full_name ametuma ombi la msaada: $subject";
                $notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                               VALUES (?, ?, ?, 'system', NOW())";
                $notif_stmt = mysqli_prepare($conn, $notif_query);
                mysqli_stmt_bind_param($notif_stmt, "iss", $admin['id'], $notif_title, $notif_message);
                mysqli_stmt_execute($notif_stmt);
            }
            
            logAudit($conn, $user_id, 'SUBMIT_HELP_REQUEST', 'help_requests', $help_id);
            $_SESSION['success_message'] = "Ombi lako limepokelewa. Tutakujibu kwa haraka iwezekanavyo.";
            header("Location: help.php?success=1");
            exit();
        } else {
            $error_message = "Hitilafu katika kutuma ombi. Tafadhali jaribu tena.";
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Get success message from session
if (isset($_GET['success'])) {
    $success_message = $_SESSION['success_message'] ?? "Ombi lako limepokelewa. Tutakujibu kwa haraka iwezekanavyo.";
    unset($_SESSION['success_message']);
}

require_once __DIR__ . '/includes/claimant-header.php';
?>

<style>
    /* Help Container */
    .help-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    /* Hero Section */
    .hero-section {
        background: linear-gradient(135deg, #006e2c 0%, #1eb050 100%);
        border-radius: 1rem;
        padding: 2rem;
        color: white;
        text-align: center;
        margin-bottom: 2rem;
    }
    .hero-title {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }
    .hero-subtitle {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    
    /* Help Cards */
    .help-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    .help-card {
        background: white;
        border-radius: 1rem;
        border: 1px solid #e8f0e4;
        padding: 1.5rem;
        text-align: center;
        transition: all 0.2s;
        cursor: pointer;
    }
    .help-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        border-color: #006e2c;
    }
    .help-card-icon {
        width: 64px;
        height: 64px;
        background: #eef6ea;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
    }
    .help-card-icon span {
        font-size: 2rem;
        color: #006e2c;
    }
    .help-card-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #1e2a1e;
        margin-bottom: 0.5rem;
    }
    .help-card-desc {
        font-size: 0.8rem;
        color: #6d7b6c;
    }
    
    /* FAQ Section */
    .faq-section {
        background: white;
        border-radius: 1rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 2rem;
    }
    .faq-header {
        padding: 1.25rem 1.5rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
    }
    .faq-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #1e2a1e;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .faq-item {
        border-bottom: 1px solid #e8f0e4;
    }
    .faq-question {
        padding: 1rem 1.5rem;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background 0.2s;
    }
    .faq-question:hover {
        background-color: #f4fcef;
    }
    .faq-question-text {
        font-weight: 500;
        color: #1e2a1e;
    }
    .faq-answer {
        padding: 0 1.5rem;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease-out;
    }
    .faq-item.active .faq-answer {
        padding: 0 1.5rem 1rem 1.5rem;
        max-height: 300px;
    }
    .faq-answer-content {
        color: #6d7b6c;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    
    /* Contact Form */
    .contact-section {
        background: white;
        border-radius: 1rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 2rem;
    }
    .contact-header {
        padding: 1.25rem 1.5rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
    }
    .contact-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #1e2a1e;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .contact-body {
        padding: 1.5rem;
    }
    
    /* Contact Info Cards */
    .contact-info-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .contact-info-card {
        text-align: center;
        padding: 1rem;
        background: #f4fcef;
        border-radius: 0.75rem;
    }
    .contact-info-card .material-symbols-outlined {
        font-size: 2rem;
        color: #006e2c;
        margin-bottom: 0.5rem;
    }
    .contact-info-card .title {
        font-weight: 600;
        font-size: 0.8rem;
        color: #1e2a1e;
    }
    .contact-info-card .value {
        font-size: 0.75rem;
        color: #6d7b6c;
        margin-top: 0.25rem;
    }
    
    /* Form Styles */
    .form-group {
        margin-bottom: 1.25rem;
    }
    .form-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        margin-bottom: 0.5rem;
    }
    .form-label.required::after {
        content: "*";
        color: #dc2626;
        margin-left: 0.25rem;
    }
    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 0.625rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        transition: all 0.2s;
    }
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    .form-hint {
        font-size: 0.7rem;
        color: #6d7b6c;
        margin-top: 0.25rem;
    }
    
    .btn-submit {
        background-color: #006e2c;
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .btn-submit:hover {
        background-color: #005a24;
    }
    
    .alert-success {
        background-color: #d1fae5;
        border: 1px solid #a7f3d0;
        color: #065f46;
        padding: 1rem;
        border-radius: 0.5rem;
        margin-bottom: 1.5rem;
    }
    .alert-error {
        background-color: #fee2e2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: 1rem;
        border-radius: 0.5rem;
        margin-bottom: 1.5rem;
    }
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    @media (max-width: 768px) {
        .help-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        .contact-info-grid {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        .grid-2 {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        .hero-title {
            font-size: 1.25rem;
        }
    }
</style>

<div class="help-container">
    
    <!-- Hero Section -->
    <div class="hero-section">
        <div class="hero-title">Karibu Kwenye Msaada na Usaidizi</div>
        <div class="hero-subtitle">Tuko hapa kukusaidia kwa maswali yako yote kuhusu madai, tathmini na malipo</div>
    </div>
    
    <!-- Success/Error Messages -->
    <?php if (!empty($success_message)): ?>
    <div class="alert-success">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined">check_circle</span>
            <span><?php echo $success_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    <div class="alert-error">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined">error</span>
            <span><?php echo $error_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Help Categories Cards -->
    <div class="help-grid">
        <div class="help-card" onclick="scrollToForm('claim')">
            <div class="help-card-icon">
                <span class="material-symbols-outlined">description</span>
            </div>
            <div class="help-card-title">Maswali kuhusu Madai</div>
            <div class="help-card-desc">Msaada kuhusu kuwasilisha na kufuatilia madai yako</div>
        </div>
        <div class="help-card" onclick="scrollToForm('valuation')">
            <div class="help-card-icon">
                <span class="material-symbols-outlined">real_estate_agent</span>
            </div>
            <div class="help-card-title">Maswali kuhusu Tathmini</div>
            <div class="help-card-desc">Msaada kuhusu tathmini ya mali na fidia</div>
        </div>
        <div class="help-card" onclick="scrollToForm('payment')">
            <div class="help-card-icon">
                <span class="material-symbols-outlined">payments</span>
            </div>
            <div class="help-card-title">Maswali kuhusu Malipo</div>
            <div class="help-card-desc">Msaada kuhusu malipo ya fidia yako</div>
        </div>
    </div>
    
    <!-- FAQ Section -->
    <div class="faq-section">
        <div class="faq-header">
            <h3>
                <span class="material-symbols-outlined text-primary">help</span>
                Maswali Yanayoulizwa Sana (FAQ)
            </h3>
        </div>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                <span class="faq-question-text">🙋 Je, ni muda gani inachukua kwa dai kuchakatwa?</span>
                <span class="material-symbols-outlined">expand_more</span>
            </div>
            <div class="faq-answer">
                <div class="faq-answer-content">
                    Baada ya kuwasilisha dai lako, inachukua kati ya siku 14 hadi 30 kwa tathmini kukamilika na uamuzi kufanywa. Utapata taarifa kupitia arifa na barua pepe.
                </div>
            </div>
        </div>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                <span class="faq-question-text">📄 Ni nyaraka gani zinahitajika kwa ajili ya kuwasilisha dai?</span>
                <span class="material-symbols-outlined">expand_more</span>
            </div>
            <div class="faq-answer">
                <div class="faq-answer-content">
                    Nyaraka zinazohitajika ni pamoja na: Hati ya umiliki wa ardhi (Title Deed/CCRO), Nakala ya Kitambulisho (ID/NIDA), Picha za mali kabla ya uondoaji, na hati nyingine za kuthibitisha umiliki.
                </div>
            </div>
        </div>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                <span class="faq-question-text">💰 Fidia inahesabiwaje?</span>
                <span class="material-symbols-outlined">expand_more</span>
            </div>
            <div class="faq-answer">
                <div class="faq-answer-content">
                    Fidia inahesabiwa kwa kuzingatia thamani ya soko ya mali, ukubwa wa mali, aina ya mali (makazi, biashara, kilimo), posho ya usumbufu, na gharama za usafiri wa kubebea mali.
                </div>
            </div>
        </div>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                <span class="faq-question-text">💳 Je, malipo hufanywa kwa njia gani?</span>
                <span class="material-symbols-outlined">expand_more</span>
            </div>
            <div class="faq-answer">
                <div class="faq-answer-content">
                    Malipo yanafanywa kwa njia mbalimbali ikiwemo: Benki (Bank Transfer), Mobile Money (M-Pesa, Tigo Pesa, Airtel Money), Taslimu (Cash), na Hundi (Cheque).
                </div>
            </div>
        </div>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                <span class="faq-question-text">📞 Ninawezaje kuwasiliana na ofisi yako?</span>
                <span class="material-symbols-outlined">expand_more</span>
            </div>
            <div class="faq-answer">
                <div class="faq-answer-content">
                    Unaweza kuwasiliana nasi kupitia simu: +255 22 123 4567, barua pepe: support@hcs.go.tz, au kutembelea ofisi zetu zilizo karibu nawe. Pia unaweza kutumia fomu ya mawasiliano hapa chini.
                </div>
            </div>
        </div>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                <span class="faq-question-text">🔒 Je, taarifa zangu ziko salama?</span>
                <span class="material-symbols-outlined">expand_more</span>
            </div>
            <div class="faq-answer">
                <div class="faq-answer-content">
                    Ndiyo, tunatumia teknolojia ya hali ya juu ya usalama ili kulinda taarifa zako zote. Taarifa zako hazitashirikiwa na mtu yeyote isipokuwa idara husika.
                </div>
            </div>
        </div>
    </div>
    
    <!-- Contact Form Section -->
    <div class="contact-section" id="contactForm">
        <div class="contact-header">
            <h3>
                <span class="material-symbols-outlined text-primary">contact_support</span>
                Wasiliana Nasi
            </h3>
        </div>
        <div class="contact-body">
            
            <!-- Contact Info -->
            <div class="contact-info-grid">
                <div class="contact-info-card">
                    <span class="material-symbols-outlined">call</span>
                    <div class="title">Simu</div>
                    <div class="value">+255 22 123 4567</div>
                    <div class="value">+255 713 456 789</div>
                </div>
                <div class="contact-info-card">
                    <span class="material-symbols-outlined">email</span>
                    <div class="title">Barua Pepe</div>
                    <div class="value">support@hcs.go.tz</div>
                    <div class="value">info@hcs.go.tz</div>
                </div>
                <div class="contact-info-card">
                    <span class="material-symbols-outlined">location_on</span>
                    <div class="title">Ofisi Kuu</div>
                    <div class="value">Sokoine Drive,</div>
                    <div class="value">Dar es Salaam, Tanzania</div>
                </div>
            </div>
            
            <!-- Contact Form -->
            <form method="POST" action="" id="helpForm">
                <input type="hidden" name="submit_help" value="1">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label required">Mada</label>
                        <input type="text" name="subject" class="form-input" required placeholder="Mfano: Swali kuhusu tathmini yangu">
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Aina ya Msaada</label>
                        <select name="category" class="form-select" required>
                            <option value="">-- Chagua Aina --</option>
                            <option value="claim">Madai</option>
                            <option value="valuation">Tathmini</option>
                            <option value="payment">Malipo</option>
                            <option value="technical">Kiteknolojia</option>
                            <option value="other">Nyingine</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Namba ya Simu (Hiari)</label>
                        <input type="tel" name="phone" class="form-input" placeholder="0712345678">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Barua Pepe Yako</label>
                        <input type="email" class="form-input" value="<?php echo htmlspecialchars($_SESSION['email']); ?>" readonly disabled>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label required">Ujumbe</label>
                    <textarea name="message" rows="5" class="form-textarea" required placeholder="Tafadhali elezea kwa kina tatizo au swali lako..."></textarea>
                    <div class="form-hint">Jaza maelezo ya kina ili tupate kukusaidia vyema</div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="btn-submit">
                        <span class="material-symbols-outlined text-sm">send</span>
                        Tuma Ujumbe
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Office Hours -->
    <div class="bg-white border border-outline-variant rounded-lg p-4 text-center">
        <div class="flex items-center justify-center gap-2 mb-2">
            <span class="material-symbols-outlined text-primary">schedule</span>
            <span class="font-semibold">Saa za Kufanya Kazi</span>
        </div>
        <p class="text-sm text-secondary">Jumatatu - Ijumaa: 8:00 AM - 5:00 PM</p>
        <p class="text-sm text-secondary">Jumamosi: 9:00 AM - 1:00 PM</p>
        <p class="text-sm text-secondary">Jumapili na Sikukuu: Zimefungwa</p>
    </div>
    
</div>

<script>
    // Toggle FAQ answers
    function toggleFaq(element) {
        const faqItem = element.closest('.faq-item');
        if (faqItem) {
            faqItem.classList.toggle('active');
            const icon = element.querySelector('.material-symbols-outlined:last-child');
            if (icon) {
                if (faqItem.classList.contains('active')) {
                    icon.textContent = 'expand_less';
                } else {
                    icon.textContent = 'expand_more';
                }
            }
        }
    }
    
    // Scroll to contact form with preselected category
    function scrollToForm(category) {
        const form = document.getElementById('contactForm');
        if (form) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            
            // Auto-select category if provided
            if (category) {
                const categorySelect = document.querySelector('select[name="category"]');
                if (categorySelect) {
                    categorySelect.value = category;
                    // Highlight the select briefly
                    categorySelect.style.borderColor = '#006e2c';
                    categorySelect.style.boxShadow = '0 0 0 3px rgba(0,110,44,0.1)';
                    setTimeout(() => {
                        categorySelect.style.borderColor = '';
                        categorySelect.style.boxShadow = '';
                    }, 2000);
                }
            }
        }
    }
    
    // Form validation
    const helpForm = document.getElementById('helpForm');
    if (helpForm) {
        helpForm.addEventListener('submit', function(e) {
            const subject = document.querySelector('input[name="subject"]').value;
            const category = document.querySelector('select[name="category"]').value;
            const message = document.querySelector('textarea[name="message"]').value;
            
            if (!subject.trim()) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu',
                    text: 'Tafadhali jaza mada ya msaada',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            if (!category) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu',
                    text: 'Tafadhali chagua aina ya msaada',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            if (!message.trim() || message.trim().length < 10) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu',
                    text: 'Tafadhali jaza ujumbe wako (angalau herufi 10)',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            // Show confirmation
            e.preventDefault();
            Swal.fire({
                title: 'Thibitisha Kutuma',
                text: 'Je, una uhakika unataka kutuma ombi hili la msaada?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#006e2c',
                cancelButtonColor: '#ba1a1a',
                confirmButtonText: 'Ndiyo, Tuma',
                cancelButtonText: 'Hapana'
            }).then((result) => {
                if (result.isConfirmed) {
                    helpForm.submit();
                }
            });
            
            return false;
        });
    }
</script>

<?php require_once __DIR__ . '/includes/claimant-footer.php'; ?>