# Test d'intégration sur un SPIP réel

Les deux suites de `tests/` vérifient la cohérence du code sans SPIP. Celle-ci
installe un vrai SPIP, y active les deux plugins, et déroule le parcours complet
dans un navigateur : création d'un site, appairage, synchronisation signée,
purge de cache, sauvegarde de base et restauration du dump obtenu.

C'est elle qui a mis au jour l'incompatibilité SQLite de la sauvegarde, le
recalcul de liste qui désactivait les plugins, un bouton de mise à jour du core
qui n'a jamais pu fonctionner, et une migration de schéma qui s'exécutait sur le
code d'avant le remplacement — des défauts que la seule analyse statique ne
pouvait pas voir.

## Pré-requis

- PHP 8 en ligne de commande, avec `sqlite3`, `zip`, `gd` et `sodium` ;
- Node et Playwright avec Chromium ;
- un serveur PHP **multi-processus** : le tableau de bord et l'agent étant ici
  sur le même site, un serveur mono-processus se bloquerait à se répondre à
  lui-même. Le script lance `php -S` avec `PHP_CLI_SERVER_WORKERS=4` et
  `-d opcache.revalidate_freq=0` — voir plus bas ;
- une archive SPIP 4.4 (`SPIP-vX.Y.Z.zip`).

## Exécution

```bash
tests/integration/executer.sh /chemin/vers/SPIP-v4.4.23.zip
```

Le script travaille dans un répertoire temporaire, laisse le site installé pour
inspection, et retourne un code de sortie non nul au premier échec.

Le plugin **SPIP WAF** n'est pas livré ici — c'est une contribution tierce. La
section qui le concerne, graphiques de tendance compris, est simplement sautée
si l'archive n'est pas fournie :

```bash
WAF_ZIP=/chemin/waf-vX.Y.Z.zip tests/integration/executer.sh /chemin/SPIP-v4.4.23.zip
```

`DASHBOARD_TEST_DIR` impose le répertoire de travail. Si l'environnement
n'autorise pas un démon lancé depuis un script, démarrez le serveur à part —
le script détecte un serveur déjà en écoute et le réutilise :

```bash
cd <repertoire>/site && PHP_CLI_SERVER_WORKERS=4 php -d opcache.revalidate_freq=0 -S 127.0.0.1:8321 -t . &
```

Attention : `php -S` retient le répertoire racine résolu à son démarrage.
Réutiliser un serveur déjà lancé pendant que le script réinstalle le site le
laisserait servir l'arbre effacé, et l'installation semblerait réussir sans rien
écrire sur le disque. Deux drapeaux séparent donc les phases :

```bash
DASHBOARD_TEST_PREPARATION_SEULE=1 tests/integration/executer.sh <zip> 8321
cd <repertoire>/site && PHP_CLI_SERVER_WORKERS=4 php -d opcache.revalidate_freq=0 -S 127.0.0.1:8321 -t . &
DASHBOARD_TEST_SANS_PREPARATION=1 tests/integration/executer.sh <zip> 8321
```

### Le serveur intégré n'est pas exempt d'opcache

`php -S` tourne sous le SAPI **`cli-server`**, et non `cli` : c'est donc
`opcache.enable` qui décide, pas `opcache.enable_cli` qu'on croit seul en
cause. Là où opcache est actif par défaut, `opcache.revalidate_freq` vaut deux
secondes — un fichier **réécrit** en cours de parcours peut donc rester
invisible ce laps de temps, en servant l'ancien code.

