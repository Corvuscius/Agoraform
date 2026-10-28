<?php
// Configuration d'Agoraform / Agoraform settings
// AF_PRIVATE = dossier privé « agoraform-private » (défini par public/index.php).
return [
    // Langue par défaut et langues disponibles (une langue = un fichier lang/xx.php
    // + des colonnes label_xx / options_xx dans les descripteurs)
    'default_lang'    => 'fr',
    'langs'           => ['fr', 'en'],

    // Séparateur CSV (';' s'ouvre directement dans Excel en français, ',' ailleurs)
    'delimiter'       => ';',

    // Descripteurs de formulaires et fichiers de saisie.
    // Ces dossiers DOIVENT rester hors du dossier public : Agoraform refuse de
    // fonctionner s'ils se trouvent dans DOCUMENT_ROOT (www/ chez OVH).
    'forms_dir'       => AF_PRIVATE . '/forms',
    'data_dir'        => AF_PRIVATE . '/data',

    // Longueur maximale d'une réponse texte (caractères)
    'max_text_length' => 1000,

    // true = affiche les erreurs de descripteur à l'écran (à éviter en production)
    'debug'           => false,
];
