<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

/*
 * Un équipement = un écran tactile ESP32.
 *
 * Toute la logique tient en trois idées :
 *   - l'écran est reconnu à son adresse MAC, normalisée en minuscules sans
 *     séparateur et recopiée dans le logicalId pour que la recherche soit un
 *     index et non un parcours ;
 *   - sa mise en page est une liste d'au plus six boutons rangés dans la
 *     configuration, chacun visant une commande d'action, un scénario, ou un
 *     couple allumer/éteindre choisi d'après l'état ;
 *   - un compteur de version dit à la carte qu'elle doit redessiner.
 *
 * Le contrat d'API (docs/fr_FR/index.md) est figé : ce qui est produit ici pour
 * « layout », « press » et « ping » ne change pas sans le décider là-bas
 * d'abord, le firmware étant écrit en face.
 *
 * ⚠ Depuis le contrat v1.3, le champ « id » d'un bouton est son RANG dans la
 * mise en page (0 à 5) et non un identifiant de commande Jeedom. La carte ne
 * désigne donc plus ce qu'il faut exécuter : c'est le plugin qui le décide, à
 * partir de la configuration du bouton et de son état courant. Une carte
 * compromise ne peut plus déclencher que les boutons de son propre écran, et
 * seulement dans le sens que le plugin juge pertinent.
 */
class glowscreen32 extends eqLogic {

    /*
     * Les plafonds du contrat v2.0. Ils sont NORMATIFS des deux côtés : les
     * dépassements sont refusés à l'enregistrement, et journalisés — jamais
     * absorbés en silence, ce qui était le défaut de la v1.4 côté firmware.
     *
     *   MAX_BUTTONS          — 32 boutons par écran (struct Layout en NVS)
     *   MAX_PAGES            — 4 pages, au-delà on cherche un bouton au lieu
     *                          de l'appuyer
     *   MAX_BUTTONS_PER_PAGE — 12, soit cols ≤ 4 × rows ≤ 3
     *   LEGACY_MAX_BUTTONS   — 6, la grille 3×2 du schéma 1, et RIEN d'autre :
     *                          c'est tout ce qu'une carte v1.4 sait dessiner
     */
    const MAX_BUTTONS          = 32;
    const MAX_PAGES            = 4;
    const MAX_BUTTONS_PER_PAGE = 12;
    const LEGACY_MAX_BUTTONS   = 6;

    /*
     * Les deux schémas du contrat.
     *
     * La carte annonce ce qu'elle sait lire en en-tête X-GLOWSCREEN32-SCHEMA.
     * Absent, vide ou 1 → le schéma 1 à l'identique, octet pour octet. 2 ou
     * plus → le schéma 2. Le plugin ne répond JAMAIS au-dessus de ce qui est
     * annoncé : le plugin se déploie d'un seul coup, le parc se met à jour
     * écran par écran, et un écran qui n'affiche plus rien ne peut plus
     * recevoir l'OTA qui le réparerait.
     */
    const SCHEMA_LEGACY  = 1;
    /* v3.0 : le schéma 2 reste servi — SANS aucune tuile « view », que la
     * carte prendrait pour un bouton — et SCHEMA_CURRENT passe à 3. Tout ce
     * qui vaut « schéma 2 ou plus » (features, rev, attente longue, commandes
     * à distance) se teste sur SCHEMA_V2, jamais sur SCHEMA_CURRENT. */
    const SCHEMA_V2      = 2;
    const SCHEMA_CURRENT = 3;

    /* Plafond de la réponse, côté firmware (JEEDOM_JSON_MAX). Le dépassement
     * n'est pas tronqué ici — il n'y a rien à tronquer qui garde un sens — mais
     * il est journalisé, faute de quoi la carte rejetterait la mise en page
     * sans que rien, côté serveur, ne dise pourquoi. */
    const MAX_PAYLOAD = 8192;

    /*
     * Âge maximal, en MINUTES, d'une valeur affichable au bandeau. 0 = ne
     * jamais périmer.
     *
     * Réglable par écran, et il le faut : les capteurs n'ont pas tous la même
     * cadence — une station météo se rafraîchit toutes les dix minutes, un
     * compteur d'énergie toutes les secondes, un thermostat tous les quarts
     * d'heure. Un seuil unique en dur serait forcément faux pour quelqu'un.
     */
    const DEFAULT_INFO_MAX_AGE = 60;

    /* Longueurs du contrat. */
    const LABEL_MAX = 24;
    const TITLE_MAX = 24;
    const INFO_MAX  = 16;

    /* La grille par défaut du schéma 2. Le contrat impose cols ∈ {3,4} et
     * rows ∈ {2,3} ; une carte de schéma 1, elle, ne connaît que 3×2. */
    const DEFAULT_COLS = 3;
    const DEFAULT_ROWS = 3;

    /*
     * Le vocabulaire d'icônes, FERMÉ — contrat v2.0.
     *
     * Le firmware embarque un jeu fini de glyphes et convertit le nom en
     * identifiant numérique au parsing : 1 octet par bouton au lieu de 12. Un
     * nom inconnu y vaut « none », et la tuile se rabat sur son seul libellé.
     *
     * Côté Jeedom, le champ est donc une LISTE DÉROULANTE et non un champ
     * texte : sans cela la liste dérive en silence et l'utilisateur configure
     * des icônes qui ne s'afficheront jamais. L'ordre est celui du contrat, et
     * la page de configuration le reprend tel quel.
     */
    const ICON_NONE = 'none';
    const ICONS = array(
        'none',
        'bulb', 'lamp', 'ceiling', 'strip', 'plug', 'power',
        'gate', 'garage', 'door', 'window', 'shutter', 'blind', 'lock',
        'heat', 'cool', 'fan', 'thermo', 'water', 'valve',
        'tv', 'music', 'speaker', 'camera', 'alarm', 'shield',
        'scene', 'movie', 'night', 'sun', 'moon', 'coffee',
        'folder', 'home', 'grid', 'car', 'mower', 'vacuum', 'bell', 'clock',
    );

    /*
     * Les alias — contrat v2.0.
     *
     * Un nom hors vocabulaire dont l'INTENTION est claire vaut son équivalent,
     * plutôt que d'être perdu. Le parc contenait « fire » : fermer le
     * vocabulaire sans cette table aurait fait disparaître l'icône d'un écran
     * en service, silencieusement, à la première ouverture du formulaire.
     *
     * ⚠ Les alias ne valent QUE pour le schéma 2 et pour l'interface. Le schéma
     * 1 ne les applique pas : il rend la chaîne stockée telle quelle (voir
     * legacyIcon). C'est exactement à cela que sert la négociation de schéma —
     * appliquer les règles de la v2 à une réponse v1 reviendrait à faire
     * diverger un contrat figé.
     *
     * La table ne contient que des noms qui ne sont PAS déjà dans le
     * vocabulaire : resolveIcon() consulte ICONS d'abord, un alias « lamp →
     * lamp » ou « camera → camera » ne serait jamais lu. Un alias qui ne
     * correspond à rien est du poids mort ; un alias manquant est une icône
     * perdue.
     */
    const ICON_ALIASES = array(
        'fire'        => 'heat',
        'flame'       => 'heat',
        'chauffage'   => 'heat',
        'light'       => 'bulb',
        'temp'        => 'thermo',
        'temperature' => 'thermo',
        'volet'       => 'shutter',
        'store'       => 'blind',
        'porte'       => 'door',
        'portail'     => 'gate',
        'prise'       => 'plug',
        'clim'        => 'cool',
        'ventilateur' => 'fan',
        'musique'     => 'music',
        'alarme'      => 'alarm',
        'serrure'     => 'lock',
    );

    /* Intervalle de rafraîchissement conseillé à la carte, en secondes. Le
     * « ping » qui l'utilise ne transfère que trois champs : une valeur basse
     * coûte peu, mais elle coûte quand même, une requête PHP à chaque appel. */
    const DEFAULT_POLL = 30;
    const MIN_POLL     = 5;
    const MAX_POLL     = 3600;

    /*
     * Facteur d'espacement du « ping » quand l'écran est ATTÉNUÉ — contrat v2.1.
     *
     * La carte suit « poll » écran allumé, et se donne le droit d'espacer
     * jusqu'à 2 x poll quand plus personne ne le regarde. Elle émet en revanche
     * un ping IMMÉDIAT au réveil, si bien que les pastilles vues par quelqu'un
     * qui s'approche sont toujours fraîches.
     *
     * ⚠ CETTE CONSTANTE EST LA MÊME DES DEUX CÔTÉS (UI_IDLE_POLL_FACTOR dans
     * theme.h). Le facteur d'espacement et le seuil de détection hors ligne
     * sont LE MÊME RÉGLAGE VU DES DEUX BOUTS : les laisser diverger ferait
     * « disparaître » tout le parc chaque nuit alors que chaque carte
     * fonctionne parfaitement — c'est-à-dire produirait une alerte à laquelle
     * on cesse de croire, ce qui est pire que pas d'alerte du tout.
     */
    const IDLE_POLL_FACTOR = 2;

    /*
     * Le niveau de réception Wi-Fi annoncé par la carte — contrat v2.1,
     * paramètre optionnel de « ping ».
     *
     * Les bornes ne sont pas décoratives : elles écartent une valeur aberrante
     * sans refuser le ping. Un diagnostic mal formé ne doit JAMAIS coûter sa
     * liaison à un écran.
     */
    const RSSI_MIN = -120;
    const RSSI_MAX = 0;

    /*
     * Seuil d'alerte précoce, en dBm. Identique à UI_RSSI_WEAK_DBM côté
     * firmware, et choisi sur des relevés de terrain, pas au jugé : un « press »
     * a été perdu vers -88 dBm et un OTA est mort à 2 % vers -92/-93 dBm, alors
     * que les « ping » passaient encore dans les deux cas. Prévenir à -75 dBm
     * laisse le temps de déplacer un répéteur avant que l'écran ne devienne
     * inutilisable.
     */
    const RSSI_WEAK = -75;

    /* La couleur d'un bouton auquel on n'en a pas donné. Le bleu de référence
     * du contrat, pour qu'un écran non peint reste cohérent avec la maquette. */
    const DEFAULT_COLOR = '#2D7FF9';

    /*
     * Les deux modes de bouton du contrat v1.3.
     *
     * MODE_ACTION  — une commande d'action, ou un scénario, joué tel quel à
     *                chaque appui. C'est ce qu'il faut pour un relais
     *                impulsionnel (un portail), pour un scénario, pour tout ce
     *                qui n'a pas de sens « allumé / éteint ».
     * MODE_TOGGLE  — un interrupteur : le plugin lit l'état courant et joue la
     *                commande INVERSE. C'est la correction du défaut constaté
     *                en v1.2, où un bouton lié à la seule commande « Allumer »
     *                allumait, et n'éteignait jamais.
     */
    const MODE_ACTION = 'action';
    const MODE_TOGGLE = 'toggle';

    /*
     * MODE_NAV — contrat v2.0. Ouvre une autre page de l'écran, et rien de
     * plus : aucune commande, aucun scénario, aucun état. La navigation est
     * ENTIÈREMENT LOCALE à la carte, donc instantanée et fonctionnelle hors
     * réseau — un écran coupé de Jeedom continue de naviguer depuis son cache
     * NVS.
     *
     * Conséquence côté plugin : il n'a rien à résoudre pour un bouton « nav »,
     * son état vaut toujours null, et un « press » reçu sur son rang signale un
     * firmware en désaccord avec la configuration — unknown_button, et une
     * ligne de journal.
     */
    const MODE_NAV = 'nav';

    /*
     * MODE_VIEW — contrat v3.0, schéma 3. Une tuile qui MONTRE au lieu
     * d'agir : l'état d'une porte, de l'alarme, une température. Elle
     * ne déclenche rien, n'émet jamais de « press », et n'est servie QU'EN
     * SCHÉMA 3 : une carte de schéma 2 la prendrait pour un bouton. Le plugin
     * met la valeur en forme (« value », ≤ 16 caractères) et lui donne un SENS
     * (« tone ») ; la carte ne fait qu'afficher.
     */
    const MODE_VIEW = 'view';

    /* Les sens d'une tuile « view ». Absent ou inconnu = neutral. */
    const TONES = array('neutral', 'ok', 'warn', 'alert');
    const VALUE_MAX = 16;
    /* Seuil de péremption par défaut d'une tuile NUMÉRIQUE, en minutes.
     * Binaire et texte : aucun par défaut (voir viewResult). */
    const VIEW_NUMERIC_MAX_AGE = 60;
    /* Nombre de seuils (numérique) et de lignes de correspondance (texte)
     * qu'une tuile peut porter. */
    const VIEW_THRESHOLDS = 3;
    const VIEW_MAPS = 6;

    /* Les deux formes d'action qu'un bouton en mode « action » sait déclencher. */
    const TARGET_NONE     = '';
    const TARGET_CMD      = 'cmd';
    const TARGET_SCENARIO = 'scenario';

    /*
     * Granularité d'horodatage du dernier contact, en secondes.
     *
     * Une carte appelle « ping » toutes les 30 s ; avec dix écrans, horodater
     * chaque appel ferait six cents écritures par heure pour une information
     * dont personne ne lit la seconde. À la minute, l'écriture est au pire une
     * par écran et par minute, et « cet écran ne répond plus » reste une
     * question à laquelle on répond exactement de la même façon.
     */
    const CONTACT_GRANULARITY = 60;

    /*
     * ---------------------------------------------------------------- OTA
     *
     * Le dépôt du firmware, sous « data/ » du plugin. Il est EXCLU du
     * déploiement (.deployignore : data/firmware/*.bin) : sans cette exclusion,
     * le rsync --delete de deploy-plugin.sh effacerait à chaque déploiement un
     * binaire que le dépôt de développement ne contient pas.
     */
    const FIRMWARE_DIR = 'firmware';

    /*
     * Taille maximale acceptée au dépôt. La carte n'a que 4 Mo de flash, dont
     * deux partitions d'application : au-delà de ~1,9 Mo l'image ne tient
     * déjà plus. Refuser à 4 Mo attrape la vraie faute — un fichier qui n'est
     * pas un firmware — sans se substituer au partitionnement.
     */
    const FIRMWARE_MAX_SIZE = 4194304;

    /*
     * Le premier octet d'une image d'application ESP32 vaut 0xE9 : c'est
     * l'en-tête lu par le chargeur d'amorçage. Un fichier qui ne commence pas
     * par là n'est pas un firmware, et le déposer reviendrait à promettre à la
     * carte une image qu'elle écrirait dans sa partition inactive avant de ne
     * plus démarrer. C'est la seule garantie qu'on ait sur la NATURE du
     * fichier, et elle est conservée telle quelle.
     */
    const ESP_IMAGE_MAGIC = 0xE9;

    /*
     * Le marqueur de version gravé dans le binaire par notre firmware :
     *
     *     GLOWSCREEN32-FW:1.4.1\0
     *
     * ---------------------------------------------------------------------
     * Pourquoi un marqueur à nous, et non le descripteur ESP-IDF de l'image.
     *
     * L'image porte bien un descripteur normalisé à l'offset 0x20 (mot magique
     * 0xABCD5432), avec un champ « version ». La 1.2 le lisait. Vérification
     * faite sur un VRAI binaire du projet, il contient :
     *
     *     version       « esp-idf: v4.4.7 38eeba213a »
     *     project_name  « arduino-lib-builder »
     *
     * Ce descripteur vient des bibliothèques Arduino PRÉCOMPILÉES du
     * framework, pas de notre code : avec « framework = arduino » sous
     * PlatformIO, on ne le maîtrise pas. Il est donc IDENTIQUE dans tous nos
     * binaires — et une version identique partout, c'est un OTA qui ne se
     * déclenche jamais. Le défaut était silencieux : le dépôt réussissait, la
     * version annoncée avait l'air d'une version, et aucun écran n'aurait
     * jamais rien reçu.
     *
     * Le firmware grave donc sa propre chaîne (src/fw_version.cpp), protégée
     * de l'élimination par le compilateur ET par l'éditeur de liens. Le plugin
     * la cherche dans le fichier téléversé. La version reste ainsi CELLE QUI
     * EST DANS LE BINAIRE, et c'est la même que celle que la carte annonce à
     * « action=firmware » : comparer l'une à l'autre a un sens, ce qui ne
     * serait pas le cas d'un numéro retapé dans un formulaire.
     *
     * Le préfixe est assez distinctif pour qu'aucun autre littéral ne puisse y
     * ressembler par accident.
     */
    const FIRMWARE_MARKER = 'GLOWSCREEN32-FW:';

    /*
     * Longueur maximale de la version lue derrière le marqueur.
     *
     * Ce n'est pas une coquetterie : la valeur lue devient un NOM DE FICHIER et
     * une URL. Elle vient d'un binaire quelconque — l'utilisateur peut déposer
     * ce qu'il veut — et tout ce qui est accepté ici doit rester inoffensif une
     * fois recollé dans un chemin. D'où une longueur bornée, un jeu de
     * caractères fermé, et un terminateur nul exigé.
     */
    const FIRMWARE_VERSION_MAX = 31;

    /* ===================================================== ADRESSE MAC */

