/**
 * The settings-screen rule builder.
 *
 * Written because the builder failed silently once already — the Add rule
 * button did nothing, the page looked fine, and there was nothing to see in
 * the console. A builder that quietly stops working is worth a test.
 *
 * The fixture mirrors what class-wpsqr-admin.php renders. If you change that
 * markup, change this too.
 */
const fs = require('fs');
const { JSDOM } = require('jsdom');

const SRC = fs.readFileSync(__dirname + '/../assets/js/admin-rules.js', 'utf8');

const FIELDS = { title: 'Title', url: 'URL path', type: 'Post type', excerpt: 'Excerpt', query: 'the search term' };
const OPS = { contains: 'contains', equals: 'is exactly', starts: 'starts with' };
const ACTIONS = { hide: 'Hide it', rewrite: 'Find and replace text', setDesc: 'Replace the description', badge: 'Add a badge' };

const select = (cls, base, key, options, selected, off) =>
  `<select class="${cls}" name="${base}[${key}]"${off ? ' disabled' : ''}>` +
  Object.entries(options).map(([v, l]) => `<option value="${v}"${v === selected ? ' selected' : ''}>${l}</option>`).join('') +
  `</select>`;

function test(base, { cond = false, off = false, value = '', when = 'title', op = 'contains' } = {}) {
  return `<div class="wpsqr-test ${cond ? 'wpsqr-cond' : 'wpsqr-test--first'}">
    ${cond ? select('wpsqr-test__join wpsqr-f-join', base, 'join', { and: 'and', any: 'or' }, 'and', off)
           : '<span class="wpsqr-test__join">If</span>'}
    ${select('wpsqr-f-when', base, 'when', FIELDS, when, off)}
    ${select('wpsqr-f-op', base, 'op', OPS, op, off)}
    <input type="text" class="wpsqr-f-value" name="${base}[value]" value="${value}"${off ? ' disabled' : ''}>
    <label class="wpsqr-test__not"><input type="checkbox" name="${base}[not]" value="1"${off ? ' disabled' : ''}> invert</label>
    ${cond ? '<button type="button" class="wpsqr-test__remove" data-remove-cond>&times;</button>'
           : '<span class="wpsqr-test__spacer"></span>'}
  </div>`;
}

function rule(name, i, { conds = 0, off = false, value = '', then = 'hide' } = {}) {
  const base = `${name}[${i}]`;
  const condHTML = Array.from({ length: conds }, (_, c) => test(`${base}[conds][${c}]`, { cond: true, off, when: 'query' })).join('');

  return `<div class="wpsqr-rule">
    <div class="wpsqr-rule__bar">
      <span class="wpsqr-rule__num"></span>
      <span class="wpsqr-rule__summary"></span>
      <button type="button" class="wpsqr-rule__delete" data-remove-rule>Delete</button>
    </div>
    <div class="wpsqr-rule__body">
      ${test(base, { off, value })}
      <div class="wpsqr-conds">${condHTML}</div>
      <p class="wpsqr-rule__addcond"><button type="button" class="button-link" data-add-cond>+ Add another condition</button></p>
      <div class="wpsqr-action">
        <span class="wpsqr-action__label">Then</span>
        ${select('wpsqr-f-then', base, 'then', ACTIONS, then, off)}
        <select class="wpsqr-f-extra" data-extra="target" name="${base}[target]" hidden${off ? ' disabled' : ''}><option value="title">in the title</option></select>
        <input type="text" class="wpsqr-f-extra" data-extra="replace" name="${base}[replace]" hidden${off ? ' disabled' : ''}>
        <input type="text" class="wpsqr-f-extra" data-extra="desc" name="${base}[desc]" hidden${off ? ' disabled' : ''}>
        <input type="text" class="wpsqr-f-extra" data-extra="label" name="${base}[label]" hidden${off ? ' disabled' : ''}>
        <input type="text" class="wpsqr-f-extra" data-extra="message" name="${base}[message]" hidden${off ? ' disabled' : ''}>
        <input type="text" class="wpsqr-f-extra" data-extra="url" name="${base}[url]" hidden${off ? ' disabled' : ''}>
      </div>
    </div>
  </div>`;
}

