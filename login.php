<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/app/core/auth.php';

$error = null;
$success = null;

// Check for session logout message
if (isset($_GET['logout'])) {
    $success = 'You have been successfully logged out.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role     = $_POST['role'] ?? '';
    $email    = trim($_POST['email'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (!in_array($role, ['affiliate', 'advertiser', 'manager'], true)) {
        $error = 'Please select your account type';
    } elseif ($email === '' || $pass === '') {
        $error = 'Email and password are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        $result = auth_login($email, $pass, $remember, $role);
        
        if (!$result['success']) {
            $error = $result['error'] ?? 'Invalid credentials. Please try again.';
        } else {
            // Secure session regenerate & redirect
            session_regenerate_id(true);
            if ($result['role'] === 'affiliate') {
                header('Location: /affiliate/dashboard.php');
            } elseif ($result['role'] === 'manager') {
                header('Location: /manager/dashboard.php');
            } else {
                header('Location: /advertiser/dashboard.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Offer on Media · Partner Portal Login</title>

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
/* ==========================================================================
   DESIGN SYSTEM & THEME TOKENS
   ========================================================================== */
:root {
    --primary: #6366f1;
    --primary-light: #818cf8;
    --primary-dark: #4f46e5;
    --accent: #10b981;
    --cyan: #06b6d4;

    --bg-dark: #090d16;
    --bg-card: rgba(15, 23, 42, 0.75);
    --surface: #0f172a;
    --surface-light: #1e293b;

    --text-primary: #f8fafc;
    --text-secondary: #94a3b8;
    --text-muted: #64748b;

    --border-color: rgba(255, 255, 255, 0.08);
    --border-glow: rgba(99, 102, 241, 0.35);
    --glass-bg: rgba(15, 23, 42, 0.8);
    --glass-blur: blur(16px);
    --shadow-card: 0 20px 40px rgba(0, 0, 0, 0.4);

    --radius-sm: 8px;
    --radius-md: 14px;
    --radius-lg: 24px;
    --radius-full: 9999px;
    
    --font-heading: 'Outfit', sans-serif;
    --font-body: 'Plus Jakarta Sans', sans-serif;

    --transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

[data-theme="light"] {
    --bg-dark: #f8fafc;
    --bg-card: rgba(255, 255, 255, 0.88);
    --surface: #ffffff;
    --surface-light: #f1f5f9;

    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-muted: #64748b;

    --border-color: rgba(15, 23, 42, 0.1);
    --border-glow: rgba(99, 102, 241, 0.35);
    --glass-bg: rgba(255, 255, 255, 0.9);
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
    flex-direction: column;
    overflow-x: hidden;
    transition: background-color 0.3s ease, color 0.3s ease;
}

/* Background Gradients */
.bg-grid {
    position: fixed;
    inset: 0;
    background-image: 
        radial-gradient(circle at 15% 15%, rgba(99, 102, 241, 0.12) 0%, transparent 40%),
        radial-gradient(circle at 85% 65%, rgba(6, 182, 212, 0.1) 0%, transparent 40%);
    pointer-events: none;
    z-index: 0;
}

.auth-wrapper {
    display: flex;
    min-height: 100vh;
    position: relative;
    z-index: 1;
}

/* Left Panel - Brand Showcase */
.brand-panel {
    flex: 1;
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.95) 0%, rgba(30, 41, 59, 0.9) 100%);
    border-right: 1px solid var(--border-color);
    padding: 3rem 4rem;
    display: none;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}

@media (min-width: 1024px) {
    .brand-panel {
        display: flex;
    }
}

.brand-logo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
    font-family: var(--font-heading);
    font-size: 1.5rem;
    font-weight: 800;
    color: #ffffff;
}

.brand-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--cyan) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #ffffff;
    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
}

.hero-text-area {
    margin: auto 0;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: var(--radius-full);
    font-size: 0.875rem;
    font-weight: 600;
    background: rgba(99, 102, 241, 0.15);
    color: var(--primary-light);
    border: 1px solid rgba(99, 102, 241, 0.3);
    margin-bottom: 1.5rem;
}

.brand-headline {
    font-size: clamp(2rem, 3.5vw, 3rem);
    font-weight: 800;
    line-height: 1.15;
    color: #ffffff;
    margin-bottom: 1.25rem;
}

.brand-desc {
    color: #94a3b8;
    font-size: 1.1rem;
    line-height: 1.6;
    max-width: 480px;
    margin-bottom: 2.5rem;
}

.brand-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
}

.stat-item {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
    padding: 1.25rem;
    border-radius: var(--radius-md);
    text-align: center;
}

.stat-val {
    font-family: var(--font-heading);
    font-size: 1.5rem;
    font-weight: 800;
    color: #ffffff;
}

.stat-lbl {
    font-size: 0.75rem;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 0.25rem;
}

/* Right Panel - Login Form */
.form-panel {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 2.5rem 1.5rem;
    position: relative;
}

.auth-card {
    width: 100%;
    max-width: 460px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    backdrop-filter: var(--glass-blur);
    border-radius: var(--radius-lg);
    padding: 2.5rem;
    box-shadow: var(--shadow-card);
}

.header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
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

/* Role Selector Pills */
.role-tabs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
    background: var(--surface-light);
    padding: 0.35rem;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-color);
    margin-bottom: 1.75rem;
}

