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
cp -a "$RACINE/plugins/dashboard" "$RACINE/plugins/dashboard_agent" "$SITE/plugins/"
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
ecrire_plugin_actifs(['dashboard/', 'dashboard_agent/'], false, 'ajoute');
lire_metas();
plugin_installes_meta();
lire_metas();
$actifs = array_keys(unserialize($GLOBALS['meta']['plugin'] ?? '') ?: []);
echo in_array('DASHBOARD', $actifs, true) && in_array('DASHAGENT', $actifs, true)
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
	'url_archives' => url_de_base() . 'zzztest-archives']);
$id_plugin = sql_getfetsel('id_plugin', 'spip_plugins', 'prefixe = ' . sql_quote('ZZZTEST'))
	?: sql_insertq('spip_plugins', ['prefixe' => 'ZZZTEST', 'nom' => 'Plugin de test']);
sql_delete('spip_paquets', 'id_depot = ' . intval($id_depot));
sql_insertq('spip_paquets', ['id_plugin' => $id_plugin, 'id_depot' => $id_depot,
	'version' => '1.0.1', 'etat' => 'test', 'nom_archive' => 'zzztest.zip',
	'src_archive' => 'auto/zzztest/v1.0.1/']);
echo "DEPOT_PRET\n";
PHPEOF
curl -s --noproxy '*' -o /dev/null "$BASE/spip.php"
if ! curl -s --noproxy '*' "$BASE/zz-depot.php" | grep -q DEPOT_PRET; then
	echo "dépôt de test non créé" >&2
	exit 1
fi
rm -f "$SITE/zz-depot.php"

echo "== Parcours fonctionnel"
BASE_URL="$BASE" SITE_DIR="$SITE" TRAVAIL_DIR="$TRAVAIL" node "$TRAVAIL/scenario.mjs"
CODE=$?

echo
echo "Site laissé en place pour inspection : $SITE"
exit $CODE
