/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Les plafonds du contrat v2.0, transmis par la page depuis les constantes de
   la classe : 32 boutons par écran, 4 pages, 12 boutons par page, et 6 pour
   l'aplatissement du schéma 1. Une seule source, pour que les deux côtés ne
   puissent pas dire deux choses différentes. */
var GLOWSCREEN32_LIMITS = (typeof glowscreen32Limits !== 'undefined')
  ? glowscreen32Limits
  : { buttons: 32, pages: 4, perPage: 12, legacy: 6, defaultCols: 3, defaultRows: 3 }

/* Le vocabulaire d'icônes, FERMÉ — contrat v2.0. Le firmware convertit le nom
   en identifiant numérique au parsing et ne sait dessiner que ceux-là : le
   champ est donc une liste déroulante, et non plus un champ texte où l'on
   pouvait écrire une icône qui ne s'afficherait jamais. */
var GLOWSCREEN32_ICONS = (typeof glowscreen32Icons !== 'undefined')
  ? glowscreen32Icons
  : ['none']

/* Les alias du contrat v2.0. Ils valent ici comme en schéma 2, et NON en
   schéma 1 : un nom hors vocabulaire dont l'intention est claire est résolu
   plutôt que perdu. Sans eux, ouvrir le formulaire d'un écran qui porte
   « fire » suffirait à lui faire perdre son icône au premier enregistrement. */
var GLOWSCREEN32_ICON_ALIASES = (typeof glowscreen32IconAliases !== 'undefined')
  ? glowscreen32IconAliases
  : {}

var GLOWSCREEN32_DEFAULT_COLOR = '#2d7ff9'

/*
 * L'écran en cours d'édition, tel que le formulaire le connaît.
 *
 * Les quatre pages ne sont PAS toutes dans le DOM : seule celle qu'on regarde
 * l'est. Quarante-huit blocs de bouton, chacun avec sa liste déroulante de
 * scénarios, feraient une page lourde à construire et lente à saisir. Les
 * autres pages vivent donc ici, et le passage d'un onglet à l'autre recopie le
 * DOM dans ce modèle avant de redessiner.
 */
var glowscreen32Model = {
  grid: { cols: GLOWSCREEN32_LIMITS.defaultCols, rows: GLOWSCREEN32_LIMITS.defaultRows },
  pages: [],
  buttons: []
}

/* La page affichée dans l'onglet « Boutons ». */
var glowscreen32Page = 0

/* Vrai pendant la reconstruction de l'onglet Boutons. Reposer une valeur dans un
   champ émet « change » exactement comme une saisie : sans ce drapeau, ouvrir un
   écran suffirait à le déclarer modifié, et l'avertissement « quitter sans
   enregistrer ? » tomberait sans que rien n'ait été touché. */
var glowscreen32Rendering = false

/* ================================================================ OUTILS */

/* Requête AJAX vers le contrôleur du plugin — celui de la page, pas le point
   d'entrée des cartes. */
function glowscreen32Ajax(_action, _data, _success, _button) {
  if (_button) {
    _button.setAttribute('disabled', 'disabled')
    _button.classList.add('disabled')
  }
  var release = function () {
    if (!_button) { return }
    _button.removeAttribute('disabled')
    _button.classList.remove('disabled')
  }

  domUtils.ajax({
    type: 'POST',
    url: 'plugins/glowscreen32/core/ajax/glowscreen32.ajax.php',
    data: Object.assign({ action: _action }, _data || {}),
    dataType: 'json',
    error: function (request, status, error) {
      release()
      domUtils.handleAjaxError(request, status, error)
    },
    success: function (data) {
      release()
      if (data.state != 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        return
      }
      _success(data.result)
    }
  })
}

/* Le coeur teste DEUX drapeaux avant d'avertir qu'on quitte une page modifiée :
   n'en poser qu'un laisse passer la perte de données une fois sur deux. */
