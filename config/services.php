<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | TMDB
    |--------------------------------------------------------------------------
    |
    | 映画マスタ（docs/design.md 4.8.5 A-05）のタイトル検索とメタデータ取り込みに
    | 使用する（8.1）。`api_key` は TMDB の v3 APIキーを指す（v4 のリード
    | アクセストークンは形式が異なり、そのままでは認証されない）。
    | 未設定の場合、A-05 の検索は案内を表示して何も呼び出さない。
    |
    | エンドポイントと画像配信元は秘匿情報ではないため .env に置かない（17.9-1）。
    | `image_base_url` のホストは 17.7 のCSP（`img-src`）が許可する値と一致させる。
    |
    */

    'tmdb' => [
        'api_key' => env('TMDB_API_KEY'),
        'base_url' => 'https://api.themoviedb.org/3',
        'image_base_url' => 'https://image.tmdb.org/t/p',
        'language' => 'ja-JP',
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeder
    |--------------------------------------------------------------------------
    |
    | 初期データ投入（docs/design.md 9章）でのみ使用する。未設定の場合、
    | MasterDataSeeder がランダムなパスワードを生成しコンソールに表示する。
    |
    */

    'seed' => [
        'super_admin_password' => env('SEED_SUPER_ADMIN_PASSWORD'),
    ],

];
