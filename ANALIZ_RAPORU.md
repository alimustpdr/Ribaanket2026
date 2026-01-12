# RİBA Anket Sistemi - Kapsamlı Analiz Raporu

**Tarih:** 2026  
**Proje:** Ribaanket2026 - Custom PHP Anket Sistemi  
**Branch:** dev (analiz için)

---

## 1. PROJE YAPISI ANALİZİ

### 1.1 Dizin Yapısı

```
Ribaanket2026/
├── public/
│   └── index.php          # Ana router (2942 satır)
├── src/
│   ├── bootstrap.php      # Başlangıç yapılandırması
│   ├── db.php             # PDO veritabanı bağlantısı
│   ├── env.php            # Ortam değişkenleri yönetimi
│   ├── http.php           # HTTP yanıt yardımcıları
│   ├── csrf.php           # CSRF koruması
│   ├── view.php           # View/HTML yardımcıları
│   ├── mailer.php         # E-posta gönderimi (mail())
│   ├── riba_report.php    # Rapor hesaplama mantığı
│   └── xlsx_export.php    # Excel çıktısı
├── config/
│   ├── env.example        # Ortam değişkenleri şablonu
│   ├── schema.sql         # Veritabanı şeması
│   ├── schema_update.sql  # Şema güncellemeleri
│   └── riba_scoring.php   # Puanlama kuralları
├── storage/               # Log ve geçici dosyalar
├── composer.json          # Bağımlılıklar (PhpSpreadsheet)
└── README.md              # Kurulum dokümantasyonu
```

### 1.2 Mimari Özellikler

- **Framework Yok:** Düz PHP, framework kullanılmıyor
- **MVC Benzeri:** Router (index.php), Model (db.php), View (view.php) ayrımı var
- **Namespace:** `App` namespace'i kullanılıyor
- **Strict Types:** `declare(strict_types=1)` aktif
- **PDO:** Prepared statements kullanılıyor
- **Session:** PHP native session yönetimi

### 1.3 Bağımlılıklar

- PHP >= 8.1
- phpoffice/phpspreadsheet ^2.2 (Excel çıktısı için)
- MariaDB/MySQL veritabanı

---

## 2. ROUTING AKIŞI (public/index.php)

### 2.1 Router Yapısı

Router, `public/index.php` dosyasında tek bir dosyada toplanmış. Akış şu şekilde:

1. **Bootstrap:** `src/bootstrap.php` yüklenir
2. **Path/Method:** `$_SERVER['REQUEST_URI']` ve `$_SERVER['REQUEST_METHOD']` parse edilir
3. **Kurulum Kontrolü:** `isInstalled()` kontrolü yapılır
4. **Route Matching:** Sıralı `if` blokları ile route eşleştirme

### 2.2 Route Listesi

#### Public Routes (Kimlik Doğrulama Gerektirmez)
- `GET /health` - Health check endpoint
- `GET /setup` - Kurulum sihirbazı (sadece kurulum yapılmadıysa)
- `POST /setup` - Kurulum işlemi
- `GET /` - Ana sayfa (kurulum kontrolü)
- `GET /apply` - Okul başvuru formu
- `POST /apply` - Okul başvuru işlemi
- `GET /f/{32-char-hex}` - Anket formu (public)
- `POST /f/{32-char-hex}` - Anket gönderimi
- `GET /pdf/{key}` - PDF dosya servisi

#### Okul Admin Routes (requireSchoolAdmin)
- `GET /login` - Okul admin girişi
- `POST /login` - Okul admin giriş işlemi
- `POST /logout` - Çıkış
- `GET /panel` - Okul paneli ana sayfa
- `GET /panel/subscription` - Üyelik bilgileri
- `POST /panel/subscription/order` - Üyelik siparişi
- `GET /panel/orders` - Sipariş geçmişi
- `GET /panel/campaigns` - Anket dönemleri listesi
- `POST /panel/campaigns/create` - Yeni anket dönemi
- `GET /panel/campaigns/{id}` - Anket dönemi detayı
- `POST /panel/campaigns/{id}/activate` - Anket dönemi aktifleştir
- `POST /panel/campaigns/{id}/close` - Anket dönemi kapat
- `POST /panel/campaigns/{id}/update` - Anket dönemi güncelle
- `POST /panel/campaigns/{id}/reopen` - Anket dönemi yeniden aç
- `GET /panel/quota` - Kota yönetimi
- `POST /panel/quota/order` - Kota siparişi
- `GET /panel/reports` - Raporlar listesi
- `GET /panel/reports/view` - Rapor görüntüleme
- `POST /panel/reports/export` - Excel export
- `GET /panel/classes` - Sınıflar listesi
- `POST /panel/classes/create` - Yeni sınıf
- `GET /panel/classes/{id}` - Sınıf detayı
- `GET /panel/classes/{id}/view` - Sınıf anket linkleri
- `GET /panel/classes/{id}/report` - Sınıf raporu

