<?php
/**
 * Client HTTP signé du tableau de bord.
 *
 * C'est le seul endroit d'où partent des requêtes vers les sites gérés : la
 * signature, les délais et la vérification TLS y sont centralisés.
 *
 * @package SPIP\Dashboard\Inc
 */

use Spip\Chiffrer\Chiffrement;
use Spip\Chiffrer\SpipCles;

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Nom par défaut de l'en-tête que la tour joint à ses appels.
 *
 * Préfixé `X-` et nommé en clair : il doit se reconnaître d'un coup d'œil dans
 * la console d'un frontal, où c'est un humain qui écrira la règle.
 */
if (!defined('_DASHBOARD_FRONTAL_ENTETE')) {
	define('_DASHBOARD_FRONTAL_ENTETE', 'X-Dashboard-Cle');
}

/**
 * Chiffre une valeur sensible avant stockage.
 *
 * @param string $valeur
 * @return string
 */
function dashboard_chiffrer($valeur) {
	if ($valeur === '') {
		return '';
	}

	$clef = dashboard_clef_chiffrement();
	if ($clef !== '') {
		$chiffre = Chiffrement::chiffrer($valeur, $clef);
		if (is_string($chiffre) && $chiffre !== '') {
			return 'c2:' . $chiffre;
		}
	}

	// Stockage en clair : le plugin reste utilisable, mais la page de
	// configuration le signale — un secret de site géré en clair en base est
	// une dégradation qu'il ne faut pas laisser passer inaperçue.
	spip_log('secret stocké en clair : chiffrement du core indisponible', 'dashboard');

	return 'p0:' . $valeur;
}

/**
 * Clef de chiffrement du site, ou chaîne vide si le core ne la fournit pas.
 *
 * @return string
 */
function dashboard_clef_chiffrement() {
	if (!class_exists(Chiffrement::class) || !class_exists(SpipCles::class)) {
		return '';
	}
	$clef = SpipCles::secret_du_site();

	return is_string($clef) ? $clef : '';
}

/**
 * Le chiffrement des secrets est-il opérationnel sur cette installation ?
 *
 * @return bool
 */
function dashboard_chiffrement_disponible() {
	return dashboard_clef_chiffrement() !== '';
}

/**
 * Déchiffre une valeur produite par dashboard_chiffrer().
 *
 * @param string $valeur
 * @return string
 */
function dashboard_dechiffrer($valeur) {
	if (!is_string($valeur) || $valeur === '') {
		return '';
	}
	if (strncmp($valeur, 'c2:', 3) === 0) {
		$clef = dashboard_clef_chiffrement();
		if ($clef === '') {
			return '';
		}
		$clair = Chiffrement::dechiffrer(substr($valeur, 3), $clef);

		return is_string($clair) ? $clair : '';
	}
	if (strncmp($valeur, 'p0:', 3) === 0) {
		return substr($valeur, 3);
	}

	return $valeur;
}

/**
 * Lit une clef de configuration du tableau de bord.
 *
 * @param string $clef
 * @param mixed $defaut
 * @return mixed
 */
function dashboard_config($clef, $defaut = null) {
	include_spip('inc/config');
	$valeur = lire_config('dashboard/' . $clef, $defaut);

	return ($valeur === null || $valeur === '') ? $defaut : $valeur;
}

/**
 * La synchronisation de fond est-elle active ?
 *
 * Une bascule ne se lit pas avec `dashboard_config()`, qui traite la chaîne
 * vide comme une absence et rend le défaut : une case **décochée** vaut la
 * chaîne vide, et se relirait donc cochée. Le réglage serait impossible à
 * éteindre — le même piège que le champ vidable de SPIP Check.
 *
 * Trois états à distinguer, donc, et `lire_config()` les rend tous les trois :
 *
 * - **jamais réglé** (`null`) : la synchronisation tourne. C'est ce qu'on veut
 *   d'une install neuve — un parc qui ne se synchronise pas ne sert à rien ;
 * - **`on`** : cochée ;
 * - **chaîne vide** : décochée, et elle le reste.
 *
 * Le formulaire et le génie passent tous deux par ici. Ils divergeaient : le
 * génie lisait le défaut à « on », le formulaire à vide. La case s'affichait
 * donc décochée sur une install neuve où la tâche tournait bel et bien, et le
 * premier enregistrement l'éteignait sans que personne l'ait demandé.
 *
 * @return bool
 */
