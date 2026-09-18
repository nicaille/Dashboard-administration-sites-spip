<?php
/**
 * Filtres mis à disposition des squelettes du tableau de bord.
 *
 * @package SPIP\Dashboard\Fonctions
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

include_spip('inc/dashboard_client');
include_spip('inc/dashboard_operations');
include_spip('inc/dashboard_versions');

/**
 * Fonctions de SPIP dont le plugin dépend, par fichier qui les fournit.
 *
 * Sert de contrat explicite : toute API du core appelée par le plugin figure
 * ici, et la page de configuration signale celles qui manquent sur cette
 * installation. Une API absente devient un message lisible au lieu d'une
 * erreur fatale au milieu d'un enregistrement.
 *
 * @return array
 */
function dashboard_api_requise() {
	return [
		''                     => ['include_spip', 'charger_fonction', '_T', '_request', 'spip_log',
			'parametre_url', 'url_de_base', 'generer_url_ecrire'],
		'inc/headers'          => ['redirige_par_entete'],
		'inc/autoriser'        => ['autoriser'],
		'inc/config'           => ['lire_config', 'ecrire_config'],
		'inc/distant'          => ['recuperer_url'],
		'inc/flock'            => ['sous_repertoire', 'spip_unlink'],
		'inc/invalideur'       => ['purger_repertoire'],
		'inc/meta'             => ['ecrire_meta', 'effacer_meta'],
		'inc/plugin'           => ['spip_version_compare'],
		'inc/session'          => ['session_get'],
		// Appelée après une migration de schéma, comme le fait SPIP lui-même :
		// les comptes d'authentification externes doivent être resynchronisés.
		'inc/auth'             => ['auth_synchroniser_distant'],
		'inc/editer'           => ['formulaires_editer_objet_charger', 'formulaires_editer_objet_verifier',
			'formulaires_editer_objet_traiter'],
		'action/editer_objet'  => ['objet_inserer', 'objet_modifier', 'objet_instituer'],
		'base/abstract_sql'    => ['sql_alltable', 'sql_create', 'sql_query', 'sql_showtable',
			'sql_version', 'sql_error', 'sql_in'],
		'base/create'          => ['maj_tables', 'creer_base'],
		'base/upgrade'         => ['maj_plugin', 'maj_base'],
	];
}

/**
 * Fonctions optionnelles : leur absence dégrade une fonctionnalité sans casser
 * le plugin.
 *
 * @return array
 */
function dashboard_api_optionnelle() {
	return [];
}

/**
 * Le chiffrement des secrets fonctionne-t-il ? Sinon, ils sont stockés en clair.
 *
 * @filtre
 * @param string $rien
 * @return bool
 */
function dashboard_secrets_chiffres($rien = '') {
	include_spip('inc/dashboard_client');

	return function_exists('dashboard_chiffrement_disponible') && dashboard_chiffrement_disponible();
}

/**
 * Parmi les API attendues, lesquelles manquent sur cette installation ?
 *
 * @filtre
 * @param string $rien
 * @return string Liste lisible, vide si tout est présent
 */
function dashboard_api_manquantes($rien = '') {
	$manquantes = [];

	foreach (dashboard_api_requise() as $fichier => $fonctions) {
		if ($fichier !== '') {
			include_spip($fichier);
		}
		foreach ($fonctions as $fonction) {
			if (!function_exists($fonction)) {
				$manquantes[] = $fonction . '()' . ($fichier ? ' [' . $fichier . ']' : '');
			}
		}
	}

	return implode(', ', $manquantes);
}

/**
 * Idem pour les API optionnelles.
 *
 * @filtre
 * @param string $rien
 * @return string
 */
function dashboard_api_optionnelle_manquantes($rien = '') {
	$manquantes = [];

	foreach (dashboard_api_optionnelle() as $fichier => $fonctions) {
		include_spip($fichier);
		foreach ($fonctions as $fonction) {
			if (!function_exists($fonction)) {
				$manquantes[] = $fonction . '()';
			}
		}
	}

	return implode(', ', $manquantes);
}

/**
 * Les tables du plugin sont-elles réellement présentes en base ?
 *
 * Une activation ratée peut laisser la version enregistrée en meta sans que les
 * tables aient été créées. Plutôt que de laisser les boucles échouer sur une
 * erreur SQL brute, les pages testent d'abord ce point et expliquent quoi faire.
 *
 * @filtre
 * @return bool
 */
