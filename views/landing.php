<?php
$site = $db->query("SELECT company_name, company_tagline, company_logo, company_contact, company_address, landing_hero_title, landing_hero_text, landing_about_us FROM settings WHERE id=1")->fetch();

$packages = [];
$partner_logos = [];
try {
    $packages = $db->query("SELECT * FROM landing_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    $partner_logos = $db->query("SELECT image_path FROM landing_logos ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (Exception $e) {
    // Keep the page up even if optional tables are missing.
}

$comp_name = trim((string) ($site['company_name'] ?? '')) ?: 'PT Einva Inti Data';
$brand     = trim((string) ($site['landing_hero_title'] ?? '')) ?: $comp_name;
$hero_text = trim((string) ($site['landing_hero_text'] ?? '')) ?: 'Layanan internet fiber untuk rumah, usaha, dan kantor.';
$address   = trim((string) ($site['company_address'] ?? ''));
$logo      = trim((string) ($site['company_logo'] ?? ''));
$phone_raw = preg_replace('/[^0-9]/', '', (string) ($site['company_contact'] ?? ''));
$wa_contact = preg_replace('/^0/', '62', $phone_raw) ?: '6281234567890';
$phone_display = $phone_raw ? preg_replace('/(\d{4})(\d{4})(\d+)/', '$1-$2-$3', $phone_raw) : '';

// Split the "about" text into intro, vision and mission when the admin wrote it that way.
$about_raw = trim((string) ($site['landing_about_us'] ?? ''));
$about_intro = $about_raw;
$vision = '';
$missions = [];
if (preg_match('/^(.*?)\n\s*Visi\s*\n(.*?)\n\s*Misi\s*\n(.*)$/si', $about_raw, $m)) {
    $about_intro = trim($m[1]);
    $vision = trim($m[2]);
    $missions = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $m[3]))));
}

$wa_link = 'https://wa.me/' . $wa_contact . '?text=' . rawurlencode("Halo $brand, saya ingin bertanya tentang pemasangan internet.");

