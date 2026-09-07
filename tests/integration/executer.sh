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

if [ -z "$ZIP" ] || [ ! -f "$ZIP" ]; then
	echo "usage : $0 /chemin/vers/SPIP-vX.Y.Z.zip [port]" >&2
	exit 2
fi

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
if ! curl -s --noproxy '*' "$BASE/zz-activer.php" | grep -q PLUGINS_ACTIFS; then
	echo "activation des plugins échouée" >&2
	exit 1
fi
rm -f "$SITE/zz-activer.php"

echo "== Parcours fonctionnel"
BASE_URL="$BASE" SITE_DIR="$SITE" TRAVAIL_DIR="$TRAVAIL" node "$TRAVAIL/scenario.mjs"
CODE=$?

echo
echo "Site laissé en place pour inspection : $SITE"
exit $CODE
