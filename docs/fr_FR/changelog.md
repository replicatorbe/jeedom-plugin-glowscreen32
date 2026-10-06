# Changelog

## 3.2

Pages masquables (contrat v3.1). **Le schéma ne change pas, le firmware non
plus** : une page masquée est simplement absente de la réponse `layout`.

- Case **« Afficher la page »** par page (onglet Boutons). Une configuration
  existante reste entièrement visible. Au moins une page doit rester affichée :
  l'interface et l'enregistrement refusent de tout masquer.
- Les pages masquées sont omises, les visibles **renumérotées** 0 … n-1 dans
  leur ordre de configuration (masquer la première fait démarrer l'écran sur la
  suivante). Un `parent` masqué est remplacé par le plus proche ancêtre visible,
  à défaut la page 0 ; un bouton de navigation vers une page masquée est
  retiré ; les `id` sont recalculés sur ce qui est servi, dans les schémas 1, 2
  et 3.
- Changer la visibilité change `version` : la carte recharge sa mise en page.
  Le refus d'un `press` dont le bouton a changé depuis le dernier `layout`
  servi couvre l'instant de transition.
- Commandes pour les scénarios : **Afficher la page**, **Masquer la page**,
  **N'afficher que la page** (liste des pages « position — titre »), **Afficher
  toutes les pages**, et l'information **Pages affichées**. Elles relisent
  l'équipement juste avant d'écrire et ne modifient que la visibilité ;
  masquer la dernière page visible est refusé et journalisé.
- La commande à distance `page` reçoit la position de configuration et la
  traduit en numéro servi au moment de la livraison ; vers une page masquée,
  elle est sans effet (journalisé).
- « Voir ce que la carte reçoit » et l'aperçu de la grille montrent les pages
  masquées comme telles.

## 3.1

Corrections issues d'une revue complète. Le schéma ne change pas.

- **Un bouton qui ne se résout plus garde son rang** (commande supprimée ou
  recréée). Il était retiré de l'aplatissement : tous les `id` suivants
  reculaient sans que `version` change, et un appui jouait **le bouton d'à
  côté**. Il est désormais servi inerte (`state` / `value` nuls), son `press`
  rend `unknown_button`, et le défaut est journalisé une fois.
- **Garde-fou de `press`** : refusé si le bouton à ce rang a changé depuis le
  dernier `layout` servi à cet écran, dans ce schéma.
- **Coût d'un `ping` divisé** : commandes résolues une seule fois par requête,
  `states` et `values` en une passe, recalcul d'une requête retenue toutes les
  15 s, contact/Wi-Fi/diagnostics écrits seulement quand ils changent (`up`,
  `heap`, `blk` au plus toutes les 5 min ou sur variation de plus de 10 %).
- Page refusée à l'enregistrement si elle dépasse la grille réelle ; libellés,
  titres et libellés de tuile refusés au-delà de leur borne (en caractères) ;
  bouton « action » sans cible ou visant une information refusé.
- **Mot de passe Wi-Fi hors du cache Jeedom** : fichier 0600 dans
  `data/secrets` (exclu des sauvegardes et du déploiement), effacé à la
  livraison ou à l'expiration. Mot de passe de 1 à 7 caractères, ou de 64 non
  hexadécimaux, refusé.
- « Dupliquer » fonctionne : la copie part sans MAC, verrou OTA, firmware ni
  signature.
- **Avertissement « commande sensible »** (portail, porte, garage, serrure,
  alarme) dans le bloc du bouton et ⚠ dans l'aperçu, avec rappel de la lecture
  seule.
- `fwfile` : jeton lié au SHA-256 du binaire, refusé si un verrou OTA a été
  refermé depuis son émission ; dépôt de firmware atomique.
- Requête retenue libérée ou fantôme : ne retire plus de commande de la file.
- API : clé ou paramètres en tableau refusés, `id` strictement numérique, `fw`
  illisible → `bad_request`, journaux sans caractère de contrôle, UTF-8
  invalide réparé, `Content-Length` sur toutes les réponses.
