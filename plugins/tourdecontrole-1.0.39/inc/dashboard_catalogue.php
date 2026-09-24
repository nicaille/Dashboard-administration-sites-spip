<?php
/**
 * Le catalogue de la tour, et la confrontation des deux points de vue.
 *
 * Jusqu'ici, tout ce que la tour savait des versions **disponibles** venait du
 * catalogue SVP de chaque site géré. Elle dépendait donc d'autant de caches
 * distants qu'elle a de sites — et c'est la source directe de la faute la plus
 * coûteuse du projet : un catalogue périmé fait annoncer « à jour » un site qui
 * ne l'est pas, sans que rien ne le signale.
 *
 * La tour est elle-même un site SPIP muni de SVP. Elle peut donc tenir son
 * propre catalogue et **confronter** les deux avis. Le rafraîchissement distant
 * reste en place : c'est SVP sur le site géré qui installe, et il lui faut son
 * catalogue à jour au moment d'agir.
 *
 * Ce qui est gagné n'est pas du réseau — les sites relisent toujours leurs
 * catalogues — mais de la justesse : le décompte du parc cesse de dépendre de
 * caches qu'on ne contrôle pas, et un catalogue périmé devient visible.
 *
 * Les fonctions qui **décident** sont pures : elles ne touchent ni au réseau ni
 * à la base, et se vérifient donc sans rien monter.
 *
 * @package SPIP\Dashboard\Catalogue
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Ramène l'adresse d'un catalogue à une racine comparable.
 *
 * SVP ne télécharge pas l'adresse qu'on lui déclare : il en dérive des
 * variantes — `…/plugins.thin.spip-4.4.xml`, `…/plugins.thin.xml`, puis
 * l'originale — et retient la première qui répond. Deux sites peuvent donc
 * avoir mémorisé deux `xml_paquets` différents pour le **même dépôt**, selon
 * leur branche SPIP et l'ordre dans lequel le serveur leur a répondu.
 *
 * Comparer les adresses telles quelles déclarerait donc « inconnus » des dépôts
 * que la tour connaît parfaitement. On compare le répertoire, pas le fichier.
 *
 * Limite assumée : deux dépôts distincts servis depuis un même répertoire
 * seraient confondus. Ça ne s'est jamais vu, et le prix de l'erreur inverse est
 * bien plus élevé.
 *
 * @param string $url
 * @return string Chaîne vide si l'adresse n'est pas exploitable
 */
function dashboard_catalogue_racine($url) {
	$url = trim((string) $url);
	if ($url === '') {
		return '';
	}

	$morceaux = parse_url($url);
	if (!is_array($morceaux) || empty($morceaux['host'])) {
		return '';
	}

	$schema = strtolower((string) ($morceaux['scheme'] ?? 'https'));
	$hote   = strtolower((string) $morceaux['host']);
	$port   = isset($morceaux['port']) ? ':' . (int) $morceaux['port'] : '';
	$chemin = (string) ($morceaux['path'] ?? '');

	// Le nom du fichier de catalogue est la partie qui varie : on l'écarte.
	$segments = explode('/', $chemin);
	$dernier  = (string) array_pop($segments);
	if ($dernier !== '' && !preg_match('/\.xml$/i', $dernier)) {
		// Pas un fichier de catalogue : c'est un répertoire, on le garde.
		$segments[] = $dernier;
	}

	$chemin = rtrim(implode('/', $segments), '/');

	return $schema . '://' . $hote . $port . $chemin;
}

/**
 * Rend à une version SVP sa forme lisible.
 *
 * **SVP stocke les versions normalisées** : `spip_paquets.version` vaut
 * `001.000.039` pour la version 1.0.39, un remplissage à gauche qui sert au
 * tri SQL. Lire cette colonne telle quelle afficherait `001.000.039` au
 * webmestre, et ferait reposer toute comparaison sur la tolérance numérique de
 * `version_compare()` — laquelle marche par chance, pas par construction.
 *
 * Trouvé par le parcours d'intégration, qui a vu la version normalisée
 * atteindre l'écran. Rien, dans la lecture du code, ne disait que cette colonne
 * n'était pas ce qu'elle a l'air d'être.
 *
 * Même règle que `denormaliser_version()` de SVP, réécrite ici pour rester
 * pure : un suffixe commence toujours par un tiret sur une version normalisée,
 * et ne doit pas se faire manger son zéro.
 *
 * @param string $version
 * @return string
 */
