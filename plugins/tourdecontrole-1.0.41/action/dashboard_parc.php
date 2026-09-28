<?php
/**
 * Une opération de parc, sur un seul site, rendue en JSON.
 *
 * Faire le tour du parc en une requête est hors de question : un catalogue de
 * dépôt pèse plusieurs méga-octets, une synchronisation prend jusqu'à trente
 * secondes, et une mise à jour de plugin bien davantage. Cette action traite
 * **un seul site** et rend la main ; c'est le navigateur qui enchaîne, site
 * après site, en montrant où il en est.
 *
 * L'argument a la forme `operation/id_site`. Cinq opérations :
 *
 * - `depots` : relit le catalogue des dépôts du site, puis recompte ;
 * - `sync` : relit l'inventaire ;
 * - `purger` : vide les caches ;
 * - `agent_maj` : met l'agent à jour, en menant un chantier étape par étape ;
 * - `core_maj` : met SPIP lui-même à jour, de la même façon.
 *
 * Toutes rendent le même contrat : `ok`, `termine`, `site`, `message`. Tant que
 * `termine` est faux, le pilote rappelle **la même adresse** — c'est ainsi
 * qu'une opération en plusieurs temps tient dans une boucle qui n'en sait rien.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashboard_parc_dist() {
	include_spip('inc/autoriser');
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_operations');
	include_spip('inc/dashboard_sync');
	// dashboard_prefixe_agent(), dashboard_agent_maj_attendue() et
	// dashboard_libelle_etape() y vivent : ce fichier n'est chargé tout seul que
	// pour les squelettes.
	include_spip('tourdecontrole_fonctions');

	$securiser_action = charger_fonction('securiser_action', 'inc');
	$arg = (string) $securiser_action();

	// L'argument portait naguère le seul identifiant : une adresse signée de ce
	// temps-là désignerait aujourd'hui une opération nommée par un nombre.
	// Aucune n'est mémorisée — elles sont refabriquées à chaque affichage de la
	// page —, mais une forme inconnue se refuse plutôt que de se deviner.
	$morceaux  = explode('/', $arg);
	$operation = (string) array_shift($morceaux);
	$id_site   = (int) array_shift($morceaux);

	$site = $id_site ? dashboard_charger_site($id_site) : null;
	if (!$site) {
		dashboard_parc_repondre(['ok' => false, 'termine' => true, 'message' => 'Site inconnu']);
	}

	// Lire l'état d'un site et agir dessus ne relèvent pas du même droit.
	$droit = ($operation === 'sync') ? 'synchroniser' : 'operer';
	if (!autoriser($droit, 'dashboard_site', $id_site)) {
		dashboard_parc_repondre([
			'ok'      => false,
			'termine' => true,
			'site'    => (string) $site['titre'],
			'message' => (string) $site['titre'] . ' : opération non autorisée',
		]);
	}

	// Une étape distante peut prendre plusieurs minutes — la sauvegarde qui
	// ouvre une mise à jour, surtout. Le temps d'exécution par défaut ne
	// suffirait pas, et la boucle s'arrêterait sans explication.
	@set_time_limit(600);

	switch ($operation) {
		case 'depots':
			dashboard_parc_depots($site);
			break;

		case 'sync':
			dashboard_parc_sync($site);
			break;

		case 'purger':
			dashboard_parc_purger($site);
			break;

		case 'agent_maj':
			dashboard_parc_agent_maj($site);
			break;

		case 'core_maj':
			dashboard_parc_core_maj($site);
			break;
	}

	dashboard_parc_repondre([
		'ok'      => false,
		'termine' => true,
		'site'    => (string) $site['titre'],
		'message' => (string) $site['titre'] . ' : opération inconnue',
	]);
}

/**
 * Relit le catalogue des dépôts d'un site, un dépôt par appel.
 *
 * Rien n'est modifié sur le site géré : un catalogue se régénère à volonté.
 * Interrompre la boucle ne laisse donc rien à moitié fait — chaque site est
 * relu ou ne l'est pas, et la synchronisation de fond rattrapera le reste.
 *
 * @param array $site
 * @return void
 */