function glowscreen32MarkModified() {
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

/* Ce changement de champ vient-il de l'utilisateur ? Reposer une valeur émet
   « change » exactement comme une saisie : le drapeau de rendu seul ne suffit
   pas, le coeur résout le même problème de la même façon sur ses propres
   champs. */
function glowscreen32ButtonEdited(_target) {
  var inside = _target.closest('#div_glowscreen32Buttons') !== null
            || _target.closest('#div_glowscreen32Page') !== null
  return inside && !glowscreen32Rendering && _target.isVisible()
}

/* ================================================== MODÈLE DE L'ÉCRAN */

/* Le nombre de cases d'une page : le plafond du contrat (12) et celui de la
   grille choisie sont deux limites différentes, et c'est la plus basse qui
   s'applique. */
function glowscreen32Capacity() {
  return Math.min(GLOWSCREEN32_LIMITS.perPage,
    glowscreen32Model.grid.cols * glowscreen32Model.grid.rows)
}

/* Un bouton du modèle, remis en forme. Les mêmes clés que côté PHP, aux mêmes
   valeurs par défaut : c'est ce tableau-là qui part dans configuration.buttons. */
function glowscreen32Button(_button, _page, _slot) {
  var button = _button || {}
  var mode = init(button.mode, 'action')
  if (mode !== 'toggle' && mode !== 'nav') { mode = 'action' }
  var nav = parseInt(init(button.nav, -1), 10)
  if (isNaN(nav) || nav < 0 || nav >= GLOWSCREEN32_LIMITS.pages) { nav = -1 }
  return {
    page: _page,
    slot: _slot,
    label: init(button.label, ''),
    color: glowscreen32Color(button.color),
    icon: glowscreen32Icon(button.icon),
    mode: mode,
    nav: nav,
    target: init(button.target, ''),
    cmd: init(button.cmd, ''),
    scenario: parseInt(init(button.scenario, 0), 10) || 0,
    on: init(button.on, ''),
    off: init(button.off, ''),
    toggle: init(button.toggle, ''),
    state: init(button.state, '')
  }
}

/* Le modèle, reconstruit depuis la configuration de l'équipement.

   C'est ici qu'a lieu la MIGRATION IMPLICITE d'une configuration v1 : un bouton
   sans page ni case tombe sur la page d'accueil, à la case de son rang dans le
   tableau — exactement la mise en page qu'il avait. Le PHP fait le même calcul,
   au même endroit de sa propre lecture : les deux doivent rester d'accord. */
function glowscreen32ResetModel(_configuration) {
  var configuration = init(_configuration, {})

  var grid = init(configuration.grid, {})
  var cols = parseInt(init(grid.cols, GLOWSCREEN32_LIMITS.defaultCols), 10)
  var rows = parseInt(init(grid.rows, GLOWSCREEN32_LIMITS.defaultRows), 10)
  glowscreen32Model.grid = {
    cols: (cols === 3 || cols === 4) ? cols : GLOWSCREEN32_LIMITS.defaultCols,
    rows: (rows === 2 || rows === 3) ? rows : GLOWSCREEN32_LIMITS.defaultRows
  }

  var pages = init(configuration.pages, [])
  glowscreen32Model.pages = []
  for (var p = 0; p < GLOWSCREEN32_LIMITS.pages; p++) {
    var page = (Array.isArray(pages) && isset(pages[p])) ? pages[p] : {}
    var parent = parseInt(init(page.parent, 0), 10)
    if (isNaN(parent) || parent < 0 || parent >= GLOWSCREEN32_LIMITS.pages || parent === p) { parent = 0 }
    glowscreen32Model.pages.push({ title: init(page.title, ''), parent: parent })
  }

  var buttons = init(configuration.buttons, [])
  if (!Array.isArray(buttons)) { buttons = [] }
  glowscreen32Model.buttons = []
  for (var i = 0; i < buttons.length && i < GLOWSCREEN32_LIMITS.buttons; i++) {
    var stored = init(buttons[i], {})
    var bpage = parseInt(init(stored.page, 0), 10)
    if (isNaN(bpage) || bpage < 0 || bpage >= GLOWSCREEN32_LIMITS.pages) { bpage = 0 }
    var bslot = parseInt(init(stored.slot, i), 10)
    if (isNaN(bslot) || bslot < 0 || bslot >= GLOWSCREEN32_LIMITS.perPage) {
      bslot = (i < GLOWSCREEN32_LIMITS.perPage) ? i : 0
    }
    glowscreen32Model.buttons.push(glowscreen32Button(stored, bpage, bslot))
  }
  glowscreen32Page = 0
}

/* Les boutons d'une page, rangés par case. */
function glowscreen32PageButtons(_page) {
  var list = []
  for (var i = 0; i < glowscreen32Model.buttons.length; i++) {
    if (glowscreen32Model.buttons[i].page === _page) { list.push(glowscreen32Model.buttons[i]) }
  }
  list.sort(function (a, b) { return a.slot - b.slot })
  return list
}

/* Le nom d'icône, résolu comme le fera le schéma 2 : le vocabulaire d'abord,
   les alias ensuite, « none » à défaut. La casse est ignorée. */
function glowscreen32Icon(_icon) {
  var icon = String(init(_icon, '')).trim().toLowerCase()
  if (GLOWSCREEN32_ICONS.indexOf(icon) >= 0) { return icon }
  if (isset(GLOWSCREEN32_ICON_ALIASES[icon])) { return GLOWSCREEN32_ICON_ALIASES[icon] }
  return 'none'
}

/* ============================================================== BOUTONS */

/* Un champ « commande », avec son sélecteur. Les quatre champs d'un bouton qui
   désignent une commande sont construits ici : un seul endroit à corriger, et
   le même bouton de choix partout. */
function glowscreen32CmdField(_key, _placeholder, _title, _type) {
  var html = '<div class="input-group">'
  html += '<input class="glowscreen32ButtonAttr form-control input-sm roundedLeft" data-l1key="' + _key + '" placeholder="' + _placeholder + '">'
  html += '<span class="input-group-btn">'
  html += '<a class="btn btn-default btn-sm roundedRight glowscreen32Pick" data-field="' + _key + '" data-cmdtype="' + _type + '" title="' + _title + '"><i class="fas fa-list-alt"></i></a>'
  html += '</span>'
  html += '</div>'
  return html
}

/*
 * Un emplacement de la grille. Les six sont toujours dessinés, remplis ou non :
 * une liste qui s'allonge quand on clique sur « ajouter » ferait croire qu'on
 * peut en mettre sept, et masquerait que la position compte — le premier bouton
 * est en haut à gauche de l'écran, le quatrième dessous.
 */
function glowscreen32AddButton(_page, _slot, _button, _outside) {
  var container = document.getElementById('div_glowscreen32Buttons')
  if (container === null) { return null }
  var button = _button || {}

  var div = '<div class="glowscreen32Button' + (_outside ? ' glowscreen32SlotOut' : '') + '"'
  div += ' data-gs-page="' + _page + '" data-gs-slot="' + _slot + '">'
  div += '<div class="form-group" style="margin:0 0 6px 0;">'
  div += '<label class="col-sm-3 control-label glowscreen32ButtonRank">{{Case}} ' + (_slot + 1)
  div += (_outside ? ' <i class="fas fa-exclamation-triangle" title="{{Cette case sort de la grille choisie : le plugin déplacera le bouton vers la première case libre.}}"></i>' : '')
  div += '</label>'
  div += '<div class="col-sm-5">'
  div += '<input type="text" class="glowscreen32ButtonAttr form-control input-sm" data-l1key="label" placeholder="{{Libellé}}" maxlength="24">'
  div += '</div>'
  div += '<div class="col-sm-2">'
  div += '<input type="color" class="glowscreen32ButtonAttr form-control input-sm" data-l1key="color" title="{{Couleur du bouton sur l\'écran}}">'
  div += '</div>'
  div += '<div class="col-sm-2">'
  /* Une LISTE et non un champ texte : le vocabulaire d'icônes est fermé côté
     firmware, et un nom qu'il ne connaît pas ne dessine rien du tout. */
  div += '<select class="glowscreen32ButtonAttr form-control input-sm" data-l1key="icon" title="{{Icône dessinée par la carte}}">'
  div += glowscreen32IconOptions()
  div += '</select>'
  div += '</div>'
  div += '</div>'

  /* Le mode d'abord : il commande tout le reste de la ligne. Un bouton qui
     n'allume que, parce qu'il est câblé sur la seule commande « Allumer »,
     était le défaut le plus visible de la version précédente. */
  div += '<div class="form-group" style="margin:0 0 6px 0;">'
  div += '<label class="col-sm-3 control-label">{{Mode}}</label>'
  div += '<div class="col-sm-4">'
  div += '<select class="glowscreen32ButtonAttr form-control input-sm glowscreen32Mode" data-l1key="mode">'
  div += '<option value="action">{{Action simple}}</option>'
  div += '<option value="toggle">{{Interrupteur (allumer / éteindre)}}</option>'
  div += '<option value="nav">{{Navigation (ouvrir une page)}}</option>'
  div += '</select>'
  div += '</div>'
  div += '<div class="col-sm-5">'
  div += '<span class="help-block glowscreen32ModeHelp" style="margin:0;"></span>'
  div += '</div>'
  div += '</div>'

  /* --- Mode « navigation » ---------------------------------------------- */
  div += '<div class="glowscreen32ModeNav">'
  div += '<div class="form-group" style="margin:0;">'
  div += '<label class="col-sm-3 control-label">{{Ouvre la page}}</label>'
  div += '<div class="col-sm-4">'
  div += '<select class="glowscreen32ButtonAttr form-control input-sm" data-l1key="nav">'
  div += '<option value="-1">{{Choisissez une page}}</option>'
  for (var navPage = 0; navPage < GLOWSCREEN32_LIMITS.pages; navPage++) {
    if (navPage === _page) { continue }
    div += '<option value="' + navPage + '">' + glowscreen32PageName(navPage) + '</option>'
  }
  div += '</select>'
  div += '</div>'
  div += '<div class="col-sm-5">'
  div += '<span class="help-block" style="margin:0;">{{Ce bouton ne commande rien : la carte change de page toute seule, sans passer par le réseau. Il reste donc utilisable quand Jeedom est injoignable.}}</span>'
  div += '</div>'
  div += '</div>'
  div += '</div>'

  /* --- Mode « action simple » ------------------------------------------- */
  div += '<div class="glowscreen32ModeAction">'
  div += '<div class="form-group" style="margin:0;">'
  div += '<label class="col-sm-3 control-label">{{Déclenche}}</label>'
  div += '<div class="col-sm-3">'
  div += '<select class="glowscreen32ButtonAttr form-control input-sm glowscreen32Target" data-l1key="target">'
  div += '<option value="">{{Rien}}</option>'
  div += '<option value="cmd">{{Une commande}}</option>'
  div += '<option value="scenario">{{Un scénario}}</option>'
  div += '</select>'
  div += '</div>'
  div += '<div class="col-sm-6 glowscreen32TargetCmd">'
  div += glowscreen32CmdField('cmd', '{{Commande à déclencher}}', '{{Choisir une commande}}', 'action')
  div += '</div>'
  div += '<div class="col-sm-6 glowscreen32TargetScenario">'
  div += '<select class="glowscreen32ButtonAttr form-control input-sm" data-l1key="scenario"></select>'
  div += '</div>'
  div += '</div>'
  div += '</div>'

  /* --- Mode « interrupteur » -------------------------------------------- */
  div += '<div class="glowscreen32ModeToggle">'
  div += '<div class="form-group" style="margin:0 0 6px 0;">'
  div += '<label class="col-sm-3 control-label">{{Allumer}}</label>'
  div += '<div class="col-sm-9">'
  div += glowscreen32CmdField('on', '{{Commande qui allume}}', '{{Choisir la commande qui allume}}', 'action')
  div += '</div>'
  div += '</div>'
  div += '<div class="form-group" style="margin:0 0 6px 0;">'
  div += '<label class="col-sm-3 control-label">{{Éteindre}}</label>'
  div += '<div class="col-sm-9">'
  div += glowscreen32CmdField('off', '{{Commande qui éteint}}', '{{Choisir la commande qui éteint}}', 'action')
  div += '</div>'
  div += '</div>'
  div += '<div class="form-group" style="margin:0;">'
  div += '<label class="col-sm-3 control-label">{{Basculer}}</label>'
  div += '<div class="col-sm-9">'
  div += glowscreen32CmdField('toggle', '{{Commande « basculer » — facultatif}}', '{{Choisir la commande qui bascule}}', 'action')
  div += '<span class="help-block" style="margin:0;">{{Secours seulement : le plugin choisit normalement « Allumer » ou « Éteindre » d\'après l\'état, car une commande « basculer » désynchronisée inverse l\'état affiché sur l\'écran. Elle ne sert que si l\'état devient illisible.}}</span>'
  div += '</div>'
  div += '</div>'
  div += '</div>'

  /* --- L'état, commun à « action » et « interrupteur » ------------------- */
  div += '<div class="form-group glowscreen32State" style="margin:6px 0 0 0;">'
  div += '<label class="col-sm-3 control-label">'
  div += '<span class="glowscreen32StateAction">{{Pastille allumée si}}</span>'
  div += '<span class="glowscreen32StateToggle">{{État (obligatoire)}}</span>'
  div += '</label>'
  div += '<div class="col-sm-9">'
  div += glowscreen32CmdField('state', '{{Commande d\'information binaire}}', '{{Choisir la commande d\'état}}', 'info')
  div += '<span class="help-block glowscreen32StateHelp" style="margin:0;"></span>'
  div += '</div>'
  div += '</div>'
  div += '</div>'

  /* L'ordre html() puis setJeeValues puis appendChild est celui du coeur. */
  var wrapper = document.createElement('div')
  wrapper.html(div)
  container.appendChild(wrapper)
  var nodes = Array.prototype.slice.call(wrapper.childNodes)
  wrapper.replaceWith(...nodes)
  var block = nodes[0]

  glowscreen32FillScenarios(block.querySelector('.glowscreen32ButtonAttr[data-l1key="scenario"]'),
    init(button.scenario, 0))

  block.setJeeValues({
    label: init(button.label, ''),
    /* Une couleur absente ou illisible rendrait l'input type=color noir, ce qui
       se lit comme un choix et n'en est pas un. */
    color: glowscreen32Color(button.color),
    icon: glowscreen32Icon(button.icon),
    /* Un bouton enregistré avant le mode vaut « action » : c'est exactement ce
       qu'il faisait, et aucune configuration existante ne change de sens. */
    mode: glowscreen32Mode(button.mode),
    nav: String(init(button.nav, -1)),
    target: init(button.target, ''),
    cmd: init(button.cmd, ''),
    on: init(button.on, ''),
    off: init(button.off, ''),
    toggle: init(button.toggle, ''),
    state: init(button.state, '')
  }, '.glowscreen32ButtonAttr')

  glowscreen32ShowMode(block)
  return block
}

/* Une couleur exploitable par l'input type=color, qui n'accepte que #rrggbb. */
function glowscreen32Color(_color) {
  var color = String(init(_color, '')).trim()
  if (/^#[0-9a-fA-F]{6}$/.test(color)) { return color.toLowerCase() }
  if (/^[0-9a-fA-F]{6}$/.test(color)) { return '#' + color.toLowerCase() }
  return GLOWSCREEN32_DEFAULT_COLOR
}

/* La liste des scénarios, transmise par la page. */
function glowscreen32FillScenarios(_select, _selected) {
  if (_select === null) { return }
  var scenarios = (typeof glowscreen32Scenarios !== 'undefined') ? glowscreen32Scenarios : []
  _select.innerHTML = ''

  var empty = document.createElement('option')
  empty.value = '0'
  empty.textContent = '{{Choisissez un scénario}}'
  _select.appendChild(empty)

  var found = false
  for (var i = 0; i < scenarios.length; i++) {
    var option = document.createElement('option')
    option.value = scenarios[i].id
    option.textContent = scenarios[i].name
    _select.appendChild(option)
    if (String(scenarios[i].id) === String(_selected)) { found = true }
  }

  /* Un scénario supprimé depuis la configuration du bouton : sans cette option
     de repli, le select retomberait sur « Choisissez un scénario » et le premier
     enregistrement effacerait silencieusement la cible. */
  if (!found && String(_selected) !== '0' && _selected !== '') {
    var orphan = document.createElement('option')
    orphan.value = _selected
    orphan.textContent = '{{Scénario introuvable}} (' + _selected + ')'
    _select.appendChild(orphan)
  }
  _select.value = String(_selected)
}

/* Montre le champ qui correspond à ce que le bouton déclenche, et masque
   l'autre : les deux côte à côte laissent croire qu'il faut remplir les deux. */
function glowscreen32ShowTarget(_block) {
  var target = _block.querySelector('.glowscreen32Target').value
  _block.querySelector('.glowscreen32TargetCmd').style.display = (target === 'cmd') ? '' : 'none'
  _block.querySelector('.glowscreen32TargetScenario').style.display = (target === 'scenario') ? '' : 'none'
}

/* Montre les champs du mode choisi, et masque ceux des autres. Un formulaire
   qui affiche « Allumer », « Éteindre » ET « Déclenche » laisse croire qu'il
   faut tout remplir, et l'utilisateur remplit alors celui qui ne sert pas. */
function glowscreen32ShowMode(_block) {
  var mode = glowscreen32Mode(_block.querySelector('.glowscreen32Mode').value)
  var toggle = (mode === 'toggle')
  var nav = (mode === 'nav')

  _block.querySelector('.glowscreen32ModeAction').style.display = (mode === 'action') ? '' : 'none'
  _block.querySelector('.glowscreen32ModeToggle').style.display = toggle ? '' : 'none'
  _block.querySelector('.glowscreen32ModeNav').style.display = nav ? '' : 'none'
  /* Un bouton de navigation n'a pas d'état : le contrat dit qu'il vaut
     TOUJOURS null, et laisser le champ visible inviterait à en désigner un qui
     ne serait jamais lu. */
  _block.querySelector('.glowscreen32State').style.display = nav ? 'none' : ''
  _block.querySelector('.glowscreen32StateAction').style.display = toggle ? 'none' : ''
  _block.querySelector('.glowscreen32StateToggle').style.display = toggle ? '' : 'none'

  var help = '{{Une commande d\'action ou un scénario, joué tel quel à chaque appui. Pour un relais impulsionnel, un portail, une scène.}}'
  if (toggle) {
    help = '{{Le plugin lit l\'état, puis joue la commande inverse : un appui allume, le suivant éteint.}}'
  } else if (nav) {
    help = '{{Le bouton ouvre une autre page de cet écran. Il ne commande rien et n\'a pas d\'état ; une carte au firmware antérieur à la v2 ne le verra pas du tout.}}'
  }
  _block.querySelector('.glowscreen32ModeHelp').textContent = help

  _block.querySelector('.glowscreen32StateHelp').textContent = toggle
    ? '{{Obligatoire : sans état, rien ne permet de décider s\'il faut allumer ou éteindre. La sauvegarde est refusée.}}'
    : '{{Facultatif. Laissé vide, le plugin reprend l\'état que Jeedom associe déjà à la commande d\'action.}}'

  if (mode === 'action') { glowscreen32ShowTarget(_block) }
}

/* Le mode d'un bouton, ramené aux trois du contrat. */
function glowscreen32Mode(_mode) {
  var mode = String(init(_mode, 'action'))
  return (mode === 'toggle' || mode === 'nav') ? mode : 'action'
}

/* Les options de la liste d'icônes, dans l'ordre du contrat. */
function glowscreen32IconOptions() {
  var html = ''
  for (var i = 0; i < GLOWSCREEN32_ICONS.length; i++) {
    html += '<option value="' + GLOWSCREEN32_ICONS[i] + '">' + GLOWSCREEN32_ICONS[i] + '</option>'
  }
  return html
}

/* Le nom d'une page : son titre s'il en a un, sinon son rang. Sert aux onglets
   comme aux listes déroulantes des boutons de navigation. */
function glowscreen32PageName(_page) {
  var page = init(glowscreen32Model.pages[_page], {})
  var title = String(init(page.title, '')).trim()
  if (title !== '') { return title }
  return (_page === 0) ? '{{Accueil}}' : '{{Page}} ' + (_page + 1)
}

/* ============================================== PAGES ET RENDU */

/* Les onglets de page. Quatre au plus, et tous affichés : une page vide reste
   une page, et la masquer obligerait à deviner comment en créer une. */
function glowscreen32RenderPageTabs() {
  var tabs = document.getElementById('ul_glowscreen32Pages')
  if (tabs === null) { return }
  tabs.innerHTML = ''
  for (var page = 0; page < GLOWSCREEN32_LIMITS.pages; page++) {
    var count = glowscreen32PageButtons(page).length
    var li = document.createElement('li')
    li.setAttribute('role', 'presentation')
    if (page === glowscreen32Page) { li.className = 'active' }
    var link = document.createElement('a')
    link.href = '#'
    link.className = 'glowscreen32PageTab'
    link.setAttribute('data-gs-page', String(page))
    link.textContent = glowscreen32PageName(page) + (count > 0 ? ' (' + count + ')' : '')
    li.appendChild(link)
    tabs.appendChild(li)
  }
}

/* Le titre de la page et sa page parente. Le parent est ce vers quoi remonte le
   bouton « retour » de la carte ; la page d'accueil n'en a pas, et le contrat
   ne lui en envoie pas. */
function glowscreen32RenderPageHeader() {
  var box = document.getElementById('div_glowscreen32Page')
  if (box === null) { return }
  var page = init(glowscreen32Model.pages[glowscreen32Page], { title: '', parent: 0 })

  var html = '<div class="form-group" style="margin:0 0 10px 0;">'
  html += '<label class="col-sm-3 control-label">{{Titre de la page}}</label>'
  html += '<div class="col-sm-4">'
  html += '<input type="text" class="form-control input-sm" id="in_glowscreen32PageTitle" maxlength="24" placeholder="' + glowscreen32PageName(glowscreen32Page) + '">'
  html += '</div>'
  if (glowscreen32Page > 0) {
    html += '<label class="col-sm-2 control-label">{{Page parente}}</label>'
    html += '<div class="col-sm-3">'
    html += '<select class="form-control input-sm" id="sel_glowscreen32PageParent">'
    for (var parent = 0; parent < GLOWSCREEN32_LIMITS.pages; parent++) {
      if (parent === glowscreen32Page) { continue }
      html += '<option value="' + parent + '">' + glowscreen32PageName(parent) + '</option>'
    }
    html += '</select>'
    html += '</div>'
  } else {
    html += '<div class="col-sm-5"><span class="help-block" style="margin:0;">{{La page 0 est la page d\'accueil : c\'est celle que la carte affiche au démarrage, et elle n\'a pas de page parente. À défaut de titre, le nom de l\'écran s\'affiche au bandeau.}}</span></div>'
  }
  html += '</div>'
  box.innerHTML = html

  var title = document.getElementById('in_glowscreen32PageTitle')
  if (title !== null) { title.value = init(page.title, '') }
  var select = document.getElementById('sel_glowscreen32PageParent')
  if (select !== null) { select.value = String(init(page.parent, 0)) }
}

/*
 * Les cases de la page affichée.
 *
 * Toutes les cases de la grille sont dessinées, remplies ou non : une liste qui
 * s'allonge quand on clique sur « ajouter » masquerait que la POSITION compte —
 * la première case est en haut à gauche de l'écran.
 *
 * Les boutons dont la case sort de la grille choisie — parce qu'on a réduit la
 * grille après coup — sont dessinés en plus, signalés : les perdre en silence
 * serait le pire des deux maux.
 */
function glowscreen32RenderButtons() {
  var container = document.getElementById('div_glowscreen32Buttons')
  if (container === null) { return }

  glowscreen32Rendering = true
  container.innerHTML = ''

  var capacity = glowscreen32Capacity()
  var stored = {}
  var extra = []
  var list = glowscreen32PageButtons(glowscreen32Page)
  for (var i = 0; i < list.length; i++) {
    if (list[i].slot < capacity && !isset(stored[list[i].slot])) {
      stored[list[i].slot] = list[i]
    } else {
      extra.push(list[i])
    }
  }

  for (var slot = 0; slot < capacity; slot++) {
    glowscreen32AddButton(glowscreen32Page, slot, init(stored[slot], {}), false)
  }
  for (var e = 0; e < extra.length; e++) {
    glowscreen32AddButton(glowscreen32Page, capacity + e, extra[e], true)
  }

  glowscreen32Rendering = false
  glowscreen32RenderPreview()
}

/* L'onglet « Boutons » en entier : les onglets de page, l'en-tête de page, les
   cases, et l'aperçu. */
function glowscreen32RenderPage() {
  glowscreen32RenderPageTabs()
  glowscreen32RenderPageHeader()
  glowscreen32RenderButtons()
}

/* Passe à une autre page, en RECOPIANT D'ABORD la page courante dans le modèle.
   Sans cette recopie, changer d'onglet perdrait la saisie en cours — et comme
   le DOM ne contient qu'une page à la fois, elle serait perdue pour de bon. */
function glowscreen32ShowPage(_page) {
  if (_page < 0 || _page >= GLOWSCREEN32_LIMITS.pages) { return }
  glowscreen32CollectPage()
  glowscreen32Page = _page
  glowscreen32RenderPage()
}

/* ============================================== COLLECTE */

/* Ce bouton porte-t-il quelque chose ?

   Un emplacement vide n'est PAS enregistré : depuis la v2.0, la page et la case
   sont explicites, et une case vide ne porte donc aucune information. Garder
   quarante-huit coquilles vides ferait par ailleurs dépasser le plafond de 32
   boutons par écran, et la troncature emporterait de vrais boutons. */
function glowscreen32ButtonFilled(_button) {
  if (_button.mode === 'nav') { return true }
  return _button.label !== '' || _button.target !== '' || _button.cmd !== ''
      || _button.scenario > 0 || _button.on !== '' || _button.off !== ''
      || _button.toggle !== '' || _button.state !== ''
}

/* La page affichée, recopiée du DOM vers le modèle. */
function glowscreen32CollectPage() {
  var container = document.getElementById('div_glowscreen32Buttons')
  if (container === null) { return }

  var title = document.getElementById('in_glowscreen32PageTitle')
  if (title !== null && isset(glowscreen32Model.pages[glowscreen32Page])) {
    glowscreen32Model.pages[glowscreen32Page].title = String(title.value).trim().substring(0, 24)
  }
  var parent = document.getElementById('sel_glowscreen32PageParent')
  if (parent !== null && isset(glowscreen32Model.pages[glowscreen32Page])) {
    var value = parseInt(parent.value, 10)
    if (isNaN(value) || value < 0 || value >= GLOWSCREEN32_LIMITS.pages || value === glowscreen32Page) { value = 0 }
    glowscreen32Model.pages[glowscreen32Page].parent = value
  }

  var kept = []
  for (var i = 0; i < glowscreen32Model.buttons.length; i++) {
    if (glowscreen32Model.buttons[i].page !== glowscreen32Page) { kept.push(glowscreen32Model.buttons[i]) }
  }

  var blocks = container.querySelectorAll('.glowscreen32Button')
  for (var b = 0; b < blocks.length; b++) {
    var values = blocks[b].getJeeValues('.glowscreen32ButtonAttr')[0]
    var slot = parseInt(blocks[b].getAttribute('data-gs-slot'), 10)
    if (isNaN(slot) || slot < 0 || slot >= GLOWSCREEN32_LIMITS.perPage) { slot = b }
    var button = glowscreen32Button(values, glowscreen32Page, slot)
    if (glowscreen32ButtonFilled(button)) { kept.push(button) }
  }

  kept.sort(function (a, b) { return (a.page - b.page) || (a.slot - b.slot) })
  glowscreen32Model.buttons = kept
}

/* Ce bouton sera-t-il envoyé à la carte ? Le même filtre que buttonResolves()
   côté plugin, aux commandes supprimées près, que le navigateur ne peut pas
   connaître — c'est à cela que sert « Voir ce que la carte reçoit ». */
function glowscreen32ButtonDrawn(_button) {
  if (_button.mode === 'nav') {
    return _button.nav >= 0 && _button.nav < GLOWSCREEN32_LIMITS.pages && _button.nav !== _button.page
  }
  if (_button.mode === 'toggle') {
    return _button.state !== '' && (_button.on !== '' || _button.off !== '' || _button.toggle !== '')
  }
  if (_button.target === 'cmd') { return _button.cmd !== '' }
  if (_button.target === 'scenario') { return _button.scenario > 0 }
  return false
}

/*
 * L'aplatissement du contrat v2.0, refait ici pour l'aperçu : page par page,
 * case par case, et un identifiant GLOBAL continu sur tout l'écran.
 *
 * Il doit dire la même chose qu'activeButtons() côté PHP — c'est le même
 * algorithme, y compris le déplacement d'un bouton dont la case est occupée ou
 * hors grille vers la première case libre. Un aperçu qui numéroterait
 * autrement ferait essayer le mauvais bouton au curl.
 */
function glowscreen32Flatten() {
  var capacity = glowscreen32Capacity()
  var flat = []
  for (var page = 0; page < GLOWSCREEN32_LIMITS.pages; page++) {
    var taken = {}
    var placed = []
    var list = glowscreen32PageButtons(page)
    for (var i = 0; i < list.length; i++) {
      if (!glowscreen32ButtonDrawn(list[i])) { continue }
      var slot = list[i].slot
      if (slot >= capacity || isset(taken[slot])) {
        slot = -1
        for (var free = 0; free < capacity; free++) {
          if (!isset(taken[free])) { slot = free; break }
        }
        if (slot < 0) { continue }
      }
      taken[slot] = true
      var copy = Object.assign({}, list[i])
      copy.slot = slot
      placed.push(copy)
    }
    placed.sort(function (a, b) { return a.slot - b.slot })
    for (var k = 0; k < placed.length; k++) { flat.push(placed[k]) }
  }
  return flat
}

/*
 * L'aperçu de l'écran, reconstruit à chaque frappe. Il montre la PAGE COURANTE
 * telle que la carte la dessinera, sur la grille choisie — et l'identifiant
 * global de chaque bouton, celui que la carte renvoie à « press ».
 */
function glowscreen32RenderPreview() {
  var grid = document.getElementById('div_glowscreen32Preview')
  if (grid === null) { return }

  glowscreen32CollectPage()
  glowscreen32ApplyGrid()
  grid.innerHTML = ''

  var capacity = glowscreen32Capacity()
  var drawn = glowscreen32Flatten()
  var here = {}
  for (var i = 0; i < drawn.length; i++) {
    if (drawn[i].page === glowscreen32Page) { here[drawn[i].slot] = { button: drawn[i], id: i } }
  }

  for (var slot = 0; slot < capacity; slot++) {
    var tile = document.createElement('div')
    tile.className = 'glowscreen32Tile'
    if (!isset(here[slot])) {
      tile.classList.add('glowscreen32TileEmpty')
      tile.textContent = '·'
      grid.appendChild(tile)
      continue
    }
    var button = here[slot].button
    tile.style.background = button.color
    if (button.icon !== 'none') {
      var icon = document.createElement('div')
      icon.className = 'glowscreen32TileIcon'
      icon.textContent = button.icon
      tile.appendChild(icon)
    }
    var label = document.createElement('div')
    /* textContent et non innerHTML : le libellé est une saisie libre, et il est
       relu ici à chaque frappe. */
    label.textContent = (button.label !== '')
      ? button.label
      : ((button.mode === 'nav') ? glowscreen32PageName(button.nav) : '…')
    tile.appendChild(label)
    /* L'id GLOBAL du contrat v2.0, celui que la carte renverra à « press ». Le
       montrer ici évite d'avoir à le compter à la main, pages comprises, quand
       on essaie un bouton au curl. */
    var rank = document.createElement('div')
    rank.className = 'glowscreen32TileRank'
    var mark = ''
    if (button.mode === 'toggle') { mark = ' ⇄' }
    if (button.mode === 'nav') { mark = ' →' }
    rank.textContent = 'id ' + here[slot].id + mark
    tile.appendChild(rank)
    grid.appendChild(tile)
  }
}

/* La grille de l'aperçu suit celle de l'écran : deux variables CSS, reposées à
   chaque rendu. Un aperçu 3×2 devant une carte réglée en 4×3 ferait placer les
   boutons au mauvais endroit en toute confiance. */
function glowscreen32ApplyGrid() {
  var grid = document.getElementById('div_glowscreen32Preview')
  if (grid === null) { return }
  grid.style.setProperty('--gs-cols', String(glowscreen32Model.grid.cols))
  grid.style.setProperty('--gs-rows', String(glowscreen32Model.grid.rows))
}

/* ============================================================ ÉQUIPEMENT */

function printEqLogic(_eqLogic) {
  /* Le coeur ne réinitialise que les .eqLogicAttr : tout le reste de l'écran
     garderait sinon l'état de l'équipement précédemment ouvert. */
  var payload = document.getElementById('pre_glowscreen32Payload')
  if (payload !== null) {
    payload.style.display = 'none'
    payload.textContent = ''
  }

  var configuration = init(_eqLogic.configuration, {})
  var saved = isset(_eqLogic.id) && _eqLogic.id != ''

  var version = document.getElementById('span_glowscreen32Version')
  if (version !== null) {
    version.textContent = saved ? init(configuration.version, 1) : '-'
  }

  var contact = document.getElementById('span_glowscreen32Contact')
  if (contact !== null) {
    contact.textContent = saved
      ? glowscreen32HumanContact(init(configuration.lastcontact, ''))
      : '-'
  }

  /* La version annoncée par la CARTE, contrat v1.4. Elle ne vient pas du
     formulaire : tant qu'aucune carte n'a appelé, il n'y a rien à montrer, et
     montrer « 0.0.0 » serait pire que de dire « inconnu ». */
  var firmware = document.getElementById('span_glowscreen32Firmware')
  if (firmware !== null) {
    var fw = String(init(configuration.fw, '')).trim()
    firmware.textContent = (saved && fw !== '') ? fw : '{{inconnu}}'
  }

  /* Le modèle est reconstruit AVANT le rendu : les quatre pages n'existent que
     là, et le DOM n'en porte qu'une à la fois. */
  glowscreen32ResetModel(configuration)

  /* La grille n'est pas une .eqLogicAttr : le coeur ne la repose donc pas, et
     sans cette ligne elle garderait celle de l'écran précédemment ouvert. */
  var gridSelect = document.getElementById('sel_glowscreen32Grid')
  if (gridSelect !== null) {
    gridSelect.value = glowscreen32Model.grid.cols + 'x' + glowscreen32Model.grid.rows
  }

  glowscreen32RenderPage()
  glowscreen32ShowApi(init(configuration.mac, ''))
}

/* « 22/09/2026 11:03:05 (il y a 2 min) ». Une date seule oblige à regarder
   l'heure qu'il est pour savoir si l'écran répond encore. */
function glowscreen32HumanContact(_stamp) {
  var stamp = String(init(_stamp, '')).trim()
  if (stamp === '') { return '{{jamais vu}}' }

  /* Découpé à la main : « 2026-09-22 11:03:05 » n'est pas de l'ISO 8601, et
     Date.parse le refuse ou l'interprète en UTC selon le navigateur. */
  var parts = stamp.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/)
  if (parts === null) { return stamp }
  var date = new Date(+parts[1], +parts[2] - 1, +parts[3], +parts[4], +parts[5], +parts[6])
  var age = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000))

  /* La phrase entière est traduite, et non ses morceaux : « 2 min ago » ne se
     fabrique pas en recollant « il y a » et « min ». */
  var ago
  if (age < 60) { ago = '{{il y a %s s}}'.replace('%s', age) }
  else if (age < 3600) { ago = '{{il y a %s min}}'.replace('%s', Math.floor(age / 60)) }
  else if (age < 86400) { ago = '{{il y a %s h}}'.replace('%s', Math.floor(age / 3600)) }
  else { ago = '{{il y a %s j}}'.replace('%s', Math.floor(age / 86400)) }

  return parts[3] + '/' + parts[2] + '/' + parts[1] + ' ' + parts[4] + ':' + parts[5] + ':' + parts[6]
    + ' (' + ago + ')'
}

