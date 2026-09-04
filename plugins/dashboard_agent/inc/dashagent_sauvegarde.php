<?php
/**
 * Sauvegarde de la base de données du site géré.
 *
 * Deux stratégies, dans cet ordre : `mysqldump` s'il est réellement exécutable,
 * sinon un export PHP pur streamé en gzip. La seconde ne suppose rien de
 * l'hébergement, ce qui est la situation normale en mutualisé.
 *
 * @package SPIP\Dashagent\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Répertoire des sauvegardes locales.
 *
 * @return string Chaîne vide si non disponible
 */
function dashagent_dir_sauvegardes() {
	include_spip('inc/dashagent');
	include_spip('inc/flock');

	if (!dashagent_dir_travail()) {
		return '';
	}
	sous_repertoire(_DASHAGENT_DIR_TRAVAIL, 'sauvegardes');
	$dir = _DASHAGENT_DIR_TRAVAIL . 'sauvegardes/';

	return is_dir($dir) && is_writable($dir) ? $dir : '';
}

/**
 * Tables exclues d'une sauvegarde allégée.
 *
 * @return array
 */
function dashagent_tables_statistiques() {
	return ['spip_visites', 'spip_visites_articles', 'spip_referers', 'spip_referers_articles', 'spip_resultats'];
}

/**
 * Crée une sauvegarde de la base.
 *
 * @param array $args
 *     - bool `sans_statistiques` : exclure les tables de statistiques
 *     - array `exclure` : tables supplémentaires à exclure
 * @return array{ok: bool, erreur: string, sauvegarde: array}
 */
function dashagent_sauvegarde_creer($args = []) {
	$dir = dashagent_dir_sauvegardes();
	if (!$dir) {
		return ['ok' => false, 'erreur' => 'Répertoire de sauvegarde indisponible ou non inscriptible', 'sauvegarde' => []];
	}
	if (!function_exists('gzopen')) {
		return ['ok' => false, 'erreur' => 'Extension PHP zlib absente', 'sauvegarde' => []];
	}

	$exclure = [];
	if (!empty($args['sans_statistiques'])) {
		$exclure = dashagent_tables_statistiques();
	}
	if (!empty($args['exclure']) && is_array($args['exclure'])) {
		$exclure = array_merge($exclure, array_map('strval', $args['exclure']));
	}

	$tables = sql_alltable('%');
	if (!is_array($tables) || !$tables) {
		return ['ok' => false, 'erreur' => 'Aucune table lisible dans la base', 'sauvegarde' => []];
	}
	$tables = array_values(array_diff($tables, $exclure));

	$identifiant = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
	$nom_fichier = 'sauvegarde-' . $identifiant . '.sql.gz';
	$chemin      = $dir . $nom_fichier;

	$debut  = microtime(true);
	$erreur = dashagent_sauvegarde_ecrire($chemin, $tables);

	if ($erreur !== '') {
		@unlink($chemin);

		return ['ok' => false, 'erreur' => $erreur, 'sauvegarde' => []];
	}

	dashagent_sauvegarde_purger_anciennes($dir);

	return [
		'ok'     => true,
		'erreur' => '',
		'sauvegarde' => [
			'identifiant' => $identifiant,
			'fichier'     => $nom_fichier,
			'octets'      => (int) filesize($chemin),
			'sha256'      => hash_file('sha256', $chemin),
			'date'        => date('c'),
			'tables'      => count($tables),
			'exclues'     => $exclure,
			'duree_ms'    => (int) round((microtime(true) - $debut) * 1000),
		],
	];
}

/**
 * Écrit le dump gzip.
 *
 * @param string $chemin
 * @param array $tables
 * @return string Message d'erreur, vide si succès
 */
