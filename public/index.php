<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Http;
use App\Csrf;
use App\Db;
use App\View;
use App\RibaReport;
use App\XlsxExport;

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
  <li><a href="/panel/campaigns">Yıllık Süreç (Kampanya)</a></li>
  <li><a href="/panel/reports">Raporlar</a></li>
</ul>
<form method="post" action="/logout">{$csrf}<button type="submit">Çıkış</button></form>
HTML);
    Http::html(200, $html);
    exit;
}

// -------------------------
// Okul Paneli: Kampanyalar (yıllık süreç)
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
    $html = View::layout('Kampanyalar', <<<HTML
<h1>Yıllık Süreç (Kampanya)</h1>
<p><strong>Okul:</strong> {$schoolName}</p>
<p>Her yıl için ayrı kampanya oluşturulur. Linkler kampanya aktifken çalışır; kota bitince otomatik kapanır.</p>
<table border="1" cellpadding="6" cellspacing="0">
  <thead><tr><th>Yıl</th><th>Ad</th><th>Durum</th><th>Başlangıç</th><th>Bitiş</th><th>Kota</th><th></th></tr></thead>
  <tbody>{$items}</tbody>
</table>
<hr />
<h2>Yeni kampanya oluştur</h2>
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
        Http::text(500, "Kampanya oluşturulamadı (aynı yıl zaten var olabilir).\n");
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
        $actions .= "<form method=\"post\" action=\"/panel/campaigns/{$campaignId}/activate\">{$csrf}<button type=\"submit\">Aktif et ve linkleri üret</button></form>";
    } elseif ((string)$camp['status'] === 'active') {
        $actions .= "<form method=\"post\" action=\"/panel/campaigns/{$campaignId}/close\">{$csrf}<button type=\"submit\">Kampanyayı kapat</button></form>";
    }

    $html = View::layout('Kampanya Detayı', <<<HTML
<h1>Kampanya</h1>
<p><strong>Ad:</strong> {$name}</p>
<p><strong>Durum:</strong> {$status}</p>
<p><strong>Başlangıç:</strong> {$startsAt}</p>
<p><strong>Bitiş:</strong> {$endsAt}</p>
<p><strong>Kota:</strong> {$quota} | <strong>Kullanım:</strong> {$used} | <strong>Kalan:</strong> {$remaining}</p>
{$actions}
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
        // Kampanya doğrula
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
            Http::badRequest("Sadece taslak kampanya aktif edilebilir.\n");
            exit;
        }

        // Aynı okulda aktif başka kampanya varsa kapat (yıllık süreçlerin karışmaması için)
        $pdo->prepare('UPDATE campaigns SET status = :closed, closed_at = NOW() WHERE school_id = :sid AND status = :active')
            ->execute([':closed' => 'closed', ':sid' => $ctx['school_id'], ':active' => 'active']);

        // Bu kampanyayı aktif et
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
        Http::text(500, "Kampanya aktif edilemedi.\n");
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
            Http::badRequest("Sadece aktif kampanya kapatılabilir.\n");
            exit;
        }

        $pdo->prepare('UPDATE campaigns SET status = :st, closed_at = NOW() WHERE id = :id')
            ->execute([':st' => 'closed', ':id' => $campaignId]);
        $pdo->prepare('UPDATE form_instances SET status = :st WHERE campaign_id = :cid')
            ->execute([':st' => 'closed', ':cid' => $campaignId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        Http::text(500, "Kampanya kapatılamadı.\n");
        exit;
    }

    redirect('/panel/campaigns/' . $campaignId);
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

    $html = View::layout('Raporlar', <<<HTML
<h1>Raporlar</h1>
<p><strong>Okul:</strong> {$name}</p>
<ul>
  <li><a href="/panel/reports/view">Web üzerinde görüntüle</a></li>
</ul>
<p>Excel çıktı formatı: <code>riba_light_report_output.xlsx</code> (ASP + dağılım).</p>
<form method="post" action="/panel/reports/export">
  {$csrf}
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
        $res = RibaReport::classAsp($pdo, $schoolType, (int)$ctx['school_id'], $cid);
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
        $classList .= '<li><a href="/panel/classes/' . $cid . '/view">' . $cname . ' (web)</a> — ';
        $classList .= '<a href="/panel/classes/' . $cid . '/report" target="_blank" rel="noopener">Excel</a></li>';
    }

    $html = View::layout('Okul Raporu', <<<HTML
<h1>Okul Raporu (Web)</h1>
<p><strong>Okul:</strong> {$schoolName}</p>
<p><strong>Tür:</strong> <code>{$schoolType}</code></p>
<h2>ASP (Okul ortalaması)</h2>
<table border="1" cellpadding="6" cellspacing="0">
  <thead><tr><th>RS</th><th>Hedef</th><th>ASP</th></tr></thead>
  <tbody>{$rowsHtml}</tbody>
</table>
<h2>Sınıflar</h2>
<ul>{$classList}</ul>
<p><a href="/panel/reports">Geri</a></p>
HTML);
    Http::html(200, $html);
    exit;
}

