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
}
