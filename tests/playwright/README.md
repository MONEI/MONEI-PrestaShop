# MONEI PrestaShop E2E suite

Playwright specs driving a real PrestaShop store against a real MONEI **test**
account. Every run takes a payment, so the seed refuses anything but a test key.

## What you need

- The dev environment: **https://github.com/MONEI/monei-prestashop-dev-env**. It
  brings up PrestaShop behind an HTTPS tunnel and mounts this module into it. Its
  README is the setup guide; everything below assumes the stack is running.
- A MONEI **test mode** API key.

## Setup

```bash
cp tests/playwright/.env.example tests/playwright/.env
# put your MONEI test key in MONEI_TEST_API_KEY
npm install
npx playwright install chromium
```

Bring the stack up from your `monei-prestashop-dev-env` checkout:

```bash
docker compose up -d
```

The module is mounted into the container and installed by that repo's
`init-scripts/module-install.sh`. Then seed:

```bash
npm run test:e2e:seed
```

Seeding writes the MONEI test credentials into the store, records the product ids
the specs shop with in `.fixtures.json`, and resets the module.

## Running

```bash
npm run test:e2e
```

## 3D Secure needs a public HTTPS store

Card journeys cannot complete against `http://localhost:8000`. 3DS sends the
shopper's browser to the issuer and frames the challenge over HTTPS, and MONEI
must be able to reach the store to do it. A localhost store satisfies neither
half, so the challenge never renders and the spec waits for an order confirmation
that can never arrive.

This is why the Flashlight compose file ships an ngrok service. Start it, then
point the suite at the tunnel:

```bash
MONEI_E2E_BASE_URL=https://your-tunnel.ngrok-free.dev npm run test:e2e
```

Specs that need 3DS skip with a reason when the base URL is not HTTPS, rather
than failing.

## The tunnel

3D Secure needs a public HTTPS origin, so the stack fronts the store with a
**Cloudflare Quick Tunnel** (`cloudflared`). It replaced ngrok, whose free tier
caps HTTP requests and starts serving `ERR_NGROK_727` partway through a run —
which looks exactly like the store breaking.

The hostname changes on every start and is only announced in the container log,
so the seed reads it from there and re-points both `ps_shop_url` and the
`PS_SHOP_DOMAIN` settings at it. If the store starts 302-ing to a dead hostname,
re-run the seed.

The tunnel terminates TLS and forwards plain HTTP, so PrestaShop cannot tell the
request was HTTPS and builds `http://` links. Following one drops the secure
session cookie, which shows up as being bounced to the login page mid-session for
no visible reason. The dev environment's `init-scripts/06-https-behind-proxy.sh`
fixes that, and `07-php-fpm-workers.sh` raises the worker count — the image ships
five, and order confirmation exhausts them until nginx answers 502 on payments
that actually succeeded. Both run automatically at boot.

Specs still retry twice, for transport failures. A failure that survives the
retries is real.

## PrestaShop version

The stack runs whatever `PS_IMAGE_TAG` selects, defaulting to `latest` — currently
the **9.x** line, with `hummingbird` as the active theme. This suite is verified on
9.1.4. See the dev environment README for pinning an 8.x tag.

## Test cards and sandbox accounts

Use the published values at https://docs.monei.com/testing. Do not invent new
ones, and never commit a credential — `.env` and `.fixtures.json` are gitignored.

## Layout

| Path | What it is |
| --- | --- |
| `playwright.config.js` | Single worker, non parallel: the suite mutates global store state |
| `seed.js` | Configures credentials, records product fixtures |
| `utils/env.js` | Environment reading, base URL, 3DS capability |
| `utils/ps-cli.js` | Everything that talks to the container: console, PHP eval, MySQL |
| `utils/fixtures.js` | Reads and writes `.fixtures.json` |
| `specs/` | The specs themselves |

## Why `ps-cli.js` evaluates PHP

PrestaShop has no `wp eval` equivalent. Its console exposes a fixed command list
with nothing that reads or writes a `Configuration` value, so fixtures bootstrap
`config/config.inc.php` and call the same API the module itself uses. That keeps a
fixture from writing database rows behind the module's back.

## Dependencies

The repo gitignores every lockfile, so the root `package.json` is installed with
plain `npm install` and no lockfile is committed. It is development tooling only
and is excluded from the merchant release ZIP.
