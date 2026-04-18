document.addEventListener('DOMContentLoaded', () => {
    // Shared State
    let allChalets = {};
    const availabilityCache = new Map();
    let latestAvailabilityRequest = 0;
    let currentChalet = 'Chalé Alpino';

    function countNightsBetween(checkinStr, checkoutStr) {
        const partsIn = checkinStr.split('-');
        const partsOut = checkoutStr.split('-');
        const cin = new Date(partsIn[0], partsIn[1] - 1, partsIn[2]);
        const cout = new Date(partsOut[0], partsOut[1] - 1, partsOut[2]);
        const diffDays = Math.ceil((cout - cin) / (1000 * 60 * 60 * 24));
        return diffDays > 0 ? diffDays : 1;
    }

    /** Soma das diárias (feriados + preço por dia da semana), alinhado com api/pricing.php */
    function computeLodgingSubtotal(chalet, checkinStr, checkoutStr) {
        if (!chalet || !checkinStr || !checkoutStr) return 0;
        const nights = countNightsBetween(checkinStr, checkoutStr);
        const partsIn = checkinStr.split('-');
        const cin = new Date(partsIn[0], partsIn[1] - 1, partsIn[2]);
        const basePrice = chalet.price != null ? parseFloat(chalet.price) : 0;
        let lodging = 0;
        for (let i = 0; i < nights; i++) {
            const currentDate = new Date(cin);
            currentDate.setDate(currentDate.getDate() + i);
            const year = currentDate.getFullYear();
            const month = String(currentDate.getMonth() + 1).padStart(2, '0');
            const day = String(currentDate.getDate()).padStart(2, '0');
            const dateStr = `${year}-${month}-${day}`;
            const dayOfWeek = currentDate.getDay();
            let nightPrice = basePrice;
            const hol = chalet.holidays?.find((h) => h.date === dateStr);
            if (hol && hol.price) {
                nightPrice = parseFloat(hol.price);
            } else {
                const weekProps = ['price_sun', 'price_mon', 'price_tue', 'price_wed', 'price_thu', 'price_fri', 'price_sat'];
                const prop = weekProps[dayOfWeek];
                if (chalet[prop] && parseFloat(chalet[prop]) > 0) {
                    nightPrice = parseFloat(chalet[prop]);
                }
            }
            lodging += nightPrice;
        }
        return Math.round(lodging * 100) / 100;
    }

    /** (total_hóspedes - base_guests) * extra_guest_fee * noites */
    function computeExtraGuestSubtotal(chalet, guestsAdults, guestsChildren, nights) {
        if (!chalet || nights < 1) return 0;
        const base = Math.max(1, parseInt(String(chalet.base_guests ?? 2), 10) || 2);
        const fee = parseFloat(String(chalet.extra_guest_fee ?? 0)) || 0;
        if (fee <= 0) return 0;
        const totalG = Math.max(0, parseInt(String(guestsAdults), 10) || 0) + Math.max(0, parseInt(String(guestsChildren), 10) || 0);
        const extra = Math.max(0, totalG - base);
        return Math.round(extra * fee * nights * 100) / 100;
    }

    function parseGuestsOption(guestsVal, fallbackAdults = 1) {
        const raw = String(guestsVal ?? '').trim();
        if (raw === '') {
            return { adults: fallbackAdults, children: 0 };
        }
        if (raw.indexOf('_') === -1) {
            const n = parseInt(raw, 10);
            if (n > 0) {
                return { adults: n, children: 0 };
            }
            return { adults: fallbackAdults, children: 0 };
        }
        const [ad, ch] = raw.split('_').map(Number);
        return {
            adults: ad > 0 ? ad : fallbackAdults,
            children: Number.isFinite(ch) && ch >= 0 ? ch : 0
        };
    }

    /** Valor do <select>: "1".."N" ou legado "N_M" (adultos_crianças). */
    function totalGuestsFromSelection(guestsVal) {
        const p = parseGuestsOption(guestsVal, 1);
        return Math.max(0, p.adults) + Math.max(0, p.children);
    }

    function renderGuestOptions(selectEl, maxGuests, preferredValue) {
        if (!selectEl) return;
        const cap = Math.max(1, parseInt(String(maxGuests ?? 4), 10) || 4);
        const frag = document.createDocumentFragment();
        for (let i = 1; i <= cap; i++) {
            const opt = document.createElement('option');
            opt.value = String(i);
            opt.textContent = i === 1 ? '1 Hóspede' : `${i} Hóspedes`;
            frag.appendChild(opt);
        }
        selectEl.innerHTML = '';
        selectEl.appendChild(frag);
        const prefToken = String(preferredValue ?? '').trim().split('_')[0];
        let prefNum = parseInt(prefToken, 10);
        if (!(prefNum >= 1 && prefNum <= cap)) {
            prefNum = Math.min(2, cap);
        }
        selectEl.value = String(prefNum);
    }

    function isUsableImageSrc(src) {
        const v = String(src || '').trim();
        return v !== '' && v.toLowerCase() !== 'null' && v.toLowerCase() !== 'undefined';
    }

    function buildSlideMarkup(src, alt, isActive) {
        const cls = `slide ${isActive ? 'active' : ''}`;
        if (!isUsableImageSrc(src)) {
            return `<div class="${cls} image-fallback"></div>`;
        }
        return `<img src="${src}" class="${cls}" alt="${alt}" onclick="openLightbox('${src}')" onerror="this.outerHTML='<div class=&quot;${cls} image-fallback&quot;></div>'">`;
    }

    /* =========================================
       NAVBAR SCROLL EFFECT
       ========================================= */
    const navbar = document.getElementById('navbar');

    // Set initial transparent state if at top
    if (window.scrollY === 0) {
        navbar.classList.add('transparent');
    }

    window.addEventListener('scroll', () => {
        const navbarLogoImg = navbar.querySelector('.logo img');
        if (window.scrollY > 50) {
            navbar.classList.add('scrolled');
            navbar.classList.remove('transparent');
            if (navbarLogoImg && navbarLogoImg.dataset.dark) {
                navbarLogoImg.src = navbarLogoImg.dataset.dark;
            }
        } else {
            navbar.classList.remove('scrolled');
            navbar.classList.add('transparent');
            if (navbarLogoImg && navbarLogoImg.dataset.light) {
                navbarLogoImg.src = navbarLogoImg.dataset.light;
            }
        }
    });

    /* =========================================
       MOBILE MENU
       ========================================= */
    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');

    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            navLinks.classList.toggle('active');

            // Toggle icon from list to X
            const icon = menuToggle.querySelector('i');
            if (navLinks.classList.contains('active')) {
                icon.classList.remove('ph-list');
                icon.classList.add('ph-x');
            } else {
                icon.classList.remove('ph-x');
                icon.classList.add('ph-list');
            }
        });
    }

    /* =========================================
       SMOOTH SCROLLING
       ========================================= */
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();

            const targetId = this.getAttribute('href');
            if (targetId === '#') return;

            const targetElement = document.querySelector(targetId);
            if (targetElement) {
                // Close mobile menu if open
                if (navLinks.classList.contains('active')) {
                    navLinks.classList.remove('active');
                    menuToggle.querySelector('i').classList.replace('ph-x', 'ph-list');
                }

                // Adjust for fixed navbar
                const headerOffset = 80;
                const elementPosition = targetElement.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                window.scrollTo({
                    top: offsetPosition,
                    behavior: "smooth"
                });
            }
        });
    });

    /* =========================================
       FADE-UP ANIMATIONS ON SCROLL (Observer)
       ========================================= */
    const fadeElements = document.querySelectorAll('.fade-up');

    const observerOptions = {
        root: null,
        rootMargin: '0px',
        threshold: 0.1
    };

    const observer = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);

    fadeElements.forEach(el => observer.observe(el));

    // Force trigger for hero section if already in view
    setTimeout(() => {
        document.querySelectorAll('.hero .fade-up').forEach(el => {
            el.classList.add('visible');
        });
    }, 100);

    // Init is now at the end of the script

    /* =========================================
       BOOKING MODAL LOGIC (MOCK)
       ========================================= */
    const modal = document.getElementById('bookingModal');
    const closeModalBtn = document.getElementById('closeModal');

    // Availability Modal
    const availabilityModal = document.getElementById('availabilityModal');
    const closeAvailabilityModalBtn = document.getElementById('closeAvailabilityModal');
    let modalSummaryDebounceTimer = null;

    const availabilityForm = document.getElementById('availabilityForm');
    const finalBookingForm = document.getElementById('finalBookingForm');
    const toast = document.getElementById('successToast');

    let bookingOptions = { show_coupon_field: false, show_extras_section: false, extra_services: [], payment_policies: [] };
    window.__couponPreview = null;
    window.__lastModalSubtotalPreCoupon = 0;

    function normalizePaymentPolicies(rawPolicies) {
        const fallback = [
            { code: 'half', label: 'Sinal de 50% para reserva', percent_now: 50 },
            { code: 'full', label: 'Pagamento 100% Antecipado', percent_now: 100 }
        ];
        if (!Array.isArray(rawPolicies) || rawPolicies.length === 0) return fallback;
        const clean = rawPolicies
            .map((p) => ({
                code: String(p && p.code ? p.code : '').trim().toLowerCase(),
                label: String(p && p.label ? p.label : '').trim(),
                percent_now: Number(p && p.percent_now != null ? p.percent_now : NaN)
            }))
            .filter((p) => p.code && p.label && Number.isFinite(p.percent_now) && p.percent_now > 0)
            .map((p) => ({ ...p, percent_now: Math.min(100, Math.max(0, p.percent_now)) }));
        return clean.length > 0 ? clean : fallback;
    }

    function renderPaymentOptionsUI() {
        const list = document.getElementById('paymentOptionsList');
        if (!list) return;
        const policies = normalizePaymentPolicies(bookingOptions.payment_policies);
        list.innerHTML = policies.map((policy, idx) => {
            const checked = idx === 0 ? 'checked' : '';
            const nowLabel = policy.percent_now >= 100 ? 'Total a debitar agora' : `Total a debitar agora (${policy.percent_now}%):`;
            const note = policy.percent_now >= 100
                ? ''
                : '<span class="payment-option-note">(O restante será pago no check-in)</span>';
            return `
                <label class="payment-option">
                    <input type="radio" name="paymentRule" value="${policy.code}" data-percent-now="${policy.percent_now}" onchange="updatePaymentPreview()" ${checked}>
                    <span class="payment-option-content">
                        <span class="payment-option-title">${policy.label}</span>
                        <span class="payment-option-detail">${nowLabel} R$ <span class="payment-rule-preview" data-policy-code="${policy.code}">0,00</span></span>
                        ${note}
                    </span>
                </label>
            `;
        }).join('');
        if (typeof window.updatePaymentPreview === 'function') {
            window.updatePaymentPreview();
        }
    }

    function getPaymentPolicyByCode(code) {
        const policies = normalizePaymentPolicies(bookingOptions.payment_policies);
        const k = String(code || '').toLowerCase();
        return policies.find((p) => p.code === k) || (k === 'half'
            ? { code: 'half', label: 'Sinal de 50% para reserva', percent_now: 50 }
            : { code: 'full', label: 'Pagamento 100% Antecipado', percent_now: 100 });
    }

    function getSelectedExtrasTotal() {
        const list = document.getElementById('bookingExtrasList');
        if (!list) return 0;
        let s = 0;
        list.querySelectorAll('input[type="checkbox"]:checked').forEach((cb) => {
            s += parseFloat(cb.getAttribute('data-price') || '0') || 0;
        });
        return Math.round(s * 100) / 100;
    }

    function hideOptionalSummaryRows() {
        const ex = document.getElementById('summaryExtrasRow');
        const di = document.getElementById('summaryDiscountRow');
        if (ex) ex.style.display = 'none';
        if (di) di.style.display = 'none';
        window.__lastModalSubtotalPreCoupon = 0;
    }

    function renderBookingOptionsUI() {
        const couponBlock = document.getElementById('bookingCouponBlock');
        const extrasBlock = document.getElementById('bookingExtrasBlock');
        const extrasList = document.getElementById('bookingExtrasList');
        if (couponBlock) {
            couponBlock.style.display = bookingOptions.show_coupon_field ? 'block' : 'none';
        }
        if (!bookingOptions.show_coupon_field) {
            const t = document.getElementById('hasCouponToggle');
            if (t) t.checked = false;
            const w = document.getElementById('bookingCouponFieldsWrap');
            if (w) w.style.display = 'none';
        }
        if (extrasBlock && extrasList) {
            if (bookingOptions.show_extras_section && bookingOptions.extra_services.length > 0) {
                extrasBlock.style.display = 'block';
                extrasList.innerHTML = bookingOptions.extra_services.map((ex) => {
                    const esc = (s) => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
                    return `
                    <label style="display:flex;align-items:flex-start;gap:0.5rem;margin-bottom:0.5rem;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="extra_service" value="${ex.id}" data-price="${ex.price}">
                        <span><strong>${esc(ex.name)}</strong> — R$ ${Number(ex.price).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                        ${ex.description ? `<br><small style="opacity:0.85;">${esc(ex.description)}</small>` : ''}</span>
                    </label>`;
                }).join('');
            } else {
                extrasBlock.style.display = 'none';
                extrasList.innerHTML = '';
            }
        }
        renderPaymentOptionsUI();
    }

    async function loadBookingOptions() {
        try {
            const res = await fetch('api/booking_options.php');
            const data = await res.json();
            bookingOptions = {
                show_coupon_field: !!data.show_coupon_field,
                show_extras_section: !!data.show_extras_section,
                extra_services: Array.isArray(data.extra_services) ? data.extra_services : [],
                payment_policies: normalizePaymentPolicies(data.payment_policies)
            };
        } catch {
            bookingOptions = {
                show_coupon_field: false,
                show_extras_section: false,
                extra_services: [],
                payment_policies: normalizePaymentPolicies([])
            };
        }
        renderBookingOptionsUI();
    }

    // Set minimum dates to today
    const today = new Date().toISOString().split('T')[0];
    const checkinInputs = document.querySelectorAll('input[type="date"]');
    checkinInputs.forEach(input => {
        input.setAttribute('min', today);
    });

    // Make `openBooking` accessible globally for inline onclick
    window.openBooking = function (chaletName) {
        currentChalet = chaletName;
        document.getElementById('modalChaletName').textContent = chaletName;

        // Try to get dates from the availability form if filled
        const checkinInput = document.getElementById('checkin');
        const checkoutInput = document.getElementById('checkout');

        const modalCin = document.getElementById('modalCheckin');
        const modalCout = document.getElementById('modalCheckout');

        if (checkinInput && checkoutInput && checkinInput.value) {
            modalCin.value = checkinInput.value;
            modalCout.value = checkoutInput.value;
        } else {
            // Default dates: tomorrow and after tomorrow
            const tmr = new Date();
            tmr.setDate(tmr.getDate() + 1);
            const dAtm = new Date();
            dAtm.setDate(dAtm.getDate() + 3);

            modalCin.value = tmr.toISOString().split('T')[0];
            modalCout.value = dAtm.toISOString().split('T')[0];
        }

        // Hóspedes: sempre de 1 até max_guests do chalé (API); alinha com a seleção do formulário da CTA quando válida
        const guestsOptionEl = document.getElementById('guestsOption');
        const modalGuestsEl = document.getElementById('modalGuestsOption');
        const chaletByName = allChalets[chaletName];
        const maxGuests = chaletByName && chaletByName.max_guests != null
            ? Math.max(1, parseInt(String(chaletByName.max_guests), 10) || 4)
            : 4;
        const rawTop = guestsOptionEl && guestsOptionEl.value ? guestsOptionEl.value : '';
        const topNum = parseInt(String(rawTop).split('_')[0], 10);
        let preferredModal = String(Math.min(2, maxGuests));
        if (topNum >= 1 && topNum <= maxGuests) {
            preferredModal = String(topNum);
        } else if (topNum > maxGuests) {
            preferredModal = String(maxGuests);
        }
        renderGuestOptions(modalGuestsEl, maxGuests, preferredModal);

        document.querySelectorAll('#bookingExtrasList input[type="checkbox"]').forEach((cb) => { cb.checked = false; });
        const hct = document.getElementById('hasCouponToggle');
        if (hct) hct.checked = false;
        const wrap = document.getElementById('bookingCouponFieldsWrap');
        if (wrap) wrap.style.display = 'none';
        const ci = document.getElementById('couponCodeInput');
        if (ci) ci.value = '';
        const fb = document.getElementById('couponFeedback');
        if (fb) { fb.textContent = ''; fb.style.color = ''; }
        window.__couponPreview = null;

        updateModalSummary();

        // Show modal
        modal.classList.add('active');
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    };

    function closeBookingModal() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (closeModalBtn) {
        closeModalBtn.addEventListener('click', closeBookingModal);
    }

    if (closeAvailabilityModalBtn) {
        closeAvailabilityModalBtn.addEventListener('click', () => {
            availabilityModal.classList.remove('active');
            document.body.style.overflow = '';
        });
    }

    // Chalet Details Modal
    const chaletDetailsModal = document.getElementById('chaletDetailsModal');
    const closeChaletDetailsModalBtn = document.getElementById('closeChaletDetailsModal');

    if (closeChaletDetailsModalBtn) {
        closeChaletDetailsModalBtn.addEventListener('click', () => {
            chaletDetailsModal.classList.remove('active');
            document.body.style.overflow = '';
        });
    }

    // Close modal on click outside
    window.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeBookingModal();
        }
        if (e.target === availabilityModal) {
            availabilityModal.classList.remove('active');
            document.body.style.overflow = '';
        }
        if (e.target === chaletDetailsModal) {
            chaletDetailsModal.classList.remove('active');
            document.body.style.overflow = '';
        }
    });

    function scheduleUpdateModalSummary() {
        if (modalSummaryDebounceTimer) clearTimeout(modalSummaryDebounceTimer);
        modalSummaryDebounceTimer = setTimeout(() => {
            updateModalSummary();
        }, 250);
    }

    document.getElementById('modalCheckin').addEventListener('change', scheduleUpdateModalSummary);
    document.getElementById('modalCheckout').addEventListener('change', scheduleUpdateModalSummary);
    const modalGuestsOptionEl = document.getElementById('modalGuestsOption');
    if (modalGuestsOptionEl) {
        modalGuestsOptionEl.addEventListener('change', scheduleUpdateModalSummary);
    }

    if (modal) {
        modal.addEventListener('change', (e) => {
            if (e.target && e.target.id === 'hasCouponToggle') {
                const wrap = document.getElementById('bookingCouponFieldsWrap');
                if (wrap) wrap.style.display = e.target.checked ? 'block' : 'none';
                window.__couponPreview = null;
                const cfb = document.getElementById('couponFeedback');
                if (cfb) cfb.textContent = '';
                scheduleUpdateModalSummary();
            }
            if (e.target && e.target.matches && e.target.matches('#bookingExtrasList input[type="checkbox"]')) {
                window.__couponPreview = null;
                const cfb = document.getElementById('couponFeedback');
                if (cfb) cfb.textContent = '';
                scheduleUpdateModalSummary();
            }
        });
        modal.addEventListener('click', async (e) => {
            if (!e.target || e.target.id !== 'applyCouponBtn') return;
            const cfb = document.getElementById('couponFeedback');
            const codeInput = document.getElementById('couponCodeInput');
            const toggle = document.getElementById('hasCouponToggle');
            if (!toggle || !toggle.checked) {
                if (cfb) { cfb.textContent = 'Marque "Possui cupom?" e informe o código.'; cfb.style.color = 'var(--danger)'; }
                return;
            }
            const code = codeInput && codeInput.value.trim();
            if (!code) {
                if (cfb) { cfb.textContent = 'Informe o código do cupom.'; cfb.style.color = 'var(--danger)'; }
                return;
            }
            const pre = window.__lastModalSubtotalPreCoupon || 0;
            if (pre <= 0) {
                if (cfb) { cfb.textContent = 'Aguarde o cálculo do valor da estadia.'; cfb.style.color = 'var(--danger)'; }
                return;
            }
            try {
                const res = await fetch('api/validate_coupon.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code, subtotal: pre })
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erro');
                if (data.valid) {
                    window.__couponPreview = { discount: data.discount, preSubtotal: pre, code };
                    if (cfb) {
                        cfb.textContent = `Desconto aplicado: R$ ${Number(data.discount).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
                        cfb.style.color = '#2e7d32';
                    }
                } else {
                    window.__couponPreview = null;
                    if (cfb) {
                        cfb.textContent = data.message || 'Cupom inválido ou expirado.';
                        cfb.style.color = 'var(--danger)';
                    }
                }
                updateModalSummary();
            } catch {
                window.__couponPreview = null;
                if (cfb) { cfb.textContent = 'Não foi possível validar o cupom.'; cfb.style.color = 'var(--danger)'; }
                updateModalSummary();
            }
        });
    }

    async function checkAvailability(chaletId, checkinStr, checkoutStr) {
        if (!chaletId || !checkinStr || !checkoutStr) return false;

        const cacheKey = `${chaletId}|${checkinStr}|${checkoutStr}`;
        if (availabilityCache.has(cacheKey)) {
            return availabilityCache.get(cacheKey);
        }

        try {
            const qs = new URLSearchParams({
                chalet_id: String(chaletId),
                start_date: checkinStr,
                end_date: checkoutStr
            });
            const res = await fetch(`api/availability.php?${qs.toString()}`);
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(data.error || `API ${res.status}`);
            }
            const available = !!data.is_available;
            availabilityCache.set(cacheKey, available);
            return available;
        } catch (error) {
            console.warn("Falha ao consultar disponibilidade em tempo real", error);
            return false;
        }
    }

    async function updateModalSummary() {
        const checkinStr = document.getElementById('modalCheckin').value;
        const checkoutStr = document.getElementById('modalCheckout').value;

        const nightsEl = document.getElementById('summaryNights');
        const lodgingEl = document.getElementById('summaryLodging');
        const extraRow = document.getElementById('summaryExtraGuestsRow');
        const extraLabelEl = document.getElementById('summaryExtraGuestsLabel');
        const extraEl = document.getElementById('summaryExtraGuests');
        const totalEl = document.getElementById('summaryTotal');
        const confirmBtn = document.getElementById('confirmBookingBtn');
        const availMsg = document.getElementById('availabilityMessage');
        const summaryExtrasRow = document.getElementById('summaryExtrasRow');
        const summaryExtrasEl = document.getElementById('summaryExtras');
        const summaryDiscountRow = document.getElementById('summaryDiscountRow');
        const summaryDiscountEl = document.getElementById('summaryDiscount');

        // Reset
        confirmBtn.disabled = true;
        availMsg.style.display = 'none';
        availMsg.className = '';
        availMsg.textContent = '';
        if (extraRow) extraRow.style.display = 'none';
        hideOptionalSummaryRows();

        if (!checkinStr || !checkoutStr) {
            nightsEl.textContent = '0';
            if (lodgingEl) lodgingEl.textContent = 'R$ 0,00';
            if (extraEl) extraEl.textContent = 'R$ 0,00';
            totalEl.textContent = 'R$ 0,00';
            return;
        }

        if (checkinStr >= checkoutStr) {
            availMsg.className = '';
            availMsg.textContent = "Data de Check-out deve ser após o Check-in.";
            availMsg.style.display = 'block';
            nightsEl.textContent = '0';
            if (lodgingEl) lodgingEl.textContent = 'R$ 0,00';
            if (extraEl) extraEl.textContent = 'R$ 0,00';
            totalEl.textContent = 'R$ 0,00';
            return;
        }

        const chalet = allChalets[currentChalet];
        const chaletId = chalet && chalet.id ? chalet.id : null;

        let nights = countNightsBetween(checkinStr, checkoutStr);

        // Verify availability against backend (Confirmada + hold ativo)
        if (!chaletId) {
            availMsg.className = 'availability-alert-unavailable';
            availMsg.innerHTML = '<i class="ph ph-warning"></i> Não foi possível validar a disponibilidade deste chalé.';
            availMsg.style.display = 'flex';
            nightsEl.textContent = '0';
            if (lodgingEl) lodgingEl.textContent = 'R$ 0,00';
            if (extraEl) extraEl.textContent = 'R$ 0,00';
            totalEl.textContent = 'R$ 0,00';
            return;
        }

        const requestId = ++latestAvailabilityRequest;
        const isAvailable = await checkAvailability(chaletId, checkinStr, checkoutStr);
        if (requestId !== latestAvailabilityRequest) {
            return;
        }

        if (!isAvailable) {
            availMsg.className = 'availability-alert-unavailable';
            availMsg.innerHTML = '<i class="ph ph-bell"></i> Infelizmente estas datas acabaram de ser reservadas ou estão em processo de pagamento.';
            availMsg.style.display = 'flex';
            nightsEl.textContent = '0';
            if (lodgingEl) lodgingEl.textContent = 'R$ 0,00';
            if (extraEl) extraEl.textContent = 'R$ 0,00';
            totalEl.textContent = 'R$ 0,00';
            return;
        }

        const guestsOptEl = document.getElementById('modalGuestsOption');
        const guestsVal = guestsOptEl ? guestsOptEl.value : '2';
        const parsedGuests = parseGuestsOption(guestsVal, 1);
        const guestsAdults = parsedGuests.adults;
        const guestsChildren = parsedGuests.children;
        const totalHospedesSelecionados = totalGuestsFromSelection(guestsVal);

        const lodging = computeLodgingSubtotal(chalet, checkinStr, checkoutStr);
        const extraGuest = computeExtraGuestSubtotal(chalet, guestsAdults, guestsChildren, nights);
        const extrasTotal = getSelectedExtrasTotal();
        const baseTotal = Math.round((lodging + extraGuest + extrasTotal) * 100) / 100;
        window.__lastModalSubtotalPreCoupon = baseTotal;

        let discount = 0;
        const pv = window.__couponPreview;
        const couponToggleEl = document.getElementById('hasCouponToggle');
        const useCoupon = bookingOptions.show_coupon_field && couponToggleEl && couponToggleEl.checked;
        if (useCoupon && pv) {
            if (Math.abs(pv.preSubtotal - baseTotal) < 0.02) {
                discount = Math.min(baseTotal, Math.max(0, parseFloat(pv.discount) || 0));
            } else {
                window.__couponPreview = null;
                const cfb = document.getElementById('couponFeedback');
                if (cfb) {
                    cfb.textContent = 'Clique em Aplicar para atualizar o cupom.';
                    cfb.style.color = 'var(--danger)';
                }
            }
        }

        const grand = Math.max(0, Math.round((baseTotal - discount) * 100) / 100);

        nightsEl.textContent = nights;
        if (lodgingEl) lodgingEl.textContent = `R$ ${lodging.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
        if (extraGuest > 0 && extraRow && extraEl && extraLabelEl) {
            const baseG = Math.max(1, parseInt(String(chalet.base_guests ?? 2), 10) || 2);
            const fee = parseFloat(String(chalet.extra_guest_fee ?? 0)) || 0;
            const hospedesExtras = Math.max(0, totalHospedesSelecionados - baseG);
            const extraCount = hospedesExtras;
            extraLabelEl.textContent = `Hóspedes extra (${extraCount} × R$ ${fee.toLocaleString('pt-BR', { minimumFractionDigits: 2 })} × ${nights} noites):`;
            extraEl.textContent = `R$ ${extraGuest.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
            extraRow.style.display = '';
        } else if (extraRow) {
            extraRow.style.display = 'none';
        }

        if (summaryExtrasRow && summaryExtrasEl) {
            if (extrasTotal > 0) {
                summaryExtrasRow.style.display = '';
                summaryExtrasEl.textContent = `R$ ${extrasTotal.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
            } else {
                summaryExtrasRow.style.display = 'none';
            }
        }
        if (summaryDiscountRow && summaryDiscountEl) {
            if (discount > 0) {
                summaryDiscountRow.style.display = '';
                summaryDiscountEl.textContent = `- R$ ${discount.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
            } else {
                summaryDiscountRow.style.display = 'none';
            }
        }

        totalEl.textContent = `R$ ${grand.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
        confirmBtn.disabled = false;

        // Atualiza as opções de pagamento baseadas nas políticas dinâmicas
        if (typeof window.updatePaymentPreview === 'function') {
            window.updatePaymentPreview();
        }
    }

    // Expose updatePaymentPreview to global scope for radio onchange handlers
    window.updatePaymentPreview = function () {
        const totalText = document.getElementById('summaryTotal').textContent;
        const numericTotal = parseFloat(totalText.replace('R$', '').replace(/\./g, '').replace(',', '.').trim()) || 0;
        document.querySelectorAll('#paymentOptionsList .payment-option input[name="paymentRule"]').forEach((input) => {
            const pct = parseFloat(input.getAttribute('data-percent-now') || '100') || 100;
            const amount = (numericTotal * pct) / 100;
            const code = input.value;
            const preview = document.querySelector(`#paymentOptionsList .payment-rule-preview[data-policy-code="${code}"]`);
            if (preview) preview.textContent = amount.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
        });
    }

    function formatDate(dateString) {
        // YYYY-MM-DD to DD/MM/YYYY
        const [y, m, d] = dateString.split('-');
        return `${d}/${m}/${y}`;
    }

    // Handle Availability Button Click (from CTA section)
    const btnVerifyAvailability = document.getElementById('btnVerifyAvailability');
    if (btnVerifyAvailability) {
        btnVerifyAvailability.addEventListener('click', () => {
            const checkin = document.getElementById('checkin').value;
            const checkout = document.getElementById('checkout').value;

            if (!checkin || !checkout) {
                alert("Por favor, preencha as datas de Check-in e Check-out.");
                return;
            }

            if (checkin >= checkout) {
                alert("A data de Check-out deve ser posterior ao Check-in.");
                return;
            }

            // Set dates in the modal header
            const headerEl = document.getElementById('availabilityModalDates');
            if (headerEl) {
                headerEl.textContent = `Check-in: ${formatDate(checkin)} | Check-out: ${formatDate(checkout)}`;
            }

            // Generate and Render Chalets inside the modal
            renderAvailabilityModal(checkin, checkout);

            // Show modal
            if (availabilityModal) {
                availabilityModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        });
    }

    async function renderAvailabilityModal(filterCheckin, filterCheckout) {
        const grid = document.getElementById('availabilityGrid');
        grid.innerHTML = '<p style="text-align:center;width:100%;grid-column:1/-1;">Procurando opções...</p>';

        try {
            // Utilizando o cache local (apenas índices numéricos para evitar duplicatas)
            let chalets = Object.keys(allChalets)
                .filter(k => /^\d+$/.test(k))
                .map(k => allChalets[parseInt(k, 10)]);

            if (chalets.length === 0) {
                const res = await fetch('api/chalets.php');
                chalets = await res.json();
                chalets.forEach((c, i) => { allChalets[i] = c; allChalets[c.name] = c; });
            }

            if (Array.isArray(chalets) && chalets.length > 0) {
                grid.innerHTML = '';
                let countDisp = 0;

                for (const [idx, chalet] of chalets.entries()) {
                    // Check availability
                    if (chalet.id && filterCheckin && filterCheckout) {
                        const available = await checkAvailability(chalet.id, filterCheckin, filterCheckout);
                        if (!available) {
                            continue; // Pula
                        }
                    }

                    countDisp++;

                    // Resolve imagens (Main + Galeria)
                    const imgIndex = (idx % 3) + 1;
                    const fallbackImg = `images/chalet${imgIndex}.png`;
                    let mainImg = isUsableImageSrc(chalet.main_image) ? chalet.main_image : fallbackImg;

                    let galleryImgs = [mainImg];
                    if (chalet.images && Array.isArray(chalet.images)) {
                        galleryImgs = [...new Set([mainImg, ...chalet.images.filter(isUsableImageSrc)])];
                    }

                    // Determina a badge baseado na etiqueta configurada
                    let badgeHtml = '';
                    if (chalet.badge && chalet.badge.trim() !== '') {
                        badgeHtml = `<div class="badge">${chalet.badge}</div>`;
                    }

                    const nights = countNightsBetween(filterCheckin, filterCheckout);
                    const guestsOptTop = document.getElementById('guestsOption');
                    const gv = guestsOptTop && guestsOptTop.value ? guestsOptTop.value : '2';
                    const parsedTopGuests = parseGuestsOption(gv, 1);
                    const gAdults = parsedTopGuests.adults;
                    const gChildren = parsedTopGuests.children;
                    const lodgingPart = computeLodgingSubtotal(chalet, filterCheckin, filterCheckout);
                    const extraPart = computeExtraGuestSubtotal(chalet, gAdults, gChildren, nights);
                    const totalPrice = Math.round((lodgingPart + extraPart) * 100) / 100;

                    const displayPrice = totalPrice.toLocaleString('pt-BR', { minimumFractionDigits: 0 });

                    // Monta HTML do Slider 
                    let sliderHtml = `<div class="chalet-slider" id="modal-slider-${chalet.id}" style="aspect-ratio: 16/9;">`;
                    galleryImgs.forEach((src, i) => {
                        sliderHtml += buildSlideMarkup(src, chalet.name, i === 0);
                    });
                    if (galleryImgs.length > 1) {
                        sliderHtml += `
                            <button class="slider-btn prev" onclick="nextSlide(${chalet.id}, -1, event)"><i class="ph ph-caret-left"></i></button>
                            <button class="slider-btn next" onclick="nextSlide(${chalet.id}, 1, event)"><i class="ph ph-caret-right"></i></button>
                        `;
                    }
                    sliderHtml += badgeHtml + `</div>`;

                    const card = document.createElement('div');
                    card.className = 'chalet-card glass-card';
                    card.innerHTML = `
                        ${sliderHtml}
                        <div class="chalet-content">
                            <h3>${chalet.name}</h3>
                            <ul class="chalet-features" style="margin-bottom:0.5rem">
                                <li><i class="ph ph-house"></i> ${chalet.type}</li>
                            </ul>
                            <div class="chalet-footer" style="margin-top:0.5rem">
                                <div class="price">
                                    <span>Total (${nights} ${nights > 1 ? 'Noites' : 'Noite'})</span>
                                    <strong>R$ ${displayPrice}</strong>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    <button class="btn btn-outline btn-sm" onclick="
                                        document.getElementById('availabilityModal').classList.remove('active');
                                        document.body.style.overflow = '';
                                        openChaletDetails(${idx});
                                    ">Saber mais</button>
                                    <button class="btn btn-primary btn-sm" onclick="
                                        document.getElementById('availabilityModal').classList.remove('active');
                                        document.getElementById('checkin').value = '${filterCheckin}';
                                        document.getElementById('checkout').value = '${filterCheckout}';
                                        openBooking('${chalet.name}'); 
                                    ">Reservar</button>
                                </div>
                            </div>
                        </div>
                    `;
                    grid.appendChild(card);
                }

                if (countDisp === 0) {
                    grid.innerHTML = '<p style="text-align:center;width:100%;grid-column:1/-1;">Nenhum chalé disponível para as datas selecionadas. Tente em outro período!</p>';
                }
            } else {
                grid.innerHTML = '<p style="text-align:center;width:100%;grid-column:1/-1;">Nenhum chalé cadastrado no momento.</p>';
            }
        } catch (e) {
            console.error("Erro ao carregar chalés modal:", e);
            grid.innerHTML = '<p style="text-align:center;width:100%;grid-column:1/-1;color:red;">Falha ao obter dados.</p>';
        }
    }

    // Handle Final Booking Form Submit (Mock)
    if (finalBookingForm) {
        finalBookingForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const submitBtn = finalBookingForm.querySelector('button[type="submit"]');
            submitBtn.textContent = 'Processando Reserva...';
            submitBtn.disabled = true;

            const checkin = document.getElementById('modalCheckin').value;
            const checkout = document.getElementById('modalCheckout').value;
            const name = document.getElementById('bookingName').value;
            const email = document.getElementById('bookingEmail').value;
            const phone = document.getElementById('bookingPhone').value;
            const totalText = document.getElementById('summaryTotal').textContent;

            const paymentRuleInput = document.querySelector('input[name="paymentRule"]:checked');
            const paymentRule = paymentRuleInput ? paymentRuleInput.value : 'full';

            const reserva = {
                clientName: name,
                clientEmail: email,
                clientPhone: phone,
                chaletName: currentChalet,
                checkin: checkin,
                checkout: checkout,
                total: totalText
            };

            const guestsOptEl = document.getElementById('modalGuestsOption');
            const guestsVal = guestsOptEl ? guestsOptEl.value : '2';
            const parsedGuests = parseGuestsOption(guestsVal, 1);
            const guestsAdults = parsedGuests.adults;
            const guestsChildren = parsedGuests.children;

            // Extrair Dados do Formulário (chalet_id quando disponível para maior confiabilidade)
            const chaletForReserva = allChalets[reserva.chaletName];
            const formDados = {
                guest_name: reserva.clientName,
                guest_email: reserva.clientEmail,
                guest_phone: reserva.clientPhone,
                guests_adults: guestsAdults,
                guests_children: guestsChildren,
                chalet_name: reserva.chaletName,
                checkin_date: reserva.checkin,
                checkout_date: reserva.checkout,
                payment_rule: paymentRule,
                status: 'Aguardando Pagamento' // Novo status inicial
            };
            if (chaletForReserva && chaletForReserva.id) formDados.chalet_id = chaletForReserva.id;

            const hasCouponEl = document.getElementById('hasCouponToggle');
            if (hasCouponEl && hasCouponEl.checked) {
                const cc = document.getElementById('couponCodeInput') && document.getElementById('couponCodeInput').value.trim();
                if (cc) formDados.coupon_code = cc;
            }
            const extraIds = [];
            document.querySelectorAll('#bookingExtrasList input[type="checkbox"]:checked').forEach((cb) => {
                const id = parseInt(cb.value, 10);
                if (id > 0) extraIds.push(id);
            });
            if (extraIds.length) formDados.extra_service_ids = extraIds;

            // 1. Salvar Intenção de Reserva no Banco via PHP API (Aguardando Pagamento)
            let reservationId = null;
            try {
                const resDb = await fetch('api/reservations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formDados)
                });
                const dbData = await resDb.json().catch(() => ({}));
                if (!resDb.ok) {
                    alert(dbData.error || 'Não foi possível registrar a reserva. Verifique os dados e tente novamente.');
                    submitBtn.textContent = 'Confirmar Reserva e Pagar';
                    submitBtn.disabled = false;
                    return;
                }
                reservationId = dbData.id;
            } catch (e) {
                console.error(e);
                alert("Houve uma falha ao registrar sua reserva. Tente novamente.");
                submitBtn.textContent = 'Confirmar Reserva';
                submitBtn.disabled = false;
                return;
            }

            // 2. Integração MercadoPago (gera preferência no backend e redireciona)
            const mpSuccess = await createMercadoPagoPreference(reservationId);

            if (!mpSuccess) {
                // MercadoPago falhou ou não está configurado - NÃO confirmar reserva nem enviar notificações
                submitBtn.textContent = 'Confirmar Reserva e Pagar';
                submitBtn.disabled = false;
                // Alerta já exibido em createMercadoPagoPreference quando aplicável
            } else {
                // Redirecionando para tela de pagamento do Mercado Pago
                submitBtn.textContent = 'Redirecionando...';
            }
        });
    }

    /* =========================================
       MERCADOPAGO INTEGRATION LOGIC
       ========================================= */
    async function createMercadoPagoPreference(reservationId) {
        try {
            const response = await fetch('api/create_preference.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ reservation_id: reservationId })
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                console.error("Erro ao criar preferência no backend:", data);
                alert("Houve um erro com o gateway de pagamento. Confirme as chaves e tente novamente.");
                return false;
            }

            if (data && data.init_point) {
                window.location.href = data.init_point;
                return true;
            }

            console.error("Resposta inválida ao criar preferência:", data);
            alert("Não foi possível iniciar o checkout do Mercado Pago.");
        } catch (error) {
            console.error("Erro ao comunicar com o backend de pagamento", error);
            alert("Erro de conexão com o MercadoPago.");
        }

        return false;
    }

    /* =========================================
       DYNAMIC DATA LOAD (API)
       ========================================= */
    const chaletsGrid = document.getElementById('chaletsGrid');

    async function loadChalets(filterCheckin = null, filterCheckout = null) {
        if (!chaletsGrid) return;
        try {
            const res = await fetch('api/chalets.php');
            const chalets = await res.json();

            if (Array.isArray(chalets) && chalets.length > 0) {
                chaletsGrid.innerHTML = ''; // Limpa "Carregando..."

                let countDisp = 0;

                for (const [idx, chalet] of chalets.entries()) {
                    // Armazena por índice (openChaletDetails) e por nome (updateModalSummary)
                    allChalets[idx] = chalet;
                    allChalets[chalet.name] = chalet;

                    // Se foi pedido filtro, ignora os indisponíveis
                    if (filterCheckin && filterCheckout && chalet.id) {
                        const available = await checkAvailability(chalet.id, filterCheckin, filterCheckout);
                        if (!available) {
                            continue; // Pula este chalé
                        }
                    }

                    countDisp++;

                    // Resolve imagens (Main + Galeria)
                    const imgIndex = (idx % 3) + 1;
                    const fallbackImg = `images/chalet${imgIndex}.png`;
                    let mainImg = isUsableImageSrc(chalet.main_image) ? chalet.main_image : fallbackImg;

                    let galleryImgs = [mainImg];
                    if (chalet.images && Array.isArray(chalet.images)) {
                        galleryImgs = [...new Set([mainImg, ...chalet.images.filter(isUsableImageSrc)])];
                    }

                    // Determina a badge baseado na etiqueta configurada
                    let badgeHtml = '';
                    if (chalet.badge && chalet.badge.trim() !== '') {
                        badgeHtml = `<div class="badge">${chalet.badge}</div>`;
                    }

                    // Formatar Preço Base (A partir de)
                    const displayPrice = parseFloat(chalet.price).toLocaleString('pt-BR', { minimumFractionDigits: 0 });

                    // Monta HTML do Slider
                    let sliderHtml = `<div class="chalet-slider" id="slider-${chalet.id}">`;
                    galleryImgs.forEach((src, i) => {
                        sliderHtml += buildSlideMarkup(src, chalet.name, i === 0);
                    });
                    if (galleryImgs.length > 1) {
                        sliderHtml += `
                            <button class="slider-btn prev" onclick="nextSlide(${chalet.id}, -1, event)"><i class="ph ph-caret-left"></i></button>
                            <button class="slider-btn next" onclick="nextSlide(${chalet.id}, 1, event)"><i class="ph ph-caret-right"></i></button>
                        `;
                    }
                    sliderHtml += badgeHtml + `</div>`;

                    const card = document.createElement('div');
                    card.className = 'chalet-card glass-card';
                    card.innerHTML = `
                        ${sliderHtml}
                        <div class="chalet-content">
                            <h3>${chalet.name}</h3>
                            <p>${chalet.description || 'Um refúgio perfeito na natureza.'}</p>
                            <ul class="chalet-features">
                                <li><i class="ph ph-house"></i> ${chalet.type}</li>
                            </ul>
                            <div class="chalet-footer">
                                <div class="price">
                                    <span>A partir de</span>
                                    <strong>R$ ${displayPrice}<small>/noite</small></strong>
                                </div>
                                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                    <button class="btn btn-outline btn-sm" onclick="openChaletDetails(${idx})" style="flex: 1;">Saber Mais</button>
                                    <button class="btn btn-primary btn-sm" onclick="openBooking('${chalet.name.replace(/'/g, "\\'")}')" style="flex: 1;">Reservar</button>
                                </div>
                            </div>
                        </div>
                    `;
                    chaletsGrid.appendChild(card);
                }

                const guestsTop = document.getElementById('guestsOption');
                if (guestsTop && chalets.length > 0) {
                    const globalMax = Math.max(
                        1,
                        ...chalets.map((c) => Math.max(1, parseInt(String(c.max_guests ?? 4), 10) || 4))
                    );
                    const prev = guestsTop.value;
                    renderGuestOptions(guestsTop, globalMax, prev || String(Math.min(2, globalMax)));
                }

                if (countDisp === 0) {
                    chaletsGrid.innerHTML = '<p style="text-align:center;width:100%;grid-column:1/-1;">Nenhum chalé disponível para as datas selecionadas.</p>';
                }
            } else {
                chaletsGrid.innerHTML = '<p style="text-align:center;width:100%;grid-column:1/-1;">Nenhum chalé disponível no momento.</p>';
            }
        } catch (e) {
            console.error("Erro ao carregar chalés:", e);
            chaletsGrid.innerHTML = '<p style="text-align:center;width:100%;grid-column:1/-1;color:red;">Falha ao conectar com o servidor.</p>';
        }
    }

    /* =========================================
       EVOLUTION API INTEGRATION LOGIC
       ========================================= */
    async function sendEvolutionWebhooks(reserva) {
        try {
            const res = await fetch('api/send_webhook.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
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
                })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                console.warn("Webhook:", data.error || "Falha ao enviar mensagens");
            }
        } catch (e) {
            console.error("Erro ao enviar mensagens de confirmação:", e);
        }
    }

    function showToast() {
        if (!toast) return;

        toast.classList.add('show');

        setTimeout(() => {
            toast.classList.remove('show');
        }, 4000);
    }

    async function loadSettings() {
        if (window.__INITIAL_CUSTOMIZATION) return; // Página já renderizada no servidor (index.php) - evita piscar
        try {
            const res = await fetch('api/settings.php');
            if (!res.ok) throw new Error('API retornou ' + res.status);
            let data = await res.json();
            if (Array.isArray(data)) data = {};

            // Social Links
            if (data.socialSettings) {
                const social = data.socialSettings;
                const socialContainer = document.querySelector('.social-links');
                if (socialContainer) {
                    let html = '';
                    if (social.instagram) html += `<a href="${social.instagram}" target="_blank"><i class="ph ph-instagram-logo"></i></a>`;
                    if (social.facebook) html += `<a href="${social.facebook}" target="_blank"><i class="ph ph-facebook-logo"></i></a>`;
                    if (social.tripadvisor) html += `<a href="${social.tripadvisor}" target="_blank"><i class="ph ph-whatsapp-logo"></i></a>`;

                    if (html) {
                        socialContainer.innerHTML = html;
                    }
                }
            }

            // Company Logo
            if (data.company_logo) {
                const headerLogo = document.querySelector('.navbar .logo');
                if (headerLogo) {
                    headerLogo.innerHTML = `<img src="${data.company_logo_light || data.company_logo}" alt="Recantos da Serra Logo" style="height: 40px;" data-light="${data.company_logo_light || data.company_logo}" data-dark="${data.company_logo}">`;
                    window.dispatchEvent(new Event('scroll'));
                }
                const footerLogo = document.querySelector('.footer-brand .logo');
                if (footerLogo) {
                    // Footer usually has a dark background, so prefer the light logo if available
                    const logoSrc = data.company_logo_light ? data.company_logo_light : data.company_logo;
                    footerLogo.innerHTML = `<img src="${logoSrc}" alt="Recantos da Serra Logo" style="height: 50px;">`;
                }
                const aboutLogo = document.getElementById('aboutSectionLogo');
                if (aboutLogo) {
                    // About section has light background, use standard logo
                    aboutLogo.innerHTML = `<img src="${data.company_logo}" alt="Recantos da Serra Logo" style="max-height: 80px; width: auto;">`;
                }
            }

            // Customization (Hero, About, Amenities)
            if (data.customization && typeof data.customization === 'object') {
                const custom = data.customization;
                const $ = (id) => document.getElementById(id);

                // Favicon
                const faviconEl = $('clientFavicon');
                if (faviconEl && custom.favicon) {
                    faviconEl.href = custom.favicon;
                    faviconEl.type = custom.favicon.endsWith('.png') ? 'image/png' : (custom.favicon.endsWith('.svg') ? 'image/svg+xml' : 'image/x-icon');
                }

                // Hero
                if ($('clientHeroTitle') && custom.heroTitle) $('clientHeroTitle').innerHTML = custom.heroTitle;
                if ($('clientHeroSubtitle') && custom.heroSubtitle) $('clientHeroSubtitle').innerHTML = custom.heroSubtitle;
                const heroImages = custom.heroImages || (custom.heroImage ? [custom.heroImage] : ['images/hero.png']);
                initHeroSlideshow(heroImages);

                // About
                if ($('clientAboutTitle') && custom.aboutTitle) $('clientAboutTitle').innerHTML = custom.aboutTitle;
                if ($('clientAboutText') && custom.aboutText) {
                    const formattedText = custom.aboutText.split('\n').filter(p => p.trim() !== '').map(p => `<p>${p}</p>`).join('');
                    $('clientAboutText').innerHTML = formattedText;
                }
                if ($('clientAboutImage') && custom.aboutImage) {
                    $('clientAboutImage').src = custom.aboutImage;
                    $('clientAboutImage').onerror = function () {
                        this.style.display = 'none';
                        const holder = document.getElementById('clientAboutImageFallback') || document.createElement('div');
                        holder.id = 'clientAboutImageFallback';
                        holder.className = 'image-fallback';
                        holder.style.width = '100%';
                        holder.style.height = '100%';
                        holder.style.minHeight = '320px';
                        holder.style.background = 'var(--secondary-color)';
                        if (!holder.parentNode && this.parentNode) this.parentNode.appendChild(holder);
                    };
                }

                // Chalets section header
                if ($('clientChaletsSubtitle') && custom.chaletsSubtitle) $('clientChaletsSubtitle').innerHTML = custom.chaletsSubtitle;
                if ($('clientChaletsTitle') && custom.chaletsTitle) $('clientChaletsTitle').innerHTML = custom.chaletsTitle;
                if ($('clientChaletsDesc') && custom.chaletsDesc) $('clientChaletsDesc').innerHTML = custom.chaletsDesc;

                // Amenities
                if ($('clientFeat1Title') && custom.feat1Title) $('clientFeat1Title').innerHTML = custom.feat1Title;
                if ($('clientFeat1Desc') && custom.feat1Desc) $('clientFeat1Desc').innerHTML = custom.feat1Desc;
                if ($('clientFeat2Title') && custom.feat2Title) $('clientFeat2Title').innerHTML = custom.feat2Title;
                if ($('clientFeat2Desc') && custom.feat2Desc) $('clientFeat2Desc').innerHTML = custom.feat2Desc;
                if ($('clientFeat3Title') && custom.feat3Title) $('clientFeat3Title').innerHTML = custom.feat3Title;
                if ($('clientFeat3Desc') && custom.feat3Desc) $('clientFeat3Desc').innerHTML = custom.feat3Desc;
                if ($('clientFeat4Title') && custom.feat4Title) $('clientFeat4Title').innerHTML = custom.feat4Title;
                if ($('clientFeat4Desc') && custom.feat4Desc) $('clientFeat4Desc').innerHTML = custom.feat4Desc;
                if ($('clientFeat5Title') && custom.feat5Title) $('clientFeat5Title').innerHTML = custom.feat5Title;
                if ($('clientFeat5Desc') && custom.feat5Desc) $('clientFeat5Desc').innerHTML = custom.feat5Desc;

                // Testimonials
                if ($('testi1Name') && custom.testi1Name) $('testi1Name').innerHTML = custom.testi1Name;
                if ($('testi1Location') && custom.testi1Location) $('testi1Location').innerHTML = custom.testi1Location;
                if ($('testi1Text') && custom.testi1Text) $('testi1Text').innerHTML = `"${custom.testi1Text}"`;
                if ($('testi1Img') && custom.testi1Image) $('testi1Img').src = custom.testi1Image;

                if ($('testi2Name') && custom.testi2Name) $('testi2Name').innerHTML = custom.testi2Name;
                if ($('testi2Location') && custom.testi2Location) $('testi2Location').innerHTML = custom.testi2Location;
                if ($('testi2Text') && custom.testi2Text) $('testi2Text').innerHTML = `"${custom.testi2Text}"`;
                if ($('testi2Img') && custom.testi2Image) $('testi2Img').src = custom.testi2Image;

                if ($('testi3Name') && custom.testi3Name) $('testi3Name').innerHTML = custom.testi3Name;
                if ($('testi3Location') && custom.testi3Location) $('testi3Location').innerHTML = custom.testi3Location;
                if ($('testi3Text') && custom.testi3Text) $('testi3Text').innerHTML = `"${custom.testi3Text}"`;
                if ($('testi3Img') && custom.testi3Image) $('testi3Img').src = custom.testi3Image;

                // Location
                if ($('locAddress') && custom.locAddress) $('locAddress').innerHTML = custom.locAddress;
                if ($('locCar') && custom.locCar) $('locCar').innerHTML = custom.locCar;
                if ($('locMapLink') && custom.locMapLink) $('locMapLink').href = custom.locMapLink;

                // Floating WhatsApp
                if (custom.waNumber) {
                    const waBtn = $('floatingWaBtn');
                    if (waBtn) {
                        let textParams = '';
                        if (custom.waMessage) textParams = `?text=${encodeURIComponent(custom.waMessage)}`;
                        waBtn.href = `https://wa.me/${custom.waNumber}${textParams}`;
                        waBtn.style.display = 'flex';
                    }
                }

                // Footer
                if ($('footerDesc') && custom.footerDesc) $('footerDesc').innerHTML = custom.footerDesc;
                if ($('footerAddress') && custom.footerAddress) $('footerAddress').innerHTML = custom.footerAddress;
                if ($('footerEmail') && custom.footerEmail) $('footerEmail').innerHTML = custom.footerEmail;
                if ($('footerPhone') && custom.footerPhone) $('footerPhone').innerHTML = custom.footerPhone;
                if ($('footerCopyright') && custom.footerCopyright) $('footerCopyright').innerHTML = custom.footerCopyright;
            } else {
                initHeroSlideshow(['images/hero.png']);
            }

        } catch (e) {
            console.warn("Failed to load settings logo", e);
            initHeroSlideshow(['images/hero.png']);
        }
    }

    let heroSlideshowInterval = null;
    let heroCurrentIndex = 0;

    function initHeroSlideshow(images) {
        const container = document.getElementById('heroSlideshow');
        const btnPrev = document.getElementById('heroNavPrev');
        const btnNext = document.getElementById('heroNavNext');
        if (!container || !Array.isArray(images) || images.length === 0) return;

        if (heroSlideshowInterval) clearInterval(heroSlideshowInterval);

        const usableImages = images.filter(isUsableImageSrc);
        if (usableImages.length === 0) {
            container.innerHTML = `<div class="hero-slide active image-fallback" data-index="0" style="background: var(--secondary-color);"></div>`;
            if (btnPrev) btnPrev.classList.add('hidden');
            if (btnNext) btnNext.classList.add('hidden');
            return;
        }
        container.innerHTML = usableImages.map((src, i) =>
            `<div class="hero-slide ${i === 0 ? 'active' : ''}" style="background-image: url('${src}');" data-index="${i}"></div>`
        ).join('');

        heroCurrentIndex = 0;

        if (usableImages.length < 2) {
            if (btnPrev) btnPrev.classList.add('hidden');
            if (btnNext) btnNext.classList.add('hidden');
            return;
        }

        if (btnPrev) btnPrev.classList.remove('hidden');
        if (btnNext) btnNext.classList.remove('hidden');

        function goToSlide(index) {
            const slides = container.querySelectorAll('.hero-slide');
            slides[heroCurrentIndex].classList.remove('active');
            heroCurrentIndex = (index + slides.length) % slides.length;
            slides[heroCurrentIndex].classList.add('active');
        }

        if (btnPrev) btnPrev.onclick = () => { goToSlide(heroCurrentIndex - 1); resetHeroInterval(); };
        if (btnNext) btnNext.onclick = () => { goToSlide(heroCurrentIndex + 1); resetHeroInterval(); };

        function resetHeroInterval() {
            if (heroSlideshowInterval) clearInterval(heroSlideshowInterval);
            heroSlideshowInterval = setInterval(() => goToSlide(heroCurrentIndex + 1), 5000);
        }

        heroSlideshowInterval = setInterval(() => goToSlide(heroCurrentIndex + 1), 5000);
    }

    // Initialize - usar dados iniciais do PHP se disponível, depois carregar settings (logo/social) e chalés
    (async function init() {
        const initial = window.__INITIAL_CUSTOMIZATION;
        if (initial && initial.heroImages && initial.heroImages.length) {
            initHeroSlideshow(initial.heroImages);
        }
        await loadSettings();
        loadBookingOptions();
        loadChalets();
    })();

    // Global Lightbox and Slider Functions
    window.nextSlide = function (chaletId, direction, event) {
        event.stopPropagation();
        const slider = document.getElementById(`slider-${chaletId}`) || document.getElementById(`modal-slider-${chaletId}`);
        if (!slider) return;

        const slides = slider.querySelectorAll('.slide');
        let activeIndex = 0;
        slides.forEach((slide, index) => {
            if (slide.classList.contains('active')) activeIndex = index;
            slide.classList.remove('active');
        });

        let nextIndex = activeIndex + direction;
        if (nextIndex < 0) nextIndex = slides.length - 1;
        if (nextIndex >= slides.length) nextIndex = 0;

        slides[nextIndex].classList.add('active');
    };

    window.openLightbox = function (src) {
        let overlay = document.getElementById('globalLightbox');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'globalLightbox';
            overlay.className = 'lightbox-overlay';
            overlay.innerHTML = `
                <span class="lightbox-close" onclick="this.parentElement.style.display='none'"><i class="ph ph-x"></i></span>
                <img src="" class="lightbox-img" id="lightboxImage">
            `;
            document.body.appendChild(overlay);
        }
        document.getElementById('lightboxImage').src = src;
        overlay.style.display = 'flex';
    };

    window.openChaletDetails = function (idx) {
        const chalet = allChalets[idx];
        if (!chalet) return;

        // Set Texts
        document.getElementById('chaletDetailsName').textContent = chalet.name;
        document.getElementById('chaletDetailsType').textContent = chalet.type;
        document.getElementById('chaletDetailsPrice').textContent = parseFloat(chalet.price).toLocaleString('pt-BR', { minimumFractionDigits: 0 });

        let descHtml = chalet.full_description || chalet.description || 'Nenhum detalhe adicional informado sobre este refúgio.';
        document.getElementById('chaletDetailsFullDescription').innerHTML = descHtml;

        // Set Hero Background
        const fallbackImg = 'images/chalet1.png';
        const modalHeroSrc = isUsableImageSrc(chalet.main_image) ? chalet.main_image : fallbackImg;
        const heroEl = document.getElementById('chaletDetailsHero');
        if (heroEl) {
            if (isUsableImageSrc(modalHeroSrc)) {
                heroEl.style.backgroundImage = `url('${modalHeroSrc}')`;
                heroEl.style.backgroundColor = '';
            } else {
                heroEl.style.backgroundImage = 'none';
                heroEl.style.backgroundColor = 'var(--secondary-color)';
            }
        }

        // Set Badge
        const badgeEl = document.getElementById('chaletDetailsBadge');
        if (chalet.badge && chalet.badge.trim() !== '') {
            badgeEl.textContent = chalet.badge;
            badgeEl.style.display = 'block';
        } else {
            badgeEl.style.display = 'none';
        }

        // Configure CTA Button action inside Modal
        const btnBook = document.getElementById('bookThisChaletBtn');
        btnBook.onclick = function () {
            document.getElementById('chaletDetailsModal').classList.remove('active');
            openBooking(chalet.name);
        };

        // Show modal
        const detailsModal = document.getElementById('chaletDetailsModal');
        detailsModal.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    // =========================================
    // POST-PAYMENT (MERCADO PAGO RETURN) CHECK
    // =========================================
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('payment_success') === 'true' && urlParams.get('reservation_id')) {
        const resId = urlParams.get('reservation_id');

        // Remove params from URL to avoid re-triggering
        window.history.replaceState({}, document.title, window.location.pathname);

        // Show loading toast
        const toast = document.getElementById('successToast');
        if (toast) {
            toast.querySelector('span').textContent = 'Confirmando pagamento...';
            toast.classList.add('show');
        }

        // Update to Confirmada and Fire Webhooks
        (async () => {
            try {
                const resUpdate = await fetch(`api/reservations.php?id=${resId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ status: 'Confirmada' })
                });

                if (resUpdate.ok) {
                    // Fetch details to send Webhooks
                    const getRes = await fetch(`api/reservations.php?id=${resId}`);
                    if (getRes.ok) {
                        const resData = await getRes.json();
                        const totalNum = parseFloat(resData.total_amount) || 0;
                        const policy = getPaymentPolicyByCode(resData.payment_rule || 'full');
                        const pct = Number(policy.percent_now || 100);
                        const valorPagoNum = (totalNum * pct) / 100;
                        const totalFmt = 'R$ ' + totalNum.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
                        const valorPagoFmt = 'R$ ' + valorPagoNum.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
                        const legacyData = {
                            clientName: resData.guest_name,
                            clientEmail: resData.guest_email,
                            clientPhone: resData.guest_phone,
                            chaletName: resData.chalet_name,
                            checkin: resData.checkin_date,
                            checkout: resData.checkout_date,
                            total: totalFmt,
                            valorPago: valorPagoFmt,
                            condicao: policy.label || `${pct}% no ato da reserva`,
                            paymentRule: resData.payment_rule || 'full',
                            id: resData.id
                        };
                        sendEvolutionWebhooks(legacyData);
                    }

                    if (toast) {
                        toast.querySelector('span').textContent = 'Pagamento Confirmado! Reserva Aprovada.';
                        setTimeout(() => toast.classList.remove('show'), 5000);
                    } else {
                        alert("Pagamento Confirmado! Reserva Aprovada.");
                    }
                } else {
                    if (toast) toast.classList.remove('show');
                    alert("Pagamento recebido, mas houve um erro ao atualizar a reserva. Por favor, contate o suporte.");
                }
            } catch (e) {
                console.error("Erro na confirmação pós-pagamento:", e);
                if (toast) toast.classList.remove('show');
            }
        })();
    } else if ((urlParams.get('payment_failed') === 'true' || urlParams.get('payment_pending') === 'true') && urlParams.get('reservation_id')) {
        const resId = urlParams.get('reservation_id');
        window.history.replaceState({}, document.title, window.location.pathname);

        // Cancelar reserva para liberar as datas para outras pessoas
        (async () => {
            try {
                await fetch(`api/reservations.php?id=${resId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ status: 'Cancelada' })
                });
            } catch (e) {
                console.error("Erro ao cancelar reserva", e);
            }
        })();

        alert("O pagamento não foi concluído. A reserva foi cancelada e as datas estão disponíveis para nova reserva.");
    }
});
