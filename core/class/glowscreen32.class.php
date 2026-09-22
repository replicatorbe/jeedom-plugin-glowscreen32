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

    /* Grille 3×2 sur un écran 320×240 : six boutons, pas un de plus. La limite
     * est celle du contrat, pas une préférence d'affichage. */
    const MAX_BUTTONS = 6;

    /* Intervalle de rafraîchissement conseillé à la carte, en secondes. Le
     * « ping » qui l'utilise ne transfère que trois champs : une valeur basse
     * coûte peu, mais elle coûte quand même, une requête PHP à chaque appel. */
    const DEFAULT_POLL = 30;
    const MIN_POLL     = 5;
    const MAX_POLL     = 3600;

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
     * Les deux nombres magiques d'une image d'application ESP32.
     *
     * Le premier octet du fichier vaut 0xE9 : c'est l'en-tête d'image lu par
     * le chargeur d'amorçage. Un fichier qui ne commence pas par là n'est pas
     * un firmware, et le déposer reviendrait à promettre à la carte une image
     * qu'elle écrirait dans sa partition inactive avant de ne plus démarrer.
     *
     * À l'offset 0x20 commence « esp_app_desc_t », dont le mot magique vaut
     * 0xABCD5432 et dont le champ « version » occupe 32 octets — à l'offset
     * 0x10 DANS le descripteur, soit 0x30 dans le fichier, derrière
     * « secure_version » et deux mots réservés. Ne pas confondre avec
     * « project_name », qui vient juste après et que l'on lirait à sa place si
     * l'on comptait l'offset depuis le début du fichier.
     * C'est là qu'on lit la version du firmware sans rien demander à personne :
     * la version affichée par Jeedom est alors CELLE QUI EST DANS LE BINAIRE,
     * et non celle qu'un opérateur a retapée dans un formulaire.
     */
    const ESP_IMAGE_MAGIC        = 0xE9;
    const ESP_APP_DESC_MAGIC     = 0xABCD5432;
    const ESP_APP_DESC_OFFSET    = 0x20;
    const ESP_APP_VERSION_OFFSET = 0x10;
    const ESP_APP_VERSION_LENGTH = 32;

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
        foreach ($_buttons as $stored) {
            if (count($buttons) >= self::MAX_BUTTONS) {
                break;
            }
            if (!is_array($stored)) {
                continue;
            }

            $mode = isset($stored['mode']) ? (string) $stored['mode'] : self::MODE_ACTION;
            if ($mode !== self::MODE_TOGGLE) {
                $mode = self::MODE_ACTION;
            }

            $target = isset($stored['target']) ? (string) $stored['target'] : self::TARGET_NONE;
            if ($target !== self::TARGET_CMD && $target !== self::TARGET_SCENARIO) {
                $target = self::TARGET_NONE;
            }

            $buttons[] = array(
                'label'    => self::trimText(isset($stored['label']) ? $stored['label'] : '', 24),
                'color'    => self::normalizeColor(isset($stored['color']) ? $stored['color'] : ''),
                /* L'icône est du texte libre en v1 : le firmware décide de ce
                 * qu'il sait dessiner, le plugin n'a pas à tenir sa liste et à
                 * refuser un nom qu'une version plus récente comprendrait. */
                'icon'     => self::trimText(isset($stored['icon']) ? $stored['icon'] : '', 24),
                'mode'     => $mode,
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
            );
        }
        return $buttons;
    }

    /* Un champ de désignation de commande, tel qu'il sort du formulaire. */
    public static function trimCmd($_stored, $_key) {
        return isset($_stored[$_key]) ? trim((string) $_stored[$_key]) : '';
    }

    /* Un texte de formulaire, sans balise ni débordement. */
    public static function trimText($_text, $_max) {
        $text = trim(strip_tags((string) $_text));
        /* mb_substr et non substr : couper « Cinéma » au milieu d'un caractère
         * accentué produit du JSON invalide, que json_encode rend alors par
         * « false » — c'est-à-dire une mise en page vide. */
        return (mb_strlen($text) > $_max) ? mb_substr($text, 0, $_max) : $text;
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
        try {
            $cmd = cmd::byString($value);
        } catch (Throwable $e) {
            return null;
        }
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
        if (isset($_button['state']) && $_button['state'] !== '') {
            try {
                $state = cmd::byString($_button['state']);
            } catch (Throwable $e) {
                $state = null;
            }
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
        $state = cmd::byId($stateId);
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
        if ($_button['mode'] === self::MODE_TOGGLE) {
            return self::buttonStateCmd($_button) !== null
                && self::buttonMainCmd($_button) !== null;
        }
        return self::buttonCmd($_button) !== null || self::buttonScenario($_button) !== null;
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
     * Les boutons sans cible sont retirés : une case vide dans le formulaire
     * est un bouton que l'utilisateur n'a pas voulu, l'écran ne doit pas
     * dessiner un carré qui ne fait rien. Une cible supprimée depuis fait
     * disparaître son bouton de la même façon, et le journal le dit — sinon
     * l'utilisateur voit un écran qui perd un bouton sans explication.
     */
    public function activeButtons() {
        $active = array();
        foreach ($this->buttons() as $index => $button) {
            if (!self::buttonResolves($button)) {
                if (self::buttonConfigured($button)) {
                    log::add('glowscreen32', 'warning', sprintf(
                        __('%1$s : le bouton %2$s est incomplet ou vise une cible introuvable, il n\'est pas envoyé à l\'écran.', __FILE__),
                        $this->getHumanName(), $index + 1
                    ));
                }
                continue;
            }
            $active[] = $button;
        }
        return $active;
    }

    public function layout() {
        $buttons = array();
        foreach ($this->activeButtons() as $rank => $button) {
            $label = $button['label'];
            if ($label === '') {
                /* Un bouton sans libellé vaut mieux que pas de bouton : on
                 * reprend le nom de ce qu'il déclenche, qui est au moins exact. */
                $cmd = self::buttonMainCmd($button);
                if ($cmd !== null) {
                    $label = self::trimText($cmd->getName(), 24);
                } else {
                    $scenario = self::buttonScenario($button);
                    $label = ($scenario !== null) ? self::trimText($scenario->getName(), 24) : '';
                }
            }

            $buttons[] = array(
                /* Le RANG, pas un identifiant de commande — contrat v1.3. La
                 * carte le traite comme opaque et le renvoie tel quel. */
                'id'    => $rank,
                'label' => $label,
                'color' => $button['color'],
                'icon'  => $button['icon'],
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
    public function states() {
        $states = array();
        foreach ($this->activeButtons() as $button) {
            $states[] = self::buttonState($button);
        }
        return $states;
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
    public function press($_rank) {
        if (!is_numeric($_rank)) {
            return null;
        }
        $rank    = (int) $_rank;
        $buttons = $this->activeButtons();
        if ($rank < 0 || !isset($buttons[$rank])) {
            return null;
        }
        $button = $buttons[$rank];

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
        if ($poll < self::MIN_POLL) {
            return self::DEFAULT_POLL;
        }
        return ($poll > self::MAX_POLL) ? self::MAX_POLL : $poll;
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
    public function layoutSignature() {
        $buttons = array();
        foreach ($this->buttons() as $button) {
            $buttons[] = self::buttonSignature($button);
        }
        return md5(json_encode(array(
            'name'    => $this->getName(),
            'poll'    => $this->poll(),
            'buttons' => $buttons,
        )));
    }

    public static function buttonSignature($_button) {
        $signature = array(
            $_button['label'], $_button['color'], $_button['icon'],
            $_button['mode'], $_button['state'],
        );
        if ($_button['mode'] === self::MODE_TOGGLE) {
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
     * Il vit dans la CONFIGURATION de l'équipement, et non dans la seule
     * commande d'information : c'est là que le reste du plugin — la page, le
     * tableau du parc, un futur contrôle de présence — va le chercher, et c'est
     * ce que « getConfiguration('lastcontact') » doit rendre.
     *
     * Le repli sur la commande couvre les écrans horodatés par les versions
     * précédentes, qui ne l'écrivaient que là.
     */
    public function lastContact() {
        $stamp = trim((string) $this->getConfiguration('lastcontact', ''));
        if ($stamp !== '') {
            return $stamp;
        }
        $cmd = $this->getCmd(null, 'lastcontact');
        return is_object($cmd) ? trim((string) $cmd->execCmd()) : '';
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
     */
    public function isOnline() {
        $age = $this->contactAge();
        if ($age === null) {
            return false;
        }
        return $age <= (3 * $this->poll()) + self::CONTACT_GRANULARITY;
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
     * Écrit à DEUX endroits, et c'est voulu :
     *
     *  - la configuration de l'équipement, qui est la source consultée par le
     *    plugin lui-même. La v1.0 ne l'écrivait pas, et c'est la raison pour
     *    laquelle « getConfiguration('lastcontact') » rendait une chaîne vide
     *    sur un écran qui dialoguait pourtant parfaitement : l'horodatage
     *    n'existait que dans une commande d'information ;
     *  - la commande d'information « Dernier contact », pour que l'écran soit
     *    un équipement ordinaire sur le dashboard et qu'un scénario puisse
     *    réagir à sa disparition.
     *
     * À la MINUTE, et non à chaque appel. Une carte « ping » toutes les 30 s ;
     * avec dix écrans, horodater chaque appel ferait six cents écritures par
     * heure pour une information dont personne ne lit la seconde. Un appui,
     * lui, est rare et intéressant : il est toujours écrit.
     *
     * save(true) et non save() : l'écriture est DIRECTE, sans preSave() ni
     * postSave(). Un ping ne doit ni recalculer la signature de mise en page, ni
     * risquer de faire bouger le compteur de version, ni recréer les commandes,
     * ni écrire une ligne de journal — il ne doit poser qu'une date.
     */
    public function noteContact($_press = null) {
        $now   = time();
        $stamp = date('Y-m-d H:i:s', $now);
        $age   = $this->contactAge();

        if ($_press === null && $age !== null && $age < self::CONTACT_GRANULARITY) {
            return false;
        }

        $this->setConfiguration('lastcontact', $stamp);
        $this->save(true);

        $this->checkAndUpdateCmd('lastcontact', $stamp);
        $this->checkAndUpdateCmd('version', $this->version());
        if ($_press !== null) {
            $this->checkAndUpdateCmd('lastpress', $_press);
        }
        return true;
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
     * L'URL publique du binaire. C'est elle qui part dans la réponse de
     * « action=firmware », et c'est la carte — pas un navigateur authentifié —
     * qui la télécharge : le fichier doit donc être servi en clair par Apache.
     *
     * data/.htaccess porte « Deny from all » ; data/firmware/.htaccess rouvre
     * les seuls fichiers .bin, exactement comme plugin_info/.htaccess rouvre
     * les seules images. Sans cette exception, la carte reçoit un 403 au
     * milieu de la mise à jour, et le journal d'Apache une ligne
     * « client denied by server configuration ».
     */
    public static function firmwareUrl($_file) {
        $root = '';
        try {
            $root = network::getNetworkAccess('internal');
        } catch (Throwable $e) {
            $root = '';
        }
        return $root . '/plugins/glowscreen32/data/' . self::FIRMWARE_DIR . '/' . rawurlencode($_file);
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
            'url'     => self::firmwareUrl($file),
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
        return (strlen($version) > 32) ? substr($version, 0, 32) : $version;
    }

    /*
     * La version lue DANS le binaire, ou une chaîne vide.
     *
     * esp_app_desc_t est écrit par l'outillage ESP-IDF à l'offset 0x20 de
     * l'image : son mot magique la reconnaît à coup sûr, et son champ
     * « version » est celui que la carte elle-même annonce à « action=firmware ».
     * Les deux viennent donc de la même source, et comparer l'une à l'autre a
     * un sens — ce qui ne serait pas le cas d'un numéro retapé à la main.
     */
    public static function imageVersion($_head) {
        $head   = (string) $_head;
        $needed = self::ESP_APP_DESC_OFFSET + self::ESP_APP_VERSION_OFFSET + self::ESP_APP_VERSION_LENGTH;
        if (strlen($head) < $needed) {
            return '';
        }
        $magic = unpack('V', substr($head, self::ESP_APP_DESC_OFFSET, 4));
        if (!is_array($magic) || !isset($magic[1]) || $magic[1] != self::ESP_APP_DESC_MAGIC) {
            return '';
        }
        $raw = substr($head, self::ESP_APP_DESC_OFFSET + self::ESP_APP_VERSION_OFFSET, self::ESP_APP_VERSION_LENGTH);
        $end = strpos($raw, "\0");
        if ($end !== false) {
            $raw = substr($raw, 0, $end);
        }
        return self::sanitizeVersion($raw);
    }

    /* Le dernier recours : la version lue dans le nom du fichier déposé,
     * « glowscreen32-1.4.0.bin ». Un binaire compilé sans numéro de version
     * reste déposable, plutôt que de renvoyer l'utilisateur à sa chaîne de
     * compilation. */
    public static function versionFromName($_name) {
        if (preg_match('/([0-9]+(?:\.[0-9]+){1,3}[0-9A-Za-z._+-]*)/', (string) $_name, $matches)) {
            return self::sanitizeVersion($matches[1]);
        }
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

        $head = @file_get_contents($_tmpPath, false, null, 0, 256);
        if ($head === false || $head === '' || ord($head[0]) !== self::ESP_IMAGE_MAGIC) {
            throw new Exception(__('Ce fichier ne commence pas par l\'octet 0xE9 : ce n\'est pas une image d\'application ESP32. Déposer autre chose ferait écrire n\'importe quoi dans la partition inactive d\'une carte, qui ne redémarrerait plus.', __FILE__));
        }

        /* La version du binaire fait foi ; la saisie de l'utilisateur ne sert
         * que si l'image n'en porte pas. Un numéro retapé à la main qui
         * contredirait le binaire ferait boucler la carte : elle annoncerait
         * indéfiniment une version que le plugin croirait périmée. */
        $version = self::imageVersion($head);
        $source  = __('lue dans le binaire', __FILE__);
        if ($version === '') {
            $version = self::sanitizeVersion($_version);
            $source  = __('saisie à la main', __FILE__);
        }
        if ($version === '') {
            $version = self::versionFromName($_name);
            $source  = __('lue dans le nom du fichier', __FILE__);
        }
        if ($version === '') {
            throw new Exception(__('Impossible de déterminer la version de ce firmware : l\'image ne porte pas de descripteur ESP-IDF, le nom du fichier n\'en contient pas, et aucune version n\'a été saisie. Sans version, rien ne permet de décider qu\'une carte est en retard.', __FILE__));
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
        $moved = is_uploaded_file($_tmpPath)
            ? @move_uploaded_file($_tmpPath, $path)
            : @copy($_tmpPath, $path);
        if (!$moved || !is_file($path)) {
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
            __('OTA : firmware %1$s déposé (%2$s, sha256 %3$s, version %4$s). Verrou global : %5$s.', __FILE__),
            $version, self::humanSize($size), $sha256, $source,
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

    /* La version que la carte a annoncée la dernière fois. */
    public function firmwareVersion() {
        return trim((string) $this->getConfiguration('fw', ''));
    }

    /*
     * Retient la version annoncée par la carte.
     *
     * N'écrit QUE si elle a changé : la carte l'annonce à chaque interrogation,
     * et réécrire la même chaîne toutes les trente secondes ferait le même
     * gâchis que d'horodater chaque ping. Une mise à jour réussie, elle, est
     * exactement le moment où l'écriture a lieu — et le journal la note.
     *
     * save(true) : écriture directe, sans preSave() ni postSave(). Une carte
     * qui dit sa version ne doit ni recalculer la signature de mise en page, ni
     * faire bouger le compteur de version, ni recréer les commandes.
     */
    public function noteFirmware($_fw) {
        $fw = self::sanitizeVersion($_fw);
        if ($fw === '') {
            return false;
        }
        $known = $this->firmwareVersion();
        if ($fw === $known) {
            return false;
        }
        $this->setConfiguration('fw', $fw);
        $this->save(true);
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
            'url'     => $firmware['url'],
            'sha256'  => $firmware['sha256'],
            'size'    => $firmware['size'],
        );
    }

    /* ==================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
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

        $buttons = $this->buttons();
        /* Refusé AVANT l'écriture : un interrupteur sans état s'enregistrerait
         * sans rien dire et se découvrirait sur le mur, un appui sur deux. */
        self::checkButtons($buttons);
        $this->setConfiguration('buttons', $buttons);

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
     * bouton disparaît alors simplement de la mise en page, avec une ligne de
     * journal. Bloquer la sauvegarde là-dessus empêcherait de corriger quoi que
     * ce soit d'autre sur l'écran.
     */
    public static function checkButtons($_buttons) {
        foreach ($_buttons as $index => $button) {
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
        $where = sprintf(__('Bouton %s', __FILE__), $_index + 1);
        return ($_button['label'] !== '') ? $where . ' « ' . $_button['label'] . ' »' : $where;
    }

    public function postSave() {
        $this->createCommands();
        $this->checkAndUpdateCmd('version', $this->version());

        log::add('glowscreen32', 'info', sprintf(
            __('%1$s : configuration enregistrée, version %2$s, %3$s bouton(s) actif(s).', __FILE__),
            $this->getHumanName(), $this->version(), count($this->layout()['buttons'])
        ));
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
            $cmd->setType('info');
            $cmd->setSubType($definition['subType']);
            $cmd->setIsVisible(1);
            $cmd->setDisplay('icon', $definition['icon']);
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
                'buttons' => count($eqLogic->layout()['buttons']),
                'contact' => $contact,
                'human'   => self::humanContact($contact),
                'age'     => $eqLogic->contactAge(),
                'online'  => $eqLogic->isOnline(),
            );
        }
        return $screens;
    }
}

/*
 * La classe des commandes est obligatoire, même réduite à sa plus simple
 * expression : sans elle, l'enregistrement d'un équipement échoue.
 *
 * Toutes les commandes du plugin sont des informations, écrites par l'API au fil
 * des échanges avec la carte : il n'y a rien à exécuter.
 */
class glowscreen32Cmd extends cmd {

    public function execute($_options = array()) {
        return true;
    }
}
