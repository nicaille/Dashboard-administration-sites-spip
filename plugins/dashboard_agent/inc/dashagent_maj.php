<?php
/**
 * Mises à jour pilotées à distance : plugins et core SPIP.
 *
 * Principe directeur : on ne détruit jamais l'existant avant d'avoir la
 * nouvelle version validée sur le disque, et le remplacement se fait par
 * `rename()` — atomique, et sans arracher sous les pieds de PHP des fichiers
 * qu'il est en train d'inclure.
 *
 * @package SPIP\Dashagent\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Répertoires jamais remplacés par une mise à jour du core : ils contiennent
 * les données et la configuration propres au site.
 *
 * @return array
 */
function dashagent_core_intouchables() {
	return ['config', 'IMG', 'local', 'tmp', 'plugins', 'squelettes', 'lib', 'extensions', 'sites'];
}

/**
 * Répertoires et fichiers constituant le core, remplaçables lors d'une mise à jour.
 *
 * @return array
 */
function dashagent_core_remplacables() {
	return [
		'ecrire', 'prive', 'squelettes-dist', 'plugins-dist',
		'spip.php', 'index.php', 'htaccess.txt', 'favicon.ico', 'composer.json', 'composer.lock',
	];
}

/* -------------------------------------------------------------------------- */
/* Plugins                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * Retourne la description d'un plugin actif, ou null.
 *
 * @param string $prefixe
 * @return array|null
 */
function dashagent_plugin_actif($prefixe) {
	include_spip('inc/dashagent_infos');

	$prefixe = strtoupper((string) $prefixe);
	foreach (dashagent_infos_plugins() as $plugin) {
		if ($plugin['prefixe'] === $prefixe) {
			return $plugin;
		}
	}

	return null;
}

/**
 * Analyse ce qu'il est possible de faire pour mettre à jour un plugin.
 *
 * @param array $args {prefixe}
 * @return array
 */
function dashagent_plugin_preflight($args) {
	include_spip('inc/dashagent_fs');

	$plugin = dashagent_plugin_actif($args['prefixe'] ?? '');
	if (!$plugin) {
		return ['ok' => false, 'erreur' => 'Plugin inconnu ou inactif sur ce site'];
	}
	if ($plugin['distribue']) {
		return ['ok' => false, 'erreur' => 'Plugin distribué avec le core : il se met à jour avec SPIP', 'plugin' => $plugin];
	}

	$strategies = [];
	if (dashagent_exec_disponible() && $plugin['source'] === 'git') {
		$strategies[] = 'git';
	}
	if (class_exists('ZipArchive')) {
		$strategies[] = 'zip';
	}

	return [
		'ok'          => (bool) $strategies,
		'erreur'      => $strategies ? '' : 'Aucune stratégie de mise à jour disponible (ni git utilisable, ni extension zip)',
		'plugin'      => $plugin,
		'strategies'  => $strategies,
		'inscriptible' => is_writable($plugin['chemin']) && is_writable(dirname(rtrim($plugin['chemin'], '/'))),
		'archive_svp' => dashagent_plugin_url_svp($plugin['prefixe']),
	];
}

/**
 * Met à jour un plugin.
 *
 * @param array $args
 *     - string `prefixe` (obligatoire)
 *     - string `url_archive` : ZIP https à déployer (prioritaire)
 *     - string `sha256` : empreinte attendue de l'archive
 *     - string `strategie` : git|zip, sinon déduite
 * @return array
 */
