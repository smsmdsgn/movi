<?php

namespace Database\Seeders;

use App\Enums\BannerPosition;

/**
 * シーダーの生成規模・固定構成を定数として集約する（docs/design.md 6.3.3 / 9章、13章）。
 * シーダーのロジックに規模やパラメータを直接記述しない。
 */
class SeedConfig
{
    /**
     * シアター規模の区分（docs/design.md 6.3.3）。
     *
     * @var array<string, array{rows: int, cols: int}>
     */
    public const array THEATER_SCALES = [
        'XL' => ['rows' => 15, 'cols' => 18],
        'L' => ['rows' => 12, 'cols' => 16],
        'M' => ['rows' => 10, 'cols' => 13],
        'S' => ['rows' => 8, 'cols' => 10],
        'XS' => ['rows' => 6, 'cols' => 8],
    ];

    /**
     * 横通路を設ける規模（M以上）。
     *
     * @var array<int, string>
     */
    public const array SCALES_WITH_AISLE = ['M', 'L', 'XL'];

    /**
     * エグゼクティブ席を設ける規模（L以上）。
     *
     * @var array<int, string>
     */
    public const array SCALES_WITH_EXECUTIVE = ['L', 'XL'];

    /**
     * 祇園ムビ（本館、手動データ。docs/design.md 4.1.1 / 9.1）の基本情報。
     */
    public const string GION_SLUG = 'gion';

    public const string GION_NAME = '祇園ムビ';

    public const string GION_STATION = '京阪 祇園四条駅';

    public const string GION_ADDRESS = '京都府京都市東山区祇園町南側';

    public const string GION_CONCEPT = '本館。趣のある小規模館。作品性を重視する';

    /**
     * 祇園ムビのシアター構成（docs/design.md 6.3.3「祇園ムビのシアター構成」）。
     * 独自規模のため THEATER_SCALES を使わず、行数・列数を直接指定する。
     * 1番シアター（12×14）はL相当、2番シアター（9×12）はM相当、3番シアター（6×8）はXS相当として
     * 横通路・エグゼクティブ席の有無を判断した（6.1追記表参照）。
     *
     * @var array<int, array{rows: int, cols: int, hasAisle: bool, hasExecutive: bool, formats: array<int, string>}>
     */
    public const array GION_THEATERS = [
        1 => ['rows' => 12, 'cols' => 14, 'hasAisle' => true, 'hasExecutive' => true, 'formats' => ['MOVI GRAND', 'MOVI VIVID']],
        2 => ['rows' => 9, 'cols' => 12, 'hasAisle' => true, 'hasExecutive' => false, 'formats' => ['MOVI VIVID']],
        3 => ['rows' => 6, 'cols' => 8, 'hasAisle' => false, 'hasExecutive' => false, 'formats' => []],
    ];

    /**
     * シーダーで生成する6館。シアター番号は規模の大きい順（docs/design.md 6.3.3）。
     * 祇園ムビは館・シアター・座席が手動データのため含まない（GionSeeder）。
     * 上映編成・上映回（ScreeningSeeder）は祇園ムビも含む全7館が対象（9.1参照）。
     *
     * @var array<string, array{name: string, station: string, address: string, concept: string, theaters: array<int, string>}>
     */
    public const array GENERATED_CINEMAS = [
        'shijo-karasuma' => [
            'name' => 'ムビ四条烏丸',
            'station' => '阪急 烏丸駅',
            'address' => '京都府京都市下京区烏丸通四条下る',
            'concept' => '繁華街の商業館',
            'theaters' => ['L', 'M', 'M', 'M', 'S', 'S'],
        ],
        'shijo-kawaramachi' => [
            'name' => 'ムビ四条河原町',
            'station' => '阪急 京都河原町駅',
            'address' => '京都府京都市下京区河原町通四条上る',
            'concept' => '商業施設内の大型館',
            'theaters' => ['L', 'M', 'M', 'M', 'S', 'S', 'S'],
        ],
        'kyoto' => [
            'name' => 'ムビ京都',
            'station' => 'JR 京都駅',
            'address' => '京都府京都市下京区東塩小路町',
            'concept' => '駅直結の最大規模館',
            'theaters' => ['XL', 'L', 'L', 'M', 'M', 'M', 'M', 'S', 'S', 'S'],
        ],
        'nijo' => [
            'name' => 'ムビ二条',
            'station' => 'JR 二条駅',
            'address' => '京都府京都市中京区西ノ京東栂尾町',
            'concept' => '郊外型の大型館',
            'theaters' => ['L', 'M', 'M', 'M', 'M', 'S', 'S', 'S'],
        ],
        'yamashina' => [
            'name' => 'ムビ山科',
            'station' => 'JR 山科駅',
            'address' => '京都府京都市山科区安朱南屋敷町',
            'concept' => '生活圏の中規模館',
            'theaters' => ['L', 'M', 'M', 'S', 'S', 'S'],
        ],
        'fushimi' => [
            'name' => 'ムビ伏見',
            'station' => '京阪 伏見桃山駅',
            'address' => '京都府京都市伏見区大手筋',
            'concept' => '生活圏の小規模館',
            'theaters' => ['M', 'M', 'S', 'S', 'XS'],
        ],
    ];

