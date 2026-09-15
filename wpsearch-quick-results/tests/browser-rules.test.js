/**
 * Tests against the real SearchWP / Beaver Builder results markup.
 */
const fs = require('fs');
const { JSDOM } = require('jsdom');

const SRC = fs.readFileSync(__dirname + '/../assets/js/search-filter.js', 'utf8');
const ORIGIN = 'https://acpsmdwebsidev.wpenginepowered.com';

function build(queryRules, rules) {
  let s = SRC;
  s = s.replace(/\tvar QUERY_RULES = \[[\s\S]*?\n\t\];/, '\tvar QUERY_RULES = ' + JSON.stringify(queryRules) + ';');
  s = s.replace(/\tvar RULES = \[[\s\S]*?\n\t\];/, '\tvar RULES = ' + JSON.stringify(rules) + ';');
  return s;
}

// Verbatim shape of a SearchWP result row, highlight markup and all.
function item({ id, type, path, title, desc = '' }) {
  return `
<article class="swp-result-item post-${id} post type-${type} status-publish format-standard hentry category-uncategorized entry">
  <div class="swp-result-item--info-container">
    <h2 class="entry-title">
      <a href="${ORIGIN}${path}" target="_blank">${title}</a>
    </h2>
    <p class="swp-result-item--desc">${desc}</p>
    <a href="${ORIGIN}${path}" class="swp-result-item--button" target="_blank">Go to Page</a>
  </div>
</article>`;
}

const SAMPLE = [
  { id: 5002, type: 'page', path: '/staff/', title: '<mark class="searchwp-highlight">Staff</mark>', desc: '<mark class="searchwp-highlight">Staff</mark> Hub (opens in new tab)' },
  { id: 43,   type: 'page', path: '/staff/directory/', title: '<mark class="searchwp-highlight">Staff</mark> Directory', desc: 'Search the directory' },
  { id: 6063, type: 'post', path: '/holiday-message-to-acps-staff-from-superintendent-martirano/', title: 'Holiday Message to ACPS <mark class="searchwp-highlight">Staff</mark> from Superintendent Martirano' },
  { id: 4930, type: 'page', path: '/about-us/elected-board/board-policies/', title: 'Board Policies' },
  { id: 6837, type: 'attachment', path: '/wp-content/uploads/2026/06/Fiscal-Year-Full-Budget-Compressed.pdf', title: 'Fiscal Year Full Budget Compressed' },
  { id: 5069, type: 'attachment', path: '/wp-content/uploads/2026/02/school-safety-staff-2025.jpg', title: 'school-safety-<mark class="searchwp-highlight">staff</mark>-2025' },
  { id: 11487, type: 'page', path: '/powerschool/', title: 'PowerSchool' },
];

function run({ query = 'staff', results = SAMPLE, queryRules = [], rules = [], data = {}, search = null }) {
  const dom = new JSDOM(`<!doctype html><html><body>
    <div class="swp-total-results-notice"><p>Found 271 results for ${query}</p></div>
    <div class="swp-search-results swp-results-template-1 swp-flex swp-rp--img-sm" id="swp-search-results-6aa940fc80207">
      ${results.map(item).join('')}
    </div></body></html>`,
    { url: ORIGIN + (search === null ? `/?s=${encodeURIComponent(query)}` : search), runScripts: 'outside-only' });

  dom.window.ACPS_SEARCH = Object.assign(
    { query, hiddenIds: [], hiddenPaths: [], isAdmin: false, rules: {} }, data);
  dom.window.eval(build(queryRules, rules));
  if (dom.window.document.readyState === 'loading') {
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  }

  const doc = dom.window.document;
  const rows = [...doc.querySelectorAll('.swp-result-item')].filter(a => !a.classList.contains('acps-filtered-out'));
  return {
    titles: rows.map(a => a.querySelector('.entry-title a').textContent.trim()),
    ids: rows.map(a => /post-(\d+)/.exec(a.className)[1]),
    notice: doc.querySelector('.swp-total-results-notice p').textContent,
    marks: doc.querySelectorAll('mark.searchwp-highlight').length,
    badges: [...doc.querySelectorAll('.acps-badge')].map(b => b.textContent),
    descs: rows.map(a => (a.querySelector('.swp-result-item--desc') || {}).textContent || ''),
    descHTML: rows.map(a => (a.querySelector('.swp-result-item--desc') || {}).innerHTML || ''),
    titleHTML: rows.map(a => a.querySelector('.entry-title a').innerHTML.trim()),
    empty: doc.querySelector('.acps-empty-message')?.textContent || null,
  };
}

