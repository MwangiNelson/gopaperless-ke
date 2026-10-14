# SecurySign onboarding gate

How an OIDC user who has no usable SecurySign certificate gets routed through
TendaWorld's KES 300 payment and the SecurySign enrolment ceremony, then back to
the GoPaperless task they started.

Email/password users are untouched. The gate only fires for a logged-in session
that came from the configured `user_oidc` provider, so token signers, public
links and legacy accounts never see it and keep the local signing engine.

## Rollout status

| Piece | State |
|---|---|
| Page gate, onboarding handoff, return journey | code complete, unit tested, **off by default** |
| Signing API gate (`/api/v1/sign/...`) | code complete, unit tested |
| `force=1` handoff replacing a different local account | code complete, unit tested |
| Website payment and enrolment continuation | code complete, script tested |
| Importing the SecurySign visible signature | code complete, unit tested |
| Signing with the user's own SecurySign certificate | working against production SecurySign on the local stack (2026-09-28: `pdfsig` valid, issuer Signa Hardware CA). Off until the signing secret is set. See "Signing with the user's SecurySign certificate" |

Nothing is enabled until `securysign_provider_id` is set to a positive value.
With it unset, `SecurySignService::applies()` returns false everywhere and both
apps behave exactly as before.

## Config keys

GoPaperless: **Administration settings → LibreSign** has four SecurySign sections:

- **SecurySign connection:** the login provider and the SecurySign address.
  Both, and the signing secret, must belong to the same SecurySign (live or
  staging). To switch, change all three, and have that SecurySign list this
  site as an authorised signing origin.
- **Sign with SecurySign:** a switch and the signing (SSC) secret. The switch
  can only be on once a provider and the secret are set.
- **MIMI passkey check:** a switch, the MIMI address, client ID and secret. The
  switch can only be on once a provider and the secret are set. Someone with no
  MIMI passkey is sent to MIMI's `/enrol` to make one, whichever enrolment site
  is chosen, and comes back to the check. MIMI runs whatever GoPaperless's MIMI
  steps they haven't done (for someone who enrolled through the website, the ID,
  face and questions too) and moves their SecurySign certificate onto the new
  passkey. MIMI only returns people to addresses registered for the client.
- **Enrolment for users without a certificate:** the TendaWorld website or
  MIMI. MIMI enrolment uses the MIMI address and client ID from the section
  above, and MIMI only returns people to addresses registered for that client.

The same keys from the command line (app id `libresign`):

```bash
occ config:app:set libresign securysign_provider_id --value 2 --type integer
occ config:app:set libresign securysign_url        --value https://signa.dev.securysign.com
occ config:app:set libresign securysign_signing_secret --value '<SSC secret>' --sensitive
occ config:app:set libresign securysign_signing_enabled --value 1 --type boolean
occ config:app:set libresign mimi_url              --value https://gopaperless.mimi.ke
occ config:app:set libresign mimi_client_id        --value gopaperless
occ config:app:set libresign mimi_client_secret    --value '<MIMI secret>' --sensitive
occ config:app:set libresign mimi_passkey_enabled  --value 1 --type boolean
occ config:app:set libresign enrolment_site        --value tendaworld   # or mimi
occ config:app:set libresign tendaworld_url        --value https://tendaworld.com
occ config:app:set libresign oidc_sso_handoff_enabled --value 1 --type boolean
occ config:app:set libresign signing_card_layout   --value horizontal-top
```

- `signing_card_layout` is the card on a signed PDF: `horizontal-top` (the
  default), `horizontal` or `stacked`. It applies to documents signed with
  SecurySign and with GoPaperless's own certificate. `horizontal-top` is one
  composition scaled whole into the signature box: the visible signature
  scribble with ISSUER and TIMESTAMP small against its top right and the name
  under it. A tight box gets a smaller copy of the same card. The scribble is
  cut to its ink first, so the canvas margin it was drawn with takes no room.
- Both switches count as on until someone turns them off, so an instance that
  already has a secret keeps its behaviour.
- A MIMI address left in `tendaworld_url` is read as the MIMI address, and
  enrolment then uses https://tendaworld.com. Saving the SecurySign settings once
  moves it to `mimi_url`.