function dashagent_sauvegarde_ecrire($chemin, $tables) {
	$gz = gzopen($chemin, 'wb6');
	if (!$gz) {
		return 'Impossible d’ouvrir le fichier de sauvegarde en écriture';
	}

	$entete = "-- Sauvegarde SPIP produite par le plugin Dashboard : agent\n"
		. '-- Site   : ' . url_de_base() . "\n"
		. '-- Date   : ' . date('c') . "\n"
		. '-- SPIP   : ' . ($GLOBALS['spip_version_branche'] ?? '?') . "\n"
		. '-- Tables : ' . count($tables) . "\n\n"
		. 'SET NAMES ' . dashagent_charset_connexion() . ";\n"
		. "SET FOREIGN_KEY_CHECKS=0;\n\n";
	gzwrite($gz, $entete);

	foreach ($tables as $table) {
		$erreur = dashagent_sauvegarde_table($gz, $table);
		if ($erreur !== '') {
			gzclose($gz);

			return $erreur;
		}
	}

	gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
	gzclose($gz);

	return '';
}

/**
 * Jeu de caractères de la connexion SQL du site.
 *
 * Imposer utf8mb4 à l'aveugle produirait un dump illisible sur une base encore
 * en latin1 : on reprend ce que le site utilise réellement.
 *
 * @return string
 */
function dashagent_charset_connexion() {
	$charset = (string) ($GLOBALS['meta']['charset_sql_connexion'] ?? '');
	if ($charset === '') {
		$charset = (string) ($GLOBALS['meta']['charset_sql_base'] ?? '');
	}
	if (!preg_match('/^[a-z0-9_]+$/i', $charset)) {
		$charset = 'utf8mb4';
	}

	return $charset;
}

/**
 * Écrit la structure et les données d'une table.
 *
 * @param resource $gz
 * @param string $table
 * @return string Message d'erreur, vide si succès
 */
function dashagent_sauvegarde_table($gz, $table) {
	gzwrite($gz, "\n-- ---------------------------------------------------------\n-- Table " . $table . "\n\n");

	$creation = dashagent_sauvegarde_structure($table);
	if ($creation === null) {
		return 'Structure illisible pour la table ' . $table;
	}
	gzwrite($gz, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $creation . ";\n\n");

	// Sans clef primaire, la pagination par offset n'est pas stable (une ligne
	// pourrait être dupliquée ou sautée) : on lit alors la table d'un seul tenant.
	$clef   = dashagent_sauvegarde_clef_primaire($table);
	$pas    = _DASHAGENT_DUMP_LOT;
	$offset = 0;
	$colonnes = null;

	do {
		$res = sql_select(
			'*',
			$table,
			'',
			'',
			$clef,
			$clef ? ($offset . ',' . $pas) : '',
			'',
			'',
			'continue'
		);
		if (!$res) {
			return 'Lecture impossible sur la table ' . $table;
		}

		$n = 0;
		$lignes = [];
		while ($ligne = sql_fetch($res, '')) {
			$n++;
			if ($colonnes === null) {
				$colonnes = array_keys($ligne);
			}
			$lignes[] = dashagent_sauvegarde_valeurs($ligne);
			if (count($lignes) >= _DASHAGENT_DUMP_LOT) {
				gzwrite($gz, dashagent_sauvegarde_insert($table, $colonnes, $lignes));
				$lignes = [];
			}
		}
		sql_free($res, '');

		if ($lignes) {
			gzwrite($gz, dashagent_sauvegarde_insert($table, $colonnes, $lignes));
		}

		$offset += $pas;
	} while ($clef && $n >= $pas);

	return '';
}

/**
 * Ordre de tri stable pour paginer une table, s'il en existe un.
 *
 * @param string $table
 * @return string
 */
function dashagent_sauvegarde_clef_primaire($table) {
	$desc = sql_showtable($table, false, '', 'continue');
	if (!is_array($desc) || empty($desc['key']['PRIMARY KEY'])) {
		return '';
	}
	$clef = $desc['key']['PRIMARY KEY'];

	// Une clef composite reste un ordre total : on la garde telle quelle.
	return $clef;
}

