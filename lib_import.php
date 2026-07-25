<?php
// =========================================================
// Import de tâches — lecture d'un tableau depuis .xlsx, .csv ou .docx
//
// Aucune bibliothèque externe : .xlsx et .docx sont des archives zip
// contenant du XML, lues ici avec ZipArchive + SimpleXML.
// Chaque lecteur renvoie le même format : un tableau de lignes, chaque
// ligne étant un tableau de cellules texte.
// =========================================================

define('IMPORT_MAX_ROWS', 2000);

/* =========================================================
   Lecture brute du tableau
   ========================================================= */

function readTableFromFile($path, $ext) {
    switch ($ext) {
        case 'csv': return readTableFromCsv($path);
        case 'xlsx': return readTableFromXlsx($path);
        case 'docx': return readTableFromDocx($path);
        default:
            fail("Format non pris en charge pour l'import (.$ext). Utilisez un fichier .xlsx, .csv ou .docx.");
    }
}

/* ---------------- CSV ---------------- */

function readTableFromCsv($path) {
    $raw = file_get_contents($path);
    if ($raw === false) fail("Lecture du fichier impossible.");

    // Excel francophone exporte en UTF-8 avec BOM, ou en Windows-1252.
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    // Le séparateur varie selon la locale : ';' en France, ',' ailleurs.
    $firstLine = strtok($raw, "\n");
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $rows = [];
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $raw);
    rewind($handle);
    while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        if ($cells === [null]) continue; // ligne vide
        $rows[] = array_map(fn($c) => trim((string)$c), $cells);
        if (count($rows) > IMPORT_MAX_ROWS) break;
    }
    fclose($handle);
    return $rows;
}

/* ---------------- XLSX ---------------- */

function openZipOrFail($path, $quoi) {
    if (!class_exists('ZipArchive')) {
        fail("L'extension PHP « zip » est requise pour lire les fichiers $quoi. Activez-la chez votre hébergeur, ou importez un fichier .csv.", 500);
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        fail("Fichier illisible : il ne s'agit pas d'un fichier $quoi valide.");
    }
    return $zip;
}

function xlsxEntry($zip, $name) {
    $data = $zip->getFromName($name);
    return $data === false ? null : $data;
}

// Retrouve la première feuille via les relations du classeur ; sans cela, un
// classeur dont la feuille n'est pas nommée sheet1.xml ne serait pas lu.
function xlsxFirstSheetPath($zip) {
    $workbook = xlsxEntry($zip, 'xl/workbook.xml');
    $rels = xlsxEntry($zip, 'xl/_rels/workbook.xml.rels');
    if ($workbook && $rels) {
        $wb = @simplexml_load_string($workbook);
        $rl = @simplexml_load_string($rels);
        if ($wb !== false && $rl !== false) {
            $sheets = $wb->sheets->sheet ?? [];
            if (count($sheets) > 0) {
                $rid = (string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($rl->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $target = ltrim((string)$rel['Target'], '/');
                        if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
                        return $target;
                    }
                }
            }
        }
    }
    return 'xl/worksheets/sheet1.xml';
}

// Repère les styles correspondant à un format de date, afin de convertir les
// numéros de série d'Excel (45000) en dates lisibles (2023-03-15).
function xlsxDateStyles($zip) {
    $stylesXml = xlsxEntry($zip, 'xl/styles.xml');
    if (!$stylesXml) return [];
    $xml = @simplexml_load_string($stylesXml);
    if ($xml === false) return [];

    // Formats intégrés d'Excel qui représentent des dates ou des heures.
    $builtinDate = [14,15,16,17,18,19,20,21,22,27,30,36,45,46,47,50,57];

    $customDate = [];
    if (isset($xml->numFmts->numFmt)) {
        foreach ($xml->numFmts->numFmt as $fmt) {
            $code = (string)$fmt['formatCode'];
            // Un format de date contient forcément un jour, un mois ou une année.
            if (preg_match('/[dmyhs]/i', preg_replace('/"[^"]*"/', '', $code))) {
                $customDate[] = (int)$fmt['numFmtId'];
            }
        }
    }

    $dateStyles = [];
    if (isset($xml->cellXfs->xf)) {
        $i = 0;
        foreach ($xml->cellXfs->xf as $xf) {
            $numFmtId = (int)$xf['numFmtId'];
            if (in_array($numFmtId, $builtinDate, true) || in_array($numFmtId, $customDate, true)) {
                $dateStyles[$i] = true;
            }
            $i++;
        }
    }
    return $dateStyles;
}

// Excel compte les jours depuis le 30/12/1899 (le décalage absorbe le bug de
// l'année 1900, qu'Excel considère à tort comme bissextile).
function excelSerialToDate($serial) {
    $days = (int)floor((float)$serial);
    if ($days <= 0) return '';
    $d = new DateTime('1899-12-30');
    $d->modify("+$days days");
    return $d->format('Y-m-d');
}

