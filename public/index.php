<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Http;
use App\Csrf;
use App\Db;
use App\View;
use App\RibaReport;
use App\XlsxExport;
use App\Mailer;

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

function appKey(): string {
    $key = (string)(\App\Env::get('APP_KEY', ''));
    if ($key === '') {
        Http::text(500, "APP_KEY ayarı eksik.\n");
        exit;
    }
    return $key;
}

function clientIp(): string {
    // Login yok → “tek doldurma” için en basit kaynak REMOTE_ADDR.
    // Proxy arkasında doğru IP için ayrıca ayar gerekebilir (ileride).
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function ipHash(string $formPublicId): string {
    $ip = clientIp();
    return hash('sha256', appKey() . '|' . $formPublicId . '|' . $ip);
}

/**
 * Soru metinlerini kopyalamamak için PDF dosyasını “kaynak” olarak gösteriyoruz.
 * Buradaki veriler: PDF dosya adı + madde sayısı (A/B seçim sayısı).
 */
function formSpec(string $schoolType, string $audience): ?array {
    $map = [
        'okul_oncesi' => [
            'veli' => ['pdf' => '18115003_RYBA_FORM_Veli_Okul_Oncesi.pdf', 'items' => 13],
            'ogretmen' => ['pdf' => '18174526_RYBA_FORM_OYretmen_Okul_Oncesi.pdf', 'items' => 13],
        ],
        'ilkokul' => [
            'ogrenci' => ['pdf' => '26133925_ilkokulogrenci.pdf', 'items' => 15],
            'veli' => ['pdf' => '26133946_ilkokulveli.pdf', 'items' => 13],
            'ogretmen' => ['pdf' => '26133935_ilkokulogretmen.pdf', 'items' => 16],
        ],
        'ortaokul' => [
            'ogrenci' => ['pdf' => '26134502_ortaokulogrenci.pdf', 'items' => 18],
            'veli' => ['pdf' => '26134525_ortaokulveli.pdf', 'items' => 16],
            'ogretmen' => ['pdf' => '26134514_ortaokulogretmen.pdf', 'items' => 18],
        ],
        'lise' => [
            'ogrenci' => ['pdf' => '26134751_liseogrenci.pdf', 'items' => 20],
            'veli' => ['pdf' => '26134812_liseveli.pdf', 'items' => 19],
            'ogretmen' => ['pdf' => '26134800_liseogretmen.pdf', 'items' => 19],
        ],
    ];
    return $map[$schoolType][$audience] ?? null;
}

function allowedAudiences(string $schoolType): array {
    if ($schoolType === 'okul_oncesi') {
        return ['veli', 'ogretmen'];
    }
    return ['ogrenci', 'veli', 'ogretmen'];
}

function activeCampaign(\PDO $pdo, int $schoolId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE school_id = :sid AND status = :st LIMIT 1');
    $stmt->execute([':sid' => $schoolId, ':st' => 'active']);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getCampaignById(\PDO $pdo, int $schoolId, int $campaignId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = :id AND school_id = :sid LIMIT 1');
    $stmt->execute([':id' => $campaignId, ':sid' => $schoolId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function currentCampaignForReports(\PDO $pdo, int $schoolId, ?int $requestedCampaignId): ?array {
    if ($requestedCampaignId !== null && $requestedCampaignId > 0) {
        return getCampaignById($pdo, $schoolId, $requestedCampaignId);
    }
    return activeCampaign($pdo, $schoolId);
}

function closeCampaign(\PDO $pdo, int $schoolId, int $campaignId): void {
    $pdo->prepare('UPDATE campaigns SET status = :st, closed_at = NOW() WHERE id = :id AND school_id = :sid')
        ->execute([':st' => 'closed', ':id' => $campaignId, ':sid' => $schoolId]);
    $pdo->prepare('UPDATE form_instances SET status = :st WHERE campaign_id = :cid AND school_id = :sid')
        ->execute([':st' => 'closed', ':cid' => $campaignId, ':sid' => $schoolId]);
}

/**
 * Kota dolduğu için kapanış bildirimi: kampanya başına sadece bir kez mail gönder.
 * @return bool true => mail gönderilmeli
 */
function markQuotaClosedNotified(\PDO $pdo, int $schoolId, int $campaignId): bool {
    $stmt = $pdo->prepare('
        UPDATE campaigns
        SET quota_closed_notified_at = NOW()
        WHERE id = :id AND school_id = :sid AND quota_closed_notified_at IS NULL
    ');
    $stmt->execute([':id' => $campaignId, ':sid' => $schoolId]);
    return $stmt->rowCount() === 1;
}

function notifyQuotaClosed(\PDO $pdo, int $schoolId, int $campaignId): void {
    $schoolStmt = $pdo->prepare('SELECT name FROM schools WHERE id = :id LIMIT 1');
    $schoolStmt->execute([':id' => $schoolId]);
    $schoolName = (string)($schoolStmt->fetch()['name'] ?? 'Okul');

    $campStmt = $pdo->prepare('SELECT year, name FROM campaigns WHERE id = :id AND school_id = :sid LIMIT 1');
    $campStmt->execute([':id' => $campaignId, ':sid' => $schoolId]);
    $camp = $campStmt->fetch();
    $campLabel = $camp ? ((string)$camp['year'] . ' - ' . (string)$camp['name']) : 'Anket Dönemi';

    $adminsStmt = $pdo->prepare('SELECT email FROM school_admins WHERE school_id = :sid');
    $adminsStmt->execute([':sid' => $schoolId]);
    $emails = array_map(static fn($r) => (string)$r['email'], $adminsStmt->fetchAll());

    $subject = 'RİBA: Anket kotası doldu ve anket kapandı';
    $body = "Merhaba,\n\n";
    $body .= "Okul: {$schoolName}\n";
    $body .= "Anket dönemi: {$campLabel}\n\n";
    $body .= "Bu anket dönemi için tanımlı kota dolduğu için anket linkleri kapatıldı.\n";
    $body .= "Devam etmek için:\n";
    $body .= "- Panel > Anket Dönemleri > ilgili dönem\n";
    $body .= "- Kota/paket artırın\n";
    $body .= "- 'Anket dönemini yeniden başlat' butonuna basın\n\n";
    $body .= "Bu e-posta otomatik bildirimdir.\n";

    foreach ($emails as $to) {
        Mailer::send($to, $subject, $body);
    }
}

function campaignIsWithinWindow(array $camp): bool {
    $now = time();
    $start = strtotime((string)$camp['starts_at']);
    $end = strtotime((string)$camp['ends_at']);
    if ($start !== false && $now < $start) {
        return false;
    }
    if ($end !== false && $now > $end) {
        return false;
    }
    return true;
}

function campaignUsage(\PDO $pdo, int $campaignId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM responses WHERE campaign_id = :cid');
    $stmt->execute([':cid' => $campaignId]);
    return (int)($stmt->fetch()['cnt'] ?? 0);
}

function campaignQuota(array $camp): int {
    return (int)($camp['response_quota'] ?? 0);
}

function schoolQuotaSummary(\PDO $pdo, int $campaignId): array {
    $used = campaignUsage($pdo, $campaignId);
    $campStmt = $pdo->prepare('SELECT response_quota, status, starts_at, ends_at FROM campaigns WHERE id = :id LIMIT 1');
    $campStmt->execute([':id' => $campaignId]);
    $camp = $campStmt->fetch();
    $quota = $camp ? (int)$camp['response_quota'] : 0;
    $remaining = max(0, $quota - $used);
    return ['used' => $used, 'quota' => $quota, 'remaining' => $remaining, 'status' => $camp ? (string)$camp['status'] : ''];
}

function listActiveQuotaPackages(\PDO $pdo): array {
    return $pdo->query('SELECT id, name, quota_add, price_amount, price_currency FROM quota_packages WHERE active = 1 ORDER BY quota_add ASC')->fetchAll();
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

    // last_login_at
    $pdo->prepare('UPDATE school_admins SET last_login_at = NOW() WHERE id = :id')->execute([':id' => (int)$row['admin_id']]);
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
<ul>
  <li><a href="/panel/classes">Sınıflar</a></li>
  <li><a href="/panel/campaigns">Anket Dönemleri</a></li>
  <li><a href="/panel/reports">Raporlar</a></li>
</ul>
<form method="post" action="/logout">{$csrf}<button type="submit">Çıkış</button></form>
HTML);
    Http::html(200, $html);
    exit;
}

// -------------------------
// Okul Paneli: Anket Dönemleri (yıllık süreç)
// -------------------------
if ($path === '/panel/campaigns' && $method === 'GET') {
    $ctx = requireSchoolAdmin();
    $pdo = Db::pdo();

    $schoolStmt = $pdo->prepare('SELECT id, name, school_type FROM schools WHERE id = :id LIMIT 1');
    $schoolStmt->execute([':id' => $ctx['school_id']]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        Http::notFound();
        exit;
    }
    $schoolName = View::e((string)$school['name']);

    $rows = $pdo->prepare('SELECT id, year, name, status, starts_at, ends_at, response_quota, created_at FROM campaigns WHERE school_id = :sid ORDER BY year DESC');
    $rows->execute([':sid' => $ctx['school_id']]);
    $campaigns = $rows->fetchAll();

    $items = '';
    foreach ($campaigns as $c) {
        $id = (int)$c['id'];
        $items .= '<tr>';
        $items .= '<td>' . View::e((string)$c['year']) . '</td>';
        $items .= '<td>' . View::e((string)$c['name']) . '</td>';
        $items .= '<td>' . View::e((string)$c['status']) . '</td>';
        $items .= '<td>' . View::e((string)$c['starts_at']) . '</td>';
        $items .= '<td>' . View::e((string)$c['ends_at']) . '</td>';
        $items .= '<td>' . View::e((string)$c['response_quota']) . '</td>';
        $items .= '<td>';
        $items .= '<a href="/panel/campaigns/' . $id . '">Detay</a>';
        $items .= '</td>';
        $items .= '</tr>';
    }

    $csrf = Csrf::input();
    $defaultYear = (int)date('Y');
    $now = date('Y-m-d\\TH:i');
    $nextYear = date('Y-m-d\\TH:i', time() + 3600 * 24 * 365);
    $html = View::layout('Anket Dönemleri', <<<HTML
<h1>Anket Dönemleri</h1>
<p><strong>Okul:</strong> {$schoolName}</p>
<p>Her yıl için ayrı anket dönemi oluşturulur. Linkler dönem aktifken çalışır; kota bitince otomatik kapanır.</p>
<table border="1" cellpadding="6" cellspacing="0">
  <thead><tr><th>Yıl</th><th>Ad</th><th>Durum</th><th>Başlangıç</th><th>Bitiş</th><th>Kota</th><th></th></tr></thead>
  <tbody>{$items}</tbody>
</table>
<hr />
<h2>Yeni anket dönemi oluştur</h2>
<form method="post" action="/panel/campaigns/create">
  {$csrf}
  <p><label>Yıl<br /><input type="number" name="year" value="{$defaultYear}" required /></label></p>
  <p><label>Ad<br /><input name="name" value="RİBA {$defaultYear}" required /></label></p>
  <p><label>Başlangıç<br /><input type="datetime-local" name="starts_at" value="{$now}" required /></label></p>
  <p><label>Bitiş<br /><input type="datetime-local" name="ends_at" value="{$nextYear}" required /></label></p>
  <p><label>Yıllık toplam yanıt kotası (öğrenci+veli+öğretmen toplamı)<br /><input type="number" name="response_quota" placeholder="Örn: 1100" required /></label></p>
  <p><button type="submit">Oluştur</button></p>
</form>
<p><a href="/panel">Geri</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/panel/campaigns/create' && $method === 'POST') {
    $ctx = requireSchoolAdmin();
    Csrf::validatePost();
    $year = (int)($_POST['year'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $startsAt = (string)($_POST['starts_at'] ?? '');
    $endsAt = (string)($_POST['ends_at'] ?? '');
    $quota = (int)($_POST['response_quota'] ?? 0);

    if ($year <= 0 || $name === '' || $startsAt === '' || $endsAt === '' || $quota <= 0) {
        Http::badRequest("Eksik/yanlış bilgi.\n");
        exit;
    }

    $pdo = Db::pdo();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO campaigns (school_id, year, name, status, starts_at, ends_at, response_quota)
            VALUES (:sid, :year, :name, :status, :starts_at, :ends_at, :quota)
        ');
        $stmt->execute([
            ':sid' => $ctx['school_id'],
            ':year' => $year,
            ':name' => $name,
            ':status' => 'draft',
            ':starts_at' => str_replace('T', ' ', $startsAt) . ':00',
            ':ends_at' => str_replace('T', ' ', $endsAt) . ':00',
            ':quota' => $quota,
        ]);
    } catch (\Throwable $e) {
        Http::text(500, "Anket dönemi oluşturulamadı (aynı yıl zaten var olabilir).\n");
        exit;
    }
    redirect('/panel/campaigns');
}

if (preg_match('#^/panel/campaigns/(\\d+)$#', $path, $m) && $method === 'GET') {
    $ctx = requireSchoolAdmin();
    $campaignId = (int)$m[1];
    $pdo = Db::pdo();

    $campStmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = :id AND school_id = :sid LIMIT 1');
    $campStmt->execute([':id' => $campaignId, ':sid' => $ctx['school_id']]);
    $camp = $campStmt->fetch();
    if (!$camp) {
        Http::notFound();
        exit;
    }

    $usedStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM responses WHERE campaign_id = :cid');
    $usedStmt->execute([':cid' => $campaignId]);
    $used = (int)($usedStmt->fetch()['cnt'] ?? 0);
    $quota = (int)$camp['response_quota'];
    $remaining = max(0, $quota - $used);

    $csrf = Csrf::input();
    $status = View::e((string)$camp['status']);
    $name = View::e((string)$camp['name']);
    $startsAt = View::e((string)$camp['starts_at']);
    $endsAt = View::e((string)$camp['ends_at']);

    $actions = '';
    if ((string)$camp['status'] === 'draft') {
        $actions .= "<form method=\"post\" action=\"/panel/campaigns/{$campaignId}/activate\">{$csrf}<button type=\"submit\">Anket dönemini başlat (aktif et) ve linkleri üret</button></form>";
    } elseif ((string)$camp['status'] === 'active') {
        $actions .= "<form method=\"post\" action=\"/panel/campaigns/{$campaignId}/close\">{$csrf}<button type=\"submit\">Anket dönemini kapat</button></form>";
    } elseif ((string)$camp['status'] === 'closed') {
        $actions .= "<form method=\"post\" action=\"/panel/campaigns/{$campaignId}/reopen\">{$csrf}<button type=\"submit\">Anket dönemini yeniden başlat</button></form>";
    }

    $editForm = '';
    if (true) {
        // Not: datetime-local format
        $startsVal = str_replace(' ', 'T', substr((string)$camp['starts_at'], 0, 16));
        $endsVal = str_replace(' ', 'T', substr((string)$camp['ends_at'], 0, 16));
        $quotaVal = (int)$camp['response_quota'];
        $editForm = <<<HTML
<hr />
<h2>Dönem ayarlarını güncelle</h2>
<form method="post" action="/panel/campaigns/{$campaignId}/update">
  {$csrf}
  <p><label>Başlangıç<br /><input type="datetime-local" name="starts_at" value="{$startsVal}" required /></label></p>
  <p><label>Bitiş<br /><input type="datetime-local" name="ends_at" value="{$endsVal}" required /></label></p>
  <p><label>Kota<br /><input type="number" name="response_quota" value="{$quotaVal}" required /></label></p>
  <p><button type="submit">Güncelle</button></p>
</form>
HTML;
    }

    $html = View::layout('Anket Dönemi', <<<HTML
<h1>Anket Dönemi</h1>
<p><strong>Ad:</strong> {$name}</p>
<p><strong>Durum:</strong> {$status}</p>
<p><strong>Başlangıç:</strong> {$startsAt}</p>
<p><strong>Bitiş:</strong> {$endsAt}</p>
<p><strong>Kota:</strong> {$quota} | <strong>Kullanım:</strong> {$used} | <strong>Kalan:</strong> {$remaining}</p>
{$actions}
{$editForm}
<hr />
<p><a href="/panel/quota?campaign_id={$campaignId}">Ek kota satın al / talep et</a></p>
<p><a href="/panel/campaigns">Geri</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if (preg_match('#^/panel/campaigns/(\\d+)/activate$#', $path, $m) && $method === 'POST') {
    $ctx = requireSchoolAdmin();
    Csrf::validatePost();
    $campaignId = (int)$m[1];
    $pdo = Db::pdo();

    $pdo->beginTransaction();
    try {
        // Anket dönemi doğrula
        $campStmt = $pdo->prepare('SELECT id, status, starts_at, ends_at FROM campaigns WHERE id = :id AND school_id = :sid LIMIT 1');
        $campStmt->execute([':id' => $campaignId, ':sid' => $ctx['school_id']]);
        $camp = $campStmt->fetch();
        if (!$camp) {
            $pdo->rollBack();
            Http::notFound();
            exit;
        }
        if ((string)$camp['status'] !== 'draft') {
            $pdo->rollBack();
            Http::badRequest("Sadece taslak anket dönemi başlatılabilir.\n");
            exit;
        }

        // Aynı okulda aktif başka anket dönemi varsa kapat (yıllık süreçlerin karışmaması için)
        $pdo->prepare('UPDATE campaigns SET status = :closed, closed_at = NOW() WHERE school_id = :sid AND status = :active')
            ->execute([':closed' => 'closed', ':sid' => $ctx['school_id'], ':active' => 'active']);

        // Bu anket dönemini aktif et
        $pdo->prepare('UPDATE campaigns SET status = :st, activated_at = NOW() WHERE id = :id')
            ->execute([':st' => 'active', ':id' => $campaignId]);

        // Okul türü ve sınıflar
        $schoolTypeStmt = $pdo->prepare('SELECT school_type FROM schools WHERE id = :id LIMIT 1');
        $schoolTypeStmt->execute([':id' => $ctx['school_id']]);
        $schoolType = (string)($schoolTypeStmt->fetch()['school_type'] ?? '');

        $classesStmt = $pdo->prepare('SELECT id FROM classes WHERE school_id = :sid');
        $classesStmt->execute([':sid' => $ctx['school_id']]);
        $classIds = array_map(static fn($r) => (int)$r['id'], $classesStmt->fetchAll());

        $ins = $pdo->prepare('
            INSERT INTO form_instances (school_id, campaign_id, class_id, audience, public_id, status)
            VALUES (:sid, :camp, :cid, :aud, :pid, :st)
        ');
        foreach ($classIds as $cid) {
            foreach (allowedAudiences($schoolType) as $aud) {
                $pid = bin2hex(random_bytes(16));
                $ins->execute([
                    ':sid' => $ctx['school_id'],
                    ':camp' => $campaignId,
                    ':cid' => $cid,
                    ':aud' => $aud,
                    ':pid' => $pid,
                    ':st' => 'active',
                ]);
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        Http::text(500, "Anket dönemi başlatılamadı.\n");
        exit;
    }

    redirect('/panel/campaigns/' . $campaignId);
}

if (preg_match('#^/panel/campaigns/(\\d+)/close$#', $path, $m) && $method === 'POST') {
    $ctx = requireSchoolAdmin();
    Csrf::validatePost();
    $campaignId = (int)$m[1];
    $pdo = Db::pdo();

    $pdo->beginTransaction();
    try {
        $campStmt = $pdo->prepare('SELECT id, status FROM campaigns WHERE id = :id AND school_id = :sid LIMIT 1');
        $campStmt->execute([':id' => $campaignId, ':sid' => $ctx['school_id']]);
        $camp = $campStmt->fetch();
        if (!$camp) {
            $pdo->rollBack();
            Http::notFound();
            exit;
        }
        if ((string)$camp['status'] !== 'active') {
            $pdo->rollBack();
            Http::badRequest("Sadece aktif anket dönemi kapatılabilir.\n");
            exit;
        }

        $pdo->prepare('UPDATE campaigns SET status = :st, closed_at = NOW() WHERE id = :id')
            ->execute([':st' => 'closed', ':id' => $campaignId]);
        $pdo->prepare('UPDATE form_instances SET status = :st WHERE campaign_id = :cid')
            ->execute([':st' => 'closed', ':cid' => $campaignId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        Http::text(500, "Anket dönemi kapatılamadı.\n");
        exit;
    }

    redirect('/panel/campaigns/' . $campaignId);
}

if (preg_match('#^/panel/campaigns/(\\d+)/update$#', $path, $m) && $method === 'POST') {
    $ctx = requireSchoolAdmin();
    Csrf::validatePost();
    $campaignId = (int)$m[1];
    $startsAt = (string)($_POST['starts_at'] ?? '');
    $endsAt = (string)($_POST['ends_at'] ?? '');
    $quota = (int)($_POST['response_quota'] ?? 0);
    if ($startsAt === '' || $endsAt === '' || $quota <= 0) {
        Http::badRequest("Eksik/yanlış bilgi.\n");
        exit;
    }
    $pdo = Db::pdo();
    $stmt = $pdo->prepare('
        UPDATE campaigns
        SET starts_at = :starts_at, ends_at = :ends_at, response_quota = :quota
        WHERE id = :id AND school_id = :sid
    ');
    $stmt->execute([
        ':starts_at' => str_replace('T', ' ', $startsAt) . ':00',
        ':ends_at' => str_replace('T', ' ', $endsAt) . ':00',
        ':quota' => $quota,
        ':id' => $campaignId,
        ':sid' => $ctx['school_id'],
    ]);
    redirect('/panel/campaigns/' . $campaignId);
}

if (preg_match('#^/panel/campaigns/(\\d+)/reopen$#', $path, $m) && $method === 'POST') {
    $ctx = requireSchoolAdmin();
    Csrf::validatePost();
    $campaignId = (int)$m[1];
    $pdo = Db::pdo();

    $pdo->beginTransaction();
    try {
        $campStmt = $pdo->prepare('SELECT id, school_id, status, starts_at, ends_at, response_quota FROM campaigns WHERE id = :id FOR UPDATE');
        $campStmt->execute([':id' => $campaignId]);
        $camp = $campStmt->fetch();
        if (!$camp || (int)$camp['school_id'] !== (int)$ctx['school_id']) {
            $pdo->rollBack();
            Http::notFound();
            exit;
        }
        if ((string)$camp['status'] !== 'closed') {
            $pdo->rollBack();
            Http::badRequest("Sadece kapalı anket dönemi yeniden başlatılabilir.\n");
            exit;
        }
        if (!campaignIsWithinWindow($camp)) {
            $pdo->rollBack();
            Http::forbidden("Anket dönemi tarih aralığı dışında. Önce başlangıç/bitiş tarihini güncelleyin.\n");
            exit;
        }

        $quota = (int)$camp['response_quota'];
        $used = campaignUsage($pdo, $campaignId);
        if ($quota > 0 && $used >= $quota) {
            $pdo->rollBack();
            Http::forbidden("Kota hâlâ dolu. Önce kotayı artırın, sonra yeniden başlatın.\n");
            exit;
        }

        // Aynı okulda aktif başka dönem varsa kapat
        $pdo->prepare('UPDATE campaigns SET status = :closed, closed_at = NOW() WHERE school_id = :sid AND status = :active')
            ->execute([':closed' => 'closed', ':sid' => $ctx['school_id'], ':active' => 'active']);

        // Bu dönemi aktif et
        $pdo->prepare('UPDATE campaigns SET status = :st, activated_at = NOW() WHERE id = :id')
            ->execute([':st' => 'active', ':id' => $campaignId]);

        // Linkleri tekrar aç
        $pdo->prepare('UPDATE form_instances SET status = :st WHERE campaign_id = :cid AND school_id = :sid')
            ->execute([':st' => 'active', ':cid' => $campaignId, ':sid' => $ctx['school_id']]);

        $pdo->commit();
    } catch (\\Throwable $e) {
        $pdo->rollBack();
        Http::text(500, "Anket dönemi yeniden başlatılamadı.\n");
        exit;
    }

    redirect('/panel/campaigns/' . $campaignId);
}

// -------------------------
// Okul Paneli: Ek kota (paket satın alma / talep)
// -------------------------
if ($path === '/panel/quota' && $method === 'GET') {
    $ctx = requireSchoolAdmin();
    $pdo = Db::pdo();

    $campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
    if ($campaignId <= 0) {
        Http::badRequest("campaign_id gerekli.\n");
        exit;
    }
    $camp = getCampaignById($pdo, (int)$ctx['school_id'], $campaignId);
    if (!$camp) {
        Http::notFound();
        exit;
    }
    $campName = View::e((string)$camp['name']);
    $campYear = View::e((string)$camp['year']);

    $sum = schoolQuotaSummary($pdo, $campaignId);
    $csrf = Csrf::input();
    $packages = listActiveQuotaPackages($pdo);

    $pkgOptions = '';
    foreach ($packages as $p) {
        $id = (int)$p['id'];
        $label = (string)$p['name'] . ' (+'. (int)$p['quota_add'] . ' yanıt)';
        if (!empty($p['price_amount']) && !empty($p['price_currency'])) {
            $label .= ' - ' . (int)$p['price_amount'] . ' ' . (string)$p['price_currency'];
        }
        $pkgOptions .= '<option value="' . $id . '">' . View::e($label) . '</option>';
    }

    $note = '<p><strong>Not:</strong> Online ödeme altyapısı (iyzico/paytr vb.) entegrasyonu yapılmadan, “online” siparişin otomatik “ödendi” sayılması mümkün değildir. Şu an sipariş oluşturulur; ödeme/tahsilat sonrası ya siz (site yöneticisi) onaylarsınız ya da ileride ödeme entegrasyonu ile otomatikleşir.</p>';

    $html = View::layout('Ek Kota', <<<HTML
<h1>Ek Kota</h1>
<p><strong>Anket dönemi:</strong> {$campYear} - {$campName}</p>
<p><strong>Kota:</strong> {$sum['quota']} | <strong>Kullanım:</strong> {$sum['used']} | <strong>Kalan:</strong> {$sum['remaining']}</p>
{$note}
<form method="post" action="/panel/quota/order">
  {$csrf}
  <input type="hidden" name="campaign_id" value="{$campaignId}" />
  <p><label>Paket<br />
    <select name="package_id" required>
      {$pkgOptions}
    </select>
  </label></p>
  <p><label>Yöntem<br />
    <select name="method" required>
      <option value="online">Online (kullanıcı kendi alır)</option>
      <option value="manual">Nakit / yönetici tanımlasın</option>
    </select>
  </label></p>
  <p><label>Not (opsiyonel)<br /><input name="note" /></label></p>
  <p><button type="submit">Sipariş oluştur</button></p>
</form>
<p><a href="/panel/campaigns/{$campaignId}">Geri</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/panel/quota/order' && $method === 'POST') {
    $ctx = requireSchoolAdmin();
    Csrf::validatePost();
    $pdo = Db::pdo();

    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    $packageId = (int)($_POST['package_id'] ?? 0);
    $methodVal = (string)($_POST['method'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));

    if ($campaignId <= 0 || $packageId <= 0 || ($methodVal !== 'online' && $methodVal !== 'manual')) {
        Http::badRequest("Eksik/yanlış bilgi.\n");
        exit;
    }
    $camp = getCampaignById($pdo, (int)$ctx['school_id'], $campaignId);
    if (!$camp) {
        Http::notFound();
        exit;
    }

    $pkgStmt = $pdo->prepare('SELECT id, quota_add, active FROM quota_packages WHERE id = :id LIMIT 1');
    $pkgStmt->execute([':id' => $packageId]);
    $pkg = $pkgStmt->fetch();
    if (!$pkg || (int)$pkg['active'] !== 1) {
        Http::badRequest("Paket bulunamadı/aktif değil.\n");
        exit;
    }
    $quotaAdd = (int)$pkg['quota_add'];

    $stmt = $pdo->prepare('
        INSERT INTO quota_orders (school_id, campaign_id, package_id, method, status, quota_add, note)
        VALUES (:sid, :cid, :pid, :m, :st, :qa, :note)
    ');
    $stmt->execute([
        ':sid' => (int)$ctx['school_id'],
        ':cid' => $campaignId,
        ':pid' => $packageId,
        ':m' => $methodVal,
        ':st' => 'pending',
        ':qa' => $quotaAdd,
        ':note' => ($note === '' ? null : $note),
    ]);

    $html = View::layout('Sipariş alındı', <<<HTML
<h1>Ek kota siparişi oluşturuldu</h1>
<p>Siparişiniz kaydedildi. Ödeme/tahsilat sonrası kota “anında” sisteme yansıtılır.</p>
<p>Not: Anket dönemi otomatik açılmaz; kota artırıldıktan sonra <strong>Anket Dönemleri</strong> ekranından “yeniden başlat” butonuna basmalısınız.</p>
<p><a href="/panel/campaigns/{$campaignId}">Anket dönemine dön</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

// -------------------------
// Okul Paneli: Raporlar (Excel çıktı)
// -------------------------
if ($path === '/panel/reports' && $method === 'GET') {
    $ctx = requireSchoolAdmin();
    $pdo = Db::pdo();
    $schoolStmt = $pdo->prepare('SELECT id, name, school_type FROM schools WHERE id = :id LIMIT 1');
    $schoolStmt->execute([':id' => $ctx['school_id']]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        Http::notFound();
        exit;
    }
    $csrf = Csrf::input();
    $name = View::e((string)$school['name']);
    $requestedCampaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;

    $campaignsStmt = $pdo->prepare('SELECT id, year, name, status FROM campaigns WHERE school_id = :sid ORDER BY year DESC');
    $campaignsStmt->execute([':sid' => $ctx['school_id']]);
    $campaigns = $campaignsStmt->fetchAll();

    $options = '<option value="">(Aktif dönem)</option>';
    foreach ($campaigns as $c) {
        $id = (int)$c['id'];
        $label = (string)$c['year'] . ' - ' . (string)$c['name'] . ' [' . (string)$c['status'] . ']';
        $sel = ($requestedCampaignId !== null && $requestedCampaignId === $id) ? ' selected' : '';
        $options .= '<option value="' . $id . '"' . $sel . '>' . View::e($label) . '</option>';
    }

    $viewLink = '/panel/reports/view';
    if ($requestedCampaignId !== null && $requestedCampaignId > 0) {
        $viewLink .= '?campaign_id=' . $requestedCampaignId;
    }

    $html = View::layout('Raporlar', <<<HTML
<h1>Raporlar</h1>
<p><strong>Okul:</strong> {$name}</p>
<ul>
  <li><a href="{$viewLink}">Web üzerinde görüntüle</a></li>
</ul>
<p>Excel çıktı formatı: <code>riba_light_report_output.xlsx</code> (ASP + dağılım).</p>
<h2>Anket dönemi seç</h2>
<form method="get" action="/panel/reports">
  <select name="campaign_id">{$options}</select>
  <button type="submit">Seç</button>
</form>
<form method="post" action="/panel/reports/export">
  {$csrf}
  <input type="hidden" name="campaign_id" value="{$requestedCampaignId}" />
  <button type="submit">Okul raporunu indir (Excel)</button>
</form>
<p><a href="/panel">Geri</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/panel/reports/view' && $method === 'GET') {
    $ctx = requireSchoolAdmin();
    $pdo = Db::pdo();

    $schoolStmt = $pdo->prepare('SELECT id, name, school_type FROM schools WHERE id = :id LIMIT 1');
    $schoolStmt->execute([':id' => $ctx['school_id']]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        Http::notFound();
        exit;
    }
    $schoolType = (string)$school['school_type'];
    $schoolName = View::e((string)$school['name']);

    $requestedCampaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;
    $camp = currentCampaignForReports($pdo, (int)$ctx['school_id'], $requestedCampaignId);
    if (!$camp) {
        Http::text(400, "Görüntülenecek anket dönemi bulunamadı.\n");
        exit;
    }
    $campaignId = (int)$camp['id'];
    $campName = View::e((string)$camp['name']);
    $campYear = View::e((string)$camp['year']);

    $scoring = RibaReport::scoring();
    $cfg = $scoring[$schoolType] ?? null;
    if (!is_array($cfg)) {
        Http::text(500, "Bu okul türü için puanlama bulunamadı.\n");
        exit;
    }
    $rsList = $cfg['rs_list'] ?? [];
    $targets = $cfg['targets'] ?? [];

    $classesStmt = $pdo->prepare('SELECT id, name FROM classes WHERE school_id = :sid ORDER BY created_at ASC');
    $classesStmt->execute([':sid' => $ctx['school_id']]);
    $classes = $classesStmt->fetchAll();

    $classAspByClassId = [];
    foreach ($classes as $c) {
        $cid = (int)$c['id'];
        $res = RibaReport::classAsp($pdo, $schoolType, (int)$ctx['school_id'], $campaignId, $cid);
        $classAspByClassId[$cid] = $res['asp'];
    }
    $schoolAsp = RibaReport::schoolAspAverage($classAspByClassId, $rsList);

    $rowsHtml = '';
    foreach ($rsList as $rs) {
        $t = View::e((string)($targets[$rs] ?? ''));
        $v = $schoolAsp[$rs] ?? null;
        $vv = ($v === null) ? '-' : number_format((float)$v, 2, '.', '');
        $rowsHtml .= '<tr><td>' . (int)$rs . '</td><td>' . $t . '</td><td>' . $vv . '</td></tr>';
    }

    $classList = '';
    foreach ($classes as $c) {
        $cid = (int)$c['id'];
        $cname = View::e((string)$c['name']);
        $classList .= '<li><a href="/panel/classes/' . $cid . '/view?campaign_id=' . $campaignId . '">' . $cname . ' (web)</a> — ';
        $classList .= '<a href="/panel/classes/' . $cid . '/report?campaign_id=' . $campaignId . '" target="_blank" rel="noopener">Excel</a></li>';
    }

    $html = View::layout('Okul Raporu', <<<HTML
<h1>Okul Raporu (Web)</h1>
<p><strong>Okul:</strong> {$schoolName}</p>
<p><strong>Tür:</strong> <code>{$schoolType}</code></p>
<p><strong>Anket dönemi:</strong> {$campYear} - {$campName}</p>
<h2>ASP (Okul ortalaması)</h2>
<table border="1" cellpadding="6" cellspacing="0">
  <thead><tr><th>RS</th><th>Hedef</th><th>ASP</th></tr></thead>
  <tbody>{$rowsHtml}</tbody>
</table>
<h2>Sınıflar</h2>
<ul>{$classList}</ul>
<p><a href="/panel/reports?campaign_id={$campaignId}">Geri</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/panel/reports/export' && $method === 'POST') {
    $ctx = requireSchoolAdmin();
    Csrf::validatePost();
    $requestedCampaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : null;

    $pdo = Db::pdo();
    $schoolStmt = $pdo->prepare('SELECT id, name, school_type FROM schools WHERE id = :id LIMIT 1');
    $schoolStmt->execute([':id' => $ctx['school_id']]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        Http::notFound();
        exit;
    }
    $schoolType = (string)$school['school_type'];
    $schoolName = (string)$school['name'];

    $camp = currentCampaignForReports($pdo, (int)$ctx['school_id'], $requestedCampaignId);
    if (!$camp) {
        Http::text(400, "İndirilecek anket dönemi bulunamadı.\n");
        exit;
    }
    $campaignId = (int)$camp['id'];

    $scoring = RibaReport::scoring();
    $cfg = $scoring[$schoolType] ?? null;
    if (!is_array($cfg)) {
        Http::text(500, "Bu okul türü için puanlama bulunamadı.\n");
        exit;
    }
    $rsList = $cfg['rs_list'] ?? [];
    $targets = $cfg['targets'] ?? [];

    // sınıflar
    $classesStmt = $pdo->prepare('SELECT id, name FROM classes WHERE school_id = :sid ORDER BY created_at ASC');
    $classesStmt->execute([':sid' => $ctx['school_id']]);
    $classes = $classesStmt->fetchAll();

    // class ASP
    $classAspByClassId = [];
    foreach ($classes as $c) {
        $cid = (int)$c['id'];
        $res = RibaReport::classAsp($pdo, $schoolType, (int)$ctx['school_id'], $campaignId, $cid);
        $classAspByClassId[$cid] = $res['asp'];
    }
    $schoolAsp = RibaReport::schoolAspAverage($classAspByClassId, $rsList);

    // report rows (class + school)
    $reportRows = [];
    foreach ($classes as $c) {
        $cid = (int)$c['id'];
        $cname = (string)$c['name'];
        $aspByRs = $classAspByClassId[$cid] ?? [];
        foreach ($rsList as $rs) {
            $reportRows[] = [
                'level' => 'class',
                'grade' => $schoolType,
                'class_no' => $cname,
                'audience' => 'combined',
                'rs' => (int)$rs,
                'target' => (string)($targets[$rs] ?? ''),
                'asp' => $aspByRs[$rs] ?? null,
            ];
        }
    }
    foreach ($rsList as $rs) {
        $reportRows[] = [
            'level' => 'school',
            'grade' => $schoolType,
            'class_no' => '',
            'audience' => 'combined',
            'rs' => (int)$rs,
            'target' => (string)($targets[$rs] ?? ''),
            'asp' => $schoolAsp[$rs] ?? null,
        ];
    }

    // distribution rows (class + school)
    $distRows = [];
    $schoolAgg = []; // audience -> item_no -> counts

    foreach ($classes as $c) {
        $cid = (int)$c['id'];
        $cname = (string)$c['name'];
        $forms = RibaReport::classFormInstances($pdo, (int)$ctx['school_id'], $campaignId, $cid);

        foreach ($forms as $aud => $formId) {
            $dist = RibaReport::distributionForForm($pdo, (int)$formId);
            $counts = $dist['counts'];

            // class rows: sadece gelen item'ları yaz (performans)
            foreach ($counts as $itemNo => $ab) {
                $total = (int)$ab['total'];
                $pctA = $total > 0 ? ((int)$ab['A'] / $total) : null;
                $pctB = $total > 0 ? ((int)$ab['B'] / $total) : null;
                $distRows[] = [
                    'level' => 'class',
                    'grade' => $schoolType,
                    'class_no' => $cname,
                    'audience' => $aud,
                    'item_no' => (int)$itemNo,
                    'count_a' => (int)$ab['A'],
                    'count_b' => (int)$ab['B'],
                    'count_total' => $total,
                    'pct_a' => $pctA,
                    'pct_b' => $pctB,
                ];

                // school aggregate
                if (!isset($schoolAgg[$aud])) {
                    $schoolAgg[$aud] = [];
                }
                if (!isset($schoolAgg[$aud][$itemNo])) {
                    $schoolAgg[$aud][$itemNo] = ['A' => 0, 'B' => 0, 'total' => 0];
                }
                $schoolAgg[$aud][$itemNo]['A'] += (int)$ab['A'];
                $schoolAgg[$aud][$itemNo]['B'] += (int)$ab['B'];
                $schoolAgg[$aud][$itemNo]['total'] += $total;
            }
        }
    }

    foreach ($schoolAgg as $aud => $items) {
        foreach ($items as $itemNo => $ab) {
            $total = (int)$ab['total'];
            $pctA = $total > 0 ? ((int)$ab['A'] / $total) : null;
            $pctB = $total > 0 ? ((int)$ab['B'] / $total) : null;
            $distRows[] = [
                'level' => 'school',
                'grade' => $schoolType,
                'class_no' => '',
                'audience' => $aud,
                'item_no' => (int)$itemNo,
                'count_a' => (int)$ab['A'],
                'count_b' => (int)$ab['B'],
                'count_total' => $total,
                'pct_a' => $pctA,
                'pct_b' => $pctB,
            ];
        }
    }

    $root = dirname(__DIR__);
    $template = $root . '/riba_light_report_output.xlsx';
    $safeName = preg_replace('/[^a-zA-Z0-9_\\-]+/u', '_', $schoolName) ?: 'school';
    $downloadName = 'riba_report_' . $safeName . '_' . date('Y-m-d_His') . '.xlsx';
    XlsxExport::downloadFromTemplate($template, $downloadName, $reportRows, $distRows);
}

// -------------------------
// Okul Paneli: Sınıflar
// -------------------------
if ($path === '/panel/classes' && $method === 'GET') {
    $ctx = requireSchoolAdmin();
    $pdo = Db::pdo();

    $school = $pdo->prepare('SELECT id, school_type FROM schools WHERE id = :id LIMIT 1');
    $school->execute([':id' => $ctx['school_id']]);
    $schoolRow = $school->fetch();
    if (!$schoolRow) {
        Http::notFound();
        exit;
    }

    $activeCamp = activeCampaign($pdo, (int)$ctx['school_id']);
    $campNote = '';
    if (!$activeCamp) {
        $campNote = '<p><strong>Uyarı:</strong> Aktif anket dönemi yok. Link üretmek için önce <a href="/panel/campaigns">anket dönemini</a> başlatın.</p>';
    } else {
        $campNote = '<p><strong>Aktif anket dönemi:</strong> ' . View::e((string)$activeCamp['name']) . ' (' . View::e((string)$activeCamp['year']) . ')</p>';
    }

    $rows = $pdo->prepare('SELECT id, name, created_at FROM classes WHERE school_id = :sid ORDER BY created_at DESC');
    $rows->execute([':sid' => $ctx['school_id']]);
    $classes = $rows->fetchAll();

    $items = '';
    foreach ($classes as $c) {
        $id = (int)$c['id'];
        $items .= '<li>';
        $items .= View::e((string)$c['name']) . ' ';
        $items .= '<a href="/panel/classes/' . $id . '">Anket linkleri</a>';
        $items .= '</li>';
    }

    $csrf = Csrf::input();
    $html = View::layout('Sınıflar', <<<HTML
<h1>Sınıflar</h1>
{$campNote}
<ul>{$items}</ul>
<hr />
<h2>Yeni sınıf ekle</h2>
<form method="post" action="/panel/classes/create">
  {$csrf}
  <p><label>Sınıf adı (örn: 5/A, 8-B)<br /><input name="name" required /></label></p>
  <p><button type="submit">Ekle</button></p>
</form>
<p><a href="/panel">Geri</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/panel/classes/create' && $method === 'POST') {
    $ctx = requireSchoolAdmin();
    Csrf::validatePost();
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        Http::badRequest("Sınıf adı gerekli.\n");
        exit;
    }

    $pdo = Db::pdo();
    try {
        $stmt = $pdo->prepare('INSERT INTO classes (school_id, name) VALUES (:sid, :name)');
        $stmt->execute([':sid' => $ctx['school_id'], ':name' => $name]);
    } catch (\Throwable $e) {
        Http::text(500, "Sınıf eklenemedi.\n");
        exit;
    }

    redirect('/panel/classes');
}

if (preg_match('#^/panel/classes/(\\d+)$#', $path, $m) && $method === 'GET') {
    $ctx = requireSchoolAdmin();
    $classId = (int)$m[1];
    $pdo = Db::pdo();

    $schoolStmt = $pdo->prepare('SELECT id, school_type FROM schools WHERE id = :id LIMIT 1');
    $schoolStmt->execute([':id' => $ctx['school_id']]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        Http::notFound();
        exit;
    }
    $schoolType = (string)$school['school_type'];

    $classStmt = $pdo->prepare('SELECT id, name FROM classes WHERE id = :cid AND school_id = :sid LIMIT 1');
    $classStmt->execute([':cid' => $classId, ':sid' => $ctx['school_id']]);
    $class = $classStmt->fetch();
    if (!$class) {
        Http::notFound();
        exit;
    }

    $activeCamp = activeCampaign($pdo, (int)$ctx['school_id']);
    if (!$activeCamp) {
        $cname = View::e((string)$class['name']);
        $html = View::layout('Anket Linkleri', <<<HTML
<h1>Anket Linkleri</h1>
<p><strong>Sınıf:</strong> {$cname}</p>
<p><strong>Uyarı:</strong> Aktif anket dönemi yok. Link üretmek için <a href="/panel/campaigns">anket dönemini</a> başlatın.</p>
<p><a href="/panel/classes">Geri</a></p>
HTML);
        Http::html(200, $html);
        exit;
    }
    $campaignId = (int)$activeCamp['id'];
    $campName = View::e((string)$activeCamp['name']);

    $formsStmt = $pdo->prepare('
        SELECT id, audience, public_id, status
        FROM form_instances
        WHERE class_id = :cid AND school_id = :sid AND campaign_id = :camp
        ORDER BY audience
    ');
    $formsStmt->execute([':cid' => $classId, ':sid' => $ctx['school_id'], ':camp' => $campaignId]);
    $forms = $formsStmt->fetchAll();

    $base = (string)($_SERVER['HTTP_HOST'] ?? '');
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $base !== '' ? ($proto . '://' . $base) : '';

    $rows = '';
    foreach ($forms as $f) {
        $aud = (string)$f['audience'];
        $spec = formSpec($schoolType, $aud);
        if ($spec === null) {
            // Bu okul türünde bu hedef kitle yoksa göstermeyelim.
            continue;
        }
        $pid = (string)$f['public_id'];
        $link = '/f/' . $pid;
        $full = $baseUrl !== '' ? ($baseUrl . $link) : $link;
        $rows .= '<tr>';
        $rows .= '<td>' . View::e($aud) . '</td>';
        $rows .= '<td><a href="' . View::e($link) . '" target="_blank" rel="noopener">Aç</a></td>';
        $rows .= '<td><code>' . View::e($full) . '</code></td>';
        $rows .= '</tr>';
    }

    $cname = View::e((string)$class['name']);
    $html = View::layout('Anket Linkleri', <<<HTML
<h1>Anket Linkleri</h1>
<p><strong>Sınıf:</strong> {$cname}</p>
<p><strong>Anket dönemi:</strong> {$campName}</p>
<table border="1" cellpadding="6" cellspacing="0">
  <thead><tr><th>Hedef kitle</th><th>Link</th><th>Kopyala</th></tr></thead>
  <tbody>{$rows}</tbody>
</table>
<p>
  <a href="/panel/classes/{$classId}/view">Bu sınıfın raporunu görüntüle (Web)</a>
  |
  <a href="/panel/classes/{$classId}/report" target="_blank" rel="noopener">Bu sınıfın raporunu indir (Excel)</a>
</p>
<p><a href="/panel/classes">Geri</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if (preg_match('#^/panel/classes/(\\d+)/view$#', $path, $m) && $method === 'GET') {
    $ctx = requireSchoolAdmin();
    $classId = (int)$m[1];
    $pdo = Db::pdo();

    $schoolStmt = $pdo->prepare('SELECT id, name, school_type FROM schools WHERE id = :id LIMIT 1');
    $schoolStmt->execute([':id' => $ctx['school_id']]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        Http::notFound();
        exit;
    }
    $schoolType = (string)$school['school_type'];
    $schoolName = View::e((string)$school['name']);

    $requestedCampaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;
    $camp = currentCampaignForReports($pdo, (int)$ctx['school_id'], $requestedCampaignId);
    if (!$camp) {
        Http::text(400, "Görüntülenecek anket dönemi bulunamadı.\n");
        exit;
    }
    $campaignId = (int)$camp['id'];
    $campName = View::e((string)$camp['name']);
    $campYear = View::e((string)$camp['year']);

    $classStmt = $pdo->prepare('SELECT id, name FROM classes WHERE id = :cid AND school_id = :sid LIMIT 1');
    $classStmt->execute([':cid' => $classId, ':sid' => $ctx['school_id']]);
    $class = $classStmt->fetch();
    if (!$class) {
        Http::notFound();
        exit;
    }
    $className = View::e((string)$class['name']);

    $scoring = RibaReport::scoring();
    $cfg = $scoring[$schoolType] ?? null;
    if (!is_array($cfg)) {
        Http::text(500, "Bu okul türü için puanlama bulunamadı.\n");
        exit;
    }
    $rsList = $cfg['rs_list'] ?? [];
    $targets = $cfg['targets'] ?? [];

    $aspRes = RibaReport::classAsp($pdo, $schoolType, (int)$ctx['school_id'], $campaignId, $classId);
    $aspByRs = $aspRes['asp'];

    $aspRows = '';
    foreach ($rsList as $rs) {
        $t = View::e((string)($targets[$rs] ?? ''));
        $v = $aspByRs[$rs] ?? null;
        $vv = ($v === null) ? '-' : number_format((float)$v, 2, '.', '');
        $aspRows .= '<tr><td>' . (int)$rs . '</td><td>' . $t . '</td><td>' . $vv . '</td></tr>';
    }

    $forms = RibaReport::classFormInstances($pdo, (int)$ctx['school_id'], $campaignId, $classId);
    $distBlocks = '';
    foreach ($forms as $aud => $formId) {
        $dist = RibaReport::distributionForForm($pdo, (int)$formId);
        $counts = $dist['counts'];
        ksort($counts);

        $rows2 = '';
        foreach ($counts as $itemNo => $ab) {
            $total = (int)$ab['total'];
            $pctA = $total > 0 ? ((int)$ab['A'] / $total) * 100.0 : 0.0;
            $pctB = $total > 0 ? ((int)$ab['B'] / $total) * 100.0 : 0.0;
            $rows2 .= '<tr>';
            $rows2 .= '<td>' . (int)$itemNo . '</td>';
            $rows2 .= '<td>' . (int)$ab['A'] . '</td>';
            $rows2 .= '<td>' . (int)$ab['B'] . '</td>';
            $rows2 .= '<td>' . $total . '</td>';
            $rows2 .= '<td>' . number_format($pctA, 1, '.', '') . '%</td>';
            $rows2 .= '<td>' . number_format($pctB, 1, '.', '') . '%</td>';
            $rows2 .= '</tr>';
        }

        $audTitle = View::e((string)$aud);
        $distBlocks .= <<<HTML
<h3>Dağılım ({$audTitle})</h3>
<table border="1" cellpadding="6" cellspacing="0">
  <thead><tr><th>Madde</th><th>A</th><th>B</th><th>Toplam</th><th>%A</th><th>%B</th></tr></thead>
  <tbody>{$rows2}</tbody>
</table>
HTML;
    }

    $html = View::layout('Sınıf Raporu', <<<HTML
<h1>Sınıf Raporu (Web)</h1>
<p><strong>Okul:</strong> {$schoolName}</p>
<p><strong>Sınıf:</strong> {$className}</p>
<p><strong>Tür:</strong> <code>{$schoolType}</code></p>
<p><strong>Anket dönemi:</strong> {$campYear} - {$campName}</p>
<p><a href="/panel/classes/{$classId}/report?campaign_id={$campaignId}" target="_blank" rel="noopener">Excel indir</a></p>
<h2>ASP (Sınıf)</h2>
<table border="1" cellpadding="6" cellspacing="0">
  <thead><tr><th>RS</th><th>Hedef</th><th>ASP</th></tr></thead>
  <tbody>{$aspRows}</tbody>
</table>
<h2>İşaretleme dağılımı (A/B)</h2>
{$distBlocks}
<p><a href="/panel/classes/{$classId}">Geri</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if (preg_match('#^/panel/classes/(\\d+)/report$#', $path, $m) && $method === 'GET') {
    $ctx = requireSchoolAdmin();
    $classId = (int)$m[1];
    $pdo = Db::pdo();
    $requestedCampaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;

    $schoolStmt = $pdo->prepare('SELECT id, name, school_type FROM schools WHERE id = :id LIMIT 1');
    $schoolStmt->execute([':id' => $ctx['school_id']]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        Http::notFound();
        exit;
    }
    $schoolType = (string)$school['school_type'];
    $schoolName = (string)$school['name'];

    $camp = currentCampaignForReports($pdo, (int)$ctx['school_id'], $requestedCampaignId);
    if (!$camp) {
        Http::text(400, "İndirilecek anket dönemi bulunamadı.\n");
        exit;
    }
    $campaignId = (int)$camp['id'];

    $classStmt = $pdo->prepare('SELECT id, name FROM classes WHERE id = :cid AND school_id = :sid LIMIT 1');
    $classStmt->execute([':cid' => $classId, ':sid' => $ctx['school_id']]);
    $class = $classStmt->fetch();
    if (!$class) {
        Http::notFound();
        exit;
    }

    $scoring = RibaReport::scoring();
    $cfg = $scoring[$schoolType] ?? null;
    if (!is_array($cfg)) {
        Http::text(500, "Bu okul türü için puanlama bulunamadı.\n");
        exit;
    }
    $rsList = $cfg['rs_list'] ?? [];
    $targets = $cfg['targets'] ?? [];

    $aspRes = RibaReport::classAsp($pdo, $schoolType, (int)$ctx['school_id'], $campaignId, $classId);
    $aspByRs = $aspRes['asp'];
    $className = (string)$class['name'];

    $reportRows = [];
    foreach ($rsList as $rs) {
        $reportRows[] = [
            'level' => 'class',
            'grade' => $schoolType,
            'class_no' => $className,
            'audience' => 'combined',
            'rs' => (int)$rs,
            'target' => (string)($targets[$rs] ?? ''),
            'asp' => $aspByRs[$rs] ?? null,
        ];
    }

    $forms = RibaReport::classFormInstances($pdo, (int)$ctx['school_id'], $campaignId, $classId);
    $distRows = [];
    foreach ($forms as $aud => $formId) {
        $dist = RibaReport::distributionForForm($pdo, (int)$formId);
        foreach ($dist['counts'] as $itemNo => $ab) {
            $total = (int)$ab['total'];
            $pctA = $total > 0 ? ((int)$ab['A'] / $total) : null;
            $pctB = $total > 0 ? ((int)$ab['B'] / $total) : null;
            $distRows[] = [
                'level' => 'class',
                'grade' => $schoolType,
                'class_no' => $className,
                'audience' => $aud,
                'item_no' => (int)$itemNo,
                'count_a' => (int)$ab['A'],
                'count_b' => (int)$ab['B'],
                'count_total' => $total,
                'pct_a' => $pctA,
                'pct_b' => $pctB,
            ];
        }
    }

    $root = dirname(__DIR__);
    $template = $root . '/riba_light_report_output.xlsx';
    $safeSchool = preg_replace('/[^a-zA-Z0-9_\\-]+/u', '_', $schoolName) ?: 'school';
    $safeClass = preg_replace('/[^a-zA-Z0-9_\\-]+/u', '_', $className) ?: 'class';
    $downloadName = 'riba_class_report_' . $safeSchool . '_' . $safeClass . '_' . date('Y-m-d_His') . '.xlsx';
    XlsxExport::downloadFromTemplate($template, $downloadName, $reportRows, $distRows);
}

// -------------------------
// PDF servis (soruların değişmemesi için PDF “kaynak”)
// -------------------------
if (preg_match('#^/pdf/([a-z0-9_\\-]+)$#', $path, $m) && $method === 'GET') {
    $key = (string)$m[1];
    $allowed = [
        'okul_oncesi_veli' => '18115003_RYBA_FORM_Veli_Okul_Oncesi.pdf',
        'okul_oncesi_ogretmen' => '18174526_RYBA_FORM_OYretmen_Okul_Oncesi.pdf',
        'ilkokul_ogrenci' => '26133925_ilkokulogrenci.pdf',
        'ilkokul_veli' => '26133946_ilkokulveli.pdf',
        'ilkokul_ogretmen' => '26133935_ilkokulogretmen.pdf',
        'ortaokul_ogrenci' => '26134502_ortaokulogrenci.pdf',
        'ortaokul_veli' => '26134525_ortaokulveli.pdf',
        'ortaokul_ogretmen' => '26134514_ortaokulogretmen.pdf',
        'lise_ogrenci' => '26134751_liseogrenci.pdf',
        'lise_veli' => '26134812_liseveli.pdf',
        'lise_ogretmen' => '26134800_liseogretmen.pdf',
    ];
    if (!isset($allowed[$key])) {
        Http::notFound();
        exit;
    }
    $root = dirname(__DIR__);
    $file = $root . '/' . $allowed[$key];
    Http::sendFilePdf($file, $allowed[$key]);
    exit;
}

// -------------------------
// Public anket doldurma (login yok)
// -------------------------
if (preg_match('#^/f/([a-f0-9]{32})$#', $path, $m) && $method === 'GET') {
    $publicId = (string)$m[1];
    $pdo = Db::pdo();

    $stmt = $pdo->prepare('
        SELECT
          fi.id AS form_id,
          fi.school_id,
          fi.class_id,
          fi.campaign_id,
          fi.audience,
          fi.status,
          c.name AS class_name,
          s.school_type,
          camp.status AS campaign_status,
          camp.starts_at,
          camp.ends_at,
          camp.response_quota
        FROM form_instances fi
        JOIN classes c ON c.id = fi.class_id
        JOIN schools s ON s.id = fi.school_id
        JOIN campaigns camp ON camp.id = fi.campaign_id
        WHERE fi.public_id = :pid
        LIMIT 1
    ');
    $stmt->execute([':pid' => $publicId]);
    $row = $stmt->fetch();
    if (!$row) {
        Http::notFound();
        exit;
    }
    if ((string)$row['status'] !== 'active') {
        Http::forbidden("Bu anket kapalı.\n");
        exit;
    }
    if ((string)$row['campaign_status'] !== 'active') {
        Http::forbidden("Anket dönemi aktif değil.\n");
        exit;
    }

    // Tarih aralığı / kota kontrolü
    $campaignId = (int)$row['campaign_id'];
    $used = campaignUsage($pdo, $campaignId);
    $quota = (int)$row['response_quota'];

    if (!campaignIsWithinWindow($row)) {
        // Bitiş geçtiyse otomatik kapat
        $end = strtotime((string)$row['ends_at']);
        if ($end !== false && time() > $end) {
            closeCampaign($pdo, (int)$row['school_id'], $campaignId);
        }
        Http::forbidden("Anket dönemi şu an açık değil (tarih aralığı dışında).\n");
        exit;
    }
    if ($quota > 0 && $used >= $quota) {
        closeCampaign($pdo, (int)$row['school_id'], $campaignId);
        $shouldMail = markQuotaClosedNotified($pdo, (int)$row['school_id'], $campaignId);
        Http::forbidden("Anket kotası doldu. Ek kota/paket almanız gerekir.\n");
        if ($shouldMail) {
            notifyQuotaClosed($pdo, (int)$row['school_id'], $campaignId);
        }
        exit;
    }

    $schoolType = (string)$row['school_type'];
    $aud = (string)$row['audience'];
    $spec = formSpec($schoolType, $aud);
    if ($spec === null) {
        Http::notFound();
        exit;
    }

    $cookieName = 'riba_done_' . $publicId;
    if (!empty($_COOKIE[$cookieName])) {
        $html = View::layout('Anket', "<h1>Anket</h1><p>Bu cihazdan daha önce yanıt gönderilmiş görünüyor.</p>");
        Http::html(200, $html);
        exit;
    }

    $itemCount = (int)$spec['items'];
    $pdfFile = (string)$spec['pdf'];
    $pdfKey = $schoolType . '_' . $aud;

    $csrf = Csrf::input();
    $itemsHtml = '';
    for ($i = 1; $i <= $itemCount; $i++) {
        $itemsHtml .= '<p>';
        $itemsHtml .= '<strong>Madde ' . $i . '</strong><br />';
        $itemsHtml .= '<label><input type="radio" name="m' . $i . '" value="A" required /> A</label> ';
        $itemsHtml .= '<label><input type="radio" name="m' . $i . '" value="B" required /> B</label>';
        $itemsHtml .= '</p>';
    }

    $className = View::e((string)$row['class_name']);
    $html = View::layout('Anket', <<<HTML
<h1>Anket</h1>
<p><strong>Sınıf:</strong> {$className}</p>
<p><strong>Sorular</strong> PDF dosyasında yer alır (değişmemesi için PDF kaynak olarak gösterilir):</p>
<p><a href="/pdf/{$pdfKey}" target="_blank" rel="noopener">PDF'yi yeni sekmede aç: {$pdfFile}</a></p>
<div style="border:1px solid #ccc; padding:8px; margin:8px 0;">
  <embed src="/pdf/{$pdfKey}" type="application/pdf" width="100%" height="500px" />
</div>
<form method="post" action="/f/{$publicId}">
  {$csrf}
  <p><label>Cinsiyet<br />
    <select name="gender" required>
      <option value="">Seçiniz</option>
      <option value="K">Kız (K)</option>
      <option value="E">Erkek (E)</option>
    </select>
  </label></p>
  <hr />
  {$itemsHtml}
  <button type="submit">Gönder</button>
</form>
HTML);
    Http::html(200, $html);
    exit;
}

if (preg_match('#^/f/([a-f0-9]{32})$#', $path, $m) && $method === 'POST') {
    $publicId = (string)$m[1];
    Csrf::validatePost();

    $pdo = Db::pdo();
    $stmt = $pdo->prepare('
        SELECT
          fi.id AS form_id,
          fi.school_id,
          fi.class_id,
          fi.campaign_id,
          fi.audience,
          fi.status,
          s.school_type
        FROM form_instances fi
        JOIN schools s ON s.id = fi.school_id
        WHERE fi.public_id = :pid
        LIMIT 1
    ');
    $stmt->execute([':pid' => $publicId]);
    $row = $stmt->fetch();
    if (!$row) {
        Http::notFound();
        exit;
    }
    if ((string)$row['status'] !== 'active') {
        Http::forbidden("Bu anket kapalı.\n");
        exit;
    }

    $schoolType = (string)$row['school_type'];
    $aud = (string)$row['audience'];
    $spec = formSpec($schoolType, $aud);
    if ($spec === null) {
        Http::notFound();
        exit;
    }

    $cookieName = 'riba_done_' . $publicId;
    if (!empty($_COOKIE[$cookieName])) {
        Http::forbidden("Bu cihazdan daha önce yanıt gönderilmiş görünüyor.\n");
        exit;
    }

    $gender = (string)($_POST['gender'] ?? '');
    if ($gender !== 'K' && $gender !== 'E') {
        Http::badRequest("Cinsiyet gerekli.\n");
        exit;
    }

    $itemCount = (int)$spec['items'];
    $choices = [];
    for ($i = 1; $i <= $itemCount; $i++) {
        $v = (string)($_POST['m' . $i] ?? '');
        if ($v !== 'A' && $v !== 'B') {
            Http::badRequest("Tüm maddeler işaretlenmelidir.\n");
            exit;
        }
        $choices[$i] = $v;
    }

    $campaignId = (int)$row['campaign_id'];
    if ($campaignId <= 0) {
        Http::text(500, "Anket dönemi bilgisi eksik.\n");
        exit;
    }

    $pdo->beginTransaction();
    try {
        // Anket dönemi: aktif mi / tarih aralığı / kota (FOR UPDATE ile yarış engellenir)
        $campStmt = $pdo->prepare('SELECT id, school_id, status, starts_at, ends_at, response_quota FROM campaigns WHERE id = :id FOR UPDATE');
        $campStmt->execute([':id' => $campaignId]);
        $camp = $campStmt->fetch();
        if (!$camp || (int)$camp['school_id'] !== (int)$row['school_id']) {
            $pdo->rollBack();
            Http::forbidden("Anket dönemi geçersiz.\n");
            exit;
        }
        if ((string)$camp['status'] !== 'active') {
            $pdo->rollBack();
            Http::forbidden("Anket dönemi aktif değil.\n");
            exit;
        }
        if (!campaignIsWithinWindow($camp)) {
            // Bitiş geçtiyse otomatik kapat
            $end = strtotime((string)$camp['ends_at']);
            if ($end !== false && time() > $end) {
                closeCampaign($pdo, (int)$row['school_id'], $campaignId);
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
            Http::forbidden("Anket dönemi şu an açık değil (tarih aralığı dışında).\n");
            exit;
        }
        $quota = (int)$camp['response_quota'];
        $used = campaignUsage($pdo, $campaignId);
        if ($quota > 0 && $used >= $quota) {
            closeCampaign($pdo, (int)$row['school_id'], $campaignId);
            $shouldMail = markQuotaClosedNotified($pdo, (int)$row['school_id'], $campaignId);
            $pdo->commit();
            Http::forbidden("Anket kotası doldu. Ek kota/paket almanız gerekir.\n");
            if ($shouldMail) {
                notifyQuotaClosed($pdo, (int)$row['school_id'], $campaignId);
            }
            exit;
        }

        // Tek doldurma: IP hash kontrolü (kesin değil; çerez/IP ile “engellemeye çalışma”)
        $iphash = ipHash($publicId);
        $exists = $pdo->prepare('SELECT id FROM responses WHERE form_instance_id = :fid AND ip_hash = :ip LIMIT 1');
        $exists->execute([':fid' => (int)$row['form_id'], ':ip' => $iphash]);
        if ($exists->fetch()) {
            $pdo->rollBack();
            Http::forbidden("Bu ağdan daha önce yanıt gönderilmiş görünüyor.\n");
            exit;
        }

        $ins = $pdo->prepare('
            INSERT INTO responses (school_id, campaign_id, class_id, form_instance_id, gender, ip_hash, user_agent)
            VALUES (:sid, :camp, :cid, :fid, :g, :ip, :ua)
        ');
        $ins->execute([
            ':sid' => (int)$row['school_id'],
            ':camp' => $campaignId,
            ':cid' => (int)$row['class_id'],
            ':fid' => (int)$row['form_id'],
            ':g' => $gender,
            ':ip' => $iphash,
            ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $responseId = (int)$pdo->lastInsertId();

        $insA = $pdo->prepare('INSERT INTO response_answers (response_id, item_no, choice) VALUES (:rid, :no, :ch)');
        foreach ($choices as $no => $ch) {
            $insA->execute([
                ':rid' => $responseId,
                ':no' => (int)$no,
                ':ch' => $ch,
            ]);
        }

        // Bu yanıt ile kota dolduysa dönemi kapat (hard stop)
        if ($quota > 0 && ($used + 1) >= $quota) {
            closeCampaign($pdo, (int)$row['school_id'], $campaignId);
            // Tek seferlik bildirim için işaretle (mail commit sonrası)
            $shouldMail = markQuotaClosedNotified($pdo, (int)$row['school_id'], $campaignId);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        Http::text(500, "Yanıt kaydedilemedi.\n");
        exit;
    }

    // Çerez: cihaz bazlı tekrar doldurmayı azaltır
    setcookie($cookieName, '1', [
        'expires' => time() + 3600 * 24 * 365,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $html = View::layout('Teşekkürler', <<<HTML
<h1>Teşekkürler</h1>
<p>Yanıtınız kaydedildi.</p>
HTML);
    Http::html(200, $html);

    // Kota bu yanıtta dolduysa (ve işaretlendiyse) mail gönder
    if (isset($shouldMail) && $shouldMail === true) {
        notifyQuotaClosed($pdo, (int)$row['school_id'], $campaignId);
    }
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

// -------------------------
// Site yöneticisi: Paketler ve Ek Kota Siparişleri
// -------------------------
if ($path === '/admin/packages' && $method === 'GET') {
    requireSiteAdmin();
    $pdo = Db::pdo();
    $rows = $pdo->query('SELECT id, name, quota_add, price_amount, price_currency, active, created_at FROM quota_packages ORDER BY quota_add ASC')->fetchAll();
    $csrf = Csrf::input();

    $items = '';
    foreach ($rows as $r) {
        $items .= '<tr>';
        $items .= '<td>' . View::e((string)$r['id']) . '</td>';
        $items .= '<td>' . View::e((string)$r['name']) . '</td>';
        $items .= '<td>' . View::e((string)$r['quota_add']) . '</td>';
        $items .= '<td>' . View::e((string)($r['price_amount'] ?? '')) . ' ' . View::e((string)($r['price_currency'] ?? '')) . '</td>';
        $items .= '<td>' . ((int)$r['active'] === 1 ? 'aktif' : 'pasif') . '</td>';
        $items .= '<td>' . View::e((string)$r['created_at']) . '</td>';
        $items .= '</tr>';
    }

    $html = View::layout('Paketler', <<<HTML
<h1>Ek Kota Paketleri</h1>
<table border="1" cellpadding="6" cellspacing="0">
  <thead><tr><th>ID</th><th>Ad</th><th>Kota</th><th>Fiyat</th><th>Durum</th><th>Oluşturma</th></tr></thead>
  <tbody>{$items}</tbody>
</table>
<hr />
<h2>Yeni paket</h2>
<form method="post" action="/admin/packages/create">
  {$csrf}
  <p><label>Ad<br /><input name="name" required /></label></p>
  <p><label>Kota artışı (yanıt)<br /><input type="number" name="quota_add" required /></label></p>
  <p><label>Fiyat (opsiyonel)<br /><input type="number" name="price_amount" /></label></p>
  <p><label>Para birimi (opsiyonel, örn TRY)<br /><input name="price_currency" /></label></p>
  <p><button type="submit">Ekle</button></p>
</form>
<p><a href="/">Ana sayfa</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/admin/packages/create' && $method === 'POST') {
    requireSiteAdmin();
    Csrf::validatePost();
    $pdo = Db::pdo();
    $name = trim((string)($_POST['name'] ?? ''));
    $quotaAdd = (int)($_POST['quota_add'] ?? 0);
    $priceAmount = isset($_POST['price_amount']) && $_POST['price_amount'] !== '' ? (int)$_POST['price_amount'] : null;
    $priceCurrency = isset($_POST['price_currency']) && trim((string)$_POST['price_currency']) !== '' ? strtoupper(trim((string)$_POST['price_currency'])) : null;
    if ($name === '' || $quotaAdd <= 0) {
        Http::badRequest("Eksik/yanlış bilgi.\n");
        exit;
    }
    $stmt = $pdo->prepare('INSERT INTO quota_packages (name, quota_add, price_amount, price_currency, active) VALUES (:n, :q, :p, :c, 1)');
    $stmt->execute([':n' => $name, ':q' => $quotaAdd, ':p' => $priceAmount, ':c' => $priceCurrency]);
    redirect('/admin/packages');
}

if ($path === '/admin/orders' && $method === 'GET') {
    requireSiteAdmin();
    $pdo = Db::pdo();
    $rows = $pdo->query('
        SELECT o.id, o.status, o.method, o.quota_add, o.note, o.created_at,
               s.name AS school_name,
               c.year AS camp_year, c.name AS camp_name,
               p.name AS pkg_name
        FROM quota_orders o
        JOIN schools s ON s.id = o.school_id
        JOIN campaigns c ON c.id = o.campaign_id
        JOIN quota_packages p ON p.id = o.package_id
        ORDER BY o.created_at DESC
        LIMIT 200
    ')->fetchAll();
    $csrf = Csrf::input();
    $items = '';
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $items .= '<tr>';
        $items .= '<td>' . View::e((string)$id) . '</td>';
        $items .= '<td>' . View::e((string)$r['school_name']) . '</td>';
        $items .= '<td>' . View::e((string)$r['camp_year']) . ' - ' . View::e((string)$r['camp_name']) . '</td>';
        $items .= '<td>' . View::e((string)$r['pkg_name']) . ' (+' . View::e((string)$r['quota_add']) . ')</td>';
        $items .= '<td>' . View::e((string)$r['method']) . '</td>';
        $items .= '<td>' . View::e((string)$r['status']) . '</td>';
        $items .= '<td>' . View::e((string)($r['note'] ?? '')) . '</td>';
        $items .= '<td>' . View::e((string)$r['created_at']) . '</td>';
        $items .= '<td>';
        if ((string)$r['status'] === 'pending') {
            $items .= "<form method=\"post\" action=\"/admin/orders/mark-paid\" style=\"margin:0\">{$csrf}<input type=\"hidden\" name=\"id\" value=\"{$id}\" /><button type=\"submit\">Ödendi (kota ekle)</button></form>";
        }
        $items .= '</td>';
        $items .= '</tr>';
    }

    $html = View::layout('Siparişler', <<<HTML
<h1>Ek Kota Siparişleri</h1>
<p><a href="/admin/packages">Paketler</a></p>
<table border="1" cellpadding="6" cellspacing="0">
  <thead><tr><th>ID</th><th>Okul</th><th>Dönem</th><th>Paket</th><th>Yöntem</th><th>Durum</th><th>Not</th><th>Oluşturma</th><th>İşlem</th></tr></thead>
  <tbody>{$items}</tbody>
</table>
<p><a href="/">Ana sayfa</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/admin/orders/mark-paid' && $method === 'POST') {
    requireSiteAdmin();
    Csrf::validatePost();
    $pdo = Db::pdo();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        Http::badRequest("Geçersiz sipariş.\n");
        exit;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM quota_orders WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $order = $stmt->fetch();
        if (!$order || (string)$order['status'] !== 'pending') {
            $pdo->rollBack();
            Http::badRequest("Sipariş bulunamadı veya zaten işlenmiş.\n");
            exit;
        }
        $campaignId = (int)$order['campaign_id'];
        $quotaAdd = (int)$order['quota_add'];

        // Siparişi paid yap
        $pdo->prepare('UPDATE quota_orders SET status = :st, paid_at = NOW() WHERE id = :id')
            ->execute([':st' => 'paid', ':id' => $id]);

        // Kampanya kotasını artır
        $pdo->prepare('UPDATE campaigns SET response_quota = response_quota + :qa WHERE id = :cid')
            ->execute([':qa' => $quotaAdd, ':cid' => $campaignId]);

        // applied_at
        $pdo->prepare('UPDATE quota_orders SET applied_at = NOW() WHERE id = :id')
            ->execute([':id' => $id]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        Http::text(500, "Sipariş işlenemedi.\n");
        exit;
    }

    redirect('/admin/orders');
}

Http::notFound();
