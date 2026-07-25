<?php
// =========================================================
// API — Agence : Gestion Clients & Projets
// Toutes les requêtes passent par ce fichier : api.php?action=xxx
// =========================================================
ini_set('session.cookie_httponly', 1);
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

function genId($prefix) { return $prefix . '_' . bin2hex(random_bytes(4)); }

function input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Non connecté.']);
        exit;
    }
}
function requireAdmin() {
    requireLogin();
    if ($_SESSION['role'] !== 'ADMIN') {
        http_response_code(403);
        echo json_encode(['error' => 'Action réservée au rôle ADMIN.']);
        exit;
    }
}
function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

/* ---------------- Validation des tâches ---------------- */

// Le statut arrive du navigateur : on le confronte à la liste autorisée
// plutôt que de le passer tel quel à une colonne ENUM (erreur SQL sinon).
function taskStatus($value) {
    $allowed = ['encours', 'effectue', 'retard'];
    return in_array($value, $allowed, true) ? $value : 'encours';
}

// Les attributs min/max du formulaire ne valent que côté navigateur.
function taskProgress($value) {
    return max(0, min(100, (int)$value));
}

// Chaîne vide -> NULL, pour ne pas insérer '0000-00-00' en base.
function taskDate($value) {
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function checkTaskDates($startDate, $endDate) {
    if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
        fail("La date de fin prévue ne peut pas précéder la date de début.");
    }
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        /* ---------------- AUTH ---------------- */
        case 'login': {
            $d = input();
            $username = trim($d['username'] ?? '');
            $password = $d['password'] ?? '';
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $u = $stmt->fetch();
            if (!$u || !password_verify($password, $u['password_hash'])) {
                fail('Identifiants incorrects.', 401);
            }
            $_SESSION['user_id'] = $u['id'];
            $_SESSION['role'] = $u['role'];
            $_SESSION['name'] = $u['name'];
            echo json_encode(['ok' => true]);
            break;
        }

        case 'logout': {
            $_SESSION = [];
            session_destroy();
            echo json_encode(['ok' => true]);
            break;
        }

        /* ---------------- STATE (lecture complète) ---------------- */
        case 'state': {
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['loggedIn' => false]);
                break;
            }

            // Bascule automatique en retard des tâches en échéance dépassée…
            $pdo->exec("UPDATE tasks SET status='retard'
                        WHERE status = 'encours'
                          AND end_date IS NOT NULL AND end_date < CURDATE()");
            // …et retour en cours si l'échéance a depuis été repoussée ou retirée
            // (sans cette bascule inverse, une tâche passée en retard le restait
            // définitivement, même après correction de sa date de fin).
            $pdo->exec("UPDATE tasks SET status='encours'
                        WHERE status = 'retard'
                          AND (end_date IS NULL OR end_date >= CURDATE())");

            $settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
            $users = $pdo->query("SELECT id, username, role, name FROM users ORDER BY created_at")->fetchAll();
            $employees = $pdo->query("SELECT * FROM employees ORDER BY name")->fetchAll();
            $entities = $pdo->query("SELECT * FROM entities ORDER BY is_internal DESC, created_at")->fetchAll();
            // seq (AUTO_INCREMENT) = ordre d'insertion réel, du plus ancien au plus
            // récent. created_at ne suffit pas : sa précision d'une seconde met à
            // égalité les lignes créées d'un même geste (seed, compte-rendu partagé).
            $projects = $pdo->query("SELECT * FROM projects ORDER BY seq")->fetchAll();
            $tasks = $pdo->query("SELECT * FROM tasks ORDER BY seq")->fetchAll();
            $meetings = $pdo->query("SELECT * FROM meetings ORDER BY date DESC")->fetchAll();
            $reports = $pdo->query("SELECT * FROM reports")->fetchAll();

            echo json_encode([
                'loggedIn' => true,
                'user' => ['id' => $_SESSION['user_id'], 'role' => $_SESSION['role'], 'name' => $_SESSION['name']],
                'settings' => [
                    'companyName' => $settings['company_name'],
                    'logoText' => $settings['logo_text'],
                    'logoDataUrl' => $settings['logo_data_url'],
                    'primaryColor' => $settings['primary_color'],
                    'secondaryColor' => $settings['secondary_color'],
                    'accentColor' => $settings['accent_color'],
                ],
                'users' => array_map(fn($u) => ['id'=>$u['id'],'username'=>$u['username'],'role'=>$u['role'],'name'=>$u['name']], $users),
                'employees' => array_map(fn($e) => ['id'=>$e['id'],'name'=>$e['name']], $employees),
                'entities' => array_map(fn($e) => ['id'=>$e['id'],'name'=>$e['name'],'isInternal'=>(bool)$e['is_internal'],'avatar'=>$e['avatar']], $entities),
                'projects' => array_map(fn($p) => ['id'=>$p['id'],'entityId'=>$p['entity_id'],'name'=>$p['name'],'description'=>$p['description'],'endDate'=>$p['end_date']], $projects),
                'tasks' => array_map(fn($t) => ['id'=>$t['id'],'projectId'=>$t['project_id'],'name'=>$t['name'],'employee'=>$t['employee'],'progress'=>(int)$t['progress'],'status'=>$t['status'],'startDate'=>$t['start_date'],'endDate'=>$t['end_date'],'observation'=>$t['observation']], $tasks),
                'meetings' => array_map(fn($m) => ['id'=>$m['id'],'projectId'=>$m['project_id'],'title'=>$m['title'],'date'=>$m['date']], $meetings),
                'reports' => array_map(fn($r) => ['id'=>$r['id'],'meetingId'=>$r['meeting_id'],'summary'=>$r['summary'],'todoRaw'=>$r['todo_raw'],'shared'=>(bool)$r['shared'],'sharedAt'=>$r['shared_at']], $reports),
            ]);
            break;
        }

        /* ---------------- ENTITÉS ---------------- */
        case 'create_entity': {
            requireAdmin();
            $d = input();
            $name = trim($d['name'] ?? '');
            if ($name === '') fail('Nom requis.');
            $id = genId('ent');
            $pdo->prepare("INSERT INTO entities (id, name, is_internal) VALUES (?,?,0)")->execute([$id, $name]);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;
        }

        /* ---------------- PROJETS ---------------- */
        case 'create_project': {
            requireAdmin();
            $d = input();
            $entityId = $d['entityId'] ?? '';
            $name = trim($d['name'] ?? '');
            if ($name === '' || $entityId === '') fail('Champs requis manquants.');
            $id = genId('prj');
            $pdo->prepare("INSERT INTO projects (id, entity_id, name, description, end_date) VALUES (?,?,?,?,?)")
                ->execute([$id, $entityId, $name, $d['description'] ?? '', $d['endDate'] ?: null]);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;
        }
        case 'update_project': {
            requireAdmin();
            $d = input();
            $id = $d['id'] ?? '';
            if ($id === '') fail('id requis.');
            $pdo->prepare("UPDATE projects SET name=?, description=?, end_date=? WHERE id=?")
                ->execute([trim($d['name'] ?? ''), $d['description'] ?? '', $d['endDate'] ?: null, $id]);
            echo json_encode(['ok' => true]);
            break;
        }

        /* ---------------- TÂCHES ---------------- */
        case 'create_task': {
            requireAdmin();
            $d = input();
            $projectId = $d['projectId'] ?? '';
            $name = trim($d['name'] ?? '');
            if ($name === '' || $projectId === '') fail('Champs requis manquants.');

            // Sans ce contrôle, un projet inexistant déclenche une violation de clé
            // étrangère renvoyée au navigateur en « Erreur base de données ».
            $exists = $pdo->prepare("SELECT COUNT(*) c FROM projects WHERE id=?");
            $exists->execute([$projectId]);
            if ($exists->fetch()['c'] == 0) fail("Ce projet n'existe pas.", 404);

            $startDate = taskDate($d['startDate'] ?? '');
            $endDate   = taskDate($d['endDate'] ?? '');
            checkTaskDates($startDate, $endDate);

            $id = genId('tsk');
            $pdo->prepare("INSERT INTO tasks (id, project_id, name, employee, progress, status, start_date, end_date, observation) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$id, $projectId, $name, $d['employee'] ?? '', taskProgress($d['progress'] ?? 0), taskStatus($d['status'] ?? 'encours'), $startDate, $endDate, $d['observation'] ?? '']);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;
        }
        case 'update_task': {
            requireAdmin();
            $d = input();
            $id = $d['id'] ?? '';
            if ($id === '') fail('id requis.');
            $name = trim($d['name'] ?? '');
            if ($name === '') fail('Nom requis.');

            // rowCount() ne distingue pas « tâche absente » de « aucune valeur
            // modifiée » : on vérifie l'existence séparément pour ne pas répondre
            // ok à une modification qui n'a rien touché.
            $exists = $pdo->prepare("SELECT COUNT(*) c FROM tasks WHERE id=?");
            $exists->execute([$id]);
            if ($exists->fetch()['c'] == 0) fail("Cette tâche n'existe plus.", 404);

            $startDate = taskDate($d['startDate'] ?? '');
            $endDate   = taskDate($d['endDate'] ?? '');
            checkTaskDates($startDate, $endDate);

            $pdo->prepare("UPDATE tasks SET name=?, employee=?, progress=?, status=?, start_date=?, end_date=?, observation=? WHERE id=?")
                ->execute([$name, $d['employee'] ?? '', taskProgress($d['progress'] ?? 0), taskStatus($d['status'] ?? 'encours'), $startDate, $endDate, $d['observation'] ?? '', $id]);
            echo json_encode(['ok' => true]);
            break;
        }
        case 'delete_task': {
            requireAdmin();
            $d = input();
            $id = $d['id'] ?? '';
            if ($id === '') fail('id requis.');
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id=?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) fail("Cette tâche n'existe plus.", 404);
            echo json_encode(['ok' => true]);
            break;
        }

        /* ---------------- RÉUNIONS ---------------- */
        case 'create_meeting': {
            requireAdmin();
            $d = input();
            $projectId = $d['projectId'] ?? '';
            $title = trim($d['title'] ?? '');
            $date = $d['date'] ?? '';
            if ($title === '' || $projectId === '' || $date === '') fail('Champs requis manquants.');
            $id = genId('mtg');
            $pdo->prepare("INSERT INTO meetings (id, project_id, title, date) VALUES (?,?,?,?)")
                ->execute([$id, $projectId, $title, $date]);
            $pdo->prepare("INSERT INTO reports (id, meeting_id, summary, todo_raw, shared) VALUES (?,?,'','',0)")
                ->execute([genId('rep'), $id]);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;
        }
        case 'delete_meeting': {
            requireAdmin();
            $d = input();
            $pdo->prepare("DELETE FROM meetings WHERE id=?")->execute([$d['id'] ?? '']); // cascade supprime le report
            echo json_encode(['ok' => true]);
            break;
        }

        /* ---------------- COMPTES-RENDUS ---------------- */
        case 'save_report': {
            requireAdmin();
            $d = input();
            $id = $d['id'] ?? '';
            if ($id === '') fail('id requis.');
            $pdo->prepare("UPDATE reports SET summary=?, todo_raw=? WHERE id=?")
                ->execute([$d['summary'] ?? '', $d['todoRaw'] ?? '', $id]);
            echo json_encode(['ok' => true]);
            break;
        }
        case 'share_report': {
            requireAdmin();
            $d = input();
            $id = $d['id'] ?? '';
            if ($id === '') fail('id requis.');

            $pdo->prepare("UPDATE reports SET summary=?, todo_raw=? WHERE id=?")
                ->execute([$d['summary'] ?? '', $d['todoRaw'] ?? '', $id]);

            $report = $pdo->query("SELECT * FROM reports WHERE id=" . $pdo->quote($id))->fetch();
            $meeting = $pdo->query("SELECT * FROM meetings WHERE id=" . $pdo->quote($report['meeting_id']))->fetch();

            $lines = array_filter(array_map('trim', explode("\n", $d['todoRaw'] ?? '')));
            $created = 0;
            $stmt = $pdo->prepare("INSERT INTO tasks (id, project_id, name, employee, progress, status, start_date, end_date, observation) VALUES (?,?,?,?,0,'encours',?,NULL,?)");
            foreach ($lines as $line) {
                $obs = "Générée automatiquement depuis le compte-rendu \"{$meeting['title']}\" ({$meeting['date']}).";
                $stmt->execute([genId('tsk'), $meeting['project_id'], $line, '', date('Y-m-d'), $obs]);
                $created++;
            }

            $pdo->prepare("UPDATE reports SET shared=1, shared_at=NOW() WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true, 'created' => $created]);
            break;
        }

        /* ---------------- PARAMÈTRES / CHARTE ---------------- */
        case 'update_settings': {
            requireAdmin();
            $d = input();
            $pdo->prepare("UPDATE settings SET company_name=?, logo_text=?, primary_color=?, secondary_color=?, accent_color=?, logo_data_url = COALESCE(?, logo_data_url) WHERE id=1")
                ->execute([
                    trim($d['companyName'] ?? 'Mon Agence'),
                    strtoupper(trim($d['logoText'] ?? 'AG')),
                    $d['primaryColor'] ?? '#122A3E',
                    $d['secondaryColor'] ?? '#2B4C66',
                    $d['accentColor'] ?? '#D98A3D',
                    $d['logoDataUrl'] ?? null,
                ]);
            echo json_encode(['ok' => true]);
            break;
        }

        /* ---------------- UTILISATEURS / EMPLOYÉS ---------------- */
        case 'create_user': {
            requireAdmin();
            $d = input();
            $username = trim($d['username'] ?? '');
            $password = $d['password'] ?? '';
            $name = trim($d['name'] ?? '');
            if ($username === '' || $password === '' || $name === '') fail('Champs requis manquants.');
            $exists = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE username=?");
            $exists->execute([$username]);
            if ($exists->fetch()['c'] > 0) fail('Cet identifiant existe déjà.');
            $id = genId('usr');
            $pdo->prepare("INSERT INTO users (id, username, password_hash, role, name) VALUES (?,?,?,'LECTURE',?)")
                ->execute([$id, $username, password_hash($password, PASSWORD_DEFAULT), $name]);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;
        }
        case 'create_employee': {
            requireAdmin();
            $d = input();
            $name = trim($d['name'] ?? '');
            if ($name === '') fail('Nom requis.');
            $id = genId('emp');
            $pdo->prepare("INSERT INTO employees (id, name) VALUES (?,?)")->execute([$id, $name]);
            echo json_encode(['ok' => true, 'id' => $id]);
            break;
        }
        case 'delete_employee': {
            requireAdmin();
            $d = input();
            $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$d['id'] ?? '']);
            echo json_encode(['ok' => true]);
            break;
        }

        /* ---------------- EXPORT (sauvegarde JSON) ---------------- */
        case 'export': {
            requireAdmin();
            $tables = ['settings','users','employees','entities','projects','tasks','meetings','reports'];
            $dump = [];
            foreach ($tables as $t) { $dump[$t] = $pdo->query("SELECT * FROM `$t`")->fetchAll(); }
            header('Content-Disposition: attachment; filename="agence-db-export.json"');
            echo json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            fail('Action inconnue.', 404);
    }
} catch (PDOException $e) {
    error_log('API PDO error [' . $action . ']: ' . $e->getMessage());
    fail('Erreur base de données.', 500);
} catch (Throwable $e) {
    error_log('API error [' . $action . ']: ' . $e->getMessage());
    fail('Erreur serveur.', 500);
}
