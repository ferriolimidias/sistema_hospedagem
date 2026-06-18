# Mapa de Funcoes do Sistema de Hospedagem

Data: 17 de junho de 2026.

## 1. Site publico

- Arquivos: `index.php`, `styles.css`, `script.js`, `api/customization.php`, `api/chalets.php`, `api/settings.php`.
- Tabelas: `personalizacao`, `chalets`, `settings`, `faqs`.
- Acesso: publico.
- Autenticacao: nao.
- Dependencia externa: nao depende de `vendor`.
- Alterado recentemente: sim, textos, personalizacao e seguranca indireta.
- Riscos: depende de `config/database.php`; erros de DB deixam site indisponivel.

## 2. Listagem de chales

- Arquivos: `api/chalets.php`, `script.js`, `admin/admin.js`.
- Tabelas: `chalets`, `chalet_custom_prices`.
- Acesso: GET publico; mutacoes administrativas.
- Autenticacao: `POST`, `PUT`, `PATCH`, `DELETE` exigem admin.
- Dependencia externa: nao.
- Alterado: sim, autenticacao, upload, precos e capacidade.
- Riscos: exclusao bloqueia se houver reservas; upload exige GD para conversao.

## 3. Consulta de disponibilidade

- Arquivos: `api/availability.php`, `script.js`.
- Tabelas: `reservations`.
- Acesso: publico.
- Autenticacao: nao.
- Dependencia externa: nao.
- Alterado: sim.
- Riscos: disponibilidade depende de status e expiracao de reservas pendentes.

## 4. Reserva

- Arquivos: `api/reservations.php`, `script.js`, `api/pricing.php`, `api/booking_extras.php`.
- Tabelas: `reservations`, `chalets`, `coupons`, `extra_services`, `stay_discounts`, `seasonal_rules`.
- Acesso: publico para criar; admin para listar/alterar/excluir.
- Autenticacao: mutacoes administrativas exigem `be_require_internal_key`.
- Dependencia externa: nao para criar reserva.
- Alterado: sim, varios campos novos.
- Riscos: conflito de disponibilidade, status duplicados, depender de colunas novas em banco antigo.

## 5. Checkout e pagamento

- Arquivos: `api/create_preference.php`, `api/mp_webhook.php`, `script.js`.
- Tabelas: `reservations`, `settings`.
- Acesso: publico para iniciar pagamento; webhook publico.
- Autenticacao: webhook valida via consulta ao Mercado Pago com token salvo.
- Dependencia externa: Mercado Pago via cURL.
- Alterado: sim.
- Riscos: token ausente, ambiente sandbox/producao, idempotencia parcial, webhook depende de conectividade externa.

## 6. Cupons

- Arquivos: `api/validate_coupon.php`, `api/admin_coupons.php`, `admin/admin.js`, `script.js`.
- Tabelas: `coupons`.
- Acesso: validacao publica; CRUD admin.
- Autenticacao: CRUD exige admin.
- Dependencia externa: nao.
- Alterado: sim.
- Riscos: validar expiracao e tipo `fixed/percent`.

## 7. Precos e regras sazonais

- Arquivos: `api/pricing.php`, `api/seasonal_rules.php`, `api/booking_options.php`, `admin/admin.js`.
- Tabelas: `seasonal_rules`, `chalet_custom_prices`, `chalets`.
- Acesso: leitura publica; escrita admin.
- Autenticacao: escrita exige admin.
- Dependencia externa: nao.
- Alterado: sim.
- Riscos: regras recorrentes exigem colunas novas; divergencia entre calculo JS e backend deve ser testada.

## 8. Descontos por estadia

- Arquivos: `api/stay_discounts.php`, `api/booking_options.php`, `script.js`, `admin/admin.js`.
- Tabelas: `stay_discounts`.
- Acesso: leitura publica; escrita admin.
- Autenticacao: escrita exige admin.
- Dependencia externa: nao.
- Alterado: sim.
- Riscos: percentuais mal configurados afetam preco final.

## 9. Consumos / conta da hospedagem

- Arquivos: `api/consumptions.php`, `api/admin_extra_services.php`, `admin/admin.js`.
- Tabelas: `reservation_consumptions`, `extra_services`, `reservations`.
- Acesso: administrativo.
- Autenticacao: exige admin.
- Dependencia externa: nao.
- Alterado: sim.
- Riscos: total de consumo entra no relatorio; precisa testar fechamento de conta.

## 10. Relatorios/exportacoes CSV

