<?php

// Copy to config.php on the license server and fill in. config.php, keys/private.pem and data/ never leave that server.
return [
    // build.envato.com → Create token with the "View your items' sales history" permission.
    'envato_token' => 'PASTE-YOUR-ENVATO-PERSONAL-TOKEN',

    // Your CodeCanyon item id once FlowForge is approved (the number in its URL). 0 = accept any of your items.
    'item_id' => 0,

    'private_key' => __DIR__.'/keys/private.pem',

    // SQLite needs no setup. For MySQL: 'mysql:host=localhost;dbname=licenses;charset=utf8mb4' plus user/password.
    'dsn' => 'sqlite:'.__DIR__.'/data/licenses.sqlite',
    'db_user' => null,
    'db_password' => null,

    // Admin page (/admin). Create the hash with: php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
    'admin_password_hash' => '',

    // The live demo: entering this key as the purchase code on one of these domains activates demo mode.
    'demo_key' => 'DEMO-change-me-to-a-long-random-string',
    'demo_domains' => ['flowforge.sarimtools.com'],
];
