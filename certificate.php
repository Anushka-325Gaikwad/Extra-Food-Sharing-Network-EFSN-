<?php
/**
 * Extra Food Sharing Network (EFSN)
 * E-Certificate of Appreciation & Recognition
 */
require_once 'config.php';

// Auth Guard: User must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$userName = $_SESSION['name'];

// Verify they have points > 0 to be eligible
$userPoints = 0;
try {
    $stmt = $pdo->prepare("SELECT points FROM rewards WHERE user_id = ?");
    $stmt->execute([$userId]);
    $reward = $stmt->fetch();
    if ($reward) {
        $userPoints = $reward['points'];
    }
} catch (PDOException $e) {
    //
}

// Fallback check: if user has 0 points, they aren't eligible yet
if ($userPoints <= 0) {
    die("<h3>Access Denied</h3><p>You must complete at least one donation or delivery to earn points and claim your Certificate of Appreciation.</p><a href='dashboard.php'>Return to Dashboard</a>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Certificate of Appreciation - <?php echo htmlspecialchars($userName); ?></title>
    
    <!-- Google Fonts: Elegant Display Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700;800&family=Great+Vibes&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --gold: #dfb75c;
            --gold-light: #f3e5ab;
            --dark-navy: #0d131f;
            --border-color: rgba(223, 183, 92, 0.4);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: #090d16;
            color: #f3f4f6;
            font-family: 'Montserrat', sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 40px 20px;
        }

        /* Certificate Container (Landscape A4 Aspect Ratio: 1.414) */
        .certificate-container {
            width: 1000px;
            height: 707px;
            background-color: var(--dark-navy);
            background-image: 
                radial-gradient(circle at 10% 10%, rgba(223, 183, 92, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 90% 90%, rgba(223, 183, 92, 0.05) 0%, transparent 50%);
            border: 20px solid var(--dark-navy);
            padding: 30px;
            position: relative;
            box-shadow: 0 15px 45px rgba(0, 0, 0, 0.6);
            border-radius: 4px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
        }

        /* Elegant Double Gold Border */
        .certificate-border {
            position: absolute;
            top: 15px;
            left: 15px;
            right: 15px;
            bottom: 15px;
            border: 4px double var(--gold);
            pointer-events: none;
        }

        .certificate-inner-border {
            position: absolute;
            top: 25px;
            left: 25px;
            right: 25px;
            bottom: 25px;
            border: 1px solid rgba(223, 183, 92, 0.25);
            pointer-events: none;
        }

        /* Certificate Content Elements */
        .cert-header {
            margin-top: 40px;
            text-align: center;
        }

        .cert-logo {
            font-family: 'Cinzel', serif;
            font-size: 1.6rem;
            color: var(--gold-light);
            font-weight: 700;
            letter-spacing: 0.1em;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .cert-logo i {
            color: #10b981; /* Fresh Emerald Accent */
            text-shadow: 0 0 10px rgba(16, 185, 129, 0.3);
        }

        .cert-title {
            font-family: 'Cinzel', serif;
            font-size: 2.6rem;
            font-weight: 800;
            color: var(--gold);
            letter-spacing: 0.1em;
            text-transform: uppercase;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.4);
            margin-bottom: 5px;
        }

        .cert-subtitle {
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .cert-body {
            text-align: center;
            max-width: 780px;
            margin: 20px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .cert-presentation {
            font-size: 1.05rem;
            font-style: italic;
            color: #9ca3af;
            margin-bottom: 20px;
        }

        .cert-name {
            font-family: 'Great Vibes', cursive;
            font-size: 4.8rem;
            color: var(--gold-light);
            line-height: 1;
            margin-bottom: 15px;
            text-shadow: 0 2px 3px rgba(0, 0, 0, 0.3);
        }

        .cert-divider {
            width: 150px;
            height: 1px;
            background: linear-gradient(to right, transparent, var(--gold), transparent);
            margin-bottom: 20px;
        }

        .cert-text {
            font-size: 1.05rem;
            line-height: 1.7;
            color: #d1d5db;
            margin-bottom: 25px;
        }

        .cert-text strong {
            color: #ffffff;
            font-weight: 600;
        }

        /* Certificate Footer (Signatures and Seal) */
        .cert-footer {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding: 0 60px 40px;
        }

        .sig-block {
            text-align: center;
            width: 180px;
        }

        .sig-line {
            border-bottom: 1px solid rgba(223, 183, 92, 0.4);
            height: 40px;
            margin-bottom: 8px;
            font-family: 'Great Vibes', cursive;
            font-size: 1.8rem;
            color: var(--gold-light);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding-bottom: 5px;
        }

        .sig-title {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #9ca3af;
        }

        /* Circular Gold Seal */
        .gold-seal {
            width: 95px;
            height: 95px;
            background: radial-gradient(circle, var(--gold-light) 0%, var(--gold) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4), inset 0 0 10px rgba(0,0,0,0.15);
            position: relative;
            border: 2px dashed rgba(13, 19, 31, 0.25);
        }

        .gold-seal::after {
            content: '';
            position: absolute;
            border: 1px solid rgba(13, 19, 31, 0.2);
            top: 4px;
            left: 4px;
            right: 4px;
            bottom: 4px;
            border-radius: 50%;
        }

        .seal-content {
            text-align: center;
            color: var(--dark-navy);
            font-family: 'Cinzel', serif;
            font-weight: 700;
            font-size: 0.55rem;
            line-height: 1.2;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .seal-content i {
            font-size: 1.2rem;
            margin-bottom: 4px;
        }

        /* Action Buttons Box */
        .actions-box {
            margin-top: 30px;
            display: flex;
            gap: 15px;
        }

        /* Print Media Styles (A4 Landscape Formats) */
        @media print {
            body {
                background: #ffffff;
                color: #000000;
                padding: 0;
                margin: 0;
                min-height: auto;
            }

            .actions-box, .app-header, footer {
                display: none !important;
            }

            .certificate-container {
                width: 100% !important;
                height: 100vh !important;
                box-shadow: none !important;
                border: none !important;
                background-color: #0a0e17 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            @page {
                size: A4 landscape;
                margin: 0;
            }
        }
    </style>
</head>
<body>

    <!-- 1. Beautiful Landscape Certificate Frame -->
    <div class="certificate-container" id="printableCertificate">
        <!-- Borders -->
        <div class="certificate-border"></div>
        <div class="certificate-inner-border"></div>
        
        <!-- Header -->
        <div class="cert-header">
            <div class="cert-logo">
                <i class="fa-solid fa-hand-holding-heart"></i> EXTRA FOOD SHARING NETWORK
            </div>
            <h1 class="cert-title">Certificate of Appreciation</h1>
            <div class="cert-subtitle">Recognizing Community Impact</div>
        </div>
        
        <!-- Body -->
        <div class="cert-body">
            <p class="cert-presentation">This Certificate is Proudly Presented To</p>
            <h2 class="cert-name"><?php echo htmlspecialchars($userName); ?></h2>
            <div class="cert-divider"></div>
            
            <p class="cert-text">
                In heartfelt recognition of outstanding contributions as an active 
                <strong><?php echo ucfirst($userRole); ?></strong>. By dedicating surplus food resources and rescue 
                efforts to the **Extra Food Sharing Network (EFSN)**, you have successfully saved meals, 
                reduced environmental waste, and directly supported local families in need, accumulating 
                an impactful score of <strong><?php echo $userPoints; ?> Reward Points</strong>.
            </p>
        </div>
        
        <!-- Footer -->
        <div class="cert-footer">
            <!-- Left Signature: EFSN Core team -->
            <div class="sig-block">
                <div class="sig-line">EFSN Team</div>
                <div class="sig-title">EFSN Executive Director</div>
            </div>
            
            <!-- Middle: Embossed Golden Seal -->
            <div class="gold-seal">
                <div class="seal-content">
                    <i class="fa-solid fa-medal"></i>
                    <strong>OFFICIAL</strong><br>SEAL
                </div>
            </div>
            
            <!-- Right Signature: Generation Date -->
            <div class="sig-block">
                <div class="sig-line" style="font-family: inherit; font-size: 1rem; font-weight: 500; height: 40px; padding-bottom: 8px;">
                    <?php echo date('d M Y'); ?>
                </div>
                <div class="sig-title">Date of Issuance</div>
            </div>
        </div>
    </div>

    <!-- 2. Bottom Action Buttons -->
    <div class="actions-box">
        <a href="dashboard.php" class="btn btn-outline">
            <i class="fa-solid fa-arrow-left"></i> Return to Dashboard
        </a>
        <button onclick="window.print()" class="btn btn-primary btn-accent">
            <i class="fa-solid fa-print"></i> Print E-Certificate
        </button>
    </div>

</body>
</html>
