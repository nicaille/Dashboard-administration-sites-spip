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
// Les filtres du catalogue vivent dans ce fichier : SPIP ne charge que
// `<prefixe>_fonctions.php`, et un filtre resté dans un `inc/` est introuvable.
include_spip('inc/dashboard_catalogue');
// Idem pour l'annuaire des versions de SPIP, dont les squelettes lisent l'état.
include_spip('inc/dashboard_spip_api');
// Et pour les lots de mises à jour de plugins, dont la page lit le verdict.
include_spip('inc/dashboard_lots');

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
 * **La liste est celle que le plugin déclare, jamais une liste écrite ici.**
 * Elle en portait une, arrêtée à quatre tables quand le plugin en compte sept :
 * `chantiers`, `waf_jours` et `push` manquaient à l'appel. Une base amputée de
 * l'une des trois passait donc pour saine — les pages s'affichaient
 * normalement, et l'absence ne se manifestait que par une « erreur mysql
 * 1146 » au fond d'un journal, sans rien pour la relier à une installation
 * incomplète. Vu en vrai.
 *
 * Une liste de tables écrite à la main est une liste qui ne suit pas : elle ne
 * se met pas à jour quand une table s'ajoute, et rien ne le signale. Celle des
 * déclarations est la seule qui ne puisse pas se désynchroniser.
 *
 * @filtre
 * @return bool
 */
