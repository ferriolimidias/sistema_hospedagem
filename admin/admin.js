

document.addEventListener('DOMContentLoaded', () => {

    function normalizeRole(value) {
        let raw = value;
        if (typeof raw === 'string') {
            const trimmed = raw.trim();
            if ((trimmed.startsWith('"') && trimmed.endsWith('"')) || (trimmed.startsWith("'") && trimmed.endsWith("'"))) {
                raw = trimmed.slice(1, -1);
            } else {
                try {
                    const parsed = JSON.parse(trimmed);
                    if (typeof parsed === 'string') raw = parsed;
                } catch {
                    raw = trimmed;
                }
            }
        }
        return String(raw || '')
            .trim()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    function isSecretaryRole(value) {
        const normalized = normalizeRole(value);
        return normalized === 'secretaria' || normalized.startsWith('secretari');
    }

    /* =========================================
       HÓSPEDES — utilidades espelhadas do site público
       ========================================= */
    function parseGuestsOptionAdmin(guestsVal, fallbackAdults = 1) {
        const raw = String(guestsVal || '').trim();
        if (raw === '') return { adults: fallbackAdults, children: 0 };
        if (raw.indexOf('_') === -1) {
            const n = parseInt(raw, 10);
            return n > 0 ? { adults: n, children: 0 } : { adults: fallbackAdults, children: 0 };
        }
        const [ad, ch] = raw.split('_').map(Number);
        return {
            adults: ad > 0 ? ad : fallbackAdults,
            children: Number.isFinite(ch) && ch >= 0 ? ch : 0
        };
    }

    function renderGuestOptionsAdmin(selectEl, maxGuests, preferredValue) {
        if (!selectEl) return;
        const cap = Math.max(1, parseInt(String(maxGuests || 4), 10) || 4);
        selectEl.innerHTML = '';
        const frag = document.createDocumentFragment();
        for (let i = 1; i <= cap; i++) {
            const opt = document.createElement('option');
            opt.value = String(i);
            opt.textContent = i === 1 ? '1 Hóspede' : `${i} Hóspedes`;
            frag.appendChild(opt);
        }
        selectEl.appendChild(frag);

        const p = parseGuestsOptionAdmin(preferredValue, 1);
        let prefNum = p.adults + p.children;
        if (!(prefNum >= 1 && prefNum <= cap)) {
            prefNum = Math.min(2, cap);
        }
        selectEl.value = String(prefNum);
    }

    /* =========================================
       AUTHENTICATION GUARD
       ========================================= */
    const adminToken = localStorage.getItem('adminToken');
    const adminRoleRaw = localStorage.getItem('adminRole') || 'admin';
    const adminRole = normalizeRole(adminRoleRaw);

    if (!adminToken) {
        // Redireciona para login se não estiver autenticado
        window.location.href = 'login.html';
        return;
    }

    // Controle de menus por permissões
    const adminPermissions = (() => {
        try {
            const p = localStorage.getItem('adminPermissions');
            return p ? JSON.parse(p) : null;
        } catch { return null; }
    })();

    const canCreateChalet = !isSecretaryRole(adminRole);
    if (isSecretaryRole(adminRole)) {
        document.body.classList.add('role-secretaria');
    }

    // Administrador total (role=admin) SEMPRE vê todos os menus, mesmo que tenha
    // um adminPermissions antigo cacheado em localStorage sem as novas abas.
    const isFullAdmin = !isSecretaryRole(adminRole);
    if (!isFullAdmin && adminPermissions && Array.isArray(adminPermissions) && adminPermissions.length > 0) {
        // Usuário NÃO-admin com permissões customizadas: mostra apenas os menus permitidos
        document.querySelectorAll('.nav-item[data-view]').forEach(nav => {
            const view = nav.getAttribute('data-view');
            nav.style.display = adminPermissions.includes(view) ? '' : 'none';
        });
    } else if (isSecretaryRole(adminRole)) {
        // Fallback: secretaria sem permissões customizadas vê dashboard, reservas e chalés
        const settingsNav = document.querySelector('.nav-item[data-view="settings"]');
        const customizationNav = document.querySelector('.nav-item[data-view="customization"]');
        const usersNav = document.querySelector('.nav-item[data-view="users"]');
        const financeiroNav = document.querySelector('.nav-item[data-view="financeiro"]');
        const couponsNav = document.querySelector('.nav-item[data-view="coupons"]');
        const extrasNav = document.querySelector('.nav-item[data-view="extras"]');
        if (settingsNav) settingsNav.style.display = 'none';
        if (customizationNav) customizationNav.style.display = 'none';
        if (usersNav) usersNav.style.display = 'none';
        if (financeiroNav) financeiroNav.style.display = 'none';
        if (couponsNav) couponsNav.style.display = 'none';
        if (extrasNav) extrasNav.style.display = 'none';
    }

    /* =========================================
       SIDEBAR & MOBILE MENU
       ========================================= */
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleSidebar');
    const mobileToggle = document.getElementById('mobileToggle');

    // Desktop Toggle
    toggleBtn.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
    });

    // Mobile Toggle
    mobileToggle.addEventListener('click', () => {
        sidebar.classList.toggle('mobile-open');
    });

    // Close mobile sidebar if clicked outside
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 &&
            !sidebar.contains(e.target) &&
            !mobileToggle.contains(e.target)) {
            sidebar.classList.remove('mobile-open');
        }
    });

    /* =========================================
       VIEWS LOGIC (SPA)
       ========================================= */
    const appContainer = document.getElementById('app');
    const navItems = document.querySelectorAll('.nav-item');

    // Global Data Holders
    let chaletsData = [];
    let reservationsData = [];
    let internalApiKey = '';
    let paymentPolicies = [
        { code: 'half', label: 'Sinal de 50% para reserva', percent_now: 50 },
        { code: 'full', label: 'Pagamento 100% Antecipado', percent_now: 100 }
    ];

    function normalizePaymentPolicies(rawPolicies) {
        if (!Array.isArray(rawPolicies) || rawPolicies.length === 0) return paymentPolicies;
        const clean = rawPolicies
            .map((p) => ({
                code: String(p && p.code ? p.code : '').trim().toLowerCase(),
                label: String(p && p.label ? p.label : '').trim(),
                percent_now: Number(p && p.percent_now != null ? p.percent_now : NaN)
            }))
            .filter((p) => p.code && p.label && Number.isFinite(p.percent_now) && p.percent_now > 0)
            .map((p) => ({ ...p, percent_now: Math.min(100, Math.max(0, p.percent_now)) }));
        return clean.length > 0 ? clean : paymentPolicies;
    }

    function getPaymentPolicy(ruleCode) {
        const key = String(ruleCode || '').toLowerCase();
        return paymentPolicies.find((p) => p.code === key) || (key === 'half'
            ? { code: 'half', label: 'Sinal de 50% para reserva', percent_now: 50 }
            : { code: 'full', label: 'Pagamento 100% Antecipado', percent_now: 100 });
    }

    async function fetchApiData() {
        try {
            const [resChalets, resReservations, resBookingOptions] = await Promise.all([
                fetch('../api/chalets.php').then(res => res.json()),
                fetch('../api/reservations.php').then(res => res.json()),
                fetch('../api/booking_options.php').then(res => res.json()).catch(() => ({}))
            ]);
            chaletsData = Array.isArray(resChalets) ? resChalets : [];
            reservationsData = Array.isArray(resReservations) ? resReservations : [];
            paymentPolicies = normalizePaymentPolicies(resBookingOptions.payment_policies);
            window.reservationsDataGlobal = reservationsData; // Expose to global
        } catch (e) {
            console.error("Erro ao buscar dados da API:", e);
        }
    }

    async function ensureInternalApiKey() {
        if (internalApiKey) return true;
        try {
            const res = await fetch('../api/settings.php');
            const data = await res.json();
            if (typeof data.internalApiKey === 'string' && data.internalApiKey.trim() !== '') {
                internalApiKey = data.internalApiKey.trim();
                return true;
            }
        } catch (e) { /* ignore */ }
        return false;
    }

    async function initFinanceiroView() {
        const warn = document.getElementById('financeiroKeyWarn');
        const ok = await ensureInternalApiKey();
        if (warn) warn.style.display = ok ? 'none' : 'block';
        if (!ok) return;

        async function loadStats() {
            try {
                const r = await fetch('../api/finance_stats.php', { headers: { 'X-Internal-Key': internalApiKey } });
                const s = await r.json();
                const rev = document.getElementById('finRevenue');
                const bal = document.getElementById('finBalance');
                const occ = document.getElementById('finOcc');
                const note = document.getElementById('finOccNote');
                if (r.ok) {
                    if (rev) rev.textContent = 'R$ ' + Number(s.revenue_confirmed || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
                    if (bal) bal.textContent = 'R$ ' + Number(s.balance_half_pending || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
                    if (occ) occ.textContent = Number(s.occupancy_pct || 0).toLocaleString('pt-BR', { minimumFractionDigits: 1 }) + ' %';
                    if (note) note.textContent = (s.occupied_room_nights != null && s.capacity_room_nights != null)
                        ? `${s.occupied_room_nights} / ${s.capacity_room_nights} UH·noites (${s.month || ''})`
                        : '';
                }
            } catch (e) {
                console.warn(e);
            }
        }

        async function loadCoupons() {
            const r = await fetch('../api/admin_coupons.php', { headers: { 'X-Internal-Key': internalApiKey } });
            const rows = await r.json();
            const tb = document.getElementById('finCouponsBody');
            if (!tb || !Array.isArray(rows)) return;
            tb.innerHTML = rows.map((c) => `
                <tr>
                    <td>${String(c.code || '').replace(/</g, '&lt;')}</td>
                    <td>${String(c.type || '')}</td>
                    <td>${Number(c.value).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</td>
                    <td>${c.expiry_date ? String(c.expiry_date) : '—'}</td>
                    <td><input type="checkbox" data-coupon-id="${c.id}" class="fin-coupon-active" ${Number(c.active) ? 'checked' : ''}></td>
                    <td><button type="button" class="btn-icon fin-coupon-del" data-id="${c.id}" style="color:var(--danger)"><i class="ph ph-trash"></i></button></td>
                </tr>
            `).join('');
            tb.querySelectorAll('.fin-coupon-del').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const id = btn.getAttribute('data-id');
                    if (!confirm('Excluir este cupom?')) return;
                    await fetch(`../api/admin_coupons.php?id=${id}`, { method: 'DELETE', headers: { 'X-Internal-Key': internalApiKey } });
                    await loadCoupons();
                });
            });
            tb.querySelectorAll('.fin-coupon-active').forEach((cb) => {
                cb.addEventListener('change', async () => {
                    const id = parseInt(cb.getAttribute('data-coupon-id'), 10);
                    const active = cb.checked ? 1 : 0;
                    const list = await fetch('../api/admin_coupons.php', { headers: { 'X-Internal-Key': internalApiKey } }).then((x) => x.json());
                    const c = Array.isArray(list) ? list.find((x) => x.id == id) : null;
                    if (!c) return;
                    await fetch('../api/admin_coupons.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                        body: JSON.stringify({
                            id,
                            code: c.code,
                            type: c.type,
                            value: parseFloat(c.value),
                            expiry_date: c.expiry_date || null,
                            active
                        })
                    });
                });
            });
        }

        async function loadExtras() {
            const r = await fetch('../api/admin_extra_services.php', { headers: { 'X-Internal-Key': internalApiKey } });
            const rows = await r.json();
            const tb = document.getElementById('finExtrasBody');
            if (!tb || !Array.isArray(rows)) return;
            tb.innerHTML = rows.map((x) => `
                <tr>
                    <td>${String(x.name || '').replace(/</g, '&lt;')}</td>
                    <td>R$ ${Number(x.price).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</td>
                    <td><input type="checkbox" data-extra-id="${x.id}" class="fin-extra-active" ${Number(x.active) ? 'checked' : ''}></td>
                    <td><button type="button" class="btn-icon fin-extra-del" data-id="${x.id}" style="color:var(--danger)"><i class="ph ph-trash"></i></button></td>
                </tr>
            `).join('');
            tb.querySelectorAll('.fin-extra-del').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const id = btn.getAttribute('data-id');
                    if (!confirm('Excluir este serviço?')) return;
                    await fetch(`../api/admin_extra_services.php?id=${id}`, { method: 'DELETE', headers: { 'X-Internal-Key': internalApiKey } });
                    await loadExtras();
                });
            });
            tb.querySelectorAll('.fin-extra-active').forEach((cb) => {
                cb.addEventListener('change', async () => {
                    const id = parseInt(cb.getAttribute('data-extra-id'), 10);
                    const active = cb.checked ? 1 : 0;
                    const list = await fetch('../api/admin_extra_services.php', { headers: { 'X-Internal-Key': internalApiKey } }).then((x) => x.json());
                    const x = Array.isArray(list) ? list.find((z) => z.id == id) : null;
                    if (!x) return;
                    await fetch('../api/admin_extra_services.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                        body: JSON.stringify({
                            id,
                            name: x.name,
                            price: parseFloat(x.price),
                            description: x.description || '',
                            active
                        })
                    });
                });
            });
        }

        await loadStats();
        await loadCoupons();
        await loadExtras();

        const refresh = document.getElementById('finRefreshBtn');
        if (refresh) {
            refresh.onclick = async () => {
                await loadStats();
                await loadCoupons();
                await loadExtras();
            };
        }

        const addC = document.getElementById('finCouponAdd');
        if (addC) {
            addC.onclick = async () => {
                const code = document.getElementById('finCouponCode').value.trim();
                const type = document.getElementById('finCouponType').value;
                const value = parseFloat(document.getElementById('finCouponVal').value);
                const expiry = document.getElementById('finCouponExpiry').value;
                if (!code || !(value > 0)) {
                    alert('Informe código e valor válidos.');
                    return;
                }
                const res = await fetch('../api/admin_coupons.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                    body: JSON.stringify({ code, type, value, expiry_date: expiry || null, active: 1 })
                });
                if (res.ok) {
                    document.getElementById('finCouponCode').value = '';
                    document.getElementById('finCouponVal').value = '';
                    await loadCoupons();
                } else {
                    const e = await res.json().catch(() => ({}));
                    alert(e.error || 'Erro ao salvar cupom');
                }
            };
        }

        const addE = document.getElementById('finExAdd');
        if (addE) {
            addE.onclick = async () => {
                const name = document.getElementById('finExName').value.trim();
                const price = parseFloat(document.getElementById('finExPrice').value);
                const description = document.getElementById('finExDesc').value.trim();
                if (!name || !(price >= 0) || Number.isNaN(price)) {
                    alert('Informe nome e preço válidos.');
                    return;
                }
                const res = await fetch('../api/admin_extra_services.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                    body: JSON.stringify({ name, price, description, active: 1 })
                });
                if (res.ok) {
                    document.getElementById('finExName').value = '';
                    document.getElementById('finExPrice').value = '';
                    document.getElementById('finExDesc').value = '';
                    await loadExtras();
                } else {
                    const e = await res.json().catch(() => ({}));
                    alert(e.error || 'Erro ao salvar serviço');
                }
            };
        }
    }

    // Views Templates
    const getViews = () => ({
        dashboard: `
            <div class="page-header">
                <h1 class="page-title">Dashboard</h1>
                <button class="btn"><i class="ph ph-download-simple"></i> Relatório</button>
            </div>
            
            <div class="grid-cards">
                <div class="card stat-card">
                    <div class="stat-icon primary"><i class="ph ph-calendar-check"></i></div>
                    <div class="stat-info">
                        <h3>Total de Reservas</h3>
                        <p>${reservationsData.length}</p>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon success"><i class="ph ph-money"></i></div>
                    <div class="stat-info">
                        <h3>Faturamento Bruto</h3>
                        <p>R$ ${reservationsData.reduce((acc, r) => acc + parseFloat(r.total_amount), 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</p>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="stat-info">
                        <h3>Hospedagens Ativas</h3>
                        <p>${chaletsData.filter(c => c.status === 'Ativo').length}</p>
                    </div>
                    <div class="stat-icon warning"><i class="ph ph-house-line"></i></div>
                </div>
            </div>

            <div class="card">
                <h3 style="margin-bottom: 1.5rem;">Últimas 5 Reservas</h3>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Hóspede</th>
                                <th>Hospedagem</th>
                                <th>Check-in / Out</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${reservationsData.slice(0, 5).map(r => `
                                <tr>
                                    <td><strong>#RES-${String(r.id).padStart(3, '0')}</strong></td>
                                    <td>${r.guest_name}</td>
                                    <td>${r.chalet_name}</td>
                                    <td>${formatDateBR(r.checkin_date)} - ${formatDateBR(r.checkout_date)}</td>
                                    <td><span class="badge ${getStatusClass(r.status)}">${r.status}</span></td>
                                </tr>
                            `).join('')}
                            ${reservationsData.length === 0 ? '<tr><td colspan="5" style="text-align:center;">Nenhuma reserva encontrada</td></tr>' : ''}
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card" style="margin-top: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3>Calendário Geral de Reservas</h3>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <button class="btn-icon" id="prevMonthBtn"><i class="ph ph-caret-left"></i></button>
                        <strong id="calendarMonthYear" style="min-width: 120px; text-align: center;">Junho 2024</strong>
                        <button class="btn-icon" id="nextMonthBtn"><i class="ph ph-caret-right"></i></button>
                    </div>
                </div>
                <!-- Weekdays Header -->
                <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; text-align: center; font-weight: bold; color: var(--text-muted); margin-bottom: 0.5rem;">
                    <div>Dom</div><div>Seg</div><div>Ter</div><div>Qua</div><div>Qui</div><div>Sex</div><div>Sáb</div>
                </div>
                <!-- Days Grid -->
                <div id="dashboardCalendar" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; text-align: center;">
                    <!-- Populated via JS -->
                </div>
                <div style="margin-top: 1rem; font-size: 0.85rem; color: var(--text-muted); display:flex; gap:1rem; align-items: center;">
                   <div style="display:flex; align-items:center; gap:0.25rem;"><span style="display:inline-block; width:12px; height:12px; background:var(--primary); border-radius:3px;"></span> Reserva Confirmada</div>
                   <div style="display:flex; align-items:center; gap:0.25rem;"><span style="display:inline-block; width:12px; height:12px; background:var(--warning); border-radius:3px;"></span> Reserva Pendente</div>
                </div>
            </div>
        `,
        reservations: `
            <div class="page-header">
                <h1 class="page-title">Gestão de Reservas</h1>
                <div style="display: flex; gap: 1rem;">
                    <input type="text" class="form-control" placeholder="Buscar reserva..." style="width: 250px;">
                    <button class="btn" onclick="openEditReservationModal(null)"><i class="ph ph-plus"></i> Nova Reserva</button>
                </div>
            </div>

            <div class="card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Ações</th>
                                <th>Reserva</th>
                                <th>Hóspede</th>
                                <th>Hospedagem</th>
                                <th>Datas</th>
                                <th>Valor Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${reservationsData.map((r, index) => `
                                <tr>
                                    <td>
                                        <button type="button" class="btn-icon" title="Editar" data-action="edit-reservation" data-index="${index}"><i class="ph ph-pencil-simple"></i></button>
                                        ${r.contract_filename
                                            ? `<button type="button" class="btn-icon" title="Ver Contrato PDF" data-action="pdf-reservation" data-index="${index}"><i class="ph ph-file-pdf"></i></button>`
                                            : `<button type="button" class="btn-icon" title="Gerar Contrato Manualmente" data-action="generate-contract" data-index="${index}" style="color:#c96621"><i class="ph ph-file-plus"></i></button>`
                                        }
                                        ${getPaymentPolicy(r.payment_rule || 'full').percent_now < 100 && Number(r.balance_paid || 0) === 0
                                            ? `<button type="button" class="btn-icon" title="Receber Saldo" data-action="pay-balance" data-index="${index}" style="color:#198754"><i class="ph ph-currency-circle-dollar"></i></button>`
                                            : ''
                                        }
                                        <button type="button" class="btn-icon" title="Notificar (Reenviar)" data-action="notify-reservation" data-index="${index}" style="color: #25D366"><i class="ph ph-whatsapp-logo"></i></button>
                                        <button type="button" class="btn-icon" title="Excluir" data-action="delete-reservation" data-id="${r.id}" style="color: var(--danger)"><i class="ph ph-trash"></i></button>
                                    </td>
                                    <td><strong>#RES-${String(r.id).padStart(3, '0')}</strong></td>
                                    <td>${r.guest_name}<br><small style="color:#666">${r.guest_email || ''}</small><br><small style="color:#888">${(r.guests_adults || 0) + (r.guests_children || 0)} hóspede(s)</small></td>
                                    <td>${r.chalet_name}</td>
                                    <td>${formatDateBR(r.checkin_date)} até ${formatDateBR(r.checkout_date)}</td>
                                    <td>
                                        R$ ${parseFloat(r.total_amount).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                                        ${getPaymentPolicy(r.payment_rule || 'full').percent_now < 100 ? `<br><span class="badge warning" style="font-size:0.7rem; padding:0.15rem 0.3rem; margin-top:0.25rem; display:inline-block">${getPaymentPolicy(r.payment_rule || 'full').label}</span>` : ''}
                                        ${Number(r.balance_paid || 0) === 1
                                            ? `<br><span class="badge success" style="font-size:0.7rem; padding:0.15rem 0.3rem; margin-top:0.25rem; display:inline-block">Total Pago</span>${r.balance_paid_at ? `<br><small style="color:#198754; font-size:0.68rem; display:block; margin-top:0.2rem;">${formatBalancePaidAtDisplay(r.balance_paid_at)}</small>` : ''}`
                                            : ''}
                                    </td>
                                    <td>
                                        <select class="form-control status-select status-${getStatusClass(r.status)}" onchange="updateReservationStatus(${r.id}, this.value); this.className='form-control status-select status-' + (this.value === 'Confirmada' ? 'success' : this.value === 'Pendente' ? 'warning' : 'danger');" style="padding: 0.25rem 0.5rem; font-size: 0.85rem; width: auto; font-weight: 600;">
                                            <option ${r.status === 'Pendente' ? 'selected' : ''}>Pendente</option>
                                            <option ${r.status === 'Confirmada' ? 'selected' : ''}>Confirmada</option>
                                            <option ${r.status === 'Cancelada' ? 'selected' : ''}>Cancelada</option>
                                        </select>
                                    </td>
                                </tr>
                            `).join('')}
                            ${reservationsData.length === 0 ? '<tr><td colspan="7" style="text-align:center;">Nenhuma reserva encontrada</td></tr>' : ''}
                        </tbody>
                    </table>
                </div>
            </div>
        `,
        chalets: `
            <div class="page-header">
                <h1 class="page-title">Gerenciar Hospedagens</h1>
                ${canCreateChalet ? '<button class="btn" data-action="add-chalet" onclick="openChaletModal()"><i class="ph ph-plus"></i> Adicionar Hospedagem</button>' : ''}
            </div>

            <div class="card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Preço Base (Noite)</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${chaletsData.map((c, index) => `
                                <tr>
                                    <td>${c.id}</td>
                                    <td><strong>${c.name}</strong></td>
                                    <td>${c.type}</td>
                                    <td>R$ ${parseFloat(c.price).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</td>
                                    <td><span class="badge ${c.status === 'Ativo' ? 'success' : 'warning'}">${c.status}</span></td>
                                    <td>
                                        <button class="btn-icon" onclick="openChaletModal(${index})" title="Editar"><i class="ph ph-pencil-simple"></i></button>
                                        <button class="btn-icon" style="color: var(--danger)" title="Excluir" onclick="deleteChalet(${c.id}, '${(c.name || '').replace(/'/g, "\\'")}')"><i class="ph ph-trash"></i></button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `,
        financeiro: `
            <div class="page-header">
                <h1 class="page-title">Financeiro</h1>
                <button type="button" class="btn" id="finRefreshBtn"><i class="ph ph-arrows-clockwise"></i> Atualizar</button>
            </div>
            <div id="financeiroKeyWarn" class="card" style="display:none;border:1px solid var(--danger);color:var(--danger);margin-bottom:1rem;">
                Não foi possível carregar a chave interna. Abra <strong>Configurações</strong> neste painel e aguarde o carregamento, depois volte aqui.
            </div>
            <div class="grid-cards">
                <div class="card stat-card">
                    <div class="stat-icon success"><i class="ph ph-coins"></i></div>
                    <div class="stat-info">
                        <h3>Receita confirmada</h3>
                        <p id="finRevenue">R$ —</p>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon warning"><i class="ph ph-hourglass"></i></div>
                    <div class="stat-info">
                        <h3>Saldo a receber (reservas parciais)</h3>
                        <p id="finBalance">R$ —</p>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon primary"><i class="ph ph-chart-line-up"></i></div>
                    <div class="stat-info">
                        <h3>Taxa de ocupação (mês)</h3>
                        <p id="finOcc">— %</p>
                        <small id="finOccNote" style="color:var(--text-muted);display:block;margin-top:0.35rem;"></small>
                    </div>
                </div>
            </div>
            <div class="card" style="margin-bottom:1.5rem;">
                <h3 style="margin-bottom:1rem;">Cupons de desconto</h3>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:0.75rem;align-items:end;margin-bottom:1rem;">
                    <div class="form-group" style="margin:0;"><label>Código</label><input type="text" id="finCouponCode" class="form-control" placeholder="PROMO10"></div>
                    <div class="form-group" style="margin:0;"><label>Tipo</label>
                        <select id="finCouponType" class="form-control"><option value="percent">Percentual</option><option value="fixed">Valor fixo</option></select>
                    </div>
                    <div class="form-group" style="margin:0;"><label>Valor</label><input type="number" id="finCouponVal" class="form-control" step="0.01" min="0" placeholder="10"></div>
                    <div class="form-group" style="margin:0;"><label>Validade (opc.)</label><input type="date" id="finCouponExpiry" class="form-control"></div>
                    <button type="button" class="btn btn-primary" id="finCouponAdd">Adicionar</button>
                </div>
                <div class="table-container"><table class="data-table"><thead><tr><th>Código</th><th>Tipo</th><th>Valor</th><th>Validade</th><th>Ativo</th><th></th></tr></thead><tbody id="finCouponsBody"></tbody></table></div>
            </div>
            <div class="card">
                <h3 style="margin-bottom:1rem;">Serviços extras</h3>
                <div style="display:grid;grid-template-columns:2fr 1fr 2fr auto;gap:0.75rem;align-items:end;margin-bottom:1rem;">
                    <div class="form-group" style="margin:0;"><label>Nome</label><input type="text" id="finExName" class="form-control" placeholder="Ex.: Café da manhã"></div>
                    <div class="form-group" style="margin:0;"><label>Preço</label><input type="number" id="finExPrice" class="form-control" step="0.01" min="0"></div>
                    <div class="form-group" style="margin:0;"><label>Descrição</label><input type="text" id="finExDesc" class="form-control" placeholder="Opcional"></div>
                    <button type="button" class="btn btn-primary" id="finExAdd">Adicionar</button>
                </div>
                <div class="table-container"><table class="data-table"><thead><tr><th>Nome</th><th>Preço</th><th>Ativo</th><th></th></tr></thead><tbody id="finExtrasBody"></tbody></table></div>
            </div>
        `,
        settings: `
            <div class="page-header">
                <h1 class="page-title">Configurações do Sistema</h1>
                <button class="btn" onclick="alert('Configurações salvas!')"><i class="ph ph-floppy-disk"></i> Salvar Alterações</button>
            </div>

            <div class="grid-cards" style="grid-template-columns: 1fr 1fr;">
                <div class="card">
                    <h3 style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">Informações Gerais</h3>
                    <form>
                        <div class="form-group">
                            <label>Nome do Estabelecimento</label>
                            <input type="text" class="form-control" value="Recantos da Serra">
                        </div>
                        <div class="form-group">
                            <label>E-mail de Contato</label>
                            <input type="email" class="form-control" value="contato@recantosdaserra.com">
                        </div>
                        <div class="form-group">
                            <label>Telefone Principal</label>
                            <input type="text" class="form-control" value="(35) 99999-9999">
                        </div>
                    </form>
                </div>
                
                <div class="card">
                    <h3 style="margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                        <i class="ph ph-clock" style="color: var(--primary); margin-right: 0.5rem; vertical-align: bottom;"></i>
                        Regras e Horários
                    </h3>
                    <form id="rulesForm">
                        <div class="form-group">
                            <label>Horário de Check-in</label>
                            <input type="time" class="form-control" id="rulesCheckinTime" value="14:00">
                            <small style="color:#666;">Salvo em <code>settings.checkin_time</code>. Usado em contratos, emails e WhatsApp.</small>
                        </div>
                        <div class="form-group">
                            <label>Horário de Check-out</label>
                            <input type="time" class="form-control" id="rulesCheckoutTime" value="12:00">
                            <small style="color:#666;">Salvo em <code>settings.checkout_time</code>.</small>
                        </div>
                        <div style="margin-top: 1rem; text-align: right;">
                            <button type="button" class="btn btn-primary" id="saveRulesBtn">
                                <i class="ph ph-floppy-disk"></i> Salvar Horários
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card" style="grid-column: 1 / -1; margin-top: 1.5rem;">
                    <h3 style="margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                        <i class="ph ph-credit-card" style="color: var(--primary); margin-right: 0.5rem; vertical-align: bottom;"></i>
                        Políticas de Pagamento
                    </h3>
                    <p style="margin-bottom: 1rem; color:#666; font-size:0.9rem;">
                        Cada política define um código (usado em <code>reservations.payment_rule</code>), o rótulo exibido ao cliente e a percentagem cobrada no ato da reserva.
                        Mantenha sempre uma política <strong>full</strong> (100%) para pagamentos integrais.
                    </p>
                    <div id="paymentPoliciesList" style="display:flex; flex-direction:column; gap:0.5rem; margin-bottom:1rem;"></div>
                    <div style="display:flex; gap:0.5rem; justify-content:space-between; align-items:center;">
                        <button type="button" class="btn btn-outline" id="addPolicyBtn">
                            <i class="ph ph-plus"></i> Adicionar política
                        </button>
                        <button type="button" class="btn btn-primary" id="savePoliciesBtn">
                            <i class="ph ph-floppy-disk"></i> Salvar Políticas
                        </button>
                    </div>
                </div>

                <div class="card" style="grid-column: 1 / -1; margin-top: 1.5rem;">
                    <h3 style="margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                        <i class="ph ph-palette" style="color: var(--primary); margin-right: 0.5rem; vertical-align: bottom;"></i>
                        Identidade Visual e SEO
                    </h3>
                    <form id="identitySeoForm">
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label>Título do Site (SEO)</label>
                            <input type="text" class="form-control" id="seoSiteTitle" placeholder="Pousada Mirante do Sol">
                        </div>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label>Descrição do Site (Meta Description)</label>
                            <textarea class="form-control" id="seoMetaDescription" rows="3" placeholder="O seu refúgio com vista para o mar em Governador Celso Ramos."></textarea>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Cor Primária</label>
                                <input type="color" class="form-control" id="seoPrimaryColor" value="#ea580c" style="height: 44px; padding: 0.35rem;">
                            </div>
                            <div class="form-group">
                                <label>Cor Secundária</label>
                                <input type="color" class="form-control" id="seoSecondaryColor" value="#1e293b" style="height: 44px; padding: 0.35rem;">
                            </div>
                        </div>
                        <div style="margin-top: 1.25rem; text-align: right;">
                            <button type="button" class="btn btn-primary" id="saveIdentitySeoBtn">
                                <i class="ph ph-floppy-disk"></i> Salvar Identidade e SEO
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Company Logo Customization -->
                <div class="card" style="grid-column: 1 / -1; margin-top: 1.5rem;">
                    <h3 style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                        <i class="ph ph-image" style="color: var(--primary); margin-right: 0.5rem; vertical-align: bottom;"></i>
                        Personalização: Logotipos da Empresa
                    </h3>
                    <p style="margin-bottom: 1.5rem; color: #666; font-size: 0.9rem;">Envie as versões clara e escura do logotipo da sua empresa para serem exibidas corretamente no cabeçalho e rodapé.</p>
                    <form id="logoForm">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                            <!-- Dark Logo -->
                            <div class="form-group" style="text-align: center; border: 2px dashed var(--border-color); padding: 2rem; border-radius: 8px; background: #fff;">
                                <h4 style="margin-bottom: 1rem; color: var(--text-dark);">Logo Principal (Fundo Claro)</h4>
                                <input type="file" id="companyLogoFile" accept="image/*" style="display:none;" onchange="document.getElementById('logoName').textContent = this.files[0]?.name || ''">
                                <label for="companyLogoFile" class="btn btn-outline" style="cursor: pointer; margin-bottom: 1rem;"><i class="ph ph-upload"></i> Escolher Arquivo</label>
                                <div id="logoName" style="color: #666; font-size: 0.85rem; margin-top: 0.5rem;">Nenhum arquivo selecionado</div>
                                <div id="currentLogoPreview" style="margin-top: 1rem;"></div>
                            </div>
                            
                            <!-- Light Logo -->
                            <div class="form-group" style="text-align: center; border: 2px dashed var(--border-color); padding: 2rem; border-radius: 8px; background: #1a1a1a;">
                                <h4 style="margin-bottom: 1rem; color: #fff;">Logo Alternativa (Fundo Escuro/Rodapé)</h4>
                                <input type="file" id="companyLogoLightFile" accept="image/*" style="display:none;" onchange="document.getElementById('logoLightName').textContent = this.files[0]?.name || ''">
                                <label for="companyLogoLightFile" class="btn btn-outline" style="cursor: pointer; margin-bottom: 1rem; background-color: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.3);"><i class="ph ph-upload"></i> Escolher Arquivo</label>
                                <div id="logoLightName" style="color: #ccc; font-size: 0.85rem; margin-top: 0.5rem;">Nenhum arquivo selecionado</div>
                                <div id="currentLogoLightPreview" style="margin-top: 1rem;"></div>
                            </div>
                        </div>
                        <div style="margin-top: 1.5rem; text-align: right;">
                            <button type="button" class="btn btn-primary" id="saveLogoBtn">
                                <i class="ph ph-floppy-disk"></i> Salvar Logos
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card" style="grid-column: 1 / -1; margin-top: 1.5rem;">
                    <h3 style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                        <i class="ph ph-whatsapp-logo" style="color: #25D366; margin-right: 0.5rem; vertical-align: bottom;"></i>
                        Integração WhatsApp (Evolution API)
                    </h3>
                    <p style="margin-bottom: 1.5rem; color: #666; font-size: 0.9rem;">Configure as credenciais da Evolution API para envio de notificações automatizadas de reservas. Os dados ficam salvos apenas neste navegador (localStorage).</p>
                    <form id="evolutionForm">
                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label>URL Base da Evolution API (ex: https://api.seudominio.com)</label>
                            <input type="url" class="form-control" id="evoUrl" placeholder="https://..." required>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                            <!-- Instância Cliente -->
                            <div style="background-color: var(--bg-light); padding: 1.5rem; border-radius: 8px;">
                                <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Instância: Envio ao Cliente</h4>
                                <div class="form-group">
                                    <label>Nome da Instância</label>
                                    <input type="text" class="form-control" id="evoClientInstance" placeholder="ex: Atendimento" required>
                                </div>
                                <div class="form-group" style="margin-top: 1rem;">
                                    <label>Global API Key</label>
                                    <input type="password" class="form-control" id="evoClientApikey" placeholder="Sua apikey secreta..." required>
                                </div>
                            </div>

                            <!-- Instância Empresa -->
                            <div style="background-color: var(--bg-light); padding: 1.5rem; border-radius: 8px;">
                                <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Instância: Notificação Interna</h4>
                                <div class="form-group">
                                    <label>Nome da Instância</label>
                                    <input type="text" class="form-control" id="evoCompanyInstance" placeholder="ex: Sistema" required>
                                </div>
                                <div class="form-group" style="margin-top: 1rem;">
                                    <label>Global API Key</label>
                                    <input type="password" class="form-control" id="evoCompanyApikey" placeholder="Sua apikey secreta..." required>
                                </div>
                                <div class="form-group" style="margin-top: 1rem;">
                                    <label>WhatsApp Destino (Central da Empresa)</label>
                                    <input type="text" class="form-control" id="evoCompanyPhone" placeholder="Ex: 553599999999" required>
                                </div>
                            </div>
                        </div>

                        <!-- Mensagem de Reserva Customizada -->
                        <div style="margin-top: 1.5rem; background-color: #fff9e6; padding: 1.5rem; border-radius: 8px; border: 1px solid #ffeeba;">
                            <h4 style="margin-bottom: 1rem; color: #856404;">
                                <i class="ph ph-chat-centered-dots" style="margin-right:0.5rem;"></i>
                                Mensagem Automática de Reserva
                            </h4>
                            <p style="font-size: 0.85rem; color: #856404; margin-bottom: 1rem;">
                                Personalize a mensagem que o cliente recebe no WhatsApp assim que faz a reserva.
                            </p>
                            <div class="form-group">
                                <label>Texto da Mensagem</label>
                                <textarea class="form-control" id="evoReservationMsg" rows="6" placeholder="Olá {nome}! Parabéns pela sua reserva..."></textarea>
                                <div style="margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                    <small style="background: #eee; padding: 2px 6px; border-radius: 4px; cursor: help;" title="Nome do Hóspede">{nome}</small>
                                    <small style="background: #eee; padding: 2px 6px; border-radius: 4px; cursor: help;" title="Nome da Hospedagem">{chale}</small>
                                    <small style="background: #eee; padding: 2px 6px; border-radius: 4px; cursor: help;" title="Data de Check-in">{checkin}</small>
                                    <small style="background: #eee; padding: 2px 6px; border-radius: 4px; cursor: help;" title="Data de Check-out">{checkout}</small>
                                    <small style="background: #eee; padding: 2px 6px; border-radius: 4px; cursor: help;" title="Valor total da reserva">{total}</small>
                                    <small style="background: #eee; padding: 2px 6px; border-radius: 4px; cursor: help;" title="Valor efetivamente pago na entrada">{valor_pago}</small>
                                    <small style="background: #eee; padding: 2px 6px; border-radius: 4px; cursor: help;" title="Condição aplicada para a reserva">{condicao}</small>
                                    <small style="background: #eee; padding: 2px 6px; border-radius: 4px; cursor: help;" title="ID da Reserva">{id}</small>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top: 1.5rem; text-align: right;">
                            <button type="button" class="btn btn-primary" id="saveEvolutionBtn">
                                <i class="ph ph-floppy-disk"></i> Salvar Credenciais WhatsApp
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card" style="grid-column: 1 / -1; margin-top: 1.5rem;">
                    <h3 style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                        <i class="ph ph-credit-card" style="color: #009EE3; margin-right: 0.5rem; vertical-align: bottom;"></i>
                        Integração MercadoPago (Checkout Pro)
                    </h3>
                    <div style="margin-bottom: 1rem; padding: 1rem; border: 1px solid #d9eefb; background: #f4fbff; border-radius: 8px;">
                        <p style="margin: 0 0 0.75rem 0; color: #0f4c6d; font-size: 0.9rem; font-weight: 600;">Como criar sua credencial no Mercado Pago</p>
                        <ol style="margin: 0; padding-left: 1.2rem; color: #23536b; font-size: 0.88rem; line-height: 1.6;">
                            <li>Acesse o painel de desenvolvedores: <a href="https://www.mercadopago.com.br/developers/panel/app" target="_blank" style="color:#007bb5;">mercadopago.com.br/developers/panel/app</a></li>
                            <li>Selecione sua aplicação e abra <strong>Credenciais</strong>.</li>
                            <li>Copie o <strong>Access Token de Produção</strong> (prefixo <code>APP_USR-</code>) e cole abaixo.</li>
                            <li>Em <strong>Webhooks</strong>, cadastre o evento <strong>Pagamentos</strong> usando a URL informada abaixo.</li>
                        </ol>
                    </div>
                    <p style="margin-bottom: 1rem; color: #666; font-size: 0.9rem;">Configure seu Access Token de Produção do Mercado Pago para gerar links de pagamento diretamente no modal de reservas.</p>
                    <form id="mpForm">
                        <div class="form-group">
                            <label>URL do Webhook (copie e cole no painel do Mercado Pago)</label>
                            <div style="display:flex; gap:0.5rem;">
                                <input type="text" class="form-control" id="mpWebhookUrl" value="${window.location.origin}/api/mp_webhook.php" readonly>
                                <button
                                    type="button"
                                    class="btn"
                                    style="white-space:nowrap; background-color:#0b7bb5;"
                                    onclick="navigator.clipboard.writeText(document.getElementById('mpWebhookUrl').value).then(() => alert('URL do webhook copiada!'))"
                                >
                                    <i class="ph ph-copy"></i> Copiar
                                </button>
                            </div>
                            <small style="display:block; margin-top:0.4rem; color:#777;">Evento recomendado: <strong>Pagamentos</strong> (type=payment).</small>
                        </div>
                        <div class="form-group">
                            <label>Access Token (Produção)</label>
                            <input type="password" class="form-control" id="mpAccessToken" placeholder="APP_USR-..." required>
                        </div>
                        <div style="margin-top: 1.5rem; text-align: right;">
                            <button type="button" class="btn btn-primary" id="saveMpBtn" style="background-color: #009EE3; border-color: #009EE3;">
                                <i class="ph ph-floppy-disk"></i> Salvar Credenciais MercadoPago
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Redes Sociais Customization -->
                <div class="card" style="grid-column: 1 / -1; margin-top: 1.5rem;">
                    <h3 style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                        <i class="ph ph-share-network" style="color: var(--primary); margin-right: 0.5rem; vertical-align: bottom;"></i>
                        Links das Redes Sociais
                    </h3>
                    <p style="margin-bottom: 1.5rem; color: #666; font-size: 0.9rem;">Insira os links para as redes sociais que serão exibidos no site.</p>
                    <form id="socialForm">
                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label>Link do Instagram</label>
                            <input type="url" class="form-control" id="socialInstagram" placeholder="https://instagram.com/recantodaserra">
                        </div>
                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label>Link do Facebook</label>
                            <input type="url" class="form-control" id="socialFacebook" placeholder="https://facebook.com/recantodaserra">
                        </div>
                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label>Link do TripAdvisor</label>
                            <input type="url" class="form-control" id="socialTripadvisor" placeholder="https://tripadvisor.com/...">
                        </div>
                        <div style="margin-top: 1.5rem; text-align: right;">
                            <button type="button" class="btn btn-primary" id="saveSocialBtn">
                                <i class="ph ph-floppy-disk"></i> Salvar Redes Sociais
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `,
        customization: `
            <div class="page-header">
                <h1 class="page-title">Personalização do Site</h1>
                <button class="btn" id="saveCustomizationBtn"><i class="ph ph-floppy-disk"></i> Salvar Alterações</button>
            </div>

            <div class="grid-cards" style="grid-template-columns: 1fr;">
                <!-- Favicon -->
                <div class="accordion-item">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-browser" style="color: var(--primary);"></i> Favicon (Ícone da Aba)</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                    <p style="margin-bottom: 1rem; color: #666; font-size:0.9rem;">O favicon aparece na aba do navegador. Use uma imagem quadrada (ex: 32x32 ou 64x64 px) em PNG ou ICO.</p>
                    <div style="text-align: center; border: 2px dashed var(--border-color); padding: 1.5rem; border-radius: 8px; background: var(--bg-light);">
                        <h4 style="margin-bottom: 1rem; color: var(--text-dark);">Favicon do Site</h4>
                        <input type="file" id="customFaviconImage" accept="image/x-icon,image/png,image/svg+xml" style="display:none;" onchange="document.getElementById('faviconImgName').textContent = this.files[0]?.name || 'Nenhum arquivo selecionado';">
                        <label for="customFaviconImage" class="btn btn-outline" style="cursor: pointer; margin-bottom: 1rem;"><i class="ph ph-upload"></i> Escolher Favicon</label>
                        <div id="faviconImgName" style="color: #666; font-size: 0.85rem; margin-top: 0.5rem;">Nenhum arquivo selecionado</div>
                        <div id="currentFaviconPreview" style="margin-top: 1rem;"></div>
                    </div>
                        </div>
                    </div>
                </div>

                <!-- Hero Section -->
                <div class="accordion-item open">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-image" style="color: var(--primary);"></i> Página Inicial (Hero)</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                    <form id="heroForm">
                        <div class="form-group">
                            <label>Título Principal</label>
                            <input type="text" class="form-control" id="customHeroTitle" placeholder="Ex: Seu Refúgio de Luxo na Natureza">
                        </div>
                        <div class="form-group">
                            <label>Subtítulo</label>
                            <textarea class="form-control" id="customHeroSubtitle" rows="2" placeholder="Ex: Desconecte-se da rotina..."></textarea>
                        </div>
                        <div class="form-group" style="text-align: center; border: 2px dashed var(--border-color); padding: 1.5rem; border-radius: 8px; background: var(--bg-light);">
                            <h4 style="margin-bottom: 1rem; color: var(--text-dark);">Imagens de Fundo (Hero) - Slideshow</h4>
                            <p style="font-size: 0.85rem; color: #666; margin-bottom: 1rem;">Selecione várias imagens para o slideshow. As imagens passam automaticamente na página inicial.</p>
                            <input type="file" id="customHeroImages" accept="image/*" multiple style="display:none;" onchange="const n = this.files.length; document.getElementById('heroImgName').textContent = n ? n + ' imagem(ns) selecionada(s)' : 'Nenhum arquivo selecionado';">
                            <label for="customHeroImages" class="btn btn-outline" style="cursor: pointer; margin-bottom: 1rem;"><i class="ph ph-upload"></i> Escolher Imagens</label>
                            <div id="heroImgName" style="color: #666; font-size: 0.85rem; margin-top: 0.5rem;">Nenhum arquivo selecionado</div>
                            <div id="currentHeroPreview" style="margin-top: 1rem; display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: center;"></div>
                        </div>
                    </form>
                        </div>
                    </div>
                </div>

                <!-- Sobre Nós -->
                <div class="accordion-item">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-info" style="color: var(--primary);"></i> Seção: Sobre Nós</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                    <form id="aboutForm">
                        <div class="form-group">
                            <label>Título da Seção</label>
                            <input type="text" class="form-control" id="customAboutTitle" placeholder="Ex: Uma experiência imersiva">
                        </div>
                        <div class="form-group">
                            <label>Texto Principal (Suporta HTML básico para negrito, ex: &lt;strong&gt;texto&lt;/strong&gt;)</label>
                            <textarea class="form-control" id="customAboutText" rows="4" placeholder="Nascido do desejo de integrar conforto..."></textarea>
                        </div>
                        <div class="form-group" style="text-align: center; border: 2px dashed var(--border-color); padding: 1.5rem; border-radius: 8px; background: var(--bg-light);">
                            <h4 style="margin-bottom: 1rem; color: var(--text-dark);">Imagem da Seção Sobre</h4>
                            <input type="file" id="customAboutImage" accept="image/*" style="display:none;" onchange="document.getElementById('aboutImgName').textContent = this.files[0]?.name || ''">
                            <label for="customAboutImage" class="btn btn-outline" style="cursor: pointer; margin-bottom: 1rem;"><i class="ph ph-upload"></i> Escolher Arquivo</label>
                            <div id="aboutImgName" style="color: #666; font-size: 0.85rem; margin-top: 0.5rem;">Nenhum arquivo selecionado</div>
                            <div id="currentAboutPreview" style="margin-top: 1rem;"></div>
                        </div>
                    </form>
                        </div>
                    </div>
                </div>

                <!-- Seção Hospedagens / Acomodações -->
                <div class="accordion-item">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-house" style="color: var(--primary);"></i> Seção Hospedagens (Acomodações)</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                    <p style="margin-bottom: 1rem; color: #666; font-size:0.9rem;">Altere os textos do cabeçalho da seção de chalés na página inicial.</p>
                    <div class="form-group">
                        <label>Subtítulo (categoria em destaque)</label>
                        <input type="text" class="form-control" id="customChaletsSubtitle" placeholder="Nossas Acomodações">
                    </div>
                    <div class="form-group">
                        <label>Título Principal</label>
                        <input type="text" class="form-control" id="customChaletsTitle" placeholder="Escolha seu Refúgio">
                    </div>
                    <div class="form-group">
                        <label>Descrição</label>
                        <input type="text" class="form-control" id="customChaletsDesc" placeholder="Designs únicos pensados para proporcionar o máximo de conforto em meio às montanhas.">
                    </div>
                        </div>
                    </div>
                </div>

                <!-- Diferenciais -->
                <div class="accordion-item">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-star" style="color: var(--primary);"></i> Comodidades Premium (Diferenciais)</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                    <p style="margin-bottom: 1rem; color: #666; font-size:0.9rem;">Altere o título e descrição dos 5 diferenciais exibidos na página inicial (Comodidades Premium).</p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div style="background: var(--bg-light); padding: 1rem; border-radius: 8px;">
                            <h4>Item 1 – Wi-Fi</h4>
                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label>Título</label>
                                <input type="text" class="form-control" id="customFeat1Title" placeholder="Wi-Fi rápido 📶">
                            </div>
                            <div class="form-group">
                                <label>Descrição</label>
                                <input type="text" class="form-control" id="customFeat1Desc" placeholder="Internet de alta velocidade para você ficar conectado.">
                            </div>
                        </div>
                        <div style="background: var(--bg-light); padding: 1rem; border-radius: 8px;">
                            <h4>Item 2 – Cozinha</h4>
                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label>Título</label>
                                <input type="text" class="form-control" id="customFeat2Title" placeholder="Cozinha completa 🍳">
                            </div>
                            <div class="form-group">
                                <label>Descrição</label>
                                <input type="text" class="form-control" id="customFeat2Desc" placeholder="Cozinha equipada para preparar suas refeições com conforto.">
                            </div>
                        </div>
                        <div style="background: var(--bg-light); padding: 1rem; border-radius: 8px;">
                            <h4>Item 3 – Estacionamento</h4>
                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label>Título</label>
                                <input type="text" class="form-control" id="customFeat3Title" placeholder="Estacionamento 🚗">
                            </div>
                            <div class="form-group">
                                <label>Descrição</label>
                                <input type="text" class="form-control" id="customFeat3Desc" placeholder="Vaga de estacionamento para seu veículo.">
                            </div>
                        </div>
                        <div style="background: var(--bg-light); padding: 1rem; border-radius: 8px;">
                            <h4>Item 4 – Ambiente</h4>
                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label>Título</label>
                                <input type="text" class="form-control" id="customFeat4Title" placeholder="Ambiente confortável 🛏️">
                            </div>
                            <div class="form-group">
                                <label>Descrição</label>
                                <input type="text" class="form-control" id="customFeat4Desc" placeholder="Espaço aconchegante para relaxar e descansar.">
                            </div>
                        </div>
                        <div style="background: var(--bg-light); padding: 1rem; border-radius: 8px;">
                            <h4>Item 5 – Pet friendly</h4>
                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label>Título</label>
                                <input type="text" class="form-control" id="customFeat5Title" placeholder="Pet friendly 🐾">
                            </div>
                            <div class="form-group">
                                <label>Descrição</label>
                                <input type="text" class="form-control" id="customFeat5Desc" placeholder="Seu amigo de quatro patas é muito bem-vindo aqui.">
                            </div>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>

                <!-- O que dizem sobre nós (Depoimentos) -->
                <div class="accordion-item">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-chat-centered-text" style="color: var(--primary);"></i> O que dizem sobre nós</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                    <p style="margin-bottom: 1rem; color: #666; font-size:0.9rem;">Personalize os 3 depoimentos que aparecem na página principal.</p>
                    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">
                        <!-- Depoimento 1 -->
                        <div style="background: var(--bg-light); padding: 1rem; border-radius: 8px;">
                            <h4>Depoimento 1</h4>
                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label>Nome do Autor</label>
                                <input type="text" class="form-control" id="customTesti1Name" placeholder="Ex: Mariana Costa">
                            </div>
                            <div class="form-group">
                                <label>Localização (Ex: São Paulo, SP)</label>
                                <input type="text" class="form-control" id="customTesti1Location" placeholder="São Paulo, SP">
                            </div>
                            <div class="form-group">
                                <label>Texto do Depoimento (Aspas já incluídas no design do site)</label>
                                <textarea class="form-control" id="customTesti1Text" rows="3" placeholder="Simplesmente perfeito!..."></textarea>
                            </div>
                            <div class="form-group" style="text-align: center; border: 2px dashed var(--border-color); padding: 1rem; border-radius: 8px; background: #fff;">
                                <h5 style="margin-bottom: 0.5rem; color: var(--text-dark);">Foto do Autor</h5>
                                <input type="file" id="customTesti1Image" accept="image/*" style="display:none;" onchange="document.getElementById('testi1ImgName').textContent = this.files[0]?.name || ''">
                                <label for="customTesti1Image" class="btn btn-outline btn-sm" style="cursor: pointer; margin-bottom: 0.5rem;"><i class="ph ph-upload"></i> Escolher Arquivo</label>
                                <div id="testi1ImgName" style="color: #666; font-size: 0.8rem;">Se nenhuma foto for enviada, o sistema usará a foto atual ou um avatar padrão.</div>
                                <div id="currentTesti1Preview" style="margin-top: 0.5rem;"></div>
                            </div>
                        </div>
                        
                        <!-- Depoimento 2 -->
                        <div style="background: var(--bg-light); padding: 1rem; border-radius: 8px;">
                            <h4>Depoimento 2</h4>
                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label>Nome do Autor</label>
                                <input type="text" class="form-control" id="customTesti2Name" placeholder="Ex: Pedro Henrique">
                            </div>
                            <div class="form-group">
                                <label>Localização (Ex: Belo Horizonte, MG)</label>
                                <input type="text" class="form-control" id="customTesti2Location" placeholder="Belo Horizonte, MG">
                            </div>
                            <div class="form-group">
                                <label>Texto do Depoimento</label>
                                <textarea class="form-control" id="customTesti2Text" rows="3" placeholder="O lugar mais aconchegante..."></textarea>
                            </div>
                            <div class="form-group" style="text-align: center; border: 2px dashed var(--border-color); padding: 1rem; border-radius: 8px; background: #fff;">
                                <h5 style="margin-bottom: 0.5rem; color: var(--text-dark);">Foto do Autor</h5>
                                <input type="file" id="customTesti2Image" accept="image/*" style="display:none;" onchange="document.getElementById('testi2ImgName').textContent = this.files[0]?.name || ''">
                                <label for="customTesti2Image" class="btn btn-outline btn-sm" style="cursor: pointer; margin-bottom: 0.5rem;"><i class="ph ph-upload"></i> Escolher Arquivo</label>
                                <div id="testi2ImgName" style="color: #666; font-size: 0.8rem;">Se nenhuma foto for enviada, o sistema usará a foto atual ou um avatar padrão.</div>
                                <div id="currentTesti2Preview" style="margin-top: 0.5rem;"></div>
                            </div>
                        </div>

                        <!-- Depoimento 3 -->
                        <div style="background: var(--bg-light); padding: 1rem; border-radius: 8px;">
                            <h4>Depoimento 3</h4>
                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label>Nome do Autor</label>
                                <input type="text" class="form-control" id="customTesti3Name" placeholder="Ex: Juliana Alves">
                            </div>
                            <div class="form-group">
                                <label>Localização (Ex: Campinas, SP)</label>
                                <input type="text" class="form-control" id="customTesti3Location" placeholder="Campinas, SP">
                            </div>
                            <div class="form-group">
                                <label>Texto do Depoimento</label>
                                <textarea class="form-control" id="customTesti3Text" rows="3" placeholder="Muito bom poder viajar..."></textarea>
                            </div>
                            <div class="form-group" style="text-align: center; border: 2px dashed var(--border-color); padding: 1rem; border-radius: 8px; background: #fff;">
                                <h5 style="margin-bottom: 0.5rem; color: var(--text-dark);">Foto do Autor</h5>
                                <input type="file" id="customTesti3Image" accept="image/*" style="display:none;" onchange="document.getElementById('testi3ImgName').textContent = this.files[0]?.name || ''">
                                <label for="customTesti3Image" class="btn btn-outline btn-sm" style="cursor: pointer; margin-bottom: 0.5rem;"><i class="ph ph-upload"></i> Escolher Arquivo</label>
                                <div id="testi3ImgName" style="color: #666; font-size: 0.8rem;">Se nenhuma foto for enviada, o sistema usará a foto atual ou um avatar padrão.</div>
                                <div id="currentTesti3Preview" style="margin-top: 0.5rem;"></div>
                            </div>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>

                <!-- Como Chegar / Localização -->
                <div class="accordion-item">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-map-pin" style="color: var(--primary);"></i> Como Chegar - Nossa Localização</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                    <form id="locationForm">
                        <div class="form-group">
                            <label>Endereço Completo</label>
                            <input type="text" class="form-control" id="customLocAddress" placeholder="Recanto da Serra Eco Park - Serra da Mantiqueira, MG">
                        </div>
                        <div class="form-group">
                            <label>Instruções (De Carro)</label>
                            <input type="text" class="form-control" id="customLocCar" placeholder="Apenas 2h30 da capital. Estrada 100% asfaltada até a entrada.">
                        </div>
                        <div class="form-group">
                            <label>Link Direto para o Google Maps (Botão)</label>
                            <input type="url" class="form-control" id="customLocMapLink" placeholder="https://www.google.com/maps/...">
                        </div>
                    </form>
                        </div>
                    </div>
                </div>

                <!-- WhatsApp Flutuante -->
                <div class="accordion-item">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-whatsapp-logo" style="color: var(--primary);"></i> WhatsApp Flutuante</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                    <p style="margin-bottom: 1rem; color: #666; font-size:0.9rem;">Configure o botão flutuante de WhatsApp que aparece no canto da tela.</p>
                    <form id="whatsappForm">
                        <div class="form-group">
                            <label>Número do WhatsApp (apenas números, com DDD)</label>
                            <input type="text" class="form-control" id="customWaNumber" placeholder="5511999999999">
                        </div>
                        <div class="form-group">
                            <label>Mensagem Padrão Inicial</label>
                            <input type="text" class="form-control" id="customWaMessage" placeholder="Olá, gostaria de mais informações!">
                        </div>
                    </form>
                        </div>
                    </div>
                </div>

                <!-- Rodapé (Footer) -->
                <div class="accordion-item">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-layout" style="color: var(--primary);"></i> Rodapé Completo</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                    <form id="footerForm">
                        <div class="form-group">
                            <label>Descrição Curta da Marca</label>
                            <input type="text" class="form-control" id="customFooterDesc" placeholder="Luxo, conforto e natureza em perfeita harmonia.">
                        </div>
                        <div class="form-group" style="display:flex; gap: 1rem;">
                            <div style="flex: 1;">
                                <label>Endereço Curto (Ícone Pino)</label>
                                <input type="text" class="form-control" id="customFooterAddress" placeholder="Serra da Mantiqueira, MG">
                            </div>
                            <div style="flex: 1;">
                                <label>E-mail de Contato</label>
                                <input type="email" class="form-control" id="customFooterEmail" placeholder="contato@recantosdaserra.com">
                            </div>
                            <div style="flex: 1;">
                                <label>Telefone de Contato</label>
                                <input type="text" class="form-control" id="customFooterPhone" placeholder="(35) 99999-9999">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Texto de Copyright</label>
                            <input type="text" class="form-control" id="customFooterCopyright" placeholder="&copy; 2026 Recantos da Serra. Todos os direitos reservados.">
                        </div>
                    </form>
                        </div>
                    </div>
                </div>
            </div>
        `,
        coupons: `
            <div class="page-header">
                <h1 class="page-title">Cupons de Desconto</h1>
                <button type="button" class="btn" id="couponsRefreshBtn"><i class="ph ph-arrows-clockwise"></i> Atualizar</button>
            </div>
            <div id="couponsKeyWarn" class="card" style="display:none; border:1px solid var(--danger); color:var(--danger); margin-bottom:1rem;">
                Não foi possível carregar a chave interna. Abra <strong>Configurações</strong> neste painel e volte aqui.
            </div>
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-bottom: 1rem;">
                    <i class="ph ph-plus-circle" style="color: var(--primary); vertical-align: bottom;"></i>
                    Novo Cupom
                </h3>
                <form id="couponForm" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.75rem; align-items:end;">
                    <input type="hidden" id="couponEditId" value="">
                    <div class="form-group" style="margin:0;">
                        <label>Código</label>
                        <input type="text" id="couponCode" class="form-control" placeholder="PROMO10" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Tipo</label>
                        <select id="couponType" class="form-control">
                            <option value="percent">Percentual</option>
                            <option value="fixed">Valor fixo</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Valor</label>
                        <input type="number" id="couponValue" class="form-control" step="0.01" min="0" placeholder="10" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Validade (opc.)</label>
                        <input type="date" id="couponExpiry" class="form-control">
                    </div>
                    <div class="form-group" style="margin:0; display:flex; align-items:center; gap:0.5rem; padding-top: 1.5rem;">
                        <input type="checkbox" id="couponActive" checked>
                        <label for="couponActive" style="margin:0;">Ativo</label>
                    </div>
                    <div style="display:flex; gap:0.5rem;">
                        <button type="submit" class="btn btn-primary" id="couponSaveBtn"><i class="ph ph-floppy-disk"></i> Salvar</button>
                        <button type="button" class="btn btn-outline" id="couponCancelBtn" style="display:none;">Cancelar</button>
                    </div>
                </form>
            </div>
            <div class="card">
                <h3 style="margin-bottom: 1rem;">Cupons cadastrados</h3>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Tipo</th>
                                <th>Valor</th>
                                <th>Validade</th>
                                <th>Ativo</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="couponsTableBody">
                            <tr><td colspan="6" style="text-align:center;">Carregando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `,
        extras: `
            <div class="page-header">
                <h1 class="page-title">Serviços Extras</h1>
                <button type="button" class="btn" id="extrasRefreshBtn"><i class="ph ph-arrows-clockwise"></i> Atualizar</button>
            </div>
            <div id="extrasKeyWarn" class="card" style="display:none; border:1px solid var(--danger); color:var(--danger); margin-bottom:1rem;">
                Não foi possível carregar a chave interna. Abra <strong>Configurações</strong> neste painel e volte aqui.
            </div>
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-bottom: 1rem;">
                    <i class="ph ph-plus-circle" style="color: var(--primary); vertical-align: bottom;"></i>
                    Novo Serviço Extra
                </h3>
                <form id="extrasForm" style="display:grid; grid-template-columns: 2fr 1fr 3fr auto; gap: 0.75rem; align-items:end;">
                    <input type="hidden" id="extraEditId" value="">
                    <div class="form-group" style="margin:0;">
                        <label>Nome</label>
                        <input type="text" id="extraName" class="form-control" placeholder="Café da manhã" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Preço (R$)</label>
                        <input type="number" id="extraPrice" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Descrição</label>
                        <input type="text" id="extraDesc" class="form-control" placeholder="Opcional">
                    </div>
                    <div style="display:flex; gap:0.5rem;">
                        <button type="submit" class="btn btn-primary" id="extraSaveBtn"><i class="ph ph-floppy-disk"></i> Salvar</button>
                        <button type="button" class="btn btn-outline" id="extraCancelBtn" style="display:none;">Cancelar</button>
                    </div>
                </form>
                <div style="margin-top:0.75rem; display:flex; gap:0.5rem; align-items:center;">
                    <input type="checkbox" id="extraActive" checked>
                    <label for="extraActive" style="margin:0;">Ativo para novas reservas</label>
                </div>
            </div>
            <div class="card">
                <h3 style="margin-bottom: 1rem;">Serviços cadastrados</h3>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Preço</th>
                                <th>Descrição</th>
                                <th>Ativo</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="extrasTableBody">
                            <tr><td colspan="5" style="text-align:center;">Carregando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `,
        users: `
            <div class="page-header">
                <h1 class="page-title">Gestão de Usuários</h1>
                <button class="btn btn-primary" onclick="openUserModal(null)"><i class="ph ph-plus"></i> Novo Usuário</button>
            </div>

            <div class="card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>E-mail</th>
                                <th>Perfil</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <tr><td colspan="4" style="text-align:center;">Carregando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `
    });

    function getStatusClass(status) {
        if (status === 'Confirmada') return 'success';
        if (status === 'Pendente') return 'warning';
        if (status === 'Cancelada') return 'danger';
        return 'info';
    }

    function formatDateBR(dateString) {
        if (!dateString) return '';
        const parts = dateString.split('-');
        if (parts.length === 3) {
            return `${parts[2]}/${parts[1]}/${parts[0]}`;
        }
        return dateString;
    }

    /** Data/hora de quitação total (balance_paid_at) vinda do MySQL */
    function formatBalancePaidAtDisplay(value) {
        if (!value) return '';
        const s = String(value).trim();
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (m) {
            return `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}`;
        }
        const d = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (d) {
            return `${d[3]}/${d[2]}/${d[1]} 00:00`;
        }
        return s;
    }
    window.formatDateBRGlobal = formatDateBR;

    // View Renderer
    async function renderView(viewName) {
        // Bloqueia acesso conforme permissões
        const adminRoleRaw = localStorage.getItem('adminRole') || 'admin';
        const adminRole = normalizeRole(adminRoleRaw);
        const adminPermissions = (() => {
            try {
                const p = localStorage.getItem('adminPermissions');
                return p ? JSON.parse(p) : null;
            } catch { return null; }
        })();

        // role=admin é administrador total: ignora adminPermissions antigos do localStorage
        // (caso contrário, usuários criados antes das novas abas ficariam presos em "Acesso Negado").
        const isFullAdmin = !isSecretaryRole(adminRole);
        if (!isFullAdmin && adminPermissions && Array.isArray(adminPermissions)) {
            if (!adminPermissions.includes(viewName)) {
                document.getElementById('app').innerHTML = `<div class="card"><h2 style="color:var(--danger)">Acesso Negado</h2><p>Você não tem permissão para acessar esta página.</p></div>`;
                return;
            }
        } else if (isSecretaryRole(adminRole)) {
            const restrictedViews = ['settings', 'customization', 'users', 'financeiro', 'coupons', 'extras'];
            if (restrictedViews.includes(viewName)) {
                document.getElementById('app').innerHTML = `<div class="card"><h2 style="color:var(--danger)">Acesso Negado</h2><p>Você não tem permissão para acessar esta página.</p></div>`;
                return;
            }
        }

        // Se a View precisa de dados do banco
        if (['dashboard', 'reservations', 'chalets'].includes(viewName)) {
            await fetchApiData();
        }

        const viewHTML = getViews()[viewName];

        if (viewHTML) {
            appContainer.innerHTML = viewHTML;

            // Segurança extra: mesmo com cache/HTML antigo, secretaria não pode ver CTA de cadastro.
            if (viewName === 'chalets') removeAddChaletButtonsForSecretary(appContainer);

            if (viewName === 'users') {
                await loadUsersTable();
            }
            // Setup Settings Form if it's the settings view
            if (viewName === 'settings') {
                await loadAllSettings();
                document.getElementById('saveEvolutionBtn').addEventListener('click', saveEvolutionSettings);
                document.getElementById('saveMpBtn').addEventListener('click', saveMpSettings);
                document.getElementById('saveLogoBtn').addEventListener('click', saveLogoSettings);
                document.getElementById('saveSocialBtn').addEventListener('click', saveSocialSettings);
                document.getElementById('saveIdentitySeoBtn').addEventListener('click', saveIdentitySeoSettings);
                const saveRulesBtnEl = document.getElementById('saveRulesBtn');
                if (saveRulesBtnEl) saveRulesBtnEl.addEventListener('click', saveRulesSettings);
                const addPolicyBtnEl = document.getElementById('addPolicyBtn');
                if (addPolicyBtnEl) addPolicyBtnEl.addEventListener('click', addEmptyPolicyRow);
                const savePoliciesBtnEl = document.getElementById('savePoliciesBtn');
                if (savePoliciesBtnEl) savePoliciesBtnEl.addEventListener('click', savePaymentPolicies);
            }
            if (viewName === 'customization') {
                await loadAllSettings();
                await loadCustomizationForm(); // Carrega dados da tabela personalizacao nos campos
                document.getElementById('saveCustomizationBtn').addEventListener('click', saveCustomizationSettings);
            }
            if (viewName === 'dashboard') {
                renderDashboardCalendar();
            }
            if (viewName === 'reservations') {
                bindReservationButtons();
            }
            if (viewName === 'financeiro') {
                void initFinanceiroView();
            }
            if (viewName === 'coupons') {
                void initCouponsView();
            }
            if (viewName === 'extras') {
                void initExtrasView();
            }
        } else {
            appContainer.innerHTML = `<h2>View não encontrada</h2>`;
        }
    }

    /* =========================================
       COUPONS (ABA DEDICADA)
       ========================================= */
    async function initCouponsView() {
        const warn = document.getElementById('couponsKeyWarn');
        const ok = await ensureInternalApiKey();
        if (warn) warn.style.display = ok ? 'none' : 'block';
        if (!ok) return;

        const form = document.getElementById('couponForm');
        const idEl = document.getElementById('couponEditId');
        const codeEl = document.getElementById('couponCode');
        const typeEl = document.getElementById('couponType');
        const valueEl = document.getElementById('couponValue');
        const expiryEl = document.getElementById('couponExpiry');
        const activeEl = document.getElementById('couponActive');
        const saveBtn = document.getElementById('couponSaveBtn');
        const cancelBtn = document.getElementById('couponCancelBtn');
        const refreshBtn = document.getElementById('couponsRefreshBtn');
        const tbody = document.getElementById('couponsTableBody');

        function resetForm() {
            idEl.value = '';
            codeEl.value = '';
            typeEl.value = 'percent';
            valueEl.value = '';
            expiryEl.value = '';
            activeEl.checked = true;
            saveBtn.innerHTML = '<i class="ph ph-floppy-disk"></i> Salvar';
            if (cancelBtn) cancelBtn.style.display = 'none';
        }

        async function loadCoupons() {
            if (!tbody) return;
            try {
                const res = await fetch('../api/admin_coupons.php', { headers: { 'X-Internal-Key': internalApiKey } });
                const rows = await res.json();
                if (!Array.isArray(rows)) throw new Error('Resposta inválida');
                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#666;">Nenhum cupom cadastrado</td></tr>';
                    return;
                }
                tbody.innerHTML = rows.map((c) => `
                    <tr>
                        <td><strong>${String(c.code || '').replace(/</g, '&lt;')}</strong></td>
                        <td>${c.type === 'fixed' ? 'Valor fixo (R$)' : 'Percentual (%)'}</td>
                        <td>${c.type === 'fixed'
                            ? 'R$ ' + Number(c.value).toLocaleString('pt-BR', { minimumFractionDigits: 2 })
                            : Number(c.value).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' %'}</td>
                        <td>${c.expiry_date ? String(c.expiry_date) : '—'}</td>
                        <td><input type="checkbox" data-coupon-toggle="${c.id}" ${Number(c.active) ? 'checked' : ''}></td>
                        <td>
                            <button type="button" class="btn-icon" data-coupon-edit="${c.id}" title="Editar"><i class="ph ph-pencil-simple"></i></button>
                            <button type="button" class="btn-icon" data-coupon-del="${c.id}" title="Excluir" style="color:var(--danger)"><i class="ph ph-trash"></i></button>
                        </td>
                    </tr>
                `).join('');
                tbody.querySelectorAll('[data-coupon-toggle]').forEach((cb) => {
                    cb.addEventListener('change', async () => {
                        const id = parseInt(cb.getAttribute('data-coupon-toggle'), 10);
                        const target = rows.find((x) => Number(x.id) === id);
                        if (!target) return;
                        await fetch('../api/admin_coupons.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                            body: JSON.stringify({
                                id,
                                code: target.code,
                                type: target.type,
                                value: parseFloat(target.value),
                                expiry_date: target.expiry_date || null,
                                active: cb.checked ? 1 : 0
                            })
                        });
                        await loadCoupons();
                    });
                });
                tbody.querySelectorAll('[data-coupon-del]').forEach((btn) => {
                    btn.addEventListener('click', async () => {
                        const id = btn.getAttribute('data-coupon-del');
                        if (!confirm('Excluir este cupom? Esta ação não pode ser desfeita.')) return;
                        await fetch(`../api/admin_coupons.php?id=${id}`, { method: 'DELETE', headers: { 'X-Internal-Key': internalApiKey } });
                        resetForm();
                        await loadCoupons();
                    });
                });
                tbody.querySelectorAll('[data-coupon-edit]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const id = parseInt(btn.getAttribute('data-coupon-edit'), 10);
                        const target = rows.find((x) => Number(x.id) === id);
                        if (!target) return;
                        idEl.value = String(target.id);
                        codeEl.value = target.code || '';
                        typeEl.value = target.type || 'percent';
                        valueEl.value = target.value;
                        expiryEl.value = target.expiry_date ? String(target.expiry_date).slice(0, 10) : '';
                        activeEl.checked = Number(target.active) === 1;
                        saveBtn.innerHTML = '<i class="ph ph-check"></i> Atualizar';
                        if (cancelBtn) cancelBtn.style.display = 'inline-flex';
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    });
                });
            } catch (e) {
                console.error('Erro ao carregar cupons:', e);
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--danger);">Erro ao carregar cupons</td></tr>';
            }
        }

        if (form) {
            form.onsubmit = async (ev) => {
                ev.preventDefault();
                const code = codeEl.value.trim();
                const type = typeEl.value;
                const value = parseFloat(valueEl.value);
                const expiry = expiryEl.value || null;
                const active = activeEl.checked ? 1 : 0;
                if (!code || !Number.isFinite(value) || value <= 0) {
                    alert('Informe código e valor válidos.');
                    return;
                }
                const payload = { code, type, value, expiry_date: expiry, active };
                if (idEl.value) payload.id = parseInt(idEl.value, 10);
                const res = await fetch('../api/admin_coupons.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                    body: JSON.stringify(payload)
                });
                if (res.ok) {
                    resetForm();
                    await loadCoupons();
                } else {
                    const err = await res.json().catch(() => ({}));
                    alert(err.error || 'Erro ao salvar cupom');
                }
            };
        }
        if (cancelBtn) cancelBtn.onclick = () => resetForm();
        if (refreshBtn) refreshBtn.onclick = () => loadCoupons();
        await loadCoupons();
    }

    /* =========================================
       SERVIÇOS EXTRAS (ABA DEDICADA)
       ========================================= */
    async function initExtrasView() {
        const warn = document.getElementById('extrasKeyWarn');
        const ok = await ensureInternalApiKey();
        if (warn) warn.style.display = ok ? 'none' : 'block';
        if (!ok) return;

        const form = document.getElementById('extrasForm');
        const idEl = document.getElementById('extraEditId');
        const nameEl = document.getElementById('extraName');
        const priceEl = document.getElementById('extraPrice');
        const descEl = document.getElementById('extraDesc');
        const activeEl = document.getElementById('extraActive');
        const saveBtn = document.getElementById('extraSaveBtn');
        const cancelBtn = document.getElementById('extraCancelBtn');
        const refreshBtn = document.getElementById('extrasRefreshBtn');
        const tbody = document.getElementById('extrasTableBody');

        function resetForm() {
            idEl.value = '';
            nameEl.value = '';
            priceEl.value = '';
            descEl.value = '';
            activeEl.checked = true;
            saveBtn.innerHTML = '<i class="ph ph-floppy-disk"></i> Salvar';
            if (cancelBtn) cancelBtn.style.display = 'none';
        }

        async function loadExtras() {
            if (!tbody) return;
            try {
                const res = await fetch('../api/admin_extra_services.php', { headers: { 'X-Internal-Key': internalApiKey } });
                const rows = await res.json();
                if (!Array.isArray(rows)) throw new Error('Resposta inválida');
                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#666;">Nenhum serviço cadastrado</td></tr>';
                    return;
                }
                tbody.innerHTML = rows.map((x) => `
                    <tr>
                        <td><strong>${String(x.name || '').replace(/</g, '&lt;')}</strong></td>
                        <td>R$ ${Number(x.price).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</td>
                        <td style="color:#555; font-size:0.9rem;">${String(x.description || '').replace(/</g, '&lt;') || '—'}</td>
                        <td><input type="checkbox" data-extra-toggle="${x.id}" ${Number(x.active) ? 'checked' : ''}></td>
                        <td>
                            <button type="button" class="btn-icon" data-extra-edit="${x.id}" title="Editar"><i class="ph ph-pencil-simple"></i></button>
                            <button type="button" class="btn-icon" data-extra-del="${x.id}" title="Excluir" style="color:var(--danger)"><i class="ph ph-trash"></i></button>
                        </td>
                    </tr>
                `).join('');
                tbody.querySelectorAll('[data-extra-toggle]').forEach((cb) => {
                    cb.addEventListener('change', async () => {
                        const id = parseInt(cb.getAttribute('data-extra-toggle'), 10);
                        const target = rows.find((x) => Number(x.id) === id);
                        if (!target) return;
                        await fetch('../api/admin_extra_services.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                            body: JSON.stringify({
                                id,
                                name: target.name,
                                price: parseFloat(target.price),
                                description: target.description || '',
                                active: cb.checked ? 1 : 0
                            })
                        });
                        await loadExtras();
                    });
                });
                tbody.querySelectorAll('[data-extra-del]').forEach((btn) => {
                    btn.addEventListener('click', async () => {
                        const id = btn.getAttribute('data-extra-del');
                        if (!confirm('Excluir este serviço extra?')) return;
                        await fetch(`../api/admin_extra_services.php?id=${id}`, { method: 'DELETE', headers: { 'X-Internal-Key': internalApiKey } });
                        resetForm();
                        await loadExtras();
                    });
                });
                tbody.querySelectorAll('[data-extra-edit]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const id = parseInt(btn.getAttribute('data-extra-edit'), 10);
                        const target = rows.find((x) => Number(x.id) === id);
                        if (!target) return;
                        idEl.value = String(target.id);
                        nameEl.value = target.name || '';
                        priceEl.value = target.price;
                        descEl.value = target.description || '';
                        activeEl.checked = Number(target.active) === 1;
                        saveBtn.innerHTML = '<i class="ph ph-check"></i> Atualizar';
                        if (cancelBtn) cancelBtn.style.display = 'inline-flex';
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    });
                });
            } catch (e) {
                console.error('Erro ao carregar serviços extras:', e);
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--danger);">Erro ao carregar serviços</td></tr>';
            }
        }

        if (form) {
            form.onsubmit = async (ev) => {
                ev.preventDefault();
                const name = nameEl.value.trim();
                const price = parseFloat(priceEl.value);
                const description = descEl.value.trim();
                const active = activeEl.checked ? 1 : 0;
                if (!name || !Number.isFinite(price) || price < 0) {
                    alert('Informe nome e preço válidos (preço >= 0).');
                    return;
                }
                const payload = { name, price, description, active };
                if (idEl.value) payload.id = parseInt(idEl.value, 10);
                const res = await fetch('../api/admin_extra_services.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                    body: JSON.stringify(payload)
                });
                if (res.ok) {
                    resetForm();
                    await loadExtras();
                } else {
                    const err = await res.json().catch(() => ({}));
                    alert(err.error || 'Erro ao salvar serviço');
                }
            };
        }
        if (cancelBtn) cancelBtn.onclick = () => resetForm();
        if (refreshBtn) refreshBtn.onclick = () => loadExtras();
        await loadExtras();
    }

    // Função Exposta para atualizar status da reserva via SELECT
    window.updateReservationStatus = async function (id, newStatus) {
        try {
            const response = await fetch(`../api/reservations.php?id=${id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status: newStatus })
            });
            if (response.ok) {
                // Toast ou alerta opcional silencioso
                console.log(`Reserva ${id} atualizada para ${newStatus}`);
            } else {
                alert("Erro ao atualizar o status no banco.");
            }
        } catch (e) {
            console.error("Erro na API PUT:", e);
        }
    }



    // Dashboard Calendar Global Logic
    let currentCalendarDate = new Date();

    function renderDashboardCalendar() {
        const calElement = document.getElementById('dashboardCalendar');
        const monthYearTxt = document.getElementById('calendarMonthYear');
        if (!calElement) return;

        const year = currentCalendarDate.getFullYear();
        const month = currentCalendarDate.getMonth();

        const monthNames = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        monthYearTxt.textContent = `${monthNames[month]} ${year}`;

        const firstDayOfMonth = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        let html = '';

        // Blank spaces for first row
        for (let i = 0; i < firstDayOfMonth; i++) {
            html += `<div style="padding: 1rem; border-radius: 4px; background: transparent;"></div>`;
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const currentDateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;

            // Check if any reservation active on this day
            let dayStatus = null;
            let resTitle = "";
            let bgColor = "var(--bg-light)";
            let color = "inherit";

            reservationsData.forEach(r => {
                if (r.status === 'Cancelada') return;
                const checkin = r.checkin_date; // YYYY-MM-DD
                const checkout = r.checkout_date; // YYYY-MM-DD

                if (currentDateStr >= checkin && currentDateStr <= checkout) {
                    dayStatus = r.status;
                    resTitle += `${r.guest_name} (${r.chalet_name})\\n`;
                    if (r.status === 'Confirmada') {
                        bgColor = "var(--primary)";
                        color = "white";
                    } else if (r.status === 'Pendente' && bgColor !== "var(--primary)") { // Prioritize Confirmed visually if overlap
                        bgColor = "var(--warning)";
                        color = "#fff";
                    }
                }
            });

            const inlineStyle = `padding: 1rem; border-radius: 4px; background: ${bgColor}; color: ${color}; cursor: ${resTitle ? 'pointer' : 'default'};`;
            html += `<div style="${inlineStyle}" title="${resTitle}">${d}</div>`;
        }

        calElement.innerHTML = html;

        document.getElementById('prevMonthBtn').onclick = () => {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() - 1);
            renderDashboardCalendar();
        };

        document.getElementById('nextMonthBtn').onclick = () => {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
            renderDashboardCalendar();
        };
    }

    /* =========================================
       SETTINGS LOGIC (Evolution & MercadoPago via API)
       ========================================= */
    async function saveEvolutionSettings() {
        const settings = {
            evolutionSettings: {
                url: document.getElementById('evoUrl').value,
                clientInstance: document.getElementById('evoClientInstance').value,
                clientApikey: document.getElementById('evoClientApikey').value,
                companyInstance: document.getElementById('evoCompanyInstance').value,
                companyApikey: document.getElementById('evoCompanyApikey').value,
                companyPhone: document.getElementById('evoCompanyPhone').value,
                reservationMsg: document.getElementById('evoReservationMsg').value
            }
        };

        await saveSettingsToAPI(settings);
        alert('Credenciais da Evolution API salvas no Banco de Dados!');
    }

    async function saveMpSettings() {
        const settings = {
            mercadoPagoSettings: {
                accessToken: document.getElementById('mpAccessToken').value
            }
        };

        await saveSettingsToAPI(settings);
        alert('Access Token do MercadoPago salvo no Banco de Dados!');
    }

    function normalizeTimeHHMM(raw, fallback) {
        const s = String(raw == null ? '' : raw).trim();
        const m = s.match(/^(\d{1,2}):(\d{2})/);
        if (!m) return fallback;
        const h = Math.max(0, Math.min(23, parseInt(m[1], 10) || 0));
        const mi = Math.max(0, Math.min(59, parseInt(m[2], 10) || 0));
        return String(h).padStart(2, '0') + ':' + String(mi).padStart(2, '0');
    }

    async function saveRulesSettings() {
        const checkinEl = document.getElementById('rulesCheckinTime');
        const checkoutEl = document.getElementById('rulesCheckoutTime');
        const checkin = normalizeTimeHHMM(checkinEl ? checkinEl.value : '', '14:00');
        const checkout = normalizeTimeHHMM(checkoutEl ? checkoutEl.value : '', '12:00');
        await saveSettingsToAPI({ checkin_time: checkin, checkout_time: checkout });
        alert('Horários salvos com sucesso!');
    }

    function renderPaymentPoliciesEditor() {
        const list = document.getElementById('paymentPoliciesList');
        if (!list) return;
        const policies = Array.isArray(paymentPolicies) && paymentPolicies.length > 0
            ? paymentPolicies
            : [
                { code: 'half', label: 'Sinal de 50% para reserva', percent_now: 50 },
                { code: 'full', label: 'Pagamento 100% Antecipado', percent_now: 100 }
            ];
        list.innerHTML = policies.map((p, idx) => `
            <div class="policy-row" data-policy-idx="${idx}" style="display:grid; grid-template-columns: 140px 1fr 120px 40px; gap:0.5rem; align-items:end; background: var(--bg-light); padding: 0.75rem; border-radius: 8px;">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem;">Código</label>
                    <input type="text" class="form-control policy-code" value="${String(p.code || '').replace(/"/g, '&quot;')}" placeholder="ex: half">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem;">Rótulo exibido</label>
                    <input type="text" class="form-control policy-label" value="${String(p.label || '').replace(/"/g, '&quot;')}" placeholder="Sinal de 30%">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem;">% no ato</label>
                    <input type="number" min="1" max="100" step="1" class="form-control policy-percent" value="${Number(p.percent_now) || 0}">
                </div>
                <button type="button" class="btn-icon policy-remove" title="Remover" style="color:var(--danger); align-self:center;"><i class="ph ph-trash"></i></button>
            </div>
        `).join('');
        list.querySelectorAll('.policy-remove').forEach((btn) => {
            btn.addEventListener('click', () => {
                const row = btn.closest('.policy-row');
                if (row) row.remove();
            });
        });
    }

    function addEmptyPolicyRow() {
        const list = document.getElementById('paymentPoliciesList');
        if (!list) return;
        const idx = list.querySelectorAll('.policy-row').length;
        const row = document.createElement('div');
        row.className = 'policy-row';
        row.setAttribute('data-policy-idx', String(idx));
        row.style.cssText = 'display:grid; grid-template-columns: 140px 1fr 120px 40px; gap:0.5rem; align-items:end; background: var(--bg-light); padding: 0.75rem; border-radius: 8px;';
        row.innerHTML = `
            <div class="form-group" style="margin:0;">
                <label style="font-size:0.8rem;">Código</label>
                <input type="text" class="form-control policy-code" placeholder="ex: deposit30">
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size:0.8rem;">Rótulo exibido</label>
                <input type="text" class="form-control policy-label" placeholder="Sinal de 30%">
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size:0.8rem;">% no ato</label>
                <input type="number" min="1" max="100" step="1" class="form-control policy-percent" value="30">
            </div>
            <button type="button" class="btn-icon policy-remove" title="Remover" style="color:var(--danger); align-self:center;"><i class="ph ph-trash"></i></button>
        `;
        row.querySelector('.policy-remove').addEventListener('click', () => row.remove());
        list.appendChild(row);
    }

    function collectPaymentPoliciesFromUI() {
        const rows = document.querySelectorAll('#paymentPoliciesList .policy-row');
        const out = [];
        const seen = new Set();
        rows.forEach((row) => {
            const code = (row.querySelector('.policy-code')?.value || '').trim().toLowerCase();
            const label = (row.querySelector('.policy-label')?.value || '').trim();
            const pctRaw = (row.querySelector('.policy-percent')?.value || '').trim();
            const pct = Number(pctRaw);
            if (!code || !label || !Number.isFinite(pct) || pct <= 0 || pct > 100) return;
            if (seen.has(code)) return;
            seen.add(code);
            out.push({ code, label, percent_now: Math.round(pct) });
        });
        return out;
    }

    async function savePaymentPolicies() {
        const policies = collectPaymentPoliciesFromUI();
        if (policies.length === 0) {
            alert('Defina pelo menos uma política de pagamento válida (código + rótulo + % entre 1 e 100).');
            return;
        }
        const hasFull = policies.some((p) => p.percent_now >= 100);
        if (!hasFull) {
            if (!confirm('Nenhuma política cobra 100% no ato. Recomendamos manter uma opção integral. Deseja salvar mesmo assim?')) {
                return;
            }
        }
        await saveSettingsToAPI({ payment_policies: policies });
        paymentPolicies = normalizePaymentPolicies(policies);
        renderPaymentPoliciesEditor();
        alert('Políticas de pagamento salvas com sucesso!');
    }

    function hexToRgb(hex) {
        const raw = String(hex || '').trim().replace('#', '');
        const full = raw.length === 3 ? raw.split('').map((c) => c + c).join('') : raw;
        if (!/^[0-9a-fA-F]{6}$/.test(full)) return null;
        const num = parseInt(full, 16);
        return {
            r: (num >> 16) & 255,
            g: (num >> 8) & 255,
            b: num & 255,
        };
    }

    function applyAdminTheme(primaryColor, secondaryColor) {
        const root = document.documentElement;
        const primary = (primaryColor && /^#[0-9a-fA-F]{6}$/.test(primaryColor)) ? primaryColor : '#ea580c';
        const secondary = (secondaryColor && /^#[0-9a-fA-F]{6}$/.test(secondaryColor)) ? secondaryColor : '#1e293b';
        root.style.setProperty('--primary-color', primary);
        root.style.setProperty('--secondary-color', secondary);
        root.style.setProperty('--primary', primary);
        root.style.setProperty('--secondary', secondary);
        const rgb = hexToRgb(primary);
        if (rgb) {
            root.style.setProperty('--primary-light', `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.14)`);
        }
    }

    async function saveIdentitySeoSettings() {
        const siteTitleEl = document.getElementById('seoSiteTitle');
        const metaEl = document.getElementById('seoMetaDescription');
        const primaryEl = document.getElementById('seoPrimaryColor');
        const secondaryEl = document.getElementById('seoSecondaryColor');
        const settings = {
            site_title: siteTitleEl ? siteTitleEl.value : '',
            meta_description: metaEl ? metaEl.value : '',
            primary_color: primaryEl ? primaryEl.value : '#ea580c',
            secondary_color: secondaryEl ? secondaryEl.value : '#1e293b'
        };
        applyAdminTheme(settings.primary_color, settings.secondary_color);
        await saveSettingsToAPI(settings);
        alert('Identidade visual e SEO salvos com sucesso!');
    }

    async function saveSettingsToAPI(dataObj) {
        try {
            await fetch('../api/settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(dataObj)
            });
            localStorage.clear(); // Garante que a versão local seja ignorada no futuro
        } catch (e) {
            console.error("Falha ao salvar config na API", e);
        }
    }

    async function sendEvolutionWebhooks(reserva, isManual = false) {
        if (!reserva.clientPhone || String(reserva.clientPhone).trim() === '') {
            return { success: false, message: "Número do cliente ausente ou inválido." };
        }
        try {
            const payload = {
                clientName: reserva.clientName,
                clientPhone: reserva.clientPhone,
                chaletName: reserva.chaletName,
                checkin: reserva.checkin,
                checkout: reserva.checkout,
                total: reserva.total,
                valorPago: reserva.valorPago,
                condicao: reserva.condicao,
                paymentRule: reserva.paymentRule,
                id: reserva.id
            };
            if (isManual) payload.manual = true;
            const res = await fetch('../api/send_webhook.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.success) {
                return { success: true, message: "Mensagem enviada com sucesso para o hóspede!" };
            }
            return { success: false, message: data.error || "Falha ao enviar a mensagem. Verifique as configurações da Evolution API." };
        } catch (e) {
            console.error("Erro ao enviar webhook", e);
            return { success: false, message: "Erro de conexão ao enviar mensagem." };
        }
    }
    window.sendEvolutionWebhooksGlobal = sendEvolutionWebhooks;

    /** Carrega dados da personalização diretamente da API (tabela personalizacao) nos campos do formulário */
    async function loadCustomizationForm() {
        try {
            const res = await fetch('../api/customization.php');
            if (!res.ok) return;
            const custom = await res.json();
            if (!custom || typeof custom !== 'object') return;

            const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
            set('customHeroTitle', custom.heroTitle);
            set('customHeroSubtitle', custom.heroSubtitle);
            set('customAboutTitle', custom.aboutTitle);
            set('customAboutText', custom.aboutText);
            set('customChaletsSubtitle', custom.chaletsSubtitle);
            set('customChaletsTitle', custom.chaletsTitle);
            set('customChaletsDesc', custom.chaletsDesc);
            set('customFeat1Title', custom.feat1Title);
            set('customFeat1Desc', custom.feat1Desc);
            set('customFeat2Title', custom.feat2Title);
            set('customFeat2Desc', custom.feat2Desc);
            set('customFeat3Title', custom.feat3Title);
            set('customFeat3Desc', custom.feat3Desc);
            set('customFeat4Title', custom.feat4Title);
            set('customFeat4Desc', custom.feat4Desc);
            set('customFeat5Title', custom.feat5Title);
            set('customFeat5Desc', custom.feat5Desc);
            set('customTesti1Name', custom.testi1Name);
            set('customTesti1Location', custom.testi1Location);
            set('customTesti1Text', custom.testi1Text);
            set('customTesti2Name', custom.testi2Name);
            set('customTesti2Location', custom.testi2Location);
            set('customTesti2Text', custom.testi2Text);
            set('customTesti3Name', custom.testi3Name);
            set('customTesti3Location', custom.testi3Location);
            set('customTesti3Text', custom.testi3Text);
            set('customLocAddress', custom.locAddress);
            set('customLocCar', custom.locCar);
            set('customLocMapLink', custom.locMapLink);
            set('customWaNumber', custom.waNumber);
            set('customWaMessage', custom.waMessage);
            set('customFooterDesc', custom.footerDesc);
            set('customFooterAddress', custom.footerAddress);
            set('customFooterEmail', custom.footerEmail);
            set('customFooterPhone', custom.footerPhone);
            set('customFooterCopyright', custom.footerCopyright);

            const imgSrc = (src) => (src && !src.startsWith('http')) ? `../${src}` : (src || '');
            const heroImgs = custom.heroImages || (custom.heroImage ? [custom.heroImage] : []);
            const heroPreview = document.getElementById('currentHeroPreview');
            if (heroPreview) heroPreview.innerHTML = heroImgs.length ? heroImgs.map(src => `<img src="${imgSrc(src)}" alt="Hero" style="max-height: 80px; max-width: 120px; object-fit: cover; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`).join('') : '';

            const aboutPreview = document.getElementById('currentAboutPreview');
            if (aboutPreview && custom.aboutImage) aboutPreview.innerHTML = `<img src="${imgSrc(custom.aboutImage)}" alt="About" style="max-height: 100px; max-width: 100%; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`;

            const faviconPreview = document.getElementById('currentFaviconPreview');
            if (faviconPreview && custom.favicon) faviconPreview.innerHTML = `<img src="${imgSrc(custom.favicon)}" alt="Favicon" style="width: 32px; height: 32px; object-fit: contain; border-radius: 4px;">`;

            [1, 2, 3].forEach((i) => {
                const img = custom[`testi${i}Image`];
                const el = document.getElementById(`currentTesti${i}Preview`);
                if (el && img) el.innerHTML = `<img src="${imgSrc(img)}" alt="Depoimento ${i}" style="max-height: 80px; max-width: 100%; border-radius: 50%;">`;
            });
        } catch (e) {
            console.warn('Erro ao carregar personalização:', e);
        }
    }

    async function loadAllSettings() {
        try {
            const res = await fetch('../api/settings.php');
            const data = await res.json();

            // Popula Evolution
            if (data.evolutionSettings) {
                const evo = data.evolutionSettings;
                document.getElementById('evoUrl').value = evo.url || '';
                document.getElementById('evoClientInstance').value = evo.clientInstance || '';
                document.getElementById('evoClientApikey').value = evo.clientApikey || '';
                document.getElementById('evoCompanyInstance').value = evo.companyInstance || '';
                document.getElementById('evoCompanyApikey').value = evo.companyApikey || '';
                document.getElementById('evoCompanyPhone').value = evo.companyPhone || '';
                document.getElementById('evoReservationMsg').value = evo.reservationMsg || '';
            }

            // Popula MercadoPago
            if (data.mercadoPagoSettings) {
                document.getElementById('mpAccessToken').value = data.mercadoPagoSettings.accessToken || '';
            }
            if (typeof data.internalApiKey === 'string' && data.internalApiKey.trim() !== '') {
                internalApiKey = data.internalApiKey.trim();
            }

            // Popula Redes Sociais
            if (data.socialSettings) {
                document.getElementById('socialInstagram').value = data.socialSettings.instagram || '';
                document.getElementById('socialFacebook').value = data.socialSettings.facebook || '';
                document.getElementById('socialTripadvisor').value = data.socialSettings.tripadvisor || '';
            }
            const rulesCheckin = document.getElementById('rulesCheckinTime');
            const rulesCheckout = document.getElementById('rulesCheckoutTime');
            if (rulesCheckin) rulesCheckin.value = normalizeTimeHHMM(data.checkin_time, '14:00');
            if (rulesCheckout) rulesCheckout.value = normalizeTimeHHMM(data.checkout_time, '12:00');
            if (Array.isArray(data.payment_policies)) {
                paymentPolicies = normalizePaymentPolicies(data.payment_policies);
            }
            if (document.getElementById('paymentPoliciesList')) {
                renderPaymentPoliciesEditor();
            }

            const seoSiteTitle = document.getElementById('seoSiteTitle');
            if (seoSiteTitle) seoSiteTitle.value = data.site_title || 'Pousada Mirante do Sol';
            const seoMetaDescription = document.getElementById('seoMetaDescription');
            if (seoMetaDescription) seoMetaDescription.value = data.meta_description || 'O seu refúgio com vista para o mar em Governador Celso Ramos.';
            const seoPrimaryColor = document.getElementById('seoPrimaryColor');
            if (seoPrimaryColor) seoPrimaryColor.value = data.primary_color || '#ea580c';
            const seoSecondaryColor = document.getElementById('seoSecondaryColor');
            if (seoSecondaryColor) seoSecondaryColor.value = data.secondary_color || '#1e293b';
            applyAdminTheme(data.primary_color || '#ea580c', data.secondary_color || '#1e293b');

            const brandName = (data.company_name && String(data.company_name).trim()) ||
                (data.site_title && String(data.site_title).trim()) ||
                'Admin';
            const brandEl = document.getElementById('adminBrandName');
            if (brandEl) brandEl.textContent = brandName;
            const titleEl = document.getElementById('adminPageTitle');
            if (titleEl) titleEl.textContent = 'Admin · ' + brandName;
            try { document.title = 'Admin · ' + brandName; } catch (_) { /* noop */ }

            // Popula Logo Preview
            if (data.company_logo) {
                document.getElementById('currentLogoPreview').innerHTML = `<img src="../${data.company_logo}" alt="Company Logo" style="max-height: 100px; max-width: 100%; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`;
            }
            if (data.company_logo_light) {
                document.getElementById('currentLogoLightPreview').innerHTML = `<img src="../${data.company_logo_light}" alt="Company Logo Light" style="max-height: 100px; max-width: 100%; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`;
            }

            // Popula Customization
            const customView = document.getElementById('customHeroTitle');
            if (customView && data.customization) {
                const custom = data.customization;
                document.getElementById('customHeroTitle').value = custom.heroTitle || '';
                document.getElementById('customHeroSubtitle').value = custom.heroSubtitle || '';

                document.getElementById('customAboutTitle').value = custom.aboutTitle || '';
                document.getElementById('customAboutText').value = custom.aboutText || '';

                const heroImgs = custom.heroImages || (custom.heroImage ? [custom.heroImage] : []);
                if (heroImgs.length > 0) {
                    document.getElementById('currentHeroPreview').innerHTML = heroImgs.map(src => 
                        `<img src="../${src}" alt="Hero" style="max-height: 80px; max-width: 120px; object-fit: cover; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`
                    ).join('');
                } else {
                    document.getElementById('currentHeroPreview').innerHTML = '';
                }
                if (custom.aboutImage) {
                    document.getElementById('currentAboutPreview').innerHTML = `<img src="../${custom.aboutImage}" alt="About Image" style="max-height: 100px; max-width: 100%; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`;
                }
                const faviconPreview = document.getElementById('currentFaviconPreview');
                if (faviconPreview) {
                    faviconPreview.innerHTML = custom.favicon 
                        ? `<img src="../${custom.favicon}" alt="Favicon" style="width: 32px; height: 32px; object-fit: contain; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">` 
                        : '';
                }

                document.getElementById('customChaletsSubtitle').value = custom.chaletsSubtitle || '';
                document.getElementById('customChaletsTitle').value = custom.chaletsTitle || '';
                document.getElementById('customChaletsDesc').value = custom.chaletsDesc || '';

                document.getElementById('customFeat1Title').value = custom.feat1Title || '';
                document.getElementById('customFeat1Desc').value = custom.feat1Desc || '';
                document.getElementById('customFeat2Title').value = custom.feat2Title || '';
                document.getElementById('customFeat2Desc').value = custom.feat2Desc || '';
                document.getElementById('customFeat3Title').value = custom.feat3Title || '';
                document.getElementById('customFeat3Desc').value = custom.feat3Desc || '';
                document.getElementById('customFeat4Title').value = custom.feat4Title || '';
                document.getElementById('customFeat4Desc').value = custom.feat4Desc || '';
                if (document.getElementById('customFeat5Title')) {
                    document.getElementById('customFeat5Title').value = custom.feat5Title || '';
                    document.getElementById('customFeat5Desc').value = custom.feat5Desc || '';
                }

                // Testimonials
                document.getElementById('customTesti1Name').value = custom.testi1Name || '';
                document.getElementById('customTesti1Location').value = custom.testi1Location || '';
                document.getElementById('customTesti1Text').value = custom.testi1Text || '';
                if (custom.testi1Image) {
                    document.getElementById('currentTesti1Preview').innerHTML = `<img src="../${custom.testi1Image}" alt="Testimonial 1" style="max-height: 80px; max-width: 100%; border-radius: 50%;">`;
                }

                document.getElementById('customTesti2Name').value = custom.testi2Name || '';
                document.getElementById('customTesti2Location').value = custom.testi2Location || '';
                document.getElementById('customTesti2Text').value = custom.testi2Text || '';
                if (custom.testi2Image) {
                    document.getElementById('currentTesti2Preview').innerHTML = `<img src="../${custom.testi2Image}" alt="Testimonial 2" style="max-height: 80px; max-width: 100%; border-radius: 50%;">`;
                }

                document.getElementById('customTesti3Name').value = custom.testi3Name || '';
                document.getElementById('customTesti3Location').value = custom.testi3Location || '';
                document.getElementById('customTesti3Text').value = custom.testi3Text || '';
                if (custom.testi3Image) {
                    document.getElementById('currentTesti3Preview').innerHTML = `<img src="../${custom.testi3Image}" alt="Testimonial 3" style="max-height: 80px; max-width: 100%; border-radius: 50%;">`;
                }

                // Location
                document.getElementById('customLocAddress').value = custom.locAddress || '';
                document.getElementById('customLocCar').value = custom.locCar || '';
                document.getElementById('customLocMapLink').value = custom.locMapLink || '';

                // WhatsApp Flutuante
                document.getElementById('customWaNumber').value = custom.waNumber || '';
                document.getElementById('customWaMessage').value = custom.waMessage || '';

                // Footer
                document.getElementById('customFooterDesc').value = custom.footerDesc || '';
                document.getElementById('customFooterAddress').value = custom.footerAddress || '';
                document.getElementById('customFooterEmail').value = custom.footerEmail || '';
                document.getElementById('customFooterPhone').value = custom.footerPhone || '';
                document.getElementById('customFooterCopyright').value = custom.footerCopyright || '';
            }

        } catch (e) {
            console.warn("Erro ao ler settings da API. Banco pode estar vazio.", e);
        }
    }

    async function saveLogoSettings() {
        const fileInput = document.getElementById('companyLogoFile');
        const fileLightInput = document.getElementById('companyLogoLightFile');

        const file = fileInput.files[0];
        const fileLight = fileLightInput.files[0];

        if (!file && !fileLight) {
            alert('Por favor, selecione pelo menos um arquivo de logo para salvar.');
            return;
        }

        const formData = new FormData();
        if (file) formData.append('logo', file);
        if (fileLight) formData.append('logo_light', fileLight);

        // Required dummy field to trigger API processing since we bypass standard JSON parsing
        formData.append('dummy', 'true');

        try {
            document.getElementById('saveLogoBtn').disabled = true;
            document.getElementById('saveLogoBtn').textContent = 'Salvando...';

            const res = await fetch('../api/settings.php', {
                method: 'POST',
                body: formData // No Content-Type header so browser sets multipart/form-data with boundary
            });

            if (res.ok) {
                alert('Logos salvos com sucesso!');
                await loadAllSettings();
            } else {
                alert('Erro ao salvar logos.');
            }
        } catch (e) {
            console.error("Erro no upload dos logos", e);
            alert("Erro de conexão");
        } finally {
            document.getElementById('saveLogoBtn').disabled = false;
            document.getElementById('saveLogoBtn').innerHTML = '<i class="ph ph-floppy-disk"></i> Salvar Logos';
        }
    }

    async function saveSocialSettings() {
        const settings = {
            socialSettings: {
                instagram: document.getElementById('socialInstagram').value,
                facebook: document.getElementById('socialFacebook').value,
                tripadvisor: document.getElementById('socialTripadvisor').value
            }
        };

        await saveSettingsToAPI(settings);
        alert('Redes Sociais salvas com sucesso no Banco de Dados!');
    }

    async function saveCustomizationSettings() {
        const fileHeroInput = document.getElementById('customHeroImages');
        const fileAboutInput = document.getElementById('customAboutImage');
        const fileTesti1Input = document.getElementById('customTesti1Image');
        const fileTesti2Input = document.getElementById('customTesti2Image');
        const fileTesti3Input = document.getElementById('customTesti3Image');

        const formData = new FormData();

        if (fileHeroInput.files.length > 0) {
            for (let i = 0; i < fileHeroInput.files.length; i++) {
                formData.append('hero_images[]', fileHeroInput.files[i]);
            }
        }
        if (fileAboutInput.files[0]) formData.append('about_image', fileAboutInput.files[0]);
        const fileFaviconInput = document.getElementById('customFaviconImage');
        if (fileFaviconInput && fileFaviconInput.files[0]) formData.append('favicon_image', fileFaviconInput.files[0]);
        if (fileTesti1Input.files[0]) formData.append('testi1_image', fileTesti1Input.files[0]);
        if (fileTesti2Input.files[0]) formData.append('testi2_image', fileTesti2Input.files[0]);
        if (fileTesti3Input.files[0]) formData.append('testi3_image', fileTesti3Input.files[0]);

        const customizationSettings = {
            heroTitle: document.getElementById('customHeroTitle').value,
            heroSubtitle: document.getElementById('customHeroSubtitle').value,
            aboutTitle: document.getElementById('customAboutTitle').value,
            aboutText: document.getElementById('customAboutText').value,
            chaletsSubtitle: document.getElementById('customChaletsSubtitle').value,
            chaletsTitle: document.getElementById('customChaletsTitle').value,
            chaletsDesc: document.getElementById('customChaletsDesc').value,
            feat1Title: document.getElementById('customFeat1Title').value,
            feat1Desc: document.getElementById('customFeat1Desc').value,
            feat2Title: document.getElementById('customFeat2Title').value,
            feat2Desc: document.getElementById('customFeat2Desc').value,
            feat3Title: document.getElementById('customFeat3Title').value,
            feat3Desc: document.getElementById('customFeat3Desc').value,
            feat4Title: document.getElementById('customFeat4Title').value,
            feat4Desc: document.getElementById('customFeat4Desc').value,
            feat5Title: document.getElementById('customFeat5Title')?.value || '',
            feat5Desc: document.getElementById('customFeat5Desc')?.value || '',
            testi1Name: document.getElementById('customTesti1Name').value,
            testi1Location: document.getElementById('customTesti1Location').value,
            testi1Text: document.getElementById('customTesti1Text').value,
            testi2Name: document.getElementById('customTesti2Name').value,
            testi2Location: document.getElementById('customTesti2Location').value,
            testi2Text: document.getElementById('customTesti2Text').value,
            testi3Name: document.getElementById('customTesti3Name').value,
            testi3Location: document.getElementById('customTesti3Location').value,
            testi3Text: document.getElementById('customTesti3Text').value,
            locAddress: document.getElementById('customLocAddress').value,
            locCar: document.getElementById('customLocCar').value,
            locMapLink: document.getElementById('customLocMapLink').value,
            waNumber: document.getElementById('customWaNumber').value,
            waMessage: document.getElementById('customWaMessage').value,
            footerDesc: document.getElementById('customFooterDesc').value,
            footerAddress: document.getElementById('customFooterAddress').value,
            footerEmail: document.getElementById('customFooterEmail').value,
            footerPhone: document.getElementById('customFooterPhone').value,
            footerCopyright: document.getElementById('customFooterCopyright').value
        };

        formData.append('dummy', 'true');
        formData.append('customization', JSON.stringify(customizationSettings));

        try {
            document.getElementById('saveCustomizationBtn').disabled = true;
            document.getElementById('saveCustomizationBtn').textContent = 'Salvando...';

            const res = await fetch('../api/customization.php', {
                method: 'POST',
                body: formData
            });

            const result = await res.json().catch(() => ({}));
            if (res.ok) {
                alert('Personalizações salvas no banco de dados com sucesso!');
                await loadCustomizationForm(); // Atualiza os campos com o que foi salvo
            } else {
                alert('Erro ao salvar: ' + (result.error || result.details || res.statusText || 'Erro desconhecido'));
            }
        } catch (e) {
            console.error("Erro no upload das imagens de personalização", e);
            alert("Erro de conexão: " + (e.message || "Verifique o console"));
        } finally {
            document.getElementById('saveCustomizationBtn').disabled = false;
            document.getElementById('saveCustomizationBtn').innerHTML = '<i class="ph ph-floppy-disk"></i> Salvar Alterações';
        }
    }

    window._handleResend = async function handleResendNotification(index) {
        const r = reservationsData[index];
        if (!r) {
            alert("Dados da reserva não encontrados. Recarregue a página e tente novamente.");
            return;
        }
        if (!r.guest_phone || String(r.guest_phone).trim() === "") {
            alert("Hóspede não possui telefone cadastrado.");
            return;
        }
        if (!confirm(`Deseja reenviar a notificação para ${r.guest_name}?`)) return;

        const totalNum = parseFloat(r.total_amount) || 0;
        const policy = getPaymentPolicy(r.payment_rule || 'full');
        const valorPagoNum = (totalNum * Number(policy.percent_now || 100)) / 100;
        const webhookData = {
            clientName: r.guest_name,
            clientPhone: r.guest_phone,
            chaletName: r.chalet_name || 'Acomodação',
            checkin: formatDateBR(r.checkin_date),
            checkout: formatDateBR(r.checkout_date),
            total: 'R$ ' + totalNum.toLocaleString('pt-BR', { minimumFractionDigits: 2 }),
            valorPago: 'R$ ' + valorPagoNum.toLocaleString('pt-BR', { minimumFractionDigits: 2 }),
            condicao: policy.label || 'Condição de pagamento configurada',
            paymentRule: r.payment_rule || 'full',
            id: r.id || '---'
        };

        try {
            const result = await sendEvolutionWebhooks(webhookData);
            alert(result.success ? "✓ " + result.message : "✗ " + result.message);
        } catch (e) {
            console.error(e);
            alert("✗ Erro ao reenviar notificação: " + (e.message || "Erro desconhecido"));
        }
    };

    window._handleDelete = async function handleDeleteReservation(id) {
        if (!id) {
            alert('ID da reserva inválido.');
            return;
        }
        if (!confirm('Tem certeza que deseja excluir esta reserva permanentemente?')) return;
        try {
            const res = await fetch(`../api/reservations.php?id=${id}`, { method: 'DELETE' });
            if (res.ok) {
                alert('Reserva excluída com sucesso!');
                window.location.reload();
            } else {
                const errData = await res.json().catch(() => ({}));
                alert('Erro ao excluir reserva: ' + (errData.error || res.statusText || 'Erro desconhecido'));
            }
        } catch (e) {
            console.error(e);
            alert('Erro ao excluir reserva: ' + (e.message || 'Verifique sua conexão e tente novamente.'));
        }
    };

    function bindReservationButtons() {
        appContainer.querySelectorAll('[data-action="edit-reservation"]').forEach(btn => {
            btn.onclick = () => {
                const idx = btn.getAttribute('data-index');
                if (window.openEditReservationModal) window.openEditReservationModal(idx !== null && idx !== '' ? parseInt(idx, 10) : null);
            };
        });
        appContainer.querySelectorAll('[data-action="pdf-reservation"]').forEach(btn => {
            btn.onclick = () => {
                const idx = btn.getAttribute('data-index');
                if (window.openReservationContract) window.openReservationContract(idx !== null && idx !== '' ? parseInt(idx, 10) : 0);
            };
        });
        appContainer.querySelectorAll('[data-action="generate-contract"]').forEach(btn => {
            btn.onclick = () => {
                const idx = btn.getAttribute('data-index');
                if (window.generateReservationContractManual) {
                    window.generateReservationContractManual(idx !== null && idx !== '' ? parseInt(idx, 10) : 0);
                }
            };
        });
        appContainer.querySelectorAll('[data-action="pay-balance"]').forEach(btn => {
            btn.onclick = () => {
                const idx = btn.getAttribute('data-index');
                if (window.receiveReservationBalance) {
                    window.receiveReservationBalance(idx !== null && idx !== '' ? parseInt(idx, 10) : 0);
                }
            };
        });
        appContainer.querySelectorAll('[data-action="notify-reservation"]').forEach(btn => {
            btn.onclick = () => {
                const idx = btn.getAttribute('data-index');
                if (window._handleResend) window._handleResend(parseInt(idx, 10));
            };
        });
        appContainer.querySelectorAll('[data-action="delete-reservation"]').forEach(btn => {
            btn.onclick = () => {
                const id = btn.getAttribute('data-id');
                if (window._handleDelete) window._handleDelete(parseInt(id, 10));
            };
        });
    }

    // Navigation Click Handler
    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            // Se for logout
            if (item.classList.contains('logout')) {
                e.preventDefault();
                localStorage.removeItem('adminToken');
                window.location.href = 'login.html';
                return;
            }

            // Se for link externo ignora
            if (item.getAttribute('target') === '_blank') return;

            e.preventDefault();

            // Remove active from all
            navItems.forEach(nav => nav.classList.remove('active'));

            // Add active to clicked
            item.classList.add('active');

            // Render View
            const view = item.getAttribute('data-view');
            renderView(view);

            // Close mobile menu on navigate
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('mobile-open');
            }
        });
    });

    function isSecretaryUser() {
        const role = localStorage.getItem('adminRole') || 'admin';
        return isSecretaryRole(role);
    }

    function removeAddChaletButtonsForSecretary(root = document) {
        if (!isSecretaryUser() || !root || !root.querySelectorAll) return;
        root.querySelectorAll('button, a').forEach(el => {
            const label = (el.textContent || '').toLowerCase().trim();
            const hasAddText = label.includes('adicionar chalé') || label.includes('adicionar chale');
            if (hasAddText || el.getAttribute('data-action') === 'add-chalet') {
                el.remove();
            }
        });
    }

    // Modals Handling (Global)
    window.openChaletModal = function (index = null) {
        if (index === null && isSecretaryUser()) {
            alert('Você não tem permissão para cadastrar novos chalés.');
            return;
        }

        const chalet = index !== null ? chaletsData[index] : null;

        let holidaysHtml = '';
        if (chalet && chalet.holidays && chalet.holidays.length > 0) {
            holidaysHtml = chalet.holidays.map(h => `
                <div class="holiday-row" style="display:flex; gap:0.5rem; margin-bottom: 0.5rem;">
                    <input type="date" class="form-control" name="hol_date[]" value="${h.date}" required>
                    <input type="number" class="form-control" name="hol_price[]" value="${h.price}" placeholder="Preço (R$)" required>
                    <input type="text" class="form-control" name="hol_desc[]" value="${h.descr || ''}" placeholder="Descrição (ex: Natal)">
                    <button type="button" class="btn-icon" style="color:var(--danger)" onclick="this.parentElement.remove()"><i class="ph ph-trash"></i></button>
                </div>
            `).join('');
        }

        const modalHtml = `
            <div class="modal-overlay" id="addChaletModal" onclick="if(event.target === this) this.remove()">
                <div class="modal-content" style="max-width: 600px; max-height: 90vh; overflow-y: auto;">
                    <div class="modal-header">
                        <h3>${chalet ? 'Editar Hospedagem' : 'Adicionar Nova Hospedagem'}</h3>
                        <button class="close-btn" onclick="document.getElementById('addChaletModal').remove()"><i class="ph ph-x"></i></button>
                    </div>
                    <form onsubmit="handleAdicionarChale(event)">
                        <input type="hidden" id="chaletId" value="${chalet ? chalet.id : ''}">
                        
                        <div class="form-group">
                            <label>Nome da Hospedagem</label>
                            <input type="text" id="addName" class="form-control" required placeholder="Ex: Flat Romântico, Suíte Master, Chalé Alpino" value="${chalet ? chalet.name : ''}">
                        </div>
                        
                        <div style="display:flex; gap:1rem;">
                            <div class="form-group" style="flex:1;">
                                <label>Etiqueta de Destaque</label>
                                <input type="text" id="addBadge" class="form-control" placeholder="Ex: Lançamento, Mais Popular" value="${chalet && chalet.badge ? chalet.badge : ''}">
                                <small style="color: #666;">Aparece sobre a foto na página inicial</small>
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>Tipo (Ex: Flat, Quarto, Chalé)</label>
                                <input type="text" id="addType" class="form-control" required placeholder="Flat, Quarto, Chalé, Bangalô..." value="${chalet ? chalet.type : ''}">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>Preço Base Estático (R$)</label>
                                <input type="number" id="addPrice" class="form-control" required min="10" value="${chalet ? chalet.price : ''}">
                            </div>
                        </div>
                        <div style="display:flex; gap:1rem; flex-wrap:wrap; margin-top:0.5rem;">
                            <div class="form-group" style="flex:1; min-width:180px;">
                                <label>Hóspedes Inclusos (Base)</label>
                                <input type="number" id="addBaseGuests" class="form-control" min="1" max="50" value="${chalet != null && chalet.base_guests != null ? chalet.base_guests : 2}">
                                <small style="color:#666;">Incluídos no preço da diária; acima aplica-se a taxa extra por noite.</small>
                            </div>
                            <div class="form-group" style="flex:1; min-width:180px;">
                                <label>Capacidade Máxima</label>
                                <input type="number" id="addMaxGuests" class="form-control" min="1" max="50" value="${chalet != null && chalet.max_guests != null ? chalet.max_guests : 4}">
                                <small style="color:#666;">Limite absoluto (site e backend bloqueiam reservas acima disto).</small>
                            </div>
                            <div class="form-group" style="flex:1; min-width:180px;">
                                <label>Taxa por Hóspede Extra (R$)</label>
                                <input type="number" id="addExtraGuestFee" class="form-control" min="0" step="0.01" value="${chalet != null && chalet.extra_guest_fee != null ? chalet.extra_guest_fee : '0'}">
                            </div>
                        </div>

                        <div class="form-group" style="background: var(--bg-light); padding: 1rem; border-radius: 8px;">
                            <h4 style="margin-bottom:0.5rem; font-size:0.95rem; color:var(--text-dark);">Fotos da Hospedagem</h4>
                            <div style="margin-bottom: 1rem;">
                                <label style="display:block; margin-bottom: 0.25rem;">Foto Principal (Capa)</label>
                                <div style="position: relative; border: 2px dashed var(--border-color); padding: 1rem; text-align: center; border-radius: 4px; background: #fff;">
                                    <input type="file" id="addMainImage" class="form-control" accept="image/*" style="opacity: 0; position: absolute; top: 0; left: 0; width: 100%; height: 100%; cursor: pointer;" onchange="document.getElementById('mainImgName').textContent = this.files.length ? this.files[0].name : 'Nenhuma foto selecionada'">
                                    <i class="ph ph-image" style="font-size: 2rem; color: #ccc;"></i>
                                    <div id="mainImgName" style="color: #666; font-size: 0.85rem; margin-top: 0.5rem;">Clique para selecionar ou arraste a Foto Principal</div>
                                </div>
                                ${chalet && chalet.main_image ? `<small style="display:block;margin-top:0.5rem;">Capa atual: <a href="../${chalet.main_image}" target="_blank">Ver</a></small>` : ''}
                            </div>
                            
                            <div>
                                <label style="display:block; margin-bottom: 0.25rem;">Galeria de Fotos (Múltiplas)</label>
                                <div style="position: relative; border: 2px dashed var(--border-color); padding: 1rem; text-align: center; border-radius: 4px; background: #fff;">
                                    <input type="file" id="addGalleryImages" class="form-control" accept="image/*" multiple style="opacity: 0; position: absolute; top: 0; left: 0; width: 100%; height: 100%; cursor: pointer;" onchange="document.getElementById('galleryImgNames').textContent = this.files.length ? this.files.length + ' foto(s) selecionada(s)' : 'Nenhuma foto selecionada'">
                                    <i class="ph ph-images" style="font-size: 2rem; color: #ccc;"></i>
                                    <div id="galleryImgNames" style="color: #666; font-size: 0.85rem; margin-top: 0.5rem;">Clique para selecionar várias fotos</div>
                                </div>
                                ${chalet && chalet.images && chalet.images.length > 0 ? `<small style="display:block;margin-top:0.5rem;">${chalet.images.length} foto(s) na galeria atual.</small>` : ''}
                            </div>
                        </div>

                        <div class="form-group">
                            <h4 style="margin-bottom:0.5rem; font-size:0.95rem; color:var(--text-dark);">Preços Dinâmicos por Dia da Semana</h4>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
                                <div><small>Seg</small><input type="number" id="p_mon" class="form-control" placeholder="R$" value="${chalet ? (chalet.price_mon || '') : ''}"></div>
                                <div><small>Ter</small><input type="number" id="p_tue" class="form-control" placeholder="R$" value="${chalet ? (chalet.price_tue || '') : ''}"></div>
                                <div><small>Qua</small><input type="number" id="p_wed" class="form-control" placeholder="R$" value="${chalet ? (chalet.price_wed || '') : ''}"></div>
                                <div><small>Qui</small><input type="number" id="p_thu" class="form-control" placeholder="R$" value="${chalet ? (chalet.price_thu || '') : ''}"></div>
                                <div><small>Sex</small><input type="number" id="p_fri" class="form-control" placeholder="R$" value="${chalet ? (chalet.price_fri || '') : ''}"></div>
                                <div><small>Sab</small><input type="number" id="p_sat" class="form-control" placeholder="R$" value="${chalet ? (chalet.price_sat || '') : ''}"></div>
                                <div><small>Dom</small><input type="number" id="p_sun" class="form-control" placeholder="R$" value="${chalet ? (chalet.price_sun || '') : ''}"></div>
                            </div>
                        </div>

                        <div class="form-group" style="padding: 1rem; background: var(--bg-light); border-radius: 8px;">
                            <h4 style="margin-bottom:0.5rem; font-size:0.95rem; color:var(--text-dark);">Preços Especiais (Feriados / Datas)</h4>
                            <div id="holidaysList">
                                ${holidaysHtml}
                            </div>
                            <button type="button" class="btn btn-primary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem; margin-top: 0.5rem;" onclick="addHolidayRow()">
                                <i class="ph ph-plus"></i> Adicionar Data Específica
                            </button>
                        </div>
                        
                        <div class="form-group">
                            <label>Descrição Curta</label>
                            <textarea id="addDesc" class="form-control" rows="2" placeholder="Ex: Perfeito para casais...">${chalet ? chalet.description : ''}</textarea>
                        </div>

                        <div class="form-group">
                            <label>Descrição Completa (Painel "Saber mais")</label>
                            <textarea id="addFullDesc" class="form-control" rows="5" placeholder="Digite os detalhes, comodidades específicas, etc...">${chalet ? (chalet.full_description || '') : ''}</textarea>
                            <small style="color: #666;">Você pode utilzar quebras de linha que o sistema vai respeitar no site.</small>
                        </div>
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select id="addStatus" class="form-control">
                                <option value="Ativo" ${chalet && chalet.status === 'Ativo' ? 'selected' : ''}>Ativo</option>
                                <option value="Inativo" ${chalet && chalet.status === 'Inativo' ? 'selected' : ''}>Inativo</option>
                            </select>
                        </div>

                        <button type="submit" class="btn" id="submitChaletBtn" style="width:100%; justify-content:center; margin-top: 1rem;">
                            ${chalet ? 'Salvar Alterações' : 'Salvar Hospedagem'}
                        </button>
                    </form>
                </div>
            </div>
        `;
        document.getElementById('modalContainer').innerHTML = modalHtml;
    }

    window.addHolidayRow = function () {
        const row = document.createElement('div');
        row.className = 'holiday-row';
        row.style.cssText = 'display:flex; gap:0.5rem; margin-bottom: 0.5rem;';
        row.innerHTML = `
            <input type="date" class="form-control" name="hol_date[]" required>
            <input type="number" class="form-control" name="hol_price[]" placeholder="Preço (R$)" required>
            <input type="text" class="form-control" name="hol_desc[]" placeholder="Descrição (ex: Natal)">
            <button type="button" class="btn-icon" style="color:var(--danger)" onclick="this.parentElement.remove()"><i class="ph ph-trash"></i></button>
        `;
        document.getElementById('holidaysList').appendChild(row);
    }

    // Reservation Handling
    window.openEditReservationModal = function (index) {
        const isEditing = index !== null;
        const res = isEditing ? reservationsData[index] : {
            id: '',
            guest_name: '',
            guest_email: '',
            guest_phone: '',
            guests_adults: 2,
            guests_children: 0,
            chalet_id: chaletsData.length > 0 ? chaletsData[0].id : '',
            checkin_date: '',
            checkout_date: '',
            total_amount: '',
            status: 'Pendente'
        };

        let chaletsOptions = chaletsData.map(c =>
            `<option value="${c.id}" ${res.chalet_id == c.id ? 'selected' : ''}>${c.name}</option>`
        ).join('');

        const formTitle = isEditing ? `Editar Reserva #${String(res.id).padStart(3, '0')}` : 'Criar Nova Reserva';
        const jsParamId = isEditing ? res.id : 'null';

        const modalHtml = `
            <div class="modal-overlay" id="editResModal" onclick="if(event.target === this) this.remove()">
                <div class="modal-content" style="max-width: 500px;">
                    <div class="modal-header">
                        <h3>${formTitle}</h3>
                        <button class="close-btn" onclick="document.getElementById('editResModal').remove()"><i class="ph ph-x"></i></button>
                    </div>
                    <form onsubmit="handleEditReservation(event, ${jsParamId})">
                        <div class="form-group">
                            <label>Nome do Hóspede</label>
                            <input type="text" id="editResName" class="form-control" required value="${res.guest_name}">
                        </div>
                        <div style="display:flex; gap:1rem;">
                            <div class="form-group" style="flex:1;">
                                <label>E-mail</label>
                                <input type="email" id="editResEmail" class="form-control" value="${res.guest_email || ''}">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>Telefone</label>
                                <input type="text" id="editResPhone" class="form-control" value="${res.guest_phone || ''}">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Hóspedes</label>
                            <select id="editResGuestsOption" class="form-control">
                                <option value="">Carregando...</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Hospedagem</label>
                            <select id="editResChaletId" class="form-control" required>
                                ${chaletsOptions}
                            </select>
                        </div>
                        <div style="display:flex; gap:1rem;">
                            <div class="form-group" style="flex:1;">
                                <label>Check-in</label>
                                <input type="date" id="editResCheckin" class="form-control" required value="${res.checkin_date}">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>Check-out</label>
                                <input type="date" id="editResCheckout" class="form-control" required value="${res.checkout_date}">
                            </div>
                        </div>
                        <div style="display:flex; gap:1rem;">
                            <div class="form-group" style="flex:1;">
                                <label>Valor Total (R$)</label>
                                <input type="number" step="0.01" id="editResTotal" class="form-control" required value="${res.total_amount}">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>Status</label>
                                <select id="editResStatus" class="form-control">
                                    <option value="Pendente" ${res.status === 'Pendente' ? 'selected' : ''}>Pendente</option>
                                    <option value="Confirmada" ${res.status === 'Confirmada' ? 'selected' : ''}>Confirmada</option>
                                    <option value="Cancelada" ${res.status === 'Cancelada' ? 'selected' : ''}>Cancelada</option>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn" style="width:100%; justify-content:center; margin-top: 1rem;">${isEditing ? 'Atualizar Reserva' : 'Criar Reserva'}</button>
                    </form>
                </div>
            </div>
        `;
        document.getElementById('modalContainer').innerHTML = modalHtml;

        // Popula o dropdown de hóspedes dinamicamente com base no max_guests da
        // hospedagem selecionada e reage à troca de hospedagem.
        const chaletSelect = document.getElementById('editResChaletId');
        const guestsSelect = document.getElementById('editResGuestsOption');
        const currentGuestsVal = res ? `${res.guests_adults || 2}_${res.guests_children || 0}` : '2_0';

        function updateGuestsDropdown() {
            if (!chaletSelect || !guestsSelect || typeof chaletsData === 'undefined') return;
            const selectedChalet = chaletsData.find(c => String(c.id) === String(chaletSelect.value)) || {};
            const maxGuests = selectedChalet.max_guests || 4;
            const valToSet = guestsSelect.value && guestsSelect.value !== '' ? guestsSelect.value : currentGuestsVal;
            renderGuestOptionsAdmin(guestsSelect, maxGuests, valToSet);
        }

        if (chaletSelect) {
            chaletSelect.addEventListener('change', updateGuestsDropdown);
            updateGuestsDropdown();
        }
    }

    window.handleEditReservation = async function (e, id) {
        e.preventDefault();

        const payload = {
            guest_name: document.getElementById('editResName').value,
            guest_email: document.getElementById('editResEmail').value,
            guest_phone: document.getElementById('editResPhone').value,
            guests_adults: parseGuestsOptionAdmin(document.getElementById('editResGuestsOption').value).adults,
            guests_children: parseGuestsOptionAdmin(document.getElementById('editResGuestsOption').value).children,
            chalet_id: document.getElementById('editResChaletId').value,
            checkin_date: document.getElementById('editResCheckin').value,
            checkout_date: document.getElementById('editResCheckout').value,
            total_amount: document.getElementById('editResTotal').value,
            status: document.getElementById('editResStatus').value
        };

        const method = id ? 'PUT' : 'POST';
        const url = id ? `../api/reservations.php?id=${id}` : '../api/reservations.php';

        try {
            const res = await fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (res.ok) {
                const data = await res.json();
                alert(id ? 'Reserva atualizada com sucesso!' : 'Reserva criada com sucesso!');

                // Se for criação e estiver confirmada, dispara Webhook
                if (!id && payload.status === 'Confirmada') {
                    const chalet = chaletsData.find(c => c.id == payload.chalet_id);
                    const totalNum = parseFloat(payload.total_amount) || 0;
                    const policy = getPaymentPolicy(payload.payment_rule || 'full');
                    const valorPagoNum = (totalNum * Number(policy.percent_now || 100)) / 100;
                    const webhookData = {
                        clientName: payload.guest_name,
                        clientPhone: payload.guest_phone,
                        chaletName: chalet ? chalet.name : 'Acomodação',
                        checkin: formatDateBR(payload.checkin_date),
                        checkout: formatDateBR(payload.checkout_date),
                        total: 'R$ ' + totalNum.toLocaleString('pt-BR', { minimumFractionDigits: 2 }),
                        valorPago: 'R$ ' + valorPagoNum.toLocaleString('pt-BR', { minimumFractionDigits: 2 }),
                        condicao: policy.label || 'Condição de pagamento configurada',
                        paymentRule: payload.payment_rule || 'full',
                        id: data.id || '---'
                    };
                    sendEvolutionWebhooks(webhookData, true);
                }

                document.getElementById('editResModal').remove();
                await fetchApiData();
                renderView('reservations');
            } else {
                alert('Erro ao salvar a reserva.');
            }
        } catch (err) {
            console.error('Falha na requisição de Reserva', err);
        }
    }

    window.openReservationContract = async function (index) {
        const res = reservationsData[index];
        if (!res || !res.id || !res.contract_filename) {
            alert('Contrato ainda não foi gerado para esta reserva.');
            return;
        }

        if (!internalApiKey) {
            alert('Chave interna não disponível. Abra Configurações e recarregue os dados.');
            return;
        }

        try {
            const req = await fetch(`../api/download_contract.php?id=${encodeURIComponent(res.id)}`, {
                method: 'GET',
                headers: { 'X-Internal-Key': internalApiKey }
            });
            if (!req.ok) {
                const err = await req.json().catch(() => ({}));
                throw new Error(err.error || 'Falha ao abrir contrato');
            }

            const blob = await req.blob();
            const fileUrl = URL.createObjectURL(blob);
            window.open(fileUrl, '_blank');
            setTimeout(() => URL.revokeObjectURL(fileUrl), 60000);
        } catch (e) {
            console.error(e);
            alert('Não foi possível abrir o contrato PDF.');
        }
    }

    window.generateReservationContractManual = async function (index) {
        const res = reservationsData[index];
        if (!res || !res.id) {
            alert('Reserva inválida para geração de contrato.');
            return;
        }
        if (!internalApiKey) {
            alert('Chave interna não disponível. Abra Configurações e recarregue os dados.');
            return;
        }

        try {
            const req = await fetch('../api/generate_contract.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Internal-Key': internalApiKey
                },
                body: JSON.stringify({ reservation_id: res.id })
            });
            const data = await req.json().catch(() => ({}));
            if (!req.ok || !data.success) {
                throw new Error(data.error || 'Falha ao gerar contrato');
            }

            alert('Contrato gerado com sucesso.');
            await fetchApiData();
            renderView('reservations');
        } catch (e) {
            console.error(e);
            alert('Não foi possível gerar o contrato manualmente.');
        }
    }

    window.receiveReservationBalance = async function (index) {
        const res = reservationsData[index];
        if (!res || !res.id) {
            alert('Reserva inválida para baixa de saldo.');
            return;
        }
        if (getPaymentPolicy(res.payment_rule || 'full').percent_now >= 100) {
            alert('A ação de recebimento de saldo é aplicável apenas para reservas parciais.');
            return;
        }
        if (Number(res.balance_paid || 0) === 1) {
            alert('Esta reserva já está com saldo quitado.');
            return;
        }
        if (!internalApiKey) {
            alert('Chave interna não disponível. Abra Configurações e recarregue os dados.');
            return;
        }

        const ok = confirm('Confirma o recebimento físico do saldo desta reserva?');
        if (!ok) return;

        try {
            const req = await fetch('../api/pay_balance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Internal-Key': internalApiKey
                },
                body: JSON.stringify({ reservation_id: res.id })
            });
            const data = await req.json().catch(() => ({}));
            if (!req.ok || !data.success) {
                throw new Error(data.error || 'Falha ao receber saldo');
            }

            alert('Saldo recebido e baixa registrada com sucesso.');
            await fetchApiData();
            renderView('reservations');
        } catch (e) {
            console.error(e);
            alert('Não foi possível registrar a baixa do saldo.');
        }
    }

    window.handleAdicionarChale = async function (e) {
        e.preventDefault();
        const submitBtn = document.getElementById('submitChaletBtn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Salvando...';

        const formData = new FormData();
        const id = document.getElementById('chaletId').value;
        if (!id && isSecretaryUser()) {
            alert('Você não tem permissão para cadastrar novos chalés.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Salvar Hospedagem';
            return;
        }
        if (id) formData.append('id', id);

        formData.append('name', document.getElementById('addName').value);
        formData.append('type', document.getElementById('addType').value);
        formData.append('badge', document.getElementById('addBadge').value);
        formData.append('price', document.getElementById('addPrice').value);
        const baseGuestsEl = document.getElementById('addBaseGuests');
        const maxGuestsEl = document.getElementById('addMaxGuests');
        const extraFeeEl = document.getElementById('addExtraGuestFee');
        const baseGuestsNum = parseInt(baseGuestsEl ? baseGuestsEl.value : '', 10);
        const maxGuestsNum = parseInt(maxGuestsEl ? maxGuestsEl.value : '', 10);
        if (Number.isFinite(baseGuestsNum) && Number.isFinite(maxGuestsNum) && maxGuestsNum < baseGuestsNum) {
            alert('A capacidade máxima não pode ser menor que os hóspedes inclusos na base.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Salvar Hospedagem';
            return;
        }
        if (baseGuestsEl) formData.append('base_guests', baseGuestsEl.value);
        if (maxGuestsEl) formData.append('max_guests', maxGuestsEl.value);
        if (extraFeeEl) formData.append('extra_guest_fee', extraFeeEl.value);
        formData.append('description', document.getElementById('addDesc').value);
        formData.append('full_description', document.getElementById('addFullDesc').value);
        formData.append('status', document.getElementById('addStatus').value);

        // Weekly prices
        const days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        days.forEach(day => {
            const val = document.getElementById('p_' + day).value;
            if (val) formData.append('price_' + day, val);
        });

        // Holidays JSON
        const holDates = document.getElementsByName('hol_date[]');
        const holPrices = document.getElementsByName('hol_price[]');
        const holDescs = document.getElementsByName('hol_desc[]');
        const holidaysArray = [];
        for (let i = 0; i < holDates.length; i++) {
            if (holDates[i].value && holPrices[i].value) {
                holidaysArray.push({
                    date: holDates[i].value,
                    price: holPrices[i].value,
                    descr: holDescs[i].value
                });
            }
        }
        if (holidaysArray.length > 0) {
            formData.append('holidays', JSON.stringify(holidaysArray));
        }

        // Image file (Main)
        const imageFile = document.getElementById('addMainImage').files[0];
        if (imageFile) {
            formData.append('main_image', imageFile);
        }

        // Gallery Images (Multiple) - PHP espera $_FILES['images']
        const galleryFiles = document.getElementById('addGalleryImages').files;
        if (galleryFiles.length > 0) {
            for (let i = 0; i < galleryFiles.length; i++) {
                formData.append('images[]', galleryFiles[i]);
            }
        }

        try {
            const res = await fetch('../api/chalets.php', {
                method: 'POST', // We use POST for FormData
                body: formData
            });

            if (res.ok) {
                alert('Hospedagem salva com sucesso!');
                document.getElementById('addChaletModal').remove();
                await fetchApiData();
                renderView('chalets');
            } else {
                const text = await res.text();
                let err = {};
                try { err = JSON.parse(text); } catch { err = { error: 'Resposta inválida', details: text ? text.substring(0, 300) : res.statusText }; }
                const msg = err.details ? `${err.error}\n\nDetalhe: ${err.details}` : (err.error || 'Erro desconhecido');
                alert('Erro ao salvar chalé: ' + msg);
            }
        } catch (err) {
            console.error('Falha no insert/update', err);
            alert("Erro de conexão");
        } finally {
            if (document.getElementById('submitChaletBtn')) {
                document.getElementById('submitChaletBtn').disabled = false;
                document.getElementById('submitChaletBtn').textContent = 'Salvar Hospedagem';
            }
        }
    }

    window.deleteChalet = async function (id, name) {
        if (!confirm(`Excluir o chalé "${name}"? Esta ação não pode ser desfeita.`)) return;
        try {
            const res = await fetch(`../api/chalets.php?id=${id}`, { method: 'DELETE' });
            if (res.ok) {
                alert('Hospedagem excluída com sucesso.');
                await fetchApiData();
                renderView('chalets');
            } else {
                const err = await res.json().catch(() => ({}));
                alert(err.error || 'Erro ao excluir chalé');
            }
        } catch (e) {
            alert('Erro de conexão');
        }
    };

    /* =========================================
       USUÁRIOS (CRUD)
       ========================================= */
    const MENU_OPTIONS = [
        { id: 'dashboard', label: 'Dashboard' },
        { id: 'reservations', label: 'Reservas' },
        { id: 'chalets', label: 'Hospedagens' },
        { id: 'financeiro', label: 'Financeiro' },
        { id: 'coupons', label: 'Cupons' },
        { id: 'extras', label: 'Serviços Extras' },
        { id: 'settings', label: 'Configurações' },
        { id: 'customization', label: 'Personalização' },
        { id: 'users', label: 'Usuários' }
    ];

    async function loadUsersTable() {
        const tbody = document.getElementById('usersTableBody');
        if (!tbody) return;
        try {
            const res = await fetch('../api/users.php');
            const users = await res.json();
            if (!Array.isArray(users)) throw new Error('Resposta inválida');
            tbody.innerHTML = users.map(u => `
                <tr>
                    <td>${u.name || '-'}</td>
                    <td>${u.email}</td>
                    <td><span class="badge ${u.role === 'admin' ? 'success' : 'info'}">${u.role === 'admin' ? 'Administrador' : 'Secretaria'}</span></td>
                    <td>
                        <button class="btn-icon" onclick="openUserModal(${u.id})" title="Editar"><i class="ph ph-pencil-simple"></i></button>
                        <button class="btn-icon" style="color:var(--danger)" onclick="deleteUser(${u.id}, '${(u.email || '').replace(/'/g, "\\'")}')" title="Excluir"><i class="ph ph-trash"></i></button>
                    </td>
                </tr>
            `).join('') || '<tr><td colspan="4" style="text-align:center;">Nenhum usuário cadastrado</td></tr>';
        } catch (e) {
            console.error('Erro ao carregar usuários:', e);
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--danger);">Erro ao carregar usuários</td></tr>';
        }
    }

    window.openUserModal = async function (userId = null) {
        let user = null;
        if (userId) {
            try {
                const res = await fetch('../api/users.php');
                const users = await res.json();
                user = users.find(u => u.id == userId);
            } catch (e) {
                alert('Erro ao carregar usuário');
                return;
            }
        }

        const perms = user && user.permissions && user.permissions.length ? user.permissions : [];
        const checkboxesHtml = MENU_OPTIONS.map(m => `
            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:normal;">
                <input type="checkbox" name="perm_${m.id}" ${perms.includes(m.id) ? 'checked' : ''}>
                <span>${m.label}</span>
            </label>
        `).join('');

        const modalHtml = `
            <div class="modal-overlay" id="userModal" onclick="if(event.target === this) this.remove()">
                <div class="modal-content" style="max-width: 500px;">
                    <div class="modal-header">
                        <h3>${user ? 'Editar Usuário' : 'Novo Usuário'}</h3>
                        <button class="close-btn" onclick="document.getElementById('userModal').remove()"><i class="ph ph-x"></i></button>
                    </div>
                    <form onsubmit="handleSaveUser(event)">
                        <input type="hidden" id="userId" value="${user ? user.id : ''}">
                        <div class="form-group">
                            <label>Nome</label>
                            <input type="text" id="userName" class="form-control" placeholder="Ex: João Silva" value="${user ? (user.name || '') : ''}">
                        </div>
                        <div class="form-group">
                            <label>E-mail</label>
                            <input type="email" id="userEmail" class="form-control" required placeholder="email@exemplo.com" value="${user ? user.email : ''}">
                        </div>
                        <div class="form-group">
                            <label>Senha ${user ? '(deixe em branco para manter)' : ''}</label>
                            <input type="password" id="userPassword" class="form-control" placeholder="••••••••" ${user ? '' : 'required'}>
                        </div>
                        <div class="form-group">
                            <label>Perfil</label>
                            <select id="userRole" class="form-control">
                                <option value="admin" ${user && user.role === 'admin' ? 'selected' : ''}>Administrador</option>
                                <option value="secretaria" ${user && user.role === 'secretaria' ? 'selected' : ''}>Secretaria</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Menus com acesso</label>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.5rem; margin-top:0.5rem;">
                                ${checkboxesHtml}
                            </div>
                            <small style="color:#666;">Marque os menus que este usuário poderá acessar.</small>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">Salvar</button>
                    </form>
                </div>
            </div>
        `;
        document.getElementById('modalContainer').innerHTML = modalHtml;
    };

    window.handleSaveUser = async function (e) {
        e.preventDefault();
        const id = document.getElementById('userId').value;
        const name = document.getElementById('userName').value.trim();
        const email = document.getElementById('userEmail').value.trim();
        const password = document.getElementById('userPassword').value;
        const role = document.getElementById('userRole').value;

        const permissions = [];
        MENU_OPTIONS.forEach(m => {
            const cb = document.querySelector(`input[name="perm_${m.id}"]`);
            if (cb && cb.checked) permissions.push(m.id);
        });

        const payload = { name, email, role, permissions };
        if (id) payload.id = parseInt(id);
        if (password) payload.password = password;

        try {
            const res = await fetch('../api/users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            if (res.ok) {
                alert('Usuário salvo com sucesso!');
                document.getElementById('userModal').remove();
                await loadUsersTable();
            } else {
                const err = await res.json().catch(() => ({}));
                alert(err.error || 'Erro ao salvar usuário');
            }
        } catch (e) {
            console.error(e);
            alert('Erro de conexão');
        }
    };

    window.deleteUser = async function (id, email) {
        if (!confirm(`Excluir o usuário ${email}? Esta ação não pode ser desfeita.`)) return;
        try {
            const res = await fetch(`../api/users.php?id=${id}`, { method: 'DELETE' });
            if (res.ok) {
                alert('Usuário excluído.');
                await loadUsersTable();
            } else {
                const err = await res.json().catch(() => ({}));
                alert(err.error || 'Erro ao excluir');
            }
        } catch (e) {
            alert('Erro de conexão');
        }
    };

    async function loadAdminThemeFromSettings() {
        try {
            const res = await fetch('../api/settings.php');
            if (!res.ok) return;
            const data = await res.json();
            applyAdminTheme(data.primary_color || '#ea580c', data.secondary_color || '#1e293b');
            const brandName = (data.company_name && String(data.company_name).trim()) ||
                (data.site_title && String(data.site_title).trim()) ||
                'Admin';
            const brandEl = document.getElementById('adminBrandName');
            if (brandEl) brandEl.textContent = brandName;
            const titleEl = document.getElementById('adminPageTitle');
            if (titleEl) titleEl.textContent = 'Admin · ' + brandName;
            try { document.title = 'Admin · ' + brandName; } catch (_) { /* noop */ }
        } catch (e) {
            // Usa tema padrão quando não conseguir carregar.
        }
    }

    // Initialize the admin app
    loadAdminThemeFromSettings();
    renderView('dashboard');
    removeAddChaletButtonsForSecretary(document);

    // Guarda global para remover o CTA caso algum trecho re-renderize botão de cadastro
    const observer = new MutationObserver(() => removeAddChaletButtonsForSecretary(document));
    observer.observe(document.body, { childList: true, subtree: true });
});
