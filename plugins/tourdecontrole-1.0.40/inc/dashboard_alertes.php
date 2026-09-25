<?php
/**
 * Alertes du parc : ce qu'il y a à dire, et à qui.
 *
 * Une tour de contrôle qui sait tout et ne dit rien ne sert qu'à ceux qui
 * pensent à venir la consulter. Ce fichier répond à la question inverse :
 * qu'est-ce qui mérite qu'on dérange quelqu'un ?
 *
 * Le principe, et il tient tout : **on n'écrit que s'il y a du nouveau.** Un
 * récapitulatif quotidien identique à celui de la veille est un récapitulatif
 * qu'on cesse de lire au bout d'une semaine, et le jour où il porte enfin
 * quelque chose, personne ne l'ouvre. D'où une empreinte de ce qui a déjà été
 * annoncé, comparée avant chaque envoi.
 *
 * L'empreinte porte sur **ce qui est en retard, site par site**, et non sur des
 * compteurs globaux : deux sites qui échangent leurs rôles laisseraient un
 * total inchangé, et le silence serait alors un mensonge.
 *
 * Levée d'alerte comprise : quand tout est rentré dans l'ordre, un dernier
 * message le dit, puis c'est le silence. Sans quoi on resterait sur la dernière
 * mauvaise nouvelle sans jamais savoir qu'elle est périmée.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Au-delà de ce nombre de jours sans synchronisation réussie, un site est
 * considéré comme muet — une panne, pas un retard de mise à jour.
 */
define('_DASHBOARD_ALERTE_MUET_JOURS', 3);

/**
 * L'état du parc, tel qu'une alerte le raconterait.
 *
 * Quatre rubriques, qui sont exactement les quatre cases cochées à la mise au
 * point du système : le core, les plugins, l'agent, et les pannes.
 *
 * @return array
 */
function dashboard_alerte_etat() {
	include_spip('inc/dashboard_client');
	include_spip('tourdecontrole_fonctions');

	$etat = [
		'core'    => [],
		'bloques' => [],
		'plugins' => [],
		'agent'   => [],
		'pannes'  => [],
		'sites'   => 0,
	];

	if (!dashboard_tables_presentes()) {
		return $etat;
	}

	$sites = sql_allfetsel(
		'id_dashboard_site, titre, url_site, version_spip, agent_version, etat, erreur,'
			. ' core_maj, core_etat, core_cible, php_version, base_maj, nb_plugins_maj, date_sync_ok',
		'spip_dashboard_sites',
		'statut = ' . sql_quote('publie'),
		'',
		'titre'
	);
	$etat['sites'] = count($sites);

	// Les sites dont l'agent est en retard : une jointure, pas une lecture par
	// site — un parc de deux cents sites ferait deux cents requêtes.
	$agents = array_flip(dashboard_agent_maj_sites());

	$muet_depuis = time() - (_DASHBOARD_ALERTE_MUET_JOURS * 86400);

	foreach ($sites as $site) {
		$id    = (int) $site['id_dashboard_site'];
		$titre = (string) $site['titre'];

		if ((string) $site['core_maj'] === 'oui') {
			$etat['core'][] = [
				'id'      => $id,
				'titre'   => $titre,
				'version' => (string) $site['version_spip'],
			];
		}

		// Un site que son PHP empêche de suivre n'est pas un retard qu'on peut
		// rattraper d'un clic : c'est une demande à faire à son hébergeur. Il a
		// donc sa rubrique, et non une ligne parmi les retards — sans quoi le
		// webmestre lancerait une opération vouée à casser le site.
		if ((string) $site['core_etat'] === 'bloque') {
			$etat['bloques'][] = [
				'id'      => $id,
				'titre'   => $titre,
				'version' => (string) $site['version_spip'],
				'cible'   => (string) $site['core_cible'],
				'php'     => (string) $site['php_version'],
			];
		}

		if ((int) $site['nb_plugins_maj'] > 0) {
			$etat['plugins'][] = [
				'id'    => $id,
				'titre' => $titre,
				'nb'    => (int) $site['nb_plugins_maj'],
			];
		}

		if (isset($agents[$id])) {
			$etat['agent'][] = [
				'id'      => $id,
				'titre'   => $titre,
				'version' => (string) $site['agent_version'],
			];
		}

		// Les pannes, par ordre de gravité décroissante, et **une seule par
		// site** : un site injoignable a forcément une sauvegarde qui date et
		// une base dont on ne sait rien. Les énumérer toutes noierait la cause
		// dans ses conséquences.
		$vu = strtotime((string) $site['date_sync_ok']);
		if ((string) $site['etat'] === 'erreur') {
			$etat['pannes'][] = [
				'id' => $id, 'titre' => $titre, 'genre' => 'injoignable',
				'detail' => (string) $site['erreur'],
			];
		} elseif ($vu && $vu < $muet_depuis) {
			$etat['pannes'][] = [
				'id' => $id, 'titre' => $titre, 'genre' => 'muet',
				'detail' => (string) $site['date_sync_ok'],
			];
		} elseif ((string) $site['base_maj'] === 'oui') {
			$etat['pannes'][] = [
				'id' => $id, 'titre' => $titre, 'genre' => 'base',
				'detail' => '',
			];
		}
	}

	return $etat;
}

