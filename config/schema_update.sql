-- Mevcut kurulumlar için şema güncellemesi (kampanya/yıllık süreç)
-- DİKKAT: Bu dosyayı phpMyAdmin'de sırayla çalıştırın.

-- 1) campaigns tablosu
CREATE TABLE IF NOT EXISTS campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id BIGINT UNSIGNED NOT NULL,
  year INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  status ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  response_quota INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at DATETIME NULL,
  closed_at DATETIME NULL,
  quota_closed_notified_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_school_year (school_id, year),
  INDEX idx_campaign_school (school_id),
  INDEX idx_campaign_status (status),
  CONSTRAINT fk_campaigns_school
    FOREIGN KEY (school_id) REFERENCES schools(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) form_instances: campaign_id alanı
ALTER TABLE form_instances
  ADD COLUMN campaign_id BIGINT UNSIGNED NULL AFTER school_id;

-- 3) responses: campaign_id alanı
ALTER TABLE responses
  ADD COLUMN campaign_id BIGINT UNSIGNED NULL AFTER school_id;

-- 4) İndeks/constraint'ler
ALTER TABLE form_instances
  ADD INDEX idx_form_instances_campaign (campaign_id);

ALTER TABLE responses
  ADD INDEX idx_responses_campaign (campaign_id);

ALTER TABLE form_instances
  ADD CONSTRAINT fk_form_instances_campaign
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id)
    ON DELETE CASCADE;

ALTER TABLE responses
  ADD CONSTRAINT fk_responses_campaign
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id)
    ON DELETE CASCADE;

-- 5) Eski unique kısıtı yeni modele çevirmek için:
-- Eğer eski kurulumda uq_form_instances_class_audience varsa, önce DROP etmeniz gerekebilir.
-- Bazı kurulumlarda isim farklı olabilir; phpMyAdmin'de tablo yapısından kontrol edin.
-- Örnek:
-- ALTER TABLE form_instances DROP INDEX uq_form_instances_class_audience;
-- ALTER TABLE form_instances ADD UNIQUE KEY uq_form_instances_campaign_class_audience (campaign_id, class_id, audience);

-- 6) campaign_id alanlarını NOT NULL yapmak için:
-- Önce mevcut verileri bir kampanyaya bağlamalısınız. (Veri yoksa doğrudan yapılabilir.)
-- ALTER TABLE form_instances MODIFY campaign_id BIGINT UNSIGNED NOT NULL;
-- ALTER TABLE responses MODIFY campaign_id BIGINT UNSIGNED NOT NULL;

-- 7) Ek kota paketleri ve siparişleri
CREATE TABLE IF NOT EXISTS quota_packages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  quota_add INT UNSIGNED NOT NULL,
  price_amount INT UNSIGNED NULL,
  price_currency VARCHAR(3) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_quota_packages_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7.1) Yıllık üyelik/paket (ilk üyelik)
CREATE TABLE IF NOT EXISTS school_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id BIGINT UNSIGNED NOT NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'pending',
  annual_quota INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  PRIMARY KEY (id),
  INDEX idx_school_subscriptions_school (school_id),
  INDEX idx_school_subscriptions_status (status),
  CONSTRAINT fk_school_subscriptions_school
    FOREIGN KEY (school_id) REFERENCES schools(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_school_subscriptions_package
    FOREIGN KEY (package_id) REFERENCES quota_packages(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quota_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  method ENUM('online','manual') NOT NULL,
  status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  quota_add INT UNSIGNED NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  applied_at DATETIME NULL,
  PRIMARY KEY (id),
  INDEX idx_quota_orders_school (school_id),
  INDEX idx_quota_orders_campaign (campaign_id),
  INDEX idx_quota_orders_status (status),
  CONSTRAINT fk_quota_orders_school
    FOREIGN KEY (school_id) REFERENCES schools(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_quota_orders_campaign
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_quota_orders_package
    FOREIGN KEY (package_id) REFERENCES quota_packages(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

