/**
 * Better FCFs — conversational front end.
 *
 * This file MUST execute before conversationalForm.js. The Vue app mounts the
 * moment its bundle is parsed, so by the time it runs, every FlowFormCustomType
 * has already fired ffc_init_custom_field and window.fluent_forms_global_var has
 * already been assigned. Assets.php guarantees the ordering.
 */
(function () {
    'use strict';

    var ROOT = window.BFCF_CONFIG;
    if (!ROOT || !ROOT.forms) {
        return;
    }

    var HIDDEN_MARKER = 'bfcf_partial_store';

    function configFor(formId) {
        return ROOT.forms[String(formId)] || null;
    }

    /* ------------------------------------------------------------------ *
     * Session identity
     *
     * The cookie is what links this session to the eventual submission:
     * fluentform/submission_inserted reads it server-side. sessionStorage keeps
     * it stable across a reload without leaking across tabs.
     * ------------------------------------------------------------------ */

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : ((r & 0x3) | 0x8)).toString(16);
        });
    }

    var sessions = {};

    function sessionFor(formId) {
        if (sessions[formId]) {
            return sessions[formId];
        }
        var key = 'bfcf_sid_' + formId;
        var sid;
        try {
            sid = window.sessionStorage.getItem(key);
        } catch (e) {
            sid = null;
        }
        if (!sid) {
            sid = uuid();
            try {
                window.sessionStorage.setItem(key, sid);
            } catch (e) {}
        }
        document.cookie = key + '=' + encodeURIComponent(sid) + ';path=/;max-age=86400;SameSite=Lax';
        sessions[formId] = sid;
        return sid;
    }

    /* ------------------------------------------------------------------ *
     * Time on page — only counts while the tab is actually visible, so a form
     * left open in a background tab for an hour doesn't read as engagement.
     * ------------------------------------------------------------------ */

    var activeMs = 0;
    var lastTick = Date.now();

    function tick() {
        var now = Date.now();
        if (!document.hidden) {
            activeMs += now - lastTick;
        }
        lastTick = now;
    }

    setInterval(tick, 1000);
    document.addEventListener('visibilitychange', tick);

    function activeSeconds() {
        tick();
        return Math.round(activeMs / 1000);
    }

    /* ------------------------------------------------------------------ *
     * The seam: trap window.fluent_forms_global_var.
     *
     * conversationalForm.js bootstraps every instance with
     *   window.fluent_forms_global_var = window[el.getAttribute('data-var_name')]
     * and then reads it straight back into globalVars, so a single accessor
     * property catches the standalone page AND every inline shortcode instance,
     * and the app picks up whatever we hand back.
     *
     * A global `var x = {...}` against an already-defined accessor property
     * routes through the setter rather than redefining it — that's what lets us
     * intercept wp_localize_script's own assignment.
     * ------------------------------------------------------------------ */

    var stashed;

    try {
        Object.defineProperty(window, 'fluent_forms_global_var', {
            configurable: true,
            get: function () {
                return stashed;
            },
            set: function (value) {
                stashed = value;
                try {
                    transform(value);
                } catch (e) {
                    window.console && console.error('[BFCF] transform failed', e);
                }
            }
        });
    } catch (e) {
        window.console && console.error('[BFCF] could not install global trap', e);
    }

    function transform(vars) {
        if (!vars || !vars.form || !Array.isArray(vars.form.questions)) {
            return;
        }
        // The bootstrap re-assigns the same object it was given, so the setter
        // fires more than once per instance.
        if (vars.__bfcf) {
            return;
        }
        vars.__bfcf = true;

        var formId = vars.form_id || vars.form.id;
        var cfg = configFor(formId);
        if (!cfg) {
            return;
        }

        vars.form.questions.forEach(function (q) {
            if (q.ff_input_type === 'input_number' && cfg.numbers[q.name]) {
                // Hand the whole answer area to us. FlowFormCustomType renders a
                // bare <div>, which is the only way to show "$450,000" while the
                // model holds 450000 — a v-model'd input can't diverge from its
                // own display value.
                q.type = 'FlowFormCustomType';
            }
        });

        if (cfg.checkpoints.length) {
            // Makes the app call handlePerStepSave() on every advance, which
            // builds a fully serialized payload of all answers so far. We
            // intercept that request below; Fluent Forms Pro never sees it.
            vars.form.has_per_step_save = true;
            vars.form.has_resume_from_last_step = false;

            // onSendData() reads extra_inputs at submit time, so this rides along
            // in the real submission and lets the server link partial -> entry even
            // when cookies are blocked.
            if (vars.extra_inputs) {
                vars.extra_inputs.bfcf_session = sessionFor(formId);
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * Number fields
     * ------------------------------------------------------------------ */

    function group(intPart) {
        return intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function toRaw(display, spec) {
        var cleaned = String(display).replace(/[^0-9.]/g, '');
        var bits = cleaned.split('.');
        var int = bits.shift() || '';
        var frac = bits.join('');

        if (!spec.decimals) {
            return int;
        }
        if (!cleaned.includes('.')) {
            return int;
        }
        return int + '.' + frac.slice(0, spec.decimals);
    }

    // The input holds ONLY the grouped number. The $ / % are separate, always-visible
    // affix elements (see mountNumberField), so the symbol shows the moment the question
    // appears — before the visitor types — and can never be deleted or caret-mangled.
    function groupNumber(raw, spec) {
        if (raw === '' || raw == null) {
            return '';
        }
        var bits = String(raw).split('.');
        var out = spec.commas ? group(bits[0]) : bits[0];
        if (bits.length > 1) {
            out += '.' + bits[1];
        }
        return out;
    }

    // Prefix/suffix included — only for human-readable messages (e.g. "at least $50,000").
    function withAffix(raw, spec) {
        return spec.prefix + groupNumber(raw, spec) + spec.suffix;
    }

    function digitsBefore(str, caret) {
        var n = 0;
        for (var i = 0; i < caret && i < str.length; i++) {
            if (str[i] >= '0' && str[i] <= '9') {
                n++;
            }
        }
        return n;
    }

    function caretAfterDigits(str, n) {
        if (n <= 0) {
            var i = 0;
            while (i < str.length && (str[i] < '0' || str[i] > '9')) {
                i++;
            }
            return i;
        }
        var seen = 0;
        for (var j = 0; j < str.length; j++) {
            if (str[j] >= '0' && str[j] <= '9') {
                seen++;
                if (seen === n) {
                    return j + 1;
                }
            }
        }
        return str.length;
    }

    function outOfRange(raw, spec) {
        if (raw === '') {
            return false;
        }
        var n = Number(raw);
        if (isNaN(n)) {
            return false;
        }
        if (spec.min !== null && n < spec.min) {
            return true;
        }
        return spec.max !== null && n > spec.max;
    }

    document.body.addEventListener('ffc_init_custom_field', function (event) {
        var detail = event.detail || {};
        var element = detail.element;
        var question = detail.question;

        if (!element || !question || question.ff_input_type !== 'input_number') {
            return;
        }

        var wrapper = element.closest('[data-form_id]');
        var formIds = Object.keys(ROOT.forms);
        var cfg = configFor(wrapper ? wrapper.getAttribute('data-form_id') : formIds[0]);

        var spec = cfg && cfg.numbers[question.name];
        if (!spec) {
            return;
        }

        mountNumberField(element, question, spec);
    });

    function affix(cls, text) {
        var span = document.createElement('span');
        span.className = 'bfcf-affix ' + cls;
        span.textContent = text;
        span.setAttribute('aria-hidden', 'true');
        return span;
    }

    function mountNumberField(element, question, spec) {
        var input = document.createElement('input');
        input.type = 'text';
        input.inputMode = spec.decimals ? 'decimal' : 'numeric';
        input.autocomplete = 'off';
        input.className = 'bfcf-number';
        input.setAttribute('name', question.name);
        if (question.placeholder) {
            input.placeholder = question.placeholder;
        }

        // [ $ ][ input ][ % ] — the affixes are static DOM, so they are on screen before
        // any input. A suffix-only field right-aligns its number so the % hugs it.
        var wrap = document.createElement('div');
        wrap.className = 'bfcf-number-wrap';
        if (spec.suffix && ! spec.prefix) {
            wrap.className += ' bfcf-suffixed';
        }

        if (spec.prefix) {
            wrap.appendChild(affix('bfcf-affix-prefix', spec.prefix));
        }
        wrap.appendChild(input);
        if (spec.suffix) {
            wrap.appendChild(affix('bfcf-affix-suffix', spec.suffix));
        }

        // Clicking the symbol should land the caret in the field, like a normal input.
        wrap.addEventListener('mousedown', function (e) {
            if (e.target !== input) {
                e.preventDefault();
                input.focus();
            }
        });

        var error = document.createElement('div');
        error.className = 'bfcf-number-error';
        error.style.display = 'none';

        element.appendChild(wrap);
        element.appendChild(error);

        // The model always holds a plain number. That's what keeps Fluent Forms'
        // numeric validation, min/max rules and calculation formulas working —
        // only the display is ever formatted.
        function push(raw) {
            element.dispatchEvent(new CustomEvent('value.update', {
                detail: { value: raw === '' ? null : Number(raw) }
            }));
        }

        function render(raw, keepCaret) {
            var caret = keepCaret ? digitsBefore(input.value, input.selectionStart) : null;
            input.value = groupNumber(raw, spec);
            if (keepCaret) {
                var pos = caretAfterDigits(input.value, caret);
                try {
                    input.setSelectionRange(pos, pos);
                } catch (e) {}
            }

            var bad = outOfRange(raw, spec);
            wrap.classList.toggle('bfcf-invalid', bad);
            if (bad) {
                error.textContent = spec.min !== null && Number(raw) < spec.min
                    ? 'Must be at least ' + withAffix(String(spec.min), spec)
                    : 'Must be at most ' + withAffix(String(spec.max), spec);
                error.style.display = '';
            } else {
                error.style.display = 'none';
            }
            return bad;
        }

        input.addEventListener('input', function () {
            var raw = toRaw(input.value, spec);
            render(raw, true);
            push(raw);
        });

        // We render our own div instead of FlowFormNumberType's input, so its
        // min/max validate() never runs. Hold the step here rather than letting
        // the user discover the problem at final submit.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && outOfRange(toRaw(input.value, spec), spec)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });

        if (question.answer !== null && question.answer !== undefined && question.answer !== '') {
            render(String(question.answer), false);
        }

        registerFocusTarget(element, input);
    }

    /**
     * The app focuses the active question by calling focusField() on the type
     * component it rendered — but FlowFormCustomType has no input of its own, so
     * nothing gets focused. Watch for the active class and do it ourselves.
     */
    var focusTargets = [];

    function registerFocusTarget(element, input) {
        focusTargets.push({ element: element, input: input });
    }

    new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
            if (m.attributeName !== 'class' || !m.target.classList.contains('q-is-active')) {
                return;
            }
            focusTargets.forEach(function (t) {
                if (m.target.contains(t.element)) {
                    setTimeout(function () {
                        t.input.focus();
                    }, 50);
                }
            });
        });
    }).observe(document.documentElement, {
        attributes: true,
        subtree: true,
        attributeFilter: ['class']
    });

    /* ------------------------------------------------------------------ *
     * Partial capture — idle-timer model
     *
     * Passing a checkpoint arms a countdown equal to that checkpoint's timer.
     * ANY further answer restarts the countdown. When it runs out — meaning they
     * went quiet — OR when they leave the page, we send the partial, once per
     * checkpoint. Reaching a DEEPER checkpoint arms a fresh countdown for it;
     * a full submission cancels everything.
     *
     * The Vue app's per-step saves are used only as a client-side signal (the
     * latest answers, and whether a checkpoint was passed). They never reach the
     * server — the single server call happens at fire time. So no draft rows, no
     * resume/prefill side effects, and this works on free Fluent Forms too.
     * ------------------------------------------------------------------ */

    function flowFor(formId) {
        var host = document.querySelector('.ff_conv_app_' + formId + ' .ffc_conv_form') ||
            document.querySelector('.ffc_conv_form');
        var app = host && host.__vue_app__;
        var proxy = app && app._instance && app._instance.proxy;
        return (proxy && proxy.$refs && proxy.$refs.flowform) || null;
    }

    /**
     * A checkpoint is passed once the user advances beyond the last question that
     * precedes it. Resolved against the app's LIVE active path, because conditional
     * logic can hide questions at runtime and shift the indices.
     */
    function passedCheckpoint(cp, formId, activeStep) {
        var flow = flowFor(formId);
        var before;

        if (flow && Array.isArray(flow.questionListActivePath)) {
            before = flow.questionListActivePath.filter(function (q) {
                return cp.after_fields.indexOf(q.name) !== -1;
            }).length;
        } else {
            before = cp.after_fields.length;
        }

        return Number(activeStep) >= before;
    }

    // The deepest checkpoint passed at this step. No time gate here — the countdown
    // handles dwell, and the server re-verifies the checkpoint claim.
    function deepestPassed(formId, activeStep) {
        var cfg = configFor(formId);
        if (!cfg) {
            return null;
        }
        var best = null;
        cfg.checkpoints.forEach(function (cp) {
            if (passedCheckpoint(cp, formId, activeStep)) {
                best = cp;
            }
        });
        return best;
    }

    function totalSteps(formId) {
        var flow = flowFor(formId);
        return (flow && Array.isArray(flow.questionListActivePath)) ? flow.questionListActivePath.length : 0;
    }

    function utm() {
        var out = {};
        var params = new URLSearchParams(window.location.search);
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid']
            .forEach(function (key) {
                var value = params.get(key);
                if (value) {
                    out[key] = value;
                }
            });
        return out;
    }

    // Per-form capture state.
    var caps = {};

    function capFor(formId) {
        if (!caps[formId]) {
            caps[formId] = { checkpoint: null, data: '', step: 0, timer: null, firedFor: null, converted: false };
        }
        return caps[formId];
    }

    var nativeFetch = window.fetch.bind(window);

    function isStepSave(init) {
        return init && init.body instanceof FormData &&
            init.body.get('action') === 'fluentform_step_form_save_data';
    }

    window.fetch = function (input, init) {
        try {
            if (isStepSave(init)) {
                handleStep(init.body);
            }
        } catch (e) {
            window.console && console.error('[BFCF] capture failed', e);
        }

        // A step-save is ALWAYS ours to swallow — it must never reach Fluent Forms
        // Pro's draft endpoint (which would create draft rows and switch on
        // resume/prefill). The partial is sent later, on idle or exit.
        if (isStepSave(init)) {
            return Promise.resolve(new Response('{}', {
                status: 200,
                headers: { 'Content-Type': 'application/json' }
            }));
        }

        return nativeFetch(input, init);
    };

    function handleStep(body) {
        var formId = String(body.get('form_id'));
        if (!configFor(formId)) {
            return;
        }

        var cap = capFor(formId);
        if (cap.converted) {
            return;
        }

        cap.data = body.get('data') || '';
        cap.step = body.get('active_step');

        var cp = deepestPassed(formId, cap.step);
        if (!cp) {
            return; // still before the first checkpoint — nothing captured yet
        }

        if (!cap.checkpoint || cp.name !== cap.checkpoint.name) {
            // A new, deeper checkpoint — its webhook gets to fire on its own merits.
            cap.checkpoint = cp;
            cap.firedFor = null;
        }

        // Capture + update the stored row on EVERY answer once they are past a
        // checkpoint, so the Partial Leads row grows as they move through the form.
        // This is storage only — no webhook.
        store(formId);

        // The webhook still fires on the settle timer or on exit.
        armIdle(formId);
    }

    function buildBody(formId, reason, dispatch) {
        var cap = capFor(formId);
        var cfg = configFor(formId);
        var body = new FormData();
        body.set('action', 'bfcf_partial_save');
        body.set('nonce', cfg.nonce);
        body.set('form_id', formId);
        body.set('session', sessionFor(formId));
        body.set('checkpoint', cap.checkpoint.name);
        body.set('active_step', cap.step);
        body.set('data', cap.data);
        body.set('reason', reason);
        body.set('dispatch', dispatch ? '1' : '0');
        body.set('active_seconds', String(activeSeconds()));
        body.set('total_steps', String(totalSteps(formId)));
        body.set('source_url', window.location.href);
        body.set('referrer', document.referrer || '');
        body.set('utm', JSON.stringify(utm()));
        return body;
    }

    // Store / update the partial row. dispatch=0: no webhook.
    function store(formId) {
        var cap = capFor(formId);
        var cfg = configFor(formId);
        if (!cfg || !cap.checkpoint || cap.converted) {
            return;
        }
        nativeFetch(cfg.ajaxurl, { method: 'POST', body: buildBody(formId, 'progress', false), keepalive: true });
    }

    function armIdle(formId) {
        var cap = capFor(formId);
        if (!cap.checkpoint) {
            return;
        }
        if (cap.timer) {
            clearTimeout(cap.timer);
            cap.timer = null;
        }
        // Webhook already fired for this checkpoint (or converted): don't re-arm.
        if (cap.converted || cap.firedFor === cap.checkpoint.name) {
            return;
        }

        var ms = Math.max(0, (cap.checkpoint.min_seconds || 0) * 1000);
        cap.timer = setTimeout(function () {
            fire(formId, 'idle');
        }, ms);
    }

    // Fire the webhook (dispatch=1) once per checkpoint, on settle timeout or exit.
    function fire(formId, reason) {
        var cap = capFor(formId);
        var cfg = configFor(formId);
        if (!cfg || !cap.checkpoint || cap.converted) {
            return;
        }
        if (cap.firedFor === cap.checkpoint.name) {
            return; // once per checkpoint
        }

        cap.firedFor = cap.checkpoint.name;
        if (cap.timer) {
            clearTimeout(cap.timer);
            cap.timer = null;
        }

        var body = buildBody(formId, reason, true);
        if (reason === 'exit' && navigator.sendBeacon) {
            navigator.sendBeacon(cfg.ajaxurl, body);
        } else {
            nativeFetch(cfg.ajaxurl, { method: 'POST', body: body, keepalive: true });
        }
    }

    // Leaving the page (closing, or switching away) sends the pending partial right
    // away instead of waiting out the rest of the countdown. Once per checkpoint, so
    // a partial that already fired on idle is not sent twice.
    function fireAllOnExit() {
        Object.keys(caps).forEach(function (formId) {
            var cap = caps[formId];
            if (cap.checkpoint && !cap.converted && cap.firedFor !== cap.checkpoint.name) {
                fire(formId, 'exit');
            }
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            fireAllOnExit();
        }
    });
    window.addEventListener('pagehide', fireAllOnExit);

    // A full submission is a conversion, not a partial: cancel any pending countdown
    // so nothing fires. The server links the entry to an already-fired partial (if any)
    // via the session cookie at fluentform/submission_inserted.
    document.body.addEventListener('fluentform_submission_success', function (e) {
        var formId = e.detail && e.detail.form_id;
        var ids = formId ? [String(formId)] : Object.keys(caps);
        ids.forEach(function (id) {
            var cap = capFor(id);
            cap.converted = true;
            if (cap.timer) {
                clearTimeout(cap.timer);
                cap.timer = null;
            }
        });
    });
})();
