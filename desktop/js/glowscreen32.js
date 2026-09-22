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

/* Les six emplacements de la grille 3x2. La limite est celle du contrat d'API,
   pas une préférence d'affichage : le firmware ne sait pas en dessiner plus. */
var GLOWSCREEN32_MAX_BUTTONS = 6
var GLOWSCREEN32_DEFAULT_COLOR = '#2d7ff9'

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
  return _target.closest('#div_glowscreen32Buttons') !== null
      && !glowscreen32Rendering
      && _target.isVisible()
}

/* ============================================================== BOUTONS */

/*
 * Un emplacement de la grille. Les six sont toujours dessinés, remplis ou non :
 * une liste qui s'allonge quand on clique sur « ajouter » ferait croire qu'on
 * peut en mettre sept, et masquerait que la position compte — le premier bouton
 * est en haut à gauche de l'écran, le quatrième dessous.
 */
function glowscreen32AddButton(_rank, _button) {
  var container = document.getElementById('div_glowscreen32Buttons')
  if (container === null) { return null }
  var button = _button || {}

  var div = '<div class="glowscreen32Button">'
  div += '<div class="form-group" style="margin:0 0 6px 0;">'
  div += '<label class="col-sm-3 control-label glowscreen32ButtonRank">{{Bouton}} ' + _rank + '</label>'
  div += '<div class="col-sm-5">'
  div += '<input type="text" class="glowscreen32ButtonAttr form-control input-sm" data-l1key="label" placeholder="{{Libellé}}" maxlength="24">'
  div += '</div>'
  div += '<div class="col-sm-2">'
  div += '<input type="color" class="glowscreen32ButtonAttr form-control input-sm" data-l1key="color" title="{{Couleur du bouton sur l\'écran}}">'
  div += '</div>'
  div += '<div class="col-sm-2">'
  div += '<input type="text" class="glowscreen32ButtonAttr form-control input-sm" data-l1key="icon" placeholder="{{Icône}}" maxlength="24" title="{{Nom d\'icône que le firmware saura dessiner : bulb, movie, fan... Texte libre en v1.}}">'
  div += '</div>'
  div += '</div>'

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
  div += '<div class="input-group">'
  div += '<input class="glowscreen32ButtonAttr form-control input-sm roundedLeft" data-l1key="cmd" placeholder="{{Commande à déclencher}}">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm roundedRight glowscreen32ListCmd" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>'
  div += '</span>'
  div += '</div>'
  div += '</div>'

  div += '<div class="col-sm-6 glowscreen32TargetScenario">'
  div += '<select class="glowscreen32ButtonAttr form-control input-sm" data-l1key="scenario"></select>'
  div += '</div>'
  div += '</div>'

  /* La commande d'état, facultative. C'est elle que la carte lit à chaque
     sondage pour savoir si la pastille du bouton doit être allumée. */
  div += '<div class="form-group" style="margin:6px 0 0 0;">'
  div += '<label class="col-sm-3 control-label">{{Pastille allumée si}}</label>'
  div += '<div class="col-sm-9">'
  div += '<div class="input-group">'
  div += '<input class="glowscreen32ButtonAttr form-control input-sm roundedLeft" data-l1key="state" placeholder="{{Commande d\'information binaire — facultatif}}">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm roundedRight glowscreen32ListState" title="{{Choisir la commande d\'état}}"><i class="fas fa-list-alt"></i></a>'
  div += '</span>'
  div += '</div>'
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
    icon: init(button.icon, ''),
    target: init(button.target, ''),
    cmd: init(button.cmd, ''),
    state: init(button.state, '')
  }, '.glowscreen32ButtonAttr')

  glowscreen32ShowTarget(block)
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

/* Les six emplacements, dans l'ordre. */
function glowscreen32RenderButtons(_eqLogic) {
  var container = document.getElementById('div_glowscreen32Buttons')
  if (container === null) { return }

  glowscreen32Rendering = true
  container.innerHTML = ''

  var configuration = init(_eqLogic.configuration, {})
  var buttons = init(configuration.buttons, [])
  if (!Array.isArray(buttons)) { buttons = [] }

  for (var i = 0; i < GLOWSCREEN32_MAX_BUTTONS; i++) {
    glowscreen32AddButton(i + 1, init(buttons[i], {}))
  }
  glowscreen32Rendering = false
  glowscreen32RenderPreview()
}