function dashboard_catalogue_denormaliser($version) {
	$version = trim((string) $version);
	if ($version === '') {
		return '';
	}

	$bouts = [];
	foreach (explode('.', $version) as $nombre) {
		$net = ltrim($nombre, '0');
		$bouts[] = ($net !== '' && substr($net, 0, 1) !== '-') ? $net : '0' . $net;
	}

	return implode('.', $bouts);
}

/**
 * La branche SPIP d'un numéro de version complet.
 *
 * « 4.4.23 » vaut la branche « 4.4 ». C'est elle que porte la compatibilité
 * déclarée par les paquets d'un catalogue.
 *
 * @param string $version
 * @return string Chaîne vide si on ne sait pas lire la version
 */
function dashboard_catalogue_branche($version) {
	$version = trim((string) $version);
	if (!preg_match('/^(\d+)\.(\d+)/', $version, $trouve)) {
		return '';
	}

	return $trouve[1] . '.' . $trouve[2];
}

/**
 * Ce paquet est-il compatible avec cette branche de SPIP ?
 *
 * SVP range les branches d'un paquet dans une chaîne séparée par des virgules.
 * Une liste vide vaut « on ne sait pas », et **on ne conclut pas à
 * l'incompatibilité** : écarter un paquet dont on ignore la compatibilité
 * reviendrait à cacher une mise à jour qui existe peut-être.
 *
 * @param string $branches Ce que déclare le paquet, « 4.0,4.1,4.2 »
 * @param string $branche La branche du site
 * @return bool
 */
function dashboard_catalogue_compatible($branches, $branche) {
	$branches = trim((string) $branches);
	$branche  = trim((string) $branche);

	if ($branches === '' || $branche === '') {
		return true;
	}

	foreach (explode(',', $branches) as $candidate) {
		if (trim($candidate) === $branche) {
			return true;
		}
	}

	return false;
}

/**
 * La version la plus élevée d'un paquet, compatible avec une branche donnée.
 *
 * C'est **le** point critique de toute la fonction. « Disponible » n'est pas
 * une propriété globale d'un plugin : Saisies 6.3.6 peut exister sans qu'un
 * site en SPIP 4.1 puisse l'installer. Prendre le numéro le plus élevé sans
 * filtrer reviendrait à annoncer une mise à jour que le site refusera, c'est-à-
 * dire à retomber sur le travers qu'on veut précisément corriger.
 *
 * @param array $paquets Chacun : ['version' => …, 'branches' => …]
 * @param string $branche
 * @return string Chaîne vide si aucun paquet ne convient
 */
function dashboard_catalogue_version_max($paquets, $branche) {
	$retenue = '';

	foreach ((array) $paquets as $paquet) {
		if (!is_array($paquet)) {
			continue;
		}
		$version = trim((string) ($paquet['version'] ?? ''));
		if ($version === '') {
			continue;
		}
		if (!dashboard_catalogue_compatible($paquet['branches'] ?? '', $branche)) {
			continue;
		}
		if ($retenue === '' || version_compare($version, $retenue, '>')) {
			$retenue = $version;
		}
	}

	return $retenue;
}

/**
 * Qui a raison, la tour ou le site ?
 *
 * La règle, et elle tient en trois lignes :
 *
 * - quand la tour a un avis, il l'emporte. C'est le seul choix qui rend le
 *   total du parc juste sur un site dont le catalogue est mort ;
 * - quand elle n'en a pas — le plugin vient d'un dépôt qu'elle ne déclare
 *   pas —, on **garde l'avis du site** plutôt que de le jeter, en disant d'où
 *   il vient. Répondre « je ne sais pas » alors que le site, lui, sait, serait
 *   une perte sèche ;
 * - un plugin livré avec le core n'est jamais compté : sa version vient du
 *   noyau, pas d'une mise à jour de plugin.
 *
 * Rend la provenance en clair, parce qu'un chiffre sans sa source est un
 * chiffre auquel on ne peut rien opposer.
 *
 * @param string $installee Version installée sur le site
 * @param string $tour Ce que dit le catalogue de la tour, vide si sans avis
 * @param string $site Ce que dit le catalogue du site
 * @param bool $distribue Le plugin est-il livré avec le core ?
 * @return array{version: string, provenance: string, maj: bool}
 */
