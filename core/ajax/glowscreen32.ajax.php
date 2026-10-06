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

/*
 * Le contrôleur de la page de configuration. À ne pas confondre avec
 * core/api/glowscreen32.api.php, qui est le point d'entrée des cartes : ici,
 * l'appelant est un administrateur connecté, et rien n'y est accessible sans
 * session.
 */

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* eqLogic::byId() charge n'importe quel équipement et le rend dans la classe
     * de SON type : sans ce contrôle, un identifiant étranger ferait agir le
     * plugin sur l'équipement d'un autre. */
    $getScreen = function ($_id) {
        $eqLogic = glowscreen32::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'glowscreen32') {
            throw new Exception(__('Écran introuvable', __FILE__));
        }
        return $eqLogic;
    };

    /*
     * Ce que la carte recevra, tel quel. C'est la seule façon de vérifier une
     * configuration sans avoir l'écran sous la main : un bouton dont la
     * commande a été supprimée disparaît de la mise en page sans que le
     * formulaire, lui, ait changé d'apparence.
     */
    $pageInfo = function ($_eqLogic) {
        $map = $_eqLogic->pageMap();
        $out = array();
        foreach ($_eqLogic->pages() as $id => $page) {
            $out[] = array('position' => $id, 'title' => $page['title'], 'visible' => isset($map[$id]),
                           'served' => isset($map[$id]) ? $map[$id] : null);
        }
        return $out;
    };

    $idMap = function ($_eqLogic, $_schema) {
        $map = array();
        foreach ($_eqLogic->buttonsFor($_schema) as $id => $button) {
            $map[] = array('id' => $id, 'label' => $_eqLogic->buttonLabel($button), 'mode' => $button['mode'],
                           'page' => $button['page'], 'inert' => !empty($button['_inert']),
                           'sensitive' => count(glowscreen32::sensitiveFields($button)) > 0);
        }
        return $map;
    };

    /*
     * Commandes SENSIBLES — v3.1 : pour une liste de références saisies (non
     * encore enregistrées), dit lesquelles visent un portail, une porte, un
     * garage, une serrure ou une alarme. La page en fait un avertissement,
     * jamais un refus.
     */
    if (init('action') == 'sensitive') {
        $refs = json_decode((string) init('refs'), true);
        $out  = array();
        foreach ((is_array($refs) ? $refs : array()) as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '' || isset($out[$ref])) {
                continue;
            }
            $cmd = glowscreen32::cmdByString($ref);
            $out[$ref] = (is_object($cmd) && $cmd->getType() == 'action' && glowscreen32::isSensitiveCmd($cmd));
        }
        ajax::success($out);
    }

    if (init('action') == 'preview') {
        $eqLogic = $getScreen(init('id'));
        /*
         * LES DEUX SCHÉMAS, et pas seulement le nouveau.
         *
         * Le schéma 1 n'est pas une curiosité historique tant que le parc n'est
         * pas entièrement passé en v2 : c'est ce que reçoivent les cartes qui
         * n'ont pas encore été mises à jour. Le montrer ici est la seule façon
         * de vérifier, sans décrocher un écran, que l'aplatissement à six
         * boutons donne bien ce qu'on croit — et que l'« id » n'y désigne pas
         * le même bouton que dans le schéma 2.
         */
        ajax::success(array(
            /* v3.0 : les TROIS schémas. Le schéma 2 n'est plus celui de la
             * dernière version : c'est ce que reçoit une carte 2.2 — sans les
             * tuiles « valeur », et avec d'autres « id ». */
            'layout' => $eqLogic->layout(glowscreen32::SCHEMA_CURRENT),
            'v2'     => $eqLogic->layout(glowscreen32::SCHEMA_V2),
            'legacy' => $eqLogic->layout(glowscreen32::SCHEMA_LEGACY),
            /* v3.1 : la correspondance id → libellé de CHAQUE schéma. Un même
             * bouton n'a pas le même id d'un schéma à l'autre ; la montrer
             * évite d'essayer le mauvais bouton au curl. */
            /* v3.2 : chaque page configurée, sa visibilité, et le numéro sous
             * lequel elle est SERVIE (null si masquée). */
            'pages'  => $pageInfo($eqLogic),
            'ids'    => array(
                3 => $idMap($eqLogic, glowscreen32::SCHEMA_CURRENT),
                2 => $idMap($eqLogic, glowscreen32::SCHEMA_V2),
                1 => $idMap($eqLogic, glowscreen32::SCHEMA_LEGACY),
            ),
            'url'    => glowscreen32::apiInfo()['url'],
        ));
    }

    /*
     * Ce que la carte a dit d'elle-même — v2.2. Depuis que l'API n'enregistre
     * plus l'eqLogic, le dernier contact, le firmware et les diagnostics ne
     * sont plus dans la configuration que la page reçoit du coeur : ils sont
     * lus ici, dans les commandes d'information.
     */
    if (init('action') == 'screenstate') {
        $eqLogic = $getScreen(init('id'));
        $uptime  = $eqLogic->infoValue('uptime');
        ajax::success(array(
            'contact'  => $eqLogic->lastContact(),
            'human'    => glowscreen32::humanContact($eqLogic->lastContact()),
            'online'   => $eqLogic->isOnline(),
            'fw'       => $eqLogic->firmwareVersion(),
            'rssi'     => $eqLogic->infoValue('rssi'),
            'ip'       => $eqLogic->infoValue('ip'),
            'ssid'     => $eqLogic->infoValue('ssid'),
            'uptime'   => is_numeric($uptime) ? glowscreen32::humanDuration((int) $uptime) : '',
            'rst'      => $eqLogic->infoValue('resetreason'),
            'heap'     => $eqLogic->infoValue('heap'),
            'blk'      => $eqLogic->infoValue('maxblock'),
            'queue'    => $eqLogic->commandCount(),
        ));
    }

    /*
     * La commande à distance « wifi » — contrat v2.2.
     *
     * Elle n'existe PAS comme commande Jeedom : elle fait transiter un mot de
     * passe en clair, et ne doit pouvoir partir ni d'un scénario ni d'un
     * widget. Seul un administrateur connecté (isConnect('admin'), plus haut)
     * l'envoie, depuis la page de l'équipement. Le mot de passe n'est jamais
     * journalisé.
     *
     * Les plafonds sont refusés ici, avec un message, plutôt que tronqués en
     * file : un mot de passe coupé est un mot de passe faux.
     */
    if (init('action') == 'wifi') {
        $eqLogic = $getScreen(init('id'));
        if ($eqLogic->getIsEnable() != 1) {
            throw new Exception(__('Cet écran est désactivé : il ne reçoit plus rien.', __FILE__));
        }
        $ssid = init('ssid');
        $pass = init('pass');
        if (!is_string($ssid) || !is_string($pass)) {
            throw new Exception(__('Paramètres invalides.', __FILE__));
        }
        if (trim($ssid) === '' || strlen($ssid) > 32) {
            throw new Exception(__('Le nom du réseau doit faire de 1 à 32 octets.', __FILE__));
        }
        /* v3.1 : les règles du WPA2 lui-même. Vide = réseau ouvert ; sinon 8 à
         * 63 caractères, ou EXACTEMENT 64 chiffres hexadécimaux (clé brute).
         * Un mot de passe de 1 à 7 octets ne se connectera jamais : la carte
         * perdrait une minute avant de revenir à l'ancien réseau. */
        $len = strlen($pass);
        if ($len > 0 && $len < 8) {
            throw new Exception(__('Un mot de passe WPA fait au moins 8 caractères (ou rien du tout pour un réseau ouvert).', __FILE__));
        }
        if ($len > 64) {
            throw new Exception(__('Le mot de passe fait plus de 64 octets.', __FILE__));
        }
        if ($len === 64 && !ctype_xdigit($pass)) {
            throw new Exception(__('Un mot de passe de 64 caractères doit être une clé hexadécimale (0-9, a-f) : une phrase de passe WPA fait 63 caractères au plus.', __FILE__));
        }
        $cmd = $eqLogic->enqueueCommand('wifi', array('ssid' => $ssid, 'pass' => $pass));
        ajax::success(array('seq' => $cmd['seq']));
    }

    /*
     * L'APERÇU d'une tuile « valeur » — contrat v3.0, et il n'est pas un
     * confort : le sens d'un « 1 » varie d'un module à l'autre (porte ouverte
     * ou fermée), et seul l'aperçu avec la VRAIE valeur permet de vérifier
     * qu'on n'a pas configuré une porte qui s'affiche fermée quand elle est
     * ouverte.
     *
     * Calculé sur la saisie EN COURS (commande et réglages non enregistrés),
     * par la même fonction que l'API — viewResult() —, et sans rien
     * enregistrer. Rend aussi le préremplissage du type générique.
     */
    if (init('action') == 'viewpreview') {
        $reference = trim((string) init('cmd'));
        $cmd = ($reference !== '') ? glowscreen32::cmdByString($reference) : null;
        if (!is_object($cmd) || $cmd->getType() != 'info') {
            /* v3.1 : commande supprimée, ou pas une information — un SUCCÈS
             * avec why=missing : la tuile s'afficherait « — », c'est
             * exactement ce que l'aperçu doit montrer. */
            ajax::success(array(
                'name' => '', 'kind' => '', 'generic' => '', 'unit' => '', 'raw' => '', 'collect' => '',
                'defaults' => null, 'doubt' => false,
                'result' => array('v' => null, 't' => 'neutral'), 'why' => 'missing',
            ));
        }
        $defaults = glowscreen32::viewDefaults($cmd);
        $raw = json_decode((string) init('fmt'), true);
        $fmt = is_array($raw) ? $raw : $defaults['fmt'];
        $button = array('mode' => glowscreen32::MODE_VIEW, 'view' => '#' . $cmd->getId() . '#',
                        'fmt' => glowscreen32::sanitizeFmt($fmt));
        $why = '';
        $result = glowscreen32::viewResult($button, $why);
        $value = $cmd->execCmd();
        ajax::success(array(
            'name'     => $cmd->getHumanName(),
            'kind'     => $defaults['kind'],
            'generic'  => $defaults['generic'],
            'unit'     => $cmd->getUnite(),
            'raw'      => is_array($value) ? '' : (string) $value,
            'collect'  => $cmd->getCollectDate(),
            'defaults' => $defaults['fmt'],
            'doubt'    => $defaults['doubt'],
            'result'   => $result,
            'why'      => $why,
        ));
    }

    /* ------------------------------------------------------------------ OTA */

    /*
     * L'état complet de l'OTA : le verrou global, le firmware déposé, et le
     * parc avec la version de chaque écran. Un seul appel, pour que la page
     * n'ait jamais à recoller deux moitiés d'information prises à deux
     * instants différents.
     */
    if (init('action') == 'otastate') {
        ajax::success(glowscreen32::otaState());
    }

    /*
     * Le verrou GLOBAL. C'est l'interrupteur qui arrête net la propagation
     * d'un firmware défectueux : il doit pouvoir être coupé en un geste, sans
     * rouvrir chaque équipement, et il est journalisé par enableOta().
     */
    if (init('action') == 'otaglobal') {
        glowscreen32::enableOta(init('enabled') == 1);
        ajax::success(glowscreen32::otaState());
    }

    /*
     * Le dépôt d'un firmware.
     *
     * Tout est vérifié par publishFirmware() — octet magique, taille, version —
     * avant que le fichier n'arrive dans data/firmware/ : ce qui est déposé ici
     * finira écrit dans la flash d'une carte accrochée à un mur.
     *
     * Le dépôt ne déverrouille RIEN : un firmware déposé alors que les verrous
     * sont fermés ne part nulle part, et c'est la manière normale de procéder —
     * on dépose, puis on ouvre un écran témoin.
     */
    if (init('action') == 'firmwareupload') {
        if (!isset($_FILES['firmware'])) {
            throw new Exception(__('Aucun fichier reçu.', __FILE__));
        }
        if ($_FILES['firmware']['error'] != UPLOAD_ERR_OK) {
            /* Le code d'erreur de PHP plutôt qu'un message vague : « le fichier
             * dépasse upload_max_filesize » et « le dossier temporaire est
             * absent » ne se corrigent pas au même endroit. */
            throw new Exception(sprintf(
                __('Le téléversement a échoué (code PHP %s).', __FILE__),
                $_FILES['firmware']['error']));
        }
        glowscreen32::publishFirmware(
            $_FILES['firmware']['tmp_name'],
            $_FILES['firmware']['name'],
            init('version')
        );
        ajax::success(glowscreen32::otaState());
    }

    /* Retirer le firmware déposé : plus aucun écran ne se voit rien proposer,
     * verrous ouverts ou non. */
    if (init('action') == 'firmwareremove') {
        glowscreen32::removeFirmware();
        ajax::success(glowscreen32::otaState());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