if ($path === '/panel/reports/export' && $method === 'POST') {
    $ctx = requireSchoolAdmin();
    Csrf::validatePost();

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
        $res = RibaReport::classAsp($pdo, $schoolType, (int)$ctx['school_id'], $cid);
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
        $forms = RibaReport::classFormInstances($pdo, (int)$ctx['school_id'], $cid);

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
        $campNote = '<p><strong>Uyarı:</strong> Aktif kampanya yok. Anket linkleri üretmek için önce <a href="/panel/campaigns">kampanyayı</a> aktif edin.</p>';
    } else {
        $campNote = '<p><strong>Aktif kampanya:</strong> ' . View::e((string)$activeCamp['name']) . ' (' . View::e((string)$activeCamp['year']) . ')</p>';
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
<p><strong>Uyarı:</strong> Aktif kampanya yok. Link üretmek için <a href="/panel/campaigns">kampanyayı</a> aktif edin.</p>
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
<p><strong>Kampanya:</strong> {$campName}</p>
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

    $aspRes = RibaReport::classAsp($pdo, $schoolType, (int)$ctx['school_id'], $classId);
    $aspByRs = $aspRes['asp'];

    $aspRows = '';
    foreach ($rsList as $rs) {
        $t = View::e((string)($targets[$rs] ?? ''));
        $v = $aspByRs[$rs] ?? null;
        $vv = ($v === null) ? '-' : number_format((float)$v, 2, '.', '');
        $aspRows .= '<tr><td>' . (int)$rs . '</td><td>' . $t . '</td><td>' . $vv . '</td></tr>';
    }

    $forms = RibaReport::classFormInstances($pdo, (int)$ctx['school_id'], $classId);
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
<p><a href="/panel/classes/{$classId}/report" target="_blank" rel="noopener">Excel indir</a></p>
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

    $schoolStmt = $pdo->prepare('SELECT id, name, school_type FROM schools WHERE id = :id LIMIT 1');
    $schoolStmt->execute([':id' => $ctx['school_id']]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        Http::notFound();
        exit;
    }
    $schoolType = (string)$school['school_type'];
    $schoolName = (string)$school['name'];

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

    $aspRes = RibaReport::classAsp($pdo, $schoolType, (int)$ctx['school_id'], $classId);
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

    $forms = RibaReport::classFormInstances($pdo, (int)$ctx['school_id'], $classId);
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
        SELECT fi.id AS form_id, fi.audience, fi.status, c.name AS class_name, s.school_type
        FROM form_instances fi
        JOIN classes c ON c.id = fi.class_id
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
        SELECT fi.id AS form_id, fi.school_id, fi.class_id, fi.audience, fi.status, s.school_type
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

    // Tek doldurma: IP hash kontrolü (kesin değil; çerez/IP ile “engellemeye çalışma”)
    $iphash = ipHash($publicId);
    $exists = $pdo->prepare('SELECT id FROM responses WHERE form_instance_id = :fid AND ip_hash = :ip LIMIT 1');
    $exists->execute([':fid' => (int)$row['form_id'], ':ip' => $iphash]);
    if ($exists->fetch()) {
        Http::forbidden("Bu ağdan daha önce yanıt gönderilmiş görünüyor.\n");
        exit;
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('
            INSERT INTO responses (school_id, class_id, form_instance_id, gender, ip_hash, user_agent)
            VALUES (:sid, :cid, :fid, :g, :ip, :ua)
        ');
        $ins->execute([
            ':sid' => (int)$row['school_id'],
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

Http::notFound();