// Convertit une référence de colonne ("A", "AB") en index à partir de 0.
function xlsxColumnIndex($ref) {
    preg_match('/^([A-Z]+)/', $ref, $m);
    if (!$m) return 0;
    $letters = $m[1];
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function readTableFromXlsx($path) {
    $zip = openZipOrFail($path, 'Excel (.xlsx)');

    // Les textes sont mutualisés dans une table à part, les cellules n'en
    // contiennent que l'index.
    $shared = [];
    $sharedXml = xlsxEntry($zip, 'xl/sharedStrings.xml');
    if ($sharedXml) {
        $xml = @simplexml_load_string($sharedXml);
        if ($xml) {
            foreach ($xml->si as $si) {
                // Un texte peut être découpé en fragments <r> s'il est formaté.
                $shared[] = isset($si->t) ? (string)$si->t : implode('', array_map(fn($r) => (string)$r->t, iterator_to_array($si->r ?? [])));
            }
        }
    }

    $dateStyles = xlsxDateStyles($zip);
    $sheetXml = xlsxEntry($zip, xlsxFirstSheetPath($zip));
    $zip->close();
    if (!$sheetXml) fail("Le fichier Excel ne contient aucune feuille lisible.");

    $xml = @simplexml_load_string($sheetXml);
    if ($xml === false) fail("Le fichier Excel est illisible.");

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = $ref !== '' ? xlsxColumnIndex($ref) : count($cells);
            $type = (string)$c['t'];

            if ($type === 'inlineStr') {
                $value = isset($c->is->t) ? (string)$c->is->t : '';
            } elseif ($type === 's') {
                $value = $shared[(int)$c->v] ?? '';
            } else {
                $value = isset($c->v) ? (string)$c->v : '';
                // Une date n'est reconnaissable qu'au style appliqué à la cellule.
                if ($value !== '' && is_numeric($value) && $type !== 'str') {
                    $styleIndex = (string)$c['s'];
                    if ($styleIndex !== '' && isset($dateStyles[(int)$styleIndex])) {
                        $value = excelSerialToDate($value);
                    }
                }
            }

            // Comble les colonnes vides, que le XML omet purement et simplement.
            while (count($cells) < $col) $cells[] = '';
            $cells[] = trim($value);
        }
        $rows[] = $cells;
        if (count($rows) > IMPORT_MAX_ROWS) break;
    }
    return $rows;
}

/* ---------------- DOCX ---------------- */

function readTableFromDocx($path) {
    $zip = openZipOrFail($path, 'Word (.docx)');
    $documentXml = xlsxEntry($zip, 'word/document.xml');
    $zip->close();
    if (!$documentXml) fail("Le fichier Word est illisible.");

    // Comparaison stricte : un SimpleXMLElement dont les enfants sont tous dans
    // un espace de noms (cas de <w:document>) vaut false en contexte booléen,
    // et un simple !$xml rejetterait un document parfaitement valide.
    $xml = @simplexml_load_string($documentXml);
    if ($xml === false) fail("Le fichier Word est illisible.");
    $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $tables = $xml->xpath('//w:tbl');
    if (!$tables) fail("Aucun tableau trouvé dans ce document Word. Le fichier doit contenir un vrai tableau, pas du texte aligné.");

    // Seul le premier tableau est repris : plusieurs tableaux impliqueraient
    // des en-têtes différents et un choix que l'utilisateur n'a pas exprimé.
    $table = $tables[0];
    $table->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $rows = [];
    foreach ($table->xpath('.//w:tr') as $tr) {
        $tr->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $cells = [];
        foreach ($tr->xpath('./w:tc') as $tc) {
            $tc->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $text = '';
            foreach ($tc->xpath('.//w:t') as $t) $text .= (string)$t;
            $cells[] = trim($text);
        }
        $rows[] = $cells;
        if (count($rows) > IMPORT_MAX_ROWS) break;
    }
    return $rows;
}

/* =========================================================
   Interprétation du tableau
   ========================================================= */

// Aplatit un en-tête pour le comparer : minuscules, sans accents ni ponctuation.
function normalizeHeader($value) {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $map = ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'];
    $value = strtr($value, $map);
    return preg_replace('/[^a-z0-9]/', '', $value);
}

// Chaque champ accepte plusieurs intitulés, pour ne pas imposer un gabarit figé.
function importColumnAliases() {
    return [
        'name'        => ['tache','taches','nom','nomdelatache','intitule','libelle','designation','titre','description'],
        'employee'    => ['responsable','employe','employeresponsable','assignea','assigne','affectea','charge','qui'],
        'progress'    => ['avancement','avancementpct','progression','pourcentage','pct','progress','avance'],
        'status'      => ['statut','etat','status'],
        'startDate'   => ['debut','datedebut','datededebut','demarrage','start','startdate'],
        'endDate'     => ['fin','datefin','datedefin','finprevue','datedefinprevue','echeance','deadline','enddate'],
        'observation' => ['observation','observations','commentaire','commentaires','remarque','remarques','note','notes'],
    ];
}