- `poll` hors bornes → 30 (y compris au-dessus de 3600) ; bandeau au format
  « 21,4 » ; `value` tronquée journalisée ; « <5 » n'est plus effacé.
- Page : aperçus périmés ignorés, réglages en cours conservés au changement de
  page, « id (schéma 3) » et correspondance des id par schéma, exemple curl en
  schéma 3.

## 3.0

Tuiles « valeur » et écrans en lecture seule — **schéma 3**. Cette fois le
numéro de schéma change, et il le faut : une carte de schéma 2 prendrait une
tuile « valeur » pour un bouton.

- **Négociation.** En-tête `3` → schéma 3 ; `2` → schéma 2 **sans aucune tuile
  « valeur »**, `id` recalculés sur ce qui reste ; `1` (ou absent) → inchangé,
  octet pour octet. `press` résout le rang dans l'aplatissement du schéma de la
  requête : **un même bouton n'a pas le même `id` d'un schéma à l'autre.**
- **Mode « Valeur ».** Une tuile qui montre une commande d'information au lieu
  d'agir : porte, alarme, chauffage, température. Le plugin met en forme
  (`value`, 16 caractères au plus) et donne un sens (`tone` : neutre, normal,
  attention, alerte) ; la carte ne fait qu'afficher. Binaire : libellé et sens
  pour 0 et 1, inversion. Numérique : unité, décimales, seuils croissants.
  Texte : table valeur → libellé + sens. Préremplissage d'après le type
  générique Jeedom, et **aperçu de la vraie valeur mise en forme** dans la page :
  le sens d'un « 1 » varie d'un module à l'autre.
- **Péremption d'une tuile valeur** : « — » si l'équipement de la commande est
  désactivé ou en alerte de communication Jeedom ; une valeur numérique périme
  en plus au-delà de 60 min sans collecte (réglable, 0 = jamais). Binaire et
  texte : pas de seuil par défaut — une porte fermée depuis trois jours
  n'émet rien et dit vrai.
- **`values`** dans le `ping` du schéma 3 (même longueur que `states`) ; `rev`
  le couvre, et le listener surveille les commandes des tuiles valeur : une
  porte qui s'ouvre libère le ping retenu dans la seconde.
- **Lecture seule** (case de l'onglet Écran) : `ui.readonly` en schéma 3, et
  **tout `press` de cet écran est refusé par le plugin** (`403 read_only`, avant
  toute résolution), quel que soit le firmware.
- Un `press` sur une tuile valeur répond `unknown_button`, journalisé.
- Les tests `>= schéma courant` sont devenus `>= schéma 2` : attente longue,
  `features`, `rev` et commandes à distance restent servis en schéma 2 et 3.

## 2.2

Réactivité et exploitation du parc. **Le schéma reste 2** : la v2.2 n'ajoute que
des paramètres de requête optionnels et des champs de réponse qu'une carte
v2.0/v2.1 ne lit pas. **Le schéma 1 est servi octet pour octet comme avant.**

- **L'API n'enregistre plus jamais l'équipement** (règle normative du contrat
  v2.2). Le dernier contact, la version du firmware et la péremption du bandeau
  étaient écrits par `save(true)` — l'eqLogic entier, tel que chargé au début
  de la requête : une configuration enregistrée entre-temps était écrasée sans
  un mot. Ils vont désormais dans les commandes d'information (contact,
  firmware) et dans le cache (péremption). Le tableau du parc, la détection
  hors ligne et la page de l'équipement lisent ces commandes.
