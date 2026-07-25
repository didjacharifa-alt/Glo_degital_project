-- =========================================================
-- Migration 02 — pièces jointes sur les tâches et les comptes-rendus
--
-- À exécuter UNE SEULE FOIS dans phpMyAdmin (onglet SQL), après la
-- migration 01. Inutile pour une nouvelle installation : schema.sql
-- contient déjà ces colonnes.
--
-- Les fichiers eux-mêmes ne sont pas stockés en base : ils vont dans le
-- dossier uploads/, et seul leur nom de stockage est enregistré ici.
-- =========================================================

-- ---------- Fiche détaillée jointe à une tâche ----------
ALTER TABLE tasks
  ADD COLUMN file_name        VARCHAR(255) NULL,
  ADD COLUMN file_path        VARCHAR(255) NULL,
  ADD COLUMN file_size        INT NULL,
  ADD COLUMN file_uploaded_at DATETIME NULL;

-- ---------- Compte-rendu joint sous forme de fichier ----------
ALTER TABLE reports
  ADD COLUMN file_name        VARCHAR(255) NULL,
  ADD COLUMN file_path        VARCHAR(255) NULL,
  ADD COLUMN file_size        INT NULL,
  ADD COLUMN file_uploaded_at DATETIME NULL;