#### Site Admin Routes (requireSiteAdmin)
- `GET /admin/login` - Site admin girişi
- `POST /admin/login` - Site admin giriş işlemi
- `GET /admin/schools` - Okullar listesi
- `POST /admin/schools/approve` - Okul onaylama
- `GET /admin/packages` - Kota paketleri yönetimi
- `POST /admin/packages/create` - Yeni paket
- `GET /admin/orders` - Siparişler listesi
- `POST /admin/orders/mark-paid` - Sipariş ödeme işaretle
- `GET /admin/subscriptions` - Üyelikler listesi
- `POST /admin/subscriptions/mark-paid` - Üyelik ödeme işaretle
- `GET /admin/demo` - Demo veri oluşturma
- `POST /admin/demo/create` - Demo veri oluştur

### 2.3 Routing Sorunları

1. **Tek Dosya:** 2942 satırlık tek dosya, bakımı zorlaştırıyor
2. **Sıralı If Blokları:** Route eşleştirme sıralı `if` blokları ile yapılıyor, performans riski var
3. **Regex Kullanımı:** Bazı route'lar `preg_match` ile eşleştiriliyor
4. **Route Parametreleri:** URL parametreleri regex ile çıkarılıyor (örn: `/panel/campaigns/(\d+)`)
5. **404 Handling:** Sadece en sonda `Http::notFound()` çağrılıyor

---

## 3. CONFIG VE VERİTABANI BAĞLANTI NOKTALARI

### 3.1 Ortam Değişkenleri (Config)

**Dosya:** `src/env.php` (Env sınıfı)

**Yükleme Sırası:**
1. `getenv()` ile sistem ortam değişkenleri
2. `.env` dosyası (proje kökü)
3. `config/env` dosyası (fallback)

**Kullanılan Ortam Değişkenleri:**
- `APP_KEY` - Uygulama anahtarı (zorunlu)
- `APP_ENV` - Ortam (local/production)
- `ADMIN_EMAIL` - Site admin e-posta
- `ADMIN_PASSWORD` - Site admin şifre
- `DB_HOST` - Veritabanı host (varsayılan: 127.0.0.1)
- `DB_PORT` - Veritabanı port (varsayılan: 3306)
- `DB_NAME` - Veritabanı adı (varsayılan: riba)
- `DB_USER` - Veritabanı kullanıcı adı (varsayılan: root)
- `DB_PASS` - Veritabanı şifresi
- `MAIL_FROM` - E-posta gönderen adres
- `MAIL_FROM_NAME` - E-posta gönderen isim

**Sorunlar:**
- `.env` dosyası git'e commit edilmemeli (şu an durum belirsiz)
- `APP_KEY` boşsa uygulama çöküyor (500 hatası)
- Ortam değişkenleri validasyonu yok

### 3.2 Veritabanı Bağlantısı

**Dosya:** `src/db.php` (Db sınıfı)

