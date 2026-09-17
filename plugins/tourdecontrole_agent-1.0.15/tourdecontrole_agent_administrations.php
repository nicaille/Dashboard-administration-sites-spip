<?php
/**
 * Installation / désinstallation de l'agent Dashboard.
 *
 * @package SPIP\Dashagent\Installation
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Reprend la version de schéma laissée par l'ancien préfixe.
 *
 * Le plugin de l’agent s'appelait « dashagent » jusqu'en 1.0.15, et le
 * préfixe a changé pour éviter une collision : un autre plugin SPIP occupe déjà
 * « dashboard », et SVP indexe les plugins par préfixe tous dépôts confondus —
 * il aurait fini par proposer l'un pour l'autre.
 *
 * Or SPIP nomme la meta de version de schéma d'après le préfixe. Sous le nouveau
 * nom, il ne trouve rien, en conclut que le plugin vient d'être installé, et
 * rejoue toutes les migrations depuis l'origine. Elles sont pour la plupart
 * inoffensives — `maj_tables()` ne détruit pas ce qui existe — mais les rejouer
 * sur une base déjà à jour n'a aucun sens, et la moindre migration destructrice
 * ajoutée un jour rendrait ce détour dangereux.
 *
 * L'ancienne meta n'est pas effacée : elle ne gêne personne, et elle laisse la
 * possibilité de revenir à la version précédente du plugin sans rien perdre.
 *
 * @param string $nom_meta_base_version Nom que SPIP donne à la meta aujourd'hui
 * @return void
 */
function dashagent_reprendre_schema($nom_meta_base_version) {
	include_spip('inc/meta');

	$ancienne = 'dashagent_base_version';
	if ($nom_meta_base_version === $ancienne) {
		return;
	}
	if (isset($GLOBALS['meta'][$nom_meta_base_version]) || !isset($GLOBALS['meta'][$ancienne])) {
		return;
	}

	ecrire_meta($nom_meta_base_version, $GLOBALS['meta'][$ancienne]);
	lire_metas();
}

/**
 * Création / mise à jour du schéma.
 *
 * @param string $nom_meta_base_version
 * @param string $version_cible
 * @return void
 */
function tourdecontrole_agent_upgrade($nom_meta_base_version, $version_cible) {
	dashagent_reprendre_schema($nom_meta_base_version);

	$maj = [];

	$maj['create'] = [
		['tourdecontrole_agent_creer_tables'],
		['tourdecontrole_agent_initialiser_configuration'],
	];

	// Rattrapage des installations où les tables n'ont pas été créées :
	// voir dashboard_administrations.php pour le détail du problème.
	$maj['1.0.2'] = [
		['tourdecontrole_agent_creer_tables'],
		['tourdecontrole_agent_initialiser_configuration'],
	];

	// Voir dashboard_administrations.php : les caches doivent être réinitialisés
	// après création des tables, sinon le compilateur garde une vue périmée.
	$maj['1.0.3'] = [
		['tourdecontrole_agent_creer_tables'],
	];

	$maj['1.0.4'] = [
		['tourdecontrole_agent_creer_tables'],
	];

	$maj['1.0.5'] = [
		['tourdecontrole_agent_creer_tables'],
	];

	include_spip('base/upgrade');
	maj_plugin($nom_meta_base_version, $version_cible, $maj);
}

/**
 * Suppression complète des données de l'agent.
 *
 * @param string $nom_meta_base_version
 * @return void
 */
function tourdecontrole_agent_vider_tables($nom_meta_base_version) {
	include_spip('inc/meta');

	sql_drop_table('spip_dashagent_journal');
	sql_drop_table('spip_dashagent_nonces');

	effacer_meta('dashagent');
	effacer_meta($nom_meta_base_version);
}

/**
 * Crée les tables de l'agent, et vérifie qu'elles existent réellement ensuite.
 *
 * @see dashboard_creer_tables()
 * @return array Tables encore absentes après coup
 */
function tourdecontrole_agent_creer_tables() {
	include_spip('base/create');
	include_spip('base/tourdecontrole_agent_tables');

	$descriptions = tourdecontrole_agent_descriptions_tables();
	$noms = array_keys($descriptions);

	if (function_exists('maj_tables')) {
		maj_tables($noms);
	}

	$manquantes = dashagent_tables_manquantes($noms);
	foreach ($manquantes as $nom) {
		$description = $descriptions[$nom];
		if (!function_exists('sql_create') || empty($description['field'])) {
			continue;
		}
		$primaire = $description['key']['PRIMARY KEY'] ?? '';
		$autoinc  = (strpos($primaire, ',') === false) && (strncmp($primaire, 'id_', 3) === 0);

		sql_create($nom, $description['field'], $description['key'] ?? [], $autoinc, false, '', 'continue');
	}

	$restantes = dashagent_tables_manquantes($noms);

	include_spip('inc/invalideur');
	include_spip('inc/meta');

	$trouver_table = charger_fonction('trouver_table', 'base', true);
	if ($trouver_table) {
		$trouver_table('');
	}
	include_spip('inc/flock');
	if (function_exists('purger_repertoire') && defined('_DIR_CACHE') && is_dir(_DIR_CACHE)) {
		purger_repertoire(_DIR_CACHE, ['subdir' => true]);
	}

	if ($restantes) {
		spip_log('installation : tables toujours absentes après création : ' . implode(', ', $restantes), 'dashagent');
	} elseif ($manquantes) {
		spip_log('installation : tables créées directement depuis les descripteurs : ' . implode(', ', $manquantes), 'dashagent');
	}

	return $restantes;
}

/**
 * Parmi ces tables, lesquelles n'existent pas en base ?
 *
 * @param array $noms
 * @return array
 */
function dashagent_tables_manquantes($noms) {
	$existantes = sql_alltable('%');
	$existantes = is_array($existantes) ? array_flip($existantes) : [];

	$manquantes = [];
	foreach ($noms as $nom) {
		if (!isset($existantes[$nom])) {
			$manquantes[] = $nom;
		}
	}

	return $manquantes;
}

/**
 * Valeurs de configuration par défaut, posées à la première installation.
 *
 * Le secret partagé n'est *pas* généré ici : tant qu'il est vide, l'agent
 * refuse toutes les requêtes. C'est un défaut volontairement fermé.
 *
 * @return void
 */
function tourdecontrole_agent_initialiser_configuration() {
	include_spip('inc/config');

	$config = lire_config('dashagent', []);
	if (!is_array($config)) {
		$config = [];
	}

	$defaut = [
		'secret'            => '',
		'ips_autorisees'    => '',
		'tolerance_horloge' => 300,
		'op_infos'          => 'on',
		'op_purger'         => 'on',
		'op_sauvegarde'     => 'on',
		'op_plugin_maj'     => '',
		'op_core_maj'       => '',
		'retention_journal' => 90,
		'retention_backup'  => 7,
		// Ces deux-là s'accordent site par site, jamais par défaut.
		'op_waf'            => '',
		'op_loader'         => '',
	];

	ecrire_config('dashagent', array_merge($defaut, $config));
}
