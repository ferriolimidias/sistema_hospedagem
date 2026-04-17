const fs = require('fs');

const codeStr = fs.readFileSync('c:/laragon/www/recantodaserra/admin/admin.js', 'utf8');

// Replace DOMContentLoaded wrapper to make it instantly callable
const runnableCode = codeStr
    .replace("document.addEventListener('DOMContentLoaded', () => {", "function initApp() {")
    .replace(/}\);[\s]*$/, "} initApp();");

const mockDOM = `
  global.window = { location: {}, innerWidth: 1024 };
  global.document = {
    getElementById: (id) => {
      if (id === 'app') return global.appContainer;
      return { addEventListener: () => {}, contains: () => false, classList: { toggle: () => {}, remove: () => {} } };
    },
    addEventListener: () => {},
    querySelectorAll: () => []
  };
  global.localStorage = { getItem: () => 'valid_token', clear: () => {} };
  global.appContainer = { innerHTML: '' };
  
  // Fake API data with valid dates so we don't hit other errors
  global.fetch = async (url) => {
    return {
      ok: true,
      json: async () => {
         if (url.includes('chalets.php')) return [{id: 1, name: 'C', type: 'D', price: 10, status: 'Ativo'}];
         if (url.includes('reservations.php')) return [{id: 1, guest_name: 'A', chalet_name: 'B', checkin_date: '2026-03-01', checkout_date: '2026-03-05', total_amount: 100, status: 'Confirmada'}];
         return {};
      }
    };
  };
  global.alert = console.log;
  global.console.warn = () => {};
`;

try {
    eval(mockDOM + runnableCode + "\n\nconsole.log('AppContainer HTML length:', global.appContainer.innerHTML.length);");
} catch (e) {
    console.error("CAUGHT ERROR:", e);
}