function dashboard_parc_depots($site) {
	$id_site = (int) $site['id_dashboard_site'];
	$titre   = (string) $site['titre'];

	// Le core a son catalogue lui aussi, et « relire les dépôts » le désigne
	// autant que ceux de SVP. Une fois pour toute la salve : l'annuaire est
	// global, et dix sites cochés ne valent pas dix appels à spip.net.
	include_spip('inc/dashboard_spip_api');
	dashboard_api_forcer_une_fois();

	$reponse = dashboard_operation_depots_actualiser($id_site, 0);

	if (empty($reponse['ok'])) {
		// Un site sans SVP n'a pas de catalogue : ce n'est pas un échec, il n'y
		// a rien à relire chez lui.
		$absent = ((string) ($reponse['data']['raison'] ?? '') === 'svp_absent');

		dashboard_parc_repondre([
			'ok'      => $absent,
			'termine' => true,
			'site'    => $titre,
			'message' => $absent
				? $titre . ' : pas de dépôt à relire'
				: $titre . ' : ' . (string) $reponse['message'],
		]);
	}

	$data = (array) $reponse['data'];
	if (empty($data['termine'])) {
		dashboard_parc_repondre([
			'ok'      => true,
			'termine' => false,
			'site'    => $titre,
			'message' => $titre . ' : dépôt « ' . (string) ($data['actualise'] ?? '') . ' » relu, reste '
				. (int) ($data['reste'] ?? 0),
		]);
	}

	// Le catalogue est à jour : reste à recompter. Sans dépôts, puisqu'on vient
	// de les relire — les repasser en revue ne ferait que perdre du temps.
	$inventaire = dashboard_synchroniser($id_site, ['sans_depots' => true]);
	$apres = dashboard_charger_site($id_site);

	// Un catalogue que l'agent n'a pas réellement rapatrié rend le décompte qui
	// suit sans valeur : le dire vaut mieux que d'annoncer un chiffre faux.
	$constat = dashboard_depots_constat($data);

	dashboard_parc_repondre([
		'ok'      => !empty($inventaire['ok']) && $constat === '',
		'termine' => true,
		'site'    => $titre,
		'plugins_maj' => (int) ($apres['nb_plugins_maj'] ?? 0),
		'message' => empty($inventaire['ok'])
			? $titre . ' : ' . (string) ($inventaire['message'] ?? 'inventaire impossible')
			: $titre . ' : ' . (int) ($apres['nb_plugins_maj'] ?? 0) . ' mise(s) à jour de plugins'
				. ($constat !== '' ? ' — ' . $constat : ''),
	]);
}

/**
 * Relit l'inventaire d'un site.
 *
 * @param array $site
 * @return void
 */
function dashboard_parc_sync($site) {
	$titre = (string) $site['titre'];
	$resultat = dashboard_synchroniser((int) $site['id_dashboard_site']);

	dashboard_parc_repondre([
		'ok'      => !empty($resultat['ok']),
		'termine' => true,
		'site'    => $titre,
		'message' => empty($resultat['ok'])
			? $titre . ' : ' . (string) ($resultat['message'] ?? 'sans réponse')
			: $titre . ' : inventaire mis à jour',
	]);
}

/**
 * Vide les caches d'un site.
 *
 * @param array $site
 * @return void
 */
function dashboard_parc_purger($site) {
	$titre = (string) $site['titre'];
	$resultat = dashboard_operation_purger((int) $site['id_dashboard_site'], ['tout']);

	dashboard_parc_repondre([
		'ok'      => !empty($resultat['ok']),
		'termine' => true,
		'site'    => $titre,
		'message' => $titre . ' : ' . (string) ($resultat['message'] ?: 'sans réponse'),
	]);
}

