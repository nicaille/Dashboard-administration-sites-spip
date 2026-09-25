<?php
/**
 * Mettre à jour en un geste des plugins choisis sur plusieurs sites.
 *
 * Le parc savait déjà mettre à jour **un** plugin sur **un** site, et **tous**
 * les plugins d'un site. Ce qui manquait est entre les deux, et c'est le cas
 * courant : dix sites portent le même plugin en retard, on veut les prendre
 * ensemble, sans pour autant tout mettre à jour partout.
 *
 * Un **lot** est un jeton qui relie les chantiers nés d'une même sélection. Il
 * n'apporte aucun pouvoir : il sert à retrouver l'écran de résultat par son
 * adresse, y compris après avoir fermé l'onglet — les chantiers, eux,
 * continuent sous le cron.
 *
 * La règle qui gouverne tout ce fichier est celle des cases à cocher du parc :
 * **ce qui vient du navigateur désigne, il n'autorise pas.** Chaque couple
 * site-plugin reçu est revalidé contre l'inventaire et contre `autoriser()`,
 * et ce qui ne s'y retrouve pas est écarté sans bruit — exactement comme un
 * site coché absent de la file d'une action de parc.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Nombre maximal de couples site-plugin qu'un seul lot peut porter.
 *
 * Une borne, pas un plafond arbitraire : chaque site du lot reçoit une
 * sauvegarde complète et un inventaire, et un lot de plusieurs centaines
 * d'entrées tiendrait des heures sans que personne ne puisse le suivre.
 */
if (!defined('_DASHBOARD_LOT_MAX')) {
	define('_DASHBOARD_LOT_MAX', 200);
}

/**
 * Lit ce que le navigateur a coché.
 *
 * Chaque case porte `<id_site>:<PREFIXE>`. Rien de plus : ni version visée, ni
 * adresse. La version est redéduite au moment d'agir, par l'étape du chantier,
 * sur un inventaire tout juste rafraîchi — celle qu'affichait la page a pu
 * changer entre le clic et l'exécution.
 *
 * Fonction pure : elle ne touche ni la base ni les autorisations. C'est ce qui
 * la rend éprouvable, et c'est pour cela que la validation est ailleurs.
 *
 * @param mixed $poste Ce que rend `_request('maj')`
 * @return array Couples `[id_site => [PREFIXE, …]]`, préfixes dédoublonnés
 */
function dashboard_lot_lire($poste) {
	$choix = [];
	if (!is_array($poste)) {
		return $choix;
	}

	$vus = 0;
	foreach ($poste as $entree) {
		if (!is_string($entree) || strpos($entree, ':') === false) {
			continue;
		}
		[$id, $prefixe] = explode(':', $entree, 2);
		$id      = (int) $id;
		$prefixe = strtoupper(trim($prefixe));

		// Le préfixe d'un plugin SPIP est alphanumérique, souligné compris —
		// `porte_plume`, livré avec SPIP, en porte un. Tout le reste est écarté :
		// ce qui vient du navigateur finira dans une requête et dans un message.
		if ($id <= 0 || !preg_match('/^[A-Z0-9_]{1,64}$/', $prefixe)) {
			continue;
		}
		if (++$vus > _DASHBOARD_LOT_MAX) {
			break;
		}
		if (!isset($choix[$id])) {
			$choix[$id] = [];
		}
		if (!in_array($prefixe, $choix[$id], true)) {
			$choix[$id][] = $prefixe;
		}
	}

	ksort($choix);
	foreach ($choix as &$prefixes) {
		sort($prefixes);
	}

	return $choix;
}