function build(existing = []) {
  const name = 'result_rules';
  const list = existing.map((opts, i) => rule(name, i, opts)).join('');

  const dom = new JSDOM(`<!doctype html><html><body><form>
    <div class="wpsqr-rules" data-wpsqr-rules data-name="${name}">
      <div class="wpsqr-rules__list">${list}</div>
      <p class="wpsqr-no-rules"${existing.length ? ' hidden' : ''}>No rules yet.</p>
      <p class="wpsqr-rules__add"><button type="button" data-add-rule>+ Add rule</button></p>
      <div class="wpsqr-proto" data-proto="rule" hidden>${rule(name, '__i__', { off: true })}</div>
      <div class="wpsqr-proto" data-proto="cond" hidden>${test(`${name}[__i__][conds][__c__]`, { cond: true, off: true, when: 'query' })}</div>
    </div></form></body></html>`, { runScripts: 'outside-only' });

  dom.window.eval(SRC);
  if (dom.window.document.readyState === 'loading') {
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  }

  const doc = dom.window.document;
  return {
    doc,
    add: () => doc.querySelector('[data-add-rule]').click(),
    rules: () => [...doc.querySelectorAll('.wpsqr-rules__list .wpsqr-rule')],
    // What the server would actually receive.
    names: () => [...doc.querySelectorAll('.wpsqr-rules__list [name]')]
      .filter(f => !f.disabled).map(f => f.getAttribute('name')),
    emptyHidden: () => doc.querySelector('.wpsqr-no-rules').hidden,
  };
}

