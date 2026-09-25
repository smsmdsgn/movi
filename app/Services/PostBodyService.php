<?php

namespace App\Services;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * お知らせ本文（Markdown）を顧客向けのHTMLへ変換する（4.7.1 本文の形式 / 17.5.2-2）。
 *
 * **エスケープせずに出力してよいのは本クラスの戻り値のみ**（17.5.2-1 追記）。
 * `HtmlString` を返すため、Blade では `{{ }}` のまま出力できる（`{!! !!}` を書かない）。
 *
 * 許可する記法（4.7.1-3）以外は、構文木の段階で落とす（4.7.6追記表）。
 * - GFM 拡張を読み込まない。表・取り消し線・自動リンク（`<` `>` なし）は構文として解釈されない
 * - HTMLは `html_input: strip` で除去し、構文木からも取り除く（4.7.1-2）
 * - 引用は囲みを外して中身を残す。コードは文字列として残す。水平線は取り除く
 * - 見出しは1段下げる（`#` → `h2`）。ページの `h1` は記事タイトルが持つ（19.3-7）
 * - リンクは `http` / `https` と自サイトのパスに限り、`rel="noopener noreferrer"` を付ける（4.7.1-4 / 17.5.2-3・4）
 * - 画像は自サイトのパスに限り、それ以外は代替テキストのみを残す（CSP の `img-src`、17.7）
 */
class PostBodyService
{
    /** 入れ子の上限。深い入れ子（`>>>>…` 等）による解析の負荷を抑える。 */
    private const MAX_NESTING_LEVEL = 10;

    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => self::MAX_NESTING_LEVEL,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addEventListener(DocumentParsedEvent::class, $this->restrict(...));

        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $markdown): HtmlString
    {
        return new HtmlString($this->converter->convert($markdown)->getContent());
    }

    /**
     * `render()` 済みの本文をタグを除いた1行のテキストにする。`meta description`（19.2）の要約に使う。
     * 変換を2度行わないよう、Markdown ではなく変換結果を受け取る。
     */
    public function plainText(HtmlString $rendered): string
    {
        $text = html_entity_decode(strip_tags($rendered->toHtml()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * 解析直後の構文木から、許可外の記法を取り除く。
     * 走査中に木を組み替えると走査が崩れるため、先に全ノードを集めてから処理する。
     */
    private function restrict(DocumentParsedEvent $event): void
    {
        /** @var list<Node> $nodes */
        $nodes = iterator_to_array($event->getDocument()->iterator(), false);

        foreach ($nodes as $node) {
            match (true) {
                $node instanceof HtmlBlock, $node instanceof HtmlInline, $node instanceof ThematicBreak => $node->detach(),
                $node instanceof BlockQuote => $this->unwrap($node),
                $node instanceof FencedCode, $node instanceof IndentedCode => $node->replaceWith($this->codeBlockAsParagraph($node->getLiteral())),
                $node instanceof Code => $node->replaceWith(new Text($node->getLiteral())),
                $node instanceof Heading => $node->setLevel(min($node->getLevel() + 1, 6)),
                $node instanceof Link => $this->restrictLink($node),
                $node instanceof Image => $this->restrictImage($node),
                default => null,
            };
        }
    }

    private function restrictLink(Link $link): void
    {
        if (! $this->isAllowedLinkUrl($link->getUrl())) {
            $this->unwrap($link);

            return;
        }

        $link->data->set('attributes/rel', 'noopener noreferrer');
    }

    /**
     * 自サイトのパス以外の画像は、代替テキスト（子ノード）だけを残す。
     */
    private function restrictImage(Image $image): void
    {
        if (! $this->isSitePath($image->getUrl())) {
            $this->unwrap($image);

            return;
        }

        $image->data->set('attributes/loading', 'lazy');
    }

    private function isAllowedLinkUrl(string $url): bool
    {
        return preg_match('#\Ahttps?://#i', $url) === 1 || $this->isSitePath($url);
    }

    /**
     * `/` で始まる自サイトのパスか。`//host` と `/\host` はブラウザが別ホストとして解釈するため除く。
     */
    private function isSitePath(string $url): bool
    {
        return preg_match('#\A/(?![/\\\\])#', $url) === 1;
    }

    /**
     * ノードの囲みを外し、子ノードを同じ位置へ移す。
     */
    private function unwrap(Node $node): void
    {
        foreach ($node->children() as $child) {
            $node->insertBefore($child);
        }

        $node->detach();
    }

    private function codeBlockAsParagraph(string $literal): Paragraph
    {
        $paragraph = new Paragraph;
        $lines = explode("\n", rtrim($literal, "\n"));

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $paragraph->appendChild(new Newline(Newline::HARDBREAK));
            }

            $paragraph->appendChild(new Text($line));
        }

        return $paragraph;
    }
}
