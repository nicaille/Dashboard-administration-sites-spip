<?php
/**
 * Connaissance des versions de SPIP disponibles en amont.
 *
 * Deux sources, dans cet ordre : les versions saisies à la main dans la
 * configuration (qui font toujours autorité), puis l'index des archives
 * officielles. La saisie manuelle permet à un parc de rester sur une version
 * validée sans dépendre d'un service externe.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/** Durée de mise en cache de l'index des archives SPIP. */
if (!defined('_DASHBOARD_CACHE_VERSIONS')) {
	define('_DASHBOARD_CACHE_VERSIONS', 6 * 3600);
}

/**
 * Versions de SPIP disponibles, indexées par branche (« 4.2 » => « 4.2.16 »).
 *
 * @param bool $forcer Ignorer le cache
 * @return array
 */
function dashboard_versions_spip($forcer = false) {
	include_spip('inc/dashboard_spip_api');
	include_spip('inc/plugin');

	$branches = [];
	foreach (dashboard_api_versions($forcer) as $version => $fiche) {
		$branche = dashboard_api_branche((string) $version);
		if (
			$branche !== ''
			&& (
				!isset($branches[$branche])
				|| spip_version_compare((string) $version, $branches[$branche], '>')
			)
		) {
			$branches[$branche] = (string) $version;
		}
	}

	// L'index d'archives ne sert plus qu'à combler une branche que l'annuaire
	// ignore — un miroir privé sans annuaire, typiquement. Il ne décide plus de
	// rien : c'est son raclage de listing qui, muet, faisait passer tout le parc
	// pour à jour.
	if (!$branches) {
		$branches = dashboard_archives_index($forcer)['versions'];
	}

	return $branches;
}

/**
 * L'index des archives officielles : versions par branche, et **nom de fichier
 * exact** de chacune.
 *
 * Le nom est relevé, jamais reconstruit : `files.spip.net` distribue
 * `spip-v4.4.23.zip`, en minuscules, et une adresse fabriquée à partir d'une
 * convention supposée donne un 404 — après la sauvegarde, au pire moment.
 *
 * @param bool $forcer Ignorer le cache
 * @return array{versions: array, fichiers: array}
 */
function dashboard_archives_index($forcer = false) {
	include_spip('inc/dashboard_client');

	$cache = $forcer ? ['versions' => [], 'fichiers' => []] : dashboard_versions_cache_lire();
	if (!$cache['versions'] && !$cache['fichiers']) {
		$cache = dashboard_versions_distantes();
		if ($cache['versions'] || $cache['fichiers']) {
			dashboard_versions_cache_ecrire($cache);
		}
	}

	return $cache;
}

/**
 * Versions imposées par la configuration.
 *
 * @return array
 */
function dashboard_versions_manuelles() {
	include_spip('inc/dashboard_client');

	$versions = [];
	foreach (preg_split('/[\r\n]+/', (string) dashboard_config('versions_manuelles', '')) as $ligne) {
		$ligne = trim($ligne);
		if ($ligne === '' || strpos($ligne, '=') === false) {
			continue;
		}
		[$branche, $version] = array_map('trim', explode('=', $ligne, 2));
		if (preg_match('/^\d+\.\d+$/', $branche) && preg_match('/^\d+\.\d+\.\d+/', $version)) {
			$versions[$branche] = $version;
		}
	}

	return $versions;
}

/**
 * Interroge l'index des archives officielles.
 *
 * @return array{versions: array, fichiers: array}
 */
function dashboard_versions_distantes() {
	include_spip('inc/dashboard_client');
	include_spip('inc/distant');

	// Le même accord que pour le reste : https, ou http si l'exploitant l'a
	// explicitement autorisé. Refuser le http ici alors que l'archive, elle,
	// serait téléchargée obligeait à deviner le nom du fichier sur les dépôts
	// locaux — précisément ce qu'on cherche à ne plus faire.
	$url = (string) dashboard_config('url_archives_spip', 'https://files.spip.net/spip/archives/');
	if (!dashboard_url_acceptable($url)) {
		return ['versions' => [], 'fichiers' => []];
	}

	$reponse = recuperer_url($url, ['taille_max' => 2 * 1024 * 1024]);
	if (!is_array($reponse) || empty($reponse['page'])) {
		return ['versions' => [], 'fichiers' => []];
	}

	return dashboard_archives_analyser((string) $reponse['page']);
}