function mapHeaderRow($headerCells) {
    $aliases = importColumnAliases();
    $mapping = [];
    foreach ($headerCells as $index => $cell) {
        $key = normalizeHeader($cell);
        if ($key === '') continue;
        foreach ($aliases as $field => $names) {
            if (in_array($key, $names, true) && !isset($mapping[$field])) {
                $mapping[$field] = $index;
                break;
            }
        }
    }
    return $mapping;
}

function parseImportStatus($value) {
    $key = normalizeHeader($value);
    if ($key === '') return 'encours';
    $done = ['effectue','effectuee','termine','terminee','fini','finie','fait','faite','done','ok','cloture','cloturee','acheve'];
    $late = ['retard','enretard','late','depasse','endepassement'];
    if (in_array($key, $done, true)) return 'effectue';
    if (in_array($key, $late, true)) return 'retard';
    return 'encours';
}

function parseImportProgress($value) {
    $value = str_replace([' ', '%', ','], ['', '', '.'], trim((string)$value));
    if ($value === '') return 0;
    if (!is_numeric($value)) return 0;
    $n = (float)$value;
    // Un tableur peut stocker 60 % comme 0,6 : on ramène à l'échelle 0-100.
    if ($n > 0 && $n <= 1 && strpos($value, '.') !== false) $n *= 100;
    return max(0, min(100, (int)round($n)));
}

// Accepte les formats courants ; retourne une date SQL ou null.
function parseImportDate($value) {
    $value = trim((string)$value);
    if ($value === '') return null;

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1])
            ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    }
    // Jour en premier : convention française, y compris avec . ou -
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})#', $value, $m)) {
        $year = (int)$m[3];
        if ($year < 100) $year += ($year < 70) ? 2000 : 1900;
        return checkdate((int)$m[2], (int)$m[1], $year)
            ? sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]) : null;
    }
    // Numéro de série Excel resté brut (colonne non formatée en date).
    if (is_numeric($value) && (float)$value > 20000 && (float)$value < 80000) {
        return excelSerialToDate($value) ?: null;
    }
    return null;
}

/**
 * Transforme le tableau brut en tâches prêtes à insérer.
 * Retourne ['tasks' => [...], 'errors' => ["Ligne 4 : ..."], 'headerRow' => n]
 */
function parseImportedTasks($rows) {
    // L'en-tête n'est pas forcément la première ligne : un fichier exporté peut
    // commencer par un titre ou des lignes vides.
    $headerIndex = null;
    $mapping = [];
    foreach ($rows as $i => $cells) {
        $candidate = mapHeaderRow($cells);
        if (isset($candidate['name'])) {
            $headerIndex = $i;
            $mapping = $candidate;
            break;
        }
        if ($i >= 10) break;
    }

    if ($headerIndex === null) {
        $aliases = importColumnAliases();
        fail("Aucune colonne de nom de tâche trouvée. La première ligne du tableau doit contenir un en-tête nommé par exemple « Tâche », « Nom » ou « Intitulé ». Colonnes reconnues : " . implode(', ', $aliases['name']) . ".");
    }

    $tasks = [];
    $errors = [];
    $cell = function($cells, $field) use ($mapping) {
        if (!isset($mapping[$field])) return '';
        return trim((string)($cells[$mapping[$field]] ?? ''));
    };

    foreach ($rows as $i => $cells) {
        if ($i <= $headerIndex) continue;
        if (!array_filter($cells, fn($c) => trim((string)$c) !== '')) continue; // ligne vide

        $ligne = $i + 1; // numéro affiché à l'utilisateur, comme dans le tableur
        $name = $cell($cells, 'name');
        if ($name === '') {
            $errors[] = "Ligne $ligne : nom de tâche vide, ligne ignorée.";
            continue;
        }

        $startDate = parseImportDate($cell($cells, 'startDate'));
        $endDate   = parseImportDate($cell($cells, 'endDate'));

        $rawStart = $cell($cells, 'startDate');
        $rawEnd   = $cell($cells, 'endDate');
        if ($rawStart !== '' && $startDate === null) {
            $errors[] = "Ligne $ligne : date de début « $rawStart » non reconnue, laissée vide.";
        }
        if ($rawEnd !== '' && $endDate === null) {
            $errors[] = "Ligne $ligne : date de fin « $rawEnd » non reconnue, laissée vide.";
        }
        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            $errors[] = "Ligne $ligne : la date de fin précède la date de début, échéance ignorée.";
            $endDate = null;
        }

        // Même réconciliation statut / avancement que dans le reste de l'application.
        [$status, $progress] = normalizeTask(
            parseImportStatus($cell($cells, 'status')),
            parseImportProgress($cell($cells, 'progress'))
        );

        $tasks[] = [
            'name'        => mb_substr($name, 0, 255),
            'employee'    => mb_substr($cell($cells, 'employee'), 0, 255),
            'progress'    => $progress,
            'status'      => $status,
            'startDate'   => $startDate,
            'endDate'     => $endDate,
            'observation' => $cell($cells, 'observation'),
        ];
    }

    return ['tasks' => $tasks, 'errors' => $errors, 'headerRow' => $headerIndex + 1];
}
