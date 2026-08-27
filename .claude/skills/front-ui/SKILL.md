---
name: front-ui
description: 顧客向け画面を実装するとき。docs/design.md 7章 / 13.4.3 / 13.5 を参照する。
allowed-tools: Read, Grep, Glob
---

顧客向け画面の実装に関する規約。詳細は `docs/design.md` 7章 / 13.4.3 / 13.5 を参照する。

- モバイル基準で記述し、`md:` `lg:` で上書きする
- `sm:` `xl:` `2xl:` を使用しない
- 顧客向け画面に Flux UI を使用しない
- 上映回ボタンは折り返しで対応し、横スクロールにしない
- 画像は遅延読み込みとする
- 状態を持たない表示は Blade コンポーネント、操作を伴うものは Livewire
