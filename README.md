# LibreForms

Construtor de formulários: contato, cadastros e envios pelo site que criam posts, com e-mails, entradas salvas e webhooks.

Precisa dos plugins **libreblocks-core** e **libreforge** ativos.

- WordPress 6.6+ e PHP 8.0+
- Gutenberg e Elementor
- Licença: GPL-2.0-or-later (veja [LICENSE](LICENSE))
- Versão atual: 0.2.1

*Form builder: contact forms, sign-ups and front-end submissions that create posts, with e-mails, saved entries and webhooks.*

## Instalação

Baixe o zip pronto em [libreblocks.com.br](https://libreblocks.com.br) (ou no diretório do WordPress.org) e envie em **Plugins → Adicionar novo → Enviar plugin**.

## Desenvolvimento

Os plugins LibreBlocks são desenvolvidos lado a lado. Clone os repositórios na mesma pasta, com estes nomes:

```bash
git clone https://github.com/Libreblocks/libreblocks-core.git
git clone https://github.com/Libreblocks/libreforge.git
git clone https://github.com/Libreblocks/libreforms.git
```

Depois, em `libreblocks-core/`, rode `npm install` e crie um link de `node_modules` em `libreforms/`. Então:

- `npm run build` gera os arquivos em `build/`
- `phpunit` roda os testes de `tests/Unit` (sem banco de dados)
