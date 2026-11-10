# Release checklist

Two security fixes (NFT-03, NFT-04) have been lost twice by branch merges that overwrote
`includes/config.php` and the account-sync code. Run every line below before tagging a release.

## Diff against the last audited commit

```
git diff <last-audited-commit> -- includes/config.php
git diff <last-audited-commit> -- includes/class-nft-checkout-payment-gateway-loader.php
```

Any change to the items below needs an explicit sign-off; a merge must never silently alter them.

## includes/config.php

- [ ] `NFT_CHECKOUT_PROTOCOL` is the literal `'https://'`. It must not use `is_ssl()` or any other store-dependent value (NFT-04).
- [ ] `NFT_CHECKOUT_PROD_HOST` is `rt.app` and `NFT_CHECKOUT_QA_HOST` is `qa.rt.app`.
- [ ] `NFT_CHECKOUT_HOST` falls back on `wp_get_environment_type()`: `development` / `staging` / `local` use the QA host, everything else uses production. A `NFT_CHECKOUT_HOST` defined in `wp-config.php` wins.
- [ ] `NFT_CHECKOUT_BASE_URL` strips any scheme from an overridden host and prefixes `https://`.
- [ ] `NFT_CHECKOUT_API_BASE_URL` is `NFT_CHECKOUT_BASE_URL . '/payment-api'`, and every API call carries the `/payment-api` prefix.
- [ ] `NFT_CHECKOUT_PLUGIN_VERSION` matches the plugin header `Version:` and the `Stable tag` in `readme.txt` / `README.md`, and is higher than the version stores already run (NFT-44).

## Account sync request (`handle_cron_event` -> `/sync-account-status`)

- [ ] The request body contains **no** `secret_key` field (NFT-03). `grep -n "'secret_key' *=>" includes/` must return nothing.
- [ ] Each account entry has `public_key`, `mode`, `timestamp` and `signature = HMAC-SHA256(secret_key, timestamp . '.' . public_key)`.
- [ ] The request carries `Authorization: Bearer <first public key>`, `X-Timestamp` and `X-Signature = HMAC-SHA256(secret_key, X-Timestamp . '.' . raw_body)`.
- [ ] The signature format above has been confirmed with the payment API team for this release.

## Merchant-facing links (NFT-46)

- [ ] Documentation and Support links in `includes/class-nft-checkout-payment-gateway-loader.php`, `readme.txt` and `README.md` point at `https://rt.app/...`, never `qa.rt.app`.

## Regression tests

- [ ] Classic and block checkout.
- [ ] Webhooks: valid, expired, replayed, wrong amount, wrong currency, unsigned.
- [ ] Customer return URL queues a background check (Action Scheduler) without waiting.
- [ ] The 2-hour cron completes paid pending orders.
- [ ] Account sync with the signed request.
- [ ] Cancellation on a store with two accounts.
- [ ] Sandbox mode.
- [ ] A staging site overriding `NFT_CHECKOUT_HOST` in `wp-config.php`.
- [ ] The `/payment-api` path prefix on every API call.
