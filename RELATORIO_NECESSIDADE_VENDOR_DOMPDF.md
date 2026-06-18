# Relatorio - Necessidade de Vendor, Dompdf, PDF e CSV

Data: 17 de junho de 2026.

## Resumo objetivo

O fluxo de relatorio gerencial identificado no painel e CSV, nao PDF.

Porem o sistema atual tambem contem fluxo real de contrato e recibo PDF. Esse fluxo foi adicionado no codigo atual e depende de `vendor/autoload.php` e Dompdf.

Conclusao: `vendor/Dompdf` nao e necessario para site publico, reserva basica, painel basico e relatorios CSV. Mas e necessario para contratos PDF, recibos PDF e envio de contrato/recibo PDF via Evolution/WhatsApp.

## Estado atual de dependencias

- `composer.json`: existe e declara `dompdf/dompdf:^3.1`.
- `composer.lock`: ausente.
- `vendor/`: ausente.
- `vendor/autoload.php`: ausente.
- `vendor/dompdf/dompdf`: ausente.
- PHP local: indisponivel.
- Composer local: indisponivel.

## Onde `vendor/autoload.php` e usado

- `api/contract_service.php`: carrega `../vendor/autoload.php` antes de gerar contrato.
- `api/evolution_service.php`: carrega `../vendor/autoload.php` para gerar recibo PDF e PDF de teste.

## Onde Dompdf e usado

- `api/contract_service.php`
  - `use Dompdf\Dompdf`
  - `use Dompdf\Options`
  - `generateContractForReservation()` gera `contract_*.pdf` em `storage/contracts`.
- `api/evolution_service.php`
  - `evo_build_folio_receipt_pdf_base64()`
  - `evo_build_dummy_pdf_base64()`
  - gera PDFs em base64 para envio por WhatsApp.

## Onde PDF aparece como fluxo real

- `api/generate_contract.php`: endpoint administrativo para gerar contrato.
- `api/download_contract.php`: endpoint para baixar/abrir contrato com `Content-Type: application/pdf`.
- `api/mp_webhook.php`: tenta gerar contrato PDF automaticamente quando pagamento e confirmado.
- `admin/admin.js`: botoes para gerar contrato, abrir PDF e enviar contrato via WhatsApp.
- `api/evolution_service.php`: reenvio de contrato e envio de recibo PDF.

## Onde CSV aparece como fluxo real

- `api/reports.php`
  - `Content-Type: text/csv; charset=UTF-8`
  - `php://output`
  - `fputcsv`
  - nome de arquivo `relatorio_*.csv`
- `admin/admin.js`
  - chama `../api/reports.php?action=summary`
  - chama `../api/reports.php?action=export`
  - baixa `relatorio_gerencial.csv`

## Respostas diretas

1. O site publico depende de `vendor/`?
   - Nao para carregar pagina, listar chales, consultar disponibilidade e criar reserva.

2. O painel admin depende de `vendor/`?
   - Nao para login, listagens e gestao basica. Sim para acoes de contrato/recibo PDF.

3. O sistema de reserva depende de `vendor/`?
   - Nao para criar reserva e iniciar pagamento. O webhook pode tentar gerar contrato PDF, mas a falha e capturada.

4. Os relatorios dependem de `vendor/`?
   - Nao. O relatorio identificado e CSV puro em PHP.

5. Os relatorios sao CSV, PDF ou ambos?
   - Relatorios gerenciais: CSV.
   - Contratos/recibos: PDF, mas sao outro fluxo.

6. Existe fluxo real de PDF?
   - Sim. Contrato PDF e recibo PDF existem em codigo e na UI administrativa.

7. Existe codigo morto ou nao usado de PDF/Dompdf?
   - Nao parece morto: ha botoes no painel, endpoint manual, webhook e Evolution chamando esses servicos.

8. `composer.json` declara dependencia que nao e usada?
   - A dependencia e usada pelos fluxos de PDF. Nao e usada pelo CSV.

9. Se `vendor/` nao existir, o que quebra?
   - Gerar contrato manualmente.
   - Gerar contrato apos pagamento.
   - Abrir contrato se ainda nao houver arquivo gerado.
   - Enviar contrato/recibo PDF via WhatsApp.
   - Testes de midia PDF da Evolution.
   - Nao quebra o relatorio CSV.

10. E seguro ignorar `vendor/` neste momento?
   - Apenas se voce decidir nao usar contratos/recibos PDF agora. Para o fluxo atual de relatorio CSV, `vendor/Dompdf` nao e necessario. Para o sistema completo com contrato, nao e seguro ignorar.

## Recomendacao

Se a prioridade imediata e subir o sistema que ja funciona na Hostinger com relatorio CSV, e aceitavel publicar sem `vendor/`, desde que o painel nao dependa de gerar contratos PDF.

Se a prioridade e usar contratos/recibos PDF, gere `vendor/` corretamente com Composer em ambiente local/SSH antes do deploy.