function dashboard_tables_presentes($rien = '') {
	static $presentes = null;

	if ($presentes === null) {
		$liste = sql_alltable('%');
		$liste = is_array($liste) ? array_flip($liste) : [];
		$presentes = true;
		foreach (['spip_dashboard_sites', 'spip_dashboard_plugins', 'spip_dashboard_journal', 'spip_dashboard_sauvegardes'] as $table) {
			$presentes = $presentes && isset($liste[$table]);
		}
	}

	return $presentes;
}

/**
 * Liste des tables du plugin absentes de la base, pour affichage.
 *
 * @filtre
 * @return string
 */
function dashboard_tables_absentes($rien = '') {
	include_spip('tourdecontrole_administrations');
	include_spip('base/tourdecontrole_tables');

	if (!function_exists('dashboard_tables_manquantes') || !function_exists('tourdecontrole_descriptions_tables')) {
		return '';
	}

	return implode(', ', dashboard_tables_manquantes(array_keys(tourdecontrole_descriptions_tables())));
}

/**
 * Capacités fournies au site géré : extensions PHP et bibliothèques.
 *
 * Elles sont rangées à part des plugins parce qu'elles ne se mettent pas à jour
 * depuis ici : leur version relève de l'hébergement, pas de la maintenance
 * applicative. Les mêler aux plugins noyait ces derniers.
 *
 * @filtre
 * @param string $json Inventaire mémorisé
 * @return array
 */
function dashboard_procures($json) {
	$procures = dashboard_info($json, 'procures', []);
	if (!is_array($procures)) {
		return [];
	}

	// L'origine se déduit ici plutôt que côté agent : un agent d'une version
	// antérieure reste ainsi affichable, et les libellés restent traduisibles.
	foreach ($procures as $rang => $procure) {
		$fournie_par = (string) ($procure['fournie_par'] ?? '');
		if ($fournie_par !== '') {
			$origine = 'plugin';
		} elseif (!empty($procure['php']) or dashboard_est_une_extension_php($procure)) {
			$origine = 'php';
		} else {
			$origine = 'spip';
		}
		$procures[$rang]['origine'] = $origine;
	}

	return $procures;
}

/**
 * Une capacité est-elle une extension du PHP lui-même ?
 *
 * Le drapeau vient de l'agent, mais un agent plus ancien ne l'envoie pas :
 * le nom (« php », « php:curl ») suffit alors à trancher, sans confondre
 * avec une bibliothèque comme « phpmailer ».
 *
 * @param array $procure
 * @return bool
 */
function dashboard_est_une_extension_php($procure) {
	$nom = strtolower((string) ($procure['nom'] ?? ''));

	return $nom === 'php' || strncmp($nom, 'php:', 4) === 0;
}

/**
 * Combien de capacités fournies ce site déclare-t-il ?
 *
 * @filtre
 * @param string $json
 * @return int
 */
function dashboard_compter_procures($json) {
	return count(dashboard_procures($json));
}

/**
 * Date lisible, ou chaîne vide si la date n'a jamais été renseignée.
 *
 * Le squelette affiche alors un libellé de repli dans un bloc voisin : imbriquer
 * `[(…)]` dans l'argument d'un filtre casse le comptage des crochets du
 * compilateur, et tout le reste du fichier finit affiché tel quel.
 *
 * @filtre
 * @param string $date
 * @param string $format heure|jour
 * @return string
 */
function dashboard_date_lisible($date, $format = 'heure') {
	$date = (string) $date;
	if ($date === '' || strncmp($date, '0000-00-00', 10) === 0) {
		return '';
	}

	include_spip('inc/filtres_dates');
	if ($format === 'jour' && function_exists('affdate_jourcourt')) {
		return affdate_jourcourt($date);
	}
	if (function_exists('affdate_heure')) {
		return affdate_heure($date);
	}

	return $date;
}

/**
 * Nom de plugin lisible, même si l'inventaire mémorisé est antérieur au
 * filtrage fait côté agent.
 *
 * @filtre
 * @param string $nom
 * @return string
 */
