<?php
/**
 * Autorisations de l'agent Dashboard.
 *
 * @package SPIP\Dashagent\Autorisations
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Chargement du fichier d'autorisations.
 *
 * @pipeline autoriser
 * @return void
 */
function dashagent_autoriser() {
}

/**
 * Configurer l'agent : réservé aux webmestres.
 *
 * L'agent porte des droits d'écriture sur le code du site : le confier à un
 * simple administrateur reviendrait à élargir silencieusement ses pouvoirs.
 *
 * @param string $faire
 * @param string $type
 * @param int $id
 * @param array $qui
 * @param array $opt
 * @return bool
 */
function autoriser_dashagent_configurer_dist($faire, $type, $id, $qui, $opt) {
	return !empty($qui['webmestre']) && $qui['webmestre'] === 'oui';
}

/**
 * Alias : selon les versions de SPIP, le type transmis pour une page
 * `configurer_xxx` conserve ou non son souligné initial.
 *
 * @param string $faire
 * @param string $type
 * @param int $id
 * @param array $qui
 * @param array $opt
 * @return bool
 */
function autoriser__dashagent_configurer_dist($faire, $type, $id, $qui, $opt) {
	return autoriser_dashagent_configurer_dist($faire, $type, $id, $qui, $opt);
}