/**
 * Y a-t-il quelque chose à signaler ?
 *
 * @param array $etat
 * @return bool
 */
function dashboard_alerte_vide($etat) {
	return !$etat['core'] && !($etat['bloques'] ?? []) && !$etat['plugins']
		&& !$etat['agent'] && !$etat['pannes'];
}

/**
 * Empreinte de ce qu'une alerte dirait.
 *
 * Pure, et c'est tout l'intérêt : elle décide des envois, et cette décision
 * doit pouvoir s'éprouver sans base ni réseau.
 *
 * Le détail des pannes n'entre pas dans l'empreinte — le message d'erreur d'un
 * site injoignable change à chaque tentative (« timeout après 30 s », puis
 * « connexion refusée »), et ferait repartir un courriel chaque jour pour la
 * même panne. Le *genre* de panne y entre, lui.
 *
 * @param array $etat
 * @return string
 */
function dashboard_alerte_empreinte($etat) {
	$lignes = [];

	foreach ($etat['core'] as $e) {
		$lignes[] = 'core:' . (int) $e['id'] . ':' . $e['version'];
	}
	// La version visée entre dans l'empreinte, et le PHP du site aussi : passer
	// de PHP 7.3 à 7.4 sur un site bloqué est précisément la nouvelle qu'on
	// attendait, et elle doit rouvrir le canal.
	foreach (($etat['bloques'] ?? []) as $e) {
		$lignes[] = 'bloque:' . (int) $e['id'] . ':' . $e['cible'] . ':' . $e['php'];
	}
	foreach ($etat['plugins'] as $e) {
		$lignes[] = 'plugins:' . (int) $e['id'] . ':' . (int) $e['nb'];
	}
	foreach ($etat['agent'] as $e) {
		$lignes[] = 'agent:' . (int) $e['id'] . ':' . $e['version'];
	}
	foreach ($etat['pannes'] as $e) {
		$lignes[] = 'panne:' . (int) $e['id'] . ':' . $e['genre'];
	}

	sort($lignes);

	return hash('sha256', implode("\n", $lignes));
}

/**
 * Le message, en texte brut.
 *
 * Pure elle aussi. Rien de ce qui vient d'un site géré n'est interprété : le
 * courriel part en texte brut, et le titre d'un site compromis n'y est qu'un
 * titre. C'est la même règle qu'à l'affichage, obtenue ici par le format.
 *
 * @param array $etat
 * @param string $url_parc Adresse de la vue d'ensemble
 * @return array{sujet: string, texte: string, court: string}
 */
