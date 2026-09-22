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

function glowscreen32_update() {
    glowscreen32_install();
}

/*
 * Rien à retirer : les équipements, leurs commandes et les clés de
 * configuration du plugin sont supprimés par le coeur avec le plugin lui-même.
 * La clé API disparaît avec eux, et les écrans se retrouvent sans interlocuteur
 * — ce qui est exactement l'effet attendu d'une désinstallation.
 */
function glowscreen32_remove() {
}
