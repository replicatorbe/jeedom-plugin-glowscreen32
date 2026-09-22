# Changelog

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
  version est lue dans le descripteur ESP-IDF du binaire, le SHA-256 et la
  taille sont calculés sur le fichier écrit.
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
