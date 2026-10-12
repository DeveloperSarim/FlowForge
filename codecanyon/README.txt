FlowForge {{VERSION}}
Self-hosted workflow automation for PHP hosting
=================================================

Full guide: open Documentation/index.html in your browser.
Support and free installation: support@sarimtools.com


WHAT'S IN THIS FOLDER

  flowforge-install-{{VERSION}}.zip   The app. Upload this for a new installation.
  flowforge-update-{{VERSION}}.zip    For updating an existing installation
                                 (Settings > Updates). Not needed for a new install.
  extract-flowforge.php          Unpacks the install zip on your server if your
                                 File Manager times out while extracting it.
  Documentation/                 The full guide, with screenshots.
  Licensing/                     Third-party open-source licenses.


QUICK INSTALL (shared hosting: Hostinger hPanel or cPanel)

  1. Set the domain's PHP version to 8.2 or newer.
  2. Create an empty MySQL database and a user with all privileges on it.
  3. Upload flowforge-install-{{VERSION}}.zip into the domain's folder
     (e.g. public_html) and extract it there.
     If you don't see the "public" and "vendor" folders afterwards, also upload
     extract-flowforge.php next to the zip and open
     https://your-domain.com/extract-flowforge.php
  4. Open https://your-domain.com/install and follow the six steps:
     server check, purchase code, database, admin account, email, cron job.
  5. Add the one cron job the wizard shows you. It runs every workflow.

VPS and step-by-step panel guides are in the documentation.


YOUR LICENSE

  Your purchase code activates FlowForge on one domain. Local copies
  (localhost, *.test) are free. Moving to a new domain: Settings > License >
  Deactivate this domain, then activate on the new one.


FREE INSTALLATION

  Email support@sarimtools.com with your purchase code, your domain and your
  hosting panel (hPanel or cPanel), and we'll install it for you.
