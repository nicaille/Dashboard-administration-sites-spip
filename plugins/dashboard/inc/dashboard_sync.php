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

	$reponse = dashboard_appeler($site, 'infos', ['caches' => true, 'plugins' => true], $options);
	$maintenant = date('Y-m-d H:i:s');

	if (!$reponse['ok']) {
		$message = $reponse['erreur']['message'] ?? 'Erreur inconnue';
		sql_updateq('spip_dashboard_sites', [
			'etat'      => 'erreur',
			'erreur'    => $message,
			'date_sync' => $maintenant,
		], 'id_dashboard_site = ' . (int) $id_dashboard_site);

		dashboard_journaliser($id_dashboard_site, 'sync', 'erreur', $message, $reponse['erreur'], $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'site' => dashboard_charger_site($id_dashboard_site)];
	}

	$infos   = $reponse['data']['infos'] ?? [];
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
		'spip_version'   => $version_spip,
		'php_version'    => (string) ($infos['serveur']['php'] ?? ''),
		'sql_version'    => (string) ($infos['base']['version'] ?? ''),
		'agent_version'  => (string) ($reponse['agent'] ?? ''),
		'nb_plugins'     => count($plugins),
		'nb_plugins_maj' => $nb_maj,
		'core_maj'       => $cible ? 'oui' : 'non',
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
