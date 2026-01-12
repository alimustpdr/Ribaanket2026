# RİBA Anket Sistemi 2026

**Çok okullu** RİBA anket sistemi - Custom PHP (Framework yok)

## 📋 Özellikler

- ✅ 11 sabit anket formu (Okul öncesi, İlkokul, Ortaokul, Lise)
- ✅ Çok okullu yönetim sistemi
- ✅ Anket dönemi ve kota yönetimi
- ✅ Otomatik raporlama ve Excel export
- ✅ Google Cloud ve AA Panel desteği
- ✅ Production-ready güvenlik ayarları

## 🚀 Hızlı Başlangıç

### Gereksinimler

- PHP >= 8.1
- MariaDB/MySQL >= 10.3
- Composer
- Apache 2.4+ veya Nginx 1.18+

### Kurulum

1. **Projeyi İndir**
   ```bash
   git clone https://github.com/your-repo/ribaanket2026.git
   cd ribaanket2026
   ```

2. **Bağımlılıkları Yükle**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Ortam Değişkenlerini Ayarla**
   ```bash
   cp config/env.example .env
   nano .env  # APP_KEY, DB bilgileri, vb. düzenle
   ```

4. **Veritabanını Kur**
   ```bash
   mysql -u root -p < config/schema.sql
   ```

5. **Document Root Ayarla**
   - Apache/Nginx: Document root'u `public/` klasörü yapın
   - AA Panel: Site oluştururken document root: `/path/to/ribaanket2026/public`

6. **Kurulum Sihirbazını Çalıştır**
   - Tarayıcıda: `https://your-domain.com/setup`

## 📚 Dokümantasyon

- **Detaylı Kurulum:** [DEPLOYMENT_AA_PANEL.md](DEPLOYMENT_AA_PANEL.md) - AA Panel ve Google Cloud deployment rehberi
- **Analiz Raporu:** [ANALIZ_RAPORU.md](ANALIZ_RAPORU.md) - Güvenlik, performans ve iyileştirme önerileri
- **Nginx Yapılandırması:** [nginx.conf.example](nginx.conf.example) - Nginx için örnek yapılandırma

## 📁 Proje Yapısı

```
ribaanket2026/
├── public/              # Document root (web erişimi)
│   ├── index.php        # Ana router
│   └── .htaccess        # Apache yapılandırması
├── src/                 # Kaynak kodlar
│   ├── bootstrap.php    # Başlangıç yapılandırması
│   ├── db.php           # Veritabanı bağlantısı
│   └── ...
├── config/              # Yapılandırma dosyaları
│   ├── schema.sql       # Veritabanı şeması
│   └── env.example      # Ortam değişkenleri şablonu
├── storage/             # Log ve dosyalar
│   ├── pdfs/           # PDF anket formları (11 adet)
│   └── logs/            # Log dosyaları
└── .env                 # Ortam değişkenleri (git'e commit edilmez)
```

## 🎯 Anket Formları

Sistem **11 sabit anket formu** içerir:

- **Okul Öncesi:** Veli (13 madde), Öğretmen (13 madde)
- **İlkokul:** Öğrenci (15 madde), Veli (13 madde), Öğretmen (16 madde)
- **Ortaokul:** Öğrenci (18 madde), Veli (16 madde), Öğretmen (18 madde)
- **Lise:** Öğrenci (20 madde), Veli (19 madde), Öğretmen (19 madde)

PDF dosyaları `storage/pdfs/` klasöründe saklanır ve güvenli şekilde servis edilir.

## 🔐 Güvenlik

- ✅ Production'da hata gösterimi kapalı
- ✅ CSRF koruması (POST istekleri)
- ✅ SQL Injection koruması (Prepared statements)
- ✅ XSS koruması (HTML escaping)
- ✅ Güvenli session yönetimi
- ✅ Password hashing (bcrypt)

## 🌐 AA Panel ve Google Cloud

- **OpenLiteSpeed 1.8.4:** [DEPLOYMENT_OPENLITESPEED.md](DEPLOYMENT_OPENLITESPEED.md) - OpenLiteSpeed için özel rehber
- **Apache/Nginx:** [DEPLOYMENT_AA_PANEL.md](DEPLOYMENT_AA_PANEL.md) - Genel deployment rehberi

### Önemli Notlar

- Document root: `public/` klasörü
- PDF dosyaları: `storage/pdfs/` (public erişimi yok)
- Log dosyaları: `storage/logs/`
- `.env` dosyası: Git'e commit edilmez

## 📞 Destek

- **Kurulum Sorunları:** [DEPLOYMENT_AA_PANEL.md](DEPLOYMENT_AA_PANEL.md) - Sorun Giderme bölümü
- **Güvenlik:** [ANALIZ_RAPORU.md](ANALIZ_RAPORU.md) - Güvenlik bölümü

## 📝 Lisans

[Lisans bilgisi buraya eklenecek]

---

**Versiyon:** 1.0  
**Son Güncelleme:** 2026