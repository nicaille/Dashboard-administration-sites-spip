<?php
/**
 * Opérations pilotées depuis le tableau de bord vers un site géré.
 *
 * Chaque fonction suit le même contrat : elle appelle l'agent, journalise, et
 * renvoie `['ok' => bool, 'message' => string, 'data' => array]`. Les pages et
 * actions n'ont ainsi qu'un seul format de résultat à traiter.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Adresse officielle du script d'installation de SPIP.
 *
 * Réglable depuis la configuration du tableau de bord, pour un miroir interne.
 */
if (!defined('_DASHBOARD_LOADER_URL')) {
	define('_DASHBOARD_LOADER_URL', 'https://get.spip.net/spip_loader.php');
}

/**
 * Répertoire local de stockage des sauvegardes rapatriées.
 *
 * @param int $id_dashboard_site
 * @return string Chaîne vide si indisponible
 */
function dashboard_dir_sauvegardes($id_dashboard_site = 0) {
	include_spip('inc/flock');

	sous_repertoire(_DIR_TMP, 'dashboard');
	$dir = _DIR_TMP . 'dashboard/';
	sous_repertoire($dir, 'sauvegardes');
	$dir .= 'sauvegardes/';

	// _DIR_TMP est hors espace web sur une installation saine ; ceinture et bretelles.
	if (!file_exists(_DIR_TMP . 'dashboard/.htaccess')) {
		@file_put_contents(_DIR_TMP . 'dashboard/.htaccess', "Deny from all\nRequire all denied\n");
	}

	if ($id_dashboard_site) {
		sous_repertoire($dir, (string) (int) $id_dashboard_site);
		$dir .= (int) $id_dashboard_site . '/';
	}

	return (is_dir($dir) && is_writable($dir)) ? $dir : '';
}

/**
 * Vide un ou plusieurs caches d'un site géré.
 *
 * @param int $id_dashboard_site
 * @param array $cibles
 * @return array
 */
function dashboard_operation_purger($id_dashboard_site, $cibles = ['tout']) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler($site, 'purger', ['cibles' => array_values($cibles)], ['timeout' => dashboard_config('timeout_long', 300)]);

	$message = $reponse['ok']
		? 'Caches vidés : ' . (int) ($reponse['data']['purge']['fichiers'] ?? 0) . ' fichier(s)'
		: (string) ($reponse['erreur']['message'] ?? '');

	dashboard_journaliser($id_dashboard_site, 'purger', $reponse['ok'] ? 'ok' : 'erreur', $message, $reponse['data'], $reponse['duree_ms']);

	return ['ok' => $reponse['ok'], 'message' => $message, 'data' => $reponse['data']];
}

/**
 * Demande une sauvegarde de la base et, par défaut, la rapatrie.
 *
 * Une sauvegarde qui reste sur le serveur du site ne protège de rien si c'est
 * l'hébergement qui tombe : le rapatriement est donc le comportement normal.
 *
 * @param int $id_dashboard_site
 * @param array $options
 *     - bool `rapatrier` (défaut true)
 *     - bool `sans_statistiques`
 * @return array
 */
function dashboard_operation_sauvegarder($id_dashboard_site, $options = []) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler(
		$site,
		'sauvegarde_creer',
		['sans_statistiques' => !empty($options['sans_statistiques'])],
		['timeout' => dashboard_config('timeout_long', 300)]
	);

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'sauvegarde', 'erreur', $message, $reponse['erreur'], $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'data' => []];
	}

	$sauvegarde = $reponse['data']['sauvegarde'] ?? [];
	$id_sauvegarde = (int) sql_insertq('spip_dashboard_sauvegardes', [
		'id_dashboard_site' => (int) $id_dashboard_site,
		'identifiant'       => substr((string) ($sauvegarde['identifiant'] ?? ''), 0, 64),
		'fichier'           => '',
		'octets'            => (int) ($sauvegarde['octets'] ?? 0),
		'sha256'            => substr((string) ($sauvegarde['sha256'] ?? ''), 0, 64),
		'statut'            => 'distante',
		'date'              => date('Y-m-d H:i:s'),
	]);

	$message = 'Sauvegarde créée sur le site (' . dashboard_octets((int) ($sauvegarde['octets'] ?? 0)) . ')';

	if (!isset($options['rapatrier']) || $options['rapatrier']) {
		$rapatriement = dashboard_rapatrier_sauvegarde($id_dashboard_site, $id_sauvegarde);
		$message .= ' — ' . $rapatriement['message'];
		if (!$rapatriement['ok']) {
			dashboard_journaliser($id_dashboard_site, 'sauvegarde', 'erreur', $message, $sauvegarde, $reponse['duree_ms']);

			return ['ok' => false, 'message' => $message, 'data' => $sauvegarde];
		}
	}

	dashboard_journaliser($id_dashboard_site, 'sauvegarde', 'ok', $message, $sauvegarde, $reponse['duree_ms']);

	return ['ok' => true, 'message' => $message, 'data' => $sauvegarde, 'id_dashboard_sauvegarde' => $id_sauvegarde];
}