- `securysign_provider_id` is the `user_oidc` provider id, per instance. On
  `gopaperless.ke` that is `2`; `1` is the dead `ke_mimi_id` provider. Read it
  from the `initial-state-core-alternativeLogins` input on `<base>/login`.
  `0` or unset disables the gate.
- `oidc_sso_handoff_enabled` gates `/apps/libresign/sso`, the route the website
  links to for the return leg. Off by default, and with it off the route
  redirects to the app root, so the handoff looks like it silently does nothing.
- `securysign_url`, `mimi_url` and `tendaworld_url` must be bare origins: https everywhere,
  or http on `localhost` / `127.0.0.1` / `[::1]` so the flow can be driven on a
  local stack. A path, a query, a fragment or embedded credentials are rejected,
  and `localhost.example.com` is not loopback.
- **`tendaworld_url` and `mimi_url` can come from the environment.** Nextcloud maps any
  `NC_`-prefixed variable into system config, so `NC_tendaworld_url` on the
  container sets it with no occ step, which is what a Dokploy or compose deploy
  wants. GoPaperless reads app config first and falls back to the system
  value, so an image's env cannot quietly override an admin who ran occ. Verified
  on the local stack 2026-09-09:
  `docker exec -e NC_tendaworld_url=… occ config:system:get tendaworld_url`.

Website (`.env.local` / `.env.production`, all of them already exist):
`NEXT_PUBLIC_GOPAPERLESS_URL`, `NEXT_PUBLIC_GP_OIDC_PROVIDER_ID`,
`SIGNA_BASE_URL`, `PAYMENT_URL_BASE`, `PAYMENT_GATEWAY_KEY`,
`SIGNA_SESSION_SECRET`.

The gate reads the user's SecurySign access token through `user_oidc`'s
`TokenService`, so the provider needs **store login token** switched on.
Verified against user_oidc 8.11-dev in the local sandbox; 8.10 carries the same
`TokenService::getToken()`, `decodeIdToken()` and `Token::isExpired()` API.

## The journey

1. An OIDC user opens any LibreSign page. `SecurySignMiddleware::afterController`
   asks SecurySign for the certificate and the visible signature card, once for
   that signed-in session. The answer is reused until logout or an explicit fresh
   check, and the card is mirrored into LibreSign on each fresh check.
2. Ready (certificate active, inside its validity window, card bound to that
   `certificateId`) means the page renders untouched.
3. Not ready sends the user to `/apps/libresign/securysign/onboard?returnTo=...`,
   which mints a one-hour nonce in the Nextcloud session bound to the uid and the
   OIDC `sub`, then hands off to `<tendaworld>/onboarding/gopaperless?state=&subject=`.
4. The website seals the same pair into `gp_onboarding` (AES-256-GCM, one hour)
   and starts a fresh OIDC exchange. The live Keycloak session makes this
   prompt-free, so `prompt=select_account` is suppressed on this leg only.
5. `/onboarding/gopaperless/continue` re-checks the subject, then entitlement
   (`checkEntitlement`, backend first, unchanged), then the certificate and the
   card. It sends the user to `/subscribe` or `/enrol/start` as needed. No
   payment logic is duplicated.
6. Once both are in place it redirects to
   `<gopaperless>/apps/libresign/securysign/return?state=<nonce>`, which verifies
   nonce, expiry, uid and `sub`, forces a fresh readiness check, then drops the nonce and
   returns the user to the exact page from step 3.