function dashagent_plugin_maj($args) {
	$preflight = dashagent_plugin_preflight($args);
	if (empty($preflight['ok'])) {
		return ['ok' => false, 'erreur' => $preflight['erreur'], 'preflight' => $preflight];
	}

	$plugin    = $preflight['plugin'];
	$strategie = (string) ($args['strategie'] ?? '');
	$url       = (string) ($args['url_archive'] ?? '');

	if ($strategie === '') {
		if ($url !== '') {
			$strategie = 'zip';
		} elseif (in_array('git', $preflight['strategies'], true)) {
			$strategie = 'git';
		} else {
			$url = (string) $preflight['archive_svp'];
			$strategie = 'zip';
		}
	}

	if ($strategie === 'git') {
		$resultat = dashagent_plugin_maj_git($plugin);
	} elseif ($strategie === 'zip') {
		if ($url === '') {
			return ['ok' => false, 'erreur' => 'Aucune URL d’archive fournie et aucun dépôt SVP ne connaît ce plugin'];
		}
		$resultat = dashagent_plugin_maj_zip($plugin, $url, (string) ($args['sha256'] ?? ''));
	} else {
		return ['ok' => false, 'erreur' => 'Stratégie inconnue : ' . $strategie];
	}

	if (!empty($resultat['ok'])) {
		$resultat['post'] = dashagent_apres_maj();
		$apres = dashagent_plugin_actif($plugin['prefixe']);
		$resultat['version_avant'] = $plugin['version'];
		$resultat['version_apres'] = $apres['version'] ?? null;
	}
	$resultat['strategie'] = $strategie;

	return $resultat;
}

/**
 * Mise à jour par `git pull --ff-only`.
 *
 * @param array $plugin
 * @return array
 */
function dashagent_plugin_maj_git($plugin) {
	$dir = escapeshellarg(rtrim($plugin['chemin'], '/'));
	$sortie = [];
	$code = 0;

	@exec('git -C ' . $dir . ' fetch --quiet 2>&1', $sortie, $code);
	if ($code !== 0) {
		return ['ok' => false, 'erreur' => 'git fetch a échoué', 'sortie' => $sortie];
	}

	$sortie = [];
	@exec('git -C ' . $dir . ' pull --ff-only 2>&1', $sortie, $code);
	if ($code !== 0) {
		return ['ok' => false, 'erreur' => 'git pull --ff-only a échoué (dépôt modifié localement ?)', 'sortie' => $sortie];
	}

	return ['ok' => true, 'erreur' => '', 'sortie' => $sortie];
}

/**
 * Mise à jour par déploiement d'une archive ZIP.
 *
 * @param array $plugin
 * @param string $url
 * @param string $sha256
 * @return array
 */
function dashagent_plugin_maj_zip($plugin, $url, $sha256 = '') {
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_fs');

	$travail = dashagent_dir_travail();
	if (!$travail) {
		return ['ok' => false, 'erreur' => 'Répertoire de travail indisponible'];
	}

	$jeton   = bin2hex(random_bytes(6));
	$archive = $travail . 'plugin-' . $jeton . '.zip';
	$extrait = $travail . 'plugin-' . $jeton . '/';

	$dl = dashagent_telecharger($url, $archive);
	if (!$dl['ok']) {
		return ['ok' => false, 'erreur' => $dl['erreur']];
	}

	if ($sha256 !== '' && !hash_equals(strtolower($sha256), hash_file('sha256', $archive))) {
		@unlink($archive);

		return ['ok' => false, 'erreur' => 'Empreinte SHA-256 de l’archive non conforme'];
	}

	$unzip = dashagent_dezipper($archive, $extrait);
	@unlink($archive);
	if (!$unzip['ok']) {
		dashagent_supprimer_repertoire($extrait, true);

		return ['ok' => false, 'erreur' => $unzip['erreur']];
	}

	$controle = dashagent_verifier_archive_plugin($unzip['racine'], $plugin['prefixe']);
	if (!$controle['ok']) {
		dashagent_supprimer_repertoire($extrait, true);

		return ['ok' => false, 'erreur' => $controle['erreur']];
	}

	$echange = dashagent_remplacer_repertoire(rtrim($plugin['chemin'], '/'), $unzip['racine']);
	dashagent_supprimer_repertoire($extrait, true);

	if (!$echange['ok']) {
		return ['ok' => false, 'erreur' => $echange['erreur']];
	}

	return [
		'ok'              => true,
		'erreur'          => '',
		'version_archive' => $controle['version'],
		'sauvegarde'      => $echange['sauvegarde'],
	];
}

/**
 * Vérifie qu'une archive extraite est bien le plugin attendu.
 *
 * Sans ce contrôle, une URL d'archive erronée écraserait un plugin par un autre.
 *
 * @param string $racine
 * @param string $prefixe
 * @return array{ok: bool, erreur: string, version: string}
 */