/* L'URL, la clé, et l'appel complet à recopier dans le firmware.
   L'exemple est un curl avec l'en-tête, et non une URL à coller dans un
   navigateur : c'est la forme que le firmware doit employer, et la montrer
   dans l'autre forme reviendrait à conseiller celle qu'on déconseille. */
function glowscreen32ShowApi(_mac) {
  var api = (typeof glowscreen32Api !== 'undefined')
    ? glowscreen32Api
    : { url: '', apikey: '', header: 'X-GLOWSCREEN32-APIKEY' }
  var url = document.getElementById('span_glowscreen32ApiUrl')
  var key = document.getElementById('span_glowscreen32ApiKey')
  var sample = document.getElementById('ta_glowscreen32ApiSample')

  if (url !== null) { url.textContent = (api.url !== '') ? api.url : '-' }
  if (key !== null) { key.textContent = (api.apikey !== '') ? api.apikey : '-' }
  if (sample === null) { return }

  var mac = String(_mac || '').replace(/[^0-9a-fA-F]/g, '').toLowerCase()
  var device = (mac.length === 12) ? mac : '<{{adresse MAC}}>'
  sample.value = 'curl -s -H "' + init(api.header, 'X-GLOWSCREEN32-APIKEY') + ': ' + api.apikey + '" \\\n'
    + '  "' + api.url + '?action=layout&device=' + device + '"'
}

