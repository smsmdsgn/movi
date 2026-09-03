{{--
    工程3-a（管理者認証基盤とルート骨格）の検証用プレースホルダ。
    各画面の実装（該当フェーズ、11.1）で画面ごとのビューへ差し替える。
--}}
<x-layouts::admin :title="$screenId">
    <div class="px-4 py-8">
        <p data-testid="screen-id">{{ $screenId }}</p>
        <p>{{ __('admin.placeholder.notice', ['screenId' => $screenId]) }}</p>
    </div>
</x-layouts::admin>
