-- RİBA (çok okullu) başlangıç şeması
-- MariaDB / MySQL için phpMyAdmin'den çalıştırılabilir.

CREATE TABLE IF NOT EXISTS schools (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  city VARCHAR(100) NOT NULL,
  district VARCHAR(100) NOT NULL,
  school_type ENUM('okul_oncesi','ilkokul','ortaokul','lise') NOT NULL,
  status ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at DATETIME NULL,
  PRIMARY KEY (id),
  INDEX idx_school_type (school_type),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_admins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_school_admin_email (email),
  CONSTRAINT fk_school_admins_school
    FOREIGN KEY (school_id) REFERENCES schools(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_classes_school (school_id),
  CONSTRAINT fk_classes_school
    FOREIGN KEY (school_id) REFERENCES schools(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Yıllık süreç: her okul için kampanya/dönem (örn: 2026 RİBA)
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
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_school_year (school_id, year),
  INDEX idx_campaign_school (school_id),
  INDEX idx_campaign_status (status),
  CONSTRAINT fk_campaigns_school
    FOREIGN KEY (school_id) REFERENCES schools(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Her sınıf için (öğrenci/veli/öğretmen) tek anket linki
CREATE TABLE IF NOT EXISTS form_instances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  audience ENUM('ogrenci','veli','ogretmen') NOT NULL,
  public_id CHAR(32) NOT NULL,
  status ENUM('active','closed') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_form_instances_public_id (public_id),
  UNIQUE KEY uq_form_instances_campaign_class_audience (campaign_id, class_id, audience),
  INDEX idx_form_instances_school (school_id),
  INDEX idx_form_instances_campaign (campaign_id),
  CONSTRAINT fk_form_instances_school
    FOREIGN KEY (school_id) REFERENCES schools(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_form_instances_campaign
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_form_instances_class
    FOREIGN KEY (class_id) REFERENCES classes(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS responses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  form_instance_id BIGINT UNSIGNED NOT NULL,
  gender ENUM('K','E') NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(255) NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_responses_form (form_instance_id),
  INDEX idx_responses_iphash (form_instance_id, ip_hash),
  INDEX idx_responses_campaign (campaign_id),
  CONSTRAINT fk_responses_form
    FOREIGN KEY (form_instance_id) REFERENCES form_instances(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_responses_school
    FOREIGN KEY (school_id) REFERENCES schools(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_responses_campaign
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_responses_class
    FOREIGN KEY (class_id) REFERENCES classes(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS response_answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  response_id BIGINT UNSIGNED NOT NULL,
  item_no INT UNSIGNED NOT NULL,
  choice ENUM('A','B') NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_response_item (response_id, item_no),
  INDEX idx_answers_response (response_id),
  CONSTRAINT fk_answers_response
    FOREIGN KEY (response_id) REFERENCES responses(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
