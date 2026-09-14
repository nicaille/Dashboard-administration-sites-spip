<?php
/**
 * Lecture de l'état d'un site géré : serveur, base, fichiers de configuration.
 *
 * Tout ici est en lecture seule, et tout est masqué dès qu'une valeur ressemble
 * à un identifiant. Ce n'est pas de la coquetterie : `phpinfo()` recopie
 * l'environnement du processus, où beaucoup d'hébergeurs déposent le mot de
 * passe de la base ; `mes_options.php` porte souvent des clés d'API ; et
 * `spip_auteurs` contient les empreintes des mots de passe. Un tableau de bord
 * qui recopierait tout cela sur un second serveur multiplierait par deux la
 * surface d'attaque du parc.
 *
 * L'ensemble de ces opérations est refusé par défaut : il faut cocher
 * explicitement « Consulter l'état du serveur » sur le site géré.
 *
 * @package SPIP\Dashagent\Serveur
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Nombre maximum de lignes rendues en une fois par le parcours de tables.
 */
if (!defined('_DASHAGENT_TABLE_LOT_MAX')) {
	define('_DASHAGENT_TABLE_LOT_MAX', 200);
}

/**
 * Taille maximale d'un fichier de configuration rendu, en octets.
 */
if (!defined('_DASHAGENT_FICHIER_MAX')) {
	define('_DASHAGENT_FICHIER_MAX', 256 * 1024);
}

/* -------------------------------------------------------------------------- */
/* Masquage                                                                   */
/* -------------------------------------------------------------------------- */

/**
 * Ce nom désigne-t-il quelque chose qui ne doit pas voyager ?
 *
 * Le test porte sur le *nom*, pas sur la valeur : c'est le seul critère fiable.
 * Une chaîne de trente caractères aléatoires peut être un identifiant de
 * session comme une somme de contrôle, mais une variable appelée `DB_PASSWORD`
 * ne laisse aucun doute.
 *
 * @param string $nom
 * @return bool
 */
function dashagent_nom_sensible($nom) {
	// `\b` ne coupe pas sur un tiret bas, que PCRE range parmi les lettres :
	// `AWS_ACCESS_KEY_ID` échappait au masque alors que `API-KEY` y tombait.
	// D'où cette frontière explicite, qui laisse passer « monkey » et
	// « keyboard » sans laisser filer une clé.
	$bord = '(?:^|[^a-z])';
	$fin  = '(?:[^a-z]|$)';

	// `low_sec` et `prefs` ne se devinent pas d'après leur nom : ce sont des
	// jetons ou des données propres à SPIP, qu'il faut nommer.
	return (bool) preg_match(
		'/(pass|passwd|password|pwd|secret|token|apikey|' . $bord . 'keys?' . $fin . '|'
			. $bord . 'cles?' . $fin . '|salt|alea|nonce|cookie|auth|credential|dsn|private|low_sec|prefs)/i',
		(string) $nom
	);
}

/**
 * Cette valeur porte-t-elle un identifiant, quel que soit son nom ?
 *
 * Le nom ne suffit pas toujours : une variable appelée `HTTPS_PROXY` ou
 * `BACKUP_URL` est parfaitement anodine jusqu'au jour où elle vaut
 * `https://utilisateur:motdepasse@hote/`. Ce cas-là se reconnaît à la forme.
 *
 * @param mixed $valeur
 * @return bool
 */
function dashagent_valeur_sensible($valeur) {
	if (!is_string($valeur) || $valeur === '') {
		return false;
	}

	return (bool) preg_match('#[a-z][a-z0-9+.-]*://[^/\s:@]+:[^/\s@]+@#i', $valeur);
}

/**
 * Remplace une valeur par une marque, en conservant une idée de sa forme.
 *
 * Dire « masqué, 32 caractères » aide au diagnostic — on voit qu'une clé est
 * renseignée et de quelle longueur — sans rien livrer.
 *
 * @param mixed $valeur
 * @return string
 */
