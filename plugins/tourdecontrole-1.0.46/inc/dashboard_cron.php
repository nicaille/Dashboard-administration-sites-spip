<?php
/**
 * Décisions du lanceur de tâches en ligne de commande.
 *
 * Une seule fonction, et elle est **pure** : c'est elle qui décide de ce que le
 * journal affirme avoir exécuté. Elle vit ici, et non dans `outils/cron.php`,
 * parce que ce script s'exécute à l'inclusion — on ne peut donc ni le charger
 * pour l'éprouver, ni éprouver ce qu'il enferme.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Ce qui a réellement tourné pendant un tour de file, dit sans le supposer.
 *
 * **Un génie exécuté replanifie son propre travail**, à sa périodicité : sa date
 * en base a donc bougé, ou son travail a disparu s'il n'était pas périodique.
 * Comparer l'avant et l'après est la seule façon de le constater — `cron()` ne
 * rend rien, et se contenter de lister les travaux échus ne dirait que ce qui
 * **devait** passer.
 *
 * La distinction n'est pas scolaire. Un génie tiers qui lève une exception est
 * rattrapé par la boucle, qui continue ; son travail, réinséré « en cours » avant
 * exécution, n'est pas repris au tour suivant. Lister les échus l'aurait compté
 * comme passé, et le journal aurait affirmé le contraire de ce qui s'est produit
 * — précisément ce qu'on cherche à pouvoir lire.
 *
 * Un travail resté échu est donc mentionné à part. C'est le symptôme d'un génie
 * qui a échoué, ou d'une file qui refuse de travailler : le défaut même que
 * l'injection d'échéance a corrigé, et qui avait valu cinquante tours en sept
 * secondes sans un seul génie exécuté.
 *
 * @param array $avant Travaux échus avant le tour, `[fonction => horodatage]`
 * @param array $apres Les mêmes, relus après le tour
 * @return string Une ligne de journal
 */
function dashboard_cron_bilan_tour($avant, $apres) {
	$avant = is_array($avant) ? $avant : [];
	$apres = is_array($apres) ? $apres : [];

	if (!$avant) {
		return 'aucun travail échu à ce tour';
	}

	$passes = [];
	$restes = [];
	foreach ($avant as $fonction => $date) {
		$fonction = (string) $fonction;
		if (!isset($apres[$fonction]) || $apres[$fonction] > $date) {
			$passes[] = $fonction;
		} else {
			$restes[] = $fonction;
		}
	}

	sort($passes);
	sort($restes);

	$dit = 'génies passés : ' . ($passes ? implode(', ', $passes) : 'aucun');
	if ($restes) {
		$dit .= ' ; encore échus : ' . implode(', ', $restes);
	}

	return $dit;
}