function dashboard_sync_auto() {
	include_spip('inc/config');
	$reglee = lire_config('dashboard/sync_auto', null);

	return ($reglee === null) ? true : ((string) $reglee === 'on');
}

/**
 * La période de la synchronisation du parc, en secondes.
 *
 * Un seul lecteur, pour la même raison que la bascule ci-dessus : le pipeline
 * qui déclare la tâche et le génie qui décide s'il doit rafraîchir l'amont
 * parlent de la même période, et deux défauts écrits à deux endroits finissent
 * toujours par diverger.
 *
 * @return int
 */
function dashboard_sync_periode() {
	return max(1, (int) dashboard_config('sync_frequence', 6)) * 3600;
}

/**
 * Les identifiants d'authentification HTTP d'un site, ou la chaîne vide.
 *
 * Un site gardé par un `htpasswd` renvoie un **401 avant que PHP ne
 * s'exécute** : la signature du protocole n'y peut rien, puisque le code qui la
 * vérifie n'est jamais atteint. Rien à corriger côté agent — il faut franchir
 * la porte du serveur avant de lui parler.
 *
 * Le couple ne remplace évidemment pas la signature : il ouvre la porte, elle
 * seule authentifie l'appel. Un site dont on connaîtrait le htpasswd sans le
 * secret partagé ne nous répondrait rien.
 *
 * @param array $site
 * @return string `utilisateur:motdepasse`, ou '' si le site n'en a pas
 */
function dashboard_auth_http($site) {
	$utilisateur = trim((string) ($site['auth_user'] ?? ''));
	if ($utilisateur === '') {
		return '';
	}

	return $utilisateur . ':' . dashboard_dechiffrer((string) ($site['auth_pass'] ?? ''));
}

/**
 * L'en-tête que la tour joint à chaque appel, pour se faire reconnaître d'un
 * frontal — et rien d'autre.
 *
 * Un site passé derrière Cloudflare, Sucuri ou un WAF d'hébergeur voit ses
 * appels interceptés avant PHP : l'agent n'est jamais atteint. La parade
 * courante — une règle visée sur l'adresse IP de la tour — tient mal : l'IP
 * sortante d'un hébergement mutualisé n'est pas celle de son nom de domaine, et
 * elle change sans préavis. Un en-tête que la tour est seule à envoyer se
 * reconnaît sans rien supposer du réseau.
 *
 * **Ce n'est pas une authentification, et il ne faut pas le lire comme telle.**
 * C'est exactement le statut des identifiants `htpasswd` déjà portés ici : ils
 * **ouvrent la porte**, la signature **authentifie l'appel**. Quelqu'un qui
 * connaîtrait cette valeur franchirait le frontal pour se faire refuser par
 * `dashagent_verifier_signature()`.
 *
 * Conséquence à ne pas taire : la valeur part vers **tous** les sites gérés.
 * Un site compromis l'apprend, et pourrait s'en servir pour franchir le frontal
 * d'un autre — où la signature l'arrêtera. Un site dont le frontal doit rester
 * la seule barrière prendra plutôt un `url_agent` que le frontal ne couvre pas.
 *
 * @return string La ligne d'en-tête, ou chaîne vide si le réglage est éteint
 */
function dashboard_frontal_entete() {
	return dashboard_frontal_entete_ligne(
		(string) dashboard_config('frontal_entete', _DASHBOARD_FRONTAL_ENTETE),
		(string) dashboard_config('frontal_cle', '')
	);
}

/**
 * Compose et valide une ligne d'en-tête.
 *
 * Deux refus, et le second n'est pas une précaution de style : **une valeur qui
 * contient un retour à la ligne injecte des en-têtes** dans toutes les requêtes
 * sortantes de la tour. La valeur vient du formulaire de configuration, donc
 * d'un webmestre — mais un collage malheureux suffit, et le prix de l'erreur
 * est que la tour parle à tout le parc avec des en-têtes qu'elle n'a pas
 * voulus.
 *
 * Le nom suit le jeton de la RFC 7230 : lettres, chiffres et une poignée de
 * signes, rien d'autre.
 *
 * Pure : ni base, ni réseau, ni configuration lue au vol.
 *
 * @param string $nom
 * @param string $valeur Vide : le réglage est éteint, et rien n'est envoyé
 * @return string
 */
