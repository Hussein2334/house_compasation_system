<?php
// auth/help.php - Help and Support Page with Database Storage
session_start();

// Correct include paths - from auth/ folder, go up one level to root
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

$success_message = '';
$error_message = '';

// Handle form submission from contact form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error_message = 'Token ya usalama ni batili. Tafadhali jaribu tena.';
    } else {
        $full_name = sanitizeInput($_POST['full_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $subject = sanitizeInput($_POST['subject'] ?? 'Msaada kutoka kwa mtumiaji');
        $message = sanitizeInput($_POST['message'] ?? '');
        $category = sanitizeInput($_POST['category'] ?? 'other');
        
        if (empty($full_name) || empty($email) || empty($message)) {
            $error_message = 'Tafadhali jaza sehemu zote zinazohitajika (*).';
        } elseif (!validateEmail($email)) {
            $error_message = 'Barua pepe si sahihi. Tafadhali ingiza barua pepe halali.';
        } elseif (strlen($message) < 10) {
            $error_message = 'Ujumbe wako ni mfupi sana. Tafadhali andika angalau herufi 10.';
        } else {
            $conn = getDB();
            
            // Get user_id if logged in
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            
            // Insert into help_requests table (without ip_address and user_agent)
            $query = "INSERT INTO help_requests (user_id, full_name, email, phone, subject, message, category, status, priority, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'medium', NOW())";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "issssss", $user_id, $full_name, $email, $phone, $subject, $message, $category);
            
            if (mysqli_stmt_execute($stmt)) {
                $request_id = mysqli_insert_id($conn);
                
                // Log the help request
                if (function_exists('logAudit')) {
                    logAudit($conn, $user_id ?? 0, 'HELP_REQUEST', 'help_requests', $request_id, null, [
                        'email' => $email,
                        'subject' => $subject,
                        'category' => $category
                    ]);
                }
                
                $success_message = 'Ujumbe wako umetumwa kwa mafanikio! Tutakujibu ndani ya saa 24 kwa barua pepe yako.';
                
                // Clear CSRF token after successful submission
                unset($_SESSION['csrf_token']);
            } else {
                $error_message = 'Kuna hitilafu katika mfumo: ' . mysqli_error($conn);
                error_log("Help request failed: " . mysqli_error($conn));
            }
            
            mysqli_stmt_close($stmt);
            mysqli_close($conn);
        }
    }
}

// Generate CSRF token for the form
$csrf_token = generateCSRFToken();

