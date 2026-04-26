<?php
/**
 * Página inicial - conteúdo carregado do banco de dados (tabela personalizacao + settings)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/api/db.php';

function isPublicImagePathUsable(string $path): bool
{
    $p = trim($path);
    if ($p === '') return false;
    if (preg_match('/^https?:\/\//i', $p) === 1) return true;
    if (strpos($p, 'data:image/') === 0) return true;
    $clean = ltrim(str_replace('\\', '/', $p), '/');
    $full = __DIR__ . '/' . $clean;
    return is_file($full);
}

function firstUsableImagePath(array $paths, string $fallback = ''): string
{
    foreach ($paths as $p) {
        $s = is_string($p) ? trim($p) : '';
        if ($s !== '' && isPublicImagePathUsable($s)) return $s;
    }
    return $fallback;
}

// Buscar personalização diretamente do banco (db.php já faz seed se tabela vazia)
$c = [
    'heroTitle' => '', 'heroSubtitle' => '', 'heroImages' => ['images/hero.png'],
    'aboutTitle' => '', 'aboutText' => '', 'aboutImage' => '',
    'chaletsSubtitle' => '', 'chaletsTitle' => '', 'chaletsDesc' => '',
    'feat1Title' => '', 'feat1Desc' => '', 'feat2Title' => '', 'feat2Desc' => '',
    'feat3Title' => '', 'feat3Desc' => '', 'feat4Title' => '', 'feat4Desc' => '',
    'feat5Title' => '', 'feat5Desc' => '',
    'videosEnabled' => 0, 'videosJson' => [],
    'testi1Name' => '', 'testi1Location' => '', 'testi1Text' => '', 'testi1Image' => '',
    'testi2Name' => '', 'testi2Location' => '', 'testi2Text' => '', 'testi2Image' => '',
    'testi3Name' => '', 'testi3Location' => '', 'testi3Text' => '', 'testi3Image' => '',
    'locAddress' => '', 'locCar' => '', 'locMapLink' => '', 'locMapEmbed' => '',
    'waNumber' => '', 'waMessage' => '',
    'footerDesc' => '', 'footerAddress' => '', 'footerEmail' => '', 'footerPhone' => '', 'footerCopyright' => '',
    'favicon' => ''
];
try {
    $stmt = $pdo->query("SELECT * FROM personalizacao ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    if ($row) {
        $c['heroTitle'] = $row['hero_titulo'] ?? '';
        $c['heroSubtitle'] = $row['hero_subtitulo'] ?? '';
        $heroImgs = !empty($row['hero_imagens']) ? json_decode($row['hero_imagens'], true) : null;
        if (is_array($heroImgs) && !empty($heroImgs)) $c['heroImages'] = $heroImgs;
        $c['aboutTitle'] = $row['about_titulo'] ?? '';
        $c['aboutText'] = $row['about_texto'] ?? '';
        $c['aboutImage'] = $row['about_imagem'] ?? '';
        $c['chaletsSubtitle'] = $row['chalets_subtitulo'] ?? '';
        $c['chaletsTitle'] = $row['chalets_titulo'] ?? '';
        $c['chaletsDesc'] = $row['chalets_desc'] ?? '';
        $c['feat1Title'] = $row['feat1_titulo'] ?? ''; $c['feat1Desc'] = $row['feat1_desc'] ?? '';
        $c['feat2Title'] = $row['feat2_titulo'] ?? ''; $c['feat2Desc'] = $row['feat2_desc'] ?? '';
        $c['feat3Title'] = $row['feat3_titulo'] ?? ''; $c['feat3Desc'] = $row['feat3_desc'] ?? '';
        $c['feat4Title'] = $row['feat4_titulo'] ?? ''; $c['feat4Desc'] = $row['feat4_desc'] ?? '';
        $c['feat5Title'] = $row['feat5_titulo'] ?? ''; $c['feat5Desc'] = $row['feat5_desc'] ?? '';
        $c['videosEnabled'] = (int)($row['videos_enabled'] ?? 0);
        $videos = !empty($row['videos_json']) ? json_decode($row['videos_json'], true) : [];
        $c['videosJson'] = is_array($videos) ? $videos : [];
        $c['testi1Name'] = $row['testi1_nome'] ?? ''; $c['testi1Location'] = $row['testi1_local'] ?? '';
        $c['testi1Text'] = $row['testi1_texto'] ?? ''; $c['testi1Image'] = $row['testi1_imagem'] ?? '';
        $c['testi2Name'] = $row['testi2_nome'] ?? ''; $c['testi2Location'] = $row['testi2_local'] ?? '';
        $c['testi2Text'] = $row['testi2_texto'] ?? ''; $c['testi2Image'] = $row['testi2_imagem'] ?? '';
        $c['testi3Name'] = $row['testi3_nome'] ?? ''; $c['testi3Location'] = $row['testi3_local'] ?? '';
        $c['testi3Text'] = $row['testi3_texto'] ?? ''; $c['testi3Image'] = $row['testi3_imagem'] ?? '';
        $c['locAddress'] = $row['loc_endereco'] ?? ''; $c['locCar'] = $row['loc_carro'] ?? '';
        $c['locMapLink'] = $row['loc_map_link'] ?? '';
        $c['locMapEmbed'] = $row['loc_map_embed'] ?? '';
        $c['waNumber'] = $row['wa_numero'] ?? ''; $c['waMessage'] = $row['wa_mensagem'] ?? '';
        $c['footerDesc'] = $row['footer_desc'] ?? ''; $c['footerAddress'] = $row['footer_endereco'] ?? '';
        $c['footerEmail'] = $row['footer_email'] ?? ''; $c['footerPhone'] = $row['footer_telefone'] ?? '';
        $c['footerCopyright'] = $row['footer_copyright'] ?? '';
        $c['favicon'] = $row['favicon'] ?? '';
    }
} catch (Exception $e) { }

// Settings (logo, social)
$companyLogo = ''; $companyLogoLight = ''; $social = ['instagram'=>'','facebook'=>'','tripadvisor'=>''];
$siteTitle = 'Sistema de Hospedagem';
$metaDescription = 'O seu refúgio com vista para o mar em Governador Celso Ramos.';
$primaryColor = '#2563eb';
$secondaryColor = '#1e293b';
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        $dec = json_decode($v, true);
        $val = (json_last_error() === JSON_ERROR_NONE && $dec !== null) ? $dec : $v;
        if ($k === 'company_logo') $companyLogo = is_string($val) ? $val : '';
        elseif ($k === 'company_logo_light') $companyLogoLight = is_string($val) ? $val : '';
        elseif ($k === 'socialSettings' && is_array($val)) $social = array_merge($social, $val);
        elseif ($k === 'site_title' && is_string($val) && trim($val) !== '') $siteTitle = trim($val);
        elseif ($k === 'meta_description' && is_string($val) && trim($val) !== '') $metaDescription = trim($val);
        elseif ($k === 'primary_color' && is_string($val) && trim($val) !== '') $primaryColor = trim($val);
        elseif ($k === 'secondary_color' && is_string($val) && trim($val) !== '') $secondaryColor = trim($val);
    }
} catch (Exception $e) { }

// FAQs ativas (para SEO: renderizadas diretamente no HTML).
$faqsList = [];
try {
    $stmtFaq = $pdo->query("SELECT id, question, answer FROM faqs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $faqsList = $stmtFaq ? $stmtFaq->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $faqsList = [];
}

// Helper
$h = function($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); };
$aboutHtml = implode('', array_map(fn($p) => '<p>' . $h(trim($p)) . '</p>', array_filter(explode("\n", $c['aboutText'] ?? ''))));
if (empty($aboutHtml)) $aboutHtml = '<p>' . $h($c['aboutText']) . '</p>';
$c['heroImages'] = array_values(array_filter(array_map(
    fn($p) => is_string($p) && isPublicImagePathUsable($p) ? $p : null,
    (array)($c['heroImages'] ?? [])
)));
$defaultHero = firstUsableImagePath(['images/hero.png'], '');
if (empty($c['heroImages']) && $defaultHero !== '') {
    $c['heroImages'] = [$defaultHero];
}
$heroFirstImg = firstUsableImagePath($c['heroImages'], '');
$aboutImageSrc = isPublicImagePathUsable((string)$c['aboutImage']) ? (string)$c['aboutImage'] : '';
$faviconHref = !empty($c['favicon']) ? $c['favicon'] : "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect fill='%232563eb' width='32' height='32' rx='4'/></svg>";
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="author" content="Ferrioli Mídias e Desenvolvimento LTDA">
    <meta name="generator" content="Consultoria de tecnologia e implementação de IA">
    <title><?= $h($siteTitle) ?></title>
    <meta name="description" content="<?= $h($metaDescription) ?>">
    <link rel="icon" type="image/x-icon" id="clientFavicon" href="<?= $h($faviconHref) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        :root {
            --primary-color: <?= $h($primaryColor) ?>;
            --secondary-color: <?= $h($secondaryColor) ?>;
            --primary: var(--primary-color);
            --secondary: var(--secondary-color);
        }
        #map-container iframe { width: 100% !important; height: 100% !important; min-height: 400px !important; border: 0; }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/carousel/carousel.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.css">
    <link rel="stylesheet" href="styles.css">
</head>

<body>

    <header class="navbar" id="navbar">
        <div class="container nav-container">
            <?php if ($companyLogo): ?>
            <a href="#" class="logo"><img src="<?= $h($companyLogoLight ?: $companyLogo) ?>" alt="<?= $h($siteTitle) ?>" style="height: 40px;" data-light="<?= $h($companyLogoLight ?: $companyLogo) ?>" data-dark="<?= $h($companyLogo) ?>"></a>
            <?php else: ?>
            <a href="#" class="logo"><i class="ph ph-mountains"></i><span><?= $h($siteTitle) ?></span></a>
            <?php endif; ?>

            <nav class="nav-links">
                <a href="#about">Sobre</a>
                <a href="#chalets">Acomodações</a>
                <a href="#amenities">Comodidades</a>
                <?php if (!empty($faqsList)): ?><a href="#faq">FAQ</a><?php endif; ?>
                <a href="#booking" class="btn btn-primary">Reservar Agora</a>
            </nav>

            <button class="menu-toggle" aria-label="Abrir menu"><i class="ph ph-list"></i></button>
        </div>
    </header>

    <section class="hero" id="home">
        <div id="heroCarousel" class="f-carousel"></div>
        <div class="hero-overlay"></div>
        <div class="container hero-content">
            <h1 class="fade-up" id="clientHeroTitle"><?= $h($c['heroTitle']) ?></h1>
            <p class="fade-up delay-1" id="clientHeroSubtitle"><?= $h($c['heroSubtitle']) ?></p>
            <div class="hero-actions fade-up delay-2">
                <a href="#chalets" class="btn btn-primary">Ver Acomodações</a>
                <a href="#about" class="btn btn-outline">Nossa História</a>
            </div>
        </div>
    </section>

    <section class="about section" id="about">
        <div class="container about-grid">
            <div class="about-text">
                <h2 class="section-title" id="clientAboutTitle"><?= $h($c['aboutTitle']) ?></h2>
                <div id="clientAboutText"><?= $aboutHtml ?></div>
                <div class="about-logo" id="aboutSectionLogo">
                    <?php if ($companyLogo): ?>
                    <a href="#"><img src="<?= $h($companyLogo) ?>" alt="<?= $h($siteTitle) ?>" style="max-height: 80px; width: auto;"></a>
                    <?php else: ?>
                    <a href="#" class="logo"><i class="ph ph-mountains"></i><span><?= $h($siteTitle) ?></span></a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="about-image-wrapper">
                <div class="about-image mask-image">
                    <?php if ($aboutImageSrc !== ''): ?>
                    <img src="<?= $h($aboutImageSrc) ?>" alt="Sobre o Recanto" id="clientAboutImage">
                    <?php else: ?>
                    <div id="clientAboutImageFallback" class="image-fallback" style="width:100%;height:100%;min-height:320px;background:var(--secondary-color);"></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="chalets section bg-light" id="chalets">
        <div class="container">
            <div class="section-header text-center">
                <span class="subtitle" id="clientChaletsSubtitle"><?= $h($c['chaletsSubtitle']) ?></span>
                <h2 class="section-title" id="clientChaletsTitle"><?= $h($c['chaletsTitle']) ?></h2>
                <p id="clientChaletsDesc"><?= $h($c['chaletsDesc']) ?></p>
            </div>
            <div class="chalets-grid" id="chaletsGrid">
                <div style="text-align: center; width: 100%; grid-column: 1 / -1; padding: 2rem;"><p>Carregando chalés...</p></div>
            </div>
        </div>
    </section>

    <section class="amenities section" id="amenities">
        <div class="container">
            <div class="section-header text-center">
                <span class="subtitle">Diferenciais</span>
                <h2 class="section-title">Comodidades Premium</h2>
            </div>
            <div class="amenities-grid">
                <div class="amenity-item">
                    <div class="icon-wrapper"><i class="ph ph-wifi-high"></i></div>
                    <h4 id="clientFeat1Title"><?= $h($c['feat1Title']) ?></h4>
                    <p id="clientFeat1Desc"><?= $h($c['feat1Desc']) ?></p>
                </div>
                <div class="amenity-item">
                    <div class="icon-wrapper"><i class="ph ph-cooking-pot"></i></div>
                    <h4 id="clientFeat2Title"><?= $h($c['feat2Title']) ?></h4>
                    <p id="clientFeat2Desc"><?= $h($c['feat2Desc']) ?></p>
                </div>
                <div class="amenity-item">
                    <div class="icon-wrapper"><i class="ph ph-car"></i></div>
                    <h4 id="clientFeat3Title"><?= $h($c['feat3Title']) ?></h4>
                    <p id="clientFeat3Desc"><?= $h($c['feat3Desc']) ?></p>
                </div>
                <div class="amenity-item">
                    <div class="icon-wrapper"><i class="ph ph-bed"></i></div>
                    <h4 id="clientFeat4Title"><?= $h($c['feat4Title']) ?></h4>
                    <p id="clientFeat4Desc"><?= $h($c['feat4Desc']) ?></p>
                </div>
                <div class="amenity-item">
                    <div class="icon-wrapper"><i class="ph ph-paw-print"></i></div>
                    <h4 id="clientFeat5Title"><?= $h($c['feat5Title']) ?></h4>
                    <p id="clientFeat5Desc"><?= $h($c['feat5Desc']) ?></p>
                </div>
            </div>
        </div>
    </section>

    <section class="section bg-light" id="videos-section" style="display: <?= (int)$c['videosEnabled'] === 1 ? 'block' : 'none' ?>;">
        <div class="container">
            <div class="section-header text-center">
                <span class="subtitle">Mídia</span>
                <h2 class="section-title">Vídeos</h2>
            </div>
            <div class="chalets-grid" id="videos-grid"></div>
        </div>
    </section>

    <section class="testimonials section bg-light" id="testimonials">
        <div class="container">
            <div class="section-header text-center">
                <span class="subtitle">O que dizem sobre nós</span>
                <h2 class="section-title">Experiências Inesquecíveis</h2>
            </div>
            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <div class="stars"><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i></div>
                    <p class="review-text" id="testi1Text">"<?= $h($c['testi1Text']) ?>"</p>
                    <div class="reviewer">
                        <img id="testi1Img" src="<?= $h($c['testi1Image']) ?>" alt="<?= $h($c['testi1Name']) ?>">
                        <div><h4 id="testi1Name"><?= $h($c['testi1Name']) ?></h4><span id="testi1Location"><?= $h($c['testi1Location']) ?></span></div>
                    </div>
                </div>
                <div class="testimonial-card">
                    <div class="stars"><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i></div>
                    <p class="review-text" id="testi2Text">"<?= $h($c['testi2Text']) ?>"</p>
                    <div class="reviewer">
                        <img id="testi2Img" src="<?= $h($c['testi2Image']) ?>" alt="<?= $h($c['testi2Name']) ?>">
                        <div><h4 id="testi2Name"><?= $h($c['testi2Name']) ?></h4><span id="testi2Location"><?= $h($c['testi2Location']) ?></span></div>
                    </div>
                </div>
                <div class="testimonial-card">
                    <div class="stars"><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i></div>
                    <p class="review-text" id="testi3Text">"<?= $h($c['testi3Text']) ?>"</p>
                    <div class="reviewer">
                        <img id="testi3Img" src="<?= $h($c['testi3Image']) ?>" alt="<?= $h($c['testi3Name']) ?>">
                        <div><h4 id="testi3Name"><?= $h($c['testi3Name']) ?></h4><span id="testi3Location"><?= $h($c['testi3Location']) ?></span></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="location section" id="location">
        <div class="container">
            <div class="location-grid">
                <div class="location-info">
                    <span class="subtitle">Como chegar</span>
                    <h2 class="section-title">Nossa Localização</h2>
                    <p>Estamos localizados no coração da serra, em um local de fácil acesso para carros de passeio, mas imerso na natureza com total privacidade.</p>
                    <ul class="contact-details">
                        <li><i class="ph ph-map-pin"></i><div><strong>Endereço</strong><span id="locAddress"><?= $h($c['locAddress']) ?></span></div></li>
                        <li><i class="ph ph-car"></i><div><strong>De Carro</strong><span id="locCar"><?= $h($c['locCar']) ?></span></div></li>
                    </ul>
                    <a id="locMapLink" href="<?= $h($c['locMapLink']) ?>" target="_blank" class="btn btn-outline" style="margin-top: 1.5rem;"><i class="ph ph-share"></i> Ver no Google Maps</a>
                </div>
                <div class="location-map glass-card">
                    <div id="map-container" style="width: 100%; height: 100%; min-height: 400px; border-radius: 12px; overflow: hidden;"><?= $c['locMapEmbed'] ?></div>
                </div>
            </div>
        </div>
    </section>

    <?php if (!empty($faqsList)): ?>
    <section class="faq-section section" id="faq">
        <div class="container">
            <div class="section-header text-center">
                <span class="subtitle">Dúvidas Comuns</span>
                <h2 class="section-title">Perguntas Frequentes</h2>
                <p class="faq-intro">Reunimos aqui as respostas para as dúvidas mais comuns dos nossos hóspedes. Não encontrou o que procurava? Fale connosco pelo WhatsApp.</p>
            </div>
            <div class="faq-accordion" itemscope itemtype="https://schema.org/FAQPage">
                <?php foreach ($faqsList as $idx => $faq): ?>
                    <?php $faqId = 'faq-item-' . (int)($faq['id'] ?? $idx); ?>
                    <div class="faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                        <button type="button" class="faq-question"
                                aria-expanded="false" aria-controls="<?= $h($faqId) ?>-a"
                                id="<?= $h($faqId) ?>-q">
                            <span itemprop="name"><?= $h($faq['question']) ?></span>
                            <i class="ph ph-plus faq-icon" aria-hidden="true"></i>
                        </button>
                        <div class="faq-answer" id="<?= $h($faqId) ?>-a"
                             role="region" aria-labelledby="<?= $h($faqId) ?>-q"
                             itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                            <div class="faq-answer-inner" itemprop="text"><?= nl2br($h($faq['answer'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="booking-cta section" id="booking">
        <div class="container">
            <div class="booking-box glass-card premium-shadow">
                <div class="booking-info">
                    <h2>Garanta sua experiência</h2>
                    <p>Verifique a disponibilidade e faça sua reserva direta com condições exclusivas.</p>
                </div>
                <form class="booking-form" id="availabilityForm">
                    <div class="form-group"><label for="checkin">Check-in</label><input type="date" id="checkin" required></div>
                    <div class="form-group"><label for="checkout">Check-out</label><input type="date" id="checkout" required></div>
                    <div class="form-group"><label for="guestsOption">Hóspedes</label>
                        <select id="guestsOption">
                            <option value="">Selecione...</option>
                        </select>
                    </div>
                    <button type="button" id="btnVerifyAvailability" class="btn btn-primary btn-block">Verificar e Reservar</button>
                </form>
                <div class="trust-badges">
                    <span><i class="ph ph-credit-card"></i> Aceitamos cartões</span>
                    <span><i class="ph ph-shield-check"></i> Site 100% seguro</span>
                    <span><i class="ph ph-lock-simple"></i> Compra segura</span>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="container footer-grid">
            <div class="footer-brand">
                <?php if ($companyLogoLight ?: $companyLogo): ?>
                <a href="#" class="logo"><img src="<?= $h($companyLogoLight ?: $companyLogo) ?>" alt="<?= $h($siteTitle) ?>" style="height: 50px;"></a>
                <?php else: ?>
                <a href="#" class="logo"><i class="ph ph-mountains"></i><span><?= $h($siteTitle) ?></span></a>
                <?php endif; ?>
                <p id="footerDesc"><?= $h($c['footerDesc']) ?></p>
                <div class="social-links">
                    <?php if (!empty($social['instagram'])): ?><a href="<?= $h($social['instagram']) ?>" target="_blank"><i class="ph ph-instagram-logo"></i></a><?php endif; ?>
                    <?php if (!empty($social['facebook'])): ?><a href="<?= $h($social['facebook']) ?>" target="_blank"><i class="ph ph-facebook-logo"></i></a><?php endif; ?>
                    <?php if (!empty($social['tripadvisor'])): ?><a href="<?= $h($social['tripadvisor']) ?>" target="_blank"><i class="ph ph-whatsapp-logo"></i></a><?php endif; ?>
                    <?php if (empty($social['instagram']) && empty($social['facebook']) && empty($social['tripadvisor'])): ?>
                    <a href="#"><i class="ph ph-instagram-logo"></i></a>
                    <a href="#"><i class="ph ph-facebook-logo"></i></a>
                    <a href="#"><i class="ph ph-whatsapp-logo"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="footer-links">
                <h4>Navegação</h4>
                <ul>
                    <li><a href="#home">Início</a></li>
                    <li><a href="#about">Sobre</a></li>
                    <li><a href="#chalets">Acomodações</a></li>
                    <li><a href="#amenities">Comodidades</a></li>
                </ul>
            </div>
            <div class="footer-contact">
                <h4>Contato</h4>
                <p><i class="ph ph-map-pin"></i> <span id="footerAddress"><?= $h($c['footerAddress']) ?></span></p>
                <p><i class="ph ph-envelope"></i> <span id="footerEmail"><?= $h($c['footerEmail']) ?></span></p>
                <p><i class="ph ph-phone"></i> <span id="footerPhone"><?= $h($c['footerPhone']) ?></span></p>
            </div>
        </div>
        <div class="footer-bottom">
            <div class="container" style="display: flex; flex-direction: column; align-items: center; gap: 1rem;">
                <p id="footerCopyright"><?= $h($c['footerCopyright']) ?></p>
            </div>
        </div>
    </footer>

    <div class="modal-overlay" id="bookingModal">
        <div class="modal-content glass-card premium-shadow">
            <button class="close-modal" id="closeModal"><i class="ph ph-x"></i></button>
            <div class="modal-header">
                <h3>Finalizar Reserva</h3>
                <p id="modalChaletName"></p>
            </div>
            <div class="modal-body">
                <div class="reservation-dates" style="display: flex; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; margin:0; min-width: 120px;"><label>Check-in</label><input type="date" id="modalCheckin" class="form-control" required></div>
                    <div class="form-group" style="flex: 1; margin:0; min-width: 120px;"><label>Check-out</label><input type="date" id="modalCheckout" class="form-control" required></div>
                    <div class="form-group" style="flex: 1; margin:0; min-width: 200px;"><label>Hóspedes</label>
                        <select id="modalGuestsOption" class="form-control">
                            <option value="">Selecione...</option>
                        </select>
                    </div>
                </div>
                <div id="availabilityMessage" style="color: var(--danger); font-size: 0.9rem; margin-bottom: 1rem; display: none;"></div>
                <div class="reservation-summary" style="margin-bottom: 1.5rem;">
                    <div class="summary-row"><span>Noites:</span><strong id="summaryNights">0</strong></div>
                    <div class="summary-row"><span>Hospedagem (diárias):</span><strong id="summaryLodging">R$ 0,00</strong></div>
                    <div class="summary-row" id="summaryExtraGuestsRow" style="display: none;"><span id="summaryExtraGuestsLabel">Hóspedes extra:</span><strong id="summaryExtraGuests">R$ 0,00</strong></div>
                    <div class="summary-row" id="summaryExtrasRow" style="display: none;"><span>Serviços adicionais:</span><strong id="summaryExtras">R$ 0,00</strong></div>
                    <div class="summary-row" id="summaryDiscountRow" style="display: none;"><span>Desconto (cupom):</span><strong id="summaryDiscount">- R$ 0,00</strong></div>
                    <hr style="margin: 0.5rem 0; border-color: rgba(255,255,255,0.1);">
                    <div class="summary-row total"><span>Total:</span><strong id="summaryTotal">R$ 0,00</strong></div>
                </div>
                <div id="bookingCouponBlock" class="form-group" style="display: none; margin-bottom: 1rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 500;">
                        <input type="checkbox" id="hasCouponToggle"> Possui cupom?
                    </label>
                    <div id="bookingCouponFieldsWrap" style="display: none; margin-top: 0.5rem;">
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <input type="text" id="couponCodeInput" class="form-control" placeholder="Código do cupom" autocomplete="off" style="flex: 1; min-width: 140px;">
                            <button type="button" class="btn btn-outline" id="applyCouponBtn">Aplicar</button>
                        </div>
                        <small id="couponFeedback" style="display: block; margin-top: 0.35rem; min-height: 1.2em;"></small>
                    </div>
                </div>
                <div id="bookingExtrasBlock" class="form-group" style="display: none; margin-bottom: 1rem;">
                    <label style="font-weight: 600;">Serviços adicionais</label>
                    <div id="bookingExtrasList" style="margin-top: 0.5rem;"></div>
                </div>
                <form id="finalBookingForm">
                    <div class="form-group"><label>Nome Completo</label><input type="text" id="bookingName" placeholder="Seu nome" required></div>
                    <div class="form-group"><label>E-mail</label><input type="email" id="bookingEmail" placeholder="seu@email.com" required></div>
                    <div class="form-group"><label>WhatsApp</label><input type="tel" id="bookingPhone" placeholder="11999999999" required></div>
                    <div class="form-group payment-methods" id="paymentMethodsGroup" style="display:none;">
                        <label>Forma de Pagamento</label>
                        <div class="payment-methods-list" id="paymentMethodsList"></div>
                    </div>
                    <div class="form-group payment-options">
                        <label>Condição de Pagamento</label>
                        <div class="payment-options-list" id="paymentOptionsList"></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block" id="confirmBookingBtn" disabled>Confirmar Reserva e Pagar</button>
                    <small id="confirmBookingHint" style="display:block; text-align:center; margin-top:0.5rem; color:#888;">*A reserva só será confirmada após o pagamento.</small>
                </form>
            </div>
        </div>
    </div>

    <div class="toast" id="successToast">
        <i class="ph ph-check-circle"></i>
        <span>Reserva salva! Redirecionando para pagamento...</span>
    </div>

    <div class="modal-overlay" id="availabilityModal">
        <div class="modal-content glass-card premium-shadow" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
            <button class="close-modal" id="closeAvailabilityModal"><i class="ph ph-x"></i></button>
            <div class="modal-header">
                <h3>Opções Disponíveis</h3>
                <p id="availabilityModalDates">Check-in: - | Check-out: -</p>
            </div>
            <div class="modal-body">
                <div class="chalets-grid" id="availabilityGrid" style="margin-top: 1rem;"></div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="chaletDetailsModal">
        <div class="modal-content glass-card premium-shadow" style="max-width: 800px; max-height: 90vh; overflow-y: auto; padding: 0;">
            <button class="close-modal" id="closeChaletDetailsModal" style="position: absolute; top: 1rem; right: 1rem; z-index: 10;"><i class="ph ph-x"></i></button>
            <div id="chaletDetailsHero" style="width: 100%; height: 300px; background-size: cover; background-position: center; position: relative;">
                <div class="badge" id="chaletDetailsBadge" style="position: absolute; bottom: 1rem; left: 1rem; top: auto; right: auto;">Exclusivo</div>
            </div>
            <div class="modal-body" style="padding: 2rem;">
                <h2 id="chaletDetailsName" style="margin-bottom: 0.5rem; color: var(--text-dark);"></h2>
                <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem; color: var(--text-muted); font-size: 0.95rem;">
                    <span><i class="ph ph-house"></i> <span id="chaletDetailsType">Tipo</span></span>
                    <span><i class="ph ph-money"></i> A partir de <strong>R$ <span id="chaletDetailsPrice">0</span>/noite</strong></span>
                </div>
                <div id="chaletDetailsFullDescription" style="line-height: 1.8; color: var(--text-light); margin-bottom: 2rem; white-space: pre-wrap;"></div>
                <div style="text-align: center; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 2rem;">
                    <button class="btn btn-primary" id="bookThisChaletBtn" style="padding: 1rem 3rem; font-size: 1.1rem;">Verificar Disponibilidade & Reservar</button>
                </div>
            </div>
        </div>
    </div>

    <a id="floatingWaBtn" class="floating-wa" target="_blank" style="display: <?= !empty($c['waNumber']) ? 'flex' : 'none' ?>;" href="<?= !empty($c['waNumber']) ? 'https://wa.me/' . $h($c['waNumber']) . (!empty($c['waMessage']) ? '?text=' . urlencode($c['waMessage']) : '') : '#' ?>">
        <i class="ph-fill ph-whatsapp-logo"></i>
    </a>

    <script>
    window.__INITIAL_CUSTOMIZATION = <?= json_encode([
        'heroImages' => $c['heroImages'],
        'videosEnabled' => $c['videosEnabled'],
        'videosJson' => $c['videosJson'],
        'waNumber' => $c['waNumber'],
        'waMessage' => $c['waMessage'],
        'favicon' => $c['favicon']
    ]) ?>;
    const heroImagesData = <?= json_encode($c['heroImages'] ?? []) ?>;
    window.heroImagesData = heroImagesData;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/carousel/carousel.umd.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/carousel/carousel.autoplay.umd.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.umd.js"></script>
    <script src="script.js?v=18"></script>
</body>

</html>
