/* Legends Global — New Hire Onboarding front-end controller (v2) */
(function () {
  'use strict';

  var MAX_FILE = 8 * 1024 * 1024;
  var MAX_TOTAL = 18 * 1024 * 1024;

  var body = document.body;
  var LG = {
    turnstile: body.getAttribute('data-turnstile') === '1',
    test: body.getAttribute('data-test') === '1',
    steps: JSON.parse(body.getAttribute('data-steps') || '[]'),
    banks: JSON.parse(body.getAttribute('data-banks') || '{}'),
    today: body.getAttribute('data-today') || ''
  };

  var form = document.getElementById('onboarding-form');
  if (!form) return;
  var steps = Array.prototype.slice.call(form.querySelectorAll('.step'));
  var total = steps.length;
  var current = 0, submitted = false, dirty = false;

  var btnBack = document.getElementById('btnBack');
  var btnNext = document.getElementById('btnNext');
  var btnSubmit = document.getElementById('btnSubmit');
  var progressBar = document.getElementById('progressBar');
  var stepName = document.getElementById('stepName');
  var stepCount = document.getElementById('stepCount');

  // ---------------------------------------------------------------- utils
  function $(s, c) { return (c || document).querySelector(s); }
  function $all(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
  function digits(s) { return (s || '').replace(/\D+/g, ''); }
  function isVisible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }
  function humanSize(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : (b >= 1024 ? Math.round(b / 1024) + ' KB' : b + ' B'); }
  function luhn(d) { if (!/^\d+$/.test(d)) return false; var s = 0, a = false; for (var i = d.length - 1; i >= 0; i--) { var n = +d[i]; if (a) { n *= 2; if (n > 9) n -= 9; } s += n; a = !a; } return s % 10 === 0; }
  function ageFrom(dob) { if (!/^\d{4}-\d{2}-\d{2}$/.test(dob)) return null; var d = new Date(dob + 'T00:00:00'), t = new Date(); var a = t.getFullYear() - d.getFullYear(); var m = t.getMonth() - d.getMonth(); if (m < 0 || (m === 0 && t.getDate() < d.getDate())) a--; return a; }

  function fmtPhone(v) {
    var d = digits(v); if (d.length === 11 && d[0] === '1') d = d.slice(1); d = d.slice(0, 10);
    if (!d.length) return '';
    if (d.length < 4) return '+1 (' + d;
    if (d.length < 7) return '+1 (' + d.slice(0, 3) + ') ' + d.slice(3);
    return '+1 (' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6);
  }

  var validators = {
    name: function (v) { return /^[\p{L}][\p{L}\p{M}\s'.\-]*$/u.test(v) ? '' : 'Please use letters only.'; },
    email: function (v) { return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v) ? '' : 'Enter a valid email address.'; },
    tel: function (v) { var d = digits(v); return (d.length >= 10 && d.length <= 15) ? '' : 'Enter a valid phone number.'; },
    postal: function (v) { return /^[A-Za-z]\d[A-Za-z]\s?\d[A-Za-z]\d$/.test(v) ? '' : 'Enter a valid postal code (e.g. K1A 0B1).'; },
    date: function (v) { return /^\d{4}-\d{2}-\d{2}$/.test(v) ? '' : 'Enter a valid date.'; },
    sin: function (v) { var d = digits(v); return (d.length === 9 && luhn(d)) ? '' : 'Enter a valid 9-digit SIN.'; },
    digits3: function (v) { return digits(v).length === 3 ? '' : 'Must be 3 digits.'; },
    digits5: function (v) { return digits(v).length === 5 ? '' : 'Must be 5 digits.'; },
    account: function (v) { var d = digits(v); return (d.length >= 5 && d.length <= 12) ? '' : 'Enter a valid account number (5–12 digits).'; }
  };

  // ---------------------------------------------------------------- errors
  function errElFor(input) {
    if (input.hasAttribute('data-cf')) { var f = input.closest('.field'); return f ? f.querySelector('[data-err]') : null; }
    var id = input.id || ('f_' + input.name);
    return document.getElementById(id + '-err') || document.getElementById('f_' + input.name + '-err');
  }
  function setError(input, msg) {
    var field = input.closest('.field') || input.closest('.avail-day') || input.closest('.contact') || input.parentElement;
    var el = errElFor(input);
    if (msg) { if (field) field.classList.add('invalid'); if (el) { el.textContent = msg; el.classList.add('show'); } }
    else { if (field) field.classList.remove('invalid'); if (el) { el.textContent = ''; el.classList.remove('show'); } }
  }
  function validateInput(input) {
    if (input.disabled || !isVisible(input)) return true;
    var required = input.getAttribute('data-required') === '1';
    if (input.type === 'checkbox') { if (required && !input.checked) { setError(input, 'This is required.'); return false; } setError(input, ''); return true; }
    if (input.type === 'radio') { return true; } // handled per-group
    if (input.type === 'file') {
      if (required && !input.files.length) { setError(input, 'Please upload this file.'); return false; }
      if (input.files[0] && input.files[0].size > MAX_FILE) { setError(input, 'File is too large (max ' + humanSize(MAX_FILE) + ').'); return false; }
      setError(input, ''); return true;
    }
    var val = (input.value || '').trim();
    if (val === '') { if (required) { setError(input, 'This field is required.'); return false; } setError(input, ''); return true; }
    var rule = input.getAttribute('data-validate');
    if (rule && validators[rule]) { var m = validators[rule](val); if (m) { setError(input, m); return false; } }
    setError(input, ''); return true;
  }
  function radioGroupOk(name) {
    var els = $all('input[name="' + name + '"]', form);
    if (!els.length || els[0].getAttribute('data-required') !== '1') return true;
    var checked = els.some(function (r) { return r.checked; });
    var errId = 'f_' + name + '-err', el = document.getElementById(errId);
    if (!checked) { if (el) { el.textContent = 'Please choose an option.'; el.classList.add('show'); } return false; }
    if (el) { el.textContent = ''; el.classList.remove('show'); } return true;
  }

  // ---------------------------------------------------------------- contacts
  var contactsWrap = document.getElementById('contacts');
  var contactTpl = document.getElementById('contact-template');
  function reindexContacts() {
    $all('.contact', contactsWrap).forEach(function (c, i) {
      c.setAttribute('data-contact', i);
      c.querySelector('legend').firstChild.textContent = (i === 0 ? 'Primary Contact ' : 'Additional Contact ');
      c.querySelector('.remove-contact').hidden = (i === 0);
      $all('[name^="contacts["]', c).forEach(function (inp) {
        inp.name = inp.name.replace(/contacts\[\d*\]/, 'contacts[' + i + ']').replace(/contacts\[__I__\]/, 'contacts[' + i + ']');
      });
    });
  }
  if (document.getElementById('addContact')) {
    document.getElementById('addContact').addEventListener('click', function () {
      if ($all('.contact', contactsWrap).length >= 4) return;
      var node = contactTpl.content.cloneNode(true);
      contactsWrap.appendChild(node);
      reindexContacts();
      wireContact(contactsWrap.lastElementChild);
    });
  }
  function wireContact(c) {
    var rel = c.querySelector('[data-rel]');
    if (rel) rel.addEventListener('change', function () { c.querySelector('.rel-other').hidden = rel.value !== 'other'; c.querySelector('.rel-other input').setAttribute('data-required', rel.value === 'other' ? '1' : ''); });
    var rm = c.querySelector('.remove-contact');
    if (rm) rm.addEventListener('click', function () { c.remove(); reindexContacts(); });
    $all('[data-phone]', c).forEach(attachPhone);
  }
  $all('.contact', contactsWrap).forEach(wireContact);

  function validateContacts(silent) {
    var ok = true, firstBad = null;
    var ownPhones = ['mobile_phone', 'home_phone', 'other_phone'].map(function (n) { return digits((form.elements[n] || {}).value || ''); }).filter(Boolean);
    var ownEmails = ['primary_email', 'secondary_email'].map(function (n) { return ((form.elements[n] || {}).value || '').trim().toLowerCase(); }).filter(Boolean);
    var blocks = $all('.contact', contactsWrap);
    blocks.forEach(function (c, i) {
      var req = ['first_name', 'last_name', 'relationship', 'phone', 'phone_device', 'phone_location', 'email', 'email_location'];
      req.forEach(function (fk) {
        var inp = c.querySelector('[data-cf="' + fk + '"]');
        if (!inp) return;
        var v = (inp.value || '').trim();
        var el = c.querySelector('[data-err="' + fk + '"]');
        var bad = '';
        if (v === '') bad = 'Required.';
        else if (inp.getAttribute('data-validate') && validators[inp.getAttribute('data-validate')]) bad = validators[inp.getAttribute('data-validate')](v);
        if (fk === 'phone' && v && ownPhones.indexOf(digits(v)) >= 0) bad = "Matches your own number — use someone else's.";
        if (fk === 'email' && v && ownEmails.indexOf(v.toLowerCase()) >= 0) bad = "Matches your own email — use someone else's.";
        if (el) { el.textContent = bad; el.classList.toggle('show', !!bad); }
        var fld = inp.closest('.field'); if (fld) fld.classList.toggle('invalid', !!bad);
        if (bad) { ok = false; if (!firstBad) firstBad = inp; }
      });
      var rel = c.querySelector('[data-rel]');
      if (rel && rel.value === 'other') {
        var ro = c.querySelector('[data-cf="relationship_other"]');
        if (!(ro.value || '').trim()) { var e2 = c.querySelector('[data-err="relationship_other"]'); if (e2) { e2.textContent = 'Please specify.'; e2.classList.add('show'); } ok = false; if (!firstBad) firstBad = ro; }
      }
    });
    if (!ok && !silent && firstBad) { firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' }); firstBad.focus && firstBad.focus(); }
    return ok;
  }

  // ---------------------------------------------------------------- phone format
  function attachPhone(inp) {
    inp.addEventListener('input', function () { var p = inp.selectionStart === inp.value.length; inp.value = fmtPhone(inp.value); });
  }
  $all('[data-phone]', form).forEach(attachPhone);

  // ---------------------------------------------------------------- pronouns
  var pron = form.querySelector('[data-pronouns]');
  if (pron) {
    var pronWrap = form.querySelector('[data-pronouns-other]');       // the .field wrapper
    var pronInput = pronWrap ? pronWrap.querySelector('input') : null;
    pron.addEventListener('change', function () {
      var other = pron.value === 'other';
      pronWrap.hidden = !other;
      if (pronInput) { if (other) pronInput.setAttribute('data-required', '1'); else { pronInput.removeAttribute('data-required'); pronInput.value = ''; setError(pronInput, ''); } }
    });
  }

  // ---------------------------------------------------------------- yes/no reveals
  $all('[data-reveal]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      var name = radio.name, target = radio.getAttribute('data-reveal');
      var show = form.querySelector('input[name="' + name + '"]:checked');
      var rev = form.querySelector('[data-reveal-for="' + target + '"]');
      var on = show && show.value === 'yes';
      if (rev) { rev.hidden = !on; var t = form.elements[target]; if (t) { t.setAttribute('data-required', on ? '1' : ''); if (!on) { t.value = ''; setError(t, ''); } } }
    });
  });

  // ---------------------------------------------------------------- availability days
  $all('[data-day-toggle]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var day = cb.closest('.avail-day'), times = day ? day.querySelector('.daytimes') : null;
      if (!times) return;
      times.hidden = !cb.checked;
      if (!cb.checked) {
        $all('input', times).forEach(function (t) { t.value = ''; setError(t, ''); });
      }
      var ae = document.getElementById('availability-err');
      if (cb.checked && ae) ae.classList.remove('show');
    });
  });

  // ---------------------------------------------------------------- SIN / permit / IRCC
  var sinInput = form.querySelector('[data-sin]');
  var permitBlock = document.getElementById('permit-block');
  var irccBlock = document.getElementById('ircc-block');
  var permitFields = ['permit_type', 'permit_number', 'permit_issued', 'permit_expiry'];
  function refreshSin() {
    if (!sinInput) return;
    var nine = digits(sinInput.value)[0] === '9';
    permitBlock.hidden = !nine;
    ['sin_issued', 'sin_expiry'].concat(permitFields).forEach(function (n) { var el = form.elements[n]; if (el) { if (nine) el.setAttribute('data-required', '1'); else { el.removeAttribute('data-required'); setError(el, ''); } } });
    var pd = form.elements['permit_document']; if (pd) pd.setAttribute('data-required', nine ? '1' : '');
    if (!nine) { irccBlock.hidden = true; } else { refreshPermitExpiry(); }
  }
  function refreshPermitExpiry() {
    if (permitBlock.hidden) { irccBlock.hidden = true; return; }
    var exp = (form.elements['permit_expiry'] || {}).value || '';
    var expired = /^\d{4}-\d{2}-\d{2}$/.test(exp) && exp < LG.today;
    irccBlock.hidden = !expired;
    ['ircc_letter_id', 'ircc_document'].forEach(function (n) { var el = form.elements[n]; if (el) { if (expired) el.setAttribute('data-required', '1'); else { el.removeAttribute('data-required'); setError(el, ''); } } });
  }
  if (sinInput) sinInput.addEventListener('input', refreshSin);
  var pe = form.querySelector('[data-permit-expiry]'); if (pe) pe.addEventListener('change', refreshPermitExpiry);

  // ---------------------------------------------------------------- gov id expiry
  var idType = form.querySelector('[data-idtype]');
  // renews map injected from server ids; simple: expiry required unless citizenship/status
  var noRenew = ['citizenship_card', 'indian_status'];
  function refreshIdExpiry() {
    if (!idType) return;
    var exp = form.querySelector('[data-gov-expiry]');
    var renews = idType.value && noRenew.indexOf(idType.value) < 0;
    if (exp) { if (renews) exp.setAttribute('data-required', '1'); else { exp.removeAttribute('data-required'); if (!renews) setError(exp, ''); } }
  }
  if (idType) idType.addEventListener('change', refreshIdExpiry);

  // ---------------------------------------------------------------- bank autofill
  var bankSel = form.querySelector('[data-bank]');
  var bankOtherWrap = form.querySelector('[data-bank-other]');       // the .field wrapper
  var bankOtherInput = bankOtherWrap ? bankOtherWrap.querySelector('input') : null;
  var instInput = form.querySelector('[data-institution]');
  function refreshBank() {
    if (!bankSel) return;
    var v = bankSel.value, inst = LG.banks[v];
    bankOtherWrap.hidden = v !== 'other';
    if (bankOtherInput) { if (v === 'other') bankOtherInput.setAttribute('data-required', '1'); else { bankOtherInput.removeAttribute('data-required'); bankOtherInput.value = ''; setError(bankOtherInput, ''); } }
    if (inst) { instInput.value = inst; instInput.readOnly = true; setError(instInput, ''); }
    else { if (instInput.readOnly) instInput.value = ''; instInput.readOnly = false; }
  }
  if (bankSel) bankSel.addEventListener('change', refreshBank);

  // ---------------------------------------------------------------- account confirm
  var acct = form.querySelector('[data-account]');
  var acctC = form.querySelector('[data-account-confirm]');
  function checkAcctMatch() {
    if (!acct || !acctC) return true;
    if (acctC.value && acct.value && digits(acct.value) !== digits(acctC.value)) { setError(acctC, 'The account numbers do not match.'); return false; }
    if (acctC.value) setError(acctC, '');
    return true;
  }
  if (acctC) acctC.addEventListener('input', checkAcctMatch);

  // ---------------------------------------------------------------- certifications
  function refreshCerts() {
    var age = ageFrom((form.elements['date_of_birth'] || {}).value || '');
    $all('.cert').forEach(function (c) {
      var ageGate = c.getAttribute('data-age-gated');
      if (ageGate && age !== null && age < +ageGate) { c.hidden = true; $all('input,select', c).forEach(function (i) { i.removeAttribute('data-required'); if (i.type === 'radio') i.checked = false; }); return; }
      c.hidden = false;
      var has = c.querySelector('input[data-cert-has]:checked');
      var yes = has && has.value === 'yes';
      c.querySelector('.cert-body').hidden = !yes;
      var rec = c.querySelector('.cert-recommend');
      if (rec) rec.hidden = !(ageGate && has && has.value === 'no');
      $all('.cert-body [data-required], .cert-body input,[data-required]', c);
      $all('.cert-body input, .cert-body select', c).forEach(function (i) {
        var core = /_(last_name|cert_id|issued|provider|document)$/.test(i.name);
        if (core && !/middle_name/.test(i.name)) i.setAttribute('data-required', yes ? '1' : '');
        if (!yes) { setError(i, ''); }
      });
    });
  }
  $all('input[data-cert-has]').forEach(function (r) { r.addEventListener('change', refreshCerts); });

  // ---------------------------------------------------------------- files
  function totalUpload() { var t = 0; $all('input[type=file]', form).forEach(function (i) { if (i.files[0]) t += i.files[0].size; }); return t; }
  $all('input[type=file]', form).forEach(function (inp) {
    inp.addEventListener('change', function () {
      var wrap = inp.closest('.filefield'), label = wrap ? wrap.querySelector('[data-filename]') : null, f = inp.files[0];
      if (label) { label.textContent = f ? (f.name + ' · ' + humanSize(f.size)) : ''; label.classList.toggle('toobig', f && f.size > MAX_FILE); }
      validateInput(inp);
      if (inp.name === 'headshot' && f && /image/.test(f.type)) showHeadshotPreview(f);
    });
  });
  function showHeadshotPreview(f) {
    var pv = document.getElementById('headshotPreview'); if (!pv) return;
    var img = pv.querySelector('img'); if (img.src && img.src.indexOf('blob:') === 0) URL.revokeObjectURL(img.src);
    img.src = URL.createObjectURL(f); pv.hidden = false;
  }
  function setFile(name, file) { var inp = form.elements[name]; if (!inp) return; var dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files; inp.dispatchEvent(new Event('change', { bubbles: true })); }

  // ---------------------------------------------------------------- camera
  var cam = { stream: null };
  var camStart = document.getElementById('camStart'), camCapture = document.getElementById('camCapture'), camRetake = document.getElementById('camRetake');
  var video = document.getElementById('camVideo'), canvas = document.getElementById('camCanvas'), guide = document.getElementById('camGuide'), placeholder = document.getElementById('camPlaceholder');
  if (camStart) camStart.addEventListener('click', function () {
    navigator.mediaDevices.getUserMedia({ video: { width: 720, height: 960, facingMode: 'user' } }).then(function (s) {
      cam.stream = s; video.srcObject = s; video.hidden = false; guide.hidden = false; placeholder.hidden = true; video.play();
      camStart.hidden = true; camCapture.hidden = false;
    }).catch(function () { placeholder.textContent = 'Camera unavailable — please upload a photo instead.'; });
  });
  if (camCapture) camCapture.addEventListener('click', function () {
    var w = 720, h = 960; canvas.width = w; canvas.height = h;
    var ctx = canvas.getContext('2d');
    var vw = video.videoWidth, vh = video.videoHeight, scale = Math.max(w / vw, h / vh);
    var dw = vw * scale, dh = vh * scale;
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);
    ctx.drawImage(video, (w - dw) / 2, (h - dh) / 2, dw, dh);
    canvas.toBlob(function (blob) { setFile('headshot', new File([blob], 'headshot.jpg', { type: 'image/jpeg' })); }, 'image/jpeg', 0.9);
    video.hidden = true; canvas.hidden = false; camCapture.hidden = true; camRetake.hidden = false;
    stopCam();
  });
  if (camRetake) camRetake.addEventListener('click', function () { canvas.hidden = true; camRetake.hidden = true; camStart.hidden = false; placeholder.hidden = false; });
  function stopCam() { if (cam.stream) { cam.stream.getTracks().forEach(function (t) { t.stop(); }); cam.stream = null; } }

  // ---------------------------------------------------------------- gov "same name"
  var govSame = document.getElementById('gov_same');
  if (govSame) govSame.addEventListener('change', function () { if (!govSame.checked) return; ['first_name', 'middle_name', 'last_name'].forEach(function (p) { if (form.elements['gov_' + p] && form.elements[p]) form.elements['gov_' + p].value = form.elements[p].value; }); });

  // ---------------------------------------------------------------- signature
  var pad = document.getElementById('signaturePad'), sigInput = document.getElementById('signatureInput'), hasSig = false;
  if (pad) {
    var ctx = pad.getContext('2d');
    function resetPad() { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, pad.width, pad.height); ctx.strokeStyle = '#12142a'; ctx.lineWidth = 2.4; ctx.lineJoin = 'round'; ctx.lineCap = 'round'; hasSig = false; }
    resetPad();
    var drawing = false, lx = 0, ly = 0;
    function pos(e) { var r = pad.getBoundingClientRect(), sx = pad.width / r.width, sy = pad.height / r.height; var cx = (e.touches ? e.touches[0].clientX : e.clientX) - r.left, cy = (e.touches ? e.touches[0].clientY : e.clientY) - r.top; return { x: cx * sx, y: cy * sy }; }
    function start(e) { drawing = true; var p = pos(e); lx = p.x; ly = p.y; e.preventDefault(); }
    function move(e) { if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(lx, ly); ctx.lineTo(p.x, p.y); ctx.stroke(); lx = p.x; ly = p.y; hasSig = true; e.preventDefault(); }
    function end() { drawing = false; }
    pad.addEventListener('mousedown', start); pad.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
    pad.addEventListener('touchstart', start, { passive: false }); pad.addEventListener('touchmove', move, { passive: false }); pad.addEventListener('touchend', end);
    document.getElementById('sigClear').addEventListener('click', function () { resetPad(); sigInput.value = ''; });
  }
  function signatureData() { return (pad && hasSig) ? pad.toDataURL('image/png') : ''; }

  // ---------------------------------------------------------------- validation per step
  function customStepCheck(index, stepEl, silent) {
    var bad = null, failed = false;
    function fail(el) { failed = true; if (el && !bad) bad = el; }
    // radio groups on this step
    var radioNames = {};
    $all('input[type=radio][data-required]', stepEl).forEach(function (r) { radioNames[r.name] = 1; });
    Object.keys(radioNames).forEach(function (n) { if (!radioGroupOk(n)) fail($('input[name="' + n + '"]', stepEl)); });

    if (index === 2) { if (!validateContacts(silent)) fail(null); }
    if (index === 3) {
      var any = false;
      $all('.avail-day', stepEl).forEach(function (d) {
        var k = d.getAttribute('data-day'), on = form.elements['avail_' + k + '_enabled'].checked;
        if (!on) return; any = true;
        var s = form.elements['avail_' + k + '_start'], e = form.elements['avail_' + k + '_end'];
        if (!s.value || !e.value) { setError(s, 'Enter start and end times.'); fail(s); }
        else if (e.value <= s.value) { setError(e, 'End must be after start.'); fail(e); }
      });
      if (!any) { var ae = document.getElementById('availability-err'); if (ae) { ae.textContent = 'Please select at least one day.'; ae.classList.add('show'); } fail(null); }
      else { var ae2 = document.getElementById('availability-err'); if (ae2) ae2.classList.remove('show'); }
    }
    if (index === 5) { refreshSin(); }
    if (index === 7) { if (!checkAcctMatch()) fail(acctC); }
    if (index === 10) {
      if (!signatureData()) { var se = document.getElementById('f_signature-err'); if (se) { se.textContent = 'Please sign above.'; se.classList.add('show'); } fail(pad); }
      if (LG.turnstile && !LG.test) { var t = form.querySelector('[name="cf-turnstile-response"]'), te = document.getElementById('turnstile-err'); if (!t || !t.value) { if (te) { te.textContent = 'Please complete the verification.'; te.classList.add('show'); } fail($('.turnstile-wrap')); } else if (te) te.classList.remove('show'); }
    }
    return failed ? (bad || true) : null;
  }
  function validateStep(index, silent) {
    var el = steps[index], was = el.hidden; if (was) el.hidden = false;
    var ok = true, firstBad = null;
    $all('input,select,textarea', el).forEach(function (inp) { if (!validateInput(inp)) { ok = false; if (!firstBad) firstBad = inp; } });
    var custom = customStepCheck(index, el, silent);
    if (custom) { ok = false; if (custom !== true && !firstBad) firstBad = custom; }
    if (was) el.hidden = true;
    if (!ok && !silent && firstBad && firstBad.focus) { try { firstBad.focus({ preventScroll: false }); } catch (e) { firstBad.focus(); } firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    return ok;
  }

  // ---------------------------------------------------------------- navigation
  function showStep(i) {
    current = Math.max(0, Math.min(total - 1, i));
    steps.forEach(function (s, n) { s.hidden = n !== current; });
    progressBar.style.width = ((current + 1) / total * 100) + '%';
    stepName.textContent = LG.steps[current] || ('Step ' + (current + 1));
    stepCount.textContent = 'Step ' + (current + 1) + ' of ' + total;
    btnBack.hidden = current === 0;
    var last = current === total - 1;
    btnNext.hidden = last; btnSubmit.hidden = !last;
    if (current === 9) refreshCerts();
    if (last) buildReview();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  btnNext.addEventListener('click', function () { if (validateStep(current)) showStep(current + 1); });
  btnBack.addEventListener('click', function () { showStep(current - 1); });
  form.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.type !== 'submit' && current < total - 1) { e.preventDefault(); btnNext.click(); } });
  form.addEventListener('input', function (e) { dirty = true; var f = e.target.closest('.field'); if (f && f.classList.contains('invalid')) validateInput(e.target); });

  // ---------------------------------------------------------------- review
  function val(n) { var el = form.elements[n]; if (!el) return ''; if (el.length && el[0] && el[0].type === 'radio') { var c = form.querySelector('input[name="' + n + '"]:checked'); return c ? c.value : ''; } return (el.value || '').trim(); }
  function maskSin() { var d = digits(val('sin')); return d.length === 9 ? '•••-•••-' + d.slice(6) : ''; }
  function maskAcct() { var d = digits(val('dd_account_number')); return d ? '••••' + d.slice(-3) : ''; }
  function fileName(n) { var el = form.elements[n]; return (el && el.files && el.files[0]) ? el.files[0].name : ''; }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function buildReview() {
    var contacts = $all('.contact', contactsWrap).map(function (c, i) { return (c.querySelector('[data-cf=first_name]').value + ' ' + c.querySelector('[data-cf=last_name]').value).trim(); }).filter(Boolean).join(' · ');
    var days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].filter(function (k) { return form.elements['avail_' + k + '_enabled'].checked; }).map(function (k) { return k.slice(0, 3); }).join(', ');
    var secs = [
      ['Personal', [['Name', [val('first_name'), val('middle_name'), val('last_name')].filter(Boolean).join(' ')], ['DOB', val('date_of_birth')], ['Address', [val('street_address'), val('unit'), val('city'), val('province'), val('postal_code')].filter(Boolean).join(', ')], ['Mobile', val('mobile_phone')], ['Email', val('primary_email')]]],
      ['Emergency', [['Contacts', contacts]]],
      ['Availability', [['Days', days], ['Trips/time off', val('trips_has')]]],
      ['Work Auth', [['SIN', maskSin()], ['Proof of SIN', fileName('sin_document')], ['Permit', val('permit_type')]]],
      ['Government ID', [['Type', form.elements['gov_doc_type'].options[form.elements['gov_doc_type'].selectedIndex].text], ['File', fileName('gov_document')]]],
      ['Direct Deposit', [['Bank', form.elements['dd_bank'].options[form.elements['dd_bank'].selectedIndex].text], ['Transit/Inst', [val('dd_transit'), val('dd_institution_number')].filter(Boolean).join(' / ')], ['Account', maskAcct()], ['Void cheque', fileName('dd_document')]]],
      ['Headshot', [['File', fileName('headshot')]]]
    ];
    var html = '';
    secs.forEach(function (s) { html += '<div class="review-sec"><h4>' + s[0] + '</h4><dl>'; s[1].forEach(function (r) { html += '<div><dt>' + esc(r[0]) + '</dt><dd>' + (r[1] ? esc(r[1]) : '<span class="miss">— not provided —</span>') + '</dd></div>'; }); html += '</dl></div>'; });
    document.getElementById('reviewSummary').innerHTML = html;
  }

  // ---------------------------------------------------------------- server errors
  function applyServerErrors(errors) {
    var firstStep = total, firstEl = null;
    Object.keys(errors).forEach(function (key) {
      var el, m = key.match(/^contacts\.(\d+)\.(.+)$/);
      if (m) { var c = $all('.contact', contactsWrap)[+m[1]]; el = c ? c.querySelector('[data-cf="' + m[2] + '"]') : null; if (el) { var ee = c.querySelector('[data-err="' + m[2] + '"]'); if (ee) { ee.textContent = errors[key]; ee.classList.add('show'); } } }
      else { el = form.elements[key]; if (el && el.length && !el.name) el = el[0]; if (el) setError(el, errors[key]); }
      if (el) { var sec = el.closest('.step'); if (sec) { var idx = +sec.getAttribute('data-step'); if (idx < firstStep) { firstStep = idx; firstEl = el; } } }
    });
    if (firstStep < total) { showStep(firstStep); if (firstEl) setTimeout(function () { firstEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); firstEl.focus && firstEl.focus(); }, 120); }
  }
  function banner(msg) {
    var b = document.getElementById('formErrorBanner');
    if (!b) { b = document.createElement('div'); b.id = 'formErrorBanner'; b.setAttribute('role', 'alert'); b.className = 'errbanner'; document.body.appendChild(b); }
    b.textContent = msg; b.style.display = 'block'; clearTimeout(b._t); b._t = setTimeout(function () { b.style.display = 'none'; }, 7000);
  }

  // ---------------------------------------------------------------- submit
  var sending = document.getElementById('sendingOverlay'), success = document.getElementById('successOverlay');
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var allOk = true, jumpTo = null;
    for (var i = 0; i < total; i++) { if (!validateStep(i, true)) { allOk = false; if (jumpTo === null) jumpTo = i; } }
    if (!allOk) { if (jumpTo !== null && jumpTo !== current) showStep(jumpTo); validateStep(jumpTo); banner('Please fix the highlighted fields before submitting.'); return; }
    if (totalUpload() > MAX_TOTAL) { banner('Your uploads exceed ' + humanSize(MAX_TOTAL) + '. Please use smaller files.'); return; }
    var sd = signatureData(); if (!sd) { showStep(total - 1); banner('Please add your signature.'); return; }
    sending.hidden = false; btnSubmit.disabled = true;
    function doSend(fd) {
      fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json().catch(function () { return { ok: false, formError: 'Unexpected server response (HTTP ' + r.status + '). Please try again; if it keeps happening, contact Human Resources and mention this code.' }; }); })
        .then(function (data) {
          sending.hidden = true; btnSubmit.disabled = false;
          if (data.ok) { submitted = true; document.getElementById('successRef').textContent = data.reference || '—'; success.hidden = false; }
          else { if (window.turnstile) try { turnstile.reset(); } catch (e) {} if (data.errors) { applyServerErrors(data.errors); banner('Some details need attention.'); } else banner(data.formError || 'We could not process your submission.'); }
        })
        .catch(function () { sending.hidden = true; btnSubmit.disabled = false; banner('Network error. Please try again.'); });
    }
    // Send the signature as a real file part: host firewalls often 403 a
    // large base64 blob in a text field but accept it as an ordinary upload.
    // The data-URL text field remains the fallback if toBlob is unavailable.
    sigInput.value = '';
    if (pad && pad.toBlob) {
      pad.toBlob(function (b) {
        if (!b) sigInput.value = sd;
        var fd = new FormData(form);
        if (b) fd.append('signature_file', b, 'signature.png');
        doSend(fd);
      }, 'image/png');
    } else {
      sigInput.value = sd;
      doSend(new FormData(form));
    }
  });
  window.addEventListener('beforeunload', function (e) { if (dirty && !submitted) { e.preventDefault(); e.returnValue = ''; } });

  // ---------------------------------------------------------------- TEST autofill
  if (LG.test && document.getElementById('fillTest')) {
    document.getElementById('fillTest').addEventListener('click', fillTest);
  }
  function tinyPdf() { return new File([new Blob(['%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF'], { type: 'application/pdf' })], 'test.pdf', { type: 'application/pdf' }); }
  function tinyImg(cb) { var c = document.createElement('canvas'); c.width = 400; c.height = 500; var x = c.getContext('2d'); x.fillStyle = '#dfe4ea'; x.fillRect(0, 0, 400, 500); x.fillStyle = '#b0967f'; x.beginPath(); x.ellipse(200, 240, 90, 120, 0, 0, 7); x.fill(); c.toBlob(function (b) { cb(new File([b], 'photo.jpg', { type: 'image/jpeg' })); }, 'image/jpeg', 0.85); }
  function setVal(name, v) { var el = form.elements[name]; if (!el) return; el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
  function pickRadio(name, v) { var r = form.querySelector('input[name="' + name + '"][value="' + v + '"]'); if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); } }
  // Fills the HARDEST path through the form so every conditional branch is
  // exercised: "other" pronouns/relationship/bank, a second contact, yes +
  // details on trips/allergies/medical, a SIN starting with 9 (permit chain),
  // an EXPIRED permit (IRCC letter chain), and all four certifications with
  // details and uploads.
  function fillTest() {
    form.elements['privacy_ack'].checked = true;
    setVal('first_name', 'Jane'); setVal('middle_name', 'A'); setVal('last_name', 'Sample');
    setVal('preferred_name', 'Janie');
    setVal('pronouns', 'other'); setVal('pronouns_other', 'ze/zir');
    setVal('date_of_birth', '1996-04-12');
    setVal('street_address', '123 King St W'); setVal('unit', '4B'); setVal('city', 'Toronto'); setVal('province', 'Ontario'); setVal('postal_code', 'M5V 2T6');
    setVal('mobile_phone', '4165550142'); setVal('home_phone', '4165550143'); setVal('other_phone', '4165550144');
    setVal('primary_email', 'jane.sample@example.com'); setVal('secondary_email', 'jane.alt@example.com');
    // contact 0 (standard relationship)
    fillContact($all('.contact', contactsWrap)[0], 'John', 'Sample', 'parent', '', '+1 (416) 555-0199', 'mobile', 'home', 'john.sample@example.com', 'home');
    // contact 1 ("other" relationship — added on first run only)
    if ($all('.contact', contactsWrap).length < 2) document.getElementById('addContact').click();
    fillContact($all('.contact', contactsWrap)[1], 'Mary', 'Smith', 'other', 'Neighbour', '+1 (647) 555-0123', 'landline', 'work', 'mary.smith@example.com', 'work');
    // availability: two days + hours + comments
    ['monday', 'friday'].forEach(function (d) { var cb = form.elements['avail_' + d + '_enabled']; cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true })); });
    setVal('avail_monday_start', '17:00'); setVal('avail_monday_end', '23:00');
    setVal('avail_friday_start', '16:00'); setVal('avail_friday_end', '23:30');
    setVal('desired_hours', '32'); setVal('availability_comments', 'Prefer evening shifts.');
    // yes + details reveals
    pickRadio('trips_has', 'yes'); setVal('trips_details', 'Family trip Dec 20–27.');
    pickRadio('allergies_has', 'yes'); setVal('allergies_details', 'Peanuts (carry an EpiPen).');
    pickRadio('medical_has', 'yes'); setVal('medical_details', 'Mild asthma.');
    // SIN starting with 9 (Luhn-valid) → SIN dates + permit; EXPIRED permit → IRCC letter
    setVal('sin', '900 000 001');
    setVal('sin_issued', '2024-01-15'); setVal('sin_expiry', futureDate(1));
    setSel(form.elements['permit_type'], 'work'); setVal('permit_number', 'P123456789');
    setVal('permit_issued', '2024-01-15'); setVal('permit_expiry', pastDate(10));
    setVal('ircc_letter_id', 'IRCC-2026-778899');
    setVal('gov_first_name', 'Jane'); setVal('gov_last_name', 'Sample'); setSel(form.elements['gov_doc_type'], 'drivers_licence');
    setVal('gov_doc_number', 'S1234-56789-01234'); setVal('gov_expiry_date', futureDate(4));
    // "Other" bank → institution name + manual institution number
    setSel(form.elements['dd_bank'], 'other'); setVal('dd_bank_other', 'DUCA Credit Union');
    setVal('dd_institution_number', '828');
    setVal('dd_account_holder', 'Jane A Sample'); setVal('dd_transit', '00123');
    setVal('dd_account_number', '1234567'); acctC.value = '1234567';
    // all four certifications: yes + details + upload
    $all('.cert').forEach(function (c) {
      if (c.hidden) return;
      var key = c.getAttribute('data-cert');
      var r = c.querySelector('input[data-cert-has][value=yes]');
      if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
      setVal(key + '_first_name', 'Jane'); setVal(key + '_last_name', 'Sample');
      setVal(key + '_cert_id', key.toUpperCase() + '-99887');
      setVal(key + '_issued', '2024-06-01'); setVal(key + '_expiry', futureDate(3));
      if (form.elements[key + '_provider']) setVal(key + '_provider', 'Toronto Training Institute');
      setFile(key + '_document', tinyPdf());
    });
    // declaration
    form.elements['declaration_ack'].checked = true; form.elements['comms_consent'].checked = true;
    setSel(form.elements['preferred_contact'], 'email'); setVal('employee_name', 'Jane A Sample');
    // signature
    if (pad) { var x = pad.getContext('2d'); x.beginPath(); x.moveTo(20, 120); x.lineTo(120, 40); x.lineTo(220, 120); x.lineTo(400, 60); x.stroke(); hasSig = true; }
    // files
    setFile('gov_document', tinyPdf()); setFile('sin_document', tinyPdf()); setFile('dd_document', tinyPdf());
    setFile('permit_document', tinyPdf()); setFile('ircc_document', tinyPdf());
    tinyImg(function (f) { setFile('headshot', f); });
    banner('Test data filled (all conditional paths) — jump to Review & Sign and submit.');
  }
  function fillContact(c, first, last, rel, relOther, phone, device, loc, email, emailLoc) {
    if (!c) return;
    c.querySelector('[data-cf=first_name]').value = first; c.querySelector('[data-cf=last_name]').value = last;
    setSel(c.querySelector('[data-cf=relationship]'), rel);
    if (relOther) { var ro = c.querySelector('[data-cf=relationship_other]'); if (ro) ro.value = relOther; }
    c.querySelector('[data-cf=phone]').value = phone;
    setSel(c.querySelector('[data-cf=phone_device]'), device); setSel(c.querySelector('[data-cf=phone_location]'), loc);
    c.querySelector('[data-cf=email]').value = email; setSel(c.querySelector('[data-cf=email_location]'), emailLoc);
  }
  function setSel(el, v) { if (!el) return; el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); }
  function futureDate(years) { var d = new Date(); d.setFullYear(d.getFullYear() + years); return d.toISOString().slice(0, 10); }
  function pastDate(days) { var d = new Date(); d.setDate(d.getDate() - days); return d.toISOString().slice(0, 10); }

  showStep(0);
})();