- **`ping` en attente longue** (`wait`, `rev`), schéma 2 seulement : un
  changement d'état fait ailleurs atteint l'écran en une seconde environ au
  lieu de `poll`. Les réponses `layout` et `ping` du schéma 2 portent
  `features: {wait: 25, cmd: true}` et `rev`. Une requête retenue relit un
  compteur de réveil quatre fois par seconde **sans requête SQL** ; un
  *listener* Jeedom sur les commandes d'état des boutons et sur celle du
  bandeau fait bouger ce compteur. Une nouvelle requête du même écran libère la
  précédente.
- **Commandes à distance** : Redémarrer, Identifier, Message, Page, Calibrer,
  Vérifier firmware — utilisables depuis le dashboard et depuis un scénario.
  File de 8 commandes par écran, durée de vie 10 minutes, livraison **au plus
  une fois**, `seq` croissant et persistant. Le changement de Wi-Fi, qui fait
  transiter un mot de passe, ne part que de la page de l'équipement.
- **Diagnostics** joints au `ping` par le firmware 2.2 (`up`, `rst`, `heap`,
  `blk`, `ip`, `ssid`) : six nouvelles commandes d'information ; Niveau Wi-Fi,
  Mémoire libre et Plus gros bloc libre sont historisés. Adresse IP, durée de
  fonctionnement et cause du dernier redémarrage apparaissent au tableau du
  parc.
- L'avertissement « bouton incomplet » n'est plus écrit à chaque `ping`, mais
  aux transitions seulement — indispensable avec l'attente longue.
- Textes d'aide corrigés : « hors ligne » au-delà de `3 × 2 × poll`, et non
  « trois intervalles ». Nom d'écran et titres de page échappés dans la page
  (XSS).

## 2.1

Trois corrections de confort, aucune rupture. **Le numéro de schéma ne bouge
pas** : la v2.1 n'ajoute qu'un paramètre de *requête* optionnel, et un paramètre
qu'un serveur ne connaît pas, il l'ignore. Un firmware v2.0 dialogue avec ce
plugin sans rien changer, et réciproquement.

- **Le journal ne déborde plus quand le bandeau périme.** `infoText()`
  journalisait à chaque appel, c'est-à-dire à chaque `ping` et à chaque
  `layout` : huit lignes rigoureusement identiques pour un seul
  enregistrement, puis deux par minute et par écran, indéfiniment — de quoi
  noyer le journal au moment précis où l'on vient y chercher autre chose. Le
  message est conservé (une commande qui n'est plus collectée est une vraie
  panne, et silencieuse), mais il n'est écrit **qu'aux transitions**, retour à
  la normale compris. C'est déjà ce que fait `noteFirmware()` pour la version
  de la carte.
- **Commande « En ligne »** (binaire) et **cron d'une minute**. Jusqu'ici
  `isOnline()` n'alimentait qu'un pictogramme dans le tableau du parc : aucun
  scénario ne pouvait réagir à un panneau mural devenu noir. Elle passe à 1 dès
  qu'un appel arrive, et le cron la remet à 0 — un écran hors ligne est
  justement celui qui n'appelle plus, personne ne peut poser le zéro à sa
  place.
- **Commande « Niveau Wi-Fi »** (numérique, dBm), alimentée par le paramètre
  optionnel `rssi` du `ping`. C'est la première cause de panne du projet et la
  plus trompeuse : les `ping` passent encore là où un `press` se perd (−88 dBm)
  et où un OTA meurt à 2 % (−92/−93 dBm). Le bandeau l'affiche déjà sous
  −75 dBm, mais il faut se tenir devant l'écran pour le lire.
- **Seuil « hors ligne » recalculé sur `3 × 2 × poll`.** La carte espace ses
  `ping` jusqu'à `2 × poll` quand son écran est atténué (contrat v2.1) ; le
  seuil devait suivre, sans quoi **tout le parc serait passé hors ligne chaque
  nuit** alors que chaque carte fonctionne parfaitement. Une alerte qui se
  déclenche toutes les nuits sans raison est une alerte à laquelle on cesse de
  croire — et elle se tairait le jour où un écran meurt pour de bon. Le prix
  est assumé : au réglage par défaut, un écran réellement mort est signalé en
  4 minutes au lieu de 2 min 30.
