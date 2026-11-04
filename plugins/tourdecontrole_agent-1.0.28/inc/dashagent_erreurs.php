<?php
/**
 * Ce que l'agent dit d'une erreur, et comment il le dit.
 *
 * Un fichier à part, et minuscule, pour une raison précise : `inc/dashagent.php`
 * ouvre la configuration et le chiffrement, donc ne se charge pas hors de SPIP.
 * La décision qui nous intéresse ici — que remonter d'une exception — est pure,
 * et doit pouvoir s'éprouver sans rien monter. C'est le même partage que pour
 * `inc/dashboard_cron.php` côté tour.
 *
 * @package SPIP\Dashagent\Erreurs
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Ce qu'on a le droit de dire d'une exception à la tour de contrôle.
 *
 * `getMessage()` seul suffit pour une erreur qu'on a nous-mêmes levée, et ne
 * suffit pas du tout pour une erreur du langage. « Call to undefined function
 * typo() » décrit parfaitement le symptôme et ne dit pas **où** — or c'est le
 * où qui se corrige. Remonter le fichier et la ligne transforme un diagnostic
 * d'une demi-journée en une lecture.
 *
 * Le chemin est ramené à la racine du site quand on la connaît : il désigne
 * alors un fichier du dépôt plutôt que l'arborescence d'un hébergeur, ce qui
 * est à la fois plus lisible et moins bavard sur le serveur d'en face.
 *
 * Pure : ni base, ni réseau, ni constante lue au vol — la racine est passée par
 * l'appelant.
 *
 * @param \Throwable $e
 * @param string $racine Racine absolue du site, ou chaîne vide
 * @return string
 */
function dashagent_exception_message($e, $racine = '') {
	$message = trim((string) $e->getMessage());
	if ($message === '') {
		// Une exception sans message existe, et « Erreur interne :  » ne dit
		// rien du tout. Le nom de la classe en dit au moins le genre.
		$message = get_class($e);
	}

	$fichier = (string) $e->getFile();
	$racine  = (string) $racine;
	if ($racine !== '' && strncmp($fichier, $racine, strlen($racine)) === 0) {
		// La barre de tête ne se retire **que** si la racine a bien été
		// reconnue. Le premier jet la retirait dans tous les cas : une racine
		// étrangère rendait alors `home/user/…`, un chemin ni absolu ni
		// relatif, qui désigne un fichier qui n'existe pas. Trouvé par le
		// contrôle, pas en relisant.
		$fichier = ltrim((string) substr($fichier, strlen($racine)), '/');
	}

	if ($fichier === '') {
		return $message;
	}

	return $message . ' (' . $fichier . ':' . (int) $e->getLine() . ')';
}