    /**
     * 上映規格の割当（docs/design.md 4.1.1「上映規格の割当」表）。
     * 全シアターに2Dが標準で付くため、ここには2D以外の追加規格のみを記す。
     * キーは館slug、値はシアター番号（1始まり）→追加規格名の配列。
     * 表に無い番号（各館の末尾側シアター）は「2Dのみ」= 追加規格なし。
     *
     * @var array<string, array<int, array<int, string>>>
     */
    public const array THEATER_EXTRA_FORMATS = [
        'shijo-karasuma' => [1 => ['MOVI GRAND'], 2 => ['MOVI VIVID'], 3 => ['MOVI 3D']],
        'shijo-kawaramachi' => [1 => ['MOVI GRAND'], 2 => ['MOVI VIVID'], 3 => ['MOVI 3D'], 4 => ['MOVI MOTION']],
        'kyoto' => [1 => ['MOVI GRAND'], 2 => ['MOVI VIVID'], 3 => ['MOVI 3D'], 4 => ['MOVI MOTION']],
        'nijo' => [1 => ['MOVI GRAND'], 2 => ['MOVI 3D'], 3 => ['MOVI MOTION']],
        'yamashina' => [1 => ['MOVI GRAND'], 2 => ['MOVI 3D']],
        'fushimi' => [1 => ['MOVI VIVID'], 2 => ['MOVI 3D']],
    ];

    /**
     * 上映規格マスタ（docs/design.md 6.6）。
     *
     * @var array<string, int>
     */
    public const array FORMATS = [
        '2D' => 0,
        'MOVI GRAND' => 800,
        'MOVI VIVID' => 800,
        'MOVI 3D' => 500,
        'MOVI MOTION' => 1000,
    ];

    public const string SEAT_TYPE_GENERAL = '一般';

    public const string SEAT_TYPE_WHEELCHAIR = '車椅子';

    public const string SEAT_TYPE_EXECUTIVE = 'エグゼクティブ';

    /**
     * 座席種別マスタ（docs/design.md 6.3.2）。
     *
     * @var array<string, array{surcharge: int, display_class: string}>
     */
    public const array SEAT_TYPES = [
        self::SEAT_TYPE_GENERAL => ['surcharge' => 0, 'display_class' => 'standard'],
        self::SEAT_TYPE_WHEELCHAIR => ['surcharge' => 0, 'display_class' => 'wheelchair'],
        self::SEAT_TYPE_EXECUTIVE => ['surcharge' => 1000, 'display_class' => 'executive'],
    ];

    /**
     * 券種マスタ（docs/design.md 6.5.1）。
     *
     * @var array<int, array{name: string, price: int, condition: string|null}>
     */
    public const array TICKET_TYPES = [
        ['name' => '大人', 'price' => 2000, 'condition' => '18歳以上'],
        ['name' => '学生', 'price' => 1500, 'condition' => '大学・専門学校。要学生証'],
        ['name' => '高校生以下', 'price' => 1000, 'condition' => null],
        ['name' => 'シニア', 'price' => 1500, 'condition' => '65歳以上'],
        ['name' => '障がい者手帳をお持ちの方', 'price' => 1000, 'condition' => null],
    ];

    /**
     * 全館共通の値（docs/design.md 4.1.1）。
     */
    public const string BUSINESS_HOURS = '8:00〜23:55';

    public const string CINEMA_PHONE = '123-4567-8900';