**Bağlantı Özellikleri:**
- Singleton pattern (static `$pdo`)
- PDO kullanımı
- `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
- `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`
- `PDO::ATTR_EMULATE_PREPARES => false` (gerçek prepared statements)

**DSN Formatı:**
```php
mysql:host={host};port={port};dbname={name};charset=utf8mb4
```

**Sorunlar:**
1. **Connection Pooling Yok:** Her istekte aynı bağlantı kullanılıyor (singleton), ancak connection pooling yok
2. **Hata Yönetimi:** Bağlantı hatası durumunda exception fırlatılıyor, ancak kullanıcıya anlamlı mesaj gösterilmiyor
3. **Timeout Ayarları:** PDO timeout ayarları yok
4. **Reconnect Mekanizması:** Bağlantı koparsa otomatik reconnect yok

### 3.3 Veritabanı Şeması

**Dosya:** `config/schema.sql`

**Ana Tablolar:**
- `schools` - Okullar
- `school_admins` - Okul yöneticileri
- `school_subscriptions` - Okul üyelikleri
- `quota_packages` - Kota paketleri
- `classes` - Sınıflar
- `campaigns` - Anket dönemleri
- `form_instances` - Anket form örnekleri
- `responses` - Anket yanıtları
- `response_answers` - Anket yanıt cevapları
- `quota_orders` - Kota siparişleri

**İndeksler:**
- Foreign key'ler tanımlı
- Bazı sütunlarda index var
- `form_instances.public_id` için index yok (sık sorgulanıyor)

---

## 4. GÜVENLİK RİSKLERİ

### 4.1 Kritik Güvenlik Sorunları

#### 🔴 YÜKSEK RİSK

1. **Production'da Hata Gösterimi Açık**
   - **Dosya:** `src/bootstrap.php:14-16`
   - **Sorun:** `display_errors = 1` ve `error_reporting(E_ALL)` production'da açık
   - **Risk:** Hata mesajları, stack trace'ler, veritabanı bilgileri kullanıcıya gösterilebilir
   - **Etki:** Bilgi sızıntısı, sistem bilgilerinin ifşası

2. **CSRF Koruması Eksiklikleri**
   - **Dosya:** `src/csrf.php`
   - **Sorun:** 
     - GET istekleri için CSRF kontrolü yok (bazı GET'ler state değiştirebilir)
     - Token rotation yok (session boyunca aynı token)
   - **Risk:** CSRF saldırıları
   - **Not:** POST istekleri için `Csrf::validatePost()` kullanılıyor (iyi)

3. **SQL Injection Potansiyeli**
   - **Dosya:** `public/index.php:244`
   - **Sorun:** `$pdo->query()` kullanımı (prepared statement değil)
   ```php
   return $pdo->query('SELECT id, name, quota_add, price_amount, price_currency FROM quota_packages WHERE active = 1 ORDER BY quota_add ASC')->fetchAll();
   ```
   - **Risk:** Eğer `active` değeri kullanıcıdan gelirse SQL injection riski
   - **Not:** Çoğu yerde prepared statements kullanılıyor (iyi)

4. **Session Güvenliği**
   - **Dosya:** `src/bootstrap.php:26-32`
   - **Sorun:**
     - `secure` flag yok (HTTPS zorunluluğu yok)
     - Session fixation koruması yok
     - Session timeout yok
   - **Risk:** Session hijacking, man-in-the-middle saldırıları

5. **Şifre Hash Güvenliği**
   - **Dosya:** `public/index.php:539, 2791`
   - **Durum:** `password_hash($password, PASSWORD_DEFAULT)` kullanılıyor ✅
   - **Not:** İyi uygulanmış

6. **IP Hash Güvenliği**
   - **Dosya:** `public/index.php:97-100`
   - **Sorun:** IP hash'i tek doldurma kontrolü için kullanılıyor, ancak:
     - Proxy arkasında yanlış IP alınabilir
     - IP değişirse aynı kullanıcı tekrar doldurabilir
   - **Risk:** Çoklu doldurma atlatılabilir

7. **Cookie Güvenliği**
   - **Dosya:** `public/index.php:2129, 2215`
   - **Sorun:** Tek doldurma kontrolü için cookie kullanılıyor, ancak:
     - `secure` flag yok
     - `httponly` flag yok
     - Cookie silinirse tekrar doldurulabilir
   - **Risk:** Çoklu doldurma atlatılabilir

8. **Dosya Erişimi**
   - **Dosya:** `public/index.php:2029-2052`
   - **Sorun:** PDF dosyaları whitelist ile kontrol ediliyor, ancak:
     - Dosya yolu doğrulaması yetersiz
     - Path traversal kontrolü eksik olabilir
   - **Not:** Whitelist kullanımı iyi

9. **Kurulum Endpoint'i**
   - **Dosya:** `public/index.php:286-431`
   - **Sorun:** Kurulum sonrası endpoint hala erişilebilir (sadece `isInstalled()` kontrolü var)
   - **Risk:** Kurulum dosyası silinirse tekrar kurulum yapılabilir

#### 🟡 ORTA RİSK

10. **Input Validation Eksiklikleri**
    - **Dosya:** `public/index.php` (çeşitli yerler)
    - **Sorun:**
      - Bazı input'larda sadece `trim()` kullanılıyor
      - Email validasyonu yok (sadece `trim()`)
      - Sayısal değerler için `(int)` cast yapılıyor, ancak negatif değerler kontrol edilmiyor
    - **Risk:** Geçersiz veri girişi, veri bütünlüğü sorunları

11. **Rate Limiting Yok**
    - **Sorun:** Login, anket gönderimi gibi endpoint'lerde rate limiting yok
    - **Risk:** Brute force saldırıları, DDoS

12. **XSS Koruması**
    - **Dosya:** `src/view.php:8-11`
    - **Durum:** `View::e()` ile HTML escape yapılıyor ✅
    - **Not:** İyi uygulanmış, ancak her yerde kullanılmıyor olabilir

13. **Authorization Kontrolleri**
    - **Sorun:** Bazı endpoint'lerde yetki kontrolü eksik olabilir
    - **Durum:** `requireSchoolAdmin()` ve `requireSiteAdmin()` fonksiyonları var ✅
    - **Not:** Çoğu yerde kullanılıyor

### 4.2 Güvenlik İyi Uygulamalar

✅ Prepared statements kullanımı (çoğu yerde)  
✅ CSRF token kontrolü (POST istekleri için)  
✅ Password hashing (PASSWORD_DEFAULT)  
✅ HTML escaping (View::e)  
✅ Session httponly ve samesite ayarları  
✅ Whitelist tabanlı dosya erişimi

---

## 5. HATA YÖNETİMİ RİSKLERİ

### 5.1 Kritik Sorunlar

1. **Production'da Hata Gösterimi**
   - **Dosya:** `src/bootstrap.php:14-16`
   - **Sorun:** Tüm hatalar ekrana yazdırılıyor
   - **Etki:** Kullanıcılar hata detaylarını görebilir, sistem bilgileri ifşa olabilir

2. **Hata Loglama Yok**
   - **Sorun:** Hata loglama mekanizması yok
   - **Etki:** Production'da hatalar takip edilemez

3. **Exception Handling Eksik**
   - **Sorun:** Çoğu yerde try-catch yok
   - **Etki:** Beklenmeyen hatalar kullanıcıya gösterilir veya uygulama çöker

4. **Veritabanı Hata Yönetimi**
   - **Sorun:** PDO exception'ları yakalanmıyor (çoğu yerde)
   - **Etki:** Veritabanı hataları kullanıcıya gösterilir

5. **Transaction Yönetimi**
   - **Durum:** Bazı yerlerde transaction kullanılıyor (anket gönderimi) ✅
   - **Sorun:** Tüm kritik işlemlerde transaction yok
   - **Etki:** Veri tutarsızlığı riski

### 5.2 Hata Mesajları

- **Sorun:** Hata mesajları Türkçe, ancak teknik detaylar içerebilir
- **Örnek:** `Http::text(500, "APP_KEY ayarı eksik.\n");` - Sistem bilgisi ifşa ediyor

---

## 6. PERFORMANS RİSKLERİ

### 6.1 Veritabanı Performansı

1. **N+1 Query Problemi**
   - **Sorun:** Bazı yerlerde döngü içinde sorgu yapılıyor olabilir
   - **Etki:** Yavaş sorgu performansı

2. **Eksik İndeksler**
   - **Sorun:** `form_instances.public_id` için index yok (sık sorgulanıyor)
   - **Etki:** Anket formu yükleme yavaşlayabilir

3. **Query Optimization**
   - **Sorun:** Bazı sorgularda gereksiz JOIN'ler olabilir
   - **Not:** Detaylı analiz gerekiyor

4. **Connection Pooling Yok**
   - **Sorun:** Her istekte aynı bağlantı kullanılıyor (singleton), ancak connection pooling yok
   - **Etki:** Yüksek trafikte performans sorunları

### 6.2 Kod Performansı

1. **Büyük Router Dosyası**
   - **Sorun:** 2942 satırlık tek dosya
   - **Etki:** Her istekte tüm dosya parse ediliyor (OPcache ile azalır)

2. **Route Matching**
   - **Sorun:** Sıralı if blokları, ilk eşleşmeyi bulana kadar tüm route'lar kontrol ediliyor
   - **Etki:** Route sayısı arttıkça performans düşer

3. **Session Kullanımı**
   - **Sorun:** Her istekte session başlatılıyor
   - **Etki:** Gereksiz I/O

4. **Dosya Okuma**
   - **Sorun:** `config/riba_scoring.php` her istekte okunuyor (static cache var, ancak ilk okumada)
   - **Etki:** İlk istekte yavaşlama

### 6.3 Önbellekleme

- **Sorun:** Önbellekleme mekanizması yok (APCu, Redis, vb.)
- **Etki:** Tekrarlanan sorgular her seferinde çalıştırılıyor

---

## 7. İYİLEŞTİRME ÖNERİLERİ

### 7.1 Güvenlik İyileştirmeleri (Öncelik: YÜKSEK)

#### 🔴 Acil Düzeltilmesi Gerekenler

1. **Production Hata Ayıklama Kapatılmalı**
   ```php
   // src/bootstrap.php
   $env = \App\Env::get('APP_ENV', 'production');
   if ($env === 'production') {
       ini_set('display_errors', '0');
       ini_set('display_startup_errors', '0');
       error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
   } else {
       ini_set('display_errors', '1');
       ini_set('display_startup_errors', '1');
       error_reporting(E_ALL);
   }
   ```

2. **Hata Loglama Eklenecek**
   ```php
   // src/bootstrap.php
   ini_set('log_errors', '1');
   ini_set('error_log', __DIR__ . '/../storage/logs/php-errors.log');
   ```

3. **Session Güvenliği İyileştirilecek**
   ```php
   // src/bootstrap.php
   session_set_cookie_params([
       'httponly' => true,
       'samesite' => 'Lax',
       'secure' => ($_SERVER['HTTPS'] ?? '') === 'on', // HTTPS kontrolü
   ]);
   ```

4. **SQL Injection Riski Düzeltilecek**
   ```php
   // public/index.php:244
   // query() yerine prepare() kullanılmalı
   $stmt = $pdo->prepare('SELECT ... WHERE active = :active ORDER BY ...');
   $stmt->execute([':active' => 1]);
   ```

5. **Cookie Güvenliği İyileştirilecek**
   ```php
   // public/index.php:2129
   setcookie($cookieName, '1', time() + 86400 * 365, '/', '', 
       ($_SERVER['HTTPS'] ?? '') === 'on', true); // secure, httponly
   ```

#### 🟡 Orta Öncelikli

6. **Input Validation Kütüphanesi**
   - Email, sayı, tarih validasyonu için yardımcı fonksiyonlar
   - Filter extension kullanımı

7. **Rate Limiting**
   - Login endpoint'leri için rate limiting
   - Anket gönderimi için rate limiting
   - IP bazlı veya session bazlı

8. **CSRF Token Rotation**
   - Her form gönderiminde token yenileme
   - Double submit cookie pattern

### 7.2 Hata Yönetimi İyileştirmeleri

1. **Global Exception Handler**
   ```php
   set_exception_handler(function (\Throwable $e) {
       error_log($e->getMessage() . "\n" . $e->getTraceAsString());
       if (APP_ENV === 'production') {
           Http::text(500, "Bir hata oluştu.\n");
       } else {
           Http::text(500, $e->getMessage() . "\n");
       }
   });
   ```

2. **Error Handler**
   ```php
   set_error_handler(function ($severity, $message, $file, $line) {
       if (!(error_reporting() & $severity)) {
           return false;
       }
       throw new \ErrorException($message, 0, $severity, $file, $line);
   });
   ```

3. **Try-Catch Blokları**
   - Kritik işlemlerde try-catch eklenmeli
   - Veritabanı işlemlerinde exception handling

### 7.3 Performans İyileştirmeleri

1. **Route Optimizasyonu**
   - Route'ları array'e taşıma
   - Regex öncelik sıralaması
   - En sık kullanılan route'ları üste alma

2. **Veritabanı İndeksleri**
   ```sql
   CREATE INDEX idx_form_instances_public_id ON form_instances(public_id);
   CREATE INDEX idx_responses_form_instance_ip ON responses(form_instance_id, ip_hash);
   ```

3. **Önbellekleme**
   - APCu veya Redis entegrasyonu
   - Sık sorgulanan veriler için cache

4. **OPcache**
   - PHP OPcache aktif edilmeli
   - Production'da zorunlu

### 7.4 Kod Organizasyonu İyileştirmeleri

1. **Router Ayrımı**
   - Route tanımlarını ayrı dosyaya taşıma
   - Route handler'ları ayrı dosyalara bölme

2. **Controller Pattern**
   - Her route grubu için controller sınıfı
   - İş mantığını controller'lara taşıma

3. **Middleware Pattern**
   - Authentication middleware
   - CSRF middleware
   - Rate limiting middleware

### 7.5 Küçük ve Kontrollü İyileştirmeler (Bozmadan)

1. **Config Validation**
   ```php
   // src/env.php
   public static function getRequired(string $key): string {
       $val = self::get($key);
       if ($val === null || $val === '') {
           throw new \RuntimeException("Required env var missing: {$key}");
       }
       return $val;
   }
   ```

2. **Helper Fonksiyonlar**
   ```php
   // src/helpers.php
   function validateEmail(string $email): bool {
       return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
   }
   
   function validateInt(string $value, int $min = null, int $max = null): ?int {
       $int = filter_var($value, FILTER_VALIDATE_INT);
       if ($int === false) return null;
       if ($min !== null && $int < $min) return null;
       if ($max !== null && $int > $max) return null;
       return $int;
   }
   ```

3. **Response Helper İyileştirmeleri**
   ```php
   // src/http.php
   public static function json(int $status, array $data): void {
       http_response_code($status);
       header('Content-Type: application/json; charset=utf-8');
       echo json_encode($data, JSON_UNESCAPED_UNICODE);
   }
   ```

4. **Logging Helper**
   ```php
   // src/logger.php
   final class Logger {
       public static function error(string $message, array $context = []): void {
           $log = date('Y-m-d H:i:s') . " ERROR: {$message}";
           if (!empty($context)) {
               $log .= " " . json_encode($context);
           }
           error_log($log . "\n", 3, __DIR__ . '/../storage/logs/app.log');
       }
   }
   ```

5. **Database Helper İyileştirmeleri**
   ```php
   // src/db.php
   public static function transaction(callable $callback) {
       $pdo = self::pdo();
       $pdo->beginTransaction();
       try {
           $result = $callback($pdo);
           $pdo->commit();
           return $result;
       } catch (\Throwable $e) {
           $pdo->rollBack();
           throw $e;
       }
   }
   ```

---

## 8. ÖNCELİK SIRASI

### Faz 1: Kritik Güvenlik (Hemen)
1. Production hata gösterimi kapatılmalı
2. Hata loglama eklenmeli
3. Session secure flag eklenmeli
4. SQL injection riski düzeltilmeli

### Faz 2: Güvenlik İyileştirmeleri (1-2 Hafta)
5. Cookie güvenliği
6. Input validation
7. Rate limiting (login, anket gönderimi)

### Faz 3: Hata Yönetimi (2-3 Hafta)
8. Global exception handler
9. Try-catch blokları
10. Transaction yönetimi

### Faz 4: Performans (1 Ay)
11. Veritabanı indeksleri
12. Route optimizasyonu
13. Önbellekleme

### Faz 5: Kod Organizasyonu (İsteğe Bağlı)
14. Router ayrımı
15. Controller pattern
16. Middleware pattern

---

## 9. SONUÇ

Bu proje, düz PHP ile yazılmış, işlevsel bir anket sistemidir. Temel güvenlik önlemleri (prepared statements, CSRF, password hashing) alınmış, ancak production'a hazır olmak için özellikle **güvenlik** ve **hata yönetimi** konularında iyileştirmeler gerekmektedir.

**En kritik sorun:** Production'da hata gösterimi açık olması. Bu, sistem bilgilerinin ifşasına ve güvenlik açıklarına yol açabilir.

**Önerilen yaklaşım:** Küçük, kontrollü adımlarla iyileştirmeler yapılmalı. Önce güvenlik sorunları giderilmeli, sonra performans ve kod organizasyonu iyileştirmeleri yapılmalıdır.

---

**Rapor Hazırlayan:** AI Assistant  
**Tarih:** 2026  
**Versiyon:** 1.0
