<?php
/**
 * Sauvegarde de la base de données du site géré.
 *
 * Un export PHP pur, streamé en gzip par `sql_select()` et lu par lots : rien
 * n'est supposé de l'hébergement — ni `exec()`, ni `mysqldump`, ni accès au
 * système de fichiers hors du site —, et la mémoire ne croît pas avec la taille
 * de la base. C'est la situation normale en mutualisé.
 *
 * Ce n'est pas la sauvegarde du core : `ecrire/inc/dump.php` rend un fichier que
 * seul SPIP sait relire, et la restauration attendue ici est celle d'un
 * administrateur devant un incident, avec les outils de son hébergeur. D'où un
 * dump SQL ordinaire.
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
 * Marque de fin écrite au bas de chaque sauvegarde.
 *
 * Elle atteste que l'export est allé à son terme — ce que le pied de page du
 * gzip, seul, ne dit pas : un script tué proprement entre deux tables produit
 * une archive valide et incomplète. La tour de contrôle la cherche au
 * rapatriement.
 */
if (!defined('_DASHAGENT_SAUVEGARDE_FIN')) {
	define('_DASHAGENT_SAUVEGARDE_FIN', '-- fin de sauvegarde');
}

/**
 * Temps qu'une tranche d'export s'accorde, en secondes.
 *
 * Il n'est pas choisi pour ce que PHP tient, mais pour ce qu'un frontal
 * supporte : le `first_byte_timeout` d'un Varnish vaut soixante secondes par
 * défaut, et un CDN d'hébergeur ne l'expose nulle part. Vingt secondes de
 * travail, plus la réponse, tiennent largement sous cette patience — et sous le
 * `max_execution_time` de trente secondes des mutualisés, qui coupe sans
 * prévenir là où le frontal, au moins, rend un 503.
 *
 * Le budget borne la tranche, pas l'export : un site de plusieurs gigaoctets
 * prend le nombre de tranches qu'il lui faut.
 */
if (!defined('_DASHAGENT_SAUVEGARDE_BUDGET')) {
	define('_DASHAGENT_SAUVEGARDE_BUDGET', 20.0);
}

/**
 * Crée une sauvegarde de la base.
 *
 * @param array $args
 *     - bool `sans_statistiques` : exclure les tables de statistiques
 *     - array `exclure` : tables supplémentaires à exclure
 *     - bool `decoupee` : exporter par tranches bornées en temps
 *     - string `reprendre` : identifiant d'un export découpé à poursuivre
 * @return array{ok: bool, erreur: string, sauvegarde: array}
 */