    /**
     * 上映編成・上映回の生成規模（docs/design.md 9.2 / 9.3-3）。
     * 生成期間は `now()` を基準とした相対値（過去2年〜未来2週間）。
     */
    public const int GENERATION_PAST_YEARS = 2;

    public const int GENERATION_FUTURE_WEEKS = 2;

    /** 1つの上映編成（t_bookings）が続く日数（典型的な上映期間の目安）。 */
    public const int BOOKING_RUN_DAYS = 14;

    /** 1日の最初の上映回の開始時刻（4.1.1の営業時間8:00〜23:55の範囲内）。 */
    public const string DAILY_FIRST_SCREENING_TIME = '10:00';

    /** 営業終了時刻。この時刻を超えて上映が終わる回は作らない。 */
    public const string DAILY_LAST_SCREENING_END_TIME = '23:55';

    /** 予告編の時間（分）。上映回の終了時刻の自動計算に使う（4.8.3-3）。 */
    public const int TRAILER_MINUTES = 15;

    /** 同一シアターの上映回どうしに空ける最小間隔（分、清掃・入替時間。4.8.3-4）。 */
    public const int SCREENING_INTERVAL_MINUTES = 30;

    /** 直近公開の作品群（対象は公開済み作品の上位1/N）から選ぶ確率（%）。9.1「公開年の新しい作品を優先する」の近似に使う。 */
    public const int RECENT_MOVIE_BIAS_PERCENT = 70;

    /** 「直近公開の作品群」とみなす件数を、公開済み作品数の何分の1とするか。 */
    public const int RECENT_MOVIE_POOL_DIVISOR = 2;