function dashboard_nom_lisible($nom) {
	$nom = (string) $nom;
	if (strpos($nom, '[') === false || !preg_match('/\[[a-z]{2}(_[a-zA-Z]{2,})?\]/', $nom)) {
		return $nom;
	}

	$langue = (string) ($GLOBALS['meta']['langue_site'] ?? 'fr');
	if (!preg_match_all('/\[([a-z]{2}(?:_[a-zA-Z]{2,})?)\]([^\[]*)/', $nom, $trouves, PREG_SET_ORDER)) {
		return $nom;
	}

	$blocs = [];
	foreach ($trouves as $bloc) {
		$valeur = trim($bloc[2]);
		if ($valeur !== '' && !isset($blocs[$bloc[1]])) {
			$blocs[$bloc[1]] = $valeur;
		}
	}
	foreach ([$langue, 'fr', 'en'] as $preferee) {
		if (!empty($blocs[$preferee])) {
			return $blocs[$preferee];
		}
	}

	return $blocs ? (string) reset($blocs) : $nom;
}

/**
 * Extrait une valeur de l'inventaire JSON mémorisé pour un site.
 *
 * Exemple : `[(#INFOS|dashboard_info{serveur/php})]`
 *
 * @filtre
 * @param string $json
 * @param string $chemin Chemin séparé par des `/`
 * @param mixed $defaut
 * @return mixed
 */
function dashboard_info($json, $chemin, $defaut = '') {
	$infos = is_array($json) ? $json : json_decode((string) $json, true);
	if (!is_array($infos)) {
		return $defaut;
	}

	foreach (explode('/', (string) $chemin) as $clef) {
		if (!is_array($infos) || !array_key_exists($clef, $infos)) {
			return $defaut;
		}
		$infos = $infos[$clef];
	}

	if ($infos === null || $infos === '') {
		return $defaut;
	}

	// L'inventaire est rendu inerte à l'enregistrement, mais celui d'un site
	// synchronisé avant ce correctif ne l'est pas : ce filtre est le passage
	// obligé de tout ce qui en sort vers un squelette. Voir dashboard_inerte().
	include_spip('inc/dashboard_client');

	return dashboard_inerte($infos);
}

/**
 * Formate une taille en octets pour l'affichage.
 *
 * @filtre
 * @param int $octets
 * @return string
 */
function dashboard_taille($octets) {
	return dashboard_octets((int) $octets);
}

/**
 * Classe CSS correspondant à l'état d'un site.
 *
 * @filtre
 * @param string $etat
 * @return string
 */
function dashboard_classe_etat($etat) {
	$classes = [
		'ok'      => 'dashboard-ok',
		'erreur'  => 'dashboard-erreur',
		'inconnu' => 'dashboard-inconnu',
	];

	return $classes[$etat] ?? 'dashboard-inconnu';
}

/**
 * Version de SPIP disponible pour la branche d'un site, ou chaîne vide.
 *
 * @filtre
 * @param string $version_actuelle
 * @return string
 */
function dashboard_version_disponible($version_actuelle) {
	return dashboard_version_cible((string) $version_actuelle);
}

/**
 * Rend lisible une opération du journal.
 *
 * @filtre
 * @param string $operation
 * @return string
 */
function dashboard_libelle_operation($operation, $cible = '') {
	$libelles = [
		'sync'            => 'dashboard:operation_sync',
		'purger'          => 'dashboard:operation_purger',
		'sauvegarde'      => 'dashboard:operation_sauvegarde',
		'plugin_maj'      => 'dashboard:operation_plugin_maj',
		'plugin_maj_tous' => 'dashboard:operation_plugin_maj_tous',
		'core_maj'        => 'dashboard:operation_core_maj',
		'depots_maj'      => 'dashboard:operation_depots_maj',
		'base_maj'        => 'dashboard:operation_base_maj',
	];

	$libelle = isset($libelles[$operation]) ? _T($libelles[$operation]) : $operation;
	$cible = trim((string) $cible);

	return $cible !== '' ? $libelle . ' : ' . $cible : $libelle;
}

/**
 * Fraîcheur du catalogue des dépôts d'un site, en clair.
 *
 * « Aucune mise à jour disponible » ne vaut que ce que vaut la date à laquelle
 * le catalogue a été relu : le dire évite de conclure trop vite. On retient le
 * dépôt le plus ancien, c'est lui qui borne la confiance qu'on peut accorder à
 * l'ensemble.
 *
 * @filtre
 * @param string $infos Inventaire JSON mémorisé pour le site
 * @return string Chaîne vide si le site n'a pas de dépôt
 */
