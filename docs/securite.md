# Sécurité

Cet outil donne à un site le pouvoir d'écrire du code sur d'autres sites. C'est
utile, et c'est dangereux. Cette page dit exactement ce qui est protégé, comment,
et ce qui ne l'est pas.

## Modèle de menace

Ce qui est pris au sérieux :

| Menace | Réponse |
|---|---|
| Un tiers découvre l'URL de l'agent | Sans le secret, toute requête est rejetée en 403 |
| Interception réseau | HTTPS obligatoire, vérification du certificat non contournable |
| Rejeu d'une requête capturée | Nonce à usage unique en base + fenêtre temporelle de 5 minutes |
| Falsification d'un argument | La signature couvre l'opération, les arguments, l'horodatage et le nonce |
| Attaque temporelle sur la comparaison de signature | `hash_equals()`, comparaison en temps constant |
| Archive de mise à jour substituée | https imposé, empreinte SHA-256 vérifiable, contenu de l'archive contrôlé |
| Archive piégée (« zip slip ») | Chaque entrée est validée avant extraction ; `..`, chemin absolu ou octet nul refusés |
| Mise à jour ratée | `rename()` réversible, restauration automatique, copies gardées 7 jours |
| Dashboard compromis qui abuserait d'un site | Chaque opération est révocable depuis le site géré |
| Sauvegarde accessible par le web | Stockage sous `tmp/`, hors espace web, plus `.htaccess` de refus |
| Secret lisible dans la base | Chiffré avec le chiffrement du core SPIP quand il est disponible |
| Site géré qui injecterait du code dans la tour de contrôle | Tout ce qui vient d'un agent est rendu inerte à l'enregistrement, et échappé à l'affichage |
| Journal de l'agent empoisonné par un inconnu | L'adresse de l'appelant doit être une adresse IP, en-tête de proxy compris |
| Fichier PHP quelconque déposé à la racine d'un site | Autorisation dédiée, refusée par défaut ; le contenu téléchargé doit être un spip_loader ; l'ancien est conservé |
| Charge utile d'attaque relue depuis le pare-feu | Les données du WAF sont rendues par `textContent`, jamais comme du balisage |

Ce qui n'est **pas** couvert :

- **un tour de contrôle compromis**. Il détient les secrets de tout le parc ;
  quiconque en prend le contrôle prend le contrôle du parc. C'est intrinsèque
  au principe même de l'outil, et cela dicte la manière de l'héberger ;
- **un hébergeur hostile** sur le site géré : l'agent s'exécute chez lui ;
- **la qualité des mises à jour elles-mêmes**. L'outil déploie ce qu'on lui dit
  de déployer ; il ne juge pas du contenu.

## Signature des requêtes