function dashagent_verifier_archive_plugin($racine, $prefixe) {
	$paquet = rtrim($racine, '/') . '/paquet.xml';
	if (!is_file($paquet)) {
		return ['ok' => false, 'erreur' => 'Archive invalide : paquet.xml absent', 'version' => ''];
	}
	$xml = file_get_contents($paquet);
	if (!preg_match('/prefix\s*=\s*"([^"]+)"/i', $xml, $m)) {
		return ['ok' => false, 'erreur' => 'Archive invalide : préfixe illisible', 'version' => ''];
	}
	if (strtoupper($m[1]) !== strtoupper($prefixe)) {
		return ['ok' => false, 'erreur' => 'Archive incohérente : préfixe ' . $m[1] . ' au lieu de ' . $prefixe, 'version' => ''];
	}
	$version = preg_match('/\sversion\s*=\s*"([^"]+)"/i', $xml, $v) ? $v[1] : '';

	return ['ok' => true, 'erreur' => '', 'version' => $version];
}

/**
 * Remplace un répertoire par un autre, en conservant l'ancien pour rollback.
 *
 * @param string $cible Répertoire à remplacer
 * @param string $source Répertoire déjà validé
 * @return array{ok: bool, erreur: string, sauvegarde: string}
 */
function dashagent_remplacer_repertoire($cible, $source) {
	include_spip('inc/dashagent_fs');

	$cible  = rtrim($cible, '/');
	$source = rtrim($source, '/');
	$sauvegarde = $cible . '.dashagent-' . date('YmdHis');

	if (is_dir($cible) && !@rename($cible, $sauvegarde)) {
		return ['ok' => false, 'erreur' => 'Impossible de mettre l’ancienne version de côté (droits ?)', 'sauvegarde' => ''];
	}

	// rename() entre le répertoire temporaire et l'arborescence du site peut
	// franchir une frontière de système de fichiers : on retombe sur une copie.
	if (!@rename($source, $cible) && !dashagent_copier_repertoire($source, $cible)) {
		if (is_dir($sauvegarde)) {
			@rename($sauvegarde, $cible);
		}

		return ['ok' => false, 'erreur' => 'Déploiement impossible, ancienne version restaurée', 'sauvegarde' => ''];
	}

	return ['ok' => true, 'erreur' => '', 'sauvegarde' => basename($sauvegarde)];
}

/**
 * URL d'archive d'un plugin telle que connue par les dépôts SVP locaux.
 *
 * @param string $prefixe
 * @return string
 */
function dashagent_plugin_url_svp($prefixe) {
	include_spip('inc/dashagent_infos');
	include_spip('inc/plugin');

	if (!dashagent_table_existe('spip_paquets') || !dashagent_table_existe('spip_depots')) {
		return '';
	}

	$res = sql_select(
		['pa.version AS version', 'pa.src_archive AS src_archive', 'pa.nom_archive AS nom_archive', 'de.url_archives AS url_archives'],
		['spip_paquets AS pa', 'spip_plugins AS pl', 'spip_depots AS de'],
		['pa.id_plugin = pl.id_plugin', 'pa.id_depot = de.id_depot', 'pa.id_depot > 0', 'pl.prefixe = ' . sql_quote($prefixe)],
		'',
		'',
		'',
		'',
		'',
		'continue'
	);
	if (!$res) {
		return '';
	}

	$meilleure = ['version' => '', 'url' => ''];
	while ($ligne = sql_fetch($res, '')) {
		$url = rtrim((string) $ligne['url_archives'], '/') . '/' . ltrim((string) $ligne['src_archive'], '/');
		if ((string) $ligne['src_archive'] === '') {
			continue;
		}
		if ($meilleure['version'] === '' || spip_version_compare((string) $ligne['version'], $meilleure['version'], '>')) {
			$meilleure = ['version' => (string) $ligne['version'], 'url' => $url];
		}
	}

	return $meilleure['url'];
}

/* -------------------------------------------------------------------------- */
/* Core                                                                       */
/* -------------------------------------------------------------------------- */

/**
 * Contrôles préalables à une mise à jour du core.
 *
 * @return array
 */