function dashboard_catalogue_verdict($installee, $tour, $site, $distribue = false) {
	$installee = trim((string) $installee);
	$tour      = trim((string) $tour);
	$site      = trim((string) $site);

	$version    = $tour !== '' ? $tour : $site;
	$provenance = $tour !== '' ? 'tour' : ($site !== '' ? 'site' : '');

	$maj = false;
	if (!$distribue && $version !== '' && $installee !== '') {
		$maj = version_compare($version, $installee, '>');
	}

	return [
		'version'    => $version,
		'provenance' => $provenance,
		'maj'        => $maj,
	];
}

/**
 * Les dépôts d'un site que la tour ne connaît pas.
 *
 * Comparaison sur la racine du catalogue, jamais sur le titre : le titre est
 * choisi par qui déclare le dépôt, et deux sites nomment rarement le même
 * dépôt de la même façon.
 *
 * @param array $depots Ce que l'agent a remonté pour ce site
 * @param array $racines Les racines que la tour déclare
 * @return array Les dépôts inconnus, tels que remontés
 */
function dashboard_catalogue_depots_inconnus($depots, $racines) {
	$connues  = array_flip(array_filter((array) $racines));
	$inconnus = [];

	foreach ((array) $depots as $depot) {
		if (!is_array($depot)) {
			continue;
		}
		$racine = dashboard_catalogue_racine($depot['source'] ?? '');
		if ($racine === '' || isset($connues[$racine])) {
			continue;
		}
		$inconnus[] = $depot;
	}

	return $inconnus;
}

/**
 * Le catalogue de la tour, indexé par préfixe.
 *
 * Lecture locale des tables de SVP. Aucun client HTTP à écrire, aucun format à
 * analyser : SVP a déjà fait le travail, et il gère les branches.
 *
 * Rend un tableau vide quand SVP n'est pas là, ce qui doit valoir « je n'ai pas
 * d'avis » et jamais « rien n'est disponible ».
 *
 * @return array prefixe => [ ['version' => …, 'branches' => …], … ]
 */
function dashboard_catalogue_tour() {
	static $catalogue = null;

	if ($catalogue !== null) {
		return $catalogue;
	}

	$catalogue = [];
	if (!dashboard_catalogue_table_existe('spip_paquets')) {
		return $catalogue;
	}

	$lignes = sql_allfetsel(
		['prefixe', 'version', 'branches_spip'],
		'spip_paquets',
		'id_depot > 0'
	);

	foreach ((array) $lignes as $ligne) {
		$prefixe = strtolower(trim((string) ($ligne['prefixe'] ?? '')));
		if ($prefixe === '') {
			continue;
		}
		$catalogue[$prefixe][] = [
			// Dénormalisée : la colonne porte un remplissage à gauche.
			'version'  => dashboard_catalogue_denormaliser($ligne['version'] ?? ''),
			'branches' => (string) ($ligne['branches_spip'] ?? ''),
		];
	}

	return $catalogue;
}

/**
 * Les racines de catalogue que la tour déclare.
 *
 * @return array
 */
function dashboard_catalogue_racines_tour() {
	static $racines = null;

	if ($racines !== null) {
		return $racines;
	}

	$racines = [];
	if (!dashboard_catalogue_table_existe('spip_depots')) {
		return $racines;
	}

	foreach ((array) sql_allfetsel(['xml_paquets'], 'spip_depots') as $depot) {
		$racine = dashboard_catalogue_racine($depot['xml_paquets'] ?? '');
		if ($racine !== '') {
			$racines[] = $racine;
		}
	}

	$racines = array_values(array_unique($racines));

	return $racines;
}

/**
 * La tour a-t-elle un catalogue exploitable ?
 *
 * Sans lui, la fonction s'éteint proprement plutôt que de déclarer tout le parc
 * « sans avis », ce qui serait un mensonge par omission. La vue d'ensemble le
 * dit alors en clair, avec l'adresse à déclarer.
 *
 * @filtre
 * @return bool
 */
function dashboard_catalogue_present($rien = '') {
	return (bool) dashboard_catalogue_tour();
}

/**
 * Cette table existe-t-elle ?
 *
 * SVP peut ne pas être installé sur la tour : on interroge ses tables sans
 * supposer qu'elles sont là.
 *
 * @param string $table
 * @return bool
 */
