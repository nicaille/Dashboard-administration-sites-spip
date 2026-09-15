<?php
/**
 * Synchronisation de l'inventaire des sites gérés.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Interroge un site et met à jour son inventaire local.
 *
 * @param int $id_dashboard_site
 * @param array $options Transmises au client HTTP (timeout…)
 * @return array{ok: bool, message: string, site: array|null}
 */
function dashboard_synchroniser($id_dashboard_site, $options = []) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');
	include_spip('inc/dashboard_versions');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'site' => null];
	}

	// Le catalogue des dépôts du site géré ne se rafraîchit pas tout seul : sans
	// cela, l'inventaire est exact mais lit une liste de versions périmée, et le
	// parc annonce « à jour » des sites qui ne le sont pas. Le seuil de fraîcheur
	// évite d'en refaire le tour à chaque passage de la tâche de fond.
	if (empty($options['sans_depots'])) {
		dashboard_sync_rafraichir_depots($id_dashboard_site);
	}

	$reponse = dashboard_appeler($site, 'infos', ['caches' => true, 'plugins' => true], $options);
	$maintenant = date('Y-m-d H:i:s');

	if (!$reponse['ok']) {
		$message = dashboard_inerte($reponse['erreur']['message'] ?? 'Erreur inconnue');
		sql_updateq('spip_dashboard_sites', [
			'etat'      => 'erreur',
			'erreur'    => $message,
			'date_sync' => $maintenant,
		], 'id_dashboard_site = ' . (int) $id_dashboard_site);

		dashboard_journaliser($id_dashboard_site, 'sync', 'erreur', $message, $reponse['erreur'], $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'site' => dashboard_charger_site($id_dashboard_site)];
	}

	// Ce que répond un site géré est du texte, jamais du balisage : on le rend
	// inerte ici, une fois, plutôt que d'y penser à chaque affichage. Voir
	// dashboard_inerte().
	$infos   = dashboard_inerte($reponse['data']['infos'] ?? []);
	$plugins = is_array($infos['plugins'] ?? null) ? $infos['plugins'] : [];

	$version_spip = (string) ($infos['spip']['version'] ?? '');
	$cible        = dashboard_version_cible($version_spip);

	$nb_maj = 0;
	foreach ($plugins as $plugin) {
		if (!empty($plugin['maj_disponible'])) {
			$nb_maj++;
		}
	}

	sql_updateq('spip_dashboard_sites', [
		'etat'           => 'ok',
		'erreur'         => '',
		'version_spip'   => $version_spip,
		'php_version'    => (string) ($infos['serveur']['php'] ?? ''),
		'sql_version'    => (string) ($infos['base']['version'] ?? ''),
		'agent_version'  => (string) dashboard_inerte((string) ($reponse['agent'] ?? '')),
		'nb_plugins'     => count($plugins),
		'nb_plugins_maj' => $nb_maj,
		'core_maj'       => $cible ? 'oui' : 'non',
		// Le site répond, mais son espace privé peut être bloqué derrière la
		// page de mise à niveau tant que son schéma n'a pas été migré.
		'base_maj'       => !empty($infos['spip']['base_maj_requise']) ? 'oui' : 'non',
		'infos'          => json_encode($infos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
		'date_sync'      => $maintenant,
		'date_sync_ok'   => $maintenant,
	], 'id_dashboard_site = ' . (int) $id_dashboard_site);

	dashboard_enregistrer_plugins($id_dashboard_site, $plugins);

	dashboard_journaliser($id_dashboard_site, 'sync', 'ok', '', [
		'spip'        => $version_spip,
		'cible'       => $cible,
		'plugins'     => count($plugins),
		'plugins_maj' => $nb_maj,
	], $reponse['duree_ms']);

	return ['ok' => true, 'message' => '', 'site' => dashboard_charger_site($id_dashboard_site)];
}

/**
 * Remplace l'inventaire des plugins d'un site.
 *
 * @param int $id_dashboard_site
 * @param array $plugins
 * @return void
 */
