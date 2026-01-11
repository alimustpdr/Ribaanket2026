<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Http;
use App\Csrf;
use App\Db;
use App\View;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($path === '/health') {
    Http::text(200, "ok\n");
    exit;
}

function redirect(string $to): void {
    header('Location: ' . $to, true, 302);
    exit;
}

function requireSchoolAdmin(): array {
    if (!isset($_SESSION['school_admin_id'], $_SESSION['school_id'])) {
        redirect('/login');
    }
    return [
        'school_admin_id' => (int)$_SESSION['school_admin_id'],
        'school_id' => (int)$_SESSION['school_id'],
    ];
}

function requireSiteAdmin(): void {
    if (empty($_SESSION['site_admin'])) {
        redirect('/admin/login');
    }
}

if ($path === '/' && $method === 'GET') {
    $html = View::layout('RİBA', <<<HTML
<h1>RİBA Sistemi</h1>
<ul>
  <li><a href="/apply">Okul başvurusu</a></li>
  <li><a href="/login">Okul paneli girişi</a></li>
  <li><a href="/admin/login">Site yöneticisi girişi</a></li>
</ul>
<p>Sağlık kontrolü: <a href="/health">/health</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

// -------------------------
// Okul başvurusu
// -------------------------
if ($path === '/apply' && $method === 'GET') {
    $csrf = Csrf::input();
    $html = View::layout('Okul Başvurusu', <<<HTML
<h1>Okul Başvurusu</h1>
<form method="post" action="/apply">
  {$csrf}
  <p><label>Okul adı<br /><input name="school_name" required /></label></p>
  <p><label>İl<br /><input name="city" required /></label></p>
  <p><label>İlçe<br /><input name="district" required /></label></p>
  <p><label>Okul türü<br />
    <select name="school_type" required>
      <option value="okul_oncesi">Okul Öncesi</option>
      <option value="ilkokul">İlkokul</option>
      <option value="ortaokul">Ortaokul</option>
      <option value="lise">Lise</option>
    </select>
  </label></p>
  <hr />
  <p><label>Yetkili e-posta<br /><input type="email" name="admin_email" required /></label></p>
  <p><label>Şifre<br /><input type="password" name="admin_password" required /></label></p>
  <p><button type="submit">Başvuruyu gönder</button></p>
</form>
<p><a href="/">Geri</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/apply' && $method === 'POST') {
    Csrf::validatePost();

    $schoolName = trim((string)($_POST['school_name'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $district = trim((string)($_POST['district'] ?? ''));
    $schoolType = (string)($_POST['school_type'] ?? '');
    $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
    $adminPassword = (string)($_POST['admin_password'] ?? '');

    $allowedTypes = ['okul_oncesi', 'ilkokul', 'ortaokul', 'lise'];
    if ($schoolName === '' || $city === '' || $district === '' || !in_array($schoolType, $allowedTypes, true) || $adminEmail === '' || $adminPassword === '') {
        Http::text(400, "Eksik/yanlış bilgi.\n");
        exit;
    }

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO schools (name, city, district, school_type, status) VALUES (:name, :city, :district, :type, :status)');
        $stmt->execute([
            ':name' => $schoolName,
            ':city' => $city,
            ':district' => $district,
            ':type' => $schoolType,
            ':status' => 'pending',
        ]);
        $schoolId = (int)$pdo->lastInsertId();

        $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
        $stmt2 = $pdo->prepare('INSERT INTO school_admins (school_id, email, password_hash) VALUES (:school_id, :email, :password_hash)');
        $stmt2->execute([
            ':school_id' => $schoolId,
            ':email' => $adminEmail,
            ':password_hash' => $hash,
        ]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        Http::text(500, "Başvuru kaydedilemedi.\n");
        exit;
    }

    $html = View::layout('Başvuru alındı', <<<HTML
<h1>Başvurunuz alındı</h1>
<p>Okul hesabınız site yöneticisi tarafından onaylandıktan sonra giriş yapabilirsiniz.</p>
<p><a href="/login">Okul paneli girişi</a></p>
<p><a href="/">Ana sayfa</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

// -------------------------
// Okul paneli girişi
// -------------------------
if ($path === '/login' && $method === 'GET') {
    $csrf = Csrf::input();
    $html = View::layout('Okul Girişi', <<<HTML
<h1>Okul Paneli Girişi</h1>
<form method="post" action="/login">
  {$csrf}
  <p><label>E-posta<br /><input type="email" name="email" required /></label></p>
  <p><label>Şifre<br /><input type="password" name="password" required /></label></p>
  <p><button type="submit">Giriş</button></p>
</form>
<p><a href="/apply">Okul başvurusu</a></p>
<p><a href="/">Ana sayfa</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/login' && $method === 'POST') {
    Csrf::validatePost();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        Http::text(400, "Eksik bilgi.\n");
        exit;
    }

    $pdo = Db::pdo();
    $stmt = $pdo->prepare('
        SELECT sa.id AS admin_id, sa.password_hash, s.id AS school_id, s.status
        FROM school_admins sa
        JOIN schools s ON s.id = sa.school_id
        WHERE sa.email = :email
        LIMIT 1
    ');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, (string)$row['password_hash'])) {
        Http::text(401, "Giriş başarısız.\n");
        exit;
    }
    if ((string)$row['status'] !== 'active') {
        Http::text(403, "Okul hesabı henüz aktif değil.\n");
        exit;
    }

    $_SESSION['school_admin_id'] = (int)$row['admin_id'];
    $_SESSION['school_id'] = (int)$row['school_id'];
    redirect('/panel');
}

if ($path === '/logout' && $method === 'POST') {
    Csrf::validatePost();
    session_destroy();
    redirect('/');
}

if ($path === '/panel' && $method === 'GET') {
    $ctx = requireSchoolAdmin();
    $pdo = Db::pdo();
    $stmt = $pdo->prepare('SELECT id, name, city, district, school_type, status FROM schools WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $ctx['school_id']]);
    $school = $stmt->fetch();
    if (!$school) {
        Http::text(404, "Okul bulunamadı.\n");
        exit;
    }

    $csrf = Csrf::input();
    $name = View::e((string)$school['name']);
    $type = View::e((string)$school['school_type']);

    $html = View::layout('Okul Paneli', <<<HTML
<h1>Okul Paneli</h1>
<p><strong>Okul:</strong> {$name}</p>
<p><strong>Tür:</strong> {$type}</p>
<p>Bu okul türüne ait anket linkleri ve raporlama bir sonraki adımda eklenecek.</p>
<form method="post" action="/logout">{$csrf}<button type="submit">Çıkış</button></form>
HTML);
    Http::html(200, $html);
    exit;
}

// -------------------------
// Site yöneticisi (manuel onay)
// -------------------------
if ($path === '/admin/login' && $method === 'GET') {
    $csrf = Csrf::input();
    $html = View::layout('Site Yöneticisi', <<<HTML
<h1>Site Yöneticisi Girişi</h1>
<form method="post" action="/admin/login">
  {$csrf}
  <p><label>E-posta<br /><input type="email" name="email" required /></label></p>
  <p><label>Şifre<br /><input type="password" name="password" required /></label></p>
  <p><button type="submit">Giriş</button></p>
</form>
<p><a href="/">Ana sayfa</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/admin/login' && $method === 'POST') {
    Csrf::validatePost();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $adminEmail = (string)(\App\Env::get('ADMIN_EMAIL', ''));
    $adminPassword = (string)(\App\Env::get('ADMIN_PASSWORD', ''));

    if ($email === '' || $password === '' || $adminEmail === '' || $adminPassword === '') {
        Http::text(500, "Admin ayarları eksik.\n");
        exit;
    }

    if (!hash_equals($adminEmail, $email) || !hash_equals($adminPassword, $password)) {
        Http::text(401, "Giriş başarısız.\n");
        exit;
    }

    $_SESSION['site_admin'] = true;
    redirect('/admin/schools');
}

if ($path === '/admin/schools' && $method === 'GET') {
    requireSiteAdmin();
    $pdo = Db::pdo();
    $rows = $pdo->query('SELECT id, name, city, district, school_type, status, created_at FROM schools ORDER BY created_at DESC')->fetchAll();

    $csrf = Csrf::input();
    $items = '';
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $items .= '<tr>';
        $items .= '<td>' . View::e((string)$id) . '</td>';
        $items .= '<td>' . View::e((string)$r['name']) . '</td>';
        $items .= '<td>' . View::e((string)$r['city']) . '</td>';
        $items .= '<td>' . View::e((string)$r['district']) . '</td>';
        $items .= '<td>' . View::e((string)$r['school_type']) . '</td>';
        $items .= '<td>' . View::e((string)$r['status']) . '</td>';
        $items .= '<td>' . View::e((string)$r['created_at']) . '</td>';
        $items .= '<td>';
        if ((string)$r['status'] === 'pending') {
            $items .= "<form method=\"post\" action=\"/admin/schools/approve\" style=\"margin:0\">{$csrf}<input type=\"hidden\" name=\"id\" value=\"{$id}\" /><button type=\"submit\">Aktif et</button></form>";
        }
        $items .= '</td>';
        $items .= '</tr>';
    }

    $html = View::layout('Okullar', <<<HTML
<h1>Okullar</h1>
<table border="1" cellpadding="6" cellspacing="0">
  <thead>
    <tr>
      <th>ID</th><th>Okul</th><th>İl</th><th>İlçe</th><th>Tür</th><th>Durum</th><th>Başvuru</th><th>İşlem</th>
    </tr>
  </thead>
  <tbody>
    {$items}
  </tbody>
</table>
<p><a href="/">Ana sayfa</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/admin/schools/approve' && $method === 'POST') {
    requireSiteAdmin();
    Csrf::validatePost();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        Http::text(400, "Geçersiz okul.\n");
        exit;
    }
    $pdo = Db::pdo();
    $stmt = $pdo->prepare('UPDATE schools SET status = :status, activated_at = NOW() WHERE id = :id AND status = :pending');
    $stmt->execute([':status' => 'active', ':id' => $id, ':pending' => 'pending']);
    redirect('/admin/schools');
}

Http::text(404, "Bulunamadı.\n");
