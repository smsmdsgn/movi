<?php

use App\Services\PostBodyService;

/**
 * お知らせ本文のレンダリング（4.7.1 本文の形式 / 17.5.2-2〜4）の検証。
 * 顧客側で本文をエスケープせずに出力する唯一の経路であるため、**許可する記法の出力と、
 * 許可外の記法・HTML・URLが出力に残らないこと**をここで固定する（4.7.6追記表）。
 */
$render = fn (string $markdown): string => (new PostBodyService)->render($markdown)->toHtml();

it('許可する記法（見出し・箇条書き・強調・リンク・画像）をHTMLにする', function () use ($render) {
    $html = $render(implode("\n", [
        '# 大見出し',
        '',
        '## 小見出し',
        '',
        '- 項目1',
        '- **太字** と *斜体*',
        '',
        '1. 番号付き',
        '',
        '[外部リンク](https://example.com/page) [館内リンク](/cinemas/gion)',
        '',
        '![館内の写真](/storage/posts/lobby.jpg)',
    ]));

    expect($html)
        ->toContain('<h2>大見出し</h2>')
        ->toContain('<h3>小見出し</h3>')
        ->toContain('<ul>')
        ->toContain('<ol>')
        ->toContain('<strong>太字</strong>')
        ->toContain('<em>斜体</em>')
        ->toContain('<a rel="noopener noreferrer" href="https://example.com/page">外部リンク</a>')
        ->toContain('<a rel="noopener noreferrer" href="/cinemas/gion">館内リンク</a>')
        ->toContain('<img loading="lazy" src="/storage/posts/lobby.jpg" alt="館内の写真" />')
        ->not->toContain('<h1');
});

it('見出しを1段下げ、h6 を超えない', function () use ($render) {
    expect($render('###### 最下位'))->toBe("<h6>最下位</h6>\n");
});

it('HTMLの直接記述を除去する', function (string $markdown, string $kept) use ($render) {
    $html = $render($markdown);

    expect($html)
        ->not->toContain('<script')
        ->not->toContain('<iframe')
        ->not->toContain('onerror')
        ->not->toContain('<span')
        ->toContain($kept);
})->with([
    'ブロック' => ["<script>alert(1)</script>\n\n本文", '本文'],
    'ブロック（iframe）' => ["<iframe src=\"https://example.com\"></iframe>\n\n本文", '本文'],
    'インライン' => ['本文<img src=x onerror=alert(1)>の続き', '本文の続き'],
    'インライン（span）' => ['<span style="color:red">赤</span>', '赤'],
]);

it('http・https・自サイトのパス以外のリンクは文字列だけを残す', function (string $url) use ($render) {
    $html = $render("[押してください]({$url})");

    expect($html)
        ->toBe("<p>押してください</p>\n")
        ->not->toContain('href');
})->with([
    'javascript' => 'javascript:alert(1)',
    'javascript（大文字小文字の混在）' => 'JaVaScRiPt:alert(1)',
    'data' => 'data:text/html;base64,PHNjcmlwdD4=',
    'プロトコル相対' => '//evil.example.com',
    'mailto' => 'mailto:info@example.com',
    '相対パス' => 'news/detail/1',
]);

it('バックスラッシュは符号化され、別ホストとして解釈されるURLにならない', function () use ($render) {
    /* `/\host` はブラウザが `//host` と同様に扱うが、CommonMark が `\` を `%5C` へ符号化するため自サイトのパスになる。 */
    expect($render('[押してください](/\\evil.example.com)'))
        ->toContain('href="/%5Cevil.example.com"');
});

it('自動リンク記法（< >）のうち https のものはリンクにし、それ以外は文字列にする', function () use ($render) {
    expect($render('<https://example.com>'))
        ->toContain('<a rel="noopener noreferrer" href="https://example.com">https://example.com</a>');

    expect($render('<mailto:info@example.com>'))
        ->not->toContain('<a');
});

it('自サイトのパス以外の画像は代替テキストだけを残す', function (string $url) use ($render) {
    $html = $render("![館内の写真]({$url})");

    expect($html)
        ->toBe("<p>館内の写真</p>\n")
        ->not->toContain('<img');
})->with([
    '外部' => 'https://example.com/photo.jpg',
    'プロトコル相対' => '//example.com/photo.jpg',
    'data' => 'data:image/png;base64,iVBORw0KGgo=',
    'javascript' => 'javascript:alert(1)',
]);

it('画像をリンクで囲んだ場合、リンクは残り画像は代替テキストになる', function () use ($render) {
    expect($render('[![バナー](https://example.com/b.png)](https://example.com)'))
        ->toBe("<p><a rel=\"noopener noreferrer\" href=\"https://example.com\">バナー</a></p>\n");
});

it('許可外の記法（表・取り消し線・引用・コード・水平線）を構文として解釈しない', function () use ($render) {
    $html = $render(implode("\n", [
        '| 列 | 列 |',
        '|---|---|',
        '| 値 | 値 |',
        '',
        '~~取り消し~~',
        '',
        '> 引用文',
        '',
        '---',
        '',
        '`インライン<b>コード</b>`',
        '',
        '```',
        '1行目',
        '2行目',
        '```',
        '',
        'https://example.com',
    ]));

    expect($html)
        ->not->toContain('<table')
        ->not->toContain('<del')
        ->not->toContain('<s>')
        ->not->toContain('<blockquote')
        ->not->toContain('<hr')
        ->not->toContain('<code')
        ->not->toContain('<pre')
        ->not->toContain('<a ')
        ->not->toContain('<b>')
        ->toContain('~~取り消し~~')
        ->toContain('<p>引用文</p>')
        ->toContain('インライン&lt;b&gt;コード&lt;/b&gt;')
        ->toContain("<p>1行目<br />\n2行目</p>");
});

it('本文からタグと記法を除いた1行のテキストを返す', function () {
    $service = new PostBodyService;
    $text = $service->plainText($service->render("## 休館のお知らせ\n\n設備点検のため、**10月1日** は休館します。\n\n- 詳細は [こちら](https://example.com) & 窓口へ"));

    expect($text)->toBe('休館のお知らせ 設備点検のため、10月1日 は休館します。 詳細は こちら & 窓口へ');
});