- Les deux nouvelles commandes sont **créées rétroactivement** sur les
  équipements existants à la mise à jour du plugin : rien à rouvrir ni à
  réenregistrer à la main.

## 2.0

Le schéma 2 du contrat d'API : des pages, une grille réglable, un bandeau — et
**la négociation de schéma**, sans laquelle rien de tout cela ne pouvait être
publié.

- **Négociation de schéma.** La carte annonce ce qu'elle sait lire dans l'en-tête
  `X-GLOWSCREEN32-SCHEMA`. Absent, vide ou `1` → **le schéma 1 à l'identique,
  octet pour octet**. `2` ou plus → le schéma 2. Le plugin ne répond jamais
  au-dessus de ce qui est annoncé.
  C'est ce qui rend la mise à jour du parc possible : le plugin se déploie en une
  seconde et d'un seul coup, alors que les écrans passent en OTA un par un, sur
  plusieurs jours. Sans négociation, publier cette version rendait **tous** les
  écrans inutilisables au même instant — et un écran qui n'affiche plus rien ne
  peut plus recevoir l'OTA qui le réparerait. **Le plugin part toujours en
  premier, le firmware ensuite.**
- **Jusqu'à 32 boutons, sur 4 pages de 12.** Le tableau `buttons` de la
  configuration reste **plat** : chaque bouton porte simplement sa `page` et sa
  `slot`. Une configuration antérieure se lit donc telle quelle — un bouton sans
  page ni case tombe sur la page d'accueil, à la case de son rang, c'est-à-dire
  exactement là où il était. **Aucun script de migration à lancer.**
- **Grille réglable** : 3×2, 3×3, 4×2 ou 4×3, **3×3 par défaut**. Réduire la
  grille ne perd aucun bouton : ceux dont la case n'existe plus sont déplacés
  vers la première case libre, et le journal le dit.
- **Mode de bouton « navigation »** : le bouton ouvre une autre page. Il ne
  commande rien, son état vaut toujours `null`, et **la carte y répond
  elle-même, sans réseau** — un écran coupé de Jeedom continue de naviguer.
- **Bandeau** : une commande d'information au choix, **formatée par le plugin**
  (arrondie, avec son unité, seize caractères au plus), plus une horloge
  facultative calée sur l'heure du serveur. Le décalage horaire est calculé
  depuis le fuseau de Jeedom, **heure d'été comprise** — pas de valeur en dur.
- **Une valeur de bandeau trop vieille n'est pas affichée** : le champ `info`
  vaut alors `null`, et le journal dit l'écran, la commande et l'âge réel. Seuil
  réglable par écran (`info_max_age`), **60 minutes par défaut, 0 pour ne jamais
  périmer** — les capteurs n'ont pas tous la même cadence. Le cas s'est
  présenté : la commande météo choisie n'avait jamais été collectée, son cron ne
  tournant pas, et l'écran aurait affiché la même température indéfiniment.
  C'est la date de **collecte** qui est lue, pas celle du dernier changement de
  valeur : une température stable à 18 °C depuis deux heures est fraîche, et se
  fier à `valueDate` l'aurait effacée à tort. La péremption ne fait **pas**
  bouger `version` — c'est un changement d'état, il voyage dans le `ping`.
- **Vocabulaire d'icônes fermé, et une table d'alias.** `icon` devient une liste
  déroulante : le firmware convertit le nom en identifiant numérique au parsing
  et ne sait dessiner que ceux qu'il connaît. Un champ libre laissait configurer
  des icônes qui ne s'afficheraient jamais. Un nom hors vocabulaire dont
  l'intention est claire — `fire`, `volet`, `temperature` — est **résolu vers
  son équivalent** plutôt que perdu ; ce qui n'est résolu par rien vaut `none`
  **et laisse une ligne de journal nommant l'écran et le bouton**, pour qu'on
  sache lequel corriger.