    /**
     * 上映作品プール（docs/design.md 9.1「上映作品」）。すべて架空の作品。
     * `tmdb_id` は実在のTMDB IDと衝突しないダミー値（900000001〜。TMDBの実IDは
     * 2026年時点で数百万台のため、9億台であれば当面衝突しない）。
     * 実在の作品ではないため `poster_path` は保持しない（フェーズ4以降でプレースホルダ画像を検討）。
     * 公開年を1978〜2026年に分散させ、祇園ムビの「旧作・特集上映」（9.1）に対応する。
     * `formats` は2D以外に対応する追加規格（6.6）。全作品が2Dに対応する前提のため含めない。
     *
     * @var array<int, array{tmdb_id: int, title: string, synopsis: string, runtime_minutes: int, released_on: string, genres: array<int, string>, formats: array<int, string>}>
     */
    public const array MOVIES = [
        ['tmdb_id' => 900000001, 'title' => '潮騒の記憶', 'synopsis' => '漁村を舞台に、幼なじみの再会と別れを描く青春群像劇。', 'runtime_minutes' => 108, 'released_on' => '1978-06-10', 'genres' => ['ドラマ'], 'formats' => []],
        ['tmdb_id' => 900000002, 'title' => '硝子の街', 'synopsis' => '硝子工場の火災事故の真相を追う新聞記者を描くサスペンス。', 'runtime_minutes' => 115, 'released_on' => '1985-09-21', 'genres' => ['ミステリー'], 'formats' => []],
        ['tmdb_id' => 900000003, 'title' => '遠い花火', 'synopsis' => '離ればなれになった恋人たちが、故郷の花火大会で再会する。', 'runtime_minutes' => 102, 'released_on' => '1991-08-03', 'genres' => ['ドラマ', '恋愛'], 'formats' => []],
        ['tmdb_id' => 900000004, 'title' => '竜の眠る山', 'synopsis' => '伝説の竜を探す少年と旅の一座が、山間の集落で謎に挑む。', 'runtime_minutes' => 128, 'released_on' => '2003-04-18', 'genres' => ['ファンタジー', '冒険'], 'formats' => ['MOVI VIVID']],
        ['tmdb_id' => 900000005, 'title' => '蒼穹のパイロット', 'synopsis' => '戦後復興期を舞台に、飛行機乗りたちの誇りと友情を描く。', 'runtime_minutes' => 132, 'released_on' => '2011-07-15', 'genres' => ['アクション'], 'formats' => ['MOVI GRAND', 'MOVI 3D']],
        ['tmdb_id' => 900000006, 'title' => '深海のラビリンス', 'synopsis' => '深海探査船が未知の生命体と遭遇するSFスリラー。', 'runtime_minutes' => 118, 'released_on' => '2015-11-06', 'genres' => ['SF', 'スリラー'], 'formats' => ['MOVI 3D']],
        ['tmdb_id' => 900000007, 'title' => 'あの日の教室', 'synopsis' => '同窓会をきっかけに、教師と教え子たちの20年前の約束が動き出す。', 'runtime_minutes' => 110, 'released_on' => '2017-05-12', 'genres' => ['ドラマ'], 'formats' => []],
        ['tmdb_id' => 900000008, 'title' => '調律師のラプソディ', 'synopsis' => '各地の音楽祭を旅する、音楽家見習いの少女と仲間たちを描くロードムービー・アニメーション。', 'runtime_minutes' => 105, 'released_on' => '2018-03-09', 'genres' => ['アニメーション', '音楽'], 'formats' => ['MOVI GRAND', 'MOVI VIVID']],
        ['tmdb_id' => 900000009, 'title' => '忘れられた灯台', 'synopsis' => '孤島の灯台守が遺した日記から、村に隠された秘密が明らかになる。', 'runtime_minutes' => 121, 'released_on' => '2019-10-25', 'genres' => ['ミステリー', 'ドラマ'], 'formats' => []],
        ['tmdb_id' => 900000010, 'title' => '真夏のパレード', 'synopsis' => '商店街の夏祭り再興を目指す家族のドタバタコメディ。', 'runtime_minutes' => 98, 'released_on' => '2020-08-07', 'genres' => ['コメディ', '家族'], 'formats' => ['MOVI VIVID']],
        ['tmdb_id' => 900000011, 'title' => '雪解けの街で', 'synopsis' => '転勤先の雪国で出会った二人が、季節の移ろいとともに距離を縮める。', 'runtime_minutes' => 112, 'released_on' => '2021-02-14', 'genres' => ['恋愛', 'ドラマ'], 'formats' => []],
        ['tmdb_id' => 900000012, 'title' => 'アステロイド・ゼロ', 'synopsis' => '地球に接近する小惑星を止めるため、宇宙飛行士チームが決死の作戦に挑む。', 'runtime_minutes' => 140, 'released_on' => '2022-07-01', 'genres' => ['SF', 'アクション'], 'formats' => ['MOVI GRAND', 'MOVI 3D', 'MOVI MOTION']],
        ['tmdb_id' => 900000013, 'title' => '最後の走者', 'synopsis' => '故障を乗り越え最後の大会に挑む陸上選手と、支えるコーチの物語。', 'runtime_minutes' => 119, 'released_on' => '2022-11-11', 'genres' => ['スポーツ', 'ドラマ'], 'formats' => ['MOVI VIVID']],
        ['tmdb_id' => 900000014, 'title' => '月夜のオーケストラ', 'synopsis' => '解散寸前のアマチュア楽団が、最後の演奏会に向けて再び集う。', 'runtime_minutes' => 108, 'released_on' => '2023-04-21', 'genres' => ['音楽', 'ドラマ'], 'formats' => ['MOVI VIVID']],
        ['tmdb_id' => 900000015, 'title' => '怪盗ノワール', 'synopsis' => '美術館を舞台にした怪盗と刑事の頭脳戦を描くケイパー・アクション。', 'runtime_minutes' => 125, 'released_on' => '2023-09-15', 'genres' => ['アクション', 'コメディ'], 'formats' => ['MOVI GRAND', 'MOVI 3D']],
        ['tmdb_id' => 900000016, 'title' => '森の番人たち', 'synopsis' => '山道で迷子になった少女が、森に暮らす小さな精霊たちの手を借りて家路を探すアニメーション。', 'runtime_minutes' => 99, 'released_on' => '2024-03-20', 'genres' => ['アニメーション', 'ファンタジー', '家族'], 'formats' => ['MOVI GRAND', 'MOVI VIVID', 'MOVI 3D']],
        ['tmdb_id' => 900000017, 'title' => '灼熱のカウントダウン', 'synopsis' => '爆発事故が相次ぐ石油プラントで、技術者が制限時間内に真犯人を追う。', 'runtime_minutes' => 135, 'released_on' => '2024-08-09', 'genres' => ['アクション', 'スリラー'], 'formats' => ['MOVI GRAND', 'MOVI 3D', 'MOVI MOTION']],
        ['tmdb_id' => 900000018, 'title' => '静かな声', 'synopsis' => '聴覚を失った作曲家が、新しい表現方法を見つけるまでを静かに描く。', 'runtime_minutes' => 106, 'released_on' => '2025-01-17', 'genres' => ['ドラマ'], 'formats' => []],
        ['tmdb_id' => 900000019, 'title' => '巨獣戦線', 'synopsis' => '巨大生物の出現に立ち向かう防衛部隊を描くスペクタクル大作。', 'runtime_minutes' => 142, 'released_on' => '2025-07-04', 'genres' => ['アクション', 'SF'], 'formats' => ['MOVI GRAND', 'MOVI 3D', 'MOVI MOTION']],
        ['tmdb_id' => 900000020, 'title' => '桜、また咲く頃に', 'synopsis' => '祖母の遺した手紙をきっかけに、孫娘が家族の歴史をたどる物語。', 'runtime_minutes' => 115, 'released_on' => '2026-03-27', 'genres' => ['ドラマ', '恋愛'], 'formats' => ['MOVI VIVID']],
    ];

