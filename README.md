# Brazilian Commodities

Site institucional da Brazilian Commodities, com portfólio, processo comercial, parceiros, formulário de cotação, envio SMTP e CAPTCHA visual.

## Publicação na Hostinger

1. Publique o conteúdo do repositório na raiz `public_html`.
2. No mesmo diretório de `public_html`, crie a pasta persistente `private`.
3. Copie `public_html/private/mail-config.example.php` para `private/mail-config.php` (fora de `public_html`).
4. Preencha a senha SMTP somente no servidor e nunca envie esse arquivo ao GitHub.

O contador usa `private/visitor-count.txt` fora de `public_html`, preservando o total entre deployments. Durante a migração, se a pasta persistente ainda não existir, o código usa temporariamente `public_html/private`.

## Segurança do formulário

- CAPTCHA visual de uso único;
- campo invisível contra robôs;
- tempo mínimo para preenchimento;
- limite de tentativas por IP;
- validação de anexos e limites de tamanho.
