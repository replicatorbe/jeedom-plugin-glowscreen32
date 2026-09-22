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

  /* Le mode d'abord : il commande tout le reste de la ligne. Un bouton qui
     n'allume que, parce qu'il est câblé sur la seule commande « Allumer »,
     était le défaut le plus visible de la version précédente. */
  div += '<div class="form-group" style="margin:0 0 6px 0;">'
  div += '<label class="col-sm-3 control-label">{{Mode}}</label>'
  div += '<div class="col-sm-4">'
  div += '<select class="glowscreen32ButtonAttr form-control input-sm glowscreen32Mode" data-l1key="mode">'
  div += '<option value="action">{{Action simple}}</option>'
  div += '<option value="toggle">{{Interrupteur (allumer / éteindre)}}</option>'
  div += '</select>'
  div += '</div>'
  div += '<div class="col-sm-5">'
  div += '<span class="help-block glowscreen32ModeHelp" style="margin:0;"></span>'
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

  /* --- L'état, commun aux deux modes ------------------------------------ */
  div += '<div class="form-group" style="margin:6px 0 0 0;">'
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
    icon: init(button.icon, ''),
    /* Un bouton enregistré avant le mode vaut « action » : c'est exactement ce
       qu'il faisait, et aucune configuration existante ne change de sens. */
    mode: (init(button.mode, 'action') === 'toggle') ? 'toggle' : 'action',
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

/* Montre les champs du mode choisi, et masque ceux de l'autre. Un formulaire
   qui affiche « Allumer », « Éteindre » ET « Déclenche » laisse croire qu'il
   faut tout remplir, et l'utilisateur remplit alors celui qui ne sert pas. */
function glowscreen32ShowMode(_block) {
  var mode = _block.querySelector('.glowscreen32Mode').value
  var toggle = (mode === 'toggle')

  _block.querySelector('.glowscreen32ModeAction').style.display = toggle ? 'none' : ''
  _block.querySelector('.glowscreen32ModeToggle').style.display = toggle ? '' : 'none'
  _block.querySelector('.glowscreen32StateAction').style.display = toggle ? 'none' : ''
  _block.querySelector('.glowscreen32StateToggle').style.display = toggle ? '' : 'none'

  _block.querySelector('.glowscreen32ModeHelp').textContent = toggle
    ? '{{Le plugin lit l\'état, puis joue la commande inverse : un appui allume, le suivant éteint.}}'
    : '{{Une commande d\'action ou un scénario, joué tel quel à chaque appui. Pour un relais impulsionnel, un portail, une scène.}}'

  _block.querySelector('.glowscreen32StateHelp').textContent = toggle
    ? '{{Obligatoire : sans état, rien ne permet de décider s\'il faut allumer ou éteindre. La sauvegarde est refusée.}}'
    : '{{Facultatif. Laissé vide, le plugin reprend l\'état que Jeedom associe déjà à la commande d\'action.}}'

  if (!toggle) { glowscreen32ShowTarget(_block) }
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
      mode: (init(button.mode, 'action') === 'toggle') ? 'toggle' : 'action',
      target: init(button.target, ''),
      cmd: init(button.cmd, ''),
      scenario: parseInt(init(button.scenario, 0), 10) || 0,
      on: init(button.on, ''),
      off: init(button.off, ''),
      toggle: init(button.toggle, ''),
      state: init(button.state, '')
    })
  }
  return buttons
}

/* Ce bouton sera-t-il envoyé à la carte ? Le même filtre que activeButtons()
   côté plugin, aux commandes supprimées près, que le navigateur ne peut pas
   connaître — c'est à cela que sert « Voir ce que la carte reçoit ». */
function glowscreen32ButtonDrawn(_button) {
  if (_button.mode === 'toggle') {
    return _button.state !== '' && (_button.on !== '' || _button.off !== '' || _button.toggle !== '')
  }
  if (_button.target === 'cmd') { return _button.cmd !== '' }
  if (_button.target === 'scenario') { return _button.scenario > 0 }
  return false
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
    if (glowscreen32ButtonDrawn(buttons[i])) { drawn.push(buttons[i]) }
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
    /* Le rang du contrat v1.3, celui que la carte renverra à « press ». Le
       montrer ici évite d'avoir à le compter à la main quand on essaie un
       bouton au curl. */
    var rank = document.createElement('div')
    rank.className = 'glowscreen32TileRank'
    rank.textContent = 'id ' + slot + (drawn[slot].mode === 'toggle' ? ' ⇄' : '')
    tile.appendChild(rank)
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

  glowscreen32RenderButtons(_eqLogic)
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
      payload.textContent = JSON.stringify(result.layout, null, 2)
    }, target)
    return
  }
})