    /**
     * 初期の super-admin アカウント（docs/design.md 4.8.4-6。シーダーで投入するのはこの1件のみ）。
     * パスワードは17.11-2（認証情報をコードに記載しない）に従い定数を持たず、
     * `MasterDataSeeder` が環境変数 `SEED_SUPER_ADMIN_PASSWORD` から読み取る（9.3追記表参照）。
     */
    public const string SUPER_ADMIN_LOGIN_ID = 'super-admin';

    public const string SUPER_ADMIN_NAME = '本部管理者';

    /**
     * お知らせカテゴリー（docs/design.md 4.7.1）。
     *
     * @var array<string, string>
     */
    public const array POST_CATEGORIES = [
        'notice' => 'お知らせ',
        'campaign' => 'キャンペーン',
        'important' => '重要なお知らせ',
    ];

    /** カテゴリーあたりのお知らせ件数（docs/design.md 9.2。3カテゴリーで計300件）。 */
    public const int POST_COUNT_PER_CATEGORY = 100;

    /** 非表示動作の確認用に、状態を `draft`（`published_at` は `null`）とする件数（9.3-5）。 */
    public const int POST_DRAFT_COUNT_PER_CATEGORY = 5;

    /** 非表示動作の確認用に、状態は `published` のまま `published_at` を未来日付とする件数（9.3-5）。 */
    public const int POST_FUTURE_COUNT_PER_CATEGORY = 5;

    /** 通常の公開済み記事の `published_at` を分散させる過去の年数（9.3-4）。 */
    public const int POST_PUBLISHED_SPAN_YEARS = 3;

    /** 未来日付の記事の `published_at` を分散させる先の日数上限。 */
    public const int POST_FUTURE_SPAN_DAYS = 30;