let pass = 0, fail = 0;
function check(name, actual, expected) {
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a === e) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}\n       expected ${e}\n       actual   ${a}`); }
}

console.log('\nadding rules');
{
  const b = build();
  check('starts with none', b.rules().length, 0);
  check('the empty notice shows', b.emptyHidden(), false);
  b.add();
  check('add rule adds one', b.rules().length, 1);
  check('the empty notice hides', b.emptyHidden(), true);
  b.add(); b.add();
  check('add rule keeps adding', b.rules().length, 3);
}

console.log('\nthe names that reach the server');
{
  const b = build();
  b.add();
  check('a new rule is indexed 0', b.names().includes('result_rules[0][value]'), true);
  check('no placeholder survives', b.names().some(n => n.includes('__i__')), false);
  b.add();
  check('the second rule is indexed 1', b.names().includes('result_rules[1][value]'), true);
  check('the first is untouched', b.names().includes('result_rules[0][value]'), true);
}

console.log('\nprototypes never submit');
{
  const b = build();
  const protoFields = [...b.doc.querySelectorAll('.wpsqr-proto [name]')];
  check('every prototype field is disabled', protoFields.every(f => f.disabled), true);
  check('prototypes are excluded from the submitted names',
    b.names().some(n => n.includes('__i__') || n.includes('__c__')), false);
  b.add();
  const added = b.rules()[0];
  check('the clone is enabled', [...added.querySelectorAll('[name]')].every(f => !f.disabled), true);
}

console.log('\nremoving and renumbering');
{
  const b = build([{ value: 'first' }, { value: 'second' }, { value: 'third' }]);
  check('three render', b.rules().length, 3);
  b.rules()[0].querySelector('[data-remove-rule]').click();
  check('one is gone', b.rules().length, 2);
  check('the survivors renumber from zero',
    b.rules().map(r => r.querySelector('.wpsqr-f-value').getAttribute('name')),
    ['result_rules[0][value]', 'result_rules[1][value]']);
  check('values follow their own rule',
    b.rules().map(r => r.querySelector('.wpsqr-f-value').value), ['second', 'third']);
  b.rules()[1].querySelector('[data-remove-rule]').click();
  b.rules()[0].querySelector('[data-remove-rule]').click();
  check('removing the last shows the notice again', b.emptyHidden(), false);
}

console.log('\nconditions');
{
  const b = build([{ value: 'x' }]);
  const r = b.rules()[0];
  check('starts with none', r.querySelectorAll('.wpsqr-cond').length, 0);
  r.querySelector('[data-add-cond]').click();
  check('add condition adds one', r.querySelectorAll('.wpsqr-cond').length, 1);
  check('it is indexed inside its rule', b.names().includes('result_rules[0][conds][0][value]'), true);
  r.querySelector('[data-add-cond]').click();
  check('a second is indexed 1', b.names().includes('result_rules[0][conds][1][value]'), true);
  r.querySelectorAll('.wpsqr-cond')[0].querySelector('[data-remove-cond]').click();
  check('removing renumbers', b.names().includes('result_rules[0][conds][0][value]'), true);
  check('and leaves no gap', b.names().includes('result_rules[0][conds][1][value]'), false);
}

console.log('\nconditions survive their rule being renumbered');
{
  const b = build([{ value: 'first' }, { value: 'second', conds: 1 }]);
  check('the condition starts under rule 1', b.names().includes('result_rules[1][conds][0][value]'), true);
  b.rules()[0].querySelector('[data-remove-rule]').click();
  check('and moves to rule 0 with it', b.names().includes('result_rules[0][conds][0][value]'), true);
  check('leaving nothing behind at rule 1', b.names().some(n => n.startsWith('result_rules[1]')), false);
}

console.log('\nthe action shows only the fields it needs');
{
  const b = build([{ value: 'x', then: 'hide' }]);
  const r = b.rules()[0];
  const shown = () => [...r.querySelectorAll('[data-extra]')].filter(f => !f.hidden).map(f => f.getAttribute('data-extra'));

  check('hide needs nothing', shown(), []);

  const then = r.querySelector('.wpsqr-f-then');
  then.value = 'rewrite';
  then.dispatchEvent(new b.doc.defaultView.Event('change', { bubbles: true }));
  check('rewrite needs a target and a replacement', shown(), ['target', 'replace']);

  then.value = 'setDesc';
  then.dispatchEvent(new b.doc.defaultView.Event('change', { bubbles: true }));
  check('replace-the-description needs one field', shown(), ['desc']);

  then.value = 'badge';
  then.dispatchEvent(new b.doc.defaultView.Event('change', { bubbles: true }));
  check('badge needs its label', shown(), ['label']);
}

console.log('\nthe summary reads back what the rule says');
{
  const b = build([{ value: 'Staff Hub', then: 'hide' }]);
  const r = b.rules()[0];
  check('numbered', r.querySelector('.wpsqr-rule__num').textContent, '1');
  check('restated as a sentence',
    r.querySelector('.wpsqr-rule__summary').textContent,
    'If Title contains “Staff Hub” → Hide it');

  const not = r.querySelector('.wpsqr-test__not input');
  not.checked = true;
  not.dispatchEvent(new b.doc.defaultView.Event('change', { bubbles: true }));
  check('invert shows in the sentence',
    r.querySelector('.wpsqr-rule__summary').textContent.includes('does NOT'), true);

  r.querySelector('[data-add-cond]').click();
  const cond = r.querySelector('.wpsqr-cond');
  cond.querySelector('.wpsqr-f-value').value = 'staff';
  cond.querySelector('.wpsqr-f-value').dispatchEvent(new b.doc.defaultView.Event('input', { bubbles: true }));
  check('a condition joins the sentence',
    r.querySelector('.wpsqr-rule__summary').textContent.includes('and the search term contains “staff”'), true);
}

console.log(`\n${pass} passed, ${fail} failed\n`);
process.exit(fail ? 1 : 0);
