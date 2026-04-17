<?php
/**
 * Script para inserir o conteúdo padrão da index no banco.
 * Acesse: /api/seed_defaults.php
 * Requer instalação concluída e insere os dados em settings.
 */
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$defaultCustomization = [
    'heroTitle' => 'Seu Refúgio de Luxo na Natureza',
    'heroSubtitle' => 'Desconecte-se da rotina e viva momentos inesquecíveis em nossos chalés exclusivos na serra.',
    'heroImages' => ['images/hero.png'],
    'aboutTitle' => 'Uma experiência imersiva',
    'aboutText' => "Nascido do desejo de integrar conforto absoluto à natureza intocada, o Recantos da Serra oferece uma hospedagem ímpar. Nossos chalés foram projetados para se fundirem com a paisagem, garantindo privacidade, luxo e uma vista de tirar o fôlego.\n\nAcorde com o canto dos pássaros, desfrute de um café da manhã artesanal e relaxe em uma banheira de hidromassagem com vista para o vale.",
    'aboutImage' => 'images/chalet3.png',
    'chaletsSubtitle' => 'Nossas Acomodações',
    'chaletsTitle' => 'Escolha seu Refúgio',
    'chaletsDesc' => 'Designs únicos pensados para proporcionar o máximo de conforto em meio às montanhas.',
    'feat1Title' => 'Wi-Fi rápido 📶',
    'feat1Desc' => 'Internet de alta velocidade para você ficar conectado.',
    'feat2Title' => 'Cozinha completa 🍳',
    'feat2Desc' => 'Cozinha equipada para preparar suas refeições com conforto.',
    'feat3Title' => 'Estacionamento 🚗',
    'feat3Desc' => 'Vaga de estacionamento para seu veículo.',
    'feat4Title' => 'Ambiente confortável 🛏️',
    'feat4Desc' => 'Espaço aconchegante para relaxar e descansar.',
    'feat5Title' => 'Pet friendly 🐾',
    'feat5Desc' => 'Seu amigo de quatro patas é muito bem-vindo aqui.',
    'testi1Name' => 'Mariana Costa',
    'testi1Location' => 'São Paulo, SP',
    'testi1Text' => 'Simplesmente perfeito! O chalé panorâmico nos proporcionou uma vista maravilhosa ao amanhecer. O atendimento e o café da manhã artesanal são impecáveis. Voltaremos com certeza!',
    'testi1Image' => 'https://ui-avatars.com/api/?name=Mariana+Costa&background=C89B5F&color=fff',
    'testi2Name' => 'Pedro Henrique',
    'testi2Location' => 'Belo Horizonte, MG',
    'testi2Text' => 'O Ninho de Inverno é o lugar mais aconchegante que já fiquei. A lareira, a adega e o silêncio da montanha criam um ambiente super romântico. Vale cada centavo.',
    'testi2Image' => 'https://ui-avatars.com/api/?name=Pedro+Henrique&background=c96621&color=fff',
    'testi3Name' => 'Juliana A. & Thor',
    'testi3Location' => 'Campinas, SP',
    'testi3Text' => 'Muito bom poder viajar com nossa cachorrinha e ser tão bem recebidos. A estrutura A-Frame é linda e tudo estava extremamente limpo e organizado. Nota 10!',
    'testi3Image' => 'https://ui-avatars.com/api/?name=Juliana+Alves&background=C89B5F&color=fff',
    'locAddress' => 'Recanto da Serra Eco Park - Serra da Mantiqueira, MG',
    'locCar' => 'Apenas 2h30 da capital. Estrada 100% asfaltada até a entrada.',
    'locMapLink' => 'https://www.google.com/maps/search/?api=1&query=Recanto+da+Serra+Eco+Park',
    'waNumber' => '5535999999999',
    'waMessage' => 'Olá, gostaria de mais informações sobre os chalés!',
    'footerDesc' => 'Luxo, conforto e natureza em perfeita harmonia.',
    'footerAddress' => 'Serra da Mantiqueira, MG',
    'footerEmail' => 'contato@recantosdaserra.com',
    'footerPhone' => '(35) 99999-9999',
    'footerCopyright' => '© 2026 Recantos da Serra. Todos os direitos reservados.'
];

$jsonValue = json_encode($defaultCustomization, JSON_UNESCAPED_UNICODE);

try {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('customization', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$jsonValue]);
    
    // Verifica se realmente foi inserido
    $check = $pdo->prepare("SELECT setting_key, LENGTH(setting_value) as len FROM settings WHERE setting_key = 'customization'");
    $check->execute();
    $row = $check->fetch();
    
    if ($row && $row['len'] > 0) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Conteúdo inserido no banco com sucesso.',
            'verificado' => true,
            'registros' => $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn()
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => 'erro',
            'message' => 'Inserção falhou - dados não encontrados no banco.',
            'verificado' => false
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro ao inserir dados',
        'details' => $e->getMessage()
    ]);
}