    /**
     * お知らせの本文・タイトルのテンプレート（カテゴリーごとに複数用意し使い回す。9.3「本文の使い回しを許容する」）。
     * 本文は4.7.1の記法制限（見出し/箇条書き/強調/リンク/画像）内のMarkdown。
     *
     * @var array<string, array<int, array{title: string, body: string}>>
     */
    public const array POST_TEMPLATES = [
        'notice' => [
            ['title' => '上映スケジュール更新のお知らせ', 'body' => "### ご案内\n\nいつもムビをご利用いただきありがとうございます。上映スケジュールを更新しましたのでお知らせいたします。\n\n- 対象: 全劇場\n- 内容: 最新の上映スケジュールを掲載\n\n詳細は各劇場のスケジュールページをご確認ください。"],
            ['title' => '館内設備点検のお知らせ', 'body' => "### ご案内\n\n館内設備の定期点検を実施いたします。点検中はご利用いただけない設備がございます。\n\n- 対象: 一部設備\n- 期間: 下記の通り\n\nご不便をおかけしますが、ご理解のほどよろしくお願いいたします。"],
            ['title' => '年末年始の営業時間について', 'body' => "### ご案内\n\n年末年始の営業時間を下記の通りご案内いたします。\n\n- 通常と異なる時間で営業する劇場がございます\n- 最新情報は各劇場ページをご確認ください\n\nご来場をお待ちしております。"],
            ['title' => '公式アプリ アップデートのお知らせ', 'body' => "### ご案内\n\n公式アプリの新バージョンを配信いたしました。\n\n- 内容: 不具合の修正および表示の改善\n- 対象: iOS / Android 版\n\n**最新版へのアップデートをお願いいたします。**"],
            ['title' => 'よくあるご質問ページ更新のお知らせ', 'body' => "### ご案内\n\nよくあるご質問ページの内容を更新いたしました。\n\n- チケットの購入方法\n- 座席変更・キャンセルについて\n\nご不明点がございましたら劇場までお問い合わせください。"],
        ],
        'campaign' => [
            ['title' => '学生応援キャンペーンのご案内', 'body' => "### キャンペーン概要\n\n学生証のご提示で対象作品がお得にご覧いただけます。\n\n- 対象: 学生証をお持ちの方\n- 特典: 学生料金でのご案内\n\n**なくなり次第終了**となります。"],
            ['title' => '夫婦50割引キャンペーン開催', 'body' => "### キャンペーン概要\n\nご夫婦でのご来場がお得になるキャンペーンを開催します。\n\n- 対象: いずれかが50歳以上のご夫婦\n- 特典: お二人合わせて割引価格\n\n詳しくは劇場窓口までお尋ねください。"],
            ['title' => '会員限定ポイント2倍キャンペーン', 'body' => "### キャンペーン概要\n\n会員の皆様を対象に、期間中のご利用でポイントが2倍になります。\n\n- 対象: 会員登録済みのお客様\n- 特典: 通常の2倍のポイント付与\n\nこの機会にぜひご利用ください。"],
            ['title' => 'レイトショー割引キャンペーン', 'body' => "### キャンペーン概要\n\n夜の回がさらにお得になるキャンペーンです。\n\n- 対象: 対象時間帯の上映回\n- 特典: 割引料金でのご案内\n\n**対象時間は劇場により異なります。**"],
            ['title' => 'お友達紹介キャンペーンのお知らせ', 'body' => "### キャンペーン概要\n\nお友達をご紹介いただくと、お二人に特典を進呈します。\n\n- 対象: 会員のお客様\n- 特典: 次回ご利用時の割引\n\n詳細は劇場スタッフまでお尋ねください。"],
        ],
        'important' => [
            ['title' => '【重要】臨時休館のお知らせ', 'body' => "### お知らせ\n\n下記の通り臨時休館いたします。お客様にはご不便をおかけいたしますが、何卒ご理解賜りますようお願い申し上げます。\n\n- 対象: 該当劇場\n- 期間: 下記の通り\n\nご不明点は劇場までお問い合わせください。"],
            ['title' => '【重要】上映中止のお知らせ', 'body' => "### お知らせ\n\n下記上映回を中止とさせていただきます。\n\n- 対象: 該当上映回\n- ご購入済みのお客様には別途ご案内いたします\n\nご迷惑をおかけし申し訳ございません。"],
            ['title' => '【重要】システム障害に関するお詫び', 'body' => "### お知らせ\n\nシステム障害によりご迷惑をおかけいたしましたことをお詫び申し上げます。\n\n- 発生期間: 下記の通り\n- 影響範囲: オンラインチケット購入\n\n現在は復旧しております。"],
            ['title' => '【重要】台風接近に伴う営業時間変更', 'body' => "### お知らせ\n\n台風の接近に伴い、営業時間を変更する場合がございます。\n\n- 対象: 該当エリアの劇場\n- 最新情報は各劇場ページをご確認ください\n\n安全を最優先に対応いたします。"],
            ['title' => '【重要】決済システムメンテナンスのお知らせ', 'body' => "### お知らせ\n\n決済システムのメンテナンスを実施いたします。\n\n- 対象: オンラインチケット購入\n- 期間中はご購入いただけません\n\nご不便をおかけし申し訳ございません。"],
        ],
    ];

    /**
     * バナーの掲載位置ごとの生成件数（docs/design.md 4.7.2「想定枚数」の範囲内で決め打ち）。
     *
     * @var array<string, int>
     */
    public const array BANNER_COUNTS = [
        BannerPosition::Main->value => 1,
        BannerPosition::Carousel->value => 4,
        BannerPosition::Sub->value => 2,
        BannerPosition::FooterLink->value => 4,
    ];

    /** 会員数（docs/design.md 9.2）。 */
    public const int MEMBER_COUNT = 200;

    /** 予約のうち会員が占める割合（%）。残りは非会員とする（9.2）。 */
    public const int MEMBER_RESERVATION_PERCENT = 60;

    /** 上映回ごとの座席稼働率の範囲（%、9.2）。 */
    public const int RESERVATION_MIN_OCCUPANCY_PERCENT = 5;