An outage is not shown to users (since 2026-09-29). Pages render, and signing
falls back to the local engine (see "Signing with
the user's SecurySign certificate"). A rejected session still gets the branded
"Reconnect" page, because signing in again fixes it. `/enrol/start` returning "done"
while the certificate or card is still missing comes back as `completed=1`,
which reports the failure instead of bouncing again.

### Cookie lifecycle

`gp_onboarding` is dropped on success and on an account mismatch (both in
`/auth/callback` and in the continuation). Leaving it in place after a
wrong-account login would bounce every later website visit into the same 409 for
an hour. GoPaperless keeps its own nonce for that hour, so a fresh click there
resumes the same task. Two tabs on two Google accounts therefore end with one
tab finishing and the other told to restart from GoPaperless.

The continuation clears the website cookie before GoPaperless confirms. That is
deliberate: GoPaperless still holds the nonce, so the return leg is retryable.

### force=1

`gpLoginUrl()` now points at `/apps/libresign/sso?providerId=<id>&force=1`.
`/apps/user_oidc/login/<id>` ignores `force`: `LoginController::login()` returns
immediately when a session already exists, so a user signed in as a different
local account stayed on that account. `SsoController::handoff()` logs the local
session out first, then enters `user_oidc`, then returns through
`/apps/libresign/sso/complete`, which sanitises the redirect a second time
before sending the user on.

## The visible signature

**SecurySign does not compose a card.** Probed 2026-09-06 against live:
`/api/signature/visible` returned a 366x137 PNG of handwriting and nothing else,
and `/download` is the same bytes with tEXt chunks added. Signa's own
`RP_VISIBLE_SIGNATURES_USER_GUIDE` says it implements "a user-created PNG
signature image", and that PAdES appearance placement is "not yet implemented"
in the Java service. The website's profile panel had its plate and detail rows
deleted on the belief that Signa composited them in; it does not.

GoPaperless stores that handwriting **unchanged**. The account, issuer and
validity lines are added at signing time by LibreSign's own signature text
template, which already ships variables for all of them. An earlier pass here
composited a card with GD instead; that duplicated a feature the app has, so it
was deleted.

### The template

Set through `SignatureTextService::save()`, stored in app config
`signature_text_template`, rendered by Twig at signing time:

```
Account : {{SignerEmail}}
Issuer : {{IssuerCommonName}}
Date of Issue : {{CertificateValidFrom|date("j M Y")}}
Expiry Date : {{CertificateValidTo|date("j M Y")}}
```

Three things about `save()` that are not obvious:

- It runs **`strip_tags()`**. Only `<br>` and `</p>` survive, as newlines. A
  `<table>` collapses into one run-on line — which is what happened first.
- The QR is **not** drawn by the template. `save()` regex-matches the submitted
  text for `{{ qrcode }}` or a base64 `<img>`, sets `signature_stamp_has_qrcode`,
  and then strips the tag. `JSignPdfHandler::createQrOnlyBackground()` composites
  the QR onto the stamp from the document's validation URL. So submit the
  `<img src="data:image/png;base64,{{ qrcode }}">` form: it sets the flag and
  leaves no placeholder behind. A bare `{{ qrcode }}` would survive stripping and
  render a base64 blob as visible text.
- `signature_render_mode` must stay `GRAPHIC_AND_DESCRIPTION`, or the handwriting
  is dropped in favour of text alone.

### The fields describe the signing certificate, not the PDC

`SignFileService::buildBaseSignatureParams()` fills `IssuerCommonName`,
`CertificateValidFrom` and `CertificateValidTo` from `readCertificate()` — the
certificate that **actually signs**. Under the SecurySign engine that is the
user's SecurySign certificate, because `SecurySignHandler::readCertificate()`
parses the PEM from `pki/certificates/me`. Under the local engine it is
LibreSign's own, and the stamp reads "GoPaperless Local". Either way the stamp
names the certificate in the CMS.

### Mirroring

`readiness()` returns the handwriting and the certificate facts in the pair of
calls the gate already makes; the middleware hands that to
`syncVisibleSignature()`, which writes it as a LibreSign signature element
stamped with `metadata.securysign_certificate_id` and `metadata.securysign_card`
(`SecurySignService::CARD_VERSION`). A later page load does nothing while both
match; a renewed certificate replaces the element, and **bumping `CARD_VERSION`
re-imports for everyone**. Failures are logged and swallowed.

Only the visible signature scribble is imported, never SecurySign's card. It
comes from `POST /api/signature/visible/signing-context` when the account has a
verified ID, and otherwise from `GET /api/signature/visible/{sub}/image`. That
route serves the card when it has no scribble, tagged with the card's hash, and
GoPaperless refuses it. With no scribble from either, an import that may be the
card is deleted and the user is let in without a signature. GoPaperless asks
them to draw one ("Define your signature") and saves it to SecurySign with
`POST /api/signature/visible` and their login, so SecurySign keeps it for every
app. The log says `SecurySign has no visible signature scribble for this user;
they draw one in GoPaperless`. The line before it says why:

- `SecurySign sent its card, not the visible signature scribble`: SecurySign
  holds no scribble for the account, or it runs the image route that served
  every user the card on Postgres (fixed in SecurySign `fix/rp-image-route-scribble`).
- `SecurySign gave no visible signature scribble` with a status: a 403 while
  every other call succeeds means the client is missing Keycloak's `basic`
  scope; see "access tokens carry no `sub`" below.

A 5xx from that route is an outage, not "not ready".

A document signed with SecurySign takes the scribble from the signing context
too. When SecurySign has no context for the certificate, the card is drawn from
the certificate itself (its name and issuer) around the imported scribble, never
LibreSign's old appearance.

`JSignPdfHandler` stamps the element image into the PDF
(`$signatureImagePath = $element->getTempFile()`), so the handwriting plus the
template text plus the QR are what land on the page.

LibreSign's own signature module is the fallback. `hasMirroredSignature()` asks
whether a stored element carries `securysign_certificate_id`; only then are the
mutating endpoints refused. With nothing mirrored, because the import has not run
or has failed, the user can draw one and sign, and the next successful import
replaces it. Without that, a failed import leaves them told to draw a signature
the API will not let them draw, and `SignFileService::retrieveUserElement()`
refuses the signature with "You need to define a visible signature or initials to
sign this document."

Editing is refused at the endpoints that mutate — `createSignatureElement`,
`patchSignatureElement`, `deleteSignatureElement` return 403 while the gate
applies and a card is mirrored. Reads stay open. Do **not** wire this to
`SignerElementsService::canCreateSignature()`: false there means "no graphic
signature at all", which hides the Signatures view and makes `SignFileService`
drop user images from the PDF entirely.

## When the gate cannot answer

Every failure renders `templates/securysign_notice.php` through Nextcloud's guest
layout, so it carries the instance logo, theme and footer rather than plain text
on white. Each one offers a primary action and a link out of LibreSign entirely —
the rest of Nextcloud is never gated, so that link is the escape hatch when the
gate itself is what is broken.

| Situation | Status | What the user is offered |
|---|---|---|
| Signa answers 401 or 403 | 401 | "Sign in again", pointing at `/apps/libresign/sso?providerId=<id>&force=1` — one click that drops the local session and re-enters OIDC |
| Signa unreachable or 5xx | none | The page renders; signing falls back to the local engine |
| Onboarding round trip broken | 503 | "Start again" at the app root |

Upstream text never reaches the user. `SecurySignService::request()` logs the
status, the path and the first 500 bytes of Signa's body, which is the only place
that says whether a 401 was an expired token, a rejected audience or a
misconfigured introspection client. Signa tries introspection first and falls
back to local JWKS validation, so a 401 means **both** paths refused the token.

## Testing it on the local stack

The devcontainer stack in this repo bind-mounts it straight onto
`apps-extra/libresign`, so there is nothing to deploy:

```bash
docker compose -p devcontainer -f .devcontainer/docker-compose.yml \
  -f .devcontainer/docker-compose.signa-terms.yml up -d
```

The override pins PHP 8.3 and Nextcloud stable34. The base file defaults to PHP
8.2 and master, and NC 34's typed class constants fatal on 8.2. Nextcloud
answers on `http://localhost` (nginx publishes 80). The website runs next to it
on `http://localhost:3100`, not 3000, and reads `.env` in that repo.

Both on `localhost` means one cookie jar: cookies ignore ports, so the site's
chunked sealed session is sent to Nextcloud on every request too, and nginx
answers `400 Request Header Or Cookie Too Large` from its default 8k buffer.
`.devcontainer/nginx-header-buffers.conf` raises it and the signa-terms override
mounts it. Production hosts differ, so nothing there needs this.

Loopback origins are allowed over plain http, so both config values below are
accepted. Every other host must be https.

```bash
docker compose exec --user www-data nextcloud php occ   config:app:set libresign securysign_provider_id --value <local provider id> --type integer
docker compose exec --user www-data nextcloud php occ   config:app:set libresign securysign_url --value https://signa.dev.securysign.com
docker compose exec --user www-data nextcloud php occ   config:app:set libresign tendaworld_url --value http://localhost:3100
```

New PHP classes do not load until the autoloader knows about them. This repo
sets `classmap-authoritative` in `composer.json`, so Composer never falls back to
a PSR-4 filesystem lookup and `SecurySignService`, `SecurySignController` and
`SecurySignMiddleware` are invisible until the classmap is regenerated:

```bash
docker exec devcontainer-nextcloud-1   sh -c 'cd /var/www/html/apps-extra/libresign && composer dump-autoload --no-dev --no-scripts'
```

**`--no-dev` is not optional.** `nextcloud/ocp` is a require-dev package and
`autoload-dev` maps `OCP\` at it, so a plain `dump-autoload` writes a thousand-odd
OCP stub entries into an authoritative classmap. They shadow the server's own
OCP, `OC\TaskProcessing\Manager` then fails its `#[\Override]` check, and
Nextcloud fatals on every request. The container decides it is not installed and
enters a restart loop, at which point `docker exec` stops working and the way
back in is a one-off container:

```bash
docker run --rm --entrypoint composer -v "<repo>:/app" -w /app \
  ghcr.io/librecodecoop/nextcloud-dev-php83:latest dump-autoload --no-dev --no-scripts
```

The tests need the opposite. They mock OCP interfaces that exist only in those
stubs, so dump with dev to run the suite and with `--no-dev` to run the app.

It takes a few minutes over the Windows bind mount. Confirm it worked before
blaming anything else — a `false` here explains a gate that never fires:

```bash
docker exec devcontainer-nextcloud-1 php -r   'require "/var/www/html/apps-extra/libresign/vendor/autoload.php";
   var_dump(class_exists("OCA\Libresign\Service\SecurySignService"));'
```

The same applies to any deployment whose pipeline does not run `composer install`
after taking these files. It is also why `tests/php/securysign-bootstrap.php`
turns the authoritative classmap off.

Read the local provider id from `occ user_oidc:provider`; it is per instance and
almost certainly not the `2` that `gopaperless.ke` uses. Set
`NEXT_PUBLIC_GOPAPERLESS_URL=http://localhost` and
`NEXT_PUBLIC_GP_OIDC_PROVIDER_ID=1` in the website's `.env` so the return leg
comes back to the sandbox instead of production.

Two things live outside these repos and have to be right before the round trip
completes. The `user_oidc` provider needs **store login token** on, or
`identity()` refuses with "Sign in again to connect to SecurySign" — the gate
reads the SecurySign access token out of `TokenService`. And the Keycloak client
has to whitelist both loopback redirect URIs,
`http://localhost/apps/user_oidc/code` and `http://localhost:3100/callback`.
Neither is something this app can set.

The scope stays `openid signa-basic`. `GET /api/pki/certificates/me` and
`GET /api/signature/visible` both authenticate on the bearer token alone —
`requireAuth()` in Signa's `AuthFunctions.php` checks introspection, not scopes.
`signa-visible-signature` only governs the reference claims Keycloak exposes
through userinfo, which this gate never reads.

What the three states look like when you drive it:

| Signa state | What you should see |
|---|---|
| No certificate | `certificates/me` answers `{"status":"none"}`, any LibreSign page redirects to `/onboarding/gopaperless` on the website |
| Certificate, no card | certificate parses and is current, `signature/visible` 404s, same redirect |
| Both, card bound to the active `certificateId` | the page renders and nothing happens |

A brand-new Google account that has never touched Signa resolves there as user
id `0`, which returns `{"status":"none"}` rather than an error, so it lands in
onboarding rather than on the 503 page. That is the path worth walking first.

To take the gate back out, unset the provider id — `applies()` then returns
false everywhere and both apps behave exactly as they did before:

```bash
docker compose exec --user www-data nextcloud php occ   config:app:delete libresign securysign_provider_id
```

## Tests

GoPaperless (portable PHP 8.3, no Nextcloud server needed; the standalone
bootstrap turns off the authoritative classmap so the new classes load):

```bash
OPENSSL_CONF=<php-runtime>/extras/ssl/openssl.cnf \
php -d extension=mbstring -d extension=openssl vendor/bin/phpunit \
  --bootstrap tests/php/securysign-bootstrap.php --no-configuration \
  tests/php/Unit/Service/SecurySignServiceTest.php \
  tests/php/Unit/Controller/SecurySignControllerTest.php \
  tests/php/Unit/Controller/SsoControllerTest.php \
  tests/php/Unit/Middleware/SecurySignMiddlewareTest.php   tests/php/Unit/Handler/SignEngine/SecurySignHandlerTest.php
```

`SecurySignHandlerTest` prepares a real PDF through `PhpNativeHandler`, signs the
hash with a local P-256 key standing in for SecurySign's HSM, and has OpenSSL
verify the finished CMS over the byte range. Checked by hand on 2026-09-28 as
well: `pdfsig` reports "Signature is Valid" and "Total document signed" for the
same output, and LibreSign's own `TSA::getSigninTime()` reads its signing time.

`OPENSSL_CONF` only matters on Windows, where `openssl_csr_sign` cannot find a
config; without it the one certificate test skips instead of failing. The rest of
`tests/php/Unit` still needs the real `lib/base.php` and will not run here.

Website:

```bash
node node_modules/tsx/dist/cli.mjs scripts/test-gopaperless-onboarding.ts
node node_modules/typescript/bin/tsc --noEmit --incremental false
```

The script fakes the network, so it covers the bad-state 400, the wrong-account
409 and its cookie clear, the tampered and expired cookie, the unpaid and outage
branches, `completed=1`, the certificate validity window, the card-to-certificate
binding, the successful return with the nonce attached, and the `force=1` URL.

## Probed 2026-09-06: `signa-rp-15` access tokens carry no `sub`

Signa answers `401 {"error":"Token missing subject claim"}` to every call made
with an access token from the client the local `user_oidc` provider uses. Its
`AuthFunctions::resolveUser()` reads `sub ?? username ?? client_id` and throws
when all three are empty.

The id_token from the same login is complete; only the access token is stripped:

| Token | Claim names | `sub` | `aud` |
|---|---|---|---|
| id_token | `exp iat jti iss aud sub typ azp nonce sid at_hash preferred_username picture` | yes | yes |
| access token | `exp iat jti iss typ azp sid scope name preferred_username given_name family_name picture` | **no** | **no** |

Since Keycloak 25 the access token's `sub` is contributed by the built-in
**`basic`** client scope rather than being hardcoded; the id_token keeps `sub`
either way because OIDC requires it there. `signa-rp-15` does not have that scope.

Probed 2026-09-06 against the authorization endpoint, one scope at a time, the
same way the realm's requestable scopes were probed before:

| Client | `basic` | `roles` | `acr` | `web-origins` | `signa-basic` |
|---|---|---|---|---|---|
| `signa-rp-6` (website, dev) | **accepted** | invalid_scope | — | — | — |
| `signa-rp-15` (gopaperless) | **invalid_scope** | invalid_scope | invalid_scope | invalid_scope | accepted |

`roles` is rejected on both, so it is not the variable. `basic` is the only scope
that separates a client whose tokens Signa accepts from one whose tokens it
refuses.

**The fix is one assignment:** Keycloak → Clients → `signa-rp-15` → Client scopes
→ Add client scope → `basic` → Default. Nothing in either repo can add a claim to
a token it does not mint.

Probed 2026-10-12 on the production realm: `signa-rp-10` (staging) and
`signa-rp-16` (local) also answer `invalid_scope` to `basic`. There Signa
resolves the token by its `username` instead, so every call succeeds except
`/api/signature/visible/{sub}/image`, which answers 403 `Not authorized`
because the `sub` in the URL is not the username. Both clients need the same
assignment. `signa-rp-10` has had it since 2026-10-13. Each user signs out and
in once afterwards, so the session holds a token with `sub`.

To re-check both tokens, from PowerShell (claim **names** only — the values are
the user's identity):

```powershell
docker exec --user www-data devcontainer-nextcloud-1 php /var/www/html/apps-extra/libresign/docs/runbooks/show-token-claims.php
```

`show-token-claims.php` decrypts the id_token of each recent login out of
`oc_user_oidc_sessions` and reads the access token's names from the last refusal
in `nextcloud.log`, printing PRESENT/MISSING for `sub` on each. It is a file
rather than a documented one-liner because the equivalent inline PHP cannot be
quoted in PowerShell.

The access token alone, if the log is all you want:

```powershell
docker exec devcontainer-nextcloud-1 grep tokenClaims /var/www/html/data/nextcloud.log | Select-String -Pattern '"tokenClaims":"[^"]*"' -AllMatches | ForEach-Object { $_.Matches.Value }
```

Grep runs **inside** the container over the whole file. A `tail -c` window misses
the entry once the log grows, and PowerShell has no `grep` of its own.

## Signing with the user's SecurySign certificate

GoPaperless builds the signed revision itself and asks SecurySign for one thing:
a signature over one hash, made in its HSM with the key behind the user's
certificate. The key never leaves SecurySign.

1. The user clicks sign and confirms in the usual dialog. Skipping the dialog
   for SecurySign signers was tried twice and did not work (see PR #33), so it
   stays. `SignFileService::identifyEngine()` picks
   `SecurySignHandler` when `SecurySignService::signs()` is true, which needs a
   session from the SecurySign `user_oidc` provider and `securysign_signing_secret`
   set on the instance.
2. **Prepare.** The handler runs `PhpNativeHandler` with an external signer, so
   the stamp, the imported card and LibreSign's signature template land exactly
   as they do today. The signature slot is left as zeros. The CMS signed
   attributes (content type, signing time, the byte-range digest, and ESS
   signing-certificate-v2 naming the user's certificate) are built now and parked
   with the PDF in app data under `securysign/`, keyed by user and document. The
   sign API answers 422 with `action: 3600` and a `securysign` object: a signing
   token from `POST /api/ssc/token`, the SHA-256 of the signed attributes, the
   document name and SecurySign's origin.
3. The browser opens SecurySign's signing frame (`<securysign_url>/#/sign-frame`)
   in a dialog (`src/components/SecurySignApproval.vue`), and posts
   `SSC_SIGN_REQUEST` as soon as the frame reports its size, so the passkey
   prompt opens without a click on the frame's button. The prompt runs on
   SecurySign's origin. The page's CSP allows the frame through
   `SecurySignCspListener`; without it LibreSign's `frame-src 'self'` shows a
   broken frame. `/ssc/finalize` verifies the assertion, signs the hash
   with the user's HSM key (ECDSA P-256) and the frame posts `SSC_SIGN_COMPLETE`.
4. **Finalize.** The browser repeats the sign request with `securysignSignature`.
   The handler verifies it against the certificate from `pki/certificates/me` and
   answers 403 to anything else. Then it writes the CMS into the parked revision,
   and LibreSign stores the file and marks the request signed as it always has.

| Signer | Engine |
|---|---|
| Session from the SecurySign provider, secret configured | SecurySign, or the local engine while SecurySign is down |
| Everyone else: email/password accounts, token and email signers, public links | LibreSign's local engine |

When SecurySign cannot sign, LibreSign's local engine signs instead, with no
error. The user already confirmed in the dialog, whose button sends
`confirmed: true`, and that is what lets `SignFileService::signWithEngine()` sign
locally. A request without it (an API client) gets `action: 3700`
(`SecurySignUnavailable`) and has to repeat with `confirmed: true`. The PDF's
issuer tells the engines apart: Signa Hardware CA or GoPaperless Local.
The log records `SecurySign is unavailable, signing with the local engine`. After
a failure, `SecurySignService` skips SecurySign for that session for a minute, and
every call gives up after 3 s to connect or 10 s in all. That covers connection failures, timeouts, 5xx
answers, unreadable answers and a refused signing token, so a wrong
`securysign_signing_secret` also lands here: watch the log for it after
configuring. Problems the user can fix are shown instead and nothing is signed:
an expired or rejected SecurySign session (401, 403), no active certificate
(409), and a signature that does not verify. The split is
`SecurySignHandler::userFacing()`.

The fallback only sees what the server sees. If SecurySign answers our calls but
its frame fails at `/ssc/finalize` (an HSM outage, say), the user gets the
frame's error, not the local engine.
The signing token is not bound to a user (LOA-2), so any SecurySign passkey could
approve inside the frame. Only a signature from this user's key verifies at
finalize, so somebody else's approval is refused there.

### Which passkey, and MIMI passkeys

The signing token is LOA-4 whenever the certificate carries an email: the request
sends `loa: LOA-4` and that email, SecurySign binds the user's newest passkey, and
the frame asks for it under the RP ID it was registered with. That is what lets a
passkey created on `mimi.ke` sign here: `mimi.ke/.well-known/webauthn` lists
`securysign.com` (WebAuthn Related Origin Requests), so the browser allows it in
SecurySign's frame. The browser offers only that passkey. `signa-rp-16` was
approved for LOA-4 on 2026-09-29 (`credentialBound: true`); an RP that is not gets
its token refused, which signs with the local engine.

A certificate without an email gets an LOA-2 token, where any passkey registered
with SecurySign can approve and only `securysign.com` ones are offered. One from
another account signs with that account's key, finalize refuses it, and the user
reads "Wrong passkey. Choose the passkey for <certificate email>."

### Round trips

One signature costs three calls to SecurySign from the server: the certificate
and the signing token on prepare, the certificate again on finalize. Until
2026-09-29 the middleware also forced a fresh readiness check (two more calls) on
each of the two sign requests. Measured on the local stack for a 929 KB PDF:
preparing the revision 1.6 s, embedding 0.02 s, reading the signatures back
0.4 s, one unauthenticated call to securysign.com about 0.5 s.

### What SecurySign has to provide

Both are settings on the RP that is the `user_oidc` provider's client
(`signa-rp-18` on the local stack, `signa-rp-8` on `gopaperless.ke`). Both clients
are already approved RPs: on 2026-09-28 `/ssc/token` answered them with `Invalid
client credentials`, not `Unknown RP client_id` or `not approved`.

- **The SSC secret.** It is not the OIDC client secret. Signa computes it as
  HMAC-SHA256 of the client id under `SSC_MASTER_SECRET`, unless
  `SSC_CLIENT_SECRET_<CLIENT_ID>` overrides it. Ask SecurySign for it.
- **The GoPaperless origin as an authorised signing origin.** On 2026-09-28
  `GET /api/ssc/iframe-config?rpOrigin=http%3A%2F%2Flocalhost` answered
  `allowed:false` on dev. Without it the frame refuses with `RP origin not
  authorized`.

### Why not SecurySign's PAdES endpoints

Three defects in `signa-original` blocked this until now. On `production` as of
2026-09-28:

1. `SignaPadesServer.doPrepare` still drops the appearance options, so a PDF
   prepared there shows nothing.
2. `doFinalize` still falls back to an ephemeral P-256 key and a self-made
   certificate when `credentialID` is empty, and reports success.
3. Fixed by Signa #565: `padesFinalize` now verifies the passkey against the
   prepared hash.

The path above needs neither endpoint. The appearance is built here, and a
signature from any key but the user's fails verification, so the ephemeral
fallback cannot reach a document.

### Limits

- One visible box per signature. When a signer has two boxes on a document,
  only the first gets a stamp.
- Envelopes are refused for SecurySign signers. Each file would need its own
  passkey approval.
- No TSA timestamp yet. A document timestamp has to cover the finished
  signature, so it belongs after finalize, and nothing applies it there.
- SecurySign's CA certificate is not embedded. A validator needs it from
  elsewhere to build the chain.
- Signing is synchronous for these users; the async worker path is skipped.
- Not yet run against SecurySign itself. The local proof uses a stand-in key.