Voir [le protocole](protocole-api.md#calcul-de-la-signature) pour la formule.

Points qui comptent :

- le secret fait 256 bits, tiré de `random_bytes()` ;
- il n'est **jamais** transmis, seulement utilisé comme clef HMAC ;
- il n'est affiché qu'une fois, à sa création ; ensuite seule une empreinte
  tronquée est visible, de part et d'autre, ce qui suffit à vérifier que les
  deux extrémités parlent bien du même secret ;
- chaque site a le sien : compromettre un site ne compromet pas les autres.

## Anti-rejeu

Le nonce est la clef primaire de `spip_dashagent_nonces`. C'est **l'échec de
l'insertion** qui détecte le rejeu, pas une lecture préalable : deux requêtes
concurrentes portant le même nonce ne peuvent pas passer toutes les deux, quel
que soit l'entrelacement. Les nonces sont purgés après 24 h.

La fenêtre temporelle borne la durée de vie d'une requête capturée. Elle est
réglable entre 30 et 3600 secondes ; en cas de dérive d'horloge, l'erreur
`horloge` renvoie l'heure de l'agent pour faciliter le diagnostic.

## Autorisations

Sur le **site géré**, configurer l'agent est réservé aux webmestres — pas aux
simples administrateurs. Le raisonnement : l'agent porte des droits d'écriture
sur le code du site, le confier à un statut plus large élargirait silencieusement
ses pouvoirs.

Sur le **tour de contrôle**, trois niveaux :

| Action | Qui |
|---|---|
| Voir le parc et les fiches | administrateurs non restreints |
| Synchroniser (lecture) | administrateurs non restreints |
| Purger, sauvegarder, mettre à jour | webmestres |
| Consulter l'état du serveur | webmestres |
| Créer, modifier, supprimer un site | webmestres |

Lire l'état d'un parc et agir dessus ne relèvent volontairement pas du même
droit.

## Ce qu'un site géré nous répond n'est jamais du balisage

Le sens de la flèche compte. On protège l'agent de ce que le tableau de bord lui
envoie ; il faut aussi protéger le tableau de bord de ce que l'agent lui répond.

Un site géré peut être compromis — c'est même l'une des raisons de superviser un
parc. Sa réponse traverse alors une signature valide (le secret est sur le site,
l'attaquant l'a), et son inventaire s'affiche dans l'espace privé de la tour de
contrôle, devant un webmestre qui détient les secrets de **tous** les autres
sites. Un numéro de version valant `<svg onload="…">` suffirait : un site pris
prendrait le parc entier. La promesse « compromettre un site ne compromet pas
les autres » y passerait.

**L'échappement de SPIP ne suffit pas à l'empêcher.** `interdire_scripts()`, que
le compilateur applique aux champs SQL, ne neutralise que `<?php`, `<base>` et,
dans l'espace privé, `<script>` et `<iframe>`. `<svg onload>`, `<details
ontoggle>`, `<b onmouseover>` passent au travers — vérifié sur SPIP 4.4.23.

Deux barrières, donc :

- **à l'entrée**, `dashboard_inerte()` retire le balisage de tout ce qui vient
  d'un agent avant que cela n'atteigne la base — inventaire, versions, noms de
  plugins, messages d'erreur, entrées de journal, messages de chantier. Un
  inventaire n'a aucune raison de porter un chevron ; ce qui est enregistré est
  donc déjà inoffensif, quelle que soit la manière dont un squelette le rendra ;
- **à l'affichage**, les champs que SPIP ne protège pas sont échappés
  explicitement (`|entites_html`). Cette seconde barrière couvre les inventaires
  relevés avant ce correctif, et rattrape un futur champ qu'on aurait oublié de
  filtrer à l'entrée.

Le `phpinfo()` d'un site, lui, est du HTML par nature : il est rendu dans une
`<iframe sandbox>` d'origine opaque, où aucun script ne s'exécute. Tout le reste
de l'onglet *Serveur* est construit par `textContent`, jamais par `innerHTML`.

## Le spip_loader, et pourquoi il a son autorisation

`spip_loader.php` installe ce qu'on lui dit d'installer. Déposé à la racine web,
il est appelable par n'importe qui. C'est donc à la fois le fichier le plus utile
à tenir à jour et le plus dangereux à remplacer à distance.

Trois barrières, indépendantes :

- **`op_loader`**, refusée par défaut et qu'aucune autre autorisation n'ouvre.
  Un site peut accorder la mise à jour de son core sans accorder celle-ci ;
- **le contrôle du contenu**, avant écriture : du PHP, moins d'un mégaoctet, et
  les marques d'un spip_loader. Cela n'empêche pas un miroir hostile de servir un
  spip_loader modifié — rien ne le pourrait sans signature —, mais cela exclut
  qu'une erreur d'adresse ou une page d'erreur HTML se retrouve exécutable à la
  racine d'un site ;
- **https obligatoire**, des deux côtés : le tableau de bord refuse d'enregistrer
  une adresse en clair, et l'agent refuse de la suivre.

L'ancien fichier est renommé en `.spip_loader.php.dashagent-<horodatage>`. Le
point initial le soustrait au balayage de SPIP et le rend non appelable sous son
ancien nom.

## L'adresse de l'appelant, côté agent

L'agent journalise chaque requête, **y compris celles qu'il refuse** — donc
celles de qui n'a pas le secret. L'adresse enregistrée vient de `REMOTE_ADDR`,
sauf si le site déclare `_DASHAGENT_PROXY_DE_CONFIANCE`, auquel cas
`X-Forwarded-For` l'emporte. Or cet en-tête est écrit par le client : sans
contrôle, un inconnu déposerait dans le journal du site — lisible dans son espace
privé — le contenu de son choix. Ce qui en sort doit donc passer
`filter_var(…, FILTER_VALIDATE_IP)`, faute de quoi on retombe sur l'adresse du
socket.

## La consultation de l'état d'un serveur

L'onglet *Serveur* d'une fiche lit, sur le site géré : la configuration de PHP
(`phpinfo()`), l'état de la base, le contenu des tables, et trois fichiers de
réglage — `.htaccess`, `config/mes_options.php`, `squelettes/mes_fonctions.php`.

C'est, de loin, la capacité la plus indiscrète de l'outil, et elle est traitée
comme telle.

**Elle est refusée par défaut.** Il faut cocher *Consulter l'état du serveur*
dans la configuration de l'agent, sur chaque site géré, séparément des autres
autorisations. Aucune mise à jour n'en dépend : un parc entier peut être
maintenu sans jamais l'accorder.

**Rien n'est conservé sur le tour de contrôle.** Ces informations transitent à
la demande, pour l'affichage, et ne sont écrites nulle part — ni dans
l'inventaire, ni dans le journal. Recopier le `phpinfo()` d'un parc dans une
seule base reviendrait à en faire une cible de choix.

**Ce qui ressemble à un identifiant est masqué avant de partir**, par l'agent,
donc avant que cela ne traverse le réseau. Deux critères :

- le **nom** — `pass`, `secret`, `token`, `key`, `cle`, `salt`, `alea`,
  `cookie`, `auth`, `dsn`, `private`, plus `low_sec` et `prefs` qui sont propres
  à SPIP. C'est ce qui protège les empreintes de `spip_auteurs`, les aléas de
  session, et les variables d'environnement où bien des hébergeurs déposent le
  mot de passe de la base ;
- la **forme** — une valeur du type `https://utilisateur:motdepasse@hôte/` est
  masquée quel que soit le nom qui la porte.

La table `spip_meta` reçoit un traitement particulier : ses deux colonnes, `nom`
et `valeur`, n'ont rien de sensible en soi, mais certaines *lignes* le sont — le
secret partagé de l'agent, les clés du site. C'est alors la ligne qui décide.

**Filtrer sur une colonne masquée est refusé** : sans cela, on devinerait une
empreinte caractère par caractère, et le masquage ne servirait plus à rien.

**Tout est en lecture seule.** Aucune écriture, aucune suppression, aucun ordre
SQL reçu de l'extérieur : le nom de la table et celui des colonnes sont
retrouvés dans le schéma que rend le moteur, jamais repris du texte reçu ; la
valeur d'un filtre passe par `sql_quote()` ; le sens du tri se réduit à deux mots
choisis dans le code. La liste des fichiers lisibles est fermée — `connect.php`,
qui porte les identifiants de la base, n'y figure pas et aucun chemin ne peut
l'atteindre.

Ces règles sont vérifiées par `tests/test_protocole.php`, et le parcours
d'intégration contrôle qu'aucune empreinte ni aucun secret n'atteint l'écran.

**Ce qui reste exposé, malgré tout.** Le masquage repose sur des noms : une clé
d'API rangée dans une constante appelée `_REGLAGE_7` passera au travers. Un
contenu éditorial confidentiel est lisible tel quel, puisque c'est précisément
ce qu'on vient consulter. N'accordez cette autorisation qu'aux sites dont vous
êtes responsable, et seulement le temps d'un diagnostic si le contenu est
sensible.

## Ce qu'une mise à jour du core ne touche jamais

`config/`, `IMG/`, `local/`, `tmp/`, `plugins/`, `squelettes/`, `lib/`,
`extensions/`, `sites/`. Cette liste est vérifiée par les tests
(`tests/test_protocole.php`) : aucun répertoire déclaré remplaçable ne peut s'y
trouver.

## Les alertes, et ce qu'elles font sortir du parc

Deux canaux sortent de la tour de contrôle, et il faut savoir ce qu'ils
emportent.

**Le courriel** part par le serveur de messagerie du site. Il contient des
titres de sites, des numéros de version et des décomptes — pas d'adresse
d'agent, pas de secret, aucun détail d'erreur. Les titres viennent des sites
gérés : ils sont donc **neutralisés** de leurs caractères de contrôle avant
d'entrer dans le message. Un retour à la ligne dans un titre fabriquerait
sinon un en-tête de courriel supplémentaire — un `Bcc:`, par exemple — à
partir d'une donnée que le parc ne maîtrise pas.

**La notification du navigateur** transite par le service de distribution du
navigateur : Google pour Chrome, Mozilla pour Firefox, Apple pour Safari.
Elle est **chiffrée de bout en bout** (RFC 8291) : le service relaie sans
pouvoir lire. Il connaît en revanche l'existence de l'envoi, son instant et sa
taille — ce que ni lui ni nous ne pouvons éviter.

Son contenu se limite au résumé d'une ligne (« 3 sites en retard de SPIP,
2 anomalies ») et à l'adresse de la vue d'ensemble. Le détail par site reste
dans le courriel.