function dashagent_core_preflight() {
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_fs');

	$racine   = _DIR_RACINE ?: './';
	$controles = [];

	$controles['zip']              = class_exists('ZipArchive');
	$controles['racine_inscriptible'] = is_writable($racine);
	$controles['travail']          = (bool) dashagent_dir_travail();

	foreach (dashagent_core_remplacables() as $entree) {
		$chemin = $racine . $entree;
		if (file_exists($chemin)) {
			$controles['inscriptible_' . $entree] = is_writable($chemin);
		}
	}

	$libre = dashagent_espace_disque_libre();
	$controles['disque_suffisant'] = ($libre === null) ? true : ($libre > 200 * 1024 * 1024);

	$bloquants = array_keys(array_filter($controles, function ($v) {
		return $v === false;
	}));

	return [
		'ok'         => !$bloquants,
		'erreur'     => $bloquants ? 'Contrôles préalables en échec : ' . implode(', ', $bloquants) : '',
		'controles'  => $controles,
		'disque_libre' => $libre,
		'version_actuelle' => (string) ($GLOBALS['spip_version_branche'] ?? ''),
	];
}

/**
 * Met à jour le core SPIP depuis une archive ZIP officielle.
 *
 * @param array $args
 *     - string `url_archive` (obligatoire, https)
 *     - string `sha256` : empreinte attendue
 *     - string `version_attendue` : contrôle de cohérence
 * @return array
 */
function dashagent_core_maj($args) {
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_fs');

	$preflight = dashagent_core_preflight();
	if (!$preflight['ok']) {
		return ['ok' => false, 'erreur' => $preflight['erreur'], 'preflight' => $preflight];
	}

	$url = (string) ($args['url_archive'] ?? '');
	if ($url === '') {
		return ['ok' => false, 'erreur' => 'URL d’archive du core obligatoire'];
	}

	$travail = dashagent_dir_travail();
	$jeton   = bin2hex(random_bytes(6));
	$archive = $travail . 'core-' . $jeton . '.zip';
	$extrait = $travail . 'core-' . $jeton . '/';

	$dl = dashagent_telecharger($url, $archive);
	if (!$dl['ok']) {
		return ['ok' => false, 'erreur' => $dl['erreur']];
	}
	if (!empty($args['sha256']) && !hash_equals(strtolower((string) $args['sha256']), hash_file('sha256', $archive))) {
		@unlink($archive);

		return ['ok' => false, 'erreur' => 'Empreinte SHA-256 de l’archive non conforme'];
	}

	$unzip = dashagent_dezipper($archive, $extrait);
	@unlink($archive);
	if (!$unzip['ok']) {
		dashagent_supprimer_repertoire($extrait, true);

		return ['ok' => false, 'erreur' => $unzip['erreur']];
	}

	$controle = dashagent_verifier_archive_core($unzip['racine'], (string) ($args['version_attendue'] ?? ''));
	if (!$controle['ok']) {
		dashagent_supprimer_repertoire($extrait, true);

		return ['ok' => false, 'erreur' => $controle['erreur']];
	}

	$echange = dashagent_core_deployer($unzip['racine']);
	dashagent_supprimer_repertoire($extrait, true);

	if (!$echange['ok']) {
		return ['ok' => false, 'erreur' => $echange['erreur'], 'detail' => $echange];
	}

	return [
		'ok'      => true,
		'erreur'  => '',
		'version_avant' => $preflight['version_actuelle'],
		'version_apres' => $controle['version'],
		'remplaces'     => $echange['remplaces'],
		'rollback'      => $echange['rollback'],
		'post'          => dashagent_apres_maj(),
	];
}

/**
 * Vérifie qu'une archive extraite est bien une distribution SPIP.
 *
 * @param string $racine
 * @param string $version_attendue
 * @return array{ok: bool, erreur: string, version: string}
 */
function dashagent_verifier_archive_core($racine, $version_attendue = '') {
	$racine = rtrim($racine, '/');
	foreach (['spip.php', 'ecrire/inc_version.php'] as $temoin) {
		if (!is_file($racine . '/' . $temoin)) {
			return ['ok' => false, 'erreur' => 'Archive invalide : ' . $temoin . ' absent', 'version' => ''];
		}
	}

	$source  = file_get_contents($racine . '/ecrire/inc_version.php');
	$version = preg_match('/spip_version_branche\s*=\s*[\'"]([^\'"]+)/', $source, $m) ? $m[1] : '';
	if ($version === '') {
		return ['ok' => false, 'erreur' => 'Archive invalide : version du core illisible', 'version' => ''];
	}
	if ($version_attendue !== '' && $version !== $version_attendue) {
		return ['ok' => false, 'erreur' => 'Archive en version ' . $version . ' au lieu de ' . $version_attendue, 'version' => $version];
	}

	return ['ok' => true, 'erreur' => '', 'version' => $version];
}

