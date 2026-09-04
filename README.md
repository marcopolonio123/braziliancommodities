# Brazilian Commodities

Site institucional da Brazilian Commodities, com portfólio, processo comercial, parceiros, formulário de cotação, envio SMTP e CAPTCHA visual.

## Publicação na Hostinger

1. Envie o conteúdo do repositório para a raiz `public_html`.
2. Copie `private/mail-config.example.php` para `private/mail-config.php`.
3. Preencha a senha SMTP somente no servidor.
4. Não envie `private/mail-config.php` ao GitHub.

O contador cria automaticamente o arquivo `private/visitor-count.txt` na primeira visita.

## Segurança do formulário

- CAPTCHA visual de uso único;
- campo invisível contra robôs;
- tempo mínimo para preenchimento;
- limite de tentativas por IP;
- validação de anexos e limites de tamanho.
