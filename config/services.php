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
    | Stripe
    |--------------------------------------------------------------------------
    |
    | 決済（docs/design.md 8.2 / 17.3）に使用する。テストモードのキーのみを扱う
    | （2.5.1「本番決済」は適用範囲外）。
    |
    | `key` は公開可能キー（`pk_test_...`）であり、決済画面（P-36）が Stripe Elements
    | の初期化のためにブラウザへ渡す。`secret` はサーバー側でのみ用い、画面・ログの
    | いずれにも出さない（17.3-6 / 17.9-1）。
    |
    | 未設定の場合、決済画面は案内を表示し Stripe を呼び出さない（8.1 の TMDB と同じ扱い）。
    |
    */

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile
    |--------------------------------------------------------------------------
    |
    | お問い合わせ（docs/design.md 4.9.2「ボット対策」/ 4.9.8）のボット対策に使用する。
    | Cloudflare のアカウント登録は行わず、公開されているテスト用キーのみを扱う
    | （4.9.2-1）。`site_key` と `secret_key` は**必ずテスト用同士で組み合わせる**こと
    | （4.9.2-2）。本番用のシークレットキーはテスト用サイトキーが発行するトークン
    | （`XXXX.DUMMY.TOKEN.XXXX`）を拒否するため、混在させると検証が必ず失敗する。
    |
    | `site_key` はブラウザへ渡してウィジェットの初期化に使う（P-14 が
    | `turnstile.render()` へ渡す）。`secret_key` はサーバー側の siteverify
    | （`TurnstileService::verify()`）でのみ用い、画面・ログのいずれにも出さない
    | （4.9.8「ログ」/ 17.9-1）。
    |
    | `verify_url` はエンドポイントであり秘匿情報ではないため .env に置かない
    | （17.9-1。`tmdb.base_url` と同じ扱い）。
    |
    | 未設定の場合、`site_key` はウィジェットを描画せず送信できない旨を案内し、
    | `secret_key` は `TurnstileService::verify()` が外部を呼ばずに失敗を返す
    | （15.1 の Stripe・TMDB と同じ扱い）。
    |
    */

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
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