/**
 * Récupère l'ordre CREATE TABLE.
 *
 * @param string $table
 * @return string|null
 */
function dashagent_sauvegarde_structure($table) {
	$res = sql_query('SHOW CREATE TABLE `' . $table . '`', '', 'continue');
	if ($res) {
		$ligne = sql_fetch($res, '');
		sql_free($res, '');
		if (is_array($ligne)) {
			foreach ($ligne as $clef => $valeur) {
				if (stripos((string) $clef, 'create') !== false) {
					return (string) $valeur;
				}
			}
		}
	}

	return null;
}

/**
 * Sérialise une ligne en liste de valeurs SQL.
 *
 * @param array $ligne
 * @return string
 */
function dashagent_sauvegarde_valeurs($ligne) {
	$valeurs = [];
	foreach ($ligne as $valeur) {
		$valeurs[] = ($valeur === null) ? 'NULL' : sql_quote($valeur);
	}

	return '(' . implode(',', $valeurs) . ')';
}

/**
 * Assemble une requête INSERT groupée.
 *
 * @param string $table
 * @param array|null $colonnes
 * @param array $lignes
 * @return string
 */
function dashagent_sauvegarde_insert($table, $colonnes, $lignes) {
	if (!$lignes) {
		return '';
	}
	$entete = $colonnes ? '(`' . implode('`,`', $colonnes) . '`) ' : '';

	return 'INSERT INTO `' . $table . '` ' . $entete . 'VALUES ' . implode(",\n", $lignes) . ";\n";
}

/**
 * Liste les sauvegardes présentes localement.
 *
 * @return array
 */
function dashagent_sauvegarde_lister() {
	$dir = dashagent_dir_sauvegardes();
	if (!$dir) {
		return [];
	}
	$liste = [];
	foreach ((array) glob($dir . 'sauvegarde-*.sql.gz') as $chemin) {
		$nom = basename($chemin);
		$liste[] = [
			'identifiant' => preg_replace('/^sauvegarde-(.*)\.sql\.gz$/', '$1', $nom),
			'fichier'     => $nom,
			'octets'      => (int) filesize($chemin),
			'date'        => date('c', (int) filemtime($chemin)),
		];
	}
	usort($liste, function ($a, $b) {
		return strcmp($b['date'], $a['date']);
	});

	return $liste;
}

/**
 * Résout un identifiant de sauvegarde en chemin local sûr.
 *
 * @param string $identifiant
 * @return string Chaîne vide si inconnu
 */
function dashagent_sauvegarde_chemin($identifiant) {
	if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', (string) $identifiant)) {
		return '';
	}
	$dir = dashagent_dir_sauvegardes();
	if (!$dir) {
		return '';
	}
	$chemin = $dir . 'sauvegarde-' . $identifiant . '.sql.gz';

	return is_file($chemin) ? $chemin : '';
}

/**
 * Supprime une sauvegarde.
 *
 * @param string $identifiant
 * @return bool
 */
function dashagent_sauvegarde_supprimer($identifiant) {
	$chemin = dashagent_sauvegarde_chemin($identifiant);

	return $chemin ? (bool) @unlink($chemin) : false;
}

/**
 * Applique la rétention configurée sur les sauvegardes locales.
 *
 * @param string $dir
 * @return int Nombre de fichiers supprimés
 */
function dashagent_sauvegarde_purger_anciennes($dir = '') {
	include_spip('inc/dashagent');
	$dir = $dir ?: dashagent_dir_sauvegardes();
	if (!$dir) {
		return 0;
	}
	$jours = max(1, (int) dashagent_config('retention_backup', 7));
	$limite = time() - $jours * 86400;
	$n = 0;
	foreach ((array) glob($dir . 'sauvegarde-*.sql.gz') as $chemin) {
		if (filemtime($chemin) < $limite && @unlink($chemin)) {
			$n++;
		}
	}

	return $n;
}
