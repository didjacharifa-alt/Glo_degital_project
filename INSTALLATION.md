# Installation — Gestion Clients & Projets (PHP + MySQL)

## Fichiers du package
- `index.html` — l'application (interface)
- `api.php` — l'API qui parle à MySQL
- `db.php` — connexion PDO
- `config.php` — **à remplir avec vos identifiants MySQL**
- `schema.sql` — structure des tables à importer
- `seed.php` — crée les comptes de départ + données de démo (à supprimer après usage)

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
Envoyez tous les fichiers (`index.html`, `api.php`, `db.php`, `config.php`, `seed.php`) dans le dossier web de votre hébergement (souvent `www/` ou la racine du sous-domaine), via FTP ou le gestionnaire de fichiers de votre hébergeur.

### 5. Lancer le seed (une seule fois)
Dans votre navigateur, ouvrez :
```
https://votredomaine.com/seed.php
```
Cela crée :
- **admin / admin123** (rôle ADMIN)
- **lecture / lecture123** (rôle LECTURE)
- des clients, projets, tâches et réunions de démonstration

**⚠️ Supprimez ensuite `seed.php` du serveur** (via FTP) — il ne doit plus être accessible une fois utilisé, et ne peut de toute façon être relancé tant qu'il y a des utilisateurs en base.

### 6. Utiliser l'application
Ouvrez `https://votredomaine.com/index.html` et connectez-vous. Pensez à changer le mot de passe admin depuis les Paramètres dès que possible (ou demandez-moi d'ajouter un écran "changer mon mot de passe" si besoin).

## Sécurité
- Les mots de passe sont hashés (bcrypt via `password_hash`), jamais stockés en clair.
- Chaque action de modification est revérifiée côté serveur (rôle ADMIN), pas seulement côté navigateur.
- Pensez à activer le HTTPS sur votre domaine (souvent gratuit via Let's Encrypt chez OVH/o2switch) pour chiffrer les échanges, y compris le mot de passe à la connexion.
- Plus aucune donnée n'est stockée dans le navigateur (`localStorage` a été retiré) : tout vit dans MySQL.

## Sauvegarde
Depuis Paramètres → Base de données → "Exporter la DB (.json)" (réservé ADMIN), vous pouvez télécharger un instantané JSON de toutes vos données à tout moment.
