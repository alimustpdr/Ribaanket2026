# Ribaanket2026

Bu repo, okul öncesi, ilkokul, ortaokul ve lise düzeyindeki eğitim anketlerinin ve sonuç çizelgelerinin saklandığı bir arşiv deposudur.

## İçerik

Repoda aşağıdaki anket formları ve sonuç çizelgeleri bulunmaktadır:

### Okul Öncesi
- `18115003_RYBA_FORM_Veli_Okul_Oncesi.pdf` - Veli anketi
- `18174526_RYBA_FORM_OYretmen_Okul_Oncesi.pdf` - Öğretmen anketi
- `26134246_ribaokuloncesiokulsonuccizelgesi.xlsx` - Okul sonuç çizelgesi
- `26134300_ribaokuloncesisinifsonuccizelgesi (1).xlsx` - Sınıf sonuç çizelgesi

### İlkokul, Ortaokul, Lise
Diğer eğitim düzeyleri için benzer form ve çizelgeler mevcuttur.

## Survey Extraction Tool (Anket Veri Çıkarma Aracı)

Okul öncesi anket dokümanlarını (PDF ve XLSX) makine okunabilir formata dönüştüren bir Python aracı sağlanmıştır.

### Özellikler

- **PDF → Metin**: PDF form dosyalarından düz metin çıkarımı (sayfa bazlı)
- **XLSX → CSV**: Her çalışma sayfası için ayrı CSV dosyası oluşturma
- **XLSX → JSON**: Yapısal özet metadata (sheet isimleri, boyutlar, hücre türleri, formüller)

### Kurulum

1. Python 3.7+ gereklidir
2. Bağımlılıkları kurun:

```bash
pip install -r requirements.txt
```

### Kullanım

#### Temel Kullanım
Repo kök dizininde çalıştırın:

```bash
python scripts/extract_surveys.py
```

Bu komut, okul öncesi PDF ve XLSX dosyalarını işleyerek `extracted/okuloncesi/` dizinine çıktıları kaydeder.

#### Komut Satırı Seçenekleri

```bash
# Sadece PDF dosyalarını işle
python scripts/extract_surveys.py --pdf

# Sadece XLSX dosyalarını işle
python scripts/extract_surveys.py --xlsx

# Özel girdi/çıktı dizinleri belirt
python scripts/extract_surveys.py --input-dir /path/to/files --output-dir /path/to/output

# Yardım mesajını görüntüle
python scripts/extract_surveys.py --help
```

### Çıktı Dizin Yapısı

Çıkarılan dosyalar aşağıdaki dizin yapısında saklanır:

```
extracted/okuloncesi/
├── pdf_text/                    # PDF'lerden çıkarılan metinler
│   ├── 18115003_RYBA_FORM_Veli_Okul_Oncesi.txt
│   └── 18174526_RYBA_FORM_OYretmen_Okul_Oncesi.txt
├── xlsx_csv/                    # Her sheet için CSV dosyaları
│   ├── 26134246_ribaokuloncesiokulsonuccizelgesi_Sheet1.csv
│   └── ...
└── xlsx_json/                   # Yapısal metadata JSON dosyaları
    ├── 26134246_ribaokuloncesiokulsonuccizelgesi_metadata.json
    └── ...
```

### Çıktılar Hakkında

- **PDF Metinleri**: Her sayfa "=== Page N ===" başlığıyla ayrılmış olarak tek bir .txt dosyasında
- **CSV Dosyaları**: Her XLSX çalışma sayfası için ayrı CSV (virgülle ayrılmış)
- **JSON Metadata**: 
  - Çalışma sayfası isimleri
  - Satır/sütun sayıları
  - Başlık satırı tespiti
  - Hücre türü dağılımı (string, number, formula, vb.)
  - Formül örnekleri (ilk 10)

### Notlar

- Çıktı dizini (`extracted/`) `.gitignore` dosyasında listelenmiştir ve commit edilmez.
- Script, dosya adlarındaki boşluklar ve özel karakterler konusunda sağlamdır.
- Dosya bulunamadığında veya işleme hatası oluştuğunda anlamlı hata mesajları verilir.

## Katkıda Bulunma

Bu repo bir arşiv deposu olduğundan, genellikle değişiklik kabul edilmez. Ancak extraction tool için iyileştirme önerileri kabul edilebilir.

## Lisans

Eğitim amaçlı kullanım için.