<?php

declare(strict_types=1);

/**
 * Charge l'export fourni dans une base SQLite en memoire, puis execute les
 * requetes de requetes.sql et affiche leurs resultats.
 *
 * Usage : php scripts/executer_requetes.php
 */

$racine = dirname(__DIR__);
$fichierBase = $racine . '/base_rendez_vous.sql';
$fichierRequetes = $racine . '/requetes.sql';

if (!is_file($fichierBase) || !is_file($fichierRequetes)) {
    fwrite(STDERR, "Fichier introuvable : base_rendez_vous.sql ou requetes.sql\n");
    exit(1);
}

/**
 * Execute un script SQL simple (instructions separees par des points-virgules,
 * commentaires de ligne commencant par --).
 */
function executerScript(PDO $pdo, string $contenu): void
{
    foreach (explode(';', $contenu) as $morceau) {
        $lignes = preg_split('/\R/', $morceau) ?: [];
        $sql = [];

        foreach ($lignes as $ligne) {
            if (str_starts_with(trim($ligne), '--')) {
                continue;
            }
            $sql[] = $ligne;
        }

        $sql = trim(implode("\n", $sql));
        if ($sql !== '') {
            $pdo->exec($sql);
        }
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
executerScript($pdo, file_get_contents($fichierBase));

$contenu = file_get_contents($fichierRequetes);
$morceaux = explode(';', $contenu);
$numero = 0;

foreach ($morceaux as $morceau) {
    $lignes = preg_split('/\R/', $morceau) ?: [];
    $sql = [];
    $titre = null;

    foreach ($lignes as $ligne) {
        $ligneNettoyee = trim($ligne);

        if (str_starts_with($ligneNettoyee, '--')) {
            if (preg_match('/^--\s*(REQUETE\s+\d+|BONUS\s+\d+)\s*-\s*(.*)$/u', $ligneNettoyee, $correspondance) === 1) {
                $titre = $correspondance[1] . ' - ' . $correspondance[2];
            }
            continue;
        }

        $sql[] = $ligne;
    }

    $sql = trim(implode("\n", $sql));
    if ($sql === '') {
        continue;
    }

    $numero++;
    echo '=== ' . ($titre ?? 'Requete ' . $numero) . " ===\n";

    $statement = $pdo->query($sql);
    $resultats = $statement->fetchAll(PDO::FETCH_ASSOC);

    if ($resultats === []) {
        echo "(aucun resultat)\n\n";
        continue;
    }

    echo implode(' | ', array_keys($resultats[0])) . "\n";
    foreach ($resultats as $resultat) {
        echo implode(' | ', array_map(static fn ($valeur): string => (string) $valeur, $resultat)) . "\n";
    }
    echo '(' . count($resultats) . " ligne(s))\n\n";
}
