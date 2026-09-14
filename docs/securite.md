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
