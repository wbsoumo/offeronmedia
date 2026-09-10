<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/app/config/database.php';

$error = null;
$success = null;

// Auto-ensure password_resets table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(100) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    // Table creation log silent catch
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$userReset = null;

// Validate Reset Token if provided in URL or POST
if ($token !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT pr.*, u.name, u.email 
            FROM password_resets pr 
            INNER JOIN users u ON u.user_id = pr.user_id 
            WHERE pr.token = ? AND (pr.used = 0 OR pr.used IS NULL)
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $userReset = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userReset) {
            // Check expiration timestamp in PHP to avoid DB/server timezone discrepancy
            $expiryTimestamp = strtotime($userReset['expires_at']);
            if ($expiryTimestamp && $expiryTimestamp < time()) {
                $error = "This password reset link has expired. Please request a new link below.";
                $userReset = null;
            }
        } else {
            $error = "This password reset link is invalid or has already been used. Please request a new link.";
        }
    } catch (PDOException $e) {
        $error = "Database query error: " . $e->getMessage();
    }
}

// Handle Password Reset Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $newPass = $_POST['password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (!$userReset) {
        $error = "Invalid or expired password reset session. Please request a new link.";
    } elseif (strlen($newPass) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($newPass !== $confirmPass) {
        $error = "Passwords do not match. Please try again.";
    } else {
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        
        $up = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
        $up->execute([$newHash, $userReset['user_id']]);

        $mark = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        $mark->execute([$token]);

        $success = "Your password has been reset successfully! You can now log in with your new password.";
        $userReset = null;
        $token = '';
    }
}

// Handle Forgot Password Email Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_reset') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $pdo->prepare("SELECT user_id, name, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Generate secure 64-character token
            $resetToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 7200); // 2 hours valid

            // Invalidate any existing unused reset tokens for this user
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ?")->execute([$user['user_id']]);

            $ins = $pdo->prepare("INSERT INTO password_resets (user_id, email, token, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())");
            $ins->execute([$user['user_id'], $user['email'], $resetToken, $expiresAt]);

            $resetUrl = "https://offeronmedia.com/forgot_password.php?token=" . $resetToken;

            // Send Mail using PHP mail / SMTP
            $to = $user['email'];
            $subject = "Reset Your Password - Offer on Media Network";
            $message = "Hello " . htmlspecialchars($user['name']) . ",\n\n";
            $message .= "We received a request to reset your password for your Offer on Media Network account.\n\n";
            $message .= "Click the link below to set a new password (valid for 2 hours):\n";
            $message .= $resetUrl . "\n\n";
            $message .= "If you did not request a password reset, please ignore this message.\n\n";
            $message .= "Best regards,\nOffer on Media Network Support\nsupport@offeronmedia.com";

            $headers = "From: Offer on Media Support <support@offeronmedia.com>\r\n";
            $headers .= "Reply-To: support@offeronmedia.com\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            @mail($to, $subject, $message, $headers);
        }

        // Show generic confirmation to prevent user enumeration
        $success = "If an account with that email exists, a password reset link has been sent to your inbox.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Reset Password · Offer on Media</title>

    <!-- Theme Auto-Detection Script -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'light' || (!savedTheme && window.matchMedia('(prefers-color-scheme: light)').matches)) {
                document.documentElement.setAttribute('data-theme', 'light');
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <!-- Google Fonts: Plus Jakarta Sans & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
:root {
    --primary: #6366f1;
    --primary-light: #818cf8;
    --primary-dark: #4f46e5;
    --accent: #10b981;
    --cyan: #06b6d4;

    --bg-dark: #090d16;
    --bg-card: rgba(15, 23, 42, 0.8);
    --surface: #0f172a;
    --surface-light: #1e293b;

    --text-primary: #f8fafc;
    --text-secondary: #94a3b8;
    --text-muted: #64748b;

    --border-color: rgba(255, 255, 255, 0.08);
    --glass-blur: blur(16px);
    --shadow-card: 0 20px 40px rgba(0, 0, 0, 0.4);

    --radius-md: 14px;
    --radius-lg: 24px;
    --radius-full: 9999px;
    
    --font-heading: 'Outfit', sans-serif;
    --font-body: 'Plus Jakarta Sans', sans-serif;

    --transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

[data-theme="light"] {
    --bg-dark: #f8fafc;
    --bg-card: rgba(255, 255, 255, 0.9);
    --surface: #ffffff;
    --surface-light: #f1f5f9;

    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-muted: #64748b;

    --border-color: rgba(15, 23, 42, 0.1);
    --shadow-card: 0 10px 30px rgba(15, 23, 42, 0.08);
}

*, *::before, *::after {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: var(--font-body);
    background-color: var(--bg-dark);
    color: var(--text-primary);
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    position: relative;
}

.bg-grid {
    position: fixed;
    inset: 0;
    background-image: 
        radial-gradient(circle at 15% 15%, rgba(99, 102, 241, 0.12) 0%, transparent 40%),
        radial-gradient(circle at 85% 65%, rgba(6, 182, 212, 0.1) 0%, transparent 40%);
    pointer-events: none;
    z-index: 0;
}

.card-panel {
    width: 100%;
    max-width: 480px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    backdrop-filter: var(--glass-blur);
    border-radius: var(--radius-lg);
    padding: 2.5rem;
    box-shadow: var(--shadow-card);
    position: relative;
    z-index: 1;
}

.header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.brand-logo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
    font-family: var(--font-heading);
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--text-primary);
}

