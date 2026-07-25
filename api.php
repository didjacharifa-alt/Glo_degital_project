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
require_once __DIR__ . '/lib_files.php';
require_once __DIR__ . '/lib_import.php';

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

// Statut et avancement décrivent la même réalité : on les réconcilie dans les
// deux sens pour éviter les états contradictoires (« effectué » à 30 %, ou
// 100 % encore « en cours ») qui faussaient les indicateurs du tableau de bord.
// Retourne le couple [statut, avancement].
function normalizeTask($status, $progress) {
    $status = taskStatus($status);
    $progress = taskProgress($progress);
    if ($status === 'effectue') {
        $progress = 100;
    } elseif ($progress === 100) {
        $status = 'effectue';
    }
    return [$status, $progress];
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
                'tasks' => array_map(fn($t) => ['id'=>$t['id'],'projectId'=>$t['project_id'],'name'=>$t['name'],'employee'=>$t['employee'],'progress'=>(int)$t['progress'],'status'=>$t['status'],'startDate'=>$t['start_date'],'endDate'=>$t['end_date'],'observation'=>$t['observation'],'fileName'=>$t['file_name'],'fileSize'=>$t['file_size'] !== null ? (int)$t['file_size'] : null], $tasks),
                'meetings' => array_map(fn($m) => ['id'=>$m['id'],'projectId'=>$m['project_id'],'title'=>$m['title'],'date'=>$m['date']], $meetings),
                'reports' => array_map(fn($r) => ['id'=>$r['id'],'meetingId'=>$r['meeting_id'],'summary'=>$r['summary'],'todoRaw'=>$r['todo_raw'],'shared'=>(bool)$r['shared'],'sharedAt'=>$r['shared_at'],'fileName'=>$r['file_name'],'fileSize'=>$r['file_size'] !== null ? (int)$r['file_size'] : null], $reports),
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

            [$status, $progress] = normalizeTask($d['status'] ?? 'encours', $d['progress'] ?? 0);

            $id = genId('tsk');
            $pdo->prepare("INSERT INTO tasks (id, project_id, name, employee, progress, status, start_date, end_date, observation) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$id, $projectId, $name, $d['employee'] ?? '', $progress, $status, $startDate, $endDate, $d['observation'] ?? '']);
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

            [$status, $progress] = normalizeTask($d['status'] ?? 'encours', $d['progress'] ?? 0);

            $pdo->prepare("UPDATE tasks SET name=?, employee=?, progress=?, status=?, start_date=?, end_date=?, observation=? WHERE id=?")
                ->execute([$name, $d['employee'] ?? '', $progress, $status, $startDate, $endDate, $d['observation'] ?? '', $id]);
            echo json_encode(['ok' => true]);
            break;
        }
        case 'delete_task': {
            requireAdmin();
            $d = input();
            $id = $d['id'] ?? '';
            if ($id === '') fail('id requis.');

            // Le fichier joint doit partir avec la tâche, sinon il resterait
            // indéfiniment dans uploads/ sans plus rien pour le référencer.
            $stmt = $pdo->prepare("SELECT file_path FROM tasks WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) fail("Cette tâche n'existe plus.", 404);

            $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
            deleteStoredFile($row['file_path']);
            echo json_encode(['ok' => true]);
            break;
        }

        /* ---------------- IMPORT DE TÂCHES ---------------- */
        case 'import_tasks': {
            requireAdmin();
            // Envoi multipart : les champs arrivent par $_POST, pas en JSON.
            $projectId = $_POST['projectId'] ?? '';
            if ($projectId === '') fail('Projet manquant.');

            $exists = $pdo->prepare("SELECT COUNT(*) c FROM projects WHERE id=?");
            $exists->execute([$projectId]);
            if ($exists->fetch()['c'] == 0) fail("Ce projet n'existe pas.", 404);

            if (!isset($_FILES['file'])) fail("Aucun fichier reçu.");
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) fail(uploadErrorMessage($_FILES['file']['error']));
            if (!is_uploaded_file($_FILES['file']['tmp_name'])) fail("Envoi invalide.");

            $ext = uploadExtension($_FILES['file']['name']);
            if (!in_array($ext, ['xlsx', 'csv', 'docx'], true)) {
                fail("Format non pris en charge pour l'import (.$ext). Utilisez un fichier .xlsx, .csv ou .docx.");
            }

            $rows = readTableFromFile($_FILES['file']['tmp_name'], $ext);
            $parsed = parseImportedTasks($rows);
            if (!$parsed['tasks']) {
                fail("Aucune tâche exploitable dans ce fichier. " . implode(' ', array_slice($parsed['errors'], 0, 3)));
            }

            // Tout ou rien : un import à moitié appliqué serait pénible à
            // rattraper, les tâches créées n'étant pas distinguables des autres.
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO tasks (id, project_id, name, employee, progress, status, start_date, end_date, observation) VALUES (?,?,?,?,?,?,?,?,?)");
                foreach ($parsed['tasks'] as $t) {
                    $stmt->execute([genId('tsk'), $projectId, $t['name'], $t['employee'], $t['progress'], $t['status'], $t['startDate'], $t['endDate'], $t['observation']]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode([
                'ok' => true,
                'created' => count($parsed['tasks']),
                'errors' => $parsed['errors'],
            ]);
            break;
        }

        /* ---------------- PIÈCES JOINTES ---------------- */
        case 'upload_task_file': {
            requireAdmin();
            $id = $_POST['id'] ?? '';
            if ($id === '') fail('id requis.');
            $stmt = $pdo->prepare("SELECT file_path FROM tasks WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) fail("Cette tâche n'existe plus.", 404);

            $stored = storeUploadedFile('file', 'tsk');
            $pdo->prepare("UPDATE tasks SET file_name=?, file_path=?, file_size=?, file_uploaded_at=NOW() WHERE id=?")
                ->execute([$stored['name'], $stored['path'], $stored['size'], $id]);
            // Remplacement : l'ancien fichier n'est effacé qu'une fois le
            // nouveau enregistré, pour ne pas perdre les deux en cas d'échec.
            deleteStoredFile($row['file_path']);

            echo json_encode(['ok' => true, 'fileName' => $stored['name'], 'fileSize' => $stored['size']]);
            break;
        }
        case 'delete_task_file': {
            requireAdmin();
            $d = input();
            $id = $d['id'] ?? '';
            if ($id === '') fail('id requis.');
            $stmt = $pdo->prepare("SELECT file_path FROM tasks WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) fail("Cette tâche n'existe plus.", 404);

            $pdo->prepare("UPDATE tasks SET file_name=NULL, file_path=NULL, file_size=NULL, file_uploaded_at=NULL WHERE id=?")->execute([$id]);
            deleteStoredFile($row['file_path']);
            echo json_encode(['ok' => true]);
            break;
        }
        case 'upload_report_file': {
            requireAdmin();
            $id = $_POST['id'] ?? '';
            if ($id === '') fail('id requis.');
            $stmt = $pdo->prepare("SELECT file_path FROM reports WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) fail("Ce compte-rendu n'existe plus.", 404);

            $stored = storeUploadedFile('file', 'rep');
            $pdo->prepare("UPDATE reports SET file_name=?, file_path=?, file_size=?, file_uploaded_at=NOW() WHERE id=?")
                ->execute([$stored['name'], $stored['path'], $stored['size'], $id]);
            deleteStoredFile($row['file_path']);

            echo json_encode(['ok' => true, 'fileName' => $stored['name'], 'fileSize' => $stored['size']]);
            break;
        }
        case 'delete_report_file': {
            requireAdmin();
            $d = input();
            $id = $d['id'] ?? '';
            if ($id === '') fail('id requis.');
            $stmt = $pdo->prepare("SELECT file_path FROM reports WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) fail("Ce compte-rendu n'existe plus.", 404);

            $pdo->prepare("UPDATE reports SET file_name=NULL, file_path=NULL, file_size=NULL, file_uploaded_at=NULL WHERE id=?")->execute([$id]);
            deleteStoredFile($row['file_path']);
            echo json_encode(['ok' => true]);
            break;
        }
        case 'download': {
            // Lecture seule : accessible aussi au rôle LECTURE, mais jamais
            // sans session — c'est ce qui protège les documents clients.
            requireLogin();
            $type = $_GET['type'] ?? '';
            $id = $_GET['id'] ?? '';
            if ($id === '') fail('id requis.');

            $table = $type === 'task' ? 'tasks' : ($type === 'report' ? 'reports' : '');
            if ($table === '') fail('Type de document inconnu.');

            $stmt = $pdo->prepare("SELECT file_name, file_path FROM `$table` WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || !$row['file_path']) fail("Aucun fichier joint.", 404);

            sendStoredFile($row['file_path'], $row['file_name']);
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
            $id = $d['id'] ?? '';
            if ($id === '') fail('id requis.');

            // La cascade SQL efface la ligne du compte-rendu, mais pas le
            // fichier déposé sur le disque : il faut le retrouver avant.
            $stmt = $pdo->prepare("SELECT file_path FROM reports WHERE meeting_id=?");
            $stmt->execute([$id]);
            $report = $stmt->fetch();

            $pdo->prepare("DELETE FROM meetings WHERE id=?")->execute([$id]);
            if ($report) deleteStoredFile($report['file_path']);
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

            $stmt = $pdo->prepare("SELECT * FROM reports WHERE id=?");
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) fail("Ce compte-rendu n'existe plus.", 404);

            $stmt = $pdo->prepare("SELECT * FROM meetings WHERE id=?");
            $stmt->execute([$report['meeting_id']]);
            $meeting = $stmt->fetch();
            if (!$meeting) fail("La réunion liée à ce compte-rendu n'existe plus.", 404);

            // Les tâches issues d'un compte-rendu partent de la réunion qui les a
            // décidées et sont attendues sous quinzaine. Sans échéance, elles ne
            // pouvaient jamais basculer en retard ni apparaître dans les indicateurs.
            $startDate = $meeting['date'];
            $dueDate = (new DateTime($startDate))->modify('+14 days')->format('Y-m-d');

            $lines = array_filter(array_map('trim', explode("\n", $d['todoRaw'] ?? '')));
            $created = 0;
            $stmt = $pdo->prepare("INSERT INTO tasks (id, project_id, name, employee, progress, status, start_date, end_date, observation) VALUES (?,?,?,?,0,'encours',?,?,?)");
            foreach ($lines as $line) {
                $obs = "Générée automatiquement depuis le compte-rendu \"{$meeting['title']}\" ({$meeting['date']}).";
                $stmt->execute([genId('tsk'), $meeting['project_id'], $line, '', $startDate, $dueDate, $obs]);
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