function dashboard_alerte_texte($etat, $url_parc = '') {
	if (dashboard_alerte_vide($etat)) {
		return [
			'sujet' => _T('dashboard:alerte_sujet_ras'),
			'texte' => _T('dashboard:alerte_ras') . "\n\n" . $url_parc,
			'court' => _T('dashboard:alerte_ras'),
		];
	}

	$blocs = [];
	$court = [];

	if ($etat['core']) {
		$court[] = _T('dashboard:alerte_court_core', ['nb' => count($etat['core'])]);
		$lignes = [_T('dashboard:alerte_titre_core')];
		foreach ($etat['core'] as $e) {
			$lignes[] = '  - ' . dashboard_alerte_nom($e['titre']) . ' (SPIP ' . $e['version'] . ')';
		}
		$blocs[] = implode("\n", $lignes);
	}

	if ($etat['bloques'] ?? []) {
		$court[] = _T('dashboard:alerte_court_bloques', ['nb' => count($etat['bloques'])]);
		$lignes = [_T('dashboard:alerte_titre_bloques')];
		foreach ($etat['bloques'] as $e) {
			$lignes[] = '  - ' . dashboard_alerte_nom($e['titre'])
				. ' (SPIP ' . $e['version'] . ', PHP ' . $e['php']
				. ' → SPIP ' . $e['cible'] . ')';
		}
		$blocs[] = implode("\n", $lignes);
	}

	if ($etat['agent']) {
		$court[] = _T('dashboard:alerte_court_agent', ['nb' => count($etat['agent'])]);
		$lignes = [_T('dashboard:alerte_titre_agent')];
		foreach ($etat['agent'] as $e) {
			$lignes[] = '  - ' . dashboard_alerte_nom($e['titre'])
				. ($e['version'] !== '' ? ' (agent ' . $e['version'] . ')' : '');
		}
		$blocs[] = implode("\n", $lignes);
	}

	if ($etat['plugins']) {
		$total = 0;
		foreach ($etat['plugins'] as $e) {
			$total += (int) $e['nb'];
		}
		$court[] = _T('dashboard:alerte_court_plugins', ['nb' => $total]);
		$lignes = [_T('dashboard:alerte_titre_plugins')];
		foreach ($etat['plugins'] as $e) {
			$lignes[] = '  - ' . dashboard_alerte_nom($e['titre']) . ' : ' . (int) $e['nb'];
		}
		$blocs[] = implode("\n", $lignes);
	}

	if ($etat['pannes']) {
		$court[] = _T('dashboard:alerte_court_pannes', ['nb' => count($etat['pannes'])]);
		// Les clefs sont écrites en toutes lettres plutôt que composées à
		// partir du genre : une clef fabriquée par concaténation échappe au
		// contrôle qui vérifie que toute référence de langue existe, et une
		// faute de frappe se lirait alors à l'écran, dans l'alerte elle-même.
		$clefs = [
			'injoignable' => 'dashboard:alerte_panne_injoignable',
			'muet'        => 'dashboard:alerte_panne_muet',
			'base'        => 'dashboard:alerte_panne_base',
		];
		$lignes = [_T('dashboard:alerte_titre_pannes')];
		foreach ($etat['pannes'] as $e) {
			$lignes[] = '  - ' . dashboard_alerte_nom($e['titre']) . ' : '
				. _T($clefs[$e['genre']] ?? 'dashboard:alerte_panne_injoignable');
		}
		$blocs[] = implode("\n", $lignes);
	}

	$texte = implode("\n\n", $blocs);
	if ($url_parc !== '') {
		$texte .= "\n\n" . $url_parc;
	}

	return [
		'sujet' => _T('dashboard:alerte_sujet', ['resume' => implode(', ', $court)]),
		'texte' => $texte,
		'court' => implode(', ', $court),
	];
}