function dashboard_catalogue_table_existe($table) {
	static $connues = null;

	if ($connues === null) {
		$liste   = sql_alltable('%');
		$connues = is_array($liste) ? array_flip($liste) : [];
	}

	return isset($connues[$table]);
}

/**
 * Confronte l'inventaire d'un site au catalogue de la tour.
 *
 * Appelée à la synchronisation : ce qu'elle décide est mémorisé, donc lisible
 * par les alertes, qui tournent sans page.
 *
 * @param array $plugins Inventaire remonté par l'agent
 * @param string $version_spip Version SPIP du site géré
 * @return array Le même inventaire, chaque plugin portant son verdict
 */
function dashboard_catalogue_confronter($plugins, $version_spip) {
	$catalogue = dashboard_catalogue_tour();
	$branche   = dashboard_catalogue_branche($version_spip);

	foreach ((array) $plugins as $rang => $plugin) {
		if (!is_array($plugin)) {
			continue;
		}

		$prefixe = strtolower(trim((string) ($plugin['prefixe'] ?? '')));
		$tour    = '';
		if ($prefixe !== '' && isset($catalogue[$prefixe])) {
			$tour = dashboard_catalogue_version_max($catalogue[$prefixe], $branche);
		}

		$verdict = dashboard_catalogue_verdict(
			(string) ($plugin['version'] ?? ''),
			$tour,
			(string) ($plugin['version_disponible'] ?? ''),
			!empty($plugin['distribue'])
		);

		$plugins[$rang]['version_retenue'] = $verdict['version'];
		$plugins[$rang]['provenance']   = $verdict['provenance'];
		$plugins[$rang]['maj_verdict']  = $verdict['maj'];
	}

	return $plugins;
}

/**
 * Les copies locales du catalogue d'un dépôt, toutes variantes confondues.
 *
 * Même mécanique que sur un site géré, et pour la même raison : SVP dérive des
 * variantes de l'adresse déclarée et chacune a sa propre copie sous
 * `IMG/distant/`. Les chercher toutes, pas seulement celle qu'on croit
 * employée.
 *
 * @param string $url
 * @return array Chemins relatifs à la racine
 */
function dashboard_catalogue_copies($url) {
	include_spip('inc/distant');
	// SVP peut ne pas être là : on charge sans supposer, et chaque appel reste
	// gardé par un function_exists().
	include_spip('inc/svp_depoter_distant');
	$url = trim((string) $url);
	if ($url === '' || !function_exists('fichier_copie_locale')) {
		return [];
	}

	$urls = [$url];
	if (function_exists('svp_depoter_distant_variantes_url')) {
		$branche = dashboard_catalogue_branche((string) ($GLOBALS['spip_version_branche'] ?? ''));
		$urls = array_merge($urls, array_values((array) svp_depoter_distant_variantes_url($url, $branche)));
	}

	$copies = [];
	foreach (array_unique($urls) as $adresse) {
		$copie = (string) fichier_copie_locale($adresse);
		if ($copie !== '') {
			$copies[$copie] = $copie;
		}
	}

	return array_values($copies);
}

/**
 * Force la relecture du catalogue de la tour.
 *
 * Sans ce forçage, la tour hériterait du verrou que le dépôt consigne : une
 * copie locale dont la date avance à chaque vérification finit par dépasser
 * celle du catalogue réellement publié, et `copie_locale()` répond 304 pour
 * toujours. Le remède est le même ici que là-bas : **effacer la copie locale
 * avant d'appeler SVP**, pour qu'il n'ait plus de date à comparer.
 *
 * L'enjeu est plus grand que sur un site géré. Un catalogue de tour périmé ne
 * ferait pas mentir un site, il ferait mentir **tout le parc d'un coup**.
 *
 * @return array{depots: int, telecharges: int}
 */
function dashboard_catalogue_rafraichir() {
	$rapport = ['depots' => 0, 'telecharges' => 0];

	include_spip('inc/svp_depoter_distant');

	if (!dashboard_catalogue_table_existe('spip_depots')
		|| !function_exists('svp_actualiser_depot')) {
		return $rapport;
	}

	$racine = defined('_DIR_RACINE') ? _DIR_RACINE : '';

	foreach ((array) sql_allfetsel(['id_depot', 'xml_paquets'], 'spip_depots') as $depot) {
		$url = (string) ($depot['xml_paquets'] ?? '');
		if ($url === '') {
			continue;
		}
		$rapport['depots']++;

		$copies = dashboard_catalogue_copies($url);
		foreach ($copies as $copie) {
			if (is_file($racine . $copie)) {
				@unlink($racine . $copie);
			}
		}

		svp_actualiser_depot((int) $depot['id_depot']);

		// On va constater plutôt que conclure : la copie ayant été effacée
		// avant l'appel, une copie revenue prouve le téléchargement, et rien
		// de revenu prouve le contraire — quoi que SVP ait répondu.
		foreach ($copies as $copie) {
			if (is_file($racine . $copie)) {
				$rapport['telecharges']++;
				break;
			}
		}
	}

	return $rapport;
}