- **En schéma 1, `icon` est un passe-plat : la chaîne stockée, telle quelle.**
  Ni minuscules, ni alias, ni `none`, ni vide — exactement ce que faisait la
  v1.4, où le champ était du texte libre. Le schéma 1 est un contrat figé, pas
  un endroit où appliquer les règles du schéma 2 : c'est précisément à cela que
  sert la négociation. La promesse « octet pour octet » devient ainsi vraie
  **par construction, pour tous les écrans**, sans exception à retenir.
- **Aplatissement du schéma 1.** Un écran configuré avec des pages, interrogé
  sans en-tête, renvoie ses **six premiers boutons**, **boutons de navigation
  exclus**, renumérotés de 0 à 5. Et `press` résout le rang reçu **dans cet
  aplatissement-là** : une carte de schéma 1 qui envoie le rang 2 désigne le
  troisième bouton de *sa* liste, pas le bouton d'id global 2.
- **Les dépassements sont journalisés, jamais absorbés en silence** : plus de 32
  boutons ou plus de 12 par page sont refusés à l'enregistrement ; une page
  pleine, un bouton déplacé ou une réponse au-delà de 8 192 octets laissent une
  ligne de journal.
- La signature de mise en page tient compte des pages, de la grille, du
  balayage, de l'horloge, de la commande du bandeau, et de la page, de la case
  et de la cible de navigation de chaque bouton. **Sans quoi `version` ne
  bougerait pas, et aucun écran ne se redessinerait** — l'enregistrement
  réussirait, la page montrerait la nouvelle mise en page, et le mur l'ancienne.
- Les traductions anglaises, en retard depuis la 1.2, sont à jour.

## 1.2

