# Test d'intégration sur un SPIP réel

Les deux suites de `tests/` vérifient la cohérence du code sans SPIP. Celle-ci
installe un vrai SPIP, y active les deux plugins, et déroule le parcours complet
dans un navigateur : création d'un site, appairage, synchronisation signée,
purge de cache, sauvegarde de base et restauration du dump obtenu.

C'est elle qui a mis au jour l'incompatibilité SQLite de la sauvegarde et le
recalcul de liste qui désactivait les plugins — deux défauts que la seule
analyse statique ne pouvait pas voir.

## Pré-requis

- PHP 8 en ligne de commande, avec `sqlite3`, `zip`, `gd` et `sodium` ;
- Node et Playwright avec Chromium ;
- un serveur PHP **multi-processus** : le tableau de bord et l'agent étant ici
  sur le même site, un serveur mono-processus se bloquerait à se répondre à
  lui-même. Le script lance `php -S` avec `PHP_CLI_SERVER_WORKERS=4` ;
- une archive SPIP 4.4 (`SPIP-vX.Y.Z.zip`).

## Exécution

```bash
tests/integration/executer.sh /chemin/vers/SPIP-v4.4.23.zip
```

Le script travaille dans un répertoire temporaire, laisse le site installé pour
inspection, et retourne un code de sortie non nul au premier échec.

`DASHBOARD_TEST_DIR` impose le répertoire de travail. Si l'environnement
n'autorise pas un démon lancé depuis un script, démarrez le serveur à part —
le script détecte un serveur déjà en écoute et le réutilise :

```bash
cd <repertoire>/site && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8321 -t . &
```

## Ce qui est vérifié

| Étape | Contrôle |
|---|---|
| Activation | les deux `paquet.xml` sont acceptés, l'installation crée les six tables |
| Espace privé | parc, fiche site et configuration s'affichent sans erreur PHP ni erreur de squelette |
| Création | le formulaire enregistre le site et génère un secret |
| Chiffrement | le secret est stocké chiffré (préfixe `c2:`), jamais en clair |
| Appairage | l'agent accepte les requêtes signées du tableau de bord |
| Synchronisation | version du core, de PHP, de SQL et inventaire des plugins remontés |
| Purge | les fichiers de cache sont réellement supprimés |
| Sauvegarde | le dump est créé, rapatrié, son empreinte SHA-256 vérifiée |
| Mise à jour de plugin | un dépôt SVP local propose une 1.0.1 : l'archive est téléchargée, déployée, et aucun plugin n'est désactivé au passage |
| Rendu | aucune chaîne de langue brute, aucun bloc de squelette non compilé, aucun nom multilingue ni version normalisée à l'écran |
| Restauration | le dump se rejoue dans une base neuve, contenu intact |
