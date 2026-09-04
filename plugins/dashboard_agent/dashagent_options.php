<?php
/**
 * Constantes de l'agent Dashboard.
 *
 * Toutes sont redéfinissables depuis le `mes_options.php` du site géré.
 *
 * @package SPIP\Dashagent\Options
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/** Version du protocole d'échange avec la tour de contrôle. */
if (!defined('_DASHAGENT_PROTOCOLE')) {
	define('_DASHAGENT_PROTOCOLE', '1.0');
}

/** Écart maximal toléré, en secondes, entre l'horloge du dashboard et celle du site. */
if (!defined('_DASHAGENT_TOLERANCE_HORLOGE')) {
	define('_DASHAGENT_TOLERANCE_HORLOGE', 300);
}

/** Longueur minimale acceptée pour le secret partagé. */
if (!defined('_DASHAGENT_SECRET_LONGUEUR_MIN')) {
	define('_DASHAGENT_SECRET_LONGUEUR_MIN', 32);
}

/** Répertoire de travail (sauvegardes, archives de mise à jour, rollbacks). */
if (!defined('_DASHAGENT_DIR_TRAVAIL')) {
	define('_DASHAGENT_DIR_TRAVAIL', _DIR_TMP . 'dashagent/');
}

/** Taille maximale d'un fichier téléchargé par l'agent (archives de mise à jour). */
if (!defined('_DASHAGENT_TAILLE_MAX_ARCHIVE')) {
	define('_DASHAGENT_TAILLE_MAX_ARCHIVE', 128 * 1024 * 1024);
}

/** Nombre de lignes INSERT regroupées par requête dans les sauvegardes SQL. */
if (!defined('_DASHAGENT_DUMP_LOT')) {
	define('_DASHAGENT_DUMP_LOT', 200);
}
