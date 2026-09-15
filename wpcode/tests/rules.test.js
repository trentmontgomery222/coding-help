const fs = require('fs');
const { JSDOM } = require('jsdom');

const SRC = fs.readFileSync('/home/user/coding-help/wpcode/02-search-filter.js', 'utf8');

function build(queryRules, rules) {
  let s = SRC;
  s = s.replace(/\tvar QUERY_RULES = \[[\s\S]*?\n\t\];/, '\tvar QUERY_RULES = ' + JSON.stringify(queryRules) + ';');
  s = s.replace(/\tvar RULES = \[[\s\S]*?\n\t\];/, '\tvar RULES = ' + JSON.stringify(rules) + ';');
  if (s.includes('var QUERY_RULES = [\n')) throw new Error('QUERY_RULES substitution failed');
  return s;
}

function run({ query = '', results = [], queryRules = [], rules = [], data = {} }) {
  const items = results.map(r =>
    `<article class="acps-result post-${r.id}"><h2 class="entry-title"><a href="${r.url}">${r.title}</a></h2><div class="entry-summary">${r.excerpt || ''}</div></article>`
  ).join('');

  const dom = new JSDOM(
    `<!doctype html><html><body><main id="content"><p class="search-count">About 99 results</p>${items}</main></body></html>`,
    { url: 'https://example.org/?s=' + encodeURIComponent(query), runScripts: 'outside-only' }
  );

  dom.window.ACPS_SEARCH = Object.assign({ query, hiddenIds: [], hiddenPaths: [], isAdmin: false, rules: {} }, data);
  dom.window.eval(build(queryRules, rules));

  // jsdom still reports readyState 'loading' at this point, so the snippet
  // has registered a DOMContentLoaded listener rather than running. Fire it
  // so the whole test stays synchronous.
  if (dom.window.document.readyState === 'loading') {
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  }

  const doc = dom.window.document;
  const visible = [...doc.querySelectorAll('article')]
    .filter(a => !a.classList.contains('acps-filtered-out'))
    .map(a => a.querySelector('.entry-title a').textContent);

  return {
    visible,
    count: doc.querySelector('.search-count').textContent,
    empty: doc.querySelector('.acps-empty-message')?.textContent || null,
    notice: doc.querySelector('.acps-notice')?.textContent || null,
    order: [...doc.querySelectorAll('article')].map(a => a.querySelector('.entry-title a').textContent),
    badges: [...doc.querySelectorAll('.acps-badge')].map(b => b.textContent),
    adminMarked: [...doc.querySelectorAll('.acps-admin-hidden')].length,
  };
}

const SAMPLE = [
  { id: 1, url: '/about/',            title: 'About Our School' },
  { id: 2, url: '/staff-only/hr/',    title: 'HR Portal' },
  { id: 3, url: '/news/fall-fest/',   title: 'ACPS - Fall Festival' },
  { id: 4, url: '/archive/2019/old/', title: 'Draft Handbook' },
  { id: 5, url: '/enrollment/',       title: 'Enrollment Info' },
];

