# GlowScreen32 — écrans tactiles ESP32 pour Jeedom

Pilote des écrans tactiles ESP32 (cartes ESP32-2432S028, « Cheap Yellow Display ») depuis
Jeedom. Un équipement = un écran physique, reconnu à l'adresse MAC de sa carte, portant jusqu'à
**32 tuiles sur 4 pages**. Le firmware des écrans vit dans un dépôt séparé :
[glowscreen32-firmware](https://github.com/replicatorbe/glowscreen32-firmware).

- **Multi-écrans par construction** : autant d'équipements que d'écrans, chacun avec sa MAC, ses
  pages et son compteur de version. Le firmware est identique sur toutes les cartes.
- **Quatre sortes de tuiles** : *action* (une commande ou un scénario), *interrupteur* (allumer
  ou éteindre selon l'état lu), *navigation* (ouvrir une page, sans réseau), et *valeur* —
  l'état d'une porte, de l'alarme, du portail, une température — mise en forme par le plugin,
  avec un sens (ok / attention / alerte) et une péremption honnête.
- **Réactif sans rien ouvrir côté écran** : la carte interroge Jeedom, qui garde la question en
  attente jusqu'à 25 s et répond dès qu'un état change. Une lampe allumée ailleurs se reflète à
  l'écran en une seconde environ.
- **Commandes vers l'écran**, utilisables dans un scénario : afficher un message, ouvrir une
  page, identifier l'écran, le redémarrer, lancer une calibration, vérifier le firmware. Les
  identifiants Wi-Fi s'envoient depuis la page de l'équipement.
- **Diagnostics** remontés par chaque écran : niveau Wi-Fi, adresse IP, réseau, mémoire, temps
  de fonctionnement, cause du dernier redémarrage, présence en ligne.
- **Écran en lecture seule**, refusé côté serveur : un écran d'entrée ne peut rien actionner.
- **Mise à jour du firmware par le réseau**, sous **double verrou fermé par défaut** (global et
  par écran), binaire servi avec un jeton à durée limitée, et retour arrière automatique côté
  carte. On ouvre un écran témoin avant les autres.
- **Enrôlement sans câble** : une carte non déclarée affiche sa propre MAC.
- **Compatibilité du parc** : le contrat d'API négocie un schéma (1, 2 ou 3) ; un écran pas
  encore mis à jour continue de fonctionner.

Aucune dépendance, aucun démon. Rien du plugin ne sort de `plugins/glowscreen32`.

## Installation

Plugin Jeedom classique : dépôt Market, ou dossier `glowscreen32` déposé dans `plugins/`, puis
activation.

## Documentation

- [Documentation](docs/fr_FR/index.md) — configuration, tuiles, contrat d'API, OTA
- [Changelog](docs/fr_FR/changelog.md)

## Licence

AGPL-3.0 — voir [LICENSE](LICENSE).