- Arquivos: `api/reports.php`, `admin/admin.js`.
- Tabelas: `reservations`, `reservation_consumptions`, `settings`.
- Acesso: administrativo.
- Autenticacao: exige admin.
- Dependencia externa: nao.
- Alterado: sim.
- Riscos: filtra `status = 'Finalizada'`; valores usam ponto decimal no CSV; nomes de arquivo dependem de `company_name`.

## 11. Contratos PDF

- Arquivos: `api/contract_service.php`, `api/generate_contract.php`, `api/download_contract.php`, `api/contract_access.php`, `admin/admin.js`.
- Tabelas: `reservations`, `chalets`, `settings`.
- Acesso: admin para gerar; download admin ou token publico.
- Autenticacao: admin cookie ou token de acesso.
- Dependencia externa: `vendor/autoload.php` e Dompdf.
- Alterado: sim.
- Riscos: sem `vendor`, contrato falha; `storage/contracts` precisa escrita; download depende de arquivo existente.

## 12. FNRH

- Arquivos: `api/fnrh_service.php`, `api/checkin_link.php`, `checkin.php`, `admin/admin.js`.
- Tabelas: `reservations`, `settings`.
- Acesso: admin e link/token de check-in.
- Autenticacao: admin para disparos; token para link.
- Dependencia externa: API FNRH se ativa.
- Alterado: sim.
- Riscos: campos obrigatorios, ambiente oficial, evitar envio real sem homologacao.

## 13. Mercado Pago

- Arquivos: `api/create_preference.php`, `api/mp_webhook.php`, `admin/admin.js`, `script.js`.
- Tabelas: `settings`, `reservations`.
- Acesso: checkout publico, configuracao admin.
- Autenticacao: configuracao exige admin.
- Dependencia externa: Mercado Pago via cURL.
- Alterado: sim.
- Riscos: token real, webhook, idempotencia, falha de geracao de contrato pos-pagamento se Dompdf ausente.

## 14. Evolution API / WhatsApp

- Arquivos: `api/evolution_instance.php`, `api/evolution_service.php`, `admin/admin.js`.
- Tabelas: `settings`, `reservations`.
- Acesso: administrativo.
- Autenticacao: exige admin.
- Dependencia externa: Evolution API, cURL; PDF exige Dompdf.
- Alterado: sim.
- Riscos: envio real a clientes, duplicidade de mensagens, falta de configuracao.

## 15. Painel admin

- Arquivos: `admin/index.html`, `admin/admin.css`, `admin/admin.js`.
- Tabelas: varias.
- Acesso: administrativo.
- Autenticacao: cookie `admin_token`.
- Dependencia externa: JS/CSS externos via CDN para icones/avatars; backend PHP.
- Alterado: sim.
- Riscos: muitas acoes em JS unico grande; testar responsividade e permissoes.

## 16. Login/logout

- Arquivos: `admin/login.html`, `api/auth.php`.
- Tabelas: `admins`.
- Acesso: publico para login.
- Autenticacao: senha admin; cookie seguro.
- Dependencia externa: nao.
- Alterado: sim.
- Riscos: `auth_token` persistente; logout deve limpar cookie/token se implementado no front.

## 17. Usuarios administradores

- Arquivos: `api/users.php`, `admin/admin.js`.
- Tabelas: `admins`.
- Acesso: administrativo.
- Autenticacao: exige admin.
- Dependencia externa: nao.
- Alterado: sim, correcao critica.
- Riscos: cuidado com permissao de secretaria/admin.

## 18. Configuracoes

- Arquivos: `api/settings.php`, `admin/admin.js`.
- Tabelas: `settings`.
- Acesso: GET publico filtrado; POST admin.
- Autenticacao: POST exige admin.
- Dependencia externa: nao.
- Alterado: sim, filtro de chaves sensiveis.
- Riscos: configuracoes sensiveis salvas no banco; nao expor no site.

## 19. Personalizacao/layout

- Arquivos: `api/customization.php`, `index.php`, `admin/admin.js`, `styles.css`.
- Tabelas: `personalizacao`, `settings`.
- Acesso: leitura publica; escrita admin.
- Autenticacao: escrita exige admin.
- Dependencia externa: upload usa GD.
- Alterado: sim.
- Riscos: paths de imagens, permissao de escrita e seguranca de upload.

## 20. Uploads/imagens

- Arquivos: `api/db.php`, `api/chalets.php`, `api/customization.php`, `.htaccess`, `images/uploads/.htaccess`.
- Tabelas: `chalets`, `personalizacao`.
- Acesso: admin para upload; publico para servir imagens.
- Autenticacao: upload exige admin.
- Dependencia externa: extensao PHP GD e `fileinfo`.
- Alterado: sim.
- Riscos: permissao de pasta, bloqueio de PHP em uploads, tamanho/MIME.