/**
 * Les dépôts du parc que la tour ne connaît pas, et qui s'en sert.
 *
 * Regroupés par racine de catalogue plutôt que répétés site par site : dix
 * sites partageant un dépôt privé ne posent qu'une question, pas dix.
 *
 * Le titre et l'adresse viennent des sites gérés. Ils sont déjà rendus inertes
 * à l'enregistrement de l'inventaire ; ils portent quand même `|entites_html`
 * à l'affichage, comme tout ce qui vient de là-bas.
 *
 * @return array racine => ['titre' => …, 'source' => …, 'sites' => [id => titre]]
 */
function dashboard_depots_inconnus_parc() {
	include_spip('inc/dashboard_client');

	$racines  = dashboard_catalogue_racines_tour();
	$inconnus = [];

	$sites = sql_allfetsel(
		['id_dashboard_site', 'titre', 'infos'],
		'spip_dashboard_sites',
		'statut = ' . sql_quote('publie')
	);

	foreach ((array) $sites as $site) {
		$depots = dashboard_info((string) ($site['infos'] ?? ''), 'depots');
		if (!is_array($depots)) {
			continue;
		}

		foreach (dashboard_catalogue_depots_inconnus($depots, $racines) as $depot) {
			$racine = dashboard_catalogue_racine($depot['source'] ?? '');
			if (!isset($inconnus[$racine])) {
				$inconnus[$racine] = [
					'titre'  => (string) ($depot['titre'] ?? ''),
					'source' => $racine,
					'sites'  => [],
				];
			}
			$inconnus[$racine]['sites'][(int) $site['id_dashboard_site']] = (string) $site['titre'];
		}
	}

	return $inconnus;
}

/**
 * Les dépôts inconnus, pour le squelette.
 *
 * La liste des sites est composée ici, et non dans le squelette : une chaîne
 * de langue à argument dont la valeur vient d'une fonction ne s'écrit pas en
 * `#ARRAY{clef,#GET{x}}` — l'analyse ne suit plus ce niveau d'accolades, et la
 * page entière tombe sur un « Argument manquant ».
 *
 * @filtre
 * @return array
 */
function dashboard_depots_inconnus($rien = '') {
	$liste = [];
	foreach (dashboard_depots_inconnus_parc() as $depot) {
		$depot['libelle_sites'] = _T('dashboard:depots_inconnus_sites', [
			'sites' => implode(', ', $depot['sites']),
		]);
		$liste[] = $depot;
	}

	return $liste;
}

/**
 * « dont N rapportée(s) par les sites », ou rien s'il n'y en a aucune.
 *
 * Composée en PHP pour la même raison que ci-dessus.
 *
 * @filtre
 * @return string
 */
function dashboard_maj_rapportees_libelle($rien = '') {
	$nb = dashboard_maj_rapportees_sites();
	if ($nb < 1) {
		return '';
	}

	return _T('dashboard:synthese_dont_sites', ['nb' => $nb]);
}

/**
 * Combien de mises à jour du parc ne sont rapportées que par les sites.
 *
 * Un chiffre agrégé sans mention de sa source laisse croire à une source
 * unique. Le total du parc dit donc la part qu'il doit aux sites eux-mêmes,
 * faute d'avis de la tour sur ces plugins-là.
 *
 * @filtre
 * @return int
 */
function dashboard_maj_rapportees_sites($rien = '') {
	return (int) sql_countsel(
		'spip_dashboard_plugins AS p JOIN spip_dashboard_sites AS s ON s.id_dashboard_site = p.id_dashboard_site',
		'p.provenance = ' . sql_quote('site')
			. ' AND p.distribue = ' . sql_quote('non')
			. ' AND p.maj_disponible = ' . sql_quote('oui')
			. ' AND s.statut = ' . sql_quote('publie')
	);
}