Le parcours réécrit `config/mes_options.php` pour ramener à zéro le budget
d'une tranche de sauvegarde. Sans ce réglage, la réécriture passait une fois
sur deux à la trappe : l'export se faisait d'un seul tenant, et le contrôle
échouait sans que rien ne désigne opcache. D'où le `-d opcache.revalidate_freq=0`
au démarrage, **et** une seconde de battement dans le parcours : la ceinture
et les bretelles, le serveur pouvant avoir été lancé à la main.

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
| Onglets | « Plugins » et « PHP » se répondent au clic et aux flèches, le compteur suit le tableau, chaque capacité porte son origine, aucune extension PHP n'apparaît parmi les plugins |
| URL des boutons | aucune balise non compilée (`%23NOM`) dans une URL d'action, et chaque opération porte un identifiant numérique |
| Mise à jour du core | une archive de core « plus récente » est servie localement : les fichiers de `ecrire/` sont réellement remplacés, l'ancien core est conservé pour rollback, `config/`, `IMG/`, `local/`, `squelettes/` et `plugins/` sont intacts, l'espace privé répond encore, et le parc enregistre la nouvelle version |
| Chantiers | chaque mise à jour commence par une sauvegarde neuve, affiche son encadré de progression, est menée à son terme par le pilote de la page, et porte au journal la transition de version comme sa conclusion |
| Onglet Serveur | l'onglet est refusé tant que le site géré ne l'a pas autorisé ; une fois permis, le résumé, le parcours d'une table (tri, filtre, pagination), les fichiers de réglage et le phpinfo s'affichent — et aucune empreinte, aucun secret partagé, aucune variable d'environnement sensible n'atteint l'écran |
| Migration du schéma | l'archive annonce aussi une version de schéma plus récente et embarque un palier qui ajoute une colonne : le test vérifie que la colonne existe, que la meta a suivi, et que l'espace privé du site n'est plus bloqué |
| Tendance du WAF | la synchronisation range l'activité jour par jour ; la fiche **et** la vue d'ensemble tracent chacune deux graphiques (jamais un double axe), portent la fenêtre entière, des chiffres non nuls, et des courbes réellement peintes — pixels relus des deux côtés ; la série est continue, le sélecteur découpe la fenêtre sans recharger, et aucun script ne vient d'un hôte extérieur |
| Actions groupées | les cases à cocher ne portent aucune adresse, les quatre files d'adresses signées sont présentes, le décompte suit la sélection, et synchroniser puis vider la sélection atteignent réellement le site |
| Mise à jour de l'agent | une version plus récente est publiée au dépôt local en cours de parcours : le parc s'en aperçoit, le bouton apparaît avec son décompte, le chantier sauvegarde puis remplace l'agent — qui se coupe la parole à lui-même —, la nouvelle version est déployée à côté de l'ancienne, l'agent répond encore, et le bouton disparaît |
| Retrait des scripts de racine | le spip_loader et le spip_check sont supprimés à la demande, sans laisser de dépouille cachée, et un retrait à vide n'est pas une erreur ; le ménage de la racine nomme les deux orthographes de glob |
| Contrôle d'intégrité | sans adresse réglée rien n'est proposé ; le dépôt est refusé tant que le site ne l'a pas accordé, et `op_loader` ne l'ouvre pas ; déposé, la version et l'édition sont lues dans la queue d'un fichier de 300 Ko ; un spip_loader est refusé à sa place ; le retrait écarte le fichier par renommage, et un retrait à vide n'est pas une erreur |
| Site derrière un htpasswd | un gardien répond 401 avant tout traitement : sans identifiants la synchronisation échoue et rapporte le 401, avec eux elle passe ; le mot de passe porte sa marque en base, ne ressort pas du formulaire, survit à un enregistrement à blanc, et le retrait referme la porte |
| Restauration | le dump se rejoue dans une base neuve, contenu intact |

## Ce que le parcours ne couvre pas

**Le repli sur le déploiement d'archive.** Le site de test a SVP — il est livré
avec SPIP — et le parcours vérifie donc le chemin SVP, celui qu'emprunteront
presque tous les sites réels. Le repli ne se déclenche que sur un site sans SVP,
ou dont SVP ignore le plugin : le reproduire ici demanderait de démonter
`plugins-dist/`, ce que la mise à jour du core rétablirait aussitôt.

Ce chemin reste couvert par les tests unitaires : la décision de se rabattre
(`dashboard_chantier_svp_repli()`), le nommage du dossier versionné
(`dashagent_dossier_versionne()`) et l'installation à côté sans écraser
(`dashagent_installer_a_cote()`, sur une arborescence temporaire réelle).

## Reproduire une vraie montée de version, d'une release à l'autre

Le parcours fabrique son archive cible à partir de celle qu'on lui donne : la
version de branche et la version de schéma y sont retouchées, et un palier de
migration y est ajouté. C'est ce qui rend le test autonome — une seule archive
suffit — mais cela ne rejoue pas la vraie succession de paliers d'une release à
l'autre.

Pour reproduire un cas réel (celui qui a servi ici : 4.4.13 → 4.4.23), il faut
deux archives officielles et une installation montée à la main :

1. installer le site depuis l'**ancienne** archive, y déposer les deux plugins,
   puis les activer (le script d'activation est dans `executer.sh`, section
   « Activation et installation des plugins ») ;
2. déposer la **nouvelle** archive, telle quelle, dans un répertoire servi par
   le site — `core-archives/` — accompagnée d'un `index.html` qui la nomme. Le
   tableau de bord relève le nom exact dans cet index ; sans index, il se
   rabat sur les noms d'usage, ce qui ne teste plus rien ;
3. pointer *Configuration → Dashboard* sur ce répertoire, appairer l'agent avec
   `op_core_maj` accordée, synchroniser, puis lancer la mise à jour.

Ce que cette manipulation a montré, et que le parcours autonome ne montrait pas :
la migration du schéma se déroule correctement (`Base migrée en version
2026080300`), mais **le chantier ne va à son terme que s'il continue d'être
poussé**. Quand le tableau de bord héberge lui-même l'agent, le remplacement du
core bloque son propre espace privé entre le swap des fichiers et la migration :
plus personne ne pilote, et le chantier reste en plan. La tâche de fond le
reprend — encore faut-il que le site reçoive du trafic.
