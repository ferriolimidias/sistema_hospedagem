document.addEventListener('DOMContentLoaded', () => {
    // Shared State
    let allChalets = {};
    const availabilityCache = new Map();
    let latestAvailabilityRequest = 0;
    let currentChalet = 'Chalé Alpino';

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

        // Sincroniza opção de hóspedes do formulário inicial para o modal
        const guestsOptionEl = document.getElementById('guestsOption');
        const modalGuestsEl = document.getElementById('modalGuestsOption');
        if (guestsOptionEl && modalGuestsEl) modalGuestsEl.value = guestsOptionEl.value;

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
        const totalEl = document.getElementById('summaryTotal');
        const confirmBtn = document.getElementById('confirmBookingBtn');
        const availMsg = document.getElementById('availabilityMessage');

        // Reset
        confirmBtn.disabled = true;
        availMsg.style.display = 'none';
        availMsg.className = '';
        availMsg.textContent = '';

        if (!checkinStr || !checkoutStr) {
            nightsEl.textContent = '0';
            totalEl.textContent = 'R$ 0,00';
            return;
        }

        if (checkinStr >= checkoutStr) {
            availMsg.className = '';
            availMsg.textContent = "Data de Check-out deve ser após o Check-in.";
            availMsg.style.display = 'block';
            nightsEl.textContent = '0';
            totalEl.textContent = 'R$ 0,00';
            return;
        }

        const chalet = allChalets[currentChalet];
        const chaletId = chalet && chalet.id ? chalet.id : null;
        const basePrice = chalet && chalet.price != null ? parseFloat(chalet.price) : 0;

        let nights = 1;
        let total = 0;

        // Verify availability against backend (Confirmada + hold ativo)
        if (!chaletId) {
            availMsg.className = 'availability-alert-unavailable';
            availMsg.innerHTML = '<i class="ph ph-warning"></i> Não foi possível validar a disponibilidade deste chalé.';
            availMsg.style.display = 'flex';
            nightsEl.textContent = '0';
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
            totalEl.textContent = 'R$ 0,00';
            return;
        }

        // Calculate nights and price
        const partsIn = checkinStr.split('-');
        const partsOut = checkoutStr.split('-');
        const cin = new Date(partsIn[0], partsIn[1] - 1, partsIn[2]);
        const cout = new Date(partsOut[0], partsOut[1] - 1, partsOut[2]);

        const diffTime = cout - cin;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        nights = diffDays > 0 ? diffDays : 1;

        for (let i = 0; i < nights; i++) {
            let currentDate = new Date(cin);
            currentDate.setDate(currentDate.getDate() + i);

            let year = currentDate.getFullYear();
            let month = String(currentDate.getMonth() + 1).padStart(2, '0');
            let day = String(currentDate.getDate()).padStart(2, '0');
            let dateStr = `${year}-${month}-${day}`;
            let dayOfWeek = currentDate.getDay();
            let nightPrice = basePrice;

            if (chalet) {
                let hol = chalet.holidays?.find(h => h.date === dateStr);
                if (hol && hol.price) {
                    nightPrice = parseFloat(hol.price);
                } else {
                    const weekProps = ['price_sun', 'price_mon', 'price_tue', 'price_wed', 'price_thu', 'price_fri', 'price_sat'];
                    const prop = weekProps[dayOfWeek];
                    if (chalet[prop] && parseFloat(chalet[prop]) > 0) {
                        nightPrice = parseFloat(chalet[prop]);
                    }
                }
            }
            total += nightPrice;
        }

        nightsEl.textContent = nights;
        totalEl.textContent = `R$ ${total.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
        confirmBtn.disabled = false;

        // Atualiza as opções de pagamento (100% ou 50%) baseadas neste total
        if (typeof window.updatePaymentPreview === 'function') {
            window.updatePaymentPreview();
        }
    }

    // Expose updatePaymentPreview to global scope for radio onchange handlers
    window.updatePaymentPreview = function () {
        const totalText = document.getElementById('summaryTotal').textContent;
        const numericTotal = parseFloat(totalText.replace('R$', '').replace(/\./g, '').replace(',', '.').trim()) || 0;

        const fullPreview = document.getElementById('fullTotalPreview');
        const halfPreview = document.getElementById('halfTotalPreview');

        if (fullPreview) {
            fullPreview.textContent = numericTotal.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
        }
        if (halfPreview) {
            halfPreview.textContent = (numericTotal / 2).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
        }
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
                    let mainImg = chalet.main_image ? chalet.main_image : fallbackImg;

                    let galleryImgs = [mainImg];
                    if (chalet.images && Array.isArray(chalet.images)) {
                        galleryImgs = [...new Set([mainImg, ...chalet.images])];
                    }

                    // Determina a badge baseado na etiqueta configurada
                    let badgeHtml = '';
                    if (chalet.badge && chalet.badge.trim() !== '') {
                        badgeHtml = `<div class="badge">${chalet.badge}</div>`;
                    }

                    // Aqui calculamos o valor exato no periodo procurado ao inves do "A partir de"
                    const diffTime = new Date(filterCheckout) - new Date(filterCheckin);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    let nights = diffDays > 0 ? diffDays : 1;

                    let totalPrice = 0;
                    const partsIn = filterCheckin.split('-');
                    const cin = new Date(partsIn[0], partsIn[1] - 1, partsIn[2]);
                    const basePrice = chalet.price != null ? parseFloat(chalet.price) : 0;

                    for (let i = 0; i < nights; i++) {
                        let currentDate = new Date(cin);
                        currentDate.setDate(currentDate.getDate() + i);

                        let year = currentDate.getFullYear();
                        let month = String(currentDate.getMonth() + 1).padStart(2, '0');
                        let day = String(currentDate.getDate()).padStart(2, '0');
                        let dateStr = `${year}-${month}-${day}`;
                        let dayOfWeek = currentDate.getDay();
                        let nightPrice = basePrice;

                        let hol = chalet.holidays?.find(h => h.date === dateStr);
                        if (hol && hol.price) {
                            nightPrice = parseFloat(hol.price);
                        } else {
                            const weekProps = ['price_sun', 'price_mon', 'price_tue', 'price_wed', 'price_thu', 'price_fri', 'price_sat'];
                            const prop = weekProps[dayOfWeek];
                            if (chalet[prop] && parseFloat(chalet[prop]) > 0) {
                                nightPrice = parseFloat(chalet[prop]);
                            }
                        }
                        totalPrice += nightPrice;
                    }

                    const displayPrice = totalPrice.toLocaleString('pt-BR', { minimumFractionDigits: 0 });

                    // Monta HTML do Slider 
                    let sliderHtml = `<div class="chalet-slider" id="modal-slider-${chalet.id}" style="aspect-ratio: 16/9;">`;
                    galleryImgs.forEach((src, i) => {
                        sliderHtml += `<img src="${src}" class="slide ${i === 0 ? 'active' : ''}" alt="${chalet.name}" onclick="openLightbox('${src}')">`;
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
            const guestsVal = guestsOptEl ? guestsOptEl.value : '2_0';
            const [ad, ch] = (guestsVal || '2_0').split('_').map(Number);
            const guestsAdults = ad || 2;
            const guestsChildren = ch || 0;

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
                total_amount: reserva.total,
                payment_rule: paymentRule,
                status: 'Aguardando Pagamento' // Novo status inicial
            };
            if (chaletForReserva && chaletForReserva.id) formDados.chalet_id = chaletForReserva.id;

            // 1. Salvar Intenção de Reserva no Banco via PHP API (Aguardando Pagamento)
            let reservationId = null;
            try {
                const resDb = await fetch('api/reservations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formDados)
                });
                if (!resDb.ok) {
                    throw new Error("Erro ao salvar intenção de reserva no banco");
                }
                const dbData = await resDb.json();
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
                    let mainImg = chalet.main_image ? chalet.main_image : fallbackImg;

                    let galleryImgs = [mainImg];
                    if (chalet.images && Array.isArray(chalet.images)) {
                        galleryImgs = [...new Set([mainImg, ...chalet.images])];
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
                        sliderHtml += `<img src="${src}" class="slide ${i === 0 ? 'active' : ''}" alt="${chalet.name}" onclick="openLightbox('${src}')">`;
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
                if ($('clientAboutImage') && custom.aboutImage) $('clientAboutImage').src = custom.aboutImage;

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

        container.innerHTML = images.map((src, i) =>
            `<div class="hero-slide ${i === 0 ? 'active' : ''}" style="background-image: url('${src}');" data-index="${i}"></div>`
        ).join('');

        heroCurrentIndex = 0;

        if (images.length < 2) {
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
        const modalHeroSrc = chalet.main_image ? chalet.main_image : fallbackImg;
        document.getElementById('chaletDetailsHero').style.backgroundImage = `url('${modalHeroSrc}')`;

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
                        const isHalf = (resData.payment_rule || '').toLowerCase() === 'half';
                        const valorPagoNum = isHalf ? totalNum / 2 : totalNum;
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
                            condicao: isHalf ? 'Sinal de 50%' : '100% à vista',
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
