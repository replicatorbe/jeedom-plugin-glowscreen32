# Changelog

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
