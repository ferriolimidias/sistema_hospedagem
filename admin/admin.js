

document.addEventListener('DOMContentLoaded', async () => {

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
    const adminRoleRaw = localStorage.getItem('adminRole') || 'admin';
    const adminRole = normalizeRole(adminRoleRaw);

    // Garante envio do cookie de sessão PHP em todas as chamadas do painel.
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (input, init) => {
        const opts = (init && typeof init === 'object') ? init : {};
        const res = await nativeFetch(input, { ...opts, credentials: opts.credentials || 'include' });
        if (res.status === 401) {
            try {
                localStorage.removeItem('adminRole');
                localStorage.removeItem('adminName');
                localStorage.removeItem('adminPermissions');
            } catch (_) { /* noop */ }
            window.location.href = 'login.html';
        }
        return res;
    };

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
        if (settingsNav) settingsNav.style.display = 'none';
        if (customizationNav) customizationNav.style.display = 'none';
        if (usersNav) usersNav.style.display = 'none';
        if (financeiroNav) financeiroNav.style.display = 'none';
        if (couponsNav) couponsNav.style.display = 'none';
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

    /* ------------------------------------------------------------------
     * CHAVE INTERNA (X-Internal-Key) — NÃO duplicar fora deste bloco.
     * Qualquer resposta JSON de api/settings.php deve passar por
     * persistInternalApiKeyFromPayload() para manter internalApiKey e
     * window.internalKey alinhados (FAQs, cupons, extras, financeiro).
     * ------------------------------------------------------------------ */
    function extractInternalKeyFromSettingsJson(data) {
        if (!data || typeof data !== 'object') return '';
        const a = data.internalApiKey;
        const b = data.internal_key;
        if (typeof a === 'string' && a.trim() !== '') return a.trim();
        if (typeof b === 'string' && b.trim() !== '') return b.trim();
        return '';
    }

    function persistInternalApiKeyFromPayload(data) {
        const key = extractInternalKeyFromSettingsJson(data);
        if (!key) return false;
        internalApiKey = key;
        try { window.internalKey = key; } catch (_) { /* noop */ }
        try { sessionStorage.setItem('internalKey', key); } catch (_) { /* noop */ }
        return true;
    }

    function getStoredInternalApiKey() {
        if (typeof internalApiKey === 'string' && internalApiKey.trim() !== '') {
            return internalApiKey.trim();
        }
        try {
            if (typeof window !== 'undefined' && typeof window.internalKey === 'string' && window.internalKey.trim() !== '') {
                internalApiKey = window.internalKey.trim();
                return internalApiKey;
            }
        } catch (_) { /* noop */ }
        try {
            const stored = sessionStorage.getItem('internalKey');
            if (typeof stored === 'string' && stored.trim() !== '') {
                internalApiKey = stored.trim();
                try { window.internalKey = internalApiKey; } catch (_) { /* noop */ }
                return internalApiKey;
            }
        } catch (_) { /* noop */ }
        return '';
    }

    let paymentPolicies = [
        { code: 'half', label: 'Sinal de 50% para reserva', percent_now: 50 },
        { code: 'full', label: 'Pagamento 100% Antecipado', percent_now: 100 }
    ];

    // Gallery Manager state (thumbnails + pending deletes).
    // Para Hero (personalização) o estado vive enquanto a página está aberta.
    // Para Chalé, reiniciamos ao abrir/fechar cada modal.
    const galleryState = {
        hero: { current: [], toDelete: [] },
        chalet: { current: [], toDelete: [] }
    };
    let activeHeroImages = [];
    const testimonialRemovalState = { 1: false, 2: false, 3: false };

    function toAssetUrl(src) {
        const v = String(src || '').trim();
        if (v === '') return '';
        if (/^(https?:)?\/\//i.test(v) || v.startsWith('data:') || v.startsWith('blob:')) return v;
        return '../' + v.replace(/^\/+/, '');
    }

    function escapeAttr(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function buildThumbAssetUrl(src) {
        const raw = String(src || '').trim();
        if (!raw) return '';
        if (raw.includes('_thumb.webp')) {
            if (/^(https?:)?\/\//i.test(raw) || raw.startsWith('data:') || raw.startsWith('blob:')) return raw;
            return '../' + raw.replace(/^\/+/, '');
        }
        if (/^(https?:)?\/\//i.test(raw) || raw.startsWith('data:') || raw.startsWith('blob:')) return raw;
        const normalized = raw.replace(/^\/+/, '');
        const thumbCandidate = normalized.replace(/\.webp$/i, '_thumb.webp');
        return '../' + thumbCandidate;
    }

    async function compressImageFile(file, opts = {}) {
        if (!(file instanceof File) || !String(file.type || '').startsWith('image/')) return file;
        const maxWidth = Number(opts.maxWidth || 1920);
        const quality = Number(opts.quality || 0.8);
        const outputType = (file.type === 'image/png' && !opts.forceLossy) ? 'image/png' : 'image/webp';

        const dataUrl = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error('Falha ao ler imagem para compressão.'));
            reader.readAsDataURL(file);
        });

        const img = await new Promise((resolve, reject) => {
            const i = new Image();
            i.onload = () => resolve(i);
            i.onerror = () => reject(new Error('Formato de imagem não suportado para compressão.'));
            i.src = dataUrl;
        });

        const srcW = Math.max(1, img.width || 1);
        const srcH = Math.max(1, img.height || 1);
        const ratio = srcW > maxWidth ? (maxWidth / srcW) : 1;
        const targetW = Math.max(1, Math.round(srcW * ratio));
        const targetH = Math.max(1, Math.round(srcH * ratio));

        const canvas = document.createElement('canvas');
        canvas.width = targetW;
        canvas.height = targetH;
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Canvas indisponível para compressão.');
        ctx.drawImage(img, 0, 0, targetW, targetH);

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, outputType, quality));
        if (!blob) throw new Error('Falha ao gerar imagem comprimida.');

        const outName = String(file.name || 'upload')
            .replace(/\.[a-z0-9]+$/i, '')
            .replace(/[^a-z0-9._-]/gi, '_') + (outputType === 'image/png' ? '.png' : '.webp');
        return new File([blob], outName, { type: outputType, lastModified: Date.now() });
    }

    async function appendCompressedImage(formData, fieldName, file, opts = {}) {
        if (!file) return;
        const compressed = await compressImageFile(file, opts);
        formData.append(fieldName, compressed);
    }

    function renderGalleryManager(scope, containerEl, opts) {
        if (!containerEl) return;
        const state = galleryState[scope];
        if (!state) return;
        const current = Array.isArray(state.current) ? state.current : [];
        const toDelete = Array.isArray(state.toDelete) ? state.toDelete : [];
        const previews = Array.isArray(opts && opts.previewFiles) ? opts.previewFiles : [];

        if (current.length === 0 && previews.length === 0) {
            containerEl.innerHTML = `<div class="gallery-manager-empty">Nenhuma imagem guardada. Use o campo acima para adicionar.</div>`;
            return;
        }

        const existingHtml = current.map((src, idx) => {
            const pending = toDelete.includes(src);
            const classes = ['gallery-thumb', 'thumb-saved'];
            if (pending) classes.push('thumb-pending-delete');
            const safeSrc = escapeAttr(buildThumbAssetUrl(src));
            const fallbackSrc = escapeAttr(toAssetUrl(src));
            const pathAttr = escapeAttr(src);
            const badge = pending ? 'Marcada p/ remover' : (idx === 0 ? 'Capa' : 'Guardada');
            const btn = pending
                ? `<button type="button" class="thumb-remove" title="Cancelar remoção" data-gallery-restore="${pathAttr}" data-gallery-scope="${scope}"><i class="ph ph-arrow-counter-clockwise"></i></button>`
                : `<button type="button" class="thumb-remove" title="Remover imagem" data-gallery-delete="${pathAttr}" data-gallery-scope="${scope}"><i class="ph ph-trash"></i></button>`;
            return `
                <div class="${classes.join(' ')}" draggable="true" data-gallery-path="${pathAttr}" data-gallery-scope="${scope}" title="Arraste para reordenar">
                    <img src="${safeSrc}" alt="Imagem da galeria" loading="lazy" draggable="false" onerror="this.onerror=null;this.src='${fallbackSrc}'">
                    <span class="thumb-order">${idx + 1}</span>
                    <span class="thumb-badge">${badge}</span>
                    ${btn}
                </div>
            `;
        }).join('');

        const previewHtml = previews.map((file, idx) => {
            let url = '';
            try { url = URL.createObjectURL(file); } catch (_) { url = ''; }
            const safeSrc = escapeAttr(url);
            return `
                <div class="gallery-thumb thumb-new">
                    <img src="${safeSrc}" alt="Nova imagem #${idx + 1}" loading="lazy">
                    <span class="thumb-badge">Nova</span>
                </div>
            `;
        }).join('');

        containerEl.innerHTML = existingHtml + previewHtml;

        containerEl.querySelectorAll('[data-gallery-delete]').forEach((btn) => {
            btn.addEventListener('click', (ev) => {
                ev.preventDefault();
                const path = btn.getAttribute('data-gallery-delete');
                const scp = btn.getAttribute('data-gallery-scope');
                if (!path || !scp || !galleryState[scp]) return;
                if (!galleryState[scp].toDelete.includes(path)) galleryState[scp].toDelete.push(path);
                if (scp === 'hero') {
                    const idx = activeHeroImages.indexOf(path);
                    if (idx !== -1) activeHeroImages.splice(idx, 1);
                    galleryState.hero.current = activeHeroImages.slice();
                }
                renderGalleryManager(scp, containerEl, opts);
            });
        });
        containerEl.querySelectorAll('[data-gallery-restore]').forEach((btn) => {
            btn.addEventListener('click', (ev) => {
                ev.preventDefault();
                const path = btn.getAttribute('data-gallery-restore');
                const scp = btn.getAttribute('data-gallery-scope');
                if (!path || !scp || !galleryState[scp]) return;
                galleryState[scp].toDelete = galleryState[scp].toDelete.filter((p) => p !== path);
                if (scp === 'hero' && !activeHeroImages.includes(path)) {
                    activeHeroImages.push(path);
                    galleryState.hero.current = activeHeroImages.slice();
                }
                renderGalleryManager(scp, containerEl, opts);
            });
        });

        attachDragAndDrop(scope, containerEl, opts);
    }

    function clearDropIndicators(containerEl) {
        containerEl.querySelectorAll('.gallery-thumb.drop-before, .gallery-thumb.drop-after')
            .forEach((el) => el.classList.remove('drop-before', 'drop-after'));
    }

    function attachDragAndDrop(scope, containerEl, opts) {
        const state = galleryState[scope];
        if (!state) return;
        const thumbs = containerEl.querySelectorAll('.gallery-thumb.thumb-saved[draggable="true"]');
        if (thumbs.length < 2) return;
        containerEl.classList.add('reordering');

        let draggedPath = null;

        thumbs.forEach((thumb) => {
            thumb.addEventListener('dragstart', (ev) => {
                draggedPath = thumb.getAttribute('data-gallery-path');
                thumb.classList.add('dragging');
                if (ev.dataTransfer) {
                    ev.dataTransfer.effectAllowed = 'move';
                    try { ev.dataTransfer.setData('text/plain', draggedPath || ''); } catch (_) { /* noop */ }
                }
            });

            thumb.addEventListener('dragend', () => {
                thumb.classList.remove('dragging');
                clearDropIndicators(containerEl);
                draggedPath = null;
            });

            thumb.addEventListener('dragover', (ev) => {
                if (!draggedPath) return;
                const targetPath = thumb.getAttribute('data-gallery-path');
                if (!targetPath || targetPath === draggedPath) return;
                ev.preventDefault();
                if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'move';
                const rect = thumb.getBoundingClientRect();
                const isBefore = (ev.clientX - rect.left) < (rect.width / 2);
                clearDropIndicators(containerEl);
                thumb.classList.add(isBefore ? 'drop-before' : 'drop-after');
            });

            thumb.addEventListener('dragleave', () => {
                thumb.classList.remove('drop-before', 'drop-after');
            });

            thumb.addEventListener('drop', (ev) => {
                if (!draggedPath) return;
                const targetPath = thumb.getAttribute('data-gallery-path');
                if (!targetPath || targetPath === draggedPath) return;
                ev.preventDefault();

                const rect = thumb.getBoundingClientRect();
                const dropBefore = (ev.clientX - rect.left) < (rect.width / 2);

                const list = galleryState[scope].current.slice();
                const fromIdx = list.indexOf(draggedPath);
                if (fromIdx === -1) return;
                list.splice(fromIdx, 1);
                let toIdx = list.indexOf(targetPath);
                if (toIdx === -1) toIdx = list.length;
                if (!dropBefore) toIdx += 1;
                list.splice(toIdx, 0, draggedPath);
                galleryState[scope].current = list;
                if (scope === 'hero') {
                    activeHeroImages = list.slice();
                }

                clearDropIndicators(containerEl);
                draggedPath = null;
                renderGalleryManager(scope, containerEl, opts);
            });
        });
    }

    function renderVideoLinksManager(urls = []) {
        const listEl = document.getElementById('customVideosList');
        if (!listEl) return;
        const safeUrls = Array.isArray(urls) ? urls : [];
        listEl.innerHTML = safeUrls.map((url, idx) => `
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <input type="url" class="form-control custom-video-url" data-video-index="${idx}" value="${escapeAttr(url)}" placeholder="https://www.youtube.com/watch?v=...">
                <button type="button" class="btn btn-danger btn-sm remove-video-btn" data-video-index="${idx}"><i class="ph ph-trash"></i></button>
            </div>
        `).join('');

        listEl.querySelectorAll('.remove-video-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const index = parseInt(btn.getAttribute('data-video-index') || '-1', 10);
                if (index < 0) return;
                const current = getVideoLinksFromManager();
                current.splice(index, 1);
                renderVideoLinksManager(current);
            });
        });
    }

    function getVideoLinksFromManager() {
        const inputs = Array.from(document.querySelectorAll('#customVideosList .custom-video-url'));
        return inputs
            .map((el) => String(el.value || '').trim())
            .filter((url) => /^https?:\/\//i.test(url));
    }

    function wireGalleryFileInput(scope, inputEl, containerEl, nameEl) {
        if (!inputEl || !containerEl) return;
        inputEl.addEventListener('change', () => {
            const files = Array.from(inputEl.files || []);
            if (nameEl) {
                nameEl.textContent = files.length
                    ? (files.length === 1 ? files[0].name : files.length + ' foto(s) selecionada(s)')
                    : 'Nenhum arquivo selecionado';
            }
            renderGalleryManager(scope, containerEl, { previewFiles: files });
        });
    }

    function renderTestimonialImagePreview(slot, imagePath) {
        const previewEl = document.getElementById(`currentTesti${slot}Preview`);
        const hiddenEl = document.getElementById(`removeTesti${slot}Img`);
        if (!previewEl) return;
        const img = String(imagePath || '').trim();
        if (!img || testimonialRemovalState[slot]) {
            previewEl.innerHTML = '';
            if (hiddenEl) hiddenEl.value = testimonialRemovalState[slot] ? '1' : '0';
            return;
        }
        previewEl.innerHTML = `
            <img src="${buildThumbAssetUrl(img)}" alt="Depoimento ${slot}" loading="lazy" style="max-height: 80px; max-width: 100%; border-radius: 50%;">
            <button type="button" class="btn btn-sm btn-outline-danger mt-2" data-remove-testi-img="${slot}" style="margin-top:0.5rem;">
                <i class="ph ph-trash"></i> Excluir foto
            </button>
        `;
        const btn = previewEl.querySelector(`[data-remove-testi-img="${slot}"]`);
        if (btn) {
            btn.addEventListener('click', () => {
                testimonialRemovalState[slot] = true;
                if (hiddenEl) hiddenEl.value = '1';
                previewEl.innerHTML = '';
            });
        }
        if (hiddenEl) hiddenEl.value = '0';
    }

    function bindTestimonialImageInput(slot) {
        const input = document.getElementById(`customTesti${slot}Image`);
        const nameEl = document.getElementById(`testi${slot}ImgName`);
        const hiddenEl = document.getElementById(`removeTesti${slot}Img`);
        if (!input || input.dataset.removeBound === '1') return;
        input.addEventListener('change', () => {
            if (nameEl) nameEl.textContent = input.files && input.files[0] ? input.files[0].name : '';
            testimonialRemovalState[slot] = false;
            if (hiddenEl) hiddenEl.value = '0';
        });
        input.dataset.removeBound = '1';
    }

    function renderLogoSitePreview(imagePath) {
        const previewEl = document.getElementById('currentLogoSitePreview');
        const hiddenEl = document.getElementById('removeLogoImg');
        if (!previewEl) return;
        const img = String(imagePath || '').trim();
        if (!img || (hiddenEl && hiddenEl.value === '1')) {
            previewEl.innerHTML = '';
            return;
        }
        previewEl.innerHTML = `
            <img src="${buildThumbAssetUrl(img)}" alt="Logo do site" loading="lazy" style="max-height: 70px; max-width: 100%; border-radius: 4px;">
            <button type="button" class="btn btn-sm btn-outline-danger mt-2" id="removeLogoSiteBtn" style="margin-top:0.5rem;">
                <i class="ph ph-trash"></i> Excluir logo
            </button>
        `;
        const removeBtn = document.getElementById('removeLogoSiteBtn');
        if (removeBtn) {
            removeBtn.addEventListener('click', () => {
                if (hiddenEl) hiddenEl.value = '1';
                previewEl.innerHTML = '';
            });
        }
    }

    function bindLogoSiteInput() {
        const input = document.getElementById('customLogoImage');
        const hiddenEl = document.getElementById('removeLogoImg');
        if (!input || input.dataset.logoBound === '1') return;
        input.addEventListener('change', () => {
            if (hiddenEl) hiddenEl.value = '0';
        });
        input.dataset.logoBound = '1';
    }

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
            const ok = await ensureInternalApiKey();
            if (!ok) {
                throw new Error('Chave interna indisponível para a requisição.');
            }
            const requestKey = getStoredInternalApiKey();
            const [resChalets, resReservations, resBookingOptions] = await Promise.all([
                fetch('../api/chalets.php').then(res => res.json()),
                fetch('../api/reservations.php', {
                    headers: { 'X-Internal-Key': requestKey }
                }).then(res => res.json()),
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
        return true;
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
        dashboard: (() => {
            // ===== Centro de Comando Operacional =====
            const today = new Date(); today.setHours(0, 0, 0, 0);
            const tomorrow = new Date(today); tomorrow.setDate(tomorrow.getDate() + 1);
            const toDateOnly = (s) => {
                if (!s) return null;
                const d = new Date(String(s).slice(0, 10) + 'T00:00:00');
                return isNaN(d.getTime()) ? null : d;
            };
            const sameDay = (a, b) => a && b && a.getTime() === b.getTime();
            const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
            const monthEnd = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            const fmtBR = (n) => 'R$ ' + Number(n || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
            const escH = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            const reservationIndex = (r) => reservationsData.indexOf(r);

            const activeStatuses = new Set(['Confirmada', 'Aguardando Pagamento', 'Pendente']);

            // Reservas com pelo menos uma noite dentro do mês atual e não canceladas.
            const activeMonth = reservationsData.filter(r => {
                if (!activeStatuses.has(String(r.status))) return false;
                const ci = toDateOnly(r.checkin_date);
                const co = toDateOnly(r.checkout_date);
                if (!ci || !co) return false;
                return ci <= monthEnd && co >= monthStart;
            });

            const checkinsToday = reservationsData.filter(r => {
                if (String(r.status) === 'Cancelada') return false;
                const ci = toDateOnly(r.checkin_date);
                return sameDay(ci, today);
            });

            // Receita estimada do mês: soma total_amount das reservas cujo check-in cai no mês corrente
            // (exclui canceladas). Métrica simples e previsível para o operador.
            const monthRevenue = reservationsData.reduce((acc, r) => {
                if (String(r.status) === 'Cancelada') return acc;
                const ci = toDateOnly(r.checkin_date);
                if (!ci || ci < monthStart || ci > monthEnd) return acc;
                return acc + (parseFloat(r.total_amount) || 0);
            }, 0);

            const nowTs = Date.now();
            // Considera-se "expirada" quando expires_at passou e o status ainda não é terminal.
            // Status terminais: Cancelada, Recusada, Confirmada.
            const terminalStatuses = new Set(['Cancelada', 'Recusada', 'Confirmada']);
            const parseExpiresAt = (s) => {
                if (!s) return null;
                const iso = String(s).replace(' ', 'T');
                const d = new Date(iso);
                return isNaN(d.getTime()) ? null : d;
            };
            const isExpired = (r) => {
                if (terminalStatuses.has(String(r.status))) return false;
                const exp = parseExpiresAt(r.expires_at);
                if (!exp) return false;
                return exp.getTime() < nowTs;
            };
            reservationsData.forEach(r => { r.__expired = isExpired(r); });

            const expiredReservations = reservationsData.filter(r => r.__expired);

            // "Ações necessárias" agrega: pendentes + aguardando pagamento + expiradas (sem duplicar).
            const pendingApprovalsRaw = reservationsData.filter(r =>
                ['Pendente', 'Aguardando Pagamento'].includes(String(r.status))
            );
            const pendingApprovalsMap = new Map();
            [...expiredReservations, ...pendingApprovalsRaw].forEach(r => {
                if (!pendingApprovalsMap.has(r.id)) pendingApprovalsMap.set(r.id, r);
            });
            const pendingApprovals = Array.from(pendingApprovalsMap.values());

            // Operação do dia: qualquer reserva (não cancelada) com check-in OU check-out hoje ou amanhã.
            const operationItems = [];
            reservationsData.forEach(r => {
                if (String(r.status) === 'Cancelada') return;
                const ci = toDateOnly(r.checkin_date);
                const co = toDateOnly(r.checkout_date);
                if (sameDay(ci, today)) operationItems.push({ r, kind: 'checkin', when: 'Hoje', date: ci });
                else if (sameDay(ci, tomorrow)) operationItems.push({ r, kind: 'checkin', when: 'Amanhã', date: ci });
                if (sameDay(co, today)) operationItems.push({ r, kind: 'checkout', when: 'Hoje', date: co });
                else if (sameDay(co, tomorrow)) operationItems.push({ r, kind: 'checkout', when: 'Amanhã', date: co });
            });
            operationItems.sort((a, b) => (a.date - b.date) || (a.kind === 'checkin' ? -1 : 1));

            // ===== Ocupação dos próximos 7 dias =====
            // Contabiliza reservas não-canceladas que cubram a data (checkin <= dia < checkout).
            const totalActiveChalets = chaletsData.filter(c => c.status === 'Ativo').length || 0;
            const weekdayLabel = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
            const occupancyDays = [];
            for (let i = 0; i < 7; i++) {
                const day = new Date(today); day.setDate(day.getDate() + i);
                const dayMs = day.getTime();
                let occupied = 0;
                reservationsData.forEach(r => {
                    if (String(r.status) === 'Cancelada') return;
                    const ci = toDateOnly(r.checkin_date);
                    const co = toDateOnly(r.checkout_date);
                    if (!ci || !co) return;
                    if (dayMs >= ci.getTime() && dayMs < co.getTime()) occupied += 1;
                });
                const pct = totalActiveChalets > 0
                    ? Math.min(100, Math.round((occupied / totalActiveChalets) * 100))
                    : 0;
                let label;
                if (i === 0) label = 'Hoje';
                else if (i === 1) label = 'Amanhã';
                else label = weekdayLabel[day.getDay()] + ' ' + String(day.getDate()).padStart(2, '0') + '/' + String(day.getMonth() + 1).padStart(2, '0');
                occupancyDays.push({ date: day, label, occupied, pct });
            }

            const occupancyBarColor = (pct) => {
                if (pct >= 90) return '#dc2626';   // lotado → urgência para liberar algo
                if (pct >= 60) return '#f59e0b';
                if (pct > 0) return '#16a34a';
                return '#94a3b8';
            };

            const occupancyRow = (d) => {
                const color = occupancyBarColor(d.pct);
                return `
                    <div class="occupancy-row">
                        <div class="occupancy-label">${escH(d.label)}</div>
                        <div class="occupancy-bar-wrap">
                            <div class="occupancy-bar" style="width:${d.pct}%;background:${color};"></div>
                        </div>
                        <div class="occupancy-meta">${d.occupied}/${totalActiveChalets} <small style="color:#64748b;">(${d.pct}%)</small></div>
                    </div>
                `;
            };

            const pendingRow = (r) => {
                const idx = reservationIndex(r);
                const policy = getPaymentPolicy(r.payment_rule || 'full');
                const total = parseFloat(r.total_amount) || 0;
                const deposit = (total * (policy.percent_now || 0)) / 100;
                const expired = !!r.__expired;

                // Status badge — prioridade visual para expiradas.
                let statusBadge;
                if (expired) {
                    statusBadge = `<span class="badge" style="background:#7f1d1d;color:#fff;font-weight:700;"><i class="ph ph-hourglass-low"></i> Reserva Expirada!</span>`;
                } else if (r.status === 'Pendente') {
                    statusBadge = `<span class="badge warning" style="background:#fef3c7;color:#92400e;">Pendente (WhatsApp)</span>`;
                } else {
                    statusBadge = `<span class="badge warning" style="background:#fde68a;color:#78350f;">Aguardando MP</span>`;
                }

                const rowClass = expired ? 'dash-row-expired' : 'dash-row-alert';
                const iconColor = expired ? '#7f1d1d' : '#dc2626';
                const iconName = expired ? 'ph-hourglass-low' : 'ph-warning-circle';

                const expiredInfo = expired && r.expires_at
                    ? `<br><small style="color:#991b1b;font-weight:600;">Expirou: ${formatDateBR(String(r.expires_at).slice(0,10))}</small>`
                    : '';

                const cancelBtn = expired
                    ? `<button type="button" class="btn btn-sm" data-action="cancel-expired" data-id="${r.id}" style="padding:.35rem .7rem;background:#7f1d1d;color:#fff;border:0;margin-left:.4rem;">
                           <i class="ph ph-x-circle"></i> Cancelar e Libertar
                       </button>`
                    : '';

                return `
                    <tr class="${rowClass}">
                        <td><i class="ph ${iconName}" style="color:${iconColor};font-size:1.2rem;vertical-align:middle;"></i></td>
                        <td><strong>${escH(r.guest_name)}</strong><br><small style="color:#666">#RES-${String(r.id).padStart(3, '0')}</small>${expiredInfo}</td>
                        <td>${escH(r.chalet_name || '')}</td>
                        <td>${formatDateBR(r.checkin_date)} → ${formatDateBR(r.checkout_date)}</td>
                        <td><strong>${fmtBR(deposit)}</strong><br><small style="color:#666">${escH(policy.label || ('Sinal ' + (policy.percent_now || 0) + '%'))}</small></td>
                        <td>${statusBadge}</td>
                        <td style="white-space:nowrap;">
                            <button type="button" class="btn btn-sm" data-action="edit-reservation" data-index="${idx}" style="padding:.35rem .7rem;">
                                <i class="ph ph-eye"></i> Revisar
                            </button>
                            ${cancelBtn}
                        </td>
                    </tr>
                `;
            };

            const operationRow = (item) => {
                const r = item.r;
                const idx = reservationIndex(r);
                const isCheckin = item.kind === 'checkin';
                const policy = getPaymentPolicy(r.payment_rule || 'full');
                const total = parseFloat(r.total_amount) || 0;
                const isPartial = (policy.percent_now || 100) < 100;
                const balancePending = isPartial && Number(r.balance_paid || 0) === 0;
                const pendingAmount = balancePending ? (total * (100 - policy.percent_now)) / 100 : 0;
                const highlightCheckin = isCheckin && balancePending;
                const rowStyle = highlightCheckin ? ' style="background:rgba(255, 235, 130, 0.45);"' : '';

                const kindBadge = isCheckin
                    ? `<span class="badge success" style="background:#dcfce7;color:#166534;"><i class="ph ph-sign-in"></i> Check-in</span>`
                    : `<span class="badge" style="background:#e0e7ff;color:#3730a3;"><i class="ph ph-sign-out"></i> Check-out</span>`;

                const balanceTag = balancePending
                    ? `<span class="badge danger" style="display:inline-block;margin-top:.25rem;background:#dc2626;color:#fff;font-weight:600;"><i class="ph ph-warning"></i> Cobrar Saldo: ${fmtBR(pendingAmount)}</span>`
                    : (isPartial ? `<span class="badge success" style="display:inline-block;margin-top:.25rem;">Saldo recebido</span>` : '');

                const payBtn = (isCheckin && balancePending)
                    ? `<button type="button" class="btn-icon" title="Receber Saldo" data-action="pay-balance" data-index="${idx}" style="color:#16a34a;"><i class="ph ph-currency-circle-dollar"></i></button>`
                    : '';

                return `
                    <tr${rowStyle}>
                        <td>${kindBadge}<br><small style="color:#666">${escH(item.when)}</small></td>
                        <td><strong>${escH(r.guest_name)}</strong><br><small style="color:#666">#RES-${String(r.id).padStart(3, '0')}</small></td>
                        <td>${escH(r.chalet_name || '')}</td>
                        <td>${formatDateBR(item.date.toISOString().slice(0, 10))}</td>
                        <td>${fmtBR(total)}${balanceTag ? '<br>' + balanceTag : ''}</td>
                        <td style="white-space:nowrap;">
                            ${payBtn}
                            <button type="button" class="btn-icon" title="Enviar Pré-Check-in (WhatsApp)" data-action="send-precheckin" data-id="${r.id}" style="color:#0ea5e9"><i class="ph ph-paper-plane-tilt"></i></button>
                            ${isCheckin ? `<button type="button" class="btn-icon" title="Fazer Check-in" data-action="start-checkin" data-id="${r.id}" style="color:#16a34a"><i class="ph ph-key"></i></button>` : ''}
                            <button type="button" class="btn-icon" title="Abrir Reserva" data-action="edit-reservation" data-index="${idx}"><i class="ph ph-pencil-simple"></i></button>
                        </td>
                    </tr>
                `;
            };

            return `
            <div class="page-header">
                <h1 class="page-title">Dashboard</h1>
                <button class="btn"><i class="ph ph-download-simple"></i> Relatório</button>
            </div>

            <div class="grid-cards">
                <div class="card stat-card">
                    <div class="stat-icon primary"><i class="ph ph-calendar-check"></i></div>
                    <div class="stat-info">
                        <h3>Reservas Ativas do Mês</h3>
                        <p>${activeMonth.length}</p>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon info"><i class="ph ph-sign-in"></i></div>
                    <div class="stat-info">
                        <h3>Check-ins Hoje</h3>
                        <p>${checkinsToday.length}</p>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon success"><i class="ph ph-money"></i></div>
                    <div class="stat-info">
                        <h3>Receita Estimada do Mês</h3>
                        <p>${fmtBR(monthRevenue)}</p>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon warning"><i class="ph ph-house-line"></i></div>
                    <div class="stat-info">
                        <h3>Hospedagens Ativas</h3>
                        <p>${chaletsData.filter(c => c.status === 'Ativo').length}</p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
                    <h3 style="margin:0;"><i class="ph ph-bell-ringing" style="color:#dc2626;"></i> Ações Necessárias</h3>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                        <span class="badge ${pendingApprovals.length > 0 ? 'danger' : 'success'}" style="${pendingApprovals.length > 0 ? 'background:#fee2e2;color:#991b1b;' : 'background:#dcfce7;color:#166534;'}">
                            ${pendingApprovals.length} pendente${pendingApprovals.length === 1 ? '' : 's'}
                        </span>
                        ${expiredReservations.length > 0 ? `
                            <span class="badge" style="background:#7f1d1d;color:#fff;font-weight:700;">
                                <i class="ph ph-hourglass-low"></i> ${expiredReservations.length} expirada${expiredReservations.length === 1 ? '' : 's'}
                            </span>
                        ` : ''}
                    </div>
                </div>

                ${pendingApprovals.length === 0 ? `
                    <div class="dash-empty-ok">
                        <i class="ph ph-check-circle"></i>
                        <div>
                            <strong>Tudo em dia!</strong>
                            <p style="margin:.15rem 0 0;color:#4b5563;font-size:.9rem;">Nenhuma aprovação pendente no momento.</p>
                        </div>
                    </div>
                ` : `
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;"></th>
                                    <th>Hóspede</th>
                                    <th>Hospedagem</th>
                                    <th>Datas</th>
                                    <th>Valor do Sinal</th>
                                    <th>Origem</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${pendingApprovals.slice(0, 10).map(pendingRow).join('')}
                            </tbody>
                        </table>
                    </div>
                    ${pendingApprovals.length > 10 ? `<p style="margin-top:.75rem;text-align:center;color:var(--text-muted);font-size:.85rem;">Mostrando 10 de ${pendingApprovals.length}. Veja todas em <a href="#" data-jump="reservations" style="color:var(--primary);">Reservas</a>.</p>` : ''}
                `}
            </div>

            <div class="card" style="margin-top:1.5rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
                    <h3 style="margin:0;"><i class="ph ph-calendar-dots"></i> Operação do Dia (Hoje e Amanhã)</h3>
                    <span class="badge" style="background:#e0e7ff;color:#3730a3;">${operationItems.length} movimento${operationItems.length === 1 ? '' : 's'}</span>
                </div>

                ${operationItems.length === 0 ? `
                    <div class="dash-empty-neutral">
                        <i class="ph ph-moon"></i>
                        <div>
                            <strong>Sem movimentos hoje ou amanhã.</strong>
                            <p style="margin:.15rem 0 0;color:#4b5563;font-size:.9rem;">Aproveite para preparar os próximos check-ins.</p>
                        </div>
                    </div>
                ` : `
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Tipo / Quando</th>
                                    <th>Hóspede</th>
                                    <th>Hospedagem</th>
                                    <th>Data</th>
                                    <th>Valor / Saldo</th>
                                    <th style="width:110px;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${operationItems.map(operationRow).join('')}
                            </tbody>
                        </table>
                    </div>
                `}
            </div>

            <div class="card" style="margin-top:1.5rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
                    <h3 style="margin:0;"><i class="ph ph-chart-bar"></i> Ocupação dos Próximos 7 Dias</h3>
                    <small style="color:var(--text-muted);">Base: ${totalActiveChalets} hospedage${totalActiveChalets === 1 ? 'm' : 'ns'} ativa${totalActiveChalets === 1 ? '' : 's'}</small>
                </div>
                ${totalActiveChalets === 0 ? `
                    <div class="dash-empty-neutral">
                        <i class="ph ph-house-line"></i>
                        <div>
                            <strong>Sem hospedagens ativas.</strong>
                            <p style="margin:.15rem 0 0;color:#4b5563;font-size:.9rem;">Ative ao menos uma hospedagem para ver a ocupação.</p>
                        </div>
                    </div>
                ` : `
                    <div class="occupancy-widget">
                        ${occupancyDays.map(occupancyRow).join('')}
                    </div>
                    <div class="occupancy-legend">
                        <span><i class="ph ph-square-fill" style="color:#16a34a;"></i> Até 59%</span>
                        <span><i class="ph ph-square-fill" style="color:#f59e0b;"></i> 60–89%</span>
                        <span><i class="ph ph-square-fill" style="color:#dc2626;"></i> 90% ou mais</span>
                    </div>
                `}
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
            `;
        })(),
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
                            ${reservationsData.map((r, index) => {
                                const __policy = getPaymentPolicy(r.payment_rule || 'full');
                                const __totalNum = parseFloat(r.total_amount) || 0;
                                const __isPartial = __policy.percent_now < 100;
                                const __balancePending = __isPartial && Number(r.balance_paid || 0) === 0;
                                const __pendingAmount = __balancePending ? (__totalNum * (100 - __policy.percent_now)) / 100 : 0;
                                const __today = new Date(); __today.setHours(0, 0, 0, 0);
                                const __ci = r.checkin_date ? new Date(r.checkin_date + 'T00:00:00') : null;
                                const __isCheckinDue = __balancePending && __ci && !isNaN(__ci.getTime()) && __today.getTime() >= __ci.getTime();
                                const __rowStyle = __isCheckinDue ? ' style="background:rgba(255, 235, 130, 0.45);"' : '';
                                const __fmtBR = (n) => 'R$ ' + Number(n).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
                                return `
                                <tr${__rowStyle}>
                                    <td>
                                        <button type="button" class="btn-icon" title="Editar" data-action="edit-reservation" data-index="${index}"><i class="ph ph-pencil-simple"></i></button>
                                        ${r.contract_filename
                                            ? `<button type="button" class="btn-icon" title="Ver Contrato PDF" data-action="pdf-reservation" data-index="${index}"><i class="ph ph-file-pdf"></i></button>`
                                            : `<button type="button" class="btn-icon" title="Gerar Contrato Manualmente" data-action="generate-contract" data-index="${index}" style="color:var(--primary-color, #2563eb)"><i class="ph ph-file-plus"></i></button>`
                                        }
                                        ${__balancePending
                                            ? `<button type="button" class="btn-icon" title="Receber Saldo" data-action="pay-balance" data-index="${index}" style="color:#198754"><i class="ph ph-currency-circle-dollar"></i></button>`
                                            : ''
                                        }
                                        <button type="button" class="btn-icon" title="Notificar (Reenviar)" data-action="notify-reservation" data-index="${index}" style="color: #25D366"><i class="ph ph-whatsapp-logo"></i></button>
                                        <button type="button" class="btn-icon" title="Enviar Pré-Check-in (WhatsApp)" data-action="send-precheckin" data-id="${r.id}" style="color:#0ea5e9"><i class="ph ph-paper-plane-tilt"></i></button>
                                        <button type="button" class="btn-icon" title="Fazer Check-in" data-action="start-checkin" data-id="${r.id}" style="color:#16a34a"><i class="ph ph-key"></i></button>
                                        <button type="button" class="btn-icon" title="Excluir" data-action="delete-reservation" data-id="${r.id}" style="color: var(--danger)"><i class="ph ph-trash"></i></button>
                                    </td>
                                    <td><strong>#RES-${String(r.id).padStart(3, '0')}</strong></td>
                                    <td>${r.guest_name}<br><small style="color:#666">${r.guest_email || ''}</small><br><small style="color:#888">${(r.guests_adults || 0) + (r.guests_children || 0)} hóspede(s)</small></td>
                                    <td>${r.chalet_name}</td>
                                    <td>${formatDateBR(r.checkin_date)} até ${formatDateBR(r.checkout_date)}</td>
                                    <td>
                                        ${__fmtBR(__totalNum)}
                                        ${__isPartial ? `<br><span class="badge warning" style="font-size:0.7rem; padding:0.15rem 0.3rem; margin-top:0.25rem; display:inline-block">${__policy.label}</span>` : ''}
                                        ${__balancePending
                                            ? `<br><span class="badge danger" style="font-size:0.72rem; padding:0.2rem 0.45rem; margin-top:0.3rem; display:inline-block; background:#dc3545; color:#fff; border-radius:0.25rem; font-weight:600;">Falta Pagar: ${__fmtBR(__pendingAmount)}</span>${__isCheckinDue ? `<br><span style="display:inline-block; margin-top:0.3rem; padding:0.2rem 0.45rem; background:#f59e0b; color:#fff; border-radius:0.25rem; font-size:0.7rem; font-weight:700; letter-spacing:0.02em;"><i class="ph ph-warning-circle"></i> Cobrar Saldo!</span>` : ''}`
                                            : ''}
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
                            `;
                            }).join('')}
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
                                <th>Imagem</th>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Preço Base (Noite)</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${chaletsData.map((c, index) => {
                                const thumbCandidate = c.main_image_thumb ? toAssetUrl(c.main_image_thumb) : '';
                                const fullCandidate = c.main_image ? toAssetUrl(c.main_image) : '';
                                const imgSrc = thumbCandidate || (c.main_image ? buildThumbAssetUrl(c.main_image) : '');
                                const fallbackSrcAttr = String(fullCandidate || '').replace(/'/g, "\\'");
                                const coverHtml = imgSrc
                                    ? `<img src="${imgSrc}" alt="Capa ${escapeAttr(c.name || '')}" loading="lazy" onerror="this.onerror=null; this.src='${fallbackSrcAttr}'" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px; border: 1px solid #e5e7eb; background: #f3f4f6;">`
                                    : `<div style="width: 50px; height: 50px; border-radius: 6px; border: 1px solid #e5e7eb; background: #f3f4f6; display: flex; align-items: center; justify-content: center; color: #9ca3af;"><i class="ph ph-image"></i></div>`;
                                return `
                                <tr>
                                    <td>${c.id}</td>
                                    <td>${coverHtml}</td>
                                    <td><strong>${c.name}</strong></td>
                                    <td>${c.type}</td>
                                    <td>R$ ${parseFloat(c.price).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</td>
                                    <td><span class="badge ${c.status === 'Ativo' ? 'success' : 'warning'}">${c.status}</span></td>
                                    <td>
                                        <button class="btn-icon" onclick="openChaletModal(${index})" title="Editar"><i class="ph ph-pencil-simple"></i></button>
                                        <button class="btn-icon" style="color: var(--danger)" title="Excluir" onclick="deleteChalet(${c.id}, '${(c.name || '').replace(/'/g, "\\'")}')"><i class="ph ph-trash"></i></button>
                                    </td>
                                </tr>
                            `;
                            }).join('')}
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
                            <input type="text" class="form-control" placeholder="Nome do estabelecimento">
                        </div>
                        <div class="form-group">
                            <label>E-mail de Contato</label>
                            <input type="email" class="form-control" placeholder="contato@suapousada.com">
                        </div>
                        <div class="form-group">
                            <label>Telefone Principal</label>
                            <input type="text" class="form-control" placeholder="(00) 00000-0000">
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
                            <input type="text" class="form-control" id="seoSiteTitle" placeholder="Hotel/Pousada">
                        </div>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label>Descrição do Site (Meta Description)</label>
                            <textarea class="form-control" id="seoMetaDescription" rows="3" placeholder="O seu refúgio com vista para o mar em Governador Celso Ramos."></textarea>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Cor Primária</label>
                                <input type="color" class="form-control" id="seoPrimaryColor" value="#2563eb" style="height: 44px; padding: 0.35rem;">
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
                        Comunicação e Integrações
                    </h3>
                    <p style="margin-bottom: 1.5rem; color: #666; font-size: 0.9rem;">Configure a integração nativa da Evolution API e escolha em quais eventos o PMS deve disparar mensagens automáticas.</p>
                    <form id="evolutionForm">
                        <div style="display:grid; grid-template-columns: 1.5fr 1fr 1fr; gap: 0.75rem;">
                            <div class="form-group">
                                <label>URL Base da Evolution API</label>
                                <input type="url" class="form-control" id="evoUrl" placeholder="https://api.seudominio.com">
                            </div>
                            <div class="form-group">
                                <label>Instância</label>
                                <input type="text" class="form-control" id="evoInstance" placeholder="nome-da-instancia">
                            </div>
                            <div class="form-group">
                                <label>API Key</label>
                                <input type="password" class="form-control" id="evoApikey" placeholder="apikey">
                            </div>
                        </div>

                        <div style="margin-top: 1rem; display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                            <label style="display:flex; align-items:center; gap:.5rem; border:1px solid var(--border-color); border-radius:8px; padding:.7rem .8rem;">
                                <input type="checkbox" id="evoNotifyReserva">
                                Notificar nova reserva
                            </label>
                            <label style="display:flex; align-items:center; gap:.5rem; border:1px solid var(--border-color); border-radius:8px; padding:.7rem .8rem;">
                                <input type="checkbox" id="evoNotifyCheckin">
                                Notificar check-in
                            </label>
                            <label style="display:flex; align-items:center; gap:.5rem; border:1px solid var(--border-color); border-radius:8px; padding:.7rem .8rem;">
                                <input type="checkbox" id="evoNotifyCheckout">
                                Notificar check-out
                            </label>
                        </div>

                        <div style="margin-top: 1.5rem; text-align: right;">
                            <button type="button" class="btn btn-primary" id="saveEvolutionBtn">
                                <i class="ph ph-floppy-disk"></i> Salvar Comunicação e Integrações
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card" style="grid-column: 1 / -1; margin-top: 1.5rem;">
                    <h3 style="margin-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                        <i class="ph ph-wallet" style="color: var(--primary-color, #2563eb); margin-right: 0.5rem; vertical-align: bottom;"></i>
                        Métodos de Pagamento
                    </h3>
                    <p style="margin: 0 0 1.25rem 0; color: #666; font-size: 0.9rem;">Ative um ou ambos os métodos. Quando os dois estiverem ativos, o hóspede escolhe no modal de reserva.</p>

                    <!-- Toggle: Mercado Pago -->
                    <div class="payment-method-card" style="border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1rem;">
                        <label style="display:flex; align-items:center; gap:0.75rem; cursor:pointer; font-weight:600; margin-bottom:0.75rem;">
                            <input type="checkbox" id="paymentMpActive" style="width:18px; height:18px; accent-color: var(--primary-color, #2563eb);">
                            <i class="ph ph-credit-card" style="color: #009EE3; font-size:1.25rem;"></i>
                            <span>Mercado Pago <small style="color:#666; font-weight:400;">— checkout automático (cartão, PIX, boleto)</small></span>
                        </label>
                        <div style="margin-bottom: 0.75rem; padding: 0.75rem 1rem; border: 1px solid #d9eefb; background: #f4fbff; border-radius: 6px; font-size:0.85rem; color:#23536b;">
                            <strong>Como configurar:</strong> acesse <a href="https://www.mercadopago.com.br/developers/panel/app" target="_blank" style="color:#007bb5;">mercadopago.com.br/developers/panel/app</a>, copie o <strong>Access Token de Produção</strong> (<code>APP_USR-...</code>) e em <strong>Webhooks</strong> cadastre o evento <strong>Pagamentos</strong> usando a URL abaixo.
                        </div>
                        <div class="form-group">
                            <label>URL do Webhook (copie e cole no painel do Mercado Pago)</label>
                            <div style="display:flex; gap:0.5rem;">
                                <input type="text" class="form-control" id="mpWebhookUrl" value="${window.location.origin}/api/mp_webhook.php" readonly>
                                <button type="button" class="btn" style="white-space:nowrap; background-color:#0b7bb5;" onclick="navigator.clipboard.writeText(document.getElementById('mpWebhookUrl').value).then(() => alert('URL do webhook copiada!'))">
                                    <i class="ph ph-copy"></i> Copiar
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Access Token (Produção)</label>
                            <input type="password" class="form-control" id="mpAccessToken" placeholder="APP_USR-...">
                        </div>
                    </div>

                    <!-- Toggle: PIX Manual -->
                    <div class="payment-method-card" style="border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1rem;">
                        <label style="display:flex; align-items:center; gap:0.75rem; cursor:pointer; font-weight:600; margin-bottom:0.75rem;">
                            <input type="checkbox" id="paymentManualActive" style="width:18px; height:18px; accent-color: var(--primary-color, #2563eb);">
                            <i class="ph ph-whatsapp-logo" style="color: #25D366; font-size:1.25rem;"></i>
                            <span>PIX Manual via WhatsApp <small style="color:#666; font-weight:400;">— comprovante validado pelo administrador</small></span>
                        </label>
                        <div style="margin-bottom: 0.75rem; padding: 0.75rem 1rem; border: 1px solid #d6f0de; background: #f4fbf6; border-radius: 6px; font-size:0.85rem; color:#1d5c34;">
                            Ao selecionar este método, o hóspede é redirecionado ao WhatsApp com a chave PIX e as instruções abaixo. A reserva entra como <strong>Pendente</strong> até que você confirme o pagamento manualmente.
                        </div>
                        <div class="form-group">
                            <label>Chave PIX Principal</label>
                            <input type="text" class="form-control" id="manualPixKey" placeholder="CPF, e-mail, telefone ou chave aleatória">
                        </div>
                        <div class="form-group">
                            <label>Instruções enviadas ao hóspede (WhatsApp)</label>
                            <textarea class="form-control" id="manualPixInstructions" rows="3" placeholder="Ex.: Olá! Acabei de pré-reservar pelo site. Segue o comprovante do PIX."></textarea>
                            <small style="display:block; margin-top:0.35rem; color:#777;">Este texto é usado como mensagem inicial do WhatsApp. Dados da reserva (nome, datas, valor) são acrescentados automaticamente.</small>
                        </div>
                    </div>

                    <div style="margin-top: 1.5rem; text-align: right;">
                        <button type="button" class="btn btn-primary" id="savePaymentMethodsBtn">
                            <i class="ph ph-floppy-disk"></i> Salvar Métodos de Pagamento
                        </button>
                    </div>
                </div>

                <!-- Integração FNRH (Gov.br) -->
                <div class="card" style="grid-column: 1 / -1; margin-top: 1.5rem;">
                    <h3 style="margin-bottom: 0.75rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                        <i class="ph ph-identification-card" style="color: var(--primary); margin-right: 0.5rem; vertical-align: bottom;"></i>
                        Integração FNRH (Gov.br) e Pré-Check-in
                    </h3>
                    <p style="margin-bottom: 1rem; color: #666; font-size: 0.9rem;">
                        Configure o envio automático de hóspedes para a Ficha Nacional de Registro de Hóspedes (FNRH Digital)
                        e a mensagem padrão do pré-check-in enviada via WhatsApp.
                    </p>
                    <div style="background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.88rem; display: flex; gap: 0.5rem; align-items: flex-start;">
                        <i class="ph ph-warning-circle" style="margin-top: 2px;"></i>
                        <span><strong>Atenção:</strong> ative apenas se a pousada possui registo no <strong>CADASTUR</strong> e Chave de API válida do <strong>Serpro</strong>. Caso contrário, mantenha desligado — o check-in continuará a funcionar normalmente no sistema local.</span>
                    </div>

                    <div class="form-group" style="background: #f9fafb; padding: 0.9rem 1rem; border-radius: 10px; margin-bottom: 1rem; display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                        <div>
                            <strong>Enviar reservas para a FNRH Digital</strong>
                            <div style="color:#666; font-size:0.82rem;">Quando ativo, o botão "Efetivar Check-in" dispara o envio ao governo.</div>
                        </div>
                        <label class="switch" style="display:inline-flex; align-items:center; gap:.5rem;">
                            <input type="checkbox" id="fnrhActive">
                            <span>Ativo</span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label>Chave API (Serpro / FNRH)</label>
                        <input type="password" class="form-control" id="fnrhApiKey" placeholder="Cole aqui a chave fornecida pelo Serpro" autocomplete="off">
                        <small style="display:block; margin-top:0.35rem; color:#777;">
                            Armazenamos a chave de forma segura no banco. Ela só é usada pelo servidor, nunca vai para o navegador do hóspede.
                        </small>
                    </div>

                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label>Mensagem padrão do Pré-Check-in (WhatsApp)</label>
                        <textarea class="form-control" id="preCheckinMessage" rows="5" placeholder="Ex.: Olá, {nome}! Sua reserva está confirmada..."></textarea>
                        <small style="display:block; margin-top:0.35rem; color:#777;">
                            Tokens disponíveis: <code>{nome}</code>, <code>{pousada}</code>, <code>{checkin}</code>, <code>{checkout}</code>, <code>{link}</code>.
                        </small>
                    </div>

                    <div style="text-align: right;">
                        <button type="button" class="btn btn-primary" id="saveFnrhSettingsBtn">
                            <i class="ph ph-floppy-disk"></i> Salvar Configurações FNRH
                        </button>
                    </div>
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
            <div id="save-progress-container" class="mt-3" style="display: none; margin-bottom: 1rem;">
                <div class="d-flex justify-content-between mb-1" style="display:flex; justify-content:space-between; margin-bottom:0.35rem;">
                    <span id="save-progress-text" class="small text-muted" style="font-size:0.85rem; color:#6b7280;">Enviando dados...</span>
                    <span id="save-progress-percent" class="small fw-bold" style="font-size:0.85rem; font-weight:700;">0%</span>
                </div>
                <div class="progress" style="height: 8px; background:#e5e7eb; border-radius:999px; overflow:hidden;">
                    <div id="save-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%; height:100%; background: var(--primary); transition: width 0.2s ease;"></div>
                </div>
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

                <!-- Logo do Site -->
                <div class="accordion-item">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-image-square" style="color: var(--primary);"></i> Logo do Site</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                            <p style="margin-bottom: 1rem; color: #666; font-size:0.9rem;">A logo enviada aqui substitui o ícone genérico no cabeçalho do site público.</p>
                            <div style="text-align: center; border: 2px dashed var(--border-color); padding: 1.5rem; border-radius: 8px; background: var(--bg-light);">
                                <h4 style="margin-bottom: 1rem; color: var(--text-dark);">Logo da Navbar</h4>
                                <input type="file" id="customLogoImage" accept="image/*" style="display:none;" onchange="document.getElementById('logoImgName').textContent = this.files[0]?.name || 'Nenhum arquivo selecionado';">
                                <label for="customLogoImage" class="btn btn-outline" style="cursor: pointer; margin-bottom: 1rem;"><i class="ph ph-upload"></i> Escolher Logo</label>
                                <div id="logoImgName" style="color: #666; font-size: 0.85rem; margin-top: 0.5rem;">Nenhum arquivo selecionado</div>
                                <div id="currentLogoSitePreview" style="margin-top: 1rem;"></div>
                                <input type="hidden" id="removeLogoImg" value="0">
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
                            <input type="file" id="heroImagensInput" accept="image/*" multiple style="display:none;">
                            <label for="heroImagensInput" class="btn btn-outline" style="cursor: pointer; margin-bottom: 1rem;"><i class="ph ph-upload"></i> Escolher Imagens</label>
                            <div id="heroImgName" style="color: #666; font-size: 0.85rem; margin-top: 0.5rem;">Nenhum arquivo selecionado</div>
                            <div id="currentHeroPreview" class="gallery-manager" style="margin-top: 1rem;"></div>
                            <small style="display:block; margin-top:0.5rem; color:#666;">Passe o rato sobre uma miniatura e use o ícone para remover. As remoções só são aplicadas ao clicar em "Salvar Alterações".</small>
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
                                <input type="hidden" id="removeTesti1Img" value="0">
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
                                <input type="hidden" id="removeTesti2Img" value="0">
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
                                <input type="hidden" id="removeTesti3Img" value="0">
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
                            <input type="text" class="form-control" id="customLocAddress" placeholder="Rua, número, bairro, cidade/UF">
                        </div>
                        <div class="form-group">
                            <label>Instruções (De Carro)</label>
                            <input type="text" class="form-control" id="customLocCar" placeholder="Apenas 2h30 da capital. Estrada 100% asfaltada até a entrada.">
                        </div>
                        <div class="form-group">
                            <label>Link Direto para o Google Maps (Botão)</label>
                            <input type="url" class="form-control" id="customLocMapLink" placeholder="https://www.google.com/maps/...">
                        </div>
                        <div class="form-group">
                            <label>Código Embed do Google Maps (iframe)</label>
                            <textarea class="form-control" id="customLocMapEmbed" rows="4" placeholder="<iframe src=&quot;...&quot; width=&quot;100%&quot; height=&quot;400&quot; style=&quot;border:0;&quot; allowfullscreen loading=&quot;lazy&quot;></iframe>"></textarea>
                        </div>
                    </form>
                        </div>
                    </div>
                </div>

                <!-- Vídeos -->
                <div class="accordion-item">
                    <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
                        <h3><i class="ph ph-video-camera" style="color: var(--primary);"></i> Seção de Vídeos</h3>
                        <i class="ph ph-caret-down accordion-icon"></i>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-body-inner">
                            <form id="videosForm">
                                <div class="form-group" style="display:flex; align-items:center; gap:0.5rem;">
                                    <input type="checkbox" id="customVideosEnabled">
                                    <label for="customVideosEnabled" style="margin:0;">Ativar seção de vídeos no site</label>
                                </div>
                                <div id="customVideosList" style="display:flex; flex-direction:column; gap:0.75rem; margin-top:0.75rem;"></div>
                                <button type="button" class="btn btn-outline btn-sm" id="addVideoBtn"><i class="ph ph-plus"></i> Adicionar</button>
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
                            <input type="text" class="form-control" id="customFooterCopyright" placeholder="&copy; 2026 Nome da Empresa. Todos os direitos reservados.">
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
        faqs: `
            <div class="page-header">
                <h1 class="page-title"><i class="ph ph-question"></i> Perguntas Frequentes</h1>
                <button type="button" class="btn btn-primary" id="faqNewBtn"><i class="ph ph-plus"></i> Nova Pergunta</button>
            </div>
            <div id="faqKeyWarn" class="card" style="display:none; border:1px solid var(--danger); color:var(--danger); margin-bottom:1rem;">
                Não foi possível carregar a chave interna. Abra <strong>Configurações</strong> neste painel e volte aqui.
            </div>
            <div class="card" id="faqFormCard" style="display:none; margin-bottom: 1.5rem;">
                <h3 style="margin-bottom: 1rem;" id="faqFormTitle">
                    <i class="ph ph-plus-circle" style="color: var(--primary); vertical-align: bottom;"></i>
                    Nova Pergunta
                </h3>
                <form id="faqForm">
                    <input type="hidden" id="faqEditId" value="">
                    <div class="form-group">
                        <label for="faqQuestion">Pergunta</label>
                        <input type="text" id="faqQuestion" class="form-control" maxlength="500" placeholder="Ex.: Qual o horário de check-in?" required>
                    </div>
                    <div class="form-group">
                        <label for="faqAnswer">Resposta</label>
                        <textarea id="faqAnswer" class="form-control" rows="4" placeholder="Escreva a resposta exibida no site..." required></textarea>
                    </div>
                    <div style="display:flex; gap:1rem; align-items:center; flex-wrap:wrap; margin-bottom: 1rem;">
                        <label style="display:flex; align-items:center; gap:.5rem; margin:0;">
                            <input type="checkbox" id="faqIsActive" checked>
                            Publicar no site (ativo)
                        </label>
                    </div>
                    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary" id="faqSaveBtn"><i class="ph ph-floppy-disk"></i> Salvar</button>
                        <button type="button" class="btn btn-outline" id="faqCancelBtn">Cancelar</button>
                    </div>
                </form>
            </div>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1rem; flex-wrap:wrap; gap:.5rem;">
                    <h3 style="margin:0;">Lista de FAQs</h3>
                    <small style="color:var(--text-muted);">Use as setas <i class="ph ph-arrow-up"></i> / <i class="ph ph-arrow-down"></i> para reordenar. A ordem é respeitada no site público.</small>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:90px;">Ordem</th>
                                <th>Pergunta</th>
                                <th style="width:90px;">Ativo</th>
                                <th style="width:200px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="faqsTableBody">
                            <tr><td colspan="4" style="text-align:center;">Carregando...</td></tr>
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
                const savePaymentMethodsBtnEl = document.getElementById('savePaymentMethodsBtn');
                if (savePaymentMethodsBtnEl) savePaymentMethodsBtnEl.addEventListener('click', savePaymentMethodsSettings);
                const saveFnrhBtnEl = document.getElementById('saveFnrhSettingsBtn');
                if (saveFnrhBtnEl) saveFnrhBtnEl.addEventListener('click', saveFnrhSettings);
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
                const addVideoBtn = document.getElementById('addVideoBtn');
                if (addVideoBtn) {
                    addVideoBtn.addEventListener('click', () => {
                        const current = getVideoLinksFromManager();
                        current.push('');
                        renderVideoLinksManager(current);
                    });
                }
            }
            if (viewName === 'dashboard') {
                renderDashboardCalendar();
                // Habilita botões "Revisar" / "Receber Saldo" dos widgets do Dashboard.
                bindReservationButtons();
                // Link "Veja todas" dos widgets → leva para a view de Reservas.
                appContainer.querySelectorAll('[data-jump="reservations"]').forEach(link => {
                    link.addEventListener('click', (ev) => {
                        ev.preventDefault();
                        renderView('reservations');
                        const sidebarLink = document.querySelector('[data-view="reservations"]');
                        if (sidebarLink) {
                            document.querySelectorAll('.nav-item.active').forEach(n => n.classList.remove('active'));
                            sidebarLink.classList.add('active');
                        }
                    });
                });
                // "Cancelar e Libertar Calendário" para reservas expiradas.
                appContainer.querySelectorAll('[data-action="cancel-expired"]').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const id = btn.getAttribute('data-id');
                        if (!id) return;
                        if (!confirm('Cancelar esta reserva expirada e libertar o calendário? Esta ação não pode ser desfeita.')) return;
                        btn.disabled = true;
                        btn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> A cancelar...';
                        try {
                            const res = await fetch(`../api/reservations.php?id=${id}`, {
                                method: 'PUT',
                                headers: { 'Content-Type': 'application/json', 'X-Internal-Key': window.internalKey || internalApiKey },
                                body: JSON.stringify({ status: 'Cancelada' })
                            });
                            if (!res.ok) {
                                const err = await res.json().catch(() => ({}));
                                throw new Error(err.error || err.message || ('HTTP ' + res.status));
                            }
                            await fetchApiData();
                            renderView('dashboard');
                        } catch (e) {
                            alert('Falha ao cancelar reserva: ' + (e && e.message ? e.message : e));
                            btn.disabled = false;
                            btn.innerHTML = '<i class="ph ph-x-circle"></i> Cancelar e Libertar';
                        }
                    });
                });
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
            if (viewName === 'faqs') {
                void initFaqsView();
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

    /* =========================================
       FAQs (Perguntas Frequentes)
       ========================================= */
    async function initFaqsView() {
        const warn = document.getElementById('faqKeyWarn');

        const ok = getStoredInternalApiKey() !== '' || await ensureInternalApiKey();
        if (warn) warn.style.display = ok ? 'none' : 'block';
        if (!ok) return;
        const faqInternalKey = getStoredInternalApiKey();

        const formCard = document.getElementById('faqFormCard');
        const formTitle = document.getElementById('faqFormTitle');
        const form = document.getElementById('faqForm');
        const idEl = document.getElementById('faqEditId');
        const questionEl = document.getElementById('faqQuestion');
        const answerEl = document.getElementById('faqAnswer');
        const activeEl = document.getElementById('faqIsActive');
        const saveBtn = document.getElementById('faqSaveBtn');
        const cancelBtn = document.getElementById('faqCancelBtn');
        const newBtn = document.getElementById('faqNewBtn');
        const tbody = document.getElementById('faqsTableBody');

        let faqsCache = [];

        const escAttr = (s) => String(s == null ? '' : s).replace(/"/g, '&quot;').replace(/</g, '&lt;');
        const escText = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        function resetForm() {
            idEl.value = '';
            questionEl.value = '';
            answerEl.value = '';
            activeEl.checked = true;
            if (formTitle) formTitle.innerHTML = '<i class="ph ph-plus-circle" style="color: var(--primary); vertical-align: bottom;"></i> Nova Pergunta';
            if (saveBtn) saveBtn.innerHTML = '<i class="ph ph-floppy-disk"></i> Salvar';
            if (formCard) formCard.style.display = 'none';
        }

        function openForm(faq) {
            if (formCard) formCard.style.display = 'block';
            if (!faq) {
                resetForm();
                if (formCard) formCard.style.display = 'block';
                if (formTitle) formTitle.innerHTML = '<i class="ph ph-plus-circle" style="color: var(--primary); vertical-align: bottom;"></i> Nova Pergunta';
                questionEl.focus();
                return;
            }
            idEl.value = String(faq.id || '');
            questionEl.value = String(faq.question || '');
            answerEl.value = String(faq.answer || '');
            activeEl.checked = Number(faq.is_active) === 1;
            if (formTitle) formTitle.innerHTML = '<i class="ph ph-pencil-simple" style="color: var(--primary); vertical-align: bottom;"></i> Editar Pergunta #' + faq.id;
            questionEl.focus();
            if (formCard) formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        async function loadFaqs() {
            if (!tbody) return;
            try {
                const res = await fetch('../api/faqs.php', { headers: { 'X-Internal-Key': faqInternalKey } });
                const rows = await res.json();
                if (!Array.isArray(rows)) throw new Error('Resposta inválida');
                faqsCache = rows;
                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#666;">Nenhuma FAQ cadastrada. Clique em "Nova Pergunta" para começar.</td></tr>';
                    return;
                }
                tbody.innerHTML = rows.map((f, i) => {
                    const isFirst = i === 0;
                    const isLast = i === rows.length - 1;
                    return `
                        <tr data-faq-id="${f.id}">
                            <td>
                                <div style="display:flex; gap:.25rem; align-items:center;">
                                    <button type="button" class="btn-icon" data-faq-up="${f.id}" title="Subir" ${isFirst ? 'disabled style="opacity:.3;cursor:not-allowed;"' : ''}><i class="ph ph-arrow-up"></i></button>
                                    <button type="button" class="btn-icon" data-faq-down="${f.id}" title="Descer" ${isLast ? 'disabled style="opacity:.3;cursor:not-allowed;"' : ''}><i class="ph ph-arrow-down"></i></button>
                                    <small style="color:#888; margin-left:.25rem;">${i + 1}</small>
                                </div>
                            </td>
                            <td>
                                <strong>${escText(f.question)}</strong>
                                <div style="color:#666; font-size:.85rem; margin-top:.25rem; max-width:60ch; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">${escText(f.answer)}</div>
                            </td>
                            <td>
                                <label style="display:inline-flex; align-items:center; gap:.35rem; cursor:pointer;">
                                    <input type="checkbox" data-faq-toggle="${f.id}" ${Number(f.is_active) === 1 ? 'checked' : ''}>
                                    <small>${Number(f.is_active) === 1 ? 'Visível' : 'Oculto'}</small>
                                </label>
                            </td>
                            <td>
                                <button type="button" class="btn-icon" data-faq-edit="${f.id}" title="Editar"><i class="ph ph-pencil-simple"></i></button>
                                <button type="button" class="btn-icon" data-faq-del="${f.id}" title="Excluir" style="color: var(--danger);"><i class="ph ph-trash"></i></button>
                            </td>
                        </tr>
                    `;
                }).join('');

                tbody.querySelectorAll('[data-faq-edit]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const id = parseInt(btn.getAttribute('data-faq-edit'), 10);
                        const target = faqsCache.find((x) => Number(x.id) === id);
                        if (target) openForm(target);
                    });
                });

                tbody.querySelectorAll('[data-faq-del]').forEach((btn) => {
                    btn.addEventListener('click', async () => {
                        const id = parseInt(btn.getAttribute('data-faq-del'), 10);
                        if (!confirm('Excluir esta pergunta? Esta ação não pode ser desfeita.')) return;
                        await fetch(`../api/faqs.php?id=${id}`, { method: 'DELETE', headers: { 'X-Internal-Key': internalApiKey } });
                        await loadFaqs();
                    });
                });

                tbody.querySelectorAll('[data-faq-toggle]').forEach((cb) => {
                    cb.addEventListener('change', async () => {
                        const id = parseInt(cb.getAttribute('data-faq-toggle'), 10);
                        await fetch(`../api/faqs.php?id=${id}`, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                            body: JSON.stringify({ is_active: cb.checked ? 1 : 0 })
                        });
                        await loadFaqs();
                    });
                });

                tbody.querySelectorAll('[data-faq-up]').forEach((btn) => {
                    btn.addEventListener('click', () => moveFaq(parseInt(btn.getAttribute('data-faq-up'), 10), -1));
                });
                tbody.querySelectorAll('[data-faq-down]').forEach((btn) => {
                    btn.addEventListener('click', () => moveFaq(parseInt(btn.getAttribute('data-faq-down'), 10), +1));
                });
            } catch (e) {
                console.error('Erro ao carregar FAQs:', e);
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:var(--danger);">Falha ao carregar FAQs.</td></tr>';
            }
        }

        async function moveFaq(id, direction) {
            const idx = faqsCache.findIndex((x) => Number(x.id) === id);
            if (idx === -1) return;
            const swapIdx = idx + direction;
            if (swapIdx < 0 || swapIdx >= faqsCache.length) return;

            const a = faqsCache[idx];
            const b = faqsCache[swapIdx];
            // Troca o sort_order entre os dois vizinhos; se empatarem, redistribui.
            let aNewOrder = Number(b.sort_order) || 0;
            let bNewOrder = Number(a.sort_order) || 0;
            if (aNewOrder === bNewOrder) {
                aNewOrder = (idx + direction) * 10;
                bNewOrder = idx * 10;
            }

            try {
                await Promise.all([
                    fetch(`../api/faqs.php?id=${a.id}`, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                        body: JSON.stringify({ sort_order: aNewOrder })
                    }),
                    fetch(`../api/faqs.php?id=${b.id}`, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                        body: JSON.stringify({ sort_order: bNewOrder })
                    })
                ]);
                await loadFaqs();
            } catch (e) {
                console.error('Erro ao reordenar:', e);
                alert('Não foi possível reordenar.');
            }
        }

        if (newBtn) newBtn.onclick = () => openForm(null);
        if (cancelBtn) cancelBtn.onclick = () => resetForm();

        if (form) {
            form.onsubmit = async (ev) => {
                ev.preventDefault();
                const question = questionEl.value.trim();
                const answer = answerEl.value.trim();
                const is_active = activeEl.checked ? 1 : 0;
                if (!question || !answer) {
                    alert('Pergunta e resposta são obrigatórias.');
                    return;
                }
                const isEditing = !!idEl.value;
                const url = isEditing ? `../api/faqs.php?id=${parseInt(idEl.value, 10)}` : '../api/faqs.php';
                const method = isEditing ? 'PUT' : 'POST';
                const payload = { question, answer, is_active };
                saveBtn.disabled = true;
                try {
                    const res = await fetch(url, {
                        method,
                        headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                        body: JSON.stringify(payload)
                    });
                    if (!res.ok) {
                        const err = await res.json().catch(() => ({}));
                        throw new Error(err.error || ('HTTP ' + res.status));
                    }
                    resetForm();
                    await loadFaqs();
                } catch (e) {
                    alert('Erro ao salvar: ' + (e && e.message ? e.message : e));
                } finally {
                    saveBtn.disabled = false;
                }
            };
        }

        await loadFaqs();
    }

    // Função Exposta para atualizar status da reserva via SELECT
    window.updateReservationStatus = async function (id, newStatus) {
        try {
            const response = await fetch(`../api/reservations.php?id=${id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-Internal-Key': window.internalKey || internalApiKey },
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
        const evoApikey = (document.getElementById('evoApikey')?.value || '').trim();
        const settings = {
            evo_url: (document.getElementById('evoUrl')?.value || '').trim(),
            evo_instance: (document.getElementById('evoInstance')?.value || '').trim(),
            evo_notify_reserva: document.getElementById('evoNotifyReserva')?.checked ? '1' : '0',
            evo_notify_checkin: document.getElementById('evoNotifyCheckin')?.checked ? '1' : '0',
            evo_notify_checkout: document.getElementById('evoNotifyCheckout')?.checked ? '1' : '0'
        };
        if (evoApikey !== '') settings.evo_apikey = evoApikey;
        await saveSettingsToAPI(settings);
        alert('Configuração de Comunicação e Integrações salva com sucesso!');
    }

    async function savePaymentMethodsSettings() {
        const mpActiveEl = document.getElementById('paymentMpActive');
        const manualActiveEl = document.getElementById('paymentManualActive');
        const mpTokenEl = document.getElementById('mpAccessToken');
        const pixKeyEl = document.getElementById('manualPixKey');
        const pixInstrEl = document.getElementById('manualPixInstructions');

        const mpActive = !!(mpActiveEl && mpActiveEl.checked);
        const manualActive = !!(manualActiveEl && manualActiveEl.checked);
        const mpToken = mpTokenEl ? mpTokenEl.value.trim() : '';
        const pixKey = pixKeyEl ? pixKeyEl.value.trim() : '';
        const pixInstr = pixInstrEl ? pixInstrEl.value.trim() : '';

        if (!mpActive && !manualActive) {
            alert('Ative pelo menos um método de pagamento. Caso contrário, o checkout público ficará inoperante.');
            return;
        }
        if (mpActive && mpToken === '') {
            alert('Para ativar o Mercado Pago, informe o Access Token de Produção (APP_USR-...).');
            return;
        }
        if (manualActive && pixKey === '') {
            alert('Para ativar o PIX Manual, informe a Chave PIX Principal.');
            return;
        }

        const settings = {
            payment_mercadopago_active: mpActive ? '1' : '0',
            payment_manual_pix_active: manualActive ? '1' : '0',
            manual_pix_key: pixKey,
            manual_pix_instructions: pixInstr
        };
        // Só reescreve o token se o admin digitou algo novo (evita apagar accidentalmente).
        if (mpToken !== '') {
            settings.mercadoPagoSettings = { accessToken: mpToken };
        }

        await saveSettingsToAPI(settings);
        alert('Métodos de pagamento salvos com sucesso!');
    }

    /* =========================================
       FNRH (Check-in 360º) — configurações
       ========================================= */
    async function saveFnrhSettings() {
        const activeEl = document.getElementById('fnrhActive');
        const apiKeyEl = document.getElementById('fnrhApiKey');
        const msgEl = document.getElementById('preCheckinMessage');

        const active = !!(activeEl && activeEl.checked);
        const apiKey = apiKeyEl ? apiKeyEl.value.trim() : '';
        const msg = msgEl ? msgEl.value.trim() : '';

        if (active && apiKey === '') {
            const cont = confirm('Você ativou a integração FNRH mas não informou a Chave API. Deseja continuar assim mesmo?');
            if (!cont) return;
        }

        const payload = {
            fnrh_active: active ? '1' : '0',
            pre_checkin_message: msg
        };
        // Só sobrescreve a chave se o admin digitou algo (evita apagar acidentalmente).
        if (apiKey !== '') payload.fnrh_api_key = apiKey;

        try {
            await saveSettingsToAPI(payload);
            if (apiKeyEl) apiKeyEl.value = '';
            alert('Configurações FNRH salvas com sucesso!');
        } catch (e) {
            alert('Não foi possível salvar as configurações FNRH.');
        }
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
        const primary = (primaryColor && /^#[0-9a-fA-F]{6}$/.test(primaryColor)) ? primaryColor : '#2563eb';
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
            primary_color: primaryEl ? primaryEl.value : '#2563eb',
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
                credentials: 'same-origin',
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
            set('customLocMapEmbed', custom.locMapEmbed);
            set('removeLogoImg', '0');
            bindLogoSiteInput();
            renderLogoSitePreview(custom.logoImg || '');
            const videosEnabledEl = document.getElementById('customVideosEnabled');
            if (videosEnabledEl) videosEnabledEl.checked = Number(custom.videosEnabled || 0) === 1;
            renderVideoLinksManager(Array.isArray(custom.videosJson)
                ? custom.videosJson.map((v) => String((v && v.url) ? v.url : v || '').trim())
                    .filter(Boolean)
                : []);
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
            activeHeroImages = Array.isArray(heroImgs) ? heroImgs.filter(Boolean) : [];
            galleryState.hero.current = activeHeroImages.slice();
            galleryState.hero.toDelete = [];
            if (heroPreview) {
                renderGalleryManager('hero', heroPreview, { previewFiles: [] });
                const heroInput = document.getElementById('heroImagensInput');
                const heroNameEl = document.getElementById('heroImgName');
                if (heroInput && !heroInput.dataset.galleryBound) {
                    wireGalleryFileInput('hero', heroInput, heroPreview, heroNameEl);
                    heroInput.dataset.galleryBound = '1';
                }
            }

            const aboutPreview = document.getElementById('currentAboutPreview');
            if (aboutPreview && custom.aboutImage) aboutPreview.innerHTML = `<img src="${buildThumbAssetUrl(custom.aboutImage)}" alt="About" loading="lazy" style="max-height: 100px; max-width: 100%; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`;

            const faviconPreview = document.getElementById('currentFaviconPreview');
            if (faviconPreview && custom.favicon) faviconPreview.innerHTML = `<img src="${buildThumbAssetUrl(custom.favicon)}" alt="Favicon" loading="lazy" style="width: 32px; height: 32px; object-fit: contain; border-radius: 4px;">`;

            [1, 2, 3].forEach((i) => {
                testimonialRemovalState[i] = false;
                bindTestimonialImageInput(i);
                renderTestimonialImagePreview(i, custom[`testi${i}Image`]);
            });
        } catch (e) {
            console.warn('Erro ao carregar personalização:', e);
        }
    }

    async function loadAllSettings() {
        try {
            const res = await fetch('../api/settings.php', { credentials: 'same-origin' });
            const data = await res.json();

            // Popula Comunicação e Integrações (Evolution API nativa).
            const asBoolFlag = (v) => {
                const s = String(v == null ? '' : v).trim().toLowerCase();
                return s === '1' || s === 'true' || s === 'on' || s === 'yes';
            };
            if (document.getElementById('evoUrl')) document.getElementById('evoUrl').value = data.evo_url || '';
            if (document.getElementById('evoInstance')) document.getElementById('evoInstance').value = data.evo_instance || '';
            if (document.getElementById('evoApikey')) {
                const hasEvoKey = typeof data.evo_apikey === 'string' && data.evo_apikey.trim() !== '';
                const el = document.getElementById('evoApikey');
                el.value = '';
                el.placeholder = hasEvoKey ? 'Chave armazenada — deixe em branco para manter' : 'apikey';
            }
            if (document.getElementById('evoNotifyReserva')) document.getElementById('evoNotifyReserva').checked = asBoolFlag(data.evo_notify_reserva);
            if (document.getElementById('evoNotifyCheckin')) document.getElementById('evoNotifyCheckin').checked = asBoolFlag(data.evo_notify_checkin);
            if (document.getElementById('evoNotifyCheckout')) document.getElementById('evoNotifyCheckout').checked = asBoolFlag(data.evo_notify_checkout);

            // Popula MercadoPago
            const mpAccessTokenEl = document.getElementById('mpAccessToken');
            if (mpAccessTokenEl) mpAccessTokenEl.value = (data.mercadoPagoSettings && data.mercadoPagoSettings.accessToken) || '';
            persistInternalApiKeyFromPayload(data);

            // Popula toggles e campos dos métodos de pagamento (híbrido MP + PIX manual).
            const mpActiveEl = document.getElementById('paymentMpActive');
            if (mpActiveEl) {
                mpActiveEl.checked = data.payment_mercadopago_active === undefined
                    ? true // default conservador: mantém MP ativo em instalações antigas
                    : asBoolFlag(data.payment_mercadopago_active);
            }
            const manualActiveEl = document.getElementById('paymentManualActive');
            if (manualActiveEl) manualActiveEl.checked = asBoolFlag(data.payment_manual_pix_active);
            const pixKeyEl = document.getElementById('manualPixKey');
            if (pixKeyEl) pixKeyEl.value = typeof data.manual_pix_key === 'string' ? data.manual_pix_key : '';
            const pixInstrEl = document.getElementById('manualPixInstructions');
            if (pixInstrEl) pixInstrEl.value = typeof data.manual_pix_instructions === 'string' ? data.manual_pix_instructions : '';

            // Popula Integração FNRH (Check-in 360º).
            const fnrhActiveEl = document.getElementById('fnrhActive');
            if (fnrhActiveEl) fnrhActiveEl.checked = asBoolFlag(data.fnrh_active);
            const preMsgEl = document.getElementById('preCheckinMessage');
            if (preMsgEl) {
                preMsgEl.value = typeof data.pre_checkin_message === 'string' && data.pre_checkin_message.trim() !== ''
                    ? data.pre_checkin_message
                    : "Olá, {nome}! Sua reserva na {pousada} está confirmada para {checkin} — {checkout}.\n\nPara agilizar sua chegada, preencha o pré-check-in online (FNRH) neste link seguro:\n{link}\n\nNos vemos em breve!";
            }
            // Placeholder indica se existe chave armazenada sem nunca expô-la.
            const fnrhKeyEl = document.getElementById('fnrhApiKey');
            if (fnrhKeyEl) {
                const hasKey = typeof data.fnrh_api_key === 'string' && data.fnrh_api_key.trim() !== '';
                fnrhKeyEl.value = '';
                fnrhKeyEl.placeholder = hasKey
                    ? 'Chave armazenada — deixe em branco para manter, ou digite nova'
                    : 'Cole aqui a chave fornecida pelo Serpro';
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
            if (seoSiteTitle) seoSiteTitle.value = data.site_title || 'Sistema de Hospedagem';
            const seoMetaDescription = document.getElementById('seoMetaDescription');
            if (seoMetaDescription) seoMetaDescription.value = data.meta_description || 'O seu refúgio com vista para o mar em Governador Celso Ramos.';
            const seoPrimaryColor = document.getElementById('seoPrimaryColor');
            if (seoPrimaryColor) seoPrimaryColor.value = data.primary_color || '#2563eb';
            const seoSecondaryColor = document.getElementById('seoSecondaryColor');
            if (seoSecondaryColor) seoSecondaryColor.value = data.secondary_color || '#1e293b';
            applyAdminTheme(data.primary_color || '#2563eb', data.secondary_color || '#1e293b');

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
                document.getElementById('currentLogoPreview').innerHTML = `<img src="${buildThumbAssetUrl(data.company_logo)}" alt="Company Logo" loading="lazy" style="max-height: 100px; max-width: 100%; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`;
            }
            if (data.company_logo_light) {
                document.getElementById('currentLogoLightPreview').innerHTML = `<img src="${buildThumbAssetUrl(data.company_logo_light)}" alt="Company Logo Light" loading="lazy" style="max-height: 100px; max-width: 100%; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`;
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
                const heroPreviewEl = document.getElementById('currentHeroPreview');
                activeHeroImages = Array.isArray(heroImgs) ? heroImgs.filter(Boolean) : [];
                galleryState.hero.current = activeHeroImages.slice();
                galleryState.hero.toDelete = [];
                if (heroPreviewEl) {
                    renderGalleryManager('hero', heroPreviewEl, { previewFiles: [] });
                    const heroInputEl = document.getElementById('heroImagensInput');
                    const heroNameEl = document.getElementById('heroImgName');
                    if (heroInputEl && !heroInputEl.dataset.galleryBound) {
                        wireGalleryFileInput('hero', heroInputEl, heroPreviewEl, heroNameEl);
                        heroInputEl.dataset.galleryBound = '1';
                    }
                }
                if (custom.aboutImage) {
                    document.getElementById('currentAboutPreview').innerHTML = `<img src="${buildThumbAssetUrl(custom.aboutImage)}" alt="About Image" loading="lazy" style="max-height: 100px; max-width: 100%; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`;
                }
                const faviconPreview = document.getElementById('currentFaviconPreview');
                if (faviconPreview) {
                    faviconPreview.innerHTML = custom.favicon 
                        ? `<img src="${buildThumbAssetUrl(custom.favicon)}" alt="Favicon" loading="lazy" style="width: 32px; height: 32px; object-fit: contain; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">`
                        : '';
                }
                const removeLogoEl = document.getElementById('removeLogoImg');
                if (removeLogoEl) removeLogoEl.value = '0';
                bindLogoSiteInput();
                renderLogoSitePreview(custom.logoImg || '');

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
                testimonialRemovalState[1] = false;
                bindTestimonialImageInput(1);
                renderTestimonialImagePreview(1, custom.testi1Image);

                document.getElementById('customTesti2Name').value = custom.testi2Name || '';
                document.getElementById('customTesti2Location').value = custom.testi2Location || '';
                document.getElementById('customTesti2Text').value = custom.testi2Text || '';
                testimonialRemovalState[2] = false;
                bindTestimonialImageInput(2);
                renderTestimonialImagePreview(2, custom.testi2Image);

                document.getElementById('customTesti3Name').value = custom.testi3Name || '';
                document.getElementById('customTesti3Location').value = custom.testi3Location || '';
                document.getElementById('customTesti3Text').value = custom.testi3Text || '';
                testimonialRemovalState[3] = false;
                bindTestimonialImageInput(3);
                renderTestimonialImagePreview(3, custom.testi3Image);

                // Location
                document.getElementById('customLocAddress').value = custom.locAddress || '';
                document.getElementById('customLocCar').value = custom.locCar || '';
                document.getElementById('customLocMapLink').value = custom.locMapLink || '';
                document.getElementById('customLocMapEmbed').value = custom.locMapEmbed || '';
                const videosEnabledEl = document.getElementById('customVideosEnabled');
                if (videosEnabledEl) videosEnabledEl.checked = Number(custom.videosEnabled || 0) === 1;
                renderVideoLinksManager(Array.isArray(custom.videosJson)
                    ? custom.videosJson.map((v) => String((v && v.url) ? v.url : v || '').trim()).filter(Boolean)
                    : []);

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
        if (file) await appendCompressedImage(formData, 'logo', file, { maxWidth: 1920, quality: 0.8, forceLossy: true });
        if (fileLight) await appendCompressedImage(formData, 'logo_light', fileLight, { maxWidth: 1920, quality: 0.8, forceLossy: true });

        // Required dummy field to trigger API processing since we bypass standard JSON parsing
        formData.append('dummy', 'true');

        try {
            document.getElementById('saveLogoBtn').disabled = true;
            document.getElementById('saveLogoBtn').textContent = 'Salvando...';

            const res = await fetch('../api/settings.php', {
                method: 'POST',
                credentials: 'same-origin',
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
        const fileHeroInput = document.getElementById('heroImagensInput');
        const fileAboutInput = document.getElementById('customAboutImage');
        const fileTesti1Input = document.getElementById('customTesti1Image');
        const fileTesti2Input = document.getElementById('customTesti2Image');
        const fileTesti3Input = document.getElementById('customTesti3Image');

        const formData = new FormData();

        if (fileHeroInput.files.length > 0) {
            for (let i = 0; i < fileHeroInput.files.length; i++) {
                await appendCompressedImage(formData, 'hero_images[]', fileHeroInput.files[i], { maxWidth: 1920, quality: 0.8, forceLossy: true });
            }
        }
        const heroToDelete = Array.isArray(galleryState.hero.toDelete) ? galleryState.hero.toDelete : [];
        heroToDelete.forEach((p) => formData.append('hero_images_to_delete[]', p));
        const heroCurrent = Array.isArray(activeHeroImages) ? activeHeroImages.slice() : [];
        formData.append('hero_existing_images', JSON.stringify(heroCurrent));
        if (heroCurrent.length > 0) {
            formData.append('hero_images_order', JSON.stringify(heroCurrent));
        }
        if (fileAboutInput.files[0]) await appendCompressedImage(formData, 'about_image', fileAboutInput.files[0], { maxWidth: 1920, quality: 0.8, forceLossy: true });
        const fileFaviconInput = document.getElementById('customFaviconImage');
        const fileLogoInput = document.getElementById('customLogoImage');
        if (fileFaviconInput && fileFaviconInput.files[0]) await appendCompressedImage(formData, 'favicon_image', fileFaviconInput.files[0], { maxWidth: 512, quality: 0.9, forceLossy: false });
        if (fileLogoInput && fileLogoInput.files[0]) await appendCompressedImage(formData, 'logoImg', fileLogoInput.files[0], { maxWidth: 1200, quality: 0.9, forceLossy: true });
        if (fileTesti1Input.files[0]) await appendCompressedImage(formData, 'testi1_image', fileTesti1Input.files[0], { maxWidth: 1200, quality: 0.82, forceLossy: true });
        if (fileTesti2Input.files[0]) await appendCompressedImage(formData, 'testi2_image', fileTesti2Input.files[0], { maxWidth: 1200, quality: 0.82, forceLossy: true });
        if (fileTesti3Input.files[0]) await appendCompressedImage(formData, 'testi3_image', fileTesti3Input.files[0], { maxWidth: 1200, quality: 0.82, forceLossy: true });
        formData.append('remove_logoImg', (document.getElementById('removeLogoImg')?.value === '1') ? '1' : '0');
        formData.append('remove_testi1Img', (document.getElementById('removeTesti1Img')?.value === '1') ? '1' : '0');
        formData.append('remove_testi2Img', (document.getElementById('removeTesti2Img')?.value === '1') ? '1' : '0');
        formData.append('remove_testi3Img', (document.getElementById('removeTesti3Img')?.value === '1') ? '1' : '0');

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
            locMapEmbed: document.getElementById('customLocMapEmbed').value,
            videosEnabled: !!(document.getElementById('customVideosEnabled') && document.getElementById('customVideosEnabled').checked),
            videosJson: getVideoLinksFromManager().map((url) => ({ url })),
            waNumber: document.getElementById('customWaNumber').value,
            waMessage: document.getElementById('customWaMessage').value,
            footerDesc: document.getElementById('customFooterDesc').value,
            footerAddress: document.getElementById('customFooterAddress').value,
            footerEmail: document.getElementById('customFooterEmail').value,
            footerPhone: document.getElementById('customFooterPhone').value,
            footerCopyright: document.getElementById('customFooterCopyright').value,
            logoImg: ''
        };

        formData.append('dummy', 'true');
        formData.append('customization', JSON.stringify(customizationSettings));

        const saveBtn = document.getElementById('saveCustomizationBtn');
        const progressContainer = document.getElementById('save-progress-container');
        const progressBar = document.getElementById('save-progress-bar');
        const progressPercent = document.getElementById('save-progress-percent');
        const progressText = document.getElementById('save-progress-text');

        const resetProgressUI = () => {
            if (progressContainer) progressContainer.style.display = 'none';
            if (progressBar) progressBar.style.width = '0%';
            if (progressPercent) progressPercent.textContent = '0%';
            if (progressText) progressText.textContent = 'Enviando dados...';
        };

        try {
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Salvando...';
            }
            if (progressContainer) progressContainer.style.display = 'block';

            const result = await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '../api/customization.php', true);

                xhr.upload.onprogress = function (e) {
                    if (!e.lengthComputable) return;
                    const percent = Math.round((e.loaded / e.total) * 100);
                    if (progressBar) progressBar.style.width = percent + '%';
                    if (progressPercent) progressPercent.textContent = percent + '%';
                    if (percent >= 100 && progressText) {
                        progressText.textContent = 'Processando no servidor...';
                    }
                };

                xhr.onload = function () {
                    let response = {};
                    try {
                        response = JSON.parse(xhr.responseText || '{}');
                    } catch (_) {
                        response = {};
                    }
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve(response);
                    } else {
                        reject(new Error(response.error || response.details || xhr.statusText || 'Erro desconhecido'));
                    }
                };

                xhr.onerror = function () {
                    reject(new Error('Falha de conexão durante o upload.'));
                };

                xhr.send(formData);
            });

            alert('Personalizações salvas no banco de dados com sucesso!');
            await loadCustomizationForm(); // Atualiza os campos com o que foi salvo
        } catch (e) {
            console.error("Erro no upload das imagens de personalização", e);
            alert("Erro de conexão: " + (e.message || "Verifique o console"));
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="ph ph-floppy-disk"></i> Salvar Alterações';
            }
            resetProgressUI();
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
            const res = await fetch(`../api/reservations.php?id=${id}`, {
                method: 'DELETE',
                headers: { 'X-Internal-Key': window.internalKey || internalApiKey }
            });
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
        appContainer.querySelectorAll('[data-action="send-precheckin"]').forEach(btn => {
            btn.onclick = () => {
                const id = btn.getAttribute('data-id');
                sendPreCheckinWhatsApp(parseInt(id, 10));
            };
        });
        appContainer.querySelectorAll('[data-action="start-checkin"]').forEach(btn => {
            btn.onclick = () => {
                const id = btn.getAttribute('data-id');
                openCheckinModal(parseInt(id, 10));
            };
        });
    }

    /* =========================================================
       Check-in 360º — Pré-Check-in (WhatsApp) e Modal Híbrido
       ========================================================= */

    async function ensureCheckinLinkData(reservationId) {
        const ok = await ensureInternalApiKey();
        if (!ok) {
            alert('Chave interna indisponível. Abra Configurações e recarregue.');
            return null;
        }
        try {
            const r = await fetch(`../api/checkin_link.php?id=${encodeURIComponent(reservationId)}`, {
                headers: { 'X-Internal-Key': internalApiKey }
            });
            const data = await r.json().catch(() => ({}));
            if (!r.ok) {
                alert('Falha ao gerar link de pré-check-in: ' + (data.error || ('HTTP ' + r.status)));
                return null;
            }
            return data; // { id, token, url, guest_name, checkin_date, checkout_date }
        } catch (e) {
            alert('Erro de rede ao gerar link de pré-check-in.');
            return null;
        }
    }

    function renderPreCheckinMessage(template, info, brand) {
        const fmt = (d) => {
            if (!d) return '';
            try { return new Date(d + 'T00:00:00').toLocaleDateString('pt-BR'); } catch (_) { return String(d); }
        };
        return String(template || '')
            .replace(/\{nome\}/g, info.guest_name || '')
            .replace(/\{pousada\}/g, brand || '')
            .replace(/\{checkin\}/g, fmt(info.checkin_date))
            .replace(/\{checkout\}/g, fmt(info.checkout_date))
            .replace(/\{link\}/g, info.url || '');
    }

    async function sendPreCheckinWhatsApp(reservationId) {
        if (!reservationId) return;
        const reservation = Array.isArray(reservationsData)
            ? reservationsData.find(r => Number(r.id) === Number(reservationId))
            : null;
        const rawPhone = reservation && reservation.guest_phone ? String(reservation.guest_phone) : '';
        const phone = rawPhone.replace(/\D/g, '');
        if (phone.length < 8) {
            alert('Esta reserva não tem um telefone válido cadastrado. Edite a reserva antes de enviar o pré-check-in.');
            return;
        }

        const info = await ensureCheckinLinkData(reservationId);
        if (!info) return;

        // Busca template e branding das settings já carregadas (ou fetch rápido).
        let template = '';
        let brand = 'Estabelecimento';
        try {
            const res = await fetch('../api/settings.php', { credentials: 'same-origin' });
            const data = await res.json();
            if (typeof data.pre_checkin_message === 'string' && data.pre_checkin_message.trim() !== '') {
                template = data.pre_checkin_message;
            }
            brand = (data.company_name && String(data.company_name).trim())
                || (data.site_title && String(data.site_title).trim())
                || brand;
        } catch (_) { /* usa fallback abaixo */ }
        if (!template) {
            template = "Olá, {nome}! Sua reserva na {pousada} está confirmada para {checkin} — {checkout}.\n\nPara agilizar sua chegada, preencha o pré-check-in online (FNRH) neste link seguro:\n{link}\n\nNos vemos em breve!";
        }

        const text = renderPreCheckinMessage(template, info, brand);
        // Formato internacional: WhatsApp aceita DDI 55 para Brasil sem +.
        const normalized = phone.startsWith('55') ? phone : ('55' + phone);
        const waUrl = `https://wa.me/${normalized}?text=${encodeURIComponent(text)}`;
        window.open(waUrl, '_blank', 'noopener');
    }

    async function openCheckinModal(reservationId) {
        if (!reservationId) return;
        const ok = await ensureInternalApiKey();
        if (!ok) {
            alert('Chave interna indisponível. Abra Configurações e recarregue.');
            return;
        }

        // Busca reserva fresca (com campos FNRH).
        let reservation = null;
        try {
            const r = await fetch(`../api/reservations.php?id=${encodeURIComponent(reservationId)}`, {
                headers: { 'X-Internal-Key': window.internalKey || internalApiKey }
            });
            reservation = await r.json();
            if (!r.ok || !reservation || reservation.error) {
                alert('Não foi possível carregar a reserva.');
                return;
            }
        } catch (e) {
            alert('Erro de rede ao carregar reserva.');
            return;
        }

        // Descobre se integração FNRH está ativa (para rótulo do botão).
        let fnrhActive = false;
        try {
            const sr = await fetch('../api/settings.php', { credentials: 'same-origin' });
            const sd = await sr.json();
            fnrhActive = String(sd.fnrh_active || '0') === '1';
        } catch (_) { /* assume false */ }

        buildCheckinModal(reservation, fnrhActive);
    }

    function buildCheckinModal(r, fnrhActive) {
        const host = document.getElementById('modalContainer') || document.body;
        // Fecha qualquer modal de check-in aberto.
        const old = document.getElementById('checkinModalOverlay');
        if (old) old.remove();

        const escH = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        const policy = getPaymentPolicy(r.payment_rule || 'full');
        const total = Number(r.total_amount || 0);
        const percentNow = Math.max(0, Math.min(100, Number(policy.percent_now || 100)));
        const depositAmount = total * (percentNow / 100);
        const balanceAmount = Math.max(0, total - depositAmount);
        const balancePaid = Number(r.balance_paid || 0) === 1;
        const isPartial = percentNow > 0 && percentNow < 100;
        const balancePending = isPartial && !balancePaid;

        const hasCpf = r.guest_cpf && String(r.guest_cpf).trim() !== '';
        const mode = hasCpf ? 'review' : 'manual';

        const fmtBR = (n) => 'R$ ' + Number(n).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
        const fnrhBtnLabel = fnrhActive ? 'Efetivar Check-in e Enviar FNRH' : 'Efetivar Check-in (local)';

        const html = `
            <div class="modal-overlay" id="checkinModalOverlay">
                <div class="modal-container" style="max-width:640px;">
                    <div class="modal-header">
                        <h3><i class="ph ph-key" style="color:var(--primary);"></i> Check-in — Reserva #RES-${String(r.id).padStart(3, '0')}</h3>
                        <button type="button" class="btn-icon" id="checkinModalClose"><i class="ph ph-x"></i></button>
                    </div>
                    <div class="modal-body">
                        <div style="background:#f9fafb; border:1px solid var(--border-color); border-radius:10px; padding:.9rem 1rem; margin-bottom:1rem;">
                            <div style="font-weight:600; font-size:1.05rem; color:var(--secondary);">${escH(r.guest_name)}</div>
                            <div style="color:#6b7280; font-size:.9rem; margin-top:.15rem;">
                                ${escH(r.chalet_name || '')} · ${escH(r.checkin_date)} → ${escH(r.checkout_date)}
                            </div>
                        </div>

                        <!-- TRAVA FINANCEIRA -->
                        ${balancePending ? `
                            <div id="checkinBalanceBox" style="background:#fef3c7; border:1px solid #fcd34d; border-radius:10px; padding:1rem 1.1rem; margin-bottom:1rem;">
                                <div style="display:flex; gap:.75rem; align-items:flex-start;">
                                    <i class="ph ph-warning-circle" style="color:#b45309; font-size:1.4rem;"></i>
                                    <div style="flex:1;">
                                        <div style="font-weight:700; color:#78350f; margin-bottom:.2rem;">Saldo pendente</div>
                                        <div style="color:#92400e; font-size:.92rem;">Total <strong>${fmtBR(total)}</strong> · Sinal pago <strong>${fmtBR(depositAmount)}</strong> · Falta pagar <strong style="color:#b91c1c; font-size:1.05rem;">${fmtBR(balanceAmount)}</strong></div>
                                        <label style="display:flex; gap:.5rem; align-items:center; margin-top:.75rem; font-weight:600; color:#78350f; cursor:pointer;">
                                            <input type="checkbox" id="checkinConfirmBalance">
                                            Confirmo o recebimento físico de ${fmtBR(balanceAmount)} (PIX, dinheiro ou cartão na recepção).
                                        </label>
                                    </div>
                                </div>
                            </div>
                        ` : `
                            <div style="background:#ecfdf5; border:1px solid #86efac; border-radius:10px; padding:.8rem 1rem; margin-bottom:1rem; display:flex; gap:.5rem; align-items:center; color:#065f46; font-weight:500;">
                                <i class="ph ph-check-circle"></i>
                                <span>${isPartial ? 'Saldo já está quitado — liberado para check-in.' : 'Reserva 100% paga — liberada para check-in.'}</span>
                            </div>
                        `}

                        <!-- DADOS FNRH -->
                        <h4 style="margin:0 0 .6rem; color:var(--secondary); font-size:1rem;">
                            <i class="ph ph-identification-card" style="color:var(--primary)"></i>
                            ${mode === 'review' ? 'Dados do hóspede (conferência)' : 'Dados do hóspede (preenchimento manual)'}
                        </h4>

                        <div class="form-group">
                            <label>Nome completo</label>
                            <input type="text" class="form-control" id="ckGuestName" value="${escH(r.guest_name)}" ${mode === 'review' ? 'readonly' : ''}>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:.75rem;">
                            <div class="form-group">
                                <label>CPF</label>
                                <input type="text" class="form-control" id="ckCpf" maxlength="14" placeholder="000.000.000-00" value="${escH(r.guest_cpf || '')}" ${mode === 'review' ? 'readonly' : ''}>
                            </div>
                            <div class="form-group">
                                <label>Telefone</label>
                                <input type="tel" class="form-control" id="ckPhone" placeholder="(11) 91234-5678" value="${escH(r.guest_phone || '')}" ${mode === 'review' ? 'readonly' : ''}>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Endereço</label>
                            <textarea class="form-control" id="ckAddress" rows="2" placeholder="Rua, número, bairro, cidade, UF, CEP" ${mode === 'review' ? 'readonly' : ''}>${escH(r.guest_address || '')}</textarea>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 2fr; gap:.75rem;">
                            <div class="form-group">
                                <label>Placa do veículo</label>
                                <input type="text" class="form-control" id="ckCarPlate" maxlength="10" style="text-transform:uppercase" value="${escH(r.guest_car_plate || '')}" ${mode === 'review' ? 'readonly' : ''}>
                            </div>
                            <div class="form-group">
                                <label>Acompanhantes</label>
                                <input type="text" class="form-control" id="ckCompanions" placeholder="Nomes dos acompanhantes" value="${escH(r.guest_companion_names || '')}" ${mode === 'review' ? 'readonly' : ''}>
                            </div>
                        </div>

                        <div id="checkinAlert" style="display:none; margin-top:.5rem;"></div>
                    </div>
                    <div class="modal-footer" style="display:flex; justify-content:space-between; gap:.75rem; flex-wrap:wrap;">
                        <button type="button" class="btn btn-outline" id="checkinModalCancel">Cancelar</button>
                        <button type="button" class="btn btn-primary" id="checkinModalSubmit" disabled>
                            <i class="ph ph-check-circle"></i> ${fnrhBtnLabel}
                        </button>
                    </div>
                </div>
            </div>
        `;

        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        host.appendChild(wrapper.firstElementChild);

        const overlay = document.getElementById('checkinModalOverlay');
        const closeModal = () => { if (overlay) overlay.remove(); };
        document.getElementById('checkinModalClose').onclick = closeModal;
        document.getElementById('checkinModalCancel').onclick = closeModal;
        overlay.addEventListener('click', (ev) => { if (ev.target === overlay) closeModal(); });

        // Máscara de CPF em modo manual.
        const cpfEl = document.getElementById('ckCpf');
        if (cpfEl && mode === 'manual') {
            cpfEl.addEventListener('input', function () {
                let v = (this.value || '').replace(/\D/g, '').slice(0, 11);
                if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
                else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
                else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
                this.value = v;
            });
        }

        // Trava financeira → habilita submit.
        const submitBtn = document.getElementById('checkinModalSubmit');
        const confirmChk = document.getElementById('checkinConfirmBalance');
        const updateSubmitState = () => {
            if (balancePending) {
                submitBtn.disabled = !(confirmChk && confirmChk.checked);
            } else {
                submitBtn.disabled = false;
            }
        };
        if (confirmChk) confirmChk.addEventListener('change', updateSubmitState);
        updateSubmitState();

        submitBtn.onclick = async () => {
            const alertEl = document.getElementById('checkinAlert');
            const showAlert = (type, msg) => {
                if (!alertEl) return;
                alertEl.style.display = 'block';
                const bg = type === 'err' ? '#fef2f2' : (type === 'ok' ? '#ecfdf5' : '#eff6ff');
                const fg = type === 'err' ? '#991b1b' : (type === 'ok' ? '#065f46' : '#1e40af');
                alertEl.innerHTML = `<div style="background:${bg}; color:${fg}; padding:.7rem .9rem; border-radius:8px; font-size:.9rem;">${msg}</div>`;
            };

            // Colher dados.
            const payload = {
                guest_phone: document.getElementById('ckPhone').value.trim(),
                guest_cpf: (document.getElementById('ckCpf').value || '').replace(/\D/g, ''),
                guest_address: document.getElementById('ckAddress').value.trim(),
                guest_car_plate: document.getElementById('ckCarPlate').value.trim().toUpperCase(),
                guest_companion_names: document.getElementById('ckCompanions').value.trim()
            };

            // Validação mínima.
            if (payload.guest_cpf.length < 11) { showAlert('err', 'Informe um CPF válido (11 dígitos).'); return; }
            if (payload.guest_address.length < 8) { showAlert('err', 'Informe o endereço completo do hóspede.'); return; }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> A processar…';

            try {
                // 1) Se houver saldo pendente, regista o recebimento.
                if (balancePending) {
                    const pb = await fetch('../api/pay_balance.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                        body: JSON.stringify({ reservation_id: r.id })
                    });
                    const pbd = await pb.json().catch(() => ({}));
                    if (!pb.ok || !pbd.success) {
                        throw new Error(pbd.error || 'Falha ao registar recebimento do saldo.');
                    }
                }

                // 2) Atualiza dados de check-in (PUT parcial) e status → Hospedado.
                const upPayload = Object.assign({}, payload, { status: 'Hospedado' });
                const up = await fetch(`../api/reservations.php?id=${encodeURIComponent(r.id)}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-Internal-Key': window.internalKey || internalApiKey },
                    body: JSON.stringify(upPayload)
                });
                if (!up.ok) {
                    const err = await up.json().catch(() => ({}));
                    throw new Error(err.error || ('HTTP ' + up.status));
                }

                // 3) Dispara envio FNRH (o backend decide se contacta o gov ou não).
                let fnrhMessage = '';
                try {
                    const fr = await fetch(`../api/fnrh_service.php?id=${encodeURIComponent(r.id)}`, {
                        method: 'POST',
                        headers: { 'X-Internal-Key': internalApiKey }
                    });
                    const fd = await fr.json().catch(() => ({}));
                    fnrhMessage = fd.message || '';
                } catch (_) { /* não crítico */ }

                showAlert('ok', 'Check-in efetivado com sucesso. ' + (fnrhMessage ? fnrhMessage : ''));
                await fetchApiData();
                setTimeout(() => {
                    closeModal();
                    renderView(document.querySelector('.nav-item.active')?.getAttribute('data-view') || 'reservations');
                }, 1200);
            } catch (e) {
                showAlert('err', 'Falha: ' + (e && e.message ? e.message : e));
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<i class="ph ph-check-circle"></i> ${fnrhBtnLabel}`;
            }
        };
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
                                    <input type="file" id="addGalleryImages" class="form-control" accept="image/*" multiple style="opacity: 0; position: absolute; top: 0; left: 0; width: 100%; height: 100%; cursor: pointer;">
                                    <i class="ph ph-images" style="font-size: 2rem; color: #ccc;"></i>
                                    <div id="galleryImgNames" style="color: #666; font-size: 0.85rem; margin-top: 0.5rem;">Clique para selecionar várias fotos</div>
                                </div>
                                <div id="chaletGalleryManager" class="gallery-manager"></div>
                                <small style="display:block; margin-top:0.5rem; color:#666;">Passe o rato sobre uma miniatura e use o ícone para remover. As remoções só são aplicadas ao clicar em "Salvar".</small>
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

        // Inicializa o gestor de galeria para este chalé (thumbnails + remoção + preview).
        const galleryContainer = document.getElementById('chaletGalleryManager');
        const galleryInput = document.getElementById('addGalleryImages');
        const galleryNameEl = document.getElementById('galleryImgNames');
        galleryState.chalet.current = (chalet && Array.isArray(chalet.images)) ? chalet.images.filter(Boolean) : [];
        galleryState.chalet.toDelete = [];
        if (galleryContainer) renderGalleryManager('chalet', galleryContainer, { previewFiles: [] });
        if (galleryInput && galleryContainer) {
            wireGalleryFileInput('chalet', galleryInput, galleryContainer, galleryNameEl);
        }
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

    // Reservation Handling (Guest Folio centralizado com abas)
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
            total_consumed: 0,
            status: 'Pendente'
        };

        const chaletsOptions = chaletsData.map(c =>
            `<option value="${c.id}" ${res.chalet_id == c.id ? 'selected' : ''}>${c.name}</option>`
        ).join('');

        const formTitle = isEditing ? `Guest Folio #${String(res.id).padStart(3, '0')}` : 'Criar Nova Reserva';
        const jsParamId = isEditing ? res.id : 'null';
        const canUseLifecycleTabs = isEditing && !!res.id;

        const modalHtml = `
            <div class="modal-overlay" id="editResModal" onclick="if(event.target === this) this.remove()">
                <div class="modal-content guest-folio-modal" style="max-width: 980px;">
                    <div class="modal-header">
                        <h3>${formTitle}</h3>
                        <button class="close-btn" onclick="document.getElementById('editResModal').remove()"><i class="ph ph-x"></i></button>
                    </div>

                    <div class="folio-tabs" id="folioTabs">
                        <button type="button" class="folio-tab active" data-tab="summary">Dados Gerais</button>
                        <button type="button" class="folio-tab ${canUseLifecycleTabs ? '' : 'disabled'}" data-tab="checkin" ${canUseLifecycleTabs ? '' : 'disabled'}>FNRH / Check-in</button>
                        <button type="button" class="folio-tab ${canUseLifecycleTabs ? '' : 'disabled'}" data-tab="consumption" ${canUseLifecycleTabs ? '' : 'disabled'}>Conta do Quarto (Frigobar)</button>
                        <button type="button" class="folio-tab ${canUseLifecycleTabs ? '' : 'disabled'}" data-tab="checkout" ${canUseLifecycleTabs ? '' : 'disabled'}>Financeiro & Check-out</button>
                    </div>

                    <form onsubmit="handleEditReservation(event, ${jsParamId})">
                        <input type="hidden" id="editResBalancePaid" value="${Number(res.balance_paid || 0) === 1 ? '1' : '0'}">

                        <section class="folio-pane active" data-pane="summary">
                            <div class="form-group">
                                <label>Nome do Hóspede</label>
                                <input type="text" id="editResName" class="form-control" required value="${res.guest_name || ''}">
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
                            <div style="display:flex; gap:1rem;">
                                <div class="form-group" style="flex:1;">
                                    <label>Hóspedes</label>
                                    <select id="editResGuestsOption" class="form-control">
                                        <option value="">Carregando...</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label>Hospedagem</label>
                                    <select id="editResChaletId" class="form-control" required>${chaletsOptions}</select>
                                </div>
                            </div>
                            <div style="display:flex; gap:1rem;">
                                <div class="form-group" style="flex:1;">
                                    <label>Check-in</label>
                                    <input type="date" id="editResCheckin" class="form-control" required value="${res.checkin_date || ''}">
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label>Check-out</label>
                                    <input type="date" id="editResCheckout" class="form-control" required value="${res.checkout_date || ''}">
                                </div>
                            </div>
                            <div style="display:flex; gap:1rem;">
                                <div class="form-group" style="flex:1;">
                                    <label>Valor Adicional / Ajuste (R$)</label>
                                    <input type="number" step="0.01" id="editResAdditionalValue" class="form-control" value="${res.additional_value != null ? res.additional_value : '0'}">
                                    <small style="color:#666;">Use para somar extras ou aplicar descontos (valor negativo).</small>
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label>Status atual</label>
                                    <select id="editResStatus" class="form-control">
                                        <option value="Pendente" ${res.status === 'Pendente' ? 'selected' : ''}>Pendente</option>
                                        <option value="Confirmada" ${res.status === 'Confirmada' ? 'selected' : ''}>Confirmada</option>
                                        <option value="Hospedado" ${res.status === 'Hospedado' ? 'selected' : ''}>Hospedado</option>
                                        <option value="Finalizada" ${res.status === 'Finalizada' ? 'selected' : ''}>Finalizada</option>
                                        <option value="Cancelada" ${res.status === 'Cancelada' ? 'selected' : ''}>Cancelada</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Valor Total (R$)</label>
                                <input type="number" step="0.01" id="editResTotal" class="form-control" required value="${res.total_amount || ''}">
                                <small id="editResTotalBreakdown" style="color:#666; display:block; margin-top:0.25rem;">Preenchido automaticamente. Edite para sobrescrever.</small>
                            </div>
                            <button type="submit" class="btn" style="width:100%; justify-content:center;">${isEditing ? 'Atualizar Reserva' : 'Criar Reserva'}</button>
                        </section>

                        <section class="folio-pane" data-pane="checkin">
                            ${canUseLifecycleTabs ? `
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                                <div class="form-group"><label>CPF</label><input id="editResGuestCpf" class="form-control" value="${res.guest_cpf || ''}"></div>
                                <div class="form-group"><label>Telefone FNRH</label><input id="editResGuestPhoneFnrh" class="form-control" value="${res.guest_phone || ''}"></div>
                            </div>
                            <div class="form-group"><label>Endereço</label><textarea id="editResGuestAddress" class="form-control" rows="2">${res.guest_address || ''}</textarea></div>
                            <div style="display:grid; grid-template-columns:1fr 2fr; gap:0.75rem;">
                                <div class="form-group"><label>Placa</label><input id="editResGuestPlate" class="form-control" value="${res.guest_car_plate || ''}"></div>
                                <div class="form-group"><label>Acompanhantes</label><input id="editResCompanions" class="form-control" value="${res.guest_companion_names || ''}"></div>
                            </div>
                            <div style="padding:.8rem 1rem; border-radius:8px; background:#f9fafb; border:1px solid #e5e7eb; margin:.5rem 0 1rem;">
                                <strong>Status FNRH:</strong> ${res.fnrh_status || 'pendente'}
                            </div>
                            <button type="button" class="btn btn-primary" id="folioStartCheckinBtn" style="width:100%; justify-content:center;">
                                <i class="ph ph-key"></i> Fazer Check-in / Enviar FNRH
                            </button>
                            ` : '<div class="dash-empty-neutral">Salve a reserva primeiro para habilitar o fluxo de Check-in 360º.</div>'}
                        </section>

                        <section class="folio-pane" data-pane="consumption">
                            ${canUseLifecycleTabs ? `
                            <div class="folio-inline-form">
                                <select id="consCatalog" class="form-control"><option value="">Catálogo (opcional)</option></select>
                                <input id="consDesc" class="form-control" placeholder="Descrição">
                                <input id="consQty" class="form-control" type="number" min="1" value="1">
                                <input id="consUnit" class="form-control" type="number" step="0.01" min="0" placeholder="Preço unit.">
                                <button type="button" class="btn btn-primary" id="consAddBtn"><i class="ph ph-plus"></i> Adicionar</button>
                            </div>
                            <div id="consList" class="folio-cons-list"></div>
                            <div id="consTotal" style="margin-top:.75rem; font-weight:600;"></div>
                            ` : '<div class="dash-empty-neutral">Salve a reserva primeiro para lançar consumos.</div>'}
                        </section>

                        <section class="folio-pane" data-pane="checkout">
                            ${canUseLifecycleTabs ? `
                            <div id="folioFinanceSummary" class="folio-fin-summary"></div>
                            <div id="folioFinanceAction" style="margin-top:1rem;"></div>
                            ` : '<div class="dash-empty-neutral">Salve a reserva primeiro para gerir o fechamento financeiro.</div>'}
                        </section>
                    </form>
                </div>
            </div>
        `;
        document.getElementById('modalContainer').innerHTML = modalHtml;

        const tabsHost = document.getElementById('folioTabs');
        const tabButtons = tabsHost ? Array.from(tabsHost.querySelectorAll('.folio-tab')) : [];
        const panes = Array.from(document.querySelectorAll('#editResModal .folio-pane'));
        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                if (btn.classList.contains('disabled')) return;
                const target = btn.getAttribute('data-tab');
                tabButtons.forEach(b => b.classList.toggle('active', b === btn));
                panes.forEach(p => p.classList.toggle('active', p.getAttribute('data-pane') === target));
            });
        });

        const chaletSelect = document.getElementById('editResChaletId');
        const guestsSelect = document.getElementById('editResGuestsOption');
        const checkinInput = document.getElementById('editResCheckin');
        const checkoutInput = document.getElementById('editResCheckout');
        const totalInput = document.getElementById('editResTotal');
        const additionalInput = document.getElementById('editResAdditionalValue');
        const breakdownEl = document.getElementById('editResTotalBreakdown');
        const balancePaidHidden = document.getElementById('editResBalancePaid');
        let consumptionTotal = Number(res.total_consumed || 0);
        let currentConsumptionItems = [];
        const currentGuestsVal = res ? `${res.guests_adults || 2}_${res.guests_children || 0}` : '2_0';

        function updateGuestsDropdown() {
            if (!chaletSelect || !guestsSelect || typeof chaletsData === 'undefined') return;
            const selectedChalet = chaletsData.find(c => String(c.id) === String(chaletSelect.value)) || {};
            const maxGuests = selectedChalet.max_guests || 4;
            const valToSet = guestsSelect.value && guestsSelect.value !== '' ? guestsSelect.value : currentGuestsVal;
            renderGuestOptionsAdmin(guestsSelect, maxGuests, valToSet);
        }
        function diffNightsAdmin(checkinStr, checkoutStr) {
            if (!checkinStr || !checkoutStr) return 0;
            const ci = new Date(checkinStr + 'T00:00:00');
            const co = new Date(checkoutStr + 'T00:00:00');
            if (isNaN(ci.getTime()) || isNaN(co.getTime())) return 0;
            const diffMs = co.getTime() - ci.getTime();
            if (diffMs <= 0) return 0;
            return Math.max(1, Math.round(diffMs / (1000 * 60 * 60 * 24)));
        }
        const fmtMoney = (n) => 'R$ ' + Number(n || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });

        function recalculateTotalAdmin() {
            if (!totalInput) return;
            const chaletObj = (typeof chaletsData !== 'undefined')
                ? (chaletsData.find(c => String(c.id) === String(chaletSelect ? chaletSelect.value : '')) || null)
                : null;
            const price = chaletObj ? (parseFloat(chaletObj.price) || 0) : 0;
            const baseGuests = chaletObj && chaletObj.base_guests != null ? parseInt(chaletObj.base_guests, 10) || 0 : 0;
            const extraFee = chaletObj && chaletObj.extra_guest_fee != null ? parseFloat(chaletObj.extra_guest_fee) || 0 : 0;
            const ciStr = checkinInput ? checkinInput.value : '';
            const coStr = checkoutInput ? checkoutInput.value : '';
            const nights = diffNightsAdmin(ciStr, coStr);
            if (ciStr && coStr && nights === 0) {
                totalInput.value = '0.00';
                if (breakdownEl) {
                    breakdownEl.textContent = 'Atenção: check-out deve ser posterior ao check-in.';
                    breakdownEl.style.color = 'var(--danger)';
                }
                return;
            }
            const parsedGuests = guestsSelect ? parseGuestsOptionAdmin(guestsSelect.value) : { adults: 0, children: 0 };
            const totalGuests = parsedGuests.adults + parsedGuests.children;
            const extraGuests = Math.max(0, totalGuests - baseGuests);
            const lodging = Math.round(price * nights * 100) / 100;
            const extra = Math.round(extraGuests * extraFee * nights * 100) / 100;
            const adjustment = additionalInput ? (parseFloat(additionalInput.value) || 0) : 0;
            const total = Math.round((lodging + extra + adjustment) * 100) / 100;
            totalInput.value = total.toFixed(2);
            if (breakdownEl) {
                breakdownEl.style.color = '#666';
                const parts = [`${nights} noite(s) × ${fmtMoney(price)} = ${fmtMoney(lodging)}`];
                if (extraGuests > 0) parts.push(`${extraGuests} hóspede(s) extra × ${fmtMoney(extraFee)} × ${nights} = ${fmtMoney(extra)}`);
                if (adjustment !== 0) parts.push(`Ajuste: ${fmtMoney(adjustment)}`);
                parts.push(`Total: ${fmtMoney(total)}`);
                breakdownEl.textContent = parts.join(' · ');
            }
        }

        function buildFinanceNumbers() {
            const policy = getPaymentPolicy((res && res.payment_rule) ? res.payment_rule : 'full');
            const percentNow = Math.min(100, Math.max(0, Number(policy.percent_now || 100)));
            const percentBal = Math.max(0, 100 - percentNow);
            const totalDiarias = Math.max(0, parseFloat(totalInput ? totalInput.value : (res.total_amount || 0)) || 0);
            const sinal = Math.round((totalDiarias * percentNow) / 100 * 100) / 100;
            const saldo = Math.round((totalDiarias * percentBal) / 100 * 100) / 100;
            const saldoPago = balancePaidHidden && balancePaidHidden.value === '1';
            const saldoPendente = saldoPago ? 0 : saldo;
            const consumo = Math.max(0, Number(consumptionTotal || 0));
            const finalAgora = Math.round((saldoPendente + consumo) * 100) / 100;
            return { totalDiarias, sinal, saldo, saldoPago, saldoPendente, consumo, finalAgora, percentBal };
        }

        async function renderConsumptionList() {
            if (!canUseLifecycleTabs) return;
            const listEl = document.getElementById('consList');
            const totalEl = document.getElementById('consTotal');
            if (!listEl || !totalEl) return;
            const ok = await ensureInternalApiKey();
            if (!ok) {
                listEl.innerHTML = '<div class="dash-empty-neutral">Não foi possível validar sessão admin.</div>';
                return;
            }
            try {
                const req = await fetch(`../api/consumptions.php?reservation_id=${encodeURIComponent(res.id)}`, {
                    headers: { 'X-Internal-Key': internalApiKey }
                });
                const data = await req.json().catch(() => ({}));
                if (!req.ok) throw new Error(data.error || ('HTTP ' + req.status));
                const items = Array.isArray(data.items) ? data.items : [];
                currentConsumptionItems = items;
                consumptionTotal = Number(data.total_consumed || 0);
                if (!items.length) {
                    listEl.innerHTML = '<div class="dash-empty-neutral">Nenhum consumo lançado ainda.</div>';
                } else {
                    listEl.innerHTML = items.map(it => `
                        <div class="folio-cons-item">
                            <div>
                                <strong>${it.description}</strong><br>
                                <small>${it.quantity} × ${fmtMoney(it.unit_price)}</small>
                            </div>
                            <div style="display:flex; align-items:center; gap:.5rem;">
                                <strong>${fmtMoney(it.total_price)}</strong>
                                <button type="button" class="btn-icon" data-cons-del="${it.id}" title="Apagar"><i class="ph ph-trash"></i></button>
                            </div>
                        </div>
                    `).join('');
                    listEl.querySelectorAll('[data-cons-del]').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            if (!confirm('Remover este consumo?')) return;
                            await fetch(`../api/consumptions.php?id=${encodeURIComponent(btn.getAttribute('data-cons-del'))}`, {
                                method: 'DELETE',
                                headers: { 'X-Internal-Key': internalApiKey }
                            });
                            await renderConsumptionList();
                            renderCheckoutTab();
                        });
                    });
                }
                totalEl.textContent = `Total consumido: ${fmtMoney(consumptionTotal)}`;
                renderCheckoutTab();
            } catch (e) {
                currentConsumptionItems = [];
                listEl.innerHTML = `<div class="dash-empty-neutral">Erro ao carregar consumo: ${e.message || e}</div>`;
            }
        }

        function printGuestFolio(brand, finance) {
            const w = window.open('', '_blank', 'width=980,height=760');
            if (!w) return;
            const safeBrand = String(brand || 'Hospedagem').replace(/</g, '&lt;');
            const receiptNo = `#RES-${String(res.id).padStart(3, '0')}`;
            const now = new Date();
            const auditDateTime = now.toLocaleString('pt-BR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            }).replace(',', '');
            const operator = (
                localStorage.getItem('adminName')
                || localStorage.getItem('adminUser')
                || localStorage.getItem('adminEmail')
                || localStorage.getItem('userName')
                || localStorage.getItem('userEmail')
                || document.getElementById('adminBrandName')?.textContent
                || (localStorage.getItem('adminRole') ? `role:${localStorage.getItem('adminRole')}` : '')
                || 'Operador'
            ).trim();
            const auditTrail = `Emitido em: ${auditDateTime} | Operador: ${operator.replace(/</g, '&lt;')}`;
            const consumptionRows = currentConsumptionItems.length
                ? currentConsumptionItems.map(it => `
                    <tr>
                        <td>${it.description}</td>
                        <td style="text-align:center;">${it.quantity}</td>
                        <td style="text-align:right;">${fmtMoney(it.unit_price)}</td>
                        <td style="text-align:right;"><strong>${fmtMoney(it.total_price)}</strong></td>
                    </tr>
                `).join('')
                : `<tr><td colspan="4" style="text-align:center;color:#6b7280;">Sem lançamentos de consumo</td></tr>`;
            const folioTemplate = `
                <div class="doc {{VIA_CLASS}}">
                    <div class="head">
                        <div><div class="brand">${safeBrand}</div><h1>Folio de Hospedagem / Extrato de Conta</h1></div>
                        <div style="text-align:right;">
                            <div class="via-badge">{{VIA_LABEL}}</div>
                            <div class="rec">${receiptNo}</div>
                        </div>
                    </div>
                    <div class="box">
                        <div class="grid">
                            <div><div class="k">Hóspede</div><div class="v">${res.guest_name || '-'}</div></div>
                            <div><div class="k">CPF</div><div class="v">${(document.getElementById('editResGuestCpf')?.value || '-')}</div></div>
                            <div><div class="k">Acomodação</div><div class="v">${res.chalet_name || '-'}</div></div>
                            <div><div class="k">Período</div><div class="v">${formatDateBR(res.checkin_date)} a ${formatDateBR(res.checkout_date)}</div></div>
                        </div>
                    </div>
                    <div class="sec-title">Diárias da Acomodação</div>
                    <table><thead><tr><th>Descrição</th><th style="text-align:right;">Valor</th></tr></thead>
                    <tbody>
                        <tr><td>Total de diárias</td><td style="text-align:right;">${fmtMoney(finance.totalDiarias)}</td></tr>
                        <tr><td>Sinal pago</td><td style="text-align:right;">${fmtMoney(finance.sinal)}</td></tr>
                        <tr><td>Saldo de diárias</td><td style="text-align:right;">${finance.saldoPago ? 'Pago' : fmtMoney(finance.saldoPendente)}</td></tr>
                    </tbody></table>
                    <div class="sec-title">Itens de Consumo (Frigobar / Extras)</div>
                    <table><thead><tr><th>Item</th><th style="text-align:center;">Qtd</th><th style="text-align:right;">Unit.</th><th style="text-align:right;">Total</th></tr></thead>
                    <tbody>${consumptionRows}</tbody></table>
                    <div class="totals">
                        <div class="tot-row"><span>Consumo total</span><strong>${fmtMoney(finance.consumo)}</strong></div>
                        <div class="tot-row final"><span>TOTAL A PAGAR</span><span>${fmtMoney(finance.finalAgora)}</span></div>
                    </div>
                    <div class="foot">
                        <div class="sign">Assinatura do(a) hóspede / responsável</div>
                        <div class="thanks">Agradecemos por escolher ${safeBrand}.<br>Desejamos uma excelente experiência.</div>
                    </div>
                    <div class="audit-trail">${auditTrail}</div>
                </div>
            `;
            const viaHospede = folioTemplate.replace('{{VIA_LABEL}}', 'Via do Hóspede').replace('{{VIA_CLASS}}', 'via-guest');
            const viaEstabelecimento = folioTemplate.replace('{{VIA_LABEL}}', 'Via do Estabelecimento').replace('{{VIA_CLASS}}', 'via-establishment');

            const html = `<!doctype html><html><head><meta charset="utf-8"><title>Folio ${receiptNo}</title>
                <style>
                    @page { size: A4; margin: 10mm; }
                    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    body { font-family: Inter, Arial, sans-serif; color: #111827; margin: 0; background: #fff; font-size: 12.2px; line-height: 1.28; }
                    .doc { padding: 12px; border: 1px solid #e5e7eb; border-radius: 10px; page-break-inside: avoid; break-inside: avoid; }
                    .head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #111827; padding-bottom:12px; margin-bottom:14px; }
                    .head h1 { margin:0; font-size:1rem; }
                    .head .brand { font-size:1.1rem; font-weight:700; }
                    .head .rec { font-size:.9rem; font-weight:700; }
                    .via-badge { display:inline-block; margin-bottom:4px; background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; border-radius:999px; padding:2px 9px; font-size:.72rem; font-weight:700; }
                    .box { border:1px solid #d1d5db; border-radius:8px; padding:8px; margin-bottom:9px; background:#f9fafb; page-break-inside: avoid; break-inside: avoid; }
                    .grid { display:grid; grid-template-columns:1fr 1fr; gap:8px 16px; }
                    .k { color:#6b7280; font-size:.74rem; text-transform:uppercase; letter-spacing:.03em; }
                    .v { font-weight:600; font-size:.84rem; }
                    .sec-title { margin:10px 0 5px; font-size:.84rem; font-weight:700; background:#eef2ff; padding:5px 8px; border-radius:6px; page-break-inside: avoid; break-inside: avoid; }
                    table { width:100%; border-collapse:collapse; font-size:.8rem; page-break-inside: avoid; break-inside: avoid; }
                    th,td { border-bottom:1px solid #e5e7eb; padding:6px 5px; text-align:left; }
                    th { background:#f3f4f6; font-size:.72rem; text-transform:uppercase; letter-spacing:.03em; }
                    .totals { margin-top:9px; border-top:2px solid #111827; padding-top:7px; page-break-inside: avoid; break-inside: avoid; }
                    .tot-row { display:flex; justify-content:space-between; margin:3px 0; font-size:.82rem; }
                    .tot-row.final { font-size:1rem; font-weight:800; margin-top:6px; }
                    .foot { margin-top:12px; display:flex; justify-content:space-between; gap:16px; page-break-inside: avoid; break-inside: avoid; }
                    .sign { flex:1; border-top:1px solid #111827; padding-top:6px; font-size:.76rem; color:#374151; text-align:center; }
                    .thanks { font-size:.78rem; color:#374151; text-align:right; }
                    .audit-trail { font-size:10px; color:#777; text-align:center; margin-top:15px; border-top:1px solid #eee; padding-top:5px; font-family:monospace; }
                    .via-establishment .sign { font-weight:700; color:#111827; }
                    .cut-line { border-top: 1px dashed #999; margin: 30px 0; text-align: center; color: #666; font-size: 12px; opacity: 0.7; }
                    @media print {
                        html, body { width: 210mm; }
                        body { font-size: 11px; }
                        .doc { border: none; border-radius: 0; padding: 0; }
                        .head { margin-bottom: 8px; padding-bottom: 7px; }
                        table, tr, td, th, .box, .totals, .foot, .sec-title { page-break-inside: avoid; break-inside: avoid; }
                    }
                </style></head><body>
                ${viaHospede}
                <div class="cut-line">✂ - - - - - - - - - - Corte Aqui - - - - - - - - - - ✂</div>
                ${viaEstabelecimento}
                <script>window.onload = function(){ window.print(); }<\/script>
                </body></html>`;
            w.document.write(html);
            w.document.close();
            w.focus();
        }

        async function loadConsumptionCatalog() {
            if (!canUseLifecycleTabs) return;
            const sel = document.getElementById('consCatalog');
            if (!sel) return;
            try {
                const ok = await ensureInternalApiKey();
                if (!ok) return;
                const req = await fetch('../api/admin_extra_services.php', { headers: { 'X-Internal-Key': internalApiKey } });
                const rows = await req.json().catch(() => []);
                if (!Array.isArray(rows)) return;
                rows.filter(r => Number(r.active || 0) === 1).forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = JSON.stringify({ name: r.name, price: r.price });
                    opt.textContent = `${r.name} (${fmtMoney(r.price)})`;
                    sel.appendChild(opt);
                });
                sel.addEventListener('change', () => {
                    if (!sel.value) return;
                    try {
                        const obj = JSON.parse(sel.value);
                        const d = document.getElementById('consDesc');
                        const u = document.getElementById('consUnit');
                        if (d && !d.value) d.value = obj.name || '';
                        if (u && (!u.value || Number(u.value) <= 0)) u.value = Number(obj.price || 0).toFixed(2);
                    } catch (_) { /* noop */ }
                });
            } catch (_) { /* catálogo opcional */ }
        }

        function renderCheckoutTab() {
            if (!canUseLifecycleTabs) return;
            const summaryEl = document.getElementById('folioFinanceSummary');
            const actionEl = document.getElementById('folioFinanceAction');
            if (!summaryEl || !actionEl) return;
            const f = buildFinanceNumbers();
            summaryEl.innerHTML = `
                <div class="folio-fin-card">
                    <small>Total Diárias</small>
                    <strong>${fmtMoney(f.totalDiarias)}</strong>
                    <span>Sinal: ${fmtMoney(f.sinal)} · Saldo: ${f.saldoPago ? 'Pago' : 'Pendente'}</span>
                </div>
                <div class="folio-fin-card">
                    <small>Total Consumo</small>
                    <strong>${fmtMoney(f.consumo)}</strong>
                    <span>Lançamentos da aba Consumo</span>
                </div>
                <div class="folio-fin-card accent">
                    <small>Total final a pagar agora</small>
                    <strong>${fmtMoney(f.finalAgora)}</strong>
                    <span>Saldo de diárias + consumo</span>
                </div>
            `;

            const isHospedado = String(document.getElementById('editResStatus')?.value || '') === 'Hospedado';
            if (!isHospedado) {
                actionEl.innerHTML = '<div class="dash-empty-neutral">O check-out é liberado apenas quando o status está como <strong>Hospedado</strong>.</div>';
                return;
            }
            const needsConfirm = f.finalAgora > 0;
            actionEl.innerHTML = `
                ${needsConfirm ? `<label style="display:flex; align-items:center; gap:.5rem; margin-bottom:.75rem;"><input type="checkbox" id="checkoutConfirmMoney"> Confirmo o recebimento do saldo (${fmtMoney(f.finalAgora)})</label>` : ''}
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:.5rem; margin-bottom:.5rem;">
                    <button type="button" id="folioPrintStatementBtn" class="btn btn-outline" style="justify-content:center;">
                        <i class="ph ph-printer"></i> Imprimir Extrato
                    </button>
                    <button type="button" id="folioSendStatementBtn" class="btn btn-outline" style="justify-content:center; color:#16a34a; border-color:#86efac;">
                        <i class="ph ph-whatsapp-logo"></i> Enviar Extrato por WhatsApp
                    </button>
                </div>
                <button type="button" id="folioCheckoutBtn" class="btn btn-primary" style="background:#16a34a; border-color:#16a34a; width:100%; justify-content:center;">
                    <i class="ph ph-check-circle"></i> Finalizar Conta e Fazer Check-out
                </button>
            `;
            const checkoutBtn = document.getElementById('folioCheckoutBtn');
            const printBtn = document.getElementById('folioPrintStatementBtn');
            const sendBtn = document.getElementById('folioSendStatementBtn');
            const checkEl = document.getElementById('checkoutConfirmMoney');
            if (checkoutBtn && checkEl) {
                checkoutBtn.disabled = !checkEl.checked;
                checkEl.addEventListener('change', () => { checkoutBtn.disabled = !checkEl.checked; });
            }
            const brand = (document.getElementById('adminBrandName')?.textContent || '').trim() || 'Hospedagem';
            if (printBtn) {
                printBtn.addEventListener('click', () => {
                    printGuestFolio(brand, f);
                });
            }
            if (sendBtn) {
                sendBtn.addEventListener('click', async () => {
                    const phoneRaw = String(res.guest_phone || '').trim();
                    const phone = phoneRaw.replace(/\D/g, '');
                    if (!phone) return alert('Telefone do hóspede não informado.');
                    const ok = await ensureInternalApiKey();
                    if (!ok) return alert('Não foi possível validar sessão interna.');
                    const req = await fetch('../api/evolution_service.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                        body: JSON.stringify({
                            action: 'folio_receipt',
                            number: phone,
                            reservation_id: Number(res.id),
                            guest_name: res.guest_name || '',
                            total_diarias: f.totalDiarias,
                            consumo_extra: f.consumo,
                            total_final: f.finalAgora
                        })
                    });
                    const data = await req.json().catch(() => ({}));
                    if (!req.ok || !data.ok) return alert(data.error || 'Falha ao enviar extrato via WhatsApp.');
                    alert('Extrato enviado via WhatsApp com sucesso.');
                });
            }
            if (checkoutBtn) {
                checkoutBtn.addEventListener('click', async () => {
                    if (!confirm('Confirma o check-out e fechamento desta conta?')) return;
                    try {
                        const ok = await ensureInternalApiKey();
                        if (!ok) throw new Error('Não foi possível validar sessão interna.');
                        if (!f.saldoPago && f.saldo > 0) {
                            const pb = await fetch('../api/pay_balance.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                                body: JSON.stringify({ reservation_id: res.id })
                            });
                            const pbd = await pb.json().catch(() => ({}));
                            if (!pb.ok || !pbd.success) {
                                throw new Error(pbd.error || 'Falha ao registrar quitação do saldo.');
                            }
                        }
                        const payload = { status: 'Finalizada' };
                        const req = await fetch(`../api/reservations.php?id=${encodeURIComponent(res.id)}`, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', 'X-Internal-Key': window.internalKey || internalApiKey },
                            body: JSON.stringify(payload)
                        });
                        const data = await req.json().catch(() => ({}));
                        if (!req.ok) throw new Error(data.error || ('HTTP ' + req.status));
                        alert('Check-out finalizado com sucesso.');
                        document.getElementById('editResModal').remove();
                        await fetchApiData();
                        renderView('reservations');
                    } catch (e) {
                        alert('Falha ao finalizar check-out: ' + (e.message || e));
                    }
                });
            }
        }

        if (chaletSelect) {
            chaletSelect.addEventListener('change', () => { updateGuestsDropdown(); recalculateTotalAdmin(); renderCheckoutTab(); });
            updateGuestsDropdown();
        }
        if (guestsSelect) guestsSelect.addEventListener('change', () => { recalculateTotalAdmin(); renderCheckoutTab(); });
        if (checkinInput) checkinInput.addEventListener('change', () => { recalculateTotalAdmin(); renderCheckoutTab(); });
        if (checkoutInput) checkoutInput.addEventListener('change', () => { recalculateTotalAdmin(); renderCheckoutTab(); });
        if (additionalInput) additionalInput.addEventListener('input', () => { recalculateTotalAdmin(); renderCheckoutTab(); });
        if (totalInput) {
            totalInput.addEventListener('input', () => {
                if (breakdownEl) {
                    breakdownEl.textContent = 'Valor total sobrescrito manualmente. Será recalculado se datas, hospedagem, hóspedes ou ajuste mudarem.';
                    breakdownEl.style.color = 'var(--warning, #b45309)';
                }
                renderCheckoutTab();
            });
        }

        if (!isEditing || !res.total_amount) recalculateTotalAdmin();
        renderCheckoutTab();

        const checkinBtn = document.getElementById('folioStartCheckinBtn');
        if (checkinBtn && canUseLifecycleTabs) {
            checkinBtn.addEventListener('click', () => openCheckinModal(Number(res.id)));
        }

        const consAddBtn = document.getElementById('consAddBtn');
        if (consAddBtn && canUseLifecycleTabs) {
            consAddBtn.addEventListener('click', async () => {
                const desc = (document.getElementById('consDesc')?.value || '').trim();
                const qty = parseInt(document.getElementById('consQty')?.value || '1', 10) || 1;
                const unit = parseFloat(document.getElementById('consUnit')?.value || '0') || 0;
                if (!desc) return alert('Informe a descrição do consumo.');
                const ok = await ensureInternalApiKey();
                if (!ok) return alert('Não foi possível validar sessão admin.');
                const req = await fetch('../api/consumptions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Internal-Key': internalApiKey },
                    body: JSON.stringify({ reservation_id: res.id, description: desc, quantity: qty, unit_price: unit })
                });
                const data = await req.json().catch(() => ({}));
                if (!req.ok) return alert(data.error || 'Erro ao adicionar consumo');
                document.getElementById('consDesc').value = '';
                document.getElementById('consQty').value = '1';
                document.getElementById('consUnit').value = '';
                await renderConsumptionList();
                await fetchApiData();
            });
            loadConsumptionCatalog();
            renderConsumptionList();
        }
    }

    window.handleEditReservation = async function (e, id) {
        e.preventDefault();

        const additionalEl = document.getElementById('editResAdditionalValue');
        const additionalValue = additionalEl ? (parseFloat(additionalEl.value) || 0) : 0;
        const balancePaidEl = document.getElementById('editResBalancePaid');
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
            additional_value: additionalValue,
            status: document.getElementById('editResStatus').value
        };
        const cpfEl = document.getElementById('editResGuestCpf');
        const fnrhPhoneEl = document.getElementById('editResGuestPhoneFnrh');
        const addrEl = document.getElementById('editResGuestAddress');
        const plateEl = document.getElementById('editResGuestPlate');
        const companionsEl = document.getElementById('editResCompanions');
        if (cpfEl) payload.guest_cpf = (cpfEl.value || '').replace(/\D/g, '');
        if (fnrhPhoneEl && !payload.guest_phone) payload.guest_phone = fnrhPhoneEl.value || '';
        if (addrEl) payload.guest_address = addrEl.value || '';
        if (plateEl) payload.guest_car_plate = plateEl.value || '';
        if (companionsEl) payload.guest_companion_names = companionsEl.value || '';
        if (balancePaidEl) {
            payload.balance_paid = balancePaidEl.value === '1' ? 1 : 0;
        }

        const method = id ? 'PUT' : 'POST';
        const url = id ? `../api/reservations.php?id=${id}` : '../api/reservations.php';

        try {
            const res = await fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json', 'X-Internal-Key': window.internalKey || internalApiKey },
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
            await appendCompressedImage(formData, 'main_image', imageFile, { maxWidth: 1920, quality: 0.8, forceLossy: true });
        }

        // Gallery Images (Multiple) - PHP espera $_FILES['images']
        const galleryFiles = document.getElementById('addGalleryImages').files;
        if (galleryFiles.length > 0) {
            for (let i = 0; i < galleryFiles.length; i++) {
                await appendCompressedImage(formData, 'images[]', galleryFiles[i], { maxWidth: 1920, quality: 0.8, forceLossy: true });
            }
        }
        // Imagens da galeria marcadas para remoção pelo gestor visual.
        const chaletToDelete = Array.isArray(galleryState.chalet.toDelete) ? galleryState.chalet.toDelete : [];
        chaletToDelete.forEach((p) => formData.append('images_to_delete[]', p));
        const chaletCurrent = Array.isArray(galleryState.chalet.current) ? galleryState.chalet.current : [];
        if (chaletCurrent.length > 0) {
            formData.append('images_order', JSON.stringify(chaletCurrent));
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
        { id: 'faqs', label: 'Perguntas Frequentes' },
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
            const res = await fetch('../api/settings.php?_t=' + new Date().getTime(), { credentials: 'same-origin', cache: 'no-store' });
            if (!res.ok) return true;
            const data = await res.json();

            applyAdminTheme(data.primary_color || '#2563eb', data.secondary_color || '#1e293b');
            const brandName = (data.company_name && String(data.company_name).trim()) ||
                (data.site_title && String(data.site_title).trim()) ||
                'Admin';
            const brandEl = document.getElementById('adminBrandName');
            if (brandEl) brandEl.textContent = brandName;
            const titleEl = document.getElementById('adminPageTitle');
            if (titleEl) titleEl.textContent = 'Admin · ' + brandName;
            try { document.title = 'Admin · ' + brandName; } catch (_) { /* noop */ }
            return true;
        } catch (e) {
            // Usa tema padrão quando não conseguir carregar.
            return true;
        }
    }

    // Initialize the admin app
    await loadAdminThemeFromSettings();
    const hashView = String(window.location.hash || '').replace(/^#/, '').trim();
    const allowedViews = ['dashboard', 'reservations', 'chalets', 'financeiro', 'coupons', 'faqs', 'settings', 'customization', 'users'];
    const initialView = allowedViews.includes(hashView) ? hashView : 'dashboard';
    await renderView(initialView);
    removeAddChaletButtonsForSecretary(document);

    // Guarda global para remover o CTA caso algum trecho re-renderize botão de cadastro
    const observer = new MutationObserver(() => removeAddChaletButtonsForSecretary(document));
    observer.observe(document.body, { childList: true, subtree: true });
});
