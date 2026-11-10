<?php
date_default_timezone_set('Asia/Makassar');
session_start();
$search = $_GET['q'] ?? '';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Ganti path ini sesuai dengan lokasi folder PHPMailer Anda
require 'vendor/phpmailer/phpmailer/src/Exception.php';
require 'vendor/phpmailer/phpmailer/src/PHPMailer.php';
require 'vendor/phpmailer/phpmailer/src/SMTP.php';


// --- DB CONNECTION ---
$host = 'localhost';
$db = 'politala_shortener';
$user = 'root';
$pass = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    die("Koneksi gagal.");
}
// --- FUNGSI PEMBANTU (HELPER) ---
function getDeviceName($ua)
{
    if (preg_match('/android/i', $ua))
        return 'Android';
    if (preg_match('/iphone/i', $ua))
        return 'iPhone';
    if (preg_match('/ipad/i', $ua))
        return 'iPad';
    if (preg_match('/windows/i', $ua))
        return 'Windows';
    if (preg_match('/macintosh|mac os x/i', $ua))
        return 'Mac OS';
    if (preg_match('/linux/i', $ua))
        return 'Linux';
    return 'Lainnya';
}
function getBrowserName($ua)
{
    if (preg_match('/edg/i', $ua))
        return 'Edge';
    if (preg_match('/opera|opr/i', $ua))
        return 'Opera';
    if (preg_match('/chrome|crios/i', $ua))
        return 'Chrome';
    if (preg_match('/firefox|fxios/i', $ua))
        return 'Firefox';
    if (preg_match('/safari/i', $ua))
        return 'Safari'; // Pastikan ditaruh setelah Chrome
    return 'Lainnya';
}
function getRealIP()
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// URL Config
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . str_replace("index.php", "", explode('?', $_SERVER['REQUEST_URI'], 2)[0]);
$logo = "link-politala.png";