/**
 * Un titre de site, rendu inoffensif pour un message en texte brut.
 *
 * Ce qui vient d'un site géré est inerte, toujours — y compris hors du HTML.
 * Les retours à la ligne sont ce qui compte ici : un titre qui en contient
 * fabriquerait de fausses lignes dans la liste, et, dans un sujet de courriel,
 * un en-tête supplémentaire.
 *
 * @param string $titre
 * @return string
 */
function dashboard_alerte_nom($titre) {
	$titre = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) $titre);
	$titre = trim(preg_replace('/\s+/u', ' ', (string) $titre));

	if ($titre === '') {
		return '(sans titre)';
	}

	return (mb_strlen($titre) > 80) ? (mb_substr($titre, 0, 79) . '…') : $titre;
}

/**
 * Les adresses à prévenir.
 *
 * Réglées à la main, et **vides par défaut** : une tour de contrôle qui se met
 * à écrire à quelqu'un dès son installation est une tour qu'on désinstalle.
 * Tant que le champ est vide, aucun courriel ne part.
 *
 * @return array
 */
function dashboard_alerte_destinataires() {
	include_spip('inc/filtres');
	include_spip('inc/dashboard_client');

	$brut = (string) dashboard_config('alerte_destinataires', '');
	$adresses = preg_split('/[\s,;]+/', $brut, -1, PREG_SPLIT_NO_EMPTY);

	$retenues = [];
	foreach ((array) $adresses as $adresse) {
		if (email_valide($adresse)) {
			$retenues[] = $adresse;
		}
	}

	return array_values(array_unique($retenues));
}

/**
 * Les alertes sont-elles en service ?
 *
 * Trois états, comme toute bascule ici : jamais réglée, réglée, vidée. Le
 * défaut est **éteint** — à l'inverse de la synchronisation, qui ne dérange
 * personne. Écrire à quelqu'un se demande.
 *
 * @return bool
 */
function dashboard_alerte_active() {
	include_spip('inc/config');
	$reglee = lire_config('dashboard/alerte_active', null);

	return ($reglee === null) ? false : ((string) $reglee === 'on');
}

/**
 * L'empreinte de la dernière alerte diffusée.
 *
 * @return string
 */
function dashboard_alerte_empreinte_connue() {
	include_spip('inc/config');

	return (string) lire_config('dashboard/alerte_empreinte', '');
}

/**
 * Mémorise ce qui vient d'être diffusé.
 *
 * Écrite **après** la diffusion et seulement si quelque chose est parti : une
 * empreinte enregistrée alors que rien n'a été envoyé ferait taire l'alerte
 * pour de bon, la nouveauté ayant été consommée sans être dite.
 *
 * @param string $empreinte
 * @return void
 */
function dashboard_alerte_memoriser($empreinte) {
	include_spip('inc/config');
	ecrire_config('dashboard/alerte_empreinte', (string) $empreinte);
	ecrire_config('dashboard/alerte_date', date('Y-m-d H:i:s'));
}

/**
 * Diffuse une alerte par les canaux réglés.
 *
 * @param array $etat
 * @return array{courriels: int, push: int, retires: int}
 */
function dashboard_alerte_diffuser($etat) {
	include_spip('inc/filtres');

	$bilan = ['courriels' => 0, 'push' => 0, 'retires' => 0];

	$url_parc = url_absolue(generer_url_ecrire('dashboard'), url_de_base());
	$message  = dashboard_alerte_texte($etat, $url_parc);

	// --- Courriel ---------------------------------------------------------
	$destinataires = dashboard_alerte_destinataires();
	if ($destinataires) {
		include_spip('inc/envoyer_mail');
		$envoyer = charger_fonction('envoyer_mail', 'inc', true);
		if ($envoyer) {
			foreach ($destinataires as $adresse) {
				if ($envoyer($adresse, $message['sujet'], $message['texte'])) {
					$bilan['courriels']++;
				} else {
					spip_log('alerte : courriel refusé pour ' . $adresse, 'dashboard');
				}
			}
		}
	}

	// --- Web Push ---------------------------------------------------------
	$bilan = array_merge($bilan, dashboard_alerte_pousser($message, $url_parc));

	return $bilan;
}

