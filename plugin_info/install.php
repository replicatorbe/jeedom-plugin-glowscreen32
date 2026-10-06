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

require_once __DIR__ . '/../../../core/php/core.inc.php';

/*
 * Le plugin ne crée rien à l'installation : un écran est ajouté à la main, avec
 * son adresse MAC. Reste la clé API, sans laquelle la page de configuration
 * n'aurait rien à afficher dans le cadre « Ce qu'il faut donner à la carte ».
 *
 * jeedom::getApiKey() la fabrique au premier appel et active du même coup le
 * mode d'accès du plugin : la demander ici, c'est s'assurer qu'elle existe
 * avant que quiconque ouvre la page, plutôt qu'au premier appel de la carte —
 * qui serait alors refusé faute de clé à comparer.
 */
function glowscreen32_install() {
    jeedom::getApiKey('glowscreen32');
}

/*
 * La mise à jour rattrape les équipements créés par une version antérieure.
 *
 * createCommands() n'est appelée qu'au postSave() : sans ce passage, les
 * commandes ajoutées en v2.1 — « En ligne » et « Niveau Wi-Fi » — n'existeraient
 * que sur les écrans qu'on pense à rouvrir et à réenregistrer à la main. Les
 * autres resteraient silencieusement sans, et le cron de présence n'aurait rien
 * où écrire : une surveillance qui ne surveille rien, sans le dire.
 *
 * La fonction est idempotente — elle ignore toute commande déjà présente — donc
 * la rejouer à chaque mise à jour ne coûte rien et répare tout.
 */
function glowscreen32_update() {
    glowscreen32_install();

    require_once __DIR__ . '/../core/class/glowscreen32.class.php';
    foreach (eqLogic::byType('glowscreen32') as $eqLogic) {
        $eqLogic->createCommands();
        /*
         * v2.2 : « rssi » est désormais historisé. La commande existait déjà
         * en v2.1, sans historique ; on l'active UNE fois — le drapeau évite de
         * revenir sur le choix de l'utilisateur à chaque mise à jour.
         */
        $rssi = $eqLogic->getCmd('info', 'rssi');
        if (is_object($rssi) && $rssi->getConfiguration('gs_history_v22', 0) != 1) {
            $rssi->setIsHistorized(1);
            $rssi->setConfiguration('gs_history_v22', 1);
            $rssi->save();
        }
        /*
         * v2.2 : le listener de l'attente longue (commandes d'état des
         * boutons + commande du bandeau), reconstruit pour CHAQUE écran. Sans
         * lui, un écran enregistré avant la v2.2 ne serait réveillé que par le
         * recalcul périodique — cinq secondes au lieu d'une.
         */
        $eqLogic->updateListener();
    }
}

/*
 * Rien à retirer : les équipements, leurs commandes et les clés de
 * configuration du plugin sont supprimés par le coeur avec le plugin lui-même.
 * La clé API disparaît avec eux, et les écrans se retrouvent sans interlocuteur
 * — ce qui est exactement l'effet attendu d'une désinstallation.
 */
function glowscreen32_remove() {
}