/**
 * Relève dans un index d'archives les versions et le nom de leur fichier.
 *
 * La casse du nom trouvé est conservée telle quelle : c'est tout l'intérêt de
 * lire l'index plutôt que de deviner.
 *
 * @param string $page Contenu de la page d'index
 * @return array{versions: array, fichiers: array}
 */
function dashboard_archives_analyser($page) {
	include_spip('inc/plugin');

	$index = ['versions' => [], 'fichiers' => []];
	if (!preg_match_all('/\b(spip-v?(\d+\.\d+\.\d+)\.zip)\b/i', $page, $trouves, PREG_SET_ORDER)) {
		return $index;
	}

	foreach ($trouves as $trouve) {
		[, $fichier, $version] = $trouve;
		$index['fichiers'][$version] = $fichier;

		$branche = implode('.', array_slice(explode('.', $version), 0, 2));
		if (
			!isset($index['versions'][$branche])
			|| spip_version_compare($version, $index['versions'][$branche], '>')
		) {
			$index['versions'][$branche] = $version;
		}
	}

	return $index;
}

/**
 * @return array{versions: array, fichiers: array}
 */
function dashboard_versions_cache_lire() {
	include_spip('inc/config');
	$vide = ['versions' => [], 'fichiers' => []];

	$cache = lire_config('dashboard/cache_versions', []);
	if (!is_array($cache) || empty($cache['date']) || empty($cache['versions'])) {
		return $vide;
	}
	if (time() - (int) $cache['date'] > _DASHBOARD_CACHE_VERSIONS) {
		return $vide;
	}

	// Un cache écrit par une version antérieure ne connaît pas les noms de
	// fichiers : on le relit sans eux, et le prochain rafraîchissement les
	// apportera.
	return [
		'versions' => (array) $cache['versions'],
		'fichiers' => is_array($cache['fichiers'] ?? null) ? $cache['fichiers'] : [],
	];
}

/**
 * @param array $index
 * @return void
 */
function dashboard_versions_cache_ecrire($index) {
	include_spip('inc/config');
	ecrire_config('dashboard/cache_versions', [
		'date'     => time(),
		'versions' => (array) ($index['versions'] ?? []),
		'fichiers' => (array) ($index['fichiers'] ?? []),
	]);
}

/**
 * Version cible pour un site donné.
 *
 * Par défaut on reste dans la branche installée : proposer un saut de branche
 * automatiquement (4.2 vers 4.4) casserait des plugins sans prévenir.
 *
 * @param string $version_actuelle
 * @return string Chaîne vide si aucune mise à jour pertinente
 */
function dashboard_version_cible($version_actuelle, $php_site = '') {
	$verdict = dashboard_core_etat($version_actuelle, $php_site);

	// Seul « retard » arme un bouton. Un site que son PHP empêche de suivre est
	// signalé ailleurs, et proposer la mise à jour quand même reviendrait à la
	// promettre en vain : l'archive se télécharge, s'installe, et le site tombe.
	return (string) $verdict['etat'] === 'retard' ? (string) $verdict['cible'] : '';
}

/**
 * L'état du core d'un site : à jour, en retard, bloqué par son PHP, ou inconnu.
 *
 * @param string $version_actuelle
 * @param string $php_site Version de PHP du site géré, si on la connaît
 * @param bool $forcer Relire l'annuaire
 * @return array{etat: string, cible: string, majeure: string, php_requis: string}
 */
function dashboard_core_etat($version_actuelle, $php_site = '', $forcer = false) {
	include_spip('inc/dashboard_spip_api');

	return dashboard_core_verdict($version_actuelle, $php_site, dashboard_api_versions($forcer));
}

/**
 * URL de l'archive officielle d'une version de SPIP.
 *
 * Le nom du fichier est **celui que publie l'index**, relevé tel quel. Fabriquer
 * l'adresse à partir d'une convention supposée est ce qui produisait un
 * `HTTP 404 sur .../SPIP-v4.4.23.zip` alors que le fichier existe bel et bien,
 * en minuscules. L'index n'étant pas toujours joignable, une convention de
 * repli subsiste — celle qu'emploie réellement `files.spip.net`.
 *
 * @param string $version
 * @return string
 */
