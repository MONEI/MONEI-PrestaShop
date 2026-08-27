# MONEI PrestaShop E2E suite

Playwright specs driving a real PrestaShop store against a real MONEI **test**
account. Every run takes a payment, so the seed refuses anything but a test key.

## What you need

- Docker, with the PrestaShop Flashlight stack from `docker-compose.yml` in the
  PrestaShop parent directory (project name `tunnel1`).
- A MONEI **test mode** API key.

## Setup

```bash
cp tests/playwright/.env.example tests/playwright/.env
# put your MONEI test key in MONEI_TEST_API_KEY
npm install
npx playwright install chromium
```

Bring the stack up from the PrestaShop parent directory:

```bash
docker compose up prestashop --force-recreate
```

The module is mounted into the container and installed by the
`init-scripts/module-install.sh` init script. Then seed:

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

## The tunnel is the flaky part

A free ngrok tunnel resets connections and gets a **new hostname every time the
agent reconnects**. Two consequences:

- Specs fail intermittently with `net::ERR_CONNECTION_RESET`. The config retries
  twice for this reason. A failure that survives the retries is real.
- After a reconnect the store keeps serving the previous hostname and 302s every
  request to a URL that now 404s. Re-run the seed: it re-points both
  `ps_shop_url` and the `PS_SHOP_DOMAIN` settings at the live tunnel.

Before debugging a red suite, check the tunnel:

```bash
docker compose restart ngrok && npm run test:e2e:seed
```

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