/* Appelée par plugin.template.js juste avant l'enregistrement. Les boutons sont
   une liste imbriquée : data-lXkey ne descend qu'à trois niveaux, il faut les
   collecter à la main. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }

  /* La page affichée d'abord : elle n'est dans le modèle qu'une fois recopiée. */
  glowscreen32CollectPage()

  /* Le tableau reste PLAT, comme en v1 : chaque bouton porte sa page et sa
     case. Une configuration v1 continue donc de se lire telle quelle, sans
     migration — et une v2 relue par un plugin v1 y verrait encore une liste de
     boutons dans l'ordre. */
  var buttons = []
  for (var i = 0; i < glowscreen32Model.buttons.length && i < GLOWSCREEN32_LIMITS.buttons; i++) {
    buttons.push(glowscreen32Model.buttons[i])
  }
  if (glowscreen32Model.buttons.length > GLOWSCREEN32_LIMITS.buttons) {
    /* Le plugin refusera l'enregistrement, et c'est voulu : les plafonds du
       contrat sont refusés, pas tronqués. L'avertissement est ici pour que la
       raison soit lisible avant le message d'erreur. */
    jeedomUtils.showAlert({
      message: '{{Cet écran porte plus de boutons que le maximum autorisé. L\'enregistrement sera refusé.}}',
      level: 'danger'
    })
  }

  _eqLogic.configuration.buttons = buttons
  _eqLogic.configuration.pages = glowscreen32Model.pages
  _eqLogic.configuration.grid = glowscreen32Model.grid
  return _eqLogic
}

