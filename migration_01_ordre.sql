-- =========================================================
-- Migration 01 — ordre d'ancienneté stable pour projets et tâches
--
-- À exécuter UNE SEULE FOIS dans phpMyAdmin (onglet SQL) sur une base
-- déjà installée. Inutile pour une nouvelle installation : schema.sql
-- contient déjà la colonne `seq`.
--
-- Pourquoi : `created_at` est un TIMESTAMP (précision 1 seconde). Toutes
-- les lignes insérées dans la même seconde (le seed, les tâches générées
-- depuis un compte-rendu) sont à égalité, et MySQL les restitue alors dans
-- un ordre arbitraire. La colonne `seq` (AUTO_INCREMENT) donne un ordre
-- d'insertion réel et stable.
--
-- Les lignes existantes reçoivent un `seq` calculé d'après `created_at`,
-- afin de conserver au mieux leur ordre de création actuel.
-- =========================================================

-- ---------- PROJETS ----------
ALTER TABLE projects ADD COLUMN seq INT NULL;

SET @i := 0;
UPDATE projects SET seq = (@i := @i + 1) ORDER BY created_at, id;

ALTER TABLE projects
  MODIFY seq INT NOT NULL AUTO_INCREMENT,
  ADD UNIQUE KEY uniq_projects_seq (seq);

-- ---------- TÂCHES ----------
ALTER TABLE tasks ADD COLUMN seq INT NULL;

SET @i := 0;
UPDATE tasks SET seq = (@i := @i + 1) ORDER BY created_at, id;

ALTER TABLE tasks
  MODIFY seq INT NOT NULL AUTO_INCREMENT,
  ADD UNIQUE KEY uniq_tasks_seq (seq);