function dashboard_depots_age($infos) {
	$depots = dashboard_info($infos, 'depots');
	if (!is_array($depots) || !$depots) {
		return '';
	}

	$ages = [];
	foreach ($depots as $depot) {
		// Jamais relu : c'est le cas le plus méritant d'être signalé.
		if (!isset($depot['age']) || $depot['age'] === null) {
			return _T('dashboard:depots_jamais');
		}
		$ages[] = (int) $depot['age'];
	}

	$age = max($ages);
	if ($age < 3600) {
		return _T('dashboard:depots_minutes', ['n' => max(1, (int) round($age / 60))]);
	}
	if ($age < 172800) {
		return _T('dashboard:depots_heures', ['n' => (int) round($age / 3600)]);
	}

	return _T('dashboard:depots_jours', ['n' => (int) round($age / 86400)]);
}

/**
 * Durée de validité par défaut d'un catalogue de dépôts, en secondes.
 */
if (!defined('_DASHBOARD_DEPOTS_VALIDITE')) {
	define('_DASHBOARD_DEPOTS_VALIDITE', 86400);
}

/**
 * Au-delà de quel âge un catalogue de dépôts n'est plus digne de foi.
 *
 * `fraicheur_depots` à zéro coupe le rafraîchissement automatique, pas
 * l'avertissement : c'est même le cas où il sert le plus, puisque plus rien ne
 * rajeunit alors le catalogue de lui-même. On retombe sur la validité par
 * défaut, un jour — sans quoi le parc annoncerait « tout est à jour » sans la
 * moindre réserve, en ayant justement renoncé à regarder.
 *
 * @return int
 */
function dashboard_depots_validite() {
	$seuil = (int) dashboard_config('fraicheur_depots', _DASHBOARD_DEPOTS_VALIDITE);

	return $seuil > 0 ? $seuil : _DASHBOARD_DEPOTS_VALIDITE;
}

/**
 * Le catalogue des dépôts est-il trop ancien pour qu'on s'y fie ?
 *
 * @filtre
 * @param string $infos Inventaire JSON mémorisé pour le site
 * @return bool
 */
function dashboard_depots_perimes($infos) {
	$depots = dashboard_info($infos, 'depots');
	if (!is_array($depots) || !$depots) {
		return false;
	}

	$seuil = dashboard_depots_validite();

	foreach ($depots as $depot) {
		if (!isset($depot['age']) || $depot['age'] === null || (int) $depot['age'] > $seuil) {
			return true;
		}
	}

	return false;
}

/**
 * L'adresse du `spip_loader.php` d'un site, à partir de celle du site.
 *
 * Le script se trouve à la racine web, là où pointe l'URL publique. Reste à
 * joindre les deux morceaux sans supposer que l'une finit par une barre — la
 * moitié des fiches en portent une, l'autre non — et à écarter une adresse qui
 * n'en est pas une : ce lien s'ouvre dans un nouvel onglet, il n'a pas à
 * emmener ailleurs que sur le site.
 *
 * @filtre
 * @param string $url URL publique du site géré
 * @return string Chaîne vide si l'adresse est inutilisable
 */
function dashboard_url_loader($url) {
	$url = trim((string) $url);
	if ($url === '' || !preg_match('#^https?://#i', $url)) {
		return '';
	}

	// Ni requête ni ancre : on veut la racine, pas la page qu'on nous a donnée.
	$url = preg_replace('/[?#].*$/', '', $url);

	return rtrim($url, '/') . '/spip_loader.php';
}

/**
 * Ce site a-t-il tel plugin actif ?
 *
 * Sert à n'afficher un onglet que là où il a un sens : proposer le tableau de
 * bord du pare-feu sur un site qui n'en a pas ne donnerait qu'un message
 * d'erreur au clic.
 *
 * @filtre
 * @param string $infos Inventaire JSON mémorisé
 * @param string $prefixe Préfixe du plugin, insensible à la casse
 * @return bool
 */
function dashboard_a_plugin($infos, $prefixe) {
	$plugins = dashboard_info($infos, 'plugins');
	if (!is_array($plugins)) {
		return false;
	}
	$prefixe = strtoupper(trim((string) $prefixe));

	foreach ($plugins as $plugin) {
		if (is_array($plugin) && strtoupper((string) ($plugin['prefixe'] ?? '')) === $prefixe) {
			return true;
		}
	}

	return false;
}