/**
 * Ne retient d'une sélection que ce que l'inventaire connaît comme en retard.
 *
 * Trois raisons d'écarter un couple, et la page n'en propose aucun :
 *
 * - le plugin n'existe pas sur ce site, ou n'y est pas en retard : l'adresse a
 *   été forgée, ou l'inventaire a changé depuis l'affichage ;
 * - le plugin est **distribué avec SPIP** : le mettre à jour séparément n'a pas
 *   de sens, et c'est le core qui s'en charge ;
 * - le site n'est pas opérable par cet utilisateur.
 *
 * On écarte sans message, comme un site coché absent de la file d'une action de
 * parc : le compte rendu dira combien de couples ont été retenus, et l'écart se
 * lit là.
 *
 * @param array $choix Sortie de dashboard_lot_lire()
 * @param array $connus Ce que l'inventaire déclare en retard : `[id_site => [PREFIXE, …]]`
 * @param array $operables Identifiants de sites que l'utilisateur peut opérer
 * @return array Couples retenus, même forme
 */
function dashboard_lot_valider($choix, $connus, $operables) {
	$retenus = [];
	$choix   = is_array($choix) ? $choix : [];

	foreach ($choix as $id => $prefixes) {
		$id = (int) $id;
		if (!in_array($id, array_map('intval', (array) $operables), true)) {
			continue;
		}
		$disponibles = (array) ($connus[$id] ?? []);
		$garde = array_values(array_intersect((array) $prefixes, $disponibles));
		if ($garde) {
			$retenus[$id] = $garde;
		}
	}

	return $retenus;
}

/**
 * Ce jeton a-t-il la forme d'un lot ?
 *
 * Un seul lecteur de cette règle, et il est en PHP. La tentation était de la
 * poser dans le squelette avec `|match` : impossible, et silencieusement — les
 * accolades d'un quantificateur ferment l'argument du filtre, et ce qui suit est
 * pris pour un nom de filtre. « Filtre `$` non défini », sur une page qui par
 * ailleurs fonctionne.
 *
 * @param string $jeton
 * @return bool
 */
function dashboard_lot_jeton_valide($jeton) {
	return (bool) preg_match('/^[0-9a-f]{16}$/', trim((string) $jeton));
}

/**
 * Un jeton de lot.
 *
 * Aléatoire, et non une séquence : il voyage dans une adresse, et un identifiant
 * qui s'incrémente inviterait à essayer le voisin. Il ne donne de toute façon
 * accès à rien — la page qui le lit revalide chaque site par `autoriser()`.
 *
 * @return string
 */
function dashboard_lot_jeton() {
	return bin2hex(random_bytes(8));
}

/**
 * Ce que l'inventaire déclare en retard, pour tout le parc.
 *
 * Les plugins distribués avec SPIP sont écartés ici : le core les remplace, et
 * les proposer séparément ferait échouer l'opération à coup sûr.
 *
 * L'avis peut venir de la tour ou du site lui-même — depuis la 1.0.39, la
 * confrontation des catalogues range la provenance à côté du verdict. On ne
 * filtre pas là-dessus : écarter ce que seul le site annonce cacherait des
 * mises à jour réelles sur un parc dont la tour ne déclare pas le dépôt. La
 * page affiche la provenance, et le webmestre tranche.
 *
 * @return array `[id_site => [PREFIXE, …]]`
 */
function dashboard_lot_en_retard() {
	$lignes = sql_allfetsel(
		'id_dashboard_site, prefixe',
		'spip_dashboard_plugins',
		[
			'maj_disponible = ' . sql_quote('oui'),
			'distribue = ' . sql_quote('non'),
		],
		'',
		'id_dashboard_site, prefixe'
	);

	$retard = [];
	foreach ($lignes as $ligne) {
		$retard[(int) $ligne['id_dashboard_site']][] = strtoupper((string) $ligne['prefixe']);
	}

	return $retard;
}

/**
 * Les sites du parc que cet utilisateur peut opérer.
 *
 * @return array Identifiants
 */
function dashboard_lot_sites_operables() {
	include_spip('inc/autoriser');

	$lignes = sql_allfetsel(
		'id_dashboard_site',
		'spip_dashboard_sites',
		'statut = ' . sql_quote('publie'),
		'',
		'id_dashboard_site'
	);

	$operables = [];
	foreach ($lignes as $ligne) {
		$id = (int) $ligne['id_dashboard_site'];
		if (autoriser('operer', 'dashboard_site', $id)) {
			$operables[] = $id;
		}
	}

	return $operables;
}