let pass = 0, fail = 0;
function check(name, actual, expected) {
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a === e) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}\n       expected ${e}\n       actual   ${a}`); }
}

console.log('\nselectors find the real markup');
check('all 7 rows survive with no rules', run({}).ids.length, 7);
check('the generated wrapper id is not required', run({}).titles[0], 'Staff');
check('the count notice outside the wrapper is found and adjusted',
  run({ rules: [{ when: 'type', op: 'equals', value: 'attachment', then: 'hide' }] }).notice, 'Found 269 results for staff');

console.log('\npost type rules');
check('type equals attachment hides PDFs and images',
  run({ rules: [{ when: 'type', op: 'equals', value: 'attachment', then: 'hide' }] }).ids,
  ['5002', '43', '6063', '4930', '11487']);
check('type in [post, attachment] leaves only pages',
  run({ rules: [{ when: 'type', op: 'in', value: ['post', 'attachment'], then: 'hide' }] }).ids,
  ['5002', '43', '4930', '11487']);
check('image extensions can be hidden while PDFs stay',
  run({ rules: [{ when: 'url', op: 'regex', value: '\\.(jpe?g|png|gif|svg|webp)$', then: 'hide' }] }).ids,
  ['5002', '43', '6063', '4930', '6837', '11487']);

console.log('\nurl rules against absolute cross-page hrefs');
check('a nested path fragment matches',
  run({ rules: [{ when: 'url', op: 'contains', value: '/staff/directory/', then: 'hide' }] }).ids.includes('43'), false);
check('a top-level path with trailing slash matches exactly one row',
  run({ rules: [{ when: 'url', op: 'equals', value: '/powerschool/', then: 'hide' }] }).ids.includes('11487'), false);
check('/staff/ as a fragment catches the section, not unrelated titles',
  run({ rules: [{ when: 'url', op: 'contains', value: '/staff/', then: 'hide' }] }).ids,
  ['6063', '4930', '6837', '5069', '11487']);
check('uploads path fragment hides every attachment',
  run({ rules: [{ when: 'url', op: 'contains', value: '/wp-content/uploads/', then: 'hide' }] }).ids.length, 5);

console.log('\ntitle and excerpt rules see through the highlight markup');
check('title contains matches across a <mark> boundary via textContent',
  run({ rules: [{ when: 'title', op: 'contains', value: 'staff directory', then: 'hide' }] }).ids.includes('43'), false);
check('title starts works on a highlighted first word',
  run({ rules: [{ when: 'title', op: 'starts', value: 'staff', then: 'hide' }] }).ids,
  ['6063', '4930', '6837', '5069', '11487']);
check('excerpt rule reads swp-result-item--desc',
  run({ rules: [{ when: 'excerpt', op: 'contains', value: 'search the directory', then: 'hide' }] }).ids.includes('43'), false);

console.log('\nrewrite preserves SearchWP highlighting');
const baseline = run({});
check('fixture starts with 5 highlight marks', baseline.marks, 5);
check('a rewrite does not strip any <mark>',
  run({ rules: [{ when: 'title', op: 'contains', value: 'ACPS ', then: 'rewrite', replace: '' }] }).marks, 5);
check('the rewrite still applies',
  run({ rules: [{ when: 'title', op: 'contains', value: 'Holiday Message to ACPS ', then: 'rewrite', replace: 'Message: ' }] })
    .titles.some(t => t.startsWith('Message: ')), true);
check('markup around the rewritten text is intact',
  run({ rules: [{ when: 'title', op: 'contains', value: ' from Superintendent Martirano', then: 'rewrite', replace: '' }] })
    .titleHTML.some(h => h.includes('<mark class="searchwp-highlight">Staff</mark>')), true);
check('a rewrite on a fully highlighted title keeps the mark',
  run({ rules: [{ when: 'title', op: 'contains', value: 'Staff', then: 'rewrite', replace: 'Team' }] }).marks, 5);

console.log('\nplugin bridge against real ids');
check('hiddenIds drop the right rows',
  run({ data: { hiddenIds: [43, 5069] } }).ids, ['5002', '6063', '4930', '6837', '11487']);
check('hiddenPaths match the absolute hrefs',
  run({ data: { hiddenPaths: ['/staff/directory'] } }).ids.includes('43'), false);

console.log('\nquery rules on the real page');
check('blocking the term empties the results', run({ queryRules: [{ op: 'equals', value: 'staff', then: 'noResults' }] }).ids, []);
check('and shows the message',
  run({ queryRules: [{ op: 'equals', value: 'staff', then: 'noResults', message: 'Try the directory.' }] }).empty, 'Try the directory.');

console.log('\nrules delivered from the settings screen');
check('a result rule array is applied',
  run({ data: { rules: { result: [{ when: 'type', op: 'equals', value: 'attachment', then: 'hide' }] } } }).ids.length, 5);
check('badge rules arrive intact',
  run({ data: { rules: { result: [{ when: 'url', op: 'contains', value: '/staff/', then: 'badge', label: 'Staff' }] } } }).badges.length, 2);
check('rewrite rules arrive intact',
  run({ data: { rules: { result: [{ when: 'title', op: 'contains', value: 'Holiday Message to ACPS ', then: 'rewrite', replace: '' }] } } })
    .titles.some(t => t.startsWith('Staff from Superintendent')), true);
check('bottom rules arrive intact',
  run({ data: { rules: { result: [{ when: 'type', op: 'equals', value: 'attachment', then: 'bottom' }] } } }).ids.slice(-2),
  ['6837', '5069']);
check('a query rule array is applied',
  run({ data: { rules: { query: [{ op: 'equals', value: 'staff', then: 'noResults' }] } } }).ids, []);
check('an allow query rule shields a term',
  run({ data: { rules: { query: [
    { op: 'equals', value: 'staff', then: 'allow' },
    { op: 'contains', value: 'staff', then: 'noResults' }
  ] } } }).ids.length, 7);
check('hideMode dim from settings keeps rows in the DOM',
  run({ data: { rules: { hideMode: 'dim', result: [{ when: 'type', op: 'equals', value: 'attachment', then: 'hide' }] } } }).ids.length, 5);
check('updateCount false leaves the notice alone',
  run({ data: { rules: { updateCount: false, result: [{ when: 'type', op: 'equals', value: 'attachment', then: 'hide' }] } } }).notice,
  'Found 271 results for staff');
check('legacy flat lists still work',
  run({ data: { rules: { blockUrlContains: ['/staff/directory/'] } } }).ids.includes('43'), false);

console.log('\ndescriptions');
check('rewrite targets the title by default, leaving the description alone',
  run({ rules: [{ when: 'title', op: 'contains', value: 'Staff', then: 'rewrite', replace: 'Team' }] }).descs[0].trim(),
  'Staff Hub (opens in new tab)');
check('target desc rewrites only the description',
  run({ rules: [{ when: 'excerpt', op: 'contains', value: 'hub', then: 'rewrite', replace: 'Portal', target: 'desc' }] }).descs[0].trim(),
  'Staff Portal (opens in new tab)');
check('target desc leaves the title alone',
  run({ rules: [{ when: 'excerpt', op: 'contains', value: 'hub', then: 'rewrite', replace: 'Portal', target: 'desc' }] }).titles[0],
  'Staff');
check('target both hits title and description',
  run({ rules: [{ when: 'title', op: 'contains', value: 'Staff', then: 'rewrite', replace: 'Team', target: 'both' }] }).descs[0].trim(),
  'Team Hub (opens in new tab)');
check('rewriting the description preserves its highlight markup',
  run({ rules: [{ when: 'excerpt', op: 'contains', value: 'Hub', then: 'rewrite', replace: 'Portal', target: 'desc' }] })
    .descHTML[0].includes('<mark class="searchwp-highlight">Staff</mark>'), true);
check('setDesc replaces the description outright',
  run({ rules: [{ when: 'url', op: 'contains', value: '/staff/directory/', then: 'setDesc', desc: 'Look up any employee.' }] }).descs[1],
  'Look up any employee.');
check('setDesc leaves other rows untouched',
  run({ rules: [{ when: 'url', op: 'contains', value: '/staff/directory/', then: 'setDesc', desc: 'Look up any employee.' }] }).descs[0].trim(),
  'Staff Hub (opens in new tab)');
check('a later setDesc overrides an earlier one',
  run({ rules: [
    { when: 'type', op: 'equals', value: 'page', then: 'setDesc', desc: 'A page.' },
    { when: 'url', op: 'contains', value: '/staff/directory/', then: 'setDesc', desc: 'The directory.' }
  ] }).descs[1], 'The directory.');
check('a custom description from the settings screen is applied by post id',
  run({ data: { descriptions: { '43': 'Written for search.' } } }).descs[1], 'Written for search.');
check('a rule-set description overrides a custom one',
  run({ data: { descriptions: { '43': 'Written for search.' } },
        rules: [{ when: 'url', op: 'contains', value: '/staff/directory/', then: 'setDesc', desc: 'Rule wins.' }] }).descs[1],
  'Rule wins.');
check('a rewrite applies on top of a custom description',
  run({ data: { descriptions: { '43': 'Search the ACPS directory.' } },
        rules: [{ when: 'excerpt', op: 'contains', value: 'ACPS', then: 'rewrite', replace: 'staff', target: 'desc' }] }).descs[1],
  'Search the staff directory.');
check('a hidden row never gets a description rewrite',
  run({ rules: [
    { when: 'url', op: 'contains', value: '/staff/directory/', then: 'hide' },
    { when: 'url', op: 'contains', value: '/staff/directory/', then: 'setDesc', desc: 'Never seen.' }
  ] }).descs.includes('Never seen.'), false);

console.log('\nreordering');
check('bottom sinks attachments below everything else',
  run({ rules: [{ when: 'type', op: 'equals', value: 'attachment', then: 'bottom' }] }).ids,
  ['5002', '43', '6063', '4930', '11487', '6837', '5069']);
check('sunk rows keep their own relative order',
  run({ rules: [{ when: 'type', op: 'equals', value: 'attachment', then: 'bottom' }] }).ids.slice(-2),
  ['6837', '5069']);
check('nothing is lost when sinking', run({ rules: [{ when: 'type', op: 'equals', value: 'attachment', then: 'bottom' }] }).ids.length, 7);
check('multiple top rows keep their order, not reversed',
  run({ rules: [{ when: 'type', op: 'equals', value: 'attachment', then: 'top' }] }).ids,
  ['6837', '5069', '5002', '43', '6063', '4930', '11487']);
check('top and bottom together',
  run({ rules: [
    { when: 'url', op: 'contains', value: '/powerschool/', then: 'top' },
    { when: 'type', op: 'equals', value: 'attachment', then: 'bottom' }
  ] }).ids, ['11487', '5002', '43', '6063', '4930', '6837', '5069']);
check('a sunk row can still be hidden by an earlier rule',
  run({ rules: [
    { when: 'url', op: 'regex', value: '\\.jpe?g$', then: 'hide' },
    { when: 'type', op: 'equals', value: 'attachment', then: 'bottom' }
  ] }).ids, ['5002', '43', '6063', '4930', '11487', '6837']);

console.log('\nfinding the search term when the bridge is absent');
// A SearchWP module on a Beaver Builder page: is_search() is false, so
// snippet #1 never printed and window.ACPS_SEARCH carries no query.
check('?swps= is read — the parameter this site actually uses',
  run({ search: '/search/?swp_form%5Bform_id%5D=5&swps=staff', data: { query: '' },
        queryRules: [{ op: 'equals', value: 'staff', then: 'noResults' }] }).ids, []);
check('a multi-word ?swps= term is decoded',
  run({ query: 'staff directory', search: '/search/?swps=staff%20directory', data: { query: '' },
        queryRules: [{ op: 'equals', value: 'staff directory', then: 'noResults' }] }).ids, []);
check('a + encoded ?swps= term is decoded',
  run({ query: 'staff directory', search: '/search/?swps=staff+directory', data: { query: '' },
        queryRules: [{ op: 'equals', value: 'staff directory', then: 'noResults' }] }).ids, []);
check('swps wins over a stale ?s= on the same URL',
  run({ search: '/search/?s=payroll&swps=staff', data: { query: '' },
        queryRules: [{ op: 'equals', value: 'staff', then: 'noResults' }] }).ids, []);
check('?swpquery= is read when ?s= is absent',
  run({ search: '/search-results/?swpquery=staff', data: { query: '' },
        queryRules: [{ op: 'equals', value: 'staff', then: 'noResults' }] }).ids, []);
check('the term is recovered from the results notice with no parameter at all',
  run({ search: '/search-results/', data: { query: '' },
        queryRules: [{ op: 'equals', value: 'staff', then: 'noResults' }] }).ids, []);
check('a non-matching term recovered from the notice does not fire',
  run({ search: '/search-results/', data: { query: '' },
        queryRules: [{ op: 'equals', value: 'payroll', then: 'noResults' }] }).ids.length, 7);
check('queryParams from the bridge are honoured',
  run({ search: '/results/?myterm=staff', data: { query: '', queryParams: ['myterm'] },
        queryRules: [{ op: 'equals', value: 'staff', then: 'noResults' }] }).ids, []);
check('result rules still work with no bridge at all',
  run({ search: '/search-results/?swpquery=staff', data: { query: '' },
        rules: [{ when: 'type', op: 'equals', value: 'attachment', then: 'hide' }] }).ids.length, 5);

console.log('\nthe count rewrite settles');
check('the total is decremented by what was filtered, not replaced',
  run({ rules: [{ when: 'type', op: 'equals', value: 'attachment', then: 'hide' }] }).notice,
  'Found 269 results for staff');
check('filtering nothing leaves the plugin\'s own total untouched',
  run({}).notice, 'Found 271 results for staff');
check('a blocked query zeroes it outright',
  run({ queryRules: [{ op: 'equals', value: 'staff', then: 'noResults' }] }).notice,
  'Found 0 results for staff');

console.log(`\n${pass} passed, ${fail} failed\n`);
process.exit(fail ? 1 : 0);