function dashagent_masquer($valeur) {
	if ($valeur === null || $valeur === '') {
		return '';
	}
	$longueur = is_scalar($valeur) ? strlen((string) $valeur) : 0;

	return '••••••••' . ($longueur ? ' (' . $longueur . ' caractères, masqué)' : ' (masqué)');
}

/**
 * Masque, dans un texte, la valeur de toute affectation dont le nom est parlant.
 *
 * Couvre les formes qu'on rencontre dans un `mes_options.php` : `define()`,
 * `const`, affectation de variable, entrée de tableau. Le nom reste lisible,
 * seule la valeur disparaît — on veut pouvoir constater qu'une constante existe.
 *
 * @param string $texte
 * @return string
 */
function dashagent_masquer_texte($texte) {
	$texte = (string) $texte;
	$marque = "'••••••••'";

	// define('NOM', valeur) — la valeur peut être une chaîne, un nombre, une
	// constante : on remplace tout ce qui suit la virgule jusqu'à la parenthèse.
	$texte = preg_replace_callback(
		'/(\bdefine\s*\(\s*([\'"])([A-Za-z0-9_]+)\2\s*,\s*)([^)]*)/s',
		function ($m) use ($marque) {
			return dashagent_nom_sensible($m[3]) ? $m[1] . $marque : $m[0];
		},
		$texte
	);

	// const NOM = valeur ;  $nom = valeur ;  $tableau['nom'] = valeur ;
	$texte = preg_replace_callback(
		'/(\bconst\s+([A-Za-z0-9_]+)\s*=\s*|\$([A-Za-z0-9_]+)\s*=\s*|\$[A-Za-z0-9_]+\s*\[\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]\s*=\s*)([^;\n]*)/',
		function ($m) use ($marque) {
			$nom = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : ($m[4] ?? ''));

			return dashagent_nom_sensible($nom) ? $m[1] . $marque : $m[0];
		},
		$texte
	);

	// Une URL portant des identifiants, quel que soit le nom qui la reçoit.
	$texte = preg_replace('#([a-z][a-z0-9+.-]*://[^/\s:@\'"]+):[^/\s@\'"]+@#i', '$1:••••••••@', $texte);

	// 'nom' => valeur, dans un tableau littéral.
	$texte = preg_replace_callback(
		'/(([\'"])([A-Za-z0-9_]+)\2\s*=>\s*)([^,\]\n]*)/',
		function ($m) use ($marque) {
			return dashagent_nom_sensible($m[3]) ? $m[1] . $marque : $m[0];
		},
		$texte
	);

	return $texte;
}

/* -------------------------------------------------------------------------- */
/* Résumé                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * Résumé de l'état du serveur : PHP, ses extensions, la base.
 *
 * @return array
 */
function dashagent_serveur_resume() {
	include_spip('inc/dashagent_fs');
	include_spip('inc/dashagent_infos');

	$extensions = get_loaded_extensions();
	sort($extensions, SORT_NATURAL | SORT_FLAG_CASE);

	return [
		'ok'  => true,
		'php' => [
			'version'             => PHP_VERSION,
			'sapi'                => PHP_SAPI,
			'os'                  => PHP_OS_FAMILY,
			'memory_limit'        => (string) ini_get('memory_limit'),
			'max_execution_time'  => (int) ini_get('max_execution_time'),
			'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
			'post_max_size'       => (string) ini_get('post_max_size'),
			'display_errors'      => (string) ini_get('display_errors'),
			'opcache'             => (bool) ini_get('opcache.enable'),
			'extensions'          => $extensions,
			'nb_extensions'       => count($extensions),
		],
		'base'    => dashagent_serveur_base(),
		'disque'  => [
			'libre' => dashagent_espace_disque_libre(),
		],
	];
}

/**
 * État de la base : moteur, version, poids, nombre de tables.
 *
 * Le poids se lit différemment selon le moteur, et n'est pas toujours lisible :
 * on rend `null` plutôt qu'un zéro trompeur.
 *
 * @return array
 */
