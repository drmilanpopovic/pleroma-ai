<?php
// Kopirati izvan javnog web direktorijuma kao config.php. Ne slati ključeve u čet.
return [
    'base_url' => 'https://www.epsihijatar.net/ordinacija',
    'data_dir' => __DIR__ . '/private-data',
    'encryption_key' => '', // 64 hex znaka: php tools/init.php
    'setup_token' => '',    // 64 hex znaka: php tools/init.php
    'allow_local_http' => false,
    'openai_api_key' => '',
    'openai_model' => '',   // Model dostupan na vašem API nalogu.
    'transcription_model' => 'whisper-1',
    'google_client_id' => '',
    'google_client_secret' => '',
    'google_calendar_id' => 'primary',
    'doctor_name' => 'Spec. dr med. Milan Popović',
    'practice_name' => 'Pleroma ordinacija',
];
