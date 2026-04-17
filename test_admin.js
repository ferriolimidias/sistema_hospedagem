const fs = require('fs');
const jsdom = require('jsdom');
const { JSDOM } = jsdom;

const code = fs.readFileSync('c:/laragon/www/recantodaserra/admin/admin.js', 'utf8');
const html = '<div id=\"app\"></div><div id=\"sidebar\"></div><button id=\"toggleSidebar\"></button><button id=\"mobileToggle\"></button>';

const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'http://localhost/admin/index.html' });
dom.window.localStorage = { getItem: () => 'token' };

dom.window.fetch = async (url) => ({
    ok: true,
    json: async () => url.includes('chalets') ? [{id:1, name:'A', type:'B', price:10, status:'Ativo'}] : [{id:1, guest_name:'A', chalet_name:'B', checkin_date:'2026-03-01', checkout_date:'2026-03-05', total_amount:100, status:'Confirmada'}]
});

dom.window.eval(code);

setTimeout(() => {
    console.log('App HTML:', dom.window.document.getElementById('app').innerHTML.substring(0, 100));
}, 2000);