/**
 * Ouvre un lot : un chantier par site, la file posée à la création.
 *
 * Un site déjà occupé par une autre opération n'est pas forcé — deux chantiers
 * de front sur le même site se marcheraient dessus. Il est **dit**, et non
 * silencieusement omis : le webmestre a coché ses plugins et doit savoir
 * lesquels attendent encore.
 *
 * @param array $retenus Sortie de dashboard_lot_valider()
 * @return array{lot: string, ouverts: array, refuses: array, plugins: int}
 */
function dashboard_lot_ouvrir($retenus) {
	include_spip('inc/dashboard_chantiers');

	$lot     = dashboard_lot_jeton();
	$ouverts = [];
	$refuses = [];
	$plugins = 0;

	foreach ((array) $retenus as $id => $prefixes) {
		$id = (int) $id;
		$ouverture = dashboard_chantier_creer($id, 'plugin_maj_choix', count($prefixes) . ' plugin(s)', [
			'lot'   => $lot,
			'reste' => implode(',', $prefixes),
		]);

		if (empty($ouverture['ok'])) {
			$refuses[$id] = (string) $ouverture['message'];
			continue;
		}
		$ouverts[$id] = (int) $ouverture['id'];
		$plugins += count($prefixes);
	}

	return ['lot' => $lot, 'ouverts' => $ouverts, 'refuses' => $refuses, 'plugins' => $plugins];
}

/**
 * Les chantiers d'un lot, réduits à ceux que l'utilisateur peut voir.
 *
 * Le jeton vient de l'adresse : il **désigne** le lot, il n'ouvre aucun droit.
 * Chaque site est donc repassé par `autoriser()`, sans quoi un jeton deviné ou
 * transmis donnerait à lire l'avancement de sites qu'on n'a pas le droit de
 * voir.
 *
 * @param string $lot
 * @return array
 */
function dashboard_lot_chantiers($lot) {
	include_spip('inc/autoriser');

	$lot = trim((string) $lot);
	if (!dashboard_lot_jeton_valide($lot)) {
		return [];
	}

	$lignes = sql_allfetsel(
		'*',
		'spip_dashboard_chantiers',
		'lot = ' . sql_quote($lot),
		'',
		'id_dashboard_chantier'
	);

	return array_values(array_filter($lignes, function ($ligne) {
		return autoriser('voir', 'dashboard_site', (int) $ligne['id_dashboard_site']);
	}));
}

/**
 * Le verdict d'un lot, plugin par plugin.
 *
 * **Le succès se constate, il ne se déduit pas du retour de l'appel.** Un site
 * qui se tait pendant qu'il se met à jour lui-même est le cas normal, pas une
 * panne : `dashboard_chantier_etape_plugins()` ne retient déjà pas un silence
 * comme un échec, et laisse l'inventaire final trancher. Ici on va au bout de
 * cette logique — un plugin est à jour si l'inventaire d'après ne le déclare
 * plus en retard, quoi qu'ait répondu l'appel qui l'a mis à jour.
 *
 * L'échec est donc doublement établi : le chantier a retenu une erreur **et**
 * le plugin est toujours en retard. Un plugin dont le chantier a signalé une
 * erreur mais qui est passé à la bonne version compte pour un succès, parce
 * que c'est ce que le site montre.
 *
 * @param array $chantiers Sortie de dashboard_lot_chantiers()
 * @param array $retard Ce que l'inventaire déclare **maintenant** en retard
 * @return array Une entrée par site
 */