/**
 * Rapatrie le fichier d'une sauvegarde déjà créée sur le site géré.
 *
 * @param int $id_dashboard_site
 * @param int $id_dashboard_sauvegarde
 * @return array
 */
function dashboard_rapatrier_sauvegarde($id_dashboard_site, $id_dashboard_sauvegarde) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	$ligne = sql_fetsel('*', 'spip_dashboard_sauvegardes', 'id_dashboard_sauvegarde = ' . (int) $id_dashboard_sauvegarde);
	if (!$site || !$ligne) {
		return ['ok' => false, 'message' => 'Sauvegarde inconnue'];
	}

	$dir = dashboard_dir_sauvegardes($id_dashboard_site);
	if (!$dir) {
		return ['ok' => false, 'message' => 'Répertoire local de sauvegardes non inscriptible'];
	}

	$nom = 'site' . (int) $id_dashboard_site . '-' . $ligne['identifiant'] . '.sql.gz';
	$resultat = dashboard_telecharger_sauvegarde($site, (string) $ligne['identifiant'], $dir . $nom);

	if (!$resultat['ok']) {
		return ['ok' => false, 'message' => 'rapatriement échoué : ' . (string) ($resultat['erreur']['message'] ?? '')];
	}

	// L'empreinte annoncée par l'agent protège d'un transfert tronqué.
	if (!empty($ligne['sha256']) && !hash_equals((string) $ligne['sha256'], (string) $resultat['sha256'])) {
		@unlink($dir . $nom);

		return ['ok' => false, 'message' => 'rapatriement échoué : empreinte SHA-256 non conforme'];
	}

	sql_updateq('spip_dashboard_sauvegardes', [
		'fichier' => $nom,
		'octets'  => (int) $resultat['octets'],
		'sha256'  => (string) $resultat['sha256'],
		'statut'  => 'locale',
	], 'id_dashboard_sauvegarde = ' . (int) $id_dashboard_sauvegarde);

	return ['ok' => true, 'message' => 'rapatriée localement (' . dashboard_octets((int) $resultat['octets']) . ')'];
}

/**
 * Fait relire au site géré le catalogue de ses dépôts de plugins.
 *
 * Un dépôt par appel : télécharger un catalogue XML de plusieurs méga-octets et
 * le réindexer suffit à occuper une requête.
 *
 * @param int $id_dashboard_site
 * @param int $age_max Ne rafraîchir qu'au-delà de cet âge, en secondes. Zéro force.
 * @return array
 */
function dashboard_operation_depots_actualiser($id_dashboard_site, $age_max = 0) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler(
		$site,
		'depots_actualiser',
		['age_max' => (int) $age_max],
		['timeout' => dashboard_config('timeout_long', 300)]
	);

	if (!$reponse['ok']) {
		return [
			'ok' => false,
			'message' => (string) ($reponse['erreur']['message'] ?? ''),
			'code' => (string) ($reponse['erreur']['code'] ?? ''),
			'data' => $reponse['data'],
		];
	}

	return ['ok' => true, 'message' => '', 'data' => $reponse['data']];
}

