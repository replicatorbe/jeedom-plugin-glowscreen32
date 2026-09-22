# GlowScreen32 — écrans tactiles ESP32 pour Jeedom

Pilote des écrans tactiles ESP32 depuis Jeedom. Un équipement = un écran
physique, reconnu à l'adresse MAC de sa carte, portant jusqu'à six boutons.

- **Multi-écrans par construction** : autant d'équipements que d'écrans, chacun
  avec sa MAC, ses boutons et son compteur de version. Le firmware est
  identique sur toutes les cartes.
- **Six boutons par écran** : libellé, couleur, icône, et ce que l'appui
  déclenche — une commande d'action de Jeedom, ou un scénario.
- **Un point d'entrée HTTP** conforme au contrat d'API v1.1 partagé avec le
  firmware : `layout`, `press`, `ping`, clé en en-tête `X-GLOWSCREEN32-APIKEY`.
- **Enrôlement sans câble** : une carte non déclarée affiche sa propre MAC, il
  suffit de la recopier dans un nouvel équipement.
- **Un aperçu de la grille 3×2** dans la page de configuration, et le moyen de
  lire la réponse exacte que reçoit la carte.

Aucune dépendance, aucun démon. Rien du plugin ne sort de `plugins/glowscreen32`.

## Installation

Plugin Jeedom classique : dépôt Market, ou dossier `glowscreen32` déposé dans
`plugins/`, puis activation.

## Documentation

- [Documentation](docs/fr_FR/index.md)
- [Changelog](docs/fr_FR/changelog.md)

## Licence

AGPL-3.0 — voir [LICENSE](LICENSE).