function dashboard_tables_presentes($rien = '') {
	static $presentes = null;

	if ($presentes === null) {
		include_spip('base/tourdecontrole_tables');

		$attendues = function_exists('tourdecontrole_descriptions_tables')
			? array_keys(tourdecontrole_descriptions_tables())
			: [];

		$liste = sql_alltable('%');
		$liste = is_array($liste) ? array_flip($liste) : [];

		// Sans déclaration lisible, on ne conclut pas à l'absence : mieux vaut
		// laisser les pages s'afficher que les masquer sur une liste vide.
		$presentes = true;
		foreach ($attendues as $table) {
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
 * Adresse du `spip_check.php` d'un site géré, à partir de son adresse publique.
 *
 * Même fabrication que pour le spip_loader, et même réserve : c'est un lien de
 * navigation vers le site géré, pas une adresse d'action. Il n'ouvre rien tout
 * seul — SPIP Check n'accepte qu'un administrateur connecté **sur ce site-là**.
 *
 * @filtre
 * @param string $url Adresse publique du site
 * @return string
 */
function dashboard_url_check($url) {
	$url = trim((string) $url);
	if ($url === '' || !preg_match('#^https?://#i', $url)) {
		return '';
	}

	$url = preg_replace('/[?#].*$/', '', $url);

	return rtrim($url, '/') . '/spip_check.php';
}

/**
 * L'adresse d'où SPIP Check est téléchargé, ou la chaîne vide s'il est éteint.
 *
 * Seul réglage du parc qui ne passe pas par `dashboard_config()`, et pour une
 * raison précise : celle-ci traite une valeur vide comme « non réglée » et rend
 * le défaut. C'est le bon comportement partout ailleurs — un délai vide n'est
 * pas un délai de zéro —, mais il rendrait ce champ-ci impossible à vider,
 * alors que l'encadré promet qu'un champ vide ne propose aucun dépôt.
 *
 * On distingue donc les deux : clé absente, c'est le défaut ; clé présente et
 * vide, c'est un refus délibéré. Le vrai interrupteur reste ailleurs et par
 * site — l'autorisation `op_check` chez le site géré —, mais un parc qui ne veut
 * pas du tout de cette fonction doit pouvoir la faire taire d'un geste.
 *
 * Sans paramètre, parce que `#VAL|nom` passe la chaîne vide.
 *
 * @filtre
 * @return string
 */
function dashboard_url_check_source() {
	include_spip('inc/config');
	include_spip('inc/dashboard_operations');

	$reglee = lire_config('dashboard/url_spip_check', null);

	return trim((string) ($reglee === null ? _DASHBOARD_CHECK_URL : $reglee));
}

/**
 * L'adresse de l'annuaire des versions de SPIP, ou la chaîne vide s'il est éteint.
 *
 * Même exception que ci-dessus, et pour la même raison : le champ doit pouvoir
 * être vidé. Un parc qui n'interroge aucun service extérieur s'en tient alors
 * aux versions qu'il impose à la main, et la tour répond « je ne sais pas »
 * plutôt que d'affirmer que tout le monde est à jour.
 *
 * Il n'y a qu'un seul lecteur, et le formulaire comme l'annuaire l'appellent :
 * deux défauts écrits à deux endroits finissent toujours par diverger — la
 * synchronisation de fond l'a déjà payé.
 *
 * Aucun squelette ne l'appelle en filtre : ce n'est pas un oubli. L'adresse d'un
 * service extérieur n'a rien à faire dans une page de l'espace privé, et le
 * formulaire de configuration l'affiche déjà dans son champ.
 *
 * @return string
 */
function dashboard_url_versions_api() {
	include_spip('inc/dashboard_spip_api');
	include_spip('inc/config');

	$reglee = lire_config('dashboard/url_versions_spip', null);

	return trim((string) ($reglee === null ? _DASHBOARD_VERSIONS_API : $reglee));
}

/**
 * L'empreinte SHA-256 à laquelle le livrable de SPIP Check doit répondre.
 *
 * Facultative, et vide par défaut : l'adresse par défaut désigne une branche,
 * dont le contenu change à chaque poussée amont, et une épingle posée d'office
 * bloquerait le dépôt au premier commit venu. Elle a son emploi là où le parc
 * décide ce qu'il déploie : un miroir interne, une release, une version relue.
 *
 * Aucun paramètre, parce que SPIP l'appelle en `#VAL|dashboard_empreinte_check`
 * et lui passe donc une chaîne vide.
 *
 * @return string Chaîne vide si aucune épingle n'est posée
 */
function dashboard_empreinte_check() {
	include_spip('inc/config');

	return strtolower(trim((string) lire_config('dashboard/sha256_spip_check', '')));
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
			'core_bloque' => 0, 'core_inconnu' => 0, 'base_retard' => 0, 'plugins_maj' => 0];
	}

	$synthese = [
		'sites'        => (int) sql_countsel('spip_dashboard_sites', 'statut != ' . sql_quote('poubelle')),
		'supervises'   => (int) sql_countsel('spip_dashboard_sites', 'statut = ' . sql_quote('publie')),
		'en_erreur'    => (int) sql_countsel('spip_dashboard_sites', ['statut = ' . sql_quote('publie'), 'etat = ' . sql_quote('erreur')]),
		'core_a_jour'  => 0,
		// Les quatre états se comptent sur `core_etat`, et sur lui seul. Déduire
		// « à jour » de « tout le parc moins les retards » comptait pour sain un
		// site bloqué par son PHP **et** un site dont on ne savait rien : c'est
		// exactement ce qui a fait passer un parc entier pour à jour pendant que
		// sa source de versions répondait 500.
		'core_retard'  => dashboard_synthese_core('retard'),
		'core_bloque'  => dashboard_synthese_core('bloque'),
		'core_inconnu' => dashboard_synthese_core('inconnu'),
		// Un site dont la base attend sa migration répond encore, mais son espace
		// privé est bloqué : c'est un retard d'une autre nature que celui du core.
		'base_retard'  => (int) sql_countsel('spip_dashboard_sites', ['statut = ' . sql_quote('publie'), 'base_maj = ' . sql_quote('oui')]),
		'plugins_maj'  => 0,
	];

	$synthese['core_a_jour'] = dashboard_synthese_core('a_jour');

	$total = sql_fetsel('SUM(nb_plugins_maj) AS total', 'spip_dashboard_sites', 'statut = ' . sql_quote('publie'));
	$synthese['plugins_maj'] = (int) ($total['total'] ?? 0);

	return $synthese;
}

/**
 * Combien de sites supervisés sont dans cet état de core.
 *
 * Un site qui n'a pas été synchronisé depuis la migration porte l'état par
 * défaut, « inconnu » : c'est exact, et la synchronisation suivante le corrige.
 *
 * @param string $etat
 * @return int
 */
function dashboard_synthese_core($etat) {
	return (int) sql_countsel('spip_dashboard_sites', [
		'statut = ' . sql_quote('publie'),
		'core_etat = ' . sql_quote($etat),
	]);
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
 * La fenêtre de jours à tracer, bornée — et jamais vide.
 *
 * `#VAL|dashboard_waf_serie_parc` ne transmet pas « rien » : SPIP compile cet
 * appel en `dashboard_waf_serie_parc('')`, et une valeur par défaut déclarée
 * dans la signature ne sert alors à rien, puisque l'argument est bel et bien
 * passé. La chaîne vide valait zéro, ramené à un par le plancher : la tendance
 * du parc ne traçait qu'**un seul point**, celui du jour, et deux courbes
 * vides s'affichaient sans que rien ne signale l'erreur.
 *
 * On traite donc l'absence de valeur ici plutôt que dans la signature.
 * `dashboard_synthese($rien = '')` prend la même précaution pour la même
 * raison.
 *
 * @param int|string $jours
 * @return int
 */
function dashboard_waf_fenetre($jours) {
	$jours = (int) $jours;
	if ($jours <= 0) {
		$jours = _DASHBOARD_WAF_FENETRE;
	}

	return max(1, min(365, $jours));
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
	$jours   = dashboard_waf_fenetre($jours);
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
	$jours  = dashboard_waf_fenetre($jours);
	$depuis = date('Y-m-d', time() - ($jours - 1) * 86400);

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
 * Le paramètre s'appelle `$rien` et ne sert à rien, comme dans les autres
 * filtres appelés `#VAL|nom` : SPIP compile cet appel en passant la chaîne
 * vide, et un premier paramètre qui aurait un sens la recevrait. C'est ce qui
 * est arrivé — un `$jours` valant `''`, donc zéro, donc une fenêtre d'un seul
 * jour, et deux courbes vides sur la vue d'ensemble.
 *
 * La fenêtre est toujours la plus large : c'est le sélecteur, dans la page,
 * qui y découpe sept ou trente jours sans rien redemander.
 *
 * @param string $rien Ignoré (voir ci-dessus)
 * @return array
 */
function dashboard_waf_serie_parc($rien = '') {
	$jours  = dashboard_waf_fenetre(0);
	$depuis = date('Y-m-d', time() - ($jours - 1) * 86400);

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

/**
 * Le préfixe du plugin agent, tel que les sites gérés le déclarent.
 *
 * Écrit ici et nulle part ailleurs. C'est une constante fragile par nature : le
 * jour où l'agent changera de préfixe — ce qui est déjà arrivé une fois —, rien
 * ici ne lèvera d'erreur. La requête ne trouverait simplement aucun site à
 * mettre à jour, et la vue d'ensemble n'afficherait plus le bouton : exactement
 * la faute qui a laissé « Version de l'agent : 0 » à l'écran pendant des
 * semaines.
 *
 * `tests/test_structure.php` relit donc cette valeur dans le `paquet.xml` de
 * l'agent et refuse qu'elles divergent.
 *
 * Les préfixes sont mis en capitales par l'agent avant d'être rendus
 * (`dashagent_plugins()`), et rangés tels quels.
 *
 * @return string
 */
function dashboard_prefixe_agent() {
	return 'TOURDECONTROLE_AGENT';
}

/**
 * Ce site attend-il une mise à jour de son agent ?
 *
 * Le critère est celui du décompte affiché par le parc : une mise à jour
 * annoncée, et un plugin qui n'est pas livré avec SPIP. L'agent ne l'est jamais,
 * mais la condition ne coûte rien et garde les deux lectures accordées.
 *
 * @param int $id_dashboard_site
 * @return bool
 */
function dashboard_agent_maj_attendue($id_dashboard_site) {
	return (bool) sql_countsel('spip_dashboard_plugins', [
		'id_dashboard_site = ' . (int) $id_dashboard_site,
		'prefixe = ' . sql_quote(dashboard_prefixe_agent()),
		'maj_disponible = ' . sql_quote('oui'),
		'distribue = ' . sql_quote('non'),
	]);
}

/**
 * Les sites supervisés dont l'agent attend une mise à jour.
 *
 * Seuls les sites publiés : un site en pause ne se touche pas, et un site à la
 * poubelle encore moins.
 *
 * @return array Identifiants, dans l'ordre du titre
 */
function dashboard_agent_maj_sites() {
	if (!dashboard_tables_presentes()) {
		return [];
	}

	$lignes = sql_allfetsel(
		'p.id_dashboard_site AS id',
		'spip_dashboard_plugins AS p INNER JOIN spip_dashboard_sites AS s ON s.id_dashboard_site = p.id_dashboard_site',
		[
			'p.prefixe = ' . sql_quote(dashboard_prefixe_agent()),
			'p.maj_disponible = ' . sql_quote('oui'),
			'p.distribue = ' . sql_quote('non'),
			's.statut = ' . sql_quote('publie'),
		],
		'',
		's.titre'
	);

	return array_map('intval', array_column($lignes, 'id'));
}

/**
 * Combien de sites attendent une mise à jour de leur agent ?
 *
 * @return int
 */
function dashboard_agent_maj_parc() {
	return count(dashboard_agent_maj_sites());
}

/**
 * Le libellé du bouton de mise à jour de l'agent, décompte compris.
 *
 * Pourquoi un filtre plutôt qu'un `#SET` dans le squelette : la forme
 * `#VAL{clef}|_T{#ARRAY{nb,#BALISE}}` veut une balise **simple** à la place du
 * nombre. Y glisser un `#GET{…}` y ajoute des accolades imbriquées, que
 * l'analyse ne suit plus — « Argument manquant dans la balise SET », et la page
 * entière tombe. Le décompte venant d'une fonction et non d'un champ de boucle,
 * autant faire la chaîne de langue ici.
 *
 * @return string
 */
function dashboard_agent_maj_libelle() {
	return _T('dashboard:action_agent_parc', ['nb' => dashboard_agent_maj_parc()]);
}

/**
 * La demande de confirmation qui va avec, même raison.
 *
 * @return string
 */
function dashboard_agent_maj_confirmation() {
	return _T('dashboard:confirmer_agent_parc', ['nb' => dashboard_agent_maj_parc()]);
}

/**
 * La clef publique VAPID du parc, pour l'abonnement d'un navigateur.
 *
 * Fabriquée au premier appel et jamais regénérée : les abonnements pris par
 * les navigateurs sont liés à la clef qui leur a été présentée, et en changer
 * les invaliderait tous d'un coup — sans que rien ne le signale avant la
 * première alerte non reçue.
 *
 * @filtre
 * @param string $rien SPIP passe une chaîne vide à `#VAL|filtre`
 * @return string Base64url, ou chaîne vide si OpenSSL ne sait pas faire de P-256
 */
function dashboard_push_clef_parc($rien = '') {
	include_spip('inc/dashboard_alertes');
	$vapid = dashboard_push_vapid();

	return (string) ($vapid['publique'] ?? '');
}

/**
 * La portée à déclarer pour le service worker des alertes.
 *
 * @filtre
 * @param string $rien
 * @return string
 */
function dashboard_push_portee($rien = '') {
	include_spip('action/dashboard_sw');

	return dashboard_sw_portee();
}

/**
 * Combien de navigateurs sont abonnés aux alertes ?
 *
 * @filtre
 * @param string $rien
 * @return int
 */
function dashboard_push_abonnes($rien = '') {
	if (!dashboard_tables_presentes()) {
		return 0;
	}

	return (int) sql_countsel('spip_dashboard_push');
}

/**
 * Résumé de ce qu'une alerte dirait en l'état, pour l'afficher sans l'envoyer.
 *
 * Voir ce que le parc écrirait *avant* de brancher le canal vaut mieux que de
 * le découvrir dans sa boîte : c'est aussi le seul moyen de vérifier qu'une
 * alerte partirait, sur un parc où justement tout va bien.
 *
 * @filtre
 * @param string $rien
 * @return string
 */
function dashboard_alerte_apercu($rien = '') {
	include_spip('inc/dashboard_alertes');
	$message = dashboard_alerte_texte(dashboard_alerte_etat());

	return (string) $message['court'];
}

/**
 * Les sites retenus par le filtre du journal, en tableau d'identifiants.
 *
 * Le critère `?IN` de SPIP a une exigence qui ne se voit pas : une valeur qui
 * n'est **pas un tableau** est poussée telle quelle dans la liste
 * (`critere_IN_cas()`, la branche `is_array()`). Lui donner la chaîne « 3,7 »
 * chercherait donc un identifiant valant littéralement « 3,7 », et la boucle
 * ne rendrait rien — sans erreur, sans message. D'où un vrai tableau.
 *
 * Tableau vide = aucun filtre : `?IN` laisse alors passer tout le monde, la
 * condition étant retirée quand l'argument est vide.
 *
 * Les identifiants sont filtrés par l'autorisation, et pas seulement validés :
 * une adresse forgée ne doit pas donner à lire le journal d'un site qu'on n'a
 * pas le droit de voir. C'est la même règle que pour les files d'actions du
 * parc — ce qui vient de l'URL désigne, il n'autorise pas.
 *
 * @filtre
 * @param string|array $choix Ce que porte l'URL
 * @return array
 */
function dashboard_journal_sites($choix = '') {
	include_spip('inc/autoriser');

	if (is_array($choix)) {
		$demandes = $choix;
	} else {
		$demandes = preg_split('/[\s,]+/', (string) $choix, -1, PREG_SPLIT_NO_EMPTY);
	}

	$retenus = [];
	foreach ($demandes as $id) {
		$id = (int) $id;
		if ($id > 0 && autoriser('voir', 'dashboard_site', $id)) {
			$retenus[] = $id;
		}
	}

	$retenus = array_values(array_unique($retenus));

	// Voir `dashboard_journal_liste()` : demandé mais vide doit rendre une
	// liste vide, pas le parc entier. Zéro ne désigne aucun site.
	if (!$retenus && $demandes) {
		return [0];
	}

	return $retenus;
}

/**
 * La date plancher du filtre, au format que SQL comprend.
 *
 * Toujours une date, jamais une chaîne vide : voir ci-dessous.
 *
 * @filtre
 * @param string $choix Nombre de jours, ou date
 * @return string
 */
function dashboard_journal_depuis($choix = '') {
	// Le critère de période est **inconditionnel**. Sa forme conditionnelle
	// testerait la variable d'environnement `date`, que l'URL ne porte pas :
	// la condition serait retirée et le filtre ne jouerait jamais. On pose
	// donc toujours un plancher, et l'époque zéro quand rien n'est demandé.
	//
	// Et l'on n'écrit pas ici la syntaxe de cette forme conditionnelle : la
	// séquence qui ferme une balise PHP ferme aussi un commentaire `//`, et
	// tout ce qui suit sort du code. C'est ce qui vient d'arriver — accolade
	// jamais refermée, « Unclosed '{' » signalé quatre-vingts lignes plus
	// bas, sur une fonction sans rapport.
	$plancher = '1970-01-01 00:00:00';

	$choix = trim((string) $choix);
	if ($choix === '') {
		return $plancher;
	}

	// Un nombre de jours — la forme qu'emploient les liens de la page.
	if (ctype_digit($choix)) {
		$jours = max(1, min(3650, (int) $choix));

		return date('Y-m-d H:i:s', time() - ($jours * 86400));
	}

	// Une date saisie à la main. Refusée plutôt que devinée si elle ne se lit
	// pas : un plancher mal compris masquerait des lignes sans le dire.
	$horodatage = strtotime($choix);

	return $horodatage ? date('Y-m-d H:i:s', $horodatage) : $plancher;
}

/**
 * Les opérations réellement présentes dans le journal, pour peupler le choix.
 *
 * On lit ce qui existe plutôt que d'énumérer ce qui pourrait exister : une
 * liste figée proposerait des filtres qui ne rendent jamais rien, et tairait
 * une opération ajoutée depuis.
 *
 * @filtre
 * @param string $rien
 * @return array
 */
function dashboard_journal_operations($rien = '') {
	if (!dashboard_tables_presentes()) {
		return [];
	}

	$lignes = sql_allfetsel(
		'DISTINCT operation',
		'spip_dashboard_journal',
		'',
		'',
		'operation'
	);

	return array_values(array_filter(array_column($lignes, 'operation')));
}

/**
 * Une valeur de filtre, rendue en tableau pour le critère `?IN`.
 *
 * Tous les filtres du journal passent par `?IN`, et pas seulement ceux qui
 * acceptent plusieurs valeurs. La raison n'est pas l'uniformité : le critère
 * facultatif simple — `{statut ?}` — lit **le contexte**, où `statut`,
 * `id_auteur` ou `operation` peuvent déjà valoir quelque chose sans qu'on
 * l'ait demandé. La page filtrerait alors sur une valeur qu'aucun lien n'a
 * posée, et rien ne le dirait. `?IN` sur un tableau qu'on a nous-même calculé
 * ne dépend que de ce qu'on lui donne.
 *
 * @param string|array $choix
 * @param array $permises Valeurs acceptées ; toutes si le tableau est vide
 * @return array
 */
function dashboard_journal_liste($choix, $permises = [], $sentinelle = '__aucun__') {
	if (is_array($choix)) {
		$demandes = $choix;
	} else {
		$demandes = preg_split('/[\s,]+/', (string) $choix, -1, PREG_SPLIT_NO_EMPTY);
	}

	$retenus = [];
	foreach ($demandes as $valeur) {
		$valeur = trim((string) $valeur);
		if ($valeur === '') {
			continue;
		}
		if ($permises && !in_array($valeur, $permises, true)) {
			continue;
		}
		$retenus[] = $valeur;
	}

	$retenus = array_values(array_unique($retenus));

	// **Demandé mais vide n'est pas « rien demandé ».** Un tableau vide retire
	// la condition `?IN`, donc afficherait *tout* — l'inverse exact de ce qu'on
	// veut quand l'utilisateur a nommé des sites qu'il n'a pas le droit de
	// voir, ou une valeur qui n'existe pas. La sentinelle ne correspond à
	// rien, et la liste ressort vide comme elle le doit.
	//
	// Trouvé par le parcours d'intégration : `?sites[]=99999` rendait
	// cinquante lignes. Rien sur la page ne le disait — une liste complète a
	// exactement l'air d'une liste non filtrée.
	if (!$retenus && $demandes) {
		return [$sentinelle];
	}

	return $retenus;
}

/**
 * Les statuts retenus par le filtre.
 *
 * @filtre
 * @param string|array $choix
 * @return array
 */
function dashboard_journal_statuts($choix = '') {
	return dashboard_journal_liste($choix, ['ok', 'erreur']);
}

/**
 * Les opérations retenues par le filtre, validées contre ce qui existe.
 *
 * @filtre
 * @param string|array $choix
 * @return array
 */
function dashboard_journal_filtre_operations($choix = '') {
	return dashboard_journal_liste($choix, dashboard_journal_operations());
}

/**
 * Les auteurs retenus par le filtre.
 *
 * @filtre
 * @param string|array $choix
 * @return array
 */
function dashboard_journal_auteurs($choix = '') {
	$demandes = dashboard_journal_liste($choix, [], '0');

	$retenus = [];
	foreach ($demandes as $valeur) {
		if (ctype_digit((string) $valeur) && (int) $valeur > 0) {
			$retenus[] = (int) $valeur;
		}
	}

	// Même règle : un auteur demandé mais inexploitable ne doit pas ouvrir le
	// journal en grand.
	if (!$retenus && $demandes) {
		return [0];
	}

	return $retenus;
}

/**
 * Le libellé qui annonce la rétention, décompte compris.
 *
 * En PHP et non dans le squelette : une chaîne de langue à argument écrite
 * dans un `#SET` rend une chaîne vide, et `#ARRAY{nb,#GET{x}}` ajoute un
 * niveau d'accolades que l'analyse ne suit plus — « Argument manquant dans la
 * balise SET », et la page entière tombe.
 *
 * @filtre
 * @param string $rien
 * @return string
 */
function dashboard_journal_retention_libelle($rien = '') {
	return _T('dashboard:journal_retention', ['nb' => dashboard_journal_retention()]);
}

/**
 * Combien de jours le journal est-il conservé ?
 *
 * Affiché avec la liste : sans cela, la purge d'entretien se confond avec un
 * trou, et l'on cherche une panne là où il n'y a qu'une rétention.
 *
 * @filtre
 * @param string $rien
 * @return int
 */
function dashboard_journal_retention($rien = '') {
	return max(1, (int) dashboard_config('retention_journal', 180));
}

/**
 * Le verdict d'un lot de mises à jour de plugins, prêt pour l'affichage.
 *
 * Un seul appel par page, et le résultat est mémorisé : la page le relit pour
 * l'entête, pour la liste des sites, et pour décider si le lot est achevé.
 *
 * Le succès est constaté sur l'inventaire relu **maintenant**, et non sur ce
 * qu'a répondu l'appel qui a mis à jour. Un site qui se tait pendant qu'il se
 * remplace lui-même est le cas normal, pas une panne.
 *
 * @filtre
 * @param string $lot
 * @return array
 */
function dashboard_lot_bilan($lot) {
	static $memoire = [];
	$lot = (string) $lot;
	if (isset($memoire[$lot])) {
		return $memoire[$lot];
	}

	include_spip('inc/dashboard_lots');
	$chantiers = dashboard_lot_chantiers($lot);

	return $memoire[$lot] = $chantiers
		? dashboard_lot_verdict($chantiers, dashboard_lot_en_retard())
		: [];
}

/**
 * Le lot est-il achevé, tous chantiers confondus ?
 *
 * @filtre
 * @param string $lot
 * @return bool
 */
function dashboard_lot_est_acheve($lot) {
	include_spip('inc/dashboard_lots');

	return dashboard_lot_acheve(dashboard_lot_bilan((string) $lot));
}

/**
 * Le lot achevé n'a-t-il que des succès ?
 *
 * @filtre
 * @param string $lot
 * @return bool
 */
function dashboard_lot_est_reussi($lot) {
	include_spip('inc/dashboard_lots');
	$bilan = dashboard_lot_bilan((string) $lot);

	return dashboard_lot_acheve($bilan) && dashboard_lot_sans_echec($bilan);
}

/**
 * Le compte rendu d'un lot, en une phrase.
 *
 * Composé en PHP et non par une chaîne de langue posée dans un `#SET` : une
 * chaîne à arguments écrite directement dans un `#SET` rend une chaîne vide, et
 * `#ARRAY{nb,#GET{x}}` ajoute un niveau d'accolades que l'analyse ne suit plus.
 *
 * @filtre
 * @param string $lot
 * @return string
 */
function dashboard_lot_resume($lot) {
	include_spip('inc/dashboard_lots');
	$bilan = dashboard_lot_bilan((string) $lot);
	if (!$bilan) {
		return '';
	}

	$reussis = 0;
	$rates   = 0;
	$restants = 0;
	foreach ($bilan as $ligne) {
		$reussis += count($ligne['reussis']);
		$rates   += count($ligne['rates']);
		if (empty($ligne['fini'])) {
			$restants++;
		}
	}

	if ($restants) {
		return _T('dashboard:lot_en_cours', ['nb' => $restants, 'sites' => count($bilan)]);
	}

	return $rates
		? _T('dashboard:lot_bilan_mixte', ['ok' => $reussis, 'ko' => $rates])
		: _T('dashboard:lot_bilan_ok', ['nb' => $reussis, 'sites' => count($bilan)]);
}

/**
 * Les sites d'un lot qui ont reçu au moins une mise à jour.
 *
 * Rendu en tableau, pour la boucle DATA de l'écran de résultat.
 *
 * @filtre
 * @param string $lot
 * @return array
 */
function dashboard_lot_touches($lot) {
	include_spip('inc/dashboard_lots');

	return dashboard_lot_sites_touches(dashboard_lot_bilan((string) $lot));
}

/**
 * La ligne de bilan d'un site dans un lot.
 *
 * @filtre
 * @param string $lot
 * @param int $id_site
 * @return array
 */
function dashboard_lot_site($lot, $id_site) {
	foreach (dashboard_lot_bilan((string) $lot) as $ligne) {
		if ((int) $ligne['id_dashboard_site'] === (int) $id_site) {
			return $ligne;
		}
	}

	return [];
}

/**
 * Combien de mises à jour de plugins le parc attend-il, tous sites confondus ?
 *
 * @filtre
 * @param string $rien Chaîne vide que SPIP passe à `#VAL|nom`
 * @return int
 */
function dashboard_plugins_en_retard($rien = '') {
	if (!dashboard_tables_presentes()) {
		return 0;
	}

	include_spip('inc/dashboard_lots');
	$total = 0;
	foreach (dashboard_lot_en_retard() as $prefixes) {
		$total += count($prefixes);
	}

	return $total;
}

/**
 * L'adresse de la gestion des plugins d'un site géré, pour un lien d'échec.
 *
 * @filtre
 * @param string $url_site
 * @return string
 */
function dashboard_url_plugins_site($url_site) {
	include_spip('inc/dashboard_lots');

	return dashboard_lot_url_plugins((string) $url_site);
}

/**
 * Les identifiants des sites d'un lot, pour le critère IN de la boucle.
 *
 * @filtre
 * @param string $lot
 * @return array
 */
function dashboard_lot_ids($lot) {
	$ids = [];
	foreach (dashboard_lot_bilan((string) $lot) as $ligne) {
		$ids[] = (int) $ligne['id_dashboard_site'];
	}

	return $ids;
}

/**
 * Le jeton de lot d'une adresse, ou la chaîne vide s'il n'en a pas la forme.
 *
 * Rendu en filtre plutôt que contrôlé dans le squelette : la règle est une
 * expression régulière à quantificateur, et les accolades d'un quantificateur
 * ferment l'argument d'un filtre SPIP. `|match{^[0-9a-f]{16}$}` produisait un
 * « Filtre $ non défini » sur une page qui par ailleurs fonctionnait.
 *
 * @filtre
 * @param string $jeton
 * @return string
 */
function dashboard_lot_jeton_lu($jeton) {
	include_spip('inc/dashboard_lots');

	return dashboard_lot_jeton_valide($jeton) ? trim((string) $jeton) : '';
}
