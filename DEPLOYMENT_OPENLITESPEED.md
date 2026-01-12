# OpenLiteSpeed 1.8.4 ve AA Panel Deployment Rehberi

## 1. OpenLiteSpeed Özellikleri

OpenLiteSpeed 1.8.4, AA Panel'de varsayılan web sunucusu olarak kullanılır. Bu rehber, RİBA Anket Sistemi'ni OpenLiteSpeed üzerinde çalıştırmak için özel yapılandırmalar içerir.

### OpenLiteSpeed Avantajları
- Yüksek performans
- Düşük kaynak kullanımı
- Kolay yönetim (Web Admin Console)
- PHP-FPM entegrasyonu
- Otomatik SSL (Let's Encrypt)

---

## 2. AA Panel Kurulumu

### 2.1 Proje Yükleme

1. **Proje Dosyalarını Yükle**
   ```bash
   cd /home/your-user/
   git clone https://github.com/your-repo/ribaanket2026.git
   cd ribaanket2026
   ```

2. **Composer Bağımlılıklarını Yükle**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Klasör İzinlerini Ayarla**
   ```bash
   chmod -R 755 storage
   chmod -R 755 public
   chown -R lsadm:lsadm storage  # OpenLiteSpeed kullanıcısı
   ```

### 2.2 AA Panel Site Oluşturma

1. **AA Panel'de Yeni Site Oluştur**
   - Domain: `your-domain.com`
   - Document Root: `/home/your-user/ribaanket2026/public`
   - PHP Version: 8.1 veya üzeri (OpenLiteSpeed PHP seçici)

2. **OpenLiteSpeed Web Admin Console Ayarları**
   
   **Erişim:**
   - URL: `https://your-server-ip:7080`
   - Veya: AA Panel > OpenLiteSpeed > Web Admin Console
   
   **Site Ayarları:**
   - Listeners > Virtual Hosts > your-domain.com
   - Document Root: `/home/your-user/ribaanket2026/public`
   - Index Files: `index.php, index.html`
   - Enable Scripts: `Yes`
   - Follow Symbolic Link: `Yes`

3. **PHP Ayarları (OpenLiteSpeed Web Admin Console)**
   
   **PHP Settings:**
   ```
   upload_max_filesize = 10M
   post_max_size = 10M
   max_execution_time = 300
   max_input_time = 300
   memory_limit = 256M
   ```
   
   **OPcache Ayarları (Production için zorunlu):**
   ```
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.interned_strings_buffer=8
   opcache.max_accelerated_files=10000
   opcache.revalidate_freq=2
   opcache.fast_shutdown=1
   opcache.enable_cli=0
   ```

4. **Rewrite Kuralları**
   - `.htaccess` dosyası otomatik olarak okunur
   - Eğer çalışmazsa, Web Admin Console'dan manuel ekleyin:
     - Virtual Hosts > your-domain.com > Rewrite > Enable Rewrite: `Yes`
     - Rewrite Rules: `.htaccess` dosyasındaki kurallar otomatik yüklenir

### 2.3 Veritabanı Kurulumu

1. **AA Panel > Databases > Create Database**
   - Database Name: `riba`
   - User: `riba_user`
   - Password: Güçlü bir şifre oluştur

2. **Şemayı Yükle**
   ```bash
   mysql -u riba_user -p riba < config/schema.sql
   ```
   
   Veya phpMyAdmin üzerinden:
   - AA Panel > Databases > phpMyAdmin
   - `riba` veritabanını seç
   - Import > `config/schema.sql` dosyasını yükle

### 2.4 Ortam Değişkenleri (.env)

1. **.env Dosyası Oluştur**
   ```bash
   cd /home/your-user/ribaanket2026
   cp config/env.example .env
   nano .env
   ```

2. **.env İçeriği**
   ```env
   APP_ENV=production
   APP_KEY=your-64-character-random-string-here
   
   # Site yöneticisi
   ADMIN_EMAIL=admin@your-domain.com
   ADMIN_PASSWORD=your-secure-password
   
   # E-posta
   MAIL_FROM=no-reply@your-domain.com
   MAIL_FROM_NAME=RIBA
   
   # MariaDB (AA Panel'de oluşturduğunuz veritabanı)
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=riba
   DB_USER=riba_user
   DB_PASS=your-database-password
   ```

3. **APP_KEY Oluştur**
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```

4. **.env Dosyasını Koru**
   ```bash
   chmod 600 .env
   chown lsadm:lsadm .env  # OpenLiteSpeed kullanıcısı
   ```

### 2.5 SSL Sertifikası (Let's Encrypt)

1. **AA Panel > SSL > Let's Encrypt**
   - Domain seç
   - "Force HTTPS" aktif et
   - Otomatik yenileme aktif et

2. **OpenLiteSpeed Web Admin Console'da SSL Ayarları**
   - Listeners > your-domain.com > SSL
   - SSL Certificate: Let's Encrypt sertifikası otomatik yüklenir
   - SSL Private Key: Otomatik yüklenir

---

## 3. OpenLiteSpeed Özel Yapılandırmalar

### 3.1 Rewrite Kuralları Kontrolü

`.htaccess` dosyası genellikle otomatik çalışır, ancak kontrol etmek için:

1. **Web Admin Console > Virtual Hosts > your-domain.com**
2. **Rewrite > Enable Rewrite: `Yes`**
3. **Rewrite Rules:** `.htaccess` dosyasındaki kurallar otomatik yüklenir

Eğer `.htaccess` çalışmazsa, kuralları manuel ekleyin:

```
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

### 3.2 PHP-FPM Ayarları

OpenLiteSpeed, PHP-FPM kullanır. Ayarlar:

1. **Web Admin Console > Server > External App**
2. **PHP-FPM ayarlarını kontrol edin:**
   - Max Connections: 50
   - Initial Request Timeout: 60
   - Retry Timeout: 0

### 3.3 Güvenlik Başlıkları

`.htaccess` dosyasında güvenlik başlıkları tanımlı. Eğer çalışmazsa:

1. **Web Admin Console > Virtual Hosts > your-domain.com**
2. **Headers > Add Header:**
   ```
   X-XSS-Protection: 1; mode=block
   X-Frame-Options: SAMEORIGIN
   X-Content-Type-Options: nosniff
   Referrer-Policy: strict-origin-when-cross-origin
   ```

### 3.4 Gzip Sıkıştırma

OpenLiteSpeed'de Gzip genellikle otomatik aktif. Kontrol etmek için:

1. **Web Admin Console > Server > Tuning**
2. **Enable Compression: `Yes`**
3. **Compressible Types:** `text/html, text/plain, text/css, text/javascript, application/javascript, application/json`

### 3.5 Önbellekleme

OpenLiteSpeed'de önbellekleme ayarları:

1. **Web Admin Console > Virtual Hosts > your-domain.com**
2. **Cache > Enable Cache: `Yes`**
3. **Cache Expire Time:** 3600 (1 saat)

---

## 4. Google Cloud Optimizasyonları

### 4.1 Firewall Kuralları

Google Cloud Console > VPC Network > Firewall Rules:
- HTTP (80): Allow
- HTTPS (443): Allow
- SSH (22): Allow (sadece gerekli IP'lerden)
- OpenLiteSpeed Admin (7080): Allow (sadece yönetim IP'lerinden)
- Diğer portlar: Deny

### 4.2 Cloud SQL (Opsiyonel)

Eğer yüksek trafik bekleniyorsa:

1. **Cloud SQL Instance Oluştur**
   - Engine: MySQL 8.0 veya MariaDB 10.6
   - Region: VM ile aynı region

2. **.env Güncelle**
   ```env
   DB_HOST=cloud-sql-ip-address
   DB_PORT=3306
   ```

### 4.3 Monitoring

1. **Google Cloud Logging**
   - Application logları: `storage/logs/php-errors.log`
   - OpenLiteSpeed logları: `/usr/local/lsws/logs/`

2. **Uptime Monitoring**
   - Health check: `https://your-domain.com/health`

---

## 5. Performans Optimizasyonları

### 5.1 OPcache

OpenLiteSpeed Web Admin Console > PHP Settings:
```ini
[opcache]
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
```

### 5.2 OpenLiteSpeed Tuning

Web Admin Console > Server > Tuning:
- Max Connections: 1000
- Initial Request Timeout: 60
- Retry Timeout: 0
- Keep Alive Timeout: 5
- Max Keep Alive Requests: 100

### 5.3 Veritabanı İndeksleri

```sql
-- Eksik indeksler (varsa)
CREATE INDEX IF NOT EXISTS idx_form_instances_public_id ON form_instances(public_id);
CREATE INDEX IF NOT EXISTS idx_responses_form_instance_ip ON responses(form_instance_id, ip_hash);
```

---

## 6. Güvenlik Kontrolleri

### 6.1 Dosya İzinleri

```bash
# Hassas dosyalar
chmod 600 .env
chmod 600 storage/logs/*.log

# Klasörler
chmod 755 public
chmod 755 storage
chmod 755 storage/pdfs
chmod 755 storage/logs

# OpenLiteSpeed kullanıcısı
chown -R lsadm:lsadm storage
chown -R lsadm:lsadm .env
```

### 6.2 OpenLiteSpeed Admin Console Güvenliği

1. **Web Admin Console Şifresini Değiştir**
   - AA Panel > OpenLiteSpeed > Change Admin Password

2. **Admin Console Erişimini Kısıtla**
   - Firewall'da 7080 portunu sadece yönetim IP'lerinden açın

### 6.3 Fail2Ban (Opsiyonel)

```bash
sudo apt install fail2ban
# /etc/fail2ban/jail.local yapılandırması
```

---

## 7. Test ve Doğrulama

### 7.1 Kurulum Testi

1. **Health Check**
   ```bash
   curl https://your-domain.com/health
   # Beklenen: "ok\n"
   ```

2. **Kurulum Sihirbazı**
   - `https://your-domain.com/setup` (ilk kurulum için)

3. **Login Testi**
   - Site Admin: `/admin/login`
   - Okul Admin: `/login`

### 7.2 OpenLiteSpeed Log Kontrolü

```bash
# Hata logları
tail -f /usr/local/lsws/logs/error.log

# Erişim logları
tail -f /usr/local/lsws/logs/access.log
```

### 7.3 Performans Testi

```bash
# Apache Bench
ab -n 1000 -c 10 https://your-domain.com/health
```

---

## 8. Sorun Giderme

### 8.1 Yaygın Hatalar

**Hata: "500 Internal Server Error"**
- Çözüm: OpenLiteSpeed loglarını kontrol et: `/usr/local/lsws/logs/error.log`
- PHP hatalarını kontrol et: `storage/logs/php-errors.log`

**Hata: "Rewrite rules not working"**
- Çözüm: Web Admin Console > Virtual Hosts > Rewrite > Enable Rewrite: `Yes`
- `.htaccess` dosyasının okunabilir olduğundan emin ol: `chmod 644 public/.htaccess`

**Hata: "Permission denied"**
- Çözüm: Dosya sahipliğini kontrol et: `chown -R lsadm:lsadm storage`
- İzinleri kontrol et: `chmod -R 755 storage`

**Hata: "PDF not found"**
- Çözüm: PDF dosyalarının `storage/pdfs/` klasöründe olduğundan emin ol
- İzinleri kontrol et: `chmod 644 storage/pdfs/*.pdf`

### 8.2 OpenLiteSpeed Servis Yönetimi

```bash
# OpenLiteSpeed'i yeniden başlat
systemctl restart lsws

# Veya AA Panel üzerinden
# AA Panel > OpenLiteSpeed > Restart
```

### 8.3 PHP Ayarlarını Kontrol Et

```bash
# PHP bilgilerini görüntüle
php -i | grep opcache
php -i | grep memory_limit
php -i | grep upload_max_filesize
```

---

## 9. Güncelleme Prosedürü

```bash
# 1. Yedek al
mysqldump -u riba_user -p riba > backup.sql
tar -czf storage-backup.tar.gz storage/

# 2. Kod güncelle
git pull origin main

# 3. Bağımlılıkları güncelle
composer install --no-dev --optimize-autoloader

# 4. Veritabanı güncellemeleri (varsa)
mysql -u riba_user -p riba < config/schema_update.sql

# 5. OpenLiteSpeed'i yeniden başlat
systemctl restart lsws
# Veya Web Admin Console > Actions > Graceful Restart
```

---

## 10. OpenLiteSpeed Web Admin Console Erişimi

### 10.1 İlk Erişim

1. **AA Panel > OpenLiteSpeed > Web Admin Console**
2. Veya: `https://your-server-ip:7080`
3. Varsayılan kullanıcı: `admin`
4. Varsayılan şifre: AA Panel'de gösterilir

### 10.2 Önemli Ayarlar

- **Server > Tuning:** Performans ayarları
- **Virtual Hosts > your-domain.com:** Site ayarları
- **External App > PHP-FPM:** PHP ayarları
- **Listeners:** Port ve SSL ayarları

---

## 11. Destek ve İletişim

- **Dokümantasyon:** `README.md`
- **Analiz Raporu:** `ANALIZ_RAPORU.md`
- **Log Dosyaları:** 
  - PHP: `storage/logs/php-errors.log`
  - OpenLiteSpeed: `/usr/local/lsws/logs/`

---

**Son Güncelleme:** 2026  
**Versiyon:** 1.0  
**OpenLiteSpeed:** 1.8.4
