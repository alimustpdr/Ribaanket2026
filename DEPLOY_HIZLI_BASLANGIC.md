# 🚀 Hızlı Deployment Başlangıç Rehberi

## GitHub'dan Sunucuya Deploy

### 1️⃣ GitHub'a Yükleme (İlk Kez)

```bash
# Lokal projede
git add .
git commit -m "İlk commit"
git push origin dev
```

### 2️⃣ Sunucuda İlk Kurulum

```bash
# Sunucuya SSH ile bağlan
ssh user@your-server-ip

# Proje dizinine git
cd /home/your-user/

# GitHub'dan clone yap
git clone https://github.com/alimustpdr/Ribaanket2026.git
cd Ribaanket2026

# Composer bağımlılıklarını yükle
composer install --no-dev --optimize-autoloader

# .env dosyası oluştur
cp config/env.example .env
nano .env  # DB bilgileri, APP_KEY, vb. düzenle

# Veritabanını kur
mysql -u root -p < config/schema.sql

# İzinleri ayarla
chmod -R 755 storage
chown -R lsadm:lsadm storage
```

### 3️⃣ AA Panel'de Site Oluştur

1. **AA Panel > Websites > Add Site**
   - Domain: `your-domain.com`
   - Document Root: `/home/your-user/Ribaanket2026/public`
   - PHP Version: 8.1+

2. **SSL Kurulumu**
   - AA Panel > SSL > Let's Encrypt

3. **Kurulum Sihirbazı**
   - Tarayıcıda: `https://your-domain.com/setup`

### 4️⃣ Güncellemeleri Çekme

```bash
# Sunucuda
cd /home/your-user/Ribaanket2026
git pull origin dev  # veya main
composer install --no-dev --optimize-autoloader
```

---

## 📚 Detaylı Rehberler

- **GitHub Deployment:** [GITHUB_DEPLOYMENT.md](GITHUB_DEPLOYMENT.md)
- **OpenLiteSpeed:** [DEPLOYMENT_OPENLITESPEED.md](DEPLOYMENT_OPENLITESPEED.md)
- **Genel Deployment:** [DEPLOYMENT_AA_PANEL.md](DEPLOYMENT_AA_PANEL.md)

---

**Hızlı Komutlar:**

```bash
# Değişiklikleri GitHub'a yükle
git add . && git commit -m "Mesaj" && git push origin dev

# Sunucuda güncelle
cd /path/to/project && git pull && composer install --no-dev --optimize-autoloader
```
