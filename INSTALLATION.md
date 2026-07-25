# Installation — Gestion Clients & Projets (PHP + MySQL)

## Fichiers du package
- `index.html` — l'application (interface)
- `api.php` — l'API qui parle à MySQL
- `db.php` — connexion PDO
- `lib_files.php` — dépôt et téléchargement des pièces jointes
- `lib_import.php` — lecture des tableaux Excel / CSV / Word
- `config.php` — **à remplir avec vos identifiants MySQL** (copiez `config.example.php`)
- `schema.sql` — structure des tables à importer
- `migration_01_ordre.sql`, `migration_02_fichiers.sql` — mises à jour d'une base **déjà installée**
- `uploads/` — dossier des fichiers déposés (doit être accessible en écriture)
- `seed1234.php` — crée les comptes de départ + données de démo (à supprimer après usage)

## Étapes

### 1. Créer la base MySQL
Vous avez déjà une base prête — notez son **nom**, l'**utilisateur** et le **mot de passe** MySQL associés (visibles dans l'espace client OVH/o2switch, section "Bases de données").

### 2. Importer le schéma
Ouvrez **phpMyAdmin** (lien fourni par votre hébergeur), sélectionnez votre base, onglet **SQL**, collez le contenu de `schema.sql`, puis exécutez. Cela crée les 8 tables nécessaires.

### 3. Configurer la connexion
Ouvrez `config.php` et remplacez :
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'votre_base');
define('DB_USER', 'votre_utilisateur');
define('DB_PASS', 'votre_mot_de_passe');
```
par vos vraies valeurs.

### 4. Uploader les fichiers
Envoyez tous les fichiers (`index.html`, `api.php`, `db.php`, `lib_files.php`, `lib_import.php`, `config.php`, `seed1234.php`) dans le dossier web de votre hébergement (souvent `www/` ou la racine du sous-domaine), via FTP ou le gestionnaire de fichiers de votre hébergeur.

Envoyez aussi le dossier **`uploads/`** avec le fichier `.htaccess` qu'il contient, et donnez-lui les **droits d'écriture** (chmod 755, ou 775 si 755 ne suffit pas). C'est là que seront rangés les comptes-rendus et les fiches de tâches.

### 5. Lancer le seed (une seule fois)
Dans votre navigateur, ouvrez :
```
https://votredomaine.com/seed1234.php
```
Cela crée :
- **admin / admin123** (rôle ADMIN)
- **lecture / lecture123** (rôle LECTURE)
- des clients, projets, tâches et réunions de démonstration

**⚠️ Supprimez ensuite `seed1234.php` du serveur** (via FTP) — il ne doit plus être accessible une fois utilisé, et ne peut de toute façon être relancé tant qu'il y a des utilisateurs en base.

### 6. Utiliser l'application
Ouvrez `https://votredomaine.com/index.html` et connectez-vous. Pensez à changer le mot de passe admin depuis les Paramètres dès que possible (ou demandez-moi d'ajouter un écran "changer mon mot de passe" si besoin).

## Mise à jour d'une installation existante
Si l'application tourne déjà chez vous, n'importez **pas** `schema.sql` (il recréerait des tables vides) : passez par les migrations, dans l'ordre, depuis phpMyAdmin → onglet SQL.

| Migration | Ce qu'elle apporte |
|---|---|
| `migration_01_ordre.sql` | tri fiable des projets et des tâches par ancienneté |
| `migration_02_fichiers.sql` | pièces jointes sur les tâches et les comptes-rendus |

Chaque migration ne doit être exécutée qu'une seule fois. Envoyez ensuite les fichiers PHP mis à jour **après** avoir joué les migrations, sinon l'application cherchera des colonnes qui n'existent pas encore.

## Importer des tâches depuis un tableau
Sur la page d'un projet, bouton **« ⇪ Importer un tableau »** (réservé ADMIN). Formats acceptés : **Excel (.xlsx)**, **CSV** et **Word (.docx)** — le document Word doit contenir un vrai tableau, pas du texte aligné.

La première ligne sert d'en-tête. Seule la colonne du nom est obligatoire, les autres sont facultatives et peuvent être dans n'importe quel ordre :

| Colonne | Contenu | Intitulés reconnus |
|---|---|---|
| Nom | **obligatoire** | Tâche, Nom, Intitulé, Libellé, Désignation, Titre |
| Responsable | nom de l'employé | Responsable, Employé, Assigné à, Chargé |
| Avancement | 0 à 100, le `%` est accepté | Avancement, Progression, Pourcentage |
| Statut | en cours · effectué · en retard | Statut, État |
| Début | `JJ/MM/AAAA` ou `AAAA-MM-JJ` | Début, Date de début, Démarrage |
| Fin | idem | Fin, Échéance, Fin prévue, Deadline |
| Observation | commentaire libre | Observation, Commentaire, Remarque, Note |

Les vraies cellules de date d'Excel sont reconnues automatiquement. Les lignes sans nom de tâche sont ignorées et signalées à la fin de l'import ; l'import est appliqué en une seule fois, ou pas du tout en cas d'erreur bloquante.

## Pièces jointes
- **Fiche détaillée d'une tâche** : colonne « Fiche » du tableau des tâches, bouton *Joindre*.
- **Compte-rendu en fichier** : bloc « Compte-rendu joint » sur la page d'une réunion.

Formats acceptés : PDF, Word, Excel, PowerPoint, OpenDocument, CSV, texte, images et ZIP. Taille maximale : **10 Mo** par fichier (dans la limite de ce qu'autorise votre hébergeur — voir `upload_max_filesize` et `post_max_size`).

Supprimer une tâche, une réunion ou remplacer un fichier efface l'ancien fichier du serveur : pas de fichiers orphelins qui s'accumulent.

## Sécurité
- Les mots de passe sont hashés (bcrypt via `password_hash`), jamais stockés en clair.
- Les fichiers déposés ne sont **jamais accessibles par une URL directe** : le dossier `uploads/` est fermé par son `.htaccess`, et chaque téléchargement passe par l'application, qui vérifie d'abord que vous êtes connecté. Sans cela, le lien d'un document client serait consultable par n'importe qui.
- Les fichiers sont enregistrés sous un nom généré aléatoirement, et seules les extensions listées plus haut sont acceptées : un fichier `.php` déposé ne peut pas être exécuté par le serveur.
- Chaque action de modification est revérifiée côté serveur (rôle ADMIN), pas seulement côté navigateur.
- Pensez à activer le HTTPS sur votre domaine (souvent gratuit via Let's Encrypt chez OVH/o2switch) pour chiffrer les échanges, y compris le mot de passe à la connexion.
- Plus aucune donnée n'est stockée dans le navigateur (`localStorage` a été retiré) : tout vit dans MySQL.

## Sauvegarde
Depuis Paramètres → Base de données → "Exporter la DB (.json)" (réservé ADMIN), vous pouvez télécharger un instantané JSON de toutes vos données à tout moment.