/**
 * Met l'agent d'un site à jour, une étape de chantier par appel.
 *
 * C'est une mise à jour de plugin ordinaire — même chantier, mêmes étapes, même
 * sauvegarde préalable —, à ceci près que le plugin visé est celui qui répond.
 * L'agent se remplace donc lui-même pendant qu'il nous parle, et le site se tait
 * au beau milieu de l'opération. Ce silence est prévu de longue date : voir
 * `dashboard_chantier_plugin_verifier()`, qui va constater plutôt que conclure.
 *
 * Aucun chantier n'est ouvert si le site n'a rien à mettre à jour : ouvrir un
 * chantier pour le voir s'arrêter à l'étape des plugins ferait une sauvegarde
 * pour rien, sur chaque site du parc.
 *
 * @param array $site
 * @return void
 */
function dashboard_parc_agent_maj($site) {
	include_spip('inc/dashboard_chantiers');

	$id_site = (int) $site['id_dashboard_site'];
	$titre   = (string) $site['titre'];

	$chantier = dashboard_chantier_courant($id_site);

	if (!$chantier) {
		if (!dashboard_agent_maj_attendue($id_site)) {
			dashboard_parc_repondre([
				'ok'      => true,
				'termine' => true,
				'site'    => $titre,
				'message' => $titre . ' : agent déjà à jour',
			]);
		}

		$ouverture = dashboard_chantier_creer($id_site, 'plugin_maj', dashboard_prefixe_agent());
		if (empty($ouverture['ok'])) {
			dashboard_parc_repondre([
				'ok'      => false,
				'termine' => true,
				'site'    => $titre,
				'message' => $titre . ' : ' . (string) $ouverture['message'],
			]);
		}
		$chantier = dashboard_chantier_charger((int) $ouverture['id']);
	} elseif ((string) $chantier['cible'] !== dashboard_prefixe_agent()) {
		// Un chantier déjà ouvert sur ce site n'est pas le nôtre : on ne le
		// pousse pas, et on ne le remplace pas non plus.
		dashboard_parc_repondre([
			'ok'      => false,
			'termine' => true,
			'site'    => $titre,
			'message' => $titre . ' : une autre opération est en cours — '
				. dashboard_chantier_resume($chantier),
		]);
	}

	if (!dashboard_chantier_fini($chantier)) {
		$chantier = dashboard_chantier_avancer((int) $chantier['id_dashboard_chantier']);
	}

	$fini = dashboard_chantier_fini($chantier);
	$etapes = dashboard_chantier_etapes((string) $chantier['operation']);
	$total  = max(1, count($etapes));
	$rang   = min((int) $chantier['rang'] + 1, $total);

	dashboard_parc_repondre([
		'ok'      => ((string) $chantier['statut'] !== 'erreur'),
		'termine' => $fini,
		'site'    => $titre,
		'message' => $fini
			? $titre . ' : ' . (string) $chantier['message']
			: $titre . ' (' . $rang . '/' . $total . ') '
				. dashboard_libelle_etape((string) $chantier['etape']),
	]);
}

/**
 * Met SPIP lui-même à jour sur un site, une étape de chantier par appel.
 *
 * La mise à jour la plus lourde du parc : sauvegarde complète, contrôles
 * préalables, remplacement du noyau, migration de la base, inventaire. Elle se
 * conduit donc exactement comme celle de l'agent — un chantier, poussé étape
 * par étape par le navigateur —, et le contrat de réponse est le même.
 *
 * La disponibilité se vérifie **ici**, au moment d'agir, et non dans la file
 * que compose le squelette. Deux raisons, et la seconde compte davantage :
 *
 * - la colonne `core_maj` date du dernier inventaire du site, quand la liste
 *   des versions amont, elle, a pu être rafraîchie depuis. Redéduire la cible
 *   de `version_spip` répond de l'état d'aujourd'hui ;
 * - bâtir la file sur le drapeau reviendrait à confier à une valeur périmée le
 *   soin de dire qui reçoit un remplacement de noyau. Une file large et un
 *   refus net valent mieux qu'une file étroite et un drapeau qu'on croit juste.
 *
 * Un site qui n'a rien à mettre à jour répond « déjà à jour » en un aller et
 * retour, sans ouvrir de chantier — donc sans sauvegarde et sans rien toucher.
 *
 * @param array $site
 * @return void
 */