/**
 * Pousse l'alerte vers les navigateurs abonnés.
 *
 * @param array $message
 * @param string $url_parc
 * @return array{push: int, retires: int}
 */
function dashboard_alerte_pousser($message, $url_parc) {
	include_spip('inc/dashboard_push');

	$bilan = ['push' => 0, 'retires' => 0];

	$vapid = dashboard_push_vapid();
	if (!$vapid) {
		return $bilan;
	}

	$abonnements = sql_allfetsel('*', 'spip_dashboard_push');
	if (!$abonnements) {
		return $bilan;
	}

	$charge = json_encode([
		'titre' => $message['sujet'],
		'texte' => $message['court'],
		'url'   => $url_parc,
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	foreach ($abonnements as $abonnement) {
		$retour = dashboard_push_envoyer($abonnement, $charge, $vapid);

		if ($retour['verdict'] === 'remis') {
			$bilan['push']++;
			sql_updateq('spip_dashboard_push', [
				'date_succes' => date('Y-m-d H:i:s'),
				'echecs'      => 0,
			], 'id_dashboard_push = ' . (int) $abonnement['id_dashboard_push']);
			continue;
		}

		// Une réponse qui dit « cet abonnement n'existe plus » se respecte :
		// le navigateur en refabriquera un au prochain passage. Un silence,
		// lui, ne dit rien — on compte, on ne supprime pas.
		if ($retour['verdict'] === 'perime') {
			sql_delete('spip_dashboard_push', 'id_dashboard_push = ' . (int) $abonnement['id_dashboard_push']);
			$bilan['retires']++;
			continue;
		}

		sql_update('spip_dashboard_push', ['echecs' => 'echecs + 1'],
			'id_dashboard_push = ' . (int) $abonnement['id_dashboard_push']);
		spip_log('alerte push ' . $retour['verdict'] . ' (' . $retour['code'] . ') : '
			. $retour['message'], 'dashboard');
	}

	return $bilan;
}

/**
 * La paire VAPID du parc, fabriquée au premier besoin.
 *
 * Une seule paire pour toute la tour, et **elle ne se regénère jamais** : les
 * abonnements des navigateurs sont liés à la clef publique qui leur a été
 * présentée. En changer les invaliderait tous d'un coup, sans que personne ne
 * s'en aperçoive avant la prochaine alerte non reçue.
 *
 * @return array{pem: string, publique: string, sujet: string}|array{}
 */
function dashboard_push_vapid() {
	include_spip('inc/config');
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_push');

	$pem      = (string) lire_config('dashboard/push_pem', '');
	$publique = (string) lire_config('dashboard/push_publique', '');

	if ($pem === '' || $publique === '') {
		$paire = dashboard_push_paire();
		if (!$paire) {
			spip_log('VAPID : paire de clefs impossible à fabriquer (OpenSSL sans courbes EC ?)', 'dashboard');

			return [];
		}
		ecrire_config('dashboard/push_pem', $paire['pem']);
		ecrire_config('dashboard/push_publique', $paire['publique']);
		$pem = $paire['pem'];
		$publique = $paire['publique'];
	}

	// Le sujet dit au service de distribution qui le sollicite, pour qu'il
	// puisse nous écrire si nos envois posent problème. Une adresse de
	// courriel du parc, à défaut celle du site.
	$sujet = (string) dashboard_config('alerte_sujet', '');
	if ($sujet === '') {
		$destinataires = dashboard_alerte_destinataires();
		$sujet = $destinataires
			? 'mailto:' . $destinataires[0]
			: url_de_base();
	} elseif (strpos($sujet, ':') === false) {
		$sujet = 'mailto:' . $sujet;
	}

	return ['pem' => $pem, 'publique' => $publique, 'sujet' => $sujet];
}
