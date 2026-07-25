-- =========================================================
-- Schéma de base — Agence : Gestion Clients & Projets
-- À importer dans phpMyAdmin (ou via mysql CLI) sur votre base vide.
-- =========================================================

CREATE TABLE IF NOT EXISTS settings (
  id INT PRIMARY KEY DEFAULT 1,
  company_name  VARCHAR(255) NOT NULL DEFAULT 'Mon Agence',
  logo_text     VARCHAR(10)  NOT NULL DEFAULT 'AG',
  logo_data_url LONGTEXT     NULL,
  primary_color   VARCHAR(7) NOT NULL DEFAULT '#122A3E',
  secondary_color VARCHAR(7) NOT NULL DEFAULT '#2B4C66',
  accent_color    VARCHAR(7) NOT NULL DEFAULT '#D98A3D'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id VARCHAR(20) PRIMARY KEY,
  username      VARCHAR(100) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('ADMIN','LECTURE') NOT NULL DEFAULT 'LECTURE',
  name          VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
  id VARCHAR(20) PRIMARY KEY,
  name VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entities (
  id VARCHAR(20) PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  avatar      LONGTEXT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
  id          VARCHAR(20) PRIMARY KEY,
  seq         INT NOT NULL AUTO_INCREMENT UNIQUE,
  entity_id   VARCHAR(20) NOT NULL,
  name        VARCHAR(255) NOT NULL,
  description TEXT NULL,
  end_date    DATE NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tasks (
  id          VARCHAR(20) PRIMARY KEY,
  seq         INT NOT NULL AUTO_INCREMENT UNIQUE,
  project_id  VARCHAR(20) NOT NULL,
  name        VARCHAR(255) NOT NULL,
  employee    VARCHAR(255) NULL,
  progress    INT NOT NULL DEFAULT 0,
  status      ENUM('encours','effectue','retard') NOT NULL DEFAULT 'encours',
  start_date  DATE NULL,
  end_date    DATE NULL,
  observation TEXT NULL,
  -- Fiche détaillée jointe à la tâche. file_path est le nom du fichier tel que
  -- stocké dans uploads/ ; file_name conserve le nom d'origine pour l'affichage.
  file_name        VARCHAR(255) NULL,
  file_path        VARCHAR(255) NULL,
  file_size        INT NULL,
  file_uploaded_at DATETIME NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meetings (
  id         VARCHAR(20) PRIMARY KEY,
  project_id VARCHAR(20) NOT NULL,
  title      VARCHAR(255) NOT NULL,
  date       DATE NOT NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reports (
  id         VARCHAR(20) PRIMARY KEY,
  meeting_id VARCHAR(20) NOT NULL UNIQUE,
  summary    TEXT NULL,
  todo_raw   TEXT NULL,
  shared     TINYINT(1) NOT NULL DEFAULT 0,
  shared_at  DATETIME NULL,
  -- Compte-rendu joint sous forme de fichier (PDF, Word…), en complément
  -- ou en remplacement de la saisie directe dans `summary`.
  file_name        VARCHAR(255) NULL,
  file_path        VARCHAR(255) NULL,
  file_size        INT NULL,
  file_uploaded_at DATETIME NULL,
  FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (id, company_name, logo_text) VALUES (1, 'Atelier Nova', 'AN');
