# nexra-autopay

A fork of [mahdiMGF2/botmirzapanel](https://github.com/mahdiMGF2/botmirzapanel)
(GPLv3) with these additions baked in:

- **Nexra Panel integration** as a new panel type — user creation/edit/delete
  go through Nexra (so admin traffic quotas are enforced), while reads
  (status, subscription link, config links) go straight to the real Marzban
  instance for full fidelity.
- Adding/removing/editing a panel or its connection details is gated behind
  a secret code (`NEXRA_SECRET_CODE` in `config.php`).
- No more "unlimited" volume/time products or trial accounts (Nexra can't
  represent unlimited, so the bot no longer offers it anywhere).
- Trial accounts and paid purchases can offer the customer a choice between
  a custom username or a random one via two buttons, instead of a fixed
  admin-picked method.
- Rebranded welcome/admin messages (no upstream branding).
- **Automatic confirmation of card-to-card payments.** Each order is quoted an
  amount nobody else is waiting on (price + 1..300 toman). A companion Android
  app forwards the bank's SMS to the bot, and a deposit whose amount matches a
  reserved one is confirmed instantly through the same code path as the admin's
  approve button. Manual approval still works and takes the order out of the
  phone's reach, so nothing is ever credited twice. See
  [AUTOPAY.md](AUTOPAY.md); the app lives in [android/](android).

This repository continues [nexra-mirzabot](https://github.com/MHBehzadian/nexra-autopay):
same bot, plus the payment side.

## Install (one line)

```bash
N=7 TOKEN='123456:abc-token' DOMAIN='bot7.example.com' ADMIN='123456789' \
NEXRA_SECRET='your-secret-code' CERT_EMAIL='you@example.com' \
bash <(curl -sL https://raw.githubusercontent.com/MHBehzadian/nexra-autopay/main/install.sh)
```

Requires: DNS for `DOMAIN` already pointing at this server, nginx +
php8.1-fpm + mysql + certbot already installed, and `botmirzapanel4`-style
prior setup only if you're overlaying onto an older install — a fresh
install via this script needs none of that, it's self-contained.

`N` must be a bot number not already used on this server
(`/var/www/html/botmirzapanel<N>` must not exist yet).