/* ============================================================== COMMANDES */

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '<a class="btn btn-danger btn-xs cmdAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i></a>'
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table génère un <tbody>
     par insertion et toutes les commandes se retrouveraient au même endroit. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  /* .cmdAttr[data-l1key="id"] est obligatoire dans la ligne : sans lui, chaque
     enregistrement détruit et recrée les commandes, l'historique est perdu et
     les scénarios qui les visaient sont cassés. */
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ==================================================================== OTA */

/* Le firmware déposé et les verrous viennent du serveur ; tout ce qui est écrit
   dans la page à partir de là est échappé, sans exception. Un nom de fichier et
   un numéro de version sont bornés côté serveur, mais les recoller dans du HTML
   sans les échapper serait une faute qui ne se voit qu'une fois exploitée. */
function glowscreen32Escape(_text) {
  var holder = document.createElement('div')
  holder.textContent = (_text === null || typeof _text === 'undefined') ? '' : String(_text)
  return holder.innerHTML
}

/* Le cadre « Firmware déposé », reconstruit après un dépôt ou un retrait. Le
   serveur rend le même tableau à l'ouverture de la page : les deux disent la
   même chose, à partir de la même structure. */
function glowscreen32RenderFirmware(_state) {
  var box = document.getElementById('div_glowscreen32Firmware')
  var firmware = (_state && _state.firmware) ? _state.firmware : null

  if (box !== null) {
    if (firmware === null) {
      box.innerHTML = '<span class="form-control-static">{{Aucun firmware déposé.}}</span>'
    } else {
      var rows = ''
      rows += '<tr><td style="width:120px;">{{Version}}</td><td><b>' + glowscreen32Escape(firmware.version) + '</b>'
      rows += firmware.exists ? '' : ' <span class="label label-danger">{{fichier introuvable}}</span>'
      rows += '</td></tr>'
      rows += '<tr><td>{{Fichier}}</td><td><code>' + glowscreen32Escape(firmware.file) + '</code> — '
        + glowscreen32Escape(firmware.human) + ' (' + glowscreen32Escape(firmware.size) + ' {{octets}})</td></tr>'
      rows += '<tr><td>{{SHA-256}}</td><td><code style="word-break:break-all;font-size:11px;">'
        + glowscreen32Escape(firmware.sha256) + '</code></td></tr>'
      rows += '<tr><td>{{URL}}</td><td><code style="word-break:break-all;font-size:11px;">'
        + glowscreen32Escape(firmware.url) + '</code></td></tr>'
      rows += '<tr><td>{{Déposé le}}</td><td>' + glowscreen32Escape(firmware.date) + '</td></tr>'
      box.innerHTML = '<table class="table table-condensed" style="margin:0;max-width:760px;">' + rows + '</table>'
    }
  }

  var remove = document.getElementById('bt_glowscreen32FirmwareRemove')
  if (remove !== null) { remove.style.display = (firmware === null) ? 'none' : '' }
}