function dashagent_serveur_base() {
	include_spip('inc/dashagent_sauvegarde');

	$moteur = function_exists('dashagent_moteur_sql') ? dashagent_moteur_sql() : '';
	$tables = sql_alltable('%');
	$tables = is_array($tables) ? $tables : [];

	$base = [
		'moteur'    => $moteur,
		'version'   => is_string(sql_version()) ? sql_version() : '',
		'nb_tables' => count($tables),
		'octets'    => dashagent_serveur_poids_base($moteur),
	];

	return $base;
}

/**
 * Poids de la base, en octets, ou null si le moteur ne le dit pas.
 *
 * @param string $moteur
 * @return int|null
 */
function dashagent_serveur_poids_base($moteur) {
	// Le type s'écrit « sqlite3 » ou « sqlite » selon la version de SPIP.
	if (strncmp((string) $moteur, 'sqlite', 6) === 0) {
		// Le fichier lui-même : c'est la mesure la plus honnête.
		foreach (dashagent_serveur_fichiers_sqlite() as $chemin) {
			if (@is_file($chemin)) {
				return (int) @filesize($chemin);
			}
		}

		return null;
	}

	$res = sql_query('SHOW TABLE STATUS', '', 'continue');
	if (!$res) {
		return null;
	}
	$octets = 0;
	while ($ligne = sql_fetch($res, '')) {
		$octets += (int) ($ligne['Data_length'] ?? 0) + (int) ($ligne['Index_length'] ?? 0);
	}

	return $octets;
}

/**
 * Où peut se trouver le fichier de la base, pour un site SQLite.
 *
 * Le nom déclaré dans `connect.php` est tantôt un chemin complet, tantôt le seul
 * nom de base, avec ou sans extension : on essaie les formes connues.
 *
 * @return array
 */
function dashagent_serveur_fichiers_sqlite() {
	$nom = (string) ($GLOBALS['connexions'][0]['db'] ?? '');
	if ($nom === '') {
		return [];
	}
	$dossier = defined('_DIR_DB') ? _DIR_DB : ((_DIR_RACINE ?: './') . 'config/bases/');

	return array_values(array_filter([
		$nom,
		$dossier . $nom,
		$dossier . $nom . '.sqlite',
	]));
}

/* -------------------------------------------------------------------------- */
/* phpinfo                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * Le `phpinfo()` du site, en HTML, débarrassé de ce qui ne doit pas voyager.
 *
 * Seul le corps est rendu : la page complète embarque ses propres styles et un
 * `<html>` qui ne s'insère nulle part. Les lignes dont l'intitulé désigne un
 * identifiant voient leur valeur remplacée — c'est dans les tableaux
 * d'environnement que dorment les mots de passe de base de données.
 *
 * @return array
 */
function dashagent_serveur_phpinfo() {
	if (!function_exists('phpinfo')) {
		return ['ok' => false, 'erreur' => 'phpinfo() est désactivée sur ce serveur'];
	}

	$niveau = ob_get_level();
	ob_start();
	phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES | INFO_ENVIRONMENT | INFO_VARIABLES);
	$html = '';
	while (ob_get_level() > $niveau) {
		$morceau = ob_get_clean();
		if ($morceau === false) {
			break;
		}
		$html = $morceau . $html;
	}

	if (trim($html) === '') {
		return ['ok' => false, 'erreur' => 'phpinfo() n’a rien produit (fonction bridée ?)'];
	}

	return [
		'ok'   => true,
		'html' => dashagent_phpinfo_expurger($html),
	];
}

/**
 * Extrait le corps d'une sortie `phpinfo()` et en masque les valeurs sensibles.
 *
 * @param string $html
 * @return string
 */
