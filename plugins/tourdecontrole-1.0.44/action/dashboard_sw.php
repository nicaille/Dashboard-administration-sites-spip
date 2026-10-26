<?php
/**
 * Sert le service worker des alertes, à une adresse stable.
 *
 * Pourquoi une action plutôt que le fichier du plugin : le chemin d'un plugin
 * porte son numéro de version. Enregistrer le service worker par ce chemin
 * fabriquerait un enregistrement de plus à chaque mise à jour, les précédents
 * restant actifs avec leurs abonnements — des notifications en double, puis en
 * triple.
 *
 * Elle n'est **pas signée**, et ne doit pas l'être : le navigateur revient
 * chercher ce fichier tout seul pour savoir s'il a changé, longtemps après la
 * visite qui l'a enregistré, et une adresse signée aurait expiré. Ce qu'elle
 * sert est du code public, sans secret ni donnée — le même fichier pour tous.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashboard_sw_dist() {
	$fichier = find_in_path('javascript/dashboard_sw.js');

	if (!$fichier || !is_readable($fichier)) {
		http_response_code(404);
		exit;
	}

	if (!headers_sent()) {
		header('Content-Type: application/javascript; charset=utf-8');
		// Le service worker se réenregistre quand son code change : le laisser
		// en cache retarderait d'autant la prise en compte d'un correctif.
		header('Cache-Control: no-cache, must-revalidate');
		// Autorise une portée plus large que le répertoire du script servant ;
		// inutile tant qu'on reste sous l'espace privé, mais sans effet de bord
		// et cela évite un refus si la portée demandée change un jour.
		header('Service-Worker-Allowed: ' . dashboard_sw_portee());
	}

	readfile($fichier);
	exit;
}

/**
 * La portée à déclarer pour le service worker : l'espace privé, et lui seul.
 *
 * Pas la racine du site : un seul service worker peut tenir une portée donnée,
 * et prendre `/` empêcherait un autre plugin — une application installable, par
 * exemple — d'avoir le sien. Le nôtre n'a de toute façon rien à faire sur les
 * pages publiques.
 *
 * @return string
 */
function dashboard_sw_portee() {
	include_spip('inc/filtres');

	$url = generer_url_ecrire('dashboard');
	$chemin = (string) parse_url(url_absolue($url, url_de_base()), PHP_URL_PATH);

	// On garde le répertoire, pas le script : la portée est un préfixe de
	// chemin, et `…/ecrire/` couvre toutes les pages de l'espace privé.
	$portee = substr($chemin, 0, strrpos($chemin, '/') + 1);

	return ($portee !== '') ? $portee : '/';
}
