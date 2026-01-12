# AA Panel ve Google Cloud Deployment Rehberi

## 1. Sunucu Gereksinimleri

### Minimum Gereksinimler
- PHP >= 8.1
- MariaDB/MySQL >= 10.3
- Apache 2.4+ veya Nginx 1.18+
- Composer
- PhpSpreadsheet kütüphanesi

### Önerilen Gereksinimler (Google Cloud)
- 2 vCPU
- 4 GB RAM
- 20 GB SSD
- PHP 8.2+
- OPcache aktif

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
   chown -R www-data:www-data storage
   ```

### 2.2 AA Panel Site Oluşturma

1. **AA Panel'de Yeni Site Oluştur**
   - Domain: `your-domain.com`
   - Document Root: `/home/your-user/ribaanket2026/public`
   - PHP Version: 8.1 veya üzeri

2. **PHP Ayarları (AA Panel > PHP Settings)**
   ```
   upload_max_filesize = 10M
   post_max_size = 10M
   max_execution_time = 300
   max_input_time = 300
   memory_limit = 256M
   ```

3. **OPcache Aktif Et (Production için zorunlu)**
   ```
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.interned_strings_buffer=8
   opcache.max_accelerated_files=10000
   opcache.revalidate_freq=2
   opcache.fast_shutdown=1
   ```

### 2.3 Veritabanı Kurulumu

1. **AA Panel > Databases > Create Database**
   - Database Name: `riba`
   - User: `riba_user`
   - Password: Güçlü bir şifre oluştur

2. **Şemayı Yükle**
   ```bash
   mysql -u riba_user -p riba < config/schema.sql
   ```

### 2.4 Ortam Değişkenleri (.env)

1. **.env Dosyası Oluştur**
   ```bash
   cd /home/your-user/ribaanket2026
   cp config/env.example .env
   nano .env
   ```

2. **.env İçeriği (Google Cloud için)**
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
   chown www-data:www-data .env
   ```

### 2.5 SSL Sertifikası (Let's Encrypt)

1. **AA Panel > SSL > Let's Encrypt**
   - Domain seç
   - "Force HTTPS" aktif et
   - Otomatik yenileme aktif et

---

## 3. Google Cloud Optimizasyonları

### 3.1 Firewall Kuralları

Google Cloud Console > VPC Network > Firewall Rules:
- HTTP (80): Allow
- HTTPS (443): Allow
- SSH (22): Allow (sadece gerekli IP'lerden)
- Diğer portlar: Deny

### 3.2 Cloud SQL (Opsiyonel - Yüksek Trafik İçin)

Eğer yüksek trafik bekleniyorsa, Cloud SQL kullanılabilir:

1. **Cloud SQL Instance Oluştur**
   - Engine: MySQL 8.0 veya MariaDB 10.6
   - Region: VM ile aynı region
   - Machine Type: db-f1-micro (başlangıç)

2. **.env Güncelle**
   ```env
   DB_HOST=cloud-sql-ip-address
   DB_PORT=3306
   ```

### 3.3 Monitoring ve Logging

1. **Google Cloud Logging**
   - Application logları: `storage/logs/php-errors.log`
   - Nginx/Apache logları: `/var/log/nginx/` veya `/var/log/apache2/`

2. **Uptime Monitoring**
   - Health check endpoint: `https://your-domain.com/health`
   - Google Cloud Monitoring ile izleme

### 3.4 Backup Stratejisi

1. **Veritabanı Yedekleme (Cron Job)**
   ```bash
   # /etc/cron.daily/riba-backup
   #!/bin/bash
   mysqldump -u riba_user -p'password' riba > /backup/riba-$(date +%Y%m%d).sql
   # Son 7 günü sakla
   find /backup -name "riba-*.sql" -mtime +7 -delete
   ```

2. **Dosya Yedekleme**
   ```bash
   # storage/ klasörü yedeklenmeli
   tar -czf /backup/riba-storage-$(date +%Y%m%d).tar.gz /home/your-user/ribaanket2026/storage/
   ```

---

## 4. Güvenlik Kontrolleri

### 4.1 Dosya İzinleri Kontrolü

```bash
# Hassas dosyalar
chmod 600 .env
chmod 600 storage/logs/*.log

# Klasörler
chmod 755 public
chmod 755 storage
chmod 755 storage/pdfs
chmod 755 storage/logs
```

### 4.2 Firewall Kontrolü

```bash
# UFW (Ubuntu)
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### 4.3 Fail2Ban (Opsiyonel)

Brute force saldırılarına karşı koruma:
```bash
sudo apt install fail2ban
# /etc/fail2ban/jail.local yapılandırması
```

---

## 5. Performans Optimizasyonları

### 5.1 OPcache Ayarları (php.ini)

```ini
[opcache]
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
opcache.enable_cli=0
```

### 5.2 MySQL/MariaDB Optimizasyonları

```sql
-- Veritabanı indeksleri (schema.sql'de mevcut, kontrol edin)
-- Eksik indeksler için:
CREATE INDEX IF NOT EXISTS idx_form_instances_public_id ON form_instances(public_id);
CREATE INDEX IF NOT EXISTS idx_responses_form_instance_ip ON responses(form_instance_id, ip_hash);
```

### 5.3 Nginx/Apache Optimizasyonları

- Gzip sıkıştırma aktif
- Statik dosya önbellekleme
- Keep-alive bağlantıları

---

## 6. Test ve Doğrulama

### 6.1 Kurulum Testi

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

### 6.2 Performans Testi

```bash
# Apache Bench (yük testi)
ab -n 1000 -c 10 https://your-domain.com/health
```

### 6.3 Güvenlik Testi

- SSL Labs: https://www.ssllabs.com/ssltest/
- Security Headers: https://securityheaders.com/

---

## 7. Sorun Giderme

### 7.1 Yaygın Hatalar

**Hata: "APP_KEY ayarı eksik"**
- Çözüm: `.env` dosyasında `APP_KEY` tanımlı olmalı

**Hata: "Database connection failed"**
- Çözüm: Veritabanı bilgilerini kontrol et, firewall kurallarını kontrol et

**Hata: "Permission denied"**
- Çözüm: `storage/` klasörüne yazma izni ver: `chmod -R 755 storage`

**Hata: "PDF not found"**
- Çözüm: PDF dosyalarının `storage/pdfs/` klasöründe olduğundan emin ol

### 7.2 Log Dosyaları

- PHP Hataları: `storage/logs/php-errors.log`
- Mail Hataları: `storage/mail.log`
- Nginx/Apache: `/var/log/nginx/` veya `/var/log/apache2/`

---

## 8. Güncelleme Prosedürü

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

# 5. Cache temizle (OPcache)
# Apache: service apache2 reload
# Nginx: service nginx reload
```

---

## 9. Destek ve İletişim

- Dokümantasyon: `README.md`
- Analiz Raporu: `ANALIZ_RAPORU.md`
- Log Dosyaları: `storage/logs/`

---

**Son Güncelleme:** 2026  
**Versiyon:** 1.0