/**
 * Cette date remonte-t-elle à moins de N secondes ?
 *
 * Sert à ne montrer le compte rendu d'un chantier que tant qu'il répond à un
 * geste qu'on vient de faire. Passé ce délai, c'est le journal qui en garde la
 * trace, et l'encadré n'encombre plus la page.
 *
 * @filtre
 * @param string $date Date SQL
 * @param int $secondes
 * @return bool
 */
function dashboard_recent($date, $secondes = 3600) {
	$date = trim((string) $date);
	if ($date === '' || strncmp($date, '0000', 4) === 0) {
		return false;
	}
	$t = strtotime($date);

	return $t !== false && (time() - $t) < max(1, (int) $secondes);
}

/**
 * Ce que le chantier est en train de faire, en clair.
 *
 * L'encadré ne portait jusqu'ici que le message de l'étape **précédente** :
 * « bloqué sur la sauvegarde » se lisait alors qu'en réalité les contrôles
 * préalables étaient en cours. Nommer le travail en cours lève l'ambiguïté.
 *
 * @filtre
 * @param string $etape
 * @return string
 */
function dashboard_libelle_etape($etape) {
	$libelles = [
		'depots'     => 'dashboard:etape_depots',
		'sauvegarde' => 'dashboard:etape_sauvegarde',
		'preflight'  => 'dashboard:etape_preflight',
		'core'       => 'dashboard:etape_core',
		'base'       => 'dashboard:etape_base',
		'plugin'     => 'dashboard:etape_plugin',
		'plugins'    => 'dashboard:etape_plugins',
		'sync'       => 'dashboard:etape_sync',
	];

	return isset($libelles[$etape]) ? _T($libelles[$etape]) : (string) $etape;
}

/**
 * Synthèse d'un parc : compteurs pour la page d'ensemble.
 *
 * @filtre
 * @return array
 */
function dashboard_synthese($rien = '') {
	if (!dashboard_tables_presentes()) {
		return ['sites' => 0, 'supervises' => 0, 'en_erreur' => 0, 'core_a_jour' => 0, 'core_retard' => 0,
			'base_retard' => 0, 'plugins_maj' => 0];
	}

	$synthese = [
		'sites'        => (int) sql_countsel('spip_dashboard_sites', 'statut != ' . sql_quote('poubelle')),
		'supervises'   => (int) sql_countsel('spip_dashboard_sites', 'statut = ' . sql_quote('publie')),
		'en_erreur'    => (int) sql_countsel('spip_dashboard_sites', ['statut = ' . sql_quote('publie'), 'etat = ' . sql_quote('erreur')]),
		'core_a_jour'  => 0,
		'core_retard'  => (int) sql_countsel('spip_dashboard_sites', ['statut = ' . sql_quote('publie'), 'core_maj = ' . sql_quote('oui')]),
		// Un site dont la base attend sa migration répond encore, mais son espace
		// privé est bloqué : c'est un retard d'une autre nature que celui du core.
		'base_retard'  => (int) sql_countsel('spip_dashboard_sites', ['statut = ' . sql_quote('publie'), 'base_maj = ' . sql_quote('oui')]),
		'plugins_maj'  => 0,
	];

	$synthese['core_a_jour'] = max(0, $synthese['supervises'] - $synthese['core_retard']);

	$total = sql_fetsel('SUM(nb_plugins_maj) AS total', 'spip_dashboard_sites', 'statut = ' . sql_quote('publie'));
	$synthese['plugins_maj'] = (int) ($total['total'] ?? 0);

	return $synthese;
}

/**
 * Fraîcheur des catalogues de dépôts sur l'ensemble du parc.
 *
 * Le nombre de mises à jour annoncé par la vue d'ensemble est la somme de ce que
 * chaque site a relevé, et chaque site ne relève que ce que son propre catalogue
 * lui dit. Donner ce nombre sans dire de quand datent les catalogues, c'est
 * affirmer « tout va bien » sans avoir regardé.
 *
 * On retient le plus ancien : c'est lui qui borne la confiance qu'on peut
 * accorder à l'ensemble.
 *
 * @return array{sites: int, sans_depot: int, perimes: int, age: int|null}
 */