    public const int RESERVATION_MAX_OCCUPANCY_PERCENT = 80;

    /** 過去の予約のうち入場済み（`checked_in_at` 設定済み）とする割合（%、9.2）。 */
    public const int RESERVATION_CHECKED_IN_PERCENT = 80;

    /**
     * 1予約あたりの座席数の分布（4.3.4「1予約あたりの座席数は最大8席」の範囲内で
     * 小人数の予約を多めに出現させるための重み付き候補リスト）。
     *
     * @var array<int, int>
     */
    public const array RESERVATION_GROUP_SIZES = [1, 1, 2, 2, 2, 3, 3, 4, 4, 5, 6, 7, 8];

    /** 券種マスタ（TICKET_TYPES）の「大人」の名称。位置（インデックス）ではなく名称で参照するための定数。 */
    public const string TICKET_TYPE_ADULT = '大人';

    /**
     * 券種の選択比率（%、合計100。TICKET_TYPES の名称をキーとする）。
     *
     * @var array<string, int>
     */
    public const array TICKET_TYPE_WEIGHTS = [
        self::TICKET_TYPE_ADULT => 50,
        '学生' => 15,
        '高校生以下' => 15,
        'シニア' => 15,
        '障がい者手帳をお持ちの方' => 5,
    ];

    /**
     * 予約データの生成対象とする過去日数（9.2は上映編成・上映回と「同上」の期間を
     * 定めるが、過去2年〜未来2週間の全上映回に予約を生成すると数百万行規模となり
     * 現実的でないため、直近のみに縮小する（9.3追記表参照）。
     */
    public const int RESERVATION_PAST_DAYS = 14;

    /**
     * 予約データの生成対象とする未来日数（4.3.1「販売期間は上映日の3日前0:00から」。
     * これを超える未来の上映回は販売開始前のため予約を生成しない）。
     */
    public const int RESERVATION_SALE_WINDOW_DAYS = 3;

    /** 入場済み（`checked_in_at`）とする場合の、上映開始からの経過分の上限（1分〜この値の範囲でランダム）。 */
    public const int RESERVATION_CHECKIN_WINDOW_MINUTES = 15;

    /**
     * 無料鑑賞券1枚への交換に必要なスタンプ数（4.5.1-2）。`GionReservationSeeder` が
     * 実際に5個での交換を再現する際に使う。`RESERVATION_STAMP_CAP` の算出元でもある。
     */
    public const int STAMPS_PER_FREE_TICKET = 5;

    /**
     * `ReservationSeeder`（他6館）において、1会員が未交換のまま保持できるスタンプ数の
     * 上限。4.5.1-2「5個で無料鑑賞券1枚を発行しリセット」をそのままシーダーで
     * 再現すると無料鑑賞券の発行・使用まで実装する必要が生じるため
     * （実際の交換は`GionReservationSeeder`側で再現する。9.3追記表参照）、
     * `STAMPS_PER_FREE_TICKET`（5個）に到達する前で頭打ちにする。
     */
    public const int RESERVATION_STAMP_CAP = self::STAMPS_PER_FREE_TICKET - 1;

    /**
     * 開発時にログインする既定アカウント（スターターキット標準）。
     * `DatabaseSeeder` と `GionReservationSeeder` の双方が参照する。
     */
    public const string TEST_MEMBER_EMAIL = 'test@example.com';

    /**
     * 非会員の氏名・フリガナのプール（架空の人物名。9.2 / 4.3.6）。
     *
     * @var array<int, array{name: string, kana: string}>
     */
    public const array GUEST_NAMES = [
        ['name' => '田中一郎', 'kana' => 'タナカイチロウ'],
        ['name' => '佐藤花子', 'kana' => 'サトウハナコ'],
        ['name' => '鈴木太郎', 'kana' => 'スズキタロウ'],
        ['name' => '高橋陽子', 'kana' => 'タカハシヨウコ'],
        ['name' => '伊藤健太', 'kana' => 'イトウケンタ'],
        ['name' => '渡辺美咲', 'kana' => 'ワタナベミサキ'],
        ['name' => '山本大輔', 'kana' => 'ヤマモトダイスケ'],
        ['name' => '中村麻衣', 'kana' => 'ナカムラマイ'],
        ['name' => '小林直樹', 'kana' => 'コバヤシナオキ'],
        ['name' => '加藤恵', 'kana' => 'カトウメグミ'],
    ];
}