function dashboard_frontal_entete_ligne($nom, $valeur) {
	$nom    = trim((string) $nom);
	$valeur = trim((string) $valeur);

	if ($valeur === '' || $nom === '') {
		return '';
	}

	if (!preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+$/', $nom)) {
		return '';
	}

	// Les caractères de contrôle, et pas seulement CR et LF : un octet nul
	// tronque la chaîne pour certaines couches, un octet de contrôle isolé
	// n'a de toute façon rien à faire dans une valeur d'en-tête.
	if (preg_match('/[\x00-\x1f\x7f]/', $valeur)) {
		return '';
	}

	return $nom . ': ' . $valeur;
}

/**
 * Construit les champs signés d'une requête vers un agent.
 *
 * C'est la seule définition de la signature côté tableau de bord ; son pendant
 * exact côté agent est `dashagent_signer()`. Toute évolution doit toucher les
 * deux en même temps, sinon plus aucun site du parc ne répond.
 *
 * @param string $op
 * @param string $args JSON brut, transmis tel quel
 * @param int $ts
 * @param string $nonce
 * @param string $secret
 * @return array Champs à poster
 */
function dashboard_signer($op, $args, $ts, $nonce, $secret) {
	return [
		'op'    => $op,
		'args'  => $args,
		'ts'    => $ts,
		'nonce' => $nonce,
		'sig'   => hash_hmac('sha256', $op . "\n" . $args . "\n" . $ts . "\n" . $nonce, $secret),
	];
}

/**
 * Rend inerte ce qu'un site géré nous a répondu.
 *
 * Tout ce qui vient d'un agent — numéros de version, noms de plugins, chemins,
 * messages d'erreur — est du texte, jamais du balisage. Mais c'est du texte
 * écrit par une machine que nous n'administrons pas forcément, et le tableau de
 * bord l'affiche dans son espace privé, à un webmestre qui détient les secrets
 * de tout le parc. Un site compromis qui répondrait
 * `<svg onload="…">` comme numéro de version ferait donc exécuter son code
 * chez nous : une prise de contrôle du parc entier depuis un seul de ses sites.
 *
 * L'échappement de SPIP ne suffit pas à l'arrêter. `interdire_scripts()` ne
 * neutralise que `<?php`, `<base>` et — dans l'espace privé — `<script>` et
 * `<iframe>` ; `<svg onload>` et `<details ontoggle>` passent au travers, ce
 * qui a été vérifié sur SPIP 4.4.23.
 *
 * On coupe donc à l'entrée, une fois pour toutes, plutôt qu'à chaque affichage :
 * ce qui est enregistré est déjà inoffensif, quelle que soit la manière dont un
 * squelette le rendra ensuite. Les `<` et `>` résiduels partent avec le reste —
 * un inventaire n'a aucune raison d'en porter, et c'est ce qui rend le résultat
 * inerte quoi qu'il advienne.
 *
 * @param mixed $valeur Chaîne, tableau ou scalaire
 * @return mixed De même forme, expurgé
 */
function dashboard_inerte($valeur) {
	if (is_array($valeur)) {
		$propre = [];
		foreach ($valeur as $clef => $sous) {
			$propre[is_string($clef) ? dashboard_inerte($clef) : $clef] = dashboard_inerte($sous);
		}

		return $propre;
	}
	if (!is_string($valeur) || $valeur === '') {
		return $valeur;
	}

	$texte = strip_tags($valeur);
	$texte = str_replace(['<', '>'], '', $texte);
	// Les caractères de contrôle ne s'affichent pas mais brouillent les journaux
	// et les comparaisons ; la tabulation et les fins de ligne restent.
	$texte = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $texte);

	return trim((string) $texte);
}

/**
 * Charge un site du parc.
 *
 * @param int $id_dashboard_site
 * @return array|null
 */
function dashboard_charger_site($id_dashboard_site) {
	$site = sql_fetsel('*', 'spip_dashboard_sites', 'id_dashboard_site = ' . (int) $id_dashboard_site);

	return $site ?: null;
}