function dashboard_depots_parc() {
	static $parc = null;
	if ($parc !== null) {
		return $parc;
	}

	$parc = ['sites' => 0, 'sans_depot' => 0, 'perimes' => 0, 'age' => null];
	if (!dashboard_tables_presentes()) {
		return $parc;
	}

	$seuil = dashboard_depots_validite();
	$lignes = sql_allfetsel('infos', 'spip_dashboard_sites', 'statut = ' . sql_quote('publie'));

	foreach ($lignes as $ligne) {
		$parc['sites']++;
		$depots = dashboard_info((string) $ligne['infos'], 'depots');
		if (!is_array($depots) || !$depots) {
			// Pas de SVP, ou inventaire d'une version antérieure de l'agent :
			// il n'y a pas de catalogue à dater.
			$parc['sans_depot']++;
			continue;
		}

		$vieux = null;
		foreach ($depots as $depot) {
			$age = (isset($depot['age']) && $depot['age'] !== null) ? (int) $depot['age'] : null;
			// Jamais relu : l'âge est inconnu, donc infini.
			if ($age === null) {
				$vieux = null;
				$parc['perimes']++;
				break;
			}
			$vieux = max((int) $vieux, $age);
		}

		if ($vieux === null) {
			continue;
		}
		if ($vieux > $seuil) {
			$parc['perimes']++;
		}
		$parc['age'] = ($parc['age'] === null) ? $vieux : max($parc['age'], $vieux);
	}

	return $parc;
}

/**
 * Âge du plus ancien catalogue du parc, en clair.
 *
 * @filtre
 * @param string $rien
 * @return string Chaîne vide si aucun site n'a de dépôt
 */
function dashboard_depots_parc_age($rien = '') {
	$parc = dashboard_depots_parc();
	if ($parc['sites'] === $parc['sans_depot']) {
		return '';
	}
	if ($parc['age'] === null) {
		return _T('dashboard:depots_jamais');
	}
	if ($parc['age'] < 3600) {
		return _T('dashboard:depots_minutes', ['n' => max(1, (int) round($parc['age'] / 60))]);
	}
	if ($parc['age'] < 172800) {
		return _T('dashboard:depots_heures', ['n' => (int) round($parc['age'] / 3600)]);
	}

	return _T('dashboard:depots_jours', ['n' => (int) round($parc['age'] / 86400)]);
}

/**
 * Combien de sites du parc lisent un catalogue périmé.
 *
 * @filtre
 * @param string $rien
 * @return int
 */
function dashboard_depots_parc_perimes($rien = '') {
	return dashboard_depots_parc()['perimes'];
}

/**
 * Mesure d'un cache donné dans l'inventaire mémorisé.
 *
 * Exemple : `[(#INFOS|dashboard_cache_info{images,octets})]`
 *
 * @filtre
 * @param string $json
 * @param string $cible
 * @param string $clef octets|fichiers|partiel
 * @return int
 */
function dashboard_cache_info($json, $cible, $clef = 'octets') {
	return (int) dashboard_info($json, 'caches/' . $cible . '/' . $clef, 0);
}

/**
 * Libellé d'une cible de purge.
 *
 * @filtre
 * @param string $cible
 * @return string
 */
function dashboard_libelle_cache($cible) {
	$cibles = dashboard_cibles_purge();

	return $cibles[$cible] ?? $cible;
}

/**
 * Cibles de purge proposées par l'interface.
 *
 * @filtre
 * @return array
 */
function dashboard_cibles_purge() {
	return [
		'tout'       => _T('dashboard:purge_tout'),
		'pages'      => _T('dashboard:purge_pages'),
		'squelettes' => _T('dashboard:purge_squelettes'),
		'images'     => _T('dashboard:purge_images'),
		'css_js'     => _T('dashboard:purge_css_js'),
		'sessions'   => _T('dashboard:purge_sessions'),
	];
}

/**
 * Nombre de jours d'historique WAF servis à la page.
 *
 * Le sélecteur propose sept, trente et quatre-vingt-dix jours ; la page reçoit
 * la fenêtre la plus large **une seule fois**, et le navigateur y découpe les
 * autres. Basculer de trente à quatre-vingt-dix jours est alors instantané, et
 * ne coûte ni requête ni rechargement — pour quelques kilo-octets de JSON.
 */