// --- REDIRECT & SPLASH SCREEN ---
if (isset($_GET['go'])) {
    $code = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['go']);
    $stmt = $pdo->prepare("SELECT * FROM links WHERE short_code = ?");
    $stmt->execute([$code]);
    $link = $stmt->fetch();

    if ($link) {
        $is_expired = ($link['expiry_date'] && date('Y-m-d H:i:s') > $link['expiry_date']);
        ?>
        <!DOCTYPE html>
        <html lang="id">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Link Politala</title>

            <style>
                body {
                    margin: 0;
                    background: #f9fafb;
                    font-family: sans-serif;
                    display: block;
                    opacity: 0;
                    transition: opacity 0.2s;
                }

                .ready {
                    opacity: 1;
                }

                nav {
                    background: transparent;
                    padding: 24px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    max-width: 1200px;
                    margin: 0 auto;
                }

                .main-container {
                    max-width: 1200px;
                    margin: 0 auto;
                    padding: 24px;
                }
            </style>

            <script src="https://cdn.tailwindcss.com"></script>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <script>
                // Munculkan body hanya setelah Tailwind siap proses atau DOM load
                document.addEventListener("DOMContentLoaded", function () {
                    document.body.classList.add('ready');
                });
            </script>
        </head>

        <body class="bg-white flex flex-col items-center justify-center h-screen text-center p-6">
            <?php if ($is_expired): ?>
                <img src="logo-politala.png" class="h-32 mb-8 grayscale opacity-30">
                <h2 class="text-xl font-black text-red-600 normal-case italic">Tautan Kadaluwarsa</h2>
            <?php else: ?>
                <?php
                $ip = getRealIP();
                $ua = $_SERVER['HTTP_USER_AGENT'];
                $stmtCheck = $pdo->prepare("SELECT id FROM visit_logs WHERE link_id = ? AND ip_address = ? AND user_agent = ? LIMIT 1");
                $stmtCheck->execute([$link['id'], $ip, $ua]);
                $alreadyVisited = $stmtCheck->fetch();
                if (!$alreadyVisited) {
                    $pdo->prepare("UPDATE links SET views = views + 1 WHERE id = ?")->execute([$link['id']]);
                    $referrer = $_SERVER['HTTP_REFERER'] ?? 'Langsung / Tanpa Referrer';
                    $lokasi = "Lokasi Tidak Diketahui";
                    $lat = "";
                    $lon = "";
                    try {
                        $api_url = "https://ipinfo.io/" . $ip . "/json";
                        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
                        $response = @file_get_contents($api_url, false, $ctx);
                        if ($response) {
                            $data = json_decode($response, true);
                            if (isset($data['city'])) {
                                // Menggabungkan Kota, Provinsi, dan Negara
                                $lokasi = $data['city'] . ", " . ($data['region'] ?? '') . " (" . ($data['country'] ?? '') . ")";

                                // Memecah koordinat (lat,lon)
                                if (isset($data['loc'])) {
                                    $coords = explode(',', $data['loc']);
                                    $lat = $coords[0] ?? '';
                                    $lon = $coords[1] ?? '';
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $lokasi = "Gagal melacak";
                    }

                    $pdo->prepare("INSERT INTO visit_logs (link_id, ip_address, lokasi, lat, lon, user_agent, referrer) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$link['id'], $ip, $lokasi, $lat, $lon, $ua, $referrer]);
                }
                ?>

                <img src="logo-politala.png" class="h-52 md:h-64 mb-10 animate-bounce">

                <div class="w-14 h-14 border-[6px] border-gray-100 border-t-black rounded-full animate-spin mx-auto"></div>

                <div class="mt-8">
                    <p class="text-base md:text-lg tracking-[0.2em] normal-case italic">Mengarahkan...</p>
                </div>

                <script>
                    setTimeout(() => {
                        window.location.href = "<?= filter_var($link['original_url'], FILTER_SANITIZE_URL) ?>";
                    }, 2000);
                </script>
            <?php endif; ?>
        </body>

        </html>
        <?php exit;
    }
}

// --- LOGIKA PROSES ---
$msg = '';
$msgType = '';
$act = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Fitur Registrasi & OTP (Public)
    if ($act === 'register') {
        $u = strtolower(trim($_POST['username']));
        $p = $_POST['password'];

        if (!filter_var($u, FILTER_VALIDATE_EMAIL) || !str_ends_with($u, '@politala.ac.id')) {
            $msg = "Gagal! Harus menggunakan email @politala.ac.id";
            $msgType = "error";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$u]);
            if ($stmt->fetch()) {
                $msg = "Email sudah terdaftar!";
                $msgType = "error";
            } else {
                $otp = rand(100000, 999999);
                $_SESSION['temp_reg'] = [
                    'username' => $u,
                    'password' => password_hash($p, PASSWORD_DEFAULT),
                    'otp' => $otp,
                    'expiry' => time() + (5 * 60)
                ];

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'noreply@politala.ac.id';
                    $mail->Password = 'xmrvmloostievgmw';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->setFrom('noreply@politala.ac.id', 'Link.Politala');
                    $mail->addAddress($u);
                    $mail->isHTML(true);
                    $mail->Subject = 'Kode OTP Pendaftaran Link Politala';
                    $mail->Body = '<div style="font-family: \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; text-align: center; padding: 40px 20px; border: 1px solid #e5e7eb; border-radius: 16px; max-width: 350px; margin: 20px auto; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); background-color: #ffffff;">
                                        <h2 style="color: #1e40af; margin: 0 0 10px 0; font-size: 22px;">Verifikasi Akun</h2>
                                        <p style="color: #6b7280; font-size: 14px; margin-bottom: 25px;">Masukkan kode di bawah ini untuk melanjutkan.</p>
                                        
                                        <div style="background-color: #f3f4f6; padding: 15px; border-radius: 12px; font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #111827; display: inline-block; width: 80%;">
                                            ' . $otp . '
                                        </div>

                                        <p style="color: #9ca3af; font-size: 12px; margin-top: 25px;">
                                            Berlaku selama <span style="color: #ef4444; font-weight: bold;">5 menit</span>.
                                        </p>
                                    </div>';
                    $mail->send();
                    $msg = "OTP telah dikirim ke email Anda!";
                    $msgType = "success";
                    $showOtpForm = true;
                } catch (Exception $e) {
                    $msg = "Gagal kirim email.";
                    $msgType = "error";
                }
            }
        }
    }

    if ($act === 'verify_otp') {
        $otp_input = $_POST['otp_code'];
        $temp = $_SESSION['temp_reg'] ?? null;
        if ($temp && $otp_input == $temp['otp'] && time() <= $temp['expiry']) {
            $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'User')")->execute([$temp['username'], $temp['password']]);
            unset($_SESSION['temp_reg']);
            $msg = "Registrasi Berhasil! Silakan Login.";
            $msgType = "success";
        } else {
            $msg = "OTP Salah atau Kadaluwarsa!";
            $msgType = "error";
            $showOtpForm = true;
        }
    }

    // 2. Login 
    if ($act === 'login') {
        $u = $_POST['username'];
        $p = $_POST['password'];
        $s = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $s->execute([$u]);
        $user = $s->fetch();
        if ($user && password_verify($p, $user['password'])) {
            $_SESSION['user'] = $user;
            if (password_verify('Sejak2009', $user['password']))
                $_SESSION['must_change'] = true;
            header("Location: ./");
            exit;
        } else {
            $msg = "Login Gagal!";
            $msgType = "error";
        }
    }

    // 3. FITUR TERPROTEKSI (Harus Login)
    if (isset($_SESSION['user'])) {
        $myRole = $_SESSION['user']['role'];
        $myUser = $_SESSION['user']['username'];

        // --- Action: Shorten ---
        if ($act === 'shorten') {
            $alias = trim($_POST['alias']);

            if (!$alias) {
                $alias = substr(md5(uniqid()), 0, 6);
            } else {
                if (strlen($alias) > 30) {
                    $msg = "Gagal! Alias maksimal 30 karakter.";
                    $msgType = "error";
                    goto end_process;
                }
                if (preg_match('/[^a-zA-Z0-9-]/', $alias)) {
                    $msg = "Gagal! Alias hanya boleh huruf, angka, dan tanda strip (-).";
                    $msgType = "error";
                    goto end_process;
                }
            }
            $is_permanent = isset($_POST['is_permanent']);
            $date = $_POST['expiry_date'] ?? null;
            $time = $_POST['expiry_time'] ?? null;

            if ($is_permanent) {
                $full_expiry = null;
            } else {
                // Jika jam kosong, set ke akhir hari (23:59:59)
                $full_expiry = $date . ' ' . (!empty($time) ? $time . ':00' : '23:59:59');
            }

            if (!$is_permanent && (empty($date) || $full_expiry < date('Y-m-d H:i:s'))) {
                $msg = "Kadaluwarsa tidak valid!";
                $msgType = "error";
            } else {
                try {
                    $pdo->prepare("INSERT INTO links (original_url, short_code, owner_username, expiry_date) VALUES (?,?,?,?)")
                        ->execute([$_POST['url'], $alias, $myUser, $full_expiry]);
                    $resLink = $baseUrl . $alias;
                    $msg = "Link Berhasil Dibuat!";
                    $msgType = "success";
                } catch (PDOException $e) {
                    $msg = "Nama Link sudah dipakai!";
                    $msgType = "error";
                }
            }
        }

        // --- Action: Delete Link ---
        if ($act === 'del_link') {
            if ($myRole === 'Super Admin' || $myRole === 'Administrator') {
                $pdo->prepare("DELETE FROM links WHERE id = ?")->execute([$_POST['id']]);
            } else {
                $pdo->prepare("DELETE FROM links WHERE id = ? AND owner_username = ?")->execute([$_POST['id'], $myUser]);
            }
            $msg = "Link Dihapus!";
        }
        if ($act === 'edit_link') {
            $id = $_POST['id'];
            $new_alias = trim($_POST['alias']);

            if (strlen($new_alias) > 30) {
                $msg = "Gagal! Alias maksimal 30 karakter.";
                $msgType = "error";
                goto end_process;
            }
            if (preg_match('/[^a-zA-Z0-9-]/', $new_alias)) {
                $msg = "Gagal! Alias hanya boleh huruf, angka, dan tanda strip (-).";
                $msgType = "error";
                goto end_process;
            }
            $is_permanent = isset($_POST['is_permanent']);
            $date = $_POST['expiry_date'] ?? null;
            $time = $_POST['expiry_time'] ?? null;

            if ($is_permanent) {
                $full_expiry = null;
            } else {
                $full_expiry = $date . ' ' . (!empty($time) ? $time . ':00' : '23:59:59');
            }

            // Cek kepemilikan agar orang lain tidak bisa edit link kita
            if ($myRole !== 'Super Admin' && $myRole !== 'Administrator') {
                $stmtCheck = $pdo->prepare("SELECT id FROM links WHERE id = ? AND owner_username = ?");
                $stmtCheck->execute([$id, $myUser]);
                if (!$stmtCheck->fetch()) {
                    die("Akses Ditolak.");
                }
            }

            try {
                $pdo->prepare("UPDATE links SET short_code = ?, expiry_date = ? WHERE id = ?")
                    ->execute([$new_alias, $full_expiry, $id]);
                $msg = "Link Berhasil Diperbarui!";
                $msgType = "success";
            } catch (PDOException $e) {
                $msg = "Nama Link sudah dipakai!";
                $msgType = "error";
            }
        }

        // --- Action: Ambil Log (Bisa diakses User, Admin, Super Admin) ---
        if ($act === 'get_link_logs') {
            header('Content-Type: application/json');
            $linkId = $_POST['link_id'];
            $range = $_POST['range'] ?? 'all';

            // Keamanan: Jika Role adalah 'User', pastikan link tersebut miliknya
            if ($myRole === 'User') {
                $stmtCheck = $pdo->prepare("SELECT id FROM links WHERE id = ? AND owner_username = ?");
                $stmtCheck->execute([$linkId, $myUser]);
                if (!$stmtCheck->fetch()) {
                    echo json_encode([]); // Jika bukan miliknya, kirim data kosong
                    exit;
                }
            }
            // Tentukan filter waktu
            $timeFilter = "";
            if ($range == '1')
                $timeFilter = " AND visited_at >= NOW() - INTERVAL 1 DAY";
            elseif ($range == '7')
                $timeFilter = " AND visited_at >= NOW() - INTERVAL 7 DAY";
            elseif ($range == '30')
                $timeFilter = " AND visited_at >= NOW() - INTERVAL 30 DAY";
            elseif ($range == '90')
                $timeFilter = " AND visited_at >= NOW() - INTERVAL 90 DAY";

            // Ambil data log
            $stmt = $pdo->prepare("SELECT visited_at, ip_address, lokasi, referrer, user_agent FROM visit_logs WHERE link_id = ? $timeFilter ORDER BY id DESC");
            $stmt->execute([$linkId]);
            echo json_encode($stmt->fetchAll());
            exit;
        }

        // --- Action: Download CSV (Bisa diakses User untuk link miliknya) ---
        if ($act === 'download_logs') {
            $linkId = $_POST['link_id'] ?? null;
            $range = $_POST['range'] ?? 'all'; // Tangkap pilihan filter
            // Cek kepemilikan jika bukan Admin
            if ($myRole === 'User' && $linkId) {
                $stmtCheck = $pdo->prepare("SELECT id FROM links WHERE id = ? AND owner_username = ?");
                $stmtCheck->execute([$linkId, $myUser]);
                if (!$stmtCheck->fetch()) {
                    die("Akses Ditolak.");
                }
            }

            // Buat filter waktu yang sama dengan logika tampilan
            $timeFilter = "";
            if ($range == '1')
                $timeFilter = " AND v.visited_at >= NOW() - INTERVAL 1 DAY";
            elseif ($range == '7')
                $timeFilter = " AND v.visited_at >= NOW() - INTERVAL 7 DAY";
            elseif ($range == '30')
                $timeFilter = " AND v.visited_at >= NOW() - INTERVAL 30 DAY";
            elseif ($range == '90')
                $timeFilter = " AND v.visited_at >= NOW() - INTERVAL 90 DAY";



            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=Log_Politala_' . date('Ymd') . '.csv');
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['Waktu', 'Short Code', 'IP', 'Lokasi', 'Perangkat', 'Browser', 'Referrer'], ";");

            $sqlBase = "SELECT v.visited_at, l.short_code, v.ip_address, v.lokasi, v.user_agent, v.referrer 
                FROM visit_logs v JOIN links l ON v.link_id = l.id";
            if ($linkId) {
                $stmt = $pdo->prepare("$sqlBase WHERE v.link_id = ? $timeFilter ORDER BY v.id DESC");
                $stmt->execute([$linkId]);
            } else if ($myRole === 'Super Admin') {
                $stmt = $pdo->query("$sqlBase WHERE 1=1 $timeFilter ORDER BY v.id DESC");
            }

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $device = getDeviceName($row['user_agent']);
                $browser = getBrowserName($row['user_agent']);
                fputcsv($out, [
                    $row['visited_at'],
                    $row['short_code'],
                    $row['ip_address'],
                    $row['lokasi'],
                    $device, // Data perangkat baru
                    $browser,// Data browser baru
                    $row['referrer']
                ], ";");
            }
            fclose($out);
            exit;
        }

        // --- Action: Khusus Super Admin ---
        if ($myRole === 'Super Admin') {

            if ($act === 'get_link_logs') {
                header('Content-Type: application/json');
                $linkId = $_POST['link_id'];
                $stmt = $pdo->prepare("SELECT visited_at, ip_address, lokasi, referrer, user_agent FROM visit_logs WHERE link_id = ? ORDER BY id DESC LIMIT 50");
                $stmt->execute([$_POST['link_id']]);
                echo json_encode($stmt->fetchAll());
                exit;
            }

            if ($act === 'manage_user') {
                $u = strtolower(trim($_POST['username']));
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$u]);
                if ($stmt->fetch()) {
                    $msg = "Akun sudah terdaftar!";
                    $msgType = "error";
                } else {
                    $h = password_hash('Sejak2009', PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)")->execute([$u, $h, $_POST['role']]);
                    $msg = "User Berhasil Ditambah!";
                    $msgType = "success";
                }
            }

            if ($act === 'reset_user') {
                $h = password_hash('Sejak2009', PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$h, $_POST['id']]);
                $msg = "Password direset ke: Sejak2009";
            }

            if ($act === 'del_user') {
                $userId = $_POST['id'];

                // 1. Ambil username user yang akan dihapus (karena tabel links berelasi via owner_username)
                $stmtUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $stmtUser->execute([$userId]);
                $userData = $stmtUser->fetch();

                if ($userData && $userData['username'] !== 'super') {
                    $usernameToDelete = $userData['username'];

                    // 2. Hapus semua log kunjungan (visit_logs) yang terkait dengan link milik user tersebut
                    // Kita menggunakan subquery untuk mencari ID link yang dimiliki oleh user ini
                    $pdo->prepare("DELETE FROM visit_logs WHERE link_id IN (SELECT id FROM links WHERE owner_username = ?)")
                        ->execute([$usernameToDelete]);

                    // 3. Hapus semua link yang dibuat oleh user tersebut
                    $pdo->prepare("DELETE FROM links WHERE owner_username = ?")
                        ->execute([$usernameToDelete]);

                    // 4. Terakhir, hapus akun user itu sendiri
                    $pdo->prepare("DELETE FROM users WHERE id = ?")
                        ->execute([$userId]);

                    $msg = "User, Tautan, dan Log berhasil dihapus permanen!";
                    $msgType = "success";
                }
            }
        }

        if ($act === 'update_password') {
            $h = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$h, $_SESSION['user']['id']]);
            unset($_SESSION['must_change']);
            $msg = "Password diperbarui!";
        }
    }
    end_process:
    if ($act === 'logout') {
        session_destroy();
        header("Location: ./");
        exit;
    }
}