/**
 * Cette erreur est-elle un silence, plutôt qu'une réponse ?
 *
 * La distinction décide de ce qu'on a le droit d'en conclure. Un agent qui
 * **répond** « verrou SVP posé » ou « opération non autorisée » a délibéré : sa
 * réponse se respecte. Un agent qui ne répond pas n'a rien dit du tout, et
 * l'opération a très bien pu aboutir sans que nous le sachions.
 *
 * Deux façons de ne rien dire :
 *
 * - `transport` : la connexion n'a pas abouti, ou a été coupée en route ;
 * - `reponse_illisible` : quelque chose est revenu, mais pas du JSON. Deux
 *   causes fréquentes, et toutes deux innocentent l'agent — un site en cours de
 *   mue qui sert sa page d'erreur, et un **cache ou répartiteur en frontal**
 *   (Varnish, nginx, Cloudflare) qui rend un 502, 503 ou 504 en HTML parce que
 *   sa propre patience est plus courte que la nôtre. Un export SQL de plusieurs
 *   dizaines de secondes dépasse couramment le `first_byte_timeout` de Varnish,
 *   soixante secondes par défaut, pendant que PHP poursuit tranquillement son
 *   travail derrière.
 *
 * @param string $code Code d'erreur rendu par `dashboard_appeler()`
 * @return bool
 */
function dashboard_silence($code) {
	return in_array((string) $code, ['transport', 'reponse_illisible'], true);
}

/**
 * Un extrait lisible d'une réponse HTML, pour un message d'erreur.
 *
 * `strip_tags()` retire les balises et **garde ce qu'elles contenaient** : le
 * contenu d'un `<style>` survit donc intact. Un défi Cloudflare commence par sa
 * feuille de style, si bien que le parc affichait
 * « Réponse non JSON (HTTP 403) : *{box-sizing:border-box;margin:0;padding:0}… »
 * — deux cents caractères de CSS, et pas un mot de ce qui s'était passé.
 *
 * Les entités ne sont **pas** décodées : ce texte vient d'un site géré, et
 * remettre un `&lt;` en `<` après avoir retiré les balises reviendrait à en
 * refabriquer. Ce qui vient d'un site géré reste inerte, y compris dans un
 * message d'erreur.
 *
 * @param string $corps
 * @param int $max Nombre de caractères conservés
 * @return string
 */
function dashboard_extrait_html($corps, $max = 200) {
	$texte = (string) $corps;

	// D'abord les éléments dont le contenu n'est pas du texte à lire, paire
	// complète…
	$texte = (string) preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1\s*>#is', ' ', $texte);
	// …puis l'ouverture restée seule, qu'une réponse tronquée laisse derrière
	// elle et que la règle ci-dessus ne peut pas voir.
	$texte = (string) preg_replace('#<(script|style|noscript|template)\b[^>]*>.*$#is', ' ', $texte);

	$texte = strip_tags($texte);
	$texte = (string) preg_replace('/\s+/u', ' ', $texte);
	$texte = trim($texte);

	if ($texte === '') {
		return '';
	}

	$max = max(20, (int) $max);
	// `preg` en mode `u` coupe sur un caractère, pas sur un octet, sans exiger
	// mbstring. Sur une charge qui n'est pas de l'UTF-8 valide il ne rend rien :
	// on retombe alors sur une coupe à l'octet, quitte à mordre un caractère.
	if (preg_match('/^.{0,' . $max . '}/su', $texte, $trouve) && isset($trouve[0])) {
		$coupe = $trouve[0];
	} else {
		$coupe = substr($texte, 0, $max);
	}

	return ($coupe === $texte) ? $texte : rtrim($coupe) . '…';
}

/**
 * Ce qui a répondu à notre place, quand ce n'est pas l'agent.
 *
 * Une réponse en HTML n'innocente pas seulement l'agent : elle dit souvent
 * **qui** a parlé, et le remède n'est pas le même selon la réponse. Un défi de
 * navigateur se règle dans la console du frontal ; un délai dépassé se règle en
 * découpant l'opération. Nommer le frontal évite de chercher du côté du site,
 * où il n'y a rien à trouver.
 *
 * Les marqueurs sont cherchés dans la **réponse entière**, jamais dans
 * l'extrait : une page de défi commence par sa feuille de style, et ce qui
 * l'identifie arrive bien après les deux cents premiers caractères.
 *
 * Pure : aucun réseau, aucune base.
 *
 * @param int $code Code HTTP
 * @param string $corps Réponse entière
 * @return string Phrase à afficher, ou chaîne vide si rien n'est reconnu
 */