/* L'état des verrous, partout où il se lit : l'interrupteur global, son
   étiquette, et la colonne « Verrou OTA » du tableau du parc.

   La colonne est recalculée, et non recopiée : ce qu'elle montre est le ET des
   DEUX verrous, c'est-à-dire ce que la carte recevra — pas ce qui est coché
   quelque part. Fermer l'interrupteur global doit faire basculer d'un coup
   toutes les lignes du tableau, sinon on croirait avoir arrêté la propagation
   alors qu'on regarde un affichage périmé. */
function glowscreen32RenderOta(_state) {
  if (!_state) { return }

  var checkbox = document.getElementById('cb_glowscreen32Ota')
  if (checkbox !== null) { checkbox.checked = (_state.enabled === true) }

  var badge = document.getElementById('span_glowscreen32OtaState')
  if (badge !== null) {
    badge.textContent = _state.enabled ? '{{ouvert}}' : '{{fermé}}'
    badge.className = 'label ' + (_state.enabled ? 'label-success' : 'label-default')
  }

  var body = document.getElementById('tbody_glowscreen32Fleet')
  if (body !== null) {
    var rows = body.querySelectorAll('tr')
    for (var index = 0; index < rows.length; index++) {
      var cell = rows[index].querySelector('.glowscreen32OtaCell')
      if (cell === null) { continue }
      var allowed = rows[index].getAttribute('data-gs-ota-allowed') === '1'
      if (!allowed) {
        cell.innerHTML = '<span class="label label-default">{{fermé (écran)}}</span>'
      } else if (!_state.enabled) {
        cell.innerHTML = '<span class="label label-default">{{fermé (global)}}</span>'
      } else {
        cell.innerHTML = '<span class="label label-success">{{ouvert}}</span>'
      }
    }
  }

  glowscreen32RenderFirmware(_state)
}