// --- STATISTIK KHUSUS SUPER ADMIN ---
$stats = [];
if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'Super Admin') {
    // Total Link & Total Klik
    $stats['total_links'] = $pdo->query("SELECT COUNT(*) FROM links")->fetchColumn();
    $stats['total_views'] = $pdo->query("SELECT SUM(views) FROM links")->fetchColumn() ?: 0;

    // Top 5 Link Terpopuler
    $stats['top_links'] = $pdo->query("SELECT short_code, views FROM links ORDER BY views DESC LIMIT 5")->fetchAll();

    // Top 5 Lokasi Pengunjung
    $stats['top_locations'] = $pdo->query("SELECT lokasi, COUNT(*) as jumlah FROM visit_logs GROUP BY lokasi ORDER BY jumlah DESC LIMIT 3")->fetchAll();

    // Link Aktif vs Kadaluwarsa
    $stats['expired_links'] = $pdo->query("SELECT COUNT(*) FROM links WHERE expiry_date < CURDATE()")->fetchColumn();
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Politala</title>
    <style>
        body {
            margin: 0;
            background: #f9fafb;
            font-family: sans-serif;
            display: block;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .ready {
            opacity: 1;
        }

        nav {
            background: transparent;
            padding: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
    </style>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Munculkan body hanya setelah Tailwind siap proses atau DOM load
        document.addEventListener("DOMContentLoaded", function () {
            document.body.classList.add('ready');
        });
    </script>
</head>

<body class="bg-gray-50 text-black">
    <nav class="p-6 max-w-6xl mx-auto flex justify-between items-center">
        <?php if (isset($_SESSION['user'])): ?>
            <a href="./" class="transition-transform active:scale-95">
                <img src="<?= $logo ?>" class="h-10 cursor-pointer" alt="Logo">
            </a>
            <div class="flex items-center gap-4">
                <button onclick="openGuide()"
                    class="text-[11px] font-bold text-blue-500 hover:text-black transition-colors flex items-center gap-1 italic normal-case tracking-widest">
                    <i class="fa-solid fa-circle-info text-sm"></i> Panduan Pengguna
                </button>
                <form method="POST" class="m-0">
                    <input type="hidden" name="action" value="logout">
                    <button
                        class="bg-black text-white px-6 py-2 rounded-full text-[11px] font-bold transition-all shadow-lg active:scale-[0.98]">Keluar</button>
                </form>
            <?php endif; ?>
    </nav>

    <main class="max-w-6xl mx-auto p-6">
        <?php if (!isset($_SESSION['user'])): ?>
            <div id="auth-container" class="mt-10 max-w-sm mx-auto bg-white p-10 rounded-[40px] shadow-2xl text-center">
                <img src="<?= $logo ?>" class="h-20 mx-auto mb-0">

                <div id="view-login" class="<?= isset($showOtpForm) ? 'hidden' : 'block' ?>">
                    <form id="form-login" method="POST" class="space-y-4 text-left">
                        <input type="hidden" name="action" value="login">
                        <input type="text" name="username" placeholder="Email"
                            class="w-full p-4 bg-gray-50 rounded-2xl outline-none focus:ring-1 ring-black/5" required>
                        <div class="relative">
                            <input type="password" name="password" id="pass-login" placeholder="Password"
                                class="w-full p-4 bg-gray-50 rounded-2xl outline-none focus:ring-1 ring-black/5" required>
                            <button type="button" onclick="togglePassVis('pass-login')"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400"><i
                                    class="fa-solid fa-eye text-xs"></i></button>
                        </div>
                        <button
                            class="w-full bg-black text-white py-5 rounded-[22px] font-extrabold text-[12px] tracking-[0.2em] hover:bg-gray-900 transition-all shadow-lg active:scale-[0.98]">MASUK</button>
                    </form>

                    <!-- Tombol Panduan Login -->
                    <button onclick="openLoginGuide()"
                        class="mt-6 text-[11px] font-bold text-blue-500 hover:text-blue-700 transition-colors flex items-center justify-center gap-1 mx-auto italic normal-case tracking-widest">
                        <i class="fa-solid fa-circle-info text-sm"></i> Panduan Daftar
                    </button>

                    <p class="mt-4 text-[11px] text-gray-400 font-bold uppercase tracking-widest">
                        Belum punya akun?
                        <button onclick="toggleAuth('register')"
                            class="text-black underline ml-1 hover:text-blue-600 transition-colors">Daftar Disini</button>
                    </p>
                </div>

                <div id="view-register" class="hidden">
                    <h2 class="text-[12px] font-black italic mb-6 tracking-widest text-gray-400">PENDAFTARAN AKUN</h2>
                    <form id="form-register" method="POST" class="space-y-4 text-left">
                        <input type="hidden" name="action" value="register">
                        <div class="space-y-1">
                            <input type="email" name="username" placeholder="Email @politala.ac.id"
                                class="w-full p-4 bg-gray-50 rounded-2xl outline-none focus:ring-1 ring-black/5" required>
                            <p class="text-[9px] text-gray-400 ml-2 italic"></p>
                        </div>
                        <div class="relative">
                            <input type="password" name="password" id="pass-reg" placeholder="Buat Password"
                                class="w-full p-4 bg-gray-50 rounded-2xl outline-none focus:ring-1 ring-black/5" required>
                            <button type="button" onclick="togglePassVis('pass-reg')"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400"><i
                                    class="fa-solid fa-eye text-xs"></i></button>
                        </div>
                        <button
                            class="w-full bg-blue-600 text-white py-5 rounded-[22px] font-extrabold text-[12px] tracking-[0.2em] hover:bg-blue-700 transition-all shadow-lg active:scale-[0.98]">DAFTAR
                            SEKARANG</button>
                    </form>

                    <button onclick="toggleAuth('login')"
                        class="mt-6 text-[10px] text-gray-400 font-bold underline hover:text-black">MASUK</button>
                </div>

                <div id="view-otp" class="<?= isset($showOtpForm) ? 'block' : 'hidden' ?>">
                    <h2 class="text-[12px] font-black italic mb-2 tracking-widest text-blue-600">VERIFIKASI EMAIL</h2>
                    <p class="text-[10px] text-gray-400 mb-6 italic">Masukkan kode 6-digit yang dikirim ke email Anda</p>

                    <form id="form-otp" method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="verify_otp">
                        <input type="text" name="otp_code" maxlength="6" placeholder="______"
                            class="w-full p-4 bg-gray-50 rounded-2xl outline-none focus:ring-1 ring-black/5 text-center text-2xl font-bold tracking-[0.5em]"
                            required>

                        <button
                            class="w-full bg-blue-600 text-white py-5 rounded-[22px] font-extrabold text-[12px] tracking-[0.2em] hover:bg-blue-700 transition-all shadow-lg active:scale-[0.98]">
                            VERIFIKASI
                        </button>
                    </form>
                    <button onclick="toggleAuth('register')"
                        class="mt-6 text-[10px] text-gray-400 font-bold underline">Bukan email saya?</button>
                </div>
            </div>

            <script>
                function toggleAuth(type) {
                    const vLogin = document.getElementById('view-login');
                    const vReg = document.getElementById('view-register');
                    const vOtp = document.getElementById('view-otp');

                    // Sembunyikan semua dulu
                    vLogin.classList.add('hidden');
                    vReg.classList.add('hidden');
                    vOtp.classList.add('hidden');

                    if (type === 'register') {
                        vReg.classList.remove('hidden');
                    } else if (type === 'otp') {
                        vOtp.classList.remove('hidden');
                    } else {
                        vLogin.classList.remove('hidden');
                    }
                }
                function togglePassVis(id) {
                    const x = document.getElementById(id);
                    x.type = x.type === "password" ? "text" : "password";
                }
                function openLoginGuide() {
                    document.getElementById('login-guide-modal').classList.remove('hidden');
                }
                function closeLoginGuide() {
                    document.getElementById('login-guide-modal').classList.add('hidden');
                }
            </script>

            <!-- Modal Panduan Login -->
            <div id="login-guide-modal" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4"
                onclick="closeLoginGuide()">
                <div class="relative max-w-5xl w-full max-h-[90vh] bg-white rounded-3xl overflow-hidden shadow-2xl animate-[fadeIn_0.3s_ease-out]"
                    onclick="event.stopPropagation()">
                    <button onclick="closeLoginGuide()"
                        class="absolute top-4 right-4 w-10 h-10 bg-black/80 hover:bg-black text-white rounded-full flex items-center justify-center transition-colors z-10">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                    <div class="p-6 bg-blue-500 text-white text-center">
                        <h3 class="text-lg font-bold tracking-wide"><i class="fa-solid fa-circle-info mr-2"></i>Panduan
                            Daftar</h3>
                    </div>
                    <div class="p-4 overflow-y-auto max-h-[70vh]">
                        <img src="pan-log.png" alt="Panduan Login" class="w-full h-auto rounded-xl shadow-md">
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php if (isset($_SESSION['must_change'])): ?>
                <div class="mt-20 max-w-sm mx-auto bg-white p-10 rounded-[40px] shadow-2xl text-center">
                    <h2 class="text-2xl font-bold mb-2 italic">Ganti Password</h2>
                    <p class="text-[10px] text-red-500 font-black uppercase italic tracking-widest mb-6">Wajib ganti password
                        default!</p>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="update_password">
                        <input type="password" name="new_password" placeholder="Password Baru"
                            class="w-full p-4 bg-gray-50 rounded-2xl outline-none focus:ring-1 ring-black/5" required>
                        <button
                            class="w-full bg-black text-white py-5 rounded-[22px] font-black tracking-widest text-[12px] shadow-lg">SIMPAN
                            PASSWORD</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2 space-y-7">
                        <div class="mb-0">
                            <h1 class="text-4xl font-bold tracking-tight text-gray-900 leading-tight">Buat Tautan Jadi Lebih
                                Efisien</h1>
                            <p class="text-gray-500 mt-0 text-[14px] font-medium">Link.Politala membantu menyederhanakan URL,
                                membuat QR Code dan memantau Tautan anda.</p>
                            <p class="text-gray-400 mt-0 text-[12px] font-medium">Butuh Bantuan? Hubungi ICT Helpdesk
                                085250512228</p>
                        </div>

                        <form method="POST"
                            class="bg-white p-6 rounded-[30px] shadow-sm flex flex-col gap-4 border border-gray-100">
                            <input type="hidden" name="action" value="shorten">
                            <input type="url" name="url" placeholder="Tempel Link Panjang disini..."
                                class="w-full h-[56px] px-6 bg-gray-50 rounded-2xl outline-none font-medium focus:ring-1 ring-black/5"
                                required>
                            <div class="flex flex-col md:flex-row gap-2 items-start">
                                <input type="text" name="alias" placeholder="Nama Link (Maks 30 Karakter)" maxlength="30"
                                    oninput="this.value = this.value.replace(/[^a-zA-Z0-9-]/g, '')"
                                    class="w-full md:flex-grow h-[56px] px-6 bg-gray-50 rounded-2xl outline-none font-medium focus:ring-1 ring-black/5">

                                <div class="flex gap-2 w-full md:w-auto">
                                    <div class="w-full md:w-40 h-[56px] bg-gray-50 rounded-2xl px-4 flex flex-col justify-center border border-transparent focus-within:ring-1 ring-black/5"
                                        id="expiry-date-container">
                                        <label class="text-[9px] font-bold text-red-400 italic">Tanggal Kadaluwarsa</label>
                                        <input type="date" name="expiry_date" id="expiry_date"
                                            class="w-full bg-transparent outline-none font-bold text-[12px]" required>
                                    </div>

                                    <div class="w-full md:w-28 h-[56px] bg-gray-50 rounded-2xl px-4 flex flex-col justify-center border border-transparent focus-within:ring-1 ring-black/5"
                                        id="expiry-time-container">
                                        <label class="text-[9px] font-bold text-gray-400 italic">Waktu (Opsional)</label>
                                        <input type="time" name="expiry_time" id="expiry_time"
                                            class="w-full bg-transparent outline-none font-bold text-[12px]">
                                    </div>

                                    <div class="flex items-center gap-2 px-2">
                                        <input type="checkbox" name="is_permanent" id="is_permanent"
                                            class="w-4 h-4 rounded border-gray-300 text-black focus:ring-black"
                                            onchange="togglePermanent(this, 'expiry_date', 'expiry_time')">
                                        <label for="is_permanent"
                                            class="text-[13px] font-bold text-gray-900 normal-case cursor-pointer">Permanen</label>
                                    </div>
                                </div>
                            </div>

                            <script>
                                function togglePermanent(checkbox, dateId, timeId) {
                                    const dateInput = document.getElementById(dateId);
                                    const timeInput = document.getElementById(timeId);
                                    if (checkbox.checked) {
                                        dateInput.disabled = true;
                                        timeInput.disabled = true;
                                        dateInput.required = false;
                                        dateInput.parentElement.classList.add('opacity-50');
                                        timeInput.parentElement.classList.add('opacity-50');
                                    } else {
                                        dateInput.disabled = false;
                                        timeInput.disabled = false;
                                        dateInput.required = true;
                                        dateInput.parentElement.classList.remove('opacity-50');
                                        timeInput.parentElement.classList.remove('opacity-50');
                                    }
                                }
                            </script>

                            <button
                                class="w-full bg-black text-white px-10 h-[56px] rounded-2xl font-black text-[12px] tracking-widest hover:bg-gray-900 active:scale-95 transition-all shadow-lg">
                                PERSINGKAT
                            </button>
                        </form>

                        <?php if (isset($resLink)): ?>
                            <div class="bg-black text-white p-8 rounded-[50px] animate-in slide-in-from-bottom duration-500">
                                <div class="flex flex-col md:flex-row items-center gap-8">
                                    <!-- Preview QR Code -->
                                    <div class="bg-white p-3 rounded-3xl shrink-0 shadow-xl overflow-hidden">
                                        <canvas id="qr-preview-canvas" class="w-32 h-auto md:w-40" style="display:none;"></canvas>
                                        <img id="qr-preview-img" alt="QR Code Preview" class="w-32 h-auto md:w-40 rounded-2xl">
                                    </div>

                                    <div class="text-center md:text-left flex-1">
                                        <p class="text-[10px] text-gray-400 font-bold mb-2 uppercase tracking-widest">Tautan
                                            Politala Berhasil:</p>
                                        <h3 class="text-xl md:text-2xl font-bold italic mb-6 break-all">
                                            <?= str_replace(['http://', 'https://'], '', $resLink) ?>
                                        </h3>
                                        <div class="flex flex-wrap justify-center md:justify-start gap-2">
                                            <button
                                                onclick="navigator.clipboard.writeText('<?= str_replace(['http://', 'https://'], '', $resLink) ?>'); alert('Disalin!')"
                                                class="bg-white text-black px-6 py-3 rounded-xl font-black text-[10px] hover:bg-gray-200 transition-colors">SALIN</button>
                                            <a href="<?= $resLink ?>" target="_blank"
                                                class="bg-blue-600 text-white px-6 py-3 rounded-xl font-black text-[10px] uppercase hover:bg-blue-700 transition-colors">Kunjungi</a>
                                            <button
                                                onclick="downloadQRCode('https://quickchart.io/qr?text=<?= urlencode($resLink) ?>&ecLevel=H&size=500', '<?= $alias ?>')"
                                                class="bg-gray-800 text-white px-6 py-3 rounded-xl font-black text-[10px] uppercase hover:bg-gray-700 transition-colors">
                                                Unduh QR
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <script>
                                document.addEventListener("DOMContentLoaded", function () {
                                    renderQRPreview('https://quickchart.io/qr?text=<?= urlencode($resLink) ?>&ecLevel=H&size=500', '<?= $alias ?>', 'qr-preview-img');
                                });
                            </script>
                        <?php endif; ?>

                        <div class="space-y-4">
                            <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-4">
                                <h3 class="text-[12px] font-black text-gray-400 italic uppercase tracking-widest">Riwayat Link
                                </h3>

                                <form method="GET" class="relative w-full md:w-64">
                                    <input type="hidden" name="p" value="1">
                                    <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                                        placeholder="Cari Nama Link atau URL Asli....."
                                        class="w-full pl-4 pr-16 py-2 bg-white border border-gray-100 rounded-2xl text-[11px] font-bold outline-none focus:ring-1 ring-black/5 shadow-sm">

                                    <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                                        <?php if ($search): ?>
                                            <a href="./" title="Hapus Pencarian"
                                                class="text-gray-300 hover:text-red-500 transition-colors">
                                                <i class="fa-solid fa-circle-xmark text-[14px]"></i>
                                            </a>
                                        <?php endif; ?>

                                        <button type="submit" class="text-gray-400 hover:text-black">
                                            <i class="fa-solid fa-magnifying-glass text-[10px]"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <?php
                            // --- LOGIKA PAGINASI & PENCARIAN ---
                            $search = $_GET['q'] ?? '';
                            $page = isset($_GET['p']) ? (int) $_GET['p'] : 1;
                            $perPage = 5; // Maksimal 10 link per halaman
                            $offset = ($page - 1) * $perPage;

                            $role = $_SESSION['user']['role'];
                            $myUser = $_SESSION['user']['username'];

                            $sql = "FROM links WHERE 1=1";
                            $params = [];

                            if ($role === 'User') {
                                $sql .= " AND owner_username = ?";
                                $params[] = $myUser;
                            }

                            if ($search) {
                                $sql .= " AND (short_code LIKE ? OR original_url LIKE ?)";
                                $params[] = "%$search%";
                                $params[] = "%$search%";
                            }

                            // Menghitung total data untuk tombol halaman
                            $totalStmt = $pdo->prepare("SELECT COUNT(*) $sql");
                            $totalStmt->execute($params);
                            $totalRows = $totalStmt->fetchColumn();
                            $totalPages = ceil($totalRows / $perPage);

                            if ($search && $totalRows == 0) {
                                $msg = "Pencarian '" . htmlspecialchars($search) . "' tidak ditemukan!";
                            }

                            $totalPages = ceil($totalRows / $perPage);

                            // Mengambil data link (Hanya 10 saja)
                            $stmt = $pdo->prepare("SELECT * $sql ORDER BY id DESC LIMIT $perPage OFFSET $offset");
                            $stmt->execute($params);

                            while ($l = $stmt->fetch()):
                                $fullUrl = $baseUrl . $l['short_code'];
                                $exp = ($l['expiry_date'] && date('Y-m-d') > $l['expiry_date']);
                                $stmtStats = $pdo->prepare("
                                SELECT 
                                    SUM(CASE WHEN user_agent REGEXP 'Android|iPhone|iPad|Mobile' THEN 1 ELSE 0 END) as mobile_clicks,
                                    SUM(CASE WHEN user_agent NOT REGEXP 'Android|iPhone|iPad|Mobile' THEN 1 ELSE 0 END) as desktop_clicks
                                FROM visit_logs WHERE link_id = ?
                            ");
                                $stmtStats->execute([$l['id']]);
                                $clickStats = $stmtStats->fetch();

                                $stmtLoc = $pdo->prepare("
                                SELECT lokasi, COUNT(*) as jumlah
                                FROM visit_logs WHERE link_id = ? 
                                GROUP BY lokasi ORDER BY jumlah DESC LIMIT 1
                            ");
                                $stmtLoc->execute([$l['id']]);
                                $topLocData = $stmtLoc->fetch();

                                $stmtRef = $pdo->prepare("
                                SELECT referrer, COUNT(*) as jumlah 
                                FROM visit_logs WHERE link_id = ? 
                                GROUP BY referrer ORDER BY jumlah DESC LIMIT 1
                            ");
                                $stmtRef->execute([$l['id']]);
                                $topRefData = $stmtRef->fetch();
                                ?>
                                <div
                                    class="bg-white p-5 rounded-[35px] flex flex-col md:flex-row justify-between items-start md:items-center shadow-sm border border-gray-100 <?= $exp ? 'opacity-40' : '' ?>">
                                    <div class="text-left">
                                        <p class="font-black italic">/<?= htmlspecialchars($l['short_code']) ?></p>
                                        <div class="text-[11px] font-bold text-blue-400 italic">
                                            <p><?= ($role !== 'User' ? 'Oleh: ' . $l['owner_username'] . ' | ' : '') . $l['views'] ?>
                                                Lihat</p>
                                            <p class="text-red-300">
                                                <?= $l['expiry_date'] ? 'Hingga: ' . date('d M Y, H:i', strtotime($l['expiry_date'])) : '' ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-5 mt-4 md:mt-0">

                                        <button onclick="openAnalytics(<?= $l['id'] ?>, '<?= $l['short_code'] ?>')"
                                            title="Analisa Pengunjung" class="text-blue-400 hover:text-blue-700 transition-colors">
                                            <i class="fa-solid fa-chart-line"></i>
                                        </button>
                                        <button
                                            onclick="openEditModal(<?= $l['id'] ?>, '<?= $l['short_code'] ?>', '<?= $l['expiry_date'] ? date('Y-m-d', strtotime($l['expiry_date'])) : '' ?>', '<?= $l['expiry_date'] ? date('H:i', strtotime($l['expiry_date'])) : '' ?>', <?= $l['expiry_date'] ? 'false' : 'true' ?>)"
                                            title="Edit Tautan" class="text-orange-400 hover:text-orange-600 transition-colors">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <button
                                            onclick="downloadQRCode('https://quickchart.io/qr?text=<?= urlencode($fullUrl) ?>&ecLevel=H&size=500', '<?= $l['short_code'] ?>')"
                                            title="Unduh QR Code" class="text-gray-400 hover:text-green-500 transition-colors">
                                            <i class="fa-solid fa-download"></i>
                                        </button>
                                        <button
                                            onclick="navigator.clipboard.writeText('<?= str_replace(['http://', 'https://'], '', $fullUrl) ?>'); alert('Tautan disalin!')"
                                            title="Salin Tautan" class="text-gray-400 hover:text-black transition-colors">
                                            <i class="fa-solid fa-copy"></i>
                                        </button>

                                        <form method="POST" onsubmit="return confirm('Hapus?')">
                                            <input type="hidden" name="action" value="del_link">
                                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                            <button class="text-red-200 hover:text-red-600 transition-colors" title="Hapus">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                            <?php if ($totalPages > 1): ?>
                                <div class="flex justify-center items-center gap-1.5 mt-8 mb-5">
                                    <!-- Halaman 1, 2, 3 -->
                                    <a href="?p=1&q=<?= urlencode($search) ?>"
                                        class="px-4 py-2 rounded-xl text-[10px] font-black transition-all <?= $page == 1 ? 'bg-black text-white shadow-lg' : 'bg-white text-gray-400 hover:bg-gray-100 border border-gray-100' ?>">1</a>

                                    <?php if ($totalPages >= 2): ?>
                                        <a href="?p=2&q=<?= urlencode($search) ?>"
                                            class="px-4 py-2 rounded-xl text-[10px] font-black transition-all <?= $page == 2 ? 'bg-black text-white shadow-lg' : 'bg-white text-gray-400 hover:bg-gray-100 border border-gray-100' ?>">2</a>
                                    <?php endif; ?>

                                    <?php if ($totalPages >= 3): ?>
                                        <a href="?p=3&q=<?= urlencode($search) ?>"
                                            class="px-4 py-2 rounded-xl text-[10px] font-black transition-all <?= $page == 3 ? 'bg-black text-white shadow-lg' : 'bg-white text-gray-400 hover:bg-gray-100 border border-gray-100' ?>">3</a>
                                    <?php endif; ?>

                                    <!-- Tombol Next (>) -->
                                    <?php if ($page < $totalPages): ?>
                                        <a href="?p=<?= $page + 1 ?>&q=<?= urlencode($search) ?>"
                                            class="px-4 py-2 rounded-xl text-[10px] font-black bg-white text-gray-400 hover:bg-gray-100 border border-gray-100 transition-all">
                                            <i class="fa-solid fa-chevron-right text-[8px]"></i>
                                        </a>
                                    <?php endif; ?>

                                    <!-- Halaman Terakhir -->
                                    <?php if ($totalPages > 3): ?>
                                        <a href="?p=<?= $totalPages ?>&q=<?= urlencode($search) ?>"
                                            class="px-4 py-2 rounded-xl text-[10px] font-black transition-all <?= $page == $totalPages ? 'bg-black text-white shadow-lg' : 'bg-white text-gray-400 hover:bg-gray-100 border border-gray-100' ?>"><?= $totalPages ?></a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($totalRows == 0): ?>
                                <div class="bg-white p-10 rounded-[35px] text-center border border-dashed border-gray-200">
                                    <?php if ($search): ?>
                                        <i class="fa-solid fa-magnifying-glass text-gray-200 text-4xl mb-4 block"></i>
                                        <p class="text-[11px] font-bold text-gray-400 italic">Tautan "<?= htmlspecialchars($search) ?>"
                                            belum pernah dibuat.</p>
                                        <a href="./"
                                            class="text-[10px] text-blue-500 font-black mt-2 inline-block uppercase tracking-widest"></a>
                                    <?php else: ?>
                                        <p class="text-[11px] font-bold text-gray-400 italic">Link belum dibuat.</p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <?php if ($role === 'Super Admin'): ?>
                            <div class="bg-white p-8 rounded-[40px] shadow-sm border border-gray-100">

                                <h3 class="text-[10px] font-black uppercase mb-2 italic">Tambah Akun</h3>
                                <div class="mb-10 bg-gray-50 p-4 rounded-3xl border border-gray-100">
                                    <form method="POST" class="space-y-2">
                                        <input type="hidden" name="action" value="manage_user">
                                        <input type="text" name="username" placeholder="Email @politala.ac.id"
                                            class="w-full p-3.5 bg-gray-50 rounded-2xl text-xs outline-none" required>
                                        <select name="role" class="w-full p-3.5 bg-gray-50 rounded-2xl text-xs font-black italic">
                                            <option value="User">USER</option>
                                            <option value="Administrator">ADMINISTRATOR</option>
                                            <option value="Super Admin">SUPER ADMIN</option>
                                        </select>
                                        <button
                                            class="w-full bg-black text-white py-4 rounded-2xl font-black text-[10px] italic shadow-xl">BUAT
                                            AKUN</button>

                                </div>
                                </form>


                                <div class="mt-8 space-y-4">
                                    <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-2">
                                        <h3 class="text-[10px] font-black uppercase italic">Pengguna</h3>
                                        <form method="GET" class="relative w-full md:w-48">
                                            <input type="text" name="uq"
                                                value="<?= htmlspecialchars($_GET['uq'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                placeholder="Cari Pengguna..."
                                                class="w-full pl-3 pr-16 py-1.5 bg-gray-50 border border-gray-100 rounded-xl text-[10px] font-bold outline-none focus:ring-1 ring-black/5 shadow-sm">

                                            <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                                                <?php if (isset($_GET['uq']) && $_GET['uq'] !== ''): ?>
                                                    <a href="?up=1" title="Hapus Pencarian"
                                                        class="text-gray-300 hover:text-red-500 transition-colors">
                                                        <i class="fa-solid fa-circle-xmark text-[12px]"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <button type="submit" class="text-gray-400 hover:text-black">
                                                    <i class="fa-solid fa-magnifying-glass text-[9px]"></i>
                                                </button>
                                            </div>
                                        </form>
                                    </div>

                                    <div class="mb-4 bg-white p-2 rounded-3xl border border-gray-100 space-y-1">
                                        <?php
                                        // --- LOGIKA PAGINASI & PENCARIAN USER ---
                                        $userSearch = $_GET['uq'] ?? '';
                                        $userPage = isset($_GET['up']) ? (int) $_GET['up'] : 1;
                                        $perPageUsers = 10;
                                        $userOffset = ($userPage - 1) * $perPageUsers;

                                        $userSql = "FROM users WHERE 1=1";
                                        $userParams = [];

                                        if ($userSearch) {
                                            $userSql .= " AND username LIKE ?";
                                            $userParams[] = "%$userSearch%";
                                        }

                                        // Hitung total user
                                        $totalUserStmt = $pdo->prepare("SELECT COUNT(*) $userSql");
                                        $totalUserStmt->execute($userParams);
                                        $totalUsers = $totalUserStmt->fetchColumn();
                                        $totalPagesUsers = ceil($totalUsers / $perPageUsers);

                                        // Ambil data user
                                        $stmtUsers = $pdo->prepare("SELECT * $userSql ORDER BY id DESC LIMIT $perPageUsers OFFSET $userOffset");
                                        $stmtUsers->execute($userParams);
                                        $users = $stmtUsers->fetchAll();

                                        if (count($users) == 0): ?>
                                            <p class="text-[10px] text-center py-4 font-bold text-gray-400 italic">Pengguna tidak
                                                ditemukan.</p>
                                        <?php else:
                                            foreach ($users as $u): ?>
                                                <div class="bg-gray-50 p-4 rounded-2xl flex justify-between items-center">
                                                    <div class="text-[10px]"><b><?= $u['username'] ?></b><br><span
                                                            class="opacity-100 uppercase text-[7px]"><?= $u['role'] ?></span></div>
                                                    <div class="flex gap-2">
                                                        <form method="POST" onsubmit="return confirm('Reset Password?')">
                                                            <input type="hidden" name="action" value="reset_user"><input type="hidden"
                                                                name="id" value="<?= $u['id'] ?>">
                                                            <button class="text-gray-300 hover:text-blue-500"><i
                                                                    class="fa-solid fa-rotate-left"></i></button>
                                                        </form>
                                                        <?php if ($u['username'] !== 'super'): ?>
                                                            <form method="POST" onsubmit="return confirm('Hapus?')">
                                                                <input type="hidden" name="action" value="del_user"><input type="hidden"
                                                                    name="id" value="<?= $u['id'] ?>">
                                                                <button class="text-gray-200 hover:text-red-500"><i
                                                                        class="fa-solid fa-circle-xmark"></i></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach;
                                        endif; ?>
                                    </div>

                                    <!-- Paginasi User -->
                                    <?php if ($totalPagesUsers > 1): ?>
                                        <div class="flex justify-center items-center gap-1.5 mt-2 mb-10">
                                            <!-- Halaman 1 & 2 -->
                                            <a href="?up=1&uq=<?= urlencode($userSearch) ?>"
                                                class="px-3 py-1.5 rounded-lg text-[9px] font-black transition-all <?= $userPage == 1 ? 'bg-black text-white shadow-md' : 'bg-gray-50 text-gray-400 hover:bg-gray-100' ?>">1</a>

                                            <?php if ($totalPagesUsers >= 2): ?>
                                                <a href="?up=2&uq=<?= urlencode($userSearch) ?>"
                                                    class="px-3 py-1.5 rounded-lg text-[9px] font-black transition-all <?= $userPage == 2 ? 'bg-black text-white shadow-md' : 'bg-gray-50 text-gray-400 hover:bg-gray-100' ?>">2</a>
                                            <?php endif; ?>

                                            <!-- Tombol Next (>) jika masih ada halaman selanjutnya -->
                                            <?php if ($userPage < $totalPagesUsers): ?>
                                                <a href="?up=<?= $userPage + 1 ?>&uq=<?= urlencode($userSearch) ?>"
                                                    class="px-3 py-1.5 rounded-lg text-[9px] font-black bg-gray-50 text-gray-400 hover:bg-gray-100 transition-all">
                                                    <i class="fa-solid fa-chevron-right text-[8px]"></i>
                                                </a>
                                            <?php endif; ?>

                                            <!-- Halaman Terakhir -->
                                            <?php if ($totalPagesUsers > 2): ?>
                                                <a href="?up=<?= $totalPagesUsers ?>&uq=<?= urlencode($userSearch) ?>"
                                                    class="px-3 py-1.5 rounded-lg text-[9px] font-black transition-all <?= $userPage == $totalPagesUsers ? 'bg-black text-white shadow-md' : 'bg-gray-50 text-gray-400 hover:bg-gray-100' ?>"><?= $totalPagesUsers ?></a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
    </main>

    <?php if ($msg): ?>
        <div id="toast"
            class="fixed top-10 right-10 bg-black text-white px-8 py-4 rounded-[20px] text-[10px] font-black italic tracking-widest shadow-2xl z-50">
            <?= $msg ?>
        </div>
        <script>setTimeout(() => document.getElementById('toast').remove(), 2000);</script>
    <?php endif; ?>
    <div id="modal-analytics"
        class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100] hidden flex items-center justify-center p-4">
        <div
            class="bg-white w-full max-w-4xl rounded-[40px] shadow-2xl overflow-hidden animate-in zoom-in duration-300 max-h-[90vh] overflow-y-auto">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h3 class="text-[10px] font-black uppercase text-gray-400 italic">Analisa Pengunjung</h3>
                        <h2 id="modal-title" class="text-[15px] font-black normal-case italic">Log Kunjungan/...</h2>
                    </div>
                    <div class="flex flex-col md:flex-row gap-3 items-center">
                        <select id="range-filter" onchange="refreshAnalytics()"
                            class="bg-gray-100 border-none text-[10px] font-bold rounded-xl px-4 py-2 outline-none focus:ring-2 ring-blue-500">
                            <option value="all">Selamanya</option>
                            <option value="1">1 Hari Terakhir</option>
                            <option value="7">7 Hari Terakhir</option>
                            <option value="30">30 Hari Terakhir</option>
                            <option value="90">90 Hari Terakhir</option>
                        </select>

                        <form method="POST" id="form-download-spec">
                            <input type="hidden" name="action" value="download_logs">
                            <input type="hidden" name="link_id" id="download-link-id">
                            <input type="hidden" name="range" id="download-range-val" value="all">
                            <button type="submit"
                                class="bg-green-600 text-white px-5 py-2 rounded-xl text-[10px] font-bold hover:bg-green-700 flex items-center gap-2">
                                <i class="fa-solid fa-file-excel"></i> Unduh
                            </button>
                        </form>
                        <button onclick="closeAnalytics()"
                            class="bg-gray-100 text-gray-400 hover:text-black p-2 rounded-full w-10 h-10 transition-colors">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <div id="stats-summary" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 hidden">
                    <div class="bg-gray-50 p-5 rounded-[30px] border border-gray-100 shadow-sm">
                        <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-1">Total Kunjungan
                        </p>
                        <p id="stat-total" class="text-3xl font-black italic">0</p>
                        <div class="flex gap-4 mt-3 pt-3 border-t border-gray-200/50">
                            <div>
                                <p class="text-[8px] font-bold text-blue-500 uppercase">Desktop</p>
                                <p id="stat-desktop" class="text-xs font-black">0</p>
                            </div>
                            <div>
                                <p class="text-[8px] font-bold text-green-500 uppercase">Mobile</p>
                                <p id="stat-mobile" class="text-xs font-black">0</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 p-5 rounded-[30px] border border-gray-100 shadow-sm">
                        <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-3">Top 10 Referrer
                        </p>
                        <ul id="top-referrers" class="text-[10px] space-y-2 font-bold italic text-gray-600"></ul>
                    </div>
                    <div class="bg-gray-50 p-5 rounded-[30px] border border-gray-100 shadow-sm">
                        <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-3">Top 10 Lokasi</p>
                        <ul id="top-locations" class="text-[10px] space-y-2 font-bold italic text-green-600"></ul>
                    </div>
                </div>

                <!-- Tabel Log Pengunjung (Disembunyikan, hanya untuk keperluan JavaScript) -->
                <div class="hidden">
                    <table class="w-full">
                        <tbody id="log-table-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        function parseUA(ua) {
            let browser = "Unknown Browser";
            if (ua.includes("Firefox")) browser = "Firefox";
            else if (ua.includes("SamsungBrowser")) browser = "Samsung Browser";
            else if (ua.includes("Opera") || ua.includes("OPR")) browser = "Opera";
            else if (ua.includes("Edge")) browser = "Edge";
            else if (ua.includes("Chrome")) browser = "Chrome";
            else if (ua.includes("Safari")) browser = "Safari";

            let isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua);
            return {
                browser: browser,
                device: isMobile ? "Mobile" : "Desktop",
                icon: isMobile ? "fa-mobile-screen-button" : "fa-desktop"
            };
        }

        let myChart = null;
        let currentLinkId = null;
        let currentShortCode = null;

        function openAnalytics(linkId, shortCode) {
            currentLinkId = linkId; // Simpan ID
            currentShortCode = shortCode; // Simpan Code
            document.getElementById('range-filter').value = 'all'; // Reset filter ke 'all' saat buka modal
            refreshAnalytics();
        }

        function refreshAnalytics() {
            const linkId = currentLinkId;
            const shortCode = currentShortCode;
            const range = document.getElementById('range-filter').value;
            document.getElementById('download-range-val').value = range;
            const modal = document.getElementById('modal-analytics');
            const statsDiv = document.getElementById('stats-summary');
            const tableBody = document.getElementById('log-table-body');
            if (tableBody) tableBody.innerHTML = '';

            document.getElementById('modal-title').innerText = "link.politala.ac.id/" + shortCode;
            document.getElementById('download-link-id').value = linkId;

            modal.classList.remove('hidden');


            const formData = new FormData();
            formData.append('action', 'get_link_logs');
            formData.append('link_id', linkId);
            formData.append('range', range);

            fetch('./', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    let mobileCount = 0;
                    const dailyStats = {};
                    const referrerCounts = {};
                    const locationCounts = {};

                    // 1. PROSES DATA DARI DATABASE
                    data.forEach(log => {
                        // Deteksi Device
                        const uaInfo = parseUA(log.user_agent || "");
                        if (uaInfo.device === "Mobile") {
                            mobileCount++;
                        }

                        // Hitung Referrer & Lokasi
                        let ref = log.referrer || 'Langsung';
                        referrerCounts[ref] = (referrerCounts[ref] || 0) + 1;

                        // Hitung Lokasi
                        let loc = log.lokasi || 'Tidak Diketahui';
                        locationCounts[loc] = (locationCounts[loc] || 0) + 1;

                        // Hitung Statistik Harian
                        const dateOnly = log.visited_at.split(' ')[0];
                        dailyStats[dateOnly] = (dailyStats[dateOnly] || 0) + 1;
                    });

                    // 2. UPDATE TAMPILAN SESUAI GAMBAR
                    const total = data.length;
                    const desktopCount = total - mobileCount;
                    document.getElementById('stat-total').innerText = total;
                    document.getElementById('stat-desktop').innerText = desktopCount;
                    document.getElementById('stat-mobile').innerText = mobileCount;

                    // 3. UPDATE DAFTAR TOP 10 REFERRER
                    const refList = document.getElementById('top-referrers');
                    const sortedRefs = Object.entries(referrerCounts).sort((a, b) => b[1] - a[1]).slice(0, 10);
                    refList.innerHTML = sortedRefs.map(item => `
                <li class="flex justify-between border-b border-gray-100 pb-1">
                    <span class="truncate pr-2">${item[0]}</span> 
                    <span class="text-blue-600">${item[1]}</span>
                </li>
            `).join('');

                    // 4. UPDATE DAFTAR TOP 10 LOKASI
                    const locList = document.getElementById('top-locations');
                    const sortedLocs = Object.entries(locationCounts).sort((a, b) => b[1] - a[1]).slice(0, 10);
                    locList.innerHTML = sortedLocs.map(item => `
                <li class="flex justify-between border-b border-gray-100 pb-1">
                    <span class="truncate pr-2">${item[0]}</span> 
                    <span class="text-green-600">${item[1]}</span>
                </li>
            `).join('');

                    // Munculkan container statistik
                    statsDiv.classList.remove('hidden');

                    // Render grafik jika fungsi renderChart tersedia
                    if (typeof renderChart === "function") {
                        renderChart(dailyStats);
                    }
                });
        }

        function renderChart(dailyData) {
            const canvas = document.getElementById('visitChart');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');

            if (myChart) { myChart.destroy(); }

            const labels = Object.keys(dailyData).reverse();
            const values = Object.values(dailyData).reverse();

            myChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Jumlah Kunjungan',
                        data: values,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: '#2563eb'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }
        // Tambahkan fungsi ini di dalam tag <script>
        function openGuide() {
            document.getElementById('modal-guide').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeGuide() {
            document.getElementById('modal-guide').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
        function closeAnalytics() {
            document.getElementById('modal-analytics').classList.add('hidden');
        }

        async function renderQRPreview(url, shortCode, targetImgId) {
            try {
                const response = await fetch(url);
                const blob = await response.blob();
                const qrImage = new Image();
                const qrUrl = URL.createObjectURL(blob);

                qrImage.onload = () => {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');

                    const size = 500;
                    const paddingBottom = 90;
                    canvas.width = size;
                    canvas.height = size + paddingBottom;

                    ctx.fillStyle = "#ffffff";
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(qrImage, 0, 0, size, size);

                    const logo = new Image();
                    logo.src = 'logopolitala.png';
                    logo.onload = () => {
                        const logoSize = size * 0.24; // Logo diperbesar (24%)
                        const logoX = (size - logoSize) / 2;
                        const logoY = (size - logoSize) / 2;

                        // Margin Putih dengan Rounded Corner
                        const margin = 10;
                        const bgSize = logoSize + (margin * 2);
                        const bgX = (size - bgSize) / 2;
                        const bgY = (size - bgSize) / 2;
                        const radius = 15;

                        ctx.fillStyle = "#ffffff";
                        ctx.beginPath();
                        ctx.moveTo(bgX + radius, bgY);
                        ctx.lineTo(bgX + bgSize - radius, bgY);
                        ctx.quadraticCurveTo(bgX + bgSize, bgY, bgX + bgSize, bgY + radius);
                        ctx.lineTo(bgX + bgSize, bgY + bgSize - radius);
                        ctx.quadraticCurveTo(bgX + bgSize, bgY + bgSize, bgX + bgSize - radius, bgY + bgSize);
                        ctx.lineTo(bgX + radius, bgY + bgSize);
                        ctx.quadraticCurveTo(bgX, bgY + bgSize, bgX, bgY + bgSize - radius);
                        ctx.lineTo(bgX, bgY + radius);
                        ctx.quadraticCurveTo(bgX, bgY, bgX + radius, bgY);
                        ctx.closePath();
                        ctx.fill();

                        ctx.drawImage(logo, logoX, logoY, logoSize, logoSize);

                        // --- Dynamic Font Sizing ---
                        const fullText = "link.politala.ac.id/" + shortCode;
                        let fontSize = 26;
                        ctx.font = "bold " + fontSize + "px Arial";

                        // Perkecil font jika teks lebih lebar dari canvas (dengan padding 40px)
                        while (ctx.measureText(fullText).width > (size - 40) && fontSize > 14) {
                            fontSize--;
                            ctx.font = "bold " + fontSize + "px Arial";
                        }

                        ctx.fillStyle = "#000000";
                        ctx.textAlign = "center";
                        ctx.fillText(fullText, size / 2, size + 55);

                        const previewImg = document.getElementById(targetImgId);
                        if (previewImg) {
                            previewImg.src = canvas.toDataURL("image/png");
                        }
                        URL.revokeObjectURL(qrUrl);
                    };
                    logo.onerror = () => {
                        const fullText = "link.politala.ac.id/" + shortCode;
                        let fontSize = 26;
                        ctx.font = "bold " + fontSize + "px Arial";
                        while (ctx.measureText(fullText).width > (size - 40) && fontSize > 14) {
                            fontSize--;
                            ctx.font = "bold " + fontSize + "px Arial";
                        }

                        ctx.fillStyle = "#000000";
                        ctx.textAlign = "center";
                        ctx.fillText(fullText, size / 2, size + 55);

                        const previewImg = document.getElementById(targetImgId);
                        if (previewImg) {
                            previewImg.src = canvas.toDataURL("image/png");
                        }
                        URL.revokeObjectURL(qrUrl);
                    };
                };
                qrImage.src = qrUrl;
            } catch (e) {
                console.error("Gagal render preview:", e);
            }
        }

        async function downloadQRCode(url, shortCode) {
            try {
                // 1. Fetch QR Code from API
                const response = await fetch(url);
                const blob = await response.blob();
                const qrImage = new Image();
                const qrUrl = URL.createObjectURL(blob);

                qrImage.onload = () => {
                    // 2. Setup Canvas
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');

                    const size = 500;
                    const paddingBottom = 90;
                    canvas.width = size;
                    canvas.height = size + paddingBottom;

                    ctx.fillStyle = "#ffffff";
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(qrImage, 0, 0, size, size);

                    // 3. Tambahkan Logo Politala
                    const logo = new Image();
                    logo.src = 'logopolitala.png';
                    logo.onload = () => {
                        const logoSize = size * 0.24; // 24%
                        const logoX = (size - logoSize) / 2;
                        const logoY = (size - logoSize) / 2;

                        // Margin Putih dengan Rounded Corner
                        const margin = 10;
                        const bgSize = logoSize + (margin * 2);
                        const bgX = (size - bgSize) / 2;
                        const bgY = (size - bgSize) / 2;
                        const radius = 15;

                        ctx.fillStyle = "#ffffff";
                        ctx.beginPath();
                        ctx.moveTo(bgX + radius, bgY);
                        ctx.lineTo(bgX + bgSize - radius, bgY);
                        ctx.quadraticCurveTo(bgX + bgSize, bgY, bgX + bgSize, bgY + radius);
                        ctx.lineTo(bgX + bgSize, bgY + bgSize - radius);
                        ctx.quadraticCurveTo(bgX + bgSize, bgY + bgSize, bgX + bgSize - radius, bgY + bgSize);
                        ctx.lineTo(bgX + radius, bgY + bgSize);
                        ctx.quadraticCurveTo(bgX, bgY + bgSize, bgX, bgY + bgSize - radius);
                        ctx.lineTo(bgX, bgY + radius);
                        ctx.quadraticCurveTo(bgX, bgY, bgX + radius, bgY);
                        ctx.closePath();
                        ctx.fill();

                        ctx.drawImage(logo, logoX, logoY, logoSize, logoSize);

                        // --- Dynamic Font Sizing ---
                        const fullText = "link.politala.ac.id/" + shortCode;
                        let fontSize = 26;
                        ctx.font = "bold " + fontSize + "px Arial";

                        // Perkecil font jika teks lebih lebar dari canvas (padding 40px)
                        while (ctx.measureText(fullText).width > (size - 40) && fontSize > 14) {
                            fontSize--;
                            ctx.font = "bold " + fontSize + "px Arial";
                        }

                        // 4. Tambahkan Nama Link
                        ctx.fillStyle = "#000000";
                        ctx.textAlign = "center";
                        ctx.fillText(fullText, size / 2, size + 55);

                        // 5. Download
                        canvas.toBlob((finalBlob) => {
                            const finalUrl = URL.createObjectURL(finalBlob);
                            const link = document.createElement('a');
                            link.href = finalUrl;
                            link.download = `QR_Politala_${shortCode}.png`;
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            URL.revokeObjectURL(finalUrl);
                            URL.revokeObjectURL(qrUrl);
                        });
                    };
                    logo.onerror = () => {
                        const fullText = "link.politala.ac.id/" + shortCode;
                        let fontSize = 26;
                        ctx.font = "bold " + fontSize + "px Arial";
                        while (ctx.measureText(fullText).width > (size - 40) && fontSize > 14) {
                            fontSize--;
                            ctx.font = "bold " + fontSize + "px Arial";
                        }

                        ctx.fillStyle = "#000000";
                        ctx.textAlign = "center";
                        ctx.fillText(fullText, size / 2, size + 55);

                        canvas.toBlob((finalBlob) => {
                            const finalUrl = URL.createObjectURL(finalBlob);
                            const downloadLink = document.createElement('a');
                            downloadLink.href = finalUrl;
                            downloadLink.download = `QR_Politala_${shortCode}.png`;
                            document.body.appendChild(downloadLink);
                            downloadLink.click();
                            document.body.removeChild(downloadLink);
                            URL.revokeObjectURL(finalUrl);
                            URL.revokeObjectURL(qrUrl);
                        });
                    };
                };
                qrImage.src = qrUrl;

            } catch (error) {
                console.error('Gagal mengunduh QR Code:', error);
                alert('Gagal mengunduh QR Code. Silakan coba lagi.');
            }
        }
        // --- LOGIKA RESET KE HALAMAN 1 SAAT REFRESH ---
        if (window.performance && window.performance.getEntriesByType("navigation")[0].type === "reload") {
            const urlParams = new URLSearchParams(window.location.search);

            // Jika terdapat parameter halaman 'p' dan nilainya bukan 1
            if (urlParams.has('p') && urlParams.get('p') !== '1') {
                urlParams.delete('p'); // Hapus parameter halaman

                // Bangun kembali URL tanpa parameter p
                const newSearch = urlParams.toString();
                const newUrl = window.location.pathname + (newSearch ? '?' + newSearch : '');

                // Arahkan ke URL baru (Halaman 1)
                window.location.href = newUrl;
            }
        }

    </script>
    <div id="modal-edit"
        class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[120] hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-[40px] shadow-2xl p-8">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-black italic">Edit Tautan</h2>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-black"><i
                        class="fa-solid fa-xmark text-xl"></i></button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit_link">
                <input type="hidden" name="id" id="edit-id">

                <div>
                    <label class="text-[10px] font-black text-gray-400 uppercase italic ml-2">Nama Link (Alias)</label>
                    <input type="text" name="alias" id="edit-alias" maxlength="30"
                        oninput="this.value = this.value.replace(/[^a-zA-Z0-9-]/g, '')"
                        class="w-full p-4 bg-gray-50 rounded-2xl outline-none font-bold" required>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[10px] font-black text-gray-400 uppercase italic ml-2">Tanggal</label>
                        <input type="date" name="expiry_date" id="edit-date"
                            class="w-full p-4 bg-gray-50 rounded-2xl outline-none font-bold text-sm" required>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-gray-400 uppercase italic ml-2">Waktu</label>
                        <input type="time" name="expiry_time" id="edit-time"
                            class="w-full p-4 bg-gray-50 rounded-2xl outline-none font-bold text-sm">
                    </div>
                </div>

                <div class="flex items-center gap-2 px-2">
                    <input type="checkbox" name="is_permanent" id="edit-permanent"
                        class="w-4 h-4 rounded border-gray-300 text-black focus:ring-black"
                        onchange="togglePermanent(this, 'edit-date', 'edit-time')">
                    <label for="edit-permanent"
                        class="text-[11px] font-bold text-gray-500 uppercase italic cursor-pointer">Permanen</label>
                </div>

                <button
                    class="w-full bg-black text-white py-5 rounded-[22px] font-black text-[12px] tracking-widest hover:bg-gray-900 shadow-lg">
                    SIMPAN PERUBAHAN
                </button>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(id, alias, date, time, isPermanent) {
            document.getElementById('edit-id').value = id;
            document.getElementById('edit-alias').value = alias;
            document.getElementById('edit-date').value = date;
            document.getElementById('edit-time').value = time;

            const permCheckbox = document.getElementById('edit-permanent');
            permCheckbox.checked = isPermanent;
            togglePermanent(permCheckbox, 'edit-date', 'edit-time');

            document.getElementById('modal-edit').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('modal-edit').classList.add('hidden');
        }
    </script>
    <div id="modal-guide" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4"
        onclick="closeGuide()">
        <div class="relative max-w-5xl w-full max-h-[90vh] bg-white rounded-3xl overflow-hidden shadow-2xl animate-[fadeIn_0.3s_ease-out]"
            onclick="event.stopPropagation()">
            <button onclick="closeGuide()"
                class="absolute top-4 right-4 w-10 h-10 bg-black/80 hover:bg-black text-white rounded-full flex items-center justify-center transition-colors z-10">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            <div class="p-6 bg-blue-500 text-white text-center">
                <h3 class="text-lg font-bold tracking-wide"><i class="fa-solid fa-circle-info mr-2"></i>Panduan
                    Pengguna</h3>
            </div>
            <div class="p-4 overflow-y-auto max-h-[70vh]">
                <img src="panduan.png?v=<?php echo filemtime('panduan.png'); ?>" alt="Panduan Pengguna"
                    class="w-full h-auto rounded-xl shadow-md">
            </div>
        </div>
    </div>
</body>

</html>