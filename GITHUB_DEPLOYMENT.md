# GitHub Deployment Rehberi - RİBA Anket Sistemi

## 📋 İçindekiler

1. [GitHub'a İlk Yükleme](#1-githuba-ilk-yükleme)
2. [Değişiklikleri GitHub'a Yükleme](#2-değişiklikleri-githuba-yükleme)
3. [AA Panel'de Otomatik Deploy](#3-aa-panelde-otomatik-deploy)
4. [Manuel Deploy](#4-manuel-deploy)
5. [Sorun Giderme](#5-sorun-giderme)

---

## 1. GitHub'a İlk Yükleme

### 1.1 GitHub Repository Oluşturma

1. **GitHub'da Yeni Repository Oluştur**
   - GitHub.com > New Repository
   - Repository Name: `Ribaanket2026`
   - Description: "RİBA Çok Okullu Anket Sistemi"
   - Public veya Private seçin
   - **Initialize with README seçmeyin** (zaten var)

2. **Repository URL'ini Kopyala**
   - Örnek: `https://github.com/kullaniciadi/Ribaanket2026.git`

### 1.2 Lokal Projeyi GitHub'a Bağlama

```bash
# Proje klasörüne git
cd /path/to/Ribaanket2026

# Git remote ekle (eğer yoksa)
git remote add origin https://github.com/kullaniciadi/Ribaanket2026.git

# Veya mevcut remote'u güncelle
git remote set-url origin https://github.com/kullaniciadi/Ribaanket2026.git
```

### 1.3 İlk Commit ve Push

```bash
# Tüm dosyaları ekle
git add .

# Commit yap
git commit -m "Initial commit: RİBA Anket Sistemi - OpenLiteSpeed yapılandırması"

# Dev branch'ini GitHub'a push et
git push -u origin dev

# Main branch'i oluştur ve push et (production için)
git checkout -b main
git push -u origin main
```

---

## 2. Değişiklikleri GitHub'a Yükleme

### 2.1 Günlük Çalışma Akışı

```bash
# 1. Değişiklikleri kontrol et
git status

# 2. Değişiklikleri stage'e al
git add .

# 3. Commit yap (anlamlı mesaj ile)
git commit -m "Açıklayıcı commit mesajı"

# 4. GitHub'a push et
git push origin dev
```

### 2.2 Commit Mesajı Örnekleri

```bash
# Yeni özellik
git commit -m "feat: OpenLiteSpeed yapılandırması eklendi"

# Hata düzeltme
git commit -m "fix: PDF servis yolu düzeltildi"

# Dokümantasyon
git commit -m "docs: Deployment rehberi güncellendi"

# Güvenlik
git commit -m "security: Production hata yönetimi eklendi"
```

### 2.3 Branch Yönetimi

```bash
# Dev branch'inde çalış
git checkout dev

# Yeni özellik için branch oluştur
git checkout -b feature/yeni-ozellik

# Değişiklikleri commit et
git add .
git commit -m "feat: Yeni özellik eklendi"

# GitHub'a push et
git push origin feature/yeni-ozellik

# Dev branch'e merge et (GitHub'da Pull Request ile)
```

---

## 3. AA Panel'de Otomatik Deploy

### 3.1 GitHub Webhook ile Otomatik Deploy

#### Adım 1: Deploy Script Oluştur

```bash
# Sunucuda deploy script oluştur
nano /home/your-user/deploy-riba.sh
```

**Deploy Script İçeriği:**

```bash
#!/bin/bash

# RİBA Anket Sistemi - Otomatik Deploy Script
# GitHub Webhook için

set -e  # Hata durumunda dur

# Proje dizini
PROJECT_DIR="/home/your-user/ribaanket2026"
BRANCH="main"  # veya "dev"

# Log dosyası
LOG_FILE="/home/your-user/deploy.log"

# Log fonksiyonu
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

log "Deploy başladı: $BRANCH branch"

# Proje dizinine git
cd "$PROJECT_DIR" || exit 1

# Git pull
log "Git pull yapılıyor..."
git fetch origin
git reset --hard "origin/$BRANCH"
git pull origin "$BRANCH"

# Composer bağımlılıklarını güncelle
log "Composer bağımlılıkları güncelleniyor..."
composer install --no-dev --optimize-autoloader --quiet

# İzinleri ayarla
log "Dosya izinleri ayarlanıyor..."
chmod -R 755 storage
chmod -R 755 public
chown -R lsadm:lsadm storage  # OpenLiteSpeed için

# Veritabanı güncellemeleri (varsa)
if [ -f "config/schema_update.sql" ]; then
    log "Veritabanı güncelleniyor..."
    mysql -u riba_user -p'DB_PASSWORD' riba < config/schema_update.sql
fi

# OpenLiteSpeed'i yeniden başlat (gerekirse)
# systemctl restart lsws

log "Deploy tamamlandı!"
```

**Script'i çalıştırılabilir yap:**

```bash
chmod +x /home/your-user/deploy-riba.sh
```

#### Adım 2: GitHub Webhook Oluştur

1. **GitHub Repository > Settings > Webhooks > Add webhook**

2. **Webhook Ayarları:**
   - **Payload URL:** `https://your-domain.com/webhook/deploy` (veya özel endpoint)
   - **Content type:** `application/json`
   - **Secret:** Güçlü bir secret oluştur (örn: `openssl rand -hex 32`)
   - **Events:** "Just the push event" seçin
   - **Active:** ✅

3. **Webhook Endpoint Oluştur (Opsiyonel)**

   Eğer webhook endpoint'i oluşturmak isterseniz:

   ```php
   // public/webhook/deploy.php (güvenlik için IP kontrolü ekleyin)
   <?php
   $secret = 'your-webhook-secret';
   $payload = file_get_contents('php://input');
   $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
   
   $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
   
   if (!hash_equals($expected, $signature)) {
       http_response_code(403);
       exit('Invalid signature');
   }
   
   // Sadece main branch için deploy
   $data = json_decode($payload, true);
   if ($data['ref'] !== 'refs/heads/main') {
       exit('Not main branch');
   }
   
   // Deploy script'i çalıştır
   exec('/home/your-user/deploy-riba.sh > /dev/null 2>&1 &');
   echo 'Deploy started';
   ```

### 3.2 AA Panel Git Deploy Eklentisi (Alternatif)

AA Panel'de Git Deploy eklentisi varsa:

1. **AA Panel > Git Deploy**
2. **Repository URL:** `https://github.com/kullaniciadi/Ribaanket2026.git`
3. **Branch:** `main` veya `dev`
4. **Deploy Path:** `/home/your-user/ribaanket2026`
5. **Auto Deploy:** Aktif et

---

## 4. Manuel Deploy

### 4.1 Sunucuda Manuel Deploy

```bash
# 1. Sunucuya SSH ile bağlan
ssh user@your-server-ip

# 2. Proje dizinine git
cd /home/your-user/ribaanket2026

# 3. Git pull
git fetch origin
git pull origin main  # veya dev

# 4. Composer güncelle
composer install --no-dev --optimize-autoloader

# 5. İzinleri ayarla
chmod -R 755 storage
chown -R lsadm:lsadm storage

# 6. Veritabanı güncellemeleri (varsa)
mysql -u riba_user -p riba < config/schema_update.sql

# 7. OpenLiteSpeed'i yeniden başlat (gerekirse)
systemctl restart lsws
```

### 4.2 Cron Job ile Otomatik Pull (Basit Yöntem)

```bash
# Cron job oluştur (her 5 dakikada bir kontrol)
crontab -e

# Ekleyin:
*/5 * * * * cd /home/your-user/ribaanket2026 && git pull origin main >> /home/your-user/git-pull.log 2>&1
```

**Not:** Bu yöntem güvenli değildir, webhook kullanmanız önerilir.

---

## 5. Production Deployment Checklist

### 5.1 Deploy Öncesi

- [ ] Tüm değişiklikler `dev` branch'inde test edildi
- [ ] `main` branch'e merge edildi
- [ ] `.env` dosyası production değerleriyle güncellendi
- [ ] Veritabanı yedek alındı
- [ ] Dosya yedekleri alındı

### 5.2 Deploy Sırasında

- [ ] Git pull yapıldı
- [ ] Composer bağımlılıkları güncellendi
- [ ] Veritabanı migration'ları çalıştırıldı (varsa)
- [ ] Dosya izinleri kontrol edildi
- [ ] Cache temizlendi (OPcache)

### 5.3 Deploy Sonrası

- [ ] Health check: `https://your-domain.com/health`
- [ ] Login testi yapıldı
- [ ] Anket formu test edildi
- [ ] Log dosyaları kontrol edildi
- [ ] Performans testi yapıldı

---

## 6. Sorun Giderme

### 6.1 Git Pull Hataları

**Hata: "Your local changes would be overwritten"**

```bash
# Değişiklikleri stash'le
git stash

# Pull yap
git pull origin main

# Stash'i geri getir (gerekirse)
git stash pop
```

**Hata: "Permission denied"**

```bash
# SSH key ekle
ssh-keygen -t ed25519 -C "your-email@example.com"
cat ~/.ssh/id_ed25519.pub
# GitHub > Settings > SSH Keys > Add SSH Key
```

### 6.2 Deploy Script Hataları

**Log Kontrolü:**

```bash
tail -f /home/your-user/deploy.log
```

**Manuel Test:**

```bash
# Deploy script'i manuel çalıştır
bash /home/your-user/deploy-riba.sh
```

### 6.3 Composer Hataları

**Hata: "Composer not found"**

```bash
# Composer'ı global yükle
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
```

**Hata: "Memory limit"**

```bash
# PHP memory limit'i artır
php -d memory_limit=512M /usr/local/bin/composer install
```

---

## 7. Güvenlik Notları

### 7.1 .env Dosyası

- ✅ `.env` dosyası **asla** GitHub'a commit edilmez
- ✅ `.gitignore` içinde tanımlı
- ✅ Sunucuda manuel oluşturulmalı

### 7.2 Webhook Secret

- ✅ Webhook secret güçlü olmalı
- ✅ GitHub ve sunucuda aynı secret kullanılmalı
- ✅ Secret'ı environment variable olarak saklayın

### 7.3 SSH Keys

- ✅ Deploy için SSH key kullanın (şifre yerine)
- ✅ SSH key'i GitHub'a ekleyin
- ✅ Private key'i güvenli saklayın

---

## 8. Hızlı Komutlar

### 8.1 Günlük Kullanım

```bash
# Değişiklikleri göster
git status

# Değişiklikleri ekle ve commit et
git add . && git commit -m "Mesaj"

# GitHub'a push et
git push origin dev

# Son commit'i geri al (lokal)
git reset --soft HEAD~1
```

### 8.2 Branch İşlemleri

```bash
# Branch listesi
git branch -a

# Yeni branch oluştur
git checkout -b feature/yeni-ozellik

# Branch değiştir
git checkout dev

# Branch'i sil
git branch -d feature/yeni-ozellik
```

### 8.3 Sunucu Komutları

```bash
# Hızlı deploy
cd /home/your-user/ribaanket2026 && git pull && composer install --no-dev --optimize-autoloader

# Log kontrolü
tail -f storage/logs/php-errors.log

# İzin kontrolü
ls -la storage/
```

---

## 9. GitHub Actions (Opsiyonel - İleri Seviye)

GitHub Actions ile otomatik test ve deploy:

```yaml
# .github/workflows/deploy.yml
name: Deploy to Production

on:
  push:
    branches: [ main ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Deploy to server
        uses: appleboy/ssh-action@master
        with:
          host: ${{ secrets.HOST }}
          username: ${{ secrets.USERNAME }}
          key: ${{ secrets.SSH_KEY }}
          script: |
            cd /home/your-user/ribaanket2026
            git pull origin main
            composer install --no-dev --optimize-autoloader
            chmod -R 755 storage
```

---

## 10. Destek

- **Git Sorunları:** GitHub Docs
- **Deploy Sorunları:** `DEPLOYMENT_OPENLITESPEED.md`
- **Log Dosyaları:** `storage/logs/php-errors.log`

---

**Son Güncelleme:** 2026  
**Versiyon:** 1.0
