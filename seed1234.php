<?php
// =========================================================
// SEED — à exécuter UNE SEULE FOIS dans votre navigateur
// (ex: https://votredomaine.com/seed.php)
// Crée les 2 comptes utilisateurs + des données de démonstration.
// SUPPRIMEZ CE FICHIER du serveur une fois l'exécution terminée.
// =========================================================
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

$count = $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
if ($count > 0) {
    die("Des utilisateurs existent déjà. Le seed a déjà été exécuté (ou supprimez les lignes existantes pour relancer). Rien n'a été fait.");
}

function genId($prefix) { return $prefix . '_' . bin2hex(random_bytes(4)); }
function addDays($n) { $d = new DateTime(); $d->modify("$n days"); return $d->format('Y-m-d'); }

$pdo->beginTransaction();
try {
    // --- Utilisateurs ---
    $adminId = genId('usr');
    $lectureId = genId('usr');
    $pdo->prepare("INSERT INTO users (id, username, password_hash, role, name) VALUES (?,?,?,?,?)")
        ->execute([$adminId, 'admin', password_hash('admin123', PASSWORD_DEFAULT), 'ADMIN', 'CHARIFA (Direction)']);
    $pdo->prepare("INSERT INTO users (id, username, password_hash, role, name) VALUES (?,?,?,?,?)")
        ->execute([$lectureId, 'lecture', password_hash('lecture123', PASSWORD_DEFAULT), 'LECTURE', 'AICHATA (Collaborateur)']);

    // --- Employés ---
    foreach (['CHARIFA','AICHATA','CARINCE','ASSAD','LENGUE'] as $name) {
        $pdo->prepare("INSERT INTO employees (id, name) VALUES (?,?)")->execute([genId('emp'), $name]);
    }

    // --- Entités ---
    $internalId = genId('ent');
    $pdo->prepare("INSERT INTO entities (id, name, is_internal) VALUES (?,?,1)")->execute([$internalId, 'Mon entreprise']);
    $clientNames = ['SAVANA FINANCE','WARA','NubieCloud','WELLY','Andal GROUP','Andal RH','Moyo DRINKS','Multiprint'];
    $clientIds = [];
    foreach ($clientNames as $name) {
        $id = genId('ent');
        $clientIds[$name] = $id;
        $pdo->prepare("INSERT INTO entities (id, name, is_internal) VALUES (?,?,0)")->execute([$id, $name]);
    }

    // --- Projets ---
    $projInternal = genId('prj');
    $pdo->prepare("INSERT INTO projects (id, entity_id, name, description, end_date) VALUES (?,?,?,?,?)")
        ->execute([$projInternal, $internalId, 'Refonte site vitrine agence', 'Nouveau site institutionnel', addDays(20)]);

    $projA1 = genId('prj');
    $pdo->prepare("INSERT INTO projects (id, entity_id, name, description, end_date) VALUES (?,?,?,?,?)")
        ->execute([$projA1, $clientIds['SAVANA FINANCE'], 'Identité visuelle', 'Logo, charte, déclinaisons', addDays(-2)]);

    $projA2 = genId('prj');
    $pdo->prepare("INSERT INTO projects (id, entity_id, name, description, end_date) VALUES (?,?,?,?,?)")
        ->execute([$projA2, $clientIds['SAVANA FINANCE'], 'Boutique en ligne', 'Développement e-commerce', addDays(35)]);

    $projB1 = genId('prj');
    $pdo->prepare("INSERT INTO projects (id, entity_id, name, description, end_date) VALUES (?,?,?,?,?)")
        ->execute([$projB1, $clientIds['WARA'], 'Campagne réseaux sociaux', 'Contenus trimestriels', addDays(10)]);

    // --- Tâches ---
    $tasks = [
        [$projInternal, 'Rédiger le cahier des charges', 'CHARIFA', 100, 'effectue', addDays(-10), addDays(-3), 'Validé en interne.'],
        [$projInternal, 'Maquettes UI', 'CARINCE', 60, 'encours', addDays(-3), addDays(7), ''],
        [$projA1, 'Recherche graphique', 'CARINCE', 100, 'effectue', addDays(-20), addDays(-10), '3 pistes proposées.'],
        [$projA1, 'Validation client', 'CHARIFA', 40, 'retard', addDays(-9), addDays(-2), 'En attente de retour client.'],
        [$projA2, 'Intégration catalogue produits', 'ASSAD', 20, 'encours', addDays(-2), addDays(30), ''],
        [$projB1, 'Calendrier éditorial Q3', 'ASSAD', 0, 'encours', date('Y-m-d'), addDays(10), 'En attente des visuels.'],
    ];
    $stmt = $pdo->prepare("INSERT INTO tasks (id, project_id, name, employee, progress, status, start_date, end_date, observation) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($tasks as $t) { $stmt->execute(array_merge([genId('tsk')], $t)); }

    // --- Réunions + comptes-rendus vides ---
    $meetings = [
        [$projA1, 'Point de lancement', addDays(-21)],
        [$projA1, 'Revue des maquettes', addDays(-9)],
    ];
    $mStmt = $pdo->prepare("INSERT INTO meetings (id, project_id, title, date) VALUES (?,?,?,?)");
    $rStmt = $pdo->prepare("INSERT INTO reports (id, meeting_id, summary, todo_raw, shared) VALUES (?,?,'','',0)");
    foreach ($meetings as $m) {
        $mid = genId('mtg');
        $mStmt->execute(array_merge([$mid], $m));
        $rStmt->execute([genId('rep'), $mid]);
    }

    $pdo->commit();
    echo "Seed terminé avec succès !\n\n";
    echo "Connexion ADMIN   : admin / admin123\n";
    echo "Connexion LECTURE : lecture / lecture123\n\n";
    echo "IMPORTANT : supprimez maintenant le fichier seed.php du serveur.";
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Erreur pendant le seed : " . $e->getMessage();
}