/* Le dépôt d'un firmware passe par FormData et fetch, et non par
   glowscreen32Ajax() : un fichier ne se transporte pas dans un corps encodé en
   formulaire classique. Le reste — la forme { state, result } de la réponse,
   les alertes — est identique au reste de la page. */
function glowscreen32UploadFirmware(_button) {
  var input = document.getElementById('in_glowscreen32FirmwareFile')
  if (input === null || input.files.length === 0) {
    jeedomUtils.showAlert({ message: '{{Choisissez d\'abord le fichier .bin à déposer.}}', level: 'warning' })
    return
  }

  var release = function () {
    _button.removeAttribute('disabled')
    _button.classList.remove('disabled')
  }
  _button.setAttribute('disabled', 'disabled')
  _button.classList.add('disabled')

  var payload = new FormData()
  payload.append('action', 'firmwareupload')
  payload.append('firmware', input.files[0])

  fetch('plugins/glowscreen32/core/ajax/glowscreen32.ajax.php', {
    method: 'POST',
    body: payload,
    credentials: 'same-origin'
  }).then(function (response) {
    return response.json()
  }).then(function (data) {
    release()
    if (data.state != 'ok') {
      jeedomUtils.showAlert({ message: data.result, level: 'danger' })
      return
    }
    input.value = ''
    glowscreen32RenderOta(data.result)
    jeedomUtils.showAlert({
      message: '{{Firmware déposé. Il ne part nulle part tant que les deux verrous ne sont pas ouverts.}}',
      level: 'success'
    })
  }).catch(function () {
    release()
    jeedomUtils.showAlert({ message: '{{Le dépôt du firmware a échoué.}}', level: 'danger' })
  })
}

/* ================================================================ ÉCOUTES */

/* Les pages sont chargées en AJAX : DOMContentLoaded a déjà eu lieu quand ce
   script s'exécute. Les écouteurs sont donc posés à la racine, tout de suite.
   La garde évite qu'une absence du conteneur ne casse tout le fichier. */
var glowscreen32Container = document.getElementById('div_pageContainer') || document.body

glowscreen32Container.addEventListener('input', function (event) {
  /* L'adresse MAC est une .eqLogicAttr : le coeur la suit déjà, il n'y a que
     l'exemple d'appel à remettre à jour. */
  if (event.target.closest('.eqLogicAttr[data-l2key="mac"]')) {
    glowscreen32ShowApi(event.target.value)
    return
  }
  /* Les champs d'un bouton ne sont pas des .eqLogicAttr : le coeur ne les voit
     pas, et sans cela on quitterait la page en perdant une mise en page tout
     juste composée, sans le moindre avertissement. */
  if (glowscreen32ButtonEdited(event.target)) {
    glowscreen32MarkModified()
    glowscreen32RenderPreview()
  }
})

