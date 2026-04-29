<?php
$site = $db->query("SELECT company_name, company_logo, company_contact, landing_hero_title, landing_hero_text, landing_about_us FROM settings WHERE id=1")->fetch();

$packages = [];
$partner_logos = [];

try {
    $packages = $db->query("SELECT * FROM landing_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    $partner_logos = $db->query("SELECT image_path FROM landing_logos ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (Exception $e) {
    // Silently fail to keep the page running; admin must configure tables
}

$comp_name = $site['company_name'] ?: 'PT Einva Inti Data';
$wa_contact = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $site['company_contact'] ?? ''));
if(empty($wa_contact)) $wa_contact = '6281234567890'; // fallback
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($comp_name) ?> - ISP & IT Solutions</title>
    <link rel="stylesheet" href="public/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script>(function(){const t=localStorage.getItem('billing_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})()</script>
    <style>
        /* Landing Page Specific Styles */
        html, body {
            overflow-x: hidden;
            position: relative;
            width: 100%;
        }
        body {
            scroll-behavior: smooth;
        }
        
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 50px;
            background: var(--glass-bg);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            z-index: 1000;
            border-bottom: 1px solid var(--glass-border);
            transition: all 0.3s ease;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(to right, var(--gradient-text-from), var(--gradient-text-to));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
        }

        .nav-menu {
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .nav-menu a {
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 15px;
            transition: color 0.3s;
        }

        .nav-menu a:hover {
            color: var(--primary);
        }

        .hero-section {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 100px 20px 50px;
            position: relative;
        }

        /* Abstract glowing blobs for hero section */
        .glow-blob {
            position: absolute;
            width: 400px;
            height: 400px;
            background: var(--primary);
            filter: blur(100px);
            border-radius: 50%;
            opacity: 0.15;
            z-index: -1;
            animation: float 10s infinite alternate ease-in-out;
        }

        .glow-blob.blue { top: 20%; left: 10%; background: #3b82f6; }
        .glow-blob.purple { bottom: 10%; right: 10%; background: #a855f7; animation-delay: -5s; }

        @keyframes float {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(50px, 50px) scale(1.2); }
        }

        .hero-title {
            font-size: 56px;
            font-weight: 800;
            margin-bottom: 20px;
            line-height: 1.1;
            background: linear-gradient(135deg, var(--text-primary) 0%, var(--gradient-text-to) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-subtitle {
            font-size: 20px;
            color: var(--text-secondary);
            max-width: 700px;
            margin: 0 auto 40px;
            line-height: 1.6;
        }

        .section {
            padding: 80px 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-title {
            font-size: 36px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 50px;
            position: relative;
        }

        .section-title::after {
            content: '';
            display: block;
            width: 60px;
            height: 4px;
            background: linear-gradient(to right, var(--primary), #a78bfa);
            margin: 15px auto 0;
            border-radius: 4px;
        }

        /* Packages Grid */
        .pkg-grid {
            display: flex;
            gap: 30px;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            padding: 10px 10px 30px;
            margin: 0 -10px;
        }

        .pkg-grid::-webkit-scrollbar {
            height: 6px;
        }
        .pkg-grid::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.03);
            border-radius: 10px;
        }
        .pkg-grid::-webkit-scrollbar-thumb {
            background: rgba(96, 165, 250, 0.3);
            border-radius: 10px;
        }

        .pkg-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
            min-width: 300px;
            flex: 1 0 300px;
            scroll-snap-align: start;
        }

        .pkg-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(to right, var(--gradient-text-from), var(--gradient-text-to));
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .pkg-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            border-color: rgba(96, 165, 250, 0.3);
        }

        .pkg-card:hover::before { opacity: 1; }

        .pkg-name {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 15px;
        }

        .pkg-speed {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 20px;
            color: var(--text-primary);
        }

        .pkg-price {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 30px;
        }

        .pkg-features {
            list-style: none;
            padding: 0;
            margin: 0 0 30px 0;
            text-align: left;
        }

        .pkg-features li {
            padding: 12px 0;
            border-bottom: 1px solid var(--glass-border);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
        }
        
        .pkg-features li::before {
            content: '\f058';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: var(--success);
            margin-right: 12px;
            font-size: 16px;
        }

        .about-text {
            font-size: 18px;
            line-height: 1.8;
            color: var(--text-secondary);
            text-align: center;
            max-width: 900px;
            margin: 0 auto;
        }

        footer {
            background: var(--glass-bg);
            padding: 60px 20px;
            text-align: center;
            border-top: 1px solid var(--glass-border);
        }

        /* Powered By Section */
        .powered-section {
            padding: 60px 0;
            text-align: center;
            overflow: hidden;
            position: relative;
        }

        .powered-label {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 4px;
            color: var(--text-secondary);
            margin-bottom: 40px;
            font-weight: 700;
        }

        .marquee-container {
            width: 100%;
            overflow: hidden;
            position: relative;
            padding: 20px 0;
        }

        .marquee-track {
            display: flex;
            gap: 40px;
            width: max-content;
            animation: marquee 30s linear infinite;
        }

        @keyframes marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .powered-item {
            flex: 0 0 auto;
            padding: 25px 35px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            transition: all 0.4s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 220px;
        }

        .powered-item img {
            max-height: 65px;
            max-width: 180px;
            filter: grayscale(100%) brightness(1) contrast(0.5);
            opacity: 0.4;
            transition: all 0.4s ease;
        }

        .marquee-container:hover .marquee-track {
            animation-play-state: paused;
        }

        .powered-item:hover {
            transform: translateY(-8px) scale(1.05);
            background: rgba(255,255,255,0.08);
            border-color: var(--primary);
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
        }

        .powered-item:hover img {
            filter: grayscale(0%) brightness(1.2) contrast(1);
            opacity: 1;
        }

        /* Feature Cards */
        .feature-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 40px 30px;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .feature-card:hover {
            transform: translateY(-12px);
            background: rgba(255,255,255,0.03);
            border-color: var(--primary);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .feature-icon-wrapper {
            width: 70px;
            height: 70px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 5px;
            transition: transform 0.4s ease;
        }

        .feature-card:hover .feature-icon-wrapper {
            transform: scale(1.1) rotate(5deg);
        }

        .icon-blue { background: rgba(59, 130, 246, 0.1); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.2); }
        .icon-green { background: rgba(35, 206, 217, 0.1); color: #23CED9; border: 1px solid rgba(35, 206, 217, 0.2); }
        .icon-purple { background: rgba(167, 139, 250, 0.1); color: #a78bfa; border: 1px solid rgba(167, 139, 250, 0.2); }

        .feature-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }

        .feature-desc {
            font-size: 15px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin: 0;
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 24px;
            cursor: pointer;
            z-index: 1100;
        }

        .mobile-menu {
            position: fixed;
            top: 0;
            right: -100%;
            width: 80%;
            height: 100vh;
            background: var(--bg-color);
            z-index: 1050;
            padding: 100px 40px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            transition: right 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: -10px 0 30px rgba(0,0,0,0.5);
            border-left: 1px solid var(--glass-border);
        }

        .mobile-menu.active {
            right: 0;
        }

        .mobile-menu a {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            text-decoration: none;
            padding: 10px 0;
            border-bottom: 1px solid var(--glass-border);
        }

        .mobile-menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
            z-index: 1040;
            display: none;
        }

        @media (max-width: 768px) {
            .navbar { padding: 15px 20px; }
            .nav-menu { display: none; }
            .mobile-menu-toggle { display: block; }
            .hero-title { font-size: 36px; }
            .hero-subtitle { font-size: 16px; }
            .powered-grid { gap: 25px; }
            .powered-item img { max-height: 35px; }
            
            .pkg-grid {
                flex-direction: column;
                overflow-x: visible;
                padding: 0;
                margin: 0;
            }
            .pkg-card {
                min-width: 100%;
                flex: none;
            }
        }
    </style>
</head>
<body>

    <!-- Nav -->
    <nav class="navbar">
        <a href="#" class="nav-brand" style="display:flex; align-items:center;">
            <?php if(!empty($site['company_logo'])): ?>
                <div class="brand-logo-wrapper navbar-logo-box">
                    <img src="<?= htmlspecialchars($site['company_logo']) ?>" alt="Logo">
                </div>
            <?php else: ?>
                <i class="fas fa-wifi" style="color:var(--primary);"></i>
            <?php endif; ?>
            <span><?= htmlspecialchars(strtoupper($comp_name)) ?></span>
        </a>
        <div class="nav-menu">
            <a href="#about">Tentang Kami</a>
            <a href="http://fibernodeinternet.com:3001/status/server" target="_blank"><i class="fas fa-server"></i> Server Status</a>
            <a href="http://fibernodeinternet.com:3004" target="_blank"><i class="fas fa-tachometer-alt"></i> Speedtest</a>
            <a href="#services">Layanan</a>
            <a href="https://wa.me/<?= $wa_contact ?>" target="_blank"><i class="fab fa-whatsapp" style="color:#25D366; font-size:18px;"></i> Hubungi Kami</a>
            <button class="theme-toggle" onclick="toggleTheme()" title="Ganti Tema">
                <i class="fas fa-sun theme-icon-dark"></i>
                <i class="fas fa-moon theme-icon-light"></i>
            </button>
            <a href="index.php?page=customer_portal" style="color:var(--success); font-weight:600;"><i class="fas fa-receipt"></i> Cek Tagihan</a>
            <a href="index.php?page=login&role=partner" class="btn btn-sm btn-ghost" style="padding: 10px 15px; border-radius:30px; border:1px solid var(--primary);"><i class="fas fa-handshake"></i> Portal Partner</a>
            <a href="index.php?page=login&role=staff" class="btn btn-sm btn-primary" style="padding: 10px 20px; border-radius:30px;"><i class="fas fa-shield-alt"></i> Area Staff</a>
        </div>
        <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
            <i class="fas fa-bars"></i>
        </button>
    </nav>

    <div class="mobile-menu-overlay" onclick="toggleMobileMenu()"></div>
    <div class="mobile-menu" id="mobileMenu">
        <a href="#about" onclick="toggleMobileMenu()">Tentang Kami</a>
        <a href="http://fibernodeinternet.com:3001/status/server" target="_blank" onclick="toggleMobileMenu()"><i class="fas fa-server"></i> Server Status</a>
        <a href="http://fibernodeinternet.com:3004" target="_blank" onclick="toggleMobileMenu()"><i class="fas fa-tachometer-alt"></i> Speedtest</a>
        <a href="#services" onclick="toggleMobileMenu()">Layanan</a>
        <a href="index.php?page=customer_portal" onclick="toggleMobileMenu()" style="color:var(--success);"><i class="fas fa-receipt"></i> Cek Tagihan</a>
        <a href="index.php?page=login&role=partner" onclick="toggleMobileMenu()" style="color:var(--primary);"><i class="fas fa-handshake"></i> Portal Partner</a>
        <a href="index.php?page=login&role=staff" onclick="toggleMobileMenu()" style="color:var(--danger);"><i class="fas fa-shield-alt"></i> Area Staff</a>
        <a href="https://wa.me/<?= $wa_contact ?>" target="_blank"><i class="fab fa-whatsapp" style="color:#25D366;"></i> Hubungi Kami</a>
        <div style="margin-top:20px;">
            <button class="btn btn-ghost" onclick="toggleTheme(); toggleMobileMenu();" style="width:100%; justify-content:center; gap:10px;">
                <i class="fas fa-moon"></i> Ganti Tema
            </button>
        </div>
    </div>

    <!-- Hero -->
    <section class="hero-section">
        <div style="z-index: 2; position:relative; animation: fadeIn 1s ease-out;">
            <div style="display:inline-block; padding:8px 20px; background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.3); border-radius:50px; color:#60a5fa; font-weight:600; font-size:14px; margin-bottom:25px;">
                <i class="fas fa-bolt"></i> Internet Cepat Tanpa Batas
            </div>
            <h1 class="hero-title"><?= htmlspecialchars($site['landing_hero_title'] ?? 'Koneksi Super Cepat & Stabil') ?></h1>
            <p class="hero-subtitle"><?= htmlspecialchars($site['landing_hero_text'] ?? 'Solusi internet dan IT untuk kebutuhan personal dan korporasi.') ?></p>
            <div style="display:flex; flex-direction:column; align-items:center; gap:25px;">
                <a href="#services" class="btn btn-primary" style="padding:18px 60px; font-size:20px; border-radius:50px; width:100%; max-width:400px; box-shadow: 0 15px 30px rgba(59, 130, 246, 0.3);">Lihat Paket <i class="fas fa-arrow-right" style="margin-left:10px;"></i></a>
            </div>
        </div>
    </section>

    <!-- Powered By (Auto-Scrolling Marquee) -->
    <?php if(count($partner_logos) > 0): ?>
    <section class="powered-section">
        <div class="powered-label">Didukung Oleh</div>
        <div class="marquee-container">
            <div class="marquee-track">
                <?php 
                // Render logos twice for seamless infinite loop
                for($i=0; $i<2; $i++):
                    foreach($partner_logos as $p): ?>
                    <div class="powered-item">
                        <img src="<?= htmlspecialchars($p['image_path']) ?>" alt="Partner Logo">
                    </div>
                <?php endforeach; endfor; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- About / Features -->
    <section id="about" class="section">
        <div style="text-align: center; margin-bottom: 60px;">
            <div style="display:inline-block; padding:6px 15px; background:rgba(35, 206, 217, 0.1); border:1px solid rgba(35, 206, 217, 0.2); border-radius:50px; color:#23CED9; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:2px; margin-bottom:20px;">
                Eksplorasi Keunggulan
            </div>
            <h2 class="section-title" style="margin-bottom:20px;">Kenapa Memilih Kami?</h2>
            <p class="about-text" style="opacity:0.8;">
                <?= nl2br(htmlspecialchars($site['landing_about_us'] ?? 'PT Einva Inti Data hadir untuk memberikan layanan yang andal.')) ?>
            </p>
        </div>
        
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:30px;">
            <div class="feature-card">
                <div class="feature-icon-wrapper icon-blue">
                    <i class="fas fa-tachometer-alt"></i>
                </div>
                <h4 class="feature-title">Koneksi Stabil</h4>
                <p class="feature-desc">99% Uptime dengan perangkat jaringan mutakhir serta monitoring real-time untuk memastikan bisnis Anda tetap berjalan lancar.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon-wrapper icon-green">
                    <i class="fas fa-headset"></i>
                </div>
                <h4 class="feature-title">Bantuan 24/7</h4>
                <p class="feature-desc">Tim teknis kami yang berpengalaman siap sedia menangani setiap keluhan dan kebutuhan teknis Anda kapanpun dibutuhkan.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon-wrapper icon-purple">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h4 class="feature-title">Aman & Terenkripsi</h4>
                <p class="feature-desc">Perlindungan berlapis pada seluruh infrastruktur kami untuk menjamin keamanan data dan privasi setiap pelanggan kami.</p>
            </div>
        </div>
    </section>

    <!-- Services / Packages -->
    <section id="services" class="section">
        <h2 class="section-title">Pilih Kecepatanmu</h2>
        <div class="pkg-grid">
            <?php foreach($packages as $pkg): 
                $feats = array_map('trim', explode(',', $pkg['features']));
            ?>
            <div class="pkg-card">
                <div class="pkg-name"><?= htmlspecialchars($pkg['name']) ?></div>
                <div class="pkg-speed"><?= htmlspecialchars($pkg['speed']) ?></div>
                <div class="pkg-price"><?= $pkg['price'] > 0 ? 'Rp ' . number_format($pkg['price'], 0, ',', '.') . '<span style="font-size:14px; font-weight:normal; color:#94a3b8;">/bln</span>' : 'Hubungi Kami' ?></div>
                
                <ul class="pkg-features">
                    <?php foreach($feats as $f): if(empty($f)) continue; ?>
                        <li><?= htmlspecialchars($f) ?></li>
                    <?php endforeach; ?>
                </ul>
                
                <a href="https://wa.me/<?= $wa_contact ?>?text=Halo%20Admin%20<?= urlencode($comp_name) ?>,%20saya%20tertarik%20berlangganan%20solusi%20<?= urlencode($pkg['name']) ?>" target="_blank" class="btn btn-primary" style="width:100%; border-radius:50px; margin-top:20px;">Pesan Sekarang</a>
            </div>
            <?php endforeach; ?>
            
            <?php if(count($packages) == 0): ?>
                <div style="grid-column: 1/-1; text-align:center; padding: 40px; color:var(--text-secondary);">
                    Etalase produk sedang disusun oleh administrator.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <footer>
        <div style="font-size:24px; font-weight:700; background: linear-gradient(to right, var(--gradient-text-from), var(--gradient-text-to)); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; margin-bottom:15px;">
            <?= htmlspecialchars(strtoupper($comp_name)) ?>
        </div>
        <p style="color:var(--text-secondary); max-width:500px; margin: 0 auto 30px;">
            Inovasi Digital untuk Masa Depan yang Lebih Baik. Connect. Empower. Evolve.
        </p>
        <div style="display:flex; justify-content:center; gap:20px; margin-bottom:30px;">
            <a href="#" style="color:var(--text-secondary); font-size:20px; transition:color 0.3s;"><i class="fab fa-facebook"></i></a>
            <a href="#" style="color:var(--text-secondary); font-size:20px; transition:color 0.3s;"><i class="fab fa-instagram"></i></a>
            <a href="https://wa.me/<?= $wa_contact ?>" style="color:var(--text-secondary); font-size:20px; transition:color 0.3s;"><i class="fab fa-whatsapp"></i></a>
        </div>
        <div style="margin-bottom:20px; font-size:16px; font-weight:700;">
            Kontak Penjualan: <a href="https://wa.me/6282346268845?text=Halo,%20saya%20ingin%20memesan%20lisensi" target="_blank" style="color:var(--primary);">0823-4626-8845</a>
        </div>
        <div style="border-top:1px solid rgba(255,255,255,0.05); padding-top:20px; color:var(--text-secondary); font-size:14px;">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($comp_name) ?>. Hak Cipta Dilindungi Undang-Undang.<br>
            Aplikasi Billing & Profil Web Internal Terintegrasi.
        </div>
    </footer>

    <script>
    function toggleTheme() {
        const html = document.documentElement;
        const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('billing_theme', next);
    }

    function toggleMobileMenu() {
        const menu = document.getElementById('mobileMenu');
        const overlay = document.querySelector('.mobile-menu-overlay');
        const icon = document.querySelector('.mobile-menu-toggle i');
        
        menu.classList.toggle('active');
        if (menu.classList.contains('active')) {
            overlay.style.display = 'block';
            icon.classList.replace('fa-bars', 'fa-times');
            document.body.style.overflow = 'hidden';
        } else {
            overlay.style.display = 'none';
            icon.classList.replace('fa-times', 'fa-bars');
            document.body.style.overflow = 'auto';
        }
    }
    </script>
</body>
</html>