La paire de clefs **VAPID** est rangée dans la configuration du plugin, donc
en base. Sa clef privée ne sert qu'à prouver aux services de distribution que
les envois viennent bien de cette tour : elle ne chiffre rien, et sa fuite ne
permettrait pas de lire une notification. Elle permettrait en revanche
d'écrire aux navigateurs abonnés — d'où l'accès à la table
`spip_dashboard_push`, qui est celui de la base tout entière.

S'abonner demande le droit de **configurer le parc**, c'est-à-dire le statut
de webmestre : c'est le même droit que celui de la page où le bouton se
trouve.

## Recommandations d'exploitation

1. **Isoler le tour de contrôle.** Idéalement sur un hébergement dédié, avec
   l'espace privé derrière une authentification supplémentaire (`.htpasswd`,
   filtrage par IP, VPN).
2. **Restreindre par IP** côté agents lorsque le tour de contrôle a une adresse
   fixe. La signature reste la protection principale, mais une liste blanche
   réduit la surface exposée.
3. **N'activer les mises à jour que sur les sites où on en a besoin**, et les
   désactiver le reste du temps.
4. **Relire les journaux.** Chaque agent conserve la trace de toutes les
   requêtes reçues, y compris les refus, avec leur origine. Une série de
   `signature_invalide` venant d'une adresse inconnue mérite un regard.
5. **Renouveler les secrets** lors d'un changement d'équipe : générer un nouveau
   secret sur l'agent, le reporter sur la fiche du site. L'ancien cesse
   immédiatement de fonctionner.
6. **Ne pas activer « http en clair »** ailleurs qu'en développement local :
   le secret ne circule pas, mais tout le reste, oui — et une réponse falsifiée
   pourrait faire installer n'importe quoi.

## Signaler un problème

Ouvrir une issue publique pour un bug ordinaire ; pour une faille, contacter
directement le mainteneur du dépôt avant toute divulgation.
