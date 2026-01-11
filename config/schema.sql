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