.role-tab {
    padding: 0.65rem 0.5rem;
    font-size: 0.825rem;
    font-weight: 600;
    text-align: center;
    border-radius: var(--radius-sm);
    color: var(--text-secondary);
    cursor: pointer;
    transition: var(--transition);
    border: none;
    background: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}

.role-tab.active {
    background: var(--primary);
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

/* Input Fields */
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

.toggle-password {
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    cursor: pointer;
}

/* Checkbox & Links */
.flex-between {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.875rem;
    margin-bottom: 1.5rem;
}

.remember-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--text-secondary);
    cursor: pointer;
}

.forgot-link {
    color: var(--primary-light);
    text-decoration: none;
    font-weight: 600;
}

.forgot-link:hover {
    text-decoration: underline;
}

.btn-submit {
    width: 100%;
    padding: 0.95rem;
    font-size: 1rem;
    border-radius: var(--radius-md);
}

/* Alert Boxes */
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

.auth-footer {
    text-align: center;
    margin-top: 1.75rem;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.auth-footer a {
    color: var(--primary-light);
    font-weight: 700;
    text-decoration: none;
}
    </style>
</head>
<body>

    <div class="bg-grid"></div>

    <div class="auth-wrapper">
        <!-- Left Brand Panel -->
        <div class="brand-panel">
            <a href="index.html" class="brand-logo">
                <img src="favicon.png" alt="Offer on Media" style="width: 42px; height: 42px; object-fit: contain; border-radius: 10px;">
                <span>Offer on Media</span>
            </a>

            <div class="hero-text-area">
                <div class="badge">
                    <i class="fas fa-shield-alt"></i> Secure Partner Portal
                </div>
                <h1 class="brand-headline">
                    Access High-Yield Performance Campaigns
                </h1>
                <p class="brand-desc">
                    Sign in to track real-time conversions, manage subIDs, generate tracking links, and check instant payouts.
                </p>

                <div class="brand-stats">
                    <div class="stat-item">
                        <div class="stat-val">50K+</div>
                        <div class="stat-lbl">Affiliates</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-val">₹15.8M+</div>
                        <div class="stat-lbl">Payouts</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-val">99.99%</div>
                        <div class="stat-lbl">Uptime</div>
                    </div>
                </div>
            </div>

            <div style="font-size: 0.85rem; color: #64748b;">
                © 2026 offeronmedia.com. All rights reserved.
            </div>
        </div>

        <!-- Right Form Panel -->
        <div class="form-panel">
            <div class="auth-card">
                <div class="header-top">
                    <a href="index.html" class="brand-logo" style="font-size: 1.25rem;">
                        <img src="favicon.png" alt="Offer on Media" style="width: 36px; height: 36px; object-fit: contain; border-radius: 8px;">
                        <span>Offer on <span style="color: var(--primary-light);">Media</span></span>
                    </a>

                    <button class="theme-toggle" id="themeToggle" title="Toggle Theme" aria-label="Toggle Theme">
                        <i class="fas fa-sun sun-icon"></i>
                        <i class="fas fa-moon moon-icon"></i>
                    </button>
                </div>

                <h2 class="form-title">Welcome Back</h2>
                <p class="form-subtitle">Select your account type to enter partner portal</p>

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

                <form action="login.php" method="POST">
                    <input type="hidden" name="role" id="selectedRole" value="affiliate">

                    <div class="role-tabs">
                        <button type="button" class="role-tab active" onclick="setRole('affiliate', this)">
                            <i class="fas fa-paper-plane"></i> Publisher
                        </button>
                        <button type="button" class="role-tab" onclick="setRole('advertiser', this)">
                            <i class="fas fa-bullhorn"></i> Advertiser
                        </button>
                        <button type="button" class="role-tab" onclick="setRole('manager', this)">
                            <i class="fas fa-user-shield"></i> Manager
                        </button>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">Email Address</label>
                        <div class="input-wrap">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" id="email" name="email" class="form-control" placeholder="name@company.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-wrap">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                            <i class="fas fa-eye toggle-password" onclick="togglePasswordVisibility()"></i>
                        </div>
                    </div>

                    <div class="flex-between">
                        <label class="remember-label">
                            <input type="checkbox" name="remember" style="accent-color: var(--primary);">
                            <span>Remember me</span>
                        </label>
                        <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary btn-submit">
                        <i class="fas fa-sign-in-alt"></i> Sign In to Portal
                    </button>
                </form>

                <div class="auth-footer">
                    Don't have an account? <a href="register.php">Apply Now →</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function setRole(role, element) {
            document.getElementById('selectedRole').value = role;
            document.querySelectorAll('.role-tab').forEach(t => t.classList.remove('active'));
            element.classList.add('active');
        }

        function togglePasswordVisibility() {
            const pwd = document.getElementById('password');
            const icon = document.querySelector('.toggle-password');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

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