function dashboard_enregistrer_plugins($id_dashboard_site, $plugins) {
	include_spip('inc/dashboard_client');
	// Déjà fait par l'appelant, mais cette fonction écrit en base ce qu'on lui
	// donne : elle ne doit pas dépendre de la vigilance de qui l'appelle.
	$plugins = dashboard_inerte($plugins);

	sql_delete('spip_dashboard_plugins', 'id_dashboard_site = ' . (int) $id_dashboard_site);

	foreach ($plugins as $plugin) {
		if (empty($plugin['prefixe'])) {
			continue;
		}
		sql_insertq('spip_dashboard_plugins', [
			'id_dashboard_site'  => (int) $id_dashboard_site,
			'prefixe'            => substr((string) $plugin['prefixe'], 0, 64),
			'nom'                => substr((string) ($plugin['nom'] ?? ''), 0, 255),
			'version'            => substr((string) ($plugin['version'] ?? ''), 0, 64),
			'version_disponible' => substr((string) ($plugin['version_disponible'] ?? ''), 0, 64),
			'maj_disponible'     => !empty($plugin['maj_disponible']) ? 'oui' : 'non',
			'etat'               => substr((string) ($plugin['etat'] ?? ''), 0, 32),
			'dossier'            => substr((string) ($plugin['dossier'] ?? ''), 0, 255),
			'source'             => substr((string) ($plugin['source'] ?? ''), 0, 16),
			'distribue'          => !empty($plugin['distribue']) ? 'oui' : 'non',
			'inscriptible'       => !empty($plugin['inscriptible']) ? 'oui' : 'non',
		]);
	}
}

/**
 * Fait relire au site géré les catalogues de dépôts trop anciens.
 *
 * Un dépôt par aller-retour, et trois au plus par synchronisation : un site en a
 * rarement davantage, et le reste attendra le passage suivant plutôt que
 * d'allonger indéfiniment la synchronisation d'un parc entier.
 *
 * @param int $id_dashboard_site
 * @return array{tentes: int, relus: int}
 */
function dashboard_sync_rafraichir_depots($id_dashboard_site) {
	include_spip('inc/dashboard_operations');

	$fraicheur = (int) dashboard_config('fraicheur_depots', 86400);
	$rapport = ['tentes' => 0, 'relus' => 0];
	if ($fraicheur <= 0) {
		return $rapport;
	}

	for ($tour = 0; $tour < 3; $tour++) {
		$reponse = dashboard_operation_depots_actualiser($id_dashboard_site, $fraicheur);
		$rapport['tentes']++;
		if (empty($reponse['ok'])) {
			break;
		}
		$data = (array) $reponse['data'];
		if (!empty($data['actualise'])) {
			$rapport['relus']++;
		}
		if (!empty($data['termine'])) {
			break;
		}
	}

	return $rapport;
}

/**
 * Synchronise tous les sites supervisés.
 *
 * @param int $limite Nombre maximum de sites traités (0 = tous)
 * @return array{traites: int, erreurs: int}
 */
function dashboard_synchroniser_tous($limite = 0) {
	$rapport = ['traites' => 0, 'erreurs' => 0];

	$ids = sql_allfetsel(
		'id_dashboard_site',
		'spip_dashboard_sites',
		'statut = ' . sql_quote('publie'),
		'',
		'date_sync',
		$limite ? '0,' . (int) $limite : ''
	);

	foreach ($ids as $ligne) {
		$resultat = dashboard_synchroniser((int) $ligne['id_dashboard_site']);
		$rapport['traites']++;
		if (!$resultat['ok']) {
			$rapport['erreurs']++;
		}
	}

	return $rapport;
}

/**
 * Inventaire complet mémorisé pour un site.
 *
 * @param array|int $site Ligne ou identifiant
 * @return array
 */
function dashboard_infos_site($site) {
	if (!is_array($site)) {
		include_spip('inc/dashboard_client');
		$site = dashboard_charger_site($site);
	}
	if (!$site || empty($site['infos'])) {
		return [];
	}
	$infos = json_decode((string) $site['infos'], true);

	return is_array($infos) ? $infos : [];
}
