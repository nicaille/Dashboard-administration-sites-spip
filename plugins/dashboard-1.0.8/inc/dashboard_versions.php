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
	include_spip('inc/dashboard_client');

	$manuelles = dashboard_versions_manuelles();

	$cache = $forcer ? [] : dashboard_versions_cache_lire();
	if (!$cache) {
		$cache = dashboard_versions_distantes();
		if ($cache) {
			dashboard_versions_cache_ecrire($cache);
		}
	}

	// Les versions manuelles écrasent l'index distant, jamais l'inverse.
	return array_merge($cache, $manuelles);
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
 * @return array
 */
function dashboard_versions_distantes() {
	include_spip('inc/dashboard_client');
	include_spip('inc/distant');
	include_spip('inc/plugin');

	$url = (string) dashboard_config('url_archives_spip', 'https://files.spip.net/spip/archives/');
	if (!preg_match('#^https://#i', $url)) {
		return [];
	}

	$reponse = recuperer_url($url, ['taille_max' => 2 * 1024 * 1024]);
	if (!is_array($reponse) || empty($reponse['page'])) {
		return [];
	}

	$versions = [];
	if (preg_match_all('/SPIP-v?(\d+\.\d+\.\d+)\.zip/i', (string) $reponse['page'], $trouves)) {
		foreach ($trouves[1] as $version) {
			$branche = implode('.', array_slice(explode('.', $version), 0, 2));
			if (!isset($versions[$branche]) || spip_version_compare($version, $versions[$branche], '>')) {
				$versions[$branche] = $version;
			}
		}
	}

	return $versions;
}

/**
 * @return array
 */
function dashboard_versions_cache_lire() {
	include_spip('inc/config');
	$cache = lire_config('dashboard/cache_versions', []);
	if (!is_array($cache) || empty($cache['date']) || empty($cache['versions'])) {
		return [];
	}
	if (time() - (int) $cache['date'] > _DASHBOARD_CACHE_VERSIONS) {
		return [];
	}

	return (array) $cache['versions'];
}

/**
 * @param array $versions
 * @return void
 */
function dashboard_versions_cache_ecrire($versions) {
	include_spip('inc/config');
	ecrire_config('dashboard/cache_versions', ['date' => time(), 'versions' => $versions]);
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
function dashboard_version_cible($version_actuelle) {
	include_spip('inc/plugin');

	$version_actuelle = trim((string) $version_actuelle);
	if (!preg_match('/^(\d+\.\d+)\./', $version_actuelle, $m)) {
		return '';
	}
	$branche  = $m[1];
	$versions = dashboard_versions_spip();

	if (empty($versions[$branche])) {
		return '';
	}

	return spip_version_compare($versions[$branche], $version_actuelle, '>') ? $versions[$branche] : '';
}

/**
 * URL de l'archive officielle d'une version de SPIP.
 *
 * @param string $version
 * @return string
 */
function dashboard_url_archive_spip($version) {
	include_spip('inc/dashboard_client');

	if (!preg_match('/^\d+\.\d+\.\d+[a-z0-9.\-]*$/i', (string) $version)) {
		return '';
	}
	$base = rtrim((string) dashboard_config('url_archives_spip', 'https://files.spip.net/spip/archives/'), '/');

	return $base . '/SPIP-v' . $version . '.zip';
}