    /*
     * Forme canonique d'une adresse MAC : douze caractères hexadécimaux en
     * minuscules, sans séparateur. « 24:6F:28:12:34:56 », « 24-6f-28-12-34-56 »
     * et « 246f28123456 » désignent le même écran, et le firmware ne connaît
     * que la dernière : c'est celle qu'on stocke.
     *
     * Rend une chaîne vide si l'adresse n'a pas la bonne longueur. Une MAC
     * tronquée qui serait acceptée telle quelle ferait correspondre l'écran à
     * rien du tout, et la panne se lirait « unknown_device » sans jamais
     * désigner la saisie fautive.
     */
    public static function normalizeMac($_mac) {
        $mac = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $_mac));
        return (strlen($mac) == 12) ? $mac : '';
    }

    /* L'adresse telle qu'on l'affiche à l'utilisateur : 24:6f:28:12:34:56. */
    public static function prettyMac($_mac) {
        $mac = self::normalizeMac($_mac);
        if ($mac === '') {
            return '';
        }
        return implode(':', str_split($mac, 2));
    }

    /*
     * L'écran portant cette adresse, ou null.
     *
     * Le logicalId est posé par preSave() et sert de clé de recherche. Le
     * parcours de repli couvre les équipements enregistrés avant que la règle
     * n'existe, et ceux qu'une restauration aurait rendus sans logicalId : sans
     * lui, une carte parfaitement configurée se verrait répondre
     * « unknown_device » jusqu'à ce que quelqu'un pense à rouvrir puis
     * réenregistrer l'équipement.
     */
    public static function byMac($_mac) {
        $mac = self::normalizeMac($_mac);
        if ($mac === '') {
            return null;
        }
        $eqLogic = eqLogic::byLogicalId($mac, 'glowscreen32');
        if (is_object($eqLogic)) {
            return $eqLogic;
        }
        foreach (eqLogic::byType('glowscreen32') as $candidate) {
            if (self::normalizeMac($candidate->getConfiguration('mac', '')) === $mac) {
                return $candidate;
            }
        }
        return null;
    }

    /* ========================================================== BOUTONS */

    /*
     * Les boutons enregistrés, remis en forme.
     *
     * Rendus tels qu'ils sont stockés — la résolution des cibles n'a lieu que
     * dans layout(), qui touche la base. La page de configuration comme l'API
     * passent par ici, si bien qu'elles voient exactement la même liste.
     */
    public function buttons() {
        $stored = $this->getConfiguration('buttons', array());
        if (!is_array($stored)) {
            $stored = array();
        }
        return self::sanitizeButtons($stored);
    }

    /*
     * Remet une liste de boutons dans la forme attendue : au plus six entrées,
     * chacune avec toutes ses clés, jamais absentes.
     *
     * Les valeurs viennent d'un formulaire, donc du réseau. Une couleur est
     * recopiée dans du JSON que la carte lit sans se méfier, et un libellé est
     * affiché tel quel : les deux sont bornés ici, une fois, plutôt qu'à chaque
     * endroit qui s'en sert.
     *
     * Un bouton enregistré avant la v1.3 n'a pas de clé « mode » : il vaut
     * « action », ce qui est exactement ce qu'il faisait. Aucune configuration
     * existante ne change de comportement à la mise à jour.
     */
    public static function sanitizeButtons($_buttons) {
        $buttons = array();
        $rank    = 0;
        foreach ($_buttons as $stored) {
            if (count($buttons) >= self::MAX_BUTTONS) {
                /* Journalisé, jamais absorbé en silence : c'est exactement le
                 * défaut que la v1.4 avait côté firmware — un écran affichait
                 * calmement 6 boutons sur 8 et personne ne pouvait le savoir. */
                log::add('glowscreen32', 'warning', sprintf(
                    __('Mise en page tronquée : plus de %s boutons enregistrés, les suivants sont ignorés.', __FILE__),
                    self::MAX_BUTTONS));
                break;
            }
            if (!is_array($stored)) {
                continue;
            }

            $mode = isset($stored['mode']) ? (string) $stored['mode'] : self::MODE_ACTION;
            if ($mode !== self::MODE_TOGGLE && $mode !== self::MODE_NAV && $mode !== self::MODE_VIEW) {
                $mode = self::MODE_ACTION;
            }

            $target = isset($stored['target']) ? (string) $stored['target'] : self::TARGET_NONE;
            if ($target !== self::TARGET_CMD && $target !== self::TARGET_SCENARIO) {
                $target = self::TARGET_NONE;
            }

            /*
             * Page et case — contrat v2.0, et MIGRATION IMPLICITE.
             *
             * Une configuration v1 n'a ni l'une ni l'autre : le bouton tombe
             * alors sur la page d'accueil, à la case de son RANG dans le
             * tableau. C'est très exactement la mise en page qu'il avait, et
             * aucun script de migration n'a donc à être écrit ni lancé — une
             * configuration existante continue de marcher telle quelle, y
             * compris si personne ne rouvre jamais l'équipement.
             */
            $page = isset($stored['page']) ? (int) $stored['page'] : 0;
            if ($page < 0 || $page >= self::MAX_PAGES) {
                $page = 0;
            }
            $slot = isset($stored['slot']) ? (int) $stored['slot'] : $rank;
            if ($slot < 0 || $slot >= self::MAX_BUTTONS_PER_PAGE) {
                $slot = ($rank < self::MAX_BUTTONS_PER_PAGE) ? $rank : 0;
            }

            /* La page visée par un bouton « nav ». -1 = non renseignée : le
             * bouton est alors incomplet, et activeButtons() le dit. Elle ne
             * peut pas s'appeler « page », qui est déjà la page OÙ SE TROUVE le
             * bouton. */
            $nav = isset($stored['nav']) ? (int) $stored['nav'] : -1;
            if ($nav < 0 || $nav >= self::MAX_PAGES) {
                $nav = -1;
            }

            $buttons[] = array(
                'label'    => self::trimText(isset($stored['label']) ? $stored['label'] : '', self::LABEL_MAX),
                'color'    => self::normalizeColor(isset($stored['color']) ? $stored['color'] : ''),
                /*
                 * L'icône est stockée BRUTE, exactement comme en v1.
                 *
                 * La résolution vers le vocabulaire fermé a lieu à la
                 * SÉRIALISATION du schéma 2, et nulle part ailleurs. Normaliser
                 * ici réécrirait la configuration de l'utilisateur, et surtout
                 * rendrait impossible de servir au schéma 1 ce qu'il a toujours
                 * reçu : la chaîne telle qu'elle a été saisie.
                 */
                'icon'     => self::trimText(isset($stored['icon']) ? $stored['icon'] : '', 24),
                'mode'     => $mode,
                /* --- mise en page, contrat v2.0 --- */
                'page'     => $page,
                'slot'     => $slot,
                /* --- mode « nav » --- */
                'nav'      => $nav,
                /* --- mode « action » --- */
                'target'   => $target,
                'cmd'      => self::trimCmd($stored, 'cmd'),
                'scenario' => isset($stored['scenario']) ? (int) $stored['scenario'] : 0,
                /* --- mode « toggle » --- */
                'on'       => self::trimCmd($stored, 'on'),
                'off'      => self::trimCmd($stored, 'off'),
                /* La commande « Basculer » de l'équipement, quand il en a une.
                 * Elle n'est qu'un secours : voir pressToggle(). */
                'toggle'   => self::trimCmd($stored, 'toggle'),
                /* --- commun --- */
                /* La commande d'information qui dit si le bouton est allumé.
                 * Facultative en mode « action », OBLIGATOIRE en mode
                 * « toggle » : sans elle, rien ne décide du sens. */
                'state'    => self::trimCmd($stored, 'state'),
                /* --- mode « view », contrat v3.0 --- */
                /* La commande d'information montrée, et sa mise en forme. */
                'view'     => self::trimCmd($stored, 'view'),
                'fmt'      => self::sanitizeFmt(isset($stored['fmt']) ? $stored['fmt'] : array()),
            );
            $rank++;
        }
        return $buttons;
    }

    /* Un sens de tuile, ramené au vocabulaire fermé. */
    public static function sanitizeTone($_tone, $_default = 'neutral') {
        $tone = strtolower(trim((string) $_tone));
        return in_array($tone, self::TONES, true) ? $tone : $_default;
    }

    /*
     * La mise en forme d'une tuile « view » — contrat v3.0. Clés PLATES, en
     * nombre fixe : le formulaire les envoie telles quelles, et rien de ce qui
     * n'est pas ici n'est conservé.
     *
     *   invert, l0/t0, l1/t1      binaire : libellé et sens pour 0 et pour 1
     *   unit (null = celle de la commande), dec ('' = automatique), base,
     *   th1v/th1t … th3v/th3t     numérique : seuils croissants → sens
     *   m1v/m1l/m1t … m6v/m6l/m6t texte : valeur → libellé + sens
     *   age                        péremption en minutes ('' = défaut du type)
     */
    public static function sanitizeFmt($_fmt) {
        $raw = is_array($_fmt) ? $_fmt : array();
        $text = function ($_key, $_max) use ($raw) {
            return isset($raw[$_key]) ? self::trimText($raw[$_key], $_max) : '';
        };
        $fmt = array(
            'invert' => (isset($raw['invert']) && (int) $raw['invert'] === 1) ? 1 : 0,
            'l0'     => $text('l0', self::VALUE_MAX),
            't0'     => self::sanitizeTone(isset($raw['t0']) ? $raw['t0'] : ''),
            'l1'     => $text('l1', self::VALUE_MAX),
            't1'     => self::sanitizeTone(isset($raw['t1']) ? $raw['t1'] : ''),
            /* v3.1 : unité VIDE = celle de la commande — une seule règle, la
             * même dans la page (placeholder) et ici. */
            'unit'   => (isset($raw['unit']) && trim((string) $raw['unit']) !== '') ? $text('unit', 8) : null,
            'dec'    => (isset($raw['dec']) && is_numeric($raw['dec'])) ? max(0, min(3, (int) $raw['dec'])) : '',
            'base'   => self::sanitizeTone(isset($raw['base']) ? $raw['base'] : ''),
            'age'    => (isset($raw['age']) && is_numeric($raw['age']) && (int) $raw['age'] >= 0) ? (int) $raw['age'] : '',
        );
        for ($i = 1; $i <= self::VIEW_THRESHOLDS; $i++) {
            $value = isset($raw['th' . $i . 'v']) ? str_replace(',', '.', trim((string) $raw['th' . $i . 'v'])) : '';
            $fmt['th' . $i . 'v'] = is_numeric($value) ? (string) (float) $value : '';
            $fmt['th' . $i . 't'] = self::sanitizeTone(isset($raw['th' . $i . 't']) ? $raw['th' . $i . 't'] : '', 'warn');
        }
        for ($i = 1; $i <= self::VIEW_MAPS; $i++) {
            $fmt['m' . $i . 'v'] = $text('m' . $i . 'v', 64);
            $fmt['m' . $i . 'l'] = $text('m' . $i . 'l', self::VALUE_MAX);
            $fmt['m' . $i . 't'] = self::sanitizeTone(isset($raw['m' . $i . 't']) ? $raw['m' . $i . 't'] : '');
        }
        return $fmt;
    }

    /*
     * Le nom d'icône résolu — SCHÉMA 2 et interface UNIQUEMENT.
     *
     * Le vocabulaire d'abord, les alias ensuite, « none » à défaut. La casse
     * est ignorée : le firmware convertit le nom en identifiant numérique au
     * parsing, et « Bulb » désigne sans ambiguïté la même chose que « bulb ».
     *
     * Ce qui n'est résolu par RIEN vaut « none » — et l'appelant le journalise
     * avec le nom de l'écran et le libellé du bouton, faute de quoi
     * l'utilisateur verrait une tuile perdre son icône sans savoir laquelle
     * corriger.
     */
    public static function resolveIcon($_icon) {
        $icon = strtolower(trim((string) $_icon));
        if (in_array($icon, self::ICONS, true)) {
            return $icon;
        }
        return isset(self::ICON_ALIASES[$icon]) ? self::ICON_ALIASES[$icon] : self::ICON_NONE;
    }

    /*
     * L'icône telle que le SCHÉMA 1 l'a toujours écrite : un PASSE-PLAT.
     *
     * La chaîne stockée, sans normalisation, sans minuscules, sans alias, sans
     * « none » et sans vide. C'est exactement ce que faisait la v1.4, où
     * « icon » était du texte libre que le firmware parsait sans jamais le
     * dessiner.
     *
     * ⚠ Ce n'est pas une paresse, c'est la règle : le schéma 1 est un CONTRAT
     * FIGÉ, pas un endroit où appliquer les règles du schéma 2. C'est
     * précisément ce à quoi sert la négociation de schéma — chaque schéma
     * répond selon ses propres règles, et celui d'hier ne change jamais. La
     * promesse « octet pour octet » devient ainsi vraie PAR CONSTRUCTION, pour
     * tous les écrans, sans exception ni cas particulier à retenir : « fire »
     * ressort « fire », « Bulb » ressort « Bulb ».
     *
     * La fonction existe pour porter ce commentaire, et pour que le contrôle de
     * tests/check-classes.php ait quelque chose à vérifier. buttonSignature()
     * passe par elle aussi : c'est la valeur STOCKÉE qui entre dans la
     * signature, donc la signature d'une configuration existante ne bouge pas,
     * et le parc ne se redessine pas pour un champ que personne ne dessine.
     */
    public static function legacyIcon($_icon) {
        return (string) $_icon;
    }

    /* ============================================================ PAGES */

    /*
     * Les pages de l'écran — contrat v2.0.
     *
     * Toujours MAX_PAGES entrées, renseignées ou non : la page est désignée par
     * son RANG, et un tableau creux ferait dépendre l'identité d'une page de
     * l'ordre d'enregistrement. La page 0 est la page d'accueil ; son parent
     * n'a pas de sens et n'est pas rendu à la carte.
     */
    public function pages() {
        return self::sanitizePages($this->getConfiguration('pages', array()));
    }

    public static function sanitizePages($_pages) {
        $pages = array();
        for ($id = 0; $id < self::MAX_PAGES; $id++) {
            $raw = (is_array($_pages) && isset($_pages[$id]) && is_array($_pages[$id]))
                ? $_pages[$id] : array();

            /*
             * La page vers laquelle remonte le bouton « retour ». Une page qui
             * serait son propre parent, ou qui viserait une page inexistante,
             * remonte à l'accueil : une boucle de retour est un écran dont on
             * ne peut plus sortir sans couper le courant.
             */
            $parent = isset($raw['parent']) ? (int) $raw['parent'] : 0;
            if ($parent < 0 || $parent >= self::MAX_PAGES || $parent === $id) {
                $parent = 0;
            }

            $pages[$id] = array(
                'title'  => self::trimText(isset($raw['title']) ? $raw['title'] : '', self::TITLE_MAX),
                'parent' => ($id === 0) ? 0 : $parent,
                /* v3.2 (contrat v3.1) : « Afficher la page ». Absent = visible :
                 * une configuration antérieure ne change pas. */
                'visible' => !(isset($raw['visible']) && in_array($raw['visible'], array(0, '0', false, 'false'), true)),
            );
        }
        return $pages;
    }

    /*
     * La correspondance position de CONFIGURATION → numéro SERVI des pages
     * visibles — v3.2, contrat v3.1. Les pages masquées sont omises, les
     * visibles renumérotées 0 … n-1 dans leur ordre de configuration : la page
     * 0 servie est la première visible. Sans page masquée, c'est l'identité —
     * la réponse d'un écran existant ne change pas d'un octet.
     *
     * Toujours au moins une page : si la configuration n'en marquait aucune
     * visible (refusé à l'enregistrement, mais une restauration…), la page 0
     * l'est.
     */
    public function pageMap() {
        $map = array();
        foreach ($this->pages() as $id => $page) {
            if ($page['visible']) {
                $map[$id] = count($map);
            }
        }
        if (count($map) == 0) {
            $map[0] = 0;
        }
        return $map;
    }

    public function pageVisible($_id) {
        return isset($this->pageMap()[(int) $_id]);
    }

    /* Le parent SERVI d'une page : le plus proche ancêtre visible, à défaut la
     * page 0 servie — le bouton retour ne mène jamais nulle part. */
    public function servedParent($_id) {
        $pages = $this->pages();
        $map   = $this->pageMap();
        $seen  = array((int) $_id => true);
        $p     = $pages[(int) $_id]['parent'];
        while (!isset($map[$p]) && !isset($seen[$p])) {
            $seen[$p] = true;
            $p = $pages[$p]['parent'];
        }
        return isset($map[$p]) ? $map[$p] : 0;
    }

    /* Le titre servi : celui saisi, à défaut le nom de l'écran pour l'accueil
     * SERVI, « Page N » (numéro servi) sinon. Sans page masquée, c'est
     * exactement pageTitle(). */
    public function servedTitle($_id, $_served) {
        $pages = $this->pages();
        if ($pages[$_id]['title'] !== '') {
            return $pages[$_id]['title'];
        }
        return ($_served === 0)
            ? self::trimText($this->getName(), self::TITLE_MAX)
            : sprintf(__('Page %s', __FILE__), $_served + 1);
    }

    /* Le titre affiché au bandeau pour cette page. À défaut de titre saisi, le
     * nom de l'écran pour l'accueil — c'est ce que la carte affichait déjà — et
     * « Page N » pour les autres : un bandeau vide ne dit pas où l'on est. */
    public function pageTitle($_id) {
        $pages = $this->pages();
        $title = isset($pages[$_id]) ? $pages[$_id]['title'] : '';
        if ($title !== '') {
            return $title;
        }
        return ($_id === 0)
            ? self::trimText($this->getName(), self::TITLE_MAX)
            : sprintf(__('Page %s', __FILE__), $_id + 1);
    }

    /* Le nombre de pages réellement servies à la carte. Toujours au moins une :
     * l'accueil existe même vide. */
    public function pageCount() {
        return count($this->layoutV2()['pages']);
    }

    /* ====================================================== GRILLE ET BANDEAU */

    /*
     * La grille de l'écran — contrat v2.0, cols ∈ {3,4}, rows ∈ {2,3}.
     *
     * Défaut 3×3, y compris pour un écran configuré avant la v2.0 : ses boutons
     * occupent les cases 0 à 5, exactement là où la grille 3×2 les mettait, et
     * la troisième rangée reste libre. Une valeur aberrante venue d'une
     * restauration retombe sur le défaut plutôt que de faire dessiner à la
     * carte une grille qu'elle refuserait.
     */
    public function grid() {
        $grid = $this->getConfiguration('grid', array());
        $cols = (is_array($grid) && isset($grid['cols'])) ? (int) $grid['cols'] : self::DEFAULT_COLS;
        $rows = (is_array($grid) && isset($grid['rows'])) ? (int) $grid['rows'] : self::DEFAULT_ROWS;
        if ($cols !== 3 && $cols !== 4) {
            $cols = self::DEFAULT_COLS;
        }
        if ($rows !== 2 && $rows !== 3) {
            $rows = self::DEFAULT_ROWS;
        }
        return array('cols' => $cols, 'rows' => $rows);
    }

    /* Le nombre de cases réellement disponibles sur une page. Le plafond du
     * contrat (12) et celui de la grille sont deux limites différentes, et
     * c'est la plus basse qui s'applique. */
    public function pageCapacity() {
        $grid = $this->grid();
        return min(self::MAX_BUTTONS_PER_PAGE, $grid['cols'] * $grid['rows']);
    }

    /* Le changement de page au balayage. Fermé par défaut : sur un écran
     * tactile résistif, un balayage involontaire est vite arrivé, et changer de
     * page sous le doigt de quelqu'un qui visait un bouton est pire que de
     * l'obliger à passer par un bouton « nav ». */
    public function swipe() {
        return ((int) $this->getConfiguration('swipe', 0)) === 1;
    }

    /* L'heure au bandeau. Elle vient de « time + tzoffset », recalés à chaque
     * ping et égrenés localement entre deux : pas de NTP, le serveur est déjà
     * la référence de temps et une carte sans Internet doit continuer à donner
     * l'heure. */
    public function clock() {
        return ((int) $this->getConfiguration('clock', 0)) === 1;
    }

    /*
     * Écran en LECTURE SEULE — contrat v3.0, « ui.readonly ». Défaut false.
     * La carte de schéma 3 n'envoie alors aucun « press » ; le plugin, lui,
     * REFUSE tout « press » de cet écran (403 read_only), quel que soit le
     * schéma : la carte qui obéit est le confort, le plugin qui refuse est la
     * garantie — un écran d'entrée ne doit pas ouvrir le portail, même avec
     * un firmware défectueux ou une clé API dérobée.
     */
    public function readOnly() {
        return ((int) $this->getConfiguration('readonly', 0)) === 1;
    }

    /*
     * Le décalage horaire local, en secondes, DST COMPRISE — contrat v2.0.
     *
     * Calculé à partir du fuseau de Jeedom, jamais codé en dur : un décalage
     * figé à 3600 donnerait une heure fausse la moitié de l'année, et une heure
     * fausse au bandeau est pire qu'une absence d'heure, puisque rien ne la
     * signale. getOffset() sur l'instant courant est la seule forme qui tienne
     * compte de l'heure d'été.
     */
    public static function tzOffset() {
        $name = trim((string) config::byKey('timezone'));
        try {
            $zone = new DateTimeZone(($name !== '') ? $name : date_default_timezone_get());
        } catch (Throwable $e) {
            try {
                $zone = new DateTimeZone(date_default_timezone_get());
            } catch (Throwable $e2) {
                return 0;
            }
        }
        return (int) $zone->getOffset(new DateTime('now', $zone));
    }

    /*
     * Le seuil de péremption du bandeau, en SECONDES. 0 = jamais.
     *
     * Une clé absente vaut le défaut, et non zéro : un écran configuré avant
     * que le réglage n'existe doit hériter d'un garde-fou, pas de son absence.
     * Un champ laissé vide est traité de la même façon — « vide » veut dire
     * « je n'ai pas choisi », alors que « 0 » est un choix explicite.
     */
    public function infoMaxAge() {
        $raw = $this->getConfiguration('info_max_age', self::DEFAULT_INFO_MAX_AGE);
        if (trim((string) $raw) === '' || !is_numeric($raw)) {
            $minutes = self::DEFAULT_INFO_MAX_AGE;
        } else {
            $minutes = (int) $raw;
        }
        if ($minutes < 0) {
            $minutes = self::DEFAULT_INFO_MAX_AGE;
        }
        return $minutes * 60;
    }

    /*
     * L'âge de la valeur d'une commande d'information, en secondes, ou null si
     * elle n'est pas datée.
     *
     * ⚠ C'est « collectDate » qu'il faut lire, PAS « valueDate ».
     *
     * Le coeur met « valueDate » à jour uniquement quand la valeur CHANGE,
     * alors que « collectDate » l'est à chaque COLLECTE. Une température stable
     * à 18 °C depuis deux heures a donc une « valueDate » vieille de deux
     * heures tout en étant parfaitement fraîche : s'y fier masquerait des
     * valeurs valides, c'est-à-dire le défaut exactement symétrique de celui
     * qu'on corrige. Constaté sur la commande météo de cette installation, à
     * l'instant où le réglage a été écrit : collectDate 1 minute, valueDate 81.
     *
     * Le repli sur « valueDate » ne sert qu'aux commandes qu'aucun collecteur
     * n'horodate. Et si les deux sont vides, la valeur est réputée UTILISABLE :
     * périmer faute de date casserait un cas qui fonctionne aujourd'hui.
     */
    public static function cmdAge($_cmd) {
        $stamp = trim((string) $_cmd->getCollectDate());
        if ($stamp === '') {
            $stamp = trim((string) $_cmd->getValueDate());
        }
        if ($stamp === '') {
            return null;
        }
        $time = strtotime($stamp);
        return ($time === false) ? null : max(0, time() - $time);
    }

    /* Une durée lisible d'un coup d'oeil dans le journal : « 2 h 15 min » se
     * comprend, « 8100 » se calcule. */
    public static function humanDuration($_seconds) {
        $seconds = max(0, (int) $_seconds);
        if ($seconds < 60) {
            return sprintf(__('%s s', __FILE__), $seconds);
        }
        if ($seconds < 3600) {
            return sprintf(__('%s min', __FILE__), (int) floor($seconds / 60));
        }
        if ($seconds < 86400) {
            return sprintf(__('%1$s h %2$s min', __FILE__),
                (int) floor($seconds / 3600), (int) floor(($seconds % 3600) / 60));
        }
        return sprintf(__('%s j', __FILE__), (int) floor($seconds / 86400));
    }

    /*
     * Le texte du bandeau — contrat v2.0.
     *
     * C'est le PLUGIN qui formate : il connaît la commande choisie, son unité
     * et son arrondi ; la carte ne fait qu'afficher. Même principe que pour
     * « id » : le plugin décide, la carte affiche. Une chaîne plutôt qu'un
     * nombre et une unité laisse mettre au bandeau une température, une
     * humidité, une puissance ou un niveau de cuve sans qu'une seule ligne du
     * firmware ait à changer — et sans OTA pour ajouter une unité.
     *
     * Rend null si rien n'est configuré, si la commande a disparu, ou si elle
     * ne rend rien : le contrat prévoit null pour exactement ces cas.
     */
    public function infoText() {
        $reference = trim((string) $this->getConfiguration('info_cmd', ''));
        if ($reference === '') {
            return null;
        }
        $cmd = self::cmdByString($reference);
        if (!is_object($cmd) || $cmd->getType() != 'info') {
            return null;
        }

        /*
         * La péremption.
         *
         * Un bandeau vide est honnête ; un bandeau qui affiche une température
         * d'hier comme si elle était actuelle ne l'est pas — et c'est pire
         * qu'inutile sur un panneau mural qu'on consulte d'un coup d'oeil en
         * passant. Le cas s'est présenté : la commande météo choisie n'avait
         * jamais été collectée, son cron ne tournant pas.
         *
         * ⚠ Ceci ne fait PAS bouger « version », et ne doit pas : « version »
         * ne suit que la CONFIGURATION. Une valeur qui périme est un changement
         * d'ÉTAT, et il se propage par le champ « info » du ping, exactement
         * comme « states ». Sinon chaque péremption ferait recharger toute la
         * mise en page au parc entier — pour un écran qui afficherait la même
         * chose, à un champ près.
         */
        $maxAge = $this->infoMaxAge();
        if ($maxAge > 0) {
            $age = self::cmdAge($cmd);
            if ($age !== null && $age > $maxAge) {
                $this->noteInfoStale(true, $cmd, $age, $maxAge);
                return null;
            }
        }
        $this->noteInfoStale(false, $cmd, 0, $maxAge);

        $value = $cmd->execCmd();
        if ($value === null || is_array($value) || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            /* « 21 °C » et non « 21,0 °C » : le bandeau a seize caractères, et
             * un zéro décimal qui ne dit rien en mange deux. v3.0 (revue) :
             * virgule décimale, « 21,4 °C », comme les tuiles « valeur ». */
            $text = self::viewNumber($value, '');
            $unit = trim((string) $cmd->getUnite());
            if ($unit !== '') {
                $text .= ' ' . $unit;
            }
        } else {
            $text = trim(self::cleanText($value));
        }

        if (mb_strlen($text) > self::INFO_MAX) {
            /* Au niveau « debug » et non « warning » : le bandeau est recalculé
             * à chaque ping, c'est-à-dire toutes les trente secondes et par
             * écran. Une ligne d'avertissement par ping rendrait le journal
             * illisible au moment précis où l'on en a besoin. */
            $cut  = $text;
            $text = mb_substr($text, 0, self::INFO_MAX);
        }
        /* Tronqué ET journalisé — aux transitions (v3.1). */
        $who = $this->getHumanName();
        $this->noteTransitions('infocut', isset($cut) ? array($cut) : array(), 'warning', function ($item) use ($who) {
            return sprintf(__('%1$s : le bandeau « %2$s » dépasse %3$s caractères, il est coupé.', __FILE__),
                $who, $item, self::INFO_MAX);
        });
        return $text;
    }

    /*
     * Journalise la PÉREMPTION DU BANDEAU, et seulement quand elle CHANGE.
     *
     * ⚠ Pourquoi ce détour plutôt qu'un log::add() direct dans infoText().
     *
     * infoText() est appelée à CHAQUE « ping » et à chaque « layout », c'est-à-
     * dire toutes les trente secondes et par écran. Journaliser à chaque appel
     * produisait, mesuré sur l'installation de référence, huit lignes
     * rigoureusement identiques pour un seul enregistrement, puis deux lignes
     * par minute et par écran indéfiniment — jusqu'à noyer le journal au moment
     * précis où l'on vient y chercher autre chose.
     *
     * Les deux journaux voisins de ce même fichier avaient déjà tiré la leçon
     * (troncature du bandeau, aplatissement en schéma 1 : tous deux en
     * « debug », avec le commentaire qui l'explique). Celui-ci avait été
     * oublié.
     *
     * Le passer en « debug » l'aurait fait taire, mais il DIT QUELQUE CHOSE
     * D'UTILE : une commande qui n'est plus collectée est une vraie panne, et
     * silencieuse. On garde donc le niveau « info » et on ne parle qu'aux
     * TRANSITIONS — exactement ce que noteFirmware() fait pour la version de la
     * carte, et pour la même raison.
     *
     * Le retour à la normale est journalisé aussi : sans lui, le journal
     * laisserait croire que la panne dure encore.
     *
     * ⚠ CONTRAT v2.2 : l'état « périmé » vit dans le CACHE, plus dans la
     * configuration. Jusqu'en v2.1 il était écrit par save(true), c'est-à-dire
     * en réenregistrant l'eqLogic entier tel que chargé au début de la requête
     * — et une requête « ping » retenue 25 s écrasait alors toute
     * configuration enregistrée entre-temps. L'API n'enregistre plus JAMAIS
     * l'eqLogic. L'ancienne clé de configuration n'est plus que lue, une fois,
     * pour amorcer le cache après la mise à jour du plugin.
     */
    private function noteInfoStale($_stale, $_cmd, $_age, $_maxAge) {
        $stale = (bool) $_stale;
        $key   = 'glowscreen32::infostale::' . $this->getId();
        $raw   = cache::byKey($key)->getValue(null);
        $was   = ($raw === null || $raw === '')
            ? (((int) $this->getConfiguration('info_stale', 0)) === 1)
            : (((int) $raw) === 1);
        if ($was === $stale && $raw !== null && $raw !== '') {
            return false;
        }
        cache::set($key, $stale ? 1 : 0);
        if ($was === $stale) {
            return false;
        }

        if ($stale) {
            log::add('glowscreen32', 'info', sprintf(
                __('%1$s : la valeur du bandeau « %2$s » date de %3$s, au-delà du seuil de %4$s minutes : le bandeau reste vide plutôt que d\'afficher une valeur périmée. Vérifiez que la collecte de cette commande tourne encore.', __FILE__),
                $this->getHumanName(), $_cmd->getHumanName(),
                self::humanDuration($_age), (int) ($_maxAge / 60)));
        } else {
            log::add('glowscreen32', 'info', sprintf(
                __('%1$s : la valeur du bandeau « %2$s » est de nouveau collectée, elle réapparaît à l\'écran.', __FILE__),
                $this->getHumanName(), $_cmd->getHumanName()));
        }
        return true;
    }

    /* Un champ de désignation de commande, tel qu'il sort du formulaire. */
    public static function trimCmd($_stored, $_key) {
        return isset($_stored[$_key]) ? trim((string) $_stored[$_key]) : '';
    }

    /* Un texte de formulaire, sans balise ni débordement. */
    public static function trimText($_text, $_max) {
        /* v3.1 : plus de strip_tags(), qui effaçait « <5 » ou « a<b » — ce qui
         * part vers la carte est du JSON, pas du HTML ; l'échappement se fait
         * à l'AFFICHAGE dans la page. On retire seulement les caractères de
         * contrôle et l'UTF-8 invalide. */
        $text = trim(self::cleanText($_text));
        /* mb_substr et non substr : couper « Cinéma » au milieu d'un caractère
         * accentué produit du JSON invalide, que json_encode rend alors par
         * « false » — c'est-à-dire une mise en page vide. */
        return (mb_strlen($text) > $_max) ? mb_substr($text, 0, $_max) : $text;
    }

    /* Un texte venu d'une saisie ou d'une commande, rendu sûr pour le JSON :
     * UTF-8 réparé (mb_scrub), caractères de contrôle remplacés par une espace. */
    public static function cleanText($_text) {
        $text = (string) $_text;
        if (function_exists('mb_scrub')) {
            $text = mb_scrub($text, 'UTF-8');
        }
        return (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
    }

    /* Une valeur venue du réseau, rendue sûre pour une ligne de journal :
     * sans caractère de contrôle (pas de fausse ligne injectée), bornée. */
    public static function logSafe($_value, $_max = 64) {
        $text = self::cleanText(is_scalar($_value) ? $_value : json_encode($_value));
        return (mb_strlen($text) > $_max) ? mb_substr($text, 0, $_max) . '…' : $text;
    }

    /*
     * Journalise un défaut AUX TRANSITIONS seulement (v3.1). $_items : la
     * liste courante (clés stables) ; $_onNew($item) rend le message d'un
     * élément NOUVEAU ; $_onClear le message quand la liste se vide. L'état
     * connu vit dans le cache, jamais dans la configuration : l'API
     * n'enregistre pas l'eqLogic.
     */
    public function noteTransitions($_kind, $_items, $_level, $_onNew, $_onClear = null) {
        if ($this->getId() == '') {
            return;
        }
        $key   = 'glowscreen32::issues::' . $_kind . '::' . $this->getId();
        $known = cache::byKey($key)->getValue(null);
        $known = is_array($known) ? $known : null;
        $items = array_values($_items);
        if ($known !== null && $known == $items) {
            return;
        }
        foreach ($items as $item) {
            if ($known !== null && in_array($item, $known, true)) {
                continue;
            }
            log::add('glowscreen32', $_level, $_onNew($item));
        }
        if ($_onClear !== null && $known !== null && count($known) > 0 && count($items) == 0) {
            log::add('glowscreen32', 'info', $_onClear);
        }
        cache::set($key, $items);
    }

    /* Une couleur hexadécimale, ou celle par défaut. La forme courte #abc est
     * acceptée et développée : le firmware n'en lit qu'une seule. */
    public static function normalizeColor($_color) {
        $color = trim((string) $_color);
        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $color, $matches)) {
            return '#' . strtolower($matches[1]);
        }
        if (preg_match('/^#?([0-9a-fA-F]{3})$/', $color, $matches)) {
            $short = strtolower($matches[1]);
            return '#' . $short[0] . $short[0] . $short[1] . $short[1] . $short[2] . $short[2];
        }
        return self::DEFAULT_COLOR;
    }

    /*
     * La commande d'action désignée par l'un des champs d'un bouton, ou null.
     *
     * Le coeur convertit lui-même le champ entre « #[Objet][Équipement]
     * [Commande]# » à l'affichage et « #id# » à l'enregistrement
     * (eqLogic.ajax.php, jeedom::toHumanReadable / fromHumanReadable) : les deux
     * formes peuvent donc se présenter ici, et cmd::byString les accepte toutes
     * les deux. Elle lève, en revanche, sur une commande supprimée — ce qui
     * arrive pour de bon, et ne doit pas emporter toute la mise en page.
     */
    public static function actionCmd($_button, $_slot) {
        $value = isset($_button[$_slot]) ? trim((string) $_button[$_slot]) : '';
        if ($value === '') {
            return null;
        }
        $cmd = self::cmdByString($value);
        return (is_object($cmd) && $cmd->getType() == 'action') ? $cmd : null;
    }

    /* La commande d'action d'un bouton en mode « action », ou null. */
    public static function buttonCmd($_button) {
        if ($_button['mode'] !== self::MODE_ACTION || $_button['target'] !== self::TARGET_CMD) {
            return null;
        }
        return self::actionCmd($_button, 'cmd');
    }

    /* Le scénario visé par un bouton en mode « action », ou null. */
    public static function buttonScenario($_button) {
        if ($_button['mode'] !== self::MODE_ACTION || $_button['target'] !== self::TARGET_SCENARIO) {
            return null;
        }
        if ($_button['scenario'] <= 0) {
            return null;
        }
        $scenario = scenario::byId($_button['scenario']);
        return is_object($scenario) ? $scenario : null;
    }

    /*
     * La commande qui REPRÉSENTE le bouton : celle dont on reprend le nom quand
     * l'utilisateur n'a pas donné de libellé, et celle dont on lit le champ
     * « valeur » pour deviner l'état quand il ne l'a pas désigné.
     *
     * Pour un interrupteur, c'est « Allumer » : sur un Shelly, « Allumer »,
     * « Éteindre » et « Basculer » pointent toutes la même commande d'état, mais
     * « Allumer » est celle dont le nom décrit le mieux ce que le bouton fait.
     */
    public static function buttonMainCmd($_button) {
        /* Un bouton « nav » ne commande rien : toute recherche de commande le
         * concernant est une erreur de raisonnement, et rendre null ici la rend
         * inoffensive partout à la fois. */
        if ($_button['mode'] === self::MODE_NAV || $_button['mode'] === self::MODE_VIEW) {
            return null;
        }
        if ($_button['mode'] === self::MODE_TOGGLE) {
            foreach (array('on', 'toggle', 'off') as $slot) {
                $cmd = self::actionCmd($_button, $slot);
                if ($cmd !== null) {
                    return $cmd;
                }
            }
            return null;
        }
        return self::buttonCmd($_button);
    }

    /*
     * La commande d'information qui dit l'état d'un bouton, ou null.
     *
     * Deux sources, dans cet ordre :
     *
     *  1. celle que l'utilisateur a désignée dans le formulaire. C'est la
     *     source qui fait foi : elle est explicite, elle se relit, et elle ne
     *     surprendra personne.
     *  2. à défaut, le lien que Jeedom pose lui-même entre une commande
     *     d'action et son état (champ « value » de la commande d'action), celui
     *     dont le coeur se sert pour allumer une tuile de dashboard. Ce n'est
     *     pas une devinette : c'est une déclaration faite ailleurs dans Jeedom,
     *     par le plugin qui a créé la lampe. S'en priver obligerait à
     *     redésigner à la main l'état de chaque bouton alors que
     *     l'installation le sait déjà.
     *
     * Ce qui est rendu est toujours une commande d'information.
     */
    public static function buttonStateCmd($_button) {
        /* Contrat v2.0 : l'état d'un bouton « nav » est TOUJOURS null. Le
         * garde-fou est ici et non chez l'appelant, pour qu'une commande
         * d'état restée dans la configuration après un changement de mode ne
         * fasse pas allumer une pastille sous un bouton de navigation. Une
         * tuile « view » (v3.0) n'a pas d'état non plus : elle a une VALEUR. */
        if ($_button['mode'] === self::MODE_NAV || $_button['mode'] === self::MODE_VIEW) {
            return null;
        }
        if (isset($_button['state']) && $_button['state'] !== '') {
            $state = self::cmdByString($_button['state']);
            if (is_object($state) && $state->getType() == 'info') {
                return $state;
            }
            /* Une commande désignée mais introuvable ne retombe PAS sur la
             * déduction : l'utilisateur a dit ce qu'il voulait, afficher autre
             * chose à sa place serait pire que de n'afficher rien. */
            return null;
        }

        $cmd = self::buttonMainCmd($_button);
        if ($cmd === null) {
            return null;
        }
        $stateId = $cmd->getValue();
        if ($stateId == '') {
            return null;
        }
        $state = self::cmdById($stateId);
        return (is_object($state) && $state->getType() == 'info') ? $state : null;
    }

    /*
     * L'état courant d'un bouton : 0, 1, ou null si la notion n'a pas de sens.
     *
     * On ne rend un état que s'il est binaire : une consigne de température ou
     * un volet à 40 % ne se résume pas à un bouton allumé ou éteint, et mentir
     * là-dessus afficherait sur l'écran l'inverse de la réalité une fois sur
     * deux. Le contrat prévoit null pour exactement ce cas.
     */
    public static function buttonState($_button) {
        /* v3.0 : un bouton inerte (non résolu, rang conservé) n'a pas d'état. */
        if (!empty($_button['_inert'])) {
            return null;
        }
        $state = self::buttonStateCmd($_button);
        if ($state === null || $state->getSubType() != 'binary') {
            return null;
        }
        $value = $state->execCmd();
        if ($value === null || $value === '') {
            return null;
        }
        return ($value == 1) ? 1 : 0;
    }

    /*
     * L'utilisateur a-t-il mis quelque chose dans cet emplacement ?
     *
     * Distingue l'emplacement laissé vide — il n'y a rien à signaler — du
     * bouton renseigné mais cassé, qui mérite une ligne de journal.
     */
    public static function buttonConfigured($_button) {
        if ($_button['mode'] === self::MODE_NAV) {
            /* Choisir le mode « nav » EST l'intention : un bouton de
             * navigation sans page visée est un bouton qu'on a commencé à
             * remplir, et mérite donc la ligne de journal. */
            return true;
        }
        if ($_button['mode'] === self::MODE_VIEW) {
            return true;
        }
        if ($_button['mode'] === self::MODE_TOGGLE) {
            return $_button['on'] !== '' || $_button['off'] !== ''
                || $_button['toggle'] !== '' || $_button['state'] !== '';
        }
        return $_button['target'] !== self::TARGET_NONE;
    }

    /*
     * Ce bouton est-il jouable ici et maintenant ?
     *
     * Un interrupteur exige les DEUX : son état, sans lequel il n'y a aucun
     * moyen de décider du sens, et au moins une commande à jouer. Il vaut mieux
     * qu'il disparaisse de l'écran qu'il ne s'y affiche en n'allumant jamais
     * qu'à moitié.
     */
    public static function buttonResolves($_button) {
        if ($_button['mode'] === self::MODE_NAV) {
            /* Une page visée, et pas la sienne : un bouton qui ouvrirait la
             * page où il se trouve déjà est un bouton mort, et l'utilisateur
             * conclurait que l'écran ne répond plus. */
            return $_button['nav'] >= 0
                && $_button['nav'] < self::MAX_PAGES
                && $_button['nav'] !== $_button['page'];
        }
        if ($_button['mode'] === self::MODE_VIEW) {
            return self::viewCmd($_button) !== null;
        }
        if ($_button['mode'] === self::MODE_TOGGLE) {
            return self::buttonStateCmd($_button) !== null
                && self::buttonMainCmd($_button) !== null;
        }
        return self::buttonCmd($_button) !== null || self::buttonScenario($_button) !== null;
    }

    /* ==================================================== TUILE « VIEW » — v3.0 */

    /* L'empreinte d'un bouton tel qu'il est servi : sa signature de
     * configuration (celle qui fait bouger « version »). */
    public static function buttonFingerprint($_button) {
        return substr(md5(json_encode(self::buttonSignature($_button))), 0, 12);
    }

    private function servedKey($_schema) {
        return 'glowscreen32::served::' . $this->getId() . '::' . self::normalizeSchema($_schema);
    }

    /* Retient, par écran ET par schéma, l'empreinte de chaque rang du layout
     * qui vient d'être servi à la carte — appelé par l'API seule, jamais par
     * un aperçu. press() s'y réfère. */
    public function rememberServedLayout($_schema) {
        $prints = array();
        foreach ($this->buttonsFor($_schema) as $button) {
            $prints[] = self::buttonFingerprint($button);
        }
        cache::set($this->servedKey($_schema), $prints);
    }

    /* La commande d'information montrée par une tuile « view », ou null. */
    public static function viewCmd($_button) {
        if ($_button['mode'] !== self::MODE_VIEW || $_button['view'] === '') {
            return null;
        }
        $cmd = self::cmdByString($_button['view']);
        return (is_object($cmd) && $cmd->getType() == 'info') ? $cmd : null;
    }

    /* La famille de mise en forme d'une commande : binary, numeric ou string. */
    public static function viewKind($_cmd) {
        $subType = $_cmd->getSubType();
        return ($subType === 'binary' || $subType === 'numeric') ? $subType : 'string';
    }

    /* Le seuil de péremption effectif d'une tuile, en SECONDES (0 = jamais).
     * Numérique : 60 min par défaut. Binaire et texte : jamais par défaut — une
     * porte fermée depuis trois jours n'émet rien et dit vrai. */
    public static function viewMaxAge($_fmt, $_kind) {
        if ($_fmt['age'] === '') {
            return ($_kind === 'numeric') ? self::VIEW_NUMERIC_MAX_AGE * 60 : 0;
        }
        return ((int) $_fmt['age']) * 60;
    }

    /* Un nombre lisible d'un coup d'oeil, virgule décimale. $_dec '' = au plus
     * une décimale, et pas de « ,0 » inutile. */
    public static function viewNumber($_value, $_dec) {
        $number = (float) $_value;
        if ($_dec === '') {
            $number = round($number, 1);
            return (abs($number - round($number)) < 0.05)
                ? (string) (int) round($number)
                : number_format($number, 1, ',', '');
        }
        return number_format(round($number, (int) $_dec), (int) $_dec, ',', '');
    }

    /*
     * La valeur mise en forme et le sens d'une tuile « view » — contrat v3.0.
     * Rend array('v' => texte|null, 't' => sens). $_why reçoit la raison d'un
     * « v » nul (missing, disabled, timeout, stale, empty) — l'aperçu de la
     * page de configuration l'affiche.
     *
     * ⚠ PÉREMPTION — pas la règle du bandeau. « null » si l'équipement de la
     * commande est désactivé ou en ALERTE DE COMMUNICATION Jeedom (statut
     * « timeout » : délai maximal entre deux communications dépassé), et, pour
     * une valeur NUMÉRIQUE, si « collectDate » dépasse le seuil de la tuile.
     * Binaire et texte n'ont pas de seuil par défaut.
     */
    public static function viewResult($_button, &$_why = null, &$_cut = null) {
        $_why = '';
        $_cut = null;
        $null = array('v' => null, 't' => 'neutral');
        $cmd  = self::viewCmd($_button);
        if ($cmd === null) {
            $_why = 'missing';
            return $null;
        }
        $eqLogic = $cmd->getEqLogic();
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
            $_why = 'disabled';
            return $null;
        }
        if (((int) $eqLogic->getStatus('timeout', 0)) === 1) {
            $_why = 'timeout';
            return $null;
        }
        $fmt    = $_button['fmt'];
        $kind   = self::viewKind($cmd);
        $maxAge = self::viewMaxAge($fmt, $kind);
        if ($maxAge > 0) {
            $age = self::cmdAge($cmd);
            if ($age !== null && $age > $maxAge) {
                $_why = 'stale';
                return $null;
            }
        }
        $raw = $cmd->execCmd();
        if ($raw === null || is_array($raw) || trim((string) $raw) === '') {
            $_why = 'empty';
            return $null;
        }

        $tone = 'neutral';
        if ($kind === 'binary') {
            $bit = (((int) $raw) === 1) ? 1 : 0;
            if ($fmt['invert'] === 1) {
                $bit = 1 - $bit;
            }
            $text = ($fmt['l' . $bit] !== '') ? $fmt['l' . $bit] : (string) $bit;
            $tone = $fmt['t' . $bit];
        } elseif ($kind === 'numeric' && is_numeric($raw)) {
            $text = self::viewNumber($raw, $fmt['dec']);
            $unit = ($fmt['unit'] === null) ? trim((string) $cmd->getUnite()) : $fmt['unit'];
            if ($unit !== '') {
                $text .= ' ' . $unit;
            }
            /* Seuils CROISSANTS : le sens est celui du plus haut seuil
             * atteint, « base » en dessous du premier. */
            $tone = $fmt['base'];
            $steps = array();
            for ($i = 1; $i <= self::VIEW_THRESHOLDS; $i++) {
                if ($fmt['th' . $i . 'v'] !== '') {
                    $steps[] = array((float) $fmt['th' . $i . 'v'], $fmt['th' . $i . 't']);
                }
            }
            usort($steps, function ($a, $b) { return ($a[0] < $b[0]) ? -1 : (($a[0] > $b[0]) ? 1 : 0); });
            foreach ($steps as $step) {
                if ((float) $raw >= $step[0]) {
                    $tone = $step[1];
                }
            }
        } else {
            $text = trim(self::cleanText($raw));
            for ($i = 1; $i <= self::VIEW_MAPS; $i++) {
                if ($fmt['m' . $i . 'v'] !== '' && strcasecmp($fmt['m' . $i . 'v'], $text) === 0) {
                    $tone = $fmt['m' . $i . 't'];
                    if ($fmt['m' . $i . 'l'] !== '') {
                        $text = $fmt['m' . $i . 'l'];
                    }
                    break;
                }
            }
        }
        if (mb_strlen($text) > self::VALUE_MAX) {
            /* Tronquée ET journalisée (par l'appelant, aux transitions). */
            $_cut = $text;
            $text = mb_substr($text, 0, self::VALUE_MAX);
        }
        return array('v' => $text, 't' => self::sanitizeTone($tone));
    }

    /*
     * Le préremplissage d'une tuile d'après le TYPE GÉNÉRIQUE Jeedom de la
     * commande — contrat v3.0. Rend array('fmt' => …, 'doubt' => bool).
     *
     * « doubt » marque un SENS DOUTEUX : rien, ni dans le coeur de Jeedom ni
     * dans les types génériques, ne fixe ce que veut dire « 1 » pour une porte
     * ou une serrure — chaque module fait à sa façon. Le préremplissage suit
     * la convention la plus répandue (1 = fermé / verrouillé), et la page
     * exige alors de vérifier sur l'APERÇU de la valeur réelle. La case
     * « inverser » de l'affichage Jeedom de la commande (invertBinary), elle,
     * est un fait : elle est reprise.
     */
    public static function viewDefaults($_cmd) {
        $fmt   = self::sanitizeFmt(array());
        $kind  = self::viewKind($_cmd);
        $type  = strtoupper(trim((string) $_cmd->getGeneric_type()));
        $doubt = false;
        if ($kind === 'binary') {
            $table = array(
                'OPENING'            => array('Ouverte', 'warn', 'Fermée', 'ok', true),
                'OPENING_WINDOW'     => array('Ouverte', 'warn', 'Fermée', 'ok', true),
                'BARRIER_STATE'      => array('Ouvert', 'warn', 'Fermé', 'ok', true),
                'GARAGE_STATE'       => array('Ouvert', 'warn', 'Fermé', 'ok', true),
                'LOCK_STATE'         => array('Déverrouillée', 'warn', 'Verrouillée', 'ok', true),
                'ALARM_ENABLE_STATE' => array('Désarmée', 'neutral', 'Armée', 'alert', false),
                'ALARM_STATE'        => array('Calme', 'ok', 'Déclenchée', 'alert', false),
                'SIREN_STATE'        => array('Silence', 'neutral', 'Sirène', 'alert', false),
                'HEATING_STATE'      => array('Arrêt', 'neutral', 'Chauffe', 'ok', false),
                'ENERGY_STATE'       => array('Éteint', 'neutral', 'Allumé', 'ok', false),
                'LIGHT_STATE'        => array('Éteint', 'neutral', 'Allumé', 'ok', false),
                'SMOKE'              => array('RAS', 'ok', 'Fumée', 'alert', false),
                'FLOOD'              => array('RAS', 'ok', 'Fuite', 'alert', false),
                'PRESENCE'           => array('Personne', 'neutral', 'Présence', 'ok', false),
            );
            if (isset($table[$type])) {
                list($fmt['l0'], $fmt['t0'], $fmt['l1'], $fmt['t1'], $doubt) = $table[$type];
            } else {
                /* Type inconnu : on n'invente pas de sens. La valeur brute
                 * s'affiche (0 ou 1) jusqu'à ce que l'utilisateur nomme les
                 * deux états. */
                $doubt = true;
            }
            $fmt['invert'] = ((int) $_cmd->getDisplay('invertBinary', 0) === 1) ? 1 : 0;
        } elseif ($kind === 'numeric') {
            $fmt['unit'] = trim((string) $_cmd->getUnite());
            $numeric = array(
                'TEMPERATURE' => array('°C', 1),
                'WEATHER_TEMPERATURE' => array('°C', 1),
                'HUMIDITY'    => array('%', 0),
                'POWER'       => array('W', 0),
                'CONSUMPTION' => array('kWh', 1),
            );
            if (isset($numeric[$type])) {
                if ($fmt['unit'] === '') {
                    $fmt['unit'] = $numeric[$type][0];
                }
                $fmt['dec'] = $numeric[$type][1];
            }
        }
        return array('fmt' => $fmt, 'doubt' => $doubt, 'kind' => $kind, 'generic' => $type);
    }

    /* ========================================================== MISE EN PAGE */

    /*
     * Les boutons réellement envoyés à la carte, dans l'ordre de l'écran.
     *
     * L'index dans CE tableau est l'identifiant du contrat v1.3 : le rang du
     * bouton, de 0 à 5. « layout », « ping » et « press » passent tous les
     * trois par ici, et c'est ce qui garantit à la fois que le tableau
     * « states » du ping est dans le même ordre que les « buttons » du layout,
     * et que le rang reçu par « press » désigne le même bouton que celui que la
     * carte a dessiné. Trois parcours séparés, même filtrés de la même façon,
     * finiraient un jour par diverger, et le symptôme serait un appui sur la
     * lampe qui ouvrirait le portail.
     *
     * Les cases VIDES sont retirées : une case vide dans le formulaire n'est
     * pas un bouton. En revanche, un bouton CONFIGURÉ dont la cible ne se
     * résout plus (commande supprimée, recréée) GARDE SON RANG, inerte — le
     * retirer décalerait tous les id suivants sans que « version » bouge, et
     * un appui ferait jouer le bouton d'à côté (contrat v3.0, revue).
     */
    public function activeButtons() {
        /* v3.1 : mémoïsé par requête. Un ping le demandait trois fois (états,
         * valeurs, rev), et chaque passage résolvait toutes les commandes. */
        if ($this->_active !== null) {
            return $this->_active;
        }
        $capacity   = $this->pageCapacity();
        $byPage     = array();
        $incomplete = array();
        $overflow   = array();

        $visible = $this->pageMap();
        foreach ($this->buttons() as $index => $button) {
            if (!self::buttonConfigured($button)) {
                /* Une case laissée vide : rien à servir. */
                continue;
            }
            /* v3.2 : une page masquée n'est pas servie, ni ses boutons ; un
             * « nav » vers une page masquée est RETIRÉ — un bouton qui ne mène
             * à rien est pire qu'une case vide. Ce n'est pas un bouton « non
             * résolu » : c'est un changement de configuration, qui fait bouger
             * « version » (signature), et la carte recharge sa mise en page. */
            if (!isset($visible[$button['page']])) {
                continue;
            }
            if ($button['mode'] === self::MODE_NAV && $button['nav'] >= 0 && !isset($visible[$button['nav']])) {
                continue;
            }
            /*
             * ⚠ v3.0 (revue) — UN BOUTON QUI NE SE RÉSOUT PLUS GARDE SON RANG.
             *
             * Jusqu'ici, un bouton dont la commande avait été supprimée ou
             * recréée (réinclusion, équipement refait) était RETIRÉ de
             * l'aplatissement : tous les id suivants reculaient d'un rang SANS
             * que « version » bouge, et un appui fait avant le prochain layout
             * déclenchait le bouton d'à côté. Il est désormais servi à sa place,
             * INERTE (« _inert ») : state null, value null, et son press rend
             * unknown_button. Le défaut est journalisé aux transitions.
             */
            $button['_inert'] = !self::buttonResolves($button);
            if ($button['_inert']) {
                $incomplete[] = $index;
            }

            $page = $button['page'];
            if (!isset($byPage[$page])) {
                $byPage[$page] = array();
            }

            /*
             * La case demandée peut être hors de la grille — l'utilisateur a
             * réduit la grille après coup — ou déjà prise. Le bouton est alors
             * DÉPLACÉ vers la première case libre plutôt qu'écarté : un écran
             * qui perd un bouton parce qu'on est passé de 4×3 à 3×2 serait
             * incompréhensible. Le déplacement est journalisé.
             */
            $slot = $button['slot'];
            if ($slot >= $capacity || isset($byPage[$page][$slot])) {
                $free = -1;
                for ($candidate = 0; $candidate < $capacity; $candidate++) {
                    if (!isset($byPage[$page][$candidate])) {
                        $free = $candidate;
                        break;
                    }
                }
                if ($free < 0) {
                    /* Plus de place du tout : TRONCATURE. Refusée à
                     * l'enregistrement depuis la v3.1 (preSave) ; si une
                     * configuration antérieure la contient encore, elle est
                     * journalisée — aux transitions, pas à chaque ping. */
                    $overflow[] = $index;
                    continue;
                }
                $slot = $free;
            }

            $button['slot']        = $slot;
            $byPage[$page][$slot]  = $button;
        }

        /*
         * L'aplatissement : page par page, case par case. C'est LUI qui définit
         * l'« id » global du contrat v2.0 — un rang continu 0 … N-1 sur tout
         * l'écran, et non un rang par page.
         */
        $active = array();
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            if (!isset($byPage[$page])) {
                continue;
            }
            ksort($byPage[$page]);
            foreach ($byPage[$page] as $button) {
                $active[] = $button;
            }
        }
        $this->noteIncompleteButtons($incomplete);
        $who = $this->getHumanName();
        $this->noteTransitions('overflow', $overflow, 'warning', function ($index) use ($who) {
            return sprintf(__('%1$s : le bouton %2$s ne tient plus dans sa page (grille pleine), il n\'est pas envoyé à l\'écran.', __FILE__),
                $who, $index + 1);
        }, sprintf(__('%s : plus aucun bouton hors grille.', __FILE__), $who));
        $this->_active = $active;
        return $active;
    }

    /* Mémo des boutons aplatis (par requête), et des commandes résolues. */
    private $_active = null;
    private static $_cmdMemo = array();

    /* Oublie ce qui a été mémoïsé : configuration enregistrée, ou recalcul
     * d'un ping retenu (une commande a pu être recréée entre-temps). */
    public function forgetMemo() {
        $this->_active = null;
        self::$_cmdMemo = array();
    }

    /* cmd::byString mémoïsé pour la requête. Lève comme lui. */
    public static function cmdByString($_reference) {
        $reference = trim((string) $_reference);
        if (!array_key_exists($reference, self::$_cmdMemo)) {
            try {
                self::$_cmdMemo[$reference] = cmd::byString($reference);
            } catch (Throwable $e) {
                self::$_cmdMemo[$reference] = null;
            }
        }
        return self::$_cmdMemo[$reference];
    }

    /* cmd::byId mémoïsé pour la requête. */
    public static function cmdById($_id) {
        $key = '#id:' . (int) $_id;
        if (!array_key_exists($key, self::$_cmdMemo)) {
            self::$_cmdMemo[$key] = cmd::byId($_id);
        }
        return self::$_cmdMemo[$key];
    }

    /*
     * Journalise les boutons qui ne se résolvent plus — aux TRANSITIONS
     * seulement : activeButtons() est appelée par chaque ping.
     */
    private function noteIncompleteButtons($_incomplete) {
        $who = $this->getHumanName();
        $this->noteTransitions('incomplete', $_incomplete, 'warning', function ($index) use ($who) {
            return sprintf(__('%1$s : le bouton %2$s est incomplet ou vise une cible introuvable. Il garde sa place à l\'écran, inerte (pastille vide, appui refusé), jusqu\'à ce que sa configuration soit corrigée.', __FILE__),
                $who, $index + 1);
        }, sprintf(__('%s : plus aucun bouton incomplet.', __FILE__), $who));
    }

    /*
     * ============================ APLATISSEMENT DU SCHÉMA 1 ============================
     *
     * Ce que voit une carte qui n'annonce pas de schéma : les SIX PREMIERS
     * boutons, renumérotés 0 à 5 DANS CET APLATISSEMENT.
     *
     * Deux règles, et elles ne sont pas décoratives :
     *
     *  - les boutons « nav » en sont EXCLUS. Une carte v1.4 ne connaît que
     *    « action » et « toggle » ; un mode inconnu la ferait dessiner une
     *    tuile qui, à l'appui, recevrait unknown_button. Mieux vaut qu'elle ne
     *    la voie jamais.
     *  - la renumérotation est CONTINUE. L'id envoyé à une carte de schéma 1
     *    n'est donc PAS l'id global du schéma 2 dès qu'il existe un bouton
     *    « nav » avant lui, ou plus de six boutons.
     *
     * D'où le point délicat de tout ce travail : « press » doit résoudre le
     * rang reçu DANS LE MÊME APLATISSEMENT que celui qui a servi le « layout ».
     * C'est pour cela que press() reçoit le schéma négocié, et non une valeur
     * par défaut. Une carte de schéma 1 qui enverrait son rang 2 et qu'on
     * résoudrait dans la numérotation globale allumerait le bouton d'à côté —
     * et sur ce projet, « le bouton d'à côté » a déjà ouvert un portail.
     */
    public function legacyButtons() {
        $legacy   = array();
        $dropped  = 0;
        foreach ($this->activeButtons() as $button) {
            /* Ni « nav » (v2.0), ni « view » (v3.0) : une carte v1.4 ne
             * connaît que « action » et « toggle ». */
            if ($button['mode'] === self::MODE_NAV || $button['mode'] === self::MODE_VIEW) {
                continue;
            }
            if (count($legacy) >= self::LEGACY_MAX_BUTTONS) {
                $dropped++;
                continue;
            }
            $legacy[] = $button;
        }
        if ($dropped > 0) {
            /* Journalisé — mais au niveau « debug » : une carte de schéma 1
             * interroge toutes les trente secondes, et une ligne
             * d'avertissement par ping noierait le journal. Le mode dégradé est
             * un choix assumé du contrat, pas un incident. */
            log::add('glowscreen32', 'debug', sprintf(
                __('%1$s : servi en schéma 1, %2$s bouton(s) au-delà des six premiers ne sont pas envoyés.', __FILE__),
                $this->getHumanName(), $dropped));
        }
        return $legacy;
    }

    /* Le libellé d'un bouton : le sien, ou à défaut le nom de ce qu'il
     * déclenche — qui est au moins exact. Un bouton sans libellé vaut mieux que
     * pas de bouton. */
    public function buttonLabel($_button) {
        if ($_button['label'] !== '') {
            return $_button['label'];
        }
        $cmd = self::buttonMainCmd($_button);
        if ($cmd !== null) {
            return self::trimText($cmd->getName(), self::LABEL_MAX);
        }
        $scenario = self::buttonScenario($_button);
        if ($scenario !== null) {
            return self::trimText($scenario->getName(), self::LABEL_MAX);
        }
        $view = self::viewCmd($_button);
        if ($view !== null) {
            return self::trimText($view->getName(), self::LABEL_MAX);
        }
        /* Un bouton « nav » sans libellé prend le titre de la page qu'il
         * ouvre : c'est ce que l'utilisateur voulait écrire. */
        if ($_button['mode'] === self::MODE_NAV) {
            return self::trimText($this->pageTitle($_button['nav']), self::LABEL_MAX);
        }
        return '';
    }

    /*
     * La mise en page, dans le schéma NÉGOCIÉ.
     *
     * Le défaut est le schéma 1 : tout appelant interne qui ne se pose pas la
     * question — une page d'administration, un contrôle — doit recevoir ce que
     * la v1.4 rendait, et non une structure que rien d'autre ne saurait lire.
     * Seul le point d'entrée des cartes, qui lit l'en-tête, demande le schéma 2.
     */
    public function layout($_schema = self::SCHEMA_LEGACY) {
        $schema = self::normalizeSchema($_schema);
        return ($schema >= self::SCHEMA_V2)
            ? $this->layoutV2($schema)
            : $this->layoutLegacy();
    }

    /* Un schéma ramené à 1, 2 ou 3 : on ne répond jamais au-dessus de ce
     * qu'on sait servir, ni au-dessus de ce qui est annoncé. */
    public static function normalizeSchema($_schema) {
        $schema = (int) $_schema;
        if ($schema >= self::SCHEMA_CURRENT) {
            return self::SCHEMA_CURRENT;
        }
        return ($schema >= self::SCHEMA_V2) ? self::SCHEMA_V2 : self::SCHEMA_LEGACY;
    }

    /*
     * L'APLATISSEMENT du schéma négocié — c'est lui qui définit les « id ».
     *
     *   schéma 1 : legacyButtons() — six au plus, ni « nav » ni « view » ;
     *   schéma 2 : tous les boutons SAUF les tuiles « view » (v3.0), id
     *              recalculés sur ce qui reste — une carte de schéma 2 prendrait
     *              une tuile « view » pour un bouton et enverrait un « press » à
     *              chaque toucher ;
     *   schéma 3 : tout.
     *
     * ⚠ Les « id » d'un même bouton DIFFÈRENT d'un schéma à l'autre. « press »
     * résout donc le rang reçu DANS CETTE LISTE-CI, pour le schéma de la
     * requête, et jamais dans une autre — sinon, le bouton d'à côté.
     */
    public function buttonsFor($_schema) {
        $schema = self::normalizeSchema($_schema);
        if ($schema === self::SCHEMA_LEGACY) {
            return $this->legacyButtons();
        }
        $buttons = $this->activeButtons();
        if ($schema === self::SCHEMA_CURRENT) {
            return $buttons;
        }
        $kept = array();
        foreach ($buttons as $button) {
            if ($button['mode'] !== self::MODE_VIEW) {
                $kept[] = $button;
            }
        }
        return $kept;
    }

    /*
     * Le schéma 1, INCHANGÉ depuis la v1.2 — octet pour octet.
     *
     * Rien ne doit bouger ici : les champs, leur ordre, leurs types. Une carte
     * de schéma 1 qui interroge ce plugin doit recevoir exactement ce qu'un
     * plugin v1.4 lui rendait.
     */
    public function layoutLegacy() {
        $buttons = array();
        foreach ($this->legacyButtons() as $rank => $button) {
            $buttons[] = array(
                /* Le RANG dans CET aplatissement, pas l'id global du schéma 2,
                 * et pas un identifiant de commande — contrat v1.3. */
                'id'    => $rank,
                'label' => $this->buttonLabel($button),
                'color' => $button['color'],
                /* La forme v1 : « none » est une représentation interne au
                 * plugin, elle n'a jamais voyagé sur le fil en schéma 1. */
                'icon'  => self::legacyIcon($button['icon']),
                'mode'  => $button['mode'],
                'state' => self::buttonState($button),
            );
        }

        return array(
            'ok'      => true,
            'device'  => self::normalizeMac($this->getConfiguration('mac', '')),
            'name'    => $this->getName(),
            'version' => $this->version(),
            'poll'    => $this->poll(),
            'buttons' => $buttons,
        );
    }

    /*
     * Le schéma 2. ADDITIF : aucun champ de la v1.4 ne change de sens, de type,
     * ni ne disparaît — c'est précisément ce que la v1.3 n'avait pas su faire
     * avec « id », et ce qui lui a coûté une rupture.
     */
    public function layoutV2($_schema = self::SCHEMA_CURRENT) {
        $schema = self::normalizeSchema($_schema);
        if ($schema < self::SCHEMA_V2) {
            $schema = self::SCHEMA_V2;
        }
        $active = $this->buttonsFor($schema);
        $pages  = $this->pages();

        /* Les pages à envoyer : l'accueil toujours, celles qui portent au moins
         * un bouton, et celles qu'un bouton « nav » vise — une page vide mais
         * atteignable reste une page, et la carte doit savoir l'afficher plutôt
         * que de rester sur un bouton qui ne fait rien. */
        $map   = $this->pageMap();
        $home  = array_search(0, $map, true);
        $used  = array($home => true);
        foreach ($active as $button) {
            $used[$button['page']] = true;
            if ($button['mode'] === self::MODE_NAV && empty($button['_inert'])) {
                $used[$button['nav']] = true;
            }
        }

        $buttonsByPage = array();
        $states        = array();
        $values        = array();
        $cuts          = array();
        foreach ($active as $id => $button) {
            $state    = self::buttonState($button);
            $states[] = $state;
            $view     = $this->viewFor($button, $cuts);
            $values[] = $view;

            /*
             * L'icône n'est résolue QU'ICI : vocabulaire, alias, puis « none ».
             * Ce qui n'est résolu par rien laisse une ligne de journal qui
             * nomme l'écran ET le bouton — sans elle, l'utilisateur verrait une
             * tuile perdre son icône sans savoir laquelle corriger.
             */
            $icon = self::resolveIcon($button['icon']);
            if ($icon === self::ICON_NONE && trim((string) $button['icon']) !== ''
                && strtolower(trim((string) $button['icon'])) !== self::ICON_NONE) {
                log::add('glowscreen32', 'warning', sprintf(
                    __('%1$s : l\'icône « %2$s » du bouton « %3$s » n\'existe pas et n\'a pas d\'équivalent connu ; la tuile s\'affichera sans icône. Choisissez-en une dans la liste.', __FILE__),
                    $this->getHumanName(), $button['icon'], $this->buttonLabel($button)));
            }

            $entry = array(
                /* L'id GLOBAL à l'écran entier — contrat v2.0. Opaque pour la
                 * carte, qui le renvoie tel quel à « press ». « page » et
                 * « slot » ne servent QU'À LA MISE EN PAGE. */
                'id'    => $id,
                'slot'  => $button['slot'],
                'label' => $this->buttonLabel($button),
                'color' => $button['color'],
                'icon'  => $icon,
                /* Un « nav » inerte (page visée invalide) part comme un bouton
                 * « action » inerte : sa place est gardée, son appui refusé. */
                'mode'  => ($button['mode'] === self::MODE_NAV && !empty($button['_inert'])) ? self::MODE_ACTION : $button['mode'],
            );
            if ($button['mode'] === self::MODE_NAV && empty($button['_inert'])) {
                /* Le numéro SERVI de la page visée (v3.2). */
                $entry['page'] = $map[$button['nav']];
            }
            $entry['state'] = $state;
            /* v3.0 : la valeur mise en forme et son sens, volatils (la carte
             * ne les met pas en NVS). Schéma 3 seulement — buttonsFor() a déjà
             * retiré les tuiles « view » pour une carte de schéma 2. */
            if ($view !== null) {
                $entry['value'] = $view['v'];
                $entry['tone']  = $view['t'];
            }

            $buttonsByPage[$button['page']][] = $entry;
        }

        $this->noteValueCuts($cuts);
        $payload = array();
        for ($id = 0; $id < self::MAX_PAGES; $id++) {
            if (!isset($used[$id]) || !isset($map[$id])) {
                continue;
            }
            /* v3.2 : numéro SERVI, titre et parent réécrits sur les seules
             * pages visibles. */
            $served = $map[$id];
            $page = array(
                'id'    => $served,
                'title' => $this->servedTitle($id, $served),
            );
            /* Absent sur la page 0 : l'accueil n'a pas de « retour ». */
            if ($served > 0) {
                $page['parent'] = $this->servedParent($id);
            }
            $page['buttons'] = isset($buttonsByPage[$id]) ? $buttonsByPage[$id] : array();
            $payload[] = $page;
        }

        $grid    = $this->grid();
        $version = $this->version();
        $info    = $this->infoText();
        $answer  = array(
            'ok'       => true,
            'schema'   => $schema,
            'device'   => self::normalizeMac($this->getConfiguration('mac', '')),
            'name'     => $this->getName(),
            'version'  => $version,
            'poll'     => $this->poll(),
            'grid'     => $grid,
            /* « readonly » en schéma 3 seulement : une carte de schéma 2 ne
             * le lit pas — le plugin refuse ses « press » de toute façon. */
            'ui'       => ($schema >= self::SCHEMA_CURRENT)
                ? array('swipe' => $this->swipe(), 'clock' => $this->clock(), 'readonly' => $this->readOnly())
                : array('swipe' => $this->swipe(), 'clock' => $this->clock()),
            'info'     => $info,
            'time'     => time(),
            'tzoffset' => self::tzOffset(),
            'pages'    => $payload,
            'states'   => $states,
            /* Contrat v2.2 : ajoutés EN FIN de réponse, et en schéma 2
             * seulement. Une carte v2.0/v2.1 ne les cherche pas et ne les lit
             * donc pas (ArduinoJson ne lit que les clés demandées). */
            'features' => self::features(),
            'rev'      => self::revOf($version, $states, $info, $this->commandHeadSeq(),
                ($schema >= self::SCHEMA_CURRENT) ? $values : null),
        );

        /*
         * Le plafond de 8 192 octets est celui du firmware (JEEDOM_JSON_MAX).
         * Le dépasser ne se tronque pas — une mise en page coupée au milieu
         * d'un bouton n'est pas du JSON — mais il ne doit pas non plus passer
         * inaperçu : la carte rejetterait la réponse et garderait son cache,
         * sans que rien côté serveur n'explique pourquoi l'écran ne change
         * plus.
         */
        $size = strlen((string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($size > self::MAX_PAYLOAD) {
            log::add('glowscreen32', 'error', sprintf(
                __('%1$s : la mise en page fait %2$s octets, au-delà des %3$s que la carte accepte. Elle la rejettera. Réduisez le nombre de boutons ou la longueur des libellés.', __FILE__),
                $this->getHumanName(), $size, self::MAX_PAYLOAD));
        }

        return $answer;
    }

    /*
     * L'état de chaque bouton, dans l'ordre de la mise en page. Ajouté au
     * contrat en v1.2, et c'est le champ qui fait tout le travail de fraîcheur :
     * « version » ne bouge qu'aux changements de CONFIGURATION, si bien qu'une
     * lampe allumée depuis l'application Jeedom, un interrupteur mural ou un
     * scénario laissait la pastille de l'écran périmée jusqu'au prochain
     * rechargement complet.
     *
     * C'est l'appel le plus fréquent du plugin — toutes les trente secondes,
     * multiplié par le nombre d'écrans — donc il ne construit ni libellés, ni
     * couleurs, ni identifiants : seulement les états.
     */
    public function states($_schema = self::SCHEMA_LEGACY) {
        return $this->rows($_schema)[0];
    }

    /*
     * « states » et « values » en UNE SEULE passe sur le même aplatissement —
     * v3.1. Leurs longueurs sont donc égales par construction (la carte
     * ignore « values » et force un layout sinon), et chaque commande n'est
     * lue qu'une fois. « values » vaut null hors schéma 3.
     */
    public function rows($_schema) {
        $schema = self::normalizeSchema($_schema);
        $states = array();
        $values = ($schema >= self::SCHEMA_CURRENT) ? array() : null;
        $cuts   = array();
        foreach ($this->buttonsFor($schema) as $button) {
            $states[] = self::buttonState($button);
            if ($values !== null) {
                $values[] = $this->viewFor($button, $cuts);
            }
        }
        $this->noteValueCuts($cuts);
        return array($states, $values);
    }

    /* La valeur d'une tuile « view » (ou null pour un autre mode), en notant
     * une éventuelle troncature à 16 caractères dans $_cuts. */
    public function viewFor($_button, &$_cuts) {
        if ($_button['mode'] !== self::MODE_VIEW) {
            return null;
        }
        $why = '';
        $cut = null;
        $result = self::viewResult($_button, $why, $cut);
        if ($cut !== null) {
            $_cuts[] = $_button['page'] . ':' . $_button['slot'] . ':' . $cut;
        }
        return $result;
    }

    /* « value » tronquée à 16 caractères : journalisé aux TRANSITIONS. */
    private function noteValueCuts($_cuts) {
        $who = $this->getHumanName();
        $this->noteTransitions('valuecut', $_cuts, 'warning', function ($item) use ($who) {
            $parts = explode(':', $item, 3);
            return sprintf(__('%1$s : la valeur « %2$s » (page %3$s, case %4$s) dépasse %5$s caractères, elle est coupée à l\'écran. Raccourcissez son libellé.', __FILE__),
                $who, $parts[2], ((int) $parts[0]) + 1, ((int) $parts[1]) + 1, self::VALUE_MAX);
        });
    }

    /*
     * « values » — contrat v3.0, schéma 3. Même longueur que « states »,
     * indexé par l'id global : null pour toute tuile qui n'est pas « view »,
     * {"v": …, "t": …} sinon.
     */
    public function values() {
        return $this->rows(self::SCHEMA_CURRENT)[1];
    }

    /*
     * La réponse de « ping », dans le schéma négocié.
     *
     * Schéma 1 : trois champs, et « states » dans le même ordre que les
     * « buttons » du layout de schéma 1 — donc l'aplatissement à six, sans les
     * boutons « nav ».
     *
     * Schéma 2 : deux champs de plus, et « states » indexé par l'id GLOBAL. Si
     * la taille du tableau ne correspond pas au nombre de boutons connus, le
     * firmware ignore « states » et force un layout complet : c'est cette règle
     * qui a rattrapé la v1.2, et elle est conservée telle quelle.
     */
    public function ping($_schema = self::SCHEMA_LEGACY, $_deliver = false) {
        $schema = self::normalizeSchema($_schema);
        if ($schema < self::SCHEMA_V2) {
            /* Schéma 1 : INCHANGÉ, octet pour octet. Ni « features », ni
             * « rev », ni « cmd » — et aucune commande retirée de la file. */
            return array(
                'ok'      => true,
                'version' => $this->version(),
                'time'    => time(),
                'states'  => $this->states(self::SCHEMA_LEGACY),
            );
        }
        $version = $this->version();
        $info    = $this->infoText();
        list($states, $values) = $this->rows($schema);
        $answer  = array(
            'ok'       => true,
            'version'  => $version,
            'time'     => time(),
            'tzoffset' => self::tzOffset(),
            'info'     => $info,
            'states'   => $states,
        );
        if ($values !== null) {
            $answer['values'] = $values;
        }
        /*
         * Contrat v2.2 : au plus UNE commande à distance par réponse, retirée
         * de la file au moment même où elle y est placée — livraison « au plus
         * une fois ». Seul le point d'entrée des cartes demande la livraison ;
         * un appel interne (aperçu, contrôle) ne doit rien consommer.
         */
        if ($_deliver) {
            $cmd = $this->dequeueCommand();
            if ($cmd !== null) {
                $answer['cmd'] = $cmd;
            }
        }
        $answer['features'] = self::features();
        /* Calculée APRÈS le retrait : s'il reste des commandes, la tête de file
         * a changé, « rev » aussi, et le ping suivant revient aussitôt. */
        $answer['rev'] = self::revOf($version, $states, $info, $this->commandHeadSeq(), $values);
        return $answer;
    }

    /* ============================================ ATTENTE LONGUE — v2.2 */

    /*
     * Durée maximale de retenue d'un « ping », en secondes — « features.wait ».
     * La carte envoie min(features.wait, poll), si bien que l'intervalle entre
     * deux requêtes reste sous poll, donc sous le plafond 2 × poll de la v2.1 :
     * le seuil hors ligne 3 × 2 × poll ne change pas.
     */
    const LONGPOLL_MAX = 25;

    /* Pas de la boucle de retenue : le compteur de réveil est relu au moins
     * deux fois par seconde (contrat : « au plus toutes les 500 ms »). */
    const LONGPOLL_TICK_US = 250000;

    /* Recalcul forcé de « rev » pendant une retenue, en secondes — même sans
     * réveil. C'est ce qui attrape la PÉREMPTION du bandeau (aucun événement ne
     * la signale) et un événement que le listener aurait manqué. */
    const LONGPOLL_RECHECK = 15;

    /* Les capacités annoncées en schéma 2 — contrat v2.2. La carte n'utilise
     * une capacité que si elle est annoncée. */
    public static function features() {
        return array('wait' => self::LONGPOLL_MAX, 'cmd' => true);
    }

    /*
     * « rev » : un court hachage de tout ce que porte un ping SAUF « time » —
     * les états, le bandeau, la version, et le seq de la commande en tête de
     * file. Opaque pour la carte, ≤ 16 caractères (8 ici).
     */
    public static function revOf($_version, $_states, $_info, $_headSeq, $_values = null) {
        $parts = array((int) $_version, array_values((array) $_states), $_info, $_headSeq);
        /* v3.0 : « rev » couvre « values » en schéma 3. Absent en schéma 2, si
         * bien que la rev d'une carte de schéma 2 est calculée exactement comme
         * en v2.2. */
        if ($_values !== null) {
            $parts[] = array_values((array) $_values);
        }
        return substr(md5(json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 8);
    }

    /* La « rev » courante de cet écran, telle qu'un ping de CE schéma la
     * rendrait. */
    public function currentRev($_schema = self::SCHEMA_V2) {
        $schema = self::normalizeSchema($_schema);
        list($states, $values) = $this->rows($schema);
        return self::revOf($this->version(), $states, $this->infoText(), $this->commandHeadSeq(), $values);
    }

    /*
     * Les petits fichiers de l'attente longue, dans le dossier temporaire de
     * Jeedom (/tmp/jeedom/glowscreen32/), et PAS dans cache:: : la boucle de
     * retenue les relit quatre fois par seconde, et ce relevé doit rester
     * SANS REQUÊTE SQL quel que soit le moteur de cache choisi dans Jeedom
     * (MariadbCache en est un). Un fichier local se lit sans rien coûter.
     *
     *   wake-<id> — change à chaque événement qui peut faire bouger « rev »
     *               (listener sur les états et le bandeau, version, file) ;
     *   hold-<id> — la génération de la requête retenue en cours : une
     *               nouvelle requête l'écrase, ce qui LIBÈRE la précédente.
     */
    public static function holdPath($_kind, $_id) {
        static $dir = null;
        if ($dir === null) {
            $dir = jeedom::getTmpFolder('glowscreen32');
        }
        return $dir . '/' . $_kind . '-' . ((int) $_id);
    }

    public static function readToken($_kind, $_id) {
        $value = @file_get_contents(self::holdPath($_kind, $_id));
        return ($value === false) ? '' : $value;
    }

    /* Rend le jeton écrit, ou null si le fichier n'a pas pu l'être. */
    private static function writeToken($_kind, $_id) {
        $token = bin2hex(random_bytes(6)) . sprintf('%.6F', microtime(true));
        $path  = self::holdPath($_kind, $_id);
        if (@file_put_contents($path, $token, LOCK_EX) === false) {
            return null;
        }
        @chmod($path, 0666);
        return $token;
    }

    /* Le motif de sortie de la dernière retenue de CETTE requête, et son
     * jeton : l'API ne livre pas de commande à une requête libérée, ni à une
     * requête dont le jeton n'est plus le dernier (v3.1). */
    private static $_holdWhy = '';
    private static $_holdToken = null;

    public static function lastHoldWhy() {
        return self::$_holdWhy;
    }

    /* Vrai si une requête retenue PLUS RÉCENTE de cet écran a pris la main
     * depuis : la nôtre n'est plus celle qui doit recevoir une commande. */
    public static function holdSuperseded($_id) {
        return self::$_holdToken !== null && self::readToken('hold', $_id) !== self::$_holdToken;
    }

    /* Réveille un éventuel ping retenu de cet écran. Ne coûte qu'une écriture
     * de fichier : appelé depuis le listener, il s'exécute dans le processus
     * qui a émis l'événement. */
    public static function wakeScreen($_id) {
        if ((int) $_id <= 0) {
            return;
        }
        self::writeToken('wake', $_id);
    }

    /*
     * Retient un ping jusqu'à ce que « rev » change ou que $_wait secondes
     * s'écoulent — contrat v2.2. Rend l'eqLogic RELU depuis la base à la
     * sortie (ou null s'il a disparu) : la réponse doit être calculée au
     * moment où elle part, sur la configuration du moment.
     *
     * $_wake est le jeton de réveil lu AVANT que l'appelant ait calculé la
     * rev courante : un événement survenu entre les deux est ainsi vu au
     * premier tour de boucle au lieu d'être perdu.
     *
     * Aucune requête SQL pendant l'attente proprement dite : seuls les deux
     * fichiers ci-dessus sont relus. L'eqLogic n'est rechargé que sur réveil,
     * ou toutes les LONGPOLL_RECHECK secondes.
     */
    public static function holdPing($_eqLogic, $_rev, $_wait, $_wake, $_schema = self::SCHEMA_V2) {
        $id   = (int) $_eqLogic->getId();
        $wait = max(1, min(self::LONGPOLL_MAX, (int) $_wait));
        @set_time_limit($wait + 15);
        /* Aucune session ne doit rester ouverte pendant la retenue : elle
         * bloquerait toute autre requête de la même session. L'API n'en ouvre
         * pas ; le garde-fou coûte une ligne. */
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $mine      = self::writeToken('hold', $id);
        self::$_holdToken = $mine;
        if ($mine === null) {
            /* v3.1 : jeton non inscriptible — on attend quand même, sans test
             * de libération (pas d'échec « ouvert »), et on le dit une fois. */
            $flag = 'glowscreen32::holdunwritable';
            if (cache::byKey($flag)->getValue('') === '') {
                cache::set($flag, 1, 86400);
                log::add('glowscreen32', 'error', sprintf(
                    __('Attente longue : %s n\'est pas inscriptible. Les requêtes retenues ne peuvent plus être libérées par la suivante ; vérifiez les droits du dossier temporaire de Jeedom.', __FILE__),
                    self::holdPath('hold', $id)));
            }
        }
        $wake      = (string) $_wake;
        $start     = microtime(true);
        $lastCheck = $start;
        $eqLogic   = $_eqLogic;
        $why       = 'timeout';

        while (true) {
            usleep(self::LONGPOLL_TICK_US);
            $now = microtime(true);
            if ($now - $start >= $wait) {
                break;
            }
            if ($mine !== null && self::readToken('hold', $id) !== $mine) {
                $why = 'released';
                break;
            }
            $current = self::readToken('wake', $id);
            if ($current !== $wake || ($now - $lastCheck) >= self::LONGPOLL_RECHECK) {
                $wake      = $current;
                $lastCheck = $now;
                /* Mémo vidé : une commande a pu être recréée entre-temps. */
                $_eqLogic->forgetMemo();
                $fresh     = self::byId($id);
                if (!is_object($fresh) || $fresh->getIsEnable() != 1) {
                    $eqLogic = null;
                    $why     = 'gone';
                    break;
                }
                $eqLogic = $fresh;
                if ($eqLogic->commandCount() > 0 || $eqLogic->currentRev($_schema) !== $_rev) {
                    $why = 'changed';
                    break;
                }
            }
        }

        self::$_holdWhy = $why;
        if ($why === 'timeout' || $why === 'released') {
            /* La réponse doit être calculée sur la configuration DU MOMENT :
             * on relit l'écran avant de répondre. */
            $_eqLogic->forgetMemo();
            $fresh   = self::byId($id);
            $eqLogic = (is_object($fresh) && $fresh->getIsEnable() == 1) ? $fresh : null;
        }
        log::add('glowscreen32', 'debug', sprintf('%s : ping retenu %.1f s (%s)',
            is_object($eqLogic) ? $eqLogic->getHumanName() : ('#' . $id), microtime(true) - $start, $why));
        return $eqLogic;
    }

    /* ============================================ COMMANDES À DISTANCE — v2.2 */

    const CMD_QUEUE_MAX = 8;
    const CMD_TTL       = 600;

    /* Le vocabulaire fermé du contrat. « wifi » n'est PAS une commande Jeedom :
     * elle ne part que depuis la page de l'équipement (ajax, administrateur). */
    const REMOTE_VERBS = array('reboot', 'identify', 'message', 'page', 'calibrate', 'ota', 'wifi');

    private function queueKey() {
        return 'glowscreen32::queue::' . $this->getId();
    }

    /*
     * Le mot de passe d'une commande « wifi » — v3.1 : JAMAIS dans le cache de
     * Jeedom, que le coeur archive (cache.tar.gz) et sauvegarde. Il est rangé
     * dans un fichier 0600 propre au plugin, sous data/secrets/ — dossier
     * exclu des sauvegardes (backupExclude) et du déploiement — et effacé à la
     * livraison comme à l'expiration.
     */
    public static function secretDir() {
        $dir = __DIR__ . '/../../data/secrets';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $dir;
    }

    private static function secretPath($_id, $_seq) {
        return self::secretDir() . '/wifi-' . ((int) $_id) . '-' . ((int) $_seq);
    }

    private static function writeSecret($_id, $_seq, $_value) {
        $path = self::secretPath($_id, $_seq);
        $old  = umask(0077);
        $ok   = @file_put_contents($path, (string) $_value, LOCK_EX);
        umask($old);
        @chmod($path, 0600);
        if ($ok === false) {
            throw new Exception(__('Impossible d\'écrire le mot de passe en attente (data/secrets).', __FILE__));
        }
    }

    private static function takeSecret($_id, $_seq) {
        $path  = self::secretPath($_id, $_seq);
        $value = @file_get_contents($path);
        @unlink($path);
        return ($value === false) ? null : $value;
    }

    /* Efface les mots de passe dont la commande a expiré (appelé par le
     * cron et à chaque lecture de la file). */
    public static function purgeSecrets() {
        foreach ((array) @glob(self::secretDir() . '/wifi-*') as $file) {
            if (is_file($file) && (time() - filemtime($file)) > self::CMD_TTL + 60) {
                @unlink($file);
            }
        }
    }

    /* La file, débarrassée des commandes expirées. */
    private function liveQueue() {
        $queue = cache::byKey($this->queueKey())->getValue(array());
        if (!is_array($queue)) {
            return array();
        }
        $now  = time();
        $live = array();
        foreach ($queue as $entry) {
            if (is_array($entry) && isset($entry['expires']) && $entry['expires'] > $now) {
                $live[] = $entry;
            } elseif (is_array($entry) && !empty($entry['secret'])) {
                self::takeSecret($this->getId(), $entry['cmd']['seq']);
            }
        }
        return $live;
    }

    public function commandCount() {
        return count($this->liveQueue());
    }

    /* Le seq de la commande en tête de file, ou null — entre dans « rev ». */
    public function commandHeadSeq() {
        if ($this->getId() == '') {
            return null;
        }
        $queue = $this->liveQueue();
        return (count($queue) > 0) ? (int) $queue[0]['cmd']['seq'] : null;
    }

    /*
     * Section critique sur la file de CET écran. La file est écrite par la page
     * ou un scénario (mise en file) et par l'API (retrait) : sans verrou, deux
     * écritures croisées perdraient une commande, ou en livreraient une deux
     * fois.
     */
    private function withQueueLock($_callback) {
        $handle = @fopen(self::holdPath('lock', $this->getId()), 'c');
        if ($handle !== false) {
            @flock($handle, LOCK_EX);
        }
        try {
            return $_callback();
        } finally {
            if ($handle !== false) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
    }

    /*
     * Ramène les arguments d'un verbe dans les bornes du contrat, AVANT
     * l'entrée en file. Rend la commande telle qu'elle partira (sans seq), ou
     * lève une exception pour un verbe inconnu ou un argument sans lequel la
     * commande n'a pas de sens.
     */
    public static function remoteCommand($_verb, $_args = array()) {
        $verb = strtolower(trim((string) $_verb));
        if (!in_array($verb, self::REMOTE_VERBS, true)) {
            throw new Exception(sprintf(__('Commande à distance inconnue : %s', __FILE__), $verb));
        }
        $args  = is_array($_args) ? $_args : array();
        $clamp = function ($_value, $_min, $_max, $_default) {
            if (!isset($_value) || trim((string) $_value) === '' || !is_numeric($_value)) {
                return $_default;
            }
            return max($_min, min($_max, (int) round((float) $_value)));
        };
        $cmd = array('do' => $verb);
        switch ($verb) {
            case 'identify':
                $cmd['duration'] = $clamp(isset($args['duration']) ? $args['duration'] : null, 1, 120, 10);
                break;
            case 'message':
                $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) (isset($args['text']) ? $args['text'] : '')));
                if ($text === '') {
                    throw new Exception(__('Le message à afficher est vide.', __FILE__));
                }
                $cmd['text']     = (mb_strlen($text) > 64) ? mb_substr($text, 0, 64) : $text;
                $cmd['duration'] = $clamp(isset($args['duration']) ? $args['duration'] : null, 1, 600, 30);
                break;
            case 'page':
                $cmd['page'] = $clamp(isset($args['page']) ? $args['page'] : null, 0, self::MAX_PAGES - 1, 0);
                break;
            case 'wifi':
                $ssid = (string) (isset($args['ssid']) ? $args['ssid'] : '');
                $pass = (string) (isset($args['pass']) ? $args['pass'] : '');
                if (trim($ssid) === '') {
                    throw new Exception(__('Le nom du réseau Wi-Fi est vide.', __FILE__));
                }
                /* En OCTETS, sans couper un caractère : 32 et 64 sont les
                 * plafonds du Wi-Fi lui-même (SSID, clé WPA2). */
                $cmd['ssid'] = (strlen($ssid) > 32) ? mb_strcut($ssid, 0, 32, 'UTF-8') : $ssid;
                $cmd['pass'] = (strlen($pass) > 64) ? mb_strcut($pass, 0, 64, 'UTF-8') : $pass;
                break;
        }
        return $cmd;
    }

    /*
     * Met une commande en file pour cet écran — contrat v2.2.
     *
     * 8 au plus (la plus ancienne est abandonnée, et journalisée), durée de
     * vie 10 minutes, « seq » croissant PAR ÉCRAN et PERSISTANT : il est rangé
     * dans la configuration du plugin (config::save, table config), jamais dans
     * l'eqLogic, et survit donc au vidage du cache comme à un redémarrage de
     * Jeedom. Une carte ignore une commande dont le seq est celui de la
     * dernière exécutée : un seq qui repartirait à 1 ferait ignorer la
     * première commande suivante.
     */
    public function enqueueCommand($_verb, $_args = array()) {
        if ($this->getId() == '') {
            throw new Exception(__('Écran non enregistré.', __FILE__));
        }
        $cmd = self::remoteCommand($_verb, $_args);
        $who = $this->getHumanName();
        $entry = $this->withQueueLock(function () use ($cmd, $who) {
            $seqKey = 'cmdseq::' . $this->getId();
            $seq    = ((int) config::byKey($seqKey, 'glowscreen32', 0)) + 1;
            config::save($seqKey, $seq, 'glowscreen32');

            $queue = $this->liveQueue();
            while (count($queue) >= glowscreen32::CMD_QUEUE_MAX) {
                $dropped = array_shift($queue);
                if (!empty($dropped['secret'])) {
                    self::takeSecret($this->getId(), $dropped['cmd']['seq']);
                }
                log::add('glowscreen32', 'warning', sprintf(
                    __('%1$s : file des commandes à distance pleine (%2$s) — la plus ancienne, « %3$s » (seq %4$s), est abandonnée.', __FILE__),
                    $who, glowscreen32::CMD_QUEUE_MAX, $dropped['cmd']['do'], $dropped['cmd']['seq']));
            }
            $entry = array(
                'cmd'     => array_merge(array('seq' => $seq), $cmd),
                'expires' => time() + glowscreen32::CMD_TTL,
            );
            if ($cmd['do'] === 'wifi') {
                /* Le mot de passe quitte la file : fichier 0600. */
                self::writeSecret($this->getId(), $seq, $cmd['pass']);
                $entry['cmd']['pass'] = null;
                $entry['secret'] = true;
            }
            $queue[] = $entry;
            cache::set($this->queueKey(), $queue, glowscreen32::CMD_TTL + 60);
            return $entry;
        });
        /* « rev » change avec la tête de file : un ping retenu la livre dans
         * la seconde. */
        self::wakeScreen($this->getId());
        log::add('glowscreen32', 'info', sprintf(
            __('%1$s : commande à distance « %2$s » mise en file (seq %3$s).', __FILE__),
            $who, $cmd['do'], $entry['cmd']['seq']));
        return $entry['cmd'];
    }

    /*
     * Retire et rend la commande en tête de file, ou null. « Au plus une
     * fois » : elle est retirée AVANT de partir. Une réponse perdue en route
     * perd la commande ; un « reboot » rejoué faute d'acquittement serait une
     * boucle de redémarrages.
     */
    public function dequeueCommand() {
        if ($this->getId() == '') {
            return null;
        }
        $key = $this->queueKey();
        $raw = cache::byKey($key)->getValue(array());
        if (!is_array($raw) || count($raw) == 0) {
            return null;
        }
        $entry = $this->withQueueLock(function () use ($key) {
            $queue = $this->liveQueue();
            if (count($queue) == 0) {
                cache::delete($key);
                return null;
            }
            $entry = array_shift($queue);
            if (count($queue) == 0) {
                cache::delete($key);
            } else {
                cache::set($key, $queue, glowscreen32::CMD_TTL + 60);
            }
            return $entry;
        });
        if ($entry === null) {
            return null;
        }
        /*
         * v3.2 (contrat v3.1) : la commande « page » est mise en file avec la
         * POSITION DE CONFIGURATION ; la carte attend le numéro SERVI. Traduit
         * au moment de la livraison — une page masquée entre-temps rend la
         * commande sans effet, journalisé.
         */
        if ($entry['cmd']['do'] === 'page') {
            $map = $this->pageMap();
            $position = (int) $entry['cmd']['page'];
            if (!isset($map[$position])) {
                log::add('glowscreen32', 'warning', sprintf(
                    __('%1$s : commande « page » (seq %2$s) vers la page %3$s, masquée — sans effet, elle n\'est pas livrée.', __FILE__),
                    $this->getHumanName(), $entry['cmd']['seq'], $position + 1));
                return null;
            }
            $entry['cmd']['page'] = $map[$position];
        }
        if (!empty($entry['secret'])) {
            $secret = self::takeSecret($this->getId(), $entry['cmd']['seq']);
            if ($secret === null) {
                log::add('glowscreen32', 'warning', sprintf(
                    __('%1$s : mot de passe Wi-Fi de la commande seq %2$s introuvable — la commande n\'est pas livrée.', __FILE__),
                    $this->getHumanName(), $entry['cmd']['seq']));
                return null;
            }
            $entry['cmd']['pass'] = $secret;
        }
        log::add('glowscreen32', 'info', sprintf(
            __('%1$s : commande à distance « %2$s » (seq %3$s) livrée à la carte.', __FILE__),
            $this->getHumanName(), $entry['cmd']['do'], $entry['cmd']['seq']));
        return $entry['cmd'];
    }

    /* ================================================== LISTENER — v2.2 */

    /*
     * Le listener de cet écran : les commandes d'état de ses boutons, et la
     * commande de son bandeau. Chaque événement sur l'une d'elles réveille un
     * ping retenu (pullChange). « background » à false : l'appel est
     * synchrone dans le processus qui émet l'événement — il ne coûte qu'une
     * écriture de fichier, là où un processus PHP lancé à chaque changement
     * d'état serait autrement plus cher.
     */
    public function updateListener() {
        if ($this->getId() == '') {
            return;
        }
        $events = array();
        if ($this->getIsEnable() == 1) {
            foreach ($this->buttons() as $button) {
                $state = self::buttonStateCmd($button);
                if ($state !== null) {
                    $events[$state->getId()] = true;
                }
                /* v3.0 : la commande d'une tuile « view » — « rev » couvre
                 * « values », une porte qui s'ouvre libère le ping retenu. */
                $view = self::viewCmd($button);
                if ($view !== null) {
                    $events[$view->getId()] = true;
                }
            }
            $reference = trim((string) $this->getConfiguration('info_cmd', ''));
            if ($reference !== '') {
                try {
                    $info = cmd::byString($reference);
                } catch (Throwable $e) {
                    $info = null;
                }
                if (is_object($info) && $info->getType() == 'info') {
                    $events[$info->getId()] = true;
                }
            }
        }

        $listener = null;
        foreach (listener::byClass('glowscreen32') as $candidate) {
            if ($candidate->getFunction() != 'pullChange'
                || (int) $candidate->getOption('eqLogic_id', 0) !== (int) $this->getId()) {
                continue;
            }
            if ($listener === null) {
                $listener = $candidate;
            } else {
                $candidate->remove();
            }
        }

        if (count($events) == 0) {
            if ($listener !== null) {
                $listener->remove();
            }
            return;
        }
        if ($listener === null) {
            $listener = new listener();
            $listener->setClass('glowscreen32');
            $listener->setFunction('pullChange');
            $listener->setOption(array('eqLogic_id' => (int) $this->getId(), 'background' => false));
        }
        $listener->emptyEvent();
        foreach (array_keys($events) as $cmdId) {
            $listener->addEvent($cmdId);
        }
        $listener->save();
    }

    public function removeListener() {
        foreach (listener::byClass('glowscreen32') as $candidate) {
            if ((int) $candidate->getOption('eqLogic_id', 0) === (int) $this->getId()) {
                $candidate->remove();
            }
        }
    }

    /* Appelé par le coeur à chaque changement d'une commande écoutée. */
    public static function pullChange($_options) {
        try {
            if (is_array($_options) && isset($_options['eqLogic_id'])) {
                self::wakeScreen($_options['eqLogic_id']);
            }
        } catch (Throwable $e) {
            log::add('glowscreen32', 'debug', 'pullChange : ' . $e->getMessage());
        }
    }

    /* ================================================================ APPUI */

    /*
     * Joue le bouton de ce RANG, et rend ce que le contrat v1.3 attend :
     * l'identifiant reçu, l'état ATTENDU après exécution, et « pending ».
     *
     * Le rang est cherché dans la mise en page de CET écran-là, jamais dans
     * toute la base : la clé API est la même pour tout le parc, et un rang qui
     * ne désigne rien sur cet écran vaut « unknown_button » (null ici).
     *
     * C'est le changement structurant de la v1.3 : jusqu'en v1.2 la carte
     * envoyait un identifiant de commande Jeedom, c'est-à-dire qu'elle décidait
     * de ce qui devait s'exécuter. Elle envoie désormais un rang opaque, et
     * c'est le plugin qui en déduit la commande — y compris son SENS, pour un
     * interrupteur.
     */
    public function press($_rank, $_schema = self::SCHEMA_LEGACY) {
        if (!is_numeric($_rank)) {
            return null;
        }
        $rank = (int) $_rank;

        /*
         * ⚠ LE POINT DÉLICAT DU SCHÉMA 2.
         *
         * Le rang est résolu DANS LE MÊME APLATISSEMENT que celui qui a servi
         * le « layout » à cette carte-là. Une carte de schéma 1 a reçu six
         * boutons renumérotés 0 à 5, sans les « nav » ; son rang 2 désigne donc
         * le troisième bouton de CETTE liste, et pas forcément le bouton d'id
         * global 2. Les résoudre dans la mauvaise liste ferait jouer le bouton
         * d'à côté — sur ce projet, « le bouton d'à côté » a déjà ouvert un
         * portail.
         *
         * Le schéma vient de l'en-tête que la carte envoie, exactement comme
         * pour « layout » : c'est la même négociation, sur la même requête, et
         * il n'y a donc rien de nouveau à implémenter côté firmware — il suffit
         * qu'il envoie l'en-tête sur TOUS ses appels, ce que le contrat prévoit.
         *
         * Le défaut est le schéma 1, et c'est volontaire : une carte v1.4, qui
         * n'envoie aucun en-tête, doit continuer d'être comprise. Le risque est
         * du bon côté — une carte v2 qui oublierait l'en-tête verrait ses six
         * premiers boutons répondre juste et les autres rendre unknown_button,
         * plutôt que de déclencher silencieusement la mauvaise commande.
         */
        $buttons = $this->buttonsFor($_schema);

        if ($rank < 0 || !isset($buttons[$rank])) {
            return null;
        }
        $button = $buttons[$rank];

        /* v3.0 (revue) : un bouton non résolu garde son rang mais ne joue
         * RIEN — unknown_button, journalisé. */
        if (!empty($button['_inert'])) {
            log::add('glowscreen32', 'warning', sprintf(
                __('%1$s : appui reçu sur le bouton %2$s, dont la commande ne se résout plus. Rien n\'est joué.', __FILE__),
                $this->getHumanName(), $rank));
            return null;
        }

        /*
         * v3.1 — GARDE-FOU : le bouton à ce rang est-il bien celui que la carte
         * a dessiné ? On compare sa signature à celle du dernier layout SERVI à
         * cet écran dans CE schéma. Si la configuration a changé depuis (la
         * carte n'a pas encore rechargé sa mise en page), l'appui est refusé
         * plutôt que de jouer un autre bouton que celui qu'on a touché.
         */
        $served = cache::byKey($this->servedKey($_schema))->getValue(null);
        if (is_array($served) && (!isset($served[$rank]) || $served[$rank] !== self::buttonFingerprint($button))) {
            log::add('glowscreen32', 'warning', sprintf(
                __('%1$s : appui reçu sur le rang %2$s, mais ce bouton a changé depuis la dernière mise en page servie à la carte (schéma %3$s). Rien n\'est joué : la carte doit recharger sa mise en page.', __FILE__),
                $this->getHumanName(), $rank, self::normalizeSchema($_schema)));
            return null;
        }

        /*
         * Un « press » sur un bouton « nav » : la navigation est locale à la
         * carte, elle n'émet jamais d'appui. En recevoir un signale un firmware
         * en désaccord avec la configuration qu'il a reçue — contrat v2.0,
         * unknown_button et une ligne de journal. Le cas ne peut pas se
         * produire en schéma 1, où les « nav » sont exclus de l'aplatissement.
         */
        if ($button['mode'] === self::MODE_VIEW) {
            /* v3.0 : une tuile « view » ne déclenche RIEN. Un press sur son id
             * signale un firmware en désaccord avec la mise en page. */
            log::add('glowscreen32', 'warning', sprintf(
                __('%1$s : appui reçu sur la tuile %2$s, qui est une tuile « valeur » — elle ne commande rien. Rien n\'est joué.', __FILE__),
                $this->getHumanName(), $rank));
            return null;
        }
        if ($button['mode'] === self::MODE_NAV) {
            log::add('glowscreen32', 'warning', sprintf(
                __('%1$s : appui reçu sur le bouton %2$s, qui est un bouton de navigation — la carte exécute une mise en page différente de celle qui est configurée. Rien n\'est joué.', __FILE__),
                $this->getHumanName(), $rank));
            return null;
        }

        /* Lu AVANT l'exécution : c'est cette valeur qui décide du sens en mode
         * interrupteur, et c'est d'elle que se déduit l'état attendu. */
        $before = self::buttonState($button);

        if ($button['mode'] === self::MODE_TOGGLE) {
            list($cmd, $expected, $what) = $this->pressToggle($button, $before, $rank);
            if ($cmd === null) {
                return null;
            }
        } else {
            $scenario = self::buttonScenario($button);
            if ($scenario !== null) {
                return $this->pressScenario($button, $scenario, $rank, $before);
            }
            $cmd = self::buttonCmd($button);
            if ($cmd === null) {
                return null;
            }
            /* En mode action le plugin n'a rien à prédire : il ne sait pas ce
             * que la commande va faire de l'état, et souvent elle n'en a pas.
             * Il rend donc ce qu'il LIT après coup. */
            $expected = null;
            $what     = $cmd->getName();
            $cmd->execCmd();
        }

        $label = ($button['label'] !== '') ? $button['label'] : $what;
        $this->noteContact($label);
        log::add('glowscreen32', 'info', sprintf(
            __('%1$s : appui sur le bouton %2$s « %3$s » → %4$s.', __FILE__),
            $this->getHumanName(), $rank, $label, $what
        ));

        return self::pressResult($rank, $button, $expected);
    }

    /*
     * Le sens d'un appui sur un interrupteur : quelle commande, et quel état en
     * attendre.
     *
     * Le choix explicite d'après l'état est le comportement par défaut, et non
     * la commande « Basculer » de l'équipement quand elle existe : une commande
     * « Basculer » désynchronisée — un relais actionné à la main pendant que
     * Jeedom ne regardait pas — inverse l'état que l'utilisateur voit sur
     * l'écran, et l'écart ne se rattrape jamais. « Allumer » quand c'est éteint
     * converge, lui, quoi qu'il se soit passé entre-temps.
     *
     * La commande « Basculer » sert donc de secours, dans deux cas seulement :
     * l'état est illisible, ou l'utilisateur n'a rempli qu'une des deux
     * commandes.
     */
    private function pressToggle($_button, $_before, $_rank) {
        if ($_before === 1) {
            $cmd = self::actionCmd($_button, 'off');
            if ($cmd !== null) {
                $cmd->execCmd();
                return array($cmd, 0, $cmd->getName());
            }
        } elseif ($_before === 0) {
            $cmd = self::actionCmd($_button, 'on');
            if ($cmd !== null) {
                $cmd->execCmd();
                return array($cmd, 1, $cmd->getName());
            }
        }

        $cmd = self::actionCmd($_button, 'toggle');
        if ($cmd !== null) {
            if ($_before === null) {
                log::add('glowscreen32', 'warning', sprintf(
                    __('%1$s : état du bouton %2$s illisible, la commande « Basculer » est jouée à l\'aveugle.', __FILE__),
                    $this->getHumanName(), $_rank
                ));
            }
            $cmd->execCmd();
            /* Sans état lu, il n'y a rien à prédire : le contrat prévoit null,
             * et le « ping » suivant dira la vérité. */
            $expected = ($_before === null) ? null : (($_before === 1) ? 0 : 1);
            return array($cmd, $expected, $cmd->getName());
        }

        /* Dernier recours : l'état est illisible, ou l'utilisateur n'a rempli
         * qu'une des deux commandes, et l'équipement n'a pas de « Basculer ».
         * Allumer est le moindre mal — un appui sur un bouton qu'on croit
         * éteint veut dire « allume », et l'appui suivant éteindra puisque
         * l'état sera alors connu. */
        foreach (array('on' => 1, 'off' => 0) as $slot => $expected) {
            $cmd = self::actionCmd($_button, $slot);
            if ($cmd === null) {
                continue;
            }
            log::add('glowscreen32', 'warning', sprintf(
                __('%1$s : le bouton %2$s ne peut pas choisir son sens (état illisible ou commande manquante), « %3$s » est jouée.', __FILE__),
                $this->getHumanName(), $_rank, $cmd->getName()
            ));
            $cmd->execCmd();
            return array($cmd, $expected, $cmd->getName());
        }

        return array(null, null, '');
    }

    /* Un bouton-scénario : le contrat ne lui connaît pas d'état, mais
     * l'utilisateur a pu en désigner un — une commande d'information qui dit si
     * le mode cinéma est en cours, par exemple. */
    private function pressScenario($_button, $_scenario, $_rank, $_before) {
        /* Les mêmes étiquettes que le coeur pose lui-même quand un scénario en
         * démarre un autre : elles s'affichent dans le journal du scénario,
         * seul endroit où l'on pourra voir qu'il a été lancé depuis un écran et
         * non à la main. */
        $_scenario->addTag('trigger', 'glowscreen32');
        $_scenario->addTag('trigger_message', __('Lancé depuis l\'écran', __FILE__) . ' ' . $this->getHumanName());
        /* launch() rend false sur un scénario désactivé, sans rien dire à
         * personne : l'appui resterait sans effet et sans trace. */
        if ($_scenario->launch() === false) {
            log::add('glowscreen32', 'warning', sprintf(
                __('%1$s : le scénario « %2$s » n\'a pas démarré (scénario désactivé ?).', __FILE__),
                $this->getHumanName(), $_scenario->getName()
            ));
        }
        $label = ($_button['label'] !== '') ? $_button['label'] : $_scenario->getName();
        $this->noteContact($label);
        log::add('glowscreen32', 'info', sprintf(
            __('%1$s : appui sur le bouton %2$s « %3$s » → %4$s.', __FILE__),
            $this->getHumanName(), $_rank, $label, $_scenario->getName()
        ));
        return self::pressResult($_rank, $_button, null);
    }

    /*
     * La réponse de « press », contrat v1.3.
     *
     * « state » est l'état ATTENDU, et « pending » dit qu'il n'est pas confirmé.
     * Sur du matériel réel, l'état remonte après un aller-retour avec
     * l'équipement : au moment où l'on répond, le relais vient de basculer mais
     * Jeedom n'a pas encore reçu la nouvelle valeur. Le firmware s'en sert pour
     * un retour visuel optimiste immédiat ; la source de vérité reste le
     * tableau « states » du ping suivant.
     *
     * La règle est unique pour les deux modes : on relit l'état, et « pending »
     * vaut vrai tant que ce qu'on rend n'est pas ce qu'on lit. En mode action
     * il n'y a pas de prédiction — l'état rendu EST l'état lu — donc « pending »
     * y vaut toujours faux, et faux aussi pour un bouton sans état du tout.
     */
    public static function pressResult($_rank, $_button, $_expected) {
        $after = self::buttonState($_button);
        $state = ($_expected === null) ? $after : $_expected;
        return array(
            'ok'      => true,
            'id'      => $_rank,
            'state'   => $state,
            'pending' => ($state !== null && $state !== $after),
        );
    }

    /* ==================================================== ÉTAT DE L'ÉQUIPEMENT */

    /* Le compteur que la carte surveille. Jamais nul : une carte qui a mis en
     * cache la version 0 et relit 0 ne redessinerait jamais. */
    public function version() {
        $version = (int) $this->getConfiguration('version', 0);
        return ($version < 1) ? 1 : $version;
    }

    /* L'intervalle conseillé, borné. Une valeur aberrante venue d'une
     * restauration ferait interroger Jeedom dix fois par seconde. */
    public function poll() {
        $poll = (int) $this->getConfiguration('poll', self::DEFAULT_POLL);
        /* Contrat : hors bornes → 30, PAS la borne (v3.0, revue — un 4000
         * rendait 3600). */
        if ($poll < self::MIN_POLL || $poll > self::MAX_POLL) {
            return self::DEFAULT_POLL;
        }
        return $poll;
    }

    /*
     * Ce qui, dans la configuration, oblige la carte à redessiner. Le nom en
     * fait partie : il est affiché en haut de l'écran.
     *
     * Le calcul porte sur la mise en page stockée et non sur celle que layout()
     * produit : résoudre chaque commande à l'enregistrement coûterait autant de
     * requêtes qu'il y a de boutons, et l'état d'une lampe changerait la
     * signature sans qu'aucune configuration ait bougé.
     *
     * Seuls les champs qui COMPTENT POUR LE MODE du bouton entrent dans le
     * calcul : une commande « Éteindre » choisie puis le bouton repassé en mode
     * action ne doit pas faire redessiner tous les écrans de la maison pour un
     * champ que plus personne ne lit.
     */
    /*
     * ⚠ LE PIÈGE. Cette méthode et buttonSignature() énumèrent leurs champs EN
     * DUR. Tout champ de configuration qui change ce que la carte DESSINE doit
     * donc y être ajouté explicitement, sans quoi « version » ne bouge pas —
     * et aucun écran du parc ne se redessine après modification. Le symptôme
     * est désolant : l'enregistrement réussit, le journal dit « configuration
     * enregistrée », la page montre la nouvelle mise en page, et le mur affiche
     * l'ancienne indéfiniment.
     *
     * Ajoutés en v2.0 : les pages (titre et parent), la grille, le balayage,
     * l'horloge et la commande du bandeau — plus, au niveau du bouton, sa page,
     * sa case et la page que vise un bouton « nav ».
     *
     * Ce qui n'y est PAS, et volontairement : le verrou OTA (il ne change rien
     * à ce qui est affiché) et tout ce qui relève de l'ÉTAT des équipements —
     * une lampe allumée ferait changer la signature sans qu'aucune
     * configuration ait bougé, et « states » du ping est là pour ça.
     */
    public function layoutSignature() {
        $buttons = array();
        foreach ($this->buttons() as $button) {
            $buttons[] = self::buttonSignature($button);
        }
        return md5(json_encode(array(
            'name'    => $this->getName(),
            'poll'    => $this->poll(),
            'buttons' => $buttons,
            /* --- contrat v2.0 --- */
            /* Titre et parent seulement : l'indicateur de visibilité ajouté en
             * v3.2 entre à part, et SEULEMENT s'il masque quelque chose — la
             * signature d'un écran existant ne bouge pas. */
            'pages'   => array_map(function ($_p) { return array('title' => $_p['title'], 'parent' => $_p['parent']); }, $this->pages()),
            'grid'    => $this->grid(),
            'swipe'   => $this->swipe(),
            'clock'   => $this->clock(),
            /* La RÉFÉRENCE de la commande du bandeau, pas sa valeur : la
             * température change toutes les minutes, et faire redessiner
             * l'écran à chaque degré serait absurde — « info » voyage dans le
             * ping, comme « states ». */
            'info'    => trim((string) $this->getConfiguration('info_cmd', '')),
            /* Le SEUIL, pas l'âge : le seuil est de la configuration, l'âge est
             * de l'état. Le premier doit faire redessiner, le second jamais. */
            'infoage' => $this->infoMaxAge(),
        ) + ((count($this->pageMap()) < self::MAX_PAGES || !$this->pageVisible(0))
            ? array('hidden' => array_values(array_diff(range(0, self::MAX_PAGES - 1), array_keys($this->pageMap()))))
            : array()) + ($this->readOnly()
            /* v3.0 — ajoutée SEULEMENT quand elle est vraie : la signature
             * d'un écran existant ne bouge pas, et le parc ne se redessine pas
             * à la mise à jour du plugin. */
            ? array('readonly' => 1) : array())));
    }

    public static function buttonSignature($_button) {
        $signature = array(
            /* L'icône dans sa forme v1 : une configuration dont l'icône était
             * vide garde ainsi exactement la signature qu'elle avait, et ne
             * fait donc pas redessiner un parc qui afficherait la même chose. */
            $_button['label'], $_button['color'], self::legacyIcon($_button['icon']),
            $_button['mode'], $_button['state'],
            /* Contrat v2.0 : déplacer un bouton d'une page ou d'une case à
             * l'autre est un changement de mise en page comme un autre. */
            $_button['page'], $_button['slot'],
        );
        if ($_button['mode'] === self::MODE_NAV) {
            $signature[] = $_button['nav'];
        } elseif ($_button['mode'] === self::MODE_VIEW) {
            /* v3.0 : la commande montrée et sa mise en forme. */
            $signature[] = $_button['view'];
            $signature[] = $_button['fmt'];
        } elseif ($_button['mode'] === self::MODE_TOGGLE) {
            $signature[] = $_button['on'];
            $signature[] = $_button['off'];
            $signature[] = $_button['toggle'];
        } else {
            $signature[] = $_button['target'];
            $signature[] = $_button['cmd'];
            $signature[] = $_button['scenario'];
        }
        return $signature;
    }

    /*
     * L'horodatage du dernier appel reçu de la carte, ou une chaîne vide.
     *
     * ⚠ CONTRAT v2.2 : la source est la COMMANDE D'INFORMATION « Dernier
     * contact », et non plus la configuration. L'API n'enregistre plus jamais
     * l'eqLogic : écrire la date dans la configuration supposait un save() à
     * chaque contact, qui écrasait toute modification enregistrée par
     * l'utilisateur pendant la requête — systématiquement, avec un ping retenu
     * 25 s.
     *
     * La clé de configuration n'est plus écrite, mais reste lue : c'est là
     * qu'une v2.1 rangeait la date. La plus RÉCENTE des deux fait foi, si bien
     * qu'un écran silencieux depuis la mise à jour garde sa vraie date.
     */
    public function lastContact() {
        $cmd     = $this->getCmd('info', 'lastcontact');
        $fromCmd = is_object($cmd) ? trim((string) $cmd->execCmd()) : '';
        $legacy  = trim((string) $this->getConfiguration('lastcontact', ''));
        if ($fromCmd === '') {
            return $legacy;
        }
        if ($legacy === '') {
            return $fromCmd;
        }
        return (strtotime($legacy) > strtotime($fromCmd)) ? $legacy : $fromCmd;
    }

    /* L'âge du dernier contact en secondes, ou null si l'écran n'a jamais
     * appelé. */
    public function contactAge() {
        $stamp = $this->lastContact();
        if ($stamp === '') {
            return null;
        }
        $time = strtotime($stamp);
        return ($time === false) ? null : max(0, time() - $time);
    }

    /*
     * L'écran a-t-il donné signe de vie récemment ?
     *
     * Trois intervalles de rafraîchissement : un ping perdu et un autre en
     * retard ne doivent pas faire clignoter « hors ligne » sur une installation
     * parfaitement saine. La granularité d'horodatage est comptée en plus, sans
     * quoi un écran qui répond parfaitement paraîtrait en retard d'une minute.
     *
     * ⚠ L'intervalle compté est le MAXIMUM que le contrat autorise, soit
     * IDLE_POLL_FACTOR x poll, et non « poll » — contrat v2.1.
     *
     * Depuis la v2.1 la carte espace ses pings quand son écran est atténué.
     * Compter sur « poll » seul ferait passer TOUT LE PARC « hors ligne »
     * chaque nuit, alors que chaque carte fonctionne parfaitement : une alerte
     * qui se déclenche toutes les nuits sans raison est une alerte à laquelle
     * on cesse de croire, et elle se tairait le jour où un écran meurt pour de
     * bon.
     *
     * Le prix est assumé : au réglage par défaut (poll = 30 s), un écran
     * réellement mort est signalé au bout de 3 x 2 x 30 + 60 = 4 minutes au
     * lieu de 2 min 30. Sur un panneau mural, la différence ne coûte rien ; la
     * fausse alerte nocturne, si.
     */
    public function isOnline() {
        $age = $this->contactAge();
        if ($age === null) {
            return false;
        }
        return $age <= (3 * self::IDLE_POLL_FACTOR * $this->poll()) + self::CONTACT_GRANULARITY;
    }

    /* Le dernier contact tel qu'on le lit : la date, et depuis combien de
     * temps. « 22/09/2026 11:03:05 (il y a 2 min) » se comprend d'un coup
     * d'oeil, là où une date seule oblige à regarder l'heure qu'il est. */
    public static function humanContact($_stamp) {
        $stamp = trim((string) $_stamp);
        if ($stamp === '') {
            return '';
        }
        $time = strtotime($stamp);
        if ($time === false) {
            return $stamp;
        }
        $age = max(0, time() - $time);
        if ($age < 60) {
            $ago = sprintf(__('il y a %s s', __FILE__), $age);
        } elseif ($age < 3600) {
            $ago = sprintf(__('il y a %s min', __FILE__), (int) floor($age / 60));
        } elseif ($age < 86400) {
            $ago = sprintf(__('il y a %s h', __FILE__), (int) floor($age / 3600));
        } else {
            $ago = sprintf(__('il y a %s j', __FILE__), (int) floor($age / 86400));
        }
        return date('d/m/Y H:i:s', $time) . ' (' . $ago . ')';
    }

    /*
     * Horodate le dernier échange avec la carte, et retient ce qu'elle a fait.
     *
     * ⚠ CONTRAT v2.2 — « le serveur n'enregistre JAMAIS l'eqLogic depuis
     * l'API ». Jusqu'en v2.1 la date allait aussi dans la configuration, par
     * save(true) : l'eqLogic entier, tel que chargé AU DÉBUT de la requête,
     * était réécrit. Une configuration enregistrée par l'utilisateur pendant
     * ce temps était écrasée sans un mot — fenêtre étroite avec une requête
     * courte, systématique avec un ping retenu 25 s. Seules des commandes
     * d'information sont désormais écrites ; elles vivent dans leur propre
     * table et dans le cache, et ne touchent pas à la configuration.
     *
     * À la MINUTE, et non à chaque appel : chaque nouvelle date est un
     * événement Jeedom. Un appui, lui, est rare et intéressant : il est
     * toujours noté.
     */
    /*
     * Ce que l'API a déjà noté pour cet écran — v3.1 : un petit état en cache,
     * lu UNE fois par requête. Il évite de recharger les commandes
     * d'information (une requête SQL chacune) pour constater qu'il n'y a rien
     * de neuf à écrire — le cas de presque tous les pings.
     */
    private $_seen = null;

    private function seen() {
        if ($this->_seen === null) {
            $value = cache::byKey('glowscreen32::seen::' . $this->getId())->getValue(array());
            $this->_seen = is_array($value) ? $value : array();
        }
        return $this->_seen;
    }

    private function seenSet($_values) {
        $this->_seen = array_merge($this->seen(), $_values);
        cache::set('glowscreen32::seen::' . $this->getId(), $this->_seen);
    }

    /* Une diagnostic « lent » (up, heap, blk) n'est noté qu'au plus toutes les
     * cinq minutes, ou sur variation significative : chaque écriture est un
     * événement Jeedom et, pour heap/blk, un point d'historique. */
    const DIAG_INTERVAL = 300;

    public function noteContact($_press = null) {
        $now   = time();
        $stamp = date('Y-m-d H:i:s', $now);
        $seen  = $this->seen();
        if ($_press === null && isset($seen['contact']) && ($now - (int) $seen['contact']) < self::CONTACT_GRANULARITY) {
            /* Noté il y a moins d'une minute : rien à écrire, et le cron ne
             * pose 0 qu'après plusieurs minutes de silence. Aucune requête. */
            return false;
        }
        $age   = $this->contactAge();

        if ($_press === null && $age !== null && $age < self::CONTACT_GRANULARITY) {
            /* Pas de nouvelle date, mais la présence est confirmée : le cron a
             * pu poser 0 entre-temps (contact noté à l'ARRIVÉE d'une requête
             * retenue, contrat v2.2). */
            $this->noteOnline(true);
            return false;
        }

        $this->checkAndUpdateCmd('lastcontact', $stamp);
        $this->checkAndUpdateCmd('version', $this->version());
        if ($_press !== null) {
            $this->checkAndUpdateCmd('lastpress', $_press);
        }
        /* Une carte qui parle est en ligne, par définition. Le retour à zéro,
         * lui, ne peut venir que du cron : personne n'est là pour le dire. */
        $this->noteOnline(true);
        $this->seenSet(array('contact' => $now));
        return true;
    }

    /*
     * ======================================================= PRÉSENCE
     *
     * « L'écran du couloir ne répond plus » est la première question qu'on se
     * pose sur un parc, et jusqu'ici elle n'avait de réponse QUE dans le
     * tableau de la page du plugin : isOnline() alimentait un pictogramme, et
     * rien d'autre. Aucun scénario, aucune notification, aucun widget de
     * dashboard ne pouvait réagir à un panneau mural devenu noir.
     *
     * D'où une commande d'information binaire ordinaire. La présence devient
     * alors un fait Jeedom comme un autre : historisable, affichable, et
     * utilisable dans un déclencheur.
     *
     * Écrit à deux endroits, et il en faut deux :
     *   - noteContact() la met à 1 — une carte qui parle est en ligne ;
     *   - cron() la remet à 0 — un silence ne se signale pas tout seul.
     */
    public function noteOnline($_online) {
        $online = $_online ? 1 : 0;

        $cmd = $this->getCmd(null, 'online');
        if (!is_object($cmd)) {
            /* Équipement créé avant la v2.1 et jamais réenregistré depuis. La
             * mise à jour du plugin les rattrape (glowscreen32_update), mais on
             * ne casse pas un ping pour autant. */
            return false;
        }

        /* L'état connu vient de la commande elle-même : pas de champ de
         * configuration à tenir en parallèle, donc rien qui puisse diverger. */
        $before = $cmd->execCmd();
        $known  = ($before === null || $before === '') ? null : ((((int) $before) === 1) ? 1 : 0);

        $this->checkAndUpdateCmd('online', $online);

        if ($known === $online) {
            return false;
        }

        $age = $this->contactAge();
        if ($online === 0 && $age === null) {
            /* Un écran déclaré dans Jeedom mais qui n'a JAMAIS appelé est en
             * cours d'enrôlement, pas en panne. La valeur est posée — il n'est
             * effectivement pas en ligne — mais sans la ligne alarmante : la
             * carte affiche sa MAC et attend qu'on la déclare, c'est le
             * fonctionnement prévu. */
            return true;
        }

        /* Aux TRANSITIONS seulement — même règle que partout ailleurs ici : un
         * cron qui écrirait une ligne par minute et par écran rendrait le
         * journal inutilisable. */
        log::add('glowscreen32', ($online === 1) ? 'info' : 'warning', sprintf(
            ($online === 1)
                ? __('%1$s : l\'écran répond de nouveau.', __FILE__)
                : __('%1$s : plus aucun appel depuis %2$s — l\'écran est déclaré hors ligne. Vérifiez son alimentation et sa liaison Wi-Fi.', __FILE__),
            $this->getHumanName(), self::humanDuration((int) $age)));
        return true;
    }

    /*
     * Appelé CHAQUE MINUTE par le coeur (cron statique sur la classe du
     * plugin). Il ne fait qu'une chose : constater les silences.
     *
     * Pourquoi un cron plutôt qu'un calcul à la lecture : un écran hors ligne
     * est justement celui qui n'appelle plus. Aucun chemin de code ne s'exécute
     * plus pour lui — il n'y a donc personne pour poser le zéro, et une valeur
     * qui ne bouge plus resterait à 1 indéfiniment.
     *
     * Le coût est nul à l'échelle du parc : une lecture d'horodatage par écran,
     * et une écriture seulement quand l'état change.
     */
    public static function cron() {
        /* Les activés seulement : un écran désactivé reçoit déjà
         * « unknown_device », il n'y a rien à surveiller. */
        foreach (eqLogic::byType('glowscreen32', true) as $eqLogic) {
            if (!$eqLogic->isOnline()) {
                $eqLogic->noteOnline(false);
            }
        }
        /* v3.1 : aucun mot de passe Wi-Fi ne survit à sa commande. */
        self::purgeSecrets();
    }

    /*
     * Retient le niveau Wi-Fi annoncé par la carte — contrat v2.1, paramètre
     * optionnel de « ping ».
     *
     * Une valeur absente, non numérique ou hors bornes est IGNORÉE SANS
     * ERREUR : le ping reste un ping, et une carte ne doit jamais voir sa
     * liaison refusée parce qu'un diagnostic est mal formé.
     *
     * Aucune écriture de configuration ici, et c'est voulu : contrairement à
     * « fw », le RSSI change à chaque ping par nature. Il va dans une commande
     * d'information, dont c'est exactement le rôle — checkAndUpdateCmd()
     * n'émet un événement que si la valeur a bougé, et l'historisation, si
     * l'utilisateur l'active, est celle du coeur.
     */
    public function noteRssi($_rssi) {
        $raw = trim((string) $_rssi);
        if ($raw === '' || !is_numeric($raw)) {
            return false;
        }
        $rssi = (int) $raw;
        if ($rssi < self::RSSI_MIN || $rssi > self::RSSI_MAX) {
            /* Debug et non warning : c'est un diagnostic, il arrive à chaque
             * ping, et une carte qui déraille sur ce point ne doit pas noyer le
             * journal. */
            log::add('glowscreen32', 'debug', sprintf(
                __('%1$s : niveau Wi-Fi hors bornes (%2$s dBm), ignoré.', __FILE__),
                $this->getHumanName(), $rssi));
            return false;
        }
        $seen = $this->seen();
        if (isset($seen['rssi']) && (int) $seen['rssi'] === $rssi) {
            return false;
        }
        /* Par ping, mais seulement s'il a CHANGÉ ; l'historique du coeur le
         * lisse (historizeMode « avg » par défaut). */
        $this->checkAndUpdateCmd('rssi', $rssi);
        $this->seenSet(array('rssi' => $rssi));
        return true;
    }

    /* Les causes de redémarrage du contrat v2.2 — vocabulaire fermé. */
    const RESET_REASONS = array('poweron', 'sw', 'panic', 'wdt', 'brownout', 'ext', 'other');

    /* Plafond de « heap » et « blk » : la carte n'a que 520 Kio de SRAM, et
     * pas de PSRAM. */
    const HEAP_MAX = 400000;

    /*
     * Les diagnostics optionnels du « ping » — contrat v2.2 : up, rst, heap,
     * blk, ip, ssid.
     *
     * Même règle que « rssi » : tout ce qui est absent, mal formé ou hors
     * bornes est IGNORÉ SANS ERREUR. Un diagnostic ne coûte jamais sa liaison
     * à un écran. Chaque valeur va dans une commande d'information — jamais
     * dans la configuration : l'API n'enregistre pas l'eqLogic.
     */
    public function noteDiagnostics($_params) {
        $params = is_array($_params) ? $_params : array();
        $get = function ($_key) use ($params) {
            return isset($params[$_key]) ? trim((string) $params[$_key]) : '';
        };
        $noted = 0;
        $now   = time();
        $seen  = $this->seen();
        $set   = array();
        $due   = function ($_key) use ($seen, $now) {
            return !isset($seen[$_key . 'T']) || ($now - (int) $seen[$_key . 'T']) >= glowscreen32::DIAG_INTERVAL;
        };

        $up = $get('up');
        if ($up !== '' && ctype_digit($up) && strlen($up) <= 10 && (float) $up <= 4294967295) {
            /* Toutes les 5 min, ou tout de suite si la carte a REDÉMARRÉ
             * (durée qui recule) : c'est l'information qui compte. */
            if ($due('up') || !isset($seen['up']) || (int) $up < (int) $seen['up']) {
                $this->checkAndUpdateCmd('uptime', (int) $up);
                $set['up'] = (int) $up;
                $set['upT'] = $now;
                $noted++;
            }
        }
        $rst = strtolower($get('rst'));
        if ($rst !== '' && in_array($rst, self::RESET_REASONS, true) && (!isset($seen['rst']) || $seen['rst'] !== $rst)) {
            $this->checkAndUpdateCmd('resetreason', $rst);
            $set['rst'] = $rst;
            $noted++;
        }
        foreach (array('heap' => 'heap', 'blk' => 'maxblock') as $param => $logicalId) {
            $value = $get($param);
            if ($value !== '' && ctype_digit($value) && strlen($value) <= 6 && (int) $value <= self::HEAP_MAX) {
                $last = isset($seen[$param]) ? (int) $seen[$param] : null;
                /* Variation significative : plus de 10 %. */
                $moved = ($last === null) || abs((int) $value - $last) > max(1024, $last / 10);
                if ($moved || $due($param)) {
                    $this->checkAndUpdateCmd($logicalId, (int) $value);
                    $set[$param] = (int) $value;
                    $set[$param . 'T'] = $now;
                    $noted++;
                }
            }
        }
        $ip = $get('ip');
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (!isset($seen['ip']) || $seen['ip'] !== $ip)) {
            $this->checkAndUpdateCmd('ip', $ip);
            $set['ip'] = $ip;
            $noted++;
        }
        /* Le SSID tel quel, mais sans caractère de contrôle, et 32 au plus :
         * au-delà ce n'est pas un SSID. Il est échappé partout où il
         * s'affiche. */
        $ssid = isset($params['ssid']) ? (string) $params['ssid'] : '';
        if ($ssid !== '' && mb_check_encoding($ssid, 'UTF-8') && mb_strlen($ssid) <= 32
            && !preg_match('/[\x00-\x1F\x7F]/', $ssid) && (!isset($seen['ssid']) || $seen['ssid'] !== $ssid)) {
            $this->checkAndUpdateCmd('ssid', $ssid);
            $set['ssid'] = $ssid;
            $noted++;
        }
        if (count($set) > 0) {
            $this->seenSet($set);
        }
        return $noted;
    }

    /* La valeur courante d'une commande d'information de l'écran, ou ''. */
    public function infoValue($_logicalId) {
        $cmd = $this->getCmd('info', $_logicalId);
        if (!is_object($cmd)) {
            return '';
        }
        $value = $cmd->execCmd();
        return ($value === null) ? '' : $value;
    }

    /* =========================================================== OTA
     *
     * La mise à jour par le réseau, contrat v1.4.
     *
     * C'est la seule fonction du plugin qui peut CASSER DURABLEMENT un écran à
     * distance : une image défectueuse écrite dans la partition inactive, et il
     * faut décrocher la carte du mur pour la rebrancher en USB. Toute la
     * conception en découle.
     *
     * Deux verrous indépendants, tous deux côté serveur, tous deux fermés par
     * défaut :
     *
     *   ota_enabled  — réglage GLOBAL du plugin (config::byKey) ;
     *   ota_allowed  — réglage PAR ÉCRAN (configuration de l'eqLogic).
     *
     * Les DEUX doivent être ouverts pour qu'un écran reçoive « update: true ».
     * Ce n'est pas une ceinture et des bretelles : les deux verrous ne servent
     * pas à la même chose.
     *
     *   - Le verrou par écran permet le DÉPLOIEMENT PROGRESSIF. On ouvre un
     *     seul écran témoin, on vérifie qu'il revient en ligne et qu'il
     *     fonctionne, puis on ouvre les autres. Sans lui, une mauvaise version
     *     part partout en même temps, et l'on découvre le défaut sur six murs
     *     au lieu d'un.
     *   - Le verrou global permet d'ARRÊTER NET la propagation. Un firmware
     *     qui s'avère défectueux se coupe d'un seul interrupteur : les écrans
     *     qui n'ont pas encore mis à jour continuent d'interroger et reçoivent
     *     « update: false », sans qu'il faille rouvrir chaque équipement.
     *
     * Une carte bloquée reçoit exactement la même réponse qu'une carte à jour,
     * et c'est voulu : elle n'a aucun moyen de faire la différence, donc aucun
     * moyen de passer outre. La décision est entièrement côté serveur.
     */

    /*
     * Le dossier de dépôt du firmware. __DIR__ est core/class/ : le dossier
     * visé est donc data/firmware/ à la racine DU PLUGIN — jamais ailleurs, et
     * surtout pas dans le coeur de Jeedom.
     */
    public static function firmwareDir($_create = false) {
        $dir = __DIR__ . '/../../data/' . self::FIRMWARE_DIR;
        if ($_create && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        /* Résolu quand il existe : le chemin brut porte un « core/class/../.. »
         * qui serait affiché tel quel dans la page, et qui ferait douter de
         * l'endroit où le binaire a réellement été écrit. */
        $real = realpath($dir);
        return ($real !== false) ? $real : $dir;
    }

    /*
     * L'URL du binaire, telle qu'elle part dans la réponse de « action=firmware ».
     *
     * ⚠️ CONTRAT v2.2 : le binaire n'est PLUS servi par Apache depuis data/.
     * Le .htaccess RACINE de Jeedom (coeur, intouchable) porte un
     * « RedirectMatch 403 » sur tout fichier situé sous un dossier data/ dont
     * l'extension n'est pas dans sa liste — .bin n'y est pas. Aucun .htaccess de
     * plugin ne peut lever un RedirectMatch : l'ancienne URL renvoyait 403 à
     * coup sûr, et toute mise à jour échouait au premier octet.
     *
     * Le binaire passe donc par api.php (action=fwfile), dans core/php/, que le
     * coeur laisse passer. Les firmwares déjà en service n'envoient AUCUN
     * en-tête au téléchargement : l'autorisation tient dans un jeton aléatoire
     * de l'URL, à durée de vie courte, plutôt que dans la clé API — qui
     * finirait sinon en clair dans l'URL. Bénéfice au passage : le binaire, qui
     * contient les secrets compilés, n'est plus téléchargeable par quiconque.
     */
    public static function firmwareUrl($_token) {
        $root = '';
        try {
            $root = network::getNetworkAccess('internal');
        } catch (Throwable $e) {
            $root = '';
        }
        return $root . '/plugins/glowscreen32/core/php/api.php?action=fwfile&token=' . rawurlencode($_token);
    }

    /* Durée de vie d'un jeton de téléchargement : large devant les 8 s d'un
     * téléchargement réel, courte devant tout le reste. */
    const FIRMWARE_TOKEN_TTL = 900;

    /* Émet un jeton qui autorise le téléchargement du binaire $_file. */
    /* v3.1 : le jeton est lié au binaire PAR SON EMPREINTE (sha256), et à
     * l'écran pour lequel il a été émis — ses verrous sont relus au
     * téléchargement. */
    public static function firmwareToken($_firmware, $_eqId = 0) {
        $token = bin2hex(random_bytes(16));
        cache::set('glowscreen32::fwtoken::' . $token, array(
            'file'   => $_firmware['file'],
            'sha256' => $_firmware['sha256'],
            'eq'     => (int) $_eqId,
        ), self::FIRMWARE_TOKEN_TTL);
        return $token;
    }

    /* Le chemin du binaire qu'autorise $_token, ou null. Le jeton n'est valable
     * que pour le binaire déposé AU MOMENT de l'émission : un dépôt survenu
     * entre-temps l'invalide plutôt que de servir un autre fichier que celui
     * dont la carte a reçu l'empreinte. */
    public static function firmwareForToken($_token, &$_why = null) {
        $_why = '';
        if (!is_string($_token) || !preg_match('/^[0-9a-f]{32}$/', $_token)) {
            $_why = 'malformed';
            return null;
        }
        $grant = cache::byKey('glowscreen32::fwtoken::' . $_token)->getValue('');
        if (!is_array($grant) || !isset($grant['file'], $grant['sha256'])) {
            /* Inconnu, expiré — ou au format d'avant la v3.1 : la carte en
             * redemandera un au prochain contrôle. */
            $_why = 'unknown';
            return null;
        }
        $firmware = self::firmware();
        if ($firmware === null || !$firmware['exists'] || $firmware['file'] !== $grant['file']
            || $firmware['sha256'] !== $grant['sha256']) {
            $_why = 'replaced';
            return null;
        }
        /* OTA refermé depuis l'émission — verrou global ou verrou de l'écran :
         * fermer un verrou doit arrêter AUSSI les téléchargements en cours de
         * démarrage, pas seulement les prochaines décisions. */
        if (!self::otaEnabled()) {
            $_why = 'locked';
            return null;
        }
        if ($grant['eq'] > 0) {
            $eqLogic = self::byId($grant['eq']);
            if (!is_object($eqLogic) || !$eqLogic->otaAllowed()) {
                $_why = 'locked';
                return null;
            }
        }
        return $firmware;
    }

    /*
     * Le firmware déposé, ou null s'il n'y en a pas.
     *
     * Les métadonnées vivent dans la configuration du plugin, et le binaire
     * dans data/firmware/ : les deux peuvent diverger — un fichier effacé à la
     * main, un dossier perdu. « exists » dit lequel des deux manque, et c'est
     * cette clé, et non la seule présence des métadonnées, qui décide
     * d'annoncer une mise à jour.
     */
    public static function firmware() {
        $file = trim((string) config::byKey('firmware_file', 'glowscreen32', ''));
        if ($file === '') {
            return null;
        }
        $path = self::firmwareDir() . '/' . $file;
        return array(
            'file'    => $file,
            'path'    => $path,
            'exists'  => is_file($path),
            'version' => trim((string) config::byKey('firmware_version', 'glowscreen32', '')),
            'sha256'  => trim((string) config::byKey('firmware_sha256', 'glowscreen32', '')),
            'size'    => (int) config::byKey('firmware_size', 'glowscreen32', 0),
            'human'   => self::humanSize((int) config::byKey('firmware_size', 'glowscreen32', 0)),
            'date'    => trim((string) config::byKey('firmware_date', 'glowscreen32', '')),
            /* Affichage seulement : l'URL réelle porte un jeton émis à chaque
             * décision OTA accordée (voir firmwareUrl). */
            'url'     => self::firmwareUrl('') . '…',
        );
    }

    /* Le verrou global. Fermé par défaut, et il le reste tant que personne ne
     * l'a ouvert sciemment : config::byKey rend '' sur une clé jamais écrite,
     * ce qui vaut 0. */
    public static function otaEnabled() {
        return ((int) config::byKey('ota_enabled', 'glowscreen32', 0)) === 1;
    }

    /*
     * Ouvre ou ferme le verrou global, et l'écrit au journal.
     *
     * Le journal n'est pas une coquetterie : couper l'interrupteur global est
     * le geste qu'on fait quand une mise à jour tourne mal, et savoir à quelle
     * minute il a été coupé est la première chose qu'on cherche ensuite.
     *
     * Ne s'appelle PAS « setOtaEnabled » : utils::a2o() appelle « set » + clé
     * de formulaire sur l'objet à chaque enregistrement, et une méthode de ce
     * nom finirait par être appelée à contretemps (STRUCTURE-PLUGIN-JEEDOM.md,
     * § 8).
     */
    public static function enableOta($_enabled) {
        $enabled = ($_enabled) ? 1 : 0;
        config::save('ota_enabled', $enabled, 'glowscreen32');
        log::add('glowscreen32', 'info', ($enabled == 1)
            ? __('OTA : le verrou global du plugin est OUVERT. Les écrans dont le verrou individuel est ouvert recevront la mise à jour.', __FILE__)
            : __('OTA : le verrou global du plugin est FERMÉ. Plus aucun écran ne recevra de mise à jour, quel que soit son réglage individuel.', __FILE__));
        return $enabled;
    }

    /*
     * Une version telle qu'on accepte de l'écrire : chiffres, lettres, point,
     * tiret, souligné, plus. Elle finit dans un NOM DE FICHIER et dans une URL
     * ; tout le reste est retiré ici, une fois, plutôt que d'espérer que
     * chaque usage y pense.
     */
    public static function sanitizeVersion($_version) {
        $version = preg_replace('/[^0-9A-Za-z._+-]/', '', trim((string) $_version));
        /* Ni « .. », ni un point en tête : le nom de fichier est construit à
         * partir de cette chaîne, et rien ne doit pouvoir désigner un dossier
         * parent. */
        $version = str_replace('..', '', $version);
        $version = ltrim($version, '.-');
        return (strlen($version) > self::FIRMWARE_VERSION_MAX)
            ? substr($version, 0, self::FIRMWARE_VERSION_MAX)
            : $version;
    }

    /*
     * La version lue DANS le binaire, derrière le marqueur, ou une chaîne vide.
     *
     * Le fichier est parcouru par tranches plutôt que chargé d'un bloc : il
     * pèse un mégaoctet aujourd'hui et le plafond du dépôt est à quatre, pour
     * une requête PHP qui sert par ailleurs une page d'administration. Le
     * recouvrement d'une tranche sur l'autre vaut exactement la longueur du
     * motif recherché, si bien qu'un marqueur à cheval sur deux lectures est
     * retrouvé au tour suivant.
     *
     * Ce qui suit le préfixe n'est accepté que si :
     *
     *   - un octet nul le termine dans la fenêtre — c'est ce qui distingue un
     *     littéral C d'une suite d'octets qui ressemblerait au préfixe ;
     *   - il ne contient que des chiffres, des lettres, un point, un tiret, un
     *     souligné ou un plus ;
     *   - il tient en 31 caractères.
     *
     * À défaut, la recherche CONTINUE à l'occurrence suivante plutôt que
     * d'abandonner : un message de diagnostic du firmware pourrait citer le
     * préfixe sans être le marqueur, et ce n'est pas une raison pour refuser un
     * binaire qui porte le vrai.
     */
    public static function markerVersion($_path) {
        $prefix  = self::FIRMWARE_MARKER;
        /* La valeur, plus l'octet nul qui doit la terminer. */
        $window  = self::FIRMWARE_VERSION_MAX + 1;
        $overlap = strlen($prefix) + $window;

        $handle = @fopen($_path, 'rb');
        if ($handle === false) {
            return '';
        }

        $tail = '';
        while (!feof($handle)) {
            $read = fread($handle, 1048576);
            if ($read === false || $read === '') {
                break;
            }
            $buffer = $tail . $read;
            $from   = 0;
            while (($at = strpos($buffer, $prefix, $from)) !== false) {
                $from  = $at + 1;
                $start = $at + strlen($prefix);
                if ($start + $window > strlen($buffer)) {
                    /* La fenêtre déborde de ce qu'on a lu : l'occurrence sera
                     * réexaminée au tour suivant, grâce au recouvrement. */
                    break;
                }
                $value = substr($buffer, $start, $window);
                $end   = strpos($value, "\0");
                if ($end === false || $end === 0) {
                    continue;
                }
                $value = substr($value, 0, $end);
                if (preg_match('/^[0-9A-Za-z._+-]+$/', $value)) {
                    fclose($handle);
                    return self::sanitizeVersion($value);
                }
            }
            $tail = substr($buffer, -$overlap);
        }

        fclose($handle);
        return '';
    }

    /* « 1 002 288 octets » lisible d'un coup d'oeil. */
    public static function humanSize($_size) {
        $size = (int) $_size;
        if ($size < 1024) {
            return $size . ' o';
        }
        if ($size < 1048576) {
            return number_format($size / 1024, 1, ',', ' ') . ' Kio';
        }
        return number_format($size / 1048576, 2, ',', ' ') . ' Mio';
    }

    /*
     * Dépose un firmware, et en fait CELUI que les écrans se verront proposer.
     *
     * Tout est vérifié avant d'écrire quoi que ce soit : ce qui est déposé ici
     * sera écrit tel quel dans la flash d'une carte accrochée à un mur, et le
     * seul moment où l'on peut encore refuser est celui-ci.
     *
     * Rend les métadonnées du firmware déposé. Lève une exception, avec un
     * message destiné à l'utilisateur, sur tout ce qui l'empêche.
     */
    public static function publishFirmware($_tmpPath, $_name = '', $_version = '') {
        if (!is_file($_tmpPath)) {
            throw new Exception(__('Aucun fichier reçu.', __FILE__));
        }
        $size = (int) filesize($_tmpPath);
        if ($size <= 0) {
            throw new Exception(__('Le fichier reçu est vide.', __FILE__));
        }
        if ($size > self::FIRMWARE_MAX_SIZE) {
            throw new Exception(sprintf(
                __('Le fichier fait %1$s : ce n\'est pas une image d\'application ESP32, la carte n\'a que 4 Mo de flash pour deux partitions. Maximum accepté : %2$s.', __FILE__),
                self::humanSize($size), self::humanSize(self::FIRMWARE_MAX_SIZE)));
        }

        /* L'octet d'en-tête : la seule garantie qu'on ait sur la nature du
         * fichier, et elle est vérifiée avant tout le reste. */
        $head = @file_get_contents($_tmpPath, false, null, 0, 4);
        if ($head === false || $head === '' || ord($head[0]) !== self::ESP_IMAGE_MAGIC) {
            throw new Exception(__('Ce fichier ne commence pas par l\'octet 0xE9 : ce n\'est pas une image d\'application ESP32. Déposer autre chose ferait écrire n\'importe quoi dans la partition inactive d\'une carte, qui ne redémarrerait plus.', __FILE__));
        }

        /*
         * La version saisie explicitement prime — c'est une porte de sortie,
         * pour un binaire produit autrement ou pour forcer un numéro le temps
         * d'un essai. À défaut, elle est lue DANS le binaire, derrière le
         * marqueur que le firmware y grave.
         *
         * Et s'il n'y a ni l'une ni l'autre, le dépôt est REFUSÉ. C'est
         * délibéré : publier un firmware dont on ne sait pas nommer la version,
         * c'est ou bien ne jamais déclencher l'OTA — une version identique
         * partout ne fait jamais de différence — ou bien le déclencher sur un
         * binaire qui n'est pas le nôtre. Mieux vaut un refus que l'un ou
         * l'autre.
         */
        $version = self::sanitizeVersion($_version);
        $source  = __('saisie à la main', __FILE__);
        if ($version === '') {
            $version = self::markerVersion($_tmpPath);
            $source  = __('lue dans le marqueur du binaire', __FILE__);
        }
        if ($version === '') {
            throw new Exception(sprintf(
                __('Ce binaire ne porte pas de marqueur de version GlowScreen32 (« %s<version> », terminé par un octet nul) : il n\'a pas été produit par ce projet, ou la version n\'a pas été incrémentée. Sans version, rien ne permet de décider qu\'une carte est en retard — et une version identique d\'un binaire à l\'autre ne déclencherait jamais aucune mise à jour.', __FILE__),
                self::FIRMWARE_MARKER));
        }

        $dir = self::firmwareDir(true);
        if (!is_dir($dir)) {
            throw new Exception(sprintf(__('Le dossier de dépôt %s n\'existe pas et n\'a pas pu être créé.', __FILE__), $dir));
        }
        if (!is_writable($dir)) {
            throw new Exception(sprintf(__('Le dossier de dépôt %s n\'est pas accessible en écriture par le serveur web.', __FILE__), $dir));
        }

        $file = 'glowscreen32-' . $version . '.bin';
        $path = $dir . '/' . $file;

        /* move_uploaded_file quand le fichier vient bien d'un téléversement :
         * c'est la seule forme qui vérifie que le chemin reçu est un fichier
         * temporaire de PHP et non un chemin choisi par l'appelant. */
        /* v3.1 : ATOMIQUE — écrit sous un nom temporaire du même dossier, puis
         * renommé. Une carte qui télécharge pendant un dépôt ne lit jamais un
         * fichier à moitié écrit. */
        $partial = $dir . '/.upload-' . bin2hex(random_bytes(6)) . '.part';
        $moved = is_uploaded_file($_tmpPath)
            ? @move_uploaded_file($_tmpPath, $partial)
            : @copy($_tmpPath, $partial);
        if (!$moved || !is_file($partial) || !@rename($partial, $path)) {
            @unlink($partial);
            throw new Exception(sprintf(__('L\'écriture de %s a échoué.', __FILE__), $path));
        }
        /* Lisible par Apache, qui le sert à la carte. */
        @chmod($path, 0664);

        $sha256 = hash_file('sha256', $path);
        $size   = (int) filesize($path);

        /* L'ancien binaire est retiré APRÈS que le nouveau est en place : une
         * panne au milieu laisse au pire deux fichiers, jamais zéro. */
        $previous = self::firmware();

        config::save('firmware_file', $file, 'glowscreen32');
        config::save('firmware_version', $version, 'glowscreen32');
        config::save('firmware_sha256', $sha256, 'glowscreen32');
        config::save('firmware_size', $size, 'glowscreen32');
        config::save('firmware_date', date('Y-m-d H:i:s'), 'glowscreen32');

        if ($previous !== null && $previous['file'] !== $file && is_file($previous['path'])) {
            @unlink($previous['path']);
        }

        log::add('glowscreen32', 'info', sprintf(
            __('OTA : firmware %1$s déposé depuis « %2$s » (%3$s, sha256 %4$s, version %5$s). Verrou global : %6$s.', __FILE__),
            $version, self::trimText($_name, 64), self::humanSize($size), $sha256, $source,
            self::otaEnabled() ? __('ouvert', __FILE__) : __('fermé — aucun écran ne le recevra', __FILE__)));

        return self::firmware();
    }

    /* Retire le firmware déposé : le binaire et ses métadonnées. Plus aucun
     * écran ne se voit alors proposer quoi que ce soit, verrous ouverts ou
     * non. */
    public static function removeFirmware() {
        $firmware = self::firmware();
        if ($firmware === null) {
            return false;
        }
        if (is_file($firmware['path'])) {
            @unlink($firmware['path']);
        }
        foreach (array('firmware_file', 'firmware_version', 'firmware_sha256',
                       'firmware_size', 'firmware_date') as $key) {
            config::save($key, '', 'glowscreen32');
        }
        log::add('glowscreen32', 'info', sprintf(
            __('OTA : le firmware %1$s a été retiré du dépôt.', __FILE__), $firmware['version']));
        return true;
    }

    /*
     * Tout ce que la page du plugin a besoin de savoir sur l'OTA, en un appel.
     * Rassemblé ici pour que la page, le contrôleur AJAX et le journal disent
     * la même chose.
     */
    public static function otaState() {
        $dir = self::firmwareDir();
        return array(
            'enabled'  => self::otaEnabled(),
            'firmware' => self::firmware(),
            'dir'      => $dir,
            'writable' => is_dir($dir) && is_writable($dir),
            'screens'  => self::overview(),
        );
    }

    /* Le verrou de CET écran. Fermé par défaut, comme le global : un écran
     * créé aujourd'hui ne doit pas se retrouver dans le lot du prochain
     * déploiement sans que personne l'ait décidé. */
    public function otaAllowed() {
        return ((int) $this->getConfiguration('ota_allowed', 0)) === 1;
    }

    /*
     * La version que la carte a annoncée la dernière fois.
     *
     * v2.2 : lue dans la commande « Version du firmware », que l'API est seule
     * à écrire. La clé de configuration « fw » n'est plus écrite (l'API
     * n'enregistre plus l'eqLogic) et ne sert que de repli pour un écran qui
     * n'a pas rappelé depuis la mise à jour du plugin.
     */
    public function firmwareVersion() {
        $cmd = $this->getCmd('info', 'firmware');
        $fw  = is_object($cmd) ? trim((string) $cmd->execCmd()) : '';
        return ($fw !== '') ? $fw : trim((string) $this->getConfiguration('fw', ''));
    }

    /*
     * Retient la version annoncée par la carte.
     *
     * N'écrit QUE si elle a changé, et le journal note la transition — une
     * mise à jour réussie est exactement le moment où l'écriture a lieu.
     *
     * v2.2 : dans la commande d'information seulement. Plus de save() de
     * l'eqLogic depuis l'API (voir noteContact).
     */
    public function noteFirmware($_fw) {
        $fw = self::sanitizeVersion($_fw);
        if ($fw === '') {
            return false;
        }
        $seen = $this->seen();
        if (isset($seen['fw']) && $seen['fw'] === $fw) {
            return false;
        }
        $known = $this->firmwareVersion();
        $this->seenSet(array('fw' => $fw));
        if ($fw === $known) {
            return false;
        }
        $this->checkAndUpdateCmd('firmware', $fw);
        log::add('glowscreen32', 'info', sprintf(
            ($known === '')
                ? __('%1$s : la carte exécute le firmware %2$s.', __FILE__)
                : __('%1$s : la carte est passée du firmware %3$s au firmware %2$s.', __FILE__),
            $this->getHumanName(), $fw, $known));
        return true;
    }

    /*
     * La décision d'OTA pour CET écran, contrat v1.4.
     *
     * Rend le corps de réponse, ou false quand le firmware annoncé a disparu du
     * dépôt — le contrat réserve « firmware_unavailable » à ce seul cas.
     *
     * Chaque décision laisse une ligne de journal qui dit la version annoncée,
     * la réponse, et LE VERROU QUI A BLOQUÉ le cas échéant. C'est la seule
     * chose qui permette de répondre à « pourquoi cet écran-là ne se met pas à
     * jour ? » : la carte, elle, reçoit la même réponse que si elle était à
     * jour, et ne peut donc rien en dire.
     *
     * Les verrous sont examinés AVANT la comparaison de versions, et tous les
     * deux : un journal qui ne nommerait que le premier verrou fermé ferait
     * rouvrir l'un des deux, réessayer, et recommencer.
     */
    public function otaDecision($_fw) {
        $who      = $this->getHumanName();
        $current  = self::sanitizeVersion($_fw);
        $shown    = ($current !== '') ? $current : '?';
        $firmware = self::firmware();

        $locked = array();
        if (!self::otaEnabled()) {
            $locked[] = __('le verrou global du plugin (ota_enabled)', __FILE__);
        }
        if (!$this->otaAllowed()) {
            $locked[] = __('le verrou de cet écran (ota_allowed)', __FILE__);
        }

        if (count($locked) > 0) {
            log::add('glowscreen32', 'info', sprintf(
                __('%1$s : OTA refusé — la carte annonce %2$s, bloqué par %3$s. Réponse : update=false.', __FILE__),
                $who, $shown, implode(__(' et par ', __FILE__), $locked)));
            return array('ok' => true, 'update' => false);
        }

        if ($firmware === null || $firmware['version'] === '') {
            log::add('glowscreen32', 'info', sprintf(
                __('%1$s : OTA autorisé (les deux verrous sont ouverts) mais aucun firmware n\'est déposé ; la carte annonce %2$s. Réponse : update=false.', __FILE__),
                $who, $shown));
            return array('ok' => true, 'update' => false);
        }

        /* version_compare et non une comparaison de chaînes : « 1.10.0 » est
         * postérieur à « 1.9.0 », et « 1.9.0 » lui est supérieur en ASCII. */
        if (!version_compare($firmware['version'], $current, '>')) {
            log::add('glowscreen32', 'debug', sprintf(
                __('%1$s : OTA autorisé, la carte annonce %2$s et le dépôt contient %3$s — rien de plus récent à proposer. Réponse : update=false.', __FILE__),
                $who, $shown, $firmware['version']));
            return array('ok' => true, 'update' => false);
        }

        if (!$firmware['exists']) {
            log::add('glowscreen32', 'error', sprintf(
                __('%1$s : le firmware %2$s est annoncé par la configuration mais le fichier %3$s a disparu du dépôt. Réponse : firmware_unavailable.', __FILE__),
                $who, $firmware['version'], $firmware['file']));
            return false;
        }

        log::add('glowscreen32', 'info', sprintf(
            __('%1$s : OTA accordé — la carte annonce %2$s, le firmware %3$s lui est proposé (%4$s octets, sha256 %5$s).', __FILE__),
            $who, $shown, $firmware['version'], $firmware['size'], $firmware['sha256']));

        return array(
            'ok'      => true,
            'update'  => true,
            'version' => $firmware['version'],
            'url'     => self::firmwareUrl(self::firmwareToken($firmware, $this->getId())),
            'sha256'  => $firmware['sha256'],
            'size'    => $firmware['size'],
        );
    }

    /* ==================================================== CYCLE DE VIE eqLogic */

    /*
     * Les bornes de longueur, refusées à l'ENREGISTREMENT sur ce qui a été
     * REÇU (contrat v3.0, revue) — en caractères, pas en octets. Ce qui
     * arrive malgré tout hors bornes par un autre chemin est tronqué ET
     * journalisé à la lecture.
     */
    public static function checkLengths($_rawButtons, $_rawPages) {
        foreach ((is_array($_rawPages) ? $_rawPages : array()) as $id => $page) {
            if (is_array($page) && isset($page['title']) && mb_strlen(trim((string) $page['title'])) > self::TITLE_MAX) {
                throw new Exception(sprintf(__('Le titre de la page %1$s dépasse %2$s caractères.', __FILE__), ((int) $id) + 1, self::TITLE_MAX));
            }
        }
        foreach ((is_array($_rawButtons) ? $_rawButtons : array()) as $index => $button) {
            if (!is_array($button)) {
                continue;
            }
            if (isset($button['label']) && mb_strlen(trim((string) $button['label'])) > self::LABEL_MAX) {
                throw new Exception(sprintf(__('Bouton %1$s : le libellé « %2$s » dépasse %3$s caractères.', __FILE__),
                    $index + 1, self::logSafe($button['label'], 40), self::LABEL_MAX));
            }
            $fmt = (isset($button['fmt']) && is_array($button['fmt'])) ? $button['fmt'] : array();
            foreach ($fmt as $key => $value) {
                if (preg_match('/^(l0|l1|m\d+l)$/', (string) $key) && mb_strlen(trim((string) $value)) > self::VALUE_MAX) {
                    throw new Exception(sprintf(__('Bouton %1$s : le libellé « %2$s » dépasse %3$s caractères — la tuile ne peut en afficher que %3$s.', __FILE__),
                        $index + 1, self::logSafe($value, 40), self::VALUE_MAX));
                }
            }
        }
    }

    public function preSave() {
        $this->forgetMemo();
        /*
         * Aucune exception sur un équipement qui vient de naître : le coeur le
         * crée avec son seul nom, et toute validation rendrait le bouton
         * « Ajouter » définitivement inopérant.
         *
         * « Ajouter » n'envoie pas non plus les cases Activer et Visible du
         * formulaire, pourtant cochées dans le HTML : sans ces deux lignes,
         * l'écran naît désactivé, l'API lui répond « unknown_device » et rien
         * ne dit pourquoi.
         */
        if ($this->getId() == '') {
            $this->setIsEnable(1);
            $this->setIsVisible(1);
        }

        $mac = self::normalizeMac($this->getConfiguration('mac', ''));
        $this->setConfiguration('mac', $mac);
        /* Le logicalId est la clé de recherche de l'API : il est dérivé de la
         * MAC et jamais saisi à la main. */
        $this->setLogicalId($mac);

        /*
         * Deux équipements sur la même MAC : les deux répondraient au même
         * écran, l'un des deux gagnerait selon l'ordre de la base, et la
         * configuration de l'autre serait invisible sans jamais être perdue.
         * Le refus est explicite, au moment de l'enregistrement.
         */
        if ($mac !== '') {
            $other = self::byMac($mac);
            if (is_object($other) && $other->getId() != $this->getId()) {
                throw new Exception(sprintf(
                    __('L\'adresse MAC %1$s est déjà celle de l\'écran « %2$s ».', __FILE__),
                    self::prettyMac($mac), $other->getName()
                ));
            }
        }

        $this->setConfiguration('poll', $this->poll());

        /*
         * Le verrou OTA de l'écran, ramené à 0 ou 1. Une case à cocher absente
         * du formulaire — un équipement créé par restauration, ou par le bouton
         * « Ajouter » qui n'envoie pas tout — vaut FERMÉ : le défaut d'un
         * verrou est de l'être.
         *
         * Il n'entre PAS dans la signature de mise en page : autoriser un écran
         * à se mettre à jour ne change rien à ce qu'il affiche, et ne doit donc
         * pas le faire redessiner.
         */
        $this->setConfiguration('ota_allowed', $this->otaAllowed() ? 1 : 0);

        /*
         * Les plafonds du contrat v2.0 sont REFUSÉS, pas tronqués — et ils le
         * sont sur ce qui a été REÇU, avant que sanitizeButtons() n'ait coupé
         * la liste. Enregistrer trente-quatre boutons en n'en gardant que
         * trente-deux, sans un mot, c'est exactement la faute que la v1.4
         * commettait côté firmware.
         */
        $raw = $this->getConfiguration('buttons', array());
        if (is_array($raw) && count($raw) > self::MAX_BUTTONS) {
            throw new Exception(sprintf(
                __('Cet écran porte %1$s boutons : le maximum est %2$s. Au-delà, la mise en page ne tient plus dans la mémoire de la carte.', __FILE__),
                count($raw), self::MAX_BUTTONS));
        }

        self::checkLengths($raw, $this->getConfiguration('pages', array()));
        $buttons = $this->buttons();
        /* Refusé AVANT l'écriture : un interrupteur sans état s'enregistrerait
         * sans rien dire et se découvrirait sur le mur, un appui sur deux. */
        self::checkButtons($buttons);
        $this->setConfiguration('buttons', $buttons);
        /* Les pages, la grille et les deux cases à cocher, remis en forme une
         * fois pour toutes : ce qui est relu ensuite est ce qui est écrit. */
        $this->setConfiguration('pages', $this->pages());
        /* v3.2 (contrat v3.1) : toujours au moins une page visible — un écran
         * sans page n'affiche rien et ne sert à rien. */
        $anyVisible = false;
        foreach ($this->pages() as $page) {
            $anyVisible = $anyVisible || $page['visible'];
        }
        if (!$anyVisible) {
            throw new Exception(__('Au moins une page doit rester affichée : un écran sans page n\'affiche rien.', __FILE__));
        }
        $this->setConfiguration('grid', $this->grid());
        $this->setConfiguration('swipe', $this->swipe() ? 1 : 0);
        $this->setConfiguration('clock', $this->clock() ? 1 : 0);
        $this->setConfiguration('readonly', $this->readOnly() ? 1 : 0);
        /* Remis en minutes entières : ce qui est relu est ce qui est écrit, et
         * un champ laissé vide retombe sur le défaut plutôt que sur zéro. */
        $this->setConfiguration('info_max_age', (int) ($this->infoMaxAge() / 60));

        /* Le nombre de boutons par page, contrôlé APRÈS la mise en forme —
         * c'est elle qui décide de la page de chacun. */
        /* v3.1 : contre la grille RÉELLE de l'écran (pageCapacity), et non
         * plus contre le seul plafond 4×3 — sinon un bouton de trop était
         * accepté puis jamais servi. */
        $capacity = $this->pageCapacity();
        $perPage  = array();
        foreach ($buttons as $button) {
            if (!self::buttonConfigured($button)) {
                continue;
            }
            $page = $button['page'];
            $perPage[$page] = (isset($perPage[$page]) ? $perPage[$page] : 0) + 1;
            if ($perPage[$page] > $capacity) {
                $grid = $this->grid();
                throw new Exception(sprintf(
                    __('La page %1$s porte plus de %2$s boutons : c\'est tout ce que tient la grille %3$s×%4$s de cet écran.', __FILE__),
                    $page + 1, $capacity, $grid['cols'], $grid['rows']));
            }
        }

        /*
         * Le compteur de version. Incrémenté ici, avant l'écriture, pour ne pas
         * avoir à réenregistrer l'équipement depuis postSave().
         *
         * Il ne bouge que si la mise en page a réellement changé : un
         * enregistrement qui ne touche qu'une catégorie ou un commentaire ne
         * doit pas faire redessiner tous les écrans de la maison, alors qu'ils
         * afficheraient exactement la même chose.
         */
        $signature = $this->layoutSignature();
        if ($this->getConfiguration('layout_signature', '') !== $signature) {
            $this->setConfiguration('version', $this->version() + (($this->getId() == '') ? 0 : 1));
            $this->setConfiguration('layout_signature', $signature);
        }
    }

    /*
     * Ce qu'un bouton doit porter pour être enregistrable.
     *
     * Un interrupteur SANS commande d'état est refusé : sans état, rien ne
     * permet de décider s'il faut allumer ou éteindre, et le bouton ferait
     * exactement ce que la v1.2 faisait — allumer, toujours. Le refus est au
     * moment de la sauvegarde, avec le numéro de l'emplacement fautif : c'est
     * le seul endroit où l'utilisateur regarde encore le formulaire.
     *
     * Une commande d'état DÉSIGNÉE mais supprimée depuis n'est pas refusée : le
     * bouton reste alors à sa place, INERTE (rang conservé, contrat v3.0), avec
     * une ligne de journal. Bloquer la sauvegarde là-dessus empêcherait de
     * corriger quoi que ce soit d'autre sur l'écran.
     */
    public static function checkButtons($_buttons) {
        foreach ($_buttons as $index => $button) {
            /*
             * Un bouton de navigation qui ne vise rien, ou qui vise sa propre
             * page : refusé à l'enregistrement. Sur le mur, il se présenterait
             * comme un bouton ordinaire qui ne fait rien, et la première
             * conclusion serait « l'écran ne répond plus ».
             */
            if ($button['mode'] === self::MODE_NAV) {
                if ($button['nav'] < 0 || $button['nav'] >= self::MAX_PAGES) {
                    throw new Exception(sprintf(
                        __('%s : un bouton de navigation doit désigner la page qu\'il ouvre.', __FILE__),
                        self::buttonWhere($index, $button)));
                }
                if ($button['nav'] === $button['page']) {
                    throw new Exception(sprintf(
                        __('%s : ce bouton de navigation ouvre la page sur laquelle il se trouve déjà. Il ne ferait rien.', __FILE__),
                        self::buttonWhere($index, $button)));
                }
                continue;
            }
            /* v3.0 : une tuile « valeur » qui ne montre rien, ou qui montre
             * une commande d'ACTION, est refusée — elle afficherait « — » pour
             * toujours, et l'on croirait le capteur en panne. */
            if ($button['mode'] === self::MODE_VIEW) {
                if ($button['view'] === '') {
                    throw new Exception(sprintf(
                        __('%s : une tuile « valeur » doit désigner la commande d\'information qu\'elle affiche.', __FILE__),
                        self::buttonWhere($index, $button)));
                }
                try {
                    $viewCmd = cmd::byString($button['view']);
                } catch (Throwable $e) {
                    $viewCmd = null;
                }
                if (is_object($viewCmd) && $viewCmd->getType() != 'info') {
                    throw new Exception(sprintf(
                        __('%s : une tuile « valeur » affiche une commande d\'INFORMATION, pas une commande d\'action.', __FILE__),
                        self::buttonWhere($index, $button)));
                }
                continue;
            }
            /* v3.1 : un bouton « action » sans cible, ou qui vise une commande
             * d'INFORMATION, est refusé — il serait servi inerte, et l'on
             * croirait l'écran en panne. */
            if ($button['mode'] === self::MODE_ACTION) {
                $where = self::buttonWhere($index, $button);
                if ($button['target'] === self::TARGET_NONE
                    || ($button['target'] === self::TARGET_CMD && $button['cmd'] === '')
                    || ($button['target'] === self::TARGET_SCENARIO && $button['scenario'] <= 0)) {
                    throw new Exception(sprintf(
                        __('%s : un bouton « action simple » doit désigner une commande ou un scénario. Videz la case si elle ne doit rien porter.', __FILE__), $where));
                }
                if ($button['target'] === self::TARGET_CMD) {
                    try {
                        $target = cmd::byString($button['cmd']);
                    } catch (Throwable $e) {
                        $target = null;
                    }
                    if (is_object($target) && $target->getType() != 'action') {
                        throw new Exception(sprintf(
                            __('%s : la commande déclenchée doit être une commande d\'ACTION, pas une commande d\'information.', __FILE__), $where));
                    }
                }
                continue;
            }
            if ($button['mode'] !== self::MODE_TOGGLE || !self::buttonConfigured($button)) {
                continue;
            }
            $where = self::buttonWhere($index, $button);

            if ($button['state'] === '') {
                throw new Exception(sprintf(
                    __('%s : un interrupteur a besoin d\'une commande d\'état. Sans elle, rien ne permet de décider s\'il faut allumer ou éteindre — désignez la commande d\'information qui dit si l\'équipement est allumé, ou repassez le bouton en « Action simple ».', __FILE__),
                    $where
                ));
            }
            /* Une commande d'ACTION dans le champ d'état : le sélecteur ne le
             * permet pas, une saisie à la main si. Elle ne rendrait jamais
             * d'état, et le bouton n'allumerait que. */
            try {
                $state = cmd::byString($button['state']);
            } catch (Throwable $e) {
                $state = null;
            }
            if (is_object($state) && $state->getType() != 'info') {
                throw new Exception(sprintf(
                    __('%s : la commande d\'état doit être une commande d\'information, celle qui DIT si l\'équipement est allumé.', __FILE__),
                    $where
                ));
            }

            if ($button['on'] === '' && $button['off'] === '' && $button['toggle'] === '') {
                throw new Exception(sprintf(
                    __('%s : un interrupteur a besoin d\'une commande « Allumer » et d\'une commande « Éteindre ».', __FILE__),
                    $where
                ));
            }
            /* Une seule des deux, sans « Basculer » : le bouton n'irait que
             * dans un sens — précisément le défaut qu'on corrige. */
            if ($button['toggle'] === '' && ($button['on'] === '' || $button['off'] === '')) {
                throw new Exception(sprintf(
                    __('%s : il manque la commande « %s ». Avec une seule des deux, le bouton ne va que dans un sens — c\'est exactement ce que le mode interrupteur corrige.', __FILE__),
                    $where,
                    ($button['on'] === '') ? __('Allumer', __FILE__) : __('Éteindre', __FILE__)
                ));
            }
        }
    }

    /* « Bouton 1 « Facade » » — de quoi retrouver l'emplacement fautif dans un
     * formulaire de six lignes qui se ressemblent toutes. */
    public static function buttonWhere($_index, $_button) {
        /* La page et la case, et non plus le seul rang : avec quatre pages de
         * douze, « Bouton 27 » ne désigne plus rien que l'utilisateur puisse
         * retrouver dans le formulaire. */
        $where = sprintf(__('Page %1$s, case %2$s', __FILE__),
            $_button['page'] + 1, $_button['slot'] + 1);
        return ($_button['label'] !== '') ? $where . ' « ' . $_button['label'] . ' »' : $where;
    }

    /* La liste des pages CONFIGURÉES, pour les commandes « select » :
     * « position|position — titre ». Les pages 1 à 4 sont toujours proposées :
     * une page vide reste une page. */
    public function pageListValue() {
        $items = array();
        foreach ($this->pages() as $id => $page) {
            $title = ($page['title'] !== '') ? $page['title'] : (($id === 0) ? $this->getName() : sprintf(__('Page %s', __FILE__), $id + 1));
            /* « | » et « ; » sont les séparateurs de listValue. */
            $items[] = $id . '|' . ($id + 1) . ' — ' . str_replace(array('|', ';'), ' ', $title);
        }
        return implode(';', $items);
    }

    /* Les titres des pages visibles, pour « Pages affichées ». */
    public function visiblePageTitles() {
        $titles = array();
        foreach ($this->pages() as $id => $page) {
            if ($page['visible']) {
                $titles[] = ($page['title'] !== '') ? $page['title'] : sprintf(__('Page %s', __FILE__), $id + 1);
            }
        }
        return implode(', ', $titles);
    }

    /* listValue des commandes de pages, et valeur de « Pages affichées ». */
    public function refreshPageCommands() {
        $list = $this->pageListValue();
        foreach (array('page_show', 'page_hide', 'page_only') as $logicalId) {
            $cmd = $this->getCmd('action', $logicalId);
            if (is_object($cmd) && $cmd->getConfiguration('listValue', '') !== $list) {
                $cmd->setConfiguration('listValue', $list);
                $cmd->save();
            }
        }
        $this->checkAndUpdateCmd('pages_visible', $this->visiblePageTitles());
    }

    /*
     * Change la VISIBILITÉ des pages — v3.2, appelé par les commandes
     * d'action. $_wanted : position → bool, pour les seules pages à changer.
     *
     * Contrat v3.1 : l'équipement est RELU juste avant d'écrire, et SEULE la
     * visibilité est modifiée — une configuration enregistrée entre-temps
     * n'est jamais écrasée. Masquer la dernière page visible est REFUSÉ et
     * journalisé (warning) ; rien n'est écrit. Rien n'est écrit non plus si
     * rien ne change.
     */
    public static function applyPageVisibility($_eqLogicId, $_wanted, $_why) {
        $eqLogic = self::byId($_eqLogicId);
        if (!is_object($eqLogic)) {
            throw new Exception(__('Écran introuvable.', __FILE__));
        }
        $pages   = $eqLogic->pages();
        $changed = false;
        foreach ($_wanted as $id => $visible) {
            if (!isset($pages[$id])) {
                continue;
            }
            if ($pages[$id]['visible'] !== (bool) $visible) {
                $pages[$id]['visible'] = (bool) $visible;
                $changed = true;
            }
        }
        $remaining = 0;
        foreach ($pages as $page) {
            $remaining += $page['visible'] ? 1 : 0;
        }
        if ($remaining === 0) {
            log::add('glowscreen32', 'warning', sprintf(
                __('%1$s : « %2$s » refusé — ce serait masquer la dernière page visible. Rien n\'est modifié.', __FILE__),
                $eqLogic->getHumanName(), $_why));
            return false;
        }
        if (!$changed) {
            return false;
        }
        $eqLogic->setConfiguration('pages', $pages);
        $eqLogic->save();
        log::add('glowscreen32', 'info', sprintf(
            __('%1$s : %2$s — pages affichées : %3$s (version %4$s).', __FILE__),
            $eqLogic->getHumanName(), $_why, $eqLogic->visiblePageTitles(), $eqLogic->version()));
        return true;
    }

    public function postSave() {
        $this->forgetMemo();
        $this->createCommands();
        $this->checkAndUpdateCmd('version', $this->version());
        /* v2.2 : le listener suit les commandes d'état et le bandeau tels
         * qu'ils viennent d'être enregistrés, et un ping retenu est réveillé —
         * « version » a pu changer, et la réponse doit le dire tout de suite. */
        $this->updateListener();
        $this->refreshPageCommands();
        self::wakeScreen($this->getId());

        log::add('glowscreen32', 'info', sprintf(
            __('%1$s : configuration enregistrée, version %2$s, %3$s bouton(s) actif(s).', __FILE__),
            /* Le nombre de boutons RÉELLEMENT envoyés, tous pages confondues,
             * et non le contenu d'une réponse d'API : depuis la v2.0 il y a
             * deux réponses possibles, et compter dans l'une des deux ferait
             * dire au journal un chiffre qui dépend du schéma de l'appelant. */
            $this->getHumanName(), $this->version(), count($this->activeButtons())
        ));
    }

    /*
     * « Dupliquer » — v3.1. Le coeur clone l'équipement puis l'enregistre :
     * avec la même MAC, preSave() refusait la copie (« MAC déjà celle de… »).
     * La copie part donc SANS ce qui identifie la carte ou son historique —
     * MAC, logicalId, verrou OTA, firmware annoncé, signature, contact — et
     * l'original n'est modifié qu'en mémoire, le temps du clonage.
     */
    public function copy($_name) {
        $keys  = array('mac', 'ota_allowed', 'fw', 'layout_signature', 'lastcontact', 'info_stale');
        $saved = array();
        foreach ($keys as $key) {
            $saved[$key] = $this->getConfiguration($key, null);
            $this->setConfiguration($key, ($key === 'ota_allowed') ? 0 : '');
        }
        $logicalId = $this->getLogicalId();
        $this->setLogicalId('');
        try {
            return parent::copy($_name);
        } finally {
            foreach ($saved as $key => $value) {
                $this->setConfiguration($key, $value);
            }
            $this->setLogicalId($logicalId);
        }
    }

    /* Exclu des sauvegardes Jeedom (install/backup.php) : les mots de passe
     * Wi-Fi en attente de livraison — v3.1. */
    public static function backupExclude() {
        return array('data/secrets');
    }

    /*
     * Une commande « sensible » — v3.1 : portail, porte, garage, serrure,
     * alarme. Sert à AVERTIR dans la page (bandeau, ⚠ dans l'aperçu, rappel de
     * la lecture seule), jamais à refuser.
     */
    public static function isSensitiveCmd($_cmd) {
        if (!is_object($_cmd)) {
            return false;
        }
        $type = strtoupper((string) $_cmd->getGeneric_type());
        foreach (array('GB_', 'GARAGE_', 'LOCK_', 'ALARM_', 'BARRIER_') as $prefix) {
            if (strpos($type, $prefix) === 0) {
                return true;
            }
        }
        $name = $_cmd->getName();
        $eqLogic = $_cmd->getEqLogic();
        if (is_object($eqLogic)) {
            $name .= ' ' . $eqLogic->getName();
        }
        $name = strtolower(self::stripAccents($name));
        foreach (array('portail', 'porte', 'garage', 'alarme', 'serrure') as $word) {
            if (strpos($name, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function stripAccents($_text) {
        $text = (string) $_text;
        $plain = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return ($plain === false) ? $text : $plain;
    }

    /* Les commandes d'action d'un bouton qui sont sensibles, par champ. */
    public static function sensitiveFields($_button) {
        $found = array();
        if ($_button['mode'] === self::MODE_ACTION && $_button['target'] === self::TARGET_CMD) {
            $fields = array('cmd');
        } elseif ($_button['mode'] === self::MODE_TOGGLE) {
            $fields = array('on', 'off', 'toggle');
        } else {
            return $found;
        }
        foreach ($fields as $field) {
            $cmd = self::actionCmd($_button, $field);
            if ($cmd !== null && self::isSensitiveCmd($cmd)) {
                $found[] = $field;
            }
        }
        return $found;
    }

    /* v2.2 : rien ne doit survivre à l'écran — ni son listener, qui
     * réveillerait un fichier orphelin à chaque changement d'état, ni sa file
     * de commandes, ni les fichiers de l'attente longue. Le seq, lui, est
     * effacé aussi : une MAC réattribuée à un nouvel équipement repart d'un
     * nouvel identifiant, donc d'une nouvelle clé. */
    public function preRemove() {
        $this->removeListener();
        $id = (int) $this->getId();
        foreach (array('queue', 'incomplete', 'infostale') as $kind) {
            cache::delete('glowscreen32::' . $kind . '::' . $id);
        }
        foreach (array('wake', 'hold', 'lock') as $kind) {
            @unlink(self::holdPath($kind, $id));
        }
        config::remove('cmdseq::' . $id, 'glowscreen32');
        foreach ((array) @glob(self::secretDir() . '/wifi-' . $id . '-*') as $file) {
            @unlink($file);
        }
        cache::delete('glowscreen32::seen::' . $id);
    }

    /*
     * Les commandes de l'équipement. Aucune n'est indispensable au dialogue
     * avec la carte : elles existent pour que l'écran soit un équipement
     * ordinaire sur le dashboard, et qu'un scénario puisse réagir à un appui
     * sans passer par le plugin.
     */
    public function createCommands() {
        $definitions = array(
            'version' => array(
                'name'    => __('Version de la mise en page', __FILE__),
                'subType' => 'numeric',
                'icon'    => 'fas fa-code-branch',
            ),
            'lastcontact' => array(
                'name'    => __('Dernier contact', __FILE__),
                'subType' => 'string',
                'icon'    => 'fas fa-network-wired',
            ),
            'lastpress' => array(
                'name'    => __('Dernier bouton', __FILE__),
                'subType' => 'string',
                'icon'    => 'fas fa-hand-pointer',
            ),
            /* La version annoncée par la carte, contrat v1.4. Sur le dashboard
             * comme dans un scénario, c'est la seule façon de voir qu'un écran
             * est resté en arrière après un déploiement progressif. */
            'firmware' => array(
                'name'    => __('Version du firmware', __FILE__),
                'subType' => 'string',
                'icon'    => 'fas fa-microchip',
            ),
            /*
             * v2.1. La présence, enfin exploitable ailleurs que dans le tableau
             * du parc : c'est elle qui permet d'être PRÉVENU qu'un panneau
             * mural est devenu noir, au lieu de s'en apercevoir en passant
             * devant.
             */
            'online' => array(
                'name'    => __('En ligne', __FILE__),
                'subType' => 'binary',
                'icon'    => 'fas fa-plug',
            ),
            /*
             * v2.1. Le niveau Wi-Fi annoncé par la carte. Première cause de
             * panne du projet, et la plus trompeuse : les « ping » passent
             * encore là où un « press » se perd (-88 dBm) et où un OTA meurt à
             * 2 % (-92/-93 dBm). Le bandeau l'affiche déjà sous -75 dBm, mais
             * il faut se tenir devant l'écran pour le lire.
             */
            'rssi' => array(
                'name'    => __('Niveau Wi-Fi', __FILE__),
                'subType' => 'numeric',
                'icon'    => 'fas fa-wifi',
                'unite'   => 'dBm',
                'history' => 1,
            ),
            /*
             * v2.2 — les diagnostics du ping. « rst=panic » ou « wdt » signale
             * un firmware qui plante sans que personne ne le voie ; « heap » et
             * surtout « blk » mesurent la fragmentation qui, sans PSRAM, finit
             * par faire échouer une allocation ; « ip » et « ssid » disent où
             * est l'écran sans débrancher personne. rssi, heap et blk sont
             * historisés : c'est leur évolution qui parle.
             */
            'uptime' => array(
                'name'    => __('Durée de fonctionnement', __FILE__),
                'subType' => 'numeric',
                'icon'    => 'fas fa-stopwatch',
                'unite'   => 's',
            ),
            'resetreason' => array(
                'name'    => __('Cause du redémarrage', __FILE__),
                'subType' => 'string',
                'icon'    => 'fas fa-redo',
            ),
            'heap' => array(
                'name'    => __('Mémoire libre', __FILE__),
                'subType' => 'numeric',
                'icon'    => 'fas fa-memory',
                'unite'   => 'o',
                'history' => 1,
            ),
            'maxblock' => array(
                'name'    => __('Plus gros bloc libre', __FILE__),
                'subType' => 'numeric',
                'icon'    => 'fas fa-cubes',
                'unite'   => 'o',
                'history' => 1,
            ),
            'ip' => array(
                'name'    => __('Adresse IP', __FILE__),
                'subType' => 'string',
                'icon'    => 'fas fa-network-wired',
            ),
            'ssid' => array(
                'name'    => __('Réseau Wi-Fi', __FILE__),
                'subType' => 'string',
                'icon'    => 'fas fa-broadcast-tower',
            ),
            /*
             * v2.2 — les commandes à distance. Chaque verbe du contrat SAUF
             * « wifi » devient une commande action : utilisable depuis le
             * dashboard et depuis un scénario. execute() la met en file ; le
             * prochain ping de la carte la livre. « wifi » fait transiter un
             * mot de passe : il ne part que de la page de l'équipement.
             */
            'cmd_reboot' => array(
                'name'    => __('Redémarrer', __FILE__),
                'type'    => 'action',
                'subType' => 'other',
                'icon'    => 'fas fa-power-off',
            ),
            'cmd_identify' => array(
                'name'    => __('Identifier', __FILE__),
                'type'    => 'action',
                'subType' => 'other',
                'icon'    => 'fas fa-lightbulb',
            ),
            'cmd_message' => array(
                'name'    => __('Message', __FILE__),
                'type'    => 'action',
                'subType' => 'message',
                'icon'    => 'fas fa-comment',
            ),
            'cmd_page' => array(
                'name'    => __('Page', __FILE__),
                'type'    => 'action',
                'subType' => 'slider',
                'icon'    => 'fas fa-columns',
                'min'     => 0,
                'max'     => self::MAX_PAGES - 1,
            ),
            'cmd_calibrate' => array(
                'name'    => __('Calibrer', __FILE__),
                'type'    => 'action',
                'subType' => 'other',
                'icon'    => 'fas fa-crosshairs',
            ),
            /*
             * v3.2 (contrat v3.1) — la visibilité des pages, pour les
             * scénarios (« alarme armée → n'afficher que la page État »). Les
             * pages y sont désignées par leur POSITION DE CONFIGURATION et leur
             * titre, jamais par leur numéro servi, qui change avec les
             * masquages. La liste est tenue à jour en postSave.
             */
            'page_show' => array(
                'name'    => __('Afficher la page', __FILE__),
                'type'    => 'action',
                'subType' => 'select',
                'icon'    => 'fas fa-eye',
            ),
            'page_hide' => array(
                'name'    => __('Masquer la page', __FILE__),
                'type'    => 'action',
                'subType' => 'select',
                'icon'    => 'fas fa-eye-slash',
            ),
            'page_only' => array(
                /* Apostrophe typographique : Jeedom retire l'apostrophe droite des
                 * noms de commande, qui devenait « Nafficher que la page ». */
                'name'    => __('N’afficher que la page', __FILE__),
                'type'    => 'action',
                'subType' => 'select',
                'icon'    => 'fas fa-filter',
            ),
            'page_all' => array(
                'name'    => __('Afficher toutes les pages', __FILE__),
                'type'    => 'action',
                'subType' => 'other',
                'icon'    => 'fas fa-layer-group',
            ),
            'pages_visible' => array(
                'name'    => __('Pages affichées', __FILE__),
                'subType' => 'string',
                'icon'    => 'fas fa-columns',
            ),
            'cmd_ota' => array(
                'name'    => __('Vérifier firmware', __FILE__),
                'type'    => 'action',
                'subType' => 'other',
                'icon'    => 'fas fa-sync',
            ),
        );

        foreach ($definitions as $logicalId => $definition) {
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd)) {
                continue;
            }
            $cmd = new glowscreen32Cmd();
            $cmd->setEqLogic_id($this->getId());
            $cmd->setLogicalId($logicalId);
            $cmd->setName($definition['name']);
            $cmd->setType(isset($definition['type']) ? $definition['type'] : 'info');
            $cmd->setSubType($definition['subType']);
            $cmd->setIsVisible(1);
            $cmd->setDisplay('icon', '<i class="' . $definition['icon'] . '"></i>');
            if (isset($definition['unite'])) {
                $cmd->setUnite($definition['unite']);
            }
            if (isset($definition['history'])) {
                $cmd->setIsHistorized(1);
            }
            if (isset($definition['min'])) {
                $cmd->setConfiguration('minValue', $definition['min']);
                $cmd->setConfiguration('maxValue', $definition['max']);
            }
            if ($definition['subType'] === 'message') {
                /* Le champ « titre » d'une commande message porte la DURÉE, en
                 * secondes, optionnelle : 30 s si vide. */
                $cmd->setDisplay('title_placeholder', __('Durée (s), 30 par défaut', __FILE__));
                $cmd->setDisplay('message_placeholder', __('Texte, 64 caractères au plus', __FILE__));
            }
            $cmd->save();
        }
    }

    /*
     * Ce que la page de configuration affiche pour aider à brancher la carte :
     * l'URL du point d'entrée et la clé qui l'ouvre. Rassemblé ici pour que la
     * page, la documentation et le journal disent la même chose.
     *
     * La clé est la même pour tout le parc : le firmware est identique sur
     * toutes les cartes, chacune se reconnaissant à sa seule adresse MAC.
     */
    public static function apiInfo() {
        $url = '';
        try {
            $url = network::getNetworkAccess('internal');
        } catch (Throwable $e) {
            $url = '';
        }
        return array(
            /* core/php/ et non core/api/ : c'est la convention des plugins de
             * cette installation, et le dossier est servi tel quel par Apache.
             * Il s'agit du dossier « core » DU PLUGIN — rien, nulle part, ne
             * touche au coeur de Jeedom. */
            'url'    => $url . '/plugins/glowscreen32/core/php/api.php',
            'apikey' => jeedom::getApiKey('glowscreen32'),
            'header' => 'X-GLOWSCREEN32-APIKEY',
        );
    }

    /*
     * La vue d'ensemble du parc, pour la liste des écrans. Avec plusieurs
     * écrans, savoir lequel ne répond plus est la première question qu'on se
     * pose, et la seule chose qui y réponde est la date du dernier appel reçu.
     */
    public static function overview() {
        $screens = array();
        /* Lu une fois pour tout le parc : le verrou global ne dépend pas de
         * l'écran, et c'est lui qui décide si le verrou individuel a le moindre
         * effet. */
        $ota = self::otaEnabled();
        foreach (eqLogic::byType('glowscreen32') as $eqLogic) {
            $contact = $eqLogic->lastContact();
            $screens[] = array(
                /* Contrat v1.4 : la version que la carte a annoncée, le verrou
                 * de l'écran, et ce que les DEUX verrous donnent ensemble.
                 * C'est ce triplet qui permet de piloter un déploiement
                 * progressif d'un seul coup d'oeil sur le tableau du parc. */
                'fw'        => $eqLogic->firmwareVersion(),
                'ota'       => $eqLogic->otaAllowed(),
                'otaGlobal' => $ota,
                'otaOpen'   => ($ota && $eqLogic->otaAllowed()),
                'id'      => $eqLogic->getId(),
                'name'    => $eqLogic->getName(),
                'mac'     => self::prettyMac($eqLogic->getConfiguration('mac', '')),
                'enable'  => (int) $eqLogic->getIsEnable(),
                'version' => $eqLogic->version(),
                'poll'    => $eqLogic->poll(),
                'buttons' => count($eqLogic->activeButtons()),
                'pages'   => $eqLogic->pageCount(),
                'contact' => $contact,
                'human'   => self::humanContact($contact),
                'age'     => $eqLogic->contactAge(),
                'online'  => $eqLogic->isOnline(),
                /* v2.2 : lus dans les commandes d'information que l'API est
                 * seule à écrire. */
                'ip'      => (string) $eqLogic->infoValue('ip'),
                'uptime'  => $eqLogic->infoValue('uptime'),
                'uptimeHuman' => (is_numeric($eqLogic->infoValue('uptime')) ? self::humanDuration((int) $eqLogic->infoValue('uptime')) : ''),
                'rst'     => (string) $eqLogic->infoValue('resetreason'),
                'rssi'    => $eqLogic->infoValue('rssi'),
                'queue'   => $eqLogic->commandCount(),
            );
        }
        return $screens;
    }
}

/*
 * La classe des commandes est obligatoire : sans elle, l'enregistrement d'un
 * équipement échoue.
 *
 * Les commandes d'INFORMATION sont écrites par l'API au fil des échanges avec la
 * carte : il n'y a rien à exécuter. Les commandes d'ACTION (v2.2) mettent une
 * commande à distance en file ; le prochain ping de la carte la livre — dans la
 * seconde si un ping est retenu.
 */
class glowscreen32Cmd extends cmd {

    const VERBS = array(
        'cmd_reboot'    => 'reboot',
        'cmd_identify'  => 'identify',
        'cmd_message'   => 'message',
        'cmd_page'      => 'page',
        'cmd_calibrate' => 'calibrate',
        'cmd_ota'       => 'ota',
        /* v3.2 : visibilité des pages — pas des commandes à distance, des
         * changements de configuration. */
        'page_show'     => 'pages:show',
        'page_hide'     => 'pages:hide',
        'page_only'     => 'pages:only',
        'page_all'      => 'pages:all',
    );

    public function execute($_options = array()) {
        if ($this->getType() != 'action') {
            return true;
        }
        $verb = isset(self::VERBS[$this->getLogicalId()]) ? self::VERBS[$this->getLogicalId()] : null;
        if ($verb === null) {
            throw new Exception(sprintf(__('Commande inconnue : %s', __FILE__), $this->getLogicalId()));
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
            throw new Exception(__('Écran introuvable ou désactivé.', __FILE__));
        }
        $options = is_array($_options) ? $_options : array();
        if (strpos($verb, 'pages:') === 0) {
            /* v3.2 : visibilité des pages. La position vient de la liste
             * (« select »), 0 à 3. */
            $position = isset($options['select']) ? trim((string) $options['select']) : '';
            if ($verb !== 'pages:all' && ($position === '' || !ctype_digit($position) || (int) $position >= glowscreen32::MAX_PAGES)) {
                throw new Exception(__('Choisissez une page dans la liste.', __FILE__));
            }
            $position = (int) $position;
            $wanted = array();
            for ($id = 0; $id < glowscreen32::MAX_PAGES; $id++) {
                if ($verb === 'pages:all') {
                    $wanted[$id] = true;
                } elseif ($verb === 'pages:only') {
                    $wanted[$id] = ($id === $position);
                }
            }
            if ($verb === 'pages:show') {
                $wanted = array($position => true);
            } elseif ($verb === 'pages:hide') {
                $wanted = array($position => false);
            }
            glowscreen32::applyPageVisibility($eqLogic->getId(), $wanted, $this->getName()
                . (($verb === 'pages:all') ? '' : ' ' . ($position + 1)));
            return true;
        }
        $args = array();
        if ($verb === 'message') {
            $args['text']     = isset($options['message']) ? $options['message'] : '';
            $args['duration'] = isset($options['title']) ? $options['title'] : '';
        } elseif ($verb === 'page') {
            $args['page'] = isset($options['slider']) ? $options['slider'] : 0;
        }
        $eqLogic->enqueueCommand($verb, $args);
        return true;
    }
}