.theme-toggle {
    background: var(--surface-light);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
    font-size: 1rem;
}

.theme-toggle:hover {
    transform: scale(1.08);
}

.sun-icon { display: none; color: #f59e0b; }
.moon-icon { display: block; color: var(--primary-light); }

[data-theme="light"] .sun-icon { display: block; }
[data-theme="light"] .moon-icon { display: none; }

.form-title {
    font-size: 1.75rem;
    font-weight: 800;
    margin-bottom: 0.5rem;
}

.form-subtitle {
    color: var(--text-secondary);
    font-size: 0.95rem;
    margin-bottom: 1.75rem;
}

.form-group {
    margin-bottom: 1.25rem;
}

.form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--text-primary);
}

.input-wrap {
    position: relative;
}

.input-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 1rem;
}

.form-control {
    width: 100%;
    padding: 0.85rem 1rem 0.85rem 2.75rem;
    font-family: var(--font-body);
    font-size: 0.95rem;
    background: var(--surface);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    outline: none;
    transition: var(--transition);
}

.form-control:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
}

.btn-submit {
    width: 100%;
    padding: 0.95rem;
    font-size: 1rem;
    font-family: var(--font-heading);
    font-weight: 600;
    border-radius: var(--radius-md);
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: #ffffff;
    border: none;
    cursor: pointer;
    box-shadow: 0 10px 25px -5px rgba(99, 102, 241, 0.4);
    transition: var(--transition);
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 30px -5px rgba(99, 102, 241, 0.6);
}

.alert {
    padding: 0.85rem 1rem;
    border-radius: var(--radius-md);
    font-size: 0.9rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.alert-danger {
    background: rgba(239, 68, 68, 0.1);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.2);
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.2);
}

.footer-link {
    text-align: center;
    margin-top: 1.75rem;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.footer-link a {
    color: var(--primary-light);
    font-weight: 700;
    text-decoration: none;
}
    </style>
</head>
<body>

    <div class="bg-grid"></div>

    <div class="card-panel">
        <div class="header-top">
            <a href="index.html" class="brand-logo">
                <img src="favicon.png" alt="Offer on Media" style="width: 36px; height: 36px; object-fit: contain; border-radius: 8px;">
                <span>Offer on <span style="color: var(--primary-light);">Media</span></span>
            </a>

            <button class="theme-toggle" id="themeToggle" title="Toggle Theme" aria-label="Toggle Theme">
                <i class="fas fa-sun sun-icon"></i>
                <i class="fas fa-moon moon-icon"></i>
            </button>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($userReset): ?>
            <!-- Form 1: New Password Form (When valid token exists) -->
            <h2 class="form-title">Set New Password</h2>
            <p class="form-subtitle">Enter your new password below for <?php echo htmlspecialchars($userReset['email']); ?></p>

            <form action="forgot_password.php" method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="form-group">
                    <label class="form-label" for="password">New Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" class="form-control" placeholder="At least 6 characters" required minlength="6">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm New Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-check-double input-icon"></i>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Repeat new password" required minlength="6">
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-key"></i> Update Password
                </button>
            </form>
        <?php else: ?>
            <!-- Form 2: Request Password Reset Email Link -->
            <h2 class="form-title">Reset Password</h2>
            <p class="form-subtitle">Enter your registered email address to receive a password reset link.</p>

            <form action="forgot_password.php" method="POST">
                <input type="hidden" name="action" value="request_reset">

                <div class="form-group">
                    <label class="form-label" for="email">Work Email Address</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" id="email" name="email" class="form-control" placeholder="name@company.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane"></i> Send Reset Link
                </button>
            </form>
        <?php endif; ?>

        <div class="footer-link">
            Remembered your password? <a href="login.php">Back to Sign In →</a>
        </div>
    </div>

    <script>
        // Theme Toggle Switcher
        document.getElementById('themeToggle').addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        });
    </script>
</body>
</html>