/**
 * Fait calculer à SVP, sur le site géré, ce qu'une mise à jour impliquerait.
 *
 * Rien n'est engagé : c'est l'occasion d'apprendre qu'une dépendance manque
 * **avant** de sauvegarder et de télécharger, plutôt qu'après avoir déployé un
 * plugin que SPIP refusera d'activer.
 *
 * @param int $id_dashboard_site
 * @param string $prefixe
 * @return array
 */
function dashboard_operation_plugin_svp_preflight($id_dashboard_site, $prefixe) {
	return dashboard_appeler_svp($id_dashboard_site, 'plugin_svp_preflight', $prefixe);
}

/**
 * Fige sur le site géré la file d'actions que SVP jouera.
 *
 * @param int $id_dashboard_site
 * @param string $prefixe
 * @param bool $forcer Passer outre un verrou SVP existant
 * @return array
 */
function dashboard_operation_plugin_svp_preparer($id_dashboard_site, $prefixe, $forcer = false) {
	return dashboard_appeler_svp($id_dashboard_site, 'plugin_svp_preparer', $prefixe, ['forcer' => (bool) $forcer]);
}

/**
 * Joue une action de la file SVP, et une seule.
 *
 * @param int $id_dashboard_site
 * @param string $prefixe
 * @return array
 */
function dashboard_operation_plugin_svp_avancer($id_dashboard_site, $prefixe) {
	return dashboard_appeler_svp($id_dashboard_site, 'plugin_svp_avancer', $prefixe);
}

/**
 * Libère une file SVP restée en plan sur le site géré.
 *
 * @param int $id_dashboard_site
 * @param string $prefixe
 * @return array
 */
function dashboard_operation_plugin_svp_liberer($id_dashboard_site, $prefixe = '') {
	return dashboard_appeler_svp($id_dashboard_site, 'plugin_svp_liberer', $prefixe);
}

/**
 * Le passage obligé des quatre opérations SVP : même chargement, même erreurs.
 *
 * @param int $id_dashboard_site
 * @param string $op
 * @param string $prefixe
 * @param array $args
 * @return array
 */
function dashboard_appeler_svp($id_dashboard_site, $op, $prefixe, $args = []) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$args['prefixe'] = strtoupper((string) $prefixe);
	$reponse = dashboard_appeler($site, $op, $args, ['timeout' => dashboard_config('timeout_long', 300)]);

	if (!$reponse['ok']) {
		return [
			'ok' => false,
			'message' => (string) ($reponse['erreur']['message'] ?? ''),
			'code' => (string) ($reponse['erreur']['code'] ?? ''),
			'data' => $reponse['data'],
		];
	}

	return ['ok' => true, 'message' => '', 'data' => $reponse['data']];
}

/**
 * Met à jour un plugin sur un site géré.
 *
 * @param int $id_dashboard_site
 * @param string $prefixe
 * @param array $options url_archive, sha256, strategie
 * @return array
 */
function dashboard_operation_plugin_maj($id_dashboard_site, $prefixe, $options = []) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');
	include_spip('inc/dashboard_sync');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$args = ['prefixe' => strtoupper((string) $prefixe)];
	foreach (['url_archive', 'sha256', 'strategie'] as $clef) {
		if (!empty($options[$clef])) {
			$args[$clef] = (string) $options[$clef];
		}
	}

	$reponse = dashboard_appeler($site, 'plugin_maj', $args, ['timeout' => dashboard_config('timeout_long', 300)]);

	if (!$reponse['ok']) {
		$message = $prefixe . ' : ' . (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'plugin_maj', 'erreur', $message, $reponse, $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'data' => $reponse['data']];
	}

	$message = $prefixe . ' : ' . (string) ($reponse['data']['version_avant'] ?? '?')
		. ' → ' . (string) ($reponse['data']['version_apres'] ?? '?');

	// Le dossier change de nom avec la version : le dire évite de chercher
	// pourquoi l'ancien répertoire ne contient plus rien.
	$dossier = (string) ($reponse['data']['dossier'] ?? '');
	if ($dossier !== '' && $dossier !== (string) ($reponse['data']['dossier_avant'] ?? '')) {
		$message .= ' (dossier ' . $dossier . ')';
	}
	dashboard_journaliser($id_dashboard_site, 'plugin_maj', 'ok', $message, $reponse['data'], $reponse['duree_ms']);

	dashboard_synchroniser($id_dashboard_site);

	return ['ok' => true, 'message' => $message, 'data' => $reponse['data']];
}