/* Ce que porte chaque emplacement, dans l'ordre de l'écran. */
function glowscreen32CollectButtons() {
  var buttons = []
  var blocks = document.querySelectorAll('#div_glowscreen32Buttons .glowscreen32Button')
  for (var i = 0; i < blocks.length && i < GLOWSCREEN32_MAX_BUTTONS; i++) {
    var button = blocks[i].getJeeValues('.glowscreen32ButtonAttr')[0]
    buttons.push({
      label: init(button.label, ''),
      color: glowscreen32Color(button.color),
      icon: init(button.icon, ''),
      target: init(button.target, ''),
      cmd: init(button.cmd, ''),
      scenario: parseInt(init(button.scenario, 0), 10) || 0,
      state: init(button.state, '')
    })
  }
  return buttons
}

/*
 * L'aperçu de l'écran, reconstruit à chaque frappe. Il montre la grille telle
 * que la carte la dessinera : les emplacements sans cible n'y apparaissent pas,
 * et les suivants remontent — c'est exactement ce que fait layout(), et c'est
 * la seule façon de s'en rendre compte avant de regarder le mur.
 */
function glowscreen32RenderPreview() {
  var grid = document.getElementById('div_glowscreen32Preview')
  if (grid === null) { return }
  grid.innerHTML = ''

  var buttons = glowscreen32CollectButtons()
  var drawn = []
  for (var i = 0; i < buttons.length; i++) {
    if (buttons[i].target === 'cmd' && buttons[i].cmd !== '') { drawn.push(buttons[i]) }
    else if (buttons[i].target === 'scenario' && buttons[i].scenario > 0) { drawn.push(buttons[i]) }
  }

  for (var slot = 0; slot < GLOWSCREEN32_MAX_BUTTONS; slot++) {
    var tile = document.createElement('div')
    tile.className = 'glowscreen32Tile'
    if (slot >= drawn.length) {
      tile.classList.add('glowscreen32TileEmpty')
      tile.textContent = '·'
      grid.appendChild(tile)
      continue
    }
    tile.style.background = drawn[slot].color
    if (drawn[slot].icon !== '') {
      var icon = document.createElement('div')
      icon.className = 'glowscreen32TileIcon'
      icon.textContent = drawn[slot].icon
      tile.appendChild(icon)
    }
    var label = document.createElement('div')
    /* textContent et non innerHTML : le libellé est une saisie libre, et il est
       relu ici à chaque frappe. */
    label.textContent = (drawn[slot].label !== '') ? drawn[slot].label : '…'
    tile.appendChild(label)
    grid.appendChild(tile)
  }
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
  var version = document.getElementById('span_glowscreen32Version')
  if (version !== null) {
    version.textContent = (isset(_eqLogic.id) && _eqLogic.id != '') ? init(configuration.version, 1) : '-'
  }

  glowscreen32RenderButtons(_eqLogic)
  glowscreen32ShowApi(init(configuration.mac, ''))
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
  _eqLogic.configuration.buttons = glowscreen32CollectButtons()
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
  var block = event.target.closest('.glowscreen32Button')
  if (block === null) { return }

  if (event.target.closest('.glowscreen32Target')) {
    glowscreen32ShowTarget(block)
  }
  if (glowscreen32ButtonEdited(event.target)) {
    glowscreen32MarkModified()
    glowscreen32RenderPreview()
  }
})

glowscreen32Container.addEventListener('click', function (event) {
  var target = null

  if (target = event.target.closest('.glowscreen32ListCmd')) {
    var block = target.closest('.glowscreen32Button')
    /* Le sélecteur rappelle avec { human: '#[Objet][Équipement][Commande]#' }.
       C'est cette forme qui est posée dans le champ ; le coeur la convertit en
       identifiant à l'enregistrement (jeedom::fromHumanReadable), si bien qu'un
       renommage ultérieur ne casse pas le bouton. */
    jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
      block.querySelector('.glowscreen32ButtonAttr[data-l1key="cmd"]').jeeValue(result.human)
      glowscreen32MarkModified()
      glowscreen32RenderPreview()
    })
    return
  }

  if (target = event.target.closest('.glowscreen32ListState')) {
    var stateBlock = target.closest('.glowscreen32Button')
    /* Type info, et non action : la commande d'état est celle qui DIT quelque
       chose, pas celle qui fait quelque chose. Le firmware n'allume la pastille
       que sur un sous-type binaire — le plugin rend null pour tout le reste
       plutôt que d'afficher une consigne de température comme un interrupteur. */
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      stateBlock.querySelector('.glowscreen32ButtonAttr[data-l1key="state"]').jeeValue(result.human)
      glowscreen32MarkModified()
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
      payload.textContent = JSON.stringify(result.layout, null, 2)
    }, target)
    return
  }
})
