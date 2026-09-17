#!/usr/bin/env bash
# Installe un SPIP réel, y active les deux plugins, et déroule le parcours complet.
# Usage : tests/integration/executer.sh /chemin/vers/SPIP-vX.Y.Z.zip [port]
set -u

ZIP="${1:-}"
PORT="${2:-8321}"
RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# Répertoire de travail : surchargeable, certains environnements
# restreignent l'écriture hors d'un emplacement dédié.
TRAVAIL="${DASHBOARD_TEST_DIR:-${TMPDIR:-/tmp}/dashboard-integration}"
SITE="$TRAVAIL/site"
BASE="http://127.0.0.1:$PORT"
export BDD="$SITE/config/bases/spip.sqlite"
# Version de branche annoncée par l’archive de core factice.
CORE_CIBLE="4.4.99"
# Version de schéma annoncée par cette archive : elle déclenche une vraie
# migration de base après le remplacement des fichiers.
BASE_CIBLE="2026090100"

if [ -z "$ZIP" ] || [ ! -f "$ZIP" ]; then
	echo "usage : $0 /chemin/vers/SPIP-vX.Y.Z.zip [port]" >&2
	exit 2
fi

# Le serveur php -S garde le répertoire racine qu'il a résolu au démarrage :
# effacer ce répertoire sous ses pieds le laisse servir un arbre fantôme, et
# l'installation semble réussir sans rien écrire sur le disque. D'où ce
# drapeau, pour préparer le site d'abord et ne démarrer le serveur qu'ensuite :
#   DASHBOARD_TEST_PREPARATION_SEULE=1 tests/integration/executer.sh <zip> <port>
#   cd <site> && php -S 127.0.0.1:<port> -t <site> &
#   DASHBOARD_TEST_SANS_PREPARATION=1 tests/integration/executer.sh <zip> <port>
if [ -z "${DASHBOARD_TEST_SANS_PREPARATION:-}" ]; then
echo "== Préparation du site dans $SITE"
rm -rf "$TRAVAIL"; mkdir -p "$SITE"
unzip -q "$ZIP" -d "$SITE"
# L'archive peut contenir un répertoire racine unique.
if [ ! -f "$SITE/spip.php" ]; then
	interne="$(find "$SITE" -maxdepth 2 -name spip.php | head -1)"
	[ -n "$interne" ] && mv "$(dirname "$interne")" "$SITE.tmp" && rm -rf "$SITE" && mv "$SITE.tmp" "$SITE"
fi
mkdir -p "$SITE/plugins" "$SITE/config/bases"
# Les dossiers portent leur version : on les prend au glob plutôt que de
# figer un numéro que la prochaine montée de version démentirait.
cp -a "$RACINE"/plugins/tourdecontrole-* "$RACINE"/plugins/tourdecontrole_agent-* "$SITE/plugins/"
chmod -R 777 "$SITE/tmp" "$SITE/local" "$SITE/config" "$SITE/IMG" "$SITE/plugins" 2>/dev/null
fi

if [ -n "${DASHBOARD_TEST_PREPARATION_SEULE:-}" ]; then
	echo "== Site préparé, serveur à démarrer manuellement sur $BASE"
	exit 0
fi

# Un serveur déjà en écoute est réutilisé tel quel : c'est plus rapide à
# relancer, et certains environnements n'autorisent pas un démon lancé depuis
# un script. Dans ce cas, démarrez-le vous-même avant :
#   cd <site> && php -S 127.0.0.1:<port> -t <site>
if curl -s --noproxy '*' -o /dev/null --max-time 2 "$BASE/spip.php"; then
	echo "== Serveur déjà en écoute sur $BASE"
else
	echo "== Démarrage du serveur sur $BASE"
	cd "$SITE" && PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$SITE" > "$TRAVAIL/serveur.log" 2>&1 &
	SERVEUR=$!
	cd "$RACINE"
	sleep 2
	# Arrêt par PID : un pkill sur le motif tuerait aussi ce script, dont la
	# ligne de commande contient le même texte.
	trap 'kill "$SERVEUR" 2>/dev/null' EXIT
fi

