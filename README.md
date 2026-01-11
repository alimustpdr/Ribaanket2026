# Ribaanket2026

## Kurulum (CyberPanel / PHP)

Bu repo, **çok okullu** RİBA anket sistemi için düz PHP iskeleti içerir.

### 1) Document Root

- Web sitenizin document root’unu `public/` klasörü yapın.

### 2) MariaDB Şeması

- phpMyAdmin üzerinden `config/schema.sql` dosyasını çalıştırın.

### 3) Ortam Ayarları

- Sunucuda ortam değişkenleri tanımlayın **veya** proje kökünde `.env` dosyası oluşturun.
- Örnek anahtarlar için `config/env.example` dosyasına bakın.

Zorunlu olanlar:
- `APP_KEY`
- `ADMIN_EMAIL`, `ADMIN_PASSWORD`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`

### 3.1) Excel Çıktısı (Composer)

Excel çıktısı (`.xlsx`) üretmek için PHP tarafında kütüphane gerekir. Bu proje `PhpSpreadsheet` kullanır.

- Sunucuda proje kök dizininde:

```bash
composer install
```

### 4) Akış

- Okul başvurusu: `/apply`
- Site yöneticisi girişi: `/admin/login` → okulu **aktif et**
- Okul girişi: `/login`
- Okul paneli: `/panel` → **Sınıflar** → her sınıf için anket linkleri

Not: Anket ekranı, soru metinlerini kopyalamamak için ilgili **PDF’i kaynak olarak gösterir** ve kullanıcı sadece **cinsiyet + A/B seçimleri** yapar.