function dashboard_frontal_nomme($code, $corps) {
	$corps = (string) $corps;
	$code  = (int) $code;

	// `cdn-cgi/challenge-platform` est servi par Cloudflare et par lui seul ;
	// les trois autres sont les noms que prennent ses défis selon les versions.
	if (preg_match('#cdn-cgi/challenge-platform|cf-browser-verification|__cf_chl|cf_chl_opt#i', $corps)) {
		return 'Cloudflare interpose un défi de navigateur : l’appel n’a pas atteint le site.';
	}

	if (preg_match('#Attention Required!?\s*\|\s*Cloudflare|error code:\s*10[0-9]{2}#i', $corps)) {
		return 'Cloudflare a bloqué l’appel par une règle de sécurité : l’agent n’a pas été atteint.';
	}

	if (in_array($code, [502, 503, 504], true)) {
		return 'Un cache ou répartiteur en frontal a coupé avant l’agent : l’opération a pu aboutir côté site.';
	}

	return '';
}

/**
 * Appelle une opération sur l'agent d'un site.
 *
 * @param array $site Ligne de spip_dashboard_sites
 * @param string $op
 * @param array $args
 * @param array $options
 *     - int `timeout` : délai en secondes
 * @return array{ok: bool, data: array, erreur: array, http: int, duree_ms: int}
 */
function dashboard_appeler($site, $op, $args = [], $options = []) {
	$debut = microtime(true);

	$url = trim((string) ($site['url_agent'] ?? ''));
	if ($url === '') {
		return dashboard_reponse_erreur('url_absente', 'Aucune URL d’agent configurée pour ce site.', $debut);
	}
	if (!dashboard_url_acceptable($url)) {
		return dashboard_reponse_erreur('url_non_https', 'L’URL de l’agent doit être en https.', $debut);
	}

	$secret = dashboard_dechiffrer((string) ($site['secret'] ?? ''));
	if ($secret === '') {
		return dashboard_reponse_erreur('secret_absent', 'Aucun secret partagé enregistré pour ce site.', $debut);
	}

	$json  = $args ? json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
	$ts    = time();
	$nonce = bin2hex(random_bytes(16));

	$champs = dashboard_signer($op, $json, $ts, $nonce, $secret);

	$timeout = (int) ($options['timeout'] ?? dashboard_config('timeout', 30));
	$http    = dashboard_http_post($url, $champs, $timeout, '', dashboard_auth_http($site));

	if (!$http['ok']) {
		return dashboard_reponse_erreur('transport', $http['erreur'], $debut, $http['code']);
	}

	$reponse = json_decode((string) $http['corps'], true);
	if (!is_array($reponse)) {
		// Nommer le frontal quand on le reconnaît, et ne retomber sur l'extrait
		// que faute de mieux : une phrase qui dit qui a répondu vaut mieux que
		// deux cents caractères de la page qu'il a servie.
		$dit = dashboard_frontal_nomme($http['code'], (string) $http['corps']);
		if ($dit === '') {
			$dit = dashboard_extrait_html((string) $http['corps']);
		}

		return dashboard_reponse_erreur(
			'reponse_illisible',
			'Réponse non JSON (HTTP ' . $http['code'] . ')' . ($dit === '' ? '' : ' : ' . $dit),
			$debut,
			$http['code']
		);
	}

	if (empty($reponse['ok'])) {
		$erreur = $reponse['erreur'] ?? [];

		return [
			'ok'     => false,
			'data'   => is_array($erreur['detail'] ?? null) ? $erreur['detail'] : [],
			'erreur' => [
				'code'    => (string) ($erreur['code'] ?? 'inconnue'),
				'message' => (string) ($erreur['message'] ?? 'Erreur non détaillée par l’agent'),
			],
			'http'     => $http['code'],
			'duree_ms' => dashboard_duree($debut),
			'agent'    => (string) ($reponse['agent'] ?? ''),
		];
	}

	return [
		'ok'       => true,
		'data'     => is_array($reponse['data'] ?? null) ? $reponse['data'] : [],
		'erreur'   => [],
		'http'     => $http['code'],
		'duree_ms' => dashboard_duree($debut),
		'agent'    => (string) ($reponse['agent'] ?? ''),
		'horloge'  => (int) ($reponse['horloge'] ?? 0),
	];
}