function svg_icon(string $name, string $class = 'h-5 w-5'): string {
    $paths = [
        'wifi'    => '<path d="M12 20h.01"/><path d="M8.5 16.4a5 5 0 0 1 7 0"/><path d="M5 12.9a10 10 0 0 1 14 0"/><path d="M2 8.8a15 15 0 0 1 20 0"/>',
        'check'   => '<path d="M20 6 9 17l-5-5"/>',
        'chat'    => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'pin'     => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'shield'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'users'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'clock'   => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'router'  => '<rect width="20" height="8" x="2" y="14" rx="2"/><path d="M6.01 18H6"/><path d="M10.01 18H10"/><path d="M15 10v4"/><path d="M17.84 7.17a4 4 0 0 0-5.66 0"/><path d="M20.66 4.34a8 8 0 0 0-11.31 0"/>',
        'phone'   => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'menu'    => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'x'       => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'building'=> '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
        'activity'=> '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
    ];
    $d = $paths[$name] ?? '';
    return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($brand) ?> — Internet fiber untuk rumah dan usaha</title>
    <meta name="description" content="<?= htmlspecialchars(mb_substr($hero_text, 0, 155)) ?>">
    <?php if ($logo): ?><link rel="icon" href="<?= htmlspecialchars($logo) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/tw-landing.css">
</head>
<body>

<!-- Navigation -->
<header class="sticky top-0 z-40 border-b border-white/10 bg-hero/95 text-hero-ink backdrop-blur">
    <div class="container flex h-16 items-center justify-between gap-6">
        <a href="index.php?page=landing" class="flex items-center gap-3 min-w-0">
            <?php if ($logo): ?>
                <img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($brand) ?>" class="h-9 w-auto max-w-[140px] object-contain">
            <?php else: ?>
                <span class="grid h-9 w-9 place-items-center rounded-md bg-primary text-primary-foreground"><?= svg_icon('wifi', 'h-5 w-5') ?></span>
            <?php endif; ?>
            <span class="<?= $logo ? 'sr-only' : 'truncate font-bold text-[17px]' ?>"><?= htmlspecialchars($brand) ?></span>
        </a>
        <nav class="hidden md:flex items-center gap-7">
            <a href="#paket" class="hero-nav-link">Paket</a>
            <a href="#cara" class="hero-nav-link">Cara berlangganan</a>
            <a href="#tentang" class="hero-nav-link">Tentang kami</a>
            <a href="#tagihan" class="hero-nav-link">Cek tagihan</a>
        </nav>
        <div class="hidden md:flex items-center gap-2">
            <a href="index.php?page=login" class="btn btn-hero">Masuk</a>
            <a href="<?= htmlspecialchars($wa_link) ?>" target="_blank" rel="noopener" class="btn btn-wa"><?= svg_icon('chat', 'h-4 w-4') ?> WhatsApp</a>
        </div>
        <button class="md:hidden btn btn-hero h-10 w-10 px-0" onclick="toggleMobileMenu()" aria-label="Buka menu" aria-expanded="false" id="menuBtn">
            <span id="menuIconOpen"><?= svg_icon('menu', 'h-5 w-5') ?></span>
            <span id="menuIconClose" class="hidden"><?= svg_icon('x', 'h-5 w-5') ?></span>
        </button>
    </div>
    <div id="mobileMenu" class="hidden md:hidden border-t border-white/10 bg-hero">
        <nav class="container flex flex-col py-3">
            <a href="#paket" class="py-3 text-[15px] font-medium border-b border-white/10 text-hero-ink" onclick="toggleMobileMenu()">Paket</a>
            <a href="#cara" class="py-3 text-[15px] font-medium border-b border-white/10 text-hero-ink" onclick="toggleMobileMenu()">Cara berlangganan</a>
            <a href="#tentang" class="py-3 text-[15px] font-medium border-b border-white/10 text-hero-ink" onclick="toggleMobileMenu()">Tentang kami</a>
            <a href="#tagihan" class="py-3 text-[15px] font-medium border-b border-white/10 text-hero-ink" onclick="toggleMobileMenu()">Cek tagihan</a>
            <div class="flex gap-2 pt-4">
                <a href="index.php?page=login" class="btn btn-hero flex-1">Masuk</a>
                <a href="<?= htmlspecialchars($wa_link) ?>" target="_blank" rel="noopener" class="btn btn-wa flex-1"><?= svg_icon('chat', 'h-4 w-4') ?> WhatsApp</a>
            </div>
        </nav>
    </div>
</header>

<main>

<!-- Hero: the dark band is the network side of the story; the light page below is
     the customer side. The cable runs along the seam between them. -->
<section class="hero-band">
    <div class="container grid gap-12 pb-4 pt-14 md:pt-20 lg:grid-cols-[1.1fr_0.9fr] lg:items-center">
        <div class="max-w-[38rem]">
            <h1 class="font-display text-[2.4rem] font-semibold leading-[1.05] tracking-[-0.022em] text-hero-ink sm:text-5xl lg:text-[3.4rem]">
                Koneksi yang dipasang rapi, dijaga tiap hari, dan tagihannya jelas.
            </h1>
            <p class="mt-6 max-w-[34rem] text-lg leading-relaxed text-hero-mute">
                <?= htmlspecialchars($hero_text) ?>
            </p>
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <a href="<?= htmlspecialchars($wa_link) ?>" target="_blank" rel="noopener" class="btn btn-wa btn-lg"><?= svg_icon('chat', 'h-5 w-5') ?> Tanya pemasangan via WhatsApp</a>
                <a href="#paket" class="btn btn-lg btn-hero">Lihat paket dan harga</a>
            </div>
            <?php if ($phone_display): ?>
            <p class="mt-5 text-sm text-hero-mute">Atau telepon langsung <a href="tel:+<?= htmlspecialchars($wa_contact) ?>" class="font-semibold text-hero-ink underline-offset-4 hover:underline"><?= htmlspecialchars($phone_display) ?></a>.</p>
            <?php endif; ?>
        </div>

        <!-- Installation slip: the one memorable object on the page -->
        <div class="hero-card p-6 sm:p-7 lg:ml-auto lg:max-w-[26rem] w-full">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm text-hero-mute">Pemasangan baru</p>
                    <h2 class="mt-1 font-display text-xl font-semibold text-hero-ink">Yang Anda dapat</h2>
                </div>
                <span class="badge border-transparent bg-signal/15 text-signal"><?= svg_icon('check', 'h-3.5 w-3.5 mr-1') ?> Tanpa biaya survei</span>
            </div>
            <ul class="mt-6 divide-y divide-white/10">
                <li class="flex items-center gap-4 py-3.5">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-white/[.06] text-hero-mute"><?= svg_icon('router') ?></span>
                    <div class="min-w-0"><p class="font-semibold text-hero-ink">Perangkat ONT dan router WiFi</p><p class="text-sm text-hero-mute">Dipasang teknisi kami, siap pakai hari itu juga.</p></div>
                </li>
                <li class="flex items-center gap-4 py-3.5">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-white/[.06] text-hero-mute"><?= svg_icon('activity') ?></span>
                    <div class="min-w-0"><p class="font-semibold text-hero-ink">Jalur fiber sampai ke rumah</p><p class="text-sm text-hero-mute">Bukan wireless. Stabil saat hujan dan jam sibuk.</p></div>
                </li>
                <li class="flex items-center gap-4 py-3.5">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-white/[.06] text-hero-mute"><?= svg_icon('receipt') ?></span>
                    <div class="min-w-0"><p class="font-semibold text-hero-ink">Tagihan tetap tiap bulan</p><p class="text-sm text-hero-mute">Bisa dicek online dengan kode pelanggan.</p></div>
                </li>
                <li class="flex items-center gap-4 py-3.5">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-white/[.06] text-hero-mute"><?= svg_icon('phone') ?></span>
                    <div class="min-w-0"><p class="font-semibold text-hero-ink">Dukungan teknis yang bisa dihubungi</p><p class="text-sm text-hero-mute">Laporan gangguan ditangani langsung oleh tim teknis kami.</p></div>
                </li>
            </ul>
            <div class="mt-5 flex items-center justify-between gap-4 rounded-md border border-white/10 px-4 py-3">
                <span class="text-sm text-hero-mute">Mulai dari</span>
                <?php $min_price = $packages ? min(array_map(fn($p) => (int) $p['price'], $packages)) : 0; ?>
                <span class="font-display text-lg font-semibold tabular-nums text-hero-fiber"><?= $min_price > 0 ? 'Rp ' . number_format($min_price, 0, ',', '.') . '<span class="text-sm font-medium text-hero-mute">/bulan</span>' : 'Hubungi kami' ?></span>
            </div>
        </div>
    </div>

    <!-- The delivery chain, in order, with the sag a real aerial cable has. The
         light travels it once when the page loads and then stays lit. -->
    <div class="fiber-route">
        <svg viewBox="0 0 1200 132" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" role="img" aria-label="Jalur layanan: dari OLT di POP, lewat tiang dan ODP, sampai ke rumah pelanggan.">
            <!-- OLT rack -->
            <g class="fr-mark">
                <rect x="40" y="50" width="42" height="40" rx="4"/>
                <path d="M48 60h26M48 70h26M48 80h16"/>
            </g>
            <!-- pole -->
            <path class="fr-mark" d="M380 70v34M370 104h20"/>
            <!-- ODP box -->
            <rect class="fr-mark" x="708" y="58" width="24" height="24" rx="4"/>
            <!-- house -->
            <path class="fr-mark" d="M1074 74l22-18 22 18M1080 72v26h32V72"/>

            <path id="frPath" class="fr-base" d="M88 70 Q 234 84 380 70 Q 550 86 720 70 Q 908 84 1096 70"/>
            <path class="fr-lit" pathLength="100" d="M88 70 Q 234 84 380 70 Q 550 86 720 70 Q 908 84 1096 70"/>
            <circle class="fr-arrive" cx="1096" cy="70" r="4.5"/>

            <g class="fr-label">
                <text x="61" y="122" text-anchor="middle">OLT</text>
                <text x="380" y="122" text-anchor="middle">Tiang</text>
                <text x="720" y="122" text-anchor="middle">ODP</text>
                <text x="1096" y="122" text-anchor="middle">Rumah Anda</text>
            </g>
        </svg>
    </div>
</section>


<!-- Trust facts -->
<section class="border-y border-border bg-card">
    <div class="container grid gap-8 py-10 sm:grid-cols-2 lg:grid-cols-4 lg:gap-0 lg:divide-x lg:divide-border">
        <div class="flex gap-4 lg:px-6 lg:first:pl-0 lg:last:pr-0">
            <span class="text-primary shrink-0"><?= svg_icon('building', 'h-6 w-6') ?></span>
            <div><p class="font-semibold">Badan hukum resmi</p><p class="mt-1 text-sm text-muted-foreground leading-relaxed"><?= htmlspecialchars($comp_name) ?>, penyelenggara jasa jual kembali telekomunikasi.</p></div>
        </div>
        <div class="flex gap-4 lg:px-6 lg:first:pl-0 lg:last:pr-0">
            <span class="text-primary shrink-0"><?= svg_icon('shield', 'h-6 w-6') ?></span>
            <div><p class="font-semibold">Terhubung ke jaringan nasional</p><p class="mt-1 text-sm text-muted-foreground leading-relaxed">Bandwidth dari penyedia tulang punggung, bukan berbagi dari koneksi rumahan.</p></div>
        </div>
        <div class="flex gap-4 lg:px-6 lg:first:pl-0 lg:last:pr-0">
            <span class="text-primary shrink-0"><?= svg_icon('users', 'h-6 w-6') ?></span>
            <div><p class="font-semibold">Rumah hingga korporasi</p><p class="mt-1 text-sm text-muted-foreground leading-relaxed">Melayani rumah tangga, UMKM, sekolah, dan perkantoran.</p></div>
        </div>
        <div class="flex gap-4 lg:px-6 lg:first:pl-0 lg:last:pr-0">
            <span class="text-primary shrink-0"><?= svg_icon('clock', 'h-6 w-6') ?></span>
            <div><p class="font-semibold">Dipantau setiap hari</p><p class="mt-1 text-sm text-muted-foreground leading-relaxed">Perangkat jaringan dan trafik pelanggan diawasi dari pusat kendali kami.</p></div>
        </div>
    </div>
</section>

<!-- Packages -->
<section id="paket" class="container py-16 md:py-20">
    <div class="max-w-[36rem]">
        <h2 class="text-3xl font-semibold sm:text-4xl">Satu harga, tanpa biaya tersembunyi.</h2>
        <p class="mt-4 leading-relaxed text-muted-foreground">Harga di bawah sudah termasuk pajak. Tidak ada kuota, tidak ada pembatasan jam. Biaya pemasangan dibicarakan saat survei, tergantung jarak ke tiang terdekat.</p>
    </div>

    <?php if (count($packages) > 0): ?>
    <?php
        // The bar compares the plans to each other, so it needs the fastest one.
        $speed_of = function ($p) { preg_match('/(\d+)/', (string) $p['speed'], $m); return isset($m[1]) ? (int) $m[1] : 0; };
        $speed_max = max(array_map($speed_of, $packages)) ?: 1;
    ?>
    <div class="mt-10 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($packages as $i => $pkg):
            $feats = array_values(array_filter(array_map('trim', explode(',', (string) $pkg['features']))));
            $pkg_wa = 'https://wa.me/' . $wa_contact . '?text=' . rawurlencode("Halo $brand, saya tertarik paket {$pkg['name']} ({$pkg['speed']}). Alamat saya: ");
            $mbps = $speed_of($pkg);
        ?>
        <article class="card flex flex-col p-6">
            <h3 class="text-[15px] font-semibold text-muted-foreground"><?= htmlspecialchars($pkg['name']) ?></h3>
            <p class="mt-3 flex items-baseline gap-1.5">
                <span class="pkg-speed"><?= $mbps ?: htmlspecialchars($pkg['speed']) ?></span>
                <?php if ($mbps): ?><span class="text-sm font-medium text-muted-foreground">Mbps</span><?php endif; ?>
            </p>
            <div class="pkg-meter"><span style="width: <?= max(8, round($mbps / $speed_max * 100)) ?>%"></span></div>
            <p class="mt-5 text-2xl font-bold tabular-nums">
                <?php if ((int) $pkg['price'] > 0): ?>
                    Rp <?= number_format((int) $pkg['price'], 0, ',', '.') ?><span class="text-base font-medium text-muted-foreground">/bulan</span>
                <?php else: ?>
                    <span class="text-xl">Hubungi kami</span>
                <?php endif; ?>
            </p>
            <ul class="mt-5 mb-2">
                <?php foreach ($feats as $f): ?>
                <li class="pkg-row"><span class="text-signal"><?= svg_icon('check', 'h-4 w-4') ?></span><span><?= htmlspecialchars($f) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <a href="<?= htmlspecialchars($pkg_wa) ?>" target="_blank" rel="noopener" class="btn <?= $i === 1 ? 'btn-primary' : 'btn-outline' ?> mt-auto w-full" style="margin-top:1.5rem">Pasang <?= htmlspecialchars($pkg['name']) ?></a>
        </article>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card mt-10 p-10 text-center text-muted-foreground">Daftar paket sedang disiapkan. Hubungi kami lewat WhatsApp untuk harga terbaru.</div>
    <?php endif; ?>

    <p class="mt-6 text-sm text-muted-foreground">Butuh kecepatan lebih tinggi untuk kantor, sekolah, atau kebutuhan lingkungan? <a href="<?= htmlspecialchars($wa_link) ?>" target="_blank" rel="noopener" class="font-semibold text-foreground underline underline-offset-4">Minta penawaran khusus.</a></p>
</section>

<!-- How to subscribe: a real sequence, so the steps sit on the cable in order -->
<section id="cara" class="net-band">
    <div class="container py-16 md:py-20">
        <div class="max-w-[36rem]">
            <h2 class="text-3xl font-semibold sm:text-4xl">Dari tanya sampai online, biasanya dalam beberapa hari.</h2>
        </div>
        <ol class="step-track mt-12 grid gap-10 md:grid-cols-2 lg:grid-cols-4 lg:gap-8">
            <li>
                <span class="step-node">1</span>
                <h3 class="mt-4 font-semibold text-hero-ink">Kirim alamat lewat WhatsApp</h3>
                <p class="mt-2 text-sm leading-relaxed text-hero-mute">Kami cek apakah jalur fiber sudah lewat depan rumah Anda dan beri tahu perkiraan waktunya.</p>
            </li>
            <li>
                <span class="step-node">2</span>
                <h3 class="mt-4 font-semibold text-hero-ink">Survei lokasi</h3>
                <p class="mt-2 text-sm leading-relaxed text-hero-mute">Teknisi datang mengukur jarak ke tiang dan titik terbaik untuk router. Gratis.</p>
            </li>
            <li>
                <span class="step-node">3</span>
                <h3 class="mt-4 font-semibold text-hero-ink">Pemasangan</h3>
                <p class="mt-2 text-sm leading-relaxed text-hero-mute">Kabel ditarik rapi, ONT dan router dipasang, kecepatan diuji di depan Anda.</p>
            </li>
            <li>
                <span class="step-node">4</span>
                <h3 class="mt-4 font-semibold text-hero-ink">Aktif dan tagihan bulanan</h3>
                <p class="mt-2 text-sm leading-relaxed text-hero-mute">Anda mendapat kode pelanggan untuk cek tagihan. Pembayaran lewat transfer bank atau tunai kepada petugas resmi kami.</p>
            </li>
        </ol>
    </div>
</section>

<!-- About -->
<section id="tentang" class="container grid gap-12 py-16 md:py-20 lg:grid-cols-[1fr_1fr]">
    <div>        <h2 class="mt-3 text-3xl font-bold sm:text-4xl"><?= htmlspecialchars($comp_name) ?></h2>
        <p class="mt-5 text-muted-foreground leading-relaxed"><?= nl2br(htmlspecialchars($about_intro)) ?></p>
        <?php if ($address): ?>
        <p class="mt-6 flex items-start gap-2.5 text-[15px]"><span class="mt-0.5 text-primary"><?= svg_icon('pin', 'h-5 w-5') ?></span><span><?= htmlspecialchars($address) ?></span></p>
        <?php endif; ?>
    </div>
    <div class="grid gap-5 content-start">
        <?php if ($vision): ?>
        <div class="card p-6">
            <h3 class="font-bold">Visi</h3>
            <p class="mt-2 text-muted-foreground leading-relaxed"><?= htmlspecialchars($vision) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($missions): ?>
        <div class="card p-6">
            <h3 class="font-bold">Misi</h3>
            <ul class="mt-3 space-y-2.5">
                <?php foreach ($missions as $ms): ?>
                <li class="flex items-start gap-2.5 text-[15px]"><span class="mt-0.5 text-signal"><?= svg_icon('check', 'h-4 w-4') ?></span><span><?= htmlspecialchars($ms) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Network partners -->
<?php if (count($partner_logos) > 0): ?>
<section class="border-y border-border bg-card">
    <div class="container py-12">
        <p class="text-center text-sm font-medium text-muted-foreground">Jaringan, asosiasi, dan penyedia yang menopang layanan kami</p>
        <!-- Marquee: the track is rendered twice and slides by half its width for a seamless loop -->
        <div class="marquee mt-8" aria-label="Logo mitra jaringan">
            <div class="marquee-track">
                <?php for ($i = 0; $i < 2; $i++): foreach ($partner_logos as $p): ?>
                <div class="mr-4 flex h-16 w-44 shrink-0 items-center justify-center rounded-md border border-border bg-white px-5" <?= $i ? 'aria-hidden="true"' : '' ?>>
                    <img src="<?= htmlspecialchars($p['image_path']) ?>" alt="<?= $i ? '' : 'Logo mitra jaringan' ?>" class="max-h-10 w-auto max-w-full object-contain" loading="lazy">
                </div>
                <?php endforeach; endfor; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Bill check -->
<section id="tagihan" class="container py-16 md:py-20">
    <div class="card grid gap-8 p-7 sm:p-10 lg:grid-cols-[1fr_auto] lg:items-center">
        <div class="max-w-[34rem]">
            <p class="eyebrow">Sudah jadi pelanggan?</p>
            <h2 class="mt-3 text-2xl font-bold sm:text-3xl">Cek tagihan bulan ini tanpa login.</h2>
            <p class="mt-3 text-muted-foreground leading-relaxed">Masukkan kode pelanggan yang tertera di nota atau pesan WhatsApp dari kami, contohnya <span class="font-semibold text-foreground">CUST-123456</span>.</p>
        </div>
        <form action="index.php" method="get" class="flex w-full flex-col gap-2 sm:flex-row lg:w-[26rem]">
            <input type="hidden" name="page" value="customer_portal">
            <label for="kode" class="sr-only">Kode pelanggan</label>
            <input id="kode" name="code" class="input" placeholder="Kode pelanggan" required autocomplete="off" pattern="[A-Za-z0-9\-]+">
            <button type="submit" class="btn btn-primary h-11 px-5 shrink-0"><?= svg_icon('receipt', 'h-4 w-4') ?> Lihat tagihan</button>
        </form>
    </div>
</section>

</main>

<footer class="footer-band">
    <div class="container grid gap-10 py-14 md:grid-cols-[1.4fr_1fr_1fr]">
        <div class="max-w-[28rem]">
            <div class="flex items-center gap-3">
                <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="h-8 w-auto object-contain"><?php endif; ?>
                <span class="font-display text-lg font-semibold"><?= htmlspecialchars($brand) ?></span>
            </div>
            <p class="mt-3 text-sm leading-relaxed text-hero-mute"><?= htmlspecialchars($comp_name) ?><?= $address ? '. ' . htmlspecialchars($address) : '' ?>.</p>
            <?php if ($phone_display): ?>
            <p class="mt-3 text-sm text-hero-mute">WhatsApp dan telepon: <a href="<?= htmlspecialchars($wa_link) ?>" target="_blank" rel="noopener" class="font-semibold text-hero-ink"><?= htmlspecialchars($phone_display) ?></a></p>
            <?php endif; ?>
            <?php // The two things a subscriber checks when something feels slow. ?>
            <div class="mt-6 flex flex-wrap gap-2">
                <a href="http://fibernodeinternet.com:3004" target="_blank" rel="noopener" class="footer-op"><?= svg_icon('activity', 'h-4 w-4') ?> Tes kecepatan</a>
                <a href="http://fibernodeinternet.com:3001/status/server" target="_blank" rel="noopener" class="footer-op"><?= svg_icon('shield', 'h-4 w-4') ?> Status jaringan</a>
            </div>
        </div>
        <div>
            <p class="font-semibold text-hero-ink">Pelanggan</p>
            <ul class="mt-3 space-y-2 text-sm">
                <li><a href="#tagihan" class="footer-link">Cek tagihan</a></li>
                <li><a href="#paket" class="footer-link">Paket dan harga</a></li>
                <li><a href="#cara" class="footer-link">Cara berlangganan</a></li>
            </ul>
        </div>
        <div>
            <p class="font-semibold text-hero-ink">Perusahaan</p>
            <ul class="mt-3 space-y-2 text-sm">
                <li><a href="#tentang" class="footer-link">Tentang kami</a></li>
                <li><a href="<?= htmlspecialchars($wa_link) ?>" target="_blank" rel="noopener" class="footer-link">Hubungi kami</a></li>
                <li><a href="index.php?page=login" class="footer-link">Masuk aplikasi</a></li>
            </ul>
        </div>
    </div>
    <div class="border-t border-white/10">
        <div class="container flex flex-col gap-2 py-5 text-sm text-hero-mute sm:flex-row sm:items-center sm:justify-between">
            <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($comp_name) ?>. Hak cipta dilindungi.</span>
            <span>Layanan dan penagihan dikelola dengan EinvaBill.</span>
        </div>
    </div>
</footer>

</body>
</html>