if (!defined('_DASHBOARD_WAF_FENETRE')) {
	define('_DASHBOARD_WAF_FENETRE', 90);
}

/**
 * Complète une série quotidienne, jour par jour, sans trou.
 *
 * Les jours sans événement n'existent pas en base : les inventer ici est
 * indispensable à la lecture. Une courbe qui saute du 3 au 9 laisse croire à
 * une continuité entre les deux, là où il ne s'est rien passé pendant cinq
 * jours — c'est précisément l'inverse de ce qu'on veut montrer.
 *
 * @param array $connus Indexé par jour (AAAA-MM-JJ)
 * @param int $jours
 * @return array Liste de points, du plus ancien au plus récent
 */
function dashboard_waf_completer($connus, $jours) {
	$jours   = max(1, min(365, (int) $jours));
	$premier = date('Y-m-d', time() - ($jours - 1) * 86400);
	$points  = [];

	for ($i = 0; $i < $jours; $i++) {
		$jour = date('Y-m-d', strtotime($premier . ' +' . $i . ' day'));
		$points[] = [
			'jour'     => $jour,
			'requetes' => (int) ($connus[$jour]['requetes'] ?? 0),
			'ips'      => (int) ($connus[$jour]['ips'] ?? 0),
		];
	}

	return $points;
}

/**
 * L'activité quotidienne du WAF d'un site, prête pour le graphique.
 *
 * @param int $id_dashboard_site
 * @param int $jours
 * @return array
 */
function dashboard_waf_serie($id_dashboard_site, $jours = _DASHBOARD_WAF_FENETRE) {
	$depuis = date('Y-m-d', time() - (max(1, (int) $jours) - 1) * 86400);

	$lignes = sql_allfetsel(
		['jour', 'requetes', 'ips'],
		'spip_dashboard_waf_jours',
		['id_dashboard_site = ' . (int) $id_dashboard_site, 'jour >= ' . sql_quote($depuis)],
		'',
		'jour'
	);

	$connus = [];
	foreach ((array) $lignes as $ligne) {
		$connus[(string) $ligne['jour']] = $ligne;
	}

	return dashboard_waf_completer($connus, $jours);
}

/**
 * La même chose pour le parc entier : la somme des sites équipés du WAF.
 *
 * Les requêtes s'additionnent sans réserve. Les adresses, non : une même IP qui
 * frappe trois sites y est comptée trois fois, et le total n'est donc pas un
 * nombre d'assaillants distincts. C'est assumé — le graphique mesure la
 * pression subie par le parc, pas la population qui l'exerce, et il faudrait
 * remonter les adresses elles-mêmes pour dire l'autre. La légende le dit.
 *
 * @param int $jours
 * @return array
 */
function dashboard_waf_serie_parc($jours = _DASHBOARD_WAF_FENETRE) {
	$depuis = date('Y-m-d', time() - (max(1, (int) $jours) - 1) * 86400);

	$lignes = sql_allfetsel(
		['jour', 'SUM(requetes) AS requetes', 'SUM(ips) AS ips'],
		'spip_dashboard_waf_jours',
		'jour >= ' . sql_quote($depuis),
		'jour',
		'jour'
	);

	$connus = [];
	foreach ((array) $lignes as $ligne) {
		$connus[(string) $ligne['jour']] = $ligne;
	}

	return dashboard_waf_completer($connus, $jours);
}

/**
 * Une série en JSON, à déposer dans la page.
 *
 * Les quatre drapeaux d'échappement ne sont pas décoratifs : sans `JSON_HEX_TAG`,
 * une valeur contenant `</script>` refermerait le bloc et le reste passerait pour
 * du HTML. Ici les valeurs sont des entiers et des dates validées, mais la
 * protection ne coûte rien et survivra au prochain champ ajouté.
 *
 * @param array $points
 * @return string
 */
function dashboard_waf_json($points) {
	return (string) json_encode(
		['points' => array_values((array) $points)],
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
	);
}

/**
 * Le parc compte-t-il au moins un site équipé du WAF ?
 *
 * Sert à n'afficher l'encadré de tendance que s'il a quelque chose à montrer.
 *
 * @return bool
 */
function dashboard_waf_parc_present() {
	return (bool) sql_countsel('spip_dashboard_waf_jours');
}
