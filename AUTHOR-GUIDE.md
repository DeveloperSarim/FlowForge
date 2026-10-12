# Author guide (not shipped to buyers)

How to run the license server, the live demo and the docs, and how to publish FlowForge on CodeCanyon.
`scripts/make-release.sh` and `scripts/make-codecanyon.sh` exclude this file, `license-server/`, `documentation/` sources and `codecanyon/` from the app zips.

## 1. Deploy the license server (do this first)

Every install asks the license server at `https://license.sarimtools.com` (set in `config/flowforge.php`) to activate a purchase code. Until it is live, nobody can activate.

1. **Create the subdomain.** In hPanel, open Domains → Subdomains and create `license.sarimtools.com`. Turn on SSL for it (Security → SSL). It needs PHP 8.1+ with `pdo_sqlite` (or MySQL), `openssl` and `curl`.
2. **Upload these files** into the subdomain's folder:
   - `license-server/index.php`
   - `license-server/.htaccess`
   - `license-server/config.example.php`
3. **Upload the private key.** Create a `keys` folder there and upload `license-server/keys/private.pem` from your Mac.
   - This key signs every license. If someone gets it, they can make licenses.
   - If you lose it, installed copies can't be re-activated until you ship an update with a new public key.
   - **Back it up somewhere safe and offline** (password manager, encrypted USB). Never commit it to git, email it or put it in a zip.
4. **Create a `data` folder** next to `index.php`. The SQLite database is created there automatically, and `.htaccess` blocks web access to it.
5. **Create `config.php`.** Copy `config.example.php` to `config.php` and fill in:
   - **`envato_token`:** create it at <https://build.envato.com/create-token/> and tick **"View your items' sales history"**.
   - **`item_id`:** leave it `0` until FlowForge is approved, then set the number from the item URL (`codecanyon.net/item/flowforge/12345678`). With `0`, codes for any of your items are accepted.
   - **`admin_password_hash`:** run `php -r "echo password_hash('a-long-password', PASSWORD_DEFAULT);"` on your Mac and paste the output.
   - **`demo_key`:** a long random string, for example from `openssl rand -hex 24`.
   - **`demo_domains`:** the live demo's domain (default `flowforge.sarimtools.com`).
6. **Test it.** Run this from your Mac. You should get `{"message":"That doesn't look like a purchase code…"}`. That reply means the server, config and database work.
   ```bash
   curl -s -X POST https://license.sarimtools.com/api/v1/activate -H 'Content-Type: application/json' \
     -d '{"purchase_code":"test","domain":"example.com","product":"flowforge"}'
   ```
7. **Admin page:** `https://license.sarimtools.com/admin` lists every activation. Use it to manage buyers:

   | Action | What it does |
   | --- | --- |
   | Release domain | A buyer asks to move domains and can't deactivate the old site. Their old site stops at its next daily check. |
   | Revoke (with a reason) | Use after a refund or abuse. The buyer sees the reason and your support email. |
   | Restore | Undoes a revoke. |

### What the protection does, and its limits

**What it does:**
- Licenses are RSA-signed by the license server. The app holds only the public key (`app/Domain/License/LicenseKey.php`), so a license file can't be forged or edited, or copied to another domain.
- Each purchase code is checked with Envato and bound to one production domain. Local hosts don't count.
- The app re-checks the license daily. You can revoke a license from `/admin`, and it stops within a day. If the server can't be reached, the app keeps working for 14 days.
- Without a license, the installer stops at step 2, the app shows only the activation page, and no workflows run (editor, webhooks, schedules and polling).

**Its limits:**
- No PHP product can be made 100% "nulled-proof". Envato requires readable (non-obfuscated) code, so a determined person can edit the PHP to remove the checks.
- What you control is that their copy gets no updates, support, free installation or new integrations, and any license you see abused can be revoked.

## 2. Your own sites

Your own copies (local dev, your production site) don't need a purchase code. Sign a license file with the private key, on your Mac:

```bash
php license-server/issue.php your-domain.com > license.json
```