/**
 * Rapatrie une sauvegarde depuis un site géré.
 *
 * @param array $site
 * @param string $identifiant
 * @param string $destination Chemin local du fichier à écrire
 * @return array{ok: bool, erreur: array, octets: int, sha256: string}
 */
function dashboard_telecharger_sauvegarde($site, $identifiant, $destination) {
	$debut  = microtime(true);
	$secret = dashboard_dechiffrer((string) ($site['secret'] ?? ''));
	$url    = trim((string) ($site['url_agent'] ?? ''));

	if ($secret === '' || !dashboard_url_acceptable($url)) {
		return ['ok' => false, 'erreur' => ['code' => 'configuration', 'message' => 'Site mal configuré'], 'octets' => 0, 'sha256' => ''];
	}

	$json  = json_encode(['identifiant' => $identifiant], JSON_UNESCAPED_SLASHES);
	$ts    = time();
	$nonce = bin2hex(random_bytes(16));
	$champs = dashboard_signer('sauvegarde_telecharger', $json, $ts, $nonce, $secret);

	$http = dashboard_http_post(
		$url,
		$champs,
		(int) dashboard_config('timeout_long', 300),
		$destination,
		dashboard_auth_http($site)
	);

	if (!$http['ok']) {
		@unlink($destination);

		return ['ok' => false, 'erreur' => ['code' => 'transport', 'message' => $http['erreur']], 'octets' => 0, 'sha256' => ''];
	}

	// Une erreur applicative revient en JSON dans le fichier : on la détecte
	// plutôt que d'enregistrer une « sauvegarde » de 200 octets illisible.
	if (filesize($destination) < 4096) {
		$debut_fichier = (string) file_get_contents($destination, false, null, 0, 512);
		if (strncmp(ltrim($debut_fichier), '{', 1) === 0) {
			$json_erreur = json_decode((string) file_get_contents($destination), true);
			@unlink($destination);
			$message = $json_erreur['erreur']['message'] ?? 'Réponse inattendue de l’agent';

			return ['ok' => false, 'erreur' => ['code' => 'agent', 'message' => (string) $message], 'octets' => 0, 'sha256' => ''];
		}
	}

	return [
		'ok'       => true,
		'erreur'   => [],
		'octets'   => (int) filesize($destination),
		'sha256'   => hash_file('sha256', $destination),
		'duree_ms' => dashboard_duree($debut),
	];
}

/**
 * POST HTTP vers un agent.
 *
 * cURL est privilégié pour maîtriser les délais et refuser les redirections ;
 * la couche `recuperer_url` de SPIP sert de repli quand cURL est absent.
 *
 * @param string $url
 * @param array $champs
 * @param int $timeout
 * @param string $fichier Si fourni, le corps est écrit dans ce fichier
 * @return array{ok: bool, code: int, corps: string, erreur: string}
 */
function dashboard_http_post($url, $champs, $timeout = 30, $fichier = '', $auth = '') {
	$timeout = max(5, min(900, (int) $timeout));

	if (function_exists('curl_init')) {
		return dashboard_http_post_curl($url, $champs, $timeout, $fichier, $auth);
	}

	include_spip('inc/distant');
	$options = [
		'methode'         => 'POST',
		'datas'           => $champs,
		'taille_max'      => 256 * 1024 * 1024,
		'follow_location' => 0,
	];
	if ($fichier !== '') {
		$options['file'] = $fichier;
	}
	// L'en-tête plutôt que `https://user:pass@site/` : une adresse porteuse
	// d'identifiants finit dans les journaux du serveur et dans les messages
	// d'erreur, l'en-tête non.
	$entetes = [];
	if ($auth !== '') {
		$entetes[] = 'Authorization: Basic ' . base64_encode($auth);
	}
	// L'en-tête de reconnaissance se lit ici, et non chez l'appelant : tout ce
	// qui part vers un agent passe par cette fonction, et un nouveau chemin
	// l'oublierait s'il fallait y penser à chaque fois.
	$frontal = dashboard_frontal_entete();
	if ($frontal !== '') {
		$entetes[] = $frontal;
	}
	if ($entetes) {
		$options['entetes'] = implode("\r\n", $entetes);
	}
	$res = recuperer_url($url, $options);

	if (!is_array($res)) {
		return ['ok' => false, 'code' => 0, 'corps' => '', 'erreur' => 'Requête impossible (aucune couche HTTP disponible)'];
	}
	$code = (int) ($res['status'] ?? 0);
	if (!$code) {
		return ['ok' => false, 'code' => 0, 'corps' => '', 'erreur' => 'Site injoignable'];
	}

	return ['ok' => true, 'code' => $code, 'corps' => (string) ($res['page'] ?? ''), 'erreur' => ''];
}