function dashboard_url_archive_spip($version) {
	include_spip('inc/dashboard_client');

	if (!preg_match('/^\d+\.\d+\.\d+[a-z0-9.\-]*$/i', (string) $version)) {
		return '';
	}
	// L'annuaire officiel donne l'adresse entière, publiée par ceux qui
	// fabriquent l'archive. Rien à deviner, rien à sonder : c'est la seule
	// source qui ne puisse pas se tromper sur la casse du nom de fichier.
	$depuis_api = dashboard_url_archive_api($version);
	if ($depuis_api !== '') {
		return $depuis_api;
	}

	$base = rtrim((string) dashboard_config('url_archives_spip', 'https://files.spip.net/spip/archives/'), '/');

	$index = dashboard_archives_index();
	if (empty($index['fichiers'][$version])) {
		// Le cache date d'avant la parution de cette version, ou d'une version
		// du plugin qui ne relevait pas les noms : mieux vaut relire l'index
		// maintenant que partir sur un nom supposé et échouer après la
		// sauvegarde. Une seule relecture par mise à jour de core : le coût est
		// négligeable, et il n'est payé que lorsque le nom manque.
		$index = dashboard_archives_index(true);
	}
	if (!empty($index['fichiers'][$version])) {
		return $base . '/' . $index['fichiers'][$version];
	}

	// Un dépôt sans index — un miroir privé, un répertoire sans listing — ne
	// dira jamais le nom : on demande alors au serveur lequel existe, plutôt
	// que de lancer une mise à jour sur une adresse qu'on n'a pas vérifiée.
	$candidats = dashboard_archives_candidats($version);
	foreach ($candidats as $nom) {
		if (dashboard_archive_existe($base . '/' . $nom)) {
			return $base . '/' . $nom;
		}
	}

	// Rien n'a répondu : rendre tout de même une adresse, pour que l'erreur en
	// nomme une plutôt que de rester muette.
	return $base . '/' . reset($candidats);
}

/**
 * Noms d'archive d'usage pour une version, du plus probable au moins.
 *
 * `files.spip.net` distribue `spip-v4.4.23.zip` ; certains miroirs emploient la
 * capitale.
 *
 * @param string $version
 * @return array
 */
function dashboard_archives_candidats($version) {
	return ['spip-v' . $version . '.zip', 'SPIP-v' . $version . '.zip'];
}

/**
 * Le serveur sert-il bien cette archive ?
 *
 * Une requête HEAD : on ne veut que le code de retour, pas les dix mégaoctets.
 *
 * @param string $url
 * @return bool
 */
function dashboard_archive_existe($url) {
	include_spip('inc/dashboard_client');
	include_spip('inc/distant');

	if (!dashboard_url_acceptable($url)) {
		return false;
	}

	$reponse = recuperer_url($url, ['methode' => 'HEAD', 'taille_max' => 0, 'follow_location' => 3]);

	return is_array($reponse) && (int) ($reponse['status'] ?? 0) === 200;
}

/**
 * L'adresse que l'annuaire officiel publie pour une version.
 *
 * @param string $version
 * @return string Chaîne vide si l'annuaire ne connaît pas cette version
 */
function dashboard_url_archive_api($version) {
	include_spip('inc/dashboard_spip_api');

	$versions = dashboard_api_versions();

	return (string) ($versions[(string) $version]['url'] ?? '');
}

/**
 * L'empreinte SHA-256 qu'il faut attendre de l'archive d'une version.
 *
 * Elle existait dans le protocole depuis le premier jour : `dashagent_core_maj()`
 * refuse déjà une archive dont le SHA-256 ne répond pas à celui qu'on lui
 * annonce. Personne ne lui en annonçait jamais, faute de source — le contrôle
 * était écrit, et n'avait jamais servi une fois.
 *
 * Rendre une chaîne vide n'interdit pas la mise à jour : un miroir privé sans
 * annuaire resterait sinon inutilisable. Le https atteste du transport, jamais
 * du contenu — mais c'est mieux que rien, et c'est l'état d'avant.
 *
 * @param string $version
 * @return string
 */
function dashboard_sha256_spip($version) {
	include_spip('inc/dashboard_spip_api');

	$versions = dashboard_api_versions();

	return (string) ($versions[(string) $version]['sha256'] ?? '');
}