/**
 * Déploie une distribution SPIP validée sur le site.
 *
 * Chaque entrée est mise de côté par renommage avant d'être remplacée ; en cas
 * d'échec en cours de route, toutes les entrées déjà traitées sont restaurées.
 *
 * @param string $source Racine de la distribution extraite
 * @return array
 */
function dashagent_core_deployer($source) {
	include_spip('inc/dashagent_fs');

	$racine       = _DIR_RACINE ?: './';
	$source       = rtrim($source, '/');
	$suffixe      = '.dashagent-' . date('YmdHis');
	$intouchables = dashagent_core_intouchables();
	$remplaces    = [];
	$deplaces     = [];

	foreach (dashagent_core_remplacables() as $entree) {
		if (in_array($entree, $intouchables, true)) {
			continue;
		}
		$neuf = $source . '/' . $entree;
		if (!file_exists($neuf)) {
			continue;
		}
		$actuel = $racine . $entree;
		$vieux  = $actuel . $suffixe;

		if (file_exists($actuel)) {
			if (!@rename($actuel, $vieux)) {
				dashagent_core_restaurer($deplaces);

				return ['ok' => false, 'erreur' => 'Impossible de mettre de côté ' . $entree, 'remplaces' => $remplaces, 'rollback' => ''];
			}
			$deplaces[] = ['actuel' => $actuel, 'vieux' => $vieux];
		}

		$installe = is_dir($neuf)
			? (@rename($neuf, $actuel) || dashagent_copier_repertoire($neuf, $actuel))
			: @copy($neuf, $actuel);

		if (!$installe) {
			dashagent_core_restaurer($deplaces);

			return ['ok' => false, 'erreur' => 'Échec du déploiement de ' . $entree . ', état précédent restauré', 'remplaces' => $remplaces, 'rollback' => ''];
		}
		$remplaces[] = $entree;
	}

	return ['ok' => true, 'erreur' => '', 'remplaces' => $remplaces, 'rollback' => $suffixe];
}

/**
 * Restaure les entrées mises de côté lors d'un déploiement interrompu.
 *
 * @param array $deplaces
 * @return void
 */
function dashagent_core_restaurer($deplaces) {
	include_spip('inc/dashagent_fs');

	foreach (array_reverse($deplaces) as $entree) {
		if (file_exists($entree['actuel'])) {
			if (is_dir($entree['actuel'])) {
				dashagent_supprimer_repertoire($entree['actuel'], true);
			} else {
				@unlink($entree['actuel']);
			}
		}
		@rename($entree['vieux'], $entree['actuel']);
	}
}

/**
 * Supprime les répertoires de rollback laissés par une mise à jour réussie.
 *
 * @param string $suffixe
 * @return int
 */
function dashagent_nettoyer_rollback($suffixe) {
	include_spip('inc/dashagent_fs');

	if (!preg_match('/^\.dashagent-[0-9]{14}$/', (string) $suffixe)) {
		return 0;
	}
	$n = 0;
	foreach ((array) glob((_DIR_RACINE ?: './') . '*' . $suffixe) as $chemin) {
		if (is_dir($chemin)) {
			dashagent_supprimer_repertoire($chemin, true);
			$n++;
		} elseif (@unlink($chemin)) {
			$n++;
		}
	}

	return $n;
}

/**
 * Remise en cohérence de SPIP après un remplacement de fichiers.
 *
 * @return array
 */
function dashagent_apres_maj() {
	include_spip('inc/dashagent_cache');

	$rapport = ['cache_purge' => dashagent_purger(['pages', 'squelettes'])];

	include_spip('inc/plugin');
	if (function_exists('ecrire_plugin_actifs') && function_exists('liste_plugin_actifs')) {
		ecrire_plugin_actifs(liste_plugin_actifs(), false, 'ajoute');
		$rapport['plugins_recalcules'] = true;
	} else {
		$rapport['plugins_recalcules'] = false;
	}

	return $rapport;
}
