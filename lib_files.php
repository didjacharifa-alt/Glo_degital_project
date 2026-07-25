<?php
// =========================================================
// Pièces jointes — dépôt, suppression et téléchargement
//
// Les fichiers sont écrits dans uploads/ sous un nom généré aléatoirement.
// Le nom d'origine n'est jamais utilisé comme chemin : il servirait sinon à
// remonter l'arborescence (../) ou à déposer un fichier exécutable.
// Le téléchargement passe toujours par l'API, jamais par une URL directe.
// =========================================================

define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_MAX_BYTES', 10 * 1024 * 1024); // 10 Mo

// Extensions acceptées, associées au type MIME renvoyé au téléchargement.
function allowedUploadTypes() {
    return [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv'  => 'text/csv',
        'txt'  => 'text/plain',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'zip'  => 'application/zip',
    ];
}

function uploadExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

// Traduit les codes d'erreur de PHP en messages compréhensibles : la cause la
// plus fréquente est un fichier plus lourd que la limite de l'hébergeur, et le
// message par défaut ne le dit pas.
function uploadErrorMessage($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "Fichier trop volumineux pour la configuration du serveur (voir upload_max_filesize).";
        case UPLOAD_ERR_PARTIAL:
            return "L'envoi du fichier a été interrompu. Réessayez.";
        case UPLOAD_ERR_NO_FILE:
            return "Aucun fichier reçu.";
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return "Le serveur n'a pas pu écrire le fichier temporaire.";
        case UPLOAD_ERR_EXTENSION:
            return "Envoi bloqué par une extension PHP du serveur.";
        default:
            return "Échec de l'envoi du fichier.";
    }
}

function ensureUploadDir() {
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
        fail("Le dossier uploads/ est absent ou non accessible en écriture. Créez-le et donnez-lui les droits d'écriture.", 500);
    }
}

/**
 * Valide le fichier reçu et l'écrit dans uploads/.
 * Retourne ['name' => nom d'origine, 'path' => nom de stockage, 'size' => octets].
 */
function storeUploadedFile($field, $idPrefix) {
    if (!isset($_FILES[$field])) fail("Aucun fichier reçu.");
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) fail(uploadErrorMessage($f['error']));
    if ($f['size'] <= 0) fail("Le fichier est vide.");
    if ($f['size'] > UPLOAD_MAX_BYTES) {
        fail("Fichier trop volumineux (maximum " . (UPLOAD_MAX_BYTES / 1024 / 1024) . " Mo).");
    }
    // Garantit que le fichier vient bien d'un envoi HTTP et non d'un chemin
    // local que l'appelant aurait glissé dans la requête.
    if (!is_uploaded_file($f['tmp_name'])) fail("Envoi invalide.");

    $ext = uploadExtension($f['name']);
    $allowed = allowedUploadTypes();
    if (!isset($allowed[$ext])) {
        fail("Type de fichier non autorisé (.$ext). Formats acceptés : " . implode(', ', array_keys($allowed)) . ".");
    }

    ensureUploadDir();

    $storedName = $idPrefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = UPLOAD_DIR . '/' . $storedName;
    if (!move_uploaded_file($f['tmp_name'], $target)) {
        fail("Impossible d'enregistrer le fichier sur le serveur.", 500);
    }
    @chmod($target, 0644);

    return [
        'name' => mb_substr($f['name'], 0, 255),
        'path' => $storedName,
        'size' => (int)$f['size'],
    ];
}

// Supprime le fichier physique. Le nom vient de la base, mais on refuse tout
// de même un chemin contenant un séparateur : une donnée corrompue ne doit pas
// permettre d'effacer un fichier hors de uploads/.
function deleteStoredFile($storedName) {
    if (!$storedName) return;
    if (basename($storedName) !== $storedName) return;
    $path = UPLOAD_DIR . '/' . $storedName;
    if (is_file($path)) @unlink($path);
}

// Envoie le fichier au navigateur. Appelée après vérification de la session.
function sendStoredFile($storedName, $originalName) {
    if (!$storedName || basename($storedName) !== $storedName) {
        fail("Fichier introuvable.", 404);
    }
    $path = UPLOAD_DIR . '/' . $storedName;
    if (!is_file($path)) fail("Fichier introuvable sur le serveur.", 404);

    $allowed = allowedUploadTypes();
    $ext = uploadExtension($storedName);
    $mime = $allowed[$ext] ?? 'application/octet-stream';

    // Le Content-Type json posé en tête d'api.php ne doit pas suivre.
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $originalName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
