---
name: mail
description: メール送信を実装・変更するとき。docs/design.md 8.3 / 4.6.1 を参照する。
allowed-tools: Read, Grep, Glob
---

メール送信の実装に関する規約。詳細は `docs/design.md` 8.3 / 4.6.1 を参照する。

- Brevo の SMTP リレーを使用する
- QRコードは PNG を CID 添付で埋め込み、あわせてリンクとコードのテキストを併記する
- QRコードの生成は `endroid/qr-code` の GD ライタを使用する（Imagick に依存しない）
- メールアドレス確認は無効
- 開発中の送信先を自身のアドレスに限定する