// Get current page for back button
$redirect_from = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '../index.php';
?>
<!DOCTYPE html>
<html class="light" lang="sw">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0, viewport-fit=cover" name="viewport">
    <meta name="theme-color" content="#006e2c">
    <meta name="csrf-token" content="<?php echo $csrf_token; ?>">
    <title>Msaada | Usajili na Matumizi | House Compensation System (HCS)</title>
    
    <!-- TailwindCSS CDN -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    
    <!-- Material Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "surface-bright": "#f4fcef",
                        "on-tertiary-container": "#690025",
                        "primary-fixed": "#79fd92",
                        "on-background": "#161d16",
                        "on-error-container": "#93000a",
                        "on-surface-variant": "#3d4a3d",
                        "on-primary-fixed": "#002108",
                        "on-error": "#ffffff",
                        "secondary-container": "#fed000",
                        "inverse-primary": "#5be079",
                        "on-secondary-container": "#6f5900",
                        "outline-variant": "#bccab9",
                        "inverse-surface": "#2b322a",
                        "tertiary-fixed-dim": "#ffb2bd",
                        "surface": "#f4fcef",
                        "on-tertiary-fixed": "#400014",
                        "surface-tint": "#006e2c",
                        "primary-container": "#1eb050",
                        "on-secondary-fixed": "#231b00",
                        "surface-variant": "#dde5d9",
                        "secondary-fixed": "#ffe07f",
                        "primary-fixed-dim": "#5be079",
                        "background": "#f4fcef",
                        "primary": "#006e2c",
                        "surface-container": "#e8f0e4",
                        "on-primary-fixed-variant": "#005320",
                        "on-secondary": "#ffffff",
                        "on-tertiary": "#ffffff",
                        "secondary": "#725c00",
                        "on-primary": "#ffffff",
                        "inverse-on-surface": "#ebf3e7",
                        "surface-container-high": "#e3eadf",
                        "outline": "#6d7b6c",
                        "on-tertiary-fixed-variant": "#8d0f38",
                        "on-primary-container": "#003a14",
                        "error-container": "#ffdad6",
                        "error": "#ba1a1a",
                        "tertiary": "#ad2c4e",
                        "surface-container-lowest": "#ffffff",
                        "on-secondary-fixed-variant": "#564500",
                        "surface-container-low": "#eef6ea",
                        "surface-container-highest": "#dde5d9",
                        "on-surface": "#161d16",
                        "tertiary-container": "#fb6787",
                        "tertiary-fixed": "#ffd9dd",
                        "secondary-fixed-dim": "#edc200",
                        "surface-dim": "#d4dcd1"
                    },
                    borderRadius: {
                        DEFAULT: "0.125rem",
                        lg: "0.25rem",
                        xl: "0.5rem",
                        full: "0.75rem"
                    },
                    spacing: {
                        "margin-desktop": "64px",
                        "base": "8px",
                        "xl": "80px",
                        "xs": "4px",
                        "lg": "48px",
                        "sm": "12px",
                        "gutter": "24px",
                        "md": "24px",
                        "max-width": "1280px",
                        "margin-mobile": "16px"
                    },
                    fontFamily: {
                        "body-lg": ["Inter"],
                        "label-md": ["Inter"],
                        "headline-md": ["Inter"],
                        "headline-lg": ["Inter"],
                        "display-lg": ["Inter"],
                        "body-md": ["Inter"],
                        "headline-lg-mobile": ["Inter"],
                        "display-lg-mobile": ["Inter"],
                        "label-sm": ["Inter"]
                    },
                    fontSize: {
                        "body-lg": ["18px", {"lineHeight": "28px", "fontWeight": "400"}],
                        "label-md": ["14px", {"lineHeight": "20px", "letterSpacing": "0.01em", "fontWeight": "600"}],
                        "headline-md": ["24px", {"lineHeight": "32px", "fontWeight": "600"}],
                        "headline-lg": ["32px", {"lineHeight": "40px", "fontWeight": "600"}],
                        "display-lg": ["48px", {"lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                        "body-md": ["16px", {"lineHeight": "24px", "fontWeight": "400"}],
                        "headline-lg-mobile": ["28px", {"lineHeight": "36px", "fontWeight": "600"}],
                        "display-lg-mobile": ["36px", {"lineHeight": "44px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                        "label-sm": ["12px", {"lineHeight": "16px", "letterSpacing": "0.04em", "fontWeight": "500"}]
                    }
                },
            },
        }
    </script>
    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .faq-item {
            transition: all 0.3s ease;
        }
        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        .faq-item.active .faq-answer {
            max-height: 500px;
            transition: max-height 0.3s ease-in;
        }
        .faq-item.active .faq-question-icon {
            transform: rotate(180deg);
        }
        .bg-pattern {
            background-image: radial-gradient(#006e2c 0.5px, transparent 0.5px);
            background-size: 24px 24px;
            opacity: 0.05;
        }
        .search-highlight {
            background-color: #fed000;
            color: #231b00;
            padding: 0 2px;
            border-radius: 4px;
        }
    </style>
</head>
<body class="bg-surface text-on-surface font-body-md selection:bg-primary-fixed selection:text-on-primary-fixed min-h-screen flex flex-col">

    <!-- Top Navigation Bar -->
    <header class="bg-surface border-b-2 border-secondary sticky top-0 z-50">
        <div class="flex justify-between items-center w-full px-margin-mobile md:px-margin-desktop max-w-max-width mx-auto h-16 md:h-20">
            <div class="flex items-center gap-3">
                <a href="<?php echo $redirect_from; ?>" class="hover:opacity-80 transition-opacity">
                    <span class="material-symbols-outlined text-primary">arrow_back</span>
                </a>
                <a href="../index.php" class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-primary text-headline-md">account_balance</span>
                    <h1 class="font-headline-md text-headline-md-mobile md:text-headline-md text-primary font-bold">HCS</h1>
                </a>
            </div>
            <div class="flex items-center gap-4">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="../dashboard.php" class="w-10 h-10 rounded-full bg-primary-container flex items-center justify-center text-on-primary-container font-bold overflow-hidden hover:scale-105 transition-transform">
                        <span class="material-symbols-outlined">account_circle</span>
                    </a>
                <?php else: ?>
                    <a href="login.php" class="font-label-md text-label-md text-primary hover:underline">Ingia</a>
                    <a href="register.php" class="bg-primary text-on-primary px-4 py-2 rounded-lg font-label-md text-label-md hover:bg-primary-container transition-colors">Jisajili</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="flex-grow relative overflow-hidden">
        <!-- Background Pattern -->
        <div class="absolute inset-0 bg-pattern pointer-events-none"></div>
        
        <div class="max-w-4xl mx-auto px-margin-mobile md:px-margin-desktop py-6 md:py-10">
            
            <!-- Hero Section -->
            <div class="text-center mb-8 md:mb-12">
                <div class="inline-flex items-center justify-center w-16 h-16 md:w-20 md:h-20 bg-primary-fixed rounded-full mb-4">
                    <span class="material-symbols-outlined text-4xl md:text-5xl text-on-primary-fixed">help_center</span>
                </div>
                <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-primary font-bold mb-3">
                    Msaada na Usajili
                </h1>
                <p class="text-on-surface-variant font-body-md max-w-2xl mx-auto">
                    Mwongozo kamili wa kukusaidia kujisajili na kutumia mfumo wa fidia ya nyumba.
                </p>
            </div>

            <!-- Quick Search -->
            <div class="mb-8 md:mb-10">
                <div class="bg-surface-container-lowest p-4 md:p-5 rounded-xl border border-outline-variant">
                    <label class="block font-label-md text-label-md mb-2 text-on-surface">
                        <span class="material-symbols-outlined text-sm mr-1">search</span>
                        Tafuta Msaada
                    </label>
                    <div class="relative">
                        <input type="text" id="searchInput" 
                               class="w-full h-12 px-4 pr-12 rounded-lg border border-outline focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all"
                               placeholder="Tafuta... mfano: jinsi ya kujisajili, nin, nywila, barua pepe">
                        <span class="material-symbols-outlined absolute right-3 top-3 text-outline">search</span>
                    </div>
                    <p class="text-label-sm text-on-surface-variant mt-2" id="searchResultsCount"></p>
                </div>
            </div>

            <!-- Registration Guide Section -->
            <div class="mb-10 md:mb-12">
                <div class="flex items-center gap-3 mb-4 md:mb-6">
                    <span class="material-symbols-outlined text-primary text-3xl">app_registration</span>
                    <h2 class="font-headline-lg-mobile md:font-headline-lg text-headline-lg-mobile md:text-headline-lg text-on-surface font-bold">
                        Mwongozo wa Usajili
                    </h2>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                    <!-- Step 1 -->
                    <div class="bg-surface-container-lowest p-4 md:p-5 rounded-xl border border-outline-variant hover:shadow-md transition-all searchable-item">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center text-white font-bold">1</div>
                            <h3 class="font-bold text-body-lg">Jaza Taarifa Zako</h3>
                        </div>
                        <p class="text-on-surface-variant text-sm md:text-base">
                            Ingiza jina lako kamili, barua pepe halali, namba ya simu (inayoanza na 0 ikifuatiwa na 6 au 7), na namba ya utambulisho (NIN).
                        </p>
                        <div class="mt-3 p-3 bg-surface-container rounded-lg">
                            <p class="text-label-sm text-primary font-bold mb-1">Mfano:</p>
                            <ul class="text-xs text-on-surface-variant space-y-1">
                                <li>• Jina: John Juma</li>
                                <li>• Barua Pepe: john@example.com</li>
                                <li>• Simu: 0712345678</li>
                                <li>• NIN: 12345678ABC</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="bg-surface-container-lowest p-4 md:p-5 rounded-xl border border-outline-variant hover:shadow-md transition-all searchable-item">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center text-white font-bold">2</div>
                            <h3 class="font-bold text-body-lg">Weka Nywila Salama</h3>
                        </div>
                        <p class="text-on-surface-variant text-sm md:text-base">
                            Nywila yako lazima iwe na angalau herufi 6. Tumia mchanganyiko wa herufi kubwa, ndogo, namba na alama kwa usalama zaidi.
                        </p>
                        <div class="mt-3 flex gap-2 flex-wrap">
                            <span class="text-xs px-2 py-1 bg-green-100 text-green-800 rounded-full">✅ Herufi kubwa</span>
                            <span class="text-xs px-2 py-1 bg-green-100 text-green-800 rounded-full">✅ Herufi ndogo</span>
                            <span class="text-xs px-2 py-1 bg-green-100 text-green-800 rounded-full">✅ Namba</span>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="bg-surface-container-lowest p-4 md:p-5 rounded-xl border border-outline-variant hover:shadow-md transition-all searchable-item">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center text-white font-bold">3</div>
                            <h3 class="font-bold text-body-lg">Kubali Masharti</h3>
                        </div>
                        <p class="text-on-surface-variant text-sm md:text-base">
                            Soma na ukubali Vigezo na Masharti pamoja na Sera ya Faragha ya mfumo. Hii ni lazima ili kuendelea na usajili.
                        </p>
                        <div class="mt-3 p-2 bg-secondary-container/20 rounded-lg">
                            <p class="text-label-sm italic">"Ninakubali Vigezo na Masharti pamoja na Sera ya Faragha"</p>
                        </div>
                    </div>

                    <!-- Step 4 -->
                    <div class="bg-surface-container-lowest p-4 md:p-5 rounded-xl border border-outline-variant hover:shadow-md transition-all searchable-item">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center text-white font-bold">4</div>
                            <h3 class="font-bold text-body-lg">Bofya Jisajili</h3>
                        </div>
                        <p class="text-on-surface-variant text-sm md:text-base">
                            Baada ya kukamilisha taarifa zote, bofya kitufe cha "Jisajili Sasa". Subiri uthibitisho wa usajili wako.
                        </p>
                        <div class="mt-3">
                            <div class="inline-flex items-center gap-2 bg-primary-container text-on-primary-container px-4 py-2 rounded-lg text-sm">
                                <span class="material-symbols-outlined text-sm">person_add</span>
                                <span>Jisajili Sasa</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Common Issues / FAQ Section -->
            <div class="mb-10 md:mb-12">
                <div class="flex items-center gap-3 mb-4 md:mb-6">
                    <span class="material-symbols-outlined text-primary text-3xl">quiz</span>
                    <h2 class="font-headline-lg-mobile md:font-headline-lg text-headline-lg-mobile md:text-headline-lg text-on-surface font-bold">
                        Maswali na Majibu (FAQ)
                    </h2>
                </div>
                
                <div class="space-y-3" id="faqContainer">
                    <!-- FAQ 1 - Jinsi ya kupata NIN -->
                    <div class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant overflow-hidden searchable-item">
                        <div class="faq-question p-4 md:p-5 cursor-pointer flex justify-between items-center hover:bg-surface-container transition-colors">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-primary">help_outline</span>
                                <h3 class="font-bold text-body-md">Ninawezaje kupata namba ya utambulisho (NIN)?</h3>
                            </div>
                            <span class="material-symbols-outlined faq-question-icon transition-transform">expand_more</span>
                        </div>
                        <div class="faq-answer px-4 md:px-5 pb-4 md:pb-5">
                            <div class="pt-2 border-t border-outline-variant">
                                <p class="text-on-surface-variant text-sm md:text-base">
                                    Namba ya utambulisho (NIN) unapata kwa kujiandikisha kwenye Mamlaka ya Usajili wa watu (NIDA). Unaweza:
                                </p>
                                <ul class="list-disc list-inside text-sm text-on-surface-variant mt-2 space-y-1">
                                    <li>Tembelea ofisi yoyote ya NIDA karibu nawe</li>
                                    <li>Jaza fomu ya usajili wa utambulisho</li>
                                    <li>Piga alama za vidole na piga picha</li>
                                    <li>Subiri kadi yako ya kitambulisho na namba ya NIN</li>
                                </ul>
                                <p class="text-label-sm text-primary mt-2">📞 Wasiliana NIDA: 0800 110 120</p>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 2 - Nimeisahau nywila -->
                    <div class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant overflow-hidden searchable-item">
                        <div class="faq-question p-4 md:p-5 cursor-pointer flex justify-between items-center hover:bg-surface-container transition-colors">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-primary">password</span>
                                <h3 class="font-bold text-body-md">Nimeisahau nywila yangu, nifanye nini?</h3>
                            </div>
                            <span class="material-symbols-outlined faq-question-icon transition-transform">expand_more</span>
                        </div>
                        <div class="faq-answer px-4 md:px-5 pb-4 md:pb-5">
                            <div class="pt-2 border-t border-outline-variant">
                                <p class="text-on-surface-variant text-sm md:text-base">
                                    Kwenye ukurasa wa kuingia (login), bofya "Umesahau nywila?". Kisha:
                                </p>
                                <ol class="list-decimal list-inside text-sm text-on-surface-variant mt-2 space-y-1">
                                    <li>Ingiza barua pepe uliyojisajilia nayo</li>
                                    <li>Utapokea barua pepe ya kuweka upya nywila</li>
                                    <li>Fuata kiungo kilichotumwa</li>
                                    <li>Weka nywila mpya</li>
                                </ol>
                                <p class="text-label-sm text-primary mt-2">💡 Hakikisha umeangaza Spam folder kama hujapokea barua pepe</p>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 3 - Kwanini sipewi barua pepe -->
                    <div class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant overflow-hidden searchable-item">
                        <div class="faq-question p-4 md:p-5 cursor-pointer flex justify-between items-center hover:bg-surface-container transition-colors">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-primary">email</span>
                                <h3 class="font-bold text-body-md">Kwa nini sipewi barua pepe ya uthibitisho?</h3>
                            </div>
                            <span class="material-symbols-outlined faq-question-icon transition-transform">expand_more</span>
                        </div>
                        <div class="faq-answer px-4 md:px-5 pb-4 md:pb-5">
                            <div class="pt-2 border-t border-outline-variant">
                                <p class="text-on-surface-variant text-sm md:text-base">
                                    Kuna sababu kadhaa zinazoweza kusababisha usipokewa barua pepe:
                                </p>
                                <ul class="list-disc list-inside text-sm text-on-surface-variant mt-2 space-y-1">
                                    <li>Barua pepe uliyo ingiza si sahihi</li>
                                    <li>Barua imeingia kwenye Spam/Junk folder</li>
                                    <li>Kuna tatizo la mtandao au seva</li>
                                    <li>Subiri dakika chache barua inaweza kuchelewa</li>
                                </ul>
                                <p class="text-label-sm text-primary mt-2">📧 Wasiliana nasi: support@hcs.go.tz</p>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 4 - Barua pepe tayari imesajiliwa -->
                    <div class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant overflow-hidden searchable-item">
                        <div class="faq-question p-4 md:p-5 cursor-pointer flex justify-between items-center hover:bg-surface-container transition-colors">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-primary">error</span>
                                <h3 class="font-bold text-body-md">Napata hitilafu "Barua pepe tayari imesajiliwa"</h3>
                            </div>
                            <span class="material-symbols-outlined faq-question-icon transition-transform">expand_more</span>
                        </div>
                        <div class="faq-answer px-4 md:px-5 pb-4 md:pb-5">
                            <div class="pt-2 border-t border-outline-variant">
                                <p class="text-on-surface-variant text-sm md:text-base">
                                    Hii inamaanisha kuwa barua pepe yako tayari inatumika kwenye akaunti nyingine. Unaweza:
                                </p>
                                <ul class="list-disc list-inside text-sm text-on-surface-variant mt-2 space-y-1">
                                    <li>Jaribu kuingia kwenye akaunti yako kwa kutumia barua pepe hiyo</li>
                                    <li>Kama hukumbuki nywila, tumia "Umesahau nywila"</li>
                                    <li>Kama hukumbuki akaunti, wasiliana na msaada</li>
                                    <li>Tumia barua pepe nyingine kujisajili</li>
                                </ul>
                                <p class="text-label-sm text-primary mt-2">🔐 Usijaribu kuunda akaunti nyingine kwa barua pepe moja</p>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 5 - Namba ya simu inakataliwa -->
                    <div class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant overflow-hidden searchable-item">
                        <div class="faq-question p-4 md:p-5 cursor-pointer flex justify-between items-center hover:bg-surface-container transition-colors">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-primary">phone_android</span>
                                <h3 class="font-bold text-body-lg">Namba ya simu inakataliwa, kwa nini?</h3>
                            </div>
                            <span class="material-symbols-outlined faq-question-icon transition-transform">expand_more</span>
                        </div>
                        <div class="faq-answer px-4 md:px-5 pb-4 md:pb-5">
                            <div class="pt-2 border-t border-outline-variant">
                                <p class="text-on-surface-variant text-sm md:text-base">
                                    Namba ya simu lazima iwe kwa muundo sahihi wa Tanzania:
                                </p>
                                <ul class="list-disc list-inside text-sm text-on-surface-variant mt-2 space-y-1">
                                    <li>Lazima ianze na 0 (sifuri)</li>
                                    <li>Kisha 6 au 7 (kwa Tigo, Airtel, Vodacom, Halotel, Zantel)</li>
                                    <li>Jumla ya tarakimu 10 kamili</li>
                                    <li>Mfano sahihi: 0712345678 au 0678123456</li>
                                    <li>Mfano usio sahihi: +255712345678 au 712345678</li>
                                </ul>
                                <div class="mt-3 p-2 bg-error-container/10 rounded-lg border border-error-container">
                                    <p class="text-label-sm">⚠️ Usiweke +255 au namba ya kimataifa. Tumia namba ya ndani kuanzia 0.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 6 - Usalama wa data -->
                    <div class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant overflow-hidden searchable-item">
                        <div class="faq-question p-4 md:p-5 cursor-pointer flex justify-between items-center hover:bg-surface-container transition-colors">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-primary">verified</span>
                                <h3 class="font-bold text-body-md">Je, taarifa zangu ziko salama?</h3>
                            </div>
                            <span class="material-symbols-outlined faq-question-icon transition-transform">expand_more</span>
                        </div>
                        <div class="faq-answer px-4 md:px-5 pb-4 md:pb-5">
                            <div class="pt-2 border-t border-outline-variant">
                                <p class="text-on-surface-variant text-sm md:text-base">
                                    Ndiyo, tunachukua usalama wa data yako kwa uzito mkubwa:
                                </p>
                                <ul class="list-disc list-inside text-sm text-on-surface-variant mt-2 space-y-1">
                                    <li>Nywila zote zimehifadhiwa kwa encryption (hashing)</li>
                                    <li>Tunatumia SSL certificate kwa usalama wa mawasiliano</li>
                                    <li>Taarifa zako hazitolewi kwa mtu yeyote bila idhini yako</li>
                                    <li>Tunafuata Sheria ya Mtandao na Sera ya Faragha ya Tanzania</li>
                                    <li>Tuna backup za usalama kwa nyakati tofauti</li>
                                </ul>
                                <p class="text-label-sm text-primary mt-2">🔒 Tunathamini usalama wako</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Support Section -->
            <div class="bg-primary-fixed/20 rounded-xl p-6 md:p-8 border border-primary/30">
                <div class="text-center mb-4">
                    <span class="material-symbols-outlined text-primary text-4xl mb-2">support_agent</span>
                    <h2 class="font-headline-md text-headline-md text-on-surface font-bold">Bado Unahitaji Msaada?</h2>
                    <p class="text-on-surface-variant mt-2">Wasiliana nasi kwa njia zifuatazo</p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                    <div class="text-center p-4 bg-surface-container-lowest rounded-xl">
                        <span class="material-symbols-outlined text-primary text-3xl">call</span>
                        <h3 class="font-bold mt-2">Simu</h3>
                        <p class="text-sm text-on-surface-variant">+255 22 123 4567</p>
                        <p class="text-xs text-on-surface-variant">Jumatatu-Ijumaa, 8am-5pm</p>
                    </div>
                    <div class="text-center p-4 bg-surface-container-lowest rounded-xl">
                        <span class="material-symbols-outlined text-primary text-3xl">email</span>
                        <h3 class="font-bold mt-2">Barua Pepe</h3>
                        <p class="text-sm text-on-surface-variant">support@hcs.go.tz</p>
                        <p class="text-xs text-on-surface-variant">Tunajibu ndani ya saa 24</p>
                    </div>
                    <div class="text-center p-4 bg-surface-container-lowest rounded-xl">
                        <span class="material-symbols-outlined text-primary text-3xl">chat</span>
                        <h3 class="font-bold mt-2">WhatsApp</h3>
                        <p class="text-sm text-on-surface-variant">+255 712 345 678</p>
                        <p class="text-xs text-on-surface-variant">Tuma ujumbe kwa namba hii</p>
                    </div>
                </div>
                
                <div class="mt-6 text-center">
                    <button onclick="showContactForm()" 
                            class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold hover:bg-primary-container transition-colors inline-flex items-center gap-2">
                        <span class="material-symbols-outlined">send</span>
                        Tumia Fomu ya Mawasiliano
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-on-background text-primary-fixed border-t border-outline-variant mt-auto">
        <div class="w-full py-xl px-margin-mobile md:px-margin-desktop grid grid-cols-1 md:grid-cols-2 gap-gutter max-w-max-width mx-auto">
            <div>
                <h3 class="font-black text-headline-sm text-primary-fixed mb-sm">HCS</h3>
                <p class="font-label-sm text-surface-variant max-w-sm">© 2025 House Compensation System (HCS). United Republic of Tanzania. Haki zote zimehifadhiwa.</p>
            </div>
            <div class="grid grid-cols-2 gap-sm">
                <div class="space-y-xs">
                    <h4 class="font-label-md font-bold text-secondary-fixed">Huduma</h4>
                    <ul class="space-y-xs">
                        <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="../track-claim.php">Kufuatilia Madai</a></li>
                        <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="#">Mwongozo wa Mchakato</a></li>
                    </ul>
                </div>
                <div class="space-y-xs">
                    <h4 class="font-label-md font-bold text-secondary-fixed">Msaada</h4>
                    <ul class="space-y-xs">
                        <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="help.php">Msaada</a></li>
                        <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="../privacy.php">Sera ya Faragha</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bottom Nav for Mobile -->
    <nav class="md:hidden fixed bottom-0 w-full z-50 bg-surface border-t border-outline-variant flex justify-around items-center h-16 px-4 shadow-sm">
        <a class="flex flex-col items-center justify-center text-on-surface-variant font-medium" href="../index.php">
            <span class="material-symbols-outlined">home</span>
            <span class="font-label-sm text-label-sm">Nyumbani</span>
        </a>
        <a class="flex flex-col items-center justify-center text-primary font-bold" href="register.php">
            <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">person_add</span>
            <span class="font-label-sm text-label-sm">Jisajili</span>
        </a>
        <a class="flex flex-col items-center justify-center text-primary font-bold" href="help.php">
            <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">help</span>
            <span class="font-label-sm text-label-sm">Msaada</span>
        </a>
    </nav>

    <script>
        // FAQ Accordion functionality
        const faqItems = document.querySelectorAll('.faq-item');
        
        faqItems.forEach(item => {
            const question = item.querySelector('.faq-question');
            if (question) {
                question.addEventListener('click', () => {
                    faqItems.forEach(otherItem => {
                        if (otherItem !== item && otherItem.classList.contains('active')) {
                            otherItem.classList.remove('active');
                        }
                    });
                    item.classList.toggle('active');
                });
            }
        });

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const searchableItems = document.querySelectorAll('.searchable-item');
        const searchResultsCount = document.getElementById('searchResultsCount');

        function performSearch() {
            if (!searchInput) return;
            
            const searchTerm = searchInput.value.toLowerCase().trim();
            let visibleCount = 0;
            
            if (searchTerm === '') {
                searchableItems.forEach(item => {
                    item.style.display = '';
                    clearHighlights(item);
                });
                if (searchResultsCount) searchResultsCount.textContent = '';
                return;
            }
            
            searchableItems.forEach(item => {
                const text = item.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    item.style.display = '';
                    visibleCount++;
                    highlightMatches(item, searchTerm);
                } else {
                    item.style.display = 'none';
                }
            });
            
            if (searchResultsCount) {
                searchResultsCount.textContent = visibleCount > 0 ? `Matokeo: ${visibleCount} yamepatikana` : 'Hakuna matokeo yaliyopatikana';
            }
        }
        
        function highlightMatches(element, searchTerm) {
            clearHighlights(element);
            if (!searchTerm) return;
            
            const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT, {
                acceptNode: function(node) {
                    if (node.parentElement && 
                        (node.parentElement.tagName === 'SCRIPT' || 
                         node.parentElement.classList?.contains('no-highlight'))) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    return NodeFilter.FILTER_ACCEPT;
                }
            });
            
            const textNodes = [];
            while (walker.nextNode()) textNodes.push(walker.currentNode);
            
            textNodes.forEach(node => {
                const text = node.textContent;
                const regex = new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
                if (regex.test(text)) {
                    const span = document.createElement('span');
                    span.innerHTML = text.replace(regex, '<span class="search-highlight no-highlight">$1</span>');
                    node.parentNode.replaceChild(span, node);
                }
            });
        }
        
        function clearHighlights(element) {
            const highlights = element.querySelectorAll('.search-highlight');
            highlights.forEach(highlight => {
                const parent = highlight.parentNode;
                parent.replaceChild(document.createTextNode(highlight.textContent), highlight);
                parent.normalize();
            });
        }
        
        let searchTimeout;
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(performSearch, 300);
            });
        }
        
        // Contact form modal with database submission
        function showContactForm() {
            Swal.fire({
                title: 'Wasiliana Nasi',
                html: `
                    <form id="contactForm" class="text-left">
                        <div class="mb-3">
                            <label class="font-label-md text-label-md block text-left mb-1">Jina Kamili <span class="text-red-500">*</span></label>
                            <input type="text" id="full_name" class="w-full p-2 border rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="Mfano: John Juma" value="Abdulnasir Issa">
                        </div>
                        <div class="mb-3">
                            <label class="font-label-md text-label-md block text-left mb-1">Barua Pepe <span class="text-red-500">*</span></label>
                            <input type="email" id="email" class="w-full p-2 border rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="barua@mfano.tz" value="abdulnasirissa14@gmail.com">
                        </div>
                        <div class="mb-3">
                            <label class="font-label-md text-label-md block text-left mb-1">Namba ya Simu</label>
                            <input type="tel" id="phone" class="w-full p-2 border rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="0712345678" value="0647322678">
                        </div>
                        <div class="mb-3">
                            <label class="font-label-md text-label-md block text-left mb-1">Aina ya Msaada <span class="text-red-500">*</span></label>
                            <select id="category" class="w-full p-2 border rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                                <option value="registration" selected>📝 Usajili</option>
                                <option value="claim">🏠 Dai / Fidia</option>
                                <option value="valuation">📊 Tathmini ya Mali</option>
                                <option value="payment">💰 Malipo</option>
                                <option value="technical">🖥️ Tatizo la Kiufundi</option>
                                <option value="other">❓ Nyingine</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="font-label-md text-label-md block text-left mb-1">Kichwa cha Ujumbe</label>
                            <input type="text" id="subject" class="w-full p-2 border rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="Mfano: Shida ya kujisajili" value="kujisajili">
                        </div>
                        <div class="mb-3">
                            <label class="font-label-md text-label-md block text-left mb-1">Ujumbe <span class="text-red-500">*</span></label>
                            <textarea id="message" rows="4" class="w-full p-2 border rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="Andika ujumbe wako kwa undani...">nashindwa kujisajili</textarea>
                        </div>
                        <input type="hidden" id="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" id="action" value="send_message">
                    </form>
                `,
                width: '550px',
                showCancelButton: true,
                confirmButtonColor: '#006e2c',
                cancelButtonColor: '#ba1a1a',
                confirmButtonText: 'Tuma Ujumbe',
                cancelButtonText: 'Ghairi',
                showLoaderOnConfirm: true,
                preConfirm: async () => {
                    const full_name = document.getElementById('full_name').value.trim();
                    const email = document.getElementById('email').value.trim();
                    const phone = document.getElementById('phone').value.trim();
                    const category = document.getElementById('category').value;
                    const subject = document.getElementById('subject').value.trim();
                    const message = document.getElementById('message').value.trim();
                    const csrf_token = document.getElementById('csrf_token').value;
                    
                    if (!full_name || !email || !message) {
                        Swal.showValidationMessage('❌ Tafadhali jaza sehemu zote zenye nyota (*)');
                        return false;
                    }
                    
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                        Swal.showValidationMessage('❌ Barua pepe si sahihi');
                        return false;
                    }
                    
                    if (message.length < 10) {
                        Swal.showValidationMessage('❌ Ujumbe wako ni mfupi sana (angalau herufi 10)');
                        return false;
                    }
                    
                    try {
                        const formData = new URLSearchParams({
                            csrf_token: csrf_token,
                            action: 'send_message',
                            full_name: full_name,
                            email: email,
                            phone: phone,
                            category: category,
                            subject: subject || 'Msaada kutoka kwa mtumiaji',
                            message: message
                        });
                        
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData
                        });
                        
                        const result = await response.text();
                        
                        if (result.includes('success') || result.includes('Ujumbe wako umetumwa')) {
                            return { full_name, email, category };
                        } else {
                            Swal.showValidationMessage('❌ Hitilafu katika kutuma ujumbe. Jaribu tena.');
                            return false;
                        }
                    } catch (error) {
                        Swal.showValidationMessage('❌ Hitilafu ya mtandao. Hakikisha una muunganisho mzuri.');
                        return false;
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: '✅ Ujumbe Umetumwa!',
                        html: `<p>Ujumbe wako umepokelewa kwa mafanikio.</p><p class="text-sm text-gray-600 mt-2">Tutakujibu ndani ya saa 24 kwa barua pepe yako.</p>`,
                        confirmButtonColor: '#006e2c',
                        confirmButtonText: 'Sawa',
                        timer: 5000
                    }).then(() => {
                        // Reload page to reset form
                        window.location.reload();
                    });
                }
            });
        }
        
        // Display success/error messages from PHP
        <?php if (!empty($success_message)): ?>
        Swal.fire({
            icon: 'success',
            title: 'Mafanikio!',
            text: '<?php echo addslashes($success_message); ?>',
            confirmButtonColor: '#006e2c',
            timer: 4000
        }).then(() => {
            // Clear form values after success
            if (document.getElementById('full_name')) {
                document.getElementById('full_name').value = '';
                document.getElementById('email').value = '';
                document.getElementById('phone').value = '';
                document.getElementById('message').value = '';
            }
        });
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
        Swal.fire({
            icon: 'error',
            title: 'Hitilafu!',
            text: '<?php echo addslashes($error_message); ?>',
            confirmButtonColor: '#006e2c'
        });
        <?php endif; ?>
    </script>
</body>
</html>