function dashagent_phpinfo_expurger($html) {
	$html = (string) $html;

	// Ne garder que le contenu du <body> : le reste est une page autonome.
	if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $m)) {
		$html = $m[1];
	}
	// Les styles de phpinfo visent des sélecteurs génériques (table, td, h1…)
	// qui déborderaient sur la page qui l'accueille.
	$html = preg_replace('#<style[^>]*>.*?</style>#is', '', $html);
	$html = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);

	// Chaque ligne de phpinfo est un <tr> dont la première cellule nomme la
	// directive ou la variable : c'est ce nom qui décide du masquage.
	return preg_replace_callback('#<tr>(.*?)</tr>#is', function ($m) {
		if (!preg_match('#<t[dh][^>]*>(.*?)</t[dh]>#is', $m[1], $cellule)) {
			return $m[0];
		}
		$nom = trim(html_entity_decode(strip_tags($cellule[1]), ENT_QUOTES, 'UTF-8'));
		$corps = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
		if (!dashagent_nom_sensible($nom) && !dashagent_valeur_sensible($corps)) {
			return $m[0];
		}

		// Toutes les cellules sauf la première, qui porte le nom.
		$premiere = true;

		return '<tr>' . preg_replace_callback('#<td[^>]*>(.*?)</td>#is', function ($c) use (&$premiere) {
			if ($premiere) {
				$premiere = false;

				return $c[0];
			}
			$brut = trim(strip_tags($c[1]));

			return '<td class="v">' . ($brut === '' || $brut === 'no value' ? $brut : '••••••••') . '</td>';
		}, $m[1]) . '</tr>';
	}, $html);
}

/* -------------------------------------------------------------------------- */
/* Fichiers de configuration                                                  */
/* -------------------------------------------------------------------------- */

/**
 * Les fichiers que l'agent accepte de rendre, et eux seuls.
 *
 * Une liste fermée, pas un chemin reçu en argument : autrement, l'opération
 * deviendrait une lecture arbitraire du disque, `config/connect.php` compris.
 *
 * @return array Clé publique => chemin relatif à la racine
 */
function dashagent_serveur_fichiers_lisibles() {
	return [
		'htaccess'      => '.htaccess',
		'mes_options'   => 'config/mes_options.php',
		'mes_fonctions' => 'squelettes/mes_fonctions.php',
	];
}

/**
 * Rend le contenu d'un des fichiers de configuration autorisés.
 *
 * @param array $args
 *     - string `fichier` : une clé de `dashagent_serveur_fichiers_lisibles()`
 * @return array
 */
function dashagent_serveur_fichier($args) {
	$clef = (string) ($args['fichier'] ?? '');
	$connus = dashagent_serveur_fichiers_lisibles();
	if (!isset($connus[$clef])) {
		return ['ok' => false, 'erreur' => 'Fichier non consultable : ' . $clef];
	}

	$chemin = (_DIR_RACINE ?: './') . $connus[$clef];
	if (!@is_file($chemin)) {
		return [
			'ok'       => true,
			'fichier'  => $connus[$clef],
			'existe'   => false,
			'contenu'  => '',
			'octets'   => 0,
		];
	}

	$octets = (int) @filesize($chemin);
	$contenu = (string) @file_get_contents($chemin, false, null, 0, _DASHAGENT_FICHIER_MAX);

	return [
		'ok'       => true,
		'fichier'  => $connus[$clef],
		'existe'   => true,
		'octets'   => $octets,
		'tronque'  => ($octets > _DASHAGENT_FICHIER_MAX),
		'modifie'  => date('Y-m-d H:i:s', (int) @filemtime($chemin)),
		'contenu'  => dashagent_masquer_texte($contenu),
	];
}

/* -------------------------------------------------------------------------- */
/* Parcours des tables                                                        */
/* -------------------------------------------------------------------------- */

/**
 * Inventaire des tables : nom, cardinalité, poids quand le moteur le dit.
 *
 * @return array
 */
function dashagent_serveur_tables() {
	$noms = sql_alltable('%');
	$noms = is_array($noms) ? $noms : [];
	sort($noms, SORT_NATURAL);

	$poids = dashagent_serveur_poids_tables();
	$tables = [];
	foreach ($noms as $nom) {
		$n = sql_countsel($nom, '', '', '', '', 'continue');
		$tables[] = [
			'nom'     => $nom,
			'lignes'  => ($n === false || $n === null) ? null : (int) $n,
			'octets'  => $poids[$nom] ?? null,
		];
	}

	return ['ok' => true, 'tables' => $tables];
}

