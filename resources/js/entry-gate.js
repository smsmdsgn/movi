/**
 * 入場ゲート（A-16、4.6.3）のカメラ読み取り。
 *
 * 読み取った文字列を Livewire の `admit()` へ渡すだけで、判定はサーバー側が行う。
 * 判定結果の表示と3秒後の復帰は Livewire 側（`result`）が受け持ち、表示中は読み取りを止める。
 *
 * qr-scanner は初回の起動時にだけ読み込む（入場ゲート以外の管理画面に解読器を載せない）。
 * 解読は Web Worker（Blob URL）で行うため、CSP を導入する際は `worker-src blob:` を要する（17.7）。
 */

/**
 * 同じコードを続けて読んだ場合に無視する時間（ミリ秒）。
 *
 * 入場可の表示が3秒で消えた後もスマートフォンをかざしたままだと、同じコードを
 * 読み直して「入場済み」の赤を出してしまう。同じコードが見え続けている間は延長する。
 */
const SAME_CODE_COOLDOWN_MS = 5000;

export default function entryGate() {
    return {
        scanner: null,
        destroyed: false,
        cameraUnavailable: false,
        busy: false,
        lastCode: null,
        lastSeenAt: 0,

        async init() {
            try {
                const { default: QrScanner } = await import('qr-scanner');

                if (this.destroyed) {
                    return;
                }

                this.scanner = new QrScanner(
                    this.$refs.video,
                    (result) => this.onScan(result.data),
                    {
                        returnDetailedScanResult: true,
                        highlightScanRegion: true,
                        // 画面側のカメラを使う。お客様はQRをかざしながら判定の色を見る（4.6.1 / 4.6.3）。
                        // カメラが1つの端末ではそれが使われる。
                        preferredCamera: 'user',
                    },
                );

                await this.scanner.start();

                // 起動を待つ間に要素が破棄された（館の選択の解除など）場合は、起動したカメラを止める。
                if (this.destroyed) {
                    this.stop();
                }
            } catch {
                // カメラが無い・権限が拒否された・HTTPS でない等。手入力モードで代替する（4.6.1）。
                this.cameraUnavailable = true;
            }
        },

        destroy() {
            this.destroyed = true;
            this.stop();
        },

        stop() {
            this.scanner?.destroy();
            this.scanner = null;
        },

        async onScan(code) {
            const now = Date.now();

            if (code === this.lastCode && now - this.lastSeenAt < SAME_CODE_COOLDOWN_MS) {
                this.lastSeenAt = now;

                return;
            }

            if (this.busy || this.$wire.result !== null) {
                return;
            }

            this.busy = true;
            this.lastCode = code;
            this.lastSeenAt = now;

            try {
                await this.$wire.admit(code);
            } finally {
                this.busy = false;
            }
        },
    };
}