/**
 * Met à jour tous les plugins d'un site pour lesquels une version est annoncée.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_plugin_maj_tous($id_dashboard_site) {
	$prefixes = sql_allfetsel(
		'prefixe',
		'spip_dashboard_plugins',
		[
			'id_dashboard_site = ' . (int) $id_dashboard_site,
			'maj_disponible = ' . sql_quote('oui'),
			'distribue = ' . sql_quote('non'),
		],
		'',
		'prefixe'
	);

	$rapport = ['ok' => true, 'faits' => [], 'echecs' => []];
	foreach ($prefixes as $ligne) {
		$resultat = dashboard_operation_plugin_maj($id_dashboard_site, $ligne['prefixe']);
		if ($resultat['ok']) {
			$rapport['faits'][] = $resultat['message'];
		} else {
			$rapport['ok'] = false;
			$rapport['echecs'][] = $resultat['message'];
		}
	}

	$rapport['message'] = count($rapport['faits']) . ' mise(s) à jour effectuée(s)'
		. ($rapport['echecs'] ? ', ' . count($rapport['echecs']) . ' en échec' : '');

	return $rapport;
}

/**
 * Met à jour le core SPIP d'un site géré.
 *
 * Le remplacement des fichiers, et lui seul : la sauvegarde préalable et la
 * migration du schéma qui suit sont des étapes du chantier, de sorte qu'aucun
 * appelant ne puisse remplacer un noyau sans filet.
 *
 * @param int $id_dashboard_site
 * @param string $version Version cible, déduite si vide
 * @param array $options
 * @return array
 */
function dashboard_operation_core_maj($id_dashboard_site, $version = '', $options = []) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');
	include_spip('inc/dashboard_versions');
	include_spip('inc/dashboard_sync');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$version = trim((string) $version) ?: dashboard_version_cible((string) $site['version_spip']);
	if ($version === '') {
		return ['ok' => false, 'message' => 'Aucune version cible connue pour la branche installée', 'data' => []];
	}

	$url = dashboard_url_archive_spip($version);
	if ($url === '') {
		return ['ok' => false, 'message' => 'URL d’archive introuvable pour SPIP ' . $version, 'data' => []];
	}

	$reponse = dashboard_appeler($site, 'core_maj', [
		'url_archive'      => $url,
		'version_attendue' => $version,
		'sha256'           => (string) ($options['sha256'] ?? ''),
	], ['timeout' => dashboard_config('timeout_long', 300)]);

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'core_maj', 'erreur', $message, $reponse, $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'data' => $reponse['data']];
	}

	$version_apres = (string) ($reponse['data']['version_apres'] ?? $version);
	$message = 'SPIP ' . (string) ($reponse['data']['version_avant'] ?? '?') . ' → ' . $version_apres;
	dashboard_journaliser($id_dashboard_site, 'core_maj', 'ok', $message, $reponse['data'], $reponse['duree_ms']);

	dashboard_synchroniser($id_dashboard_site);

	// La synchronisation qui suit immédiatement le remplacement interroge un
	// PHP qui a déjà chargé l'ancien inc_version.php : il annonce encore la
	// version d'avant, et la fiche continuerait de proposer une mise à jour
	// déjà faite. La version déployée, elle, est connue avec certitude —
	// l'agent l'a lue dans l'archive qu'il vient d'installer.
	if ($version_apres !== '') {
		sql_updateq('spip_dashboard_sites', [
			'version_spip' => $version_apres,
			'core_maj'     => dashboard_version_cible($version_apres) ? 'oui' : 'non',
		], 'id_dashboard_site = ' . (int) $id_dashboard_site);
	}

	return ['ok' => true, 'message' => $message, 'data' => $reponse['data']];
}

