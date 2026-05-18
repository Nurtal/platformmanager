# Roadmap de mise à jour — PlatformManager

État initial : PHP 8.0.7 (EOL nov 2023), dépendances datant de 2021-2022, plusieurs CVEs connues.

---

## Phase 1 — Audit de sécurité

CVEs identifiées manuellement sur les versions verrouillées dans `composer.lock` :

| Package | Version vulnérable | CVE | Sévérité | Description |
|---|---|---|---|---|
| `firebase/php-jwt` | v5.5.1 | CVE-2021-46743 | CVSS 9.1 | Algorithm confusion attack — un attaquant peut forcer l'algorithme `none` ou substituer une clé publique RSA à la place d'un secret HMAC |
| `guzzlehttp/guzzle` | 7.4.1 | CVE-2022-29248 | High | SSRF via changement de schéma HTTP→HTTPS lors des redirections |
| `guzzlehttp/guzzle` | 7.4.1 | CVE-2022-31090 | High | Injection d'en-têtes Authorization lors des redirections cross-origin |
| `guzzlehttp/guzzle` | 7.4.1 | CVE-2022-31091 | High | Même vecteur via `curl` handler |
| `league/commonmark` | 1.6.7 | XSS non backporté | Medium | Corrections XSS présentes uniquement en v2, jamais portées vers v1 |

---

## Phase 2 — Dockerfile

### Fait
- `FROM php:8.0.7-apache` → `FROM php:8.3-apache` (LTS, EOL novembre 2026)
- Suppression de `libc-client2007e` et `libc-client2007e-dev` : ces paquets ont été retirés de Debian Bookworm (base de PHP 8.3). PHP 8.0+ embarque sa propre implémentation c-client en interne ; seuls `libkrb5-dev` et `libssl-dev` sont nécessaires au build.
- Remplacement des dépendances runtime IMAP : `libc-client2007e libkrb5-3` → `libkrb5-3 libssl3`
- `docker-compose-wait` 2.6.0 → 2.12.1
- Suppression du bloc commenté mort (tini, abandonné)

### Commande de build
```bash
cd docker
docker build -t pfm .
```

---

## Phase 3 — Dépendances PHP (`composer.json`)

`composer.lock` supprimé pour forcer la résolution aux nouvelles contraintes lors du prochain `composer install`.

### Mises à jour de sécurité (breaking changes inclus, corrections de code effectuées)

| Package | Avant | Après | Raison |
|---|---|---|---|
| `firebase/php-jwt` | ^5.4 | ^6.10 | CVE-2021-46743 — API v6 requiert `Key` object |
| `guzzlehttp/guzzle` | ^7.0.1 | ^7.9 | CVE-2022-29248 / -31090 / -31091 |
| `guzzlehttp/psr7` | ^2.0.0 | ^2.7 | Suivi de guzzle |
| `league/commonmark` | ^1.6 | ^2.4 | XSS fixes, `convertToHtml()` → `convert()` |

### Mises à jour majeures (breaking changes, code adapté)

| Package | Avant | Après | Notes |
|---|---|---|---|
| `monolog/monolog` | ^2.2 | ^3.6 | Processor reçoit `LogRecord` objet en v3 (plus un tableau) |
| `phpoffice/phpspreadsheet` | ^1.18 | ^2.1 | API de style array compatible ; PHP 8.1+ requis |
| `symfony/yaml` | ^6.0 | ^7.1 | API compatible ; PHP 8.2+ requis (couvert par PHP 8.3) |

### Mises à jour mineures (compatibles)

| Package | Avant | Après |
|---|---|---|
| `phpmailer/phpmailer` | ^6.4 | ^6.9 |
| `maximebf/debugbar` | ^1.16 | ^1.22 |
| `php-amqplib/php-amqplib` | ^3.0 | ^3.7 |
| `twig/twig` | ^3.3 | ^3.14 |
| `league/html-to-markdown` | ^5.0 | ^5.1 |
| `promphp/prometheus_client_php` | ^2.2 | ^2.9 |
| `spipu/html2pdf` | ^5.2.4 | ^5.2 |

### Packages maintenus en version majeure actuelle (risque de breaking change non testé)

| Package | Version | Raison du gel |
|---|---|---|
| `sentry/sdk` | ^3.22 | v4 supprime `\Sentry\configureScope()` utilisé dans `Controller.php` |
| `influxdata/influxdb-client-php` | ^2.9 | v3 a des breaking changes sur l'API client InfluxDB, usage intensif dans `Framework/Statistics.php` |

### Dev

| Package | Avant | Après |
|---|---|---|
| `phpunit/phpunit` | ^9.5 | ^11.0 |

### Régénérer composer.lock
```bash
# Via Docker (recommandé)
docker run --rm -v "$(pwd):/app" composer:2 install

# En local si PHP 8.3 + Composer disponibles
composer install
```

---

## Phase 4 — Corrections de code (breaking changes)

### `firebase/php-jwt` v5 → v6
**Fichier :** `Modules/core/Controller/CoreaccountController.php`