glowscreen32Container.addEventListener('change', function (event) {
  /* Le verrou global prend effet TOUT DE SUITE, sans passer par un bouton
     « Sauvegarder » : c'est un arrêt d'urgence, et un arrêt d'urgence qui
     demande une confirmation n'en est pas un. La case est remise à ce que le
     serveur répond, jamais à ce que l'on vient de cocher. */
  if (event.target.closest('#cb_glowscreen32Ota')) {
    var wanted = event.target.checked ? 1 : 0
    glowscreen32Ajax('otaglobal', { enabled: wanted }, function (result) {
      glowscreen32RenderOta(result)
      jeedomUtils.showAlert({
        message: result.enabled
          ? '{{Verrou global ouvert. Seuls les écrans dont le verrou individuel est ouvert recevront la mise à jour.}}'
          : '{{Verrou global fermé. Plus aucun écran ne recevra de mise à jour.}}',
        level: result.enabled ? 'warning' : 'success'
      })
    })
    return
  }

  /* La grille : elle change le nombre de cases de CHAQUE page, il faut donc
     recopier la page courante avant de redessiner, sinon la saisie en cours
     disparaît avec les anciens blocs. */
  if (event.target.closest('#sel_glowscreen32Grid')) {
    glowscreen32CollectPage()
    var parts = String(event.target.value).split('x')
    var cols = parseInt(parts[0], 10)
    var rows = parseInt(parts[1], 10)
    glowscreen32Model.grid = {
      cols: (cols === 3 || cols === 4) ? cols : GLOWSCREEN32_LIMITS.defaultCols,
      rows: (rows === 2 || rows === 3) ? rows : GLOWSCREEN32_LIMITS.defaultRows
    }
    glowscreen32MarkModified()
    glowscreen32RenderPage()
    return
  }

  /* Le titre d'une page et sa page parente : ils changent le nom des onglets et
     celui des listes de navigation, donc tout l'onglet est redessiné. */
  if (event.target.closest('#in_glowscreen32PageTitle')
   || event.target.closest('#sel_glowscreen32PageParent')) {
    glowscreen32MarkModified()
    glowscreen32RenderPage()
    return
  }

  var block = event.target.closest('.glowscreen32Button')
  if (block === null) { return }

  if (event.target.closest('.glowscreen32Mode')) {
    glowscreen32ShowMode(block)
  } else if (event.target.closest('.glowscreen32Target')) {
    glowscreen32ShowTarget(block)
  }
  if (glowscreen32ButtonEdited(event.target)) {
    glowscreen32MarkModified()
    glowscreen32RenderPreview()
  }
})

glowscreen32Container.addEventListener('click', function (event) {
  var target = null

  /* Les onglets de page. Ce ne sont pas des onglets Bootstrap : le DOM ne porte
     qu'une page à la fois, et c'est le JS qui recopie puis redessine. */
  if (target = event.target.closest('.glowscreen32PageTab')) {
    event.preventDefault()
    glowscreen32ShowPage(parseInt(target.getAttribute('data-gs-page'), 10))
    return
  }

  /* La commande du bandeau. Filtrée sur les commandes d'INFORMATION : le
     bandeau affiche ce qui DIT quelque chose, jamais ce qui fait quelque
     chose. */
  if (target = event.target.closest('#bt_glowscreen32InfoPick')) {
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      var field = document.querySelector('.eqLogicAttr[data-l2key="info_cmd"]')
      if (field === null) { return }
      field.jeeValue(result.human)
      glowscreen32MarkModified()
    })
    return
  }

  if (target = event.target.closest('#bt_glowscreen32FirmwareUpload')) {
    if (target.classList.contains('disabled')) { return }
    glowscreen32UploadFirmware(target)
    return
  }

  if (target = event.target.closest('#bt_glowscreen32FirmwareRemove')) {
    if (target.classList.contains('disabled')) { return }
    var button = target
    /* Une confirmation, ici : retirer le firmware n'est pas destructeur pour
       les écrans — ils gardent le leur — mais le binaire, lui, est effacé, et
       il faut alors le retrouver dans la chaîne de compilation. */
    jeeDialog.confirm('{{Retirer le firmware déposé ? Le binaire sera effacé du serveur ; les écrans déjà mis à jour ne sont pas touchés.}}', function (confirmed) {
      if (!confirmed) { return }
      glowscreen32Ajax('firmwareremove', {}, function (result) {
        glowscreen32RenderOta(result)
        jeedomUtils.showAlert({ message: '{{Firmware retiré.}}', level: 'success' })
      }, button)
    })
    return
  }

  if (target = event.target.closest('.glowscreen32Pick')) {
    var block = target.closest('.glowscreen32Button')
    var field = target.getAttribute('data-field')
    /* Type « action » pour ce qui FAIT quelque chose, type « info » pour ce qui
       DIT quelque chose : le sélecteur ne propose que ce qui convient au champ,
       plutôt que de laisser découvrir le contresens à l'enregistrement. */
    var cmdType = target.getAttribute('data-cmdtype')
    /* Le sélecteur rappelle avec { human: '#[Objet][Équipement][Commande]#' }.
       C'est cette forme qui est posée dans le champ ; le coeur la convertit en
       identifiant à l'enregistrement (jeedom::fromHumanReadable), si bien qu'un
       renommage ultérieur ne casse pas le bouton. */
    jeedom.cmd.getSelectModal({ cmd: { type: cmdType } }, function (result) {
      block.querySelector('.glowscreen32ButtonAttr[data-l1key="' + field + '"]').jeeValue(result.human)
      glowscreen32MarkModified()
      glowscreen32RenderPreview()
    })
    return
  }

  if (target = event.target.closest('#bt_glowscreen32Preview')) {
    if (target.classList.contains('disabled')) { return }
    var idInput = document.querySelector('.eqLogicAttr[data-l1key="id"]')
    if (idInput === null || idInput.value === '') {
      jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord l\'écran.}}', level: 'warning' })
      return
    }
    /* La réponse est celle de la configuration ENREGISTRÉE : c'est le but. Une
       commande choisie il y a dix secondes et pas encore sauvegardée n'est pas
       celle que la carte recevrait, et c'est précisément ce qu'on vient
       vérifier ici. */
    glowscreen32Ajax('preview', { id: idInput.value }, function (result) {
      var payload = document.getElementById('pre_glowscreen32Payload')
      payload.style.display = ''
      /* Les DEUX réponses, l'une sous l'autre : c'est la seule façon de voir ce
         que reçoit une carte restée en schéma 1 — six boutons renumérotés, sans
         les boutons de navigation — et de vérifier qu'un id n'y désigne pas le
         même bouton que dans le schéma 2. */
      payload.textContent = '// X-GLOWSCREEN32-SCHEMA: 2\n'
        + JSON.stringify(result.layout, null, 2)
        + '\n\n// {{sans en-tête de schéma — une carte antérieure à la v2}}\n'
        + JSON.stringify(result.legacy, null, 2)
    }, target)
    return
  }
})