/**
 * Poids de chaque table, indexé par nom. Vide si le moteur ne le dit pas.
 *
 * @return array
 */
function dashagent_serveur_poids_tables() {
	$res = sql_query('SHOW TABLE STATUS', '', 'continue');
	if (!$res) {
		return [];
	}
	$poids = [];
	while ($ligne = sql_fetch($res, '')) {
		$nom = (string) ($ligne['Name'] ?? '');
		if ($nom !== '') {
			$poids[$nom] = (int) ($ligne['Data_length'] ?? 0) + (int) ($ligne['Index_length'] ?? 0);
		}
	}

	return $poids;
}

/**
 * Vérifie qu'un nom de table existe réellement, et rend le nom tel que la base
 * l'écrit.
 *
 * La comparaison se fait contre la liste que rend le moteur, jamais contre le
 * texte reçu : c'est ce qui interdit qu'un nom fabriqué se retrouve dans une
 * requête. Rien n'est échappé, parce que rien n'est repris de l'entrée.
 *
 * @param string $demande
 * @return string Nom réel, ou chaîne vide si la table n'existe pas
 */
function dashagent_serveur_table_connue($demande) {
	$demande = (string) $demande;
	if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $demande)) {
		return '';
	}
	$noms = sql_alltable('%');
	$noms = is_array($noms) ? $noms : [];

	foreach ($noms as $nom) {
		if (strcasecmp($nom, $demande) === 0) {
			return $nom;
		}
	}

	return '';
}

/**
 * Colonnes réelles d'une table, telles que le moteur les déclare.
 *
 * @param string $table Nom déjà validé
 * @return array
 */
function dashagent_serveur_colonnes($table) {
	$description = sql_showtable($table, true);
	$champs = is_array($description['field'] ?? null) ? $description['field'] : [];

	$colonnes = [];
	foreach ($champs as $nom => $type) {
		$colonnes[] = [
			'nom'      => (string) $nom,
			'type'     => (string) $type,
			'masquee'  => dashagent_nom_sensible((string) $nom),
		];
	}

	return $colonnes;
}

/**
 * Contenu d'une table, paginé, trié, filtré.
 *
 * Le tri et le filtre portent sur des noms de colonne retrouvés dans le schéma
 * réel ; la valeur du filtre, elle, passe par `sql_quote()`. Le sens du tri se
 * réduit à deux mots choisis ici. Aucune portion de la requête ne provient donc
 * telle quelle de l'appelant.
 *
 * @param array $args
 *     - string `table`
 *     - int    `debut`, `lot`
 *     - string `tri`, `sens`
 *     - string `filtre_colonne`, `filtre_valeur`
 * @return array
 */
function dashagent_serveur_table_contenu($args) {
	$table = dashagent_serveur_table_connue($args['table'] ?? '');
	if ($table === '') {
		return ['ok' => false, 'erreur' => 'Table inconnue : ' . (string) ($args['table'] ?? '')];
	}

	$colonnes = dashagent_serveur_colonnes($table);
	$noms = array_column($colonnes, 'nom');
	if (!$noms) {
		return ['ok' => false, 'erreur' => 'Colonnes illisibles pour ' . $table];
	}

	$debut = max(0, (int) ($args['debut'] ?? 0));
	$lot   = (int) ($args['lot'] ?? 50);
	$lot   = max(1, min(_DASHAGENT_TABLE_LOT_MAX, $lot));

	$tri = dashagent_serveur_colonne_connue($args['tri'] ?? '', $noms);
	$sens = (strtolower((string) ($args['sens'] ?? '')) === 'desc') ? 'DESC' : 'ASC';
	$ordre = $tri !== '' ? $tri . ' ' . $sens : '';

	$ou = dashagent_serveur_filtre($args, $noms);
	if ($ou === false) {
		return ['ok' => false, 'erreur' => 'Colonne de filtre inconnue'];
	}

	$total = sql_countsel($table, $ou, '', '', '', 'continue');
	$lignes = sql_allfetsel('*', $table, $ou, '', $ordre, $debut . ',' . $lot, '', '', 'continue');
	$lignes = is_array($lignes) ? $lignes : [];

	return [
		'ok'       => true,
		'table'    => $table,
		'colonnes' => $colonnes,
		'lignes'   => dashagent_serveur_masquer_lignes($lignes, $colonnes),
		'total'    => ($total === false || $total === null) ? null : (int) $total,
		'debut'    => $debut,
		'lot'      => $lot,
		'tri'      => $tri,
		'sens'     => strtolower($sens),
	];
}