L'API de décodage a changé : le 2e argument n'est plus une string mais un objet `Key`.
L'algorithme est maintenant obligatoire dans `encode()`.

```php
// Avant (v5)
use Firebase\JWT\JWT;
JWT::decode($token, $secret, array('HS256'));
JWT::encode($payload, $secret);

// Après (v6)
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
JWT::decode($token, new Key($secret, 'HS256'));
JWT::encode($payload, $secret, 'HS256');
```

### `monolog/monolog` v2 → v3
**Fichier :** `Framework/Configuration.php`

En v3, les processors reçoivent un objet `LogRecord` immutable (plus un tableau).
La mutation se fait via `$record->with(extra: $extra)`.
Import inutilisé `TagProcessor` supprimé.

```php
// Avant (v2)
self::$logger->pushProcessor(function ($entry) {
    $entry['extra']['user'] = $user;
    return $entry;
});

// Après (v3)
self::$logger->pushProcessor(function (\Monolog\LogRecord $record) {
    $extra = $record->extra;
    $extra['user'] = $user;
    return $record->with(extra: $extra);
});
```

### `league/commonmark` v1 → v2
**Fichiers :** `Modules/core/Controller/CoretilesController.php`, `Modules/helpdesk/Controller/HelpdeskController.php`

`convertToHtml()` retourne un objet `RenderedContent` en v2 (plus une string).
Migration vers `convert()` avec cast explicite.

```php
// Avant (v1)
$content = $converter->convertToHtml($content);

// Après (v2)
$content = (string) $converter->convert($content);
```

---

## Phase 5 — Services Docker (`docker/docker-compose.yml`)

| Service | Avant | Après | Notes |
|---|---|---|---|
| `rabbitmq` | 3.8 | 3.13 | 3.8 EOL depuis déc 2022 |
| `influxdb` | 2.0 | 2.7 | Dernière 2.x stable |
| `mysql` | :8 (non pinné) | 8.4 | Version LTS pinned |
| `redis` | 6.2.6 | 7.4 | Redis 6 EOL mars 2024 |
| `grafana` | non pinné | 11.4.0 | Tag flottant → version fixe |

Suppression de la clé `version:` dépréciée en tête du fichier (Compose spec actuelle ne l'utilise plus).

---

## Phase 6 — Dépendances JS (`externals/package.json`)

Mises à jour dans la même branche majeure pour éviter des changements d'API :

| Package | Avant | Après | Notes |
|---|---|---|---|
| `bootstrap` | ^5.1.3 | ^5.3.0 | Composants accessibilité améliorés |
| `bootstrap-icons` | ^1.7.2 | ^1.11.0 | +300 nouvelles icônes |
| `datatables.net-bs5` | ^1.11.4 | ^1.13.0 | Resté en v1 (v2 = breaking) |
| `lodash` | ^4.16.0 | ^4.17.21 | Patch CVE ReDoS |
| `marked` | ^4.0.12 | ^4.3.0 | Resté en v4 (v5+ = async API) |
| `moment` | ^2.29.2 | ^2.30.1 | Patch mineur |
| `vuedraggable` | ^2.24.3 | inchangé | v4 = réécriture complète Vue 3 |

### Régénérer package-lock.json
```bash
cd externals
npm install
```

---

## Phase 7 — Configuration tests (`phpunit.xml`)

Mise à jour du schéma XML pour PHPUnit 11 :
- Ajout de `xmlns:xsi` et `xsi:noNamespaceSchemaLocation`

---

## Travaux restants / points d'attention

### À faire avant mise en production
1. **Régénérer `composer.lock`** : `docker run --rm -v "$(pwd):/app" composer:2 install`
2. **Régénérer `package-lock.json`** : `cd externals && npm install`
3. **Lancer les tests** : `docker compose -f docker/docker-compose.yml run --rm pfm vendor/bin/phpunit`
4. **Rebuild complet de l'image** : `docker build -f docker/Dockerfile -t pfm .`

### Upgrades majeurs non effectués (nécessitent tests)
- **`sentry/sdk` v3 → v4** : `\Sentry\configureScope()` supprimé en v4. Migrer vers `\Sentry\withScope()` dans `Framework/Controller.php`.
- **`influxdata/influxdb-client-php` v2 → v3** : API `Client` modifiée, usage intensif dans `Framework/Statistics.php`.
- **`datatables.net-bs5` v1 → v2** : API JS incompatible, nécessite réécriture des initialisations.
- **`marked` v4 → v9+** : `parse()` devient asynchrone, nécessite réécriture de tous les appels.
- **`vuedraggable` v2 → v4** : Réécriture complète Vue 3, incompatible avec l'API actuelle.

### Recommandations
- Activer `composer audit` en CI pour détecter les CVEs en continu
- Passer `npm audit` dans le pipeline sur `externals/`
- Envisager de migrer `moment.js` (déprécié) vers `day.js` ou `date-fns` à terme
