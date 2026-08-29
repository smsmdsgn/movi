<?php

namespace Database\Seeders;

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
     * 祇園ムビは手動データのため含まない（GionSeeder）。
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
}