/**
 * Implémentation cURL du POST.
 *
 * @param string $url
 * @param array $champs
 * @param int $timeout
 * @param string $fichier
 * @return array
 */
function dashboard_http_post_curl($url, $champs, $timeout, $fichier = '', $auth = '') {
	$ch = curl_init($url);
	if (!$ch) {
		return ['ok' => false, 'code' => 0, 'corps' => '', 'erreur' => 'Initialisation cURL impossible'];
	}

	$sortie = null;
	$options = [
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => http_build_query($champs),
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_TIMEOUT        => $timeout,
		// Suivre une redirection rejouerait la requête ailleurs, éventuellement
		// en clair : mieux vaut échouer visiblement et corriger l'URL.
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_USERAGENT      => 'SPIP Dashboard/1.0 (+' . url_de_base() . ')',
		CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Expect:'],
	];

	$frontal = dashboard_frontal_entete();
	if ($frontal !== '') {
		$options[CURLOPT_HTTPHEADER][] = $frontal;
	}

	// Basic et rien d'autre : `CURLAUTH_ANY` ferait d'abord un appel à blanc
	// pour découvrir la méthode, et surtout accepterait qu'un serveur réclame
	// une authentification qui laisse fuir davantage.
	if ($auth !== '') {
		$options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
		$options[CURLOPT_USERPWD]  = $auth;
	}

	if ($fichier !== '') {
		$sortie = @fopen($fichier, 'wb');
		if (!$sortie) {
			curl_close($ch);

			return ['ok' => false, 'code' => 0, 'corps' => '', 'erreur' => 'Fichier de destination non inscriptible'];
		}
		$options[CURLOPT_FILE] = $sortie;
	} else {
		$options[CURLOPT_RETURNTRANSFER] = true;
	}

	curl_setopt_array($ch, $options);
	$corps = curl_exec($ch);
	$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$erreur = curl_error($ch);
	curl_close($ch);
	if ($sortie) {
		fclose($sortie);
	}

	if ($corps === false && $erreur !== '') {
		return ['ok' => false, 'code' => $code, 'corps' => '', 'erreur' => $erreur];
	}
	if ($code >= 300 && $code < 400) {
		return ['ok' => false, 'code' => $code, 'corps' => '', 'erreur' => 'L’agent répond par une redirection (HTTP ' . $code . ') : vérifiez l’URL exacte.'];
	}

	return ['ok' => true, 'code' => $code, 'corps' => is_string($corps) ? $corps : '', 'erreur' => ''];
}

/**
 * L'URL d'agent est-elle utilisable ?
 *
 * Le http en clair n'est toléré que si le tableau de bord l'autorise
 * explicitement — utile en développement local, jamais en production.
 *
 * @param string $url
 * @return bool
 */
function dashboard_url_acceptable($url) {
	if (preg_match('#^https://#i', $url)) {
		return true;
	}

	return preg_match('#^http://#i', $url) && dashboard_config('autoriser_http', '') === 'on';
}

/**
 * Fabrique une réponse d'erreur homogène.
 *
 * @param string $code
 * @param string $message
 * @param float $debut
 * @param int $http
 * @return array
 */
function dashboard_reponse_erreur($code, $message, $debut, $http = 0) {
	return [
		'ok'       => false,
		'data'     => [],
		'erreur'   => ['code' => $code, 'message' => $message],
		'http'     => (int) $http,
		'duree_ms' => dashboard_duree($debut),
	];
}

/**
 * @param float $debut
 * @return int
 */
function dashboard_duree($debut) {
	return (int) round((microtime(true) - $debut) * 1000);
}

/**
 * Génère un secret partagé.
 *
 * @return string
 */
function dashboard_generer_secret() {
	return bin2hex(random_bytes(32));
}