function dashagent_sauvegarde_creer($args = []) {
	/* Le découpage ne s'impose pas : il se demande. Une tour de contrôle
	   ancienne appelle cette opération sans le drapeau et attend un export
	   d'un seul tenant — lui rendre une tranche et un « termine » à faux
	   qu'elle ne sait pas lire lui ferait croire la sauvegarde faite. */
	if (!empty($args['decoupee']) || !empty($args['reprendre'])) {
		return dashagent_sauvegarde_tranche($args);
	}

	$dir = dashagent_dir_sauvegardes();
	if (!$dir) {
		return ['ok' => false, 'erreur' => 'Répertoire de sauvegarde indisponible ou non inscriptible', 'sauvegarde' => []];
	}
	if (!function_exists('gzopen')) {
		return ['ok' => false, 'erreur' => 'Extension PHP zlib absente', 'sauvegarde' => []];
	}

	$exclure = dashagent_sauvegarde_exclusions($args);

	$tables = sql_alltable('%');
	if (!is_array($tables) || !$tables) {
		return ['ok' => false, 'erreur' => 'Aucune table lisible dans la base', 'sauvegarde' => []];
	}
	$tables = array_values(array_diff($tables, $exclure));

	$identifiant = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
	$nom_fichier = 'sauvegarde-' . $identifiant . '.sql.gz';
	$chemin      = $dir . $nom_fichier;

	/* L'export s'écrit sous un nom provisoire, et ne prend son nom définitif
	   qu'une fois terminé. Sans cela, un processus tué en cours d'écriture —
	   dépassement du temps d'exécution, de la mémoire, ou worker emporté par
	   l'hébergement — laisse une archive amputée sous le nom d'une sauvegarde
	   valide. Elle serait listée, adoptée, et donnerait l'illusion d'une
	   protection le jour où il faudrait restaurer.

	   Le renommage, lui, est atomique sur le même système de fichiers : à aucun
	   instant le nom définitif ne désigne un fichier incomplet. */
	$provisoire = $chemin . '.partiel';

	$debut  = microtime(true);
	$erreur = dashagent_sauvegarde_ecrire($provisoire, $tables);

	if ($erreur !== '') {
		@unlink($provisoire);

		return ['ok' => false, 'erreur' => $erreur, 'sauvegarde' => []];
	}

	if (!@rename($provisoire, $chemin)) {
		@unlink($provisoire);

		return ['ok' => false, 'erreur' => 'Sauvegarde écrite mais impossible à publier sous son nom', 'sauvegarde' => []];
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
 * Une tranche d'export, bornée en temps, reprise là où la précédente s'est arrêtée.
 *
 * L'export d'un seul tenant se heurte à un mur qui n'est pas le nôtre : le
 * frontal du site géré. Un Varnish, un nginx, le CDN d'un hébergement rendent
 * un 503 au bout de soixante secondes pendant que PHP travaille encore, et la
 * tour de contrôle ne sait plus rien de l'issue — c'est le « silence » que
 * `dashboard_silence()` distingue d'une réponse. Le rattrapage sait constater
 * après coup ; il ne sait pas éviter.
 *
 * Le découpage, lui, l'évite : chaque appel rend la main avant la patience du
 * frontal, et l'export se poursuit à l'appel suivant. Ce qui tient ce fil d'un
 * appel à l'autre est un état sur disque — la table en cours, le rang atteint
 * dans cette table, et **la taille du fichier au dernier point de contrôle**.
 *
 * Cette taille est ce qui rend la reprise sûre. Un processus tué en plein
 * milieu d'une tranche laisse dans le `.partiel` des octets que l'état ne
 * mentionne pas : on les tronque avant de reprendre. Sans cela la reprise
 * écrirait à la suite d'un membre gzip inachevé, ou rejouerait des lignes déjà
 * écrites — une archive que rien, ensuite, ne signalerait comme fausse.
 *
 * Chaque tranche ajoute un membre gzip au fichier. C'est valide (RFC 1952), et
 * `gunzip` les relit à la file ; la vérification au rapatriement, côté tour de
 * contrôle, les parcourt un par un.
 *
 * @param array $args
 * @return array
 */
function dashagent_sauvegarde_tranche($args = []) {
	$dir = dashagent_dir_sauvegardes();
	if (!$dir) {
		return ['ok' => false, 'termine' => true, 'erreur' => 'Répertoire de sauvegarde indisponible ou non inscriptible', 'sauvegarde' => []];
	}
	if (!function_exists('gzopen')) {
		return ['ok' => false, 'termine' => true, 'erreur' => 'Extension PHP zlib absente', 'sauvegarde' => []];
	}

	$echeance = microtime(true) + _DASHAGENT_SAUVEGARDE_BUDGET;
	$exclure  = dashagent_sauvegarde_exclusions($args);

	$reprendre = (string) ($args['reprendre'] ?? '');
	$etat = ($reprendre !== '') ? dashagent_sauvegarde_etat_lire($reprendre) : null;

	if ($etat === null && $reprendre !== '') {
		return ['ok' => false, 'termine' => true, 'erreur' => 'Aucun export en cours sous l’identifiant ' . $reprendre, 'sauvegarde' => []];
	}

	if ($etat === null) {
		/* Une première tranche peut très bien s'être perdue en route : elle a
		   fait son travail, et c'est sa réponse qui n'est pas revenue. Reprendre
		   l'export déjà entamé plutôt que d'en ouvrir un second vaut mieux — à
		   condition qu'il exporte la même chose, faute de quoi on mélangerait
		   deux demandes. */
		$etat = dashagent_sauvegarde_reprise_adoptable($exclure);
	}

	if ($etat === null) {
		$tables = sql_alltable('%');
		if (!is_array($tables) || !$tables) {
			return ['ok' => false, 'termine' => true, 'erreur' => 'Aucune table lisible dans la base', 'sauvegarde' => []];
		}
		$etat = [
			'identifiant'       => date('Ymd-His') . '-' . bin2hex(random_bytes(4)),
			'empreinte_options' => dashagent_sauvegarde_empreinte_options($exclure),
			'exclues'           => $exclure,
			'tables'            => array_values(array_diff($tables, $exclure)),
			'index'             => 0,
			'offset'            => 0,
			'octets_bruts'      => 0,
			'debut'             => microtime(true),
			'tranches'          => 0,
		];
	}

	$partiel = dashagent_sauvegarde_partiel_chemin($etat['identifiant']);
	if ($partiel === '') {
		return ['ok' => false, 'termine' => true, 'erreur' => 'Identifiant d’export invalide', 'sauvegarde' => []];
	}

	$erreur = dashagent_sauvegarde_recaler($partiel, (int) $etat['octets_bruts']);
	if ($erreur !== '') {
		dashagent_sauvegarde_etat_effacer($etat['identifiant']);

		return ['ok' => false, 'termine' => true, 'erreur' => $erreur, 'sauvegarde' => []];
	}

	$gz = gzopen($partiel, 'ab6');
	if (!$gz) {
		return ['ok' => false, 'termine' => true, 'erreur' => 'Impossible d’ouvrir le fichier de sauvegarde en écriture', 'sauvegarde' => []];
	}

	if ((int) $etat['octets_bruts'] === 0) {
		gzwrite($gz, dashagent_sauvegarde_entete($etat['tables']));
	}

	$total = count($etat['tables']);
	while ($etat['index'] < $total) {
		$table = (string) $etat['tables'][$etat['index']];

		if ((int) $etat['offset'] === 0) {
			$erreur = dashagent_sauvegarde_structure_ecrire($gz, $table);
			if ($erreur !== '') {
				gzclose($gz);

				return ['ok' => false, 'termine' => true, 'erreur' => $erreur, 'sauvegarde' => []];
			}
		}

		$avancee = dashagent_sauvegarde_table($gz, $table, (int) $etat['offset'], $echeance);
		if ($avancee['erreur'] !== '') {
			gzclose($gz);

			return ['ok' => false, 'termine' => true, 'erreur' => $avancee['erreur'], 'sauvegarde' => []];
		}

		$etat['offset'] = $avancee['fini'] ? 0 : (int) $avancee['offset'];
		if ($avancee['fini']) {
			$etat['index'] = (int) $etat['index'] + 1;
		}

		if (microtime(true) >= $echeance) {
			break;
		}
	}

	$etat['tranches'] = (int) $etat['tranches'] + 1;

	if ($etat['index'] >= $total) {
		gzwrite($gz, dashagent_sauvegarde_pied());
		gzclose($gz);

		return dashagent_sauvegarde_conclure($etat, $partiel);
	}

	gzclose($gz);

	clearstatcache(true, $partiel);
	$etat['octets_bruts'] = (int) filesize($partiel);

	if (!dashagent_sauvegarde_etat_ecrire($etat)) {
		return ['ok' => false, 'termine' => true, 'erreur' => 'Impossible d’enregistrer le point de reprise de l’export', 'sauvegarde' => []];
	}

	return [
		'ok'          => true,
		'termine'     => false,
		'erreur'      => '',
		'reprendre'   => $etat['identifiant'],
		'sauvegarde'  => [],
		'avancement'  => [
			'identifiant'   => $etat['identifiant'],
			'tables'        => (int) $etat['index'],
			'tables_total'  => $total,
			'table'         => (string) ($etat['tables'][$etat['index']] ?? ''),
			'lignes'        => (int) $etat['offset'],
			'octets'        => (int) $etat['octets_bruts'],
			'tranches'      => (int) $etat['tranches'],
		],
	];
}

/**
 * Publie l'export achevé sous son nom définitif.
 *
 * @param array $etat
 * @param string $partiel
 * @return array
 */
function dashagent_sauvegarde_conclure($etat, $partiel) {
	$dir    = dashagent_dir_sauvegardes();
	$nom    = 'sauvegarde-' . $etat['identifiant'] . '.sql.gz';
	$chemin = $dir . $nom;

	if (!@rename($partiel, $chemin)) {
		@unlink($partiel);
		dashagent_sauvegarde_etat_effacer($etat['identifiant']);

		return ['ok' => false, 'termine' => true, 'erreur' => 'Sauvegarde écrite mais impossible à publier sous son nom', 'sauvegarde' => []];
	}

	dashagent_sauvegarde_etat_effacer($etat['identifiant']);
	dashagent_sauvegarde_purger_anciennes($dir);

	return [
		'ok'      => true,
		'termine' => true,
		'erreur'  => '',
		'sauvegarde' => [
			'identifiant' => $etat['identifiant'],
			'fichier'     => $nom,
			'octets'      => (int) filesize($chemin),
			'sha256'      => hash_file('sha256', $chemin),
			'date'        => date('c'),
			'tables'      => count((array) $etat['tables']),
			'exclues'     => (array) $etat['exclues'],
			'tranches'    => (int) $etat['tranches'],
			'duree_ms'    => (int) round((microtime(true) - (float) $etat['debut']) * 1000),
		],
	];
}

/**
 * Ramène le fichier partiel à son dernier point de contrôle.
 *
 * Tout ce qui a été écrit au-delà provient d'une tranche qui n'est pas allée à
 * son terme : ces octets n'ont jamais été comptés, et les rejouer donnerait une
 * archive plausible et fausse.
 *
 * @param string $partiel
 * @param int $octets Taille au dernier point de contrôle
 * @return string Message d'erreur, vide si succès
 */
function dashagent_sauvegarde_recaler($partiel, $octets) {
	if ($octets <= 0) {
		// Première tranche : rien d'acquis, on repart d'un fichier neuf.
		if (is_file($partiel)) {
			@unlink($partiel);
		}

		return '';
	}

	clearstatcache(true, $partiel);
	if (!is_file($partiel)) {
		return 'L’export en cours a disparu du disque du site';
	}

	$taille = (int) filesize($partiel);
	if ($taille < $octets) {
		return 'L’export en cours est plus court que son point de reprise ('
			. $taille . ' octets pour ' . $octets . ' attendus)';
	}
	if ($taille === $octets) {
		return '';
	}

	$fp = @fopen($partiel, 'r+b');
	if (!$fp) {
		return 'L’export en cours n’est pas réinscriptible';
	}
	$tronque = @ftruncate($fp, $octets);
	fclose($fp);

	return $tronque ? '' : 'Impossible de ramener l’export en cours à son point de reprise';
}

/**
 * Les tables écartées de l'export, selon les options de la demande.
 *
 * @param array $args
 * @return array
 */
function dashagent_sauvegarde_exclusions($args) {
	$exclure = [];
	if (!empty($args['sans_statistiques'])) {
		$exclure = dashagent_tables_statistiques();
	}
	if (!empty($args['exclure']) && is_array($args['exclure'])) {
		$exclure = array_merge($exclure, array_map('strval', $args['exclure']));
	}

	return array_values(array_unique($exclure));
}

/**
 * Ce qui distingue deux demandes d'export l'une de l'autre.
 *
 * Reprendre un export entamé n'est légitime que s'il exporte ce qu'on demande :
 * adopter un export allégé pour une demande complète rendrait une sauvegarde
 * amputée sous le nom d'une sauvegarde entière.
 *
 * @param array $exclure
 * @return string
 */
function dashagent_sauvegarde_empreinte_options($exclure) {
	$exclure = array_map('strval', (array) $exclure);
	sort($exclure);

	return substr(hash('sha256', implode(',', $exclure)), 0, 16);
}

/**
 * Un identifiant de sauvegarde est-il de notre fabrication ?
 *
 * Pur à dessein : il sert à valider un état relu, et une validation qui aurait
 * besoin du disque ne pourrait pas se vérifier hors de SPIP.
 *
 * @param string $identifiant
 * @return bool
 */
function dashagent_sauvegarde_identifiant_valide($identifiant) {
	return (bool) preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', (string) $identifiant);
}

/**
 * Chemin du fichier d'état d'un export découpé.
 *
 * @param string $identifiant
 * @return string Chaîne vide si l'identifiant n'est pas conforme
 */
function dashagent_sauvegarde_etat_chemin($identifiant) {
	if (!dashagent_sauvegarde_identifiant_valide($identifiant)) {
		return '';
	}
	$dir = dashagent_dir_sauvegardes();

	return $dir ? $dir . 'sauvegarde-' . $identifiant . '.etat.json' : '';
}

/**
 * Chemin du fichier partiel d'un export découpé.
 *
 * @param string $identifiant
 * @return string Chaîne vide si l'identifiant n'est pas conforme
 */
function dashagent_sauvegarde_partiel_chemin($identifiant) {
	if (!dashagent_sauvegarde_identifiant_valide($identifiant)) {
		return '';
	}
	$dir = dashagent_dir_sauvegardes();

	return $dir ? $dir . 'sauvegarde-' . $identifiant . '.sql.gz.partiel' : '';
}

/**
 * Relit l'état d'un export découpé.
 *
 * @param string $identifiant
 * @return array|null
 */
function dashagent_sauvegarde_etat_lire($identifiant) {
	$chemin = dashagent_sauvegarde_etat_chemin($identifiant);
	if ($chemin === '' || !is_file($chemin)) {
		return null;
	}

	return dashagent_sauvegarde_etat_valide(json_decode((string) @file_get_contents($chemin), true));
}

/**
 * Un état relu n'est un état que s'il porte tout ce qu'une reprise demande.
 *
 * @param mixed $etat
 * @return array|null
 */
function dashagent_sauvegarde_etat_valide($etat) {
	if (!is_array($etat)) {
		return null;
	}
	foreach (['identifiant', 'empreinte_options', 'tables', 'index', 'offset', 'octets_bruts'] as $clef) {
		if (!array_key_exists($clef, $etat)) {
			return null;
		}
	}
	if (!dashagent_sauvegarde_identifiant_valide($etat['identifiant']) || !is_array($etat['tables'])) {
		return null;
	}
	$etat['tables']       = array_values(array_map('strval', $etat['tables']));
	$etat['index']        = max(0, (int) $etat['index']);
	$etat['offset']       = max(0, (int) $etat['offset']);
	$etat['octets_bruts'] = max(0, (int) $etat['octets_bruts']);
	$etat['tranches']     = max(0, (int) ($etat['tranches'] ?? 0));
	$etat['exclues']      = array_values(array_map('strval', (array) ($etat['exclues'] ?? [])));
	$etat['debut']        = (float) ($etat['debut'] ?? microtime(true));

	return $etat;
}

/**
 * Enregistre l'état d'un export découpé.
 *
 * Écriture sous un nom provisoire puis renommage : un état relu à moitié écrit
 * serait pire qu'un état absent, puisqu'il ferait reprendre l'export au mauvais
 * endroit.
 *
 * @param array $etat
 * @return bool
 */
function dashagent_sauvegarde_etat_ecrire($etat) {
	$chemin = dashagent_sauvegarde_etat_chemin($etat['identifiant'] ?? '');
	if ($chemin === '') {
		return false;
	}
	$provisoire = $chemin . '.tmp';
	$json = json_encode($etat);
	if ($json === false || @file_put_contents($provisoire, $json) === false) {
		@unlink($provisoire);

		return false;
	}
	if (!@rename($provisoire, $chemin)) {
		@unlink($provisoire);

		return false;
	}
	@chmod($chemin, 0600);

	return true;
}

/**
 * Efface l'état d'un export découpé.
 *
 * @param string $identifiant
 * @return void
 */
function dashagent_sauvegarde_etat_effacer($identifiant) {
	$chemin = dashagent_sauvegarde_etat_chemin($identifiant);
	if ($chemin !== '') {
		@unlink($chemin);
		@unlink($chemin . '.tmp');
	}
}

/**
 * L'export en cours qu'une demande peut reprendre à son compte, s'il y en a un.
 *
 * @param array $exclure Tables écartées par la demande
 * @param int $age_max Âge au-delà duquel un export en plan n'est plus repris
 * @return array|null
 */
function dashagent_sauvegarde_reprise_adoptable($exclure, $age_max = 3600) {
	$dir = dashagent_dir_sauvegardes();
	if (!$dir) {
		return null;
	}
	$empreinte = dashagent_sauvegarde_empreinte_options($exclure);
	$limite    = time() - max(1, (int) $age_max);
	$retenu    = null;
	$date      = 0;

	foreach ((array) glob($dir . 'sauvegarde-*.etat.json') as $chemin) {
		$quand = (int) filemtime($chemin);
		if ($quand < $limite || $quand <= $date) {
			continue;
		}
		$etat = dashagent_sauvegarde_etat_valide(json_decode((string) @file_get_contents($chemin), true));
		if ($etat === null || $etat['empreinte_options'] !== $empreinte) {
			continue;
		}
		$retenu = $etat;
		$date   = $quand;
	}

	return $retenu;
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

	gzwrite($gz, dashagent_sauvegarde_entete($tables));

	foreach ($tables as $table) {
		$erreur = dashagent_sauvegarde_structure_ecrire($gz, $table);
		if ($erreur !== '') {
			gzclose($gz);

			return $erreur;
		}
		$avancee = dashagent_sauvegarde_table($gz, $table);
		if ($avancee['erreur'] !== '') {
			gzclose($gz);

			return $avancee['erreur'];
		}
	}

	gzwrite($gz, dashagent_sauvegarde_pied());
	gzclose($gz);

	return '';
}

/**
 * L'en-tête du dump : de quoi savoir d'où il vient, et comment le relire.
 *
 * @param array $tables
 * @return string
 */
function dashagent_sauvegarde_entete($tables) {
	$entete = "-- Sauvegarde SPIP produite par le plugin Dashboard : agent\n"
		. '-- Site   : ' . url_de_base() . "\n"
		. '-- Date   : ' . date('c') . "\n"
		. '-- SPIP   : ' . ($GLOBALS['spip_version_branche'] ?? '?') . "\n"
		. '-- Tables : ' . count($tables) . "\n\n"
		. '-- Moteur : ' . (dashagent_moteur_sql() ?: '?') . "\n\n";

	if (strncmp(dashagent_moteur_sql(), 'sqlite', 6) !== 0) {
		$entete .= 'SET NAMES ' . dashagent_charset_connexion() . ";\n"
			. "SET FOREIGN_KEY_CHECKS=0;\n\n";
	}

	return $entete;
}

/**
 * Le pied du dump, et la marque qui atteste qu'on est allé au bout.
 *
 * La dernière ligne du dump atteste que l'export est allé à son terme. Le pied
 * de page du gzip prouve déjà qu'aucun octet n'a été perdu en route, mais il ne
 * dit rien de ce que le script avait encore à écrire quand il a été interrompu :
 * un export tué proprement entre deux tables produit une archive gzip
 * parfaitement valide, et incomplète.
 *
 * @return string
 */
function dashagent_sauvegarde_pied() {
	$pied = '';
	if (strncmp(dashagent_moteur_sql(), 'sqlite', 6) !== 0) {
		$pied .= "SET FOREIGN_KEY_CHECKS=1;\n";
	}

	return $pied . _DASHAGENT_SAUVEGARDE_FIN . ' ' . date('c') . "\n";
}

/**
 * Écrit le titre, le DROP et le CREATE d'une table.
 *
 * Séparé du corps parce qu'une reprise en plein milieu d'une table ne doit
 * surtout pas le réécrire : le CREATE effacerait les lignes déjà exportées.
 *
 * @param resource $gz
 * @param string $table
 * @return string Message d'erreur, vide si succès
 */
function dashagent_sauvegarde_structure_ecrire($gz, $table) {
	gzwrite($gz, "\n-- ---------------------------------------------------------\n-- Table " . $table . "\n\n");

	$creation = dashagent_sauvegarde_structure($table);
	if ($creation === null) {
		return 'Structure illisible pour la table ' . $table
			. ' (moteur ' . (dashagent_moteur_sql() ?: 'inconnu') . ' non pris en charge par la sauvegarde)';
	}
	gzwrite($gz, 'DROP TABLE IF EXISTS ' . dashagent_identifiant_sql($table) . ";\n" . $creation . ";\n\n");

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
 * Écrit les données d'une table, à partir d'un rang donné.
 *
 * Deux façons de s'arrêter, et elles ne se confondent pas : `fini` à vrai dit
 * que la table est exportée en entier, `fini` à faux qu'on a rendu la main sur
 * l'échéance, à `offset` lignes. L'appelant range cet `offset` dans son état de
 * reprise et rappellera ici.
 *
 * Une table sans clef primaire ne se pagine pas — l'ordre n'étant pas total,
 * une ligne pourrait être sautée ou écrite deux fois. Elle se lit donc d'un
 * seul tenant, échéance ou pas : c'est la limite du découpage, et elle ne se
 * voit que sur une table énorme et sans clef, ce que SPIP ne produit pas.
 *
 * @param resource $gz
 * @param string $table
 * @param int $offset Rang de la première ligne à exporter
 * @param float $echeance Instant au-delà duquel rendre la main ; 0 pour aller au bout
 * @return array{erreur: string, offset: int, fini: bool}
 */
function dashagent_sauvegarde_table($gz, $table, $offset = 0, $echeance = 0.0) {
	$clef   = dashagent_sauvegarde_clef_primaire($table);
	$pas    = _DASHAGENT_DUMP_LOT;
	$offset = (int) $offset;
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
			return ['erreur' => 'Lecture impossible sur la table ' . $table, 'offset' => $offset, 'fini' => false];
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

		// Le rang avance du nombre de lignes réellement lues : c'est lui qui
		// sera relu depuis l'état de reprise, et un pas supposé y ferait un trou.
		$offset += $n;

		if (!$clef || $n < $pas) {
			return ['erreur' => '', 'offset' => $offset, 'fini' => true];
		}
	} while (!$echeance || microtime(true) < $echeance);

	return ['erreur' => '', 'offset' => $offset, 'fini' => false];
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
 * Moteur SQL du site : « mysql », « sqlite3 »… selon la connexion en cours.
 *
 * @return string
 */
function dashagent_moteur_sql() {
	$type = $GLOBALS['connexions'][0]['type'] ?? '';
	if (!$type) {
		$type = $GLOBALS['connexions']['']['type'] ?? '';
	}

	return strtolower((string) $type);
}

/**
 * Récupère l'ordre CREATE TABLE, quel que soit le moteur.
 *
 * MySQL répond à SHOW CREATE TABLE, SQLite stocke l'ordre d'origine dans
 * sqlite_master. Sans ce double chemin, la sauvegarde échouait purement et
 * simplement sur un site SQLite.
 *
 * @param string $table
 * @return string|null
 */
function dashagent_sauvegarde_structure($table) {
	$moteur = dashagent_moteur_sql();

	if (strncmp($moteur, 'sqlite', 6) === 0) {
		$res = sql_query('SELECT sql FROM sqlite_master WHERE type = ' . sql_quote('table')
			. ' AND name = ' . sql_quote($table), '', 'continue');
		if ($res) {
			$ligne = sql_fetch($res, '');
			sql_free($res, '');
			if (is_array($ligne) && !empty($ligne['sql'])) {
				return (string) $ligne['sql'];
			}
		}

		return null;
	}

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
 * Échappe un nom de table ou de colonne selon le moteur.
 *
 * @param string $nom
 * @return string
 */
function dashagent_identifiant_sql($nom) {
	return (strncmp(dashagent_moteur_sql(), 'sqlite', 6) === 0)
		? '"' . str_replace('"', '""', $nom) . '"'
		: '`' . str_replace('`', '``', $nom) . '`';
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
	$entete = '';
	if ($colonnes) {
		$entete = '(' . implode(',', array_map('dashagent_identifiant_sql', $colonnes)) . ') ';
	}

	return 'INSERT INTO ' . dashagent_identifiant_sql($table) . ' ' . $entete
		. 'VALUES ' . implode(",\n", $lignes) . ";\n";
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
 * Les exports restés inachevés, comme preuve d'un processus interrompu.
 *
 * Un `.partiel` que personne n'a renommé ne vaut rien comme sauvegarde — il
 * n'est d'ailleurs jamais listé. Mais il vaut beaucoup comme **indice** : il
 * prouve que PHP a commencé à écrire et n'est pas allé au bout, ce qui désigne
 * une limite du site — temps d'exécution, mémoire — et non le cache en frontal.
 *
 * Sans cette information, une tour de contrôle qui ne retrouve aucune sauvegarde
 * après un 503 ne peut pas distinguer « l'export n'a jamais démarré » de « il a
 * été tué en route », et renvoie la même erreur opaque dans les deux cas.
 *
 * Aucun identifiant n'est rendu : ces fichiers ne se téléchargent ni ne se
 * restaurent, ils se constatent.
 *
 * @return array
 */
function dashagent_sauvegarde_inacheves() {
	$dir = dashagent_dir_sauvegardes();
	if (!$dir) {
		return [];
	}
	$liste = [];
	foreach ((array) glob($dir . 'sauvegarde-*.sql.gz.partiel') as $chemin) {
		$identifiant = preg_replace('/^sauvegarde-(.*)\.sql\.gz\.partiel$/', '$1', basename($chemin));
		$liste[] = [
			'octets' => (int) filesize($chemin),
			'date'   => date('c', (int) filemtime($chemin)),
			/* Un export découpé en cours porte un état de reprise à côté de lui.
			   Le distinguer importe : sans cela la tour de contrôle accuse une
			   limite du site — temps d'exécution, mémoire — là où l'export se
			   porte bien et n'attend que la tranche suivante. */
			'reprise' => dashagent_sauvegarde_etat_lire($identifiant) !== null,
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
	if (!dashagent_sauvegarde_identifiant_valide($identifiant)) {
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

	/* Les exports restés en plan. Un `.partiel` que personne n'a renommé est la
	   trace d'un processus tué en cours d'écriture : il n'est bon à rien, et
	   n'est de toute façon jamais listé. Une heure de délai suffit à ne pas
	   emporter celui d'un export en cours sur un gros site. */
	foreach ((array) glob($dir . 'sauvegarde-*.sql.gz.partiel') as $chemin) {
		if (filemtime($chemin) < time() - 3600 && @unlink($chemin)) {
			$n++;
			// L'état de reprise n'a plus rien à reprendre : le garder ferait
			// échouer la tranche suivante sur un fichier disparu.
			dashagent_sauvegarde_etat_effacer(
				preg_replace('/^sauvegarde-(.*)\.sql\.gz\.partiel$/', '$1', basename($chemin))
			);
		}
	}

	// Un état dont le partiel a déjà disparu ne sert plus qu'à égarer.
	foreach ((array) glob($dir . 'sauvegarde-*.etat.json') as $chemin) {
		$partiel = preg_replace('/\.etat\.json$/', '.sql.gz.partiel', $chemin);
		if (!is_file($partiel) && @unlink($chemin)) {
			$n++;
		}
	}

	return $n;
}