echo "== Installation de SPIP (SQLite)"
cp "$RACINE/tests/integration/aide-install.mjs" "$TRAVAIL/"
cp "$RACINE/tests/integration/scenario.mjs" "$TRAVAIL/"
BASE_URL="$BASE" node "$TRAVAIL/aide-install.mjs" || { echo "installation échouée" >&2; exit 1; }

# Sans compte administrateur, tout le parcours se déroulerait déconnecté et les
# vérifications passeraient sur des pages vides : on le constate tout de suite.
auteurs="$(php -r '$d=new SQLite3(getenv("BDD"));echo (int)$d->querySingle("SELECT count(*) FROM spip_auteurs");' 2>/dev/null)"
if [ "${auteurs:-0}" -lt 1 ]; then
	echo "installation incomplète : aucun compte administrateur créé" >&2
	exit 1
fi

# L'installeur laisse connect.tmp.php : c'est l'étape finale qui le renomme.
[ -f "$SITE/config/connect.tmp.php" ] && cp "$SITE/config/connect.tmp.php" "$SITE/config/connect.php"
rm -rf "$SITE"/tmp/cache/*

echo "== Activation et installation des plugins"
cat > "$SITE/zz-activer.php" <<'PHPEOF'
<?php
use function SpipLeague\Component\Kernel\param;
require_once __DIR__ . '/vendor/autoload.php';
include_once param('spip.dirs.core') . 'inc_version.php';
include_spip('inc/plugin');
include_spip('inc/meta');
header('Content-Type: text/plain; charset=utf-8');
// Les dossiers portent leur version : on les retrouve au glob plutôt que de
// figer un numéro que la prochaine montée de version démentirait.
$dossiers = [];
foreach (['tourdecontrole', 'tourdecontrole_agent'] as $prefixe) {
	foreach ((array) glob(_DIR_PLUGINS . $prefixe . '-*', GLOB_ONLYDIR) as $chemin) {
		$dossiers[] = basename($chemin) . '/';
	}
}
ecrire_plugin_actifs($dossiers, false, 'ajoute');
lire_metas();
plugin_installes_meta();
lire_metas();
$actifs = array_keys(unserialize($GLOBALS['meta']['plugin'] ?? '') ?: []);
echo in_array('TOURDECONTROLE', $actifs, true) && in_array('TOURDECONTROLE_AGENT', $actifs, true)
	? "PLUGINS_ACTIFS\n" : "ECHEC : " . implode(',', $actifs) . "\n";
PHPEOF
# La toute première requête construit les caches de plugins ; la liste des
# plugins actifs n'est visible qu'au passage suivant, exactement comme lorsqu'on
# recharge la page des plugins dans un navigateur. D'où ces tentatives.
active=""
for _ in 1 2 3; do
	if curl -s --noproxy '*' "$BASE/zz-activer.php" | grep -q PLUGINS_ACTIFS; then
		active="oui"
		break
	fi
done
if [ -z "$active" ]; then
	echo "activation des plugins échouée" >&2
	exit 1
fi
rm -f "$SITE/zz-activer.php"

echo "== Plugin de test et dépôt local (pour la mise à jour)"
mkdir -p "$SITE/plugins/zzztest" "$SITE/zzztest-archives"
cat > "$SITE/plugins/zzztest/paquet.xml" <<'XMLEOF'
<paquet prefix="zzztest" categorie="outil" version="1.0.0" etat="test" compatibilite="[4.1.0;4.*]">
	<nom>Plugin de test</nom>
	<auteur>Test</auteur>
	<licence>GPL 3</licence>
</paquet>
XMLEOF
echo 'VERSION 1.0.0' > "$SITE/plugins/zzztest/marqueur.txt"
# La version 1.0.1, servie en zip par le serveur local
rm -rf "$TRAVAIL/paquet"; mkdir -p "$TRAVAIL/paquet/zzztest"
sed 's/version="1.0.0"/version="1.0.1"/' "$SITE/plugins/zzztest/paquet.xml" > "$TRAVAIL/paquet/zzztest/paquet.xml"
echo 'VERSION 1.0.1' > "$TRAVAIL/paquet/zzztest/marqueur.txt"
(cd "$TRAVAIL/paquet" && zip -qr "$SITE/zzztest-archives/zzztest.zip" zzztest)
# Le catalogue du dépôt, au format que SVP sait lire : c'est lui qu'il relira, et
# c'est de lui qu'il tirera la version disponible. L'écrire à la main en base
# aurait testé notre SQL, pas la chaîne réelle.
taille_zip="$(stat -c%s "$SITE/zzztest-archives/zzztest.zip")"
cat > "$SITE/zzztest-archives/paquets.xml" <<XMLEOF
<?xml version="1.0" encoding="utf-8"?>
<depot>
	<titre>Dépôt de test</titre>
	<type>http</type>
	<url_archives>$BASE/zzztest-archives</url_archives>
</depot>
<archives>
	<archive dtd="paquet">
		<zip>
			<file>zzztest.zip</file>
			<size>$taille_zip</size>
			<date>$(date '+%s')</date>
			<last_commit>$(date '+%Y-%m-%d %H:%M:%S')</last_commit>
			<source>auto/zzztest/v1.0.1/</source>
		</zip>
		<paquet prefix="zzztest" categorie="outil" version="1.0.1" etat="test" compatibilite="[4.1.0;4.*]">
			<nom>Plugin de test</nom>
			<auteur>Test</auteur>
			<licence>GPL 3</licence>
		</paquet>
	</archive>
</archives>
XMLEOF
# Le site de test sert ses archives en http sur la boucle locale.
cat > "$SITE/config/mes_options.php" <<'OPTEOF'
<?php
define('_DASHAGENT_ARCHIVES_HTTP', true);
OPTEOF

cat > "$SITE/zz-depot.php" <<'PHPEOF'
<?php
use function SpipLeague\Component\Kernel\param;
require_once __DIR__ . '/vendor/autoload.php';
include_once param('spip.dirs.core') . 'inc_version.php';
include_spip('inc/plugin');
include_spip('inc/meta');
header('Content-Type: text/plain; charset=utf-8');
ecrire_plugin_actifs(['zzztest/'], false, 'ajoute');
lire_metas();
plugin_installes_meta();
lire_metas();
sql_delete('spip_depots', 'titre = ' . sql_quote('Dépôt de test'));
$id_depot = sql_insertq('spip_depots', ['titre' => 'Dépôt de test', 'type' => 'http',
	'url_archives' => url_de_base() . 'zzztest-archives',
	'xml_paquets' => url_de_base() . 'zzztest-archives/paquets.xml']);

// C'est SVP qui lit le catalogue et en tire les paquets distants : écrire nous-
// mêmes la ligne en base testerait notre SQL, pas la chaîne que le tableau de
// bord déclenchera à distance.
include_spip('inc/svp_depoter_distant');
$lu = svp_actualiser_depot($id_depot);

// Puis les plugins présents sur le disque, et la mise à jour constatée : sans
// ces deux passes, SVP n'a pas de paquet local pour ZZZTEST et l'agent
// retomberait sur le déploiement d'archive.
include_spip('inc/svp_depoter_local');
svp_actualiser_paquets_locaux();
svp_actualiser_maj_version();

$local = sql_fetsel(['pa.version', 'pa.maj_version'], ['spip_paquets AS pa', 'spip_plugins AS pl'],
	['pa.id_plugin = pl.id_plugin', 'pa.id_depot = 0', 'pl.prefixe = ' . sql_quote('ZZZTEST')]);
$actifs = array_keys(unserialize($GLOBALS['meta']['plugin'] ?? '') ?: []);
if (!$lu) {
	echo "ECHEC : catalogue du dépôt de test illisible par SVP\n";
} elseif (!in_array('ZZZTEST', $actifs, true)) {
	echo "ECHEC : zzztest inactif\n";
} elseif (!$local) {
	echo "ECHEC : aucun paquet local SVP pour zzztest\n";
} elseif (!$local['maj_version']) {
	echo "ECHEC : SVP n'annonce pas de mise à jour pour zzztest\n";
} else {
	echo "DEPOT_PRET\n";
}
PHPEOF
curl -s --noproxy '*' -o /dev/null "$BASE/spip.php"
# Comme pour l'activation initiale : la liste des plugins actifs n'est visible
# qu'au passage suivant celui qui l'a recalculée.
depot=""
for _ in 1 2 3; do
	if curl -s --noproxy '*' "$BASE/zz-depot.php" | grep -q DEPOT_PRET; then
		depot="oui"
		break
	fi
done
if [ -z "$depot" ]; then
	echo "dépôt de test non créé, ou plugin de test inactif" >&2
	exit 1
fi
rm -f "$SITE/zz-depot.php"

echo "== Archive de core factice (pour la mise à jour du noyau)"
# L'archive officielle est réutilisée telle quelle, avec deux retouches : la
# version de branche annoncée, et un témoin dans ecrire/ qui prouve que ce sont
# bien les fichiers de l'archive qui sont arrivés sur le site. Le contenu reste
# celui d'un SPIP valide, donc le site fonctionne encore après le remplacement.
mkdir -p "$SITE/core-archives"
# Le nom porte volontairement une capitale, alors que le nom de repli du
# tableau de bord est en minuscules : la mise à jour du core ne peut donc
# aboutir que s'il a bien relevé le nom exact dans l'index du dépôt, au lieu
# de le fabriquer. Un système de fichiers sensible à la casse fait le reste.
cp "$ZIP" "$SITE/core-archives/SPIP-v$CORE_CIBLE.zip"
php "$RACINE/tests/integration/preparer-core.php" "$SITE/core-archives/SPIP-v$CORE_CIBLE.zip" "$CORE_CIBLE" "$BASE_CIBLE" \
	|| { echo "archive de core non préparée" >&2; exit 1; }

# Témoins dans les répertoires que la mise à jour ne doit jamais toucher.
for garde in config IMG local squelettes plugins; do
	mkdir -p "$SITE/$garde"
	echo "temoin-$garde" > "$SITE/$garde/temoin-dashboard.txt"
done

# Le plugin SPIP WAF n'est pas livré ici — c'est une contribution tierce, et le
# dépôt n'a pas à en porter une copie. Quand on lui en donne une, le parcours
# vérifie aussi l'onglet qui lit son tableau de bord ; sinon il le saute et le
# dit. Usage : WAF_ZIP=/chemin/waf-vX.Y.Z.zip tests/integration/executer.sh …
if [ -n "${WAF_ZIP:-}" ] && [ -f "$WAF_ZIP" ]; then
	echo "== Installation du plugin SPIP WAF"
	unzip -qo "$WAF_ZIP" -d "$SITE/plugins"
	dossier_waf="$(find "$SITE/plugins" -maxdepth 1 -name 'waf*' -type d | head -1)"
	cat > "$SITE/zz-waf.php" <<'PHPEOF'
<?php
use function SpipLeague\Component\Kernel\param;
require_once __DIR__ . '/vendor/autoload.php';
include_once param('spip.dirs.core') . 'inc_version.php';
include_spip('inc/plugin');
include_spip('inc/meta');
include_spip('base/create');
include_spip('base/abstract_sql');
header('Content-Type: text/plain; charset=utf-8');
$dossiers = [];
foreach ((array) glob(_DIR_PLUGINS . 'waf*', GLOB_ONLYDIR) as $chemin) {
	$dossiers[] = basename($chemin) . '/';
}
ecrire_plugin_actifs($dossiers, false, 'ajoute');
lire_metas();
plugin_installes_meta();
lire_metas();
// La table n'est créée qu'une fois le registre des tables peuplé : le premier
// passage active le plugin, le second seulement peut la créer.
maj_tables(['spip_waf_events']);
$tables = (array) sql_alltable('%');
echo in_array('spip_waf_events', $tables, true) ? "WAF_PRET\n" : "WAF_INCOMPLET\n";
PHPEOF
	for _ in 1 2 3; do
		curl -s --noproxy '*' "$BASE/zz-waf.php" | grep -q WAF_PRET && break
	done
	rm -f "$SITE/zz-waf.php"
	echo "   plugin WAF : ${dossier_waf:-?}"
fi

echo "== Parcours fonctionnel"
BASE_URL="$BASE" SITE_DIR="$SITE" TRAVAIL_DIR="$TRAVAIL" CORE_CIBLE="$CORE_CIBLE" BASE_CIBLE="$BASE_CIBLE" node "$TRAVAIL/scenario.mjs"
CODE=$?

echo
echo "Site laissé en place pour inspection : $SITE"
exit $CODE