function dashboard_parc_core_maj($site) {
	include_spip('inc/dashboard_chantiers');
	include_spip('inc/dashboard_versions');
	include_spip('inc/dashboard_spip_api');

	$id_site = (int) $site['id_dashboard_site'];
	$titre   = (string) $site['titre'];

	$chantier = dashboard_chantier_courant($id_site);

	if (!$chantier) {
		// Le PHP du site entre dans la décision : une version que son hébergement
		// ne supporte pas n'est pas une cible. Sans cet argument, un site coché
		// mais bloqué recevrait une archive qui s'installe et le casse — et le
		// parc n'a pas de raison de refuser ce que la fiche refuse.
		$etat  = dashboard_core_etat((string) $site['version_spip'], (string) $site['php_version']);
		$cible = (string) $etat['etat'] === 'retard' ? (string) $etat['cible'] : '';
		if ($cible === '') {
			// Trois raisons de ne rien faire, et elles ne se disent pas pareil :
			// à jour, bloqué par le PHP, ou pas de version connue. Répondre
			// « déjà à jour » dans les trois cas était un mensonge dans deux.
			$messages = [
				'bloque'  => ' : SPIP ' . (string) $etat['cible'] . ' demande PHP '
					. dashboard_core_php_requis((string) $etat['cible'])
					. ', ce site est en ' . (string) $site['php_version'],
				'inconnu' => ' : version disponible inconnue, l’annuaire n’a rien répondu',
			];
			dashboard_parc_repondre([
				'ok'      => (string) $etat['etat'] !== 'inconnu',
				'termine' => true,
				'site'    => $titre,
				'message' => $titre . ($messages[(string) $etat['etat']] ?? ' : SPIP déjà à jour'),
			]);
		}

		$ouverture = dashboard_chantier_creer($id_site, 'core_maj', $cible);
		if (empty($ouverture['ok'])) {
			dashboard_parc_repondre([
				'ok'      => false,
				'termine' => true,
				'site'    => $titre,
				'message' => $titre . ' : ' . (string) $ouverture['message'],
			]);
		}
		$chantier = dashboard_chantier_charger((int) $ouverture['id']);
	} elseif ((string) $chantier['operation'] !== 'core_maj') {
		// Un chantier déjà ouvert sur ce site n'est pas le nôtre : on ne le
		// pousse pas, et on ne le remplace pas non plus.
		dashboard_parc_repondre([
			'ok'      => false,
			'termine' => true,
			'site'    => $titre,
			'message' => $titre . ' : une autre opération est en cours — '
				. dashboard_chantier_resume($chantier),
		]);
	}

	if (!dashboard_chantier_fini($chantier)) {
		$chantier = dashboard_chantier_avancer((int) $chantier['id_dashboard_chantier']);
	}

	$fini   = dashboard_chantier_fini($chantier);
	$etapes = dashboard_chantier_etapes((string) $chantier['operation']);
	$total  = max(1, count($etapes));
	$rang   = min((int) $chantier['rang'] + 1, $total);

	dashboard_parc_repondre([
		'ok'      => ((string) $chantier['statut'] !== 'erreur'),
		'termine' => $fini,
		'site'    => $titre,
		'message' => $fini
			? $titre . ' : ' . (string) $chantier['message']
			: $titre . ' (' . $rang . '/' . $total . ') '
				. dashboard_libelle_etape((string) $chantier['etape']),
	]);
}

/**
 * Émet la réponse JSON et termine.
 *
 * @param array $donnees
 * @return void
 */
function dashboard_parc_repondre($donnees) {
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, private');
	}
	echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}
