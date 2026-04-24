<?php
/**
 * Script para inserir o conteúdo padrão da index no banco.
 * Acesse: /api/seed_defaults.php
 * Requer instalação concluída e insere os dados em settings.
 */
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$defaultCustomization = [
    'heroTitle' => 'Bem-vindo ao Sistema Modelo',
    'heroSubtitle' => 'Personalize textos, imagens e conteúdos para refletir a identidade do seu estabelecimento.',
    'heroImages' => ['images/hero.png'],
    'aboutTitle' => 'Uma experiência imersiva',
    'aboutText' => "Bem-vindo ao seu estabelecimento. Este sistema foi preparado para apresentar acomodações, diferenciais e experiências com total flexibilidade de marca.\n\nPersonalize este texto com a identidade do seu hotel ou pousada.",
    'aboutImage' => 'images/chalet3.png',
    'chaletsSubtitle' => 'Nossas Acomodações',
    'chaletsTitle' => 'Conheça Nossas Acomodações',
    'chaletsDesc' => 'Estruturas confortáveis e funcionais para diferentes perfis de hóspedes.',
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
    'testi1Name' => 'Hóspede Exemplo',
    'testi1Location' => 'Avaliação verificada',
    'testi1Text' => 'Excelente estadia, ambiente limpo e atendimento impecável.',
    'testi1Image' => 'https://ui-avatars.com/api/?name=Hospede+Exemplo&background=64748B&color=fff',
    'testi2Name' => 'Hóspede Exemplo',
    'testi2Location' => 'Avaliação verificada',
    'testi2Text' => 'Processo de reserva simples, quarto confortável e ótima organização.',
    'testi2Image' => 'https://ui-avatars.com/api/?name=Hospede+Exemplo&background=2563EB&color=fff',
    'testi3Name' => 'Hóspede Exemplo',
    'testi3Location' => 'Avaliação verificada',
    'testi3Text' => 'Ótima experiência geral, check-in rápido e suporte atencioso durante toda a hospedagem.',
    'testi3Image' => 'https://ui-avatars.com/api/?name=Hospede+Exemplo&background=64748B&color=fff',
    'locAddress' => 'Endereço do estabelecimento',
    'locCar' => 'Informações de acesso e deslocamento podem ser personalizadas pelo estabelecimento.',
    'locMapLink' => 'https://www.google.com/maps',
    'waNumber' => '5535999999999',
    'waMessage' => 'Olá! Gostaria de mais informações sobre disponibilidade e valores em {pousada}.',
    'footerDesc' => 'Estrutura completa para uma experiência de hospedagem confortável e segura.',
    'footerAddress' => 'Cidade/UF',
    'footerEmail' => 'contato@meuestabelecimento.com',
    'footerPhone' => '(00) 00000-0000',
    'footerCopyright' => '© ' . date('Y') . ' Todos os direitos reservados.'
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