/**
 * Contrôles préalables à une mise à jour du core, sans rien modifier.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_core_preflight($id_dashboard_site) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler($site, 'core_maj_preflight');

	return [
		'ok'      => $reponse['ok'],
		'message' => $reponse['ok'] ? '' : (string) ($reponse['erreur']['message'] ?? ''),
		'data'    => $reponse['data'],
	];
}

/**
 * Joue une tranche de migration du schéma de base sur un site géré.
 *
 * L'agent rend la main dès qu'il a consommé son budget de temps ; `termine`
 * dit s'il reste du travail. L'appelant rappelle tant que ce n'est pas fini —
 * c'est ce que fait l'étape « base » d'un chantier.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_base_maj($id_dashboard_site) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'termine' => true, 'data' => []];
	}

	// Le budget accordé à l'agent reste en deçà de notre propre temps d'attente :
	// mieux vaut une tranche rendue proprement qu'une requête coupée.
	$timeout = (int) dashboard_config('timeout_long', 300);
	$budget  = max(5, min(120, (int) ($timeout / 3)));

	$reponse = dashboard_appeler($site, 'base_maj', ['budget' => $budget], ['timeout' => $timeout]);

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'base_maj', 'erreur', $message, $reponse, $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'termine' => true, 'data' => $reponse['data']];
	}

	$data    = $reponse['data'];
	$termine = !empty($data['termine']);
	$message = $termine
		? 'Base migrée en version ' . (string) ($data['version_base'] ?? '?')
		: 'Migration en cours : version ' . (string) ($data['version_base'] ?? '?')
			. ' sur ' . (string) ($data['version_base_attendue'] ?? '?');

	// Une tranche qui n'aboutit pas n'est pas un événement : seul le terme
	// mérite une ligne au journal, sans quoi une grosse migration le noierait.
	if ($termine) {
		dashboard_journaliser($id_dashboard_site, 'base_maj', 'ok', $message, $data, $reponse['duree_ms']);
	}

	return ['ok' => true, 'message' => $message, 'termine' => $termine, 'data' => $data];
}

/**
 * Contrôles préalables à la migration du schéma, sans rien modifier.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_base_preflight($id_dashboard_site) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler($site, 'base_maj_preflight');

	return [
		'ok'      => $reponse['ok'],
		'message' => $reponse['ok'] ? '' : (string) ($reponse['erreur']['message'] ?? ''),
		'data'    => $reponse['data'],
	];
}

/**
 * Remplace le `spip_loader.php` d'un site géré.
 *
 * Ce n'est pas un chantier : un seul aller-retour suffit, et il n'y a pas de
 * sauvegarde de base à prendre — c'est un fichier, que l'agent met lui-même de
 * côté avant d'écrire le nouveau. L'opération reste journalisée : remplacer le
 * script qui sait installer SPIP n'est pas un geste anodin.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_loader_maj($id_dashboard_site) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$url = trim((string) dashboard_config('url_spip_loader', _DASHBOARD_LOADER_URL));
	$reponse = dashboard_appeler(
		$site,
		'loader_maj',
		['url' => $url],
		['timeout' => dashboard_config('timeout_long', 300)]
	);

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'loader_maj', 'erreur', $message, $reponse['erreur'], $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'data' => $reponse['data']];
	}

	$data = (array) $reponse['data'];
	$avant = (string) ($data['version_avant'] ?? '');
	$apres = (string) ($data['version_apres'] ?? '');
	$message = 'spip_loader.php déposé (' . dashboard_octets((int) ($data['octets'] ?? 0)) . ')'
		. ($apres !== '' ? ' — version ' . ($avant !== '' && $avant !== $apres ? $avant . ' → ' . $apres : $apres) : '');

	dashboard_journaliser($id_dashboard_site, 'loader_maj', 'ok', $message, $data, $reponse['duree_ms']);

	return ['ok' => true, 'message' => $message, 'data' => $data];
}

/**
 * Formate une taille en octets.
 *
 * @param int $octets
 * @return string
 */
function dashboard_octets($octets) {
	$octets = (int) $octets;
	$unites = ['o', 'Ko', 'Mo', 'Go', 'To'];
	$rang = 0;
	while ($octets >= 1024 && $rang < count($unites) - 1) {
		$octets /= 1024;
		$rang++;
	}

	return ($rang ? round($octets, 1) : $octets) . ' ' . $unites[$rang];
}
