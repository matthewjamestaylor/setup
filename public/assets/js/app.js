/* Legends Global — New Hire Onboarding front-end controller */
(function () {
  'use strict';

  var MAX_FILE = 8 * 1024 * 1024;      // keep in sync with config uploads.max_bytes_per_file
  var MAX_TOTAL = 18 * 1024 * 1024;    // uploads.max_bytes_total

  var LG = {
    turnstileEnabled: document.body.getAttribute('data-turnstile') === '1',
    steps: JSON.parse(document.body.getAttribute('data-steps') || '[]')
  };

  var form = document.getElementById('onboarding-form');
  if (!form) return;
  var steps = Array.prototype.slice.call(form.querySelectorAll('.step'));
  var total = steps.length;
  var current = 0;
  var submitted = false;

  var btnBack = document.getElementById('btnBack');
  var btnNext = document.getElementById('btnNext');
  var btnSubmit = document.getElementById('btnSubmit');
  var progressBar = document.getElementById('progressBar');
  var stepName = document.getElementById('stepName');
  var stepCount = document.getElementById('stepCount');

  // ---------------------------------------------------------------- utils
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function digits(s) { return (s || '').replace(/\D+/g, ''); }
  function humanSize(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' KB';
    return b + ' B';
  }
  function luhn(d) {
    if (!/^\d+$/.test(d)) return false;
    var sum = 0, alt = false;
    for (var i = d.length - 1; i >= 0; i--) {
      var n = parseInt(d.charAt(i), 10);
      if (alt) { n *= 2; if (n > 9) n -= 9; }
      sum += n; alt = !alt;
    }
    return sum % 10 === 0;
  }

  // ---------------------------------------------------------------- validators
  var validators = {
    name: function (v) { return /^[\p{L}][\p{L}\p{M}\s'.\-]*$/u.test(v) ? '' : 'Please use letters only.'; },
    email: function (v) { return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v) ? '' : 'Enter a valid email address.'; },
    tel: function (v) { var d = digits(v); return (d.length >= 10 && d.length <= 15) ? '' : 'Enter a valid phone number.'; },
    postal: function (v) { return /^[A-Za-z]\d[A-Za-z]\s?\d[A-Za-z]\d$/.test(v) ? '' : 'Enter a valid postal code (e.g. K1A 0B1).'; },
    date: function (v) { return /^\d{4}-\d{2}-\d{2}$/.test(v) ? '' : 'Enter a valid date.'; },
    sin: function (v) { var d = digits(v); return (d.length === 9 && luhn(d)) ? '' : 'Enter a valid 9-digit SIN.'; },
    digits5: function (v) { return digits(v).length === 5 ? '' : 'Must be 5 digits.'; },
    digits3: function (v) { return digits(v).length === 3 ? '' : 'Must be 3 digits.'; },
    account: function (v) { var d = digits(v); return (d.length >= 5 && d.length <= 17) ? '' : 'Enter a valid account number.'; }
  };

  function errEl(input) {
    var id = input.id || input.name;
    var el = document.getElementById(id + '-err') || document.getElementById('f_' + input.name + '-err');
    return el;
  }
  function setError(input, msg) {
    var field = input.closest('.field') || input.closest('.avail-day') || input.parentElement;
    var el = errEl(input);
    if (msg) {
      if (field) field.classList.add('invalid');
      if (el) { el.textContent = msg; el.classList.add('show'); }
    } else {
      if (field) field.classList.remove('invalid');
      if (el) { el.textContent = ''; el.classList.remove('show'); }
    }
  }
  function isVisible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }

  function validateInput(input) {
    if (input.disabled || !isVisible(input)) return true;
    var val = (input.value || '').trim();
    var required = input.getAttribute('data-required') === '1';
    if (input.type === 'checkbox') {
      if (required && !input.checked) { setError(input, 'This is required.'); return false; }
      setError(input, ''); return true;
    }
    if (input.type === 'file') {
      if (required && input.files.length === 0) { setError(input, 'Please upload this document.'); return false; }
      if (input.files.length && input.files[0].size > MAX_FILE) { setError(input, 'File is too large (max ' + humanSize(MAX_FILE) + ').'); return false; }
      setError(input, ''); return true;
    }
    if (val === '') {
      if (required) { setError(input, 'This field is required.'); return false; }
      setError(input, ''); return true;
    }
    var rule = input.getAttribute('data-validate');
    if (rule && validators[rule]) {
      var msg = validators[rule](val);
      if (msg) { setError(input, msg); return false; }
    }
    setError(input, ''); return true;
  }

  // custom per-step checks; returns first invalid element or null
  function customStepCheck(index, stepEl) {
    var bad = null;
    function fail(el, msg) { if (!bad) bad = el; setError(el, msg); }

    if (index === 2) { // emergency: partial secondary requires name + phone
      var ec2 = ['ec2_name', 'ec2_relationship', 'ec2_phone', 'ec2_email'].map(function (n) { return form.elements[n]; });
      var any = ec2.some(function (i) { return i && i.value.trim() !== ''; });
      if (any) {
        if (!form.elements['ec2_name'].value.trim()) fail(form.elements['ec2_name'], 'Name is required for the secondary contact.');
        if (!form.elements['ec2_phone'].value.trim()) fail(form.elements['ec2_phone'], 'Phone is required for the secondary contact.');
      }
    }
    if (index === 3) { // availability: enabled day needs start<end
      $all('.avail-day', stepEl).forEach(function (day) {
        var key = day.getAttribute('data-day');
        var on = form.elements['avail_' + key + '_enabled'].checked;
        var s = form.elements['avail_' + key + '_start'];
        var e = form.elements['avail_' + key + '_end'];
        if (!on) return;
        if (!s.value || !e.value) { fail(s, 'Enter start and end times, or turn this day off.'); return; }
        if (e.value <= s.value) { fail(e, 'End time must be after start time.'); }
      });
    }
    if (index === 8) { // certifications: provided section requires core fields
      $all('.cert', stepEl).forEach(function (cert) {
        var key = cert.getAttribute('data-cert');
        if (form.elements[key + '_not_applicable'].checked) return;
        var group = ['_first_name', '_middle_name', '_last_name', '_cert_id', '_issued', '_expiry'];
        var hasData = group.some(function (sfx) { var el = form.elements[key + sfx]; return el && el.value.trim() !== ''; });
        var provEl = form.elements[key + '_provider'];
        if (provEl && provEl.value.trim() !== '') hasData = true;
        if (!hasData) return;
        if (!form.elements[key + '_last_name'].value.trim()) fail(form.elements[key + '_last_name'], 'Required (or mark Not Applicable).');
        if (!form.elements[key + '_cert_id'].value.trim()) fail(form.elements[key + '_cert_id'], 'Required (or mark Not Applicable).');
        if (!form.elements[key + '_issued'].value.trim()) fail(form.elements[key + '_issued'], 'Required (or mark Not Applicable).');
        if (provEl && !provEl.value.trim()) fail(provEl, 'Required (or mark Not Applicable).');
      });
    }
    if (index === 9) { // review: signature + turnstile
      if (!signatureData()) { fail($('#signaturePad'), 'Please sign in the box above.'); var se = document.getElementById('f_signature-err'); if (se) { se.textContent = 'A signature is required.'; se.classList.add('show'); } }
      if (LG.turnstileEnabled) {
        var t = form.querySelector('[name="cf-turnstile-response"]');
        var te = document.getElementById('turnstile-err');
        if (!t || !t.value) { if (te) { te.textContent = 'Please complete the verification.'; te.classList.add('show'); } if (!bad) bad = $('.turnstile-wrap'); }
        else if (te) { te.textContent = ''; te.classList.remove('show'); }
      }
    }
    return bad;
  }

  function validateStep(index, silent) {
    var stepEl = steps[index];
    // Fields are skipped by validateInput() when not visible, so a hidden step
    // would validate as a no-op. Temporarily reveal it (synchronously, no
    // repaint) so its fields are checked; conditionally-hidden sub-blocks
    // (permit, N/A cert bodies, day times) stay hidden and remain skipped.
    var wasHidden = stepEl.hidden;
    if (wasHidden) stepEl.hidden = false;
    var ok = true, firstBad = null;
    $all('input,select,textarea', stepEl).forEach(function (input) {
      if (!validateInput(input)) { ok = false; if (!firstBad) firstBad = input; }
    });
    var custom = customStepCheck(index, stepEl);
    if (custom) { ok = false; if (!firstBad) firstBad = custom; }
    if (wasHidden) stepEl.hidden = true;
    if (!ok && !silent && firstBad && firstBad.focus) {
      try { firstBad.focus({ preventScroll: false }); } catch (e) { firstBad.focus(); }
      firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    return ok;
  }

  // ---------------------------------------------------------------- navigation
  function showStep(index) {
    current = Math.max(0, Math.min(total - 1, index));
    steps.forEach(function (s, i) { s.hidden = i !== current; });
    var pct = ((current + 1) / total) * 100;
    progressBar.style.width = pct + '%';
    stepName.textContent = (LG.steps && LG.steps[current]) || ('Step ' + (current + 1));
    stepCount.textContent = 'Step ' + (current + 1) + ' of ' + total;
    btnBack.hidden = current === 0;
    var last = current === total - 1;
    btnNext.hidden = last;
    btnSubmit.hidden = !last;
    if (last) buildReview();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    var f = steps[current].querySelector('input:not([type=hidden]),select,textarea,button');
    if (f && f.focus) setTimeout(function () { try { f.focus({ preventScroll: true }); } catch (e) {} }, 60);
  }

  btnNext.addEventListener('click', function () { if (validateStep(current)) showStep(current + 1); });
  btnBack.addEventListener('click', function () { showStep(current - 1); });

  // Enter advances (except in textareas / on last step where it submits)
  form.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.type !== 'submit') {
      if (current < total - 1) { e.preventDefault(); btnNext.click(); }
    }
  });

  // live-clear errors as the user fixes them
  form.addEventListener('input', function (e) {
    if (e.target.closest('.field') && e.target.closest('.field').classList.contains('invalid')) validateInput(e.target);
  });

  // ---------------------------------------------------------------- conditional logic
  var sinInput = form.elements['sin'];
  if (sinInput) {
    sinInput.addEventListener('input', function () {
      var d = digits(sinInput.value);
      var nine = d.charAt(0) === '9';
      var block = document.getElementById('permit-block');
      block.hidden = !nine;
      ['permit_number', 'permit_issued', 'permit_expiry', 'sin_expiry'].forEach(function (n) {
        var el = form.elements[n];
        if (!el) return;
        if (nine) el.setAttribute('data-required', '1'); else { el.removeAttribute('data-required'); setError(el, ''); }
      });
    });
  }

  $all('[data-day-toggle]').forEach(function (chk) {
    chk.addEventListener('change', function () {
      var key = chk.getAttribute('data-day-toggle');
      var day = chk.closest('.avail-day');
      var times = $('.daytimes', day);
      times.hidden = !chk.checked;
      ['start', 'end'].forEach(function (p) {
        var el = form.elements['avail_' + key + '_' + p];
        if (chk.checked) el.setAttribute('data-required', '1'); else { el.removeAttribute('data-required'); el.value = ''; setError(el, ''); }
      });
    });
  });

  $all('[data-cert-na]').forEach(function (chk) {
    chk.addEventListener('change', function () {
      var cert = chk.closest('.cert');
      cert.classList.toggle('na-on', chk.checked);
      if (chk.checked) {
        $all('input,select,textarea', cert).forEach(function (el) {
          if (el === chk) return;
          if (el.type === 'checkbox') el.checked = false; else el.value = '';
          el.removeAttribute('data-required'); setError(el, '');
        });
      }
    });
  });

  var govSame = document.getElementById('gov_same');
  if (govSame) {
    govSame.addEventListener('change', function () {
      if (!govSame.checked) return;
      ['first_name', 'middle_name', 'last_name'].forEach(function (p) {
        if (form.elements['gov_' + p] && form.elements[p]) form.elements['gov_' + p].value = form.elements[p].value;
      });
    });
  }

  // ---------------------------------------------------------------- file inputs
  function totalUploadBytes() {
    var t = 0;
    $all('input[type=file]', form).forEach(function (inp) { if (inp.files[0]) t += inp.files[0].size; });
    return t;
  }
  $all('input[type=file]', form).forEach(function (inp) {
    inp.addEventListener('change', function () {
      var wrap = inp.closest('.filefield');
      var label = wrap ? wrap.querySelector('[data-filename]') : null;
      var f = inp.files[0];
      if (!f) { if (label) label.textContent = ''; return; }
      if (label) {
        label.textContent = f.name + ' · ' + humanSize(f.size);
        label.classList.toggle('toobig', f.size > MAX_FILE);
      }
      validateInput(inp);
      if (totalUploadBytes() > MAX_TOTAL) setError(inp, 'Combined uploads exceed ' + humanSize(MAX_TOTAL) + '. Please use smaller files.');
      if (inp.name === 'headshot' && f.type.indexOf('image') === 0) {
        var pv = document.getElementById('headshotPreview');
        if (pv) {
          var img = pv.querySelector('img');
          if (img.src && img.src.indexOf('blob:') === 0) URL.revokeObjectURL(img.src);
          img.src = URL.createObjectURL(f);
          pv.hidden = false;
        }
      }
    });
  });

  // ---------------------------------------------------------------- signature pad
  var pad = document.getElementById('signaturePad');
  var sigInput = document.getElementById('signatureInput');
  var hasSig = false;
  if (pad) {
    var ctx = pad.getContext('2d');
    function resetPad() { ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, pad.width, pad.height); ctx.strokeStyle = '#12142a'; ctx.lineWidth = 2.4; ctx.lineJoin = 'round'; ctx.lineCap = 'round'; hasSig = false; }
    resetPad();
    var drawing = false, lastX = 0, lastY = 0;
    function pos(e) {
      var r = pad.getBoundingClientRect();
      var sx = pad.width / r.width, sy = pad.height / r.height;
      var cx = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
      var cy = (e.touches ? e.touches[0].clientY : e.clientY) - r.top;
      return { x: cx * sx, y: cy * sy };
    }
    function start(e) { drawing = true; var p = pos(e); lastX = p.x; lastY = p.y; e.preventDefault(); }
    function move(e) {
      if (!drawing) return;
      var p = pos(e);
      ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.stroke();
      lastX = p.x; lastY = p.y; hasSig = true; e.preventDefault();
    }
    function end() { drawing = false; }
    pad.addEventListener('mousedown', start); pad.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    pad.addEventListener('touchstart', start, { passive: false });
    pad.addEventListener('touchmove', move, { passive: false });
    pad.addEventListener('touchend', end);
    var clearBtn = document.getElementById('sigClear');
    if (clearBtn) clearBtn.addEventListener('click', function () { resetPad(); sigInput.value = ''; });
  }
  function signatureData() {
    if (!pad || !hasSig) return '';
    try { return pad.toDataURL('image/png'); } catch (e) { return ''; }
  }

  // ---------------------------------------------------------------- review
  function val(n) { var el = form.elements[n]; return el ? (el.value || '').trim() : ''; }
  function maskSin() { var d = digits(val('sin')); return d.length === 9 ? '•••-•••-' + d.slice(6) : ''; }
  function maskAcct() { var d = digits(val('dd_account_number')); return d ? '••••' + d.slice(-3) : ''; }
  function fileNameFor(n) { var el = form.elements[n]; return (el && el.files[0]) ? el.files[0].name : ''; }
  function availSummary() {
    var out = [];
    ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach(function (k) {
      if (form.elements['avail_' + k + '_enabled'].checked) {
        var s = val('avail_' + k + '_start'), e = val('avail_' + k + '_end');
        if (s && e) out.push(k.charAt(0).toUpperCase() + k.slice(1, 3) + ' ' + s + '–' + e);
      }
    });
    return out.join(', ');
  }
  function buildReview() {
    var sections = [
      ['Personal', [
        ['Name', [val('first_name'), val('middle_name'), val('last_name')].filter(Boolean).join(' ')],
        ['Date of Birth', val('date_of_birth')],
        ['Address', [val('street_address'), val('unit'), val('city'), val('province'), val('postal_code')].filter(Boolean).join(', ')],
        ['Mobile', val('mobile_phone')],
        ['Email', val('primary_email')]
      ]],
      ['Emergency', [
        ['Primary', [val('ec1_name'), val('ec1_relationship'), val('ec1_phone')].filter(Boolean).join(' · ')],
        ['Secondary', [val('ec2_name'), val('ec2_phone')].filter(Boolean).join(' · ')]
      ]],
      ['Availability', [
        ['Days', availSummary()],
        ['Desired hours/week', val('desired_hours')]
      ]],
      ['Work Authorization', [
        ['SIN', maskSin()],
        ['Government ID', val('gov_doc_type')],
        ['ID file', fileNameFor('gov_document')]
      ]],
      ['Direct Deposit', [
        ['Institution', val('dd_institution_name')],
        ['Transit / Inst.', [val('dd_transit'), val('dd_institution_number')].filter(Boolean).join(' / ')],
        ['Account', maskAcct()],
        ['Void cheque', fileNameFor('dd_document')]
      ]],
      ['Documents', [
        ['Headshot', fileNameFor('headshot')]
      ]]
    ];
    var html = '';
    sections.forEach(function (sec) {
      html += '<div class="review-sec"><h4>' + sec[0] + '</h4><dl>';
      sec[1].forEach(function (row) {
        var v = row[1] ? escapeHtml(row[1]) : '<span class="miss">— not provided —</span>';
        html += '<div><dt>' + escapeHtml(row[0]) + '</dt><dd>' + v + '</dd></div>';
      });
      html += '</dl></div>';
    });
    document.getElementById('reviewSummary').innerHTML = html;
  }
  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }

  // ---------------------------------------------------------------- server error mapping
  function applyServerErrors(errors) {
    var firstStep = total, firstEl = null;
    Object.keys(errors).forEach(function (key) {
      var el = form.elements[key];
      if (!el) return;
      if (el.length && !el.name) el = el[0];
      setError(el, errors[key]);
      var sec = el.closest ? el.closest('.step') : null;
      if (sec) { var idx = parseInt(sec.getAttribute('data-step'), 10); if (idx < firstStep) { firstStep = idx; firstEl = el; } }
    });
    if (firstStep < total) {
      showStep(firstStep);
      if (firstEl) setTimeout(function () { firstEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); firstEl.focus && firstEl.focus(); }, 120);
    }
  }
  function showFormError(msg) {
    alertBanner(msg);
  }
  function alertBanner(msg) {
    var b = document.getElementById('formErrorBanner');
    if (!b) { b = document.createElement('div'); b.id = 'formErrorBanner'; b.setAttribute('role', 'alert'); b.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:22px;background:#c0392b;color:#fff;padding:13px 18px;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.25);z-index:60;max-width:92%;font-weight:600'; document.body.appendChild(b); }
    b.textContent = msg; b.style.display = 'block';
    clearTimeout(b._t); b._t = setTimeout(function () { b.style.display = 'none'; }, 7000);
  }

  // ---------------------------------------------------------------- submit
  var sending = document.getElementById('sendingOverlay');
  var success = document.getElementById('successOverlay');

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    // validate every step (silent: reveal-and-check happens inside validateStep)
    var allOk = true, jumpTo = null;
    for (var i = 0; i < total; i++) {
      if (!validateStep(i, true)) { allOk = false; if (jumpTo === null) jumpTo = i; }
    }
    if (!allOk) { if (jumpTo !== null && jumpTo !== current) showStep(jumpTo); showFormError('Please fix the highlighted fields before submitting.'); return; }

    // Enforce combined upload size (server also enforces this authoritatively).
    if (totalUploadBytes() > MAX_TOTAL) {
      var firstFile = $all('input[type=file]', form).filter(function (i) { return i.files && i.files.length; })[0];
      var fstep = firstFile ? firstFile.closest('.step') : null;
      if (fstep) showStep(parseInt(fstep.getAttribute('data-step'), 10));
      showFormError('Your uploaded documents total more than ' + humanSize(MAX_TOTAL) + '. Please upload smaller or compressed files.');
      return;
    }

    var sd = signatureData();
    if (!sd) { showStep(total - 1); showFormError('Please add your signature.'); return; }
    sigInput.value = sd;

    sending.hidden = false;
    btnSubmit.disabled = true;

    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, formError: 'Unexpected server response. Please try again.' }; }); })
      .then(function (data) {
        sending.hidden = true; btnSubmit.disabled = false;
        if (data.ok) {
          submitted = true;
          document.getElementById('successRef').textContent = data.reference || '—';
          success.hidden = false;
        } else if (data.errors) {
          if (window.turnstile) try { turnstile.reset(); } catch (e) {}
          applyServerErrors(data.errors);
          showFormError('Some details need attention.');
        } else {
          if (window.turnstile) try { turnstile.reset(); } catch (e) {}
          showFormError(data.formError || 'We could not process your submission.');
        }
      })
      .catch(function () {
        sending.hidden = true; btnSubmit.disabled = false;
        showFormError('Network error. Please check your connection and try again.');
      });
  });

  // warn before leaving with unsaved data
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  window.addEventListener('beforeunload', function (e) {
    if (dirty && !submitted) { e.preventDefault(); e.returnValue = ''; }
  });

  // init
  showStep(0);
})();