function dashboard_lot_verdict($chantiers, $retard) {
	$bilan = [];

	foreach ((array) $chantiers as $chantier) {
		$id     = (int) $chantier['id_dashboard_site'];
		$demande = dashboard_lot_demande($chantier);
		$echecs  = [];
		$detail  = json_decode((string) ($chantier['detail'] ?? ''), true);
		if (is_array($detail) && is_array($detail['echecs'] ?? null)) {
			$echecs = array_change_key_case($detail['echecs'], CASE_UPPER);
		}

		$encore  = array_map('strtoupper', (array) ($retard[$id] ?? []));
		$reussis = [];
		$rates   = [];

		foreach ($demande as $prefixe) {
			if (!in_array($prefixe, $encore, true)) {
				$reussis[] = $prefixe;
			} else {
				$rates[$prefixe] = (string) ($echecs[$prefixe] ?? '');
			}
		}

		$bilan[] = [
			'id_dashboard_site' => $id,
			'id_chantier'       => (int) $chantier['id_dashboard_chantier'],
			'statut'            => (string) $chantier['statut'],
			'fini'              => in_array((string) $chantier['statut'], ['fini', 'erreur'], true),
			'demandes'          => $demande,
			'reussis'           => $reussis,
			'rates'             => $rates,
		];
	}

	return $bilan;
}

/**
 * La liste des préfixes qu'un chantier avait à traiter.
 *
 * Elle n'est plus dans `reste` une fois la file épuisée : ce qui a été demandé
 * se relit donc dans le compte rendu, où l'étape la range. À défaut — un
 * chantier interrompu très tôt —, `reste` porte encore la file, et elle fait
 * l'affaire.
 *
 * @param array $chantier
 * @return array
 */
function dashboard_lot_demande($chantier) {
	$detail = json_decode((string) ($chantier['detail'] ?? ''), true);
	if (is_array($detail) && is_array($detail['demandes'] ?? null)) {
		return array_values(array_map('strtoupper', $detail['demandes']));
	}

	return array_values(array_filter(array_map(
		'strtoupper',
		explode(',', (string) ($chantier['reste'] ?? ''))
	)));
}

/**
 * L'adresse de la gestion des plugins d'un site géré.
 *
 * `?exec=admin_plugin` existe sur tout SPIP, avec ou sans SVP : c'est de là
 * qu'on voit l'état réel et qu'on active ou désactive. Pointer la page de SVP
 * serait plus direct là où il est installé, et tomberait en erreur précisément
 * sur les sites où une mise à jour a le plus de raisons d'avoir échoué.
 *
 * @param string $url_site
 * @return string Chaîne vide si l'adresse du site n'est pas exploitable
 */
function dashboard_lot_url_plugins($url_site) {
	$url_site = trim((string) $url_site);
	if (!preg_match('#^https?://#i', $url_site)) {
		return '';
	}

	return rtrim($url_site, '/') . '/ecrire/?exec=admin_plugin';
}

/**
 * Un lot est-il achevé ?
 *
 * @param array $bilan Sortie de dashboard_lot_verdict()
 * @return bool
 */
function dashboard_lot_acheve($bilan) {
	foreach ((array) $bilan as $ligne) {
		if (empty($ligne['fini'])) {
			return false;
		}
	}

	return (bool) $bilan;
}

/**
 * Un lot achevé n'a-t-il que des succès ?
 *
 * Rendre faux sur un lot inachevé serait un contresens : on ne sait pas encore.
 * L'appelant regarde d'abord `dashboard_lot_acheve()`.
 *
 * @param array $bilan
 * @return bool
 */
function dashboard_lot_sans_echec($bilan) {
	foreach ((array) $bilan as $ligne) {
		if (!empty($ligne['rates'])) {
			return false;
		}
	}

	return true;
}

/**
 * Les sites d'un lot qui ont reçu au moins une mise à jour.
 *
 * Ce sont eux, et eux seuls, qu'il y a lieu de resynchroniser : un site dont
 * rien n'a bougé n'a pas d'inventaire à rafraîchir.
 *
 * @param array $bilan
 * @return array Identifiants
 */
function dashboard_lot_sites_touches($bilan) {
	$sites = [];
	foreach ((array) $bilan as $ligne) {
		if (!empty($ligne['reussis'])) {
			$sites[] = (int) $ligne['id_dashboard_site'];
		}
	}

	return array_values(array_unique($sites));
}