/**
 * Rend le nom réel d'une colonne demandée, ou une chaîne vide.
 *
 * @param string $demande
 * @param array $noms Colonnes réelles de la table
 * @return string
 */
function dashagent_serveur_colonne_connue($demande, $noms) {
	$demande = (string) $demande;
	foreach ($noms as $nom) {
		if (strcasecmp($nom, $demande) === 0) {
			return $nom;
		}
	}

	return '';
}

/**
 * Construit la clause de filtre, ou false si la colonne demandée n'existe pas.
 *
 * @param array $args
 * @param array $noms
 * @return string|false
 */
function dashagent_serveur_filtre($args, $noms) {
	$colonne = (string) ($args['filtre_colonne'] ?? '');
	$valeur  = (string) ($args['filtre_valeur'] ?? '');
	if ($colonne === '' || $valeur === '') {
		return '';
	}

	$reelle = dashagent_serveur_colonne_connue($colonne, $noms);
	if ($reelle === '') {
		return false;
	}

	// Filtrer sur une colonne masquée reviendrait à la deviner caractère par
	// caractère : le masquage ne servirait plus à rien.
	if (dashagent_nom_sensible($reelle)) {
		return false;
	}

	return $reelle . ' LIKE ' . sql_quote('%' . $valeur . '%');
}

/**
 * Masque les colonnes sensibles et abrège les valeurs démesurées.
 *
 * @param array $lignes
 * @param array $colonnes
 * @return array
 */
function dashagent_serveur_masquer_lignes($lignes, $colonnes) {
	$masquees = [];
	foreach ($colonnes as $colonne) {
		if (!empty($colonne['masquee'])) {
			$masquees[$colonne['nom']] = true;
		}
	}

	$plafond = 2000;
	foreach ($lignes as $rang => $ligne) {
		foreach ((array) $ligne as $nom => $valeur) {
			if (isset($masquees[$nom])) {
				$lignes[$rang][$nom] = dashagent_masquer($valeur);
				continue;
			}
			// Une meta comme `dashagent` porte le secret partagé, chiffré : son
			// nom ne trahit rien, son contenu si.
			if (is_string($valeur) && strlen($valeur) > $plafond) {
				$lignes[$rang][$nom] = substr($valeur, 0, $plafond) . '… (' . strlen($valeur) . ' caractères)';
			}
		}
		$lignes[$rang] = dashagent_serveur_masquer_meta($lignes[$rang]);
	}

	return $lignes;
}

/**
 * Cas particulier de `spip_meta` : c'est la ligne, pas la colonne, qui décide.
 *
 * La table a deux colonnes, `nom` et `valeur`, dont aucune n'est sensible en
 * soi. Ce sont certaines entrées qui le sont : le secret partagé de l'agent, les
 * aléas de session, les clés du site.
 *
 * @param array $ligne
 * @return array
 */
function dashagent_serveur_masquer_meta($ligne) {
	if (!isset($ligne['nom'], $ligne['valeur'])) {
		return $ligne;
	}
	if (dashagent_nom_sensible((string) $ligne['nom']) || in_array((string) $ligne['nom'], ['dashagent', 'dashboard'], true)) {
		$ligne['valeur'] = dashagent_masquer($ligne['valeur']);
	}

	return $ligne;
}