Upload `license.json` to that site as `storage/app/license.json`. For the local copy, run it with `127.0.0.1` and write straight into `storage/app/license.json`.

These "author" licenses pass the daily check because they're signed with your key. Your older live site will show the activation page after it updates to 1.0.0 until you do this.

## 3. The live demo (`flowforge.sarimtools.com`)

The demo uses the normal install zip. What makes it a demo is the `demo_key` from step 1.

1. **Install as usual** on `flowforge.sarimtools.com`. At the license step, enter your **`demo_key`** instead of a purchase code. The key only works on the domains in `demo_domains`.
2. **Finish the wizard** with your own admin account and a strong password. The demo visitor account is created automatically (`demo@flowforge.sarimtools.com` / `demo12345`).
3. **Sign in with your admin account** and open Settings → License → **Live demo**:
   - Change the visitor email and password if you like. The login page shows them in a "Sign in to the demo" box.
   - Build a few nice sample workflows, then click **Save current workflows as the starting point**. Every hour the demo goes back to exactly these workflows, and visitors' chats are cleared.
4. **What visitors can do:**
   - look at every page;
   - create, edit and duplicate workflows;
   - use templates;
   - chat with the AI Builder (40 messages per hour).
5. **What visitors can't do:**
   - delete anything;
   - add or see credentials, or connect apps;
   - run, test or switch on workflows;
   - change any setting, team, license or update.
   - Your email settings and team emails are hidden from them.
6. **Optional:** add an AI provider key under Settings → AI builder so the AI Builder works in the demo. Use a key with a low spending limit.

## 4. Host the documentation

`documentation/` is a static site: `index.html` plus `assets/`. There is no build step.

1. Create a subdomain or folder, for example `docs.sarimtools.com/flowforge/`, and upload the folder's contents there.
2. The item description links to that address; change it in `codecanyon/item-description.html` if you use another one.
3. Don't put the docs inside FlowForge's own `public/` folder: updates replace that folder.

## 5. Publish on CodeCanyon

1. **Build the upload set:**
   ```bash
   bash scripts/make-codecanyon.sh 1.0.0
   ```
   It writes `dist/codecanyon/`:

   | File | Where it goes on the upload form |
   | --- | --- |
   | `flowforge-codecanyon-1.0.0.zip` | **Main file.** Contains the install and update zips, `extract-flowforge.php`, `Documentation/`, `Licensing/` and `README.txt`. |
   | `thumbnail-80x80.png` | **Thumbnail.** |
   | `inline-preview-590x300.png` | **Inline preview image.** |
   | `screenshots-1.0.0.zip` | **Screenshots / preview images.** |

2. **Upload form settings:**
   - **Category:** PHP Scripts → Project Management Tools (or Miscellaneous).
   - **Description:** paste `codecanyon/item-description.html`.
   - **Demo URL:** `https://flowforge.sarimtools.com`.
   - **Software version:** PHP 8.2, 8.3, 8.4; MySQL 5.x/8.x.
   - **Files included:** PHP, JavaScript JS, CSS, HTML.
   - **Compatible browsers:** Chrome, Firefox, Safari, Edge.
3. **Reviewers:** Envato's reviewers don't have a purchase code, and the installer needs one. Before you submit, decide how they will test FlowForge, for example the live demo, or whatever Envato's current author guidance allows. Explain it in the "message to the reviewer".
4. **After approval:** set `item_id` in the license server's `config.php`.

### Releasing an update

1. Update the changelog in `documentation/index.html`.
2. Run `bash scripts/make-codecanyon.sh 1.1.0`. The script puts the new version number into the docs' file names and badge.
3. Upload the new main file on CodeCanyon.
4. Buyers update in Settings → Updates with `flowforge-update-1.1.0.zip`.

## 6. Support and free installation

- **Support email:** support@sarimtools.com. Every license message in the app and docs points there.
- **Free installation (hPanel/cPanel):**
  - Ask for the purchase code first and check it on `/admin` or in your Envato sales.
  - Ask for temporary access: Hostinger has account sharing; cPanel can use a temporary password.
  - Never ask buyers to email passwords before you've replied.
