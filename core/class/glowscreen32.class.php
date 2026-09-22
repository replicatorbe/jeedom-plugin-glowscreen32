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
 *     configuration, chacun visant une commande d'action ou un scénario ;
 *   - un compteur de version dit à la carte qu'elle doit redessiner.
 *
 * Le contrat d'API (docs/fr_FR/index.md) est figé : ce qui est produit ici pour
 * « layout », « press » et « ping » ne change pas sans le décider là-bas
 * d'abord, le firmware étant écrit en face.
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

    /* Les deux formes d'action qu'un bouton sait déclencher. */
    const TARGET_NONE     = '';
    const TARGET_CMD      = 'cmd';
    const TARGET_SCENARIO = 'scenario';

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
     * Rendus tels qu'ils sont stockés — la résolution de la cible n'a lieu que
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
     * chacune avec ses cinq clés, jamais absentes.
     *
     * Les valeurs viennent d'un formulaire, donc du réseau. Une couleur est
     * recopiée dans du JSON que la carte lit sans se méfier, et un libellé est
     * affiché tel quel : les deux sont bornés ici, une fois, plutôt qu'à chaque
     * endroit qui s'en sert.
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
                'target'   => $target,
                'cmd'      => isset($stored['cmd']) ? trim((string) $stored['cmd']) : '',
                'scenario' => isset($stored['scenario']) ? (int) $stored['scenario'] : 0,
                /* La commande d'information qui dit si le bouton est allumé.
                 * Désignée explicitement par l'utilisateur, et facultative. */
                'state'    => isset($stored['state']) ? trim((string) $stored['state']) : '',
            );
        }
        return $buttons;
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
     * La commande d'action visée par un bouton, ou null.
     *
     * Le coeur convertit lui-même le champ entre « #[Objet][Équipement]
     * [Commande]# » à l'affichage et « #id# » à l'enregistrement
     * (eqLogic.ajax.php, jeedom::toHumanReadable / fromHumanReadable) : les deux
     * formes peuvent donc se présenter ici, et cmd::byString les accepte toutes
     * les deux. Elle lève, en revanche, sur une commande supprimée — ce qui
     * arrive pour de bon, et ne doit pas emporter toute la mise en page.
     */
    public static function buttonCmd($_button) {
        if ($_button['target'] !== self::TARGET_CMD || $_button['cmd'] === '') {
            return null;
        }
        try {
            $cmd = cmd::byString($_button['cmd']);
        } catch (Throwable $e) {
            return null;
        }
        return (is_object($cmd) && $cmd->getType() == 'action') ? $cmd : null;
    }

    /* Le scénario visé par un bouton, ou null. */
    public static function buttonScenario($_button) {
        if ($_button['target'] !== self::TARGET_SCENARIO || $_button['scenario'] <= 0) {
            return null;
        }
        $scenario = scenario::byId($_button['scenario']);
        return is_object($scenario) ? $scenario : null;
    }

    /*
     * L'identifiant qu'un bouton porte dans le contrat.
     *
     * Pour une commande, c'est son id Jeedom, exactement ce que le contrat
     * demande. Le contrat ne prévoit rien pour un scénario : on lui donne
     * l'opposé de son id, qui ne peut entrer en collision avec aucun id de
     * commande et reste un entier JSON que le firmware lit sans traitement
     * particulier. Le sens du signe est documenté, et « press » sait le relire.
     */
    public static function buttonId($_button) {
        $cmd = self::buttonCmd($_button);
        if ($cmd !== null) {
            return (int) $cmd->getId();
        }
        $scenario = self::buttonScenario($_button);
        if ($scenario !== null) {
            return -((int) $scenario->getId());
        }
        return 0;
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

        $cmd = self::buttonCmd($_button);
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

    /* ========================================================== MISE EN PAGE */

    /*
     * La réponse de « action=layout », telle que la carte la reçoit.
     *
     * Les boutons sans cible sont retirés : une case vide dans le formulaire
     * est un bouton que l'utilisateur n'a pas voulu, l'écran ne doit pas
     * dessiner un carré qui ne fait rien. Une cible supprimée depuis fait
     * disparaître son bouton de la même façon, et le journal le dit — sinon
     * l'utilisateur voit un écran qui perd un bouton sans explication.
     */
    /*
     * Les boutons réellement envoyés à la carte, dans l'ordre de l'écran.
     *
     * « layout » et « ping » passent tous les deux par ici, et c'est ce qui
     * garantit que le tableau « states » du ping est dans le même ordre que les
     * « buttons » du layout — exigence du contrat v1.2. Deux parcours séparés,
     * même filtrés de la même façon, finiraient un jour par diverger, et le
     * symptôme serait des pastilles décalées d'un cran sur le mur.
     */
    public function activeButtons() {
        $active = array();
        foreach ($this->buttons() as $index => $button) {
            if (self::buttonId($button) === 0) {
                if ($button['target'] !== self::TARGET_NONE) {
                    log::add('glowscreen32', 'warning', sprintf(
                        __('%1$s : le bouton %2$s vise une commande ou un scénario introuvable, il n\'est pas envoyé à l\'écran.', __FILE__),
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
        foreach ($this->activeButtons() as $button) {
            $label = $button['label'];
            if ($label === '') {
                /* Un bouton sans libellé vaut mieux que pas de bouton : on
                 * reprend le nom de ce qu'il déclenche, qui est au moins exact. */
                $cmd = self::buttonCmd($button);
                if ($cmd !== null) {
                    $label = self::trimText($cmd->getName(), 24);
                } else {
                    $scenario = self::buttonScenario($button);
                    $label = ($scenario !== null) ? self::trimText($scenario->getName(), 24) : '';
                }
            }

            $buttons[] = array(
                'id'    => self::buttonId($button),
                'label' => $label,
                'color' => $button['color'],
                'icon'  => $button['icon'],
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

    /*
     * Joue le bouton portant cet identifiant, et rend son état après coup.
     *
     * L'identifiant est cherché parmi les boutons de CET écran, jamais dans
     * toute la base : sans ce filtre, la clé API d'un écran du couloir
     * commanderait n'importe quelle commande d'action de l'installation, porte
     * de garage comprise. Un id qui n'est pas au tableau vaut « unknown_button ».
     */
    public function press($_id) {
        $id = (int) $_id;
        foreach ($this->buttons() as $button) {
            if (self::buttonId($button) !== $id || $id === 0) {
                continue;
            }

            $scenario = self::buttonScenario($button);
            if ($scenario !== null) {
                /* Les mêmes étiquettes que le coeur pose lui-même quand un
                 * scénario en démarre un autre : elles s'affichent dans le
                 * journal du scénario, seul endroit où l'on pourra voir qu'il
                 * a été lancé depuis un écran et non à la main. */
                $scenario->addTag('trigger', 'glowscreen32');
                $scenario->addTag('trigger_message', __('Lancé depuis l\'écran', __FILE__) . ' ' . $this->getHumanName());
                /* launch() rend false sur un scénario désactivé, sans rien dire
                 * à personne : l'appui resterait sans effet et sans trace. */
                if ($scenario->launch() === false) {
                    log::add('glowscreen32', 'warning', sprintf(
                        __('%1$s : le scénario « %2$s » n\'a pas démarré (scénario désactivé ?).', __FILE__),
                        $this->getHumanName(), $scenario->getName()
                    ));
                }
                $this->noteContact($button['label'] !== '' ? $button['label'] : $scenario->getName());
                /* Un scénario n'a pas d'état par lui-même, mais l'utilisateur a
                 * pu en désigner un — une commande d'information qui dit si le
                 * mode cinéma est en cours, par exemple. buttonState() le rend
                 * s'il existe, et null sinon, ce qui est le cas courant. */
                return array('ok' => true, 'id' => $id, 'state' => self::buttonState($button));
            }

            $cmd = self::buttonCmd($button);
            if ($cmd === null) {
                return null;
            }
            $cmd->execCmd();
            $this->noteContact($button['label'] !== '' ? $button['label'] : $cmd->getName());

            return array('ok' => true, 'id' => $id, 'state' => self::buttonState($button));
        }
        return null;
    }

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
     */
    public function layoutSignature() {
        return md5(json_encode(array(
            'name'    => $this->getName(),
            'poll'    => $this->poll(),
            'buttons' => $this->buttons(),
        )));
    }

    /* Horodate le dernier échange avec la carte, et retient ce qu'elle a fait.
     * Écrit des commandes, jamais l'équipement : un « ping » toutes les trente
     * secondes qui sauverait l'eqLogic réécrirait la base sans raison. */
    public function noteContact($_press = null) {
        $this->checkAndUpdateCmd('lastcontact', date('Y-m-d H:i:s'));
        $this->checkAndUpdateCmd('version', $this->version());
        if ($_press !== null) {
            $this->checkAndUpdateCmd('lastpress', $_press);
        }
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
        $this->setConfiguration('buttons', $this->buttons());

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
     * La vue d'ensemble du parc, pour la liste des écrans. Un seul écran ne
     * justifierait pas ce tableau ; à partir de trois, savoir lequel ne répond
     * plus est la première question qu'on se pose, et la seule chose qui y
     * réponde est la date du dernier appel reçu.
     */
    public static function overview() {
        $screens = array();
        foreach (eqLogic::byType('glowscreen32') as $eqLogic) {
            $contact = $eqLogic->getCmd(null, 'lastcontact');
            $screens[] = array(
                'id'      => $eqLogic->getId(),
                'name'    => $eqLogic->getName(),
                'mac'     => self::prettyMac($eqLogic->getConfiguration('mac', '')),
                'enable'  => (int) $eqLogic->getIsEnable(),
                'version' => $eqLogic->version(),
                'buttons' => count($eqLogic->layout()['buttons']),
                'contact' => is_object($contact) ? (string) $contact->execCmd() : '',
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