let pass = 0, fail = 0;
function check(name, actual, expected) {
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a === e) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}\n       expected ${e}\n       actual   ${a}`); }
}

console.log('\nquery rules');
check('equals -> noResults empties the page',
  run({ query: 'staff directory', results: SAMPLE,
        queryRules: [{ op: 'equals', value: 'staff directory', then: 'noResults', message: 'Nope.' }] }).visible, []);
check('equals -> noResults shows its message',
  run({ query: 'staff directory', results: SAMPLE,
        queryRules: [{ op: 'equals', value: 'staff directory', then: 'noResults', message: 'Nope.' }] }).empty, 'Nope.');
check('equals -> noResults zeroes the count',
  run({ query: 'staff directory', results: SAMPLE,
        queryRules: [{ op: 'equals', value: 'staff directory', then: 'noResults' }] }).count, 'About 0 results');
check('equals does not fire on a superstring',
  run({ query: 'staff directory hours', results: SAMPLE,
        queryRules: [{ op: 'equals', value: 'staff directory', then: 'noResults' }] }).visible.length, 5);
check('contains fires on a superstring',
  run({ query: 'school payroll info', results: SAMPLE,
        queryRules: [{ op: 'contains', value: 'payroll', then: 'noResults' }] }).visible, []);
check('query matching is case-insensitive',
  run({ query: '  PAYROLL  ', results: SAMPLE,
        queryRules: [{ op: 'contains', value: 'payroll', then: 'noResults' }] }).visible, []);
check('in matches any listed term',
  run({ query: 'w-2', results: SAMPLE,
        queryRules: [{ op: 'in', value: ['ssn', 'w-2'], then: 'noResults' }] }).visible, []);
check('regex works',
  run({ query: '123-45-6789', results: SAMPLE,
        queryRules: [{ op: 'regex', value: '^\\d{3}-\\d{2}-\\d{4}$', then: 'noResults' }] }).visible, []);
check('an earlier allow rule wins',
  run({ query: 'board policy', results: SAMPLE,
        queryRules: [{ op: 'equals', value: 'board policy', then: 'allow' },
                     { op: 'contains', value: 'policy', then: 'noResults' }] }).visible.length, 5);
check('notice leaves results alone',
  run({ query: 'enrollment', results: SAMPLE,
        queryRules: [{ op: 'contains', value: 'enrollment', then: 'notice', message: 'Try admissions.' }] }).visible.length, 5);
check('notice renders',
  run({ query: 'enrollment', results: SAMPLE,
        queryRules: [{ op: 'contains', value: 'enrollment', then: 'notice', message: 'Try admissions.' }] }).notice, 'Try admissions.');
check('unmatched query leaves everything',
  run({ query: 'lunch', results: SAMPLE,
        queryRules: [{ op: 'equals', value: 'payroll', then: 'noResults' }] }).visible.length, 5);

console.log('\nresult rules');
check('url contains -> hide',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'url', op: 'contains', value: '/staff-only/', then: 'hide' }] }).visible,
  ['About Our School', 'ACPS - Fall Festival', 'Draft Handbook', 'Enrollment Info']);
check('title starts -> hide',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'title', op: 'starts', value: 'Draft', then: 'hide' }] }).visible.includes('Draft Handbook'), false);
check('title starts does not match mid-string',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'title', op: 'starts', value: 'Handbook', then: 'hide' }] }).visible.length, 5);
check('id in -> hide',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'id', op: 'in', value: [1, 5], then: 'hide' }] }).visible,
  ['HR Portal', 'ACPS - Fall Festival', 'Draft Handbook']);
check('url regex -> hide',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'url', op: 'regex', value: '/20(1[0-9])/', then: 'hide' }] }).visible.includes('Draft Handbook'), false);
check('keep beats a later hide',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'url', op: 'contains', value: '/staff-only/', then: 'keep' },
                                             { when: 'title', op: 'contains', value: 'hr', then: 'hide' }] }).visible.includes('HR Portal'), true);
check('rewrite changes the title',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'title', op: 'contains', value: 'ACPS - ', then: 'rewrite', replace: '' }] }).visible.includes('Fall Festival'), true);
check('badge is added',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'url', op: 'contains', value: '/news/', then: 'badge', label: 'News' }] }).badges, ['News']);
check('top pins to the front',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'url', op: 'contains', value: '/enrollment/', then: 'top' }] }).order[0], 'Enrollment Info');
check('dim keeps the node but flags it',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'title', op: 'contains', value: 'draft', then: 'dim' }] }).order.length, 5);
check('count reflects what survived',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'url', op: 'contains', value: '/staff-only/', then: 'hide' }] }).count, 'About 4 results');
check('everything hidden -> empty message',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'url', op: 'contains', value: '/', then: 'hide' }] }).empty,
  'No matching results. Try a different search term.');
check('no rules changes nothing',
  run({ query: 'x', results: SAMPLE }).visible.length, 5);

console.log('\nplugin bridge');
check('hiddenIds are filtered',
  run({ query: 'x', results: SAMPLE, data: { hiddenIds: [2, 4] } }).visible,
  ['About Our School', 'ACPS - Fall Festival', 'Enrollment Info']);
check('hiddenPaths are filtered (trailing slash tolerant)',
  run({ query: 'x', results: SAMPLE, data: { hiddenPaths: ['/about'] } }).visible.includes('About Our School'), false);
check('admin sees hidden, marked',
  run({ query: 'x', results: SAMPLE, data: { hiddenIds: [2], isAdmin: true } }).adminMarked, 1);
check('admin still sees the row',
  run({ query: 'x', results: SAMPLE, data: { hiddenIds: [2], isAdmin: true } }).visible.length, 5);
check('adminSeesHidden=false hides from admins too',
  run({ query: 'x', results: SAMPLE, data: { hiddenIds: [2], isAdmin: true, rules: { adminSeesHidden: false } } }).visible.length, 4);

console.log('\nsettings-page rules');
check('blockUrlContains from settings',
  run({ query: 'x', results: SAMPLE, data: { rules: { blockUrlContains: ['/staff-only/'] } } }).visible.length, 4);
check('blockTitleContains from settings',
  run({ query: 'x', results: SAMPLE, data: { rules: { blockTitleContains: ['draft'] } } }).visible.length, 4);
check('titleRewrites from settings',
  run({ query: 'x', results: SAMPLE, data: { rules: { titleRewrites: [{ match: 'ACPS - ', replace: '' }] } } }).visible.includes('Fall Festival'), true);
check('emptyMessage from settings',
  run({ query: 'x', results: SAMPLE, data: { rules: { blockUrlContains: ['/'], emptyMessage: 'Nothing here.' } } }).empty, 'Nothing here.');

console.log('\nrobustness');
check('bad regex is skipped, not fatal',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'title', op: 'regex', value: '[unclosed', then: 'hide' }] }).visible.length, 5);
check('empty rule value never matches everything',
  run({ query: 'x', results: SAMPLE, rules: [{ when: 'title', op: 'contains', value: '', then: 'hide' }] }).visible.length, 5);
check('missing ACPS_SEARCH data is survivable',
  run({ query: '', results: SAMPLE }).visible.length, 5);

console.log(`\n${pass} passed, ${fail} failed\n`);
process.exit(fail ? 1 : 0);
