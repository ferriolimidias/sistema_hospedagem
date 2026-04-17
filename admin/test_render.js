const fs = require('fs');

let code = fs.readFileSync('c:/laragon/www/recantodaserra/admin/admin.js', 'utf8');
code = code.replace("document.addEventListener('DOMContentLoaded', () => {", "global.initApp = async function() {")
    .replace(/}\);\s*$/, "} ");

let htmlOutput = "";

global.window = { location: {}, innerWidth: 1024 };
global.document = {
    getElementById: (id) => {
        if (id === 'app') return { set innerHTML(val) { htmlOutput = val; }, get innerHTML() { return htmlOutput; } };
        return { addEventListener: () => { }, classList: { toggle: () => { }, remove: () => { } } };
    },
    addEventListener: () => { },
    querySelectorAll: () => []
};
global.localStorage = { getItem: () => 'valid', clear: () => { } };
global.fetch = async (url) => ({
    ok: true,
    json: async () => {
        if (url.includes('chalets.php')) return [{ id: 1, name: 'C', type: 'D', price: 10, status: 'Ativo' }];
        if (url.includes('reservations.php')) return [{ id: 1, guest_name: 'A', chalet_name: 'B', checkin_date: '2026-03-01', checkout_date: '2026-03-05', total_amount: 100, status: 'Confirmada' }];
        return {};
    }
});
global.alert = console.log;

process.on('unhandledRejection', (reason, promise) => {
    console.log('UNHANDLED REJECTION:', reason);
    process.exit(1);
});

eval(code);

(async function () {
    await global.initApp();
    // wait a bit for async renderView to finish internally
    await new Promise(r => setTimeout(r, 1000));
    console.log("SUCCESS. HTML LENGTH:", htmlOutput.length);
})();