La mise à jour du firmware par le réseau (contrat d'API v1.4).

- **`action=firmware`** : la carte annonce la version qu'elle exécute, le plugin
  répond `update: false` ou l'objet complet avec `version`, `url`, `sha256` et
  `size`. La carte vérifie l'empreinte avant de basculer.
- **Double verrou d'autorisation, tous deux fermés par défaut** : `ota_enabled`,
  global au plugin, et `ota_allowed`, propre à chaque écran. Les deux doivent
  être ouverts pour qu'une mise à jour parte. Le verrou par écran permet le
  déploiement progressif — un seul écran témoin, vérifié, puis les autres ; le
  verrou global arrête net la propagation d'un firmware défectueux. Une carte
  bloquée reçoit exactement la réponse d'une carte à jour : elle ne peut pas
  faire la différence, donc pas passer outre.
- **Dépôt du firmware depuis la page du plugin.** Un fichier qui ne commence pas
  par l'octet `0xE9` est refusé — ce n'est pas une image d'application ESP32. La
  version est lue dans le binaire, derrière le marqueur `GLOWSCREEN32-FW:` que
  le firmware y grave ; le SHA-256 et la taille sont calculés sur le fichier
  écrit. **Un binaire sans marqueur est refusé** : sans version, il n'y a rien à
  comparer.
  Le descripteur `esp_app_desc_t` de l'image ne convient pas — il vient des
  bibliothèques Arduino précompilées du framework, annonce
  « esp-idf: v4.4.7 … » dans tous nos binaires, et un OTA fondé dessus ne se
  déclencherait donc jamais.
- Le binaire est rangé dans `data/firmware/`, **exclu du déploiement** : un
  `deploy-plugin.sh` n'efface pas le firmware déposé. `data/firmware/.htaccess`
  rouvre les `.bin` que le `Deny from all` de `data/` interdirait à la carte.
- **Suivi du parc** : la version annoncée par chaque écran est retenue (et
  n'est écrite que lorsqu'elle change), exposée en commande d'information
  « Version du firmware », et affichée dans le tableau du parc à côté de l'état
  des deux verrous.
- **Chaque décision d'OTA est journalisée** : la version annoncée, la réponse,
  et le ou les verrous qui ont bloqué le cas échéant.
- `tests/check-classes.php` vérifie en plus que les deux verrous sont toujours
  exigés ensemble, que `.deployignore` protège le firmware déposé, et que
  `data/firmware/.htaccess` laisse passer les `.bin`.

## 1.1

Deux corrections issues d'un essai sur matériel réel.

- **Mode de bouton** (contrat d'API v1.3). Un bouton porte désormais un mode.
  En « Action simple » il joue toujours la même commande, comme avant ; en
  « Interrupteur » le plugin lit l'état et joue la commande **inverse** — un
  appui allume, le suivant éteint. Un bouton lié à la seule commande
  « Allumer » d'un Shelly n'éteignait jamais : c'est corrigé.
- Un bouton interrupteur **sans commande d'état est refusé à la sauvegarde**,
  avec le numéro de l'emplacement fautif : sans état, rien ne permet de décider
  du sens.
- La commande « Basculer » d'un équipement est acceptée comme configuration
  alternative, mais elle n'est jouée qu'en secours : une commande « basculer »
  désynchronisée inverse l'état affiché sur l'écran.
- **`id` est maintenant le rang du bouton** (0 à 5) et non plus un identifiant
  de commande Jeedom, dans `layout` comme dans `press`. La carte ne désigne plus
  ce qu'il faut exécuter : elle désigne le bouton qu'on a touché, et le plugin
  en déduit la commande. Une carte ne peut donc plus nommer une commande
  arbitraire de l'installation. La convention de l'identifiant négatif pour les
  scénarios disparaît, devenue inutile.
- `press` renvoie `pending`, et `state` est l'état **attendu** et non l'état
  constaté : sur du matériel réel, l'état remonte après l'aller-retour avec
  l'équipement. Le `states` du `ping` suivant reste la source de vérité.
- **Le dernier contact est enfin écrit dans la configuration de l'équipement.**
  Il ne vivait que dans une commande d'information, si bien que
  `getConfiguration('lastcontact')` rendait une chaîne vide sur un écran qui
  dialoguait pourtant. Il est arrondi à la minute pour ne pas produire une
  écriture en base à chaque `ping` de chaque écran, et s'affiche en clair dans
  la page, avec une étiquette « hors ligne » au-delà de trois intervalles de
  rafraîchissement.
- L'icône du plugin est de nouveau servie : le `.htaccess` de `plugin_info/`
  bloquait aussi les images, contrairement à celui des autres plugins.

## 1.0

Première version.

- Un équipement par écran ESP32, identifié par son adresse MAC. La MAC est
  normalisée (minuscules, sans séparateur) avant stockage comme avant
  comparaison, et deux écrans ne peuvent pas porter la même.
- Six boutons par écran : libellé, couleur, icône, et une commande d'action ou
  un scénario à déclencher.
- Point d'entrée `core/php/api.php` implémentant le contrat d'API v1.2 :
  `layout`, `press`, `ping`, authentification par l'en-tête
  `X-GLOWSCREEN32-APIKEY` avec repli sur `?apikey=`.
- `ping` renvoie le tableau `states`, dans le même ordre que les boutons du
  `layout` : les pastilles de l'écran restent fraîches même quand une lampe est
  allumée depuis l'application, un interrupteur mural ou un scénario.
- Champ facultatif « Pastille allumée si » par bouton, pour désigner
  explicitement la commande d'information qui porte l'état. À défaut, le lien
  que Jeedom pose lui-même entre l'action et son état est repris.
- Compteur de version propre à chaque écran, incrémenté quand la mise en page
  change — et seulement dans ce cas.
- Trois commandes d'information par écran : version de la mise en page, dernier
  contact, dernier bouton appuyé.
- Aperçu de la grille 3×2 dans la page de configuration, et affichage de la
  réponse `layout` réellement servie.
